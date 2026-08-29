<?php
/* ============================================================
   VERO — Gestão Agrícola / Culturas  (CRUD real — bônus)
   Entregue junto com Safras porque o vínculo Safra × Válvula
   exige cultura_id (NOT NULL em agro_safra_talhoes).
   Substitui a tela mock. Rota da matriz: /agro/culturas.php
   Guard: agricola.culturas | Escrita: agro.culturas.editar/excluir
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'agro_culturas';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.culturas.editar');

        $id   = vero_int('id');
        $nome = vero_str('nome', 120);
        if ($nome === null) {
            vero_flash('erro', 'Informe o nome da cultura.');
            vero_redirect();
        }
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND nome=:n AND ativo=1 AND id<>:id",
            [':t' => vero_tenant(), ':n' => $nome, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe a cultura \"{$nome}\".");
            vero_redirect();
        }

        $unid = in_array($_POST['unidade_produtividade'] ?? '', ['sacas_ha','t_ha','arroba_ha','kg_ha','litros_ha'], true)
            ? $_POST['unidade_produtividade'] : 'kg_ha';

        /* A1-42 (colheita→estoque F1): produto/local gerados pela colheita —
           validados contra o tenant; vazio = colheita NÃO entra no estoque */
        $prodColheita = vero_int('produto_estoque_colheita_id') ?: null;
        if ($prodColheita && !vero_val("SELECT id FROM estoque_produtos WHERE id=:i AND tenant_id=:t",
                [':i' => $prodColheita, ':t' => vero_tenant()])) {
            $prodColheita = null;
        }
        $almoxColheita = vero_int('almoxarifado_colheita_id') ?: null;
        if ($almoxColheita && !vero_val("SELECT id FROM almoxarifados WHERE id=:i AND tenant_id=:t",
                [':i' => $almoxColheita, ':t' => vero_tenant()])) {
            $almoxColheita = null;
        }

        $data = [
            'nome'                  => $nome,
            'unidade_produtividade' => $unid,
            'produto_estoque_colheita_id' => $prodColheita,
            'almoxarifado_colheita_id'    => $almoxColheita,
            'exige_classificacao'         => vero_int('exige_classificacao') ? 1 : 0,
            'ativo'                 => vero_int('ativo') ?? 1,
        ];
        /* Campo legado `variedade` (varchar) não é usado: o cadastro
           detalhado fica no módulo Variedades (agro_variedades, mig. 120). */

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', "Cultura \"{$nome}\" atualizada.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Cultura \"{$nome}\" cadastrada.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('agro.culturas.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 15;

$where  = "c.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    $where .= " AND c.nome LIKE :q";
    $params[':q'] = "%{$q}%";
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " c WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT c.*,
            (SELECT COUNT(*) FROM agro_safra_talhoes v
              WHERE v.tenant_id = c.tenant_id AND v.cultura_id = c.id) AS usos
       FROM " . T . " c
      WHERE {$where}
      ORDER BY c.ativo DESC, c.nome
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
        [':i' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

/* A1-42: opções da entrada de colheita no estoque */
$produtosOpt = [];
foreach (vero_rows(
    "SELECT id, CONCAT(COALESCE(codigo,''), CASE WHEN codigo IS NULL THEN '' ELSE ' — ' END, nome) AS label
       FROM estoque_produtos WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => vero_tenant()]
) as $po) { $produtosOpt[(int)$po['id']] = (string)$po['label']; }
$almoxOpt = vero_options('almoxarifados', 'nome', 'ativo = 1');

$GUARD      = ['macro' => 'agricola', 'micro' => 'culturas'];
$PAGE_VIEW  = 'agricola_culturas';
$PAGE_TITLE = 'Culturas';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.culturas.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Culturas', 'Culturas trabalhadas — base para safras, atividades e variedades',
        $podeEditar ? '+ Nova cultura' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar cultura…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma cultura cadastrada. <?= $podeEditar ? 'Ex.: Uva, Manga, Goiaba…' : '' ?></div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Cultura</th><th>Unidade de produtividade</th>
        <th style="text-align:right">Usos em safras</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><span class="vbadge vb-info"><?= h(str_replace('_', '/', (string)$r['unidade_produtividade'])) ?></span></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['usos'] ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('agro.culturas.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar esta cultura?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar cultura' : 'Nova cultura' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome da cultura', $edit['nome'] ?? '', true, 'Ex.: Uva') ?>
        <?= vero_f_select('unidade_produtividade', 'Unidade de produtividade',
              ['kg_ha' => 'kg/ha', 't_ha' => 't/ha', 'sacas_ha' => 'sacas/ha', 'arroba_ha' => '@/ha', 'litros_ha' => 'L/ha'],
              $edit['unidade_produtividade'] ?? 'kg_ha', true, '') ?>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>

        <!-- A1-42: colheita → estoque (F1) — sem produto configurado, a colheita não entra no estoque -->
        <div class="full" style="border-top:1px solid var(--v-borda, #ddd);padding-top:8px;margin-top:4px">
          <strong>Entrada da colheita no estoque</strong>
          <span class="vhint">— produto acabado gerado por esta cultura; vazio = colheita fica só no histórico agrícola</span>
        </div>
        <?= vero_f_select('produto_estoque_colheita_id', 'Produto gerado pela colheita', $produtosOpt,
              $edit['produto_estoque_colheita_id'] ?? null, false, '— Não entra no estoque —') ?>
        <?= vero_f_select('almoxarifado_colheita_id', 'Local padrão de entrada (packing/câmara)', $almoxOpt,
              $edit['almoxarifado_colheita_id'] ?? null, false, '— Selecione o almoxarifado —') ?>
        <?= vero_f_select('exige_classificacao', 'Exigir CLASSIFICAÇÃO antes da entrada',
              [0 => 'Não — entra o kg total realizado', 1 => 'Sim — entra só o kg APROVADO da classificação'],
              (int)($edit['exige_classificacao'] ?? 0), true, '') ?>
      </div>
      <div class="vhint" style="margin-top:8px">
        As variedades (ex.: BRS Vitória, Arra 15) e porta-enxertos são cadastrados no módulo Variedades.
        Com produto configurado, a tela de Colheita ganha o botão "Confirmar entrada no estoque" (lote COLH- com custo provisório).
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
