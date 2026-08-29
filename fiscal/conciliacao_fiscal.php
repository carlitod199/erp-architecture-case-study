<?php
/* ============================================================
   VERO — Fiscal / Conciliação Fiscal  (tela real)
   Substitui o mock. Rota: /fiscal/conciliacao_fiscal.php
   Guard: fiscal.conciliacao_fiscal
   Casa documento fiscal importado × pedido de compra
   (fiscal_conciliacoes): sugestão automática por fornecedor+valor,
   conciliar marca o documento como conciliado; diferença de valor
   registra divergência com observação.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'conciliar') {
        vero_require('fiscal.conciliacao_fiscal.editar');
        $docId = vero_int('documento_id');
        $pedId = vero_int('pedido_id');
        $doc = $docId ? vero_row("SELECT * FROM fiscal_documentos WHERE id=:i AND tenant_id=:t", [':i' => $docId, ':t' => $t]) : null;
        $ped = $pedId ? vero_row("SELECT * FROM compras_pedidos WHERE id=:i AND tenant_id=:t", [':i' => $pedId, ':t' => $t]) : null;
        if (!$doc || !$ped) {
            vero_flash('erro', 'Selecione o documento e o pedido.');
            vero_redirect();
        }
        $ja = vero_val("SELECT id FROM fiscal_conciliacoes WHERE tenant_id=:t AND documento_id=:d AND status <> 'pendente'",
            [':t' => $t, ':d' => (int)$docId]);
        if ($ja) {
            vero_flash('erro', 'Este documento já foi conciliado.');
            vero_redirect();
        }
        $dif = (float)$doc['valor_total'] - (float)$ped['valor_total'];
        $divergente = abs($dif) > 0.005;
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            vero_insert('fiscal_conciliacoes', [
                'documento_id' => (int)$docId,
                'pedido_id'    => (int)$pedId,
                'status'       => $divergente ? 'divergente' : 'conciliado',
                'observacao'   => $divergente
                    ? 'Diferença de R$ ' . numFmt($dif, 2) . ' entre documento e pedido'
                    : ('Valores conferem (R$ ' . numFmt((float)$doc['valor_total'], 2) . ')'),
            ]);
            if (!$divergente) {
                vero_update('fiscal_documentos', (int)$docId, ['status' => 'conciliado']);
            }
            $pdo->commit();
            vero_flash($divergente ? 'erro' : 'ok',
                $divergente
                    ? 'Conciliação registrada com DIVERGÊNCIA de R$ ' . numFmt($dif, 2) . ' — revise documento e pedido.'
                    : 'Documento conciliado com o pedido ' . $ped['numero'] . ' ✓');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'desfazer') {
        vero_require('fiscal.conciliacao_fiscal.excluir');
        $id = vero_int('id');
        $c = $id ? vero_row("SELECT * FROM fiscal_conciliacoes WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if ($c) {
            $pdo = vero_pdo();
            $pdo->beginTransaction();
            try {
                if ($c['documento_id'] !== null) {
                    vero_update('fiscal_documentos', (int)$c['documento_id'], ['status' => 'importado']);
                }
                $pdo->prepare("DELETE FROM fiscal_conciliacoes WHERE tenant_id=? AND id=?")->execute([$t, (int)$id]);
                $pdo->commit();
                vero_flash('ok', 'Conciliação desfeita — documento voltou para importado.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                vero_flash('erro', $e->getMessage());
            }
        }
        vero_redirect();
    }
}

/* documentos pendentes de conciliação */
$pendentes = vero_rows(
    "SELECT d.*, f.nome AS fornecedor FROM fiscal_documentos d
       LEFT JOIN fornecedores f ON f.id = d.fornecedor_id
      WHERE d.tenant_id = :t AND d.status = 'importado'
      ORDER BY d.data_emissao DESC, d.id DESC LIMIT 50", [':t' => $t]);

/* pedidos candidatos (não cancelados) */
$pedidos = vero_rows(
    "SELECT p.id, p.numero, p.valor_total, p.fornecedor_id, f.nome AS fornecedor
       FROM compras_pedidos p
       LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
      WHERE p.tenant_id = :t AND p.status <> 'cancelado'
      ORDER BY p.id DESC LIMIT 100", [':t' => $t]);

/* sugestão automática: mesmo fornecedor e valor igual */
$sugestoes = [];
foreach ($pendentes as $d) {
    foreach ($pedidos as $p) {
        if ($d['fornecedor_id'] !== null && (int)$p['fornecedor_id'] === (int)$d['fornecedor_id']
            && abs((float)$p['valor_total'] - (float)$d['valor_total']) < 0.005) {
            $sugestoes[(int)$d['id']] = $p;
            break;
        }
    }
}

$historico = vero_rows(
    "SELECT c.*, d.numero AS doc_numero, d.valor_total AS doc_valor, p.numero AS ped_numero, p.valor_total AS ped_valor
       FROM fiscal_conciliacoes c
       LEFT JOIN fiscal_documentos d ON d.id = c.documento_id
       LEFT JOIN compras_pedidos p ON p.id = c.pedido_id
      WHERE c.tenant_id = :t ORDER BY c.id DESC LIMIT 30", [':t' => $t]);

$GUARD      = ['macro' => 'fiscal', 'micro' => 'conciliacao_fiscal'];
$PAGE_VIEW  = 'fiscal_conciliacao_fiscal';
$PAGE_TITLE = 'Conciliação Fiscal';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('fiscal.conciliacao_fiscal.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Conciliação Fiscal', 'Casa o documento fiscal com o pedido de compra — fornecedor e valor conferidos', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Documentos pendentes</strong>
      <span class="vsub"><?= count($pendentes) ?> documento(s)</span></div>
    <?php if (!$pendentes): ?>
      <div class="vempty">Nenhum documento pendente de conciliação. ✓</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Documento</th><th>Fornecedor</th>
        <th style="text-align:right">Valor (R$)</th>
        <th>Sugestão</th>
        <?php if ($podeEditar): ?><th style="width:320px">Conciliar com pedido</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($pendentes as $d):
          $sug = $sugestoes[(int)$d['id']] ?? null; ?>
        <tr>
          <td><strong class="vnum"><?= h(strtoupper((string)$d['tipo']) . ' ' . ($d['numero'] ?? '#' . $d['id'])) ?></strong></td>
          <td><?= h($d['fornecedor'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$d['valor_total'], 2) ?></strong></td>
          <td><?= $sug
                ? '<span class="vbadge vb-ok">' . h((string)$sug['numero']) . ' (valor confere)</span>'
                : '<span class="vhint">sem par exato</span>' ?></td>
          <?php if ($podeEditar): ?>
          <td>
            <form method="post" style="display:flex;gap:6px">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="conciliar">
              <input type="hidden" name="documento_id" value="<?= (int)$d['id'] ?>">
              <select name="pedido_id" required style="flex:1">
                <option value="">Pedido…</option>
                <?php foreach ($pedidos as $p): ?>
                  <option value="<?= (int)$p['id'] ?>"<?= $sug && (int)$sug['id'] === (int)$p['id'] ? ' selected' : '' ?>>
                    <?= h($p['numero'] . ' — ' . ($p['fornecedor'] ?? '') . ' (R$ ' . numFmt((float)$p['valor_total'], 2) . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button class="vbtn vbtn-primary vbtn-sm" type="submit">Conciliar</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Histórico de conciliações</strong>
      <span class="vsub"><?= count($historico) ?> registro(s)</span></div>
    <?php if (!$historico): ?>
      <div class="vempty">Nenhuma conciliação registrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Documento</th><th>Pedido</th>
        <th style="text-align:right">Doc. (R$)</th>
        <th style="text-align:right">Pedido (R$)</th>
        <th>Status</th><th>Observação</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($historico as $c): ?>
        <tr>
          <td class="vnum"><strong><?= h($c['doc_numero'] ?? '—') ?></strong></td>
          <td class="vnum"><?= h($c['ped_numero'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= $c['doc_valor'] !== null ? numFmt((float)$c['doc_valor'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $c['ped_valor'] !== null ? numFmt((float)$c['ped_valor'], 2) : '—' ?></td>
          <td><?= $c['status'] === 'conciliado' ? '<span class="vbadge vb-ok">Conciliado</span>'
                : ($c['status'] === 'divergente' ? '<span class="vbadge vb-off">Divergente</span>'
                : '<span class="vbadge vb-warn">Pendente</span>') ?></td>
          <td class="vhint"><?= h(mb_substr((string)($c['observacao'] ?? ''), 0, 60)) ?: '—' ?></td>
          <td><div class="vactions">
            <?php if (vero_can('fiscal.conciliacao_fiscal.excluir')): ?>
              <form method="post" onsubmit="return confirm('Desfazer esta conciliação?')">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="desfazer">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Desfazer</button>
              </form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
