<?php
/* ============================================================
   VERO — Compras / Compras Fora do Orçamento  (tela real, leitura)
   Substitui o mock. Rota: /compras/fora_orcamento.php
   Guard: compras.compras_fora_orcamento
   Confronta pedidos de compra (não cancelados) com o orçamento
   de insumos da safra vinculada: pedidos sem safra, sem orçamento
   vigente ou que estouram o previsto aparecem aqui. Usa também a
   marcação acima_orcamento do próprio pedido quando existir.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

$pedidos = vero_rows(
    "SELECT p.*, f.nome AS fornecedor, st.safra_id, sa.identificacao AS safra
       FROM compras_pedidos p
       LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
       LEFT JOIN agro_safra_talhoes st ON st.id = p.safra_talhao_id
       LEFT JOIN agro_safras sa ON sa.id = st.safra_id
      WHERE p.tenant_id = :t AND p.status <> 'cancelado'
      ORDER BY p.id DESC", [':t' => $t]);

/* orçamento de insumos vigente por safra */
$orcInsumos = [];
foreach (vero_rows(
    "SELECT o.safra_id, COALESCE(SUM(i.valor_previsto),0) AS previsto
       FROM custeio_orcamentos o
       JOIN custeio_orcamento_itens i ON i.orcamento_id = o.id AND i.tenant_id = o.tenant_id
      WHERE o.tenant_id = :t AND o.status = 'vigente' AND i.categoria = 'insumos'
      GROUP BY o.safra_id", [':t' => $t]) as $r) {
    $orcInsumos[(int)$r['safra_id']] = (float)$r['previsto'];
}

/* total de pedidos por safra (para medir consumo do orçamento) */
$gastoSafra = [];
foreach ($pedidos as $p) {
    $sid = $p['safra_id'] !== null ? (int)$p['safra_id'] : 0;
    $gastoSafra[$sid] = ($gastoSafra[$sid] ?? 0.0) + (float)$p['valor_total'];
}

$fora = [];
foreach ($pedidos as $p) {
    $sid = $p['safra_id'] !== null ? (int)$p['safra_id'] : 0;
    $motivo = null;
    if ((int)($p['acima_orcamento'] ?? 0) === 1) {
        $motivo = 'Marcado acima do orçamento no pedido';
    } elseif ($sid === 0) {
        $motivo = 'Pedido sem safra vinculada';
    } elseif (!isset($orcInsumos[$sid])) {
        $motivo = 'Safra sem orçamento vigente de insumos';
    } elseif (($gastoSafra[$sid] ?? 0) > $orcInsumos[$sid]) {
        $motivo = 'Pedidos da safra excedem o orçado de insumos (R$ ' . numFmt($orcInsumos[$sid], 2) . ')';
    }
    if ($motivo !== null) $fora[] = $p + ['motivo' => $motivo];
}
$totFora = array_sum(array_map(static fn($p) => (float)$p['valor_total'], $fora));

$GUARD      = ['macro' => 'compras', 'micro' => 'compras_fora_orcamento'];
$PAGE_VIEW  = 'compras_compras_fora_orcamento';
$PAGE_TITLE = 'Compras Fora do Orçamento';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Compras Fora do Orçamento', 'Pedidos sem cobertura no orçamento de insumos da safra — para revisão da gestão', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <span class="vsub"><?= count($fora) ?> de <?= count($pedidos) ?> pedido(s) precisam de atenção</span>
      <span class="vsub">total fora do orçamento <strong class="vnum">R$ <?= numFmt($totFora, 2) ?></strong> ·
        <a href="<?= $base ?>/custeio/orcamento.php">orçamentos</a></span>
    </div>

    <?php if (!$pedidos): ?>
      <div class="vempty">Nenhum pedido de compra registrado.</div>
    <?php elseif (!$fora): ?>
      <div class="vempty">Todos os pedidos estão cobertos pelo orçamento vigente de insumos. ✓</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Pedido</th><th>Fornecedor</th><th>Safra</th>
        <th class="num">Valor (R$)</th>
        <th>Status</th><th>Motivo</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($fora as $p): ?>
        <tr style="box-shadow:inset 3px 0 0 #C2410C">
          <td><strong class="vnum"><?= h($p['numero']) ?></strong>
            <div class="vhint"><?= $p['data_pedido'] ? date('d/m/Y', strtotime((string)$p['data_pedido'])) : '' ?></div></td>
          <td><?= h($p['fornecedor'] ?? '—') ?></td>
          <td><?= $p['safra'] ? h($p['safra']) : '<span style="color:#b3261e;font-weight:600">sem safra</span>' ?></td>
          <td class="num"><strong><?= numFmt((float)$p['valor_total'], 2) ?></strong></td>
          <td class="vhint"><?= h(str_replace('_', ' ', (string)$p['status'])) ?></td>
          <td><span style="color:#8A6D1A;font-weight:600;font-size:12px"><?= h((string)$p['motivo']) ?></span></td>
          <td class="num"><?= vero_btn_icone(vero_ico_olho(), 'Ver orçamento da safra', '', $base . '/custeio/orcamento') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      A leitura compara o total dos pedidos da safra com a categoria "Insumos" do orçamento vigente.
      Pedidos sem safra vinculada entram por precaução — vincule na conversão da solicitação.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
