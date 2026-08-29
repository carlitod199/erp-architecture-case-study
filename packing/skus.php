<?php
/* ============================================================
   VERO — Packing House / SKUs (produto acabado)  (CRUD real)
   Rota: /packing/skus.php   Guard: packing.skus
   Tabela: ph_skus (migration_200_ph_skus.php).
   O SKU é o "produto acabado" embalado: código comercial, cultura/variedade,
   calibre/categoria, embalagem, peso nominal + tolerâncias, paletização, GTIN,
   mercado e o vínculo opcional com o item de estoque (fonte fiscal única).
   NCM/CFOP/CEST NÃO ficam aqui — vêm de estoque_produtos.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'ph_skus';

/* Categórico = VARCHAR + whitelist em PHP (nunca ENUM). */
const CATEGORIAS = [
    'extra'     => 'Extra',
    'cat1'      => 'Categoria I',
    'cat2'      => 'Categoria II',
    'interno'   => 'Mercado interno',
    'industria' => 'Indústria',
];

/* Unidade de comercialização do produto acabado (P-07): a uva é vendida por
   PALETE (~110 caixas). VARCHAR + whitelist em PHP (nunca ENUM). */
const UNIDADES_COMERCIAL = [
    'caixa'   => 'Caixa',
    'palete'  => 'Palete',
    'cumbuca' => 'Cumbuca',
];
/* fator default caixa→palete (configurável no cadastro) */
const CAIXAS_POR_PALETE_PADRAO = 110;

/** Existe a tabela neste schema? (ph_embalagens/ph_mercados podem ainda não existir.) */
function ph_tabela_existe(string $tabela): bool
{
    return (bool)vero_val(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n LIMIT 1",
        [':n' => $tabela]
    );
}

$temEmbalagens = ph_tabela_existe('ph_embalagens') && vero_has_column('ph_embalagens', 'nome');
$temMercados   = ph_tabela_existe('ph_mercados')   && vero_has_column('ph_mercados', 'nome');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('packing.skus.editar');
        $id      = vero_int('id');
        $codigo  = vero_str('codigo', 40);
        $descricao = vero_str('descricao', 160);

        if ($codigo === null) {
            vero_flash('erro', 'O código do SKU é obrigatório.');
            vero_redirect();
        }
        if ($descricao === null) {
            vero_flash('erro', 'A descrição do SKU é obrigatória.');
            vero_redirect();
        }

        /* código único por tenant */
        $dup = vero_val(
            "SELECT id FROM " . T . " WHERE tenant_id = :t AND codigo = :c AND id <> :id",
            [':t' => vero_tenant(), ':c' => $codigo, ':id' => (int)$id]
        );
        if ($dup) {
            vero_flash('erro', "Já existe um SKU com o código \"{$codigo}\".");
            vero_redirect();
        }

        /* categoria: só valores da whitelist entram (senão NULL) */
        $categoria = vero_str('categoria', 20);
        if ($categoria !== null && !isset(CATEGORIAS[$categoria])) {
            $categoria = null;
        }

        /* unidade de comercialização (caixa/palete/cumbuca): whitelist ou NULL */
        $unidadeComercial = vero_str('unidade_comercial', 20);
        if ($unidadeComercial !== null && !isset(UNIDADES_COMERCIAL[$unidadeComercial])) {
            $unidadeComercial = null;
        }
        /* fator caixas→palete: >0 ou NULL (cai no default 110 na apuração) */
        $caixasPorPalete = vero_int('caixas_por_palete');
        if ($caixasPorPalete !== null && $caixasPorPalete <= 0) {
            $caixasPorPalete = null;
        }

        /* GTIN: só dígitos, até 14 (EAN-8/12/13/14) */
        $gtin = vero_str('gtin', 14);
        if ($gtin !== null) {
            $gtin = preg_replace('/\D+/', '', $gtin);
            if ($gtin === '') $gtin = null;
        }

        /* FKs — TODA referência validada contra o tenant atual */
        $unidadeId   = vero_fk_tenant('almoxarifados', vero_int('unidade_id'));
        $culturaId   = vero_fk_tenant('agro_culturas', vero_int('cultura_id'));
        $variedadeId = vero_fk_tenant('agro_variedades', vero_int('variedade_id'));
        $produtoId   = vero_fk_tenant('estoque_produtos', vero_int('produto_estoque_id'));

        /* variedade precisa pertencer à cultura escolhida (quando ambas vierem) */
        if ($variedadeId !== null && $culturaId !== null) {
            $ok = vero_val(
                "SELECT id FROM agro_variedades WHERE id = :v AND cultura_id = :c AND tenant_id = :t",
                [':v' => $variedadeId, ':c' => $culturaId, ':t' => vero_tenant()]
            );
            if (!$ok) $variedadeId = null;
        }

        /* embalagem/mercado: só validam contra o tenant se a tabela já existir */
        $embalagemId = null;
        if ($temEmbalagens) {
            $embalagemId = vero_fk_tenant('ph_embalagens', vero_int('embalagem_id'));
        }
        $mercadoId = null;
        if ($temMercados) {
            $mercadoId = vero_fk_tenant('ph_mercados', vero_int('mercado_id'));
        }

        $data = [
            'unidade_id'         => $unidadeId,
            'codigo'             => $codigo,
            'descricao'          => $descricao,
            'cultura_id'         => $culturaId,
            'variedade_id'       => $variedadeId,
            'marca_comercial'    => vero_str('marca_comercial', 80),
            'calibre'            => vero_str('calibre', 40),
            'categoria'          => $categoria,
            'embalagem_id'       => $embalagemId,
            'unidade_comercial'  => $unidadeComercial,
            'caixas_por_palete'  => $caixasPorPalete,
            'peso_nominal_kg'    => vero_dec('peso_nominal_kg'),
            'tolerancia_min_kg'  => vero_dec('tolerancia_min_kg'),
            'tolerancia_max_kg'  => vero_dec('tolerancia_max_kg'),
            'unidades_por_caixa' => vero_int('unidades_por_caixa'),
            'caixas_por_camada'  => vero_int('caixas_por_camada'),
            'camadas_por_pallet' => vero_int('camadas_por_pallet'),
            'gtin'               => $gtin,
            'mercado_id'         => $mercadoId,
            'produto_estoque_id' => $produtoId,
            'ativo'              => vero_int('ativo') ?? 1,
        ];

        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "SKU \"{$codigo}\" atualizado."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "SKU \"{$codigo}\" criado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('packing.skus.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$rows = vero_rows(
    "SELECT s.*, c.nome AS cultura, v.nome AS variedade,
            a.nome AS unidade, p.codigo AS prod_codigo, p.nome AS prod_nome
       FROM " . T . " s
       LEFT JOIN agro_culturas   c ON c.id = s.cultura_id
       LEFT JOIN agro_variedades v ON v.id = s.variedade_id
       LEFT JOIN almoxarifados   a ON a.id = s.unidade_id
       LEFT JOIN estoque_produtos p ON p.id = s.produto_estoque_id
      WHERE s.tenant_id = :t
      ORDER BY s.ativo DESC, s.codigo", [':t' => vero_tenant()]);

/* Opções dos selects (sempre escopadas no tenant) */
$unidades  = vero_options('almoxarifados', 'nome', "tipo = 'packing' AND ativo = 1");
$culturas  = vero_options('agro_culturas', 'nome', 'ativo = 1');
$variedades = vero_options('agro_variedades', 'nome');
$embalagens = $temEmbalagens ? vero_options('ph_embalagens', 'nome') : [];
$mercados   = $temMercados   ? vero_options('ph_mercados', 'nome')   : [];

$produtos = [];
foreach (vero_rows(
    "SELECT id, codigo, nome FROM estoque_produtos
      WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => vero_tenant()]) as $p) {
    $lbl = trim(($p['codigo'] ? $p['codigo'] . ' — ' : '') . (string)$p['nome']);
    $produtos[(int)$p['id']] = $lbl;
}

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id = :id AND tenant_id = :t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'packing', 'micro' => 'skus'];
$PAGE_VIEW  = 'packing_skus';
$PAGE_TITLE = 'SKUs (produto acabado)';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('packing.skus.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <header class="vero-topbar">
    <h1 class="vero-topbar__title">SKUs (produto acabado)</h1>
    <div class="vero-topbar__actions">
      <?php if ($podeEditar): ?><?= vero_btn_icone('<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>', 'Novo SKU', "vModalNovo('vm-form')") ?><?php endif; ?>
    </div>
  </header>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum SKU cadastrado — clique em "Novo SKU" para começar.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Código</th><th>Descrição</th><th>Cultura / Variedade</th>
        <th>Calibre</th><th>Categoria</th>
        <th style="text-align:right">Peso nom. (kg)</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['codigo']) ?></strong>
            <?php if (!empty($r['gtin'])): ?><div class="vhint">GTIN <?= h($r['gtin']) ?></div><?php endif; ?>
          </td>
          <td><?= h($r['descricao']) ?>
            <?php if (!empty($r['marca_comercial'])): ?><div class="vhint"><?= h($r['marca_comercial']) ?></div><?php endif; ?>
          </td>
          <td>
            <?= h($r['cultura'] ?? '') ?: '—' ?>
            <?php if (!empty($r['variedade'])): ?><div class="vhint"><?= h($r['variedade']) ?></div><?php endif; ?>
          </td>
          <td class="vhint"><?= h($r['calibre'] ?? '') ?: '—' ?></td>
          <td><?= isset($r['categoria'], CATEGORIAS[$r['categoria']]) ? h(CATEGORIAS[$r['categoria']]) : '—' ?>
            <?php if (!empty($r['unidade_comercial']) && isset(UNIDADES_COMERCIAL[$r['unidade_comercial']])): ?>
              <div class="vhint"><?= h(UNIDADES_COMERCIAL[$r['unidade_comercial']]) ?><?php
                if ($r['unidade_comercial'] === 'palete' && !empty($r['caixas_por_palete'])):
                ?> · <?= (int)$r['caixas_por_palete'] ?> cx<?php endif; ?></div>
            <?php endif; ?>
          </td>
          <td class="vnum" style="text-align:right"><?= $r['peso_nominal_kg'] !== null ? numFmt((float)$r['peso_nominal_kg'], 3) : '—' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('packing.skus.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este SKU?', 'Inativar') ?>
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
      <h2><?= $edit ? 'Editar SKU' : 'Novo SKU' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('codigo', 'Código', $edit['codigo'] ?? '', true, 'Ex.: UVA-THOMP-EXT-8.2') ?>
        <?= vero_f_select('unidade_id', 'Unidade (packing)', $unidades, $edit['unidade_id'] ?? null, false, '— Não vinculada —') ?>
        <div class="full"><?= vero_f_text('descricao', 'Descrição', $edit['descricao'] ?? '', true, 'Ex.: Uva Thompson sem semente, caixa 8,2 kg') ?></div>

        <?= vero_f_select('cultura_id', 'Cultura', $culturas, $edit['cultura_id'] ?? null, false, '— Selecione —') ?>
        <?= vero_f_select('variedade_id', 'Variedade', $variedades, $edit['variedade_id'] ?? null, false, '— Selecione —') ?>

        <?= vero_f_text('marca_comercial', 'Marca comercial', $edit['marca_comercial'] ?? '', false) ?>
        <?= vero_f_text('calibre', 'Calibre', $edit['calibre'] ?? '', false, 'Ex.: 8.2, XL, 18mm+') ?>

        <?= vero_f_select('categoria', 'Categoria', CATEGORIAS, $edit['categoria'] ?? null, false, '— Selecione —') ?>
        <?php if ($temEmbalagens): ?>
          <?= vero_f_select('embalagem_id', 'Embalagem', $embalagens, $edit['embalagem_id'] ?? null, false, '— Selecione —') ?>
        <?php endif; ?>

        <?= vero_f_select('unidade_comercial', 'Unidade de comercialização', UNIDADES_COMERCIAL, $edit['unidade_comercial'] ?? null, false, '— Selecione —') ?>
        <?= vero_f_text('caixas_por_palete', 'Caixas por palete', isset($edit['caixas_por_palete']) && $edit['caixas_por_palete'] !== null ? (string)(int)$edit['caixas_por_palete'] : (string)CAIXAS_POR_PALETE_PADRAO, false, 'Ex.: 110 (conversão caixa → palete)', 'number') ?>

        <?= vero_f_text('peso_nominal_kg', 'Peso nominal (kg)', isset($edit['peso_nominal_kg']) && $edit['peso_nominal_kg'] !== null ? (string)$edit['peso_nominal_kg'] : '', false, 'Ex.: 8,200') ?>
        <?= vero_f_text('tolerancia_min_kg', 'Tolerância mín. (kg)', isset($edit['tolerancia_min_kg']) && $edit['tolerancia_min_kg'] !== null ? (string)$edit['tolerancia_min_kg'] : '', false) ?>
        <?= vero_f_text('tolerancia_max_kg', 'Tolerância máx. (kg)', isset($edit['tolerancia_max_kg']) && $edit['tolerancia_max_kg'] !== null ? (string)$edit['tolerancia_max_kg'] : '', false) ?>

        <?= vero_f_text('unidades_por_caixa', 'Unidades por caixa', isset($edit['unidades_por_caixa']) && $edit['unidades_por_caixa'] !== null ? (string)$edit['unidades_por_caixa'] : '', false, '', 'number') ?>
        <?= vero_f_text('caixas_por_camada', 'Caixas por camada', isset($edit['caixas_por_camada']) && $edit['caixas_por_camada'] !== null ? (string)$edit['caixas_por_camada'] : '', false, '', 'number') ?>
        <?= vero_f_text('camadas_por_pallet', 'Camadas por pallet', isset($edit['camadas_por_pallet']) && $edit['camadas_por_pallet'] !== null ? (string)$edit['camadas_por_pallet'] : '', false, '', 'number') ?>

        <?= vero_f_text('gtin', 'GTIN / EAN', $edit['gtin'] ?? '', false, 'Só números (EAN-8/13/14)') ?>
        <?php if ($temMercados): ?>
          <?= vero_f_select('mercado_id', 'Mercado', $mercados, $edit['mercado_id'] ?? null, false, '— Selecione —') ?>
        <?php endif; ?>

        <div class="full"><?= vero_f_select('produto_estoque_id', 'Item de estoque (fonte fiscal: NCM/CFOP/CEST)', $produtos, $edit['produto_estoque_id'] ?? null, false, '— Não vinculado —') ?></div>

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
