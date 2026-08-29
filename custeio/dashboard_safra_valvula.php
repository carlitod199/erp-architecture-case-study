<?php
/* ============================================================
   VERO — Custos / Dashboard de custo × margem POR VÁLVULA  (A3+A4)
   Rota: /custeio/dashboard_safra_valvula.php
   Guard: custos.custo_talhao (reusa o slug de "Custo por Válvula" —
   quem enxerga custo por válvula vê este painel; evita novo seed de
   permissão, mesmo precedente de custeio/dashboard_custos.php).

   Itens de reunião V-01 e V-02 (docs/VERO_CORRECOES_REUNIAO_20260729.md),
   decisões D-4 e D-5:
     • Filtro por VÁLVULA (talhão) + SAFRA.
     • Custo REALIZADO por válvula (custeio_lancamentos — mesma fonte do
       dashboard_custos) + custo PREVISTO (orçado da safra, custeio_orcamentos;
       rateado por área quando uma válvula é filtrada — ver nota).
     • Faturamento PREVISTO = Σ(kg por categoria × preço por categoria) da
       PREVISÃO de colheita (D-5) = Σ colheita_registros.faturamento_previsto.
     • Lucro = faturamento previsto − custo realizado; margem = lucro ÷ fat.
     • EVOLUÇÃO SEMANAL da margem (D-5): custo realizado ACUMULADO semana a
       semana contra o faturamento previsto fixo (margem corrói à medida que
       o custo entra — "acompanhar antes de fechar a safra").
     • Card "onde o custo foi gasto" (ranking maior→menor). custeio_lancamentos
       NÃO tem coluna de produto — só `categoria`; portanto o ranking é por
       CATEGORIA (grão mais fino garantido pela fonte, igual aos demais painéis
       de custo). Ver nota no rodapé.
     • Card R$/kg (V-02/D-4) = custo realizado ÷ kg. kg PREVISTOS enquanto a
       safra está em andamento, REALIZADOS após o encerramento; rótulo indica
       a base em uso.

   NÃO toca em estoque/* (A-06 é de outro agente). Consome o custo que existe
   via custeio_lancamentos, exatamente como o dashboard vigente.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

/* ── Filtros: válvula + safra ─────────────────────────────── */
$safras    = vero_options('agro_safras', 'identificacao');
$safrasRot = vero_safra_rotulos($safras); /* P-04: id => rótulo curto (2026.2, sem "-NN") */
$talhoes   = vero_options('agro_talhoes', 'codigo');
$fSafra   = (int)($_GET['safra'] ?? 0);
if ($fSafra === 0 && $safras) $fSafra = (int)array_key_first($safras);
$fTalhao  = (int)($_GET['talhao'] ?? 0);

/* status da safra selecionada → decide a base de kg (D-4) */
$safraRow = $fSafra > 0
    ? vero_row("SELECT identificacao, status, data_fim FROM agro_safras WHERE id=:s AND tenant_id=:t",
        [':s' => $fSafra, ':t' => $t])
    : null;
$safraNome      = (string)($safraRow['identificacao'] ?? '');
$safraEncerrada = $safraRow !== null
    && (($safraRow['status'] ?? '') === 'encerrada' || !empty($safraRow['data_fim']));

/* WHERE combinável para as tabelas que têm talhao_id */
$wCusto = "cl.tenant_id = :t";
$pCusto = [':t' => $t];
if ($fSafra > 0)  { $wCusto .= " AND cl.safra_id = :s";  $pCusto[':s']  = $fSafra; }
if ($fTalhao > 0) { $wCusto .= " AND cl.talhao_id = :tl"; $pCusto[':tl'] = $fTalhao; }

$wColh = "cr.tenant_id = :t";
$pColh = [':t' => $t];
if ($fSafra > 0)  { $wColh .= " AND cr.safra_id = :s";  $pColh[':s']  = $fSafra; }
if ($fTalhao > 0) { $wColh .= " AND cr.talhao_id = :tl"; $pColh[':tl'] = $fTalhao; }

$rotuloCat = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));

/* ── 1) Custo REALIZADO + ranking POR PRODUTO ─────────────────
   custeio_lancamentos NÃO tem coluna de produto, mas cada lançamento guarda
   origem_tipo + origem_id, que amarram de volta ao produto quando a origem é
   de INSUMO:
     • 'apontamento_insumo' → agro_apontamento_insumos.produto_id → estoque_produtos
       (1 lançamento = 1 produto).
     • 'aplicacao'          → agro_aplicacao_itens (N produtos por documento) →
       estoque_produtos; o valor do lançamento (Σ dos itens) é RATEADO entre os
       itens pelo custo_total de cada item, para chegar ao produto.
   As demais origens NÃO têm produto (mão de obra: rh_producao_item/rh_folha_lancamento;
   máquinas: apontamento_maquina/maquina_abastecimento/maquina_manutencao;
   irrigacao_consumo; patrimonio_depreciacao; rateio_execucao) → caem no balde
   "Sem produto", de modo que o ranking FECHA exatamente com o custo realizado. */
$custoRealizado = (float)(vero_val(
    "SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl WHERE {$wCusto}", $pCusto) ?? 0);

/* drill: grupo de produtos clicado no card "Custo por grupo" (?grupo=id). null = todos. */
$fGrupo = (isset($_GET['grupo']) && $_GET['grupo'] !== '') ? (int)$_GET['grupo'] : null;

/* 1a) insumos do apontamento — produto direto (com grupo) */
$prodInsumo = vero_rows(
    "SELECT ep.nome AS produto, COALESCE(ep.grupo_id,0) AS grupo_id, COALESCE(gr.nome,'Sem grupo') AS grupo,
            SUM(cl.valor) AS total
       FROM custeio_lancamentos cl
       JOIN agro_apontamento_insumos ai ON ai.id = cl.origem_id AND ai.tenant_id = cl.tenant_id
       JOIN estoque_produtos ep ON ep.id = ai.produto_id AND ep.tenant_id = cl.tenant_id
       LEFT JOIN estoque_grupos gr ON gr.id = ep.grupo_id AND gr.tenant_id = ep.tenant_id
      WHERE {$wCusto} AND cl.origem_tipo = 'apontamento_insumo'
      GROUP BY ep.id, ep.nome, grupo_id, grupo", $pCusto);

/* 1b) aplicações — rateia o valor do lançamento entre os itens por custo_total (com grupo) */
$prodAplic = vero_rows(
    "SELECT ep.nome AS produto, COALESCE(ep.grupo_id,0) AS grupo_id, COALESCE(gr.nome,'Sem grupo') AS grupo,
            SUM(cl.valor * COALESCE(it.custo_total,0) / NULLIF(tot.soma,0)) AS total
       FROM custeio_lancamentos cl
       JOIN agro_aplicacao_itens it ON it.aplicacao_id = cl.origem_id AND it.tenant_id = cl.tenant_id
       JOIN (SELECT tenant_id, aplicacao_id, SUM(COALESCE(custo_total,0)) AS soma
               FROM agro_aplicacao_itens GROUP BY tenant_id, aplicacao_id) tot
             ON tot.aplicacao_id = cl.origem_id AND tot.tenant_id = cl.tenant_id
       JOIN estoque_produtos ep ON ep.id = it.produto_id AND ep.tenant_id = cl.tenant_id
       LEFT JOIN estoque_grupos gr ON gr.id = ep.grupo_id AND gr.tenant_id = ep.tenant_id
      WHERE {$wCusto} AND cl.origem_tipo = 'aplicacao'
      GROUP BY ep.id, ep.nome, grupo_id, grupo", $pCusto);

/* 1c) aplicações multi-válvula — o custeio é rateado POR VÁLVULA
   (origem_tipo='aplicacao_valvula', origem_id = agro_aplicacao_valvulas.id);
   resolve a aplicação pela linha DB-29 e rateia entre os itens como no 1b. */
$prodAplicValv = vero_rows(
    "SELECT ep.nome AS produto, COALESCE(ep.grupo_id,0) AS grupo_id, COALESCE(gr.nome,'Sem grupo') AS grupo,
            SUM(cl.valor * COALESCE(it.custo_total,0) / NULLIF(tot.soma,0)) AS total
       FROM custeio_lancamentos cl
       JOIN agro_aplicacao_valvulas av ON av.id = cl.origem_id AND av.tenant_id = cl.tenant_id
       JOIN agro_aplicacao_itens it ON it.aplicacao_id = av.aplicacao_id AND it.tenant_id = cl.tenant_id
       JOIN (SELECT tenant_id, aplicacao_id, SUM(COALESCE(custo_total,0)) AS soma
               FROM agro_aplicacao_itens GROUP BY tenant_id, aplicacao_id) tot
             ON tot.aplicacao_id = av.aplicacao_id AND tot.tenant_id = cl.tenant_id
       JOIN estoque_produtos ep ON ep.id = it.produto_id AND ep.tenant_id = cl.tenant_id
       LEFT JOIN estoque_grupos gr ON gr.id = ep.grupo_id AND gr.tenant_id = ep.tenant_id
      WHERE {$wCusto} AND cl.origem_tipo = 'aplicacao_valvula'
      GROUP BY ep.id, ep.nome, grupo_id, grupo", $pCusto);

$prodMap  = [];   /* nome => ['valor','grupo_id','grupo'] */
$grupoMap = [];   /* grupo_id => ['nome','valor'] */
foreach (array_merge($prodInsumo, $prodAplic, $prodAplicValv) as $r) {
    $nm = trim((string)($r['produto'] ?? ''));
    if ($nm === '') continue;
    $v    = (float)($r['total'] ?? 0);
    $gid  = (int)($r['grupo_id'] ?? 0);
    $gnm  = trim((string)($r['grupo'] ?? '')) ?: 'Sem grupo';
    if (!isset($prodMap[$nm]))  $prodMap[$nm]  = ['valor' => 0.0, 'grupo_id' => $gid, 'grupo' => $gnm];
    if (!isset($grupoMap[$gid])) $grupoMap[$gid] = ['nome' => $gnm, 'valor' => 0.0];
    $prodMap[$nm]['valor']   += $v;
    $grupoMap[$gid]['valor'] += $v;
}
$somaProdutos = array_sum(array_map(static fn($d) => $d['valor'], $prodMap));

/* grupos por custo (card clicável) */
uasort($grupoMap, static fn($a, $b) => $b['valor'] <=> $a['valor']);
$grupoTotal   = array_sum(array_map(static fn($g) => $g['valor'], $grupoMap));
$grupoNomeSel = ($fGrupo !== null && isset($grupoMap[$fGrupo])) ? $grupoMap[$fGrupo]['nome'] : null;

/* ranking de PRODUTOS — filtrado pelo grupo selecionado (drill) */
$TOP_N = 10;
$prodFiltrado = [];
foreach ($prodMap as $nm => $d) {
    if ($fGrupo !== null && $d['grupo_id'] !== $fGrupo) continue;
    if ($d['valor'] <= 0) continue;
    $prodFiltrado[$nm] = $d['valor'];
}
arsort($prodFiltrado);
$ranking = [];
$i = 0; $outrosProd = 0.0;
foreach ($prodFiltrado as $nm => $v) {
    if ($i < $TOP_N) $ranking[] = ['label' => $nm, 'valor' => round((float)$v, 2), 'tipo' => 'produto'];
    else             $outrosProd += (float)$v;
    $i++;
}
if ($outrosProd > 0.005) {
    $ranking[] = ['label' => 'Outros produtos', 'valor' => round($outrosProd, 2), 'tipo' => 'outros'];
}
$semProduto = $custoRealizado - $somaProdutos;

/* Detalhe do balde "Sem produto": agrupa os lançamentos SEM produto por origem,
   mapeada a rótulos de negócio (mão de obra, máquinas, irrigação, depreciação,
   rateio). Substitui a tabela de faturamento (que duplicava a composição). */
$mapaSemProd = [
    'rh_producao_item'       => 'Mão de obra',
    'rh_folha_lancamento'    => 'Mão de obra',
    'seed_demo_folha'        => 'Mão de obra',
    'apontamento_maquina'    => 'Máquinas',
    'maquina_abastecimento'  => 'Máquinas',
    'maquina_manutencao'     => 'Máquinas',
    'irrigacao_consumo'      => 'Irrigação',
    'patrimonio_depreciacao' => 'Depreciação',
    'rateio_execucao'        => 'Rateio',
];
$semProdDet = [];
foreach (vero_rows(
    "SELECT cl.origem_tipo, SUM(cl.valor) AS total
       FROM custeio_lancamentos cl
      WHERE {$wCusto} AND cl.origem_tipo NOT IN ('apontamento_insumo','aplicacao','aplicacao_valvula')
      GROUP BY cl.origem_tipo", $pCusto) as $r) {
    $v = (float)($r['total'] ?? 0);
    if ($v <= 0) continue;
    $lab = $mapaSemProd[(string)$r['origem_tipo']] ?? 'Outros';
    $semProdDet[$lab] = ($semProdDet[$lab] ?? 0) + $v;
}
arsort($semProdDet);
$semProdTotal = array_sum($semProdDet);

/* Custo realizado POR VÁLVULA (talhão) — substitui a evolução semanal da margem. */
$custoPorTalhao = vero_rows(
    "SELECT COALESCE(tl.codigo, tl.nome, CONCAT('Válvula #', cl.talhao_id)) AS valvula,
            SUM(cl.valor) AS total
       FROM custeio_lancamentos cl
       LEFT JOIN agro_talhoes tl ON tl.id = cl.talhao_id AND tl.tenant_id = cl.tenant_id
      WHERE {$wCusto} AND cl.talhao_id IS NOT NULL
      GROUP BY cl.talhao_id, valvula
      HAVING total > 0
      ORDER BY total DESC", $pCusto);
$custoTalhaoTotal = array_sum(array_map(static fn($r) => (float)$r['total'], $custoPorTalhao));

/* ── 2) Custo PREVISTO (orçado da safra) ──────────────────────
   Fonte real do orçado: custeio_orcamentos + custeio_orcamento_itens
   (mesma que custeio/realizado.php). É por SAFRA — NÃO tem dimensão de
   válvula. Quando uma válvula é filtrada, rateamos o orçado da safra pela
   participação de ÁREA PLANTADA da válvula (agro_safra_talhoes) — rótulo
   deixa isso explícito. Sem orçamento cadastrado → custo previsto = null. */
$custoPrevisto = null;
$custoPrevistoBase = 'orçado da safra';
if ($fSafra > 0) {
    $orc = vero_row(
        "SELECT id, status FROM custeio_orcamentos
          WHERE tenant_id = :t AND safra_id = :s
          ORDER BY FIELD(status,'vigente','rascunho','encerrado'), id DESC LIMIT 1",
        [':t' => $t, ':s' => $fSafra]);
    if ($orc) {
        $orcSafra = (float)(vero_val(
            "SELECT COALESCE(SUM(valor_previsto),0) FROM custeio_orcamento_itens
              WHERE tenant_id = :t AND orcamento_id = :o", [':t' => $t, ':o' => (int)$orc['id']]) ?? 0);
        if ($fTalhao > 0) {
            $areaSafra = (float)(vero_val(
                "SELECT COALESCE(SUM(area_plantada_ha),0) FROM agro_safra_talhoes
                  WHERE tenant_id = :t AND safra_id = :s", [':t' => $t, ':s' => $fSafra]) ?? 0);
            $areaValv = (float)(vero_val(
                "SELECT COALESCE(SUM(area_plantada_ha),0) FROM agro_safra_talhoes
                  WHERE tenant_id = :t AND safra_id = :s AND talhao_id = :tl",
                [':t' => $t, ':s' => $fSafra, ':tl' => $fTalhao]) ?? 0);
            $custoPrevisto = $areaSafra > 0 ? round($orcSafra * $areaValv / $areaSafra, 2) : null;
            $custoPrevistoBase = 'orçado da safra rateado por área da válvula';
        } else {
            $custoPrevisto = round($orcSafra, 2);
        }
    }
}

/* ── 3) Faturamento PREVISTO (D-5) — Σ(kg×preço/categoria) da previsão ──
   colheita_registros.faturamento_previsto já é a Σ das classificações
   momento='previsto' (kg_calculado × preco_kg). Total + composição por
   categoria (para o card de composição). */
$fatPrevisto = (float)(vero_val(
    "SELECT COALESCE(SUM(cr.faturamento_previsto),0) FROM colheita_registros cr WHERE {$wColh}",
    $pColh) ?? 0);

$fatCatRows = vero_rows(
    "SELECT cc.categoria AS categoria,
            COALESCE(SUM(cc.kg_calculado),0) AS kg,
            COALESCE(SUM(cc.faturamento),0)  AS fat
       FROM colheita_classificacoes cc
       JOIN colheita_registros cr ON cr.id = cc.registro_id AND cr.tenant_id = cc.tenant_id
      WHERE {$wColh} AND cc.momento = 'previsto'
      GROUP BY cc.categoria
      ORDER BY fat DESC", $pColh);
$fatComposicao = [];
foreach ($fatCatRows as $r) {
    $fat = (float)($r['fat'] ?? 0);
    if ($fat <= 0 && (float)($r['kg'] ?? 0) <= 0) continue;
    $fatComposicao[] = [
        'label' => $rotuloCat((string)($r['categoria'] ?? 'outros')),
        'kg'    => round((float)($r['kg'] ?? 0), 0),
        'fat'   => round($fat, 2),
    ];
}

/* ── 4) kg base (D-4) — previsto até fechar a safra, realizado depois ── */
$kgPrev = (float)(vero_val(
    "SELECT COALESCE(SUM(cr.kg_total_previsto),0)  FROM colheita_registros cr WHERE {$wColh}", $pColh) ?? 0);
$kgReal = (float)(vero_val(
    "SELECT COALESCE(SUM(cr.kg_total_realizado),0) FROM colheita_registros cr WHERE {$wColh}", $pColh) ?? 0);
$kgBase      = $safraEncerrada ? $kgReal : $kgPrev;
$kgBaseLabel = $safraEncerrada ? 'realizados (safra encerrada)' : 'previstos (safra em andamento)';

/* ── 5) Lucro / margem (faturamento previsto − custo realizado) ── */
$lucro  = $fatPrevisto - $custoRealizado;
$margem = $fatPrevisto > 0 ? $lucro / $fatPrevisto * 100 : null;

/* ── 6) R$/kg (V-02) = custo realizado ÷ kg base ── */
$rsPorKg = $kgBase > 0 ? $custoRealizado / $kgBase : null;

/* ── 7) Evolução SEMANAL da margem (D-5) ──────────────────────
   Custo realizado ACUMULADO por semana (YEARWEEK ISO) confrontado com o
   faturamento previsto fixo. margem_semana = (fatPrev − custoAcum) ÷ fatPrev. */
$semRows = vero_rows(
    "SELECT YEARWEEK(cl.data_competencia, 3) AS yw,
            MIN(cl.data_competencia) AS wk,
            SUM(cl.valor) AS v
       FROM custeio_lancamentos cl
      WHERE {$wCusto} AND cl.data_competencia IS NOT NULL
      GROUP BY yw
      ORDER BY yw", $pCusto);
$serie = ['labels' => [], 'custoAcum' => [], 'margem' => []];
$acum = 0.0;
foreach ($semRows as $r) {
    $acum += (float)($r['v'] ?? 0);
    $serie['labels'][]    = date('d/m/y', strtotime((string)($r['wk'] ?? 'now')));
    $serie['custoAcum'][] = round($acum, 2);
    $serie['margem'][]    = $fatPrevisto > 0 ? round(($fatPrevisto - $acum) / $fatPrevisto * 100, 2) : null;
}
$temSerie = count($serie['labels']) > 0 && $fatPrevisto > 0;

/* ranking reconcilia com o custo realizado; com grupo filtrado, % relativo ao grupo */
$rankTotal = $fGrupo !== null ? array_sum($prodFiltrado) : $custoRealizado;

/* Modo EMBED: quando incluído por outra tela (ex.: aba do Dashboard Operacional),
   $EMBED === true e o chrome (guard/header/footer) é do host — aqui só renderiza o
   corpo. O host DEVE ter checado a permissão custos.custo_talhao antes de incluir. */
$EMBED = !empty($EMBED);
if (!$EMBED) {
    $GUARD      = ['macro' => 'custos', 'micro' => 'custo_talhao'];
    $PAGE_VIEW  = 'custos_dashboard';
    $PAGE_TITLE = 'Custo × Margem por Válvula';
    $EXTRA_HEAD = vero_assets();
    require __DIR__ . '/../includes/agro_header.php';
}

$base = rtrim(BIOS_BASE, '/');
$brl  = static fn($v, int $d = 2): string => $v === null ? '—' : 'R$ ' . numFmt((float)$v, $d);
?>
<style>
/* ===== Custo × Margem por Válvula — escopado em .dsv (tokens VERO) ===== */
.dsv{--ac:#005059;--acd:#00363D;--ac3:#2A767C;--warm:#FBF8F2;--track:#EEE6D6;
  --ink:#241B14;--ink2:#2B2018;--mut:#8A7C68;--mut2:#9A8C78;--bd:#E3D9C8;--bd2:#DDD2BF;
  --pos:#0E7E72;--pos-bg:#DDEDEB;--amber:#B57C1A;--amber-d:#7A5410;--amber-bg:#F3E7C8;
  --danger:#B23A2E;--danger-bg:#F2DCD8;--num:'IBM Plex Mono',ui-monospace,monospace;
  position:relative;overflow:hidden;
  background:linear-gradient(180deg,rgba(237,234,224,0) 0%,rgba(237,234,224,.06) 24%,rgba(237,234,224,.55) 62%,rgba(237,234,224,.85) 100%),
             url('<?= $base ?>/assets/img/dashboard-banner.webp') right top/cover no-repeat}
.dsv .kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.dsv .kpi{background:rgba(255,255,255,.66);border:1px solid var(--bd);border-radius:13px;padding:16px 18px;
  box-shadow:0 1px 2px rgba(36,27,20,.05);position:relative;overflow:hidden;min-width:0}
.dsv .kpi .strip{position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--ac3)}
.dsv .kpi .lab{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--mut);font-weight:700;margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dsv .kpi .val{font-family:var(--num);font-size:25px;font-weight:700;line-height:1.05;letter-spacing:-.5px;white-space:nowrap;color:var(--ink)}
.dsv .kpi .sub{font-size:10px;color:var(--mut);margin-top:5px;font-weight:500;white-space:normal}
.dsv .kpi.pos .strip{background:var(--pos)}.dsv .kpi.pos .val{color:var(--pos)}
.dsv .kpi.danger .strip{background:var(--danger)}.dsv .kpi.danger .val{color:var(--danger)}
.dsv .kpi.amber .strip{background:var(--amber)}.dsv .kpi.amber .val{color:var(--amber-d)}
.dsv .kpi.accent .strip{background:var(--ac)}.dsv .kpi.accent .val{color:var(--acd)}
.dsv .fgrid{display:grid;gap:16px}
.dsv .g2{grid-template-columns:1.5fr 1fr}
.dsv .fcard{background:#fff;border:1px solid var(--bd);border-radius:13px;box-shadow:0 1px 2px rgba(36,27,20,.05);
  padding:16px 18px 12px;display:flex;flex-direction:column;min-width:0}
.dsv .fcard h3{font-size:14.5px;font-weight:700;letter-spacing:-.2px;color:var(--ink2)}
.dsv .fcard .desc{font-size:11.5px;color:var(--mut);font-weight:500;margin-top:2px}
.dsv .fchart{width:100%}
.dsv .mt16{margin-top:16px}
.dsv .rank{padding:6px 2px 4px}
.dsv .rrow{display:grid;grid-template-columns:190px 1fr 120px;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid var(--bd)}
.dsv .rrow:last-child{border-bottom:0}
.dsv .rrow .nm{font-weight:700;font-size:12.5px;color:var(--ink);line-height:1.25}
.dsv .rrow.resid .nm{font-weight:600;color:var(--mut)}
.dsv .rtr{position:relative;height:16px;border-radius:5px;background:var(--track);overflow:hidden}
.dsv .rfill{position:absolute;left:0;top:0;bottom:0;border-radius:5px;background:var(--ac)}
.dsv .rfill.mut{background:var(--mut2)}
.dsv .rval{text-align:right;font-family:var(--num);font-size:12.5px;font-weight:600;color:var(--ink)}
.dsv a.rrow-link{text-decoration:none;color:inherit;cursor:pointer;transition:background .12s;border-radius:8px;margin:0 -8px;padding-left:8px;padding-right:8px}
.dsv a.rrow-link:hover{background:var(--warm)}
.dsv a.rrow-link.sel{background:var(--pos-bg)}
.dsv a.rrow-link.sel .nm{color:var(--pos)}
.dsv .fempty{padding:34px 14px;text-align:center;color:var(--mut2);font-size:13px}
.dsv .fnote{padding:12px 2px;font-size:11.5px;color:var(--mut2)}
.dsv table.dt{width:100%;border-collapse:collapse;font-size:13px}
.dsv .dt th{text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;
  color:var(--mut);background:var(--warm);padding:9px 12px;border-bottom:1px solid var(--bd)}
.dsv .dt td{padding:9px 12px;border-bottom:1px solid var(--track)}
.dsv .dt tr:last-child td{border-bottom:none}
.dsv .dt .num{font-family:var(--num);font-weight:600;text-align:right;white-space:nowrap}
.dsv .dt th.num{text-align:right}
@media(max-width:1080px){.dsv .g2{grid-template-columns:1fr}}
@media(max-width:560px){.dsv .kpis{grid-template-columns:1fr}}
</style>

<div class="vwrap dsv">
  <?= $DOPS_TABS ?? '' ?>
  <?= vero_flash_html() ?>

  <div class="vcard" style="margin-bottom:16px">
    <form method="get" class="vtoolbar" style="flex-wrap:wrap;gap:8px;align-items:center">
      <?php if ($EMBED): ?><input type="hidden" name="aba" value="custo"><?php endif; ?>
      <label class="vhint">Safra</label>
      <select name="safra" onchange="this.form.submit()">
        <option value="">— Selecione —</option>
        <?php foreach ($safras as $sid => $sn): ?>
          <option value="<?= $sid ?>"<?= $fSafra === (int)$sid ? ' selected' : '' ?>><?= h($safrasRot[$sid] ?? $sn) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="vhint">Válvula</label>
      <select name="talhao" onchange="this.form.submit()">
        <option value="">Todas as válvulas</option>
        <?php foreach ($talhoes as $id => $n): ?>
          <option value="<?= $id ?>"<?= $fTalhao === (int)$id ? ' selected' : '' ?>><?= h($n) ?></option>
        <?php endforeach; ?>
      </select>
      <span style="flex:1"></span>
      <span class="vsub">
        <?= $safraNome !== '' ? 'Safra <strong>' . h(vero_safra_rotulo($safraNome)) . '</strong>' : 'todas as safras' ?>
        · <span class="vbadge <?= $safraEncerrada ? 'vb-off' : 'vb-ok' ?>"><?= $safraEncerrada ? 'encerrada' : 'em andamento' ?></span>
      </span>
    </form>
  </div>

  <!-- KPIs -->
  <div class="kpis">
    <div class="kpi accent"><div class="strip"></div>
      <div class="lab">Custo de produção</div>
      <div class="val"><?= $brl($custoRealizado) ?></div></div>
    <div class="kpi pos"><div class="strip"></div>
      <div class="lab">Faturamento</div>
      <div class="val"><?= $brl($fatPrevisto) ?></div></div>
    <div class="kpi <?= $lucro < 0 ? 'danger' : 'pos' ?>"><div class="strip"></div>
      <div class="lab">Lucro</div>
      <div class="val"><?= $brl($lucro) ?></div></div>
    <div class="kpi <?= $margem === null ? '' : ($margem < 0 ? 'danger' : ($margem < 20 ? 'amber' : 'pos')) ?>"><div class="strip"></div>
      <div class="lab">Margem</div>
      <div class="val"><?= $margem !== null ? numFmt($margem, 1) . '%' : '—' ?></div></div>
  </div>

  <?php if ($fSafra === 0): ?>
    <div class="vcard"><div class="fempty">Selecione uma safra para ver a evolução da margem e a composição.</div></div>
  <?php endif; ?>

  <!-- Linha 1: custo por válvula + custo detalhado por atividade (hbar) -->
  <div class="fgrid g2">
    <div class="fcard">
      <div><h3>Custo por válvula</h3></div>
      <?php if ($custoPorTalhao): ?>
        <div class="fchart" id="dsvCV"></div>
      <?php else: ?>
        <div class="fempty">Nenhum custo lançado por válvula nesta safra.</div>
      <?php endif; ?>
    </div>
    <div class="fcard">
      <div><h3>Custo detalhado por atividade</h3></div>
      <?php if ($semProdDet): ?>
        <div class="fchart" id="dsvCA"></div>
      <?php else: ?>
        <div class="fempty">Sem custos fora de produto (mão de obra, máquinas, irrigação…) nesta safra.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Linha 2: custo por grupo (pizza clicável) + custo detalhado por produto (drill do grupo) -->
  <div class="fgrid g2 mt16">
    <div class="fcard">
      <div><h3>Custo por grupo de produtos</h3><div class="desc">clique numa fatia para detalhar os produtos ao lado</div></div>
      <?php if ($grupoMap): ?>
        <div class="fchart" id="dsvCG" style="height:320px"></div>
      <?php else: ?>
        <div class="fempty">Nenhum custo de produto nesta válvula/safra.</div>
      <?php endif; ?>
    </div>
    <div class="fcard">
      <div><h3 id="dsvCPtitle">Custo detalhado por produto</h3>
        <div class="desc" id="dsvCPall" style="display:none"><a href="#">← todos os grupos</a></div></div>
      <?php if ($prodMap): ?>
        <div class="fchart" id="dsvCP"></div>
      <?php else: ?>
        <div class="fempty">Nenhum lançamento de custeio nesta válvula/safra.</div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php
/* payload dos 4 gráficos (hbar + pizza de grupo com drill client-side, sem reload) */
$jsValv = array_map(static fn($r) => ['nome' => (string)$r['valvula'], 'valor' => round((float)$r['total'], 2)], $custoPorTalhao);
$jsAtiv = [];
foreach ($semProdDet as $lab => $v) $jsAtiv[] = ['nome' => (string)$lab, 'valor' => round((float)$v, 2)];
$jsGrupos = [];
foreach ($grupoMap as $gid => $g) { if ((float)$g['valor'] > 0) $jsGrupos[] = ['gid' => (int)$gid, 'nome' => (string)$g['nome'], 'valor' => round((float)$g['valor'], 2)]; }
$jsProds = [];
foreach ($prodMap as $nm => $d) { if ((float)$d['valor'] > 0) $jsProds[] = ['nome' => (string)$nm, 'gid' => (int)$d['grupo_id'], 'valor' => round((float)$d['valor'], 2)]; }
?>
<script defer src="<?= $base ?>/assets/vendor/echarts/echarts.min.js"></script>
<script>
(function(){
  var VALV=<?= jsvar($jsValv) ?>, ATIV=<?= jsvar($jsAtiv) ?>, GRUPOS=<?= jsvar($jsGrupos) ?>, PRODS=<?= jsvar($jsProds) ?>;
  var C = {ac:'#005059',acd:'#00363D',ac3:'#2A767C',pos:'#0E7E72',amber:'#B57C1A',
           danger:'#B23A2E',track:'#EEE6D6',bd:'#E3D9C8',bd2:'#DDD2BF',mut:'#8A7C68',ink:'#241B14',surface:'#fff'};
  var MONO = "'IBM Plex Mono',ui-monospace,monospace";
  var PAL = [C.ac, C.amber, C.ac3, C.pos, '#7A4E9E', '#C3B49E', '#B26A3A', '#4E7CA1'];
  var brl  = function(v){ return v==null ? '—' : 'R$ ' + Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); };
  var kAxis= function(v){ return Math.abs(v)>=1000 ? (v/1000).toLocaleString('pt-BR',{maximumFractionDigits:1})+'k' : String(v); };
  var tip  = { backgroundColor:C.surface, borderColor:C.bd, textStyle:{color:C.ink,fontSize:12},
    extraCssText:'box-shadow:0 8px 24px -12px rgba(8,38,42,.35);border-radius:9px' };
  var charts = [];
  function hbar(id, rows, color){
    var el=document.getElementById(id); if(!el) return;
    if(!rows||!rows.length){ el.style.display='none'; return; }
    el.style.display='';
    var asc=rows.slice().sort(function(a,b){return a.valor-b.valor;});
    el.style.height=Math.max(150, asc.length*32+24)+'px';
    var ch=echarts.getInstanceByDom(el)||echarts.init(el);
    if(charts.indexOf(ch)<0) charts.push(ch);
    ch.setOption({textStyle:{fontFamily:'inherit'},
      grid:{left:6,right:78,top:8,bottom:6,containLabel:true},
      tooltip:Object.assign({trigger:'item',formatter:function(p){return '<b>'+p.name+'</b><br/>'+brl(p.value);}}, tip),
      xAxis:{type:'value',axisLine:{show:false},axisTick:{show:false},splitLine:{show:false},axisLabel:{show:false}},
      yAxis:{type:'category',data:asc.map(function(r){return r.nome;}),axisLine:{lineStyle:{color:C.bd2}},axisTick:{show:false},axisLabel:{color:C.acd,fontSize:11,fontWeight:600}},
      series:[{type:'bar',barWidth:15,data:asc.map(function(r){return {value:r.valor,itemStyle:{color:color||C.ac,borderRadius:[0,4,4,0]}};}),
        label:{show:true,position:'right',fontFamily:MONO,fontSize:10,color:C.mut,formatter:function(p){return brl(p.value);}}}]
    }, true);
  }
  function donut(id, rows){
    var el=document.getElementById(id); if(!el) return;
    if(!rows||!rows.length){ el.style.display='none'; return; }
    var total=rows.reduce(function(s,r){return s+r.valor;},0);
    var ch=echarts.getInstanceByDom(el)||echarts.init(el);
    if(charts.indexOf(ch)<0) charts.push(ch);
    ch.setOption({
      tooltip:Object.assign({trigger:'item',formatter:function(p){return '<b>'+p.name+'</b><br/>'+brl(p.value)+' · '+p.percent+'%';}}, tip),
      legend:{orient:'vertical',right:2,top:'center',icon:'circle',textStyle:{color:C.mut,fontSize:11},itemGap:8},
      series:[{type:'pie',radius:['52%','76%'],center:['33%','50%'],avoidLabelOverlap:true,cursor:'pointer',
        itemStyle:{borderColor:C.surface,borderWidth:3,borderRadius:4},
        label:{show:true,position:'center',formatter:function(){return '{v|'+(total>=1000?'R$ '+Math.round(total/1000)+'k':brl(total))+'}\n{l|total}';},
          rich:{v:{fontSize:18,fontWeight:700,fontFamily:MONO,color:C.ink},l:{fontSize:11,color:C.mut,padding:[4,0,0,0]}}},
        labelLine:{show:false},
        data:rows.map(function(r,i){return {name:r.nome,value:r.valor,gid:r.gid,itemStyle:{color:PAL[i%PAL.length]}};})}]
    }, true);
    ch.off('click'); ch.on('click', function(p){ if(p.data && p.data.gid!=null) renderProd(p.data.gid); });
  }
  function rankProd(gid){
    var list=PRODS.filter(function(p){return gid==null||p.gid===gid;}).slice().sort(function(a,b){return b.valor-a.valor;});
    var TOP=10, top=list.slice(0,TOP), rest=list.slice(TOP);
    if(rest.length){ top.push({nome:'Outros produtos',valor:rest.reduce(function(s,x){return s+x.valor;},0)}); }
    return top;
  }
  function renderProd(gid){
    var g=GRUPOS.filter(function(x){return x.gid===gid;})[0];
    var t=document.getElementById('dsvCPtitle'); if(t) t.textContent='Custo detalhado por produto'+(g?' · '+g.nome:'');
    var all=document.getElementById('dsvCPall'); if(all) all.style.display=(gid==null?'none':'');
    hbar('dsvCP', rankProd(gid), C.ac);
  }
  function boot(){
    if(typeof echarts==='undefined') return;
    hbar('dsvCV', VALV, C.ac3);
    hbar('dsvCA', ATIV, C.amber);
    donut('dsvCG', GRUPOS);
    renderProd(null);
    var all=document.getElementById('dsvCPall');
    if(all){ var a=all.querySelector('a'); if(a) a.addEventListener('click',function(e){e.preventDefault();renderProd(null);}); }
    window.addEventListener('resize',function(){ charts.forEach(function(c){ if(c) c.resize(); }); });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot); else boot();
})();
</script>

<?php if (!$EMBED) require __DIR__ . '/../includes/agro_footer_simple.php';
