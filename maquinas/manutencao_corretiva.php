<?php
/* ============================================================
   VERO — Máquinas / Manutenção Corretiva  (tela real, leitura)
   Substitui o mock. Rota: /maquinas/manutencao_corretiva.php
   Guard: maquinas.manutencao_corretiva
   Recorte das manutenções tipo corretiva — o registro/edição fica
   na tela de Manutenções (que cobre preventiva e corretiva).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fStatus = (string)($_GET['status'] ?? '');

$where  = "mn.tenant_id = :t AND mn.tipo = 'corretiva'";
$params = [':t' => $t];
if (in_array($fStatus, ['aberta', 'executada', 'cancelada'], true)) {
    $where .= " AND mn.status = :s"; $params[':s'] = $fStatus;
}

$rows = vero_rows(
    "SELECT mn.*, m.codigo, m.nome AS maquina FROM maquina_manutencoes mn
       JOIN maquinas m ON m.id = mn.maquina_id
      WHERE {$where}
      ORDER BY (mn.status='aberta') DESC, mn.data_manutencao DESC, mn.id DESC LIMIT 100", $params);

$kpi = vero_row(
    "SELECT SUM(status='aberta') AS abertas, COALESCE(SUM(CASE WHEN status='executada' THEN custo END),0) AS custo
       FROM maquina_manutencoes WHERE tenant_id = :t AND tipo = 'corretiva'", [':t' => $t]);

$GUARD      = ['macro' => 'maquinas', 'micro' => 'manutencao_corretiva'];
$PAGE_VIEW  = 'maquinas_manutencao_corretiva';
$PAGE_TITLE = 'Manutenção Corretiva';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
/* P-75 (CSO): custo (R$) só com o proxy financeiro. */
$veCusto = function_exists('vero_can') ? vero_can('financeiro.dre_agro.ver') : true;
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Manutenção Corretiva', 'Quebras e reparos não programados — registre em Máquinas → Manutenções', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <select name="status" onchange="this.form.submit()">
          <option value="">Todos os status</option>
          <option value="aberta"<?= $fStatus === 'aberta' ? ' selected' : '' ?>>Abertas</option>
          <option value="executada"<?= $fStatus === 'executada' ? ' selected' : '' ?>>Executadas</option>
          <option value="cancelada"<?= $fStatus === 'cancelada' ? ' selected' : '' ?>>Canceladas</option>
        </select>
      </form>
      <span class="vsub"><?= (int)($kpi['abertas'] ?? 0) ?> aberta(s) ·
        custo executado <strong class="vnum">R$ <?= $veCusto ? numFmt((float)$kpi['custo'], 2) : '•••' ?></strong> ·
        <a href="<?= $base ?>/maquinas/manutencao.php">registrar manutenção</a></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma manutenção corretiva no filtro.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Máquina</th><th>Descrição</th>
        <th style="text-align:right">Custo (R$)</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'cancelada' ? ' style="opacity:.55"' : '' ?>>
          <td class="vnum"><strong><?= $r['data_manutencao'] ? date('d/m/Y', strtotime((string)$r['data_manutencao'])) : '—' ?></strong></td>
          <td><strong class="vnum"><?= h($r['codigo']) ?></strong> <?= h($r['maquina']) ?></td>
          <td><?= h(mb_substr((string)($r['descricao'] ?? ''), 0, 70)) ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= !$veCusto ? '•••' : ($r['custo'] !== null ? numFmt((float)$r['custo'], 2) : '—') ?></td>
          <td><?= $r['status'] === 'executada' ? '<span class="vbadge vb-ok">Executada</span>'
                : ($r['status'] === 'aberta' ? '<span class="vbadge vb-warn">Aberta</span>'
                : '<span class="vbadge vb-off">Cancelada</span>') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
