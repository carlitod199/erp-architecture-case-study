<?php
/* ============================================================
   VERO — Irrigação / Bombas  (CRUD real)
   Rota da matriz: /agro/bombas.php (micro irrigacao.bombas — A0-07)
   Guard: irrigacao.bombas | Escrita: irrigacao.bombas.editar/excluir
   Tabelas: agro_bombas + agro_bomba_valvulas (DB-31 / migration 135).
   Cadastro das bombas de irrigação e do vínculo N:N com as
   válvulas que cada bomba atende — base da IF (fertirrigação):
   o documento IF referencia a bomba usada (A1-28).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'agro_bombas';

$t = vero_tenant();

/** Sincroniza o N:N bomba×válvulas (tabela SEM auditoria → PDO direto, regra 4).
    Só aceita válvulas ATIVAS da MESMA fazenda da bomba. */
function bomba_sync_valvulas(int $bombaId, int $fazendaId, array $setorIds): void
{
    $t = vero_tenant();
    $pdo = vero_pdo();
    $pdo->prepare("DELETE FROM agro_bomba_valvulas WHERE tenant_id=? AND bomba_id=?")
        ->execute([$t, $bombaId]);
    if (!$setorIds) return;
    $ins = $pdo->prepare(
        "INSERT INTO agro_bomba_valvulas (tenant_id, bomba_id, setor_id) VALUES (?,?,?)");
    foreach (array_unique(array_map('intval', $setorIds)) as $sid) {
        if ($sid <= 0) continue;
        $ok = vero_val(
            "SELECT s.id FROM agro_setores s
              WHERE s.id=:i AND s.tenant_id=:t AND s.ativo=1
                AND COALESCE(s.fazenda_id, (SELECT t2.fazenda_id FROM agro_talhoes t2 WHERE t2.id = s.talhao_id)) = :f",
            [':i' => $sid, ':t' => $t, ':f' => $fazendaId]);
        if ($ok) $ins->execute([$t, $bombaId, $sid]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('irrigacao.bombas.editar');

        $id        = vero_int('id');
        $fazendaId = vero_int('fazenda_id');
        $nome      = vero_str('nome', 80);
        if (!$fazendaId || $nome === null) {
            vero_flash('erro', 'Fazenda e nome da bomba são obrigatórios.');
            vero_redirect();
        }
        $okFaz = vero_val("SELECT id FROM agro_fazendas WHERE id=:f AND tenant_id=:t",
            [':f' => $fazendaId, ':t' => $t]);
        if (!$okFaz) {
            vero_flash('erro', 'Fazenda inválida.');
            vero_redirect();
        }
        $dup = vero_val(
            "SELECT id FROM " . T . " WHERE tenant_id=:t AND fazenda_id=:f AND nome=:n AND ativo=1 AND id<>:id",
            [':t' => $t, ':f' => $fazendaId, ':n' => $nome, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe a bomba \"{$nome}\" nesta fazenda.");
            vero_redirect();
        }

        /* A11: vazão e potência são grandezas físicas — nunca negativas */
        $vazao    = vero_dec('vazao_m3h');
        $potencia = vero_dec('potencia_kw');
        if (($vazao !== null && $vazao < 0) || ($potencia !== null && $potencia < 0)) {
            vero_flash('erro', 'Vazão e potência não podem ser negativas.');
            vero_redirect();
        }

        $dados = [
            'fazenda_id' => $fazendaId,
            'nome'       => $nome,
            'codigo'     => vero_str('codigo', 20),
            'vazao_m3h'  => $vazao,
            'potencia_kw'=> $potencia,
            'ativo'      => vero_int('ativo') ?? 1,
        ];
        if ($id) {
            vero_update(T, $id, $dados);
            vero_flash('ok', "Bomba \"{$nome}\" atualizada.");
        } else {
            $id = vero_insert(T, $dados);
            vero_flash('ok', "Bomba \"{$nome}\" cadastrada.");
        }
        bomba_sync_valvulas((int)$id, $fazendaId, (array)($_POST['valvulas'] ?? []));
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('irrigacao.bombas.excluir');
        $id = vero_int('id');
        if ($id) {
            $uso = (int)vero_val(
                "SELECT COUNT(*) FROM agro_aplicacoes WHERE tenant_id=:t AND bomba_id=:b AND status <> 'cancelada'",
                [':t' => $t, ':b' => $id]);
            $nome = (string)vero_val("SELECT nome FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => $t]);
            vero_update(T, $id, ['ativo' => 0]); // soft delete (mesmo efeito do vero_delete p/ tabela com 'ativo')
            if ($uso > 0) {
                vero_flash('aviso', "Bomba \"{$nome}\" inativada — ela é referenciada por {$uso} documento(s) IF (histórico preservado) e deixa de aparecer nos vínculos e selects.");
            } else {
                vero_flash('ok', "Bomba \"{$nome}\" inativada — ela deixa de aparecer nos vínculos e selects.");
            }
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$fFazenda = (int)($_GET['fazenda'] ?? 0);
$where  = "b.tenant_id = :t";
$params = [':t' => $t];
if ($fFazenda > 0) { $where .= " AND b.fazenda_id = :f"; $params[':f'] = $fFazenda; }

$rows = vero_rows(
    "SELECT b.*, f.nome AS fazenda_nome,
            (SELECT COUNT(*) FROM agro_bomba_valvulas bv
              WHERE bv.tenant_id = b.tenant_id AND bv.bomba_id = b.id)          AS valvulas,
            (SELECT GROUP_CONCAT(s.codigo ORDER BY s.codigo SEPARATOR ', ')
               FROM agro_bomba_valvulas bv2
               JOIN agro_setores s ON s.id = bv2.setor_id
              WHERE bv2.tenant_id = b.tenant_id AND bv2.bomba_id = b.id)        AS valvulas_lista
       FROM " . T . " b
       JOIN agro_fazendas f ON f.id = b.fazenda_id
      WHERE {$where}
      ORDER BY b.ativo DESC, f.nome, b.nome",
    $params);

$fazendas = vero_options('agro_fazendas', 'nome', 'ativo = 1');

/* válvulas ativas por fazenda (checkboxes do modal) */
$valvulas = vero_rows(
    "SELECT s.id, COALESCE(s.fazenda_id, t2.fazenda_id) AS fazenda_id,
            s.codigo, s.nome, t2.codigo AS talhao
       FROM agro_setores s
       LEFT JOIN agro_talhoes t2 ON t2.id = s.talhao_id
      WHERE s.tenant_id = :t AND s.ativo = 1
      ORDER BY s.codigo", [':t' => $t]);

$edit = null;
$editValvulas = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
        [':i' => (int)$_GET['editar'], ':t' => $t]);
    if ($edit) {
        $editValvulas = array_map('intval', array_column(vero_rows(
            "SELECT setor_id FROM agro_bomba_valvulas WHERE tenant_id=:t AND bomba_id=:b",
            [':t' => $t, ':b' => (int)$edit['id']]), 'setor_id'));
    }
}

$GUARD      = ['macro' => 'irrigacao', 'micro' => 'bombas'];
$PAGE_VIEW  = 'irrigacao_bombas';
$PAGE_TITLE = 'Bombas de Irrigação';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('irrigacao.bombas.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Bombas de Irrigação', 'Bombas por fazenda e as válvulas que cada uma atende — base do documento IF (fertirrigação)',
        $podeEditar ? '+ Nova bomba' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <select name="fazenda" onchange="this.form.submit()">
          <option value="">Todas as fazendas</option>
          <?php foreach ($fazendas as $fid => $fn): ?>
            <option value="<?= $fid ?>"<?= $fFazenda === $fid ? ' selected' : '' ?>><?= h($fn) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= count($rows) ?> bomba(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma bomba cadastrada. <?= $podeEditar ? 'Cadastre e vincule as válvulas que ela atende.' : '' ?></div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Bomba</th><th>Fazenda</th>
        <th style="text-align:right">Vazão (m³/h)</th>
        <th style="text-align:right">Potência (kW)</th>
        <th>Válvulas atendidas</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong>
              <?= $r['codigo'] ? ' <span class="vhint vnum">' . h((string)$r['codigo']) . '</span>' : '' ?></td>
          <td><?= h($r['fazenda_nome']) ?></td>
          <td class="vnum" style="text-align:right"><?= $r['vazao_m3h'] !== null ? numFmt((float)$r['vazao_m3h'], 1) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $r['potencia_kw'] !== null ? numFmt((float)$r['potencia_kw'], 1) : '—' ?></td>
          <td><?= (int)$r['valvulas'] > 0
                ? '<span class="vbadge vb-info">' . (int)$r['valvulas'] . '</span> <span class="vhint">' . h((string)$r['valvulas_lista']) . '</span>'
                : '<span class="vhint" style="color:#b3261e">nenhuma ⚠</span>' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('irrigacao.bombas.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar esta bomba?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      O documento IF sugere as bombas vinculadas às válvulas da aplicação; bomba sem válvula não aparece na sugestão.
    </div>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox" style="max-width:640px">
    <header>
      <h2><?= $edit ? 'Editar bomba' : 'Nova bomba' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_select('fazenda_id', 'Fazenda', $fazendas, $edit['fazenda_id'] ?? ($fFazenda ?: null), true) ?></div>
        <?= vero_f_text('nome', 'Nome da bomba', $edit['nome'] ?? '', true, 'Ex.: Bomba 01 — casa de bombas norte') ?>
        <?= vero_f_text('codigo', 'Código (opcional)', $edit['codigo'] ?? '') ?>
        <?= vero_f_text('vazao_m3h', 'Vazão (m³/h)', $edit && $edit['vazao_m3h'] !== null ? numFmt((float)$edit['vazao_m3h'], 1) : '') ?>
        <?= vero_f_text('potencia_kw', 'Potência (kW)', $edit && $edit['potencia_kw'] !== null ? numFmt((float)$edit['potencia_kw'], 1) : '') ?>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vfield" style="margin-top:10px">
        <label>Válvulas atendidas pela bomba</label>
        <div id="bomba-valvulas" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px;max-height:220px;overflow:auto;border:1px solid #EEE8DB;border-radius:8px;padding:10px">
          <?php foreach ($valvulas as $v): ?>
            <label style="display:flex;gap:6px;align-items:center;font-weight:400" data-fazenda="<?= (int)$v['fazenda_id'] ?>">
              <input type="checkbox" name="valvulas[]" value="<?= (int)$v['id'] ?>"
                <?= in_array((int)$v['id'], $editValvulas, true) ? ' checked' : '' ?>>
              <span><?= h($v['codigo'] ?? $v['nome'] ?? ('#' . $v['id'])) ?><?= $v['talhao'] ? ' <span class="vhint">(' . h((string)$v['talhao']) . ')</span>' : '' ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="vhint">A lista filtra pela fazenda selecionada. Válvulas de outra fazenda ficam ocultas (e desmarcadas ao salvar).</div>
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
  var sel = document.querySelector('#vm-form select[name="fazenda_id"]');
  if (!sel) return;
  function filtra() {
    var f = sel.value;
    document.querySelectorAll('#bomba-valvulas label').forEach(function (l) {
      var mostra = f !== '' && l.dataset.fazenda === f;
      l.style.display = mostra ? '' : 'none';
      if (!mostra) { var cb = l.querySelector('input'); if (cb) cb.checked = cb.checked && mostra; }
    });
  }
  sel.addEventListener('change', filtra);
  filtra();
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
