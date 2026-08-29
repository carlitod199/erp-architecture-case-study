<?php
/* ============================================================
   VERO — Nutrição / Nutrientes  (CRUD real)
   Substitui o mock. Rota: /nutricao/nutrientes.php
   Guard: nutricao.nutrientes
   Catálogo de nutrientes (analise_nutrientes) usado por faixas,
   análises e importação de laudo. Exclusão bloqueada quando o
   nutriente tem resultados ou faixas — apenas inativa.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'analise_nutrientes';
const APLICACOES = ['solo' => 'Solo', 'foliar' => 'Foliar', 'ambos' => 'Solo + Foliar'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('nutricao.nutrientes.editar');
        $id   = vero_int('id');
        $nome = vero_str('nome', 80);
        if ($nome === null) {
            vero_flash('erro', 'Nome é obrigatório.');
            vero_redirect();
        }
        $aplicacao = (string)($_POST['aplicacao'] ?? 'ambos');
        if (!isset(APLICACOES[$aplicacao])) $aplicacao = 'ambos';
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND nome=:n AND id<>:id",
            [':t' => vero_tenant(), ':n' => $nome, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe o nutriente \"{$nome}\".");
            vero_redirect();
        }
        $data = [
            'nome'           => $nome,
            'simbolo'        => vero_str('simbolo', 10),
            'aplicacao'      => $aplicacao,
            'unidade_padrao' => vero_str('unidade_padrao', 20),
            'ordem'          => vero_int('ordem') ?? 0,
            'ativo'          => vero_int('ativo') ?? 1,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Nutriente \"{$nome}\" atualizado."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Nutriente \"{$nome}\" criado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('nutricao.nutrientes.excluir');
        $id = vero_int('id');
        if ($id) {
            $uso = (int)vero_val(
                "SELECT (SELECT COUNT(*) FROM analise_solo_resultados WHERE tenant_id=:t1 AND nutriente_id=:n1)
                      + (SELECT COUNT(*) FROM analise_foliar_resultados WHERE tenant_id=:t2 AND nutriente_id=:n2)
                      + (SELECT COUNT(*) FROM analise_faixas WHERE tenant_id=:t3 AND nutriente_id=:n3)",
                [':t1' => vero_tenant(), ':n1' => $id, ':t2' => vero_tenant(), ':n2' => $id, ':t3' => vero_tenant(), ':n3' => $id]);
            if ($uso > 0) {
                vero_update(T, (int)$id, ['ativo' => 0]);
                vero_flash('erro', "Nutriente em uso por {$uso} resultado(s)/faixa(s) — inativado em vez de excluído.");
            } else {
                vero_delete(T, $id);
            }
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT n.*,
            (SELECT COUNT(*) FROM analise_faixas fx WHERE fx.tenant_id=n.tenant_id AND fx.nutriente_id=n.id AND fx.ativo=1) AS faixas,
            (SELECT COUNT(*) FROM analise_solo_resultados r WHERE r.tenant_id=n.tenant_id AND r.nutriente_id=n.id)
          + (SELECT COUNT(*) FROM analise_foliar_resultados r2 WHERE r2.tenant_id=n.tenant_id AND r2.nutriente_id=n.id) AS resultados
       FROM " . T . " n
      WHERE n.tenant_id = :t ORDER BY n.ativo DESC, n.ordem, n.nome", [':t' => vero_tenant()]);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'nutricao', 'micro' => 'nutrientes'];
$PAGE_VIEW  = 'nutricao_nutrientes';
$PAGE_TITLE = 'Nutrientes';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('nutricao.nutrientes.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Nutrientes', 'Catálogo usado pelas faixas, análises e importação de laudo',
        $podeEditar ? '+ Novo nutriente' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum nutriente cadastrado.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th class="num">Ordem</th><th>Símbolo</th><th>Nome</th>
        <th>Aplicação</th><th>Unidade padrão</th>
        <th class="num">Faixas ativas</th>
        <th class="num">Resultados</th>
        <th>Status</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="num"><?= (int)$r['ordem'] ?></td>
          <td><strong class="vnum"><?= h($r['simbolo'] ?? '—') ?></strong></td>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><span class="vbadge vb-info"><?= h(APLICACOES[(string)$r['aplicacao']] ?? ucfirst((string)$r['aplicacao'])) ?></span></td>
          <td class="vnum"><?= h($r['unidade_padrao'] ?? '—') ?></td>
          <td class="num"><?= (int)$r['faixas'] ?></td>
          <td class="num"><?= (int)$r['resultados'] ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('nutricao.nutrientes.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este nutriente? (em uso será apenas inativado)') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar nutriente' : 'Novo nutriente' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', true) ?>
        <?= vero_f_text('simbolo', 'Símbolo', $edit['simbolo'] ?? '', false, 'Ex.: N, P, K, Ca') ?>
        <?= vero_f_select('aplicacao', 'Aplicação', APLICACOES, $edit['aplicacao'] ?? 'ambos', true, '') ?>
        <?= vero_f_text('unidade_padrao', 'Unidade padrão', $edit['unidade_padrao'] ?? '', false, 'Ex.: mg/dm3, g/kg, %') ?>
        <?= vero_f_text('ordem', 'Ordem de exibição', (string)($edit['ordem'] ?? '0')) ?>
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
