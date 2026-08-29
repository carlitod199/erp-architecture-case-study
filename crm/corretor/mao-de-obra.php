<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Corretor / Eficiência da operação (protótipo demo)
   Rota: /crm/corretor/mao-de-obra · dados: crm/_mock.php
   Dashboard de produção: ritmo do packing (caixas/hora), produção
   por turma e tabela analítica das equipes.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

$totalCaixas = array_sum(array_column($M['turmas'], 'cx'));

crm_shell_start([
    'modulo' => 'corretor',
    'micro'  => 'mao_obra',
    'titulo' => 'Eficiência da operação',
    'sub'    => 'Mão de obra terceirizada · packing house & colheita · 12/08',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Produtividade', '1,84 t/trab.', '', 'teal') ?>
  <?= crm_kpi('Custo', 'R$ 92,40/t', '', 'blue') ?>
  <?= crm_kpi('Caixas no dia', crm_num((float)$totalCaixas), count($M['turmas']) . ' turmas · 56 pessoas', 'green') ?>
  <?= crm_kpi('Eficiência', '94%', crm_trend(2, 'pp') . ' vs. semana anterior', 'amber') ?>
</div>

<div class="crm-g2">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Ritmo de produção · caixas por hora · 12/08</span>
      <span class="crm-sub">pico 9h · pausa de almoço 12h</span>
    </div>
    <div id="crm-mo-ritmo" style="height:240px"></div>
  </div>
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Produção por turma · caixas</span>
      <span class="crm-sub"><?= crm_num((float)$totalCaixas) ?> caixas no dia</span>
    </div>
    <div id="crm-mo-turmas" style="height:240px"></div>
  </div>
</div>

<div class="crm-card" style="margin-top:14px">
  <div class="crm-card__head">
    <span class="crm-card__title">Turmas do dia · 12/08</span>
    <?= crm_pill(count($M['turmas']) . ' equipes', 'teal') ?>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Equipe</th><th class="num">Pessoas</th><th class="num">Caixas</th>
          <th class="num">Prod.</th><th>Aproveitamento</th><th class="num">Custo/kg</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($M['turmas'] as $t): ?>
        <tr>
          <td><strong><?= h($t['t']) ?></strong></td>
          <td class="num"><?= crm_num((float)$t['pes']) ?></td>
          <td class="num"><?= crm_num((float)$t['cx']) ?></td>
          <td class="num"><?= crm_num((float)$t['prod']) ?> cx/pessoa</td>
          <td>
            <?php if ((int)$t['ap'] > 72): ?>
              <?= crm_pill($t['ap'] . '%', 'green') ?>
            <?php elseif ((int)$t['ap'] > 0): ?>
              <?= crm_pill($t['ap'] . '%', 'amber') ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td class="num">R$ <?= crm_num((float)$t['custo'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script defer src="<?= BIOS_BASE ?>/assets/vendor/echarts/echarts.min.js"></script>
<script>
/* Padrão de gráfico dos dashboards VERO: ECharts local, degradação
   silenciosa. Série única em teal; rótulos diretos só nos destaques. */
document.addEventListener('DOMContentLoaded', function () {
  if (typeof echarts === 'undefined') return;
  var TEAL = '#005059';
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

  /* ── Ritmo do dia (área) ── */
  var elRitmo = document.getElementById('crm-mo-ritmo');
  if (elRitmo) {
    var ritmo = <?= jsvar($M['producao_horas']) ?>;
    var pico = ritmo.reduce(function (a, b) { return b[1] > a[1] ? b : a; });
    var ch1 = echarts.init(elRitmo, null, { renderer: 'canvas' });
    ch1.setOption(Object.assign({}, base, {
      grid: { left: 44, right: 14, top: 24, bottom: 24 },
      tooltip: Object.assign({}, base.tooltip, { trigger: 'axis',
        formatter: function (p) {
          return p[0].name + ' · <strong>' + p[0].value + ' caixas</strong>';
        } }),
      xAxis: { type: 'category', data: ritmo.map(function (d) { return d[0]; }),
               boundaryGap: false,
               axisLine: { lineStyle: { color: '#DDD2BF' } }, axisTick: { show: false },
               axisLabel: eixoMono },
      yAxis: { type: 'value', splitNumber: 3, axisLabel: eixoMono, splitLine: grade },
      series: [{
        type: 'line', smooth: true, symbol: 'circle', symbolSize: 5,
        data: ritmo.map(function (d) {
          var ehPico = d[0] === pico[0];
          return {
            value: d[1],
            symbolSize: ehPico ? 8 : 5,
            label: ehPico
              ? { show: true, position: 'top', formatter: '{c} cx',
                  fontFamily: 'IBM Plex Mono', fontSize: 10.5, fontWeight: 700, color: '#241B14' }
              : { show: false },
          };
        }),
        lineStyle: { width: 2.5, color: TEAL }, itemStyle: { color: TEAL },
        areaStyle: { color: {
          type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
          colorStops: [{ offset: 0, color: 'rgba(0,80,89,.22)' }, { offset: 1, color: 'rgba(0,80,89,.02)' }],
        } },
      }],
    }));
    charts.push(ch1);
  }

  /* ── Produção por turma (barras horizontais) ── */
  var elTurmas = document.getElementById('crm-mo-turmas');
  if (elTurmas) {
    var turmas = <?= jsvar(array_map(
        static fn($t) => ['t' => $t['t'], 'cx' => $t['cx'], 'prod' => $t['prod']],
        $M['turmas']
    )) ?>;
    turmas.sort(function (a, b) { return a.cx - b.cx; });   /* maior no topo */
    var ch2 = echarts.init(elTurmas, null, { renderer: 'canvas' });
    ch2.setOption(Object.assign({}, base, {
      grid: { left: 130, right: 66, top: 12, bottom: 8 },
      tooltip: Object.assign({}, base.tooltip, { trigger: 'axis',
        formatter: function (ps) {
          var t = turmas[ps[0].dataIndex];
          return t.t + '<br><strong>' + t.cx.toLocaleString('pt-BR') + ' caixas</strong> · '
               + t.prod + ' cx/pessoa';
        } }),
      xAxis: { type: 'value', axisLabel: eixoMono, splitLine: grade },
      yAxis: { type: 'category', data: turmas.map(function (t) { return t.t; }),
               axisLine: { lineStyle: { color: '#DDD2BF' } }, axisTick: { show: false },
               axisLabel: { fontSize: 12, color: '#241B14' } },
      series: [{
        type: 'bar', barWidth: 22,
        data: turmas.map(function (t) { return t.cx; }),
        itemStyle: { color: TEAL, borderRadius: [0, 5, 5, 0] },
        label: { show: true, position: 'right', fontFamily: 'IBM Plex Mono',
                 fontSize: 11, fontWeight: 700, color: '#241B14',
                 formatter: function (p) { return p.value.toLocaleString('pt-BR') + ' cx'; } },
        emphasis: { itemStyle: { color: '#2A767C' } },
      }],
    }));
    charts.push(ch2);
  }

  window.addEventListener('resize', function () {
    charts.forEach(function (c) { c.resize(); });
  });
});
</script>

<?php crm_shell_end();
