<?php
/* ============================================================
   VERO — Financeiro / Relatórios Financeiros  (tela real, leitura)
   Substitui o mock. Rota: /financeiro/relatorios_financeiros.php
   Guard: financeiro.relatorios_financeiros
   Consolidado imprimível do período: posição do razão, caixa,
   custeio por categoria e razão detalhado.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : date('Y-01-01');
$fFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : date('Y-m-d');

$caixa = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN tipo='receber' THEN valor END),0) AS entradas,
            COALESCE(SUM(CASE WHEN tipo='pagar' THEN valor END),0) AS saidas
       FROM movimentacoes_financeiras
      WHERE tenant_id=:t AND status='pago' AND data_pagamento BETWEEN :i AND :f",
    [':t' => $t, ':i' => $fIni, ':f' => $fFim]);

$aberto = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN tipo='receber' AND status='aberto' THEN valor END),0) AS receber,
            COALESCE(SUM(CASE WHEN tipo='pagar' AND status='aberto' THEN valor END),0) AS pagar
       FROM movimentacoes_financeiras WHERE tenant_id=:t", [':t' => $t]);

$custeio = vero_rows(
    "SELECT COALESCE(categoria,'outros') AS categoria, SUM(valor) AS total
       FROM custeio_lancamentos
      WHERE tenant_id=:t AND data_competencia BETWEEN :i AND :f
      GROUP BY categoria ORDER BY total DESC", [':t' => $t, ':i' => $fIni, ':f' => $fFim]);
$totCusteio = array_sum(array_map(static fn($c) => (float)$c['total'], $custeio));

$razao = vero_rows(
    "SELECT * FROM movimentacoes_financeiras
      WHERE tenant_id=:t AND (
            (status='pago' AND data_pagamento BETWEEN :i AND :f)
         OR (status='aberto' AND COALESCE(data_vencimento, data_competencia) BETWEEN :i2 AND :f2))
      ORDER BY COALESCE(data_pagamento, data_vencimento, data_competencia), id LIMIT 200",
    [':t' => $t, ':i' => $fIni, ':f' => $fFim, ':i2' => $fIni, ':f2' => $fFim]);

$empresa = (string)vero_val("SELECT nome FROM tenants WHERE id = :t", [':t' => $t]);
$rotuloCat = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));

$GUARD      = ['macro' => 'financeiro', 'micro' => 'relatorios_financeiros'];
$PAGE_VIEW  = 'financeiro_relatorios_financeiros';
$PAGE_TITLE = 'Relatórios Financeiros';
$EXTRA_HEAD = vero_assets() . '<style media="print">.vsidebar,.no-print{display:none !important}</style>';
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Relatórios Financeiros', 'Consolidado do período — caixa, posição em aberto, custeio e razão detalhado', null) ?>
  <div style="margin:-6px 0 12px">
    <a class="vbtn vbtn-ghost vbtn-sm" href="<?= rtrim(BIOS_BASE, '/') ?>/financeiro/verificador_razao">🔒 Verificador do Razão (integridade p/ o contador — P-01)</a>
  </div>

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
      <div class="vkpi"><span class="vhint">Entradas pagas</span>
        <strong class="vnum" style="font-size:1.15rem;color:var(--vero-ok,#1a7f4b)">R$ <?= numFmt((float)$caixa['entradas'], 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Saídas pagas</span>
        <strong class="vnum" style="font-size:1.15rem;color:#b3261e">R$ <?= numFmt((float)$caixa['saidas'], 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Saldo do período</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt((float)$caixa['entradas'] - (float)$caixa['saidas'], 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Em aberto (hoje)</span>
        <strong class="vnum" style="font-size:1.05rem">+<?= numFmt((float)$aberto['receber'], 2) ?> / −<?= numFmt((float)$aberto['pagar'], 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Custeio do período</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($totCusteio, 2) ?></strong></div>
    </div>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Custeio por categoria</strong></div>
    <?php if (!$custeio): ?>
      <div class="vempty">Nenhum custeio no período.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Categoria</th><th style="text-align:right">Total (R$)</th><th style="text-align:right">%</th></tr></thead>
      <tbody>
      <?php foreach ($custeio as $c): ?>
        <tr>
          <td><strong><?= h($rotuloCat((string)$c['categoria'])) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$c['total'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= $totCusteio > 0 ? numFmt((float)$c['total'] / $totCusteio * 100, 1) . '%' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Razão do período</strong>
      <span class="vsub"><?= count($razao) ?> lançamento(s)<?= count($razao) === 200 ? ' (primeiros 200)' : '' ?></span></div>
    <?php if (!$razao): ?>
      <div class="vempty">Nenhum lançamento no período.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Tipo</th><th>Descrição</th><th>Origem</th>
        <th style="text-align:right">Valor (R$)</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($razao as $m): ?>
        <tr>
          <td class="vnum"><?= ($d = $m['data_pagamento'] ?? $m['data_vencimento'] ?? $m['data_competencia'])
                ? date('d/m/Y', strtotime((string)$d)) : '—' ?></td>
          <td><?= $m['tipo'] === 'receber'
                ? '<span class="vbadge vb-ok">Receber</span>' : '<span class="vbadge vb-warn">Pagar</span>' ?></td>
          <td><?= h(mb_substr((string)($m['descricao'] ?? ''), 0, 60)) ?></td>
          <td class="vhint"><?= h(str_replace('_', ' ', (string)($m['origem_tipo'] ?? 'manual'))) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$m['valor'], 2) ?></strong></td>
          <td><?= $m['status'] === 'pago' ? '<span class="vbadge vb-ok">Pago</span>'
                : '<span class="vbadge vb-warn">' . h(ucfirst((string)$m['status'])) . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">O razão é imutável (hash encadeado por lançamento) — cancelamentos preservam a linha.</div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
