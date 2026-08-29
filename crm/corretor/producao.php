<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Corretor / Produção & Estoque (protótipo demo)
   Rota: /crm/corretor/producao · dados: crm/_mock.php
   Contagem do packing: produzido × expedido por variedade,
   estoque em câmara fria e tabela variedade × classificação.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

$pe = $M['producao_estoque'];

/* Totais do dia */
$totProd = array_sum(array_column($pe, 'prod'));
$totExp  = array_sum(array_column($pe, 'exp'));
$totEst  = array_sum(array_column($pe, 'est'));
$pctExp  = $totProd > 0 ? (int)round($totExp / $totProd * 100) : 0;
$pesoT   = $totEst * 4.3 / 1000;                     /* 4,3 kg por caixa */

/* Agregado por variedade (gráfico produzido × expedido) */
$porVar = [];
foreach ($pe as $l) {
    $porVar[$l['v']]['prod'] = ($porVar[$l['v']]['prod'] ?? 0) + $l['prod'];
    $porVar[$l['v']]['exp']  = ($porVar[$l['v']]['exp'] ?? 0) + $l['exp'];
}
$grafico = [];
foreach ($porVar as $v => $d) {
    $grafico[] = ['v' => $v, 'prod' => $d['prod'], 'exp' => $d['exp']];
}

/* Estoque por variedade × classificação, do maior para o menor */
$estoque = $pe;
usort($estoque, static fn(array $a, array $b) => $b['est'] <=> $a['est']);
$estMax  = max(array_column($estoque, 'est'));
$clCurta = ['Primeira' => '1ª', 'Segunda' => '2ª', 'Exportação' => 'Exp.', 'Extra' => 'Extra'];

/* Opções do modal de contagem */
$variedades     = array_values(array_unique(array_column($pe, 'v')));
$classificacoes = array_values(array_unique(array_column($pe, 'cl')));

crm_shell_start([
    'modulo' => 'corretor',
    'micro'  => 'producao',
    'titulo' => 'Produção & Estoque',
    'sub'    => 'Contagem do packing · 12/08',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-contagem\')">Registrar contagem</button>',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Produzido hoje', crm_num((float)$totProd) . ' caixas', '', 'teal') ?>
  <?= crm_kpi('Expedido', crm_num((float)$totExp), $pctExp . '% do produzido', 'green') ?>
  <?= crm_kpi('Em estoque', crm_num((float)$totEst), 'câmara fria', 'blue') ?>
  <?= crm_kpi('Peso em estoque', crm_num($pesoT, 1) . ' t', '4,3 kg por caixa', 'amber') ?>
</div>

<div class="crm-g2">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Produção × expedição por variedade</span>
      <span class="crm-sub">caixas · 12/08</span>
    </div>
    <div id="crm-pe-var" style="height:260px"></div>
  </div>
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Estoque em câmara fria</span>
      <span class="crm-sub"><?= crm_num((float)$totEst) ?> caixas</span>
    </div>
    <div class="crm-hbars">
      <?php foreach ($estoque as $e): ?>
      <div class="crm-hbar">
        <span><?= h($e['v'] . ' ' . ($clCurta[$e['cl']] ?? $e['cl'])) ?></span>
        <?= crm_bar($e['est'] / $estMax * 100) ?>
        <span class="num" style="font-family:'IBM Plex Mono',monospace"><?= crm_num((float)$e['est']) ?> cx</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="crm-card" style="margin-top:14px">
  <div class="crm-card__head">
    <span class="crm-card__title">Contagem por variedade × classificação</span>
    <?= crm_pill(count($pe) . ' lotes', 'teal') ?>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Variedade</th><th>Classificação</th>
          <th class="num">Produzido</th><th class="num">Expedido</th>
          <th class="num">Em estoque</th><th>Giro</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pe as $l):
            $premium = in_array($l['cl'], ['Extra', 'Exportação'], true);
            $giro    = $l['prod'] > 0 ? (int)round($l['exp'] / $l['prod'] * 100) : 0;
            $gCor    = $giro >= 70 ? 'green' : ($giro >= 40 ? 'amber' : 'red');
        ?>
        <tr>
          <td><strong><?= h($l['v']) ?></strong></td>
          <td><?= crm_pill($l['cl'], $premium ? 'teal' : 'grey') ?></td>
          <td class="num"><?= crm_num((float)$l['prod']) ?></td>
          <td class="num"><?= crm_num((float)$l['exp']) ?></td>
          <td class="num"><strong><?= crm_num((float)$l['est']) ?></strong></td>
          <td><?= crm_pill($giro . '%', $gCor) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: registrar contagem (demo · sem persistência) -->
<div class="vmodal" id="vm-contagem">
  <div class="vbox">
    <header>
      <h2>Registrar contagem</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-contagem')">×</button>
    </header>
    <div class="vform">
      <div class="vfield">
        <label>Variedade</label>
        <select>
          <?php foreach ($variedades as $v): ?>
            <option><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vfield">
        <label>Classificação</label>
        <select>
          <?php foreach ($classificacoes as $cl): ?>
            <option><?= h($cl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vfield">
        <label>Caixas contadas</label>
        <input type="number" min="0" step="1" placeholder="120">
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-contagem')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Contagem registrada">Salvar</button>
      </div>
    </div>
  </div>
</div>

<script defer src="<?= BIOS_BASE ?>/assets/vendor/echarts/echarts.min.js"></script>
<script>
/* Padrão de gráfico dos dashboards VERO: ECharts local, degradação
   silenciosa. Duas séries na mesma matiz teal (produzido × expedido). */
document.addEventListener('DOMContentLoaded', function () {
  if (typeof echarts === 'undefined') return;
  var TEAL = '#005059', TEAL_CLARO = '#8FB5B9';
  var base = {
    textStyle: { fontFamily: 'IBM Plex Sans, sans-serif', color: '#6B7069' },
    tooltip: {
      backgroundColor: '#FFFFFF', borderColor: '#DDD2BF', borderWidth: 1,
      textStyle: { color: '#241B14', fontSize: 12 },
    },
  };
  var eixoMono = { fontFamily: 'IBM Plex Mono', fontSize: 10, color: '#8A7C68' };
  var grade = { lineStyle: { color: '#EEE6D6', type: [4, 4] } };
  var charts = [];

  /* ── Produção × expedição por variedade (barras horizontais agrupadas) ── */
  var elVar = document.getElementById('crm-pe-var');
  if (elVar) {
    var dados = <?= jsvar($grafico) ?>;
    dados.sort(function (a, b) { return a.prod - b.prod; });   /* maior no topo */
    var rotulo = {
      show: true, position: 'right', fontFamily: 'IBM Plex Mono',
      fontSize: 10.5, fontWeight: 700, color: '#241B14',
      formatter: function (p) { return p.value.toLocaleString('pt-BR'); },
    };
    var ch1 = echarts.init(elVar, null, { renderer: 'canvas' });
    ch1.setOption(Object.assign({}, base, {
      legend: {
        top: 0, right: 0, itemWidth: 10, itemHeight: 10,
        textStyle: { fontSize: 11, color: '#6B7069' },
      },
      grid: { left: 82, right: 56, top: 30, bottom: 8 },
      tooltip: Object.assign({}, base.tooltip, { trigger: 'axis',
        formatter: function (ps) {
          var d = dados[ps[0].dataIndex];
          var pct = d.prod > 0 ? Math.round(d.exp / d.prod * 100) : 0;
          return d.v + '<br>Produzido <strong>' + d.prod.toLocaleString('pt-BR') + ' cx</strong>'
               + ' · Expedido <strong>' + d.exp.toLocaleString('pt-BR') + ' cx</strong>'
               + ' (' + pct + '%)';
        } }),
      xAxis: { type: 'value', axisLabel: eixoMono, splitLine: grade },
      yAxis: { type: 'category', data: dados.map(function (d) { return d.v; }),
               axisLine: { lineStyle: { color: '#DDD2BF' } }, axisTick: { show: false },
               axisLabel: { fontSize: 12, color: '#241B14' } },
      series: [
        {
          name: 'Produzido', type: 'bar', barWidth: 12, barGap: '30%',
          data: dados.map(function (d) { return d.prod; }),
          itemStyle: { color: TEAL, borderRadius: [0, 5, 5, 0] },
          label: rotulo,
          emphasis: { itemStyle: { color: '#2A767C' } },
        },
        {
          name: 'Expedido', type: 'bar', barWidth: 12,
          data: dados.map(function (d) { return d.exp; }),
          itemStyle: { color: TEAL_CLARO, borderRadius: [0, 5, 5, 0] },
          label: Object.assign({}, rotulo, { color: '#6B7069', fontWeight: 400 }),
          emphasis: { itemStyle: { color: '#7AA6AB' } },
        },
      ],
    }));
    charts.push(ch1);
  }

  window.addEventListener('resize', function () {
    charts.forEach(function (c) { c.resize(); });
  });
});
</script>

<?php crm_shell_end();
