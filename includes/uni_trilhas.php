<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/uni_trilhas.php
   Camada de dados de TRILHAS / CURSOS / MATRÍCULA / PROGRESSO do
   portal da Universidade. Consome o banco separado (uni_pdo) e
   respeita a visibilidade por permissão (uni_portal.php).
   As páginas do portal só renderizam o que estas funções devolvem.
   ============================================================ */

require_once __DIR__ . '/uni_portal.php';

/** Trilhas ativas visíveis ao tenant, com contagem de cápsulas e progresso do usuário. */
function uni_trilhas_todas(array $ctx): array
{
    $pdo = uni_pdo();
    $st = $pdo->prepare(
        "SELECT t.id, t.slug, t.titulo, t.publico, t.tempo_estimado_min, t.ordem
           FROM uni_trilha t
          WHERE t.ativo = 1 AND (t.tenant_id IS NULL OR t.tenant_id = ?)
          ORDER BY t.ordem, t.titulo"
    );
    $st->execute([$ctx['tenant']]);
    $trilhas = $st->fetchAll();
    foreach ($trilhas as &$t) {
        $st2 = uni_trilha_stats($ctx, (int)$t['id']);
        $t['total'] = $st2['total'];
        $t['concluidas'] = $st2['concluidas'];
        $t['percentual'] = $st2['percentual'];
        $t['perfis'] = uni_trilha_perfis((int)$t['id']);
    }
    return $trilhas;
}

/** Trilhas recomendadas para o perfil do usuário logado. */
function uni_trilhas_do_perfil(array $ctx): array
{
    return array_values(array_filter(uni_trilhas_todas($ctx), function ($t) use ($ctx) {
        return in_array($ctx['role'], $t['perfis'], true);
    }));
}

/** Trilhas em que o usuário está matriculado (com progresso). */
function uni_trilhas_matriculadas(array $ctx): array
{
    if (empty($ctx['uid'])) return [];
    $q = uni_pdo()->prepare("SELECT trilha_id FROM uni_matricula WHERE usuario_id = ? AND status = 'ativa'");
    $q->execute([$ctx['uid']]);
    $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) return [];
    return array_values(array_filter(uni_trilhas_todas($ctx), fn($t) => in_array((int)$t['id'], $ids, true)));
}

/** Perfis (papéis) associados a uma trilha. */
function uni_trilha_perfis(int $trilhaId): array
{
    $st = uni_pdo()->prepare("SELECT perfil FROM uni_trilha_perfil WHERE trilha_id = ?");
    $st->execute([$trilhaId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Ids das cápsulas VISÍVEIS de uma trilha (via seus cursos), na ordem curso→cápsula. */
function uni_trilha_capsulas(array $ctx, int $trilhaId): array
{
    $pdo = uni_pdo();
    $st = $pdo->prepare(
        "SELECT c.id, c.slug, c.titulo, c.tipo, c.duracao_seg, c.modulo,
                cur.id AS curso_id, cur.titulo AS curso_titulo, cur.ordem AS curso_ordem,
                cc.ordem AS cap_ordem, cc.obrigatorio,
                (SELECT rota FROM uni_capsula_rota WHERE capsula_id=c.id ORDER BY principal DESC LIMIT 1) AS rota
           FROM uni_trilha_curso tc
           JOIN uni_curso cur          ON cur.id = tc.curso_id AND cur.ativo = 1
           JOIN uni_curso_capsula cc   ON cc.curso_id = cur.id
           JOIN uni_capsula c          ON c.id = cc.capsula_id AND c.status='publicado' AND c.ativo=1
          WHERE tc.trilha_id = ? AND (c.tenant_id IS NULL OR c.tenant_id = ?)
          ORDER BY tc.ordem, cur.ordem, cc.ordem"
    );
    $st->execute([$trilhaId, $ctx['tenant']]);
    $linhas = $st->fetchAll();
    if (!$linhas) return [];

    /* filtra por permissão */
    $ids = array_map(fn($l) => (int)$l['id'], $linhas);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $qp = $pdo->prepare("SELECT capsula_id, permissao_chave FROM uni_capsula_permissao WHERE capsula_id IN ({$in})");
    $qp->execute($ids);
    $permsPor = [];
    foreach ($qp->fetchAll() as $r) $permsPor[(int)$r['capsula_id']][] = (string)$r['permissao_chave'];

    return array_values(array_filter($linhas, fn($l) => uni_visivel($ctx, (string)$l['tipo'], $permsPor[(int)$l['id']] ?? [])));
}

/** Trilha por slug: metadados + cursos com suas cápsulas (visíveis) + progresso. */
function uni_trilha_por_slug(string $slug, array $ctx): ?array
{
    $pdo = uni_pdo();
    $st = $pdo->prepare(
        "SELECT * FROM uni_trilha WHERE slug = ? AND ativo = 1 AND (tenant_id IS NULL OR tenant_id = ?) LIMIT 1"
    );
    $st->execute([$slug, $ctx['tenant']]);
    $t = $st->fetch();
    if (!$t) return null;

    $capsulas = uni_trilha_capsulas($ctx, (int)$t['id']);
    $prog = uni_progresso_usuario($ctx, array_map(fn($c) => (int)$c['id'], $capsulas));

    /* agrupa por curso, anexando estado de progresso */
    $cursos = [];
    foreach ($capsulas as $c) {
        $cid = (int)$c['curso_id'];
        if (!isset($cursos[$cid])) {
            $cursos[$cid] = ['id' => $cid, 'titulo' => $c['curso_titulo'], 'ordem' => (int)$c['curso_ordem'], 'capsulas' => []];
        }
        $c['estado'] = $prog[(int)$c['id']]['estado'] ?? 'nao_iniciada';
        $cursos[$cid]['capsulas'][] = $c;
    }
    $t['cursos'] = array_values($cursos);
    $t['perfis'] = uni_trilha_perfis((int)$t['id']);
    $stats = uni_trilha_stats($ctx, (int)$t['id']);
    $t = array_merge($t, $stats);
    $t['matriculado'] = uni_esta_matriculado($ctx, (int)$t['id']);
    return $t;
}

/** Progresso do usuário para um conjunto de cápsulas: id => [estado, percentual]. */
function uni_progresso_usuario(array $ctx, array $capsulaIds): array
{
    if (!$capsulaIds || !$ctx['uid']) return [];
    $pdo = uni_pdo();
    $in = implode(',', array_fill(0, count($capsulaIds), '?'));
    $st = $pdo->prepare(
        "SELECT capsula_id, estado, percentual FROM uni_progresso
          WHERE usuario_id = ? AND capsula_id IN ({$in})"
    );
    $st->execute(array_merge([$ctx['uid']], $capsulaIds));
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['capsula_id']] = ['estado' => $r['estado'], 'percentual' => (int)$r['percentual']];
    }
    return $out;
}

/** Estatística de conclusão de uma trilha para o usuário. */
function uni_trilha_stats(array $ctx, int $trilhaId): array
{
    $caps = uni_trilha_capsulas($ctx, $trilhaId);
    $ids = array_map(fn($c) => (int)$c['id'], $caps);
    $total = count($ids);
    $prog = uni_progresso_usuario($ctx, $ids);
    $concl = 0;
    foreach ($ids as $id) if (($prog[$id]['estado'] ?? '') === 'concluida') $concl++;
    return [
        'total' => $total,
        'concluidas' => $concl,
        'percentual' => $total > 0 ? (int)round($concl * 100 / $total) : 0,
    ];
}

/** Marca o progresso de uma cápsula (upsert). $estado: nao_iniciada|em_andamento|concluida. */
function uni_marcar_progresso(array $ctx, int $capsulaId, string $estado): bool
{
    if (!$ctx['uid'] || !in_array($estado, ['nao_iniciada', 'em_andamento', 'concluida'], true)) return false;
    /* cápsula existe e é visível? */
    $c = uni_pdo()->prepare("SELECT tipo FROM uni_capsula WHERE id = ? AND status='publicado' AND ativo=1 LIMIT 1");
    $c->execute([$capsulaId]);
    if (!$c->fetch()) return false;
    $pct = $estado === 'concluida' ? 100 : ($estado === 'em_andamento' ? 50 : 0);
    uni_pdo()->prepare(
        "INSERT INTO uni_progresso (tenant_id, usuario_id, capsula_id, estado, percentual, origem)
         VALUES (?,?,?,?,?, 'web')
         ON DUPLICATE KEY UPDATE estado=VALUES(estado), percentual=VALUES(percentual), atualizado_em=CURRENT_TIMESTAMP"
    )->execute([$ctx['tenant'], $ctx['uid'], $capsulaId, $estado, $pct]);
    return true;
}

/** Matricula o usuário na trilha (idempotente). */
function uni_matricular(array $ctx, int $trilhaId, string $origem = 'autoinscricao'): bool
{
    if (!$ctx['uid']) return false;
    $t = uni_pdo()->prepare("SELECT id FROM uni_trilha WHERE id = ? AND ativo = 1 LIMIT 1");
    $t->execute([$trilhaId]);
    if (!$t->fetch()) return false;
    uni_pdo()->prepare(
        "INSERT INTO uni_matricula (tenant_id, usuario_id, trilha_id, origem)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE status='ativa'"
    )->execute([$ctx['tenant'], $ctx['uid'], $trilhaId, $origem]);
    return true;
}

function uni_esta_matriculado(array $ctx, int $trilhaId): bool
{
    if (!$ctx['uid']) return false;
    $st = uni_pdo()->prepare("SELECT 1 FROM uni_matricula WHERE usuario_id = ? AND trilha_id = ? LIMIT 1");
    $st->execute([$ctx['uid'], $trilhaId]);
    return (bool)$st->fetchColumn();
}
