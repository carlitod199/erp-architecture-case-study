<?php
/* ============================================================
   VERO — Dashboard Executivo · redesenho A4-04 (design docs/
   dashboard_executivo_vero.html) ligado a DADOS REAIS.
   DEFAULT de dashboard/dashboard_executivo.php (?classico=1 = render
   antigo). Reusa as variáveis já calculadas pela tela + queries de
   leitura próprias (sinalizadas). Regra 1/D5 intactas (só lê/plota):
   - degradação por estado (dash_mode/dash_empty) preservada;
   - badge PARCIAL (D5) por rateio pendente preservado;
   - NENHUMA métrica inventada — lacunas viram itens de AUDITORIA
     computados de condição real, nunca número fake.
   Assets: ECharts vendor LOCAL (rede rural), animações em JS puro
   (sem GSAP), fonte da stack VERO. CSS escopado em .dex.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/_dash.php'; /* regras de degradação por estado (R1) */

/* QA-006 — blindagem de $alertas reusada da tela (no-op em produção). */
$alertas = $alertas ?? [];

/* ── Paleta (espelha o design; usada também nas configs ECharts) ── */
$C = ['accent' => '#005059', 'deep' => '#00363D', 'a3' => '#2A767C', 'olive' => '#4E9CA1',
      'pos' => '#0E7E72', 'amber' => '#B57C1A', 'danger' => '#B23A2E',
      'track' => '#EEE6D6', 'border' => '#E3D9C8', 'muted' => '#8A7C68'];

/* ── Custo por hectare (dado real A3×A1): custeio ÷ área plantada ── */
$areaRows = vero_rows(
    "SELECT COALESCE(SUM(st.area_plantada_ha), 0) AS ha
       FROM agro_safra_talhoes st JOIN agro_talhoes tl ON tl.id = st.talhao_id
      WHERE st.tenant_id = :t AND st.safra_id = :s" . ($fTalhao ? ' AND tl.id = :tl' : ''),
    $fTalhao ? [':t' => $t, ':s' => $fSafra, ':tl' => $fTalhao] : [':t' => $t, ':s' => $fSafra]);
$areaPlantada = (float)($areaRows[0]['ha'] ?? 0);
$custoHa = $areaPlantada > 0 ? $custoSafraTotal / $areaPlantada : null;
$receitaHa = $areaPlantada > 0 ? $faturamento / $areaPlantada : null;
$kgHa = $areaPlantada > 0 ? (float)$producao['realizado'] / $areaPlantada : null;

/* ── D5: custo PARCIAL por rateio pendente (indiretos sem válvula) ── */
$indiretoNaoRateado = (float)vero_val(
    "SELECT COALESCE(SUM(valor), 0) FROM custeio_lancamentos
      WHERE tenant_id = :t AND talhao_id IS NULL AND (safra_id = :s OR safra_id IS NULL)",
    [':t' => $t, ':s' => $fSafra]);
$rateioPendente = $indiretoNaoRateado > 0.005;
$parcialBadge = $rateioPendente
    ? dash_parcial('Rateio/fechamento pendente: R$ ' . numFmt($indiretoNaoRateado, 2)
        . ' em custos indiretos (ex.: depreciação) ainda sem válvula — o custo por válvula/ha não fecha com o total.',
        '◐ parcial')
    : '';

/* ── R3: DF/IF pendentes e carências ativas (nascem zerados) ── */
$dfifPendentes = (int)vero_val(
    "SELECT COUNT(*) FROM agro_aplicacoes WHERE tenant_id = :t AND doc_numero IS NOT NULL AND status = 'emitida'", [':t' => $t]);
$carenciasAtivas = (int)vero_val(
    "SELECT COUNT(*) FROM agro_alertas WHERE tenant_id = :t AND categoria IN ('residuo','carencia') AND status = 'aberto'", [':t' => $t]);

/* ── Metas da safra (dormente sem cadastro) ── */
$metas = [];
foreach (vero_rows("SELECT indicador, valor_meta FROM gestao_metas WHERE tenant_id = :t AND safra_id = :s",
        [':t' => $t, ':s' => $fSafra]) as $mt) $metas[(string)$mt['indicador']] = (float)$mt['valor_meta'];

/* ── Vendas: contagem + kg (faturamento/precoMedio já vêm da tela) ── */
$vendasCount = (int)vero_val(
    "SELECT COUNT(*) FROM comercial_vendas WHERE tenant_id = :t AND safra_id = :s AND status <> 'cancelada'",
    [':t' => $t, ':s' => $fSafra]);
$vendasKg = (float)$vendasSafra['kg'];

/* ── Faturamento BRUTO × LÍQUIDO (deduções de comercialização) ──
   bruto = SUM(valor_total) [=$faturamento]; deduções = Σ comercial_venda_despesas
   das vendas da safra (frete/comissão/imposto/taxa/embalagem). Glossário canônico
   (comercial/_despesas.php, P-112): margem líquida = receita − CPV − Σ despesas.
   SEM despesa cadastrada → líquido = bruto (NÃO inventa dedução). */
$fatBruto   = $faturamento;
$deducoes   = (float)vero_val(
    "SELECT COALESCE(SUM(d.valor), 0) FROM comercial_venda_despesas d
       JOIN comercial_vendas v ON v.id = d.venda_id
      WHERE d.tenant_id = :t AND v.safra_id = :s AND v.status <> 'cancelada'",
    [':t' => $t, ':s' => $fSafra]);
$fatLiquido = $fatBruto - $deducoes;
$deducoesCat = vero_rows(
    "SELECT COALESCE(td.nome, d.descricao, 'Despesa') AS nome, SUM(d.valor) AS tot
       FROM comercial_venda_despesas d
       JOIN comercial_vendas v ON v.id = d.venda_id
       LEFT JOIN comercial_tipos_despesa td ON td.id = d.tipo_despesa_id
      WHERE d.tenant_id = :t AND v.safra_id = :s AND v.status <> 'cancelada'
      GROUP BY COALESCE(td.nome, d.descricao, 'Despesa') ORDER BY tot DESC",
    [':t' => $t, ':s' => $fSafra]);

/* ══════════════ GRÁFICO 1 — Faturamento por variedade ══════════════
   receita (valor_total) por variedade. Vínculo: venda → colheita_registro.
   variedade_id (preferencial) ou venda → talhão.variedade_id (fallback).
   Vendas sem variedade caem em "Sem variedade informada" (não inventa). */
$fatVarRows = vero_rows(
    "SELECT COALESCE(av.nome, 'Sem variedade informada') AS nome, SUM(v.valor_total) AS fat
       FROM comercial_vendas v
       LEFT JOIN colheita_registros cr ON cr.id = v.colheita_registro_id
       LEFT JOIN agro_talhoes tl ON tl.id = v.talhao_id
       LEFT JOIN agro_variedades av ON av.id = COALESCE(cr.variedade_id, tl.variedade_id)
      WHERE v.tenant_id = :t AND v.safra_id = :s AND v.status <> 'cancelada'" . ($fTalhao ? " AND v.talhao_id = :tl" : "") . "
      GROUP BY COALESCE(av.id, 0), nome ORDER BY fat DESC",
    [':t' => $t, ':s' => $fSafra] + $pTal);
$chVar = $fatVarRows ? [
    'cats' => array_map(fn($r) => (string)$r['nome'], $fatVarRows),
    'vals' => array_map(fn($r) => round((float)$r['fat'], 2), $fatVarRows),
] : null;
$varSemVinculo = $fatVarRows && count($fatVarRows) === 1 && $fatVarRows[0]['nome'] === 'Sem variedade informada';

/* ══════════════ GRÁFICO 2 — Custo × Faturado por safra ══════════════
   por safra do tenant: custo (custeio_lancamentos) × faturamento
   (comercial_vendas). Comparação inerentemente multi-safra → escopo do tenant
   (como "custo ao longo dos anos"). Custos SEM safra (rateio pendente) ficam de
   fora — já sinalizados na nota do card de custo/resultado. */
$custoFatSafra = vero_rows(
    "SELECT s.identificacao AS safra,
            COALESCE((SELECT SUM(cl.valor) FROM custeio_lancamentos cl
                        WHERE cl.tenant_id = :t1 AND cl.safra_id = s.id), 0) AS custo,
            COALESCE((SELECT SUM(v.valor_total) FROM comercial_vendas v
                        WHERE v.tenant_id = :t2 AND v.safra_id = s.id AND v.status <> 'cancelada'), 0) AS fat
       FROM agro_safras s WHERE s.tenant_id = :t3
      HAVING custo > 0 OR fat > 0 ORDER BY s.identificacao", /* QA-011/HY093: :t distintos */
    [':t1' => $t, ':t2' => $t, ':t3' => $t]);
$chSafra = $custoFatSafra ? [
    'cats'  => array_map(fn($r) => vero_safra_rotulo((string)$r['safra']), $custoFatSafra), /* P-04: rótulo curto */
    'custo' => array_map(fn($r) => round((float)$r['custo'], 2), $custoFatSafra),
    'fat'   => array_map(fn($r) => round((float)$r['fat'], 2), $custoFatSafra),
] : null;

/* Rateio por válvula — usado só no modal do KPI de custo (o gráfico saiu). */
$custoTalhao = vero_rows(
    "SELECT tl.codigo AS talhao, SUM(cl.valor) AS tot
       FROM custeio_lancamentos cl JOIN agro_talhoes tl ON tl.id = cl.talhao_id
      WHERE cl.tenant_id = :t AND cl.safra_id = :s AND cl.talhao_id IS NOT NULL"
      . ($fTalhao ? ' AND cl.talhao_id = :tl' : '')
      . " GROUP BY tl.id, tl.codigo ORDER BY tot DESC LIMIT 10",
    $fTalhao ? [':t' => $t, ':s' => $fSafra, ':tl' => $fTalhao] : [':t' => $t, ':s' => $fSafra]);

/* ══════════════ GRÁFICO 3 — Classificação de custo por categoria (barras verticais) ══════════════ */
$chClasse = $custeioCat ? [
    'cats' => array_map(fn($c) => $rotuloCat((string)$c['categoria']), $custeioCat),
    'vals' => array_map(fn($c) => round((float)$c['total'], 2), $custeioCat),
] : null;

/* ══════════════ GRÁFICO 4 — Custo de produção ao longo dos anos (linha) ══════════════
   custeio total por ano de competência (escopo tenant, todas as safras). */
$custoAnoRows = vero_rows(
    "SELECT YEAR(data_competencia) AS ano, SUM(valor) AS tot
       FROM custeio_lancamentos WHERE tenant_id = :t AND data_competencia IS NOT NULL
      GROUP BY ano ORDER BY ano", [':t' => $t]);
$chAnos = $custoAnoRows ? [
    'cats' => array_map(fn($r) => (string)$r['ano'], $custoAnoRows),
    'vals' => array_map(fn($r) => round((float)$r['tot'], 2), $custoAnoRows),
] : null;

$resCls = $resultado >= 0 ? 'k-pos' : 'k-danger';
$posLiquida = (float)$posicao['receber_aberto'] - (float)$posicao['pagar_aberto'];

/* ══════════════ DETALHAMENTO (modal) POR KPI — tudo dado real ══════════════ */
$brl2 = static fn($v) => 'R$ ' . numFmt((float)$v, 2);
$vendasList = vero_rows(
    "SELECT numero, data_venda, kg_total, valor_total FROM comercial_vendas
      WHERE tenant_id = :t AND safra_id = :s AND status <> 'cancelada' ORDER BY data_venda DESC, id DESC LIMIT 30",
    [':t' => $t, ':s' => $fSafra]);
$titReceber = vero_rows(
    "SELECT descricao, valor, data_vencimento FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND tipo='receber' AND status='aberto' ORDER BY data_vencimento LIMIT 20", [':t' => $t]);
$titPagar = vero_rows(
    "SELECT descricao, valor, data_vencimento FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND tipo='pagar' AND status='aberto' ORDER BY data_vencimento LIMIT 20", [':t' => $t]);

$dv = static fn($s) => $s ? date('d/m/Y', strtotime((string)$s)) : '—';
$kpiDet = [
    'faturamento' => [
        'titulo' => 'Faturamento bruto', 'valor' => $brl2($fatBruto),
        'sub' => $vendasCount . ' venda(s) · ' . numFmt($vendasKg, 0) . ' kg' . ($precoMedio !== null ? ' · preço médio ' . $brl2($precoMedio) . '/kg' : ''),
        'cols' => ['Venda', 'kg', 'Valor'],
        'rows' => array_map(static fn($v) => [h(($v['numero'] ?: '—') . ' · ' . $dv($v['data_venda'])), numFmt((float)$v['kg_total'], 0), numFmt((float)$v['valor_total'], 2)], $vendasList),
    ],
    'faturamento_liquido' => [
        'titulo' => 'Faturamento líquido', 'valor' => $brl2($fatLiquido),
        'sub' => $deducoes > 0.005
            ? 'Bruto − deduções de comercialização das vendas da safra'
            : 'Sem deduções cadastradas nas vendas — líquido = bruto',
        'cols' => ['Item', 'R$'],
        'rows' => array_merge(
            [['Faturamento bruto', '+' . numFmt($fatBruto, 2)]],
            array_map(static fn($d) => [h((string)$d['nome']), '−' . numFmt((float)$d['tot'], 2)], $deducoesCat),
            [['Faturamento líquido', numFmt($fatLiquido, 2)]]
        ),
        /* Regra 1/D5: sem dedução cadastrada, o líquido iguala o bruto — não se
           fabrica imposto/frete. As deduções (quando houver) vêm do glossário
           canônico da venda (comercial/_despesas.php, comercial_venda_despesas). */
        'note' => $deducoes > 0.005 ? '' : 'Deduções de venda (frete, comissão, embalagem, imposto, taxa) '
            . 'vêm de Comercial → Despesas da venda (comercial_venda_despesas). Nenhuma cadastrada para as '
            . 'vendas desta safra, então o líquido iguala o bruto — nada é fabricado.',
    ],
    'custo' => [
        'titulo' => 'Custo de Produção', 'valor' => $brl2($custoSafraTotal),
        'sub' => 'Custeio real da safra — por categoria' . ($custoHa !== null ? ' · ' . $brl2($custoHa) . '/ha' : ''),
        'cols' => ['Categoria', 'R$'],
        'rows' => array_map(static fn($c) => [h($rotuloCat((string)$c['categoria'])), numFmt((float)$c['total'], 2)], $custeioCat),
        'sub2' => 'Rateio por válvula',
        'cols2' => ['Válvula', 'R$'],
        'rows2' => array_map(static fn($r) => [h((string)$r['talhao']), numFmt((float)$r['tot'], 2)], $custoTalhao),
        'note' => $rateioPendente ? '◐ + ' . $brl2($indiretoNaoRateado) . ' de indiretos sem válvula (ex.: depreciação, máquina) — rateio contábil pendente no fechamento.' : '',
    ],
    'resultado' => [
        /* P02 (auditoria 20/07): padronização de glossário com o Financeiro (T30).
           Este número é Faturamento − custeio TOTAL da safra (inclui descontos/
           depreciação) = Resultado LÍQUIDO na semântica CONAB/T30 — é o MESMO que
           o Financeiro rotula "Res. Líquido". (Bruto = Faturamento − custeio
           operacional, i.e., SEM os descontos.) */
        'titulo' => 'Resultado líquido', 'valor' => $brl2($resultado),
        'sub' => 'Faturamento − custeio total da safra (inclui descontos/depreciação)',
        'cols' => ['Item', 'R$'],
        'rows' => [['Faturamento', '+' . numFmt($faturamento, 2)], ['Custo de Produção', '−' . numFmt($custoSafraTotal, 2)],
                   ['Resultado líquido', numFmt($resultado, 2)], ['Margem líquida', ($margem !== null ? numFmt($margem, 1) . '%' : '—')]],
        /* R2-04: o que o resultado NÃO enxerga são os custos SEM SAFRA (soma
           líquida P-07) — Σ resultados − pendências = DRE; rateio manual (P-125) */
        'note' => $pendSemSafra > 0.005 ? 'Resultado ainda sem ' . $pendFmt . ' de custos pendentes de rateio (não alocados '
            . 'a safras — mão de obra/máquinas/combustível): Σ resultados das safras − pendências = DRE. Rateio manual no fechamento.' : '',
    ],
    'receita_ha' => [
        'titulo' => 'Receita por hectare', 'valor' => $receitaHa !== null ? $brl2($receitaHa) : '—',
        'sub' => 'Faturamento ÷ área plantada da safra',
        'cols' => ['Item', 'Valor'],
        'rows' => [['Faturamento', $brl2($faturamento)], ['Área plantada', numFmt($areaPlantada, 2) . ' ha'],
                   ['Receita / ha', $receitaHa !== null ? $brl2($receitaHa) : '—'], ['Colheita / ha', $kgHa !== null ? numFmt($kgHa, 0) . ' kg/ha' : '—']],
    ],
    'posicao' => [
        'titulo' => 'Posição em aberto (consolidado do tenant)', 'valor' => $brl2($posLiquida),
        'sub' => 'A receber − a pagar (títulos em aberto do razão) · consolidado do tenant, não filtra safra/fazenda',
        'cols' => ['Título', 'Vencimento', 'Valor'],
        'rows' => array_merge(
            array_map(static fn($r) => [h(mb_substr((string)$r['descricao'], 0, 40) ?: 'A receber'), $dv($r['data_vencimento']), '+' . numFmt((float)$r['valor'], 2)], $titReceber),
            array_map(static fn($r) => [h(mb_substr((string)$r['descricao'], 0, 40) ?: 'A pagar'), $dv($r['data_vencimento']), '−' . numFmt((float)$r['valor'], 2)], $titPagar)
        ),
    ],
];
?>
<style>
/* ===== Dashboard Executivo (A4-04) — TUDO escopado em .dex ===== */
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
.dex .dbrand{font-size:24px; font-weight:700; letter-spacing:-.02em; color:var(--acd)}
.dex .dbrand b{color:#00B49D}
.dex .dh1{font-size:19px; font-weight:700; color:var(--ink2); margin-top:2px}
.dex .dsub{font-size:12px; color:var(--mut); margin-top:2px}
.dex .dstamp{font-size:11px; color:var(--mut2); text-align:right}
.dex .dstamp b{font-family:var(--num)}
.dex .dtoolbar{display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap}
.dex .dtoolbar select{font:inherit; font-size:13px; font-weight:500; color:var(--ink); background:var(--surface);
  border:1px solid var(--bd); border-radius:var(--r); padding:8px 12px; min-width:150px; cursor:pointer; outline:none}
.dex .dtoolbar select:focus-visible{border-color:var(--ac); box-shadow:0 0 0 3px rgba(0,80,89,.12)}
.dex .dflash{display:flex; gap:10px; align-items:flex-start; background:var(--amber-bg); border:1px solid #E4CE96;
  border-radius:var(--r); padding:10px 14px; margin:12px 0 18px; font-size:12.5px; color:var(--amber-d)}
.dex .dflash.ok{background:var(--pos-bg); border-color:#B9DAD5; color:#0B5F55}
.dex .dflash b{font-weight:700}
/* KPIs */
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
/* grid de cards */
.dex .grid{display:grid; grid-template-columns:repeat(12,1fr); gap:16px}
.dex .card{background:rgba(255,255,255,.66); border:1px solid rgba(227,217,200,.9); border-radius:var(--rc); padding:16px 18px; min-width:0;
  transition:box-shadow .25s, border-color .25s}
.dex .card:hover{border-color:var(--bd2); box-shadow:0 8px 22px -16px rgba(8,38,42,.25)}
.dex .card h2{font-size:13.5px; font-weight:700; color:var(--ink2)}
.dex .card .chint{font-size:11.5px; color:var(--mut2); margin:1px 0 10px}
.dex .c6{grid-column:span 6} .dex .c4{grid-column:span 4} .dex .c8{grid-column:span 8} .dex .c12{grid-column:span 12}
.dex .chart{width:100%; height:280px}
.dex .empty{padding:26px 14px; text-align:center; color:var(--mut2); font-size:13px}
.dex .empty a{font-weight:600}
/* tabelas */
.dex table.dt{width:100%; border-collapse:collapse; font-size:13px}
.dex .dt th{text-align:left; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.04em;
  color:var(--mut); background:var(--warm); padding:9px 12px; border-bottom:1px solid var(--bd)}
.dex .dt td{padding:9px 12px; border-bottom:1px solid var(--track); vertical-align:middle}
.dex .dt tr:last-child td{border-bottom:none}
.dex .dt tbody tr:hover{background:var(--warm)}
.dex .dt .num{font-family:var(--num); font-weight:600; text-align:right; white-space:nowrap}
.dex .dt th.num{text-align:right}
.dex .vb{display:inline-block; font-size:11px; font-weight:600; border-radius:var(--rs); padding:2px 9px; white-space:nowrap}
.dex .vb-ok{background:var(--pos-bg); color:var(--pos)} .dex .vb-warn{background:var(--amber-bg); color:var(--amber-d)}
.dex .vb-danger{background:var(--danger-bg); color:var(--danger)} .dex .vb-info{background:#E2ECED; color:var(--ac)} .dex .vb-off{background:var(--track); color:var(--mut)}
/* alertas */
.dex .alist{display:flex; flex-direction:column; gap:9px}
.dex .aitem{display:flex; gap:10px; align-items:flex-start; padding:10px 12px; border:1px solid var(--track);
  border-left-width:3px; border-radius:var(--r); background:var(--warm); transition:transform .2s}
.dex .aitem:hover{transform:translateX(3px)}
.dex .aitem.sev-critico{border-left-color:var(--danger)} .dex .aitem.sev-atencao{border-left-color:var(--amber)} .dex .aitem.sev-info{border-left-color:var(--ac3)}
.dex .aitem .at{font-weight:600; font-size:13px; color:var(--ink2)}
.dex .aitem .ad{font-size:12px; color:var(--mut); margin-top:1px}
.dex .adot{width:8px; height:8px; border-radius:50%; margin-top:6px; flex:none}
.dex .adot.d-critico{background:var(--danger)} .dex .adot.d-atencao{background:var(--amber)} .dex .adot.d-info{background:var(--ac3)}
/* auditoria */
.dex .agrid{display:grid; grid-template-columns:1fr 1fr; gap:9px}
.dex .aud{padding:10px 12px; border:1px solid var(--track); border-radius:var(--r); background:var(--warm); font-size:12.5px}
.dex .aud b{display:block; font-size:12px; color:var(--ink2)} .dex .aud em{font-style:normal; color:var(--mut)}
.dex .dfoot{margin-top:22px; font-size:11px; color:var(--mut2); text-align:center}
.dex .mt16{margin-top:16px}
@media (max-width:1080px){ .dex .kpis{grid-template-columns:repeat(3,1fr)} .dex .c6,.dex .c4,.dex .c8{grid-column:span 12} }
@media (max-width:680px){ .dex{padding:16px 12px 40px} .dex .kpis{grid-template-columns:repeat(2,1fr)} .dex .agrid{grid-template-columns:1fr} .dex .kpi{padding:12px 12px 10px} }
@media (max-width:440px){ .dex .kpis{grid-template-columns:1fr} }
/* anima de entrada (JS puro) — respeitando reduced-motion */
/* entrada só de deslize — nunca esconde o conteúdo (sem flicker no load) */
.dex [data-rise]{animation:dexrise .4s ease both}
@keyframes dexrise{from{transform:translateY(10px)} to{transform:none}}
@media (prefers-reduced-motion:reduce){ .dex [data-rise]{animation:none} }
/* KPI clicável → modal de detalhamento por rateio */
.dex .kpi[data-kpi]{cursor:pointer}
.dex .kpi[data-kpi]::before{content:"detalhar ›"; position:absolute; top:10px; right:12px; font-size:9.5px; font-weight:600;
  letter-spacing:.03em; color:var(--mut2); opacity:0; transition:opacity .2s}
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

  <!-- TOPO -->
  <header class="dtop" data-rise>
    <div class="dtoolbar">
      <form method="get" style="display:flex;gap:8px;align-items:flex-end">
        <select name="safra" aria-label="Safra" onchange="this.form.submit()">
          <?php foreach ($safras as $s): ?>
            <option value="<?= (int)$s['id'] ?>"<?= $fSafra === (int)$s['id'] ? ' selected' : '' ?>><?= h($safraRot[(int)$s['id']] ?? $s['identificacao']) ?> (<?= h((string)$s['status']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <select name="talhao" aria-label="Válvula" onchange="this.form.submit()">
          <option value="0">Todas as válvulas</option>
          <?php foreach ($talhoes as $tid => $tcod): ?>
            <option value="<?= (int)$tid ?>"<?= $fTalhao === (int)$tid ? ' selected' : '' ?>><?= h($tcod) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </header>

  <!-- KPIs — OBS-B (R2): valor FINAL renderizado no HTML; o count-up do JS é
       só enhancement (sem JS/automação o número certo já está na página) -->
  <section class="kpis">
    <div class="kpi" data-rise data-kpi="faturamento">
      <div class="kl">Faturamento bruto</div>
      <div class="kv" data-count="<?= $fatBruto ?>" data-fmt="brl">R$ <?= numFmt($fatBruto, 2) ?></div>
      <div class="ks"><?= $vendasCount ?> venda(s) · <?= numFmt($vendasKg, 0) ?> kg</div>
      <?php if ($precoMedio !== null): ?><div class="kd up">R$ <?= numFmt($precoMedio, 2) ?>/kg médio</div><?php endif; ?>
    </div>
    <div class="kpi" data-rise data-kpi="faturamento_liquido"
         title="Faturamento líquido = bruto − deduções de comercialização da venda (frete, comissão, embalagem, imposto, taxa — comercial_venda_despesas). Sem dedução cadastrada, líquido = bruto.">
      <div class="kl">Faturamento líquido</div>
      <div class="kv" data-count="<?= $fatLiquido ?>" data-fmt="brl">R$ <?= numFmt($fatLiquido, 2) ?></div>
      <div class="ks"><?= $deducoes > 0.005 ? '− R$ ' . numFmt($deducoes, 2) . ' em deduções' : 'sem deduções cadastradas' ?></div>
      <?php if ($deducoes > 0.005 && $fatBruto > 0): ?><div class="kd down">− <?= numFmt($deducoes / $fatBruto * 100, 1) ?>% do bruto</div>
      <?php else: ?><div class="kd up">= bruto</div><?php endif; ?>
    </div>
    <div class="kpi" data-rise data-kpi="custo"
         title="Custo de Produção da SAFRA SELECIONADA (custeio real lançado nesta safra). Os gráficos abaixo de custo por safra e ao longo dos anos são CONSOLIDADOS do tenant (todas as safras) — por isso podem mostrar valor mesmo quando esta safra ainda não tem custo lançado.">
      <div class="kl">Custo de Produção <span style="font-weight:500;text-transform:none;letter-spacing:0;color:var(--mut2)">(safra selecionada)</span></div>
      <div class="kv" data-count="<?= $custoSafraTotal ?>" data-fmt="brl">R$ <?= numFmt($custoSafraTotal, 2) ?></div>
      <div class="ks"><?= $custoHa !== null ? 'R$ ' . numFmt($custoHa, 2) . '/ha' : 'sem área plantada' ?></div>
      <?php if ($rateioPendente): ?><div class="kd warn">R$ <?= numFmt($indiretoNaoRateado, 0) ?> sem rateio</div><?php endif; ?>
    </div>
    <div class="kpi <?= $resCls ?>" data-rise data-kpi="resultado"
         title="Resultado líquido = Faturamento − custeio total da safra (inclui descontos/depreciação). Mesma definição do 'Res. Líquido' do Financeiro. Bruto = Faturamento − custeio operacional (sem descontos).">
      <div class="kl">Resultado líquido</div>
      <div class="kv" data-count="<?= $resultado ?>" data-fmt="brl">R$ <?= numFmt($resultado, 2) ?></div>
      <div class="ks"><?= $margem !== null ? 'Margem líquida ' . numFmt($margem, 1) . '%' : 'sem vendas na safra' ?></div>
      <?php /* R2-04: valor sem safra fora do resultado até o rateio manual (P-125); P-75 mascara sem o proxy financeiro */ ?>
      <?php if ($pendSemSafra > 0.005): ?><div class="kd warn">+ <?= $pendFmt ?> pendentes de rateio</div><?php endif; ?>
    </div>
    <div class="kpi" data-rise data-kpi="receita_ha">
      <div class="kl">Receita / hectare</div>
      <div class="kv" data-count="<?= $receitaHa ?? 0 ?>" data-fmt="brl">R$ <?= numFmt($receitaHa ?? 0.0, 2) ?></div>
      <div class="ks"><?= $custoHa !== null ? 'Custo/ha: R$ ' . numFmt($custoHa, 2) : '—' ?></div>
      <?php if ($kgHa !== null): ?><div class="kd up"><?= numFmt($kgHa, 0) ?> kg/ha efetivos</div><?php endif; ?>
    </div>
    <div class="kpi <?= $posLiquida < 0 ? 'k-warn' : '' ?>" data-rise data-kpi="posicao"
         title="Posição do razão (a receber − a pagar em aberto). É consolidada do tenant inteiro — não filtra por safra/fazenda, ao contrário dos demais cards.">
      <div class="kl">Posição em aberto <span style="font-weight:500;text-transform:none;letter-spacing:0;color:var(--mut2)">(consolidado do tenant)</span></div>
      <div class="kv" data-count="<?= $posLiquida ?>" data-fmt="brl">R$ <?= numFmt($posLiquida, 2) ?></div>
      <div class="ks">A receber <?= numFmt((float)$posicao['receber_aberto'], 0) ?> − a pagar <?= numFmt((float)$posicao['pagar_aberto'], 0) ?></div>
      <?php if ((int)$posicao['venc_15d'] > 0): ?><div class="kd warn"><?= (int)$posicao['venc_15d'] ?> título(s) vencendo em 15d</div><?php endif; ?>
    </div>
  </section>

  <!-- LINHA 2: DIAGNÓSTICO -->
  <section class="grid">
    <div class="card c6" data-rise>
      <h2>Faturamento por variedade — <?= $safraSel ? h($safraSelRot) : 'safra' ?></h2>
      <div class="chint"><?= $varSemVinculo
          ? 'Vendas sem variedade vinculada (colheita/talhão sem variedade cadastrada) — total em "Sem variedade informada".'
          : 'Receita das vendas da safra agrupada por variedade.' ?></div>
      <?php if ($chVar): ?><div id="chVar" class="chart"></div>
      <?php else: ?><?= dash_empty('Sem vendas nesta safra.', 'Registrar venda', $base . '/comercial/vendas') ?><?php endif; ?>
    </div>
    <div class="card c6" data-rise>
      <h2>Custo × Faturado por safra</h2>
      <?php if ($chSafra): ?><div id="chSafra" class="chart"></div>
      <?php else: ?><?= dash_empty('Sem custo ou faturamento por safra.', 'Ver custeio', $base . '/custeio/custos') ?><?php endif; ?>
    </div>
    <div class="card c6 mt16" data-rise>
      <h2>Classificação de custo por categoria</h2>
      <div class="chint">Total R$ <?= numFmt($custoSafraTotal, 2) ?> · realizado por categoria · <strong>safra selecionada</strong>.</div>
      <?php if ($chClasse): ?><div id="chClasse" class="chart"></div>
      <?php else: ?><?= dash_empty('Nenhum custo lançado nesta safra.', 'Lançar custeio', $base . '/custeio/custos') ?><?php endif; ?>
    </div>
    <div class="card c6 mt16" data-rise>
      <h2>Custo de produção ao longo dos anos</h2>
      <?php if ($chAnos): ?><div id="chAnos" class="chart"></div>
      <?php else: ?><?= dash_empty('Sem custeio com data de competência.', 'Lançar custeio', $base . '/custeio/custos') ?><?php endif; ?>
    </div>
  </section>

  <div class="dfoot">VERO · Dashboard Executivo</div>

  <!-- Modal de detalhamento por KPI (rateio real do custeio) -->
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
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var DEX = <?= jsvar([
      'variedade' => $chVar, 'safra' => $chSafra, 'classe' => $chClasse, 'anos' => $chAnos,
      'C' => $C,
  ]) ?>;
  var C = DEX.C, MONO = "'IBM Plex Mono',ui-monospace,monospace";
  var brl = function(v){ return v.toLocaleString('pt-BR',{style:'currency',currency:'BRL'}); };
  var kg  = function(v){ return v.toLocaleString('pt-BR') + ' kg'; };

  /* ---- count-up (JS puro, sem GSAP) — OBS-B (R2): o HTML já carrega o valor
     final; a animação é enhancement e é PULADA sob automação (webdriver) e
     reduced-motion, para o número certo estar sempre na página ---- */
  function countUp(el){
    var target = parseFloat(el.dataset.count) || 0, f = el.dataset.fmt;
    var render = function(v){ el.textContent = (f==='brl') ? brl(v) : kg(Math.round(v)); };
    if(reduced || navigator.webdriver){ render(target); return; }
    var t0 = null, dur = 1100;
    function step(ts){ if(!t0) t0 = ts; var p = Math.min((ts-t0)/dur,1); var e = 1-Math.pow(1-p,3);
      render(target*e); if(p<1) requestAnimationFrame(step); else render(target); }
    requestAnimationFrame(step);
  }
  document.querySelectorAll('.dex [data-count]').forEach(countUp);

  /* ---- reveal escalonado (JS puro) ---- */
  var dex = document.querySelector('.dex');
  if(reduced){ dex.classList.add('is-in'); }
  else {
    var rises = dex.querySelectorAll('[data-rise]');
    rises.forEach(function(el,i){ el.style.transitionDelay = Math.min(i*60,600)+'ms'; });
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ dex.classList.add('is-in'); }); });
  }

  /* ---- Modal de detalhamento por KPI (rateio real do custeio) ---- */
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
  function mk(id, opt){ var el = document.getElementById(id); if(!el) return;
    var ch = echarts.init(el, null, {renderer:'canvas'}); ch.setOption(opt); charts.push(ch); }
  var base = { textStyle:{fontFamily:'inherit', color:C.muted},
    tooltip:{ backgroundColor:'#fff', borderColor:C.border, textStyle:{color:'#241B14', fontSize:12},
      extraCssText:'box-shadow:0 8px 24px -12px rgba(8,38,42,.35);border-radius:9px;' },
    grid:{left:8, right:16, top:34, bottom:6, containLabel:true},
    animationDuration: reduced?0:800, animationEasing:'cubicOut' };
  var axCat = {axisLine:{lineStyle:{color:C.border}}, axisTick:{show:false}, axisLabel:{color:C.muted, fontSize:11.5}};
  var axVal = {splitLine:{show:false}, axisLabel:{color:C.muted, fontSize:11, fontFamily:MONO}};

  var brlEixo = function(v){ return v>=1000 ? 'R$ '+(v/1000).toLocaleString('pt-BR',{maximumFractionDigits:1})+' mil' : 'R$ '+v; };
  var brlR = function(v){ return 'R$ '+v.toLocaleString('pt-BR',{maximumFractionDigits:0}); };
  /* 1. Faturamento por variedade (barras horizontais) */
  if(DEX.variedade){ var vv = DEX.variedade;
    mk('chVar', Object.assign({}, base, {
      tooltip:Object.assign({}, base.tooltip, {trigger:'axis', axisPointer:{type:'shadow'}, valueFormatter:brl}),
      xAxis:Object.assign({type:'value'}, axVal, {axisLabel:{color:C.muted, fontSize:11, fontFamily:MONO, formatter:brlEixo}}),
      yAxis:Object.assign({type:'category', data:vv.cats, inverse:true}, axCat),
      series:[{name:'Faturamento', type:'bar', data:vv.vals, barWidth:16, itemStyle:{color:C.accent, borderRadius:[0,5,5,0]},
        label:{show:true, position:'right', fontFamily:MONO, fontSize:10.5, color:C.deep, formatter:function(p){return brlR(p.value);}}}]
    }));
  }
  /* 2. Custo × Faturado por safra (barras agrupadas) */
  if(DEX.safra){ var sf = DEX.safra;
    mk('chSafra', Object.assign({}, base, {
      legend:{top:0, right:0, itemWidth:11, itemHeight:11, textStyle:{fontSize:11.5, color:C.muted}},
      tooltip:Object.assign({}, base.tooltip, {trigger:'axis', axisPointer:{type:'shadow'}, valueFormatter:brl}),
      xAxis:Object.assign({type:'category', data:sf.cats}, axCat, {axisLabel:{color:C.muted, fontSize:11, interval:0, rotate: sf.cats.length>4?18:0}}),
      yAxis:Object.assign({type:'value'}, axVal, {axisLabel:{color:C.muted, fontSize:10.5, fontFamily:MONO, formatter:brlEixo}}),
      series:[
        {name:'Custo', type:'bar', data:sf.custo, barWidth:'32%', itemStyle:{color:C.amber, borderRadius:[5,5,0,0]}},
        {name:'Faturado', type:'bar', data:sf.fat, barWidth:'32%', itemStyle:{color:C.accent, borderRadius:[5,5,0,0]}}
      ]
    }));
  }
  /* 3. Classificação de custo por categoria (barras verticais) */
  if(DEX.classe){ var cl = DEX.classe;
    mk('chClasse', Object.assign({}, base, {
      tooltip:Object.assign({}, base.tooltip, {trigger:'axis', axisPointer:{type:'shadow'}, valueFormatter:brl}),
      xAxis:Object.assign({type:'category', data:cl.cats}, axCat, {axisLabel:{color:C.muted, fontSize:10.5, interval:0, rotate: cl.cats.length>4?18:0}}),
      yAxis:Object.assign({type:'value'}, axVal, {axisLabel:{color:C.muted, fontSize:10.5, fontFamily:MONO, formatter:brlEixo}}),
      series:[{name:'Custo', type:'bar', data:cl.vals, barWidth:'46%', itemStyle:{color:C.accent, borderRadius:[5,5,0,0]},
        label:{show:true, position:'top', fontFamily:MONO, fontSize:10.5, color:C.deep, formatter:function(p){return brlR(p.value);}}}]
    }));
  }
  /* 4. Custo de produção ao longo dos anos (linha) */
  if(DEX.anos){ var an = DEX.anos;
    mk('chAnos', Object.assign({}, base, {
      tooltip:Object.assign({}, base.tooltip, {trigger:'axis', valueFormatter:brl}),
      xAxis:Object.assign({type:'category', data:an.cats, boundaryGap:false}, axCat),
      yAxis:Object.assign({type:'value'}, axVal, {axisLabel:{color:C.muted, fontSize:10.5, fontFamily:MONO, formatter:brlEixo}}),
      series:[{name:'Custo', type:'line', data:an.vals, smooth:true, symbol:'circle', symbolSize:7,
        lineStyle:{color:C.accent, width:2.5}, itemStyle:{color:C.accent, borderColor:'#fff', borderWidth:2},
        areaStyle:{color:{type:'linear',x:0,y:0,x2:0,y2:1,colorStops:[{offset:0,color:'rgba(0,80,89,.14)'},{offset:1,color:'rgba(0,80,89,0)'}]}},
        label:{show:true, position:'top', fontFamily:MONO, fontSize:10, color:C.deep, formatter:function(p){return brlEixo(p.value);}}}]
    }));
  }
  window.addEventListener('resize', function(){ charts.forEach(function(c){ c.resize(); }); });
  }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', __charts); else __charts();
})();
</script>
