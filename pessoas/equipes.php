<?php
/* ============================================================
   VERO — Pessoas / Equipes  (CRUD real)
   Substitui o mock. Rota: /pessoas/equipes.php
   Guard: pessoas.equipes
   Equipes de campo (agro_equipes) com membros (agro_equipe_membros,
   sem colunas de auditoria — sync via PDO direto, mesmo padrão do
   N:N de tipos de atividade).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'agro_equipes';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('pessoas.equipes.editar');
        $id   = vero_int('id');
        $nome = vero_str('nome', 120);
        if ($nome === null) {
            vero_flash('erro', 'Nome da equipe é obrigatório.');
            vero_redirect();
        }
        $membros = array_values(array_unique(array_map('intval', (array)($_POST['membros'] ?? []))));

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $data = [
                'nome'       => $nome,
                'fazenda_id' => vero_fk_tenant('agro_fazendas', vero_int('fazenda_id')), // A-5
                'ativo'      => vero_int('ativo') ?? 1,
            ];
            if ($id) { vero_update(T, $id, $data); $msg = "Equipe \"{$nome}\" atualizada."; }
            else     { $id = vero_insert(T, $data); $msg = "Equipe \"{$nome}\" criada."; }

            /* sync de membros (tabela sem auditoria → PDO direto) */
            $pdo->prepare("DELETE FROM agro_equipe_membros WHERE tenant_id = ? AND equipe_id = ?")
                ->execute([vero_tenant(), (int)$id]);
            $ins = $pdo->prepare(
                "INSERT INTO agro_equipe_membros (tenant_id, equipe_id, operador_id, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())");
            foreach ($membros as $opId) {
                if ($opId > 0) $ins->execute([vero_tenant(), (int)$id, $opId]);
            }
            $pdo->commit();
            vero_flash('ok', $msg . ' ' . count($membros) . ' membro(s).');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Falha ao salvar: ' . $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('pessoas.equipes.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`); membros ficam para reativação
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT e.*, f.nome AS fazenda,
            (SELECT COUNT(*) FROM agro_equipe_membros m WHERE m.tenant_id = e.tenant_id AND m.equipe_id = e.id) AS membros
       FROM " . T . " e
       LEFT JOIN agro_fazendas f ON f.id = e.fazenda_id
      WHERE e.tenant_id = :t ORDER BY e.ativo DESC, e.nome", [':t' => vero_tenant()]);

$operadores = vero_rows(
    "SELECT id, nome, funcao FROM agro_operadores WHERE tenant_id = :t AND ativo = 1 ORDER BY nome",
    [':t' => vero_tenant()]);
$fazendas = vero_options('agro_fazendas', 'nome');

$edit = null;
$membrosAtuais = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        $membrosAtuais = array_map('intval', array_column(vero_rows(
            "SELECT operador_id FROM agro_equipe_membros WHERE tenant_id=:t AND equipe_id=:e",
            [':t' => vero_tenant(), ':e' => (int)$edit['id']]), 'operador_id'));
    }
}

$GUARD      = ['macro' => 'pessoas', 'micro' => 'equipes'];
$PAGE_VIEW  = 'pessoas_equipes';
$PAGE_TITLE = 'Equipes';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('pessoas.equipes.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Equipes', 'Equipes de campo — agrupam colaboradores para os apontamentos',
        $podeEditar ? '+ Nova equipe' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma equipe cadastrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Equipe</th><th>Fazenda</th>
        <th style="text-align:right">Membros</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= h($r['fazenda'] ?? 'Todas') ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['membros'] ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('pessoas.equipes.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar esta equipe?') ?>
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
      <h2><?= $edit ? 'Editar equipe' : 'Nova equipe' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome da equipe', $edit['nome'] ?? '', true) ?>
        <?= vero_f_select('fazenda_id', 'Fazenda', ['' => 'Todas'] + $fazendas, $edit['fazenda_id'] ?? '', false, '') ?>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vfield" style="margin-top:10px">
        <label>Membros</label>
        <?php if (!$operadores): ?>
          <div class="vhint">Nenhum colaborador ativo — cadastre em Pessoas → Colaboradores.</div>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;max-height:240px;overflow:auto;border:1px solid var(--vero-border,#e3e3e3);border-radius:8px;padding:10px">
          <?php foreach ($operadores as $op): ?>
            <label style="display:flex;gap:8px;align-items:center;font-weight:400">
              <input type="checkbox" name="membros[]" value="<?= (int)$op['id'] ?>"
                     <?= in_array((int)$op['id'], $membrosAtuais, true) ? 'checked' : '' ?>>
              <span><?= h($op['nome']) ?><?= $op['funcao'] ? ' <span class="vhint">(' . h($op['funcao']) . ')</span>' : '' ?></span>
            </label>
          <?php endforeach; ?>
        </div>
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
