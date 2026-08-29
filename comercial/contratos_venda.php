<?php
/* ============================================================
   VERO — Comercial / Contratos de Pré-Venda  (A3-T17 — P-09)
   Rota: /comercial/contratos_venda.php · Guard: comercial.contratos_venda
   Tabela: comercial_contratos (migration 137 / DB-02, modelo enxuto).
   Preço travado ANTES da colheita: kg contratado × preço/kg, com
   vencimento e status (rascunho→ativo→cumprido|cancelado — VARCHAR
   + validação PHP). Saldo = kg contratado − kg faturado das vendas
   vinculadas (comercial_vendas.contrato_id). Contrato ATIVO entra
   no fluxo de caixa como previsto informativo (valor restante).
   Sem liquidação/washout (fora do escopo — análise §10.4).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'comercial_contratos';
const STATUS_CT = ['rascunho' => 'Rascunho', 'ativo' => 'Ativo', 'cumprido' => 'Cumprido', 'cancelado' => 'Cancelado'];

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('comercial.contratos_venda.editar');
        $id          = vero_int('id');
        $compradorId = vero_int('comprador_id');
        $kg          = vero_dec('kg_contratado');
        $preco       = vero_dec('preco_kg');
        if (!$compradorId || $kg === null || $kg <= 0 || $preco === null || $preco <= 0) {
            vero_flash('erro', 'Comprador, kg contratado e preço/kg (maiores que zero) são obrigatórios.');
            vero_redirect();
        }
        if (!vero_val("SELECT id FROM comercial_compradores WHERE id=:i AND tenant_id=:t",
            [':i' => $compradorId, ':t' => $t])) {
            vero_flash('erro', 'Comprador inválido.');
            vero_redirect();
        }
        $culturaId = vero_int('cultura_id') ?: null;
        if ($culturaId && !vero_val("SELECT id FROM agro_culturas WHERE id=:i AND tenant_id=:t",
            [':i' => $culturaId, ':t' => $t])) $culturaId = null;
        $safraId = vero_int('safra_id') ?: null;
        if ($safraId && !vero_val("SELECT id FROM agro_safras WHERE id=:i AND tenant_id=:t",
            [':i' => $safraId, ':t' => $t])) $safraId = null;

        $dados = [
            'comprador_id'    => $compradorId,
            'cultura_id'      => $culturaId,
            'safra_id'        => $safraId,
            'kg_contratado'   => $kg,
            'preco_kg'        => $preco,
            'data_contrato'   => vero_date('data_contrato') ?? date('Y-m-d'),
            'data_vencimento' => vero_date('data_vencimento'),
            'observacao'      => vero_str('observacao', 255),
        ];
        $pdo = vero_pdo();
        try {
            if ($id) {
                $ct = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]);
                if (!$ct) throw new RuntimeException('Contrato inválido.');
                if (in_array($ct['status'], ['cumprido', 'cancelado'], true)) {
                    throw new RuntimeException('Contrato ' . $ct['status'] . ' não pode ser editado.');
                }
                if ($ct['status'] === 'ativo'
                    && number_format((float)$ct['preco_kg'], 4, '.', '') !== number_format((float)$preco, 4, '.', '')
                    && (int)vero_val("SELECT COUNT(*) FROM comercial_vendas WHERE tenant_id=:t AND contrato_id=:c AND status<>'cancelada'",
                        [':t' => $t, ':c' => $id]) > 0) {
                    throw new RuntimeException('Contrato ativo com vendas vinculadas não pode ter o PREÇO alterado (preço travado).');
                }
                vero_update(T, $id, $dados);
                vero_flash('ok', 'Contrato atualizado.');
            } else {
                /* numeração atômica por ano (padrão GET_LOCK do projeto) */
                $pdo->query("SELECT GET_LOCK('vero_ct_num_{$t}', 5)");
                $seq = (int)vero_val(
                    "SELECT COALESCE(MAX(CAST(SUBSTRING(numero, 8) AS UNSIGNED)),0)
                       FROM " . T . " WHERE tenant_id=:t AND numero LIKE :p",
                    [':t' => $t, ':p' => 'CT' . date('Y') . '-%']) + 1;
                $dados['numero'] = 'CT' . date('Y') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                $dados['status'] = 'rascunho';
                vero_insert(T, $dados);
                $pdo->query("SELECT RELEASE_LOCK('vero_ct_num_{$t}')");
                vero_flash('ok', "Contrato {$dados['numero']} criado (rascunho — ative para valer no fluxo de caixa).");
            }
        } catch (Throwable $e) {
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'status') {
        vero_require('comercial.contratos_venda.editar');
        $id = vero_int('id');
        $novo = (string)($_POST['novo_status'] ?? '');
        $ct = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        $transicoes = ['rascunho' => ['ativo', 'cancelado'], 'ativo' => ['cumprido', 'cancelado'], 'cumprido' => [], 'cancelado' => []];
        if ($ct && isset(STATUS_CT[$novo]) && in_array($novo, $transicoes[(string)$ct['status']] ?? [], true)) {
            vero_update(T, (int)$id, ['status' => $novo]);
            vero_flash('ok', "Contrato {$ct['numero']} → " . STATUS_CT[$novo] . '.');
        } else {
            vero_flash('erro', 'Transição de status inválida.');
        }
        vero_redirect();
    }
}

/* ── Listagem com saldo (kg faturado das vendas vinculadas) ── */
$fStatus = (string)($_GET['status'] ?? '');
$where = "ct.tenant_id = :t";
$params = [':t' => $t];
if (isset(STATUS_CT[$fStatus])) { $where .= " AND ct.status = :st"; $params[':st'] = $fStatus; }

$rows = vero_rows(
    "SELECT ct.*, COALESCE(NULLIF(cc.nome_fantasia,''), cc.razao_social) AS comprador,
            cu.nome AS cultura, sa.identificacao AS safra,
            COALESCE((SELECT SUM(v.kg_total) FROM comercial_vendas v
              WHERE v.tenant_id = ct.tenant_id AND v.contrato_id = ct.id AND v.status <> 'cancelada'), 0) AS kg_faturado,
            COALESCE((SELECT SUM(v.valor_total) FROM comercial_vendas v
              WHERE v.tenant_id = ct.tenant_id AND v.contrato_id = ct.id AND v.status <> 'cancelada'), 0) AS valor_faturado
       FROM " . T . " ct
       JOIN comercial_compradores cc ON cc.id = ct.comprador_id
       LEFT JOIN agro_culturas cu ON cu.id = ct.cultura_id
       LEFT JOIN agro_safras sa ON sa.id = ct.safra_id
      WHERE {$where}
      ORDER BY FIELD(ct.status,'ativo','rascunho','cumprido','cancelado'), ct.data_vencimento, ct.id DESC",
    $params);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t AND status IN ('rascunho','ativo')",
        [':i' => (int)$_GET['editar'], ':t' => $t]);
}
$compradores = vero_options('comercial_compradores', 'razao_social', 'ativo = 1');
$culturas = vero_options('agro_culturas', 'nome');
$safras = vero_options('agro_safras', 'identificacao');

$GUARD      = ['macro' => 'comercial', 'micro' => 'contratos_venda'];
$PAGE_VIEW  = 'comercial_contratos_venda';
$PAGE_TITLE = 'Contratos de Pré-Venda';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('comercial.contratos_venda.editar');
$badge = static fn(string $s): string => match ($s) {
    'ativo'     => '<span class="vbadge vb-ok">Ativo</span>',
    'rascunho'  => '<span class="vbadge vb-warn">Rascunho</span>',
    'cumprido'  => '<span class="vbadge vb-info">Cumprido</span>',
    default     => '<span class="vbadge vb-off">Cancelado</span>',
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Contratos de Pré-Venda',
      'Preço travado antes da colheita — contrato ativo entra no fluxo de caixa como previsto', $podeEditar ? '+ Novo contrato' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get">
        <select name="status" onchange="this.form.submit()">
          <option value="">Todos os status</option>
          <?php foreach (STATUS_CT as $k => $l): ?>
            <option value="<?= $k ?>"<?= $fStatus === $k ? ' selected' : '' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= count($rows) ?> contrato(s)</span>
    </div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum contrato. Vendas a prazo já faturadas seguem em Comercial → Vendas.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th>Nº</th><th>Comprador</th><th>Cultura/Safra</th>
        <th style="text-align:right">kg contratado</th>
        <th style="text-align:right">Preço (R$/kg)</th>
        <th style="text-align:right">Valor (R$)</th>
        <th style="text-align:right">kg faturado</th>
        <th style="text-align:right">Saldo (kg)</th>
        <th>Vencimento</th><th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $valorTotal = (float)$r['kg_contratado'] * (float)$r['preco_kg'];
          $saldoKg = (float)$r['kg_contratado'] - (float)$r['kg_faturado']; ?>
        <tr<?= $r['status'] === 'cancelado' ? ' style="opacity:.55"' : '' ?>>
          <td class="vnum"><strong><?= h((string)$r['numero']) ?></strong></td>
          <td><?= h((string)$r['comprador']) ?></td>
          <td><?= h(trim(($r['cultura'] ?? '') . ' / ' . ($r['safra'] ?? ''), ' /') ?: '—') ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['kg_contratado'], 0) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['preco_kg'], 4) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($valorTotal, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['kg_faturado'], 0) ?></td>
          <td class="vnum" style="text-align:right;<?= $saldoKg < 0 ? 'color:#b3261e' : '' ?>"><?= numFmt($saldoKg, 0) ?></td>
          <td class="vnum"><?= $r['data_vencimento'] ? date('d/m/Y', strtotime((string)$r['data_vencimento'])) : '—' ?></td>
          <td><?= $badge((string)$r['status']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar && in_array($r['status'], ['rascunho', 'ativo'], true)): ?>
              <?= vero_btn_editar((int)$r['id']) ?>
              <?php $prox = $r['status'] === 'rascunho' ? ['ativo' => 'Ativar'] : ['cumprido' => 'Cumprir']; ?>
              <?php foreach ($prox + ['cancelado' => 'Cancelar'] as $ns => $rot): ?>
              <form method="post" data-confirm="<?= $rot ?> o contrato <?= h((string)$r['numero']) ?>?"<?= $ns === 'cancelado' ? ' data-confirm-danger' : '' ?> data-confirm-ok="<?= $rot ?>" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="status">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="novo_status" value="<?= $ns ?>">
                <button class="vicon <?= $ns === 'cancelado' ? 'vicon-del' : 'vicon-acao' ?>" type="submit" title="<?= $rot ?>" aria-label="<?= $rot ?>"><?= $ns === 'cancelado' ? vero_ico_x() : vero_ico_check() ?></button>
              </form>
              <?php endforeach; ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      Vincule vendas ao contrato na tela Comercial → Vendas (campo "Contrato de pré-venda") — o saldo
      abate automaticamente. O preço de contrato ATIVO com vendas vinculadas é travado. A transição para
      "Cumprido" é manual (o sistema mostra o saldo, não decide).
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar contrato ' . h((string)$edit['numero']) : 'Novo contrato de pré-venda' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="vfield full"><label>Comprador *</label>
          <select name="comprador_id" required><option value="">—</option>
            <?php foreach ($compradores as $cid => $cn): ?>
              <option value="<?= $cid ?>"<?= (int)($edit['comprador_id'] ?? 0) === (int)$cid ? ' selected' : '' ?>><?= h($cn) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="vfield"><label>Cultura</label>
          <select name="cultura_id"><option value="">—</option>
            <?php foreach ($culturas as $cid => $cn): ?>
              <option value="<?= $cid ?>"<?= (int)($edit['cultura_id'] ?? 0) === (int)$cid ? ' selected' : '' ?>><?= h($cn) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="vfield"><label>Safra</label>
          <select name="safra_id"><option value="">—</option>
            <?php foreach ($safras as $sid => $sn): ?>
              <option value="<?= $sid ?>"<?= (int)($edit['safra_id'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= h($sn) ?></option>
            <?php endforeach; ?>
          </select></div>
        <?= vero_f_text('kg_contratado', 'kg contratado *', $edit ? numFmt((float)$edit['kg_contratado'], 0) : '', true) ?>
        <?= vero_f_text('preco_kg', 'Preço travado (R$/kg) *', $edit ? numFmt((float)$edit['preco_kg'], 4) : '', true) ?>
        <div class="vfield"><label>Data do contrato</label>
          <input type="date" name="data_contrato" value="<?= h($edit['data_contrato'] ?? date('Y-m-d')) ?>"></div>
        <div class="vfield"><label>Vencimento (entrega/faturamento)</label>
          <input type="date" name="data_vencimento" value="<?= h($edit['data_vencimento'] ?? '') ?>"></div>
        <div class="full"><?= vero_f_text('observacao', 'Observação', $edit['observacao'] ?? '') ?></div>
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
