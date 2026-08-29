<?php
/* ============================================================
   VERO — Pessoas / Terceirizados  (CRUD real)
   Tela nova. Rota da matriz: /pessoas/terceirizados.php
   Guard: pessoas.terceirizados | Escrita: pessoas.terceirizados.editar/excluir
   Tabela: rh_terceirizados (migration 130)
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'rh_terceirizados';

const MODALIDADES = ['producao' => 'Produção', 'diaria' => 'Diária'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('pessoas.terceirizados.editar');

        $id         = vero_int('id');
        $nome       = vero_str('nome', 150);
        $modalidade = vero_str('modalidade_padrao', 10);

        if ($nome === null || $modalidade === null || !isset(MODALIDADES[$modalidade])) {
            vero_flash('erro', 'Nome e modalidade padrão são obrigatórios.');
            vero_redirect();
        }
        $valorDiaria = vero_dec('valor_diaria');
        if ($valorDiaria !== null && $valorDiaria < 0) { /* A11: valor da diária nunca é negativo (qualquer modalidade) */
            vero_flash('erro', 'O valor da diária não pode ser negativo.');
            vero_redirect();
        }
        if ($modalidade === 'diaria' && ($valorDiaria === null || $valorDiaria <= 0)) {
            vero_flash('erro', 'Informe o valor da diária para a modalidade Diária.');
            vero_redirect();
        }
        /* sem constraint no banco — aviso amigável para homônimo ativo */
        $dup = vero_val(
            "SELECT id FROM " . T . " WHERE tenant_id=:t AND nome=:n AND ativo=1 AND id<>:id",
            [':t' => vero_tenant(), ':n' => $nome, ':id' => (int)$id]
        );
        if ($dup) {
            vero_flash('erro', "Já existe um terceirizado ativo chamado \"{$nome}\".");
            vero_redirect();
        }

        $papelPk = vero_str('funcao_packing', 12);
        if ($papelPk !== null && !in_array($papelPk, ['colhedor', 'embalador', 'ambos'], true)) $papelPk = null;
        $data = [
            'nome'              => $nome,
            'funcao_packing'    => $papelPk,
            'documento'         => vero_str('documento', 20),
            'telefone'          => vero_str('telefone', 20),
            'modalidade_padrao' => $modalidade,
            'valor_diaria'      => $valorDiaria,
            'observacao'        => vero_str('observacao', 255),
            'ativo'             => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', "Terceirizado \"{$nome}\" atualizado.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Terceirizado \"{$nome}\" cadastrado.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('pessoas.terceirizados.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

$where  = "t.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    $where .= " AND (t.nome LIKE :q1 OR t.documento LIKE :q2 OR t.telefone LIKE :q3)";
    foreach ([1, 2, 3] as $qi) $params[":q{$qi}"] = "%{$q}%"; /* QA-011 */
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " t WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT t.* FROM " . T . " t
      WHERE {$where}
      ORDER BY t.ativo DESC, t.nome
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'pessoas', 'micro' => 'terceirizados'];
$PAGE_VIEW  = 'pessoas_terceirizados';
$PAGE_TITLE = 'Terceirizados';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('pessoas.terceirizados.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Terceirizados', 'Prestadores de serviço por diária ou produção — pagos pelo apontamento, sem encargos de folha',
        $podeEditar ? '+ Novo terceirizado' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nome, documento ou telefone…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum terceirizado encontrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Nome</th><th>Documento</th><th>Telefone</th><th>Modalidade padrão</th>
        <th style="text-align:right">Valor diária (R$)</th><th>Observação</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td class="vnum"><?= h($r['documento'] ?? '') ?: '—' ?></td>
          <td class="vnum"><?= h($r['telefone'] ?? '') ?: '—' ?></td>
          <td><span class="vbadge <?= $r['modalidade_padrao'] === 'producao' ? 'vb-info' : 'vb-warn' ?>"><?= h(MODALIDADES[$r['modalidade_padrao']] ?? $r['modalidade_padrao']) ?></span></td>
          <td class="vnum" style="text-align:right"><?= $r['valor_diaria'] !== null ? numFmt((float)$r['valor_diaria'], 2) : '—' ?></td>
          <td class="vhint"><?= h(mb_substr((string)($r['observacao'] ?? ''), 0, 50)) ?: '—' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('pessoas.terceirizados.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este terceirizado?') ?>
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
      <h2><?= $edit ? 'Editar terceirizado' : 'Novo terceirizado' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('nome', 'Nome completo', $edit['nome'] ?? '', true) ?></div>
        <?= vero_f_text('documento', 'Documento (CPF)', $edit['documento'] ?? '') ?>
        <?= vero_f_text('telefone', 'Telefone', $edit['telefone'] ?? '') ?>
        <?= vero_f_select('modalidade_padrao', 'Modalidade padrão', MODALIDADES, $edit['modalidade_padrao'] ?? 'producao', true, '') ?>
        <?= vero_f_select('funcao_packing', 'Função no packing', ['colhedor' => 'Colhedor', 'embalador' => 'Embalador', 'ambos' => 'Ambos'], $edit['funcao_packing'] ?? '', false, '— Não atua no packing —') ?>
        <?= vero_f_text('valor_diaria', 'Valor da diária (R$)', $edit && $edit['valor_diaria'] !== null ? numFmt((float)$edit['valor_diaria'], 2) : '', false, 'Obrigatório na modalidade Diária') ?>
        <div class="full"><?= vero_f_text('observacao', 'Observação (opcional)', $edit['observacao'] ?? '') ?></div>
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
