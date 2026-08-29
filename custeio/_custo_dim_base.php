<?php
/* ============================================================
   VERO — Custos / base compartilhada de consolidação por dimensão
   Incluída por custo_fazenda.php e custo_cultura.php, que definem:
     $DIM_CAMPO  — expressão SQL do rótulo (após os JOINs abaixo)
     $DIM_MICRO, $DIM_VIEW, $DIM_TITULO, $DIM_SUB, $DIM_COLUNA
   Mesma fonte do Custo por Válvula (custeio_lancamentos), agregada
   na dimensão pedida, com categorias em colunas dinâmicas.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$fSafra = (int)($_GET['safra'] ?? 0);
$fIni   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';

$where  = "cl.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($fSafra > 0)  { $where .= " AND cl.safra_id = :s";          $params[':s'] = $fSafra; }
if ($fIni !== '') { $where .= " AND cl.data_competencia >= :i"; $params[':i'] = $fIni; }
if ($fFim !== '') { $where .= " AND cl.data_competencia <= :f"; $params[':f'] = $fFim; }

$categorias = array_map('strval', array_column(vero_rows(
    "SELECT DISTINCT COALESCE(cl.categoria,'outros') AS categoria
       FROM custeio_lancamentos cl WHERE {$where} ORDER BY categoria", $params), 'categoria'));

$linhas = vero_rows(
    "SELECT COALESCE({$DIM_CAMPO}, 'Não identificado') AS rotulo,
            COALESCE(cl.categoria,'outros') AS categoria, SUM(cl.valor) AS total
       FROM custeio_lancamentos cl
       LEFT JOIN agro_talhoes t ON t.id = cl.talhao_id
       LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
       LEFT JOIN agro_culturas cu ON cu.id = cl.cultura_id
      WHERE {$where}
      GROUP BY rotulo, categoria", $params);

$porDim = [];
foreach ($linhas as $l) {
    $r = (string)$l['rotulo'];
    if (!isset($porDim[$r])) $porDim[$r] = ['cats' => [], 'total' => 0.0];
    $porDim[$r]['cats'][(string)$l['categoria']] = (float)$l['total'];
    $porDim[$r]['total'] += (float)$l['total'];
}
uasort($porDim, static fn($a, $b) => $b['total'] <=> $a['total']);
$totalGeral = array_sum(array_column($porDim, 'total'));

$safras = vero_options('agro_safras', 'identificacao');

$GUARD      = ['macro' => 'custos', 'micro' => $DIM_MICRO];
$PAGE_VIEW  = $DIM_VIEW;
$PAGE_TITLE = $DIM_TITULO;
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$rotuloCat = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header($DIM_TITULO, $DIM_SUB, null) ?>

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

    <?php if (!$porDim): ?>
      <div class="vempty">Nenhum lançamento de custeio no filtro.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th><?= h($DIM_COLUNA) ?></th>
        <?php foreach ($categorias as $cat): ?>
          <th style="text-align:right"><?= h($rotuloCat($cat)) ?></th>
        <?php endforeach; ?>
        <th style="text-align:right">Total (R$)</th>
        <th style="width:22%">Participação</th>
      </tr></thead>
      <tbody>
      <?php foreach ($porDim as $rotulo => $d):
          $pct = $totalGeral > 0 ? $d['total'] / $totalGeral * 100 : 0; ?>
        <tr>
          <td><strong><?= h((string)$rotulo) ?></strong></td>
          <?php foreach ($categorias as $cat): ?>
            <td class="vnum" style="text-align:right"><?= isset($d['cats'][$cat]) ? numFmt($d['cats'][$cat], 2) : '—' ?></td>
          <?php endforeach; ?>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($d['total'], 2) ?></strong></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
                <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
              </div>
              <span class="vnum vhint"><?= numFmt($pct, 1) ?>%</span>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
