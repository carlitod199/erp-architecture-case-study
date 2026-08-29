<?php
/* ============================================================
   VERO — Compras / Histórico de Compras  (tela real)
   Substitui o mock. Rota: /compras/historico_compras.php
   Guard: compras.historico_compras
   Leitura consolidada dos recebimentos confirmados por item,
   com filtros de fornecedor, produto e período + totais.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$fForn    = (int)($_GET['fornecedor'] ?? 0);
$fProduto = (int)($_GET['produto'] ?? 0);
$fIni     = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim     = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';
$page     = max(1, (int)($_GET['pg'] ?? 1));
$perPage  = 25;

$where  = "r.tenant_id = :t AND r.status = 'confirmado'";
$params = [':t' => vero_tenant()];
if ($fForn > 0)    { $where .= " AND p.fornecedor_id = :f"; $params[':f'] = $fForn; }
if ($fProduto > 0) { $where .= " AND ri.produto_id = :pr";  $params[':pr'] = $fProduto; }
if ($fIni !== '')  { $where .= " AND r.data_recebimento >= :ini"; $params[':ini'] = $fIni . ' 00:00:00'; }
if ($fFim !== '')  { $where .= " AND r.data_recebimento <= :fim"; $params[':fim'] = $fFim . ' 23:59:59'; }

$base = "FROM compras_recebimento_itens ri
         JOIN compras_recebimentos r ON r.id = ri.recebimento_id
         JOIN compras_pedidos p ON p.id = r.pedido_id
         JOIN fornecedores f ON f.id = p.fornecedor_id
         LEFT JOIN compras_pedido_itens pi ON pi.id = ri.pedido_item_id
         LEFT JOIN estoque_produtos ep ON ep.id = ri.produto_id
        WHERE {$where}";

$tot = vero_row("SELECT COUNT(*) AS linhas, COALESCE(SUM(ri.quantidade * ri.custo_unitario),0) AS valor {$base}", $params);
$rows = vero_rows(
    "SELECT ri.*, r.numero AS recebimento_numero, r.data_recebimento,
            p.numero AS pedido_numero, f.nome AS fornecedor,
            ep.codigo AS produto_codigo, ep.nome AS produto_nome, ep.unidade,
            pi.descricao AS item_descricao
     {$base}
     ORDER BY r.data_recebimento DESC, ri.id DESC
     LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

$fornecedores = vero_options('fornecedores', 'nome');
$produtos = vero_rows("SELECT id, codigo, nome FROM estoque_produtos WHERE tenant_id = :t ORDER BY nome",
    [':t' => vero_tenant()]);

$GUARD      = ['macro' => 'compras', 'micro' => 'historico_compras'];
$PAGE_VIEW  = 'compras_historico_compras';
$PAGE_TITLE = 'Histórico de Compras';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Histórico de Compras', 'Itens efetivamente recebidos, ao custo real — base para negociação e análise de preço', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <select name="fornecedor" onchange="this.form.submit()">
          <option value="">Todos os fornecedores</option>
          <?php foreach ($fornecedores as $fid => $fn): ?>
            <option value="<?= $fid ?>"<?= $fForn === $fid ? ' selected' : '' ?>><?= h($fn) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="produto" onchange="this.form.submit()">
          <option value="">Todos os produtos</option>
          <?php foreach ($produtos as $pr): ?>
            <option value="<?= (int)$pr['id'] ?>"<?= $fProduto === (int)$pr['id'] ? ' selected' : '' ?>>
              <?= h($pr['codigo'] . ' — ' . $pr['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
        <?php if ($fForn || $fProduto || $fIni !== '' || $fFim !== ''): ?><a class="vbtn vbtn-ghost vbtn-sm" href="<?= h(strtok((string)$_SERVER['REQUEST_URI'], '?')) ?>" data-vero-clear>Limpar filtros</a><?php endif; ?>
      </form>
      <span class="vsub"><?= (int)$tot['linhas'] ?> item(ns) ·
        total <strong class="vnum">R$ <?= numFmt((float)$tot['valor'], 2) ?></strong></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty"><?= ($fForn || $fProduto || $fIni !== '' || $fFim !== '') ? 'Nenhum recebimento para os filtros selecionados.' : 'Nenhuma compra recebida ainda.' ?></div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Data</th><th>Recebimento</th><th>Pedido</th><th>Fornecedor</th><th>Item</th>
        <th class="num">Qtd</th>
        <th class="num">Custo unit. (R$)</th>
        <th class="num">Total (R$)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="vnum" style="white-space:nowrap"><?= date('d/m/Y', strtotime((string)$r['data_recebimento'])) ?></td>
          <td class="vnum"><?= h($r['recebimento_numero'] ?? '—') ?></td>
          <td class="vnum"><strong><?= h($r['pedido_numero']) ?></strong></td>
          <td><?= h($r['fornecedor']) ?></td>
          <td><?= $r['produto_nome']
                ? '<strong class="vnum">' . h($r['produto_codigo']) . '</strong> ' . h($r['produto_nome'])
                : h($r['item_descricao'] ?? '—') ?></td>
          <td class="num"><?= numFmt((float)$r['quantidade'], 2) ?> <span class="vhint"><?= h($r['unidade'] ?? '') ?></span></td>
          <td class="num"><?= numFmt((float)$r['custo_unitario'], 2) ?></td>
          <td class="num"><strong><?= numFmt((float)$r['quantidade'] * (float)$r['custo_unitario'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= vero_pagination($page, (int)$tot['linhas'], $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
