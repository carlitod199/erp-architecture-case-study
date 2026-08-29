<?php
/* ============================================================
   VERO — Pessoas / Gestão de EPI  (A3-T20 — análise A3-06)
   Rota: /pessoas/epis.php · Guard: pessoas.epis (slug/rota = A0)
   Ficha NR-31: itens (CA/vida útil — P-62: 7 itens semeados sem CA)
   + entregas por colaborador + devolução com motivo tipado.
   Estado DERIVADO (entrega + vida útil). A DF referencia a entrega
   vigente (`agro_aplicacao_operadores.epi_entrega_id` — DB-38).
   Alertas categoria `epi` (preservação de status).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_ifa_helper.php';

const MOTIVOS_DEV = ['desgaste' => 'Desgaste', 'dano' => 'Dano', 'desligamento' => 'Desligamento', 'outro' => 'Outro'];

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'item') {
        vero_require('pessoas.epis.editar');
        $nome = vero_str('nome', 120);
        if ($nome === null) { vero_flash('erro', 'Nome do EPI é obrigatório.'); vero_redirect(); }
        vero_insert('rh_epi_itens', [
            'nome' => $nome, 'ca' => vero_str('ca', 20), 'validade_ca' => vero_date('validade_ca'),
            'vida_util_meses' => vero_int('vida_util_meses') ?: null, 'ativo' => 1,
        ]);
        vero_flash('ok', 'Item de EPI cadastrado.');
        vero_redirect();
    }

    if ($acao === 'entregar') {
        vero_require('pessoas.epis.editar');
        $opId = vero_int('operador_id');
        $itemId = vero_int('item_id');
        $qtd = vero_dec('quantidade') ?? 1.0;
        $item = $itemId ? vero_row("SELECT * FROM rh_epi_itens WHERE id=:i AND tenant_id=:t AND ativo=1",
            [':i' => $itemId, ':t' => $t]) : null;
        $okOp = $opId ? vero_val("SELECT id FROM agro_operadores WHERE id=:i AND tenant_id=:t AND ativo=1",
            [':i' => $opId, ':t' => $t]) : null;
        if (!$item || !$okOp || $qtd <= 0) { vero_flash('erro', 'Colaborador, item e quantidade válidos são obrigatórios.'); vero_redirect(); }
        if ($item['validade_ca'] !== null && $item['validade_ca'] < date('Y-m-d')) {
            vero_flash('erro', 'CA do item está VENCIDO (' . date('d/m/Y', strtotime((string)$item['validade_ca'])) . ') — item não pode ser entregue (NR-31).');
            vero_redirect();
        }
        vero_insert('rh_epi_entregas', [
            'operador_id' => $opId, 'item_id' => $itemId,
            'data_entrega' => vero_date('data_entrega') ?? date('Y-m-d'), 'quantidade' => $qtd,
        ]);
        vero_flash('ok', 'Entrega registrada — colha a assinatura na ficha impressa (digital no app).');
        vero_redirect();
    }

    if ($acao === 'devolver') {
        vero_require('pessoas.epis.editar');
        $id = vero_int('id');
        $motivo = (string)($_POST['motivo'] ?? '');
        $ent = $id ? vero_row("SELECT * FROM rh_epi_entregas WHERE id=:i AND tenant_id=:t AND devolvido_em IS NULL",
            [':i' => $id, ':t' => $t]) : null;
        if (!$ent || !isset(MOTIVOS_DEV[$motivo])) { vero_flash('erro', 'Entrega vigente e motivo válido são obrigatórios.'); vero_redirect(); }
        vero_update('rh_epi_entregas', (int)$id, ['devolvido_em' => date('Y-m-d'), 'motivo_devolucao' => $motivo]);
        vero_flash('ok', 'Devolução registrada (' . MOTIVOS_DEV[$motivo] . ') — vigência encerrada.');
        vero_redirect();
    }
}

ifa_reemitir_alertas_pessoas();

$itens = vero_rows("SELECT * FROM rh_epi_itens WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => $t]);
$operadores = vero_rows("SELECT id, nome FROM agro_operadores WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => $t]);
$entregas = vero_rows(
    "SELECT e.*, i.nome AS item, i.ca, i.vida_util_meses, o.nome AS operador
       FROM rh_epi_entregas e
       JOIN rh_epi_itens i ON i.id = e.item_id
       JOIN agro_operadores o ON o.id = e.operador_id
      WHERE e.tenant_id = :t
      ORDER BY (e.devolvido_em IS NULL) DESC, e.data_entrega DESC LIMIT 60", [':t' => $t]);

$GUARD      = ['macro' => 'pessoas', 'micro' => 'epis'];
$PAGE_VIEW  = 'pessoas_epis';
$PAGE_TITLE = 'Gestão de EPI';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
$podeEditar = vero_can('pessoas.epis.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Gestão de EPI (NR-31 / IFA v6)',
      'Ficha de entrega por colaborador — CA, vida útil e devolução; a DF referencia a entrega vigente', null) ?>

  <?php if ($podeEditar): ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Nova entrega</strong></div>
      <form class="vform" method="post" style="padding:10px 14px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="entregar">
        <div class="vgrid">
          <div class="vfield"><label>Colaborador *</label><select name="operador_id" required>
            <option value="">—</option>
            <?php foreach ($operadores as $op): ?><option value="<?= (int)$op['id'] ?>"><?= h($op['nome']) ?></option><?php endforeach; ?>
          </select></div>
          <div class="vfield"><label>Item *</label><select name="item_id" required>
            <option value="">—</option>
            <?php foreach ($itens as $it): ?>
              <option value="<?= (int)$it['id'] ?>"><?= h($it['nome']) ?><?= $it['ca'] ? ' (CA ' . h((string)$it['ca']) . ')' : ' (sem CA — preencher!)' ?></option>
            <?php endforeach; ?>
          </select></div>
          <div class="vfield"><label>Data</label><input type="date" name="data_entrega" value="<?= date('Y-m-d') ?>"></div>
          <?= vero_f_text('quantidade', 'Quantidade', '1') ?>
        </div>
        <div class="vform-actions"><button class="vbtn vbtn-primary" type="submit">Registrar entrega</button></div>
      </form>
    </div>
    <div class="vcard">
      <div class="vtoolbar"><strong>Novo item de EPI</strong></div>
      <form class="vform" method="post" style="padding:10px 14px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="item">
        <div class="vgrid">
          <?= vero_f_text('nome', 'Nome *', '', true) ?>
          <?= vero_f_text('ca', 'CA (certificado de aprovação)', '') ?>
          <div class="vfield"><label>Validade do CA</label><input type="date" name="validade_ca"></div>
          <?= vero_f_text('vida_util_meses', 'Vida útil (meses)', '') ?>
        </div>
        <div class="vhint" style="margin-top:6px">O CA é do item COMPRADO — preencha ao receber o lote (o sistema bloqueia entrega com CA vencido).</div>
        <div class="vform-actions"><button class="vbtn vbtn-primary" type="submit">Cadastrar item</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Entregas</strong>
      <span class="vhint">estado derivado: entrega + vida útil do item; vigentes primeiro</span></div>
    <?php if (!$entregas): ?><div class="vempty">Nenhuma entrega registrada.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>Colaborador</th><th>Item</th><th>CA</th><th>Entrega</th>
        <th style="text-align:right">Qtd</th><th>Vida útil até</th><th>Situação</th><th style="text-align:right">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($entregas as $e):
          $vigente = $e['devolvido_em'] === null;
          $vence = ($vigente && $e['vida_util_meses'] !== null)
              ? date('Y-m-d', strtotime($e['data_entrega'] . ' +' . (int)$e['vida_util_meses'] . ' months')) : null; ?>
        <tr<?= $vigente ? '' : ' style="opacity:.55"' ?>>
          <td><strong><?= h($e['operador']) ?></strong></td>
          <td><?= h($e['item']) ?></td>
          <td class="vnum"><?= h($e['ca'] ?? '—') ?></td>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$e['data_entrega'])) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$e['quantidade'], 0) ?></td>
          <td class="vnum"><?= $vence ? date('d/m/Y', strtotime($vence)) : '—' ?></td>
          <td><?php if (!$vigente): ?>
              <span class="vbadge vb-off">Devolvido (<?= h(MOTIVOS_DEV[$e['motivo_devolucao']] ?? (string)$e['motivo_devolucao']) ?>)</span>
            <?php elseif ($vence !== null && $vence < date('Y-m-d')): ?>
              <span class="vbadge vb-off">Vida útil vencida</span>
            <?php elseif ($vence !== null && $vence <= date('Y-m-d', strtotime('+30 days'))): ?>
              <span class="vbadge vb-warn">Trocar em breve</span>
            <?php else: ?><span class="vbadge vb-ok">Vigente</span><?php endif; ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar && $vigente): ?>
            <form method="post" style="display:inline-flex;gap:4px" data-confirm="Registrar devolução deste EPI?" data-confirm-ok="Devolver" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="devolver">
              <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <select name="motivo"><?php foreach (MOTIVOS_DEV as $mk => $ml): ?><option value="<?= $mk ?>"><?= h($ml) ?></option><?php endforeach; ?></select>
              <button class="vicon vicon-acao" type="submit" title="Devolver" aria-label="Devolver"><?= vero_ico_voltar() ?></button>
            </form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      A ficha de entrega é a evidência NR-31/IFA v6; a assinatura é colhida em papel nesta fase
      (digital no app). Na DF, o operador seleciona o EPI VIGENTE desta ficha.
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
