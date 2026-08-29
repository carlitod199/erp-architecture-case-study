<?php
/* ============================================================
   VERO — Estoque / Lotes e Validade  (tela real)
   Rota: /estoque/lotes.php | Guard: estoque.lotes_validade
   Visão dos lotes (base do FEFO): saldo, validade, dias para
   vencer, fornecedor e custo — filtros de situação.
   A2-F2-20 (F2 colheita→estoque, sobre a migration 143/A0-18):
   - ORIGEM AGRÍCOLA do lote (cultura · safra · talhão · variedade,
     via safra_talhao_id) e vínculo com o registro de colheita;
   - STATUS do lote (VERO_LOTE_STATUS) com badge + filtro; BLOQUEIO
     manual disponivel↔bloqueado (qualidade) validado na const —
     em_classificacao/consumido/estornado são estados do SISTEMA;
   - RASTREIO frente/trás (?rastreio=ID): para trás, as entradas do
     lote e sua origem (colheita/compra/transferência/devolução);
     para frente, os consumos pelo rateio FEFO persistido
     (estoque_movimentacao_lotes) até a origem de cada saída.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_audit.php'; /* A2-F2-19: ações críticas → auth_audit_logs */
require_once __DIR__ . '/_export.php'; /* A2-F2-23: export CSV do filtro ativo */

$t = vero_tenant();

const LOTE_STATUS_LABEL = [
    'disponivel'       => ['Disponível', 'vb-ok'],
    'em_classificacao' => ['Em classificação', 'vb-info'],
    'bloqueado'        => ['Bloqueado', 'vb-warn'],
    'consumido'        => ['Consumido', 'vb-off'],
    'estornado'        => ['Estornado', 'vb-off'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    /* A2-F2-20: bloqueio/desbloqueio MANUAL (qualidade). Só transita
       disponivel↔bloqueado; estados do sistema ficam intocáveis. Novo status
       validado contra VERO_LOTE_STATUS (contrato A0-18). */
    if ((string)($_POST['acao'] ?? '') === 'status') {
        vero_require('estoque.lotes_validade.editar');
        $loteId = vero_int('lote_id');
        $novo   = vero_str('novo_status', 15) ?? '';
        $lote = $loteId ? vero_row("SELECT * FROM estoque_lotes WHERE tenant_id=:t AND id=:i",
            [':t' => $t, ':i' => $loteId]) : null;
        if (!$lote || !in_array($novo, VERO_LOTE_STATUS, true)) {
            vero_flash('erro', 'Lote inválido ou status fora do contrato (' . implode(', ', VERO_LOTE_STATUS) . ').');
            vero_redirect();
        }
        $atual = (string)($lote['status'] ?? 'disponivel');
        $transicaoOk = ($atual === 'disponivel' && $novo === 'bloqueado')
            || ($atual === 'bloqueado' && $novo === 'disponivel');
        if (!$transicaoOk) {
            vero_flash('erro', "Transição manual não permitida ({$atual} → {$novo}) — apenas bloquear/desbloquear; os demais status são controlados pelo sistema (colheita, consumo, estorno).");
            vero_redirect();
        }
        vero_update('estoque_lotes', (int)$loteId, ['status' => $novo]);
        estoque_audit('estoque_lote_status', "Lote #{$loteId} ({$lote['codigo_lote']}): {$atual} → {$novo}");
        vero_flash('ok', 'Lote ' . h((string)$lote['codigo_lote']) . ($novo === 'bloqueado'
            ? ' BLOQUEADO — o FEFO não deve consumi-lo até o desbloqueio.'
            : ' desbloqueado (disponível).'));
        vero_redirect();
    }
}

/* ── Rastreio frente/trás (?rastreio=ID) ────────────────────── */
$rastreio = null;
if (!empty($_GET['rastreio'])) {
    $rastreio = vero_row(
        "SELECT l.*, p.codigo AS prod_codigo, p.nome AS prod_nome, p.unidade,
                a.nome AS almox, fo.nome AS fornecedor,
                c.nome AS cultura, s.identificacao AS safra, tl.nome AS talhao, v.nome AS variedade
           FROM estoque_lotes l
           JOIN estoque_produtos p ON p.id = l.produto_id
           LEFT JOIN almoxarifados a ON a.id = l.almoxarifado_id
           LEFT JOIN fornecedores fo ON fo.id = l.fornecedor_id
           LEFT JOIN agro_safra_talhoes st ON st.id = l.safra_talhao_id
           LEFT JOIN agro_culturas c ON c.id = st.cultura_id
           LEFT JOIN agro_safras s ON s.id = st.safra_id
           LEFT JOIN agro_talhoes tl ON tl.id = st.talhao_id
           LEFT JOIN agro_variedades v ON v.id = l.variedade_id
          WHERE l.tenant_id = :t AND l.id = :i", [':t' => $t, ':i' => (int)$_GET['rastreio']]);
    if ($rastreio) {
        /* PARA TRÁS: movimentos que apontam o lote diretamente (entradas,
           devoluções, ajustes por lote). PARA FRENTE: rateio FEFO persistido.
           União cronológica, com estornos visíveis. */
        $rastreio['eventos'] = vero_rows(
            "SELECT mv.id, mv.data_movimento, mv.tipo, mv.quantidade, mv.custo_unitario,
                    mv.origem_tipo, mv.origem_id, mv.observacao, mv.estornado_em,
                    'direto' AS via
               FROM estoque_movimentacoes mv
              WHERE mv.tenant_id = :t1 AND mv.lote_id = :l1
              UNION ALL
             SELECT mv.id, mv.data_movimento, mv.tipo, ml.quantidade, mv.custo_unitario,
                    mv.origem_tipo, mv.origem_id, mv.observacao, mv.estornado_em,
                    'rateio' AS via
               FROM estoque_movimentacao_lotes ml
               JOIN estoque_movimentacoes mv ON mv.id = ml.movimentacao_id
              WHERE ml.tenant_id = :t2 AND ml.lote_id = :l2 AND mv.lote_id IS NULL
              ORDER BY data_movimento, id",
            [':t1' => $t, ':l1' => (int)$rastreio['id'], ':t2' => $t, ':l2' => (int)$rastreio['id']]);
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$fSit     = (string)($_GET['sit'] ?? 'saldo');
$fProduto = (int)($_GET['produto'] ?? 0);
$fStatus  = (string)($_GET['status'] ?? '');
$fOrigem  = (string)($_GET['origem'] ?? '');
$page     = max(1, (int)($_GET['pg'] ?? 1));
$perPage  = 25;

$where  = "l.tenant_id = :t";
$params = [':t' => $t];
if ($fProduto > 0) { $where .= " AND l.produto_id = :p"; $params[':p'] = $fProduto; }
if ($fStatus !== '' && in_array($fStatus, VERO_LOTE_STATUS, true)) {
    $where .= " AND l.status = :st";
    $params[':st'] = $fStatus;
}
if ($fOrigem === 'agricola') $where .= " AND l.colheita_registro_id IS NOT NULL";
elseif ($fOrigem === 'insumo') $where .= " AND l.colheita_registro_id IS NULL";
switch ($fSit) {
    case 'vencidos':  $where .= " AND l.quantidade > 0 AND l.validade IS NOT NULL AND l.validade < CURDATE()"; break;
    case 'vencendo':  $where .= " AND l.quantidade > 0 AND l.validade IS NOT NULL
                                  AND l.validade >= CURDATE() AND l.validade <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; break;
    case 'saldo':     $where .= " AND l.quantidade > 0"; break;
    /* 'todos': sem filtro extra */
}

/* A2-F2-23: export CSV — MESMO filtro (situação/produto/status/origem), todos os
   lotes. Antes do header; gate igual ao da tela. bios_guard() ainda não existe
   aqui (menu_agro só carrega no header) — usa vero_require (guard real
   pré-header, via vero_crud) senão o CSV escaparia. */
$veCusto = function_exists('vero_can') ? vero_can('financeiro.dre_agro.ver') : true; // P-75
if (($_GET['csv'] ?? '') !== '') {
    vero_require('estoque.lotes_validade.ver');
    $rowsCsv = vero_rows(
        "SELECT l.codigo_lote, p.codigo AS prod_codigo, p.nome AS prod_nome, p.unidade,
                a.nome AS almox, fo.nome AS fornecedor, l.quantidade, l.custo_unitario,
                l.validade, l.status, l.colheita_registro_id,
                c.nome AS cultura, s.identificacao AS safra, tl.nome AS talhao, v.nome AS variedade
           FROM estoque_lotes l
           JOIN estoque_produtos p ON p.id = l.produto_id
           LEFT JOIN almoxarifados a ON a.id = l.almoxarifado_id
           LEFT JOIN fornecedores fo ON fo.id = l.fornecedor_id
           LEFT JOIN agro_safra_talhoes st ON st.id = l.safra_talhao_id
           LEFT JOIN agro_culturas c ON c.id = st.cultura_id
           LEFT JOIN agro_safras s ON s.id = st.safra_id
           LEFT JOIN agro_talhoes tl ON tl.id = st.talhao_id
           LEFT JOIN agro_variedades v ON v.id = l.variedade_id
          WHERE {$where}
          ORDER BY (l.validade IS NULL), l.validade, l.id", $params);
    foreach ($rowsCsv as &$rc) {
        $rc['origem_label'] = $rc['colheita_registro_id'] !== null
            ? (implode(' · ', array_filter([$rc['cultura'], $rc['safra'], $rc['talhao'], $rc['variedade']])) ?: 'Agrícola')
            : (string)($rc['fornecedor'] ?? '');
        $st = (string)($rc['status'] ?? '') !== '' ? (string)$rc['status'] : 'disponivel';
        $rc['status_label'] = LOTE_STATUS_LABEL[$st][0] ?? $st;
    }
    unset($rc);
    $loteCols = [
        'codigo_lote' => 'Lote', 'prod_codigo' => 'Código', 'prod_nome' => 'Produto',
        'origem_label' => 'Origem', 'almox' => 'Almoxarifado', 'quantidade' => 'Saldo',
        'unidade' => 'Unidade', 'custo_unitario' => 'Custo unit. (R$)', 'validade' => 'Validade',
        'status_label' => 'Status',
    ];
    $loteFmt = ['quantidade' => 'dec2', 'custo_unitario' => 'dec4', 'validade' => 'data'];
    if (!$veCusto) { unset($loteCols['custo_unitario'], $loteFmt['custo_unitario']); }
    estoque_csv_stream('lotes', $rowsCsv, $loteCols, $loteFmt);
}

$total = (int)vero_val("SELECT COUNT(*) FROM estoque_lotes l WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT l.*, p.codigo AS prod_codigo, p.nome AS prod_nome, p.unidade,
            a.nome AS almox, fo.nome AS fornecedor,
            c.nome AS cultura, s.identificacao AS safra, tl.nome AS talhao, v.nome AS variedade
       FROM estoque_lotes l
       JOIN estoque_produtos p ON p.id = l.produto_id
       LEFT JOIN almoxarifados a ON a.id = l.almoxarifado_id
       LEFT JOIN fornecedores fo ON fo.id = l.fornecedor_id
       LEFT JOIN agro_safra_talhoes st ON st.id = l.safra_talhao_id
       LEFT JOIN agro_culturas c ON c.id = st.cultura_id
       LEFT JOIN agro_safras s ON s.id = st.safra_id
       LEFT JOIN agro_talhoes tl ON tl.id = st.talhao_id
       LEFT JOIN agro_variedades v ON v.id = l.variedade_id
      WHERE {$where}
      ORDER BY (l.validade IS NULL), l.validade, l.id
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

$produtos = vero_rows("SELECT id, codigo, nome FROM estoque_produtos WHERE tenant_id = :t ORDER BY nome",
    [':t' => $t]);

$GUARD      = ['macro' => 'estoque', 'micro' => 'lotes_validade'];
$PAGE_VIEW  = 'estoque_lotes_validade';
$PAGE_TITLE = 'Lotes e Validade';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('estoque.lotes_validade.editar');
$base = rtrim(BIOS_BASE, '/');
$qsBase = http_build_query(array_filter([
    'sit' => $fSit !== 'saldo' ? $fSit : null, 'produto' => $fProduto ?: null,
    'status' => $fStatus ?: null, 'origem' => $fOrigem ?: null, 'pg' => $page > 1 ? $page : null,
]));

$badgeStatus = static function (?string $s): string {
    $s = $s !== null && $s !== '' ? $s : 'disponivel';
    [$lbl, $cls] = LOTE_STATUS_LABEL[$s] ?? [$s, 'vb-info'];
    return '<span class="vbadge ' . $cls . '">' . h($lbl) . '</span>';
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Lotes e Validade', 'Base do FEFO — as saídas consomem sempre o lote de vencimento mais próximo; lotes agrícolas (COLH-) carregam cultura, safra e válvula', null) ?>

  <?php if ($rastreio): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <strong>Rastreio — lote <?= h($rastreio['codigo_lote']) ?></strong>
      <?= $badgeStatus($rastreio['status'] ?? null) ?>
      <div style="flex:1"></div>
      <a class="vbtn vbtn-ghost vbtn-sm" href="?<?= h($qsBase) ?>">← Fechar rastreio</a>
    </div>
    <div style="display:flex;gap:22px;flex-wrap:wrap;padding:0 14px 12px;font-size:12.5px">
      <div><span class="vhint">Produto</span><br><strong><?= h($rastreio['prod_codigo'] . ' — ' . $rastreio['prod_nome']) ?></strong></div>
      <div><span class="vhint">Saldo atual</span><br><strong><?= numFmt((float)$rastreio['quantidade'], 2) ?> <?= h($rastreio['unidade']) ?></strong></div>
      <div><span class="vhint">Almoxarifado</span><br><?= h($rastreio['almox'] ?? '—') ?></div>
      <?php if ($rastreio['colheita_registro_id']): ?>
        <div><span class="vhint">Origem agrícola</span><br>
          <strong><?= h(implode(' · ', array_filter([$rastreio['cultura'], $rastreio['safra'], $rastreio['talhao'], $rastreio['variedade']]))) ?: '—' ?></strong>
          <span class="vhint">(colheita #<?= (int)$rastreio['colheita_registro_id'] ?>)</span></div>
      <?php elseif ($rastreio['fornecedor']): ?>
        <div><span class="vhint">Fornecedor</span><br><?= h($rastreio['fornecedor']) ?></div>
      <?php endif; ?>
      <?php if ($rastreio['validade']): ?>
        <div><span class="vhint">Validade</span><br><?= date('d/m/Y', strtotime((string)$rastreio['validade'])) ?></div>
      <?php endif; ?>
    </div>
    <?php if (!$rastreio['eventos']): ?>
      <div class="vempty">Nenhum movimento registrado para este lote.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Data</th><th>Mov</th><th>Sentido</th>
        <th style="text-align:right">Qtd no lote</th><th>Origem</th><th>Observação</th></tr></thead>
      <tbody>
      <?php foreach ($rastreio['eventos'] as $ev):
          $qtdEv = (float)$ev['quantidade'];
          /* sentido no LOTE: saída rateada grava POSITIVO consumido (negativo =
             estorno devolvendo); ajuste segue o sinal do delta; direto segue o tipo */
          $tipoEv = (string)$ev['tipo'];
          if ($tipoEv === 'saida')        $entradaNoLote = $qtdEv < 0;
          elseif ($tipoEv === 'entrada')  $entradaNoLote = $qtdEv >= 0;
          else                            $entradaNoLote = $qtdEv >= 0; /* ajuste */
      ?>
        <tr<?= $ev['estornado_em'] !== null ? ' style="opacity:.55"' : '' ?>>
          <td class="vnum" style="white-space:nowrap"><?= date('d/m/Y', strtotime((string)$ev['data_movimento'])) ?></td>
          <td class="vnum">#<?= (int)$ev['id'] ?></td>
          <td><?= $entradaNoLote
                ? '<span class="vbadge vb-ok">entrou</span>'
                : '<span class="vbadge vb-warn">saiu</span>' ?>
              <?= $ev['estornado_em'] !== null ? ' <span class="vbadge vb-off">estornada</span>' : '' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt(abs($qtdEv), 2) ?></td>
          <td><?= h(ucfirst(str_replace('_', ' ', (string)($ev['origem_tipo'] ?? 'estorno'))))
                . ($ev['origem_id'] ? ' <span class="vhint">#' . (int)$ev['origem_id'] . '</span>' : '') ?></td>
          <td class="vhint"><?= h(mb_substr((string)($ev['observacao'] ?? ''), 0, 90)) ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="sit" onchange="this.form.submit()">
          <option value="saldo"<?= $fSit === 'saldo' ? ' selected' : '' ?>>Com saldo</option>
          <option value="vencendo"<?= $fSit === 'vencendo' ? ' selected' : '' ?>>Vencendo em 30 dias</option>
          <option value="vencidos"<?= $fSit === 'vencidos' ? ' selected' : '' ?>>Vencidos (com saldo)</option>
          <option value="todos"<?= $fSit === 'todos' ? ' selected' : '' ?>>Todos (inclui zerados)</option>
        </select>
        <select name="produto" onchange="this.form.submit()">
          <option value="">Todos os produtos</option>
          <?php foreach ($produtos as $p): ?>
            <option value="<?= (int)$p['id'] ?>"<?= $fProduto === (int)$p['id'] ? ' selected' : '' ?>>
              <?= h($p['codigo'] . ' — ' . $p['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
          <option value="">Qualquer status</option>
          <?php foreach (VERO_LOTE_STATUS as $st): ?>
            <option value="<?= h($st) ?>"<?= $fStatus === $st ? ' selected' : '' ?>><?= h(LOTE_STATUS_LABEL[$st][0] ?? $st) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="origem" onchange="this.form.submit()">
          <option value="">Qualquer origem</option>
          <option value="agricola"<?= $fOrigem === 'agricola' ? ' selected' : '' ?>>Agrícola (colheita)</option>
          <option value="insumo"<?= $fOrigem === 'insumo' ? ' selected' : '' ?>>Insumos (compra/manual)</option>
        </select>
      </form>
      <?php
        /* A2-F2-23: "Exportar CSV" = filtro atual + csv=1 */
        $loteExportUrl = '?' . http_build_query(array_filter([
            'sit' => $fSit !== 'saldo' ? $fSit : null, 'produto' => $fProduto ?: null,
            'status' => $fStatus ?: null, 'origem' => $fOrigem ?: null, 'csv' => '1',
        ]));
      ?>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h($loteExportUrl) ?>" title="Baixar os lotes filtrados em CSV (abre no Excel)">Exportar CSV</a>
      <span class="vsub"><?= $total ?> lote(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum lote no filtro — lotes nascem nas entradas com validade (compras ou manuais) e na confirmação de colheita (COLH-).</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th>Lote</th><th>Produto</th><th>Origem</th><th>Almoxarifado</th>
        <th style="text-align:right">Saldo</th>
        <th style="text-align:right">Custo unit. (R$)</th>
        <th>Validade</th><th>Situação</th><th>Status</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $dias = $r['validade'] !== null
              ? (int)floor((strtotime((string)$r['validade']) - strtotime(date('Y-m-d'))) / 86400) : null;
          $statusAtual = (string)($r['status'] ?? '') !== '' ? (string)$r['status'] : 'disponivel';
          $origemAgr = $r['colheita_registro_id'] !== null;
      ?>
        <tr<?= (float)$r['quantidade'] <= 0 ? ' style="opacity:.55"' : '' ?>>
          <td><strong class="vnum"><?= h($r['codigo_lote']) ?></strong></td>
          <td><strong class="vnum"><?= h($r['prod_codigo']) ?></strong> <?= h($r['prod_nome']) ?></td>
          <td><?php if ($origemAgr): ?>
              <?= h(implode(' · ', array_filter([$r['cultura'], $r['safra'], $r['talhao']]))) ?: '<span class="vhint">agrícola</span>' ?>
              <?php if ($r['variedade']): ?><div class="vhint"><?= h($r['variedade']) ?></div><?php endif; ?>
            <?php else: ?>
              <?= h($r['fornecedor'] ?? '') ?: '<span class="vhint">—</span>' ?>
            <?php endif; ?></td>
          <td><?= h($r['almox'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['quantidade'], 2) ?></strong> <span class="vhint"><?= h($r['unidade']) ?></span></td>
          <td class="vnum" style="text-align:right"><?= $veCusto ? numFmt((float)$r['custo_unitario'], 2) : '<span class="vhint">•••</span>' ?></td>
          <td class="vnum"><?= $r['validade'] !== null ? date('d/m/Y', strtotime((string)$r['validade'])) : '—' ?></td>
          <td>
            <?php if ($r['validade'] === null): ?>
              <span class="vhint">sem validade</span>
            <?php elseif ((float)$r['quantidade'] <= 0): ?>
              <span class="vhint">zerado</span>
            <?php elseif ($dias < 0): ?>
              <span class="vbadge vb-off">VENCIDO há <?= abs($dias) ?>d</span>
            <?php elseif ($dias <= 30): ?>
              <span class="vbadge vb-warn">vence em <?= $dias ?>d</span>
            <?php else: ?>
              <span class="vbadge vb-ok">ok</span>
            <?php endif; ?>
          </td>
          <td><?= $badgeStatus($statusAtual) ?></td>
          <td><div class="vactions" style="justify-content:flex-end">
            <?= vero_btn_icone(vero_ico_olho(), 'Rastrear', '', '?rastreio=' . (int)$r['id']) ?>
            <?php if ($podeEditar && in_array($statusAtual, ['disponivel', 'bloqueado'], true) && (float)$r['quantidade'] > 0): $bloquear = $statusAtual === 'disponivel'; ?>
              <form method="post" style="display:inline" data-confirm="<?= $bloquear
                  ? 'BLOQUEAR este lote? O FEFO não deve consumi-lo até o desbloqueio.'
                  : 'Desbloquear este lote (volta a disponível)?' ?>" data-confirm-ok="<?= $bloquear ? 'Bloquear' : 'Desbloquear' ?>" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="status">
                <input type="hidden" name="lote_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="novo_status" value="<?= $bloquear ? 'bloqueado' : 'disponivel' ?>">
                <button type="submit" class="vicon vicon-acao" title="<?= $bloquear ? 'Bloquear lote' : 'Desbloquear lote' ?>" aria-label="<?= $bloquear ? 'Bloquear lote' : 'Desbloquear lote' ?>"><?php if ($bloquear): ?><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><?php else: ?><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg><?php endif; ?></button>
              </form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
