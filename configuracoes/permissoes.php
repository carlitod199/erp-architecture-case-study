<?php
/* ============================================================
   VERO — Configurações / Permissões  (tela real)
   Substitui o mock. Rota: /configuracoes/permissoes.php
   Guard: configuracoes.permissoes
   Tabelas: permissions (catálogo, sincronizado da matriz de
   navegação) + role_permissions (marcações por perfil).
   O login carrega exatamente role_permissions (index.php), com
   wildcards suportados por vero_dbn_perm. super_admin = '*'.
   Perfis GLOBAIS (tenant_id NULL) são compartilhados entre tenants —
   a tela avisa antes de editar.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/menu_agro.php';
require_once __DIR__ . '/../includes/permissions.php';
/* Catálogo derivado da matriz: perm_catalogo_matriz() vive em
   includes/permissions.php desde o A0-04 (gerador ÚNICO — antes esta
   tela tinha um gerador próprio, divergente do catálogo global). */

/** Garante o catálogo na tabela permissions e retorna slug → id. */
function perm_sincronizar_catalogo(array $catalogo): array
{
    $pdo = vero_pdo();
    $ins = $pdo->prepare("INSERT IGNORE INTO permissions (slug, label, modulo) VALUES (?,?,?)");
    foreach ($catalogo as $base => $grupo) {
        foreach ($grupo['itens'] as $item) {
            $ins->execute([$item['slug'], $item['label'], $base]);
        }
    }
    $mapa = [];
    foreach (vero_rows("SELECT id, slug FROM permissions") as $p) {
        $mapa[(string)$p['slug']] = (int)$p['id'];
    }
    return $mapa;
}

$catalogo = perm_catalogo_matriz();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (($_POST['acao'] ?? '') === 'salvar') {
        vero_require('configuracoes.permissoes.editar');

        $roleId = vero_int('role_id');
        $role = $roleId ? vero_row(
            "SELECT * FROM roles WHERE id=:i AND (tenant_id IS NULL OR tenant_id=:t) AND slug <> 'super_admin'",
            [':i' => $roleId, ':t' => vero_tenant()]) : null;
        if (!$role) {
            vero_flash('erro', 'Perfil inválido (super_admin tem todas as permissões e não é editável).');
            vero_redirect(BIOS_BASE . '/configuracoes/permissoes');
        }

        $mapa = perm_sincronizar_catalogo($catalogo);
        $marcadas = array_values(array_intersect(
            array_map('strval', (array)($_POST['perm'] ?? [])),
            array_keys($mapa)
        ));

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([(int)$roleId]);
            $ins = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)");
            foreach ($marcadas as $slug) {
                $ins->execute([(int)$roleId, $mapa[$slug]]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao salvar: ' . h($e->getMessage()));
            vero_redirect(BIOS_BASE . '/configuracoes/permissoes?role=' . $roleId);
        }
        vero_flash('ok', 'Permissões do perfil "' . h($role['nome']) . '" salvas (' . count($marcadas) . ' marcações). Usuários aplicam no próximo login.');
        vero_redirect(BIOS_BASE . '/configuracoes/permissoes?role=' . $roleId);
    }
}

/* ── Dados ──────────────────────────────────────────────────── */
$roles = vero_rows(
    "SELECT * FROM roles
      WHERE (tenant_id IS NULL OR tenant_id = :t) AND ativo = 1 AND slug <> 'super_admin'
      ORDER BY tenant_id IS NULL DESC, nome", [':t' => vero_tenant()]);

$roleSel = null;
$marcadasAtuais = [];
if (!empty($_GET['role'])) {
    foreach ($roles as $r) {
        if ((int)$r['id'] === (int)$_GET['role']) { $roleSel = $r; break; }
    }
    if ($roleSel) {
        $marcadasAtuais = array_map('strval', array_column(vero_rows(
            "SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = :r", [':r' => (int)$roleSel['id']]), 'slug'));
    }
}

$GUARD      = ['macro' => 'configuracoes', 'micro' => 'permissoes'];
$PAGE_VIEW  = 'configuracoes_permissoes';
$PAGE_TITLE = 'Permissões';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('configuracoes.permissoes.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Permissões por Perfil', 'Catálogo gerado da matriz de navegação — marque ver/editar/excluir por tela; wildcards (* e modulo.*) continuam válidos', null) ?>

  <div class="vcard" style="padding:16px 18px;margin-bottom:16px">
    <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <div class="vfield" style="min-width:280px">
        <label>Perfil</label>
        <select name="role" onchange="this.form.submit()">
          <option value="">— Selecione um perfil —</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int)$r['id'] ?>"<?= $roleSel && (int)$roleSel['id'] === (int)$r['id'] ? ' selected' : '' ?>>
              <?= h($r['nome']) ?><?= $r['tenant_id'] === null ? ' (sistema)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vhint" style="padding-bottom:10px">super_admin tem todas as permissões (*) e não aparece aqui.</div>
    </form>
    <?php if ($roleSel && $roleSel['tenant_id'] === null): ?>
      <div class="vflash vflash-aviso" style="margin:10px 0 0">
        Perfil de SISTEMA — alterações valem para todos os tenants que o utilizam.
      </div>
    <?php endif; ?>
  </div>

  <?php if ($roleSel): ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="role_id" value="<?= (int)$roleSel['id'] ?>">

    <div class="vcard" style="position:sticky;top:8px;z-index:5;padding:11px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <input type="search" id="permBusca" placeholder="Buscar tela ou módulo…" oninput="permFiltro()"
             style="flex:1;min-width:200px;padding:8px 11px">
      <span class="vhint">Marcar coluna:</span>
      <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="permColuna('ver',true)">Ver</button>
      <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="permColuna('editar',true)">Editar</button>
      <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="permColuna('excluir',true)">Excluir</button>
      <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="permLimparTudo()">Limpar tudo</button>
      <span class="vbadge vb-info" id="permContador">0 marcadas</span>
    </div>

    <?php foreach ($catalogo as $base => $grupo):
        /* agrupa por micro para exibir em linhas ver/editar/excluir */
        $porMicro = [];
        $permModulo = null;
        foreach ($grupo['itens'] as $item) {
            if ($item['micro'] === null) { $permModulo = $item; continue; }
            $porMicro[$item['micro']][$item['acao']] = $item;
        }
    ?>
    <div class="vcard" style="margin-bottom:14px">
      <div class="vtoolbar">
        <strong style="font-size:14px"><?= h($grupo['label']) ?></strong>
        <span class="vhint">(<?= h($base) ?>.*)</span>
        <div style="flex:1"></div>
        <?php if ($permModulo): ?>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px">
          <input type="checkbox" class="perm-cb" data-acao="modulo" name="perm[]" value="<?= h($permModulo['slug']) ?>" style="width:auto"
                 <?= in_array($permModulo['slug'], $marcadasAtuais, true) ? 'checked' : '' ?>>
          Ver o módulo (menu)
        </label>
        <?php endif; ?>
        <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="permGrupo(this, true)">Marcar tudo</button>
        <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="permGrupo(this, false)">Limpar</button>
      </div>
      <table class="vtable">
        <thead><tr>
          <th>Tela</th>
          <th style="width:100px;text-align:center">Ver</th>
          <th style="width:100px;text-align:center">Editar</th>
          <th style="width:100px;text-align:center">Excluir</th>
        </tr></thead>
        <tbody>
        <?php foreach ($porMicro as $micro => $acoes): ?>
          <tr class="perm-linha" data-busca="<?= h(mb_strtolower($micro . ' ' . $grupo['label'])) ?>">
            <td><?= h($micro) ?></td>
            <?php foreach (['ver', 'editar', 'excluir'] as $acao): $item = $acoes[$acao] ?? null; ?>
              <td style="text-align:center">
                <?php if ($item): ?>
                  <input type="checkbox" class="perm-cb" data-acao="<?= $acao ?>" name="perm[]" value="<?= h($item['slug']) ?>" style="width:auto"
                         <?= in_array($item['slug'], $marcadasAtuais, true) ? 'checked' : '' ?>>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>

    <?php if ($podeEditar): ?>
    <div style="position:sticky;bottom:14px;display:flex;justify-content:flex-end;gap:10px">
      <button class="vbtn vbtn-primary" type="submit" style="box-shadow:0 6px 18px rgba(0,0,0,.25)">Salvar permissões do perfil</button>
    </div>
    <?php endif; ?>
  </form>

  <script>
  /* auto-coerência (#49): marcar Editar/Excluir marca a Ver da mesma tela — evita
     a permissão "morta" (o guard da tela exige a .ver) que o harness detecta. */
  function permAutoVer(cb) {
    if (!cb.checked) return;
    if (cb.dataset.acao !== 'editar' && cb.dataset.acao !== 'excluir') return;
    const ver = cb.closest('tr') && cb.closest('tr').querySelector('.perm-cb[data-acao="ver"]');
    if (ver) ver.checked = true;
  }
  function permContar() {
    const n = document.querySelectorAll('.perm-cb:checked').length;
    const el = document.getElementById('permContador');
    if (el) el.textContent = n + ' marcada' + (n === 1 ? '' : 's');
  }
  function permFiltro() {
    const q = (document.getElementById('permBusca').value || '').toLowerCase().trim();
    document.querySelectorAll('.perm-linha').forEach(tr => {
      tr.style.display = (!q || (tr.dataset.busca || '').indexOf(q) !== -1) ? '' : 'none';
    });
    document.querySelectorAll('form[method="post"] > .vcard').forEach(card => {
      const linhas = card.querySelectorAll('.perm-linha');
      if (!linhas.length) return; /* pula a barra de ferramentas (sem linhas) */
      const alguma = Array.prototype.some.call(linhas, tr => tr.style.display !== 'none');
      card.style.display = alguma ? '' : 'none';
    });
  }
  function permColuna(acao, marcar) {
    document.querySelectorAll('.perm-cb[data-acao="' + acao + '"]').forEach(cb => {
      const tr = cb.closest('.perm-linha');
      if (tr && tr.style.display === 'none') return; /* respeita o filtro ativo */
      cb.checked = marcar;
      if (marcar) permAutoVer(cb);
    });
    permContar();
  }
  function permLimparTudo() { document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = false); permContar(); }
  function permGrupo(btn, marcar) {
    btn.closest('.vcard').querySelectorAll('.perm-cb').forEach(c => { c.checked = marcar; if (marcar) permAutoVer(c); });
    permContar();
  }
  document.addEventListener('change', e => {
    if (e.target && e.target.classList && e.target.classList.contains('perm-cb')) { permAutoVer(e.target); permContar(); }
  });
  permContar();
  </script>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
