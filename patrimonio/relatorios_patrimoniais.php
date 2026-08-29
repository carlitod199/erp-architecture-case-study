<?php
/* ============================================================
   VERO — Patrimônio / Relatórios Patrimoniais  (tela real, leitura)
   Substitui o mock. Rota: /patrimonio/relatorios_patrimoniais.php
   Guard: patrimonio.relatorios_patrimoniais
   Relatório imprimível: resumo por categoria + inventário completo
   dos ativos com depreciação e valor líquido, e histórico de
   depreciação por competência.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_depreciacao_teorica.php'; /* R3-01: leitura linear teórica */

$t = vero_tenant();

$ativos = vero_rows(
    "SELECT a.*, c.nome AS categoria, c.vida_util_meses AS cat_vida, m.nome AS maquina,
            (SELECT d.valor_acumulado FROM patrimonio_depreciacoes d
              WHERE d.tenant_id = a.tenant_id AND d.ativo_id = a.id
              ORDER BY d.competencia DESC LIMIT 1) AS acumulada,
            (SELECT COUNT(*) FROM patrimonio_depreciacoes d2
              WHERE d2.tenant_id = a.tenant_id AND d2.ativo_id = a.id) AS dep_qtd
       FROM patrimonio_ativos a
       LEFT JOIN patrimonio_categorias c ON c.id = a.categoria_id
       LEFT JOIN maquinas m ON m.id = a.maquina_id
      WHERE a.tenant_id = :t AND a.ativo = 1
      ORDER BY c.nome, a.descricao", [':t' => $t]);

$totAq = 0.0; $totAc = 0.0; $totLiq = 0.0; $totTeo = 0.0; $totLiqEcon = 0.0; $temDivergencia = false;
foreach ($ativos as $a) {
    $acum = (float)($a['acumulada'] ?? 0);
    $liq  = max((float)$a['valor_aquisicao'] - $acum, (float)($a['valor_residual'] ?? 0));
    $totAq += (float)$a['valor_aquisicao'];
    $totAc += $acum;
    $totLiq += $liq;
    /* R3-01: leitura linear desde a aquisição (não grava nada) */
    $teo = pat_teorica($a);
    if (pat_diverge($teo, $acum)) $temDivergencia = true;
    $totTeo     += $teo !== null ? $teo['teorica'] : $acum;
    $totLiqEcon += $teo !== null ? $teo['liquido_econ'] : $liq;
}

$historico = vero_rows(
    "SELECT DATE_FORMAT(competencia, '%Y-%m') AS comp, COUNT(*) AS ativos, SUM(valor) AS total
       FROM patrimonio_depreciacoes WHERE tenant_id = :t
      GROUP BY comp ORDER BY comp DESC LIMIT 24", [':t' => $t]);

$empresa = (string)vero_val("SELECT nome FROM tenants WHERE id = :t", [':t' => $t]);

$GUARD      = ['macro' => 'patrimonio', 'micro' => 'relatorios_patrimoniais'];
$PAGE_VIEW  = 'patrimonio_relatorios_patrimoniais';
$PAGE_TITLE = 'Relatórios Patrimoniais';
$EXTRA_HEAD = vero_assets() . '<style media="print">.vsidebar,.vtoolbar button,.no-print{display:none !important}</style>';
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Relatórios Patrimoniais', 'Inventário de ativos com depreciação gerencial — pronto para impressão', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <span class="vsub"><strong><?= h($empresa) ?></strong> · posição em <?= date('d/m/Y') ?></span>
      <button class="vbtn vbtn-primary no-print" type="button" onclick="window.print()">Imprimir</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Ativos</span>
        <strong class="vnum" style="font-size:1.2rem"><?= count($ativos) ?></strong></div>
      <div class="vkpi"><span class="vhint">Aquisição</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($totAq, 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Depreciação acumulada GERADA</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($totAc, 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Valor líquido gerencial</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($totLiq, 2) ?></strong></div>
      <?php if ($temDivergencia): ?>
      <div class="vkpi"><span class="vhint">Depr. linear desde a aquisição (teórica)</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($totTeo, 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Valor líquido econômico estimado</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($totLiqEcon, 2) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Inventário de ativos</strong></div>
    <?php if (!$ativos): ?>
      <div class="vempty">Nenhum ativo cadastrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Ativo</th><th>Categoria</th><th>Aquisição</th>
        <th style="text-align:right">Valor (R$)</th>
        <th style="text-align:right">Residual (R$)</th>
        <th style="text-align:right">Acumulada GERADA (R$)</th>
        <th style="text-align:right">Líquido gerencial (R$)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ativos as $a):
          $acum = (float)($a['acumulada'] ?? 0);
          $liq = max((float)$a['valor_aquisicao'] - $acum, (float)($a['valor_residual'] ?? 0));
          $teo = pat_teorica($a);                       /* R3-01 */
          $divergente = pat_diverge($teo, $acum); ?>
        <tr>
          <td><strong><?= h($a['descricao']) ?></strong>
            <?= $a['maquina'] ? '<div class="vhint">máquina: ' . h((string)$a['maquina']) . '</div>' : '' ?></td>
          <td><?= h($a['categoria'] ?? '—') ?></td>
          <td class="vnum"><?= $a['data_aquisicao'] ? date('d/m/Y', strtotime((string)$a['data_aquisicao'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$a['valor_aquisicao'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)($a['valor_residual'] ?? 0), 2) ?></td>
          <td class="vnum" style="text-align:right"><?= $acum > 0 ? numFmt($acum, 2) : '—' ?>
            <?php if ($divergente): ?>
              <div class="vhint">linear desde a aquisição:<br>R$ <?= numFmt($teo['teorica'], 2) ?>
                (<?= (int)$teo['meses'] ?> compet., <?= (int)$teo['pendentes'] ?> não gerada<?= $teo['pendentes'] === 1 ? '' : 's' ?>)</div>
            <?php endif; ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($liq, 2) ?></strong>
            <?php if ($divergente): ?>
              <div class="vhint">econômico estimado:<br>R$ <?= numFmt($teo['liquido_econ'], 2) ?></div>
            <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Depreciação por competência</strong></div>
    <?php if (!$historico): ?>
      <div class="vempty">Nenhuma competência gerada — use Patrimônio → Depreciação.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Competência</th><th style="text-align:right">Ativos</th><th style="text-align:right">Total (R$)</th></tr></thead>
      <tbody>
      <?php foreach ($historico as $hRow): ?>
        <tr>
          <td class="vnum"><strong><?= date('m/Y', strtotime($hRow['comp'] . '-01')) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= (int)$hRow['ativos'] ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$hRow['total'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">Relatório gerencial — a "acumulada GERADA" soma
      apenas as competências lançadas; a leitura "linear desde a aquisição" mostra o acumulado
      teórico quando há competências não geradas. A depreciação contábil/fiscal é apurada pela
      contabilidade.</div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
