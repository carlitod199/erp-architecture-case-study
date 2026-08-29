<?php
/* ============================================================
   VERO — Hub de Apontamentos (A0-27)
   Launcher LEVE (sem carregar dados) com as ações de REGISTRO do dia:
   Campo, Irrigação, Aplicação (DF/IF), Abastecimento, Colheita.
   Refina a versão broad anterior (A0-24) para o spec focado do A0-27
   ("hub leve", 5 ações). Mesmo padrão do Hub de Cadastros (A0-23): lê a
   matriz do menu (label/rota/ícone/permissão) para nascer sincronizado;
   respeita a permissão do usuário. NÃO move nem duplica telas.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/menu_agro.php';
require_once __DIR__ . '/../includes/agro_icons.php';

/* Ações de registro (curadas — spec A0-27). Label/ícone/rota/permissão
   saem da matriz via (macro,micro); `href` sobrescreve a rota só quando o
   micro aponta para outro alvo (colheita: o micro é o recorte de leitura;
   apontar colheita é a tela de gestão /colheita/index.php). */
$ACOES = [
    ['macro' => 'agricola',  'micro' => 'apontamentos_campo',     'desc' => 'Tratos, pessoas e máquinas no talhão',                 'cor' => '#2E8B3D', 'href' => null],
    ['macro' => 'irrigacao', 'micro' => 'apontamentos_irrigacao', 'desc' => 'Horas e volume de água por setor',                     'cor' => '#0E7E72', 'href' => null],
    ['macro' => 'mip',       'micro' => 'aplicacoes_defensivos',  'desc' => 'Pulverização e fertirrigação (DF/IF) com receituário', 'cor' => '#C77800', 'href' => null],
    ['macro' => 'maquinas',  'micro' => 'abastecimentos',         'desc' => 'Combustível e horímetro da máquina',                   'cor' => '#08262A', 'href' => null],
    ['macro' => 'agricola',  'micro' => 'colheita',               'desc' => 'Colheita, classificação e entrada no estoque',         'cor' => '#B5402F', 'href' => '/colheita/index'],
];

$ctx   = bios_menu_ctx();
$acoes = [];
foreach ($ACOES as $a) {
    $macro = bios_menu_macro($a['macro']);
    $micro = $macro ? bios_menu_micro($a['macro'], $a['micro']) : null;
    if (!$macro || !$micro) {
        continue;
    }
    // permissão (plano + perm) — mesmo gate da sidebar
    if (!bios_plano_libera($ctx['plano'], $macro['slug'], $micro['slug'])) {
        continue;
    }
    if (!vero_dbn_perm(bios_menu_micro_perm($macro, $micro), $ctx['role'], $ctx['perms'])) {
        continue;
    }
    $acoes[] = [
        'label' => $micro['label'],
        'slug'  => $micro['slug'],
        'desc'  => $a['desc'],
        'cor'   => $a['cor'],
        'href'  => $a['href'] !== null ? BIOS_BASE . $a['href'] : bios_menu_micro_href(BIOS_BASE, $macro, $micro),
    ];
}

$GUARD      = ['macro' => 'dashboard', 'micro' => 'apontamentos'];
$PAGE_VIEW  = 'dashboard.apontamentos';
$PAGE_TITLE = 'Apontamentos';
$EXTRA_HEAD = function_exists('vero_assets') ? vero_assets() : '';
require __DIR__ . '/../includes/agro_header.php';
?>
<style>
  .apo__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr));gap:16px}
  .apo__card{display:flex;align-items:center;gap:16px;padding:20px 18px;background:#fff;
    border:1px solid var(--border,#E3D9C8);border-radius:18px;text-decoration:none;color:var(--ink,#241B14);
    box-shadow:0 2px 0 rgba(24,42,32,.04);transition:transform .12s,border-color .14s,box-shadow .14s}
  .apo__card:hover{transform:translateY(-2px);border-color:var(--accent,#005059);box-shadow:0 10px 24px -14px rgba(8,38,42,.4)}
  .apo__ic{width:58px;height:58px;border-radius:16px;display:grid;place-items:center;color:#fff;flex:none}
  .apo__ic svg{width:30px;height:30px}
  .apo__t{font:700 17px/1.15 'IBM Plex Sans',system-ui,sans-serif;color:var(--ink,#241B14);display:block}
  .apo__d{font:400 12.5px/1.35 'IBM Plex Sans',system-ui,sans-serif;color:var(--muted,#8B7C68);margin-top:3px;display:block}
  .apo__go{margin-left:auto;color:var(--muted,#8B7C68);flex:none}
  .apo__go svg{width:22px;height:22px}
  .apo__card:hover .apo__go{color:var(--accent,#005059)}
</style>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Apontamentos', 'Atalhos para as telas de registro do dia.', null) ?>

  <?php if (!$acoes): ?>
    <div class="vempty">Nenhum tipo de apontamento disponível para o seu perfil.</div>
  <?php else: ?>
  <div class="apo__grid">
    <?php foreach ($acoes as $a): ?>
      <a class="apo__card" href="<?= h($a['href']) ?>">
        <span class="apo__ic" style="background:<?= h($a['cor']) ?>"><?= bios_micro_icon($a['slug'], 30) ?></span>
        <span style="min-width:0">
          <span class="apo__t"><?= h($a['label']) ?></span>
          <span class="apo__d"><?= h($a['desc']) ?></span>
        </span>
        <span class="apo__go"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
