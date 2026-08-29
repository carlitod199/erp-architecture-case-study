<?php
/* ============================================================
   VERO — Patrimônio / Valor Patrimonial  (tela real, leitura)
   Substitui o mock. Rota: /patrimonio/valor_patrimonial.php
   Guard: patrimonio.valor_patrimonial
   Posição por categoria: aquisição − depreciação acumulada
   (última competência de cada ativo) = valor líquido gerencial.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_depreciacao_teorica.php'; /* R3-01: leitura linear teórica */

$t = vero_tenant();

$ativos = vero_rows(
    "SELECT a.*, c.nome AS categoria, c.vida_util_meses AS cat_vida,
            (SELECT d.valor_acumulado FROM patrimonio_depreciacoes d
              WHERE d.tenant_id = a.tenant_id AND d.ativo_id = a.id
              ORDER BY d.competencia DESC LIMIT 1) AS acumulada,
            (SELECT COUNT(*) FROM patrimonio_depreciacoes d2
              WHERE d2.tenant_id = a.tenant_id AND d2.ativo_id = a.id) AS dep_qtd
       FROM patrimonio_ativos a
       LEFT JOIN patrimonio_categorias c ON c.id = a.categoria_id
      WHERE a.tenant_id = :t AND a.ativo = 1", [':t' => $t]);

$porCat = []; $divergentes = [];
foreach ($ativos as $a) {
    $cat = (string)($a['categoria'] ?? 'Sem categoria');
    $acum = (float)($a['acumulada'] ?? 0);
    $liquido = max((float)$a['valor_aquisicao'] - $acum, (float)($a['valor_residual'] ?? 0));
    /* R3-01: acumulada linear teórica desde a aquisição (não grava nada) */
    $teo = pat_teorica($a);
    $teorica = $teo !== null ? $teo['teorica'] : $acum;
    $liqEcon = $teo !== null ? $teo['liquido_econ'] : $liquido;
    if (pat_diverge($teo, $acum)) {
        $divergentes[] = ['descricao' => (string)$a['descricao'], 'acumulada' => $acum,
            'liquido' => $liquido] + $teo;
    }
    if (!isset($porCat[$cat])) $porCat[$cat] = ['n' => 0, 'aquisicao' => 0.0, 'acumulada' => 0.0,
        'liquido' => 0.0, 'teorica' => 0.0, 'liq_econ' => 0.0];
    $porCat[$cat]['n']++;
    $porCat[$cat]['aquisicao'] += (float)$a['valor_aquisicao'];
    $porCat[$cat]['acumulada'] += $acum;
    $porCat[$cat]['liquido']   += $liquido;
    $porCat[$cat]['teorica']   += $teorica;
    $porCat[$cat]['liq_econ']  += $liqEcon;
}
uasort($porCat, static fn($a, $b) => $b['liquido'] <=> $a['liquido']);
$totAq  = array_sum(array_column($porCat, 'aquisicao'));
$totAc  = array_sum(array_column($porCat, 'acumulada'));
$totLiq = array_sum(array_column($porCat, 'liquido'));
$totTeo = array_sum(array_column($porCat, 'teorica'));
$totLiqEcon = array_sum(array_column($porCat, 'liq_econ'));
$maxLiq = $porCat ? max(array_column($porCat, 'liquido')) : 0.0;
$temDivergencia = count($divergentes) > 0;

$GUARD      = ['macro' => 'patrimonio', 'micro' => 'valor_patrimonial'];
$PAGE_VIEW  = 'patrimonio_valor_patrimonial';
$PAGE_TITLE = 'Valor Patrimonial';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Valor Patrimonial', 'Posição gerencial por categoria — aquisição, depreciação acumulada gerada e valor líquido', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Ativos em carteira</span>
        <strong class="vnum" style="font-size:1.25rem"><?= count($ativos) ?></strong></div>
      <div class="vkpi"><span class="vhint">Valor de aquisição</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($totAq, 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Depreciação acumulada GERADA</span>
        <strong class="vnum" style="font-size:1.15rem;color:#b3261e">R$ <?= numFmt($totAc, 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Valor líquido gerencial</span>
        <strong class="vnum" style="font-size:1.15rem;color:var(--vero-ok,#1a7f4b)">R$ <?= numFmt($totLiq, 2) ?></strong></div>
      <?php if ($temDivergencia): ?>
      <div class="vkpi"><span class="vhint">Depr. linear desde a aquisição (teórica)</span>
        <strong class="vnum" style="font-size:1.15rem;color:#b3261e">R$ <?= numFmt($totTeo, 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Valor líquido econômico estimado</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($totLiqEcon, 2) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($temDivergencia): ?>
  <div class="vcard" style="margin-bottom:14px;border-left:4px solid #b3261e">
    <div class="vtoolbar"><strong>Divergência: competências de depreciação não geradas</strong>
      <span class="vsub"><?= count($divergentes) ?> ativo(s)</span></div>
    <div style="padding:0 14px 12px">
      <?php foreach ($divergentes as $dv): ?>
        <div style="padding:6px 0;border-bottom:1px solid var(--vero-border,#eee)">
          <strong><?= h($dv['descricao']) ?></strong> —
          acumulada gerada <strong class="vnum">R$ <?= numFmt($dv['acumulada'], 2) ?></strong> ·
          depreciação linear desde a aquisição: <strong class="vnum">R$ <?= numFmt($dv['teorica'], 2) ?></strong>
          (<?= (int)$dv['meses'] ?> competências, <?= (int)$dv['pendentes'] ?> não gerada<?= $dv['pendentes'] === 1 ? '' : 's' ?>) ·
          valor líquido econômico estimado: <strong class="vnum">R$ <?= numFmt($dv['liquido_econ'], 2) ?></strong>
        </div>
      <?php endforeach; ?>
      <div class="vhint" style="padding-top:8px">
        A geração de depreciação é manual por competência (Patrimônio → Depreciação). Gere as
        competências em ordem ou solicite o backfill ao A0 — o backfill em massa não está autorizado.
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Por categoria</strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/patrimonio/ativos.php">Gerenciar ativos</a></div>
    <?php if (!$porCat): ?>
      <div class="vempty">Nenhum ativo cadastrado — comece em Patrimônio → Ativos.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Categoria</th>
        <th style="text-align:right">Ativos</th>
        <th style="text-align:right">Aquisição (R$)</th>
        <th style="text-align:right">Depr. acumulada GERADA (R$)</th>
        <th style="text-align:right">Líquido gerencial (R$)</th>
        <?php if ($temDivergencia): ?>
        <th style="text-align:right">Depr. teórica (R$)</th>
        <th style="text-align:right">Líquido econômico (R$)</th>
        <?php endif; ?>
        <th style="width:24%">Comparativo</th>
      </tr></thead>
      <tbody>
      <?php foreach ($porCat as $cat => $d):
          $pct = $maxLiq > 0 ? $d['liquido'] / $maxLiq * 100 : 0; ?>
        <tr>
          <td><strong><?= h((string)$cat) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= (int)$d['n'] ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($d['aquisicao'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= $d['acumulada'] > 0 ? numFmt($d['acumulada'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($d['liquido'], 2) ?></strong></td>
          <?php if ($temDivergencia): ?>
          <td class="vnum" style="text-align:right"><?= $d['teorica'] > 0 ? numFmt($d['teorica'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($d['liq_econ'], 2) ?></td>
          <?php endif; ?>
          <td><div style="height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
            <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="border-top:2px solid var(--vero-border,#ccc)">
          <td><strong>Total</strong></td>
          <td class="vnum" style="text-align:right"><strong><?= count($ativos) ?></strong></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($totAq, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($totAc, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($totLiq, 2) ?></strong></td>
          <?php if ($temDivergencia): ?>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($totTeo, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($totLiqEcon, 2) ?></strong></td>
          <?php endif; ?>
          <td></td>
        </tr>
      </tfoot>
    </table>
    <div class="vhint" style="padding:10px 14px">
      A acumulada GERADA usa a última competência lançada em Patrimônio → Depreciação; o líquido
      nunca fica abaixo do valor residual do ativo. A "Depr. teórica" é a linear desde a aquisição
      (cota mensal × meses decorridos, limitada à vida útil) e aparece quando há competências não
      geradas. Visão gerencial — não substitui a contábil.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
