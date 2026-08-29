<?php
/* ============================================================
   VERO — Configurações / Usuários  (CRUD real)
   Substitui o mock. Rota: /configuracoes/usuarios.php
   Guard: configuracoes.usuarios
   Tabela: usuarios (perfil = slug do role). Senha: bcrypt cost 12
   (BCRYPT_COST do .env). Proteções: não inativar/rebaixar a si mesmo.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'usuarios';

$roles = vero_rows(
    "SELECT slug, nome FROM roles
      WHERE ativo = 1 AND (tenant_id IS NULL OR tenant_id = :t)
      ORDER BY nome", [':t' => vero_tenant()]);
$rolesMap = [];
foreach ($roles as $r) $rolesMap[(string)$r['slug']] = (string)$r['nome'];

/* R12-B5: mapa de rótulos para EXIBIÇÃO na lista — NUNCA filtrado (o $rolesMap
   perde perfis privilegiados para não-super_admin, o que fazia a coluna Perfil
   mostrar o slug cru "super_admin"). Fallback: slug em Title Case sem underscore. */
$rolesLabelAll = $rolesMap;
$roleNome = static function (string $slug) use ($rolesLabelAll): string {
    return $rolesLabelAll[$slug] ?? ucwords(str_replace('_', ' ', $slug));
};

/* B4 (auditoria Go-Live): segregação de funções na atribuição de perfil.
   Perfis privilegiados (acesso total) só podem ser concedidos por super_admin.
   Sem isto, quem tem configuracoes.usuarios.editar criava/promovia um super_admin
   (o único guard existente era não alterar o PRÓPRIO perfil). "Privilegiado" =
   slug reservado OU perfil com o grant '*' em role_permissions. Para operador
   não-super_admin esses perfis somem do dropdown E são rejeitados no POST. */
$_isSuperAdmin = ((string)($_SESSION['user_role'] ?? '') === 'super_admin');
$_perfisPrivilegiados = ['super_admin' => true, 'club_admin' => true, 'admin' => true, 'superadmin' => true, 'root' => true];
foreach (vero_rows(
    "SELECT DISTINCT r.slug FROM roles r
       JOIN role_permissions rp ON rp.role_id = r.id
       JOIN permissions p ON p.id = rp.permission_id
      WHERE p.slug = '*'") as $r) {
    $_perfisPrivilegiados[(string)$r['slug']] = true;
}
if (!$_isSuperAdmin) {
    foreach (array_keys($_perfisPrivilegiados) as $ps) unset($rolesMap[$ps]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('configuracoes.usuarios.editar');

        $id     = vero_int('id');
        $nome   = vero_str('nome', 150);
        $email  = vero_str('email', 150);
        $perfil = vero_str('perfil', 50);
        $senha  = (string)($_POST['senha'] ?? '');

        if ($nome === null || $email === null || $perfil === null || !isset($rolesMap[$perfil])) {
            vero_flash('erro', 'Nome, e-mail e perfil válido são obrigatórios.');
            vero_redirect();
        }
        /* B4: reforço explícito — perfil privilegiado só por super_admin (já
           filtrado de $rolesMap acima; esta guarda deixa a mensagem clara e
           protege caso a filtragem mude). */
        if (!$_isSuperAdmin && isset($_perfisPrivilegiados[$perfil])) {
            vero_flash('erro', 'Apenas um super administrador pode conceder este perfil.');
            vero_redirect();
        }
        /* R12 (segurança): quem não é super_admin NÃO pode editar/inativar/rebaixar
           um usuário cujo perfil ATUAL é privilegiado (super_admin). Sem esta guarda
           um "dono" conseguia rebaixar E inativar o super_admin via POST (provado no
           reteste). O bloqueio acima cobre só o perfil sendo ATRIBUÍDO. */
        if ($id && !$_isSuperAdmin) {
            $alvoPerfil = (string)vero_val(
                "SELECT perfil FROM " . T . " WHERE id=:id AND tenant_id=:t",
                [':id' => (int)$id, ':t' => vero_tenant()]);
            if ($alvoPerfil !== '' && isset($_perfisPrivilegiados[$alvoPerfil])) {
                vero_flash('erro', 'Apenas um super administrador pode editar um usuário com perfil privilegiado.');
                vero_redirect();
            }
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            vero_flash('erro', 'E-mail inválido.');
            vero_redirect();
        }
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND email=:e AND id<>:id",
            [':t' => vero_tenant(), ':e' => $email, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe um usuário com o e-mail {$email}.");
            vero_redirect();
        }
        $ativo = vero_int('ativo') ?? 1;
        if ($id === vero_uid() && ($ativo === 0 || $perfil !== (string)($_SESSION['user_role'] ?? ''))) {
            vero_flash('erro', 'Você não pode inativar nem alterar o próprio perfil (peça a outro administrador).');
            vero_redirect();
        }

        $data = ['nome' => $nome, 'email' => $email, 'perfil' => $perfil, 'ativo' => $ativo];
        if ($senha !== '') {
            /* Política de senha FORTE: >=8, maiúscula, minúscula,
               número e caractere especial. */
            $regras = [
                [mb_strlen($senha) >= 8,                      'ao menos 8 caracteres'],
                [(bool)preg_match('/[A-Z]/', $senha),         'uma letra maiúscula'],
                [(bool)preg_match('/[a-z]/', $senha),         'uma letra minúscula'],
                [(bool)preg_match('/\d/', $senha),            'um número'],
                [(bool)preg_match('/[^A-Za-z0-9]/', $senha),  'um caractere especial (ex.: ! @ # $ %)'],
            ];
            $faltam = array_column(array_filter($regras, static fn($r) => !$r[0]), 1);
            if ($faltam) {
                vero_flash('erro', 'Senha fraca — precisa conter: ' . implode(', ', $faltam) . '.');
                vero_redirect();
            }
            $cost = max(10, (int)($_ENV['BCRYPT_COST'] ?? 12));
            $data['senha_hash'] = password_hash($senha, PASSWORD_BCRYPT, ['cost' => $cost]);
        }

        if ($id) {
            vero_update(T, $id, $data);
            // A-7: trocou a senha → revoga todos os tokens de app do usuário (a
            // sessão mobile precisa reautenticar; um token roubado deixa de valer).
            if ($senha !== '') {
                vero_pdo()->prepare(
                    "UPDATE app_tokens SET revogado_em = NOW()
                      WHERE tenant_id = :t AND usuario_id = :u AND revogado_em IS NULL"
                )->execute([':t' => vero_tenant(), ':u' => (int)$id]);
            }
            vero_flash('ok', "Usuário \"{$nome}\" atualizado" . ($senha !== '' ? ' (senha trocada)' : '') . '.');
        } else {
            if ($senha === '') {
                vero_flash('erro', 'Defina a senha inicial do novo usuário.');
                vero_redirect();
            }
            vero_insert(T, $data);
            vero_flash('ok', "Usuário \"{$nome}\" criado.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('configuracoes.usuarios.excluir');
        $id = vero_int('id');
        if ($id === vero_uid()) {
            vero_flash('erro', 'Você não pode inativar a si mesmo.');
        } elseif ($id) {
            /* R12 (segurança): não-super_admin não pode inativar/excluir um usuário
               de perfil privilegiado (super_admin). */
            $alvoPerfil = (string)vero_val(
                "SELECT perfil FROM " . T . " WHERE id=:id AND tenant_id=:t",
                [':id' => (int)$id, ':t' => vero_tenant()]);
            if (!$_isSuperAdmin && $alvoPerfil !== '' && isset($_perfisPrivilegiados[$alvoPerfil])) {
                vero_flash('erro', 'Apenas um super administrador pode inativar um usuário com perfil privilegiado.');
            } else {
                vero_delete(T, $id); // soft delete (tem `ativo`)
            }
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

$where  = "u.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    $where .= " AND (u.nome LIKE :q1 OR u.email LIKE :q2)";
    foreach ([1, 2] as $qi) $params[":q{$qi}"] = "%{$q}%"; /* QA-011 */
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " u WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT u.* FROM " . T . " u WHERE {$where}
      ORDER BY u.ativo DESC, u.nome
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'configuracoes', 'micro' => 'usuarios'];
$PAGE_VIEW  = 'configuracoes_usuarios';
$PAGE_TITLE = 'Usuários';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('configuracoes.usuarios.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Usuários', 'Acessos do tenant — perfil define as permissões (Configurações → Perfis e Permissões)',
        $podeEditar ? '+ Novo usuário' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nome ou e-mail…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum usuário encontrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Nome</th><th>E-mail</th><th>Perfil</th><th>Último login</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong><?= (int)$r['id'] === vero_uid() ? ' <span class="vbadge vb-info">você</span>' : '' ?></td>
          <td><?= h($r['email'] ?? '') ?: '—' ?></td>
          <td><span class="vbadge <?= $r['perfil'] === 'super_admin' ? 'vb-off' : 'vb-info' ?>"><?= h($roleNome((string)$r['perfil'])) ?></span></td>
          <td class="vhint"><?= $r['ultimo_login'] ? date('d/m/Y H:i', strtotime((string)$r['ultimo_login'])) : 'nunca' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <?php /* R12: perfil privilegiado só é editável/inativável por super_admin —
                    esconde os ícones para os demais (mesmo padrão do dropdown B4). */
                 $alvoPodeMexer = $_isSuperAdmin || !isset($_perfisPrivilegiados[(string)$r['perfil']]); ?>
          <td><div class="vactions">
            <?php if ($podeEditar && $alvoPodeMexer): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if ($alvoPodeMexer && vero_can('configuracoes.usuarios.excluir') && (int)$r['ativo'] === 1 && (int)$r['id'] !== vero_uid()): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este usuário? Ele perde o acesso imediatamente.') ?>
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
      <h2><?= $edit ? 'Editar usuário' : 'Novo usuário' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('nome', 'Nome completo', $edit['nome'] ?? '', true) ?></div>
        <?= vero_f_text('email', 'E-mail (login)', $edit['email'] ?? '', true, '', 'email') ?>
        <?= vero_f_select('perfil', 'Perfil de acesso', $rolesMap, $edit['perfil'] ?? null, true) ?>
        <div class="vfield">
          <label>Senha <?= $edit ? '(deixe em branco para manter)' : '*' ?></label>
          <input type="password" name="senha" id="u-senha" autocomplete="new-password" minlength="8"<?= $edit ? '' : ' required' ?>>
          <ul id="u-senha-reqs" style="list-style:none;padding:0;margin:6px 0 0;font-size:12px;color:var(--muted,#8A7C68)">
            <li data-rule="len"><span class="mk">•</span> ao menos 8 caracteres</li>
            <li data-rule="upper"><span class="mk">•</span> uma letra maiúscula</li>
            <li data-rule="lower"><span class="mk">•</span> uma letra minúscula</li>
            <li data-rule="digit"><span class="mk">•</span> um número</li>
            <li data-rule="special"><span class="mk">•</span> um caractere especial (! @ # $ %)</li>
          </ul>
          <script>
          (function () {
            var i = document.getElementById('u-senha'), ul = document.getElementById('u-senha-reqs');
            if (!i || !ul) return;
            var C = { len: v => v.length >= 8, upper: v => /[A-Z]/.test(v), lower: v => /[a-z]/.test(v),
                      digit: v => /\d/.test(v), special: v => /[^A-Za-z0-9]/.test(v) };
            i.addEventListener('input', function () {
              var v = i.value;
              ul.querySelectorAll('li').forEach(function (li) {
                var ok = C[li.dataset.rule](v), mk = li.querySelector('.mk');
                if (v === '') { mk.textContent = '•'; li.style.color = ''; }
                else { mk.textContent = ok ? '✓' : '✗'; li.style.color = ok ? '#2E7D32' : '#9A3B2A'; }
              });
            });
          })();
          </script>
        </div>
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
