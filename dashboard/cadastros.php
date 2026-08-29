<?php
/* ============================================================
   VERO — Hub de Cadastros (A0-23)
   Página de ATALHOS para as telas de cadastro/estrutura, agrupadas
   por módulo. NÃO move nem duplica telas: LÊ A MATRIZ do menu
   (bios_menu_macros + helpers de permissão/rota) para nascer
   sincronizada — rota, label, ícone e visibilidade vêm da fonte única.
   Cada atalho respeita a permissão do usuário (mesmo filtro da sidebar).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/menu_agro.php';
require_once __DIR__ . '/../includes/agro_icons.php';   // bios_micro_icon (também carregado pelo header)

/* Classificação de "cadastro" (master data / estrutura / configuração).
   A matriz não tem flag própria hoje — então mantemos esta lista curada
   por módulo E honramos um futuro flag `'cad' => true` na matriz
   (feature-detection): se o A0 marcar os micros, eles entram sozinhos.
   Nada aqui inventa rota — a rota/permissão saem sempre da matriz. */
$CAD = [
    'agricola'      => ['safras', 'fazendas', 'areas_produtivas', 'talhoes', 'valvulas', 'culturas', 'variedades', 'tipos_atividade'],
    'estoque'       => ['produtos_insumos', 'grupos_subgrupos', 'almoxarifados'],
    'compras'       => ['fornecedores'],
    'custos'        => ['metodologias', 'parametros_cultura'],
    'nutricao'      => ['nutrientes', 'faixas_nutricionais'],
    'mip'           => ['pragas', 'doencas', 'alvos_controle', 'pontos_amostragem'],
    'irrigacao'     => ['setores_irrigacao', 'pivos', 'bombas'],
    'maquinas'      => ['maquinas', 'veiculos', 'implementos'],
    'pessoas'       => ['equipes', 'operadores', 'terceirizados', 'responsaveis_tecnicos', 'premiacao', 'encargos'],
    'comercial'     => ['compradores', 'armazenagem_propria', 'armazenagem_terceiros'],
    'financeiro'    => ['plano_contas', 'centros_custo', 'contas_bancarias'],
    'patrimonio'    => ['terras', 'benfeitorias', 'equipamentos', 'maquinas_ativos', 'veiculos_ativos'],
    'configuracoes' => ['empresa_fazenda', 'usuarios', 'perfis_acesso', 'unidades_medida', 'categorias'],
];

$ctx    = bios_menu_ctx();
$grupos = [];
foreach (bios_menu_macros() as $macro) {
    $ms    = $macro['slug'];
    $itens = [];
    foreach (bios_menu_micros_visiveis($macro, $ctx) as $micro) {
        $isCad = !empty($micro['cad']) || in_array($micro['slug'], $CAD[$ms] ?? [], true);
        if (!$isCad) {
            continue;
        }
        $itens[] = [
            'label' => $micro['label'],
            'slug'  => $micro['slug'],
            'href'  => bios_menu_micro_href(BIOS_BASE, $macro, $micro),
            'dev'   => empty($micro['rota']),   // sem rota na matriz = ainda não disponível
        ];
    }
    if ($itens) {
        $grupos[] = ['label' => $macro['label'], 'slug' => $ms, 'itens' => $itens];
    }
}
$totalAtalhos = array_sum(array_map(static fn(array $g): int => count($g['itens']), $grupos));

$GUARD      = ['macro' => 'dashboard', 'micro' => 'cadastros'];
$PAGE_VIEW  = 'dashboard.cadastros';
$PAGE_TITLE = 'Cadastros';
$EXTRA_HEAD = function_exists('vero_assets') ? vero_assets() : '';
require __DIR__ . '/../includes/agro_header.php';
?>
<style>
  .cadhub__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(340px,100%),1fr));gap:16px}
  .cadhub__links{padding:10px 12px 12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px}
  .cadhub__lnk{display:flex;align-items:center;gap:8px;padding:9px 11px;border-radius:9px;
    border:1px solid var(--border,#E3D9C8);background:#fff;color:var(--ink,#241B14);
    text-decoration:none;font-size:13px;line-height:1.2;transition:.14s}
  .cadhub__lnk:hover{border-color:var(--accent,#005059);background:#F5F1E8;color:var(--accent,#005059)}
  .cadhub__lnk svg{flex:0 0 auto;opacity:.8}
  .cadhub__lnk span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .cadhub__lnk.is-dev{opacity:.5;cursor:default;background:#FAF7F0}
  .cadhub__lnk.is-dev:hover{border-color:var(--border,#E3D9C8);background:#FAF7F0;color:var(--ink,#241B14)}
  .cadhub__lnk.is-dev .cadhub__soon{margin-left:auto;font-size:10px;color:var(--muted,#8B7C68);white-space:nowrap}
  .cadhub__count{font-size:11px;color:var(--muted,#8B7C68);font-variant-numeric:tabular-nums}
  .cadhub__hidden{display:none !important}
</style>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Cadastros', 'Atalhos para as telas de cadastro e estrutura, por módulo.', null) ?>

  <div class="vcard" style="padding:12px 14px;margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <input type="search" id="cadBusca" class="vfield" placeholder="Buscar cadastro (ex.: talhão, fornecedor, plano de contas)…"
           aria-label="Buscar cadastro" style="flex:1;min-width:220px" autocomplete="off">
    <span class="cadhub__count"><?= (int)$totalAtalhos ?> atalho<?= $totalAtalhos === 1 ? '' : 's' ?></span>
  </div>

  <?php if (!$grupos): ?>
    <div class="vempty">Nenhum cadastro disponível para o seu perfil.</div>
  <?php else: ?>
  <div class="cadhub__grid" id="cadGrid">
    <?php foreach ($grupos as $g): ?>
      <div class="vcard cadhub__sec" data-sec>
        <div class="vtoolbar">
          <strong style="font-size:14px"><?= h($g['label']) ?></strong>
          <div style="flex:1"></div>
          <span class="cadhub__count"><?= count($g['itens']) ?></span>
        </div>
        <div class="cadhub__links">
          <?php foreach ($g['itens'] as $it): ?>
            <?php if ($it['dev']): ?>
              <span class="cadhub__lnk is-dev" data-item data-label="<?= h(mb_strtolower($it['label'])) ?>" title="<?= h($it['label']) ?> — em breve">
                <?= bios_micro_icon($it['slug'], 18) ?><span><?= h($it['label']) ?></span><span class="cadhub__soon">em breve</span>
              </span>
            <?php else: ?>
              <a class="cadhub__lnk" href="<?= h($it['href']) ?>" data-item data-label="<?= h(mb_strtolower($it['label'])) ?>" title="<?= h($it['label']) ?>">
                <?= bios_micro_icon($it['slug'], 18) ?><span><?= h($it['label']) ?></span>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="vempty cadhub__hidden" id="cadVazio">Nenhum cadastro encontrado para a busca.</div>
  <?php endif; ?>
</div>

<script>
(function () {
  var busca = document.getElementById('cadBusca');
  if (!busca) return;
  var secoes = Array.prototype.slice.call(document.querySelectorAll('#cadGrid [data-sec]'));
  var vazio  = document.getElementById('cadVazio');
  busca.addEventListener('input', function () {
    var q = busca.value.trim().toLowerCase();
    var algum = false;
    secoes.forEach(function (sec) {
      var itens = sec.querySelectorAll('[data-item]');
      var visiveis = 0;
      Array.prototype.forEach.call(itens, function (it) {
        var ok = !q || (it.getAttribute('data-label') || '').indexOf(q) >= 0;
        it.classList.toggle('cadhub__hidden', !ok);
        if (ok) visiveis++;
      });
      sec.classList.toggle('cadhub__hidden', visiveis === 0);
      if (visiveis) algum = true;
    });
    if (vazio) vazio.classList.toggle('cadhub__hidden', algum);
  });
})();
</script>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
