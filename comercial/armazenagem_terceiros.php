<?php
/* ============================================================
   VERO — Comercial / Armazenagem de Terceiros  (CRUD real)
   Substitui o mock. Rota: /comercial/armazenagem_terceiros.php
   Guard: comercial.armazenagem_terceiros
   Contratos de armazenagem com terceiros (armazenagem_contratos):
   prestador (fornecedores), cultura, quantidade e vencimento.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'armazenagem_contratos';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('comercial.armazenagem_terceiros.editar');
        $id     = vero_int('id');
        $numero = vero_str('numero', 40);
        $fornId = vero_int('fornecedor_id');
        if ($numero === null || !$fornId) {
            vero_flash('erro', 'Número do contrato e prestador são obrigatórios.');
            vero_redirect();
        }
        $status = (string)($_POST['status'] ?? 'ativo');
        if (!in_array($status, ['ativo', 'encerrado', 'cancelado'], true)) $status = 'ativo';
        $qtd = vero_dec('quantidade');
        if ($qtd !== null && $qtd < 0) { /* A11: quantidade armazenada nunca é negativa */
            vero_flash('erro', 'A quantidade não pode ser negativa.');
            vero_redirect();
        }
        $data = [
            'numero'        => $numero,
            'fornecedor_id' => (int)$fornId,
            'cultura_id'    => vero_int('cultura_id') ?: null,
            'quantidade'    => $qtd,
            'vencimento'    => vero_date('vencimento'),
            'status'        => $status,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Contrato {$numero} atualizado."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Contrato {$numero} registrado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') { /* cancelamento lógico */
        vero_require('comercial.armazenagem_terceiros.excluir');
        $id = vero_int('id');
        if ($id) {
            vero_update(T, (int)$id, ['status' => 'cancelado']);
            vero_flash('ok', 'Contrato cancelado.');
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT a.*, f.nome AS prestador, c.nome AS cultura
       FROM " . T . " a
       LEFT JOIN fornecedores f ON f.id = a.fornecedor_id
       LEFT JOIN agro_culturas c ON c.id = a.cultura_id
      WHERE a.tenant_id = :t
      ORDER BY FIELD(a.status,'ativo','encerrado','cancelado'), a.vencimento", [':t' => $t]);

$fornecedores = vero_options('fornecedores', 'nome');
$culturas     = vero_options('agro_culturas', 'nome');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t", [':id' => (int)$_GET['editar'], ':t' => $t]);
}

$GUARD      = ['macro' => 'comercial', 'micro' => 'armazenagem_terceiros'];
$PAGE_VIEW  = 'comercial_armazenagem_terceiros';
$PAGE_TITLE = 'Armazenagem de Terceiros';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('comercial.armazenagem_terceiros.editar');
$hoje = date('Y-m-d');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Armazenagem de Terceiros', 'Contratos de armazenagem com prestadores externos',
        $podeEditar ? '+ Novo contrato' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum contrato de armazenagem com terceiros.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Contrato</th><th>Prestador</th><th>Cultura</th>
        <th style="text-align:right">Quantidade</th>
        <th>Vencimento</th><th>Status</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $vencendo = $r['status'] === 'ativo' && $r['vencimento'] !== null &&
                      $r['vencimento'] <= date('Y-m-d', strtotime('+30 days')); ?>
        <tr<?= $r['status'] === 'cancelado' ? ' style="opacity:.55"' : '' ?>>
          <td><strong class="vnum"><?= h($r['numero']) ?></strong></td>
          <td><?= h($r['prestador'] ?? '—') ?></td>
          <td><?= h($r['cultura'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= $r['quantidade'] !== null ? numFmt((float)$r['quantidade'], 0) : '—' ?></td>
          <td><?= $r['vencimento']
                ? '<span class="vbadge ' . ($vencendo ? ($r['vencimento'] < $hoje ? 'vb-off' : 'vb-warn') : 'vb-info') . '">'
                  . date('d/m/Y', strtotime((string)$r['vencimento'])) . '</span>' : '—' ?></td>
          <td><?= $r['status'] === 'ativo' ? '<span class="vbadge vb-ok">Ativo</span>'
                : ($r['status'] === 'encerrado' ? '<span class="vbadge vb-warn">Encerrado</span>'
                : '<span class="vbadge vb-off">Cancelado</span>') ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar && $r['status'] !== 'cancelado'): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('comercial.armazenagem_terceiros.excluir') && $r['status'] === 'ativo'): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Cancelar este contrato?') ?>
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
      <h2><?= $edit ? 'Editar contrato' : 'Novo contrato de armazenagem' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('numero', 'Nº do contrato', $edit['numero'] ?? '', true) ?>
        <?= vero_f_select('fornecedor_id', 'Prestador', $fornecedores, $edit['fornecedor_id'] ?? '', true, 'Selecione…') ?>
        <?= vero_f_select('cultura_id', 'Cultura', $culturas, $edit['cultura_id'] ?? '', false, 'Selecione…') ?>
        <?= vero_f_text('quantidade', 'Quantidade contratada', $edit && $edit['quantidade'] !== null ? numFmt((float)$edit['quantidade'], 0) : '') ?>
        <div class="vfield">
          <label>Vencimento</label>
          <input type="date" name="vencimento" value="<?= h($edit['vencimento'] ?? '') ?>">
        </div>
        <?php if ($edit): ?>
          <?= vero_f_select('status', 'Status', ['ativo' => 'Ativo', 'encerrado' => 'Encerrado'], $edit['status'] ?? 'ativo', true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
