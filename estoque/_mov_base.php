<?php
/* ============================================================
   VERO — Estoque / base compartilhada de Movimentações (leitura)
   Incluída por movimentacoes.php (todas), entradas.php e saidas.php,
   que definem: $MOV_TIPO (null|'entrada'|'saida'), $MOV_MICRO,
   $MOV_VIEW, $MOV_TITULO, $MOV_SUB e opcionalmente $MOV_ACOES
   (true = habilita ajuste tipado e devolução de campo — A2-F2-2).
   Estornadas ficam fora da visão por padrão (estornado_em IS NULL);
   toggle "incluir estornadas" mostra a trilha completa com badges.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_export.php'; /* A2-F2-23: export CSV do filtro ativo */

$MOV_ACOES = $MOV_ACOES ?? false;

$fProduto  = (int)($_GET['produto'] ?? 0);
$fOrigem   = (string)($_GET['origem'] ?? '');
$fIni      = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim      = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';
$fEstorno  = (string)($_GET['estornadas'] ?? '') === '1';
$page      = max(1, (int)($_GET['pg'] ?? 1));
$perPage   = 25;

/* $whereBase = todos os filtros EXCETO o tipo (para o resumo do topo mostrar o
   panorama entrada/saída/ajuste do produto/período). $where = base + tipo, usado
   na tabela e na paginação da visão específica. */
$whereBase  = "mv.tenant_id = :t";
$paramsBase = [':t' => vero_tenant()];
if (!$fEstorno)      { $whereBase .= " AND mv.estornado_em IS NULL"; }
if ($fProduto > 0)   { $whereBase .= " AND mv.produto_id = :p"; $paramsBase[':p'] = $fProduto; }
if ($fOrigem !== '') { $whereBase .= " AND mv.origem_tipo = :o"; $paramsBase[':o'] = $fOrigem; }
if ($fIni !== '')    { $whereBase .= " AND mv.data_movimento >= :i"; $paramsBase[':i'] = $fIni . ' 00:00:00'; }
if ($fFim !== '')    { $whereBase .= " AND mv.data_movimento <= :f"; $paramsBase[':f'] = $fFim . ' 23:59:59'; }
$where  = $whereBase;
$params = $paramsBase;
if ($MOV_TIPO !== null) { $where .= " AND mv.tipo = :tp"; $params[':tp'] = $MOV_TIPO; }

/* P-75 (CSO): valores em R$ (custo unit./valor) gateados pelo proxy financeiro. */
$veCusto = function_exists('vero_can') ? vero_can('financeiro.dre_agro.ver') : true;

/* A2-F2-23: export CSV — MESMO filtro ativo (produto/origem/período/estornadas +
   o tipo da visão), todos os registros. Antes do header; gate igual ao da tela.
   bios_guard() ainda não existe aqui (menu_agro só carrega no header) — usa
   vero_require (guard real pré-header, via vero_crud) senão o CSV escaparia. */
if (($_GET['csv'] ?? '') !== '') {
    vero_require('estoque.' . $MOV_MICRO . '.ver');
    $rowsCsv = vero_rows(
        "SELECT mv.data_movimento, mv.tipo, p.codigo AS prod_codigo, p.nome AS prod_nome,
                a.nome AS almox, l.codigo_lote, mv.quantidade, p.unidade,
                mv.custo_unitario, mv.valor_total, mv.origem_tipo, mv.origem_id,
                mv.observacao, mv.estornado_em
           FROM estoque_movimentacoes mv
           JOIN estoque_produtos p ON p.id = mv.produto_id
           LEFT JOIN estoque_lotes l ON l.id = mv.lote_id
           LEFT JOIN almoxarifados a ON a.id = mv.almoxarifado_id
          WHERE {$where}
          ORDER BY mv.data_movimento DESC, mv.id DESC", $params);
    $tipoLbl = ['entrada' => 'Entrada', 'saida' => 'Saída', 'ajuste' => 'Ajuste', 'transferencia' => 'Transferência'];
    foreach ($rowsCsv as &$rc) {
        $rc['tipo_label']     = $tipoLbl[$rc['tipo']] ?? ucfirst((string)$rc['tipo']);
        $rc['origem_label']   = ucfirst(str_replace('_', ' ', (string)($rc['origem_tipo'] ?? 'manual')))
                              . ($rc['origem_id'] ? ' #' . (int)$rc['origem_id'] : '');
        $rc['estornada_label'] = $rc['estornado_em'] !== null ? 'Sim' : '';
    }
    unset($rc);
    $csvSlug = $MOV_TIPO === null ? 'movimentacoes' : ($MOV_TIPO === 'entrada' ? 'entradas' : 'saidas');
    $movCols = [
        'data_movimento' => 'Data', 'tipo_label' => 'Tipo', 'prod_codigo' => 'Código',
        'prod_nome' => 'Produto', 'almox' => 'Almoxarifado', 'codigo_lote' => 'Lote',
        'quantidade' => 'Quantidade', 'unidade' => 'Unidade', 'custo_unitario' => 'Custo unit. (R$)',
        'valor_total' => 'Valor (R$)', 'origem_label' => 'Origem', 'observacao' => 'Observação',
        'estornada_label' => 'Estornada',
    ];
    $movFmt = ['data_movimento' => 'data', 'quantidade' => 'dec2', 'custo_unitario' => 'dec4', 'valor_total' => 'dec2'];
    if (!$veCusto) { unset($movCols['custo_unitario'], $movCols['valor_total'], $movFmt['custo_unitario'], $movFmt['valor_total']); }
    estoque_csv_stream($csvSlug, $rowsCsv, $movCols, $movFmt);
}

/* Resumo (todos os tipos no filtro, sem o recorte de tipo). */
$resumo = vero_row(
    "SELECT COUNT(*) AS linhas,
            COALESCE(SUM(CASE WHEN mv.tipo = 'entrada' AND mv.estornado_em IS NULL THEN mv.valor_total ELSE 0 END),0) AS entradas,
            COALESCE(SUM(CASE WHEN mv.tipo = 'saida' AND mv.estornado_em IS NULL THEN mv.valor_total ELSE 0 END),0) AS saidas,
            COALESCE(SUM(CASE WHEN mv.tipo = 'ajuste' AND mv.estornado_em IS NULL THEN mv.valor_total ELSE 0 END),0) AS ajustes
       FROM estoque_movimentacoes mv WHERE {$whereBase}", $paramsBase);
/* Contagem da visão específica (tabela/paginação). */
$totLinhas = (int)vero_val("SELECT COUNT(*) FROM estoque_movimentacoes mv WHERE {$where}", $params);
$rows = vero_rows(
    "SELECT mv.*, p.codigo AS prod_codigo, p.nome AS prod_nome, p.unidade,
            l.codigo_lote, a.nome AS almox,
            CASE WHEN mv.tipo = 'saida' AND mv.estornado_em IS NULL
                      AND mv.origem_tipo IN ('apontamento_insumo','aplicacao')
                 THEN mv.quantidade - COALESCE((SELECT SUM(d.quantidade) FROM estoque_movimentacoes d
                        WHERE d.tenant_id = mv.tenant_id AND d.origem_tipo = 'devolucao_campo'
                          AND d.mov_ref_id = mv.id AND d.estornado_em IS NULL), 0)
                 ELSE NULL END AS devolvivel
       FROM estoque_movimentacoes mv
       JOIN estoque_produtos p ON p.id = mv.produto_id
       LEFT JOIN estoque_lotes l ON l.id = mv.lote_id
       LEFT JOIN almoxarifados a ON a.id = mv.almoxarifado_id
      WHERE {$where}
      ORDER BY mv.data_movimento DESC, mv.id DESC
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

$produtos = vero_rows("SELECT id, codigo, nome FROM estoque_produtos WHERE tenant_id = :t ORDER BY nome",
    [':t' => vero_tenant()]);
$origens = array_map('strval', array_column(vero_rows(
    "SELECT DISTINCT origem_tipo FROM estoque_movimentacoes
      WHERE tenant_id = :t AND origem_tipo IS NOT NULL ORDER BY origem_tipo", [':t' => vero_tenant()]), 'origem_tipo'));

if ($MOV_ACOES) {
    $almoxesAtivos = vero_rows("SELECT id, nome FROM almoxarifados WHERE tenant_id = :t AND ativo = 1 ORDER BY nome",
        [':t' => vero_tenant()]);
    $lotesAtivos = vero_rows(
        "SELECT l.id, l.codigo_lote, l.quantidade, l.validade, p.codigo, p.nome, a.nome AS almox
           FROM estoque_lotes l
           JOIN estoque_produtos p ON p.id = l.produto_id
           LEFT JOIN almoxarifados a ON a.id = l.almoxarifado_id
          WHERE l.tenant_id = :t AND l.quantidade > 0
          ORDER BY p.nome, (l.validade IS NULL), l.validade", [':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'estoque', 'micro' => $MOV_MICRO];
$PAGE_VIEW  = $MOV_VIEW;
$PAGE_TITLE = $MOV_TITULO;
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeAgir = $MOV_ACOES && vero_can('estoque.historico_movimentacoes.editar');
/* A2-F2-16: estorno pela interface — só em movimentacoes.php (MOV_ACOES) e
   apenas p/ movimentos manuais/devolução; slug próprio quando o A0-16 criar */
$podeEstornar = $MOV_ACOES && function_exists('mov_pode_estornar') && mov_pode_estornar();

/* A2-F2-19: KARDEX (histórico consolidado por produto) — só na visão "todas"
   com produto filtrado: movimentos ATIVOS em ordem cronológica com saldo
   corrente acumulado (entrada +, saída −, ajuste ±); estornadas fora. */
$kardex = null;
$kardexProduto = null;
if ($MOV_TIPO === null && $fProduto > 0 && (string)($_GET['kardex'] ?? '') === '1') {
    $kardexRows = vero_rows(
        "SELECT mv.id, mv.data_movimento, mv.tipo, mv.quantidade, mv.custo_unitario, mv.valor_total,
                mv.origem_tipo, mv.origem_id, a.nome AS almox, l.codigo_lote
           FROM estoque_movimentacoes mv
           LEFT JOIN almoxarifados a ON a.id = mv.almoxarifado_id
           LEFT JOIN estoque_lotes l ON l.id = mv.lote_id
          WHERE mv.tenant_id = :t AND mv.produto_id = :p AND mv.estornado_em IS NULL
          ORDER BY mv.data_movimento, mv.id
          LIMIT 1000", [':t' => vero_tenant(), ':p' => $fProduto]);
    $saldoCorrente = 0.0;
    $kardex = [];
    foreach ($kardexRows as $kr) {
        $deltaK = match ((string)$kr['tipo']) {
            'entrada' => (float)$kr['quantidade'],
            'saida'   => -(float)$kr['quantidade'],
            default   => (float)$kr['quantidade'], /* ajuste: delta assinado */
        };
        $saldoCorrente += $deltaK;
        $kr['delta'] = $deltaK;
        $kr['saldo'] = $saldoCorrente;
        $kardex[] = $kr;
    }
    $kardexProduto = vero_row("SELECT codigo, nome, unidade FROM estoque_produtos WHERE tenant_id = :t AND id = :p",
        [':t' => vero_tenant(), ':p' => $fProduto]);
}

$badgeTipo = static function (array $r): string {
    $b = match ((string)$r['tipo']) {
        'entrada' => '<span class="vbadge vb-ok">Entrada</span>',
        'saida'   => '<span class="vbadge vb-warn">Saída</span>',
        'ajuste'  => '<span class="vbadge vb-info">Ajuste ' . ((float)$r['quantidade'] >= 0 ? '+' : '−') . '</span>',
        default   => '<span class="vbadge vb-info">' . h(ucfirst((string)$r['tipo'])) . '</span>',
    };
    if ($r['motivo'] !== null && isset(VERO_ESTOQUE_MOTIVOS_AJUSTE[$r['motivo']])) {
        $b .= ' <span class="vhint">' . h(VERO_ESTOQUE_MOTIVOS_AJUSTE[$r['motivo']]) . '</span>';
    }
    if ($r['estornado_em'] !== null) {
        $b .= ' <span class="vbadge vb-off">' . ($r['origem_tipo'] === null && $r['mov_ref_id'] !== null ? 'estorno' : 'estornada') . '</span>';
    }
    return $b;
};
?>
<style>
.mov-filtros{display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center}
.mov-filtros select{min-width:0}
.mov-filtros input[type=date]{min-width:0;height:36px;border:1px solid #D8CEBB;border-radius:8px;
  padding:6px 9px;font:13px 'IBM Plex Sans',system-ui,sans-serif;color:#241B14;background:#fff;color-scheme:light}
.mov-chk{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#6B5F53;white-space:nowrap}
.mov-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:10px}
.mov-table{width:100%;border-collapse:collapse;min-width:760px}
.mov-table thead th{background:#F5F1E8;font:600 11px 'IBM Plex Sans';text-transform:uppercase;letter-spacing:.03em;color:#6B5F53;border-bottom:2px solid #E1D9C7;padding:10px 12px;text-align:left;white-space:nowrap}
.mov-table tbody td{padding:9px 12px;border-bottom:1px solid #F0EBDF;vertical-align:top}
.mov-table tbody tr:nth-child(even){background:#FBFAF6}
.mov-table tbody tr:hover{background:#F4F1E8}
.mov-table tbody tr.mov-off{opacity:.5}
.mov-orig .l1{font-weight:600;color:#3A342A;font-size:12px}
.mov-orig .l2{font-size:11px;color:#8A7D6E;margin-top:2px;max-width:260px}
.mov-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
@media(max-width:760px){.mov-filtros{width:100%}.mov-filtros select{flex:1 1 100%}.mov-filtros input[type=date]{flex:1 1 46%}}
@media print{.vero-topbar,.macromenu,.microrail,.vero-navbtn,.mov-toolbar,.vflash-host{display:none!important}}
</style>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header($MOV_TITULO, $MOV_SUB, null) ?>


  <div class="vcard">
    <div class="vtoolbar mov-toolbar">
      <form method="get" class="mov-filtros">
        <select name="produto" onchange="this.form.submit()" aria-label="Produto">
          <option value="">Todos os produtos</option>
          <?php foreach ($produtos as $p): ?>
            <option value="<?= (int)$p['id'] ?>"<?= $fProduto === (int)$p['id'] ? ' selected' : '' ?>><?= h($p['codigo'] . ' — ' . $p['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="origem" onchange="this.form.submit()" aria-label="Origem">
          <option value="">Todas as origens</option>
          <?php foreach ($origens as $o): ?>
            <option value="<?= h($o) ?>"<?= $fOrigem === $o ? ' selected' : '' ?>><?= h(ucfirst(str_replace('_', ' ', $o))) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="ini" value="<?= h($fIni) ?>" aria-label="Data inicial" title="Data inicial">
        <input type="date" name="fim" value="<?= h($fFim) ?>" aria-label="Data final" title="Data final">
        <label class="mov-chk">
          <input type="checkbox" name="estornadas" value="1" style="width:auto" onchange="this.form.submit()"<?= $fEstorno ? ' checked' : '' ?>>
          Incluir estornadas
        </label>
        <button class="vbtn vbtn-primary vbtn-sm" type="submit">Filtrar</button>
        <?php if ($MOV_TIPO === null && $fProduto > 0): /* A2-F2-19 */ ?>
          <button class="vbtn vbtn-ghost vbtn-sm" type="submit" name="kardex" value="1"<?= $kardex !== null ? ' disabled' : '' ?>>Kardex</button>
        <?php endif; ?>
        <?php if ($fProduto || $fOrigem !== '' || $fIni !== '' || $fFim !== '' || $fEstorno): ?>
          <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h(strtok((string)$_SERVER['REQUEST_URI'], '?')) ?>" data-vero-clear>Limpar filtros</a>
        <?php endif; ?>
      </form>
      <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="window.print()">Imprimir</button>
      <?php
        /* A2-F2-23: "Exportar CSV" = filtro atual + csv=1 (sem paginação/kardex) */
        $qsMovE = $_GET; unset($qsMovE['pg'], $qsMovE['kardex']); $qsMovE['csv'] = '1';
        $movExportUrl = strtok((string)$_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($qsMovE);
      ?>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h($movExportUrl) ?>" title="Baixar a lista filtrada em CSV (abre no Excel)">Exportar CSV</a>
      <?php if ($podeAgir): ?>
        <button class="vbtn vbtn-primary vbtn-sm" type="button" onclick="vModalOpen('vm-ajuste')">+ Ajuste</button>
      <?php endif; ?>
    </div>

    <?php if ($kardex !== null): /* A2-F2-19: kardex consolidado do produto */ ?>
      <div style="padding:0 14px 8px">
        <strong>Kardex — <?= h(($kardexProduto['codigo'] ?? '') . ' — ' . ($kardexProduto['nome'] ?? '')) ?></strong>
        <span class="vsub"><?= count($kardex) ?> movimento(s) ativo(s), saldo corrente acumulado (todos os almoxarifados)<?= count($kardex) >= 1000 ? ' — LIMITADO aos 1000 primeiros' : '' ?></span>
      </div>
      <?php if (!$kardex): ?>
        <div class="vempty">Nenhum movimento ativo para este produto.</div>
      <?php else: ?>
      <div class="mov-wrap" style="margin-bottom:14px">
      <table class="mov-table">
        <thead><tr><th>Data</th><th>Mov</th><th>Tipo</th><th>Almox / Lote</th>
          <th class="mov-num">Δ Qtd</th><th class="mov-num">Custo unit. (R$)</th>
          <th>Origem</th><th class="mov-num">Saldo após</th></tr></thead>
        <tbody>
        <?php foreach ($kardex as $k): ?>
          <tr>
            <td class="vnum" style="white-space:nowrap"><?= date('d/m/Y', strtotime((string)$k['data_movimento'])) ?></td>
            <td class="vnum">#<?= (int)$k['id'] ?></td>
            <td><?= $badgeTipo($k + ['motivo' => null, 'estornado_em' => null, 'origem_tipo' => $k['origem_tipo'], 'mov_ref_id' => null]) ?></td>
            <td><?= h($k['almox'] ?? '—') ?><?= $k['codigo_lote'] ? ' <span class="vhint">' . h($k['codigo_lote']) . '</span>' : '' ?></td>
            <td class="mov-num" style="color:<?= $k['delta'] >= 0 ? '#1E6B34' : '#9A3B2A' ?>">
              <strong><?= ($k['delta'] >= 0 ? '+' : '') . numFmt((float)$k['delta'], 2) ?></strong></td>
            <td class="mov-num"><?= $veCusto ? numFmt((float)$k['custo_unitario'], 2) : '•••' ?></td>
            <td class="vhint"><?= h(ucfirst(str_replace('_', ' ', (string)($k['origem_tipo'] ?? 'manual'))))
                . ($k['origem_id'] ? ' #' . (int)$k['origem_id'] : '') ?></td>
            <td class="mov-num"><strong><?= numFmt((float)$k['saldo'], 2) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma movimentação encontrada para os filtros selecionados.</div>
    <?php else: ?>
    <div class="mov-wrap">
    <table class="mov-table">
      <thead><tr>
        <th>Data</th><th>Tipo</th><th>Produto</th><th>Almoxarifado</th><th>Lote</th>
        <th class="mov-num">Qtd</th>
        <th class="mov-num">Custo unit. (R$)</th>
        <th class="mov-num">Valor (R$)</th>
        <th>Origem</th>
        <?php if ($podeAgir): ?><th class="mov-num">Ações</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['estornado_em'] !== null ? ' class="mov-off"' : '' ?>>
          <td class="vnum" style="white-space:nowrap"><?= date('d/m/Y', strtotime((string)$r['data_movimento'])) ?></td>
          <td><?= $badgeTipo($r) ?></td>
          <td><strong class="vnum"><?= h($r['prod_codigo']) ?></strong><br><span style="font-size:12px"><?= h($r['prod_nome']) ?></span></td>
          <td><?= h($r['almox'] ?? '—') ?></td>
          <td class="vnum"><?= $r['codigo_lote'] ? '<strong>' . h($r['codigo_lote']) . '</strong>' : '<span class="vhint">—</span>' ?></td>
          <td class="mov-num"><strong><?= numFmt((float)$r['quantidade'], 2) ?></strong> <span class="vhint"><?= h($r['unidade']) ?></span></td>
          <td class="mov-num"><?= $veCusto ? numFmt((float)$r['custo_unitario'], 2) : '<span class="vhint">•••</span>' ?></td>
          <td class="mov-num"><?= $veCusto ? '<strong>' . numFmt((float)$r['valor_total'], 2) . '</strong>' : '<span class="vhint">•••</span>' ?></td>
          <td class="mov-orig">
            <div class="l1"><?= h(ucfirst(str_replace('_', ' ', (string)($r['origem_tipo'] ?? 'manual')))) ?></div>
            <?php if ($r['observacao']): ?><div class="l2"><?= h(mb_substr((string)$r['observacao'], 0, 80)) ?></div><?php endif; ?>
          </td>
          <?php if ($podeAgir): ?>
          <td class="mov-num"><div class="vactions" style="justify-content:flex-end">
            <?php
              $temAcao = false;
              if ($r['devolvivel'] !== null && (float)$r['devolvivel'] > 0.0001) {
                  $temAcao = true;
                  echo vero_btn_icone(vero_ico_voltar(), 'Devolver sobra', "devAbrir(" . (int)$r['id'] . ", '" . h(addslashes($r['prod_nome'])) . "', " . json_encode(round((float)$r['devolvivel'], 4)) . ", '" . h($r['unidade']) . "')");
              }
              /* A2-F2-16: estornável = ativo e de origem manual/devolução (documentos
                 estornam pela origem; transferência tem o fluxo do par) */
              if ($podeEstornar && $r['estornado_em'] === null
                  && in_array((string)($r['origem_tipo'] ?? ''), ['manual', 'devolucao_campo'], true)) {
                  $temAcao = true;
                  echo vero_btn_icone(vero_ico_voltar(), 'Estornar', 'estAbrir('
                      . (int)$r['id'] . ", '" . h(addslashes($r['prod_codigo'] . ' — tipo ' . $r['tipo'] . ', qtd '
                      . numFmt((float)$r['quantidade'], 2))) . "')");
              }
              if (!$temAcao) echo '<span class="vhint">—</span>';
            ?>
          </div></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= vero_pagination($page, $totLinhas, $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeAgir): ?>
<div class="vmodal" id="vm-ajuste">
  <div class="vbox">
    <header><h2>Ajuste de estoque (tipado)</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-ajuste')">×</button></header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="ajustar">
      <div class="vgrid">
        <div class="vfield full">
          <label>Lote (opcional — ajusta aquele lote específico)</label>
          <select name="lote_id" id="aj-lote" onchange="ajLoteUI()">
            <option value="">— Sem lote: informe produto e almoxarifado —</option>
            <?php foreach ($lotesAtivos as $l): ?>
              <option value="<?= (int)$l['id'] ?>">
                <?= h($l['codigo'] . ' ' . $l['nome'] . ' — ' . ($l['almox'] ?? '?') . ' — ' . $l['codigo_lote']
                    . ' (' . numFmt((float)$l['quantidade'], 2) . ')'
                    . ($l['validade'] ? ' val. ' . date('d/m/Y', strtotime((string)$l['validade'])) : '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield" id="aj-prod-box"><label>Produto</label>
          <select name="produto_id">
            <option value="">Selecione…</option>
            <?php foreach ($produtos as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= h($p['codigo'] . ' — ' . $p['nome']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="vfield" id="aj-almox-box"><label>Almoxarifado</label>
          <select name="almoxarifado_id">
            <option value="">Selecione…</option>
            <?php foreach ($almoxesAtivos as $a): ?>
              <option value="<?= (int)$a['id'] ?>"><?= h($a['nome']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="vfield"><label>Direção *</label>
          <select name="direcao" required>
            <option value="reducao">Redução (−)</option>
            <option value="acrescimo">Acréscimo (+)</option>
          </select></div>
        <div class="vfield"><label>Quantidade *</label>
          <input type="text" name="quantidade" required inputmode="decimal" placeholder="0,00" style="text-align:right"></div>
        <div class="vfield"><label>Motivo *</label>
          <select name="motivo" required>
            <?php foreach (VERO_ESTOQUE_MOTIVOS_AJUSTE as $k => $lbl): if ($k === 'devolucao_campo') continue; ?>
              <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="vfield"><label>Data</label>
          <input type="date" name="data" value="<?= date('Y-m-d') ?>"></div>
        <div class="full"><?= vero_f_text('observacao', 'Observação (opcional)', '') ?></div>
      </div>
      <div class="vhint" style="margin-top:8px">Redução sem lote consome FEFO; ao custo médio nas duas direções. Fica na trilha como tipo "ajuste" com o motivo.</div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-ajuste')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Registrar ajuste</button>
      </div>
    </form>
  </div>
</div>

<?php if ($podeEstornar): ?>
<div class="vmodal" id="vm-estorno">
  <div class="vbox">
    <header><h2 id="est-titulo">Estornar movimentação</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-estorno')">×</button></header>
    <form class="vform" method="post"
          data-confirm="Estornar esta movimentação? Saldo e lotes serão revertidos; original e contra-movimento ficam na trilha." data-confirm-danger data-confirm-ok="Estornar"
          onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="estornar">
      <input type="hidden" name="mov_id" id="est-mov">
      <div class="vgrid">
        <div class="vfield"><label>Motivo *</label>
          <select name="motivo" required>
            <option value="">Selecione…</option>
            <?php foreach (ESTORNO_MOTIVOS as $k => $lbl): ?>
              <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="full"><?= vero_f_text('observacao', 'Observação (opcional)', '') ?></div>
      </div>
      <div class="vhint" style="margin-top:8px">Estorno LÓGICO (nada é apagado): reverte saldo e lotes pelo rateio da saída; o motivo fica anotado no contra-movimento. Movimentos de documentos (compra, apontamento, aplicação, inventário) não aparecem aqui — estorne pela origem.</div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-estorno')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Estornar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="vmodal" id="vm-dev">
  <div class="vbox">
    <header><h2 id="dev-titulo">Devolução de campo</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-dev')">×</button></header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="devolver">
      <input type="hidden" name="mov_id" id="dev-mov">
      <div class="vgrid">
        <div class="vfield"><label>Quantidade a devolver *</label>
          <input type="text" name="quantidade" required inputmode="decimal" placeholder="0,00" style="text-align:right">
          <div class="vhint" id="dev-disp"></div></div>
        <div class="vfield"><label>Data</label>
          <input type="date" name="data" value="<?= date('Y-m-d') ?>"></div>
        <div class="full"><?= vero_f_text('observacao', 'Observação (opcional)', '') ?></div>
      </div>
      <div class="vhint" style="margin-top:8px">A sobra volta ao estoque ao custo da saída original e aos mesmos lotes (ordem inversa do FEFO).</div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-dev')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Registrar devolução</button>
      </div>
    </form>
  </div>
</div>
<script>
function ajLoteUI() {
  const temLote = document.getElementById('aj-lote').value !== '';
  document.getElementById('aj-prod-box').style.display = temLote ? 'none' : '';
  document.getElementById('aj-almox-box').style.display = temLote ? 'none' : '';
}
function devAbrir(movId, nome, disponivel, unidade) {
  document.getElementById('dev-mov').value = movId;
  document.getElementById('dev-titulo').textContent = 'Devolução de campo — ' + nome;
  document.getElementById('dev-disp').textContent = 'Disponível para devolver: '
    + disponivel.toLocaleString('pt-BR', {minimumFractionDigits: 2}) + ' ' + unidade;
  vModalOpen('vm-dev');
}
function estAbrir(movId, resumo) {
  document.getElementById('est-mov').value = movId;
  document.getElementById('est-titulo').textContent = 'Estornar movimentação #' + movId + ' (' + resumo + ')';
  vModalOpen('vm-estorno');
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
