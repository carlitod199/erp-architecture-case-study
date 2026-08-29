<?php
/* ============================================================
   VERO — Financeiro / Centros de Custo  (CRUD real)
   Substitui o mock. Rota: /financeiro/centros_custo.php
   Guard: financeiro.centros_custo
   Tabela: centros_custo — MDO/INS/MAQ/IRR são criados
   automaticamente pelos módulos; aqui gerencia-se o restante.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'centros_custo';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('financeiro.centros_custo.editar');
        $id     = vero_int('id');
        $codigo = vero_str('codigo', 20);
        $nome   = vero_str('nome', 120);
        if ($codigo === null || $nome === null) {
            vero_flash('erro', 'Código e nome são obrigatórios.');
            vero_redirect();
        }
        $codigo = strtoupper($codigo);
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND codigo=:c AND id<>:id",
            [':t' => vero_tenant(), ':c' => $codigo, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe o centro de custo \"{$codigo}\".");
            vero_redirect();
        }
        $data = [
            'codigo' => $codigo, 'nome' => $nome,
            'descricao' => vero_str('descricao', 255), 'ativo' => vero_int('ativo') ?? 1,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Centro \"{$codigo}\" atualizado."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Centro \"{$codigo}\" criado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('financeiro.centros_custo.excluir');
        $id = vero_int('id');
        if ($id) {
            $emUso = (int)vero_val("SELECT COUNT(*) FROM custeio_lancamentos WHERE tenant_id=:t AND centro_custo_id=:c",
                [':t' => vero_tenant(), ':c' => $id]);
            if ($emUso > 0) {
                vero_flash('erro', "Centro em uso por {$emUso} lançamento(s) de custeio — apenas inative-o.");
                vero_update(T, (int)$id, ['ativo' => 0]);
            } else {
                vero_delete(T, $id); // soft delete (tem `ativo`)
            }
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$rows = vero_rows(
    "SELECT c.*,
            (SELECT COUNT(*) FROM custeio_lancamentos cl
              WHERE cl.tenant_id = c.tenant_id AND cl.centro_custo_id = c.id) AS lancamentos,
            (SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl
              WHERE cl.tenant_id = c.tenant_id AND cl.centro_custo_id = c.id) AS total
       FROM " . T . " c
      WHERE c.tenant_id = :t ORDER BY c.ativo DESC, c.codigo", [':t' => vero_tenant()]);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'financeiro', 'micro' => 'centros_custo'];
$PAGE_VIEW  = 'financeiro_centros_custo';
$PAGE_TITLE = 'Centros de Custo';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('financeiro.centros_custo.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Centros de Custo', 'Destinos do custeio — MDO, INS, MAQ e IRR são criados automaticamente pelos módulos',
        $podeEditar ? '+ Novo centro' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum centro — os automáticos aparecem na primeira emissão de custo.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Código</th><th>Nome</th><th>Descrição</th>
        <th style="text-align:right">Lançamentos</th>
        <th style="text-align:right">Total (R$)</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong class="vnum"><?= h($r['codigo']) ?></strong></td>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td class="vhint"><?= h(mb_substr((string)($r['descricao'] ?? ''), 0, 60)) ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['lancamentos'] ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['total'], 2) ?></strong></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('financeiro.centros_custo.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este centro de custo?') ?>
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
      <h2><?= $edit ? 'Editar centro de custo' : 'Novo centro de custo' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vhint" style="margin-bottom:8px">Destino para onde o custeio é direcionado. Use um código curto e único (ele é gravado em maiúsculas). Centros em uso não podem ser excluídos, apenas inativados.</div>

      <div style="padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">Dados do centro de custo</strong>
        <div class="vgrid" style="margin-top:6px">
          <?= vero_f_text('codigo', 'Código', $edit['codigo'] ?? '', true, 'Curto, ex.: ADM, LOG') ?>
          <?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', true) ?>
          <div class="full"><?= vero_f_text('descricao', 'Descrição', $edit['descricao'] ?? '') ?></div>
          <?php if ($edit): ?>
            <?= vero_f_select('ativo', 'Status', [1 => 'Ativo', 0 => 'Inativo'], (int)$edit['ativo'], true, '') ?>
          <?php endif; ?>
        </div>
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
