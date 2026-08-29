<?php
/* ============================================================
   VERO — Compras / Cotações  (tela real)
   Substitui o mock. Rota: /compras/cotacoes.php
   Cotações por solicitação de compra: cada fornecedor cotado vira
   uma linha em compras_cotacoes com itens precificados; o
   comparativo mostra os totais lado a lado e permite marcar a
   vencedora (as demais ficam preteridas).
   Guard: compras.cotacoes (micro proprio desde o A0-04/P-26). Antes: protegida pelo micro de Solicitações de Compra até existir
   permissão própria na matriz central (mesma convenção do mock).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'compras_cotacoes';
const PERM = 'compras.cotacoes'; /* micro proprio desde o A0-04 (P-26) */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require(PERM . '.editar');
        $solId  = vero_int('solicitacao_id');
        $fornId = vero_int('fornecedor_id');
        $data   = vero_date('data_cotacao') ?? date('Y-m-d');
        $sol = $solId ? vero_row("SELECT * FROM compras_solicitacoes WHERE id=:i AND tenant_id=:t",
            [':i' => $solId, ':t' => vero_tenant()]) : null;
        if (!$sol || !$fornId) {
            vero_flash('erro', 'Solicitação e fornecedor são obrigatórios.');
            vero_redirect();
        }
        $itensSol = vero_rows("SELECT * FROM compras_solicitacao_itens WHERE tenant_id=:t AND solicitacao_id=:s",
            [':t' => vero_tenant(), ':s' => (int)$solId]);
        if (!$itensSol) {
            vero_flash('erro', 'A solicitação não tem itens.');
            vero_redirect();
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $total = 0.0;
            $precos = [];
            foreach ($itensSol as $it) {
                $vu = vero_dec('preco_' . (int)$it['id']);
                if ($vu === null || $vu < 0) {
                    throw new RuntimeException('Informe o preço unitário de todos os itens (use 0 se cortesia).');
                }
                $precos[(int)$it['id']] = $vu;
                $total += $vu * (float)$it['quantidade'];
            }
            $cotId = vero_insert(T, [
                'solicitacao_id' => (int)$solId,
                'fornecedor_id'  => (int)$fornId,
                'data_cotacao'   => $data,
                'valor_total'    => $total,
                'status'         => 'aberta',
            ]);
            $ins = $pdo->prepare(
                "INSERT INTO compras_cotacao_itens
                    (tenant_id, cotacao_id, produto_id, descricao, quantidade, valor_unitario, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
            foreach ($itensSol as $it) {
                $ins->execute([vero_tenant(), (int)$cotId, $it['produto_id'] ?: null,
                    $it['descricao'], $it['quantidade'], $precos[(int)$it['id']]]);
            }
            /* solicitação aberta passa a 'em_cotacao' (A2-F2-3 — valor de ENUM ocioso ativado) */
            if ($sol['status'] === 'aberta') {
                vero_update('compras_solicitacoes', (int)$solId, ['status' => 'em_cotacao']);
            }
            $pdo->commit();
            vero_flash('ok', 'Cotação registrada — total R$ ' . numFmt($total, 2) . '.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect('?solicitacao=' . (int)$solId);
    }

    if ($acao === 'selecionar') {
        vero_require(PERM . '.editar');
        $id = vero_int('id');
        $cot = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if ($cot) {
            $pdo = vero_pdo();
            $pdo->beginTransaction();
            try {
                /* ENUM da tabela: aberta | escolhida | recusada */
                $pdo->prepare("UPDATE " . T . " SET status = 'recusada', updated_at = NOW()
                                WHERE tenant_id = ? AND solicitacao_id = ? AND status IN ('aberta','escolhida')")
                    ->execute([vero_tenant(), (int)$cot['solicitacao_id']]);
                vero_update(T, (int)$id, ['status' => 'escolhida']);
                $pdo->commit();
                vero_flash('ok', 'Cotação marcada como escolhida — as demais ficaram recusadas. Use "Gerar pedido" para converter com fornecedor e preços.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                vero_flash('erro', $e->getMessage());
            }
        }
        vero_redirect('?solicitacao=' . (int)($cot['solicitacao_id'] ?? 0));
    }

    if ($acao === 'excluir') { /* recusa lógica — comparativo preserva a história */
        vero_require(PERM . '.excluir');
        $id = vero_int('id');
        $cot = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if ($cot) {
            vero_update(T, (int)$id, ['status' => 'recusada']);
            vero_flash('ok', 'Cotação recusada.');
        }
        vero_redirect('?solicitacao=' . (int)($cot['solicitacao_id'] ?? 0));
    }
}

$t = vero_tenant();
$fSol = (int)($_GET['solicitacao'] ?? 0);

/* solicitações com itens (qualquer status — cotar faz sentido antes do pedido) */
$solicitacoes = vero_rows(
    "SELECT s.id, s.numero, s.status, s.justificativa,
            (SELECT COUNT(*) FROM compras_solicitacao_itens i WHERE i.tenant_id = s.tenant_id AND i.solicitacao_id = s.id) AS itens,
            (SELECT COUNT(*) FROM " . T . " c WHERE c.tenant_id = s.tenant_id AND c.solicitacao_id = s.id AND c.status <> 'recusada') AS cotacoes
       FROM compras_solicitacoes s
      WHERE s.tenant_id = :t
      ORDER BY s.id DESC", [':t' => $t]);

$solAtual = null;
$itensSol = [];
$cotacoes = [];
if ($fSol > 0) {
    $solAtual = vero_row("SELECT * FROM compras_solicitacoes WHERE id=:i AND tenant_id=:t", [':i' => $fSol, ':t' => $t]);
    if ($solAtual) {
        $itensSol = vero_rows(
            "SELECT i.*, p.nome AS produto, p.unidade
               FROM compras_solicitacao_itens i
               LEFT JOIN estoque_produtos p ON p.id = i.produto_id
              WHERE i.tenant_id = :t AND i.solicitacao_id = :s ORDER BY i.id", [':t' => $t, ':s' => $fSol]);
        $cotacoes = vero_rows(
            "SELECT c.*, f.nome AS fornecedor
               FROM " . T . " c
               LEFT JOIN fornecedores f ON f.id = c.fornecedor_id
              WHERE c.tenant_id = :t AND c.solicitacao_id = :s
              ORDER BY (c.status='escolhida') DESC, c.valor_total", [':t' => $t, ':s' => $fSol]);
        foreach ($cotacoes as &$c) {
            $c['itens'] = vero_rows(
                "SELECT * FROM compras_cotacao_itens WHERE tenant_id = :t AND cotacao_id = :c ORDER BY id",
                [':t' => $t, ':c' => (int)$c['id']]);
        }
        unset($c);
    }
}

$fornecedores = vero_rows(
    "SELECT id, nome FROM fornecedores
      WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => $t]);
$fornOpts = [];
foreach ($fornecedores as $fRow) $fornOpts[(int)$fRow['id']] = (string)$fRow['nome'];

$GUARD      = ['macro' => 'compras', 'micro' => 'cotacoes'];
$PAGE_VIEW  = 'compras_cotacoes';
$PAGE_TITLE = 'Cotações';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can(PERM . '.editar');
$menorTotal = null;
foreach ($cotacoes as $c) {
    if ($c['status'] === 'recusada') continue;
    if ($menorTotal === null || (float)$c['valor_total'] < $menorTotal) $menorTotal = (float)$c['valor_total'];
}
$badgeCot = static fn(string $s): string => match ($s) {
    'escolhida' => '<span class="vbadge vb-ok">Escolhida</span>',
    'recusada'  => '<span class="vbadge vb-off">Recusada</span>',
    default     => '<span class="vbadge vb-info">Aberta</span>',
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Cotações', 'Compare preços de fornecedores por solicitação antes de gerar o pedido',
        ($podeEditar && $solAtual && $itensSol) ? '+ Nova cotação' : null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="solicitacao" onchange="this.form.submit()">
          <option value="">Selecione a solicitação…</option>
          <?php foreach ($solicitacoes as $s): ?>
            <option value="<?= (int)$s['id'] ?>"<?= $fSol === (int)$s['id'] ? ' selected' : '' ?>>
              <?= h($s['numero'] . ' — ' . ucfirst((string)$s['status']) . ' (' . (int)$s['itens'] . ' item(ns), ' . (int)$s['cotacoes'] . ' cotação(ões))') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($solAtual): ?>
        <span class="vsub"><?= h(mb_substr((string)($solAtual['justificativa'] ?? ''), 0, 70)) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$solAtual): ?>
    <div class="vcard"><div class="vempty">Selecione uma solicitação para lançar fornecedores, preços, prazos e comparar cotações.</div></div>
  <?php elseif (!$cotacoes): ?>
    <div class="vcard"><div class="vempty">Nenhuma cotação para <?= h($solAtual['numero']) ?> ainda<?= $podeEditar && $itensSol ? ' — use "+ Nova cotação"' : '' ?>.</div></div>
  <?php else: ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Comparativo — <?= h($solAtual['numero']) ?></strong>
      <span class="vsub"><?= count($cotacoes) ?> cotação(ões)</span></div>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Fornecedor</th><th>Data</th>
        <?php foreach ($itensSol as $it): ?>
          <th class="num"><?= h($it['produto'] ?? $it['descricao'] ?? 'Item') ?>
            <span class="vhint">× <?= numFmt((float)$it['quantidade'], 0) ?></span></th>
        <?php endforeach; ?>
        <th class="num">Total (R$)</th>
        <th>Status</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($cotacoes as $c):
          $melhor = $menorTotal !== null && (float)$c['valor_total'] <= $menorTotal && $c['status'] !== 'recusada'; ?>
        <tr<?= $c['status'] === 'recusada' ? ' class="is-off"' : '' ?>>
          <td><strong><?= h($c['fornecedor'] ?? '—') ?></strong>
            <?= $melhor ? ' <span style="color:#1E6B34;font-size:11px;font-weight:600">· menor preço</span>' : '' ?></td>
          <td class="vnum" style="white-space:nowrap"><?= $c['data_cotacao'] ? date('d/m/Y', strtotime((string)$c['data_cotacao'])) : '—' ?></td>
          <?php foreach ($itensSol as $idx => $it):
              $ci = $c['itens'][$idx] ?? null; ?>
            <td class="num"><?= $ci ? numFmt((float)$ci['valor_unitario'], 2) : '—' ?></td>
          <?php endforeach; ?>
          <td class="num"><strong<?= $melhor ? ' style="color:#1E6B34"' : '' ?>><?= numFmt((float)$c['valor_total'], 2) ?></strong></td>
          <td><?= $badgeCot((string)$c['status']) ?></td>
          <td class="num"><div class="vactions" style="justify-content:flex-end">
            <?php if ($podeEditar && in_array($c['status'], ['aberta', 'recusada'], true)): ?>
              <?= vero_btn_icone_post(vero_ico_check(), 'Selecionar como vencedora', 'selecionar', (int)$c['id']) ?>
            <?php endif; ?>
            <?php if ($podeEditar && $c['status'] === 'escolhida' && vero_can('compras.pedidos_compra.editar')): ?>
              <?= vero_btn_icone(vero_ico_seta(), 'Gerar pedido', '', BIOS_BASE . '/compras/pedidos?novo=1&cotacao=' . (int)$c['id']) ?>
            <?php endif; ?>
            <?php if (vero_can(PERM . '.excluir') && $c['status'] !== 'recusada'): ?>
              <?= vero_btn_excluir((int)$c['id'], 'Recusar esta cotação?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      Preços por unidade do item. Selecione a vencedora e use <strong>Gerar pedido</strong> —
      fornecedor, itens e preços entram no pedido em 1 clique (cada cotação gera no máximo um pedido ativo).
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($podeEditar && $solAtual && $itensSol): ?>
<div class="vmodal" id="vm-form">
  <div class="vbox">
    <header>
      <h2>Nova cotação — <?= h($solAtual['numero']) ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="solicitacao_id" value="<?= (int)$solAtual['id'] ?>">
      <div class="vgrid">
        <?= vero_f_select('fornecedor_id', 'Fornecedor', $fornOpts, '', true, 'Selecione…') ?>
        <div class="vfield">
          <label>Data da cotação *</label>
          <input type="date" name="data_cotacao" value="<?= date('Y-m-d') ?>" required>
        </div>
      </div>
      <div class="vfield" style="margin-top:10px">
        <label>Preço unitário por item (R$)</label>
        <table class="vtable">
          <thead><tr><th>Item</th><th style="text-align:right">Qtd</th><th style="width:160px">Preço unit.</th></tr></thead>
          <tbody>
          <?php foreach ($itensSol as $it): ?>
            <tr>
              <td><?= h($it['produto'] ?? $it['descricao'] ?? 'Item') ?></td>
              <td class="vnum" style="text-align:right"><?= numFmt((float)$it['quantidade'], 0) ?>
                <span class="vhint"><?= h($it['unidade'] ?? '') ?></span></td>
              <td><input type="text" name="preco_<?= (int)$it['id'] ?>" placeholder="0,00" required
                         style="width:100%;text-align:right"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Registrar cotação</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
