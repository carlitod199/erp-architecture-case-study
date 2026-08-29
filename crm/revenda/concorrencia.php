<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Inteligência competitiva (protótipo)
   Rota: /crm/revenda/concorrencia · coletas de preço da
   concorrência registradas pelo time. Dados: crm/_mock.php.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

/* Posição por produto: preço VERO × média das coletas da concorrência */
$porProduto = [];
foreach ($M['concorrencia'] as $c) {
    $nome = preg_replace('/ \(equiv\.\)$/', '', $c['prod']);
    $porProduto[$nome]['vero']   = (float)$c['vero'];
    $porProduto[$nome]['cps'][]  = (float)$c['cp'];
}
$posicao = [];
foreach ($porProduto as $nome => $d) {
    $posicao[] = ['prod' => $nome, 'vero' => $d['vero'],
                  'conc' => round(array_sum($d['cps']) / count($d['cps']))];
}

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'concorrencia',
    'titulo' => 'Inteligência competitiva',
    'sub'    => 'Coletas de preço da concorrência · ' . count($M['concorrencia']) . ' registros recentes',
    'papel'  => 'vendedor',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-nova-coleta\')">＋ Registrar coleta</button>',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Posição VERO · ProtectSC', 'Competitivo', 'R$ 145 · média dos concorrentes R$ 146', 'teal') ?>
  <?= crm_kpi('Menor preço concorrente', 'R$ 139', 'Concorrente B · Juazeiro', 'amber') ?>
  <?= crm_kpi('Maior preço concorrente', 'R$ 152', 'Concorrente A · Petrolina', 'green') ?>
  <?= crm_kpi('Coletas · 7 dias', (string)count($M['concorrencia']), '3 concorrentes · 4 regiões', 'blue') ?>
</div>

<div class="crm-g23">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Posição de preço por produto · VERO × concorrência</span>
      <?= crm_demo('coletas de campo') ?>
    </div>
    <div id="crm-conc-posicao" style="height:250px"></div>
  </div>
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Tendência · ProtectSC · 8 semanas</span>
    </div>
    <div id="crm-conc-tendencia" style="height:250px"></div>
  </div>
</div>

<script defer src="<?= BIOS_BASE ?>/assets/vendor/echarts/echarts.min.js"></script>
<script>
/* Padrão de gráfico dos dashboards VERO: ECharts local + degradação
   silenciosa. Identidade fixa: VERO = teal, concorrência = tan quente
   (rótulos diretos como codificação secundária). */
document.addEventListener('DOMContentLoaded', function () {
  if (typeof echarts === 'undefined') return;
  var TEAL = '#005059', TAN = '#B08968';
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

  /* ── Posição por produto (barras horizontais agrupadas) ── */
  var elPos = document.getElementById('crm-conc-posicao');
  if (elPos) {
    var pos = <?= jsvar($posicao) ?>;
    var ch1 = echarts.init(elPos, null, { renderer: 'canvas' });
    ch1.setOption(Object.assign({}, base, {
      legend: { top: 0, right: 0, itemWidth: 10, itemHeight: 10,
                textStyle: { fontSize: 11, color: '#6B7069' } },
      grid: { left: 78, right: 46, top: 30, bottom: 8 },
      tooltip: Object.assign({}, base.tooltip, { trigger: 'axis',
        formatter: function (ps) {
          return ps[0].name + '<br>' + ps.map(function (p) {
            return p.marker + ' ' + p.seriesName + ': <strong>R$ ' + p.value + '</strong>';
          }).join('<br>');
        } }),
      xAxis: { type: 'value', axisLabel: eixoMono, splitLine: grade },
      yAxis: { type: 'category', data: pos.map(function (p) { return p.prod; }),
               axisLine: { lineStyle: { color: '#DDD2BF' } }, axisTick: { show: false },
               axisLabel: { fontSize: 12, color: '#241B14' } },
      series: [
        { name: 'VERO', type: 'bar', data: pos.map(function (p) { return p.vero; }),
          barWidth: 12, itemStyle: { color: TEAL, borderRadius: [0, 4, 4, 0] },
          label: { show: true, position: 'right', fontFamily: 'IBM Plex Mono',
                   fontSize: 10.5, fontWeight: 700, color: '#241B14',
                   formatter: 'R$ {c}' } },
        { name: 'Concorrência (média)', type: 'bar',
          data: pos.map(function (p) { return p.conc; }),
          barWidth: 12, itemStyle: { color: TAN, borderRadius: [0, 4, 4, 0] },
          label: { show: true, position: 'right', fontFamily: 'IBM Plex Mono',
                   fontSize: 10.5, color: '#6B7069', formatter: 'R$ {c}' } },
      ],
    }));
    charts.push(ch1);
  }

  /* ── Tendência ProtectSC (linhas · 8 semanas) ── */
  var elTen = document.getElementById('crm-conc-tendencia');
  if (elTen) {
    var t = <?= jsvar($M['conc_tendencia']) ?>;
    var ch2 = echarts.init(elTen, null, { renderer: 'canvas' });
    ch2.setOption(Object.assign({}, base, {
      legend: { top: 0, right: 0, itemWidth: 10, itemHeight: 10,
                textStyle: { fontSize: 11, color: '#6B7069' } },
      grid: { left: 44, right: 14, top: 30, bottom: 24 },
      tooltip: Object.assign({}, base.tooltip, { trigger: 'axis' }),
      xAxis: { type: 'category', data: t.semanas, boundaryGap: false,
               axisLine: { lineStyle: { color: '#DDD2BF' } }, axisTick: { show: false },
               axisLabel: Object.assign({}, eixoMono, { interval: 1 }) },
      yAxis: { type: 'value', min: 135, max: 160, splitNumber: 3,
               axisLabel: eixoMono, splitLine: grade },
      series: [
        { name: 'VERO', type: 'line', data: t.vero, symbol: 'circle', symbolSize: 6,
          lineStyle: { width: 2, color: TEAL }, itemStyle: { color: TEAL } },
        { name: 'Concorrência (média)', type: 'line', data: t.conc,
          symbol: 'circle', symbolSize: 6,
          lineStyle: { width: 2, color: TAN, type: [6, 4] }, itemStyle: { color: TAN } },
      ],
    }));
    charts.push(ch2);
  }

  window.addEventListener('resize', function () {
    charts.forEach(function (c) { c.resize(); });
  });
});
</script>

<div class="crm-card" style="margin-top:14px">
  <div class="crm-card__head">
    <span class="crm-card__title">Coletas recentes</span>
    <?= crm_pill(count($M['concorrencia']) . ' registros', 'teal') ?>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Produto</th><th>Fabricante</th><th>Concorrente</th><th>Região</th>
          <th class="num">VERO</th><th class="num">Concorrente</th><th>Δ</th><th>Data</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($M['concorrencia'] as $c): $dif = $c['vero'] - $c['cp']; ?>
          <tr>
            <td><?= h($c['prod']) ?></td>
            <td><?= h($c['fab']) ?></td>
            <td><?= h($c['conc']) ?></td>
            <td><?= h($c['reg']) ?></td>
            <td class="num"><?= crm_brl((float)$c['vero']) ?></td>
            <td class="num"><?= crm_brl((float)$c['cp']) ?></td>
            <td>
              <?php if ($c['vero'] < $c['cp']): ?>
                <?= crm_pill('VERO -R$ ' . crm_num((float)abs($dif)), 'green') ?>
              <?php else: ?>
                <?= crm_pill('VERO +R$ ' . crm_num((float)$dif), 'red') ?>
              <?php endif; ?>
            </td>
            <td><?= h($c['dt']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: registrar coleta de preço (demo — sem persistência) -->
<div class="vmodal" id="vm-nova-coleta">
  <div class="vbox">
    <header>
      <h2>Registrar coleta de preço</h2>
      <button type="button" class="vclose" onclick="vModalClose('vm-nova-coleta')">×</button>
    </header>
    <div class="vform">
      <div class="vgrid">
        <div class="vfield"><label>Produto</label><input type="text" value="ProtectSC (equiv.)"></div>
        <div class="vfield"><label>Concorrente</label><input type="text" value="Concorrente A"></div>
        <div class="vfield"><label>Preço coletado</label><input type="text" value="R$ 152"></div>
        <div class="vfield"><label>Região</label><input type="text" value="Petrolina"></div>
      </div>
      <div class="vform-actions">
        <button type="button" class="vbtn vbtn-ghost" onclick="vModalClose('vm-nova-coleta')">Cancelar</button>
        <button type="button" class="vbtn vbtn-primary" data-toast="Coleta registrada">Salvar</button>
      </div>
    </div>
  </div>
</div>

<?php crm_shell_end();
