<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Dashboard do Vendedor (protótipo demo)
   Rota: /crm/revenda/dashboard · dados: crm/_mock.php
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M   = crm_mock();
$cli = $M['clientes'];

$risco = array_filter($cli, fn($c) => in_array($c['risco'], ['Alto', 'Médio'], true));
$pipeAberto = 0; $nAbertas = 0;
$ultimaEtapa = count($M['etapas']) - 1;
foreach ($M['opps'] as $o) {
    if ($o['etapa'] < $ultimaEtapa) { $pipeAberto += $o['valor']; $nAbertas++; }
}

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'dashboard',
    'titulo' => 'Dashboard',
    'sub'    => 'Agrovale Insumos · Vale do São Francisco · quinta, 13 de agosto',
    'papel'  => 'vendedor',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Vendas no mês', crm_brl(342000), crm_trend(12) . ' vs. mês anterior', 'green') ?>
  <?= crm_kpi('Meta do mês', '68%', crm_brl(342000) . ' de ' . crm_brl(500000), 'teal') ?>
  <?= crm_kpi('Pipeline aberto', crm_brl($pipeAberto), $nAbertas . ' oportunidades', 'blue') ?>
  <?= crm_kpi('Taxa de conversão', '34%', crm_trend(3, 'pp') . ' no trimestre', 'amber') ?>
</div>

<div class="crm-g23">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Agenda de hoje</span>
      <?= crm_pill(count($M['agenda']) . ' compromissos', 'teal') ?>
    </div>
    <?php foreach ($M['agenda'] as $a): ?>
      <div class="crm-ag" data-href="<?= crm_url('revenda', 'cliente') ?>?id=<?= h($a['cliente']) ?>" style="cursor:pointer">
        <span class="crm-ag__h"><?= h($a['h']) ?></span>
        <span class="crm-ag__bar b-<?= h($a['cor']) ?>"></span>
        <span class="crm-ag__body">
          <div class="crm-ag__t"><?= h($a['t']) ?></div>
          <div class="crm-ag__sub"><?= h($a['sub']) ?></div>
        </span>
        <span class="crm-pill p-grey">›</span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Clientes em risco</span>
      <?= crm_pill((string)count($risco), 'red') ?>
    </div>
    <?php foreach ($risco as $c): ?>
      <div class="crm-ag" data-href="<?= crm_url('revenda', 'cliente') ?>?id=<?= h($c['id']) ?>" style="cursor:pointer">
        <?= crm_avatar($c['nome'], $c['cor']) ?>
        <span class="crm-ag__body">
          <div class="crm-ag__t"><?= h($c['nome']) ?></div>
          <div class="crm-ag__sub">Sem visita há <?= (int)$c['ult_visita'] ?>d · consumo <?= crm_trend((float)$c['var_consumo']) ?></div>
        </span>
        <?= crm_risco_pill($c['risco']) ?>
      </div>
    <?php endforeach; ?>
    <?= crm_callout('<strong>2 propostas</strong> sem retorno há mais de 7 dias.', 'amber') ?>
  </div>
</div>

<?php
/* Funil de vendas: oportunidades por etapa (contagem + valor total) */
$funil = [];
foreach ($M['etapas'] as $i => $nome) $funil[$i] = ['nome' => $nome, 'n' => 0, 'valor' => 0];
foreach ($M['opps'] as $o) { $funil[$o['etapa']]['n']++; $funil[$o['etapa']]['valor'] += $o['valor']; }
?>
<div class="crm-g2">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Evolução de vendas · últimos 8 meses</span>
      <span class="crm-sub">Ticket médio R$ 28.500 · 12 pedidos/mês</span>
    </div>
    <div id="crm-vendas-chart" style="height:250px"></div>
  </div>
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Funil de vendas · <?= count($M['opps']) ?> oportunidades</span>
      <span class="crm-sub">R$ <?= crm_num(array_sum(array_column($funil, 'valor')) / 1000) ?> mil no funil</span>
    </div>
    <?php
    /* Funil trapezoidal clássico: larguras em taper fixo (a forma comunica a
       ORDEM das etapas; os números reais vão escritos dentro dos segmentos).
       Cada segmento afunila da própria largura para a largura do próximo:
       silhueta contínua. Matiz teal clara para escura; texto escuro nos tons
       claros, branco nos escuros. */
    $tons   = ['#B9D2D5', '#7FADB2', '#2A767C', '#005059'];
    $texto  = ['#153A3E', '#153A3E', '#FFFFFF', '#FFFFFF'];
    $largs  = [100, 74, 50, 30, 22];    /* % (o 5º valor é a base do último) */
    ?>
    <div class="crm-funil" style="margin-top:14px">
      <?php foreach (array_values($funil) as $i => $f):
          $wTopo = $largs[$i];
          $wBase = $largs[$i + 1];
          /* inset lateral da base, em % da LARGURA DO SEGMENTO */
          $inset = round(($wTopo - $wBase) / 2 / $wTopo * 100, 2); ?>
        <div class="crm-funil__seg"
             style="width:<?= $wTopo ?>%;background:linear-gradient(180deg,<?= $tons[$i] ?>,<?= $tons[min(3, $i + 1)] ?>);color:<?= $texto[$i] ?>;
                    clip-path:polygon(0 0, 100% 0, <?= 100 - $inset ?>% 100%, <?= $inset ?>% 100%)"
             title="<?= h($f['nome']) ?> · <?= (int)$f['n'] ?> oportunidade<?= $f['n'] === 1 ? '' : 's' ?> · <?= crm_brl((float)$f['valor']) ?>">
          <span class="crm-funil__seg-nome"><?= h($f['nome']) ?></span>
          <span class="crm-funil__seg-val">R$ <?= crm_num($f['valor'] / 1000) ?> mil · <?= (int)$f['n'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script defer src="<?= BIOS_BASE ?>/assets/vendor/echarts/echarts.min.js"></script>
<script>
/* Padrão de gráfico dos dashboards VERO (_executivo_piloto): ECharts local,
   degradação silenciosa sem rede. Série única usa a mesma matiz (teal), mês
   corrente em cheio; rótulo direto SÓ no destaque, resto via tooltip. */
document.addEventListener('DOMContentLoaded', function () {
  if (typeof echarts === 'undefined') return;
  var el = document.getElementById('crm-vendas-chart');
  if (!el) return;

  var dados = <?= jsvar($M['vendas_meses']) ?>;          /* [['jan',180],…] R$ mil */
  var meses = dados.map(function (d) { return d[0].toUpperCase(); });
  var ultimo = dados.length - 1;
  var serie = dados.map(function (d, i) {
    return {
      value: d[1],
      itemStyle: { color: i === ultimo ? '#005059' : '#8FB5B9' },
      label: i === ultimo
        ? { show: true, position: 'top', formatter: 'R$ ' + d[1] + ' mil',
            fontFamily: 'IBM Plex Mono', fontSize: 11, fontWeight: 700, color: '#241B14' }
        : { show: false },
    };
  });

  var chart = echarts.init(el, null, { renderer: 'canvas' });
  chart.setOption({
    textStyle: { fontFamily: 'IBM Plex Sans, sans-serif', color: '#6B7069' },
    grid: { left: 44, right: 10, top: 28, bottom: 26 },
    tooltip: {
      trigger: 'axis', axisPointer: { type: 'none' },
      backgroundColor: '#FFFFFF', borderColor: '#DDD2BF', borderWidth: 1,
      textStyle: { color: '#241B14', fontSize: 12 },
      formatter: function (p) {
        return p[0].name + ' · <strong>R$ ' + p[0].value + ' mil</strong>';
      },
    },
    xAxis: {
      type: 'category', data: meses,
      axisLine: { lineStyle: { color: '#DDD2BF' } }, axisTick: { show: false },
      axisLabel: { fontFamily: 'IBM Plex Mono', fontSize: 10, color: '#8A7C68', margin: 10 },
    },
    yAxis: {
      type: 'value', splitNumber: 3,
      axisLabel: { fontFamily: 'IBM Plex Mono', fontSize: 10, color: '#8A7C68', formatter: '{value}' },
      splitLine: { lineStyle: { color: '#EEE6D6', type: [4, 4] } },
    },
    series: [{
      type: 'bar', data: serie, barWidth: '46%',
      itemStyle: { borderRadius: [4, 4, 0, 0] },
      emphasis: { itemStyle: { color: '#2A767C' } },
      name: 'Vendas (R$ mil)',
    }],
  });
  window.addEventListener('resize', function () { chart.resize(); });
});
</script>

<?php crm_shell_end();
