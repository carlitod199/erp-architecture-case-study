<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/uni_gestor.php
   Painel do gestor: adoção da equipe + checklist de implantação.
   Cruza o banco do SISTEMA (usuários do tenant) com o banco da
   Universidade (progresso). Sem ranking entre pessoas (LGPD) — é
   um retrato de adoção, não uma competição.
   ============================================================ */

require_once __DIR__ . '/uni_trilhas.php'; // uni_ctx, uni_pdo, visibilidade
require_once __DIR__ . '/db.php';          // Database::getConnection() — banco do sistema

/** Só dono/gestor/administrador (ou permissão dedicada) veem o painel. */
function uni_gestor_pode(array $ctx): bool
{
    /* Defense-in-depth: sem tenant não há equipe a mostrar; negar evita que
       uma conta sem organização (ex.: auto-registrado) alcance o painel. */
    if (($ctx['tenant'] ?? null) === null) return false;
    if (in_array($ctx['role'], ['super_admin', 'club_admin', 'administrador', 'dono', 'gestor'], true)) return true;
    return uni_perm_ok('uni.equipe.ver', $ctx);
}

/** Total de cápsulas publicadas visíveis (denominador da adoção). */
function uni_gestor_total_capsulas(array $ctx): int
{
    $st = uni_pdo()->prepare(
        "SELECT COUNT(*) FROM uni_capsula WHERE status='publicado' AND ativo=1 AND (tenant_id IS NULL OR tenant_id = ?)"
    );
    $st->execute([$ctx['tenant']]);
    return (int)$st->fetchColumn();
}

/**
 * Adoção por pessoa do tenant: nome, perfil, cápsulas concluídas,
 * matrículas e última atividade. Sem ordenar por "melhor" — ordem alfabética.
 */
function uni_gestor_equipe(array $ctx): array
{
    $uni = uni_pdo();
    $tenant = $ctx['tenant'] !== null ? (int)$ctx['tenant'] : null;

    /* SEGURANÇA: sem tenant NUNCA varremos o roster global — isso vazaria os
       alunos de TODOS os tenants. Conta sem organização não vê equipe. */
    if ($tenant === null) return [];

    /* alunos do LMS (somente da mesma organização) */
    $qu = $uni->prepare("SELECT id, nome, email, perfil FROM uni_usuario WHERE tenant_id = ? AND ativo = 1 ORDER BY nome");
    $qu->execute([$tenant]);
    $usuarios = $qu->fetchAll();
    if (!$usuarios) return [];

    $ids = array_map(fn($u) => (int)$u['id'], $usuarios);
    $in = implode(',', array_fill(0, count($ids), '?'));

    /* progresso agregado por aluno (banco da Universidade) */
    $qp = $uni->prepare(
        "SELECT usuario_id,
                SUM(estado = 'concluida') AS concluidas,
                MAX(atualizado_em) AS ultima
           FROM uni_progresso WHERE usuario_id IN ({$in}) GROUP BY usuario_id"
    );
    $qp->execute($ids);
    $prog = [];
    foreach ($qp->fetchAll() as $r) $prog[(int)$r['usuario_id']] = $r;

    /* matrículas por usuário */
    $qm = $uni->prepare("SELECT usuario_id, COUNT(*) AS n FROM uni_matricula WHERE usuario_id IN ({$in}) GROUP BY usuario_id");
    $qm->execute($ids);
    $mat = [];
    foreach ($qm->fetchAll() as $r) $mat[(int)$r['usuario_id']] = (int)$r['n'];

    /* certificados por usuário */
    $qc = $uni->prepare("SELECT usuario_id, COUNT(*) AS n FROM uni_certificado WHERE usuario_id IN ({$in}) AND revogado=0 GROUP BY usuario_id");
    $qc->execute($ids);
    $cert = [];
    foreach ($qc->fetchAll() as $r) $cert[(int)$r['usuario_id']] = (int)$r['n'];

    $total = max(1, uni_gestor_total_capsulas($ctx));
    $out = [];
    foreach ($usuarios as $u) {
        $uid = (int)$u['id'];
        $conc = (int)($prog[$uid]['concluidas'] ?? 0);
        $out[] = [
            'nome'        => (string)$u['nome'],
            'perfil'      => (string)$u['perfil'],
            'concluidas'  => $conc,
            'percentual'  => (int)round($conc * 100 / $total),
            'matriculas'  => $mat[$uid] ?? 0,
            'certificados' => $cert[$uid] ?? 0,
            'ultima'      => $prog[$uid]['ultima'] ?? null,
        ];
    }
    return $out;
}

/** Resumo da equipe (para os KPIs do topo do painel). */
function uni_gestor_resumo(array $ctx): array
{
    $eq = uni_gestor_equipe($ctx);
    $n = count($eq);
    $ativos = 0; $somaPct = 0;
    foreach ($eq as $p) { if ($p['concluidas'] > 0) $ativos++; $somaPct += $p['percentual']; }
    return [
        'pessoas'      => $n,
        'ativos'       => $ativos,
        'media_pct'    => $n > 0 ? (int)round($somaPct / $n) : 0,
        'total_caps'   => uni_gestor_total_capsulas($ctx),
    ];
}

/**
 * Checklist de implantação: itens (globais + do tenant), avaliando a
 * verificacao_sql no banco do sistema para o tenant e persistindo o estado.
 */
function uni_gestor_checklist(array $ctx): array
{
    $uni = uni_pdo();
    $st = $uni->prepare(
        "SELECT * FROM uni_checklist_item WHERE ativo = 1 AND (tenant_id IS NULL OR tenant_id = ?) ORDER BY ordem, id"
    );
    $st->execute([$ctx['tenant']]);
    $itens = $st->fetchAll();
    if (!$itens) return [];

    $sis = Database::getConnection();
    $upsert = $uni->prepare(
        "INSERT INTO uni_checklist_estado (tenant_id, item_id, concluido, concluido_em)
         VALUES (?,?,?, IF(?,NOW(),NULL))
         ON DUPLICATE KEY UPDATE concluido=VALUES(concluido),
           concluido_em=IF(VALUES(concluido) AND concluido_em IS NULL, NOW(), concluido_em)"
    );

    foreach ($itens as &$it) {
        $ok = false;
        $sql = trim((string)($it['verificacao_sql'] ?? ''));
        if ($sql !== '' && preg_match('/^select\b/i', $sql)) {
            try {
                $q = $sis->prepare("SELECT COUNT(*) FROM ({$sql}) AS _chk");
                if (strpos($sql, ':tenant') !== false) $q->bindValue(':tenant', (int)$ctx['tenant'], PDO::PARAM_INT);
                $q->execute();
                $ok = (int)$q->fetchColumn() > 0;
            } catch (Throwable $e) { error_log('[uni_checklist] ' . $e->getMessage()); }
            try { $upsert->execute([$ctx['tenant'], (int)$it['id'], $ok ? 1 : 0, $ok ? 1 : 0]); }
            catch (Throwable $e) { error_log('[uni_checklist_estado] ' . $e->getMessage()); }
        }
        $it['concluido'] = $ok;
    }
    return $itens;
}
