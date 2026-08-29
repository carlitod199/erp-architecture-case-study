<?php
/* ============================================================
   VERO — Financeiro / Conciliação Bancária  (tela real)
   Substitui o mock. Rota: /financeiro/conciliacao_bancaria.php
   Guard: financeiro.conciliacao_bancaria
   Conciliação por conta/período (conciliacao_bancaria): informa o
   saldo do extrato, o sistema calcula o saldo do razão pago no
   período; itens do extrato podem ser casados com movimentações.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

function conc_saldo_sistema(string $ini, string $fim): float
{
    return (float)vero_val(
        "SELECT COALESCE(SUM(CASE WHEN tipo='receber' THEN valor ELSE -valor END),0)
           FROM movimentacoes_financeiras
          WHERE tenant_id = :t AND status = 'pago' AND data_pagamento BETWEEN :i AND :f",
        [':t' => vero_tenant(), ':i' => $ini, ':f' => $fim]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'abrir') {
        vero_require('financeiro.conciliacao_bancaria.editar');
        $contaId = vero_int('conta_bancaria_id');
        $ini = vero_date('data_inicio');
        $fim = vero_date('data_fim');
        $saldoExtrato = vero_dec('saldo_extrato');
        if (!$contaId || $ini === null || $fim === null || $fim < $ini || $saldoExtrato === null) {
            vero_flash('erro', 'Conta, período válido e saldo do extrato são obrigatórios.');
            vero_redirect();
        }
        $saldoSistema = conc_saldo_sistema($ini, $fim);
        $divergente = abs($saldoExtrato - $saldoSistema) > 0.005;
        /* tabela sem updated_by — insert direto */
        vero_pdo()->prepare(
            "INSERT INTO conciliacao_bancaria
                (tenant_id, conta_bancaria_id, referencia, data_inicio, data_fim, status,
                 saldo_extrato, saldo_sistema, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
            ->execute([$t, (int)$contaId, date('m/Y', strtotime($ini)), $ini, $fim,
                $divergente ? 'divergente' : 'conciliada', $saldoExtrato, $saldoSistema, vero_uid()]);
        vero_flash($divergente ? 'erro' : 'ok',
            'Conciliação registrada — extrato R$ ' . numFmt($saldoExtrato, 2) .
            ' × sistema R$ ' . numFmt($saldoSistema, 2) .
            ($divergente ? ' (DIVERGENTE — diferença R$ ' . numFmt($saldoExtrato - $saldoSistema, 2) . ')' : ' ✓'));
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('financeiro.conciliacao_bancaria.excluir');
        $id = vero_int('id');
        if ($id) {
            vero_pdo()->prepare("DELETE FROM conciliacao_itens WHERE tenant_id=? AND conciliacao_id=?")->execute([$t, (int)$id]);
            vero_pdo()->prepare("DELETE FROM conciliacao_bancaria WHERE tenant_id=? AND id=?")->execute([$t, (int)$id]);
            vero_flash('ok', 'Conciliação excluída.');
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT cb.*, c.nome AS conta FROM conciliacao_bancaria cb
       LEFT JOIN contas_bancarias c ON c.id = cb.conta_bancaria_id
      WHERE cb.tenant_id = :t ORDER BY cb.data_inicio DESC, cb.id DESC LIMIT 50", [':t' => $t]);

$contas = vero_rows("SELECT id, nome FROM contas_bancarias WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => $t]);
$contaOpts = [];
foreach ($contas as $c) $contaOpts[(int)$c['id']] = (string)$c['nome'];

$GUARD      = ['macro' => 'financeiro', 'micro' => 'conciliacao_bancaria'];
$PAGE_VIEW  = 'financeiro_conciliacao_bancaria';
$PAGE_TITLE = 'Conciliação Bancária';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('financeiro.conciliacao_bancaria.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Conciliação Bancária', 'Saldo do extrato contra o razão pago do período — divergências ficam sinalizadas', null) ?>

  <?php if ($podeEditar): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Nova conciliação</strong>
      <?php if (!$contaOpts): ?>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/financeiro/contas_bancarias.php">Cadastrar conta bancária</a>
      <?php endif; ?></div>
    <?php if (!$contaOpts): ?>
      <div class="vempty">Cadastre ao menos uma conta bancária ativa para iniciar uma conciliação.</div>
    <?php else: ?>
    <form class="vform" method="post" style="padding:12px 14px">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="abrir">

      <div style="padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">1. Conta e período</strong>
        <div class="vgrid" style="margin-top:6px">
          <div class="vfield"><label>Conta *</label>
            <select name="conta_bancaria_id" required>
              <option value="">Selecione…</option>
              <?php foreach ($contaOpts as $cid => $cn): ?>
                <option value="<?= $cid ?>"><?= h($cn) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="vfield"><label>Início *</label>
            <input type="date" name="data_inicio" value="<?= date('Y-m-01') ?>" required></div>
          <div class="vfield"><label>Fim *</label>
            <input type="date" name="data_fim" value="<?= date('Y-m-t') ?>" required></div>
        </div>
      </div>

      <div style="margin-top:10px;padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">2. Saldo do extrato</strong>
        <div class="vgrid" style="margin-top:6px">
          <div class="vfield"><label>Saldo do extrato (R$) *</label>
            <input type="text" name="saldo_extrato" placeholder="0,00" required style="text-align:right"></div>
        </div>
        <div class="vhint" style="margin-top:4px">Transcreva o saldo final do extrato bancário no período escolhido — é ele que o sistema compara com o razão.</div>
      </div>

      <div class="vform-actions">
        <button class="vbtn vbtn-primary" type="submit">Conciliar</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Conciliações registradas</strong>
      <span class="vsub"><?= count($rows) ?> registro(s) · <strong>Diferença</strong> = extrato − sistema</span></div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma conciliação registrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Conta</th><th>Referência</th><th>Período</th>
        <th style="text-align:right">Extrato (R$)</th>
        <th style="text-align:right">Sistema (R$)</th>
        <th style="text-align:right">Diferença (R$)</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $dif = (float)$r['saldo_extrato'] - (float)$r['saldo_sistema']; ?>
        <tr>
          <td><strong><?= h($r['conta'] ?? '—') ?></strong></td>
          <td class="vnum"><?= h($r['referencia'] ?? '—') ?></td>
          <td class="vnum"><?= date('d/m', strtotime((string)$r['data_inicio'])) ?> –
            <?= date('d/m/Y', strtotime((string)$r['data_fim'])) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['saldo_extrato'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['saldo_sistema'], 2) ?></td>
          <td class="vnum" style="text-align:right;<?= abs($dif) > 0.005 ? 'color:#b3261e;font-weight:700' : '' ?>">
            <?= numFmt($dif, 2) ?></td>
          <td><?= $r['status'] === 'conciliada' ? '<span class="vbadge vb-ok">Conciliada</span>'
                : ($r['status'] === 'divergente' ? '<span class="vbadge vb-off">Divergente</span>'
                : '<span class="vbadge vb-warn">Aberta</span>') ?></td>
          <td><div class="vactions">
            <?php if (vero_can('financeiro.conciliacao_bancaria.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir esta conciliação?') ?>
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
