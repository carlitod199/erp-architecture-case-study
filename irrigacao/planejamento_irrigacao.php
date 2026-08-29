<?php
/* ============================================================
   VERO — Irrigação / Planejamento de Irrigação  (CRUD real)
   Substitui o mock. Rota: /irrigacao/planejamento_irrigacao.php
   Guard: irrigacao.planejamento_irrigacao
   Planejamentos por válvula (irrigacao_planejamentos): lâmina-alvo
   e período. O confronto com o apontado fica em Planejado vs
   Realizado. Lâmina é definida pelo responsável — o sistema não
   recomenda valores.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'irrigacao_planejamentos';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('irrigacao.planejamento_irrigacao.editar');
        $id     = vero_int('id');
        $talhao = vero_int('talhao_id');
        $lamina = vero_dec('lamina_mm');
        $ini    = vero_date('data_inicio');
        $fim    = vero_date('data_fim');
        if (!$talhao || $lamina === null || $lamina <= 0 || $ini === null) {
            vero_flash('erro', 'Válvula, lâmina (maior que zero) e início são obrigatórios.');
            vero_redirect();
        }
        if ($fim !== null && $fim < $ini) {
            vero_flash('erro', 'O fim do período não pode ser antes do início.');
            vero_redirect();
        }
        /* X-08: horas de irrigação + vazão (trava por m³ do Vale =
           vazão × horas). Vazão sugerida da bomba, editável. Nunca negativos (A11). */
        $horas = vero_dec('horas_irrigacao');
        $vazao = vero_dec('vazao_m3h');
        if (($horas !== null && $horas < 0) || ($vazao !== null && $vazao < 0)) {
            vero_flash('erro', 'Horas de irrigação e vazão não podem ser negativas.');
            vero_redirect();
        }
        $data = [
            'talhao_id'   => (int)$talhao,
            'pivo_id'     => vero_int('pivo_id') ?: null,
            'lamina_mm'   => $lamina,
            'horas_irrigacao' => $horas,
            'vazao_m3h'   => $vazao,
            'data_inicio' => $ini,
            'data_fim'    => $fim,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', 'Planejamento atualizado.'); }
        else     { vero_insert(T, $data);      vero_flash('ok', 'Planejamento criado.'); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('irrigacao.planejamento_irrigacao.excluir');
        $id = vero_int('id');
        if ($id) {
            vero_pdo()->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")->execute([$t, (int)$id]);
            vero_flash('ok', 'Planejamento excluído.');
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT p.*, tl.codigo AS talhao, fz.nome AS fazenda, pv.nome AS pivo,
            (SELECT COALESCE(SUM(a.lamina_mm),0) FROM irrigacao_apontamentos a
              WHERE a.tenant_id = p.tenant_id AND a.talhao_id = p.talhao_id
                AND a.data_apontamento >= p.data_inicio
                AND (p.data_fim IS NULL OR a.data_apontamento <= p.data_fim)) AS lamina_apontada
       FROM " . T . " p
       LEFT JOIN agro_talhoes tl ON tl.id = p.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
       LEFT JOIN agro_pivos pv ON pv.id = p.pivo_id
      WHERE p.tenant_id = :t
      ORDER BY p.data_inicio DESC, p.id DESC", [':t' => $t]);

$talhoes = vero_rows(
    "SELECT t.id, t.codigo, f.nome AS fazenda FROM agro_talhoes t
      LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
     WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo", [':t' => $t]);
$talhaoOpts = [];
foreach ($talhoes as $tl) $talhaoOpts[(int)$tl['id']] = ($tl['fazenda'] ? $tl['fazenda'] . ' — ' : '') . $tl['codigo'];
$pivoOpts = vero_options('agro_pivos', 'nome');

/* X-08: vazão (m³/h) da bomba por válvula — agro_setores → agro_bomba_valvulas
   → agro_bombas.vazao_m3h. Vira mapa JS p/ sugerir a vazão ao escolher a válvula. */
$vazaoPorValvula = [];
foreach (vero_rows(
    "SELECT s.talhao_id, MAX(b.vazao_m3h) AS vazao
       FROM agro_setores s
       JOIN agro_bomba_valvulas bv ON bv.setor_id = s.id AND bv.tenant_id = s.tenant_id
       JOIN agro_bombas b ON b.id = bv.bomba_id AND b.tenant_id = s.tenant_id
      WHERE s.tenant_id = :t AND b.vazao_m3h IS NOT NULL
      GROUP BY s.talhao_id", [':t' => $t]) as $vz) {
    $vazaoPorValvula[(int)$vz['talhao_id']] = (float)$vz['vazao'];
}

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t", [':id' => (int)$_GET['editar'], ':t' => $t]);
}

$GUARD      = ['macro' => 'irrigacao', 'micro' => 'planejamento_irrigacao'];
$PAGE_VIEW  = 'irrigacao_planejamento_irrigacao';
$PAGE_TITLE = 'Planejamento de Irrigação';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('irrigacao.planejamento_irrigacao.editar');
$base = rtrim(BIOS_BASE, '/');
$hoje = date('Y-m-d');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Planejamento de Irrigação', 'Lâmina-alvo por válvula e período — confronto em Planejado vs Realizado',
        $podeEditar ? '+ Novo planejamento' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <span class="vsub"><?= count($rows) ?> planejamento(s)</span>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/irrigacao/planejado_realizado.php">Planejado vs Realizado</a>
    </div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum planejamento de irrigação.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Válvula</th><th>Período</th>
        <th class="num">Lâmina-alvo (mm)</th>
        <th class="num">Apontada (mm)</th>
        <th class="num">Horas · Vazão · m³</th>
        <th>Situação</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $vigente = $r['data_inicio'] <= $hoje && ($r['data_fim'] === null || $r['data_fim'] >= $hoje);
          $pct = (float)$r['lamina_mm'] > 0 ? (float)$r['lamina_apontada'] / (float)$r['lamina_mm'] * 100 : 0; ?>
        <tr>
          <td><strong><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></strong></td>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_inicio'])) ?>
            <?= $r['data_fim'] ? '– ' . date('d/m/Y', strtotime((string)$r['data_fim'])) : '<span class="vhint">em aberto</span>' ?></td>
          <td class="num"><strong><?= numFmt((float)$r['lamina_mm'], 1) ?></strong></td>
          <td class="num"><?= numFmt((float)$r['lamina_apontada'], 1) ?>
            <span class="vhint">(<?= numFmt($pct, 0) ?>%)</span></td>
          <?php
            $hh = ($r['horas_irrigacao'] ?? null) !== null ? (float)$r['horas_irrigacao'] : null;
            $vv = ($r['vazao_m3h'] ?? null) !== null ? (float)$r['vazao_m3h'] : null;
            $m3 = ($hh !== null && $vv !== null) ? $hh * $vv : null;
          ?>
          <td class="num"><?= $hh !== null ? numFmt($hh, 1) . ' h' : '<span class="vhint">—</span>' ?>
            <?= $vv !== null ? ' · ' . numFmt($vv, 0) . ' m³/h' : '' ?>
            <?= $m3 !== null ? '<br><strong>' . numFmt($m3, 0) . ' m³</strong>' : '' ?></td>
          <td><?= $vigente ? '<span class="vbadge vb-ok">Vigente</span>'
                : ($r['data_inicio'] > $hoje ? '<span class="vbadge vb-info">Futuro</span>'
                : '<span class="vbadge vb-off">Encerrado</span>') ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('irrigacao.planejamento_irrigacao.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este planejamento?') ?>
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
      <h2><?= $edit ? 'Editar planejamento' : 'Novo planejamento' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_select('talhao_id', 'Válvula', $talhaoOpts, $edit['talhao_id'] ?? '', true, 'Selecione…') ?>
        <?php /* X-06/X-08: Pivô/Sistema removido — a cultura é
                 latada (sem pivô). Coluna pivo_id preservada no banco. */ ?>
        <?= vero_f_text('lamina_mm', 'Lâmina-alvo (mm)', $edit ? numFmt((float)$edit['lamina_mm'], 1) : '', true,
            'Definida pelo responsável pelo manejo') ?>
        <?php /* X-08: horas + vazão (trava por m³ do Vale = vazão × horas) */ ?>
        <div class="vfield">
          <label>Horas de irrigação</label>
          <input type="text" name="horas_irrigacao" id="pi-horas" placeholder="0,0"
                 value="<?= $edit && ($edit['horas_irrigacao'] ?? null) !== null ? numFmt((float)$edit['horas_irrigacao'], 1) : '' ?>">
        </div>
        <div class="vfield">
          <label>Vazão (m³/h) <span class="vhint">sugerida da bomba</span></label>
          <input type="text" name="vazao_m3h" id="pi-vazao" placeholder="da bomba da válvula"
                 value="<?= $edit && ($edit['vazao_m3h'] ?? null) !== null ? numFmt((float)$edit['vazao_m3h'], 2) : '' ?>">
          <div class="vhint" id="pi-m3"></div>
        </div>
        <div class="vfield">
          <label>Início *</label>
          <input type="date" name="data_inicio" value="<?= h($edit['data_inicio'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="vfield">
          <label>Fim</label>
          <input type="date" name="data_fim" value="<?= h($edit['data_fim'] ?? '') ?>">
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

<?php if ($podeEditar): ?>
<script>
/* X-08: ao escolher a válvula, sugere a vazão da bomba; horas × vazão = m³ (trava). */
(function () {
  var VAZAO = <?= jsvar($vazaoPorValvula) ?>;
  var val = document.querySelector('#vm-form [name="talhao_id"]');
  var vaz = document.getElementById('pi-vazao');
  var hor = document.getElementById('pi-horas');
  var out = document.getElementById('pi-m3');
  if (!val || !vaz || !hor) return;
  function dec(s) { s = String(s == null ? '' : s).trim(); if (s === '') return null; if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.'); var n = parseFloat(s); return isNaN(n) ? null : n; }
  function m3() {
    var v = dec(vaz.value), h = dec(hor.value);
    if (out) out.textContent = (v !== null && h !== null) ? ('Volume ≈ ' + Math.round(v * h) + ' m³ (vazão × horas)') : '';
  }
  val.addEventListener('change', function () {
    var v = VAZAO[val.value];
    if (v != null && vaz.value.trim() === '') vaz.value = String(v).replace('.', ',');
    m3();
  });
  vaz.addEventListener('input', m3);
  hor.addEventListener('input', m3);
  m3();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
