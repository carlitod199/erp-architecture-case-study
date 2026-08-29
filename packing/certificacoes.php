<?php
/* ============================================================
   VERO — Packing House / Certificações  (CRUD real)
   Rota: /packing/certificacoes.php · Guard: packing.certificacoes
   Tabela: ph_certificacoes (migration_198). Escopo polimórfico
   (unidade | fazenda | produtor) — o alvo concreto vive em escopo_id
   e é validado contra o tenant conforme o escopo.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'ph_certificacoes';

/* Categóricos = VARCHAR + whitelist em PHP (convenção do projeto, NUNCA ENUM). */
const PH_CERT_ESCOPOS = ['unidade' => 'Unidade', 'fazenda' => 'Fazenda', 'produtor' => 'Produtor'];
const PH_CERT_NORMAS  = [
    'GLOBALGAP'  => 'GLOBALG.A.P.',
    'GRASP'      => 'GRASP',
    'RAINFOREST' => 'Rainforest Alliance',
    'BRCGS'      => 'BRCGS',
    'IFS'        => 'IFS',
];

/* Unidades de packing (almoxarifados tipo='packing') do tenant: id => nome. */
function ph_cert_unidades(): array
{
    $out = [];
    foreach (vero_rows(
        "SELECT id, nome FROM almoxarifados
          WHERE tenant_id = :t AND tipo = 'packing' AND ativo = 1
          ORDER BY nome", [':t' => vero_tenant()]) as $r) {
        $out[(int)$r['id']] = (string)$r['nome'];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('packing.certificacoes.editar');

        $id     = vero_int('id');
        $escopo = vero_str('escopo', 20);
        $norma  = vero_str('norma', 40);
        $numero = vero_str('numero', 60);

        if ($escopo === null || !isset(PH_CERT_ESCOPOS[$escopo])) {
            vero_flash('erro', 'Escopo inválido.');
            vero_redirect();
        }
        if ($norma === null || !isset(PH_CERT_NORMAS[$norma])) {
            vero_flash('erro', 'Norma inválida.');
            vero_redirect();
        }
        if ($numero === null) {
            vero_flash('erro', 'O número/identificador da certificação é obrigatório.');
            vero_redirect();
        }

        /* escopo_id é polimórfico: valida contra o tenant conforme o escopo.
           - unidade → almoxarifado tipo packing deste tenant
           - fazenda → agro_fazendas deste tenant
           - produtor → sem tabela consolidada no MVP (referência solta) */
        $escopoId = vero_int('escopo_id');
        if ($escopo === 'unidade') {
            $escopoId = $escopoId
                ? (int)vero_val("SELECT id FROM almoxarifados
                                  WHERE id = :i AND tenant_id = :t AND tipo = 'packing'",
                    [':i' => $escopoId, ':t' => vero_tenant()]) ?: null
                : null;
        } elseif ($escopo === 'fazenda') {
            $escopoId = vero_fk_tenant('agro_fazendas', $escopoId);
        } else { // produtor
            $escopoId = $escopoId ?: null;
        }

        /* Guarda anti-duplicidade (não há UNIQUE): mesma norma+número ativo. */
        $dup = vero_val(
            "SELECT id FROM " . T . " WHERE tenant_id = :t AND norma = :n AND numero = :num
                                        AND ativo = 1 AND id <> :id",
            [':t' => vero_tenant(), ':n' => $norma, ':num' => $numero, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe uma certificação ativa {$norma} nº \"{$numero}\".");
            vero_redirect();
        }

        $data = [
            'escopo'    => $escopo,
            'escopo_id' => $escopoId,
            'norma'     => $norma,
            'edicao'    => vero_str('edicao', 20),
            'numero'    => $numero,
            'validade'  => vero_date('validade'),
            'organismo' => vero_str('organismo', 120),
            'ativo'     => vero_int('ativo') ?? 1,
        ];

        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Certificação {$norma} atualizada."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Certificação {$norma} cadastrada."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('packing.certificacoes.editar');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Opções do escopo (para o select dependente via JS) ──────────── */
$optsUnidade = ph_cert_unidades();
$optsFazenda = vero_options('agro_fazendas', 'nome', 'ativo = 1');
$escopoOpts  = [
    'unidade'  => $optsUnidade,
    'fazenda'  => $optsFazenda,
    'produtor' => new stdClass(), // sem tabela no MVP → objeto vazio no JSON
];

/* ── Listagem ───────────────────────────────────────────────────── */
$rows = vero_rows(
    "SELECT c.*,
            CASE c.escopo
              WHEN 'unidade' THEN (SELECT a.nome FROM almoxarifados a
                                    WHERE a.id = c.escopo_id AND a.tenant_id = c.tenant_id)
              WHEN 'fazenda' THEN (SELECT f.nome FROM agro_fazendas f
                                    WHERE f.id = c.escopo_id AND f.tenant_id = c.tenant_id)
              ELSE NULL
            END AS escopo_nome
       FROM " . T . " c
      WHERE c.tenant_id = :t
      ORDER BY c.ativo DESC, c.norma, c.numero", [':t' => vero_tenant()]);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id = :id AND tenant_id = :t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'packing', 'micro' => 'certificacoes'];
$PAGE_VIEW  = 'packing_certificacoes';
$PAGE_TITLE = 'Certificações';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('packing.certificacoes.editar');
$hoje       = date('Y-m-d');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <header class="vero-topbar">
    <h1 class="vero-topbar__title">Certificações</h1>
    <div class="vero-topbar__actions">
      <?php if ($podeEditar): ?><?= vero_btn_icone('<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>', 'Nova certificação', "vModalNovo('vm-form')") ?><?php endif; ?>
    </div>
  </header>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma certificação cadastrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Norma</th><th>Número</th><th>Edição</th>
        <th>Escopo</th><th>Organismo</th><th>Validade</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $venc = $r['validade'] !== null && $r['validade'] < $hoje; ?>
        <tr>
          <td><strong><?= h(PH_CERT_NORMAS[$r['norma']] ?? $r['norma']) ?></strong></td>
          <td class="vnum"><?= h($r['numero']) ?></td>
          <td class="vhint"><?= h($r['edicao'] ?? '') ?: '—' ?></td>
          <td>
            <?= h(PH_CERT_ESCOPOS[$r['escopo']] ?? $r['escopo']) ?>
            <?php if ($r['escopo_nome']): ?><span class="vhint"> · <?= h($r['escopo_nome']) ?></span><?php endif; ?>
          </td>
          <td class="vhint"><?= h($r['organismo'] ?? '') ?: '—' ?></td>
          <td>
            <?php if ($r['validade'] !== null): ?>
              <span<?= $venc && (int)$r['ativo'] === 1 ? ' class="vbadge vb-off"' : '' ?>><?= h(date('d/m/Y', strtotime((string)$r['validade']))) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if ($podeEditar && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar esta certificação?', 'Inativar') ?>
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
      <h2><?= $edit ? 'Editar certificação' : 'Nova certificação' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_select('norma', 'Norma', PH_CERT_NORMAS, $edit['norma'] ?? null, true, '— Selecione —') ?>
        <?= vero_f_text('numero', 'Número / identificador', $edit['numero'] ?? '', true, 'Ex.: 4049929123456') ?>
        <?= vero_f_select('escopo', 'Escopo', PH_CERT_ESCOPOS, $edit['escopo'] ?? 'unidade', true, '') ?>
        <div class="vfield">
          <label>Alvo do escopo</label>
          <select name="escopo_id" id="cert-escopo-id" data-selected="<?= $edit ? (int)$edit['escopo_id'] : '' ?>">
            <option value="">— Não vinculado —</option>
          </select>
          <div class="vhint">Unidade de packing, fazenda ou produtor, conforme o escopo.</div>
        </div>
        <?= vero_f_text('edicao', 'Edição (opcional)', $edit['edicao'] ?? '', false, 'Ex.: v6, 2023') ?>
        <?= vero_f_text('organismo', 'Organismo certificador (opcional)', $edit['organismo'] ?? '', false, 'Ex.: SGS, Bureau Veritas') ?>
        <?= vero_f_text('validade', 'Validade (opcional)', $edit['validade'] ?? '', false, '', 'date') ?>
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
<script>
(function () {
  var MAP = <?= json_encode($escopoOpts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var escSel = document.querySelector('#vm-form select[name="escopo"]');
  var alvoSel = document.getElementById('cert-escopo-id');
  if (!escSel || !alvoSel) return;
  function rebuild(keep) {
    var esc = escSel.value;
    var opts = (MAP && MAP[esc]) ? MAP[esc] : {};
    var want = keep ? String(alvoSel.getAttribute('data-selected') || '') : '';
    alvoSel.innerHTML = '<option value="">— Não vinculado —</option>';
    for (var id in opts) {
      if (!Object.prototype.hasOwnProperty.call(opts, id)) continue;
      var o = document.createElement('option');
      o.value = id; o.textContent = opts[id];
      if (id === want) o.selected = true;
      alvoSel.appendChild(o);
    }
  }
  escSel.addEventListener('change', function () { rebuild(false); });
  rebuild(true);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
