<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/uni_pratica.php
   Camada de dados de PRÁTICA (Fazenda Escola). As tarefas ficam no
   banco separado (uni_pdo); a VERIFICAÇÃO roda no banco do SISTEMA
   (Database::getConnection) contra o tenant do usuário — a Fazenda
   Escola quando ele está nela. Registra cada tentativa.
   ============================================================ */

require_once __DIR__ . '/uni_portal.php';
require_once __DIR__ . '/db.php'; // Database::getConnection() — banco do sistema (verificação)

/** Exercícios ativos de uma cápsula. */
function uni_pratica_da_capsula(int $capsulaId): array
{
    $st = uni_pdo()->prepare(
        "SELECT id, slug, titulo, enunciado_md, mensagem_ok, mensagem_falha, ordem
           FROM uni_tarefa_pratica WHERE capsula_id = ? AND ativo = 1 ORDER BY ordem, id"
    );
    $st->execute([$capsulaId]);
    return $st->fetchAll();
}

/** Todos os exercícios ativos, com a cápsula de origem (para a página de prática). */
function uni_pratica_todas(array $ctx): array
{
    $st = uni_pdo()->prepare(
        "SELECT p.id, p.slug, p.titulo, p.enunciado_md, p.mensagem_ok, p.mensagem_falha,
                c.slug AS capsula_slug, c.titulo AS capsula_titulo, c.modulo,
                (SELECT rota FROM uni_capsula_rota WHERE capsula_id=c.id ORDER BY principal DESC LIMIT 1) AS rota
           FROM uni_tarefa_pratica p
           JOIN uni_capsula c ON c.id = p.capsula_id AND c.status='publicado' AND c.ativo=1
          WHERE p.ativo = 1 AND (c.tenant_id IS NULL OR c.tenant_id = ?)
          ORDER BY c.modulo, p.ordem"
    );
    $st->execute([$ctx['tenant']]);
    return $st->fetchAll();
}

/** Um exercício pelo slug. */
function uni_pratica_por_slug(string $slug): ?array
{
    $st = uni_pdo()->prepare("SELECT * FROM uni_tarefa_pratica WHERE slug = ? AND ativo = 1 LIMIT 1");
    $st->execute([$slug]);
    $t = $st->fetch();
    return $t ?: null;
}

/**
 * Verifica um exercício: roda o SELECT de verificação no banco do SISTEMA,
 * amarrando :tenant e :uid do usuário logado. >0 linhas = sucesso.
 * Retorna ['ok'=>bool, 'mensagem'=>string]. Registra a tentativa.
 *
 * Segurança: verificacao_sql é conteúdo autorado pela plataforma (confiável),
 * mas ainda assim exigimos que comece com SELECT e roda somente leitura,
 * com os únicos parâmetros sendo :tenant e :uid (nunca entrada do usuário).
 */
function uni_pratica_verificar(array $ctx, array $tarefa): array
{
    $sql = trim((string)($tarefa['verificacao_sql'] ?? ''));
    $ok = false;
    $detalhe = '';

    if ($sql === '' || !preg_match('/^select\b/i', $sql)) {
        $detalhe = 'verificação inválida';
    } else {
        try {
            $pdoSis = Database::getConnection();
            /* envolve em COUNT(*) — a verificação passa quando encontra ≥1 linha */
            $st = $pdoSis->prepare("SELECT COUNT(*) FROM ({$sql}) AS _uni_chk");
            foreach ([':tenant' => $ctx['tenant'], ':uid' => $ctx['uid']] as $ph => $val) {
                if (strpos($sql, $ph) !== false) $st->bindValue($ph, (int)$val, PDO::PARAM_INT);
            }
            $st->execute();
            $ok = (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            $detalhe = 'erro na verificação';
            error_log('[uni_pratica] ' . $e->getMessage());
        }
    }

    $mensagem = $ok
        ? (string)($tarefa['mensagem_ok'] ?? 'Muito bem! Tarefa concluída.')
        : (string)($tarefa['mensagem_falha'] ?? 'Ainda não. Refaça o passo e verifique de novo.');

    /* registra tentativa (best-effort) */
    try {
        uni_pdo()->prepare(
            "INSERT INTO uni_tentativa (tenant_id, usuario_id, tarefa_id, sucesso, detalhe) VALUES (?,?,?,?,?)"
        )->execute([$ctx['tenant'], $ctx['uid'], (int)$tarefa['id'], $ok ? 1 : 0, mb_substr($detalhe, 0, 300)]);
    } catch (Throwable $e) {
        error_log('[uni_tentativa] ' . $e->getMessage());
    }

    return ['ok' => $ok, 'mensagem' => $mensagem];
}
