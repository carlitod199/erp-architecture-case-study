<?php
/* ============================================================
   VERO — Configurações / Perfis de Acesso  (CRUD real)
   Substitui o mock. Rota: /configuracoes/perfis_acesso.php
   Guard: configuracoes.perfis_acesso
   Tabela: roles — perfis globais (tenant_id NULL, somente leitura)
   e do tenant. Permissões por perfil na tela Permissões.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'roles';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('configuracoes.perfis_acesso.editar');

        $id   = vero_int('id');
        $nome = vero_str('nome', 120);
        if ($nome === null) {
            vero_flash('erro', 'Nome do perfil é obrigatório.');
            vero_redirect();
        }
        /* slug: gerado do nome no cadastro; imutável na edição (usuarios.perfil aponta pra ele) */
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nome)) ?? '', '_'));
        if ($slug === '') $slug = 'perfil_' . time();

        /* B4 (auditoria Go-Live): slugs reservados NÃO podem ser criados aqui —
           super_admin/club_admin/admin resolvem para acesso total (login e RBAC).
           Sem isto, um delegado de perfis fabricava um perfil "Admin" (slug=admin)
           e, atribuído a um usuário, obtinha '*'. Bloqueia na origem. */
        $slugsReservados = ['super_admin', 'club_admin', 'admin', 'superadmin', 'root'];
        if (in_array($slug, $slugsReservados, true)) {
            vero_flash('erro', 'Este identificador de perfil é reservado pelo sistema. Escolha outro nome.');
            vero_redirect();
        }

        if ($id) {
            $role = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => vero_tenant()]);
            if (!$role) {
                vero_flash('erro', 'Perfis globais do sistema não podem ser editados aqui.');
                vero_redirect();
            }
            vero_update(T, $id, [
                'nome'      => $nome,
                'descricao' => vero_str('descricao', 255),
                'ativo'     => vero_int('ativo') ?? 1,
            ]);
            vero_flash('ok', "Perfil \"{$nome}\" atualizado.");
        } else {
            $dup = vero_val("SELECT id FROM " . T . " WHERE slug=:s AND (tenant_id IS NULL OR tenant_id=:t)",
                [':s' => $slug, ':t' => vero_tenant()]);
            if ($dup) {
                vero_flash('erro', "Já existe um perfil com o identificador \"{$slug}\".");
                vero_redirect();
            }
            vero_insert(T, [
                'slug'      => $slug,
                'nome'      => $nome,
                'descricao' => vero_str('descricao', 255),
                'ativo'     => 1,
            ]);
            vero_flash('ok', "Perfil \"{$nome}\" criado — defina as permissões em Configurações → Permissões.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('configuracoes.perfis_acesso.excluir');
        $id = vero_int('id');
        $role = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if (!$role) {
            vero_flash('erro', 'Perfis globais não podem ser inativados.');
        } else {
            $emUso = (int)vero_val("SELECT COUNT(*) FROM usuarios WHERE tenant_id=:t AND perfil=:s AND ativo=1",
                [':t' => vero_tenant(), ':s' => $role['slug']]);
            if ($emUso > 0) {
                vero_flash('erro', "Perfil em uso por {$emUso} usuário(s) ativo(s) — troque o perfil deles antes.");
            } else {
                vero_update(T, (int)$id, ['ativo' => 0]);
                vero_flash('ok', 'Perfil inativado.');
            }
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$rows = vero_rows(
    "SELECT r.*,
            (SELECT COUNT(*) FROM usuarios u
              WHERE u.tenant_id = :t1 AND u.perfil = r.slug AND u.ativo = 1) AS usuarios,
            (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permissoes
       FROM " . T . " r
      WHERE r.tenant_id IS NULL OR r.tenant_id = :t2
      ORDER BY r.tenant_id IS NULL DESC, r.ativo DESC, r.nome",
    [':t1' => vero_tenant(), ':t2' => vero_tenant()]);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'configuracoes', 'micro' => 'perfis_acesso'];
$PAGE_VIEW  = 'configuracoes_perfis_acesso';
$PAGE_TITLE = 'Perfis de Acesso';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('configuracoes.perfis_acesso.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Perfis de Acesso', 'Perfis globais do sistema (fixos) e perfis próprios do tenant — permissões na tela ao lado',
        $podeEditar ? '+ Novo perfil' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum perfil.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Perfil</th><th>Identificador</th><th>Escopo</th><th>Descrição</th>
        <th style="text-align:right">Usuários ativos</th>
        <th style="text-align:right">Permissões</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $global = $r['tenant_id'] === null; ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td class="vnum"><?= h($r['slug']) ?></td>
          <td><?= $global ? '<span class="vbadge vb-warn">Sistema</span>' : '<span class="vbadge vb-info">Tenant</span>' ?></td>
          <td class="vhint"><?= h($r['descricao'] ?? '') ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['usuarios'] ?></td>
          <td class="vnum" style="text-align:right">
            <?= $r['slug'] === 'super_admin' ? '<span class="vbadge vb-ok">todas (*)</span>' : (int)$r['permissoes'] ?>
          </td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($r['slug'] !== 'super_admin'): ?>
              <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/configuracoes/permissoes?role=<?= (int)$r['id'] ?>">Permissões</a>
            <?php endif; ?>
            <?php if ($podeEditar && !$global): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('configuracoes.perfis_acesso.excluir') && !$global && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este perfil?') ?>
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
      <h2><?= $edit ? 'Editar perfil' : 'Novo perfil do tenant' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('nome', 'Nome do perfil', $edit['nome'] ?? '', true, $edit ? 'Identificador fixo: ' . $edit['slug'] : 'O identificador é gerado do nome e não muda depois') ?></div>
        <div class="full"><?= vero_f_text('descricao', 'Descrição', $edit['descricao'] ?? '') ?></div>
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
