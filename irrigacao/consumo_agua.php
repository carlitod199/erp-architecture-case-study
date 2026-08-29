<?php
/* ============================================================
   VERO — Irrigação / Consumo de Água — DASHBOARD (A4-CONSUMO)
   Reformulação do design docs/consumo_agua_dashboard.html ligada a
   DADOS REAIS do tenant. Guard/rota/permissão preservados
   (irrigacao.consumo_agua). Toggle de métrica (Água/Energia/Custo),
   filtros (válvula + período), KPIs c/ variação vs mês anterior,
   tendência mensal, tabela de eficiência por válvula e composição do
   custo (donut). Gráficos em ECharts LOCAL (sem CDN),
   padrão OBS-B (valor final renderizado; animação = enhancement com
   guard navigator.webdriver + prefers-reduced-motion).

   Fontes reais: irrigacao_consumos × irrigacao_apontamentos (água m³,
   energia kWh, custo), área da válvula (agro_talhoes), horas de bomba
   (irrigacao_apontamentos.horas), tarifas vigentes de
   tenant_parametros chave 'irrigacao.tarifas' (C-21). Nada inventado:
   quando o número não existe no schema, o card é omitido.

   A base compartilhada _consumo_base.php (Água/Energia comuns) NÃO é
   mais incluída por esta tela — consumo_energia.php segue usando-a.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_consumos_abas.php';

$t = vero_tenant();

$mesAbrev = [1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun',
             7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'];

/* ── Tarifas vigentes (C-21) — só para transparência no rodapé; o custo
   exibido é sempre o REAL gravado por consumo, não o derivado da tarifa. */
$tarifasIrr = json_decode((string)(vero_val(
    "SELECT valor FROM tenant_parametros WHERE tenant_id = :t AND chave = 'irrigacao.tarifas'",
    [':t' => $t]) ?: ''), true) ?: [];
$tarAgua = isset($tarifasIrr['agua_m3'])     ? (float)$tarifasIrr['agua_m3']     : null;
$tarEner = isset($tarifasIrr['energia_kwh']) ? (float)$tarifasIrr['energia_kwh'] : null;

/* ── Totais por válvula (água + energia), com área da válvula ──────── */
$rowsValv = vero_rows(
    "SELECT a.talhao_id, c.tipo,
            SUM(c.quantidade)            AS qtd,
            SUM(COALESCE(c.custo, 0))    AS custo,
            tl.codigo AS talhao, fz.nome AS fazenda, tl.area_ha
       FROM irrigacao_consumos c
       JOIN irrigacao_apontamentos a ON a.id = c.apontamento_id
       LEFT JOIN agro_talhoes  tl ON tl.id = a.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
      WHERE c.tenant_id = :t AND c.tipo IN ('agua', 'energia')
      GROUP BY a.talhao_id, c.tipo, tl.codigo, fz.nome, tl.area_ha", [':t' => $t]);

/* horas de bomba: agregadas no NÍVEL DO APONTAMENTO (um evento pode ter
   consumo de água E de energia — somar pelos consumos duplicaria as horas). */
$rowsHoras = vero_rows(
    "SELECT talhao_id, SUM(COALESCE(horas, 0)) AS horas
       FROM irrigacao_apontamentos
      WHERE tenant_id = :t
      GROUP BY talhao_id", [':t' => $t]);
$horasPorTalhao = [];
foreach ($rowsHoras as $r) $horasPorTalhao[(int)$r['talhao_id']] = (float)$r['horas'];

$valves = []; /* key = talhao_id */
foreach ($rowsValv as $r) {
    $k = (int)$r['talhao_id'];
    if (!isset($valves[$k])) {
        $rotulo = trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? 'Sem válvula'), ' —');
        $valves[$k] = [
            'key'   => (string)$k,
            'name'  => $rotulo !== '' ? $rotulo : 'Sem válvula',
            'area'  => $r['area_ha'] !== null ? (float)$r['area_ha'] : null,
            'agua'  => 0.0, 'energia' => 0.0, 'custoAgua' => 0.0, 'custoEnergia' => 0.0,
            'horas' => $horasPorTalhao[$k] ?? 0.0,
        ];
    }
    if ($r['tipo'] === 'agua')    { $valves[$k]['agua']    = (float)$r['qtd']; $valves[$k]['custoAgua']    = (float)$r['custo']; }
    if ($r['tipo'] === 'energia') { $valves[$k]['energia'] = (float)$r['qtd']; $valves[$k]['custoEnergia'] = (float)$r['custo']; }
}
/* ordena por consumo de água desc (mesma lógica da tabela do mockup) */
uasort($valves, static fn($a, $b) => $b['agua'] <=> $a['agua']);
$valves = array_values($valves);

/* ── Séries mensais (por válvula e tipo) ───────────────────────────── */
$rowsMes = vero_rows(
    "SELECT a.talhao_id, c.tipo, DATE_FORMAT(a.data_apontamento, '%Y-%m') AS ym,
            SUM(c.quantidade)         AS qtd,
            SUM(COALESCE(c.custo, 0)) AS custo
       FROM irrigacao_consumos c
       JOIN irrigacao_apontamentos a ON a.id = c.apontamento_id
      WHERE c.tenant_id = :t AND c.tipo IN ('agua', 'energia')
      GROUP BY a.talhao_id, c.tipo, ym", [':t' => $t]);

/* eixo de meses: ano mais recente com dado, Jan..(mês máx com dado ou mês atual) */
$anosData = $mesesMaxPorAno = [];
foreach ($rowsMes as $r) {
    [$yy, $mm] = array_map('intval', explode('-', (string)$r['ym']));
    $anosData[$yy] = true;
    $mesesMaxPorAno[$yy] = max($mesesMaxPorAno[$yy] ?? 0, $mm);
}
$anoBase  = $anosData ? max(array_keys($anosData)) : (int)date('Y');
$mesFim   = ($anoBase === (int)date('Y')) ? max((int)date('n'), $mesesMaxPorAno[$anoBase] ?? 1)
                                          : ($mesesMaxPorAno[$anoBase] ?? 12);
$monthsKeys = $monthsLbl = [];
for ($m = 1; $m <= $mesFim; $m++) {
    $monthsKeys[] = sprintf('%04d-%02d', $anoBase, $m);
    $monthsLbl[]  = $mesAbrev[$m];
}
$nMes = count($monthsKeys);
$idxMes = array_flip($monthsKeys);

/* zera a matriz mensal por válvula + agregado 'todas' */
$blank = static fn() => ['agua' => array_fill(0, $nMes, 0.0),
                         'energia' => array_fill(0, $nMes, 0.0),
                         'custo' => array_fill(0, $nMes, 0.0)];
$monthly = ['todas' => $blank()];
foreach ($valves as $v) $monthly[$v['key']] = $blank();

foreach ($rowsMes as $r) {
    $ym = (string)$r['ym'];
    if (!isset($idxMes[$ym])) continue;                 /* fora do eixo (outro ano) */
    $i = $idxMes[$ym];
    $k = (string)(int)$r['talhao_id'];
    if (!isset($monthly[$k])) continue;
    $q = (float)$r['qtd']; $cu = (float)$r['custo'];
    $metrica = $r['tipo'] === 'agua' ? 'agua' : 'energia';
    $monthly[$k][$metrica][$i]      += $q;
    $monthly[$k]['custo'][$i]       += $cu;
    $monthly['todas'][$metrica][$i] += $q;
    $monthly['todas']['custo'][$i]  += $cu;
}

/* ── Agregado 'todas' (para KPIs / donut / summary renderizados no HTML) ── */
$agg = ['agua' => 0.0, 'energia' => 0.0, 'custoAgua' => 0.0, 'custoEnergia' => 0.0, 'horas' => 0.0, 'area' => 0.0];
foreach ($valves as $v) {
    $agg['agua']         += $v['agua'];
    $agg['energia']      += $v['energia'];
    $agg['custoAgua']    += $v['custoAgua'];
    $agg['custoEnergia'] += $v['custoEnergia'];
    $agg['horas']        += $v['horas'];
    if ($v['area'] !== null) $agg['area'] += $v['area'];
}
$agg['custo'] = $agg['custoAgua'] + $agg['custoEnergia'];

$temEnergia = $agg['energia'] > 0 || $agg['custoEnergia'] > 0;   /* omite cards de energia se o schema não tiver */
$temHoras   = $agg['horas']   > 0;
$temArea    = $agg['area']    > 0;

/* Δ vs mês anterior (só quando o mês anterior tem base > 0 — senão OMITE
   honestamente, nada de % contra zero). Comparação = último mês do eixo. */
$deltaPct = static function (array $serie): ?float {
    $n = count($serie);
    if ($n < 2) return null;
    $cur = (float)$serie[$n - 1]; $prev = (float)$serie[$n - 2];
    if ($prev <= 0) return null;
    return ($cur - $prev) / $prev;
};
$dAgua  = $deltaPct($monthly['todas']['agua']);
$dEner  = $deltaPct($monthly['todas']['energia']);
$dCusto = $deltaPct($monthly['todas']['custo']);

/* cores da paleta VERO */
$C = ['accent' => '#005059', 'accent3' => '#2A767C', 'olive' => '#4E9CA1',
      'amber' => '#B57C1A', 'pos' => '#0E7E72', 'amberD' => '#7A5410',
      'muted' => '#8A7C68', 'ink' => '#241B14', 'track' => '#EEE6D6', 'border' => '#E3D9C8'];

$DATA = [
    'months'  => $monthsLbl,
    'anoBase' => $anoBase,
    'valves'  => $valves,
    'monthly' => $monthly,
    'agg'     => $agg,
    'C'       => $C,
];

/* ── helpers de render server-side (OBS-B: valor final já no HTML) ── */
$kpiCardHtml = static function (string $lbl, string $valHtml, ?float $dlt, string $capBase): string {
    $delta = '';
    if ($dlt !== null) {
        $up = $dlt >= 0;
        $delta = '<div class="dlt ' . ($up ? 'up' : 'down') . '"><span class="arw">' . ($up ? '&#9650;' : '&#9660;') . '</span>'
               . numFmt(abs($dlt) * 100, 1) . '%<span class="cap">vs mês anterior</span></div>';
    } else {
        $delta = '<div class="dlt none"><span class="cap">sem mês anterior p/ comparar</span></div>';
    }
    return '<div class="vcard dsh-kpi"><div class="lbl">' . h($lbl) . '</div>'
         . '<div class="val">' . $valHtml . '</div>' . $delta . '</div>';
};
$mMes = $nMes ? $monthsLbl[$nMes - 1] : '';

$GUARD      = ['macro' => 'irrigacao', 'micro' => 'consumo_agua'];
$PAGE_VIEW  = 'irrigacao_consumo_agua';
$PAGE_TITLE = 'Consumo de Água';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<style>
/* ===== Consumo de Água — dashboard (escopo .iwd; sem depender de vars globais) ===== */
.iwd{
  --accent:#005059; --accent-3:#2A767C; --accent-deep:#00363D;
  --amber:#B57C1A; --amber-bg:#F3E7C8; --amber-d:#7A5410;
  --border:#E3D9C8; --border2:#DDD2BF; --danger:#B23A2E;
  --faint:#C3B49E; --ink:#241B14; --ink2:#2B2018;
  --muted:#8A7C68; --muted2:#9A8C78;
  --olive:#4E9CA1; --page:#EDEAE0; --pos:#0E7E72; --pos-bg:#DDEDEB;
  --r:9px; --r-card:13px; --r-sm:8px;
  --surface:#fff; --track:#EEE6D6; --warm:#FBF8F2;
  --mono:'IBM Plex Mono',ui-monospace,SFMono-Regular,Menlo,monospace;
  color:var(--ink2); font-size:13px;
}
.iwd .num,.iwd .vnum{font-family:var(--mono);font-variant-numeric:tabular-nums}
.iwd .controls{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:12px 14px;margin-bottom:16px}
.iwd .pills{display:inline-flex;gap:4px;background:var(--warm);border:1px solid var(--border);padding:3px;border-radius:10px}
.iwd .dsh-pill{appearance:none;border:0;background:transparent;color:var(--muted);font-family:inherit;
  font-weight:600;font-size:12px;padding:6px 15px;border-radius:7px;cursor:pointer;transition:.12s}
.iwd .dsh-pill:hover{color:var(--ink)}
.iwd .dsh-pill.active{background:var(--accent);color:#fff;box-shadow:0 1px 2px rgba(0,54,61,.25)}
.iwd .filters{display:inline-flex;gap:8px}
.iwd .dsh-select{appearance:none;font-family:inherit;font-size:12px;color:var(--ink2);background:#fff
  url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'><path d='M1 3l4 4 4-4' fill='none' stroke='%238A7C68' stroke-width='1.4'/></svg>") no-repeat right 10px center;
  border:1px solid var(--border2);border-radius:var(--r-sm);padding:7px 28px 7px 11px;cursor:pointer;font-weight:500;min-width:0}
.iwd .dsh-select:focus{outline:2px solid var(--pos-bg);border-color:var(--accent-3)}
.iwd .summary{margin-left:auto;font-size:13px;color:var(--muted)}
.iwd .summary b{color:var(--ink);font-family:var(--mono);font-weight:600}

.iwd .dsh-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:16px}
.iwd .dsh-kpi{padding:14px 16px}
.iwd .dsh-kpi .lbl{font-size:10.5px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted)}
.iwd .dsh-kpi .val{font-family:var(--mono);font-size:25px;font-weight:600;color:var(--ink);margin-top:9px;line-height:1;white-space:nowrap}
.iwd .dsh-kpi .val u{font-size:12px;font-weight:500;color:var(--muted2);margin-left:5px;text-decoration:none}
.iwd .dsh-kpi .dlt{margin-top:9px;font-size:11.5px;font-weight:600;display:flex;align-items:center;gap:5px;min-height:15px}
.iwd .dsh-kpi .dlt .cap{color:var(--muted2);font-weight:500}
.iwd .dlt.up{color:var(--amber-d)} .iwd .dlt.down{color:var(--pos)} .iwd .dlt.none{color:var(--muted2);font-weight:500}
.iwd .dlt .arw{font-size:9px}

.iwd .cardhead{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border)}
.iwd .cardhead h3{margin:0;font-size:13.5px;font-weight:600;color:var(--ink)}
.iwd .cardnote{font-size:11.5px;color:var(--muted2)}
.iwd .chartcard{margin-bottom:16px}
.iwd .dsh-chart{position:relative;height:290px;padding:14px 12px 8px}
.iwd .dsh-chart>div{width:100%;height:100%}

.iwd .dsh-grid2{display:grid;grid-template-columns:1.85fr 1fr;gap:16px;margin-bottom:16px}
.iwd .dsh-table{width:100%;border-collapse:collapse}
.iwd .dsh-table th{font-size:10.5px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);
  text-align:right;padding:11px 16px 10px;border-bottom:1px solid var(--border)}
.iwd .dsh-table th:first-child{text-align:left}
.iwd .dsh-table td{padding:12px 16px;border-bottom:1px solid var(--border);color:var(--ink2);text-align:right}
.iwd .dsh-table td:first-child{text-align:left}
.iwd .dsh-table tbody tr:last-child td{border-bottom:0}
.iwd .dsh-table .vn{font-weight:600;color:var(--ink)}
.iwd .dsh-table .vn .area{font-weight:400;color:var(--muted2);font-size:11.5px;margin-left:7px}
.iwd .dsh-table .cell-num{font-family:var(--mono);font-variant-numeric:tabular-nums}
.iwd .dsh-bar{width:100%;min-width:90px;height:8px;background:var(--track);border-radius:5px;overflow:hidden}
.iwd .dsh-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--accent-3),var(--accent));border-radius:5px}
.iwd .dsh-table td.cmp{width:26%}

.iwd .donutwrap{position:relative;height:186px;padding:16px 10px 4px}
.iwd .donutwrap>div{width:100%;height:100%}
.iwd .donutctr{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;padding-bottom:6px}
.iwd .donutctr b{font-family:var(--mono);font-size:15px;font-weight:600;color:var(--ink);white-space:nowrap;letter-spacing:-.01em}
.iwd .donutctr span{font-size:9.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted2);margin-top:3px}
.iwd .legend{display:flex;flex-direction:column;gap:8px;padding:6px 18px 18px}
.iwd .legend .lg{display:flex;align-items:center;gap:9px;font-size:12.5px;color:var(--ink2)}
.iwd .legend .sw{width:11px;height:11px;border-radius:3px;flex:0 0 auto}
.iwd .legend .lg .v{margin-left:auto;font-family:var(--mono);font-weight:600;color:var(--ink)}
.iwd .legend .lg .p{color:var(--muted2);font-family:var(--mono);width:44px;text-align:right}

.iwd .sparks{display:grid;grid-template-columns:repeat(3,1fr);gap:0;padding:2px 0}
.iwd .spark{padding:16px 18px;border-right:1px solid var(--border)}
.iwd .spark:last-child{border-right:0}
.iwd .spark .lbl{font-size:10.5px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted)}
.iwd .spark .row{display:flex;align-items:baseline;gap:8px;margin-top:7px}
.iwd .spark .val{font-family:var(--mono);font-size:20px;font-weight:600;color:var(--ink)}
.iwd .spark .val u{font-size:11px;font-weight:500;color:var(--muted2);text-decoration:none;margin-left:3px}
.iwd .spark .mini{position:relative;height:40px;margin-top:8px}
.iwd .spark .mini>div{width:100%;height:100%}

.iwd .foot{font-size:11.5px;line-height:1.6;color:var(--muted);margin:20px 2px 0;max-width:960px}
.iwd .foot b{color:var(--muted2);font-weight:600}
.iwd .dsh-empty{padding:34px 16px;text-align:center;color:var(--muted2);font-size:13px}

@media(max-width:960px){
  .iwd .dsh-kpis{grid-template-columns:repeat(2,1fr)}
  .iwd .dsh-grid2{grid-template-columns:1fr}
  .iwd .sparks{grid-template-columns:1fr}
  .iwd .spark{border-right:0;border-bottom:1px solid var(--border)}
  .iwd .summary{margin-left:0;width:100%}
}
</style>

<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header($PAGE_TITLE, 'Água, energia e custo dos apontamentos de irrigação — por válvula, com eficiência e composição do custo', null) ?>
  <?php /* C-42: abas Água/Energia/Custo removidas (20/07) — o toggle de métrica
           deste dashboard já cobre as três; evita o duplo seletor no topo. */ ?>

  <div class="iwd">
  <?php if (!$valves): ?>
    <div class="vcard dsh-empty">
      Nenhum consumo de irrigação lançado — os consumos nascem nos apontamentos de irrigação.
    </div>
  <?php else: ?>

    <div class="controls vcard">
      <div class="pills" id="iwdPills">
        <button class="dsh-pill active" type="button" data-m="agua">Água</button>
        <?php if ($temEnergia): ?><button class="dsh-pill" type="button" data-m="energia">Energia</button><?php endif; ?>
        <button class="dsh-pill" type="button" data-m="custo">Custo</button>
      </div>
      <div class="filters">
        <select id="iwdValv" class="dsh-select" aria-label="Válvula">
          <option value="todas">Todas as válvulas</option>
          <?php foreach ($valves as $v): ?>
            <option value="<?= h($v['key']) ?>"><?= h($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="iwdPer" class="dsh-select" aria-label="Período">
          <option value="12">Mensal &middot; <?= (int)$anoBase ?></option>
          <option value="6">Últimos 6 meses</option>
          <option value="3">Últimos 3 meses</option>
        </select>
      </div>
      <div class="summary" id="iwdSummary"></div>
    </div>

    <div class="dsh-kpis" id="iwdKpis">
      <?php
      echo $kpiCardHtml('Consumo total', numFmt($agg['agua'], 1) . '<u>m³</u>', $dAgua, $mMes);
      echo $kpiCardHtml('Custo total', 'R$ ' . numFmt($agg['custo'], 2), $dCusto, $mMes);
      if ($temEnergia) echo $kpiCardHtml('Energia', numFmt($agg['energia'], 1) . '<u>kWh</u>', $dEner, $mMes);
      if ($temHoras)   echo $kpiCardHtml('Horas irrigadas', numFmt($agg['horas'], 1) . '<u>h</u>', null, $mMes);
      if ($temArea)    echo $kpiCardHtml('Consumo por área', numFmt($agg['agua'] / $agg['area'], 1) . '<u>m³/ha</u>', $dAgua, $mMes);
      ?>
    </div>

    <div class="vcard chartcard">
      <div class="cardhead">
        <h3 id="iwdTrendTitle">Consumo de água — evolução mensal</h3>
        <span class="cardnote" id="iwdTrendNote">m³ por mês &middot; <?= (int)$anoBase ?></span>
      </div>
      <div class="dsh-chart"><div id="iwdTrend"></div></div>
    </div>

    <div class="dsh-grid2">
      <div class="vcard">
        <div class="cardhead">
          <h3>Consumo e eficiência por válvula</h3>
          <span class="cardnote">m³/ha = consumo ÷ área &middot; R$/m³ = custo total ÷ consumo</span>
        </div>
        <table class="dsh-table">
          <thead><tr>
            <th>Válvula</th><th>Consumo (m³)</th><th>m³/ha</th><th>R$/m³</th><th>Comparativo</th>
          </tr></thead>
          <tbody id="iwdValvBody">
          <?php
          $maxM3 = 0.0; foreach ($valves as $v) $maxM3 = max($maxM3, $v['agua']);
          foreach ($valves as $v):
              $custoTot = $v['custoAgua'] + $v['custoEnergia'];
              $pct = $maxM3 > 0 ? $v['agua'] / $maxM3 * 100 : 0; ?>
            <tr>
              <td class="vn"><?= h($v['name']) ?><?= $v['area'] !== null ? '<span class="area">' . numFmt($v['area'], 2) . ' ha</span>' : '' ?></td>
              <td class="cell-num"><?= numFmt($v['agua'], 1) ?></td>
              <td class="cell-num"><?= $v['area'] > 0 ? numFmt($v['agua'] / $v['area'], 1) : '—' ?></td>
              <td class="cell-num"><?= $v['agua'] > 0 ? numFmt($custoTot / $v['agua'], 4) : '—' ?></td>
              <td class="cmp"><div class="dsh-bar"><i style="width:<?= number_format($pct, 1, '.', '') ?>%"></i></div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="vcard">
        <div class="cardhead">
          <h3>Composição do custo</h3>
          <span class="cardnote">Água × Energia</span>
        </div>
        <div class="donutwrap">
          <div id="iwdDonut"></div>
          <div class="donutctr"><b id="iwdDonutTot">R$ <?= numFmt($agg['custo'], 2) ?></b><span>custo total</span></div>
        </div>
        <div class="legend" id="iwdDonutLegend"></div>
      </div>
    </div>

    <div class="vcard">
      <div class="cardhead">
        <h3>Água × Energia × Custo — evolução</h3>
        <span class="cardnote" id="iwdSparkNote"><?= (int)$anoBase ?></span>
      </div>
      <div class="sparks" id="iwdSparks"></div>
    </div>

    <p class="foot">
      <b>Como lemos:</b> m³/ha = consumo &divide; área da válvula; R$/m³ = (custo de água + energia) &divide; consumo.
      As variações comparam o último mês do eixo<?= $mMes !== '' ? ' (' . h($mMes) . '/' . (int)$anoBase . ')' : '' ?>
      com o mês anterior; quando não há mês anterior com base, a variação é omitida.
      <b>Tarifas vigentes:</b> água <?= $tarAgua !== null ? 'R$ ' . numFmt($tarAgua, 4) . '/m³' : '—' ?>
      e energia <?= $tarEner !== null ? 'R$ ' . numFmt($tarEner, 4) . '/kWh' : '—' ?>.
      Os custos exibidos são os valores <b>reais gravados em cada consumo</b>, não derivados da tarifa.
    </p>

  <?php endif; ?>
  </div>
</div>

<?php if ($valves): ?>
<script defer src="<?= rtrim(BIOS_BASE, '/') ?>/assets/vendor/echarts/echarts.min.js"></script>
<script>
(function(){
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    || navigator.webdriver === true;               /* OBS-B: automação/leitores veem o valor final */
  var D = <?= jsvar($DATA) ?>;
  var C = D.C, MONO = "'IBM Plex Mono',ui-monospace,monospace";

  /* ---- formatação pt-BR ---- */
  function nf(n, d){ d = d || 0; return Number(n).toLocaleString('pt-BR', {minimumFractionDigits:d, maximumFractionDigits:d}); }
  function money(n, d){ d = (d === undefined) ? 2 : d; return 'R$ ' + nf(n, d); }

  /* índice da válvula selecionada em D.valves, ou -1 p/ 'todas' */
  function valveIndex(key){ for (var i=0;i<D.valves.length;i++){ if (D.valves[i].key === key) return i; } return -1; }

  var state = { metric:'agua', valv:'todas', per:12 };
  var META = {
    agua:    { color:C.accent, title:'Consumo de água — evolução mensal', note:'m³ por mês',  unit:'m³',  dec:1, money:false },
    energia: { color:C.olive,  title:'Energia — evolução mensal',         note:'kWh por mês', unit:'kWh', dec:1, money:false },
    custo:   { color:C.amber,  title:'Custo — evolução mensal',           note:'R$ por mês',  unit:'',    dec:2, money:true }
  };

  function serie(metric){ return (D.monthly[state.valv] || D.monthly['todas'])[metric]; }
  function windowN(){ return Math.min(state.per, D.months.length); }

  var elTrend = document.getElementById('iwdTrend');
  var elDonut = document.getElementById('iwdDonut');
  var trend, donut, sparkCharts = [];

  var baseTip = { backgroundColor:'#fff', borderColor:C.border, textStyle:{color:C.ink, fontSize:12},
    extraCssText:'box-shadow:0 8px 24px -12px rgba(8,38,42,.35);border-radius:9px;' };

  /* ---- Tendência (barras) ---- */
  function drawTrend(){
    var m = META[state.metric], n = windowN(), from = D.months.length - n;
    var labels = D.months.slice(from), data = serie(state.metric).slice(from).map(function(x){ return Math.round(x*100)/100; });
    var valvName = state.valv === 'todas' ? '' : ' · ' + D.valves[valveIndex(state.valv)].name;
    document.getElementById('iwdTrendTitle').textContent = m.title + valvName;
    document.getElementById('iwdTrendNote').textContent = m.note + ' · ' + (state.per >= D.months.length ? String(D.anoBase) : ('últimos ' + n + 'm'));
    if (!trend) trend = echarts.init(elTrend, null, {renderer:'canvas'});
    trend.setOption({
      textStyle:{fontFamily:'inherit'},
      animationDuration: reduced ? 0 : 700, animationEasing:'cubicOut',
      grid:{left:8, right:14, top:16, bottom:6, containLabel:true},
      tooltip:Object.assign({trigger:'axis', axisPointer:{type:'shadow'},
        valueFormatter:function(v){ return m.money ? money(v) : nf(v, m.dec) + (m.unit ? ' ' + m.unit : ''); }}, baseTip),
      xAxis:{ type:'category', data:labels, axisTick:{show:false},
        axisLine:{lineStyle:{color:C.border}}, axisLabel:{color:C.muted, fontSize:11.5} },
      yAxis:{ type:'value', splitLine:{lineStyle:{color:C.track}},
        axisLabel:{color:C.muted, fontSize:11, fontFamily:MONO,
          formatter:function(v){ return v >= 1000 ? (v/1000) + 'k' : v; }} },
      series:[{ type:'bar', data:data, barMaxWidth:46,
        itemStyle:{ color:m.color, borderRadius:[6,6,0,0] },
        emphasis:{ itemStyle:{ color:m.color } } }]
    }, true);
  }

  /* ---- Donut (composição do custo água × energia) ---- */
  function drawDonut(){
    var ca, ce;
    if (state.valv === 'todas'){ ca = D.agg.custoAgua; ce = D.agg.custoEnergia; }
    else { var v = D.valves[valveIndex(state.valv)]; ca = v.custoAgua; ce = v.custoEnergia; }
    var tot = ca + ce;
    document.getElementById('iwdDonutTot').textContent = money(tot);
    if (!donut) donut = echarts.init(elDonut, null, {renderer:'canvas'});
    donut.setOption({
      textStyle:{fontFamily:'inherit'},
      animationDuration: reduced ? 0 : 700,
      tooltip:Object.assign({trigger:'item',
        valueFormatter:function(v){ return money(v); }}, baseTip),
      series:[{ type:'pie', radius:['62%','86%'], center:['50%','50%'], avoidLabelOverlap:false,
        label:{show:false}, labelLine:{show:false}, padAngle:2,
        itemStyle:{ borderColor:'#fff', borderWidth:2, borderRadius:5 },
        emphasis:{ scaleSize:5 },
        data:[ {value:Math.round(ca*100)/100, name:'Água',    itemStyle:{color:C.accent}},
               {value:Math.round(ce*100)/100, name:'Energia', itemStyle:{color:C.amber}} ] }]
    }, true);
    var pc = function(x){ return tot > 0 ? nf(x/tot*100, 1) : '0,0'; };
    document.getElementById('iwdDonutLegend').innerHTML =
      row(C.accent, 'Água', ca, pc(ca)) + row(C.amber, 'Energia', ce, pc(ce));
    function row(cor, lbl, val, p){
      return '<div class="lg"><span class="sw" style="background:' + cor + '"></span>' + lbl +
        '<span class="v">' + money(val) + '</span><span class="p">' + p + '%</span></div>';
    }
  }

  /* ---- KPIs + summary (re-render no filtro de válvula) ---- */
  function deltaPct(serieArr){
    var n = serieArr.length; if (n < 2) return null;
    var cur = serieArr[n-1], prev = serieArr[n-2];
    if (prev <= 0) return null; return (cur - prev) / prev;
  }
  function kpiHtml(lbl, valHtml, dlt){
    var d;
    if (dlt === null || dlt === undefined){ d = '<div class="dlt none"><span class="cap">sem mês anterior p/ comparar</span></div>'; }
    else { var up = dlt >= 0; d = '<div class="dlt ' + (up?'up':'down') + '"><span class="arw">' + (up?'▲':'▼') + '</span>' +
      nf(Math.abs(dlt)*100, 1) + '%<span class="cap">vs mês anterior</span></div>'; }
    return '<div class="vcard dsh-kpi"><div class="lbl">' + lbl + '</div><div class="val">' + valHtml + '</div>' + d + '</div>';
  }
  function drawKpis(){
    var agua, energia, custo, horas, area;
    if (state.valv === 'todas'){ agua=D.agg.agua; energia=D.agg.energia; custo=D.agg.custo; horas=D.agg.horas; area=D.agg.area; }
    else { var v = D.valves[valveIndex(state.valv)]; agua=v.agua; energia=v.energia; custo=v.custoAgua+v.custoEnergia; horas=v.horas; area=(v.area||0); }
    var mo = D.monthly[state.valv] || D.monthly['todas'];
    var html = '';
    html += kpiHtml('Consumo total', nf(agua,1) + '<u>m³</u>', deltaPct(mo.agua));
    html += kpiHtml('Custo total', money(custo), deltaPct(mo.custo));
    if (energia > 0) html += kpiHtml('Energia', nf(energia,1) + '<u>kWh</u>', deltaPct(mo.energia));
    if (horas > 0)   html += kpiHtml('Horas irrigadas', nf(horas,1) + '<u>h</u>', null);
    if (area > 0)    html += kpiHtml('Consumo por área', nf(agua/area,1) + '<u>m³/ha</u>', deltaPct(mo.agua));
    document.getElementById('iwdKpis').innerHTML = html;
  }
  function drawTable(){
    var rows = state.valv === 'todas' ? D.valves : [D.valves[valveIndex(state.valv)]];
    var maxM3 = 0; D.valves.forEach(function(v){ if (v.agua > maxM3) maxM3 = v.agua; });
    document.getElementById('iwdValvBody').innerHTML = rows.map(function(v){
      var custoTot = v.custoAgua + v.custoEnergia, pct = maxM3 > 0 ? Math.round(v.agua/maxM3*100) : 0;
      return '<tr><td class="vn">' + esc(v.name) + (v.area != null ? '<span class="area">' + nf(v.area,2) + ' ha</span>' : '') + '</td>' +
        '<td class="cell-num">' + nf(v.agua,1) + '</td>' +
        '<td class="cell-num">' + (v.area > 0 ? nf(v.agua/v.area,1) : '—') + '</td>' +
        '<td class="cell-num">' + (v.agua > 0 ? nf(custoTot/v.agua,4) : '—') + '</td>' +
        '<td class="cmp"><div class="dsh-bar"><i style="width:' + pct + '%"></i></div></td></tr>';
    }).join('');
  }

  function hexA(hex, a){ var n = parseInt(hex.slice(1),16); return 'rgba(' + ((n>>16)&255) + ',' + ((n>>8)&255) + ',' + (n&255) + ',' + a + ')'; }

  /* ---- Resumo (linha do topo, re-render no filtro de válvula) ---- */
  function drawSummary(){
    var agua, custo, horas;
    if (state.valv === 'todas'){ agua = D.agg.agua; custo = D.agg.custo; horas = D.agg.horas; }
    else { var v = D.valves[valveIndex(state.valv)]; agua = v.agua; custo = v.custoAgua + v.custoEnergia; horas = v.horas; }
    var s = 'total <b>' + nf(agua, 1) + '</b> m³ · custo <b>' + money(custo) + '</b>';
    if (horas > 0) s += ' · <b>' + nf(horas, 1) + '</b> h irrigadas';
    document.getElementById('iwdSummary').innerHTML = s;
  }

  /* ---- Sparklines Água × Energia × Custo (série anual real por válvula) ---- */
  function drawSparks(){
    var mo = D.monthly[state.valv] || D.monthly['todas'];
    var defs = [ { lbl:'Água', arr:mo.agua, color:C.accent, unit:'m³', money:false } ];
    if (D.agg.energia > 0 || D.agg.custoEnergia > 0)
      defs.push({ lbl:'Energia', arr:mo.energia, color:C.olive, unit:'kWh', money:false });
    defs.push({ lbl:'Custo', arr:mo.custo, color:C.amber, unit:'', money:true });

    var valvName = state.valv === 'todas' ? String(D.anoBase) + ' · fazenda'
      : String(D.anoBase) + ' · ' + D.valves[valveIndex(state.valv)].name;
    document.getElementById('iwdSparkNote').textContent = valvName;

    var host = document.getElementById('iwdSparks');
    host.style.gridTemplateColumns = 'repeat(' + defs.length + ',1fr)';
    sparkCharts.forEach(function(c){ c.dispose(); }); sparkCharts = [];
    host.innerHTML = '';

    defs.forEach(function(d){
      var last = d.arr.length ? d.arr[d.arr.length - 1] : 0;
      var el = document.createElement('div'); el.className = 'spark';
      el.innerHTML = '<div class="lbl">' + d.lbl + '</div>' +
        '<div class="row"><span class="val">' + (d.money ? money(last) : nf(last, 0)) +
        (d.money ? '' : '<u> ' + d.unit + '</u>') + '</span></div>' +
        '<div class="mini"><div></div></div>';
      host.appendChild(el);
      var mount = el.querySelector('.mini > div');
      var ch = echarts.init(mount, null, {renderer:'canvas'});
      ch.setOption({
        textStyle:{fontFamily:'inherit'},
        animationDuration: reduced ? 0 : 700,
        grid:{left:0, right:0, top:3, bottom:3},
        xAxis:{ type:'category', show:false, boundaryGap:false, data:D.months },
        yAxis:{ type:'value', show:false },
        tooltip:Object.assign({trigger:'axis', axisPointer:{type:'none'},
          valueFormatter:function(v){ return d.money ? money(v) : nf(v, 0) + (d.unit ? ' ' + d.unit : ''); }}, baseTip),
        series:[{ type:'line', data:d.arr.map(function(x){ return Math.round(x*100)/100; }),
          smooth:true, symbol:'none', lineStyle:{color:d.color, width:2},
          areaStyle:{ color:new echarts.graphic.LinearGradient(0,0,0,1,
            [{offset:0, color:hexA(d.color,.28)}, {offset:1, color:hexA(d.color,0)}]) } }]
      }, true);
      sparkCharts.push(ch);
    });
  }

  function renderAll(){ drawKpis(); drawTrend(); drawTable(); drawDonut(); drawSparks(); drawSummary(); }

  function boot(){
    if (typeof echarts === 'undefined') return;
    /* controles */
    document.getElementById('iwdValv').addEventListener('change', function(){ state.valv = this.value; renderAll(); });
    document.getElementById('iwdPer').addEventListener('change', function(){ state.per = parseInt(this.value, 10) || 12; drawTrend(); });
    document.querySelectorAll('#iwdPills .dsh-pill').forEach(function(b){
      b.addEventListener('click', function(){
        document.querySelectorAll('#iwdPills .dsh-pill').forEach(function(x){ x.classList.remove('active'); });
        this.classList.add('active'); state.metric = this.getAttribute('data-m'); drawTrend();
      });
    });
    renderAll();
    window.addEventListener('resize', function(){
      if (trend) trend.resize(); if (donut) donut.resize();
      sparkCharts.forEach(function(c){ c.resize(); });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
