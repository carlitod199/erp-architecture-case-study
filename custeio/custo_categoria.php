<?php
/* ============================================================
   VERO — Custos / Custo por Categoria  (tela real, leitura)
   Substitui o mock. Rota: /custeio/custo_categoria.php
   Guard: custos.custo_categoria
   Consolidação por categoria com participação e detalhamento
   por origem (rh_producao_item, apontamento_insumo…).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$fSafra = (int)($_GET['safra'] ?? 0);
$fIni   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';

$where  = "cl.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($fSafra > 0) { $where .= " AND cl.safra_id = :s";           $params[':s'] = $fSafra; }
if ($fIni !== '') { $where .= " AND cl.data_competencia >= :i"; $params[':i'] = $fIni; }
if ($fFim !== '') { $where .= " AND cl.data_competencia <= :f"; $params[':f'] = $fFim; }

$categorias = vero_rows(
    "SELECT COALESCE(cl.categoria,'outros') AS categoria,
            COUNT(*) AS lancamentos, SUM(cl.valor) AS total
       FROM custeio_lancamentos cl
      WHERE {$where}
      GROUP BY categoria ORDER BY total DESC", $params);
$totalGeral = 0.0;
foreach ($categorias as $c) $totalGeral += (float)$c['total'];

$origens = vero_rows(
    "SELECT COALESCE(cl.categoria,'outros') AS categoria, cl.origem_tipo,
            COUNT(*) AS lancamentos, SUM(cl.valor) AS total
       FROM custeio_lancamentos cl
      WHERE {$where}
      GROUP BY categoria, cl.origem_tipo ORDER BY categoria, total DESC", $params);
$origensPorCat = [];
foreach ($origens as $o) $origensPorCat[(string)$o['categoria']][] = $o;

$safras = vero_options('agro_safras', 'identificacao');

$GUARD      = ['macro' => 'custos', 'micro' => 'custo_categoria'];
$PAGE_VIEW  = 'custos_custo_categoria';
$PAGE_TITLE = 'Custo por Categoria';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$rotulo = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Custo por Categoria', 'Onde o dinheiro está indo — categorias do custeio com participação e detalhamento por origem', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <select name="safra" onchange="this.form.submit()">
          <option value="">Todas as safras</option>
          <?php foreach ($safras as $sid => $sn): ?>
            <option value="<?= $sid ?>"<?= $fSafra === $sid ? ' selected' : '' ?>><?= h($sn) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub">custo total: <strong class="vnum">R$ <?= numFmt($totalGeral, 2) ?></strong></span>
    </div>

    <?php if (!$categorias): ?>
      <div class="vempty">Nenhum lançamento de custeio no filtro.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Categoria</th>
        <th style="text-align:right">Lançamentos</th>
        <th style="text-align:right">Total (R$)</th>
        <th style="text-align:right">% do custo</th>
        <th style="width:30%">Participação</th>
      </tr></thead>
      <tbody>
      <?php foreach ($categorias as $c):
          $pct = $totalGeral > 0 ? (float)$c['total'] / $totalGeral * 100 : 0; ?>
        <tr>
          <td><strong><?= h($rotulo((string)$c['categoria'])) ?></strong>
            <?php foreach (($origensPorCat[(string)$c['categoria']] ?? []) as $o): ?>
              <div class="vhint"><?= h(str_replace('_', ' ', (string)($o['origem_tipo'] ?? 'manual'))) ?>:
                R$ <?= numFmt((float)$o['total'], 2) ?> (<?= (int)$o['lancamentos'] ?>)</div>
            <?php endforeach; ?>
          </td>
          <td class="vnum" style="text-align:right"><?= (int)$c['lancamentos'] ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$c['total'], 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt($pct, 1) ?>%</td>
          <td><div style="height:12px;background:#F2EDE2;border-radius:6px;overflow:hidden">
            <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:6px"></div>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
