<?php
/* ============================================================
   VERO — Financeiro / Visão de Contas  (tela real, leitura)
   Substitui o dashboard mock legado. Rota: /financeiro/index.php
   Guard: financeiro.contas_pagar (mesma permissão da rota do menu)
   Landing do módulo: posição do razão, caixa do mês, vencidos e
   próximos vencimentos, com atalhos para as telas do financeiro.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_fin_alertas.php';

$t = vero_tenant();
$mesIni = date('Y-m-01');
$mesFim = date('Y-m-t');

/* Reemissão idempotente dos alertas de vencimento (categoria financeiro) */
fin_reemitir_alertas_vencimento();

$posicao = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN tipo='receber' AND status='aberto' THEN valor END),0) AS receber_aberto,
            COALESCE(SUM(CASE WHEN tipo='pagar' AND status='aberto' THEN valor END),0) AS pagar_aberto,
            COALESCE(SUM(CASE WHEN tipo='receber' AND status='aberto' AND data_vencimento < CURDATE() THEN valor END),0) AS receber_vencido,
            COALESCE(SUM(CASE WHEN tipo='pagar' AND status='aberto' AND data_vencimento < CURDATE() THEN valor END),0) AS pagar_vencido
       FROM movimentacoes_financeiras WHERE tenant_id = :t", [':t' => $t]);

$caixaMes = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN tipo='receber' THEN valor END),0) AS entradas,
            COALESCE(SUM(CASE WHEN tipo='pagar' THEN valor END),0) AS saidas
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status='pago' AND data_pagamento BETWEEN :i AND :f",
    [':t' => $t, ':i' => $mesIni, ':f' => $mesFim]);

$vencidas = vero_rows(
    "SELECT tipo, descricao, valor, data_vencimento
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status='aberto' AND data_vencimento < CURDATE()
      ORDER BY data_vencimento LIMIT 8", [':t' => $t]);

$proximas = vero_rows(
    "SELECT tipo, descricao, valor, data_vencimento
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status='aberto' AND data_vencimento >= CURDATE()
        AND data_vencimento <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
      ORDER BY data_vencimento LIMIT 8", [':t' => $t]);

$GUARD      = ['macro' => 'financeiro', 'micro' => 'contas_pagar'];
$PAGE_VIEW  = 'financeiro';
$PAGE_TITLE = 'Financeiro';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$saldoMes = (float)$caixaMes['entradas'] - (float)$caixaMes['saidas'];
$posLiquida = (float)$posicao['receber_aberto'] - (float)$posicao['pagar_aberto'];
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Financeiro — Visão de Contas',
      'Posição do razão, caixa do mês e vencimentos — atalhos para as telas do módulo', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">A pagar em aberto</span>
        <strong class="vnum" style="font-size:1.2rem;color:#b3261e">R$ <?= numFmt((float)$posicao['pagar_aberto'], 2) ?></strong>
        <?php if ((float)$posicao['pagar_vencido'] > 0): ?>
          <span class="vhint" style="color:#b3261e">vencido: R$ <?= numFmt((float)$posicao['pagar_vencido'], 2) ?></span>
        <?php endif; ?></div>
      <div class="vkpi"><span class="vhint">A receber em aberto</span>
        <strong class="vnum" style="font-size:1.2rem;color:var(--vero-ok,#1a7f4b)">R$ <?= numFmt((float)$posicao['receber_aberto'], 2) ?></strong>
        <?php if ((float)$posicao['receber_vencido'] > 0): ?>
          <span class="vhint" style="color:#b3261e">vencido: R$ <?= numFmt((float)$posicao['receber_vencido'], 2) ?></span>
        <?php endif; ?></div>
      <div class="vkpi"><span class="vhint">Posição líquida (aberto)</span>
        <strong class="vnum" style="font-size:1.2rem;<?= $posLiquida < 0 ? 'color:#b3261e' : '' ?>">R$ <?= numFmt($posLiquida, 2) ?></strong>
        <span class="vhint">a receber − a pagar</span></div>
      <div class="vkpi"><span class="vhint">Caixa de <?= date('m/Y') ?></span>
        <strong class="vnum" style="font-size:1.2rem;<?= $saldoMes < 0 ? 'color:#b3261e' : '' ?>">R$ <?= numFmt($saldoMes, 2) ?></strong>
        <span class="vhint">+<?= numFmt((float)$caixaMes['entradas'], 2) ?> / −<?= numFmt((float)$caixaMes['saidas'], 2) ?></span></div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;padding:0 14px 12px">
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/financeiro/contas_pagar.php">Contas a Pagar</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/financeiro/contas_receber.php">Contas a Receber</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/financeiro/fluxo_caixa.php">Fluxo de Caixa</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/financeiro/dre_agro.php">DRE</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/financeiro/plano_contas.php">Plano de Contas</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/financeiro/conciliacao_bancaria.php">Conciliação</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/dashboard/dashboard_financeiro.php">Dashboard Financeiro</a>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Contas vencidas</strong></div>
      <?php if (!$vencidas): ?>
        <div class="vempty">Nenhuma conta vencida. 🎉</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Vencimento</th><th>Tipo</th><th>Descrição</th><th style="text-align:right">Valor (R$)</th></tr></thead>
        <tbody>
        <?php foreach ($vencidas as $v): ?>
          <tr>
            <td><span class="vbadge vb-off"><?= date('d/m/Y', strtotime((string)$v['data_vencimento'])) ?></span></td>
            <td><?= $v['tipo'] === 'receber'
                  ? '<span class="vbadge vb-ok">Receber</span>' : '<span class="vbadge vb-warn">Pagar</span>' ?></td>
            <td><?= h(mb_substr((string)$v['descricao'], 0, 50)) ?></td>
            <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$v['valor'], 2) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong>Próximos vencimentos (15 dias)</strong></div>
      <?php if (!$proximas): ?>
        <div class="vempty">Nenhum título vencendo nos próximos 15 dias.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Vencimento</th><th>Tipo</th><th>Descrição</th><th style="text-align:right">Valor (R$)</th></tr></thead>
        <tbody>
        <?php foreach ($proximas as $v): ?>
          <tr>
            <td><span class="vbadge vb-warn"><?= date('d/m/Y', strtotime((string)$v['data_vencimento'])) ?></span></td>
            <td><?= $v['tipo'] === 'receber'
                  ? '<span class="vbadge vb-ok">Receber</span>' : '<span class="vbadge vb-warn">Pagar</span>' ?></td>
            <td><?= h(mb_substr((string)$v['descricao'], 0, 50)) ?></td>
            <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$v['valor'], 2) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
