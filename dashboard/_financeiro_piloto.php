<?php
/* ============================================================
   VERO — Dashboard Financeiro · redesenho A4-05 (design docs/
   dashboard_financeiro_vero.html) ligado a DADOS REAIS.
   DEFAULT de dashboard/dashboard_financeiro.php (?classico=1 = render
   antigo). Reusa as variáveis da tela + queries de leitura próprias.
   Mesmo padrão do executivo: ECharts vendor LOCAL, animações em JS
   puro (sem GSAP), fonte stack VERO, CSS escopado em .dex, banner
   com logo. Saldo em caixa vem de contas_bancarias (fonte de verdade).
   NENHUMA métrica inventada — a "conciliação bancária" do mockup (sem
   backend) foi trocada por cards reais (custeio em donut + resultado).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/_dash.php';

$C = ['accent' => '#005059', 'deep' => '#00363D', 'a3' => '#2A767C', 'olive' => '#4E9CA1',
      'pos' => '#0E7E72', 'amber' => '#B57C1A', 'danger' => '#B23A2E',
      'track' => '#EEE6D6', 'border' => '#E3D9C8', 'muted' => '#8A7C68'];

/* Saldo em caixa = razão pago acumulado (entradas − saídas baixadas), all-time.
   Nota: as movimentações não vêm amarradas a contas_bancarias neste tenant, então
   o saldo real vem do razão pago (não do saldo_inicial das contas). */
$saldoCaixa = (float)vero_val(
    "SELECT COALESCE(SUM(CASE WHEN tipo='receber' THEN valor WHEN tipo='pagar' THEN -valor ELSE 0 END),0)
       FROM movimentacoes_financeiras WHERE tenant_id = :t AND status='pago'", [':t' => $t]);
$posLiquida = (float)$posicao['receber_aberto'] - (float)$posicao['pagar_aberto'];
$caixaProjetado = $saldoCaixa + $posLiquida;

/* F-04 R2 (auditoria 19/07): abertos SEM data de vencimento — mesmo bucket
   "Sem vencimento" do fluxo de caixa (commit 3838bce). O card acima JÁ os
   inclui ($posicao não filtra data); a série acumulada e o aging abaixo
   ganham o bucket explícito p/ as 3 visões (fluxo, card, aging) fecharem
   ao centavo com o em-aberto de Contas a Pagar/Receber. */
$semVencTot = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN tipo='receber' THEN valor END),0) AS e,
            COALESCE(SUM(CASE WHEN tipo='pagar' THEN valor END),0) AS s
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status='aberto' AND data_vencimento IS NULL", [':t' => $t]);
$svE = (float)$semVencTot['e']; $svS = (float)$semVencTot['s'];

/* ── Série de caixa: realizado (pago) + projetado (aberto/vencimento) por mês ── */
$caixaSerieRows = vero_rows(
    "SELECT ym, SUM(entr) AS entr, SUM(sai) AS sai, MAX(fut) AS fut FROM (
        SELECT DATE_FORMAT(data_pagamento,'%Y-%m') AS ym,
               SUM(CASE WHEN tipo='receber' THEN valor ELSE 0 END) AS entr,
               SUM(CASE WHEN tipo='pagar'   THEN valor ELSE 0 END) AS sai, 0 AS fut
          FROM movimentacoes_financeiras
         WHERE tenant_id = :t AND status='pago' AND data_pagamento IS NOT NULL GROUP BY ym
        UNION ALL
        SELECT DATE_FORMAT(data_vencimento,'%Y-%m') AS ym,
               SUM(CASE WHEN tipo='receber' THEN valor ELSE 0 END),
               SUM(CASE WHEN tipo='pagar'   THEN valor ELSE 0 END), 1
          FROM movimentacoes_financeiras
         WHERE tenant_id = :t2 AND status='aberto' AND data_vencimento IS NOT NULL GROUP BY ym
      ) x GROUP BY ym ORDER BY ym", [':t' => $t, ':t2' => $t]);
$labels = $entr = $sai = $acum = []; $run = 0.0;
foreach (array_slice($caixaSerieRows, -10) as $r) {
    $labels[] = date('m/y', (int)strtotime($r['ym'] . '-01'));
    $e = (float)$r['entr']; $s = (float)$r['sai'];
    $entr[] = round($e, 2); $sai[] = round($s, 2);
    $run += $e - $s; $acum[] = round($run, 2);
}
if ($svE > 0 || $svS > 0) { /* F-04: bucket "Sem venc." — o acumulado final fecha com o card */
    $labels[] = 'Sem venc.';
    $entr[] = round($svE, 2); $sai[] = round($svS, 2);
    $run += $svE - $svS; $acum[] = round($run, 2);
}
$chCaixa = $labels ? ['labels' => $labels, 'entr' => $entr, 'sai' => $sai, 'acum' => $acum] : null;

/* ── Aging dos títulos em aberto (a receber × a pagar por faixa) ──
   F-04 R2: título sem data de vencimento NÃO some mais — bucket explícito
   "Sem vencimento" (mesmo rótulo do fluxo de caixa); total do aging =
   em aberto das telas de contas, ao centavo. */
$agingRows = vero_rows(
    "SELECT tipo,
       CASE WHEN data_vencimento IS NULL THEN 4
            WHEN data_vencimento >= CURDATE() THEN 0
            WHEN data_vencimento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1
            WHEN data_vencimento >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN 2
            ELSE 3 END AS bucket, SUM(valor) AS tot
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status='aberto'
      GROUP BY tipo, bucket", [':t' => $t]);
$rec = [0, 0, 0, 0, 0]; $pag = [0, 0, 0, 0, 0];
foreach ($agingRows as $r) { $b = (int)$r['bucket'];
    if ($r['tipo'] === 'receber') $rec[$b] = round((float)$r['tot'], 2);
    elseif ($r['tipo'] === 'pagar') $pag[$b] = round((float)$r['tot'], 2); }
$agingBkts = ['A vencer', '1–30 dias', '31–60 dias', '+60 dias', 'Sem vencimento'];
if ($rec[4] + $pag[4] <= 0) { /* sem título sem-vencimento → bucket não aparece */
    array_pop($agingBkts); array_pop($rec); array_pop($pag);
}
$chAging = (array_sum($rec) + array_sum($pag)) > 0
    ? ['buckets' => array_reverse($agingBkts),
       'receber' => array_reverse($rec), 'pagar' => array_reverse($pag)] : null;

/* ── Custeio do ano por categoria (donut com rótulo central) ──
   Z-08: paleta CATEGÓRICA de alto contraste. A anterior era
   monocromática (só tons de verde/teal + um tan) e as fatias vizinhas —
   "verde × amarelo" — ficavam indistinguíveis. Agora cores bem separadas
   em MATIZ e LUMINÂNCIA, começando pelo par pedido pelo cliente: verde
   royal + amarelo dourado, bem contrastados. Ordem = fatias maiores (o
   custeio vem ORDER BY total DESC) recebem os dois primeiros. */
$paleta = ['#00544A', '#E8B004', '#2F6FB0', '#C4531D', '#7A4E9E', '#8A7C68'];
$chCusteio = $custeio ? ['items' => array_values(array_map(
    static fn($c, $i) => ['name' => $rotuloCat((string)$c['categoria']), 'value' => round((float)$c['total'], 2),
        'color' => $paleta[$i % count($paleta)]], $custeio, array_keys($custeio)))] : null;

$saldoCls = $saldoCaixa >= 0 ? 'k-pos' : 'k-danger';
$projCls  = $caixaProjetado >= 0 ? 'k-pos' : 'k-danger';

/* ── A3-T30 (P-90/DB-55): Resultado Bruto × Líquido por safra.
   `tenant_parametros.resultado.descontos` lista o que SEPARA bruto de líquido
   (seed: Depreciação via categoria do custeio): Bruto = faturamento − custos
   SEM os itens da lista; Líquido = Bruto − itens da lista (= fat − custo total). ── */
$descontosCfg = json_decode((string)(vero_val(
    "SELECT valor FROM tenant_parametros WHERE tenant_id = :t AND chave = 'resultado.descontos'",
    [':t' => $t]) ?? ''), true) ?: [];
$descCategorias = [];
$descRotulos = [];
foreach ($descontosCfg as $d) {
    if (($d['origem'] ?? '') === 'custeio_categoria' && ($d['ref'] ?? '') !== '') {
        $descCategorias[] = (string)$d['ref'];
        $descRotulos[] = (string)($d['rotulo'] ?? $d['ref']);
    }
}
$descPorSafra = [];
if ($descCategorias) {
    $ph = []; $par = [':t' => $t];
    foreach (array_values(array_unique($descCategorias)) as $i => $cat) { /* QA-011: placeholders distintos */
        $ph[] = ":c{$i}";
        $par[":c{$i}"] = $cat;
    }
    foreach (vero_rows(
        "SELECT safra_id, SUM(valor) AS tot FROM custeio_lancamentos
          WHERE tenant_id = :t AND categoria IN (" . implode(',', $ph) . ") AND safra_id IS NOT NULL
          GROUP BY safra_id", $par) as $r) {
        $descPorSafra[(int)$r['safra_id']] = (float)$r['tot'];
    }
}

/* ── A3-T30: Caixa Projetado da PRODUÇÃO por safra — três lentes de projeção
   (motor F1, pré-vendas, estimativa da colheita) + faturado como âncora.
   São LEITURAS INDEPENDENTES do mesmo futuro — nunca somar entre si. ── */
$projProducao = vero_rows(
    "SELECT s.id, s.identificacao,
            COALESCE((SELECT SUM(pc.produtividade_prevista_ha * pc.preco_previsto_unidade * pc.area_prevista_ha)
               FROM agro_custo_parametros_cultura pc
              WHERE pc.tenant_id = s.tenant_id AND pc.safra_id = s.id AND pc.ativo = 1), 0) AS receita_f1,
            COALESCE((SELECT SUM(ct.kg_contratado * ct.preco_kg) FROM comercial_contratos ct
              WHERE ct.tenant_id = s.tenant_id AND ct.safra_id = s.id AND ct.status = 'ativo'), 0) AS pre_vendas,
            COALESCE((SELECT SUM(cr.faturamento_realizado) FROM colheita_registros cr
              WHERE cr.tenant_id = s.tenant_id AND cr.safra_id = s.id), 0) AS proj_colheita,
            COALESCE((SELECT SUM(v.valor_total) FROM comercial_vendas v
              WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id AND v.status <> 'cancelada'), 0) AS faturado
       FROM agro_safras s
      WHERE s.tenant_id = :t
     HAVING receita_f1 > 0 OR pre_vendas > 0 OR proj_colheita > 0 OR faturado > 0
      ORDER BY s.identificacao DESC LIMIT 6", [':t' => $t]);

/* ══════════════ DETALHAMENTO (modal) POR KPI — dado real ══════════════ */
$brl2 = static fn($v) => 'R$ ' . numFmt((float)$v, 2);
$dv = static fn($s) => $s ? date('d/m/Y', strtotime((string)$s)) : '—';
$pagoComp = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN tipo='receber' THEN valor END),0) AS rec,
            COALESCE(SUM(CASE WHEN tipo='pagar' THEN valor END),0) AS pag
       FROM movimentacoes_financeiras WHERE tenant_id = :t AND status='pago'", [':t' => $t]);
$recMesL = vero_rows(
    "SELECT descricao, valor, data_pagamento FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND tipo='receber' AND status='pago' AND data_pagamento BETWEEN :i AND :f
      ORDER BY data_pagamento DESC LIMIT 20", [':t' => $t, ':i' => $mesIni, ':f' => $mesFim]);
$pagMesL = vero_rows(
    "SELECT descricao, valor, data_pagamento FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND tipo='pagar' AND status='pago' AND data_pagamento BETWEEN :i AND :f
      ORDER BY data_pagamento DESC LIMIT 20", [':t' => $t, ':i' => $mesIni, ':f' => $mesFim]);
$titReceber = vero_rows(
    "SELECT descricao, valor, data_vencimento FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND tipo='receber' AND status='aberto' ORDER BY data_vencimento LIMIT 20", [':t' => $t]);
$titPagar = vero_rows(
    "SELECT descricao, valor, data_vencimento FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND tipo='pagar' AND status='aberto' ORDER BY data_vencimento LIMIT 20", [':t' => $t]);
$linhaMov = static fn($r, $dcol, $sign) => [h(mb_substr((string)$r['descricao'], 0, 40) ?: '—'), $dv($r[$dcol]), $sign . numFmt((float)$r['valor'], 2)];

$kpiDet = [
    'saldo' => ['titulo' => 'Saldo em caixa', 'valor' => $brl2($saldoCaixa), 'sub' => 'Razão pago acumulado (entradas − saídas baixadas)',
        'cols' => ['Item', 'R$'], 'rows' => [
            ['Recebido (pago) acumulado', '+' . numFmt((float)$pagoComp['rec'], 2)],
            ['Pago acumulado', '−' . numFmt((float)$pagoComp['pag'], 2)],
            ['Saldo em caixa', numFmt($saldoCaixa, 2)]]],
    'recebido' => ['titulo' => 'Recebido em ' . $perMesLabel, 'valor' => $brl2((float)$caixaMes['entradas']), 'sub' => 'Entradas baixadas em ' . $perMesLabel . ' (por data de pagamento) — não é o acumulado histórico',
        'cols' => ['Título', 'Data', 'R$'], 'rows' => array_map(fn($r) => $linhaMov($r, 'data_pagamento', '+'), $recMesL)],
    'pago' => ['titulo' => 'Pago em ' . $perMesLabel, 'valor' => $brl2((float)$caixaMes['saidas']), 'sub' => 'Saídas baixadas em ' . $perMesLabel . ' (por data de pagamento) — não é o acumulado histórico',
        'cols' => ['Título', 'Data', 'R$'], 'rows' => array_map(fn($r) => $linhaMov($r, 'data_pagamento', '−'), $pagMesL)],
    'receber' => ['titulo' => 'A receber em aberto', 'valor' => $brl2((float)$posicao['receber_aberto']), 'sub' => 'Títulos a receber em aberto',
        'cols' => ['Título', 'Vencimento', 'R$'], 'rows' => array_map(fn($r) => $linhaMov($r, 'data_vencimento', '+'), $titReceber)],
    'pagar' => ['titulo' => 'A pagar em aberto', 'valor' => $brl2((float)$posicao['pagar_aberto']), 'sub' => 'Títulos a pagar em aberto',
        'cols' => ['Título', 'Vencimento', 'R$'], 'rows' => array_map(fn($r) => $linhaMov($r, 'data_vencimento', '−'), $titPagar)],
    'projetado' => ['titulo' => 'Caixa Projetado (títulos)', 'valor' => $brl2($caixaProjetado), 'sub' => 'Saldo em caixa + a receber − a pagar (títulos do razão; a projeção da PRODUÇÃO está na tabela por safra)',
        'cols' => ['Item', 'R$'], 'rows' => [
            ['Saldo em caixa', '+' . numFmt($saldoCaixa, 2)],
            ['A receber em aberto', '+' . numFmt((float)$posicao['receber_aberto'], 2)],
            ['A pagar em aberto', '−' . numFmt((float)$posicao['pagar_aberto'], 2)],
            ['Caixa projetado', numFmt($caixaProjetado, 2)]],
        /* F-04 R2: transparência — o total JÁ inclui os abertos sem vencimento */
        'note' => ($svE > 0 || $svS > 0)
            ? 'Inclui os títulos em aberto SEM data de vencimento ('
              . ($svE > 0 ? 'a receber R$ ' . numFmt($svE, 2) . ($svS > 0 ? ' · ' : '') : '')
              . ($svS > 0 ? 'a pagar R$ ' . numFmt($svS, 2) : '')
              . ') — mesmo bucket "Sem vencimento" do fluxo de caixa e do aging.' : null],
];
?>
<style>
/* ===== Dashboards A4 — escopado em .dex (reuso do executivo) ===== */
.dex{
  --ac:#005059; --acd:#00363D; --ac3:#2A767C; --olive:#4E9CA1;
  --page:#EDEAE0; --surface:#fff; --warm:#FBF8F2; --ink:#241B14; --ink2:#2B2018;
  --mut:#8A7C68; --mut2:#9A8C78; --bd:#E3D9C8; --bd2:#DDD2BF; --track:#EEE6D6;
  --pos:#0E7E72; --pos-bg:#DDEDEB; --amber:#B57C1A; --amber-d:#7A5410; --amber-bg:#F3E7C8;
  --danger:#B23A2E; --danger-bg:#F2DCD8;
  --num:'IBM Plex Mono',ui-monospace,'SFMono-Regular',Menlo,monospace;
  --rc:13px; --r:9px; --rs:8px;
  position:relative; color:var(--ink); font-size:14px; line-height:1.5;
  padding:20px 26px 46px; overflow:hidden;
  background:linear-gradient(180deg,rgba(237,234,224,0) 0%,rgba(237,234,224,.06) 24%,rgba(237,234,224,.55) 62%,rgba(237,234,224,.85) 100%),
             url('<?= $base ?>/assets/img/dashboard-banner.webp') right top/cover no-repeat;
}
.dex .dwrap{position:relative; z-index:1}
.dex a{color:var(--ac)}
.dex .dtop{display:flex; align-items:flex-end; justify-content:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:10px}
.dex .dtop .dperiodo{font-size:12px; color:var(--mut2)}
.dex .kpis{display:grid; grid-template-columns:repeat(auto-fit,minmax(185px,1fr)); gap:14px; margin-bottom:20px}
.dex .kpi{background:rgba(255,255,255,.66); border:1px solid rgba(227,217,200,.9); border-radius:var(--rc); padding:14px 16px 12px; container-type:inline-size;
  position:relative; overflow:hidden; transition:transform .25s, box-shadow .25s, border-color .25s}
.dex .kpi:hover{transform:translateY(-3px); box-shadow:0 10px 26px -14px rgba(8,38,42,.35); border-color:var(--bd2)}
.dex .kpi .kl{font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:var(--mut)}
.dex .kpi .kv{font-family:var(--num); font-size:clamp(13px,9cqi,23px); font-weight:700; color:var(--acd); margin-top:4px; white-space:nowrap}
.dex .kpi .ks{font-size:11.5px; color:var(--mut2); margin-top:3px}
.dex .kpi.k-pos .kv{color:var(--pos)} .dex .kpi.k-warn .kv{color:var(--amber)} .dex .kpi.k-danger .kv{color:var(--danger)}
.dex .kpi::after{content:""; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--ac3); opacity:.55}
.dex .kpi.k-pos::after{background:var(--pos)} .dex .kpi.k-warn::after{background:var(--amber)} .dex .kpi.k-danger::after{background:var(--danger)}
.dex .kd{display:inline-block; font-family:var(--num); font-size:11px; font-weight:600; border-radius:var(--rs); padding:1px 7px; margin-top:5px}
.dex .kd.up{background:var(--pos-bg); color:var(--pos)} .dex .kd.warn{background:var(--amber-bg); color:var(--amber-d)} .dex .kd.down{background:var(--danger-bg); color:var(--danger)}
.dex .grid{display:grid; grid-template-columns:repeat(12,1fr); gap:16px}
.dex .card{background:rgba(255,255,255,.66); border:1px solid rgba(227,217,200,.9); border-radius:var(--rc); padding:16px 18px; min-width:0;
  transition:box-shadow .25s, border-color .25s}
.dex .card:hover{border-color:var(--bd2); box-shadow:0 8px 22px -16px rgba(8,38,42,.25)}
.dex .card h2{font-size:13.5px; font-weight:700; color:var(--ink2)}
.dex .card .chint{font-size:11.5px; color:var(--mut2); margin:1px 0 10px}
.dex .c8{grid-column:span 8} .dex .c6{grid-column:span 6} .dex .c4{grid-column:span 4} .dex .c12{grid-column:span 12}
.dex .chart{width:100%; height:280px}
.dex .empty{padding:26px 14px; text-align:center; color:var(--mut2); font-size:13px}
.dex .empty a{font-weight:600}
.dex table.dt{width:100%; border-collapse:collapse; font-size:13px}
.dex .dt th{text-align:left; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.04em;
  color:var(--mut); background:var(--warm); padding:9px 12px; border-bottom:1px solid var(--bd)}
.dex .dt td{padding:9px 12px; border-bottom:1px solid var(--track); vertical-align:middle}
.dex .dt tr:last-child td{border-bottom:none}
.dex .dt tbody tr:hover{background:var(--warm)}
.dex .dt .num{font-family:var(--num); font-weight:600; text-align:right; white-space:nowrap}
.dex .dt th.num{text-align:right}
.dex .dt .tpos{color:var(--pos)} .dex .dt .tneg{color:var(--danger)}
.dex .vb{display:inline-block; font-size:11px; font-weight:600; border-radius:var(--rs); padding:2px 9px; white-space:nowrap}
.dex .vb-ok{background:var(--pos-bg); color:var(--pos)} .dex .vb-warn{background:var(--amber-bg); color:var(--amber-d)}
.dex .vb-danger{background:var(--danger-bg); color:var(--danger)} .dex .vb-info{background:#E2ECED; color:var(--ac)} .dex .vb-off{background:var(--track); color:var(--mut)}
.dex .dfoot{margin-top:22px; font-size:11px; color:var(--mut2); text-align:center}
.dex .mt16{margin-top:16px}
@media (max-width:1080px){ .dex .kpis{grid-template-columns:repeat(3,1fr)} .dex .c8,.dex .c6,.dex .c4{grid-column:span 12} }
@media (max-width:680px){ .dex{padding:16px 12px 40px} .dex .kpis{grid-template-columns:repeat(2,1fr)} .dex .kpi{padding:12px 12px 10px} }
@media (max-width:440px){ .dex .kpis{grid-template-columns:1fr} }
/* entrada só de deslize — nunca esconde o conteúdo (sem flicker no load) */
.dex [data-rise]{animation:dexrise .4s ease both}
@keyframes dexrise{from{transform:translateY(10px)} to{transform:none}}
@media (prefers-reduced-motion:reduce){ .dex [data-rise]{animation:none} }
/* KPI clicável → modal de detalhamento */
.dex .kpi[data-kpi]{cursor:pointer}
.dex .kpi[data-kpi]::before{content:"detalhar ›"; position:absolute; top:10px; right:12px; font-size:9.5px; font-weight:600; letter-spacing:.03em; color:var(--mut2); opacity:0; transition:opacity .2s}
.dex .kpi[data-kpi]:hover::before{opacity:.9}
.dex .kmodal{position:fixed; inset:0; z-index:1000; display:none; align-items:center; justify-content:center; background:rgba(20,15,10,.45); padding:20px}
.dex .kmodal.open{display:flex}
.dex .kbox{background:#fff; border-radius:16px; max-width:820px; width:100%; max-height:88vh; overflow:auto; box-shadow:0 24px 60px -20px rgba(8,38,42,.5)}
.dex .khead{display:flex; justify-content:space-between; align-items:flex-start; gap:12px; padding:20px 24px 14px; border-bottom:1px solid var(--bd); position:sticky; top:0; background:#fff}
.dex .khead h3{font-size:12.5px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:var(--mut)}
.dex .khead .kval{font-family:var(--num); font-size:28px; font-weight:700; color:var(--acd); margin-top:3px}
.dex .kx{border:0; background:none; font-size:26px; line-height:1; cursor:pointer; color:var(--mut)}
.dex .kbody{padding:18px 24px 24px; font-size:13.5px}
.dex .kbody .dt{font-size:13.5px}
.dex .kbody .dt td, .dex .kbody .dt th{padding:11px 14px}
.dex .kbody .ksub{font-size:12.5px; color:var(--mut2); margin:0 0 10px}
.dex .kbody .ksub.two{margin-top:20px}
.dex .kbody .knote{font-size:12.5px; color:var(--amber-d); margin-top:14px; background:var(--amber-bg); padding:11px 13px; border-radius:9px}
</style>

<div class="dex">
 <div class="dwrap">
  <?= vero_flash_html() ?>
  <header class="dtop" data-rise>
    <?php /* P12 (auditoria 20/07): o razão financeiro é inerentemente do tenant
       inteiro (movimentações não carregam safra/fazenda) — por isso NÃO há filtro
       Safra/Fazenda aqui (ao contrário do Executivo); tudo é consolidado do tenant
       e está rotulado. A dimensão de safra aparece só nas tabelas por safra.
       #50: há filtro de PERÍODO (mês/ano) — aplica-se ao caixa do mês e ao custeio
       do ano; séries e aging seguem all-time/point-in-time. */
    $meses = [1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',
              7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
    $anoAtual = (int)date('Y'); ?>
    <form method="get" class="dfiltro" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <label style="display:flex;flex-direction:column;gap:2px;font-size:11px;color:var(--mut,#8A7C68)">Mês
        <select name="fmes" onchange="this.form.submit()" style="padding:6px 8px;border:1px solid var(--bd,#E3D9C8);border-radius:8px;background:#fff">
          <?php foreach ($meses as $mk => $mv): ?>
            <option value="<?= $mk ?>"<?= $mk === $fMes ? ' selected' : '' ?>><?= h($mv) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="display:flex;flex-direction:column;gap:2px;font-size:11px;color:var(--mut,#8A7C68)">Ano
        <select name="fano" onchange="this.form.submit()" style="padding:6px 8px;border:1px solid var(--bd,#E3D9C8);border-radius:8px;background:#fff">
          <?php for ($y = $anoAtual + 1; $y >= $anoAtual - 5; $y--): ?>
            <option value="<?= $y ?>"<?= $y === $fAno ? ' selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <noscript><button type="submit" style="padding:7px 12px;border-radius:8px">Aplicar</button></noscript>
    </form>
  </header>

  <!-- KPIs -->
  <section class="kpis">
    <div class="kpi <?= $saldoCls ?>" data-rise data-kpi="saldo">
      <div class="kl">Saldo em caixa</div>
      <?php /* OBS-B: valor final renderizado de imediato — a contagem JS é só enhancement */ ?>
      <div class="kv" data-count="<?= $saldoCaixa ?>" data-fmt="brl">R$ <?= numFmt($saldoCaixa, 2) ?></div>
      <div class="ks">entradas − saídas baixadas</div>
    </div>
    <div class="kpi" data-rise data-kpi="recebido"
         title="Somente baixas com data de pagamento dentro de <?= $perMesLabel ?> (1º ao último dia do mês). Não é o acumulado histórico.">
      <div class="kl">Recebido em <?= $perMesLabel ?></div>
      <div class="kv" data-count="<?= (float)$caixaMes['entradas'] ?>" data-fmt="brl">R$ <?= numFmt((float)$caixaMes['entradas'], 2) ?></div>
      <div class="ks">baixado em <?= $perMesLabel ?> (por data de pagamento)</div>
    </div>
    <div class="kpi" data-rise data-kpi="pago"
         title="Somente baixas com data de pagamento dentro de <?= $perMesLabel ?> (1º ao último dia do mês). Não é o acumulado histórico.">
      <div class="kl">Pago em <?= $perMesLabel ?></div>
      <div class="kv" data-count="<?= (float)$caixaMes['saidas'] ?>" data-fmt="brl">R$ <?= numFmt((float)$caixaMes['saidas'], 2) ?></div>
      <div class="ks">baixado em <?= $perMesLabel ?> (por data de pagamento)</div>
    </div>
    <div class="kpi" data-rise data-kpi="receber">
      <div class="kl">A receber em aberto</div>
      <div class="kv" data-count="<?= (float)$posicao['receber_aberto'] ?>" data-fmt="brl">R$ <?= numFmt((float)$posicao['receber_aberto'], 2) ?></div>
      <?php if ((float)$posicao['receber_vencido'] > 0): ?><div class="kd down">vencido R$ <?= numFmt((float)$posicao['receber_vencido'], 0) ?></div><?php endif; ?>
    </div>
    <div class="kpi k-warn" data-rise data-kpi="pagar">
      <div class="kl">A pagar em aberto</div>
      <div class="kv" data-count="<?= (float)$posicao['pagar_aberto'] ?>" data-fmt="brl">R$ <?= numFmt((float)$posicao['pagar_aberto'], 2) ?></div>
      <?php if ((float)$posicao['pagar_vencido'] > 0): ?><div class="kd down">vencido R$ <?= numFmt((float)$posicao['pagar_vencido'], 0) ?></div><?php endif; ?>
    </div>
    <div class="kpi <?= $projCls ?>" data-rise data-kpi="projetado">
      <div class="kl">Caixa Projetado (títulos)</div>
      <div class="kv" data-count="<?= $caixaProjetado ?>" data-fmt="brl">R$ <?= numFmt($caixaProjetado, 2) ?></div>
      <div class="kd <?= $posLiquida >= 0 ? 'up' : 'down' ?>"><?= $posLiquida >= 0 ? '+' : '−' ?>R$ <?= numFmt(abs($posLiquida), 2) ?> líquidos</div>
      <?php if ($svE > 0 || $svS > 0): /* F-04 R2: nota — o total já soma os sem-vencimento */
          $svPartes = [];
          if ($svE > 0) $svPartes[] = 'R$ ' . numFmt($svE, 2) . ' a receber';
          if ($svS > 0) $svPartes[] = 'R$ ' . numFmt($svS, 2) . ' a pagar'; ?>
      <div class="ks">inclui <?= implode(' e ', $svPartes) ?> sem vencimento</div>
      <?php endif; ?>
    </div>
  </section>

  <!-- LINHA 2 -->
  <section class="grid">
    <div class="card c8" data-rise>
      <h2>Caixa realizado × projetado</h2>
      <?php if ($chCaixa): ?><div id="chCaixa" class="chart"></div>
      <?php else: ?><?= dash_empty('Sem movimentações com data para montar a série.', 'Abrir fluxo de caixa', $base . '/financeiro/fluxo_caixa') ?><?php endif; ?>
    </div>
    <div class="card c4" data-rise>
      <h2>Aging dos títulos em aberto</h2>
      <?php if ($chAging): ?><div id="chAging" class="chart"></div>
      <?php else: ?><?= dash_empty('Nenhum título em aberto.', 'Abrir fluxo de caixa', $base . '/financeiro/fluxo_caixa') ?><?php endif; ?>
    </div>
  </section>

  <!-- LINHA 3 -->
  <section class="grid mt16">
    <div class="card c4" data-rise>
      <h2>Compromissos em aberto (15 dias)</h2>
      <div class="chint">Próximos vencimentos · <a href="<?= $base ?>/financeiro/fluxo_caixa.php">fluxo de caixa</a></div>
      <?php if (!$vencimentos): ?>
        <div class="empty">Nenhum título vencendo nos próximos 15 dias. ✓</div>
      <?php else: ?>
      <table class="dt">
        <thead><tr><th>Vence</th><th>Título</th><th class="num">Valor</th></tr></thead>
        <tbody>
        <?php foreach ($vencimentos as $v): $rcv = $v['tipo'] === 'receber'; ?>
          <tr>
            <td><?= date('d/m', strtotime((string)$v['data_vencimento'])) ?></td>
            <td><?= h(mb_substr((string)$v['descricao'], 0, 30)) ?></td>
            <td class="num <?= $rcv ? 'tpos' : 'tneg' ?>"><?= $rcv ? '+' : '−' ?><?= numFmt((float)$v['valor'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="card c4" data-rise>
      <h2>Custo da Produção <?= $perAno ?> por categoria</h2>
      <div class="chint">Total R$ <?= numFmt($totCusteio, 2) ?> no ano · <a href="<?= $base ?>/custeio/custo_categoria.php">detalhe</a></div>
      <?php if ($chCusteio): ?><div id="chCusteio" class="chart" style="height:240px"></div>
      <?php else: ?><?= dash_empty('Nenhum custeio lançado no ano.', 'Lançar custeio', $base . '/custeio/custos') ?><?php endif; ?>
    </div>

    <div class="card c4" data-rise>
      <h2>Resultado Bruto × Líquido por safra</h2>
      <div class="chint"><a href="<?= $base ?>/custeio/resultado_safra.php">detalhe</a></div>
      <?php if (!$resultado): ?>
        <div class="empty">Nenhuma safra com faturamento ou custo.</div>
      <?php else: ?>
      <table class="dt">
        <thead><tr><th>Safra</th>
          <th class="num" title="Resultado Bruto = Faturamento − custeio operacional (SEM os descontos da lista, ex.: depreciação). Glossário T30.">Res. Bruto</th>
          <th class="num" title="Resultado Líquido = Bruto − descontos = Faturamento − custeio total. É o MESMO valor que o Executivo mostra no card de resultado. Glossário T30.">Res. Líquido</th>
          <th class="num">Margem líq.</th></tr></thead>
        <tbody>
        <?php foreach ($resultado as $r):
            $fat = (float)$r['faturamento'];
            $desc = $descPorSafra[(int)$r['id']] ?? 0.0;
            $bruto = $fat - ((float)$r['custo'] - $desc); /* custos operacionais (sem a lista) */
            $liq = $bruto - $desc;                        /* = faturamento − custo total */
            $mg = $fat > 0 ? $liq / $fat * 100 : null; ?>
          <tr>
            <td><?= h(vero_safra_rotulo((string)$r['safra'])) ?>
              <?= $desc > 0 ? '<span class="chint" style="display:block">desc. ' . numFmt($desc, 2) . '</span>' : '' ?></td>
            <td class="num <?= $bruto >= 0 ? 'tpos' : 'tneg' ?>"><?= numFmt($bruto, 2) ?></td>
            <td class="num <?= $liq >= 0 ? 'tpos' : 'tneg' ?>"><?= numFmt($liq, 2) ?></td>
            <td class="num"><?= $mg !== null ? numFmt($mg, 1) . '%' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </section>

  <!-- LINHA 4 — A3-T30: Caixa Projetado da Produção -->
  <section class="grid mt16">
    <div class="card c12" data-rise>
      <h2>Caixa Projetado da Produção — por safra</h2>
      <?php if (!$projProducao): ?>
        <div class="empty">Nenhuma safra com projeção — cadastre os parâmetros de cultura (motor F1) ou contratos de pré-venda.</div>
      <?php else: ?>
      <table class="dt">
        <thead><tr><th>Safra</th>
          <th class="num">Previsto (motor F1)</th>
          <th class="num">Pré-vendas contratadas</th>
          <th class="num">Estimativa da colheita</th>
          <th class="num">Faturado</th>
          <th class="num">A faturar (F1 − faturado)</th></tr></thead>
        <tbody>
        <?php foreach ($projProducao as $p):
            $f1 = (float)$p['receita_f1'];
            $aFaturar = $f1 > 0 ? $f1 - (float)$p['faturado'] : null; ?>
          <tr>
            <td><?= h(vero_safra_rotulo((string)$p['identificacao'])) ?></td>
            <td class="num"><?= $f1 > 0 ? numFmt($f1, 2) : '—' ?></td>
            <td class="num"><?= (float)$p['pre_vendas'] > 0 ? numFmt((float)$p['pre_vendas'], 2) : '—' ?></td>
            <td class="num"><?= (float)$p['proj_colheita'] > 0 ? numFmt((float)$p['proj_colheita'], 2) : '—' ?></td>
            <td class="num tpos"><?= numFmt((float)$p['faturado'], 2) ?></td>
            <td class="num <?= $aFaturar !== null && $aFaturar < 0 ? 'tneg' : '' ?>"><?= $aFaturar !== null ? numFmt($aFaturar, 2) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </section>

  <div class="kmodal" id="kmodal" role="dialog" aria-modal="true">
    <div class="kbox">
      <div class="khead">
        <div><h3 id="km-title"></h3><div class="kval" id="km-val"></div></div>
        <button class="kx" type="button" onclick="closeKpi()" aria-label="Fechar">×</button>
      </div>
      <div class="kbody">
        <div class="ksub" id="km-sub"></div>
        <div id="km-table"></div>
        <div class="ksub two" id="km-sub2" style="display:none"></div>
        <div id="km-table2"></div>
        <div class="knote" id="km-note" style="display:none"></div>
      </div>
    </div>
  </div>
 </div>
</div>

<script defer src="<?= $base ?>/assets/vendor/echarts/echarts.min.js"></script>
<script>
(function(){
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    || navigator.webdriver === true; /* OBS-B: automação/leitores veem o valor real */
  var DEX = <?= jsvar(['caixa' => $chCaixa, 'aging' => $chAging, 'custeio' => $chCusteio, 'C' => $C]) ?>;
  var C = DEX.C, MONO = "'IBM Plex Mono',ui-monospace,monospace";
  var brl = function(v){ return v==null ? '—' : v.toLocaleString('pt-BR',{style:'currency',currency:'BRL'}); };
  var brlK = function(v){ return v>=1000 ? 'R$ '+(v/1000).toLocaleString('pt-BR',{maximumFractionDigits:1})+' mil' : brl(v); };

  document.querySelectorAll('.dex [data-count]').forEach(function(el){
    var target = parseFloat(el.dataset.count) || 0;
    var render = function(v){ el.textContent = brl(v); };
    if(reduced){ render(target); return; }
    var t0=null, dur=1100;
    function step(ts){ if(!t0)t0=ts; var p=Math.min((ts-t0)/dur,1), e=1-Math.pow(1-p,3);
      render(target*e); if(p<1) requestAnimationFrame(step); else render(target); }
    requestAnimationFrame(step);
  });
  var dex = document.querySelector('.dex');
  if(reduced){ dex.classList.add('is-in'); }
  else { dex.querySelectorAll('[data-rise]').forEach(function(el,i){ el.style.transitionDelay=Math.min(i*60,600)+'ms'; });
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ dex.classList.add('is-in'); }); }); }

  /* ---- Modal de detalhamento por KPI ---- */
  var KDET = <?= jsvar($kpiDet) ?>;
  function tbl(cols, rows){
    if(!rows || !rows.length) return '<div style="text-align:center;color:#9A8C78;padding:14px;font-size:12.5px">Sem detalhamento disponível.</div>';
    var s = '<table class="dt"><thead><tr>' + cols.map(function(c,i){ return '<th'+(i>0?' class="num"':'')+'>'+c+'</th>'; }).join('') + '</tr></thead><tbody>';
    rows.forEach(function(r){ s += '<tr>' + r.map(function(c,i){ return '<td'+(i>0?' class="num"':'')+'>'+c+'</td>'; }).join('') + '</tr>'; });
    return s + '</tbody></table>';
  }
  function openKpi(id){
    var d = KDET[id]; if(!d) return;
    document.getElementById('km-title').textContent = d.titulo;
    document.getElementById('km-val').textContent = d.valor;
    document.getElementById('km-sub').textContent = d.sub || '';
    document.getElementById('km-table').innerHTML = tbl(d.cols, d.rows);
    var s2 = document.getElementById('km-sub2'), t2 = document.getElementById('km-table2');
    if(d.rows2){ s2.textContent = d.sub2 || ''; s2.style.display=''; t2.innerHTML = tbl(d.cols2, d.rows2); }
    else { s2.style.display='none'; t2.innerHTML=''; }
    var note = document.getElementById('km-note');
    if(d.note){ note.textContent = d.note; note.style.display=''; } else { note.style.display='none'; }
    document.getElementById('kmodal').classList.add('open');
  }
  window.closeKpi = function(){ document.getElementById('kmodal').classList.remove('open'); };
  document.querySelectorAll('.dex .kpi[data-kpi]').forEach(function(el){ el.addEventListener('click', function(){ openKpi(el.dataset.kpi); }); });
  document.getElementById('kmodal').addEventListener('click', function(e){ if(e.target.id==='kmodal') closeKpi(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeKpi(); });

  function __charts(){
  if(typeof echarts === 'undefined') return;
  var charts = [];
  var base = { textStyle:{fontFamily:'inherit', color:C.muted},
    tooltip:{ backgroundColor:'#fff', borderColor:C.border, textStyle:{color:'#241B14', fontSize:12},
      extraCssText:'box-shadow:0 8px 24px -12px rgba(8,38,42,.35);border-radius:9px;' },
    grid:{left:8, right:16, top:34, bottom:6, containLabel:true},
    animationDuration: reduced?0:800, animationEasing:'cubicOut' };
  var axCat = {axisLine:{lineStyle:{color:C.border}}, axisTick:{show:false}, axisLabel:{color:C.muted, fontSize:11.5}};
  var axVal = {splitLine:{show:false}, axisLabel:{color:C.muted, fontSize:11, fontFamily:MONO}};
  function mk(id, opt){ var el=document.getElementById(id); if(!el) return; var ch=echarts.init(el,null,{renderer:'canvas'}); ch.setOption(opt); charts.push(ch); }

  /* 1. Caixa realizado × projetado (barras entradas/saídas + linha acumulada) */
  if(DEX.caixa){ var k = DEX.caixa;
    mk('chCaixa', Object.assign({}, base, {
      legend:{top:0, right:0, itemWidth:11, itemHeight:11, textStyle:{fontSize:11.5, color:C.muted}},
      tooltip:Object.assign({}, base.tooltip, {trigger:'axis', valueFormatter:brl}),
      xAxis:Object.assign({type:'category', data:k.labels}, axCat),
      yAxis:[ Object.assign({type:'value'}, axVal, {axisLabel:{color:C.muted, fontSize:11, fontFamily:MONO, formatter:brlK}}),
              {type:'value', splitLine:{show:false}, axisLabel:{color:C.a3, fontSize:10.5, fontFamily:MONO, formatter:brlK}} ],
      series:[
        {name:'Entradas', type:'bar', yAxisIndex:0, data:k.entr, barWidth:16, itemStyle:{color:C.pos, borderRadius:[5,5,0,0]}},
        {name:'Saídas', type:'bar', yAxisIndex:0, data:k.sai, barWidth:16, itemStyle:{color:C.danger, borderRadius:[5,5,0,0]}},
        {name:'Acumulado', type:'line', yAxisIndex:1, data:k.acum, symbol:'circle', symbolSize:7,
         lineStyle:{color:C.accent, width:2.5}, itemStyle:{color:C.accent, borderColor:'#fff', borderWidth:2},
         areaStyle:{color:{type:'linear',x:0,y:0,x2:0,y2:1,colorStops:[{offset:0,color:'rgba(0,80,89,.10)'},{offset:1,color:'rgba(0,80,89,0)'}]}}}
      ]
    }));
  }
  /* 2. Aging (barras horizontais a receber × a pagar) */
  if(DEX.aging){ var a = DEX.aging;
    mk('chAging', Object.assign({}, base, {
      legend:{top:0, right:0, itemWidth:11, itemHeight:11, textStyle:{fontSize:11.5, color:C.muted}},
      tooltip:Object.assign({}, base.tooltip, {trigger:'axis', axisPointer:{type:'shadow'}, valueFormatter:brl}),
      xAxis:Object.assign({type:'value'}, axVal, {axisLabel:{color:C.muted, fontSize:10.5, fontFamily:MONO, formatter:function(v){return 'R$ '+v;}}}),
      yAxis:Object.assign({type:'category', data:a.buckets}, axCat),
      series:[
        {name:'A receber', type:'bar', data:a.receber, barWidth:12, itemStyle:{color:C.pos, borderRadius:4}},
        {name:'A pagar', type:'bar', data:a.pagar, barWidth:12, itemStyle:{color:C.danger, borderRadius:4}}
      ]
    }));
  }
  /* 3. Custeio (donut com rótulo central) */
  if(DEX.custeio){
    var items = DEX.custeio.items, total = items.reduce(function(s,it){return s+it.value;},0);
    var centerLbl = function(val,name){ return { text:val, subtext:name, left:'50%', top:'37%', textAlign:'center',
      textStyle:{fontFamily:MONO, fontSize:16, fontWeight:700, color:C.deep}, subtextStyle:{fontSize:11, color:C.muted} }; };
    var chC = echarts.init(document.getElementById('chCusteio'), null, {renderer:'canvas'});
    chC.setOption(Object.assign({}, base, {
      tooltip:Object.assign({}, base.tooltip, {trigger:'item', valueFormatter:brl}),
      legend:{bottom:0, itemWidth:11, itemHeight:11, textStyle:{fontSize:11, color:C.muted}},
      title: centerLbl(brlK(total), 'Total'),
      series:[{ type:'pie', radius:['54%','76%'], center:['50%','42%'], avoidLabelOverlap:true, padAngle:2,
        itemStyle:{borderRadius:6, borderColor:'#fff', borderWidth:2}, label:{show:false}, emphasis:{scaleSize:6, label:{show:false}},
        data: items.map(function(it){ return {value:it.value, name:it.name, itemStyle:{color:it.color}}; }) }]
    }));
    chC.on('mouseover', function(p){ if(p.seriesType==='pie') chC.setOption({title: centerLbl(brl(p.value), p.name+' · '+p.percent+'%')}); });
    chC.on('mouseout', function(){ chC.setOption({title: centerLbl(brlK(total), 'Total')}); });
    charts.push(chC);
  }
  window.addEventListener('resize', function(){ charts.forEach(function(c){ c.resize(); }); });
  }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', __charts); else __charts();
})();
</script>
