<?php
declare(strict_types=1);
/* ============================================================
   VERO — Packing House / Unidade  (Onda 1 · tarefa 2)
   Rota: /packing/unidade.php · Guard: packing.unidade
   Edita os atributos INDUSTRIAIS da unidade de packing (Decisão 1: a unidade
   é um almoxarifado tipo='packing', migration 195 adicionou as colunas). Não
   cria almoxarifado (isso é em Estoque → Almoxarifados); só edita os campos
   de packing dos que já são tipo='packing'.
   ============================================================ */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'almoxarifados';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'salvar') {
        vero_require('packing.unidade.editar');
        $id = vero_int('id');
        $ok = $id ? vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t AND tipo='packing'",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if (!$ok) { vero_flash('erro', 'Unidade de packing inválida.'); vero_redirect(); }
        vero_update(T, [
            'ggn'              => vero_str('ggn', 13),
            'registro_mapa_uc' => vero_str('registro_mapa_uc', 40),
            'codigo_gacc'      => vero_str('codigo_gacc', 40),
            'gln'              => vero_str('gln', 13),
            'prefixo_gs1'      => vero_str('prefixo_gs1', 12),
        ], (int)$id);
        vero_flash('ok', 'Atributos da unidade atualizados.');
        vero_redirect();
    }
}

$rows = vero_rows("SELECT * FROM " . T . " WHERE tenant_id=:t AND tipo='packing' ORDER BY nome",
    [':t' => vero_tenant()]);
$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t AND tipo='packing'",
        [':i' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'packing', 'micro' => 'unidade'];
$PAGE_VIEW  = 'packing_unidade';
$PAGE_TITLE = 'Unidade de Packing';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Unidade de Packing', 'Dados industriais/de certificação da unidade (GGN, registro MAPA, GACC, GS1). A unidade em si é cadastrada em Estoque → Almoxarifados (tipo "packing").', null) ?>

  <div class="vcard">
  <?php if (!$rows): ?>
    <div class="vempty">Nenhum almoxarifado tipo "packing".
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h(BIOS_BASE) ?>/estoque/almoxarifados.php">Cadastrar</a></div>
  <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Unidade</th><th>GGN</th><th>Registro MAPA (UC)</th><th>GACC</th><th>Prefixo GS1</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= h((string)($r['ggn'] ?? '')) ?: '—' ?></td>
          <td><?= h((string)($r['registro_mapa_uc'] ?? '')) ?: '—' ?></td>
          <td><?= h((string)($r['codigo_gacc'] ?? '')) ?: '—' ?></td>
          <td><?= h((string)($r['prefixo_gs1'] ?? '')) ?: '—' ?></td>
          <td style="text-align:right"><a class="vbtn vbtn-ghost vbtn-sm" href="?editar=<?= (int)$r['id'] ?>">Editar</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>

  <?php if ($edit): ?>
  <div class="vmodal open" id="vm-form">
    <div class="vbox">
      <header>
        <h2>Editar — <?= h($edit['nome']) ?></h2>
        <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
      </header>
      <form class="vform" method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <div class="vgrid">
          <?= vero_f_text('ggn', 'GGN (GLOBALG.A.P. Number)', $edit['ggn'] ?? '', false, '13 dígitos') ?>
          <?= vero_f_text('registro_mapa_uc', 'Registro MAPA (Unidade de Consolidação)', $edit['registro_mapa_uc'] ?? '') ?>
          <?= vero_f_text('codigo_gacc', 'Código GACC (China)', $edit['codigo_gacc'] ?? '') ?>
          <?= vero_f_text('gln', 'GLN (GS1 Global Location Number)', $edit['gln'] ?? '') ?>
          <?= vero_f_text('prefixo_gs1', 'Prefixo de empresa GS1', $edit['prefixo_gs1'] ?? '') ?>
        </div>
        <div class="vform-actions"><button type="submit" class="vbtn vbtn-primary">Salvar</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php'; ?>
