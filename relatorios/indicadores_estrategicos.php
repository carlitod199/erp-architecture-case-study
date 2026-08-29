<?php
/* ============================================================
   VERO — Relatórios / Indicadores Estratégicos  (tela real, leitura)
   Substitui o mock. Rota: /relatorios/indicadores_estrategicos.php
   Guard: relatorios.indicadores_estrategicos
   KPIs macro da operação por safra: faturamento, custo, margem,
   custo/ha, produtividade, R$/kg e disponibilidade da frota.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

$safras = vero_rows(
    "SELECT sa.id, sa.identificacao AS safra,
            (SELECT COALESCE(SUM(v.valor_total),0) FROM comercial_vendas v
              WHERE v.tenant_id = sa.tenant_id AND v.safra_id = sa.id AND v.status <> 'cancelada') AS faturamento,
            (SELECT COALESCE(SUM(v2.kg_total),0) FROM comercial_vendas v2
              WHERE v2.tenant_id = sa.tenant_id AND v2.safra_id = sa.id AND v2.status <> 'cancelada') AS kg_vendido,
            (SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl
              WHERE cl.tenant_id = sa.tenant_id AND cl.safra_id = sa.id) AS custo,
            (SELECT COALESCE(SUM(cr.kg_total_realizado),0) FROM colheita_registros cr
              WHERE cr.tenant_id = sa.tenant_id AND cr.safra_id = sa.id) AS colhido,
            (SELECT COALESCE(SUM(st.area_plantada_ha),0) FROM agro_safra_talhoes st
              WHERE st.tenant_id = sa.tenant_id AND st.safra_id = sa.id) AS area
       FROM agro_safras sa
      WHERE sa.tenant_id = :t
     HAVING faturamento > 0 OR custo > 0 OR colhido > 0
      ORDER BY sa.identificacao DESC", [':t' => $t]);

$frota = vero_row(
    "SELECT COUNT(*) AS total, SUM(status='ativa') AS ativas FROM maquinas
      WHERE tenant_id = :t AND ativo = 1", [':t' => $t]);
$dispFrota = (int)($frota['total'] ?? 0) > 0 ? (int)$frota['ativas'] / (int)$frota['total'] * 100 : null;

$alertasAbertos = (int)vero_val("SELECT COUNT(*) FROM agro_alertas WHERE tenant_id = :t AND status = 'aberto'", [':t' => $t]);
$receberAberto = (float)vero_val("SELECT COALESCE(SUM(valor),0) FROM movimentacoes_financeiras
    WHERE tenant_id = :t AND tipo='receber' AND status='aberto'", [':t' => $t]);

$GUARD      = ['macro' => 'relatorios', 'micro' => 'indicadores_estrategicos'];
$PAGE_VIEW  = 'relatorios_indicadores_estrategicos';
$PAGE_TITLE = 'Indicadores Estratégicos';
$EXTRA_HEAD = vero_assets() . '<style media="print">.vsidebar,.no-print{display:none !important}</style>';
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Indicadores Estratégicos', 'KPIs macro por safra — margem, custo/ha, produtividade e preço médio', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Disponibilidade da frota</span>
        <strong class="vnum" style="font-size:1.2rem"><?= $dispFrota !== null ? numFmt($dispFrota, 0) . '%' : '—' ?></strong></div>
      <div class="vkpi"><span class="vhint">Alertas abertos (todos)</span>
        <strong class="vnum" style="font-size:1.2rem;color:<?= $alertasAbertos > 0 ? '#b3261e' : 'var(--vero-ok,#1a7f4b)' ?>">
          <?= $alertasAbertos ?></strong></div>
      <div class="vkpi"><span class="vhint">A receber em aberto</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($receberAberto, 2) ?></strong></div>
    </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Indicadores por safra</strong>
      <button class="vbtn vbtn-primary vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button></div>
    <?php if (!$safras): ?>
      <div class="vempty">Nenhuma safra com movimento.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th>Safra</th>
        <th style="text-align:right">Faturamento (R$)</th>
        <th style="text-align:right">Custo (R$)</th>
        <th style="text-align:right">Margem</th>
        <th style="text-align:right">Colhido (kg)</th>
        <th style="text-align:right">Área (ha)</th>
        <th style="text-align:right">Produtividade (kg/ha)</th>
        <th style="text-align:right">Custo/ha (R$)</th>
        <th style="text-align:right">R$/kg vendido</th>
      </tr></thead>
      <tbody>
      <?php foreach ($safras as $s):
          $fat = (float)$s['faturamento']; $custo = (float)$s['custo'];
          $margem = $fat > 0 ? ($fat - $custo) / $fat * 100 : null;
          $area = (float)$s['area'];
          $colhido = (float)$s['colhido'];
          $kgVend = (float)$s['kg_vendido']; ?>
        <tr>
          <td><strong><?= h($s['safra']) ?></strong></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($fat, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt($custo, 2) ?></td>
          <td class="vnum" style="text-align:right;<?= $margem !== null && $margem < 0 ? 'color:#b3261e' : 'color:var(--vero-ok,#1a7f4b)' ?>">
            <strong><?= $margem !== null ? numFmt($margem, 1) . '%' : '—' ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt($colhido, 0) ?></td>
          <td class="vnum" style="text-align:right"><?= $area > 0 ? numFmt($area, 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $area > 0 ? numFmt($colhido / $area, 0) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $area > 0 ? numFmt($custo / $area, 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $kgVend > 0 ? numFmt($fat / $kgVend, 2) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">Área usa a soma plantada dos vínculos safra×talhão — complete os vínculos para os índices por hectare.</div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
