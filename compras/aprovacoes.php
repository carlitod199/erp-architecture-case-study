<?php
/* ============================================================
   VERO — Compras / Aprovações  (tela real)
   Substitui o mock. Rota: /compras/aprovacoes.php
   Guard: compras.aprovacoes
   Fila de pedidos em aprovação (compras_aprovacoes nível 1):
   aprovar → pedido liberado para recebimento; rejeitar → volta
   a rascunho com a observação registrada.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if (in_array($acao, ['aprovar', 'rejeitar'], true)) {
        vero_require('compras.aprovacoes.editar');

        $aprovacaoId = vero_int('aprovacao_id');
        $apr = $aprovacaoId ? vero_row(
            "SELECT a.*, p.numero, p.status AS pedido_status
               FROM compras_aprovacoes a
               JOIN compras_pedidos p ON p.id = a.pedido_id
              WHERE a.id = :i AND a.tenant_id = :t AND a.status = 'pendente'",
            [':i' => $aprovacaoId, ':t' => vero_tenant()]) : null;
        if (!$apr || $apr['pedido_status'] !== 'aprovacao') {
            vero_flash('erro', 'Aprovação inválida ou já decidida.');
            vero_redirect();
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE compras_aprovacoes
                              SET status = ?, aprovador_id = ?, observacao = ?, data_decisao = NOW()
                            WHERE tenant_id = ? AND id = ?")
                ->execute([
                    $acao === 'aprovar' ? 'aprovado' : 'rejeitado',
                    vero_uid(), vero_str('observacao', 255), vero_tenant(), (int)$aprovacaoId,
                ]);
            vero_update('compras_pedidos', (int)$apr['pedido_id'], [
                'status' => $acao === 'aprovar' ? 'aprovado' : 'rascunho',
            ]);
            $pdo->commit();
            vero_flash('ok', "Pedido {$apr['numero']} " . ($acao === 'aprovar'
                ? 'APROVADO — liberado para recebimento.'
                : 'rejeitado — voltou a rascunho para ajustes.'));
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro: ' . h($e->getMessage()));
        }
        vero_redirect();
    }
}

/* ── Fila e histórico ───────────────────────────────────────── */
$pendentes = vero_rows(
    "SELECT a.id AS aprovacao_id, a.created_at AS enviado_em, p.*, f.nome AS fornecedor,
            (SELECT COUNT(*) FROM compras_pedido_itens i
              WHERE i.tenant_id = p.tenant_id AND i.pedido_id = p.id) AS itens
       FROM compras_aprovacoes a
       JOIN compras_pedidos p ON p.id = a.pedido_id
       JOIN fornecedores f ON f.id = p.fornecedor_id
      WHERE a.tenant_id = :t AND a.status = 'pendente' AND p.status = 'aprovacao'
      ORDER BY a.id", [':t' => vero_tenant()]);

$itensPorPedido = [];
if ($pendentes) {
    $ids = implode(',', array_map(static fn($p) => (int)$p['id'], $pendentes));
    foreach (vero_rows(
        "SELECT i.*, ep.nome AS produto_nome, ep.codigo AS produto_codigo
           FROM compras_pedido_itens i
           LEFT JOIN estoque_produtos ep ON ep.id = i.produto_id
          WHERE i.tenant_id = :t AND i.pedido_id IN ({$ids}) ORDER BY i.id",
        [':t' => vero_tenant()]) as $it) {
        $itensPorPedido[(int)$it['pedido_id']][] = $it;
    }
}

$decididas = vero_rows(
    "SELECT a.*, p.numero, p.valor_total, f.nome AS fornecedor, u.nome AS aprovador
       FROM compras_aprovacoes a
       JOIN compras_pedidos p ON p.id = a.pedido_id
       JOIN fornecedores f ON f.id = p.fornecedor_id
       LEFT JOIN usuarios u ON u.id = a.aprovador_id
      WHERE a.tenant_id = :t AND a.status <> 'pendente'
      ORDER BY a.data_decisao DESC LIMIT 15", [':t' => vero_tenant()]);

$GUARD      = ['macro' => 'compras', 'micro' => 'aprovacoes'];
$PAGE_VIEW  = 'compras_aprovacoes';
$PAGE_TITLE = 'Aprovações de Compra';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeAprovar = vero_can('compras.aprovacoes.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Aprovações de Compra', 'Fila de pedidos aguardando decisão — aprovado libera o recebimento; rejeitado volta a rascunho', null) ?>

  <?php if (!$pendentes): ?>
    <div class="vcard"><div class="vempty">Nenhum pedido aguardando aprovação.</div></div>
  <?php else: ?>
    <?php foreach ($pendentes as $p): ?>
    <div class="vcard" style="margin-bottom:16px">
      <div class="vtoolbar">
        <strong style="font-size:14px">Pedido <?= h($p['numero']) ?></strong>
        <span class="vsub"><?= h($p['fornecedor']) ?> · <?= $p['data_pedido'] ? date('d/m/Y', strtotime((string)$p['data_pedido'])) : '—' ?>
          · enviado em <?= date('d/m/Y H:i', strtotime((string)$p['enviado_em'])) ?></span>
        <div style="flex:1"></div>
        <strong class="vnum" style="font-size:15px;color:#005059">R$ <?= numFmt((float)$p['valor_total'], 2) ?></strong>
      </div>
      <div class="vdata-wrap">
      <table class="vdata">
        <thead><tr>
          <th>Item</th><th class="num">Qtd</th>
          <th class="num">R$ unitário</th><th class="num">Total</th>
        </tr></thead>
        <tbody>
        <?php foreach (($itensPorPedido[(int)$p['id']] ?? []) as $it): ?>
          <tr>
            <td><?= $it['produto_nome']
                  ? '<strong class="vnum">' . h($it['produto_codigo']) . '</strong> ' . h($it['produto_nome'])
                  : h($it['descricao'] ?? '—') ?></td>
            <td class="num"><?= numFmt((float)$it['quantidade'], 2) ?></td>
            <td class="num"><?= numFmt((float)$it['valor_unitario'], 2) ?></td>
            <td class="num"><strong><?= numFmt((float)$it['valor_total'], 2) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if ($podeAprovar): ?>
      <form method="post" class="vtoolbar" style="border-top:1px solid #EEE8DB;border-bottom:0">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="aprovacao_id" value="<?= (int)$p['aprovacao_id'] ?>">
        <input type="text" name="observacao" placeholder="Observação da decisão (opcional)" style="flex:1;min-width:220px">
        <button class="vbtn vbtn-ghost" type="submit" name="acao" value="rejeitar"
                data-confirm="Rejeitar este pedido? Ele volta a rascunho." data-confirm-danger data-confirm-ok="Rejeitar"
                onclick="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">Rejeitar</button>
        <button class="vbtn vbtn-primary" type="submit" name="acao" value="aprovar">Aprovar pedido</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong style="font-size:14px">Últimas decisões</strong></div>
    <?php if (!$decididas): ?>
      <div class="vempty">Nenhuma decisão registrada.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Pedido</th><th>Fornecedor</th>
        <th class="num">Valor (R$)</th>
        <th>Decisão</th><th>Tipo</th><th>Por</th><th>Quando</th><th>Observação</th>
      </tr></thead>
      <tbody>
      <?php foreach ($decididas as $d):
          $auto = str_starts_with((string)($d['observacao'] ?? ''), 'Auto-aprovado por alçada'); ?>
        <tr>
          <td><strong class="vnum"><?= h($d['numero']) ?></strong></td>
          <td><?= h($d['fornecedor']) ?></td>
          <td class="num"><?= numFmt((float)$d['valor_total'], 2) ?></td>
          <td><?= $d['status'] === 'aprovado'
                ? '<span class="vbadge vb-ok">Aprovado</span>'
                : '<span class="vbadge vb-off">Rejeitado</span>' ?></td>
          <td><?= $auto
                ? '<span style="color:#1E6B34;font-size:11.5px;font-weight:600">Automática (alçada)</span>'
                : '<span class="vhint">Manual</span>' ?></td>
          <td><?= $auto ? '<span class="vhint">sistema</span>' : h($d['aprovador'] ?? '—') ?></td>
          <td class="vhint" style="white-space:nowrap"><?= $d['data_decisao'] ? date('d/m/Y H:i', strtotime((string)$d['data_decisao'])) : '—' ?></td>
          <td class="vhint"><?= h($d['observacao'] ?? '') ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
