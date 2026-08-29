<?php
/* ============================================================
   VERO — Máquinas / Horímetro  (tela real)
   Substitui o mock. Rota: /maquinas/horimetro.php
   Guard: maquinas.horimetro
   Leituras de horímetro (maquina_horimetros) com registro manual;
   valida regressão e atualiza horimetro_atual da máquina.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php'; /* alertas de plano preventivo (A2-F2-4) */

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'registrar') {
        vero_require('maquinas.horimetro.editar');
        $maquinaId = vero_int('maquina_id');
        $leitura   = vero_dec('horimetro');
        $dataL     = vero_date('data_leitura') ?? date('Y-m-d');
        $maq = $maquinaId ? vero_row("SELECT * FROM maquinas WHERE id=:i AND tenant_id=:t",
            [':i' => $maquinaId, ':t' => $t]) : null;
        if (!$maq || $leitura === null || $leitura < 0) {
            vero_flash('erro', 'Máquina e leitura válida são obrigatórias.');
            vero_redirect();
        }
        if ($maq['horimetro_atual'] !== null && $leitura < (float)$maq['horimetro_atual']) {
            vero_flash('erro', 'Leitura menor que o horímetro atual (' . numFmt((float)$maq['horimetro_atual'], 1) .
                ' h) — horímetro não regride.');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            vero_insert('maquina_horimetros', [
                'maquina_id' => (int)$maquinaId, 'data_leitura' => $dataL, 'horimetro' => $leitura,
            ]);
            vero_update('maquinas', (int)$maquinaId, ['horimetro_atual' => $leitura]);
            vero_srv_maquina_reemitir_alertas((int)$maquinaId); /* planos preventivos (A2-F2-4) */
            $pdo->commit();
            vero_flash('ok', 'Leitura registrada — horímetro de ' . $maq['nome'] . ' agora em ' . numFmt($leitura, 1) . ' h.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }
}

$fMaquina = (int)($_GET['maquina'] ?? 0);
$where  = "h.tenant_id = :t";
$params = [':t' => $t];
if ($fMaquina > 0) { $where .= " AND h.maquina_id = :m"; $params[':m'] = $fMaquina; }

$rows = vero_rows(
    "SELECT h.*, m.codigo, m.nome FROM maquina_horimetros h
       JOIN maquinas m ON m.id = h.maquina_id
      WHERE {$where}
      ORDER BY h.data_leitura DESC, h.id DESC LIMIT 100", $params);

$maquinas = vero_rows(
    "SELECT id, codigo, nome, horimetro_atual FROM maquinas
      WHERE tenant_id = :t AND ativo = 1 ORDER BY codigo", [':t' => $t]);

$GUARD      = ['macro' => 'maquinas', 'micro' => 'horimetro'];
$PAGE_VIEW  = 'maquinas_horimetro';
$PAGE_TITLE = 'Horímetro';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('maquinas.horimetro.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Horímetro', 'Leituras por máquina — abastecimentos também alimentam o horímetro', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Situação atual</strong></div>
    <?php if (!$maquinas): ?>
      <div class="vempty">Nenhuma máquina ativa.</div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;padding:12px 14px">
      <?php foreach ($maquinas as $m): ?>
        <div class="vkpi"><span class="vhint"><?= h($m['codigo'] . ' — ' . $m['nome']) ?></span>
          <strong class="vnum" style="font-size:1.2rem">
            <?= $m['horimetro_atual'] !== null ? numFmt((float)$m['horimetro_atual'], 1) . ' h' : 'sem leitura' ?></strong></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($podeEditar && $maquinas): ?>
    <form class="vform" method="post" style="padding:0 14px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="registrar">
      <div class="vfield"><label>Máquina *</label>
        <select name="maquina_id" required>
          <option value="">Selecione…</option>
          <?php foreach ($maquinas as $m): ?>
            <option value="<?= (int)$m['id'] ?>"><?= h($m['codigo'] . ' — ' . $m['nome']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="vfield"><label>Leitura (h) *</label>
        <input type="text" name="horimetro" placeholder="0,0" required style="text-align:right"></div>
      <div class="vfield"><label>Data</label>
        <input type="date" name="data_leitura" value="<?= date('Y-m-d') ?>"></div>
      <button class="vbtn vbtn-primary" type="submit">Registrar leitura</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <select name="maquina" onchange="this.form.submit()">
          <option value="">Todas as máquinas</option>
          <?php foreach ($maquinas as $m): ?>
            <option value="<?= (int)$m['id'] ?>"<?= $fMaquina === (int)$m['id'] ? ' selected' : '' ?>>
              <?= h($m['codigo'] . ' — ' . $m['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= count($rows) ?> leitura(s)</span>
    </div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma leitura registrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Data</th><th>Máquina</th><th style="text-align:right">Horímetro (h)</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="vnum"><strong><?= date('d/m/Y', strtotime((string)$r['data_leitura'])) ?></strong></td>
          <td><strong class="vnum"><?= h($r['codigo']) ?></strong> <?= h($r['nome']) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['horimetro'], 1) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
