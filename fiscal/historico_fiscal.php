<?php
/* ============================================================
   VERO — Fiscal / Histórico Fiscal  (tela real, leitura)
   Substitui o mock. Rota: /fiscal/historico_fiscal.php
   Guard: fiscal.historico_fiscal
   Trilha dos documentos por mês, tipo e status, com totais.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fAno = (int)($_GET['ano'] ?? date('Y'));
if ($fAno < 2000 || $fAno > 2100) $fAno = (int)date('Y');

$anos = array_map('intval', array_column(vero_rows(
    "SELECT DISTINCT YEAR(COALESCE(data_emissao, created_at)) AS a FROM fiscal_documentos
      WHERE tenant_id = :t ORDER BY a DESC", [':t' => $t]), 'a'));
if (!in_array($fAno, $anos, true)) $anos[] = $fAno;

$porMes = vero_rows(
    "SELECT DATE_FORMAT(COALESCE(d.data_emissao, d.created_at), '%m/%Y') AS mes,
            COUNT(*) AS docs,
            SUM(CASE WHEN d.status <> 'recusado' THEN d.valor_total ELSE 0 END) AS valor,
            SUM(d.status = 'conciliado') AS conciliados,
            SUM(d.status = 'recusado') AS recusados
       FROM fiscal_documentos d
      WHERE d.tenant_id = :t AND YEAR(COALESCE(d.data_emissao, d.created_at)) = :a
      GROUP BY DATE_FORMAT(COALESCE(d.data_emissao, d.created_at), '%Y-%m'), mes
      ORDER BY DATE_FORMAT(COALESCE(d.data_emissao, d.created_at), '%Y-%m')", [':t' => $t, ':a' => $fAno]);

$porTipo = vero_rows(
    "SELECT d.tipo, COUNT(*) AS docs, SUM(CASE WHEN d.status <> 'recusado' THEN d.valor_total ELSE 0 END) AS valor
       FROM fiscal_documentos d
      WHERE d.tenant_id = :t AND YEAR(COALESCE(d.data_emissao, d.created_at)) = :a
      GROUP BY d.tipo ORDER BY valor DESC", [':t' => $t, ':a' => $fAno]);

$rotuloTipo = ['nfe' => 'NF-e', 'nfce' => 'NFC-e', 'cte' => 'CT-e', 'outro' => 'Outro/NFS-e'];

$GUARD      = ['macro' => 'fiscal', 'micro' => 'historico_fiscal'];
$PAGE_VIEW  = 'fiscal_historico_fiscal';
$PAGE_TITLE = 'Histórico Fiscal';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Histórico Fiscal', 'Documentos por mês e por tipo — recusados ficam fora dos totais', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px">
        <select name="ano" onchange="this.form.submit()">
          <?php foreach ($anos as $a): ?>
            <option value="<?= $a ?>"<?= $a === $fAno ? ' selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/fiscal/documentos.php">Documentos</a>
    </div>
    <div class="vtoolbar" style="border-top:1px solid var(--vero-border,#eee)"><strong>Por tipo</strong></div>
    <?php if (!$porTipo): ?>
      <div class="vempty">Nenhum documento em <?= $fAno ?>.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Tipo</th><th style="text-align:right">Documentos</th><th style="text-align:right">Valor (R$)</th></tr></thead>
      <tbody>
      <?php foreach ($porTipo as $r): ?>
        <tr>
          <td><span class="vbadge vb-info"><?= h($rotuloTipo[(string)$r['tipo']] ?? ucfirst((string)$r['tipo'])) ?></span></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['docs'] ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['valor'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Por mês</strong></div>
    <?php if (!$porMes): ?>
      <div class="vempty">Nenhum documento em <?= $fAno ?>.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Mês</th>
        <th style="text-align:right">Documentos</th>
        <th style="text-align:right">Valor (R$)</th>
        <th style="text-align:right">Conciliados</th>
        <th style="text-align:right">Recusados</th>
      </tr></thead>
      <tbody>
      <?php foreach ($porMes as $r): ?>
        <tr>
          <td class="vnum"><strong><?= h($r['mes']) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['docs'] ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['valor'], 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['conciliados'] ?></td>
          <td class="vnum" style="text-align:right;<?= (int)$r['recusados'] > 0 ? 'color:#b3261e' : '' ?>"><?= (int)$r['recusados'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
