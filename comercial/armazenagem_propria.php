<?php
/* ============================================================
   VERO — Comercial / Armazenagem Própria  (CRUD real)
   Substitui o mock. Rota: /comercial/armazenagem_propria.php
   Guard: comercial.armazenagem_propria
   Estoques de produção em armazéns próprios (armazenagem_estoques):
   cultura × safra × local, quantidade e unidade.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'armazenagem_estoques';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('comercial.armazenagem_propria.editar');
        $id    = vero_int('id');
        $local = vero_str('local', 120);
        $qtd   = vero_dec('quantidade');
        if ($local === null || $qtd === null || $qtd < 0) {
            vero_flash('erro', 'Local e quantidade são obrigatórios.');
            vero_redirect();
        }
        $data = [
            'cultura_id' => vero_int('cultura_id') ?: null,
            'safra_id'   => vero_int('safra_id') ?: null,
            'local'      => $local,
            'quantidade' => $qtd,
            'unidade'    => vero_str('unidade', 15) ?? 'kg',
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', 'Posição de armazenagem atualizada.'); }
        else     { vero_insert(T, $data);      vero_flash('ok', 'Posição de armazenagem registrada.'); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('comercial.armazenagem_propria.excluir');
        $id = vero_int('id');
        if ($id) {
            vero_pdo()->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")->execute([$t, (int)$id]);
            vero_flash('ok', 'Posição excluída.');
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT a.*, c.nome AS cultura, s.identificacao AS safra
       FROM " . T . " a
       LEFT JOIN agro_culturas c ON c.id = a.cultura_id
       LEFT JOIN agro_safras s ON s.id = a.safra_id
      WHERE a.tenant_id = :t ORDER BY a.local, c.nome", [':t' => $t]);
$totQtd = array_sum(array_map(static fn($r) => (float)$r['quantidade'], $rows));

$culturas = vero_options('agro_culturas', 'nome');
$safras   = vero_options('agro_safras', 'identificacao');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t", [':id' => (int)$_GET['editar'], ':t' => $t]);
}

$GUARD      = ['macro' => 'comercial', 'micro' => 'armazenagem_propria'];
$PAGE_VIEW  = 'comercial_armazenagem_propria';
$PAGE_TITLE = 'Armazenagem Própria';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('comercial.armazenagem_propria.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Armazenagem Própria', 'Posições de produção armazenada em estruturas próprias',
        $podeEditar ? '+ Nova posição' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <span class="vsub"><?= count($rows) ?> posição(ões)</span>
      <span class="vsub">total <strong class="vnum"><?= numFmt($totQtd, 0) ?></strong> (unidades conforme posição)</span>
    </div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma posição de armazenagem própria.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Local</th><th>Cultura</th><th>Safra</th>
        <th style="text-align:right">Quantidade</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['local']) ?></strong></td>
          <td><?= h($r['cultura'] ?? '—') ?></td>
          <td><?= h($r['safra'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['quantidade'], 0) ?></strong>
            <span class="vhint"><?= h($r['unidade'] ?? 'kg') ?></span></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('comercial.armazenagem_propria.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir esta posição?') ?>
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
      <h2><?= $edit ? 'Editar posição' : 'Nova posição de armazenagem' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('local', 'Local', $edit['local'] ?? '', true, 'Ex.: Câmara fria 1, Galpão sede') ?></div>
        <?= vero_f_select('cultura_id', 'Cultura', $culturas, $edit['cultura_id'] ?? '', false, 'Selecione…') ?>
        <?= vero_f_select('safra_id', 'Safra', $safras, $edit['safra_id'] ?? '', false, 'Selecione…') ?>
        <?= vero_f_text('quantidade', 'Quantidade', $edit ? numFmt((float)$edit['quantidade'], 0) : '', true) ?>
        <?= vero_f_text('unidade', 'Unidade', $edit['unidade'] ?? 'kg', false, 'kg, cx, pallet…') ?>
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
