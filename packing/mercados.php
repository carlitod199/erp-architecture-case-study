<?php
/* ============================================================
   VERO — Packing House / Mercados  (CRUD real)
   Rota: /packing/mercados.php
   Guard: packing.mercados  (view: packing_mercados)
   Tabela: ph_mercados (migration_197). Cada mercado carrega suas
   regras de aceitação em JSON (brix_min, peso_cacho_min_g, classes,
   tolerancias, janela_sazonal, docs_exigidos, mrl_ref, so2_permitido…).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'ph_mercados';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('packing.mercados.editar');
        $id     = vero_int('id');
        $codigo = vero_str('codigo', 40);
        $nome   = vero_str('nome', 120);
        if ($codigo === null) {
            vero_flash('erro', 'Código do mercado é obrigatório.');
            vero_redirect();
        }
        $codigo = mb_strtoupper($codigo);
        if ($nome === null) {
            vero_flash('erro', 'Nome do mercado é obrigatório.');
            vero_redirect();
        }

        // País ISO-3 (opcional): 3 letras, maiúsculas.
        $paisIso3 = vero_str('pais_iso3', 3);
        if ($paisIso3 !== null) {
            $paisIso3 = mb_strtoupper($paisIso3);
            if (!preg_match('/^[A-Z]{3}$/', $paisIso3)) {
                vero_flash('erro', 'País deve ser um código ISO-3 (3 letras). Ex.: BRA, USA, NLD.');
                vero_redirect();
            }
        }

        // Regras: textarea que grava JSON válido (objeto). Valida json_decode.
        $regrasRaw  = trim((string)($_POST['regras'] ?? ''));
        $regrasJson = null;
        if ($regrasRaw !== '') {
            $decoded = json_decode($regrasRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                vero_flash('erro', 'As regras precisam ser um JSON válido (objeto). Ex.: {"brix_min":16,"so2_permitido":true}');
                vero_redirect();
            }
            // Re-serializa canônico (remove espaços/comentários e normaliza).
            $regrasJson = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Unicidade do código dentro do tenant (a UNIQUE do banco é o guarda final).
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND codigo=:c AND id<>:id",
            [':t' => vero_tenant(), ':c' => $codigo, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe um mercado com o código \"{$codigo}\".");
            vero_redirect();
        }

        $data = [
            'codigo'    => $codigo,
            'nome'      => $nome,
            'pais_iso3' => $paisIso3,
            'regras'    => $regrasJson,
            'ativo'     => vero_int('ativo') ?? 1,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Mercado \"{$nome}\" atualizado."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Mercado \"{$nome}\" criado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('packing.mercados.excluir');
        $id = vero_int('id');
        if ($id) {
            // Escopo de tenant garantido por vero_delete (soft delete: tem `ativo`).
            $ok = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => vero_tenant()]);
            if ($ok) vero_delete(T, $id);
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$rows = vero_rows(
    "SELECT * FROM " . T . "
      WHERE tenant_id = :t
      ORDER BY ativo DESC, nome", [':t' => vero_tenant()]);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

// JSON das regras "bonito" para edição no textarea.
$regrasVal = '';
if ($edit && !empty($edit['regras'])) {
    $dec = json_decode((string)$edit['regras'], true);
    $regrasVal = is_array($dec)
        ? json_encode($dec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : (string)$edit['regras'];
}

$GUARD      = ['macro' => 'packing', 'micro' => 'mercados'];
$PAGE_VIEW  = 'packing_mercados';
$PAGE_TITLE = 'Mercados';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('packing.mercados.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <header class="vero-topbar">
    <h1 class="vero-topbar__title">Mercados</h1>
    <div class="vero-topbar__actions">
      <?php if ($podeEditar): ?><?= vero_btn_icone('<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>', 'Novo mercado', "vModalNovo('vm-form')") ?><?php endif; ?>
    </div>
  </header>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum mercado cadastrado — clique em "Novo mercado" para começar.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Código</th><th>Mercado</th><th>País</th>
        <th>Regras</th><th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['codigo']) ?></strong></td>
          <td><?= h($r['nome']) ?></td>
          <td class="vhint"><?= h($r['pais_iso3'] ?? '') ?: '—' ?></td>
          <td class="vhint"><?= !empty($r['regras']) ? 'JSON' : '—' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('packing.mercados.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este mercado?') ?>
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
      <h2><?= $edit ? 'Editar mercado' : 'Novo mercado' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('codigo', 'Código', $edit['codigo'] ?? '', true, 'Ex.: MI, UE, EUA (único no tenant)') ?>
        <?= vero_f_text('pais_iso3', 'País ISO-3 (opcional)', $edit['pais_iso3'] ?? '', false, 'Ex.: BRA, USA, NLD') ?>
        <div class="full"><?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', true, 'Ex.: Mercado Interno, União Europeia') ?></div>
        <div class="full">
          <div class="vfield">
            <label>Regras (JSON — opcional)</label>
            <textarea name="regras" rows="10" spellcheck="false" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.85rem"><?= h($regrasVal) ?></textarea>
            <div class="vhint">Objeto JSON com brix_min, peso_cacho_min_g, classes, tolerancias, janela_sazonal, docs_exigidos, mrl_ref, so2_permitido. Ex.: {"brix_min":16,"peso_cacho_min_g":300,"so2_permitido":true}</div>
          </div>
        </div>
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
