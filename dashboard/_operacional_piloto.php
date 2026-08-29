<?php
/* ============================================================
   VERO — Dashboard Operacional · redesenho A4-05 (modelo novo do
   usuário: docs/vero_dashboard_operacional.html) ligado a DADOS REAIS.
   DEFAULT de dashboard/dashboard_operacional.php (?classico=1 = render
   antigo). Reusa as variáveis da tela + queries de leitura próprias.
   Mesmo padrão do executivo/financeiro: ECharts vendor LOCAL, animações
   em JS puro (sem GSAP), fonte stack VERO, CSS escopado em .dex, banner.

   SEÇÕES (espelham o modelo novo):
     1. KPIs + fila de alertas (unificada)
     2. Operação do campo   — apontamentos/dia, por atividade, colheita
     3. Produtividade        — kg/ha por válvula e por variedade (fonte:
                               agro/produtividade.php); estoque abaixo do mínimo
     4. Produtividade dos colaboradores — rendimento por pessoa × atividade
                               (fonte: apontamentos → rh_producao_itens)
     5. Painéis dos módulos  — drill-down p/ Nutrição, MIP, Irrigação

   NENHUMA métrica inventada. Métricas do modelo SEM fonte confiável no
   schema ficam FORA e estão listadas em "lacunas" abaixo (custo × produção
   por válvula: não há custo por kg rateado por válvula em colheita_registros).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/_dash.php';

$C = ['accent' => '#005059', 'deep' => '#00363D', 'a3' => '#2A767C', 'olive' => '#4E9CA1',
      'pos' => '#0E7E72', 'amber' => '#B57C1A', 'danger' => '#B23A2E', 'grape' => '#6E4B9E',
      'track' => '#EEE6D6', 'border' => '#E3D9C8', 'muted' => '#8A7C68'];

/* ── Leituras adicionais (read-only; usam $t, $d30 e os filtros de fazenda
   fFaz/pFaz/fj../fc.. já em escopo — vindos de dashboard_operacional.php) ── */
$apontPorDia = vero_rows(
    "SELECT DATE(a.data_apontamento) AS d, COUNT(*) AS n
       FROM agro_apontamentos a{$fjApont} WHERE a.tenant_id = :t AND a.data_apontamento >= :d{$fcApont} GROUP BY DATE(a.data_apontamento)",
    [':t' => $t, ':d' => $d30] + $pFaz);
/* CHART (top-7 atividades) */
$apontAtiv = vero_rows(
    "SELECT COALESCE(ta.nome,'Sem tipo') AS nome, COUNT(*) AS n
       FROM agro_apontamentos a LEFT JOIN agro_tipos_atividade ta ON ta.id = a.tipo_atividade_id{$fjApont}
      WHERE a.tenant_id = :t AND a.data_apontamento >= :d{$fcApont} GROUP BY ta.id, ta.nome ORDER BY n DESC LIMIT 7",
    [':t' => $t, ':d' => $d30] + $pFaz);
/* P01 (auditoria 20/07): DETALHE do modal SEM LIMIT — a soma das linhas tem de
   reconciliar EXATAMENTE com o valor do card ($apont30); o LIMIT 7 do gráfico
   truncaria a soma se houvesse >7 atividades. */
$apontAtivDet = vero_rows(
    "SELECT COALESCE(ta.nome,'Sem tipo') AS nome, COUNT(*) AS n
       FROM agro_apontamentos a LEFT JOIN agro_tipos_atividade ta ON ta.id = a.tipo_atividade_id{$fjApont}
      WHERE a.tenant_id = :t AND a.data_apontamento >= :d{$fcApont} GROUP BY ta.id, ta.nome ORDER BY n DESC",
    [':t' => $t, ':d' => $d30] + $pFaz);
$custoMO30 = (float)vero_val(
    "SELECT COALESCE(SUM(pi.valor_total),0) FROM rh_producao_itens pi{$fjRh} WHERE pi.tenant_id = :t AND pi.data_trabalho >= :d{$fcRh}",
    [':t' => $t, ':d' => $d30] + $pFaz);

/* série contínua de 30 dias (preenche buracos com 0) */
$mapDia = [];
foreach ($apontPorDia as $r) $mapDia[(string)$r['d']] = (int)$r['n'];
$serieLabels = $serieVals = [];
for ($i = 29; $i >= 0; $i--) { $day = date('Y-m-d', strtotime("-$i days"));
    $serieLabels[] = date('d/m', strtotime($day)); $serieVals[] = $mapDia[$day] ?? 0; }
$chSerie = array_sum($serieVals) > 0 ? ['labels' => $serieLabels, 'vals' => $serieVals] : null;

$alertasTotal = array_sum(array_map(static fn($a) => (int)$a['total'], $alertas));
$alertasCrit  = array_sum(array_map(static fn($a) => (int)$a['criticos'], $alertas));
$chAtiv = $apontAtiv ? ['cats' => array_map(static fn($a) => (string)$a['nome'], $apontAtiv),
    'vals' => array_map(static fn($a) => (int)$a['n'], $apontAtiv)] : null;

/* seletor dia/semana/mês do gráfico de atividades — 3 janelas pré-calculadas
   (troca client-side, sem recarregar) */
$topAtiv = static function (string $dStart) use ($t, $fjApont, $fcApont, $pFaz): array {
    return vero_rows(
        "SELECT COALESCE(ta.nome,'Sem tipo') AS nome, COUNT(*) AS n
           FROM agro_apontamentos a LEFT JOIN agro_tipos_atividade ta ON ta.id = a.tipo_atividade_id{$fjApont}
          WHERE a.tenant_id = :t AND a.data_apontamento >= :d{$fcApont} GROUP BY ta.id, ta.nome ORDER BY n DESC LIMIT 7",
        [':t' => $t, ':d' => $dStart] + $pFaz);
};
$mkWin = static fn(array $rows): array => [
    'cats' => array_map(static fn($a) => (string)$a['nome'], $rows),
    'vals' => array_map(static fn($a) => (int)$a['n'], $rows)];
$chAtivWin = [
    'dia'    => $mkWin($topAtiv(date('Y-m-d'))),
    'semana' => $mkWin($topAtiv(date('Y-m-d', strtotime('-7 days')))),
    'mes'    => $mkWin($topAtiv($d30)),
];

$colPrev = array_map(static fn($c) => round((float)$c['previsto']), $colheita);
$colReal = array_map(static fn($c) => round((float)$c['realizado']), $colheita);
$chColheita = ($colheita && (array_sum($colPrev) + array_sum($colReal)) > 0)
    ? ['safras' => array_map(static fn($c) => (string)$c['safra'], $colheita), 'prev' => $colPrev, 'real' => $colReal] : null;
/* % executado (realizado ÷ previsto) — o gauge redundante virou este rótulo no card (pedido A0) */
$colPrevTot = array_sum($colPrev);
$colRealTot = array_sum($colReal);
$colExecPct = $colPrevTot > 0 ? $colRealTot / $colPrevTot * 100 : null;

$alCls = $alertasCrit > 0 ? 'k-danger' : ($alertasTotal > 0 ? 'k-warn' : 'k-pos');

/* ══════════════ DETALHAMENTO (modal) POR KPI — dado real (30d) ══════════════ */
$pessoasOrigem = vero_rows(
    "SELECT pi.origem_pessoa, COUNT(DISTINCT COALESCE(pi.operador_id, pi.terceirizado_id, 0)) AS n
       FROM rh_producao_itens pi{$fjRh} WHERE pi.tenant_id = :t AND pi.data_trabalho >= :d{$fcRh} GROUP BY pi.origem_pessoa ORDER BY n DESC",
    [':t' => $t, ':d' => $d30] + $pFaz);
$moOrigem = vero_rows(
    "SELECT pi.origem_pessoa, COALESCE(SUM(pi.valor_total),0) AS tot
       FROM rh_producao_itens pi{$fjRh} WHERE pi.tenant_id = :t AND pi.data_trabalho >= :d{$fcRh} GROUP BY pi.origem_pessoa ORDER BY tot DESC",
    [':t' => $t, ':d' => $d30] + $pFaz);
$irrRecent = vero_rows(
    "SELECT ia.data_apontamento, ia.horas FROM irrigacao_apontamentos ia{$fjIrr}
      WHERE ia.tenant_id = :t AND ia.data_apontamento >= :d{$fcIrr} ORDER BY ia.data_apontamento DESC LIMIT 15", [':t' => $t, ':d' => $d30] + $pFaz);
$mipDias = vero_rows(
    "SELECT DATE(mm.data_monitoramento) AS dia, COUNT(*) AS n FROM mip_monitoramentos mm{$fjMip}
      WHERE mm.tenant_id = :t AND mm.data_monitoramento >= :d{$fcMip} GROUP BY DATE(mm.data_monitoramento) ORDER BY dia DESC LIMIT 20", [':t' => $t, ':d' => $d30] + $pFaz);
$rotOrigem = static fn($o) => ucfirst(str_replace('_', ' ', (string)$o)) ?: '—';
$intBR = static fn($n) => number_format((int)$n, 0, ',', '.');

/* P01: janela rolante de 30 dias explícita (sem ambiguidade) — o card usa
   data >= hoje-30d; o Relatório Operacional usa o ano corrente (Y-01-01..hoje),
   então os dois divergem legitimamente quando há registro com mais de 30 dias. */
$d30Ini = date('d/m/Y', strtotime($d30));
$d30Fim = date('d/m/Y');
$rng30  = "últimos 30 dias ({$d30Ini} – {$d30Fim})";
$fazNota = $fFaz ? ' · fazenda filtrada' : '';

$kpiDet = [
    'apontamentos' => ['titulo' => 'Apontamentos (30d)', 'valor' => $intBR($apont30), 'sub' => 'Por atividade · ' . $rng30 . $fazNota,
        'cols' => ['Atividade', 'Qtd'], 'rows' => array_map(static fn($a) => [h((string)$a['nome']), (int)$a['n']], $apontAtivDet),
        'note' => 'Janela rolante de 30 dias (por data do apontamento). O Relatório Operacional usa o ano corrente, então pode listar mais registros — não é divergência de fonte.'],
    'pessoas' => ['titulo' => 'Pessoas em campo (30d)', 'valor' => $intBR($pessoas30), 'sub' => 'Distintos por origem',
        'cols' => ['Origem', 'Pessoas'], 'rows' => array_map(static fn($r) => [h($rotOrigem($r['origem_pessoa'])), (int)$r['n']], $pessoasOrigem)],
    'mao_obra' => ['titulo' => 'Mão de obra (30d)', 'valor' => 'R$ ' . numFmt($custoMO30, 2), 'sub' => 'Custo apontado por origem · ' . $rng30 . $fazNota,
        'cols' => ['Origem', 'R$'], 'rows' => array_map(static fn($r) => [h($rotOrigem($r['origem_pessoa'])), numFmt((float)$r['tot'], 2)], $moOrigem),
        'note' => 'Custo de produção com data de trabalho nos últimos 30 dias. O Relatório Operacional soma por período do ano corrente — janelas diferentes, mesma fonte.'],
    'irrigacao' => ['titulo' => 'Irrigação (30d)', 'valor' => numFmt((float)$irr30['horas'], 1) . ' h', 'sub' => (int)$irr30['apontamentos'] . ' apontamento(s) · mais recentes',
        'cols' => ['Data', 'Horas'], 'rows' => array_map(static fn($r) => [date('d/m/Y', strtotime((string)$r['data_apontamento'])), numFmt((float)$r['horas'], 1)], $irrRecent)],
    'mip' => ['titulo' => 'Monitoramentos MIP (30d)', 'valor' => $intBR($monit30), 'sub' => 'Leituras por dia',
        'cols' => ['Data', 'Leituras'], 'rows' => array_map(static fn($r) => [date('d/m/Y', strtotime((string)$r['dia'])), (int)$r['n']], $mipDias)],
    'alertas' => ['titulo' => 'Alertas abertos', 'valor' => $intBR($alertasTotal), 'sub' => $alertasCrit . ' crítico(s) · por categoria',
        'cols' => ['Categoria', 'Abertos', 'Críticos'], 'rows' => array_map(static fn($a) => [h(ucfirst((string)$a['categoria'])), (int)$a['total'], (int)$a['criticos']], $alertas)],
    /* Colheita realizada (kg) — usa o mesmo dado de colheita já carregado aqui. */
    'colheita' => ['titulo' => 'Colheita realizada', 'valor' => $intBR($colRealTot) . ' kg',
        'sub' => ($colPrevTot > 0 ? 'de ' . $intBR($colPrevTot) . ' kg previstos'
                    . ($colExecPct !== null ? ' · ' . numFmt($colExecPct, 0) . '% executado' : '') : 'sem previsão cadastrada') . ' · por safra' . $fazNota,
        'cols' => ['Safra', 'Previsto', 'Realizado'],
        'rows' => array_map(static fn($c) => [h((string)$c['safra']), $intBR((float)$c['previsto']), $intBR((float)$c['realizado'])], $colheita)],
];

/* ══════════════ FILA DE ALERTAS (unificada) — modelo novo, dado real ══════════
   Substitui o gráfico "alertas por categoria" por cartões de ação, no espírito
   da alertbar do protótipo. Fonte: estoque abaixo do mínimo, lotes vencendo e
   agro_alertas abertos por categoria (com rota real). Máx. 4 cartões. */
$abarCards = [];
if ($estoqueMin) {
    $abarCards[] = ['cls' => 'crit', 'mod' => 'Estoque', 'msg' => count($estoqueMin) . ' insumo(s) abaixo do mínimo',
        'go' => 'Ver produtos', 'href' => $base . '/estoque/produtos'];
}
if ($lotesVencendo) {
    $abarCards[] = ['cls' => 'amber', 'mod' => 'Estoque · validade', 'msg' => count($lotesVencendo) . ' lote(s) vencendo em 30 dias',
        'go' => 'Ver lotes', 'href' => $base . '/estoque/lotes'];
}
foreach ($alertas as $al) {
    if (count($abarCards) >= 4) break;
    $cat = (string)$al['categoria'];
    $crit = (int)$al['criticos'];
    $abarCards[] = ['cls' => $crit > 0 ? 'crit' : 'water', 'mod' => ucfirst($cat),
        'msg' => (int)$al['total'] . ' alerta(s) aberto(s)' . ($crit > 0 ? ' · ' . $crit . ' crítico(s)' : ''),
        'go' => 'Abrir', 'href' => $base . ($rotaAlerta[$cat] ?? '/dashboard/indicadores_alertas')];
}
$abarCards = array_slice($abarCards, 0, 4);

/* ══════════════ SEÇÃO PRODUTIVIDADE — FONTE: agro/produtividade.php (C-17) ══════
   Mesmíssimo cálculo da tela Produtividade por Talhão: kg/ha REALIZADO por
   válvula×safra (área plantada do plano de safra quando existe, senão a cadastral
   da válvula) e ATINGIMENTO do plano. Agrega TODAS as safras com colheita
   (escopo GERAL), respeitando o filtro de fazenda. */
$prodRowsSql = vero_rows(
    "SELECT cr.talhao_id, cr.safra_id,
            tl.codigo AS talhao, fz.nome AS fazenda,
            tl.area_ha, st.area_plantada_ha, st.produtividade_planejada,
            vr.nome AS variedade, cu.nome AS cultura,
            SUM(cr.kg_total_realizado) AS kg_realizado,
            AVG(cr.producao_realizada_kg_ha) AS kgha_medio
       FROM colheita_registros cr
       LEFT JOIN agro_talhoes tl ON tl.id = cr.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
       LEFT JOIN agro_safra_talhoes st ON st.id = cr.safra_talhao_id
       LEFT JOIN agro_variedades vr ON vr.id = cr.variedade_id
       LEFT JOIN agro_culturas cu ON cu.id = cr.cultura_id
      WHERE cr.tenant_id = :t" . ($fFaz ? " AND tl.fazenda_id = :fz" : "") . "
      GROUP BY cr.talhao_id, cr.safra_id, tl.codigo, fz.nome, tl.area_ha,
               st.area_plantada_ha, st.produtividade_planejada, vr.nome, cu.nome
      ORDER BY fz.nome, tl.codigo", [':t' => $t] + $pFaz);

$prodKgTotal = 0.0; $prodAreaTotal = 0.0; $prodPctSoma = 0.0; $prodPctN = 0;
$prodRank = []; $varAgg = [];
foreach ($prodRowsSql as $r) {
    $area = ($r['area_plantada_ha'] !== null && (float)$r['area_plantada_ha'] > 0)
          ? (float)$r['area_plantada_ha']
          : ($r['area_ha'] !== null ? (float)$r['area_ha'] : null);
    $kgReal = (float)$r['kg_realizado'];
    $kgha = ($area && $area > 0) ? $kgReal / $area : ($r['kgha_medio'] !== null ? (float)$r['kgha_medio'] : null);
    $plan = $r['produtividade_planejada'] !== null ? (float)$r['produtividade_planejada'] : null;
    $pct  = ($plan !== null && $plan > 0 && $kgha !== null) ? $kgha / $plan * 100 : null;
    $prodKgTotal += $kgReal;
    if ($area) $prodAreaTotal += $area;
    if ($pct !== null) { $prodPctSoma += $pct; $prodPctN++; }
    if ($kgha !== null) {
        $prodRank[] = ['nome' => trim(((string)($r['fazenda'] ?? '')) . ' — ' . ((string)($r['talhao'] ?? '')), ' —') ?: '—',
            'kgha' => (int)round($kgha), 'cultura' => (string)($r['cultura'] ?? ''), 'pct' => $pct !== null ? (int)round($pct) : null];
    }
    $vn = $r['variedade'] !== null ? (string)$r['variedade'] : null;
    if ($vn !== null && $vn !== '' && $area && $area > 0) {
        if (!isset($varAgg[$vn])) $varAgg[$vn] = ['kg' => 0.0, 'area' => 0.0];
        $varAgg[$vn]['kg'] += $kgReal; $varAgg[$vn]['area'] += $area;
    }
}
$prodKghaGeral = $prodAreaTotal > 0 ? $prodKgTotal / $prodAreaTotal : null;
$prodPctMedio  = $prodPctN > 0 ? $prodPctSoma / $prodPctN : null;
$temProd = !empty($prodRowsSql);
$prodAtgCls = $prodPctMedio === null ? '' : ($prodPctMedio >= 95 ? 'k-pos' : ($prodPctMedio >= 85 ? 'k-warn' : 'k-danger'));

/* ranking por kg/ha (≥2 itens → gráfico; senão degrada — regra do _dash.php) */
usort($prodRank, static fn($a, $b) => $a['kgha'] <=> $b['kgha']);
$prodRankMedia = $prodRank ? array_sum(array_map(static fn($x) => $x['kgha'], $prodRank)) / count($prodRank) : null;
$jsProdRank = count($prodRank) >= 2 ? ['rows' => $prodRank, 'media' => $prodRankMedia !== null ? (int)round($prodRankMedia) : null] : null;

/* produtividade média por variedade (kg/ha ponderado por área) */
$prodVar = [];
foreach ($varAgg as $vn => $a) { if ($a['area'] > 0) $prodVar[] = ['var' => $vn, 'kgha' => (int)round($a['kg'] / $a['area'])]; }
usort($prodVar, static fn($a, $b) => $b['kgha'] <=> $a['kgha']);
$jsProdVar = count($prodVar) >= 2 ? $prodVar : null;

/* ══════════════ SEÇÃO PRODUTIVIDADE DOS COLABORADORES — dado real ══════════════
   Rendimento por pessoa × atividade a partir dos apontamentos de campo:
   rh_producao_itens (quantidade produzida) → apontamento → atividade; pessoa =
   operador OU terceirizado. Rendimento = SUM(quantidade) ÷ dias distintos de
   trabalho (unidade/dia). Escopo GERAL (todas as datas com produção), com o
   filtro de fazenda via apontamento → talhão. Só entram itens com quantidade>0
   (diárias sem produção não medem rendimento). */
$colabRowsSql = vero_rows(
    "SELECT ta.nome AS atividade, ta.unidade_padrao AS un,
            pi.origem_pessoa,
            COALESCE(op.nome, te.nome) AS pessoa,
            SUM(pi.quantidade) AS qtd,
            COUNT(DISTINCT pi.data_trabalho) AS dias
       FROM rh_producao_itens pi
       JOIN agro_apontamentos a ON a.id = pi.apontamento_id
       LEFT JOIN agro_tipos_atividade ta ON ta.id = a.tipo_atividade_id
       LEFT JOIN agro_operadores op ON op.id = pi.operador_id
       LEFT JOIN rh_terceirizados te ON te.id = pi.terceirizado_id"
       . ($fFaz ? " LEFT JOIN agro_talhoes tl ON tl.id = a.talhao_id" : "") . "
      WHERE pi.tenant_id = :t AND pi.quantidade > 0" . ($fFaz ? " AND tl.fazenda_id = :fz" : "") . "
      GROUP BY ta.nome, ta.unidade_padrao, pi.origem_pessoa, pi.operador_id, pi.terceirizado_id, op.nome, te.nome
      ORDER BY ta.nome", [':t' => $t] + $pFaz);

$unDiaMap = ['planta' => 'plantas/dia', 'caixa' => 'caixas/dia', 'kg' => 'kg/dia', 'ha' => 'ha/dia',
             'metro_linear' => 'm/dia', 'hora' => 'h/dia', 'outro' => 'un/dia'];
$colabAtiv = [];
foreach ($colabRowsSql as $r) {
    $dias = (int)$r['dias'];
    if ($dias <= 0) continue;
    $ativ = (string)($r['atividade'] ?? '') ?: 'Sem tipo';
    $rend = (float)$r['qtd'] / $dias;
    if (!isset($colabAtiv[$ativ])) {
        $colabAtiv[$ativ] = ['un' => $unDiaMap[(string)$r['un']] ?? 'un/dia', 'rows' => []];
    }
    $colabAtiv[$ativ]['rows'][] = [
        'nome' => (string)($r['pessoa'] ?? '') ?: '—',
        'vinc' => $r['origem_pessoa'] === 'terceirizado' ? 'terceiro' : 'colab.',
        'r'    => round($rend, 1),
        'd'    => $dias,
    ];
}
/* atividades ordenadas por nº de pessoas (mais relevante primeiro) */
$colabKeys = array_keys($colabAtiv);
usort($colabKeys, static fn($a, $b) => count($colabAtiv[$b]['rows']) <=> count($colabAtiv[$a]['rows']));
$temColab = !empty($colabKeys);

/* ══════════════ PAINÉIS DOS MÓDULOS (drill-down) — dado real ══════════════
   Contagens reais já carregadas: alertas por categoria (nutrição, MIP),
   monitoramentos MIP (30d) e horas de irrigação (30d). Rotas reais. */
$alByCat = [];
foreach ($alertas as $al) { $alByCat[(string)$al['categoria']] = ['total' => (int)$al['total'], 'crit' => (int)$al['criticos']]; }
$modCards = [
    ['t' => 'Nutrição', 'ic' => '🌱', 'bg' => '#DDEDEB', 'href' => $base . '/nutricao/painel_nutrientes',
        'stats' => [['n' => $intBR($alByCat['nutricao']['crit'] ?? 0), 'l' => 'críticos'],
                    ['n' => $intBR($alByCat['nutricao']['total'] ?? 0), 'l' => 'alertas abertos']],
        'go' => 'Painel de Nutrientes'],
    ['t' => 'MIP', 'ic' => '🐛', 'bg' => '#F2DCD8', 'href' => $base . '/mip/alertas_fitossanitarios',
        'stats' => [['n' => $intBR($alByCat['mip']['crit'] ?? 0), 'l' => 'críticos'],
                    ['n' => $intBR($monit30), 'l' => 'leituras (30d)']],
        'go' => 'Alertas Fitossanitários'],
    ['t' => 'Irrigação', 'ic' => '💧', 'bg' => '#D7E9F1', 'href' => $base . '/irrigacao/painel',
        'stats' => [['n' => numFmt((float)$irr30['horas'], 1) . ' h', 'l' => 'na janela'],
                    ['n' => $intBR((int)$irr30['apontamentos']), 'l' => 'apontamentos (30d)']],
        'go' => 'Painel de Irrigação'],
];
?>
<style>
/* ===== Dashboards A4 — escopado em .dex (reuso do executivo) ===== */
.dex{
  --ac:#005059; --acd:#00363D; --ac3:#2A767C; --olive:#4E9CA1;
  --page:#EDEAE0; --surface:#fff; --warm:#FBF8F2; --ink:#241B14; --ink2:#2B2018;
  --mut:#8A7C68; --mut2:#9A8C78; --bd:#E3D9C8; --bd2:#DDD2BF; --track:#EEE6D6;
  --pos:#0E7E72; --pos-bg:#DDEDEB; --amber:#B57C1A; --amber-d:#7A5410; --amber-bg:#F3E7C8;
  --danger:#B23A2E; --danger-bg:#F2DCD8; --water:#1E7FA8;
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
.dex .grid{display:grid; grid-template-columns:repeat(12,1fr); gap:16px}
.dex .card{background:rgba(255,255,255,.66); border:1px solid rgba(227,217,200,.9); border-radius:var(--rc); padding:16px 18px; min-width:0;
  transition:box-shadow .25s, border-color .25s}
.dex .card:hover{border-color:var(--bd2); box-shadow:0 8px 22px -16px rgba(8,38,42,.25)}
.dex .card h2{font-size:13.5px; font-weight:700; color:var(--ink2)}
.dex .card .chint{font-size:11.5px; color:var(--mut2); margin:1px 0 10px}
.dex .c8{grid-column:span 8} .dex .c6{grid-column:span 6} .dex .c4{grid-column:span 4} .dex .c12{grid-column:span 12}
.dex .chart{width:100%; height:260px}
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
.dex .bar{height:8px; background:var(--track); border-radius:4px; overflow:hidden; flex:1}
.dex .bar>i{display:block; height:100%; background:var(--danger); border-radius:4px}
.dex .vb{display:inline-block; font-size:11px; font-weight:600; border-radius:var(--rs); padding:2px 9px; white-space:nowrap}
.dex .vb-ok{background:var(--pos-bg); color:var(--pos)} .dex .vb-warn{background:var(--amber-bg); color:var(--amber-d)}
.dex .vb-danger{background:var(--danger-bg); color:var(--danger)} .dex .vb-off{background:var(--track); color:var(--mut)}
.dex .dfoot{margin-top:22px; font-size:11px; color:var(--mut2); text-align:center}
.dex .mt16{margin-top:16px}
/* cabeçalho de seção (Operação / Produtividade / Colaboradores / Módulos) */
.dex .sech{display:flex; align-items:center; gap:10px; margin:26px 2px 12px; flex-wrap:wrap}
.dex .sech h2{font-size:15px; font-weight:800; color:var(--ink2); letter-spacing:-.2px}
.dex .sech .ln{flex:1; height:1px; background:var(--bd); min-width:20px}
.dex .sech .hint{font-size:11.5px; color:var(--mut2)}
.dex .sech .hint a, .dex .sech a{font-weight:600}
/* fila de alertas (unificada) */
.dex .abar{display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:14px; margin-bottom:6px}
.dex .acard{background:rgba(255,255,255,.72); border:1px solid rgba(227,217,200,.9); border-left:4px solid var(--mut); border-radius:var(--rc);
  padding:13px 15px; text-decoration:none; color:inherit; display:block; transition:transform .2s, box-shadow .2s}
.dex .acard:hover{transform:translateY(-2px); box-shadow:0 8px 22px -14px rgba(8,38,42,.3)}
.dex .acard.crit{border-left-color:var(--danger)} .dex .acard.amber{border-left-color:var(--amber)} .dex .acard.water{border-left-color:var(--water)} .dex .acard.pos{border-left-color:var(--pos)}
.dex .acard .amod{font-size:10px; text-transform:uppercase; letter-spacing:.06em; font-weight:700; color:var(--mut)}
.dex .acard .amsg{font-size:13.5px; font-weight:700; margin-top:5px; color:var(--ink2)}
.dex .acard .algo{font-size:11px; color:var(--ac3); font-weight:700; margin-top:7px; display:inline-flex; gap:4px}
/* segmentado (filtro de atividade dos colaboradores) */
.dex .seg{display:flex; gap:7px; flex-wrap:wrap; border:0; border-radius:0; overflow:visible}
.dex .seg button{font:600 12.5px/1 'IBM Plex Sans',sans-serif; color:var(--mut); background:rgba(255,255,255,.6);
  border:1px solid var(--bd); border-radius:20px; padding:7px 14px; cursor:pointer; transition:.18s}
.dex .seg button:hover{border-color:var(--ac3)}
.dex .seg button.on{background:var(--ac); border-color:var(--ac); color:#fff}
/* lista de destaques */
.dex .dlist{display:flex; flex-direction:column}
.dex .drow{display:flex; align-items:center; gap:11px; padding:10px 2px; border-bottom:1px solid var(--track)}
.dex .drow:last-child{border-bottom:none}
.dex .drow .dico{width:26px; text-align:center; font-size:16px}
.dex .drow .dnm{flex:1; min-width:0}
.dex .drow .dnm .dt1{font-weight:600; font-size:12.5px; color:var(--ink2)}
.dex .drow .dnm .dt2{font-size:11px; color:var(--mut2)}
.dex .drow .dvv{font-family:var(--num); font-weight:600; font-size:12.5px; color:var(--pos); white-space:nowrap}
.dex .drow .dvv small{font-size:9.5px; color:var(--mut2); font-weight:500}
/* painéis dos módulos */
.dex .mods{display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:14px}
.dex .mod{background:rgba(255,255,255,.7); border:1px solid rgba(227,217,200,.9); border-radius:var(--rc); padding:16px 18px;
  text-decoration:none; color:inherit; display:flex; flex-direction:column; gap:8px; transition:transform .2s, border-color .2s}
.dex .mod:hover{transform:translateY(-3px); border-color:var(--ac3)}
.dex .mod .mh{display:flex; align-items:center; justify-content:space-between}
.dex .mod .mt{font-size:14.5px; font-weight:800; color:var(--ink2)}
.dex .mod .mi{width:34px; height:34px; border-radius:9px; display:grid; place-items:center; font-size:17px}
.dex .mod .mstat{display:flex; gap:18px; margin-top:2px}
.dex .mod .mstat div{display:flex; flex-direction:column}
.dex .mod .mstat .n{font-family:var(--num); font-size:19px; font-weight:600; color:var(--acd)}
.dex .mod .mstat .l{font-size:10px; color:var(--mut); font-weight:600; text-transform:uppercase; letter-spacing:.03em}
.dex .mod .go{font-size:11.5px; color:var(--ac3); font-weight:700; margin-top:2px}
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
  <?= $DOPS_TABS ?? '' ?>
  <?= vero_flash_html() ?>
  <header class="dtop" data-rise>
    <?php /* P12: filtro Fazenda por URL (mesmo padrão do executivo). Sem filtro
       de Safra — o pulso é uma janela de tempo (30d), não de safra. */ ?>
    <div class="dtoolbar" style="display:flex;gap:8px;align-items:flex-end;margin-right:auto">
      <form method="get" style="display:flex;gap:8px;align-items:flex-end">
        <select name="fazenda" aria-label="Fazenda" onchange="this.form.submit()"
          style="font:inherit;font-size:13px;font-weight:500;color:#241B14;background:#fff;border:1px solid #E3D9C8;border-radius:9px;padding:8px 12px;min-width:150px;cursor:pointer;outline:none">
          <option value="0">Todas as fazendas</option>
          <?php foreach ($fazendas as $f): ?>
            <option value="<?= (int)$f['id'] ?>"<?= $fFaz === (int)$f['id'] ? ' selected' : '' ?>><?= h((string)$f['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </header>

  <!-- KPIs (30d) removidos a pedido do usuário (22/07): o operacional foca na
       fila de alertas, Operação do campo, Produtividade, Colaboradores e Painéis. -->

  <!-- ══════════════ PRODUTIVIDADE (movida p/ o topo — pedido 23/07) ══════════════ -->
  <div class="sech" data-rise>
    <h2>Produtividade</h2>
    <?= dash_scope('geral') ?>
    <div class="ln"></div>
  </div>

  <?php if (!$temProd): ?>
  <section class="grid">
    <div class="card c12" data-rise>
      <?= dash_empty('Nenhuma colheita registrada para calcular produtividade.', 'Abrir Produtividade por Talhão', $base . '/agro/produtividade') ?>
    </div>
  </section>
  <?php else: ?>
  <section class="kpis">
    <div class="kpi k-pos" data-rise>
      <div class="kl">Colhido total</div>
      <div class="kv"><?= numFmt($prodKgTotal, 0) ?> <span style="font-size:.6em;color:var(--mut)">kg</span></div>
      <div class="ks"><?= numFmt($prodAreaTotal, 2) ?> ha com colheita</div>
    </div>
    <div class="kpi" data-rise>
      <div class="kl">Produtividade média</div>
      <div class="kv"><?= $prodKghaGeral !== null ? numFmt($prodKghaGeral, 0) : '—' ?> <span style="font-size:.6em;color:var(--mut)">kg/ha</span></div>
      <div class="ks">realizado ÷ área com colheita</div>
    </div>
    <div class="kpi <?= $prodAtgCls ?>" data-rise>
      <div class="kl">Atingimento médio do plano</div>
      <div class="kv"><?= $prodPctMedio !== null ? numFmt($prodPctMedio, 1) . '%' : '—' ?></div>
      <div class="ks"><?= $prodPctMedio !== null ? $prodPctN . ' válvula(s) com plano de safra' : 'sem produtividade planejada cadastrada' ?></div>
    </div>
    <div class="kpi" data-rise>
      <div class="kl">Válvulas com colheita</div>
      <div class="kv"><?= numFmt((float)count($prodRank), 0) ?></div>
      <div class="ks"><?= count($prodVar) ?> variedade(s) apuradas</div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ══ OPERAÇÃO DO CAMPO + PRODUTIVIDADE DOS COLABORADORES (lado a lado) ══ -->
  <div class="sech" data-rise>
    <h2>Operação do campo</h2>
    <div class="ln"></div>
  </div>
  <section class="grid">
    <div class="card c6" data-rise style="display:flex;flex-direction:column">
      <h2>Apontamentos por atividade</h2>
      <div class="dtoolbar" style="margin:8px 0 10px">
        <div class="seg" id="segAtivWin">
          <button type="button" data-win="dia">Dia</button>
          <button type="button" data-win="semana">Semana</button>
          <button type="button" data-win="mes" class="on">Mês</button>
        </div>
      </div>
      <div id="chAtiv" class="chart" style="flex:1;height:auto;min-height:280px"></div>
      <div id="chAtivEmpty" class="empty" style="display:none;padding:30px 14px">Sem apontamentos no período.</div>
    </div>
    <div class="card c6" data-rise>
      <h2 id="colabTitle">Produtividade dos colaboradores</h2>
      <?php if (!$temColab): ?>
        <?= dash_empty('Sem produção apontada por pessoa (quantidade produzida) para medir rendimento.', 'Registrar apontamento com produção', $base . '/agro/apontamentos') ?>
      <?php else: ?>
        <div class="dtoolbar" style="margin:8px 0 12px">
          <div class="seg" id="segAtiv">
            <?php foreach ($colabKeys as $i => $k): ?>
              <button type="button" data-ativ="<?= h($k) ?>" class="<?= $i === 0 ? 'on' : '' ?>"><?= h($k) ?> <span style="opacity:.7">· <?= count($colabAtiv[$k]['rows']) ?></span></button>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="chint" id="colabHint">Média por pessoa · linha tracejada = média da equipe.</div>
        <div id="chColab" class="chart" style="height:360px"></div>
        <div id="colabSingle" class="empty" style="display:none;padding:34px 14px"></div>
      <?php endif; ?>
    </div>
  </section>

  <div class="dfoot">VERO · Dashboard Operacional</div>

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
  /* OBS-B: o HTML já traz o valor FINAL dos KPIs; o count-up é só enhancement.
     Sob automação (navigator.webdriver) ou reduced-motion, nada anima. */
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches || navigator.webdriver === true;
  var DEX = <?= jsvar(['serie' => $chSerie, 'ativ' => $chAtiv, 'ativWin' => $chAtivWin, 'colheita' => $chColheita,
      'prodRank' => $jsProdRank, 'prodVar' => $jsProdVar,
      'colab' => $temColab ? $colabAtiv : null, 'colabKeys' => $colabKeys, 'C' => $C]) ?>;
  var C = DEX.C, MONO = "'IBM Plex Mono',ui-monospace,monospace";
  function cultColor(c){ c=(c||'').toLowerCase();
    if(c.indexOf('manga')>=0) return C.amber;
    if(c.indexOf('uva')>=0 || c.indexOf('videira')>=0 || c.indexOf('parreira')>=0) return C.grape;
    return C.a3; }
  var brl = function(v){ return v.toLocaleString('pt-BR',{style:'currency',currency:'BRL'}); };
  var kg = function(v){ return v.toLocaleString('pt-BR')+' kg'; };
  var nf = function(v){ return Number(v).toLocaleString('pt-BR'); };
  function fmtVal(v, f){
    if(f==='brl') return brl(v);
    if(f==='h') return v.toLocaleString('pt-BR',{minimumFractionDigits:1,maximumFractionDigits:1})+' h';
    if(f==='kg') return kg(Math.round(v));
    return Math.round(v).toLocaleString('pt-BR');
  }
  document.querySelectorAll('.dex [data-count]').forEach(function(el){
    var target = parseFloat(el.dataset.count) || 0, f = el.dataset.fmt;
    var render = function(v){ el.textContent = fmtVal(v, f); };
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

  /* ---- Destaques por atividade (best por atividade) — sem echarts ---- */
  (function(){
    if(!DEX.colab || !DEX.colabKeys.length) return;
    var ico = function(k){ k=(k||'').toLowerCase();
      if(k.indexOf('colheita')>=0) return '🍇';
      if(k.indexOf('poda')>=0) return '✂️';
      if(k.indexOf('amarr')>=0) return '🪢';
      if(k.indexOf('rale')>=0) return '🍃';
      if(k.indexOf('embal')>=0 || k.indexOf('pack')>=0) return '📦';
      if(k.indexOf('irrig')>=0) return '💧';
      return '⚙️'; };
    var html = DEX.colabKeys.map(function(k){
      var a = DEX.colab[k]; if(!a || !a.rows.length) return '';
      var best = a.rows.reduce(function(m,x){ return x.r>m.r?x:m; }, a.rows[0]);
      return '<div class="drow"><div class="dico">'+ico(k)+'</div>'+
        '<div class="dnm"><div class="dt1">Melhor em '+k+'</div><div class="dt2">'+best.nome+' · '+best.vinc+'</div></div>'+
        '<div class="dvv">'+nf(best.r)+' <small>'+a.un+'</small></div></div>';
    }).join('');
    var _cd = document.getElementById('colabDest'); if (_cd) _cd.innerHTML = html;
  })();

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
  var axCat = {axisLine:{lineStyle:{color:C.border}}, axisTick:{show:false}, axisLabel:{color:C.muted, fontSize:11}};
  var axVal = {splitLine:{show:false}, axisLabel:{color:C.muted, fontSize:11, fontFamily:MONO}};
  function mk(id, opt){ var el=document.getElementById(id); if(!el) return null; var ch=echarts.init(el,null,{renderer:'canvas'}); ch.setOption(opt); charts.push(ch); return ch; }

  /* (1. "Apontamentos por dia" removido a pedido do usuário 22/07) */
  /* 2. Apontamentos por atividade (hbar) — seletor dia/semana/mês, sem reload */
  if(DEX.ativWin){
    var chAtiv=null, elAtiv=document.getElementById('chAtiv'), emAtiv=document.getElementById('chAtivEmpty');
    var renderAtiv = function(win){
      var a = DEX.ativWin[win] || {cats:[],vals:[]};
      if(!a.cats.length){ if(elAtiv) elAtiv.style.display='none'; if(emAtiv) emAtiv.style.display='';
        if(chAtiv){ chAtiv.dispose(); chAtiv=null; } return; }
      if(emAtiv) emAtiv.style.display='none';
      if(elAtiv) elAtiv.style.display='';
      if(!chAtiv){ chAtiv = echarts.init(elAtiv,null,{renderer:'canvas'}); charts.push(chAtiv); }
      chAtiv.setOption(Object.assign({}, base, {
        grid:{left:8, right:40, top:8, bottom:6, containLabel:true},
        tooltip:Object.assign({}, base.tooltip, {trigger:'item'}),
        xAxis:Object.assign({type:'value', minInterval:1}, axVal, {axisLabel:{show:false}, splitLine:{show:false}}),
        yAxis:Object.assign({type:'category', data:a.cats, inverse:true}, axCat, {axisLabel:{color:C.deep, fontSize:11, fontWeight:600}}),
        series:[{name:'Apontamentos', type:'bar', data:a.vals, barMaxWidth:26, barCategoryGap:'32%',
          itemStyle:{color:C.accent, borderRadius:[0,4,4,0]},
          label:{show:true, position:'right', fontFamily:MONO, fontSize:11, color:C.deep}}]
      }), true);
      chAtiv.resize();
    };
    renderAtiv('mes');
    var segW=document.getElementById('segAtivWin');
    if(segW) segW.addEventListener('click', function(e){ var b=e.target.closest('button'); if(!b) return;
      Array.prototype.forEach.call(segW.children,function(x){x.classList.remove('on');}); b.classList.add('on'); renderAtiv(b.dataset.win); });
  }
  /* (3. "Colheita previsto × realizado" removido a pedido do usuário 22/07) */
  /* 4. Produtividade por válvula (kg/ha, barras horizontais, cor por cultura) */
  if(DEX.prodRank){ var pr = DEX.prodRank;
    mk('chProdRank', Object.assign({}, base, {
      grid:{left:8, right:52, top:10, bottom:6, containLabel:true},
      tooltip:Object.assign({}, base.tooltip, {trigger:'item', formatter:function(p){ var r=pr.rows[p.dataIndex];
        return '<b>'+r.nome+'</b><br/>'+r.kgha.toLocaleString('pt-BR')+' kg/ha'+(r.pct!=null?'<br/>Atingimento: <b>'+r.pct+'%</b> do plano':''); }}),
      xAxis:Object.assign({type:'value'}, axVal, {axisLabel:{color:C.muted, fontSize:10, fontFamily:MONO, formatter:function(v){return (v/1000)+'k';}}}),
      yAxis:Object.assign({type:'category', data:pr.rows.map(function(r){return r.nome;})}, axCat, {axisLabel:{color:C.deep, fontSize:11, fontWeight:600}}),
      series:[{name:'kg/ha', type:'bar', barWidth:14,
        data:pr.rows.map(function(r){ return {value:r.kgha, itemStyle:{color:cultColor(r.cultura), borderRadius:[0,4,4,0]}}; }),
        label:{show:true, position:'right', fontFamily:MONO, fontSize:10.5, color:C.muted, formatter:function(p){return Math.round(p.value/1000)+'k';}},
        markLine: pr.media!=null ? {silent:true, symbol:'none', data:[{xAxis:pr.media}],
          lineStyle:{color:C.danger, type:'dashed', width:1.5},
          label:{formatter:'média '+Math.round(pr.media/1000)+'k', color:C.danger, fontSize:10, position:'insideEndTop'}} : undefined
      }]
    }));
  }
  /* 5. Produtividade por variedade (kg/ha, barras verticais) */
  if(DEX.prodVar){ var pv = DEX.prodVar;
    mk('chProdVar', Object.assign({}, base, {
      grid:{left:8, right:12, top:16, bottom:26, containLabel:true},
      tooltip:Object.assign({}, base.tooltip, {trigger:'item', valueFormatter:function(v){return v.toLocaleString('pt-BR')+' kg/ha';}}),
      xAxis:Object.assign({type:'category', data:pv.map(function(r){return r.var;})}, axCat, {axisLabel:{color:C.deep, fontSize:10, fontWeight:600, interval:0, rotate:18}}),
      yAxis:Object.assign({type:'value'}, axVal, {axisLabel:{color:C.muted, fontSize:10, fontFamily:MONO, formatter:function(v){return (v/1000)+'k';}}}),
      series:[{name:'kg/ha', type:'bar', barWidth:'46%', data:pv.map(function(r){return r.kgha;}),
        itemStyle:{color:C.pos, borderRadius:[4,4,0,0]}}]
    }));
  }
  /* 6. Produtividade dos colaboradores (ranking por atividade selecionada) */
  var chColab = null;
  if(DEX.colab && DEX.colabKeys.length){
    var elChart = document.getElementById('chColab'), elSingle = document.getElementById('colabSingle');
    var elTitle = document.getElementById('colabTitle'), elHint = document.getElementById('colabHint');
    function renderColab(k){
      var a = DEX.colab[k]; if(!a) return;
      var rows = a.rows.slice().sort(function(x,y){ return x.r - y.r; }); /* asc p/ barra horizontal */
      elTitle.textContent = 'Ranking · ' + k;
      elHint.textContent = 'Rendimento médio em ' + a.un + ' · linha tracejada = média da equipe.';
      if(rows.length < 2){
        if(chColab){ chColab.dispose(); chColab=null; }
        elChart.style.display='none'; elSingle.style.display='';
        elSingle.innerHTML = rows.length ? ('Só uma pessoa com apontamento de ' + k.toLowerCase() + '.<br><strong class="num" style="font-size:20px;color:var(--acd)">' + nf(rows[0].r) + ' ' + a.un + '</strong><br>' + esc(rows[0].nome) + ' · ' + esc(rows[0].vinc)) : 'Sem dados para esta atividade.';
        return;
      }
      elSingle.style.display='none'; elChart.style.display='';
      var media = rows.reduce(function(s,x){ return s+x.r; }, 0) / rows.length;
      var top = Math.max.apply(null, rows.map(function(x){ return x.r; }));
      if(!chColab){ chColab = echarts.init(elChart, null, {renderer:'canvas'}); charts.push(chColab); }
      chColab.setOption(Object.assign({}, base, {
        grid:{left:8, right:60, top:10, bottom:6, containLabel:true},
        tooltip:Object.assign({}, base.tooltip, {trigger:'item', formatter:function(p){ var r=rows[p.dataIndex];
          return '<b>'+r.nome+'</b> <span style="color:'+C.muted+'">'+r.vinc+'</span><br/>Rendimento: <b>'+nf(r.r)+' '+a.un+'</b><br/>'+r.d+' dia(s) trabalhado(s)'; }}),
        xAxis:Object.assign({type:'value'}, axVal),
        yAxis:Object.assign({type:'category', data:rows.map(function(r){ return r.nome; })}, axCat, {axisLabel:{color:C.deep, fontSize:11, fontWeight:600}}),
        series:[{name:'rendimento', type:'bar', barWidth:15,
          data:rows.map(function(r){ return {value:r.r, itemStyle:{color:r.r===top?C.pos:C.a3, borderRadius:[0,4,4,0]}}; }),
          label:{show:true, position:'right', fontFamily:MONO, fontSize:10.5, color:C.muted, formatter:function(p){ return nf(p.value); }},
          markLine:{silent:true, symbol:'none', data:[{xAxis:Math.round(media)}],
            lineStyle:{color:C.danger, type:'dashed', width:1.5},
            label:{formatter:'média '+nf(Math.round(media)), color:C.danger, fontSize:10, position:'insideEndTop'}}
        }]
      }), true);
    }
    renderColab(DEX.colabKeys[0]);
    var seg = document.getElementById('segAtiv');
    if(seg) seg.addEventListener('click', function(e){ var b=e.target.closest('button'); if(!b) return;
      Array.prototype.forEach.call(seg.children, function(x){ x.classList.remove('on'); });
      b.classList.add('on'); renderColab(b.dataset.ativ); });
  }

  window.addEventListener('resize', function(){ charts.forEach(function(c){ if(c) c.resize(); }); });
  }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', __charts); else __charts();
})();
</script>
