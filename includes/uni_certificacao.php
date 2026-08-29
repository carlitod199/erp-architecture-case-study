<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/uni_certificacao.php
   Emissão e validação de CERTIFICADOS de trilha (banco separado).
   Regra: certificado só nasce quando a trilha está 100% concluída
   para o usuário. Código público valida sem login. Snapshot do
   conteúdo (cápsulas/versões) fica gravado — o certificado prova o
   que valia quando foi emitido, mesmo que a trilha mude depois.
   ============================================================ */

require_once __DIR__ . '/uni_trilhas.php'; // uni_trilha_stats/_capsulas + uni_ctx

/** Código público legível e único: VERO-XXXX-XXXX. */
function uni_cert_gerar_codigo(): string
{
    $hex = strtoupper(bin2hex(random_bytes(4))); // 8 hex
    return 'VERO-' . substr($hex, 0, 4) . '-' . substr($hex, 4, 4);
}

/**
 * Emite o certificado da trilha se elegível (100%) e ainda não existir.
 * Idempotente: se já houver, devolve o existente. Null se inelegível.
 */
function uni_cert_emitir(array $ctx, int $trilhaId): ?array
{
    if (empty($ctx['uid'])) return null;
    $stats = uni_trilha_stats($ctx, $trilhaId);
    if ($stats['total'] === 0 || $stats['percentual'] < 100) return null;

    $pdo = uni_pdo();
    $q = $pdo->prepare("SELECT * FROM uni_certificado WHERE usuario_id = ? AND trilha_id = ? LIMIT 1");
    $q->execute([$ctx['uid'], $trilhaId]);
    if ($cert = $q->fetch()) return $cert;

    $t = $pdo->prepare("SELECT titulo FROM uni_trilha WHERE id = ? LIMIT 1");
    $t->execute([$trilhaId]);
    $titulo = (string)$t->fetchColumn();

    $caps = uni_trilha_capsulas($ctx, $trilhaId);
    $snapshot = [
        'capsulas' => array_map(fn($c) => $c['slug'], $caps),
        'total'    => count($caps),
        'emitido'  => date('Y-m-d'),
    ];
    $codigo = uni_cert_gerar_codigo();

    $pdo->prepare(
        "INSERT INTO uni_certificado (tenant_id, usuario_id, trilha_id, codigo_publico, nome_titular, trilha_titulo, versao_conteudo)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([
        $ctx['tenant'], $ctx['uid'], $trilhaId, $codigo,
        $ctx['nome'] ?? null, $titulo, json_encode($snapshot, JSON_UNESCAPED_UNICODE),
    ]);

    /* selo de conclusão (best-effort) */
    try {
        $pdo->prepare(
            "INSERT IGNORE INTO uni_selo (tenant_id, usuario_id, slug, titulo, icone)
             VALUES (?,?,?,?,?)"
        )->execute([$ctx['tenant'], $ctx['uid'], 'trilha-' . $trilhaId, 'Trilha concluída: ' . $titulo, '🎓']);
    } catch (Throwable $e) { error_log('[uni_selo] ' . $e->getMessage()); }

    $q->execute([$ctx['uid'], $trilhaId]);
    return $q->fetch() ?: null;
}

/** Certificados do usuário logado (com % atual da trilha para contexto). */
function uni_cert_do_usuario(array $ctx): array
{
    if (empty($ctx['uid'])) return [];
    $st = uni_pdo()->prepare(
        "SELECT c.*, t.slug AS trilha_slug
           FROM uni_certificado c
           JOIN uni_trilha t ON t.id = c.trilha_id
          WHERE c.usuario_id = ? ORDER BY c.emitido_em DESC"
    );
    $st->execute([$ctx['uid']]);
    return $st->fetchAll();
}

/** Validação PÚBLICA por código (sem login). Null se não existe. */
function uni_cert_por_codigo(string $codigo): ?array
{
    $codigo = strtoupper(trim($codigo));
    if (!preg_match('/^VERO-[0-9A-F]{4}-[0-9A-F]{4}$/', $codigo)) return null;
    /* Endpoint PÚBLICO (sem login): qualquer falha de banco/consulta degrada
       para "não encontrado" em vez de estourar 500 num link compartilhável. */
    try {
        $st = uni_pdo()->prepare(
            "SELECT c.*, t.slug AS trilha_slug
               FROM uni_certificado c
               JOIN uni_trilha t ON t.id = c.trilha_id
              WHERE c.codigo_publico = ? LIMIT 1"
        );
        $st->execute([$codigo]);
        $c = $st->fetch();
        if (!$c) return null;
        $c['valido'] = ((int)$c['revogado'] === 0)
            && ($c['valido_ate'] === null || $c['valido_ate'] >= date('Y-m-d'));
        return $c;
    } catch (Throwable $e) {
        error_log('[uni_cert_por_codigo] ' . $e->getMessage());
        return null;
    }
}
