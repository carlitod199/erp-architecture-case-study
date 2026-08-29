<?php
/* ============================================================
   VERO — Gestão Agrícola / Porta-enxertos  (CRUD real)
   Rota da matriz: /agro/porta_enxertos.php
   Cadastro botânico irmão da variedade. O porta-enxerto é
   ATRIBUÍDO no talhão/válvula (agro_talhoes.porta_enxerto_id) e
   pode qualificar faixas nutricionais.
   Tabela: agro_porta_enxertos (migration 155)
   A0: reusa permissão de variedades por ora; slug próprio = decisão futura
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'agro_porta_enxertos';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.variedades.editar'); /* A0: reusa permissão de variedades */

        $id   = vero_int('id');
        $nome = vero_str('nome', 120);

        if ($nome === null) {
            vero_flash('erro', 'O nome do porta-enxerto é obrigatório.');
            vero_redirect();
        }
        /* nome único por tenant (inclui inativos) */
        $dup = vero_val(
            "SELECT id FROM " . T . " WHERE tenant_id=:t AND nome=:n AND id<>:id",
            [':t' => vero_tenant(), ':n' => $nome, ':id' => (int)$id]
        );
        if ($dup) {
            vero_flash('erro', "Já existe o porta-enxerto \"{$nome}\" (mesmo que inativo).");
            vero_redirect();
        }

        $data = [
            'nome'      => $nome,
            'codigo'    => vero_str('codigo', 40),
            'descricao' => vero_str('descricao', 1000),
            'ativo'     => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', "Porta-enxerto \"{$nome}\" atualizado.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Porta-enxerto \"{$nome}\" cadastrado.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('agro.variedades.excluir'); /* A0: reusa permissão de variedades */
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 15;

$where  = "pe.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    /* QA-011: placeholder repetido quebra com prepares nativos (HY093) — :q1..:qN */
    $where .= " AND (pe.nome LIKE :q1 OR pe.codigo LIKE :q2 OR pe.descricao LIKE :q3)";
    $params[':q1'] = $params[':q2'] = $params[':q3'] = "%{$q}%";
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " pe WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT pe.* FROM " . T . " pe
      WHERE {$where}
      ORDER BY pe.ativo DESC, pe.nome
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

/* A0: reusa o guard/slug de variedades (cadastro botânico irmão) — sem migration de permissão */
$GUARD      = ['macro' => 'agricola', 'micro' => 'variedades'];
$PAGE_VIEW  = 'agricola_porta_enxertos';
$PAGE_TITLE = 'Porta-enxertos';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.variedades.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Porta-enxertos', 'Cadastro de porta-enxertos — atribuído no talhão/válvula e usado como referência nas faixas nutricionais',
        $podeEditar ? '+ Novo porta-enxerto' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nome, código ou descrição…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum porta-enxerto encontrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Nome</th><th>Código</th><th>Descrição</th><th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= $r['codigo'] !== null && $r['codigo'] !== '' ? '<span class="vhint vnum">' . h($r['codigo']) . '</span>' : '—' ?></td>
          <td class="vhint"><?= h($r['descricao'] ?? '') ?: '—' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('agro.variedades.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este porta-enxerto?') ?>
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
      <h2><?= $edit ? 'Editar porta-enxerto' : 'Novo porta-enxerto' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome do porta-enxerto', $edit['nome'] ?? '', true, 'Ex.: IAC 572, Paulsen 1103, SO4') ?>
        <?= vero_f_text('codigo', 'Código (opcional)', $edit['codigo'] ?? '') ?>
        <div class="full"><?= vero_f_text('descricao', 'Descrição', $edit['descricao'] ?? '', false, 'Vigor, tolerâncias, indicações de uso…') ?></div>
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
