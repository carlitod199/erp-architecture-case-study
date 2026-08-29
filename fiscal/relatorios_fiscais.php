<?php
/* ============================================================
   VERO — Fiscal / Relatórios Fiscais  (tela real, leitura + CSV)
   Substitui o mock. Rota: /fiscal/relatorios_fiscais.php
   Guard: fiscal.relatorios_fiscais
   Documentos e livro caixa do período, imprimível e exportável
   (CSV com BOM, separador ;) para a contabilidade.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : date('Y-01-01');
$fFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : date('Y-m-d');

$docs = vero_rows(
    "SELECT d.tipo, d.numero, d.chave, f.nome AS fornecedor, d.data_emissao, d.valor_total, d.status
       FROM fiscal_documentos d
       LEFT JOIN fornecedores f ON f.id = d.fornecedor_id
      WHERE d.tenant_id = :t AND COALESCE(d.data_emissao, d.created_at) BETWEEN :i AND :f
      ORDER BY d.data_emissao, d.id", [':t' => $t, ':i' => $fIni, ':f' => $fFim]);

$livro = vero_rows(
    "SELECT data_lancamento, historico, tipo, valor FROM fiscal_livro_caixa
      WHERE tenant_id = :t AND data_lancamento BETWEEN :i AND :f
      ORDER BY data_lancamento, id", [':t' => $t, ':i' => $fIni, ':f' => $fFim]);

/* export CSV antes de qualquer HTML */
$csv = (string)($_GET['csv'] ?? '');
if (in_array($csv, ['documentos', 'livro'], true)) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="vero_fiscal_' . $csv . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($csv === 'documentos') {
        fputcsv($out, ['Tipo', 'Número', 'Chave', 'Fornecedor', 'Emissão', 'Valor', 'Status'], ';');
        foreach ($docs as $d) {
            fputcsv($out, [$d['tipo'], $d['numero'], $d['chave'], $d['fornecedor'], $d['data_emissao'],
                number_format((float)$d['valor_total'], 2, ',', ''), $d['status']], ';');
        }
    } else {
        fputcsv($out, ['Data', 'Histórico', 'Tipo', 'Valor'], ';');
        foreach ($livro as $l) {
            fputcsv($out, [$l['data_lancamento'], preg_replace('/\s*\[RAZAO#\d+\]$/', '', (string)$l['historico']),
                $l['tipo'], number_format((float)$l['valor'], 2, ',', '')], ';');
        }
    }
    fclose($out);
    exit;
}

$totDocs = 0.0;
foreach ($docs as $d) if ($d['status'] !== 'recusado') $totDocs += (float)$d['valor_total'];
$totE = 0.0; $totS = 0.0;
foreach ($livro as $l) { if ($l['tipo'] === 'entrada') $totE += (float)$l['valor']; else $totS += (float)$l['valor']; }

$empresa = (string)vero_val("SELECT nome FROM tenants WHERE id = :t", [':t' => $t]);
$rotuloTipo = ['nfe' => 'NF-e', 'nfce' => 'NFC-e', 'cte' => 'CT-e', 'outro' => 'Outro/NFS-e'];

$GUARD      = ['macro' => 'fiscal', 'micro' => 'relatorios_fiscais'];
$PAGE_VIEW  = 'fiscal_relatorios_fiscais';
$PAGE_TITLE = 'Relatórios Fiscais';
$EXTRA_HEAD = vero_assets() . '<style media="print">.vsidebar,.no-print{display:none !important}</style>';
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Relatórios Fiscais', 'Documentos e livro caixa do período — imprimível e exportável para a contabilidade', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center" class="no-print">
        <label class="vhint">Período</label>
        <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub"><strong><?= h($empresa) ?></strong> ·
        <?= date('d/m/Y', strtotime($fIni)) ?> – <?= date('d/m/Y', strtotime($fFim)) ?></span>
      <button class="vbtn vbtn-primary vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Documentos (não recusados)</span>
        <strong class="vnum" style="font-size:1.15rem"><?= count($docs) ?> · R$ <?= numFmt($totDocs, 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Livro caixa — entradas</span>
        <strong class="vnum" style="font-size:1.15rem;color:var(--vero-ok,#1a7f4b)">R$ <?= numFmt($totE, 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Livro caixa — saídas</span>
        <strong class="vnum" style="font-size:1.15rem;color:#b3261e">R$ <?= numFmt($totS, 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Resultado do livro</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($totE - $totS, 2) ?></strong></div>
    </div>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Documentos fiscais</strong>
      <a class="no-print" href="?ini=<?= h($fIni) ?>&fim=<?= h($fFim) ?>&csv=documentos">Exportar CSV</a></div>
    <?php if (!$docs): ?>
      <div class="vempty">Nenhum documento no período.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Tipo</th><th>Número</th><th>Fornecedor</th><th>Emissão</th>
        <th style="text-align:right">Valor (R$)</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($docs as $d): ?>
        <tr<?= $d['status'] === 'recusado' ? ' style="opacity:.55"' : '' ?>>
          <td><span class="vbadge vb-info"><?= h($rotuloTipo[(string)$d['tipo']] ?? ucfirst((string)$d['tipo'])) ?></span></td>
          <td class="vnum"><strong><?= h($d['numero'] ?? '—') ?></strong></td>
          <td><?= h($d['fornecedor'] ?? '—') ?></td>
          <td class="vnum"><?= $d['data_emissao'] ? date('d/m/Y', strtotime((string)$d['data_emissao'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$d['valor_total'], 2) ?></strong></td>
          <td><?= h(ucfirst((string)$d['status'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Livro caixa</strong>
      <a class="no-print" href="?ini=<?= h($fIni) ?>&fim=<?= h($fFim) ?>&csv=livro">Exportar CSV</a></div>
    <?php if (!$livro): ?>
      <div class="vempty">Nenhum lançamento no período.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Histórico</th>
        <th style="text-align:right">Entrada (R$)</th>
        <th style="text-align:right">Saída (R$)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($livro as $l): ?>
        <tr>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$l['data_lancamento'])) ?></td>
          <td><?= h(preg_replace('/\s*\[RAZAO#\d+\]$/', '', (string)$l['historico'])) ?></td>
          <td class="vnum" style="text-align:right;color:var(--vero-ok,#1a7f4b)">
            <?= $l['tipo'] === 'entrada' ? numFmt((float)$l['valor'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right;color:#b3261e">
            <?= $l['tipo'] === 'saida' ? numFmt((float)$l['valor'], 2) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">Relatórios gerenciais de apoio — a escrituração e a apuração oficiais são da contabilidade.</div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
