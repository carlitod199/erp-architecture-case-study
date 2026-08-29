<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/uni_portal.php
   Biblioteca do Portal da Universidade (/universidade). Conteúdo
   vem do banco SEPARADO (uni_pdo); auth/permissão do $_SESSION do ERP.
   Visibilidade segue a mesma regra da API de ajuda (Regra de Ouro nº 5).
   ============================================================ */

require_once __DIR__ . '/uni_db.php';
require_once __DIR__ . '/uni_auth.php'; // LMS: contexto vem do login PRÓPRIO da Universidade

/** Contexto do ALUNO logado (login próprio do LMS), no formato usado pelas libs. */
function uni_ctx(): array
{
    return uni_auth_ctx();
}

/** Wildcards do ERP ('*', 'base.*', 'base.micro.*', '*.acao'). */
function uni_perm_ok(string $slug, array $ctx): bool
{
    $role = $ctx['role'];
    $perms = $ctx['perms'];
    if (in_array($role, ['super_admin', 'club_admin'], true)) return true;
    if ($slug === '') return false;
    if (in_array('*', $perms, true) || in_array($slug, $perms, true)) return true;
    $partes = explode('.', $slug);
    $acao = end($partes);
    $prefixo = '';
    foreach ($partes as $p) {
        $prefixo = $prefixo === '' ? $p : "{$prefixo}.{$p}";
        if (in_array("{$prefixo}.*", $perms, true)) return true;
    }
    return count($partes) > 1 && in_array("*.{$acao}", $perms, true);
}

/** ENTENDER/CONSULTAR e cápsulas sem permissão = de todos; demais exigem ≥1 permissão. */
function uni_visivel(array $ctx, string $tipo, array $permissoes): bool
{
    if (!empty($ctx['aluno'])) return true; // LMS: aluno vê todo conteúdo publicado (sem RBAC do ERP)
    if ($tipo === 'ENTENDER' || $tipo === 'CONSULTAR') return true;
    if (!$permissoes) return true;
    foreach ($permissoes as $p) if (uni_perm_ok($p, $ctx)) return true;
    return false;
}

/** Catálogo: cápsulas publicadas visíveis ao usuário, com filtros opcionais. */
function uni_catalogo(array $ctx, array $f = []): array
{
    $pdo = uni_pdo();
    $sql = "SELECT c.id, c.slug, c.titulo, c.tipo, c.resumo, c.modulo, c.objetivo,
                   c.nivel, c.duracao_seg, c.versao,
                   (SELECT rota FROM uni_capsula_rota WHERE capsula_id = c.id ORDER BY principal DESC LIMIT 1) AS rota
              FROM uni_capsula c
             WHERE c.status = 'publicado' AND c.ativo = 1
               AND (c.tenant_id IS NULL OR c.tenant_id = :tenant)";
    $p = [':tenant' => $ctx['tenant']];
    if (!empty($f['modulo'])) { $sql .= " AND c.modulo = :modulo"; $p[':modulo'] = $f['modulo']; }
    if (!empty($f['tipo']))   { $sql .= " AND c.tipo = :tipo";     $p[':tipo']   = $f['tipo']; }
    if (!empty($f['nivel']))  { $sql .= " AND c.nivel = :nivel";   $p[':nivel']  = $f['nivel']; }
    if (!empty($f['q'])) {
        $sql .= " AND (c.titulo LIKE :q OR c.resumo LIKE :q OR c.modulo LIKE :q)";
        $p[':q'] = '%' . $f['q'] . '%';
    }
    $sql .= " ORDER BY c.modulo, c.titulo";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $linhas = $st->fetchAll();
    if (!$linhas) return [];

    $ids = array_map(fn($l) => (int)$l['id'], $linhas);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $qp = $pdo->prepare("SELECT capsula_id, permissao_chave FROM uni_capsula_permissao WHERE capsula_id IN ({$in})");
    $qp->execute($ids);
    $permsPor = [];
    foreach ($qp->fetchAll() as $r) $permsPor[(int)$r['capsula_id']][] = (string)$r['permissao_chave'];

    return array_values(array_filter($linhas, fn($l) => uni_visivel($ctx, (string)$l['tipo'], $permsPor[(int)$l['id']] ?? [])));
}

/** Módulos distintos (para o filtro), só entre cápsulas publicadas. */
function uni_modulos(array $ctx): array
{
    $st = uni_pdo()->prepare(
        "SELECT DISTINCT modulo FROM uni_capsula
          WHERE status='publicado' AND ativo=1 AND modulo IS NOT NULL
            AND (tenant_id IS NULL OR tenant_id = ?) ORDER BY modulo"
    );
    $st->execute([$ctx['tenant']]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Uma cápsula pelo slug, respeitando visibilidade. Null se não existe/sem acesso. */
function uni_capsula(string $slug, array $ctx): ?array
{
    $pdo = uni_pdo();
    $st = $pdo->prepare(
        "SELECT * FROM uni_capsula
          WHERE slug = ? AND status='publicado' AND ativo=1
            AND (tenant_id IS NULL OR tenant_id = ?) LIMIT 1"
    );
    $st->execute([$slug, $ctx['tenant']]);
    $c = $st->fetch();
    if (!$c) return null;

    $qp = $pdo->prepare("SELECT permissao_chave FROM uni_capsula_permissao WHERE capsula_id = ?");
    $qp->execute([(int)$c['id']]);
    $perms = $qp->fetchAll(PDO::FETCH_COLUMN);
    if (!uni_visivel($ctx, (string)$c['tipo'], $perms)) return null;
    $c['permissoes'] = $perms;

    $qr = $pdo->prepare("SELECT rota, principal FROM uni_capsula_rota WHERE capsula_id = ? ORDER BY principal DESC");
    $qr->execute([(int)$c['id']]);
    $c['rotas'] = $qr->fetchAll();

    /* relações (antes/depois/relacionado) → título + rota da relacionada publicada */
    $qx = $pdo->prepare(
        "SELECT rel.tipo, c2.slug, c2.titulo,
                (SELECT rota FROM uni_capsula_rota WHERE capsula_id=c2.id ORDER BY principal DESC LIMIT 1) AS rota
           FROM uni_capsula_relacao rel
           JOIN uni_capsula c2 ON c2.id = rel.relacionada_id
          WHERE rel.capsula_id = ? AND c2.status='publicado' AND c2.ativo=1"
    );
    $qx->execute([(int)$c['id']]);
    $c['relacoes'] = ['prerequisito' => [], 'proximo' => [], 'relacionado' => []];
    foreach ($qx->fetchAll() as $r) {
        $tp = $r['tipo'];
        if (isset($c['relacoes'][$tp])) $c['relacoes'][$tp][] = $r;
    }
    return $c;
}

/** Passos visuais (com print marcado) de uma cápsula, na ordem. */
function uni_passos(int $capsulaId): array
{
    $st = uni_pdo()->prepare(
        "SELECT ordem, texto, imagem_url, marca_label, estado
           FROM uni_passo WHERE capsula_id = ? ORDER BY ordem"
    );
    $st->execute([$capsulaId]);
    return $st->fetchAll();
}

/** Cabeçalho unificado do portal (logo + nav). $ativo: catalogo|trilha|praticar|certificados|equipe.
 *  "Equipe" só aparece para dono/gestor/administrador. Devolve HTML. */
function uni_portal_header(array $ctx, string $ativo = ''): string
{
    $base = defined('BIOS_BASE') ? BIOS_BASE : '';
    $itens = [
        'catalogo'     => ['Catálogo',     $base . '/universidade/'],
        'trilha'       => ['Minha trilha',  $base . '/universidade/minha-trilha.php'],
        'praticar'     => ['Praticar',      $base . '/universidade/praticar.php'],
    ];
    if (in_array($ctx['role'] ?? '', ['super_admin', 'club_admin', 'administrador', 'dono', 'gestor'], true)) {
        $itens['equipe'] = ['Equipe', $base . '/universidade/equipe.php'];
    }
    $nav = '';
    foreach ($itens as $k => $v) {
        $nav .= '<a href="' . uni_h($v[1]) . '"' . ($k === $ativo ? ' class="ativo"' : '') . '>' . uni_h($v[0]) . '</a>';
    }
    return '<header class="up-top"><div class="up-top-in">'
        . '<a class="up-brand" href="' . uni_h($base) . '/universidade/">'
        . '<img src="' . uni_h($base) . '/assets/img/brand/vero-lockup-white.svg" alt="VERO" class="up-logo-img">'
        . '<span class="up-brand-txt"><span class="up-brand-title">Universidade</span>'
        . '<span class="up-brand-tag">Aprendizado &amp; Treinamento</span></span></a>'
        . '<nav class="up-nav">' . $nav . '</nav>'
        . '<div class="up-user"><span class="up-oi">' . uni_h($ctx['nome'] ?? '') . '</span>'
        . '<a class="up-voltar" href="' . uni_h($base) . '/universidade/sair.php">Sair</a></div>'
        . '</div></header>';
}

/** Rótulos amigáveis. */
function uni_tipo_label(string $t): string
{
    return ['FAZER' => 'Fazer', 'ENTENDER' => 'Entender', 'CONSULTAR' => 'Consultar',
            'PRATICAR' => 'Praticar', 'VERIFICAR' => 'Verificar'][$t] ?? $t;
}
function uni_modulo_label(string $m): string
{
    static $map = [
        'operacao-agricola' => 'Operação Agrícola',
        'mip'         => 'MIP e Defensivos',
        'nutricao'    => 'Nutrição',
        'irrigacao'   => 'Irrigação',
        'colheita'    => 'Colheita',
        'safra'       => 'Safra',
        'estoque'     => 'Estoque e Insumos',
        'compras'     => 'Compras',
        'maquinas'    => 'Máquinas e Frota',
        'patrimonio'  => 'Patrimônio',
        'pessoas'     => 'Pessoas e Equipes',
        'comercial'   => 'Comercial',
        'custos'      => 'Custos e Safra',
        'financeiro'  => 'Financeiro',
        'fiscal'      => 'Fiscal',
    ];
    return $map[$m] ?? ucwords(str_replace('-', ' ', $m));
}

/** Ordem de exibição dos módulos no catálogo (produção → suprimentos → financeiro). */
function uni_modulo_ordem(): array
{
    return ['operacao-agricola', 'mip', 'nutricao', 'irrigacao', 'colheita', 'safra',
            'estoque', 'compras', 'maquinas', 'patrimonio', 'pessoas', 'comercial',
            'custos', 'financeiro', 'fiscal'];
}
function uni_duracao(?int $seg): string
{
    if (!$seg) return '';
    $min = (int)round($seg / 60);
    return $min <= 1 ? '1 min' : "{$min} min";
}

/** Escapa HTML. (definição canônica em uni_auth.php; guarda evita redeclaração) */
if (!function_exists('uni_h')) {
    function uni_h(?string $s): string
    {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** Render mínimo de markdown (subconjunto das cápsulas): ##, listas, **negrito**, parágrafos. */
function uni_md_html(string $md): string
{
    $linhas = preg_split('/\R/', $md);
    $out = '';
    $modo = null; // 'ol' | 'ul' | null
    $buffer = [];

    $fechaLista = function () use (&$out, &$modo) {
        if ($modo) { $out .= "</{$modo}>\n"; $modo = null; }
    };
    $inline = function (string $s): string {
        return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', uni_h($s));
    };

    foreach ($linhas as $ln) {
        $t = rtrim($ln);
        if (preg_match('/^##\s+(.+)$/', $t, $m)) {
            $fechaLista();
            $out .= '<h2>' . uni_h(trim($m[1])) . "</h2>\n";
        } elseif (preg_match('/^\s*\d+\.\s+(.+)$/', $t, $m)) {
            if ($modo !== 'ol') { $fechaLista(); $out .= "<ol>\n"; $modo = 'ol'; }
            $out .= '<li>' . $inline(trim($m[1])) . "</li>\n";
        } elseif (preg_match('/^\s*[-*]\s+(.+)$/', $t, $m)) {
            if ($modo !== 'ul') { $fechaLista(); $out .= "<ul>\n"; $modo = 'ul'; }
            $out .= '<li>' . $inline(trim($m[1])) . "</li>\n";
        } elseif (trim($t) === '') {
            $fechaLista();
        } else {
            $fechaLista();
            $out .= '<p>' . $inline(trim($t)) . "</p>\n";
        }
    }
    $fechaLista();
    return $out;
}
