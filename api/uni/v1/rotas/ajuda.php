<?php
declare(strict_types=1);
/* ============================================================
   Universidade VERO — api/uni/v1/rotas/ajuda.php
   GET  ajuda?rota=&tela_app=  → conteúdo da tela (shape §8), filtrado
                                  por permissão. 204 se não houver nada.
   POST evento                 → registra uni_ajuda_evento (telemetria).
   ============================================================ */

/** Visibilidade da cápsula: ENTENDER/CONSULTAR (conceito/referência) e
 *  cápsulas sem permissão exigida são de todos; as demais precisam de ≥1
 *  permissão do usuário (Regra de Ouro nº 5). */
function uni_capsula_visivel(array $ctx, string $tipo, array $permissoes): bool
{
    if ($tipo === 'ENTENDER' || $tipo === 'CONSULTAR') return true;
    if (!$permissoes) return true;
    foreach ($permissoes as $p) {
        if (uni_pode($p, $ctx)) return true;
    }
    return false;
}

/** Quebra o corpo markdown nos blocos do §8 (finalidade, como_fazer, erros). */
function uni_blocos_md(string $md): array
{
    $secoes = [];
    $atual = null;
    foreach (preg_split('/\R/', $md) as $ln) {
        if (preg_match('/^##\s+(.+?)\s*$/', $ln, $m)) {
            $atual = mb_strtolower(trim($m[1]));
            $secoes[$atual] = [];
        } elseif ($atual !== null) {
            $secoes[$atual][] = $ln;
        }
    }
    $texto = fn(string $k) => isset($secoes[$k]) ? trim(implode("\n", $secoes[$k])) : '';

    /* finalidade: parágrafo. */
    $finalidade = $texto('finalidade');

    /* como fazer: itens de lista numerada. */
    $comoFazer = [];
    foreach ($secoes['como fazer'] ?? [] as $ln) {
        if (preg_match('/^\s*\d+\.\s+(.+)$/', $ln, $m)) $comoFazer[] = trim($m[1]);
    }

    /* erros comuns: '- **Título.** texto' → {titulo, texto}. */
    $erros = [];
    foreach ($secoes['erros comuns'] ?? [] as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] !== '-') continue;
        $item = trim(ltrim($ln, '- '));
        if (preg_match('/^\*\*(.+?)\*\*\s*(.*)$/', $item, $m)) {
            $erros[] = ['titulo' => rtrim($m[1], '.'), 'texto' => trim($m[2])];
        } else {
            $erros[] = ['titulo' => '', 'texto' => $item];
        }
    }

    return ['finalidade' => $finalidade, 'como_fazer' => $comoFazer, 'erros_comuns' => $erros];
}

function rota_uni_ajuda(array $ctx): never
{
    $rotaRaw = trim((string)($_GET['rota'] ?? ''));
    if ($rotaRaw === '') uni_json(422, ['erro' => 'rota_obrigatoria']);
    $rota = parse_url($rotaRaw, PHP_URL_PATH) ?: $rotaRaw;
    $telaApp = trim((string)($_GET['tela_app'] ?? ''));

    $pdo = uni_pdo();
    $tenant = $ctx['tenant'];

    /* Cápsulas ancoradas à rota (ou tela_app), do tenant ou globais. */
    $cond = "r.rota = :rota";
    $params = [':rota' => $rota, ':tenant' => $tenant];
    if ($telaApp !== '') { $cond .= " OR r.tela_app = :app"; $params[':app'] = $telaApp; }

    $sql = "SELECT c.id, c.slug, c.titulo, c.tipo, c.resumo, c.corpo_md, c.duracao_seg,
                   c.versao, c.revisado_em, MAX(r.principal) AS principal,
                   (SELECT DATE(MAX(publicado_em)) FROM uni_publicacao WHERE capsula_id = c.id) AS publicado_em
              FROM uni_capsula c
              JOIN uni_capsula_rota r ON r.capsula_id = c.id
             WHERE c.status = 'publicado' AND c.ativo = 1
               AND (c.tenant_id IS NULL OR c.tenant_id = :tenant)
               AND ({$cond})
             GROUP BY c.id
             ORDER BY principal DESC, c.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $linhas = $st->fetchAll();

    if (!$linhas) uni_json(204, null);

    /* Permissões por cápsula (uma consulta). */
    $ids = array_map(fn($l) => (int)$l['id'], $linhas);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $qp = $pdo->prepare("SELECT capsula_id, permissao_chave FROM uni_capsula_permissao WHERE capsula_id IN ({$in})");
    $qp->execute($ids);
    $permsPor = [];
    foreach ($qp->fetchAll() as $r) $permsPor[(int)$r['capsula_id']][] = (string)$r['permissao_chave'];

    /* Filtra por visibilidade. */
    $visiveis = [];
    foreach ($linhas as $l) {
        $id = (int)$l['id'];
        if (uni_capsula_visivel($ctx, (string)$l['tipo'], $permsPor[$id] ?? [])) {
            $visiveis[] = $l;
        }
    }
    if (!$visiveis) uni_json(204, null);

    /* Cápsula principal = primeira (principal DESC). */
    $principal = $visiveis[0];
    $blocos = uni_blocos_md((string)$principal['corpo_md']);

    /* Vídeo (uni_ativo) da principal, se houver. */
    $qa = $pdo->prepare("SELECT url, duracao_seg, tipo FROM uni_ativo WHERE capsula_id = ? AND tipo = 'video' AND estado = 'ok' ORDER BY ordem ASC LIMIT 1");
    $qa->execute([(int)$principal['id']]);
    $video = $qa->fetch();
    $blocos['video'] = $video ? [
        'url' => (string)$video['url'],
        'duracao_seg' => $video['duracao_seg'] !== null ? (int)$video['duracao_seg'] : null,
        'titulo' => (string)$principal['titulo'],
    ] : null;

    /* Fluxo antes/depois (relações da principal → título + rota principal do alvo). */
    $blocos['fluxo'] = ['antes' => [], 'depois' => []];
    $qr = $pdo->prepare(
        "SELECT rel.tipo, c2.titulo, c2.status,
                (SELECT rota FROM uni_capsula_rota WHERE capsula_id = c2.id ORDER BY principal DESC LIMIT 1) AS rota
           FROM uni_capsula_relacao rel
           JOIN uni_capsula c2 ON c2.id = rel.relacionada_id
          WHERE rel.capsula_id = ? AND rel.tipo IN ('prerequisito','proximo') AND c2.status = 'publicado' AND c2.ativo = 1"
    );
    $qr->execute([(int)$principal['id']]);
    foreach ($qr->fetchAll() as $r) {
        $alvo = ['titulo' => (string)$r['titulo'], 'rota' => (string)($r['rota'] ?? '')];
        if ($r['tipo'] === 'prerequisito') $blocos['fluxo']['antes'][] = $alvo;
        else $blocos['fluxo']['depois'][] = $alvo;
    }

    /* Lista de cápsulas visíveis + ações. */
    $capsulas = array_map(fn($l) => [
        'slug' => (string)$l['slug'],
        'titulo' => (string)$l['titulo'],
        'tipo' => (string)$l['tipo'],
        'duracao_seg' => $l['duracao_seg'] !== null ? (int)$l['duracao_seg'] : null,
    ], $visiveis);

    $temPraticar = false;
    foreach ($visiveis as $l) if ($l['tipo'] === 'PRATICAR') $temPraticar = true;

    uni_json(200, [
        'tela'          => ['titulo' => (string)$principal['titulo'], 'rota' => $rota],
        'atualizado_em' => $principal['revisado_em'] ?: ($principal['publicado_em'] ?: null),
        'versao'        => (string)($principal['versao'] ?? ''),
        'blocos'        => $blocos,
        'capsulas'      => $capsulas,
        'acoes'         => [
            'assistente' => false,                 // IA entra na Fase 1+ (Regra nº: base madura antes da IA)
            'praticar'   => $temPraticar,
            'ver_tudo'   => '/universidade/capsula/' . ($principal['slug'] ?? ''),
        ],
    ]);
}

/** Telemetria de uso do "?" (backlog de UX do produto). */
function rota_uni_evento(array $ctx): never
{
    $corpo = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($corpo)) $corpo = [];
    $rota = trim((string)($corpo['rota'] ?? ''));
    $acao = trim((string)($corpo['acao'] ?? ''));
    if ($rota === '' || !in_array($acao, ['abriu', 'assistiu', 'perguntou', 'abriu_chamado'], true)) {
        uni_json(422, ['erro' => 'evento_invalido']);
    }
    $capsulaId = isset($corpo['capsula_id']) && $corpo['capsula_id'] !== '' ? (int)$corpo['capsula_id'] : null;

    try {
        uni_pdo()->prepare(
            "INSERT INTO uni_ajuda_evento (tenant_id, usuario_id, rota, acao, capsula_id) VALUES (?,?,?,?,?)"
        )->execute([$ctx['tenant'], $ctx['uid'], mb_substr($rota, 0, 200), $acao, $capsulaId]);
    } catch (Throwable $e) {
        error_log('[uni_evento] ' . $e->getMessage()); // best-effort: nunca quebra o widget
    }
    uni_json(204, null);
}
