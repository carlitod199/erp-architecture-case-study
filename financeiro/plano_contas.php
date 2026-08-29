<?php
/* ============================================================
   VERO — Financeiro / Plano de Contas  (CRUD real)
   Substitui o mock. Rota: /financeiro/plano_contas.php
   Guard: financeiro.plano_contas
   Plano hierárquico (plano_contas): código, tipo receita/despesa,
   conta pai, nível calculado, aceita_lancamento nas folhas.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'plano_contas';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('financeiro.plano_contas.editar');
        $id     = vero_int('id');
        $codigo = vero_str('codigo', 20);
        $nome   = vero_str('nome', 120);
        $tipo   = (string)($_POST['tipo'] ?? 'despesa');
        if (!in_array($tipo, ['receita', 'despesa'], true)) $tipo = 'despesa';
        if ($codigo === null || $nome === null) {
            vero_flash('erro', 'Código e nome são obrigatórios.');
            vero_redirect();
        }
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND codigo=:c AND id<>:id",
            [':t' => $t, ':c' => $codigo, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe conta com o código {$codigo}.");
            vero_redirect();
        }
        $paiId = vero_int('conta_pai_id') ?: null;
        $nivel = 1;
        if ($paiId) {
            if ($id && $paiId === $id) {
                vero_flash('erro', 'A conta não pode ser pai dela mesma.');
                vero_redirect();
            }
            $pai = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $paiId, ':t' => $t]);
            if (!$pai) { vero_flash('erro', 'Conta pai inválida.'); vero_redirect(); }
            $nivel = (int)$pai['nivel'] + 1;
            $tipo = (string)$pai['tipo']; // herda o tipo do pai
        }
        $data = [
            'codigo'            => $codigo,
            'nome'              => $nome,
            'tipo'              => $tipo,
            'conta_pai_id'      => $paiId,
            'nivel'             => $nivel,
            'aceita_lancamento' => vero_int('aceita_lancamento') ?? 1,
            'ativo'             => vero_int('ativo') ?? 1,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Conta {$codigo} atualizada."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Conta {$codigo} criada."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('financeiro.plano_contas.excluir');
        $id = vero_int('id');
        if ($id) {
            $filhas = (int)vero_val("SELECT COUNT(*) FROM " . T . " WHERE tenant_id=:t AND conta_pai_id=:p AND ativo=1",
                [':t' => $t, ':p' => $id]);
            $uso = (int)vero_val("SELECT COUNT(*) FROM custeio_lancamentos WHERE tenant_id=:t AND plano_conta_id=:p",
                [':t' => $t, ':p' => $id]);
            if ($filhas > 0 || $uso > 0) {
                vero_update(T, (int)$id, ['ativo' => 0]);
                vero_flash('erro', 'Conta com filhas ou lançamentos — inativada em vez de excluída.');
            } else {
                vero_delete(T, $id);
            }
        }
        vero_redirect();
    }
}

$rows = vero_rows("SELECT * FROM " . T . " WHERE tenant_id = :t ORDER BY codigo", [':t' => $t]);

/* montagem da árvore por código (ordenada) */
$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t", [':id' => (int)$_GET['editar'], ':t' => $t]);
}
$paiOpts = [];
foreach ($rows as $r) {
    if ((int)$r['ativo'] === 1) $paiOpts[(int)$r['id']] = $r['codigo'] . ' — ' . $r['nome'];
}

$GUARD      = ['macro' => 'financeiro', 'micro' => 'plano_contas'];
$PAGE_VIEW  = 'financeiro_plano_contas';
$PAGE_TITLE = 'Plano de Contas';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('financeiro.plano_contas.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Plano de Contas', 'Estrutura hierárquica de receitas e despesas — lançamentos nas contas-folha',
        $podeEditar ? '+ Nova conta' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma conta cadastrada — comece pelas contas de nível 1 (ex.: 1 Receitas, 2 Despesas).</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Código / Conta</th><th>Tipo</th>
        <th style="text-align:center">Aceita lançamento</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= (int)$r['ativo'] !== 1 ? ' style="opacity:.55"' : '' ?>>
          <td style="padding-left:<?= 14 + ((int)$r['nivel'] - 1) * 26 ?>px">
            <strong class="vnum"><?= h($r['codigo']) ?></strong> <?= h($r['nome']) ?></td>
          <td><?= $r['tipo'] === 'receita'
                ? '<span class="vbadge vb-ok">Receita</span>' : '<span class="vbadge vb-warn">Despesa</span>' ?></td>
          <td style="text-align:center"><?= (int)$r['aceita_lancamento'] === 1 ? '✓' : '<span class="vhint">sintética</span>' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('financeiro.plano_contas.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir esta conta? (com filhas/lançamentos será inativada)') ?>
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
      <h2><?= $edit ? 'Editar conta' : 'Nova conta' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vhint" style="margin-bottom:8px">Contas de nível 1 são os grandes grupos (ex.: 1 Receitas, 2 Despesas); crie subcontas indicando a conta pai. Só as contas-folha (analíticas) recebem lançamentos.</div>

      <div style="padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">Identificação</strong>
        <div class="vgrid" style="margin-top:6px">
          <?= vero_f_text('codigo', 'Código', $edit['codigo'] ?? '', true, 'Ex.: 2.1.3') ?>
          <?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', true) ?>
        </div>
      </div>

      <div style="margin-top:10px;padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">Classificação</strong>
        <div class="vgrid" style="margin-top:6px">
          <?= vero_f_select('tipo', 'Tipo (herda do pai quando houver)', ['despesa' => 'Despesa', 'receita' => 'Receita'],
              $edit['tipo'] ?? 'despesa', true, '') ?>
          <?= vero_f_select('conta_pai_id', 'Conta pai', ['' => 'Nenhuma (nível 1)'] + $paiOpts, $edit['conta_pai_id'] ?? '', false, '') ?>
          <?= vero_f_select('aceita_lancamento', 'Aceita lançamento', [1 => 'Sim (analítica)', 0 => 'Não (sintética)'],
              $edit['aceita_lancamento'] ?? 1, true, '') ?>
          <?php if ($edit): ?>
            <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
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
