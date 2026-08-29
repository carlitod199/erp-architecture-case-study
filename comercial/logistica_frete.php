<?php
/* ============================================================
   VERO — Comercial / Logística e Frete  (CRUD real)
   Substitui o mock. Rota: /comercial/logistica_frete.php
   Guard: comercial.logistica_frete
   Fretes por venda (comercial_logistica): transportadora, placa,
   valor e situação (previsto → em trânsito → entregue).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'comercial_logistica';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('comercial.logistica_frete.editar');
        $id      = vero_int('id');
        $vendaId = vero_int('venda_id');
        $transp  = vero_str('transportadora', 120);
        if (!$vendaId || $transp === null) {
            vero_flash('erro', 'Venda e transportadora são obrigatórias.');
            vero_redirect();
        }
        $status = (string)($_POST['status'] ?? 'previsto');
        if (!in_array($status, ['previsto', 'em_transito', 'entregue'], true)) $status = 'previsto';
        $frete = vero_dec('frete');
        if ($frete !== null && $frete < 0) { /* A11: frete é custo, nunca negativo */
            vero_flash('erro', 'O valor do frete não pode ser negativo.');
            vero_redirect();
        }
        $data = [
            'venda_id'       => (int)$vendaId,
            'transportadora' => $transp,
            'placa'          => vero_str('placa', 10),
            'frete'          => $frete,
            'status'         => $status,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', 'Frete atualizado.'); }
        else     { vero_insert(T, $data);      vero_flash('ok', 'Frete registrado.'); }
        vero_redirect();
    }

    if ($acao === 'status') {
        vero_require('comercial.logistica_frete.editar');
        $id = vero_int('id');
        $novo = (string)($_POST['status'] ?? '');
        if ($id && in_array($novo, ['previsto', 'em_transito', 'entregue', 'cancelado'], true)) {
            vero_update(T, (int)$id, ['status' => $novo]);
            vero_flash('ok', 'Situação do frete atualizada.');
        }
        vero_redirect();
    }

    if ($acao === 'excluir') { /* cancelamento lógico */
        vero_require('comercial.logistica_frete.excluir');
        $id = vero_int('id');
        if ($id) {
            vero_update(T, (int)$id, ['status' => 'cancelado']);
            vero_flash('ok', 'Frete cancelado.');
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT l.*, v.numero AS venda, COALESCE(c.nome_fantasia, c.razao_social, v.cliente) AS comprador
       FROM " . T . " l
       JOIN comercial_vendas v ON v.id = l.venda_id
       LEFT JOIN comercial_compradores c ON c.id = v.comprador_id
      WHERE l.tenant_id = :t
      ORDER BY FIELD(l.status,'em_transito','previsto','entregue','cancelado'), l.id DESC", [':t' => $t]);
$totFrete = 0.0;
foreach ($rows as $r) if ($r['status'] !== 'cancelado') $totFrete += (float)($r['frete'] ?? 0);

$vendas = vero_rows(
    "SELECT v.id, v.numero, COALESCE(c.nome_fantasia, c.razao_social, v.cliente) AS comprador
       FROM comercial_vendas v
       LEFT JOIN comercial_compradores c ON c.id = v.comprador_id
      WHERE v.tenant_id = :t AND v.status <> 'cancelada' ORDER BY v.id DESC", [':t' => $t]);
$vendaOpts = [];
foreach ($vendas as $v) $vendaOpts[(int)$v['id']] = $v['numero'] . ' — ' . $v['comprador'];

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t", [':id' => (int)$_GET['editar'], ':t' => $t]);
}

$badgeSt = static fn(string $s): string => match ($s) {
    'entregue'    => '<span class="vbadge vb-ok">Entregue</span>',
    'em_transito' => '<span class="vbadge vb-warn">Em trânsito</span>',
    'cancelado'   => '<span class="vbadge vb-off">Cancelado</span>',
    default       => '<span class="vbadge vb-info">Previsto</span>',
};

$GUARD      = ['macro' => 'comercial', 'micro' => 'logistica_frete'];
$PAGE_VIEW  = 'comercial_logistica_frete';
$PAGE_TITLE = 'Logística e Frete';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('comercial.logistica_frete.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Logística e Frete', 'Fretes por venda — transportadora, placa e acompanhamento da entrega',
        $podeEditar ? '+ Novo frete' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <span class="vsub"><?= count($rows) ?> frete(s)</span>
      <span class="vsub">total (não cancelados) <strong class="vnum">R$ <?= numFmt($totFrete, 2) ?></strong></span>
    </div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum frete registrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Venda</th><th>Comprador</th><th>Transportadora</th><th>Placa</th>
        <th style="text-align:right">Frete (R$)</th>
        <th>Situação</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'cancelado' ? ' style="opacity:.55"' : '' ?>>
          <td><strong class="vnum"><?= h($r['venda']) ?></strong></td>
          <td><?= h($r['comprador'] ?? '—') ?></td>
          <td><strong><?= h($r['transportadora'] ?? '—') ?></strong></td>
          <td class="vnum"><?= h($r['placa'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= $r['frete'] !== null ? numFmt((float)$r['frete'], 2) : '—' ?></td>
          <td><?= $badgeSt((string)$r['status']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar && $r['status'] === 'previsto'): ?>
              <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="status" value="em_transito">
                <button class="vicon vicon-acao" type="submit" title="Embarcar" aria-label="Embarcar"><?= vero_ico_seta() ?></button></form>
            <?php endif; ?>
            <?php if ($podeEditar && $r['status'] === 'em_transito'): ?>
              <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="status" value="entregue">
                <button class="vicon vicon-acao" type="submit" title="Confirmar entrega" aria-label="Confirmar entrega"><?= vero_ico_check() ?></button></form>
            <?php endif; ?>
            <?php if ($podeEditar && $r['status'] !== 'cancelado'): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('comercial.logistica_frete.excluir') && in_array($r['status'], ['previsto', 'em_transito'], true)): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Cancelar este frete?') ?>
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
      <h2><?= $edit ? 'Editar frete' : 'Novo frete' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_select('venda_id', 'Venda', $vendaOpts, $edit['venda_id'] ?? '', true, 'Selecione…') ?></div>
        <?= vero_f_text('transportadora', 'Transportadora', $edit['transportadora'] ?? '', true) ?>
        <?= vero_f_text('placa', 'Placa', $edit['placa'] ?? '', false, 'ABC1D23') ?>
        <?= vero_f_text('frete', 'Valor do frete (R$)', $edit && $edit['frete'] !== null ? numFmt((float)$edit['frete'], 2) : '') ?>
        <?php if ($edit): ?>
          <?= vero_f_select('status', 'Situação', ['previsto' => 'Previsto', 'em_transito' => 'Em trânsito', 'entregue' => 'Entregue'],
              $edit['status'] ?? 'previsto', true, '') ?>
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
