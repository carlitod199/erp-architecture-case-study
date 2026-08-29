<?php
/* ============================================================
   VERO — Dashboard Comercialização · redesenho ECharts (A4-05)
   DEFAULT de dashboard/comercializacao.php (?classico=1 = antigo).
   Reusa as variáveis da tela ($porComprador, $qualidades,
   $porValvula, $fat, $kg…).
   NOTA DE FRONTEIRA: 1 QUERY DE LEITURA adicional (faturamento por
   mês, comercial_vendas.data_venda) — read-only, sinalizada;
   A0/A3 auditam. Regra 1/D5 intactas (só lê/plota).
   CONTRATO A3-T27d (venda×lote, análise T27 §1.5 — vale p/ TODO
   dashboard comercial): NUNCA somar VALOR DE ESTOQUE COLHIDO
   (estoque_lotes/saldos do produto agrícola) com CUSTO DA SAFRA
   (custeio_lancamentos) — o custo do lote COLH- JÁ NASCE do custo
   da safra; somar = dupla contagem. CPV comercial (kg × custo do
   lote na saída) e custo de produção são LEITURAS DISTINTAS do
   mesmo custo: exibir separados, com rótulos distintos (referência:
   custeio/resultado_safra.php, colunas CPV lote/Margem comercial).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/_dash.php'; /* regras de degradação por estado (R1) */

$precoMedio = $kg > 0 ? $fat / $kg : null;
$nComprador = count($porComprador);

/* ── Faturamento por mês (A4-05, read-only; $t em escopo) ── */
$mesVenda = vero_rows(
    "SELECT DATE_FORMAT(data_venda,'%Y-%m') AS ym, SUM(valor_total) AS fat
       FROM comercial_vendas
      WHERE tenant_id = :t AND status <> 'cancelada' AND data_venda IS NOT NULL
      GROUP BY ym ORDER BY ym DESC LIMIT 10", [':t' => $t]);
$mesVenda = array_reverse($mesVenda);
$mvLabels = $mvFat = [];
foreach ($mesVenda as $r) { $mvLabels[] = date('m/y', (int)strtotime($r['ym'] . '-01')); $mvFat[] = round((float)$r['fat'], 2); }

/* ── Faturamento por comprador (top 8, do $porComprador já em escopo) ── */
$topComp = array_slice($porComprador, 0, 8);

/* ── kg/ha por válvula (top 8 por produtividade, do $porValvula) ── */
$valvOrd = $porValvula;
usort($valvOrd, static fn($a, $b) => (float)$b['kg_ha'] <=> (float)$a['kg_ha']);
$valvTop = array_slice(array_filter($valvOrd, static fn($v) => (float)$v['kg_ha'] > 0), 0, 8);

/* configs + MODOS por estado (R1) */
/* D2 NA CONFIG: faturamento por mês com <2 meses → coluna (bars). */
$mesSeries = [['name' => 'Faturamento', 'data' => $mvFat, 'color' => '#005059']];
$mesN = count($mvLabels);
$cfgMes = $mesN >= 2 ? ['type' => 'area', 'unit' => 'brl', 'labels' => $mvLabels, 'series' => $mesSeries]
    : ($mesN === 1 ? ['type' => 'bars', 'horizontal' => false, 'unit' => 'brl', 'categories' => $mvLabels, 'series' => $mesSeries] : null);
/* D8: rankings/composição com 1 item → KPI. */
$compMode = dash_mode(count($topComp));
$cfgComp = $compMode === 'chart' ? ['type' => 'hbar', 'unit' => 'brl', 'serieName' => 'Faturamento',
    'categories' => array_map(static fn($c) => (string)$c['comprador'], $topComp),
    'values' => array_map(static fn($c) => round((float)$c['valor'], 2), $topComp)] : null;
$qualMode = ($kgQual > 0) ? dash_mode(count($qualidades)) : 'empty';
$cfgQual = $qualMode === 'chart' ? ['type' => 'donut', 'unit' => 'kg', 'centerLabel' => 'kg total',
    'categories' => array_map(static fn($q) => QUAL_UI[$q['categoria']][0] ?? (string)$q['categoria'], $qualidades),
    'values' => array_map(static fn($q) => round((float)$q['kg'], 2), $qualidades),
    'colors' => array_map(static fn($q) => QUAL_UI[$q['categoria']][1] ?? '#8A7D6E', $qualidades)] : null;
$valvMode = dash_mode(count($valvTop));
$cfgValv = $valvMode === 'chart' ? ['type' => 'hbar', 'unit' => 'num', 'serieName' => 'kg/ha',
    'categories' => array_map(static fn($v) => (string)$v['valvula'] . ' · ' . (string)$v['talhao'], $valvTop),
    'values' => array_map(static fn($v) => round((float)$v['kg_ha']), $valvTop)] : null;

$jsonBlock = static function (string $id, ?array $cfg): string {
    return $cfg === null ? '' : '<script type="application/json" id="' . $id . '">' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Comercialização', '', null) ?>

  <!-- LINHA 1 — venda consolidada -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(178px,100%),1fr));gap:12px;margin-bottom:14px">
    <div class="vkpi"><span class="vhint">Faturamento total</span>
      <strong class="vnum" style="font-size:1.5rem;color:var(--accent,#005059)">R$ <?= numFmt($fat, 0) ?></strong>
      <span class="vhint"><?= $vendas ?> venda(s)</span></div>
    <div class="vkpi"><span class="vhint">kg comercializados</span>
      <strong class="vnum" style="font-size:1.5rem"><?= numFmt($kg, 0) ?></strong></div>
    <div class="vkpi"><span class="vhint">Preço médio</span>
      <strong class="vnum" style="font-size:1.5rem"><?= $precoMedio !== null ? 'R$ ' . numFmt($precoMedio, 2) : '—' ?></strong>
      <span class="vhint">por kg</span></div>
    <div class="vkpi"><span class="vhint">Compradores</span>
      <strong class="vnum" style="font-size:1.5rem"><?= $nComprador ?></strong>
      <span class="vhint">com venda</span></div>
    <div class="vkpi"><span class="vhint">A receber (aberto)</span>
      <strong class="vnum" style="font-size:1.5rem;color:var(--amber,#B57C1A)">R$ <?= numFmt($receberAberto, 0) ?></strong></div>
  </div>

  <!-- LINHA 2 — faturamento por mês -->
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Faturamento por mês</strong><span style="display:flex;gap:8px;align-items:center"><span class="vhint">vendas confirmadas ao longo do tempo</span><?= dash_scope('geral') ?></span></div>
    <?php if ($cfgMes): ?>
      <div style="padding:12px 14px"><div data-vdash="vd-mes" style="height:220px"></div></div>
      <?= $jsonBlock('vd-mes', $cfgMes) ?>
    <?php else: ?><?= dash_empty('Nenhuma venda com data registrada.', 'Registrar venda', $base . '/comercial/vendas') ?><?php endif; ?>
  </div>

  <!-- LINHA 3 — por comprador + qualidade -->
  <div style="display:grid;grid-template-columns:3fr 2fr;gap:14px;margin-bottom:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Faturamento por comprador</strong><span style="display:flex;gap:8px;align-items:center"><span class="vhint">maiores clientes</span><?= dash_scope('geral') ?></span></div>
      <?php if ($compMode === 'chart'): ?>
        <div style="padding:10px 14px"><div data-vdash="vd-comp" style="height:<?= max(160, count($topComp) * 40 + 30) ?>px"></div></div>
        <?= $jsonBlock('vd-comp', $cfgComp) ?>
      <?php elseif ($compMode === 'kpi'): ?>
        <div class="vkpi" style="margin:14px"><span class="vhint">Único comprador</span>
          <strong class="vnum" style="font-size:1.5rem">R$ <?= numFmt((float)$topComp[0]['valor'], 0) ?></strong>
          <span class="vhint"><?= h((string)$topComp[0]['comprador']) ?> · <?= numFmt((float)$topComp[0]['kg'], 0) ?> kg</span></div>
      <?php else: ?><?= dash_empty('Nenhuma venda registrada.', 'Registrar venda', $base . '/comercial/vendas') ?><?php endif; ?>
    </div>
    <div class="vcard">
      <div class="vtoolbar"><strong>Qualidade comercializada</strong><span style="display:flex;gap:8px;align-items:center"><span class="vhint">composição por kg</span><?= dash_scope('geral') ?></span></div>
      <?php if ($qualMode === 'chart'): ?>
        <div style="padding:10px 14px"><div data-vdash="vd-qual" style="height:250px"></div></div>
        <?= $jsonBlock('vd-qual', $cfgQual) ?>
      <?php elseif ($qualMode === 'kpi'): ?>
        <div class="vkpi" style="margin:14px"><span class="vhint"><?= h(QUAL_UI[$qualidades[0]['categoria']][0] ?? (string)$qualidades[0]['categoria']) ?></span>
          <strong class="vnum" style="font-size:1.5rem"><?= numFmt((float)$qualidades[0]['kg'], 0) ?> kg</strong>
          <span class="vhint">100% da qualidade — gráfico com ≥2 categorias</span></div>
      <?php else: ?><?= dash_empty('Sem qualidades registradas.', 'Classificar produção', $base . '/comercial/classificacao_producao') ?><?php endif; ?>
    </div>
  </div>

  <!-- LINHA 4 — produtividade por válvula -->
  <?php if ($valvMode !== 'empty'): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Produtividade por válvula (kg/ha)</strong><span style="display:flex;gap:8px;align-items:center"><span class="vhint">colheita realizada — maiores rendimentos</span><?= dash_scope('geral') ?></span></div>
    <?php if ($valvMode === 'chart'): ?>
      <div style="padding:12px 14px"><div data-vdash="vd-valv" style="height:<?= max(160, count($valvTop) * 40 + 30) ?>px"></div></div>
      <?= $jsonBlock('vd-valv', $cfgValv) ?>
    <?php else: ?>
      <div class="vkpi" style="margin:14px"><span class="vhint">Única válvula com colheita</span>
        <strong class="vnum" style="font-size:1.5rem"><?= numFmt((float)$valvTop[0]['kg_ha'], 0) ?> kg/ha</strong>
        <span class="vhint"><?= h((string)$valvTop[0]['valvula']) ?> · <?= h((string)$valvTop[0]['talhao']) ?></span></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- LINHA 5 — detalhe (drill) -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(360px,100%),1fr));gap:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Compradores — detalhe</strong>
        <span style="display:flex;gap:8px;align-items:center"><?= dash_scope('geral') ?><a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/comercial/vendas.php">Vendas</a></span></div>
      <?php if (!$porComprador): ?>
        <div class="vempty">Nenhuma venda registrada.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Comprador</th><th style="text-align:right">Vendas</th><th style="text-align:right">kg</th><th style="text-align:right">Faturamento (R$)</th></tr></thead>
        <tbody>
        <?php foreach ($porComprador as $pc): ?>
          <tr>
            <td><strong><?= h($pc['comprador']) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= (int)$pc['vendas'] ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$pc['kg'], 0) ?></td>
            <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$pc['valor'], 2) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <div class="vcard">
      <div class="vtoolbar"><strong>Produção por válvula</strong>
        <span style="display:flex;gap:8px;align-items:center"><?= dash_scope('geral') ?><a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/colheita/index.php">Colheita</a></span></div>
      <?php if (!$porValvula): ?>
        <div class="vempty">Nenhuma colheita realizada.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Válvula</th><th>Fazenda/Válvula</th><th style="text-align:right">kg/ha</th><th style="text-align:right">kg colhidos</th></tr></thead>
        <tbody>
        <?php foreach ($porValvula as $pv): ?>
          <tr>
            <td><strong class="vnum"><?= h($pv['valvula']) ?></strong></td>
            <td class="vhint"><?= h($pv['fazenda']) ?> — <?= h($pv['talhao']) ?></td>
            <td class="vnum" style="text-align:right"><?= $pv['kg_ha'] !== null ? numFmt((float)$pv['kg_ha'], 0) : '—' ?></td>
            <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$pv['kg'], 0) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="<?= $base ?>/assets/vendor/echarts/echarts.min.js"></script>
<script src="<?= $base ?>/assets/js/vero-dash.js"></script>
