<?php
/* ============================================================
   VERO — Agrícola / Áreas Produtivas  (CRUD real)
   Substitui o mock. Rota: /agro/areas_produtivas.php
   Guard: agricola.areas_produtivas
   Macro-áreas da fazenda (agro_areas) que agrupam talhões.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'agro_areas';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.areas_produtivas.editar');
        $id        = vero_int('id');
        $nome      = vero_str('nome', 120);
        $fazendaId = vero_int('fazenda_id');
        /* fazenda é obrigatória: a coluna é NOT NULL no schema (bugfix da
           auditoria A1-01 — a tela permitia NULL e estourava na FK) */
        if ($nome === null || !$fazendaId) {
            vero_flash('erro', 'Nome da área e fazenda são obrigatórios.');
            vero_redirect();
        }
        $okFaz = vero_val("SELECT id FROM agro_fazendas WHERE id=:f AND tenant_id=:t",
            [':f' => $fazendaId, ':t' => $t]);
        if (!$okFaz) {
            vero_flash('erro', 'Fazenda inválida.');
            vero_redirect();
        }
        $areaHa = vero_dec('area_ha');
        if ($areaHa !== null && $areaHa < 0) { /* A11: área nunca é negativa */
            vero_flash('erro', 'A área não pode ser negativa.');
            vero_redirect();
        }
        $data = [
            'nome'       => $nome,
            'fazenda_id' => $fazendaId,
            'area_ha'    => $areaHa,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Área \"{$nome}\" atualizada."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Área \"{$nome}\" criada."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('agro.areas_produtivas.excluir');
        $id = vero_int('id');
        if ($id) {
            $uso = (int)vero_val("SELECT COUNT(*) FROM agro_talhoes WHERE tenant_id=:t AND area_id=:a",
                [':t' => $t, ':a' => $id]);
            if ($uso > 0) {
                vero_flash('erro', "Área com {$uso} talhão(ões) vinculado(s) — mova-os antes de excluir.");
            } else {
                vero_pdo()->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")->execute([$t, (int)$id]);
                vero_flash('ok', 'Área excluída.');
            }
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT a.*, f.nome AS fazenda,
            (SELECT COUNT(*) FROM agro_talhoes tl
              WHERE tl.tenant_id = a.tenant_id AND tl.area_id = a.id AND tl.ativo = 1) AS talhoes,
            (SELECT COALESCE(SUM(tl2.area_ha),0) FROM agro_talhoes tl2
              WHERE tl2.tenant_id = a.tenant_id AND tl2.area_id = a.id AND tl2.ativo = 1) AS area_talhoes
       FROM " . T . " a
       LEFT JOIN agro_fazendas f ON f.id = a.fazenda_id
      WHERE a.tenant_id = :t ORDER BY f.nome, a.nome", [':t' => $t]);

$fazendas = vero_options('agro_fazendas', 'nome');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t", [':id' => (int)$_GET['editar'], ':t' => $t]);
}

$GUARD      = ['macro' => 'agricola', 'micro' => 'areas_produtivas'];
$PAGE_VIEW  = 'agricola_areas_produtivas';
$PAGE_TITLE = 'Áreas Produtivas';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.areas_produtivas.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Áreas Produtivas', 'Macro-áreas que agrupam talhões — os talhões são vinculados no cadastro de Talhões',
        $podeEditar ? '+ Nova área' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma área produtiva — talhões podem ser agrupados por área no cadastro.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Área</th><th>Fazenda</th>
        <th style="text-align:right">Área declarada (ha)</th>
        <th style="text-align:right">Talhões ativos</th>
        <th style="text-align:right">Área dos talhões (ha)</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= h($r['fazenda'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= $r['area_ha'] !== null ? numFmt((float)$r['area_ha'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['talhoes'] ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['area_talhoes'], 2) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('agro.areas_produtivas.excluir') && (int)$r['talhoes'] === 0): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir esta área?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px"><a href="<?= $base ?>/agro/talhoes.php">Cadastro de talhões</a> — o vínculo talhão→área é feito lá.</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar área' : 'Nova área produtiva' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome da área', $edit['nome'] ?? '', true, 'Ex.: Quadra Norte') ?>
        <?= vero_f_select('fazenda_id', 'Fazenda', $fazendas, $edit['fazenda_id'] ?? '', true, 'Selecione…') ?>
        <?= vero_f_text('area_ha', 'Área (ha)', $edit && $edit['area_ha'] !== null ? numFmt((float)$edit['area_ha'], 2) : '') ?>
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
