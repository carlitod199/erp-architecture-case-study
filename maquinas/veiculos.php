<?php
/* ============================================================
   VERO — Máquinas / Veículos  (CRUD real)
   Substitui o mock. Rota: /maquinas/veiculos.php
   Guard: maquinas.veiculos
   Frota leve (veiculos): placa, modelo, ano, odômetro. Os
   abastecimentos podem referenciar o veículo (veiculo_id).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'veiculos';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('maquinas.veiculos.editar');
        $id     = vero_int('id');
        $modelo = vero_str('modelo', 120);
        if ($modelo === null) {
            vero_flash('erro', 'Modelo é obrigatório.');
            vero_redirect();
        }
        $placa = vero_str('placa', 10);
        if ($placa !== null) {
            $placa = strtoupper($placa);
            $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND placa=:p AND id<>:id",
                [':t' => vero_tenant(), ':p' => $placa, ':id' => (int)$id]);
            if ($dup) {
                vero_flash('erro', "Já existe veículo com a placa {$placa}.");
                vero_redirect();
            }
        }
        /* A11: leitura de odômetro nunca é negativa */
        $odometro = vero_dec('odometro_atual');
        if ($odometro !== null && $odometro < 0) {
            vero_flash('erro', 'O odômetro não pode ser negativo.');
            vero_redirect();
        }
        $data = [
            'fazenda_id'     => vero_int('fazenda_id') ?: null,
            'placa'          => $placa,
            'modelo'         => $modelo,
            'ano'            => vero_int('ano') ?: null,
            'odometro_atual' => $odometro,
            'ativo'          => vero_int('ativo') ?? 1,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Veículo \"{$modelo}\" atualizado."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Veículo \"{$modelo}\" cadastrado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('maquinas.veiculos.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT v.*, f.nome AS fazenda,
            (SELECT COUNT(*) FROM maquina_abastecimentos ab
              WHERE ab.tenant_id = v.tenant_id AND ab.veiculo_id = v.id) AS abastecimentos
       FROM " . T . " v
       LEFT JOIN agro_fazendas f ON f.id = v.fazenda_id
      WHERE v.tenant_id = :t ORDER BY v.ativo DESC, v.modelo", [':t' => vero_tenant()]);

$fazendas = vero_options('agro_fazendas', 'nome');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'maquinas', 'micro' => 'veiculos'];
$PAGE_VIEW  = 'maquinas_veiculos';
$PAGE_TITLE = 'Veículos';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('maquinas.veiculos.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Veículos', 'Frota leve — abastecimentos podem referenciar o veículo para custo e odômetro',
        $podeEditar ? '+ Novo veículo' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum veículo cadastrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Modelo</th><th>Placa</th><th>Ano</th><th>Fazenda</th>
        <th style="text-align:right">Odômetro (km)</th>
        <th style="text-align:right">Abastecimentos</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= (int)$r['ativo'] !== 1 ? ' style="opacity:.55"' : '' ?>>
          <td><strong><?= h($r['modelo']) ?></strong></td>
          <td class="vnum"><?= h($r['placa'] ?? '—') ?></td>
          <td class="vnum"><?= $r['ano'] !== null ? (int)$r['ano'] : '—' ?></td>
          <td><?= h($r['fazenda'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= $r['odometro_atual'] !== null ? numFmt((float)$r['odometro_atual'], 0) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['abastecimentos'] ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('maquinas.veiculos.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este veículo?') ?>
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
      <h2><?= $edit ? 'Editar veículo' : 'Novo veículo' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('modelo', 'Modelo', $edit['modelo'] ?? '', true, 'Ex.: Toyota Hilux 2.8') ?>
        <?= vero_f_text('placa', 'Placa', $edit['placa'] ?? '', false, 'ABC1D23') ?>
        <?= vero_f_text('ano', 'Ano', (string)($edit['ano'] ?? '')) ?>
        <?= vero_f_select('fazenda_id', 'Fazenda', $fazendas, $edit['fazenda_id'] ?? '', false, 'Selecione…') ?>
        <?= vero_f_text('odometro_atual', 'Odômetro atual (km)', $edit && $edit['odometro_atual'] !== null ? numFmt((float)$edit['odometro_atual'], 0) : '') ?>
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
