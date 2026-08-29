<?php
/* ============================================================
   VERO — Financeiro / Contas Bancárias  (CRUD real)
   Substitui o mock. Rota: /financeiro/contas_bancarias.php
   Guard: financeiro.contas_bancarias
   Contas (contas_bancarias): banco, agência, conta, tipo e saldo
   inicial — base para a conciliação bancária.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'contas_bancarias';
const TIPOS_CONTA = ['corrente' => 'Corrente', 'poupanca' => 'Poupança', 'caixa' => 'Caixa', 'aplicacao' => 'Aplicação'];

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('financeiro.contas_bancarias.editar');
        $id   = vero_int('id');
        $nome = vero_str('nome', 120);
        if ($nome === null) {
            vero_flash('erro', 'Nome da conta é obrigatório.');
            vero_redirect();
        }
        $tipo = (string)($_POST['tipo'] ?? 'corrente');
        if (!isset(TIPOS_CONTA[$tipo])) $tipo = 'corrente';
        $data = [
            'nome'          => $nome,
            'banco'         => vero_str('banco', 80),
            'agencia'       => vero_str('agencia', 20),
            'conta'         => vero_str('conta', 30),
            'tipo'          => $tipo,
            'saldo_inicial' => vero_dec('saldo_inicial') ?? 0,
            'ativo'         => vero_int('ativo') ?? 1,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Conta \"{$nome}\" atualizada."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Conta \"{$nome}\" cadastrada."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('financeiro.contas_bancarias.excluir');
        $id = vero_int('id');
        if ($id) {
            $uso = (int)vero_val("SELECT COUNT(*) FROM conciliacao_bancaria WHERE tenant_id=:t AND conta_bancaria_id=:c",
                [':t' => $t, ':c' => $id]);
            if ($uso > 0) {
                vero_update(T, (int)$id, ['ativo' => 0]);
                vero_flash('erro', 'Conta com conciliações — inativada em vez de excluída.');
            } else {
                vero_delete(T, $id);
            }
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT c.*,
            (SELECT COUNT(*) FROM conciliacao_bancaria cb
              WHERE cb.tenant_id = c.tenant_id AND cb.conta_bancaria_id = c.id) AS conciliacoes
       FROM " . T . " c
      WHERE c.tenant_id = :t ORDER BY c.ativo DESC, c.nome", [':t' => $t]);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t", [':id' => (int)$_GET['editar'], ':t' => $t]);
}

$GUARD      = ['macro' => 'financeiro', 'micro' => 'contas_bancarias'];
$PAGE_VIEW  = 'financeiro_contas_bancarias';
$PAGE_TITLE = 'Contas Bancárias';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('financeiro.contas_bancarias.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Contas Bancárias', 'Contas e caixas da operação — base para a conciliação bancária',
        $podeEditar ? '+ Nova conta' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma conta bancária cadastrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Conta</th><th>Banco</th><th>Agência / Conta</th><th>Tipo</th>
        <th style="text-align:right">Saldo inicial (R$)</th>
        <th style="text-align:right">Conciliações</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= (int)$r['ativo'] !== 1 ? ' style="opacity:.55"' : '' ?>>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= h($r['banco'] ?? '—') ?></td>
          <td class="vnum"><?= h(trim(($r['agencia'] ?? '') . ' / ' . ($r['conta'] ?? ''), ' /') ?: '—') ?></td>
          <td><span class="vbadge vb-info"><?= h(TIPOS_CONTA[(string)$r['tipo']] ?? ucfirst((string)$r['tipo'])) ?></span></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['saldo_inicial'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['conciliacoes'] ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('financeiro.contas_bancarias.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir esta conta? (com conciliações será inativada)') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar conta' : 'Nova conta bancária' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vhint" style="margin-bottom:8px">Uma conta por banco/caixa da operação — serve de base para a conciliação bancária e para classificar os títulos (LCDPR).</div>

      <div style="padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">Identificação</strong>
        <div class="vgrid" style="margin-top:6px">
          <?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', true, 'Ex.: BB Movimento') ?>
          <?= vero_f_text('banco', 'Banco', $edit['banco'] ?? '', false, 'Ex.: Banco do Brasil') ?>
        </div>
      </div>

      <div style="margin-top:10px;padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">Dados bancários e saldo</strong>
        <div class="vgrid" style="margin-top:6px">
          <?= vero_f_text('agencia', 'Agência', $edit['agencia'] ?? '') ?>
          <?= vero_f_text('conta', 'Conta', $edit['conta'] ?? '') ?>
          <?= vero_f_select('tipo', 'Tipo', TIPOS_CONTA, $edit['tipo'] ?? 'corrente', true, '') ?>
          <?= vero_f_text('saldo_inicial', 'Saldo inicial (R$)', $edit ? numFmt((float)$edit['saldo_inicial'], 2) : '0,00') ?>
        </div>
      </div>

      <?php if ($edit): ?>
      <div class="vgrid" style="margin-top:10px">
        <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
      </div>
      <?php endif; ?>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
