<?php
/* ============================================================
   VERO — Custos / Custo Realizado  (tela real, leitura)
   Substitui o mock. Rota: /custeio/custo_realizado.php
   Guard: custos.custo_realizado
   Custeio realizado mês a mês × categoria (matriz), com filtro
   de safra e ano — visão de evolução do gasto no tempo.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fSafra = (int)($_GET['safra'] ?? 0);
$fAno   = (int)($_GET['ano'] ?? date('Y'));
if ($fAno < 2000 || $fAno > 2100) $fAno = (int)date('Y');

$where  = "cl.tenant_id = :t AND YEAR(cl.data_competencia) = :a";
$params = [':t' => $t, ':a' => $fAno];
if ($fSafra > 0) { $where .= " AND cl.safra_id = :s"; $params[':s'] = $fSafra; }

$anos = array_map('intval', array_column(vero_rows(
    "SELECT DISTINCT YEAR(data_competencia) AS a FROM custeio_lancamentos
      WHERE tenant_id = :t AND data_competencia IS NOT NULL ORDER BY a DESC", [':t' => $t]), 'a'));
if (!in_array($fAno, $anos, true)) $anos[] = $fAno;

$linhas = vero_rows(
    "SELECT MONTH(cl.data_competencia) AS mes, COALESCE(cl.categoria,'outros') AS categoria, SUM(cl.valor) AS total
       FROM custeio_lancamentos cl WHERE {$where}
      GROUP BY mes, categoria", $params);

$categorias = [];
$matriz = [];
$porMes = array_fill(1, 12, 0.0);
foreach ($linhas as $l) {
    $cat = (string)$l['categoria'];
    $mes = (int)$l['mes'];
    $categorias[$cat] = true;
    $matriz[$cat][$mes] = (float)$l['total'];
    $porMes[$mes] += (float)$l['total'];
}
ksort($categorias);
$totalGeral = array_sum($porMes);

$safras = vero_options('agro_safras', 'identificacao');
$NOME_MES = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];

$GUARD      = ['macro' => 'custos', 'micro' => 'custo_realizado'];
$PAGE_VIEW  = 'custos_custo_realizado';
$PAGE_TITLE = 'Custo Realizado';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$rotuloCat = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Custo Realizado', 'Evolução mensal do custeio por categoria — competência dos lançamentos', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="ano" onchange="this.form.submit()">
          <?php foreach ($anos as $a): ?>
            <option value="<?= $a ?>"<?= $a === $fAno ? ' selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
        <select name="safra" onchange="this.form.submit()">
          <option value="">Todas as safras</option>
          <?php foreach ($safras as $sid => $sn): ?>
            <option value="<?= $sid ?>"<?= $fSafra === (int)$sid ? ' selected' : '' ?>><?= h($sn) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub">total <?= $fAno ?>: <strong class="vnum">R$ <?= numFmt($totalGeral, 2) ?></strong></span>
    </div>

    <?php if (!$matriz): ?>
      <div class="vempty">Nenhum lançamento de custeio no filtro.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th>Categoria</th>
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <th style="text-align:right"><?= $NOME_MES[$m] ?></th>
        <?php endfor; ?>
        <th style="text-align:right">Total (R$)</th>
      </tr></thead>
      <tbody>
      <?php foreach (array_keys($categorias) as $cat):
          $totCat = array_sum($matriz[$cat] ?? []); ?>
        <tr>
          <td><strong><?= h($rotuloCat((string)$cat)) ?></strong></td>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <td class="vnum" style="text-align:right"><?= isset($matriz[$cat][$m]) ? numFmt($matriz[$cat][$m], 2) : '—' ?></td>
          <?php endfor; ?>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($totCat, 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="border-top:2px solid var(--vero-border,#ccc)">
          <td><strong>Total mês</strong></td>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <td class="vnum" style="text-align:right"><strong><?= $porMes[$m] > 0 ? numFmt($porMes[$m], 2) : '—' ?></strong></td>
          <?php endfor; ?>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($totalGeral, 2) ?></strong></td>
        </tr>
      </tfoot>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
