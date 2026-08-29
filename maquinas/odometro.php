<?php
/* ============================================================
   VERO — Máquinas / Odômetro  (tela real)
   Substitui o mock. Rota: /maquinas/odometro.php
   Guard: maquinas.odometro
   Odômetro da frota (veiculos.odometro_atual + máquinas com
   odômetro) com atualização manual e trilha dos abastecimentos
   que informaram km.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'registrar') {
        vero_require('maquinas.odometro.editar');
        /* A2-F2-7 (DB-10): leitura com HISTÓRICO (maquina_odometros), em
           transação, para veículo OU máquina ("bem" = v:ID ou m:ID) */
        $bem = (string)($_POST['bem'] ?? '');
        $leitura = vero_dec('odometro');
        $ehVeiculo = str_starts_with($bem, 'v:');
        $bemId = (int)substr($bem, 2);
        $reg = null;
        if ($bemId && $ehVeiculo) {
            $reg = vero_row("SELECT id, modelo AS rotulo, odometro_atual FROM veiculos WHERE id=:i AND tenant_id=:t",
                [':i' => $bemId, ':t' => $t]);
        } elseif ($bemId) {
            $reg = vero_row("SELECT id, CONCAT(codigo, ' — ', nome) AS rotulo, odometro_atual FROM maquinas WHERE id=:i AND tenant_id=:t",
                [':i' => $bemId, ':t' => $t]);
        }
        if (!$reg || $leitura === null || $leitura < 0) {
            vero_flash('erro', 'Veículo/máquina e leitura válida são obrigatórios.');
            vero_redirect();
        }
        if ($reg['odometro_atual'] !== null && $leitura < (float)$reg['odometro_atual']) {
            vero_flash('erro', 'Leitura menor que o odômetro atual (' . numFmt((float)$reg['odometro_atual'], 0) .
                ' km) — odômetro não regride.');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            vero_insert('maquina_odometros', [
                'veiculo_id'   => $ehVeiculo ? $bemId : null,
                'maquina_id'   => $ehVeiculo ? null : $bemId,
                'data_leitura' => date('Y-m-d'),
                'odometro'     => $leitura,
            ]);
            vero_update($ehVeiculo ? 'veiculos' : 'maquinas', $bemId, ['odometro_atual' => $leitura]);
            $pdo->commit();
            vero_flash('ok', 'Odômetro de ' . ($reg['rotulo'] ?? 'bem') . ' atualizado para ' . numFmt($leitura, 0) . ' km (leitura registrada no histórico).');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }
}

$veiculos = vero_rows(
    "SELECT * FROM veiculos WHERE tenant_id = :t AND ativo = 1 ORDER BY modelo", [':t' => $t]);
$maquinasKm = vero_rows(
    "SELECT id, codigo, nome, odometro_atual FROM maquinas
      WHERE tenant_id = :t AND ativo = 1 ORDER BY codigo", [':t' => $t]);

$trilha = vero_rows(
    "SELECT ab.data_abastecimento, ab.odometro, ab.litros, v.modelo, v.placa, m.nome AS maquina
       FROM maquina_abastecimentos ab
       LEFT JOIN veiculos v ON v.id = ab.veiculo_id
       LEFT JOIN maquinas m ON m.id = ab.maquina_id
      WHERE ab.tenant_id = :t AND ab.odometro IS NOT NULL
      ORDER BY ab.data_abastecimento DESC, ab.id DESC LIMIT 50", [':t' => $t]);

/* A2-F2-7: histórico de leituras manuais */
$historicoOdo = vero_rows(
    "SELECT o.data_leitura, o.odometro, v.modelo, v.placa, m.codigo AS maq_codigo, m.nome AS maq_nome
       FROM maquina_odometros o
       LEFT JOIN veiculos v ON v.id = o.veiculo_id
       LEFT JOIN maquinas m ON m.id = o.maquina_id
      WHERE o.tenant_id = :t
      ORDER BY o.id DESC LIMIT 50", [':t' => $t]);

$GUARD      = ['macro' => 'maquinas', 'micro' => 'odometro'];
$PAGE_VIEW  = 'maquinas_odometro';
$PAGE_TITLE = 'Odômetro';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('maquinas.odometro.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Odômetro', 'Quilometragem da frota — abastecimentos com km alimentam a trilha', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Situação atual</strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/maquinas/veiculos.php">Veículos</a></div>
    <?php if (!$veiculos && !$maquinasKm): ?>
      <div class="vempty">Nenhum veículo ativo — cadastre em Máquinas → Veículos.</div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;padding:12px 14px">
      <?php foreach ($veiculos as $v): ?>
        <div class="vkpi"><span class="vhint"><?= h(($v['modelo'] ?? 'Veículo') . ($v['placa'] ? ' · ' . $v['placa'] : '')) ?></span>
          <strong class="vnum" style="font-size:1.2rem">
            <?= $v['odometro_atual'] !== null ? numFmt((float)$v['odometro_atual'], 0) . ' km' : 'sem leitura' ?></strong></div>
      <?php endforeach; ?>
      <?php foreach ($maquinasKm as $m): if ($m['odometro_atual'] === null || (float)$m['odometro_atual'] <= 0) continue; ?>
        <div class="vkpi"><span class="vhint"><?= h($m['codigo'] . ' — ' . $m['nome']) ?> <span class="vbadge vb-info">máquina</span></span>
          <strong class="vnum" style="font-size:1.2rem"><?= numFmt((float)$m['odometro_atual'], 0) ?> km</strong></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($podeEditar && ($veiculos || $maquinasKm)): ?>
    <form class="vform" method="post" style="padding:0 14px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="registrar">
      <div class="vfield"><label>Veículo / máquina *</label>
        <select name="bem" required>
          <option value="">Selecione…</option>
          <?php foreach ($veiculos as $v): ?>
            <option value="v:<?= (int)$v['id'] ?>"><?= h(($v['modelo'] ?? 'Veículo') . ($v['placa'] ? ' · ' . $v['placa'] : '')) ?></option>
          <?php endforeach; ?>
          <?php foreach ($maquinasKm as $m): ?>
            <option value="m:<?= (int)$m['id'] ?>"><?= h($m['codigo'] . ' — ' . $m['nome']) ?> (máquina)</option>
          <?php endforeach; ?>
        </select></div>
      <div class="vfield"><label>Odômetro (km) *</label>
        <input type="text" name="odometro" placeholder="0" required style="text-align:right"></div>
      <button class="vbtn vbtn-primary" type="submit">Atualizar odômetro</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Histórico de leituras</strong>
      <span class="vsub"><?= count($historicoOdo) ?> leitura(s)</span></div>
    <?php if (!$historicoOdo): ?>
      <div class="vempty">Nenhuma leitura manual registrada ainda.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Data</th><th>Bem</th><th style="text-align:right">Odômetro (km)</th></tr></thead>
      <tbody>
      <?php foreach ($historicoOdo as $r): ?>
        <tr>
          <td class="vnum"><strong><?= date('d/m/Y', strtotime((string)$r['data_leitura'])) ?></strong></td>
          <td><?= h($r['modelo'] ? ($r['modelo'] . ($r['placa'] ? ' · ' . $r['placa'] : ''))
                : (($r['maq_codigo'] ?? '') !== '' ? $r['maq_codigo'] . ' — ' . $r['maq_nome'] : '—')) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['odometro'], 0) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Trilha de km nos abastecimentos</strong>
      <span class="vsub"><?= count($trilha) ?> registro(s)</span></div>
    <?php if (!$trilha): ?>
      <div class="vempty">Nenhum abastecimento com odômetro informado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Bem</th>
        <th style="text-align:right">Odômetro (km)</th>
        <th style="text-align:right">Litros</th>
      </tr></thead>
      <tbody>
      <?php foreach ($trilha as $r): ?>
        <tr>
          <td class="vnum"><strong><?= date('d/m/Y', strtotime((string)$r['data_abastecimento'])) ?></strong></td>
          <td><?= h($r['modelo'] ? ($r['modelo'] . ($r['placa'] ? ' · ' . $r['placa'] : '')) : ($r['maquina'] ?? '—')) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['odometro'], 0) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['litros'], 1) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
