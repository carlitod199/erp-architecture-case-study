<?php
/* ============================================================
   VERO — Máquinas / Implementos  (CRUD real)
   Substitui o mock. Rota: /maquinas/implementos.php
   Guard: maquinas.implementos
   Implementos agrícolas (implementos): nome, tipo, fazenda.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'implementos';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('maquinas.implementos.editar');
        $id   = vero_int('id');
        $nome = vero_str('nome', 120);
        if ($nome === null) {
            vero_flash('erro', 'Nome do implemento é obrigatório.');
            vero_redirect();
        }
        /* A2-F2-7 (DB-10): máquina à qual o implemento está acoplado */
        $maquinaId = vero_int('maquina_id');
        if ($maquinaId) {
            $ok = vero_val("SELECT id FROM maquinas WHERE id=:i AND tenant_id=:t",
                [':i' => $maquinaId, ':t' => vero_tenant()]);
            if (!$ok) $maquinaId = null;
        }
        $data = [
            'nome'       => $nome,
            'tipo'       => vero_str('tipo', 60),
            'fazenda_id' => vero_int('fazenda_id') ?: null,
            'maquina_id' => $maquinaId,
            'ativo'      => vero_int('ativo') ?? 1,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Implemento \"{$nome}\" atualizado."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Implemento \"{$nome}\" cadastrado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('maquinas.implementos.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT i.*, f.nome AS fazenda, m.codigo AS maq_codigo, m.nome AS maq_nome
       FROM " . T . " i
      LEFT JOIN agro_fazendas f ON f.id = i.fazenda_id
      LEFT JOIN maquinas m ON m.id = i.maquina_id
     WHERE i.tenant_id = :t ORDER BY i.ativo DESC, i.nome", [':t' => vero_tenant()]);

$fazendas = vero_options('agro_fazendas', 'nome');
$maquinasOpt = vero_options('maquinas', 'nome', 'ativo = 1');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'maquinas', 'micro' => 'implementos'];
$PAGE_VIEW  = 'maquinas_implementos';
$PAGE_TITLE = 'Implementos';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('maquinas.implementos.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Implementos', 'Implementos agrícolas — pulverizadores, grades, carretas e afins',
        $podeEditar ? '+ Novo implemento' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum implemento cadastrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Implemento</th><th>Tipo</th><th>Fazenda</th><th>Máquina atual</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= (int)$r['ativo'] !== 1 ? ' style="opacity:.55"' : '' ?>>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= $r['tipo'] ? '<span class="vbadge vb-info">' . h((string)$r['tipo']) . '</span>' : '—' ?></td>
          <td><?= h($r['fazenda'] ?? '—') ?></td>
          <td><?= $r['maq_codigo'] ? '<strong class="vnum">' . h($r['maq_codigo']) . '</strong> ' . h($r['maq_nome']) : '<span class="vhint">solto</span>' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('maquinas.implementos.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este implemento?') ?>
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
      <h2><?= $edit ? 'Editar implemento' : 'Novo implemento' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', true, 'Ex.: Pulverizador 2000L') ?>
        <?= vero_f_text('tipo', 'Tipo', $edit['tipo'] ?? '', false, 'Ex.: pulverizador, grade, carreta') ?>
        <?= vero_f_select('fazenda_id', 'Fazenda', $fazendas, $edit['fazenda_id'] ?? '', false, 'Selecione…') ?>
        <?= vero_f_select('maquina_id', 'Máquina atual (acoplado a)', $maquinasOpt, $edit['maquina_id'] ?? null, false, '— Solto —') ?>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativo', 0 => 'Inativo'], (int)$edit['ativo'], true, '') ?>
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
