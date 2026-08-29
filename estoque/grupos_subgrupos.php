<?php
/* ============================================================
   VERO — Estoque / Grupos e Subgrupos  (CRUD real)
   Substitui o mock. Rota: /estoque/grupos_subgrupos.php
   Guard: estoque.grupos_subgrupos
   Tabelas: estoque_grupos + estoque_subgrupos (sem soft delete —
   exclusão física com FK traduzida em mensagem amigável).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const TIPOS_GRUPO = [
    'insumo' => 'Insumo', 'veterinario' => 'Veterinário', 'peca' => 'Peça',
    'combustivel' => 'Combustível', 'epi' => 'EPI', 'irrigacao' => 'Irrigação', 'outro' => 'Outro',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar_grupo') {
        vero_require('estoque.grupos_subgrupos.editar');
        $id   = vero_int('id');
        $nome = vero_str('nome', 120);
        $tipo = vero_str('tipo', 20);
        if ($nome === null || $tipo === null || !isset(TIPOS_GRUPO[$tipo])) {
            vero_flash('erro', 'Nome e tipo do grupo são obrigatórios.');
            vero_redirect();
        }
        $dup = vero_val("SELECT id FROM estoque_grupos WHERE tenant_id=:t AND nome=:n AND id<>:id",
            [':t' => vero_tenant(), ':n' => $nome, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe o grupo \"{$nome}\".");
            vero_redirect();
        }
        $data = ['nome' => $nome, 'tipo' => $tipo, 'ativo' => vero_int('ativo') ?? 1];
        if ($id) { vero_update('estoque_grupos', $id, $data); vero_flash('ok', 'Grupo atualizado.'); }
        else     { vero_insert('estoque_grupos', $data);      vero_flash('ok', 'Grupo criado.'); }
        vero_redirect();
    }

    if ($acao === 'salvar_subgrupo') {
        vero_require('estoque.grupos_subgrupos.editar');
        $grupoId = vero_int('grupo_id');
        $nome    = vero_str('nome_sub', 120);
        $okGrupo = $grupoId ? vero_val("SELECT id FROM estoque_grupos WHERE id=:i AND tenant_id=:t",
            [':i' => $grupoId, ':t' => vero_tenant()]) : null;
        if (!$okGrupo || $nome === null) {
            vero_flash('erro', 'Grupo e nome do subgrupo são obrigatórios.');
            vero_redirect();
        }
        $dup = vero_val("SELECT id FROM estoque_subgrupos WHERE tenant_id=:t AND grupo_id=:g AND nome=:n",
            [':t' => vero_tenant(), ':g' => $grupoId, ':n' => $nome]);
        if ($dup) {
            vero_flash('erro', "Já existe o subgrupo \"{$nome}\" neste grupo.");
            vero_redirect();
        }
        vero_pdo()->prepare("INSERT INTO estoque_subgrupos (tenant_id, grupo_id, nome, created_by, updated_by)
                             VALUES (?,?,?,?,?)")
            ->execute([vero_tenant(), $grupoId, $nome, vero_uid(), vero_uid()]);
        vero_flash('ok', 'Subgrupo criado.');
        vero_redirect();
    }

    if ($acao === 'excluir_grupo') {
        vero_require('estoque.grupos_subgrupos.excluir');
        $id = vero_int('id');
        if ($id) {
            /* A2-F2-18: grupo com produtos ATIVOS não inativa (evita cadastro
               órfão — a checagem C12 da Auditoria de Estoque pegaria depois) */
            $nAtivos = (int)vero_val(
                "SELECT COUNT(*) FROM estoque_produtos WHERE tenant_id=:t AND grupo_id=:g AND ativo=1",
                [':t' => vero_tenant(), ':g' => $id]);
            if ($nAtivos > 0) {
                vero_flash('erro', "O grupo tem {$nAtivos} produto(s) ATIVO(S) — mova-os de grupo (ou inative-os) antes de inativar o grupo.");
                vero_redirect();
            }
            vero_delete('estoque_grupos', $id); // soft delete (tem `ativo`)
            vero_flash('ok', 'Grupo inativado.');
        }
        vero_redirect();
    }

    if ($acao === 'excluir_subgrupo') {
        vero_require('estoque.grupos_subgrupos.excluir');
        $id = vero_int('id');
        if ($id) {
            try {
                vero_pdo()->prepare("DELETE FROM estoque_subgrupos WHERE tenant_id=? AND id=?")
                    ->execute([vero_tenant(), (int)$id]);
                vero_flash('ok', 'Subgrupo excluído.');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') vero_flash('erro', 'Subgrupo em uso por produtos — mova-os antes.');
                else throw $e;
            }
        }
        vero_redirect();
    }
}

/* ── Dados ──────────────────────────────────────────────────── */
$grupos = vero_rows(
    "SELECT g.*,
            (SELECT COUNT(*) FROM estoque_produtos p
              WHERE p.tenant_id = g.tenant_id AND p.grupo_id = g.id) AS produtos
       FROM estoque_grupos g
      WHERE g.tenant_id = :t ORDER BY g.ativo DESC, g.nome", [':t' => vero_tenant()]);
$subgrupos = vero_rows(
    "SELECT s.*,
            (SELECT COUNT(*) FROM estoque_produtos p
              WHERE p.tenant_id = s.tenant_id AND p.subgrupo_id = s.id) AS produtos
       FROM estoque_subgrupos s
      WHERE s.tenant_id = :t ORDER BY s.nome", [':t' => vero_tenant()]);
$subsPorGrupo = [];
foreach ($subgrupos as $s) $subsPorGrupo[(int)$s['grupo_id']][] = $s;

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM estoque_grupos WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'estoque', 'micro' => 'grupos_subgrupos'];
$PAGE_VIEW  = 'estoque_grupos_subgrupos';
$PAGE_TITLE = 'Grupos e Subgrupos';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar  = vero_can('estoque.grupos_subgrupos.editar');
$podeExcluir = vero_can('estoque.grupos_subgrupos.excluir');
$plural = static fn(int $n): string => $n === 1 ? '1 produto' : $n . ' produtos';
?>
<style>
.vg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:14px;align-items:start}
.vg-card{background:#fff;border:1px solid #E7E0D2;border-radius:14px;padding:15px 17px 14px;
  box-shadow:0 1px 3px rgba(43,32,24,.05);transition:box-shadow .16s,border-color .16s}
.vg-card:hover{box-shadow:0 8px 22px -14px rgba(43,32,24,.35);border-color:#DDD3BF}
.vg-card--off{background:#FBF9F4;opacity:.72}
.vg-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.vg-name{margin:0;font:700 16px 'IBM Plex Sans',sans-serif;color:#1E1610;word-break:break-word;line-height:1.25}
.vg-meta{display:flex;align-items:center;flex-wrap:wrap;gap:5px 9px;margin-top:6px;font-size:12px;color:#8A7D6E}
.vg-meta .vg-type{color:#00575F;font-weight:700;letter-spacing:.02em}
.vg-meta .vg-st{display:inline-flex;align-items:center;gap:5px}
.vg-meta .dot{width:7px;height:7px;border-radius:50%}
.vg-meta .dot--on{background:#2E8B3D}.vg-meta .dot--off{background:#B9A98F}
.vg-actions{display:flex;align-items:center;gap:2px;flex:none}
.vg-subs{display:flex;flex-wrap:wrap;gap:7px;margin-top:14px}
.vg-chip{display:inline-flex;align-items:center;gap:6px;background:#F4F1E8;border:1px solid #E4DECF;
  border-radius:8px;padding:4px 5px 4px 11px;font-size:12.5px;color:#3A342A}
.vg-chip b{font-weight:600}
.vg-chip__n{color:#A0917C;font-size:11px}
.vg-chip__del{border:0;background:none;cursor:pointer;color:#B4A791;font-weight:700;font-size:15px;line-height:1;padding:0 3px;border-radius:5px}
.vg-chip__del:hover{color:#9A3B2A;background:#F0E4E0}
.vg-empty-subs{color:#AB9C86;font-size:12px;font-style:italic;margin-top:13px;display:block}
.vg-add{margin-top:13px}
.vg-add__toggle{background:none;border:1px dashed #D5CEBF;color:#8A7D6E;border-radius:8px;
  padding:5px 12px;font:600 12px 'IBM Plex Sans';cursor:pointer;transition:.14s}
.vg-add__toggle:hover{border-color:#00575F;color:#00575F;background:#F7FAFA}
.vg-add__form{display:none;gap:7px}
.vg-add.open .vg-add__form{display:flex}
.vg-add.open .vg-add__toggle{display:none}
.vg-add__form input{flex:1;min-width:0;border:1px solid #D5CEBF;border-radius:8px;padding:7px 10px;font:13px 'IBM Plex Sans';background:#fff}
.vg-add__form input:focus{outline:2px solid #00575F33;border-color:#00575F}
</style>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Grupos e Subgrupos', 'Organização do catálogo de produtos do estoque',
        $podeEditar ? '+ Novo grupo' : null) ?>

  <?php if (!$grupos): ?>
    <div class="vcard"><div class="vempty">Nenhum grupo cadastrado ainda.</div></div>
  <?php else: ?>
    <div class="vg-grid">
    <?php foreach ($grupos as $g):
        $gid = (int)$g['id']; $ativo = (int)$g['ativo'] === 1; $nprod = (int)$g['produtos'];
        $subs = $subsPorGrupo[$gid] ?? []; ?>
    <section class="vg-card<?= $ativo ? '' : ' vg-card--off' ?>">
      <div class="vg-top">
        <div style="min-width:0">
          <h2 class="vg-name"><?= h($g['nome']) ?></h2>
          <div class="vg-meta">
            <span class="vg-type"><?= h(TIPOS_GRUPO[$g['tipo']] ?? $g['tipo']) ?></span>
            <span class="vg-st"><span class="dot dot--<?= $ativo ? 'on' : 'off' ?>"></span><?= $ativo ? 'Ativo' : 'Inativo' ?></span>
            <span><?= h($plural($nprod)) ?></span>
          </div>
        </div>
        <div class="vg-actions">
          <?php if ($nprod > 0): ?><?= vero_btn_icone(vero_ico_olho(), 'Ver produtos', '', ($base ?? '') . 'produtos.php?grupo_id=' . $gid) ?><?php endif; ?>
          <?php if ($podeEditar): ?>
            <?= vero_btn_editar($gid) ?>
            <?php if ($podeExcluir && $ativo): ?>
              <form method="post" style="display:inline" data-confirm="Inativar o grupo '<?= h($g['nome']) ?>'?" data-confirm-danger data-confirm-ok="Inativar" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="excluir_grupo">
                <input type="hidden" name="id" value="<?= $gid ?>">
                <button class="vicon vicon-acao" type="submit" title="Inativar grupo" aria-label="Inativar grupo"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 12h4"/></svg></button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$subs): ?>
        <span class="vg-empty-subs">Nenhum subgrupo ainda.</span>
      <?php else: ?>
        <div class="vg-subs">
          <?php foreach ($subs as $s): ?>
            <span class="vg-chip"><b><?= h($s['nome']) ?></b> <span class="vg-chip__n"><?= h($plural((int)$s['produtos'])) ?></span>
              <?php if ($podeExcluir): ?><form method="post" style="display:inline" data-confirm="Excluir o subgrupo '<?= h($s['nome']) ?>'?" data-confirm-danger data-confirm-ok="Excluir" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))"><input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>"><input type="hidden" name="acao" value="excluir_subgrupo"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="vg-chip__del" type="submit" aria-label="Excluir subgrupo <?= h($s['nome']) ?>" title="Excluir">×</button></form><?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($podeEditar): ?>
      <div class="vg-add">
        <button type="button" class="vg-add__toggle" onclick="var a=this.closest('.vg-add');a.classList.add('open');a.querySelector('input').focus()">+ subgrupo</button>
        <form class="vg-add__form" method="post">
          <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
          <input type="hidden" name="acao" value="salvar_subgrupo">
          <input type="hidden" name="grupo_id" value="<?= $gid ?>">
          <input type="text" name="nome_sub" placeholder="Nome do subgrupo" required maxlength="120" aria-label="Nome do novo subgrupo em <?= h($g['nome']) ?>">
          <button class="vbtn vbtn-primary vbtn-sm" type="submit">Adicionar</button>
        </form>
      </div>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar grupo' : 'Novo grupo' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar_grupo">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome do grupo', $edit['nome'] ?? '', true, 'Ex.: Fertilizantes, Defensivos') ?>
        <?= vero_f_select('tipo', 'Tipo', TIPOS_GRUPO, $edit['tipo'] ?? 'insumo', true, '') ?>
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
