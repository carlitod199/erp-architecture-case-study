<?php
/* ============================================================
   VERO — Packing House / Embalagens  (CRUD real)
   Rota: /packing/embalagens.php · Guard: packing.embalagens
   Tabela: ph_embalagens (migration 196)
   Cadastro das embalagens do Packing House (caixa, cumbuca, punnet,
   saco, liner, pad SO2, absorvedor, cantoneira, pallet) com tara,
   dimensões, geração de SO2, ISPM-15 e elegibilidade a drawback.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'ph_embalagens';

/* Categóricos = VARCHAR + whitelist em PHP (convenção do projeto). */
const PH_EMB_TIPOS = [
    'caixa'      => 'Caixa',
    'cumbuca'    => 'Cumbuca',
    'punnet'     => 'Punnet',
    'saco'       => 'Saco',
    'liner'      => 'Liner',
    'pad_so2'    => 'Pad SO₂',
    'absorvedor' => 'Absorvedor',
    'cantoneira' => 'Cantoneira',
    'pallet'     => 'Pallet',
];
const PH_EMB_SO2_FASES = [
    'unica'      => 'Única',
    'dupla'      => 'Dupla',
    'ultra_fast' => 'Ultra fast',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('packing.embalagens.editar');
        $id     = vero_int('id');
        $codigo = vero_str('codigo', 40);
        $nome   = vero_str('nome', 120);
        if ($codigo === null) {
            vero_flash('erro', 'Código da embalagem é obrigatório.');
            vero_redirect();
        }
        if ($nome === null) {
            vero_flash('erro', 'Nome da embalagem é obrigatório.');
            vero_redirect();
        }

        // tipo: obrigatório e restrito à whitelist
        $tipo = vero_str('tipo', 30);
        if ($tipo === null || !isset(PH_EMB_TIPOS[$tipo])) {
            vero_flash('erro', 'Selecione um tipo de embalagem válido.');
            vero_redirect();
        }

        // so2_fase: opcional, mas se vier tem de estar na whitelist
        $so2Fase = vero_str('so2_fase', 20);
        if ($so2Fase !== null && !isset(PH_EMB_SO2_FASES[$so2Fase])) {
            $so2Fase = null;
        }

        // código único por tenant
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND codigo=:c AND id<>:id",
            [':t' => vero_tenant(), ':c' => $codigo, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe uma embalagem com o código \"{$codigo}\".");
            vero_redirect();
        }

        $data = [
            'produto_estoque_id'    => vero_fk_tenant('estoque_produtos', vero_int('produto_estoque_id')),
            'codigo'                => $codigo,
            'nome'                  => $nome,
            'tipo'                  => $tipo,
            'tara_g'                => vero_dec('tara_g'),
            'comprimento_mm'        => vero_int('comprimento_mm'),
            'largura_mm'            => vero_int('largura_mm'),
            'altura_mm'             => vero_int('altura_mm'),
            'so2_fase'              => $so2Fase,
            'so2_dose_ppm'          => vero_dec('so2_dose_ppm'),
            'so2_duracao_h'         => vero_int('so2_duracao_h'),
            'ispm15_credenciamento' => vero_str('ispm15_credenciamento', 40),
            'ispm15_tratamento'     => vero_str('ispm15_tratamento', 10),
            'elegivel_drawback'     => vero_int('elegivel_drawback') === 1 ? 1 : 0,
            'ativo'                 => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            // garante que a linha editada é do tenant antes de gravar
            $alvo = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t2",
                [':i' => $id, ':t2' => vero_tenant()]);
            if (!$alvo) {
                vero_flash('erro', 'Embalagem não encontrada.');
                vero_redirect();
            }
            vero_update(T, $id, $data);
            vero_flash('ok', "Embalagem \"{$nome}\" atualizada.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Embalagem \"{$nome}\" criada.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('packing.embalagens.excluir');
        $id = vero_int('id');
        if ($id) {
            $alvo = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => vero_tenant()]);
            if ($alvo) vero_delete(T, $id); // soft delete (tem `ativo`)
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$rows = vero_rows(
    "SELECT e.*, p.codigo AS prod_codigo, p.nome AS prod_nome
       FROM " . T . " e
       LEFT JOIN estoque_produtos p ON p.id = e.produto_estoque_id AND p.tenant_id = e.tenant_id
      WHERE e.tenant_id = :t
      ORDER BY e.ativo DESC, e.nome", [':t' => vero_tenant()]);

/* Produtos de estoque para vínculo (código — nome). */
$produtos = [];
foreach (vero_rows(
    "SELECT id, codigo, nome FROM estoque_produtos
      WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => vero_tenant()]) as $p) {
    $produtos[(int)$p['id']] = trim(($p['codigo'] ? $p['codigo'] . ' — ' : '') . $p['nome']);
}

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'packing', 'micro' => 'embalagens'];
$PAGE_VIEW  = 'packing_embalagens';
$PAGE_TITLE = 'Embalagens';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('packing.embalagens.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <header class="vero-topbar">
    <h1 class="vero-topbar__title">Embalagens</h1>
    <div class="vero-topbar__actions">
      <?php if ($podeEditar): ?><?= vero_btn_icone('<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>', 'Nova embalagem', "vModalNovo('vm-form')") ?><?php endif; ?>
    </div>
  </header>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma embalagem cadastrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Código</th><th>Embalagem</th><th>Tipo</th><th>Produto (estoque)</th>
        <th style="text-align:right">Tara (g)</th>
        <th>Dimensões (mm)</th><th>SO₂</th><th>Drawback</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="vhint"><?= h($r['codigo']) ?></td>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= h(PH_EMB_TIPOS[$r['tipo']] ?? $r['tipo']) ?></td>
          <td><?= $r['prod_nome'] ? h(trim(($r['prod_codigo'] ? $r['prod_codigo'] . ' — ' : '') . $r['prod_nome'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $r['tara_g'] !== null ? numFmt((float)$r['tara_g'], 3) : '—' ?></td>
          <td class="vhint">
            <?php
              $dim = array_filter([$r['comprimento_mm'], $r['largura_mm'], $r['altura_mm']], fn($v) => $v !== null);
              echo $dim
                ? h(implode(' × ', array_map(fn($v) => (string)(int)$v, [$r['comprimento_mm'] ?? 0, $r['largura_mm'] ?? 0, $r['altura_mm'] ?? 0])))
                : '—';
            ?>
          </td>
          <td class="vhint"><?= $r['so2_fase'] ? h(PH_EMB_SO2_FASES[$r['so2_fase']] ?? $r['so2_fase']) : '—' ?></td>
          <td><?= (int)$r['elegivel_drawback'] === 1 ? '<span class="vbadge vb-ok">Sim</span>' : '<span class="vbadge vb-off">Não</span>' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('packing.embalagens.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar esta embalagem?', 'Inativar') ?>
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
      <h2><?= $edit ? 'Editar embalagem' : 'Nova embalagem' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('codigo', 'Código', $edit['codigo'] ?? '', true, 'Ex.: CX-4KG, PUN-500') ?>
        <div class="full"><?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', true, 'Ex.: Caixa papelão 4 kg exportação') ?></div>
        <?= vero_f_select('tipo', 'Tipo', PH_EMB_TIPOS, $edit['tipo'] ?? null, true, '— Selecione —') ?>
        <?= vero_f_select('produto_estoque_id', 'Produto no estoque', $produtos, $edit['produto_estoque_id'] ?? null, false, '— Não vinculado —') ?>

        <?= vero_f_text('tara_g', 'Tara (g)', isset($edit['tara_g']) && $edit['tara_g'] !== null ? (string)$edit['tara_g'] : '', false, 'Peso vazio da embalagem') ?>
        <?= vero_f_select('elegivel_drawback', 'Elegível a drawback', [1 => 'Sim', 0 => 'Não'], (int)($edit['elegivel_drawback'] ?? 0), false, '') ?>

        <?= vero_f_text('comprimento_mm', 'Comprimento (mm)', isset($edit['comprimento_mm']) && $edit['comprimento_mm'] !== null ? (string)(int)$edit['comprimento_mm'] : '', false, '') ?>
        <?= vero_f_text('largura_mm', 'Largura (mm)', isset($edit['largura_mm']) && $edit['largura_mm'] !== null ? (string)(int)$edit['largura_mm'] : '', false, '') ?>
        <?= vero_f_text('altura_mm', 'Altura (mm)', isset($edit['altura_mm']) && $edit['altura_mm'] !== null ? (string)(int)$edit['altura_mm'] : '', false, '') ?>

        <?= vero_f_select('so2_fase', 'Fase SO₂', PH_EMB_SO2_FASES, $edit['so2_fase'] ?? null, false, '— Sem SO₂ —') ?>
        <?= vero_f_text('so2_dose_ppm', 'Dose SO₂ (ppm)', isset($edit['so2_dose_ppm']) && $edit['so2_dose_ppm'] !== null ? (string)$edit['so2_dose_ppm'] : '', false, '') ?>
        <?= vero_f_text('so2_duracao_h', 'Duração SO₂ (h)', isset($edit['so2_duracao_h']) && $edit['so2_duracao_h'] !== null ? (string)(int)$edit['so2_duracao_h'] : '', false, '') ?>

        <?= vero_f_text('ispm15_credenciamento', 'ISPM-15 credenciamento', $edit['ispm15_credenciamento'] ?? '', false, 'Nº do credenciamento (madeira)') ?>
        <?= vero_f_text('ispm15_tratamento', 'ISPM-15 tratamento', $edit['ispm15_tratamento'] ?? '', false, 'Ex.: HT, MB') ?>

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
