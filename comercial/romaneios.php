<?php
/* ============================================================
   VERO — Comercial / Romaneios de Saída  (CRUD real)
   Substitui o mock. Rota: /comercial/romaneios.php
   Guard: comercial.romaneios_saida (slug da matriz — corrigido pelo A0 no QA-003; era comercial.romaneios, inexistente no catálogo)
   Romaneios vinculados a vendas (comercial_romaneios): número,
   peso e data — confronto do embarcado com o kg da venda.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'comercial_romaneios';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('comercial.romaneios_saida.editar');
        $id      = vero_int('id');
        $vendaId = vero_int('venda_id');
        $numero  = vero_str('romaneio', 40);
        $peso    = vero_dec('peso_kg');
        if (!$vendaId || $numero === null || $peso === null || $peso <= 0) {
            vero_flash('erro', 'Venda, número do romaneio e peso (maior que zero) são obrigatórios.');
            vero_redirect();
        }
        $venda = vero_row("SELECT * FROM comercial_vendas WHERE id=:i AND tenant_id=:t", [':i' => $vendaId, ':t' => $t]);
        if (!$venda || $venda['status'] === 'cancelada') {
            vero_flash('erro', 'Venda inválida ou cancelada.');
            vero_redirect();
        }
        $data = [
            'venda_id'      => (int)$vendaId,
            'romaneio'      => $numero,
            'peso_kg'       => $peso,
            'data_romaneio' => vero_date('data_romaneio') ?? date('Y-m-d'),
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Romaneio {$numero} atualizado."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Romaneio {$numero} registrado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('comercial.romaneios_saida.excluir');
        $id = vero_int('id');
        if ($id) {
            vero_pdo()->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")->execute([$t, (int)$id]);
            vero_flash('ok', 'Romaneio excluído.');
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT r.*, v.numero AS venda, v.kg_total AS venda_kg, c.nome_fantasia, c.razao_social,
            l.codigo_lote /* A3-T27c: lote agrícola da venda (DB-50); NULL = legada */
       FROM " . T . " r
       JOIN comercial_vendas v ON v.id = r.venda_id
       LEFT JOIN comercial_compradores c ON c.id = v.comprador_id
       LEFT JOIN estoque_lotes l ON l.id = v.lote_id
      WHERE r.tenant_id = :t
      ORDER BY r.data_romaneio DESC, r.id DESC LIMIT 100", [':t' => $t]);

/* embarcado por venda */
$embarcado = [];
foreach ($rows as $r) {
    $embarcado[(int)$r['venda_id']] = ($embarcado[(int)$r['venda_id']] ?? 0.0) + (float)$r['peso_kg'];
}

/* A4 19/07 — agrupa os romaneios por VENDA para dar hierarquia à tela: um card
   por venda com o confronto embarcado × vendido e a lista de embarques dentro
   (comprador/lote sobem para o cabeçalho do card, deixam de repetir por linha). */
$grupos = [];
foreach ($rows as $r) {
    $vid = (int)$r['venda_id'];
    if (!isset($grupos[$vid])) {
        $grupos[$vid] = [
            'venda'       => $r['venda'],
            'comprador'   => $r['nome_fantasia'] ?? $r['razao_social'] ?? '—',
            'codigo_lote' => $r['codigo_lote'],
            'venda_kg'    => (float)($r['venda_kg'] ?? 0),
            'romaneios'   => [],
        ];
    }
    $grupos[$vid]['romaneios'][] = $r;
}
$totPeso = array_sum($embarcado);

$vendas = vero_rows(
    "SELECT v.id, v.numero, v.kg_total, COALESCE(c.nome_fantasia, c.razao_social, v.cliente) AS comprador,
            l.codigo_lote
       FROM comercial_vendas v
       LEFT JOIN comercial_compradores c ON c.id = v.comprador_id
       LEFT JOIN estoque_lotes l ON l.id = v.lote_id
      WHERE v.tenant_id = :t AND v.status <> 'cancelada'
      ORDER BY v.id DESC", [':t' => $t]);
$vendaOpts = [];
foreach ($vendas as $v) {
    $vendaOpts[(int)$v['id']] = $v['numero'] . ' — ' . $v['comprador'] . ' (' . numFmt((float)$v['kg_total'], 0) . ' kg'
        . ($v['codigo_lote'] ? ' · ' . $v['codigo_lote'] : '') . ')';
}

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t", [':id' => (int)$_GET['editar'], ':t' => $t]);
}

$GUARD      = ['macro' => 'comercial', 'micro' => 'romaneios_saida'];
$PAGE_VIEW  = 'comercial_romaneios';
$PAGE_TITLE = 'Romaneios de Saída';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('comercial.romaneios_saida.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Romaneios de Saída', 'Embarques vinculados às vendas — confronto do peso embarcado com o kg vendido',
        $podeEditar ? '+ Novo romaneio' : null) ?>

  <?php if (!$rows): ?>
    <div class="vcard"><div class="vempty">Nenhum romaneio registrado.</div></div>
  <?php else: ?>

  <!-- resumo do embarque -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
    <div class="vcard vkpi" style="padding:14px 16px"><div class="vkpi-l">Romaneios</div><div class="vkpi-v vnum"><?= count($rows) ?></div></div>
    <div class="vcard vkpi" style="padding:14px 16px"><div class="vkpi-l">Peso embarcado</div><div class="vkpi-v vnum"><?= numFmt($totPeso, 0) ?> kg</div></div>
    <div class="vcard vkpi" style="padding:14px 16px"><div class="vkpi-l">Vendas com embarque</div><div class="vkpi-v vnum"><?= count($grupos) ?></div></div>
  </div>

  <!-- um card por venda: cabeçalho com comprador/lote/confronto + embarques dentro -->
  <?php foreach ($grupos as $vid => $g):
      $emb = $embarcado[$vid] ?? 0.0;
      $vkg = $g['venda_kg'];
      $pct = $vkg > 0 ? $emb / $vkg * 100 : null;
      $excedente = $pct !== null && $pct > 100.5; ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar" style="gap:10px;flex-wrap:wrap;align-items:center">
      <strong style="font-size:14px">Venda <?= h($g['venda']) ?></strong>
      <?= $g['codigo_lote'] ? '<span class="vbadge vb-info">' . h((string)$g['codigo_lote']) . '</span>'
            : '<span class="vhint">legada (sem lote)</span>' ?>
      <span class="vsub"><?= h($g['comprador']) ?></span>
      <div style="flex:1"></div>
      <span class="vsub" style="white-space:nowrap;<?= $excedente ? 'color:#b3261e;font-weight:600' : '' ?>">
        embarcado <?= numFmt($emb, 0) ?> / <?= numFmt($vkg, 0) ?> kg
        <?= $pct !== null ? '(' . numFmt($pct, 0) . '%)' : '' ?>
      </span>
    </div>
    <?php if ($pct !== null): /* barra de progresso do embarque × vendido */ ?>
    <div style="padding:10px 14px 2px">
      <div style="height:7px;border-radius:4px;background:#EEE8DB;overflow:hidden">
        <div style="height:100%;width:<?= min(100, $pct) ?>%;background:<?= $excedente ? '#b3261e' : '#0E7E72' ?>"></div>
      </div>
      <?php if ($excedente): ?><div class="vhint" style="color:#b3261e;margin-top:5px">⚠ Peso embarcado acima do kg vendido.</div><?php endif; ?>
    </div>
    <?php endif; ?>
    <table class="vtable">
      <thead><tr>
        <th>Romaneio</th><th>Data</th>
        <th style="text-align:right">Peso (kg)</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($g['romaneios'] as $r): ?>
        <tr>
          <td><strong class="vnum"><?= h($r['romaneio']) ?></strong></td>
          <td class="vnum"><?= $r['data_romaneio'] ? date('d/m/Y', strtotime((string)$r['data_romaneio'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['peso_kg'], 0) ?></strong></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('comercial.romaneios_saida.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este romaneio?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar romaneio' : 'Novo romaneio' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_select('venda_id', 'Venda', $vendaOpts, $edit['venda_id'] ?? '', true, 'Selecione…') ?></div>
        <?= vero_f_text('romaneio', 'Nº do romaneio', $edit['romaneio'] ?? '', true, 'Ex.: RM2026-0001') ?>
        <?= vero_f_text('peso_kg', 'Peso (kg)', $edit ? numFmt((float)$edit['peso_kg'], 0) : '', true) ?>
        <div class="vfield">
          <label>Data</label>
          <input type="date" name="data_romaneio" value="<?= h($edit['data_romaneio'] ?? date('Y-m-d')) ?>">
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

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
