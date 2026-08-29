<?php
/* ============================================================
   VERO — Custos / Custo por Válvula  (tela real, leitura)
   Substitui o mock. Rota: /custeio/custos.php
   Guard: custos.custo_talhao
   Leitura consolidada de custeio_lancamentos: válvula × categoria,
   com custo/ha e filtro de safra. Os lançamentos nascem nos módulos
   de origem (apontamentos, insumos, máquinas, irrigação…).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$fSafra = (int)($_GET['safra'] ?? 0);
$fIni   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';

$where  = "cl.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($fSafra > 0) { $where .= " AND cl.safra_id = :s";           $params[':s'] = $fSafra; }
if ($fIni !== '') { $where .= " AND cl.data_competencia >= :i"; $params[':i'] = $fIni; }
if ($fFim !== '') { $where .= " AND cl.data_competencia <= :f"; $params[':f'] = $fFim; }

/* categorias presentes (colunas dinâmicas) */
$categorias = array_map('strval', array_column(vero_rows(
    "SELECT DISTINCT COALESCE(cl.categoria,'outros') AS categoria
       FROM custeio_lancamentos cl WHERE {$where} ORDER BY categoria", $params), 'categoria'));

/* matriz válvula × categoria */
$linhas = vero_rows(
    "SELECT cl.talhao_id, COALESCE(cl.categoria,'outros') AS categoria, SUM(cl.valor) AS total,
            t.codigo AS talhao, f.nome AS fazenda, t.area_ha
       FROM custeio_lancamentos cl
       LEFT JOIN agro_talhoes t ON t.id = cl.talhao_id
       LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
      WHERE {$where}
      GROUP BY cl.talhao_id, categoria, t.codigo, f.nome, t.area_ha", $params);

$porTalhao = [];
foreach ($linhas as $l) {
    $chave = $l['talhao_id'] !== null ? (int)$l['talhao_id'] : 0;
    if (!isset($porTalhao[$chave])) {
        $porTalhao[$chave] = [
            'rotulo' => $l['talhao_id'] !== null ? ($l['fazenda'] . ' — ' . $l['talhao']) : 'Sem válvula',
            'area'   => $l['area_ha'] !== null ? (float)$l['area_ha'] : null,
            'cats'   => [], 'total' => 0.0,
        ];
    }
    $porTalhao[$chave]['cats'][(string)$l['categoria']] = (float)$l['total'];
    $porTalhao[$chave]['total'] += (float)$l['total'];
}
uasort($porTalhao, static fn($a, $b) => $b['total'] <=> $a['total']);
$totalGeral = array_sum(array_column($porTalhao, 'total'));

$safras = vero_options('agro_safras', 'identificacao');

$rotuloCat = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));

/* ── DETALHAMENTO: drill-down nos lançamentos que
   formam cada número da matriz. ?detalhe=<talhao_id> (ou 'sem' p/ lançamentos
   sem válvula) + &cat=<categoria> opcional. Mesmos filtros da matriz. ── */
const CUSTOS_ORIGEM_ROTULO = [
    'aplicacao'              => 'Aplicação (DF/IF)',
    'aplicacao_valvula'      => 'Aplicação — rateio por válvula',
    'apontamento_insumo'     => 'Insumo do apontamento',
    'apontamento_maquina'    => 'Máquina do apontamento',
    'irrigacao_consumo'      => 'Irrigação (consumo)',
    'maquina_abastecimento'  => 'Abastecimento de máquina',
    'maquina_manutencao'     => 'Manutenção de máquina',
    'patrimonio_depreciacao' => 'Depreciação',
    'rateio_execucao'        => 'Rateio',
    'rh_producao_item'       => 'Mão de obra (produção)',
    'folha'                  => 'Folha de pagamento',
];
$detalheRaw = (string)($_GET['detalhe'] ?? '');
$detalhe    = $detalheRaw === '' ? null : ($detalheRaw === 'sem' ? 0 : max(0, (int)$detalheRaw));
$fCat       = (string)($_GET['cat'] ?? '');
$detLancs = [];
$detTotal = 0.0;
if ($detalhe !== null) {
    $whereDet  = $where . ($detalhe === 0 ? " AND cl.talhao_id IS NULL" : " AND cl.talhao_id = :tl");
    $paramsDet = $params + ($detalhe === 0 ? [] : [':tl' => $detalhe]);
    if ($fCat !== '') { $whereDet .= " AND COALESCE(cl.categoria,'outros') = :cat"; $paramsDet[':cat'] = $fCat; }
    $detLancs = vero_rows(
        "SELECT cl.data_competencia, COALESCE(cl.categoria,'outros') AS categoria,
                cl.origem_tipo, cl.origem_id, cl.quantidade, cl.valor,
                sa.identificacao AS safra
           FROM custeio_lancamentos cl
           LEFT JOIN agro_safras sa ON sa.id = cl.safra_id AND sa.tenant_id = cl.tenant_id
          WHERE {$whereDet}
          ORDER BY cl.data_competencia DESC, cl.id DESC LIMIT 1000", $paramsDet);
    $detTotal = array_sum(array_map(static fn($l) => (float)$l['valor'], $detLancs));
}
$rotuloOrigem = static fn(?string $o): string =>
    CUSTOS_ORIGEM_ROTULO[(string)$o] ?? ucfirst(str_replace('_', ' ', (string)($o ?? 'manual')));

/* MÃO DE OBRA mais detalhada: para lançamentos vindos de
   rh_producao_itens, enriquece a linha com QUEM (colaborador/terceirizado),
   a ATIVIDADE do apontamento, modalidade e a conta qtde × valor unitário.
   Uma query em lote — nada por linha. */
$detExtra = [];
if ($detLancs) {
    $idsRh = [];
    foreach ($detLancs as $l) {
        if ((string)$l['origem_tipo'] === 'rh_producao_item' && $l['origem_id'] !== null) {
            $idsRh[] = (int)$l['origem_id'];
        }
    }
    if ($idsRh) {
        $in = implode(',', array_map('intval', array_unique($idsRh)));
        foreach (vero_rows(
            "SELECT ri.id, ri.modalidade, ri.unidade, ri.quantidade, ri.valor_unitario, ri.meta_aplicada,
                    COALESCE(op.nome, tz.nome, '—') AS pessoa,
                    ri.origem_pessoa, ta.nome AS atividade
               FROM rh_producao_itens ri
               LEFT JOIN agro_operadores op ON op.id = ri.operador_id AND op.tenant_id = ri.tenant_id
               LEFT JOIN rh_terceirizados tz ON tz.id = ri.terceirizado_id AND tz.tenant_id = ri.tenant_id
               LEFT JOIN agro_apontamentos ap ON ap.id = ri.apontamento_id AND ap.tenant_id = ri.tenant_id
               LEFT JOIN agro_tipos_atividade ta ON ta.id = ap.tipo_atividade_id AND ta.tenant_id = ri.tenant_id
              WHERE ri.tenant_id = :t AND ri.id IN ({$in})",
            [':t' => vero_tenant()]) as $ri) {
            $partes = ['<strong>' . h((string)$ri['pessoa']) . '</strong>'
                . ((string)$ri['origem_pessoa'] === 'terceirizado' ? ' <span class="vhint">(terceirizado)</span>' : '')];
            if ($ri['atividade'] !== null) $partes[] = h((string)$ri['atividade']);
            if ($ri['modalidade'] !== null) $partes[] = h(ucfirst((string)$ri['modalidade']));
            if ($ri['quantidade'] !== null) {
                $conta = numFmt((float)$ri['quantidade'], 0) . ' ' . h((string)($ri['unidade'] ?? ''));
                if ($ri['valor_unitario'] !== null) $conta .= ' × R$ ' . numFmt((float)$ri['valor_unitario'], 2);
                if ($ri['meta_aplicada'] !== null) $conta .= ' <span class="vhint">(meta ' . numFmt((float)$ri['meta_aplicada'], 0) . ')</span>';
                $partes[] = $conta;
            }
            $detExtra['rh_producao_item-' . (int)$ri['id']] = implode(' · ', $partes);
        }
    }
}

$GUARD      = ['macro' => 'custos', 'micro' => 'custo_talhao'];
$PAGE_VIEW  = 'custos_custo_talhao';
$PAGE_TITLE = 'Custo por Válvula';

/* ── Export CSV (antes de qualquer HTML) ─────────────────────────
   Relatório read-only: baixa a MESMA matriz consolidada já filtrada.
   Reusa o helper compartilhado vero_csv_stream (compras/_export.php) e
   o guard canônico bios_guard — sem passar pelo header, então o guard
   é chamado manualmente (mesma proteção da tela). */
if (($_GET['csv'] ?? '') === '1') {
    require_once __DIR__ . '/../includes/menu_agro.php';
    bios_guard($GUARD['macro'], $GUARD['micro']);
    require_once __DIR__ . '/../compras/_export.php';
    /* CSV do DETALHE (drill-down): os lançamentos filtrados, não a matriz */
    if ($detalhe !== null) {
        $rowsDet = [];
        foreach ($detLancs as $l) {
            $rowsDet[] = [
                'data'      => $l['data_competencia'],
                'safra'     => $l['safra'],
                'categoria' => $rotuloCat((string)$l['categoria']),
                'origem'    => $rotuloOrigem($l['origem_tipo']) . ($l['origem_id'] !== null ? ' #' . (int)$l['origem_id'] : ''),
                'qtde'      => $l['quantidade'],
                'valor'     => $l['valor'],
            ];
        }
        vero_csv_stream('custeio', 'custo_valvula_detalhe', $rowsDet,
            ['data' => 'Data', 'safra' => 'Safra', 'categoria' => 'Categoria',
             'origem' => 'Origem', 'qtde' => 'Qtde', 'valor' => 'Valor (R$)'],
            ['data' => 'data', 'qtde' => 'dec2', 'valor' => 'dec2']);
    }
    $colunas = ['rotulo' => 'Válvula', 'area' => 'Área (ha)'];
    $formato = ['area' => 'dec2'];
    foreach ($categorias as $cat) { $colunas['cat_' . $cat] = $rotuloCat($cat); $formato['cat_' . $cat] = 'dec2'; }
    $colunas += ['total' => 'Total (R$)', 'custo_ha' => 'Custo/ha (R$)', 'participacao' => 'Participação (%)'];
    $formato += ['total' => 'dec2', 'custo_ha' => 'dec2', 'participacao' => 'dec2'];
    $rowsCsv = [];
    foreach ($porTalhao as $tl) {
        $r = ['rotulo' => $tl['rotulo'], 'area' => $tl['area']];
        foreach ($categorias as $cat) { $r['cat_' . $cat] = $tl['cats'][$cat] ?? ''; }
        $r['total']        = $tl['total'];
        $r['custo_ha']     = ($tl['area'] > 0) ? $tl['total'] / $tl['area'] : '';
        $r['participacao'] = $totalGeral > 0 ? $tl['total'] / $totalGeral * 100 : 0;
        $rowsCsv[] = $r;
    }
    vero_csv_stream('custeio', 'custo_valvula', $rowsCsv, $colunas, $formato);
}

$qsBase = http_build_query(array_filter(['safra' => $fSafra ?: null, 'ini' => $fIni ?: null, 'fim' => $fFim ?: null]));

$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Custo por Válvula', 'Consolidação do custeio por válvula e categoria — alimentado pelos apontamentos, insumos e demais origens', null) ?>

  <?php if ($detalhe !== null): /* ── DETALHAMENTO: lançamentos da válvula ── */
      $detRotulo = $detalhe === 0 ? 'Sem válvula'
          : (string)(vero_val(
              "SELECT CONCAT(f.nome, ' — ', t.codigo) FROM agro_talhoes t
                 LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
                WHERE t.id = :i AND t.tenant_id = :t",
              [':i' => $detalhe, ':t' => vero_tenant()]) ?? 'Válvula #' . $detalhe);
      $qsDet = http_build_query(array_filter([
          'safra' => $fSafra ?: null, 'ini' => $fIni ?: null, 'fim' => $fFim ?: null,
          'detalhe' => $detalheRaw,
      ]));
  ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <a class="vbtn vbtn-ghost vbtn-sm" href="?<?= h($qsBase) ?>">← Voltar à matriz</a>
      <strong>Detalhamento — <?= h($detRotulo) ?></strong>
      <span class="vsub"><?= count($detLancs) ?> lançamento(s) · total
        <strong class="vnum">R$ <?= numFmt($detTotal, 2) ?></strong></span>
      <span style="flex:1"></span>
      <?php if ($detLancs): ?>
        <a class="vbtn vbtn-ghost vbtn-sm no-print" href="?<?= h($qsDet) ?><?= $fCat !== '' ? '&cat=' . h(urlencode($fCat)) : '' ?>&csv=1">Exportar CSV</a>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;padding:10px 16px 0">
      <a class="vbtn vbtn-sm <?= $fCat === '' ? 'vbtn-primary' : 'vbtn-ghost' ?>" href="?<?= h($qsDet) ?>">Todas as categorias</a>
      <?php foreach ($categorias as $cat): ?>
        <a class="vbtn vbtn-sm <?= $fCat === $cat ? 'vbtn-primary' : 'vbtn-ghost' ?>"
           href="?<?= h($qsDet) ?>&cat=<?= h(urlencode($cat)) ?>"><?= h($rotuloCat($cat)) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if (!$detLancs): ?>
      <div class="vempty">Nenhum lançamento para esta válvula no filtro.</div>
    <?php else: ?>
    <?php /* Cascata estilo DRE: categoria → origem →
             lançamentos, com subtotais e participação sobre o total da válvula
             — mesmo vocabulário visual do DRE Agro (mestre com fundo, filhos
             indentados, totalizador com borda dupla). */
    $arvore = [];
    foreach ($detLancs as $l) {
        $cat = (string)$l['categoria'];
        $org = (string)($l['origem_tipo'] ?? 'manual');
        $arvore[$cat]['total'] = ($arvore[$cat]['total'] ?? 0.0) + (float)$l['valor'];
        $arvore[$cat]['origens'][$org]['total'] = ($arvore[$cat]['origens'][$org]['total'] ?? 0.0) + (float)$l['valor'];
        $arvore[$cat]['origens'][$org]['lancs'][] = $l;
    }
    uasort($arvore, static fn($a, $b) => $b['total'] <=> $a['total']);
    $pctDet = static fn(float $v): string => $detTotal > 0 ? numFmt($v / $detTotal * 100, 1) . '%' : '—';
    ?>
    <?php /* expansível: abre só com as categorias; clique
             expande origens e, dentro delas, os lançamentos. Setas ▸/▾. */ ?>
    <div style="display:flex;gap:8px;justify-content:flex-end;padding:8px 16px 0">
      <button type="button" class="vbtn vbtn-ghost vbtn-sm no-print" onclick="cdTudo(true)">Expandir tudo</button>
      <button type="button" class="vbtn vbtn-ghost vbtn-sm no-print" onclick="cdTudo(false)">Recolher tudo</button>
    </div>
    <table class="vtable" id="cd-arvore">
      <thead><tr>
        <th>Composição do custo</th>
        <th class="vnum" style="text-align:right;width:150px">Valor (R$)</th>
        <th class="vnum" style="text-align:right;width:90px">% do total</th>
      </tr></thead>
      <tbody>
      <?php $ci = 0; foreach ($arvore as $cat => $bloco): $ci++; ?>
        <tr class="cd-cat" data-k="c<?= $ci ?>" onclick="cdCat(this)" role="button" tabindex="0"
            style="background:rgba(0,80,89,.06);border-top:2px solid var(--vero-border,#ccc);cursor:pointer">
          <td><span class="cd-seta" style="display:inline-block;width:14px;font-weight:700;color:#005059">▸</span>
            <strong><?= h($rotuloCat($cat)) ?></strong>
            <span class="vhint"><?= count($bloco['origens']) ?> origem(ns)</span></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($bloco['total'], 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><strong><?= $pctDet($bloco['total']) ?></strong></td>
        </tr>
        <?php uasort($bloco['origens'], static fn($a, $b) => $b['total'] <=> $a['total']);
        $oi = 0; foreach ($bloco['origens'] as $org => $go): $oi++; ?>
          <tr class="cd-org" data-p="c<?= $ci ?>" data-k="c<?= $ci ?>o<?= $oi ?>" onclick="cdOrg(this)"
              role="button" tabindex="0" style="display:none;cursor:pointer">
            <td style="padding-left:30px"><span class="cd-seta" style="display:inline-block;width:14px;color:#005059">▸</span>
              <?= h($rotuloOrigem($org)) ?>
              <span class="vhint"><?= count($go['lancs']) ?> lançamento(s)</span></td>
            <td class="vnum" style="text-align:right"><?= numFmt($go['total'], 2) ?></td>
            <td class="vnum" style="text-align:right"><?= $pctDet($go['total']) ?></td>
          </tr>
          <?php foreach ($go['lancs'] as $l): ?>
            <?php $extra = $detExtra[(string)$l['origem_tipo'] . '-' . (int)($l['origem_id'] ?? 0)] ?? null; ?>
            <tr class="cd-lnc" data-p="c<?= $ci ?>o<?= $oi ?>" data-cat="c<?= $ci ?>" style="display:none">
              <td class="vhint" style="padding-left:62px">
                <?= $l['data_competencia'] ? date('d/m/Y', strtotime((string)$l['data_competencia'])) : '—' ?>
                <?php if ($extra !== null): /* mão de obra: quem · atividade · modalidade · conta */ ?>
                  · <?= $extra ?>
                <?php else: ?>
                  <?= $l['safra'] !== null ? ' · safra ' . h((string)$l['safra']) : '' ?>
                  <?= $l['origem_id'] !== null ? ' · #' . (int)$l['origem_id'] : '' ?>
                  <?= $l['quantidade'] !== null ? ' · qtde ' . numFmt((float)$l['quantidade'], 2) : '' ?>
                <?php endif; ?>
              </td>
              <td class="vnum vhint" style="text-align:right"><?= numFmt((float)$l['valor'], 2) ?></td>
              <td></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
        <tr style="border-top:2px solid var(--vero-border,#ccc);background:rgba(0,80,89,.1)">
          <td><strong>Custo total<?= $fCat !== '' ? ' — ' . h($rotuloCat($fCat)) : '' ?></strong></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($detTotal, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><strong><?= $detTotal > 0 ? '100%' : '—' ?></strong></td>
        </tr>
      </tbody>
    </table>
    <script>
    /* árvore expansível: categoria mostra/esconde origens; origem, seus lançamentos */
    function cdSeta(tr, aberto) { var s = tr.querySelector('.cd-seta'); if (s) s.textContent = aberto ? '▾' : '▸'; }
    function cdCat(tr) {
      var k = tr.dataset.k, abrir = tr.dataset.aberto !== '1';
      tr.dataset.aberto = abrir ? '1' : '0'; cdSeta(tr, abrir);
      document.querySelectorAll('#cd-arvore .cd-org[data-p="' + k + '"]').forEach(function (o) {
        o.style.display = abrir ? '' : 'none';
        if (!abrir) { o.dataset.aberto = '0'; cdSeta(o, false); }
      });
      if (!abrir) { /* recolher também os lançamentos das origens desta categoria */
        document.querySelectorAll('#cd-arvore .cd-lnc[data-cat="' + k + '"]').forEach(function (l) { l.style.display = 'none'; });
      }
    }
    function cdOrg(tr) {
      var k = tr.dataset.k, abrir = tr.dataset.aberto !== '1';
      tr.dataset.aberto = abrir ? '1' : '0'; cdSeta(tr, abrir);
      document.querySelectorAll('#cd-arvore .cd-lnc[data-p="' + k + '"]').forEach(function (l) {
        l.style.display = abrir ? '' : 'none';
      });
    }
    function cdTudo(abrir) {
      document.querySelectorAll('#cd-arvore .cd-cat').forEach(function (c) {
        if ((c.dataset.aberto === '1') !== abrir) cdCat(c);
      });
      if (abrir) document.querySelectorAll('#cd-arvore .cd-org').forEach(function (o) {
        if (o.dataset.aberto !== '1') cdOrg(o);
      });
    }
    </script>
    <?php if (count($detLancs) === 1000): ?>
      <div class="vhint" style="padding:8px 16px">Mostrando os 1.000 lançamentos mais recentes — refine pelo período ou categoria para ver o restante.</div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <select name="safra" onchange="this.form.submit()">
          <option value="">Todas as safras</option>
          <?php foreach ($safras as $sid => $sn): ?>
            <option value="<?= $sid ?>"<?= $fSafra === $sid ? ' selected' : '' ?>><?= h($sn) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub">custo total: <strong class="vnum">R$ <?= numFmt($totalGeral, 2) ?></strong></span>
      <?php if ($porTalhao): ?>
        <a class="vbtn vbtn-ghost vbtn-sm no-print" href="?<?= $qsBase ? h($qsBase) . '&' : '' ?>csv=1">Exportar CSV</a>
        <button class="vbtn vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button>
      <?php endif; ?>
    </div>

    <?php if (!$porTalhao): ?>
      <div class="vempty">Nenhum lançamento de custeio no filtro.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Válvula</th>
        <?php foreach ($categorias as $cat): ?>
          <th style="text-align:right"><?= h($rotuloCat($cat)) ?></th>
        <?php endforeach; ?>
        <th style="text-align:right">Total (R$)</th>
        <th style="text-align:right">Custo/ha (R$)</th>
        <th style="width:22%">Participação</th>
      </tr></thead>
      <tbody>
      <?php foreach ($porTalhao as $chave => $t):
          $pct = $totalGeral > 0 ? $t['total'] / $totalGeral * 100 : 0;
          /* drill-down: 0 = lançamentos sem válvula (?detalhe=sem) */
          $urlDet = '?' . ($qsBase ? $qsBase . '&' : '') . 'detalhe=' . ($chave === 0 ? 'sem' : $chave); ?>
        <tr>
          <td><a href="<?= h($urlDet) ?>" style="font-weight:700;color:#005059" title="Ver os lançamentos desta válvula"><?= h($t['rotulo']) ?></a>
            <?= $t['area'] !== null ? '<span class="vhint">' . numFmt($t['area'], 2) . ' ha</span>' : '' ?></td>
          <?php foreach ($categorias as $cat): ?>
            <td class="vnum" style="text-align:right"><?= isset($t['cats'][$cat])
                ? '<a href="' . h($urlDet . '&cat=' . urlencode($cat)) . '" title="Detalhar ' . h($rotuloCat($cat)) . '">' . numFmt($t['cats'][$cat], 2) . '</a>'
                : '—' ?></td>
          <?php endforeach; ?>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($t['total'], 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= $t['area'] > 0 ? numFmt($t['total'] / $t['area'], 2) : '—' ?></td>
          <td><div style="height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
            <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
