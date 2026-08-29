<?php
/* ============================================================
   VERO — Custos / Fechamento de Safra  (tela real)
   Substitui o mock. Rota: /custeio/fechamento.php
   Guard: custos.fechamento_safra
   Fecha a safra registrando um snapshot do custo total apurado
   (custeio_fechamentos). O fechamento é referência gerencial —
   o bloqueio de lançamentos retroativos em safra fechada é etapa
   posterior, pendente de validação do fluxo com o cliente.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_plano_map.php';
require_once __DIR__ . '/_rateio_exec.php'; /* A3-T5 (P-07) */

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'ratear') { /* A3-T5: aplicar regra na safra (antes de fechar) */
        vero_require('custeio.rateios.editar');
        $safraId = vero_int('safra_id');
        $rateioId = vero_int('rateio_id');
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $r = rateio_aplicar((int)$rateioId, (int)$safraId);
            $pdo->commit();
            vero_flash('ok', "Rateio aplicado: {$r['linhas']} linha(s), R$ " . numFmt($r['total'], 2)
                . ' distribuídos (com estorno — memória de cálculo no histórico).');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'desfazer_rateio') {
        vero_require('custeio.rateios.editar');
        $safraId = vero_int('safra_id');
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $fech = vero_row("SELECT status FROM custeio_fechamentos WHERE tenant_id=:t AND safra_id=:s",
                [':t' => $t, ':s' => (int)$safraId]);
            if ($fech && $fech['status'] === 'fechado') throw new RuntimeException('Safra fechada — reabra antes de desfazer o rateio.');
            $n = rateio_desfazer((int)$safraId);
            $pdo->commit();
            vero_flash('ok', "Rateio desfeito — {$n} linha(s) de custeio removidas (execuções marcadas como desfeitas).");
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'fechar') {
        vero_require('custeio.fechamento_safra.editar');
        $safraId = vero_int('safra_id');
        if ($safraId) {
            $aberto = vero_row("SELECT * FROM custeio_fechamentos
                                 WHERE tenant_id=:t AND safra_id=:s AND status='fechado'",
                [':t' => $t, ':s' => $safraId]);
            if ($aberto) {
                vero_flash('erro', 'Esta safra já está fechada — reabra antes de fechar de novo.');
            } else {
                $custo = (float)vero_val("SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos
                                           WHERE tenant_id=:t AND safra_id=:s", [':t' => $t, ':s' => $safraId]);
                /* fechamentos anteriores da mesma safra (reaberto) → substitui snapshot */
                $anterior = vero_row("SELECT id FROM custeio_fechamentos WHERE tenant_id=:t AND safra_id=:s",
                    [':t' => $t, ':s' => $safraId]);
                if ($anterior) {
                    vero_update('custeio_fechamentos', (int)$anterior['id'], [
                        'data_fechamento' => date('Y-m-d'), 'valor_total' => $custo, 'status' => 'fechado']);
                } else {
                    vero_insert('custeio_fechamentos', [
                        'safra_id'        => (int)$safraId,
                        'data_fechamento' => date('Y-m-d'),
                        'valor_total'     => $custo,
                        'status'          => 'fechado',
                    ]);
                }
                /* A3-T5: aviso de indiretos não rateados (líquido sem talhão > 0) */
                $indireto = (float)vero_val(
                    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos
                      WHERE tenant_id=:t AND safra_id=:s AND talhao_id IS NULL", [':t' => $t, ':s' => $safraId]);
                vero_flash('ok', 'Safra fechada com custo apurado de R$ ' . numFmt($custo, 2) . '.'
                    . ($indireto > 0.005 ? ' ATENÇÃO: R$ ' . numFmt($indireto, 2)
                        . ' de custos indiretos sem rateio — o custo por talhão está incompleto.' : '')
                    . ' A partir de agora a safra NÃO aceita novos lançamentos de custeio.');
            }
        }
        vero_redirect();
    }

    if ($acao === 'reabrir') {
        vero_require('custeio.fechamento_safra.editar');
        $id = vero_int('id');
        $fc = $id ? vero_row("SELECT * FROM custeio_fechamentos WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if ($fc && $fc['status'] === 'fechado') {
            vero_update('custeio_fechamentos', (int)$id, ['status' => 'reaberto']);
            $temRateio = (int)vero_val("SELECT COUNT(*) FROM custeio_rateio_execucoes
                                         WHERE tenant_id=:t AND safra_id=:s AND status='aplicada'",
                [':t' => $t, ':s' => (int)$fc['safra_id']]);
            vero_flash('ok', 'Fechamento reaberto — lançamentos voltam a ser aceitos; o snapshot será recalculado ao refechar.'
                . ($temRateio ? ' ATENÇÃO: há rateio aplicado — desfaça e reaplique após os ajustes.' : ''));
        }
        vero_redirect();
    }
}

$safras = vero_rows(
    "SELECT s.id, s.identificacao,
            (SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl
              WHERE cl.tenant_id = s.tenant_id AND cl.safra_id = s.id) AS custo,
            (SELECT COALESCE(SUM(v.valor_total),0) FROM comercial_vendas v
              WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id AND v.status <> 'cancelada') AS faturamento,
            (SELECT f2.status FROM custeio_fechamentos f2
              WHERE f2.tenant_id = s.tenant_id AND f2.safra_id = s.id
              ORDER BY f2.id DESC LIMIT 1) AS fech_status
       FROM agro_safras s
      WHERE s.tenant_id = :t ORDER BY s.identificacao DESC", [':t' => $t]);

$historico = vero_rows(
    "SELECT f.*, s.identificacao AS safra FROM custeio_fechamentos f
       LEFT JOIN agro_safras s ON s.id = f.safra_id
      WHERE f.tenant_id = :t ORDER BY f.id DESC LIMIT 20", [':t' => $t]);

/* A3-T5: regras ativas + rateios vigentes por safra + memória recente */
$regrasRateio = vero_rows("SELECT id, nome, base FROM custeio_rateios WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => $t]);
$rateadosPorSafra = [];
foreach (vero_rows("SELECT safra_id, COUNT(*) n FROM custeio_rateio_execucoes
                     WHERE tenant_id=:t AND status='aplicada' GROUP BY safra_id", [':t' => $t]) as $rx) {
    $rateadosPorSafra[(int)$rx['safra_id']] = (int)$rx['n'];
}
$memorias = vero_rows(
    "SELECT e.*, r.nome AS regra, s.identificacao AS safra
       FROM custeio_rateio_execucoes e
       JOIN custeio_rateios r ON r.id = e.rateio_id
       LEFT JOIN agro_safras s ON s.id = e.safra_id
      WHERE e.tenant_id = :t ORDER BY e.id DESC LIMIT 30", [':t' => $t]);

$GUARD      = ['macro' => 'custos', 'micro' => 'fechamento_safra'];
$PAGE_VIEW  = 'custos_fechamento_safra';
$PAGE_TITLE = 'Fechamento de Safra';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('custeio.fechamento_safra.editar');
$podeRatear = vero_can('custeio.rateios.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Fechamento de Safra', 'Snapshot do custo apurado por safra — referência para o resultado final', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Safras</strong></div>
    <?php if (!$safras): ?>
      <div class="vempty">Nenhuma safra cadastrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Safra</th>
        <th style="text-align:right">Custo apurado (R$)</th>
        <th style="text-align:right">Faturamento (R$)</th>
        <th style="text-align:right">Resultado (R$)</th>
        <th>Situação</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($safras as $s):
          $res = (float)$s['faturamento'] - (float)$s['custo'];
          $fechada = $s['fech_status'] === 'fechado'; ?>
        <tr>
          <td><strong><?= h($s['identificacao']) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$s['custo'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$s['faturamento'], 2) ?></td>
          <td class="vnum" style="text-align:right;<?= $res < 0 ? 'color:#b3261e' : 'color:var(--vero-ok,#1a7f4b)' ?>">
            <strong><?= numFmt($res, 2) ?></strong></td>
          <td><?= $fechada ? '<span class="vbadge vb-off">Fechada</span>'
                : ($s['fech_status'] === 'reaberto' ? '<span class="vbadge vb-warn">Reaberta</span>'
                : '<span class="vbadge vb-ok">Aberta</span>') ?></td>
          <td><div class="vactions">
            <?php $temRateio = isset($rateadosPorSafra[(int)$s['id']]); ?>
            <?php if ($podeRatear && !$fechada && !$temRateio && $regrasRateio): ?>
              <form method="post" style="display:flex;gap:4px" data-confirm="Aplicar o rateio nesta safra?" data-confirm-ok="Ratear" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="ratear">
                <input type="hidden" name="safra_id" value="<?= (int)$s['id'] ?>">
                <select name="rateio_id">
                  <?php foreach ($regrasRateio as $rr): ?>
                    <option value="<?= (int)$rr['id'] ?>"><?= h($rr['nome']) ?> (<?= h((string)$rr['base']) ?>)</option>
                  <?php endforeach; ?>
                </select>
                <button class="vicon vicon-acao" type="submit" title="Ratear" aria-label="Ratear"><?= vero_ico_seta() ?></button>
              </form>
            <?php elseif ($podeRatear && !$fechada && $temRateio): ?>
              <form method="post" data-confirm="Desfazer o rateio aplicado nesta safra?" data-confirm-danger data-confirm-ok="Desfazer" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="desfazer_rateio">
                <input type="hidden" name="safra_id" value="<?= (int)$s['id'] ?>">
                <button class="vicon vicon-del" type="submit" title="Desfazer rateio" aria-label="Desfazer rateio"><?= vero_ico_voltar() ?></button>
              </form>
            <?php endif; ?>
            <?php if ($podeEditar && !$fechada): ?>
              <form method="post" data-confirm="Fechar a safra <?= h((string)$s['identificacao']) ?> com o custo atual? Depois de fechada ela NÃO aceita novos lançamentos de custeio." data-confirm-ok="Fechar safra" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="fechar">
                <input type="hidden" name="safra_id" value="<?= (int)$s['id'] ?>">
                <button class="vicon vicon-acao" type="submit" title="Fechar safra" aria-label="Fechar safra"><?= vero_ico_check() ?></button>
              </form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Histórico de fechamentos</strong></div>
    <?php if (!$historico): ?>
      <div class="vempty">Nenhum fechamento registrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Safra</th><th>Data</th>
        <th style="text-align:right">Custo no fechamento (R$)</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($historico as $fc): ?>
        <tr<?= $fc['status'] === 'reaberto' ? ' style="opacity:.6"' : '' ?>>
          <td><strong><?= h($fc['safra'] ?? '—') ?></strong></td>
          <td class="vnum"><?= $fc['data_fechamento'] ? date('d/m/Y', strtotime((string)$fc['data_fechamento'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$fc['valor_total'], 2) ?></strong></td>
          <td><?= $fc['status'] === 'fechado' ? '<span class="vbadge vb-ok">Fechado</span>'
                : '<span class="vbadge vb-warn">' . h(ucfirst((string)$fc['status'])) . '</span>' ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar && $fc['status'] === 'fechado'): ?>
              <form method="post" data-confirm="Reabrir este fechamento?" data-confirm-ok="Reabrir" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="reabrir">
                <input type="hidden" name="id" value="<?= (int)$fc['id'] ?>">
                <button class="vicon vicon-acao" type="submit" title="Reabrir" aria-label="Reabrir"><?= vero_ico_voltar() ?></button>
              </form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      Fluxo recomendado: aplique o rateio dos indiretos ANTES de fechar; safra fechada não aceita
      novos lançamentos de custeio (o razão financeiro segue aberto); reabrir volta a aceitar e exige
      desfazer/reaplicar o rateio. Lançamentos SEM safra (folha, depreciação) não entram no rateio por
      safra — rateio entre safras é fase futura.
    </div>
  </div>

  <div class="vcard" style="margin-top:14px">
    <div class="vtoolbar"><strong>Memória de cálculo dos rateios (últimas execuções)</strong></div>
    <?php if (!$memorias): ?>
      <div class="vempty">Nenhuma execução de rateio.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>Safra</th><th>Regra (base)</th><th>Status</th><th>Executado</th><th>Memória</th></tr></thead>
      <tbody>
      <?php foreach ($memorias as $mx): ?>
        <tr<?= $mx['status'] === 'desfeita' ? ' style="opacity:.55"' : '' ?>>
          <td><strong><?= h($mx['safra'] ?? '—') ?></strong></td>
          <td><?= h($mx['regra']) ?> <span class="vhint">(<?= h((string)$mx['base_aplicada']) ?>)</span></td>
          <td><?= $mx['status'] === 'aplicada' ? '<span class="vbadge vb-ok">Aplicada</span>' : '<span class="vbadge vb-off">Desfeita</span>' ?></td>
          <td class="vnum"><?= $mx['executado_em'] ? date('d/m/Y H:i', strtotime((string)$mx['executado_em'])) : '—' ?></td>
          <td class="vnum" style="font-size:.78rem"><?= h((string)$mx['memoria']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
