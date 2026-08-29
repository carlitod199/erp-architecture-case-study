<?php
/* ============================================================
   VERO — Dashboard Financeiro  (tela real, leitura)
   Substitui o mock. Rota: /dashboard/dashboard_financeiro.php
   Guard: dashboard.dashboard_financeiro
   Posição do razão (aberto/vencido), caixa do mês, custeio por
   categoria e resultado por safra (faturamento − custo).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

/* #50: filtro por MÊS e ANO. Aplica-se às métricas de PERÍODO (caixa do mês:
   recebido/pago; custeio do ano). Séries históricas, aging e saldo acumulado
   são all-time/point-in-time por natureza e não respondem ao filtro.
   Default = mês/ano correntes. Estas variáveis são reusadas pelo piloto. */
$fAno = (int)($_GET['fano'] ?? date('Y'));
$fMes = (int)($_GET['fmes'] ?? date('n'));
if ($fMes < 1 || $fMes > 12) { $fMes = (int)date('n'); }
if ($fAno < 2000 || $fAno > 2100) { $fAno = (int)date('Y'); }
$mesIni = sprintf('%04d-%02d-01', $fAno, $fMes);
$mesFim = date('Y-m-t', strtotime($mesIni));
$perMesLabel = sprintf('%02d/%04d', $fMes, $fAno);   // rótulo do mês selecionado
$perAno = $fAno;                                      // rótulo/filtro do ano

/* Posição do razão */
$posicao = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN tipo='receber' AND status='aberto' THEN valor END),0) AS receber_aberto,
            COALESCE(SUM(CASE WHEN tipo='pagar' AND status='aberto' THEN valor END),0) AS pagar_aberto,
            COALESCE(SUM(CASE WHEN tipo='receber' AND status='aberto' AND data_vencimento < CURDATE() THEN valor END),0) AS receber_vencido,
            COALESCE(SUM(CASE WHEN tipo='pagar' AND status='aberto' AND data_vencimento < CURDATE() THEN valor END),0) AS pagar_vencido
       FROM movimentacoes_financeiras WHERE tenant_id = :t", [':t' => $t]);

/* Caixa do mês corrente */
$caixaMes = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN tipo='receber' THEN valor END),0) AS entradas,
            COALESCE(SUM(CASE WHEN tipo='pagar' THEN valor END),0) AS saidas
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status='pago' AND data_pagamento BETWEEN :i AND :f",
    [':t' => $t, ':i' => $mesIni, ':f' => $mesFim]);

/* Próximos vencimentos (15 dias) */
$vencimentos = vero_rows(
    "SELECT tipo, descricao, valor, data_vencimento
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status = 'aberto' AND data_vencimento IS NOT NULL
        AND data_vencimento <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
      ORDER BY data_vencimento LIMIT 10", [':t' => $t]);

/* Custeio por categoria (ano corrente) */
$custeio = vero_rows(
    "SELECT COALESCE(categoria,'outros') AS categoria, SUM(valor) AS total
       FROM custeio_lancamentos
      WHERE tenant_id = :t AND YEAR(data_competencia) = :ano
      GROUP BY categoria ORDER BY total DESC", [':t' => $t, ':ano' => $perAno]);
$totCusteio = array_sum(array_map(static fn($c) => (float)$c['total'], $custeio));

/* Resultado por safra: faturamento das vendas − custeio */
$resultado = vero_rows(
    "SELECT sa.id, sa.identificacao AS safra,
            (SELECT COALESCE(SUM(v.valor_total),0) FROM comercial_vendas v
              WHERE v.tenant_id = sa.tenant_id AND v.safra_id = sa.id AND v.status <> 'cancelada') AS faturamento,
            (SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl
              WHERE cl.tenant_id = sa.tenant_id AND cl.safra_id = sa.id) AS custo
       FROM agro_safras sa
      WHERE sa.tenant_id = :t
     HAVING faturamento > 0 OR custo > 0
      ORDER BY sa.identificacao DESC LIMIT 6", [':t' => $t]);

$GUARD      = ['macro' => 'dashboard', 'micro' => 'dashboard_financeiro'];
$PAGE_VIEW  = 'dashboard_dashboard_financeiro';
$PAGE_TITLE = 'Dashboard Financeiro';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$saldoMes = (float)$caixaMes['entradas'] - (float)$caixaMes['saidas'];
$rotuloCat = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));

/* Redesenho ECharts (A4-05) — re-entregue pós-R1; DEFAULT. ?classico=1 = render
   antigo (escape reversível). Reusa as variáveis acima; o pilot aplica as
   regras R1 de estado de dados (via _dash.php). */
if (empty($_GET['classico'])) {
    require __DIR__ . '/_financeiro_piloto.php';
    require __DIR__ . '/../includes/agro_footer_simple.php';
    return;
}
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Dashboard Financeiro', 'Posição do razão, caixa do mês, custeio por categoria e resultado por safra', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">A receber em aberto</span>
        <strong class="vnum" style="font-size:1.15rem;color:var(--vero-ok,#1a7f4b)">R$ <?= numFmt((float)$posicao['receber_aberto'], 2) ?></strong>
        <?php if ((float)$posicao['receber_vencido'] > 0): ?>
          <span class="vhint" style="color:#b3261e">vencido: R$ <?= numFmt((float)$posicao['receber_vencido'], 2) ?></span>
        <?php endif; ?></div>
      <div class="vkpi"><span class="vhint">A pagar em aberto</span>
        <strong class="vnum" style="font-size:1.15rem;color:#b3261e">R$ <?= numFmt((float)$posicao['pagar_aberto'], 2) ?></strong>
        <?php if ((float)$posicao['pagar_vencido'] > 0): ?>
          <span class="vhint" style="color:#b3261e">vencido: R$ <?= numFmt((float)$posicao['pagar_vencido'], 2) ?></span>
        <?php endif; ?></div>
      <div class="vkpi"><span class="vhint">Caixa de <?= date('m/Y') ?></span>
        <strong class="vnum" style="font-size:1.15rem;<?= $saldoMes < 0 ? 'color:#b3261e' : '' ?>">R$ <?= numFmt($saldoMes, 2) ?></strong>
        <span class="vhint">+<?= numFmt((float)$caixaMes['entradas'], 2) ?> / −<?= numFmt((float)$caixaMes['saidas'], 2) ?></span></div>
      <div class="vkpi"><span class="vhint">Custeio <?= date('Y') ?></span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($totCusteio, 2) ?></strong></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Próximos vencimentos (15 dias)</strong>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/financeiro/fluxo_caixa.php">Fluxo de caixa</a></div>
      <?php if (!$vencimentos): ?>
        <div class="vempty">Nenhum título vencendo nos próximos 15 dias.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Vencimento</th><th>Tipo</th><th>Descrição</th><th style="text-align:right">Valor (R$)</th></tr></thead>
        <tbody>
        <?php foreach ($vencimentos as $v):
            $vencido = $v['data_vencimento'] < date('Y-m-d'); ?>
          <tr>
            <td><span class="vbadge <?= $vencido ? 'vb-off' : 'vb-warn' ?>">
              <?= date('d/m/Y', strtotime((string)$v['data_vencimento'])) ?></span></td>
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
      <div class="vtoolbar"><strong>Custeio <?= date('Y') ?> por categoria</strong>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/custeio/custo_categoria.php">Detalhe</a></div>
      <?php if (!$custeio): ?>
        <div class="vempty">Nenhum lançamento de custeio no ano.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Categoria</th><th style="text-align:right">Total (R$)</th><th style="width:36%">Participação</th></tr></thead>
        <tbody>
        <?php foreach ($custeio as $c):
            $pct = $totCusteio > 0 ? (float)$c['total'] / $totCusteio * 100 : 0; ?>
          <tr>
            <td><strong><?= h($rotuloCat((string)$c['categoria'])) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$c['total'], 2) ?></td>
            <td><div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
                <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
              </div>
              <span class="vnum vhint"><?= numFmt($pct, 1) ?>%</span>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Resultado por safra</strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/custeio/resultado_safra.php">Detalhe</a></div>
    <?php if (!$resultado): ?>
      <div class="vempty">Nenhuma safra com faturamento ou custo lançado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Safra</th>
        <th style="text-align:right">Faturamento (R$)</th>
        <th style="text-align:right">Custo (R$)</th>
        <th style="text-align:right">Resultado (R$)</th>
        <th style="text-align:right">Margem</th>
      </tr></thead>
      <tbody>
      <?php foreach ($resultado as $r):
          $res = (float)$r['faturamento'] - (float)$r['custo'];
          $margem = (float)$r['faturamento'] > 0 ? $res / (float)$r['faturamento'] * 100 : null; ?>
        <tr>
          <td><strong><?= h(vero_safra_rotulo((string)$r['safra'])) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['faturamento'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['custo'], 2) ?></td>
          <td class="vnum" style="text-align:right;<?= $res < 0 ? 'color:#b3261e' : 'color:var(--vero-ok,#1a7f4b)' ?>">
            <strong><?= numFmt($res, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= $margem !== null ? numFmt($margem, 1) . '%' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
