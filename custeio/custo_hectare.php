<?php
/* ============================================================
   VERO — Custos / Custo por Hectare  (tela real, leitura)
   Substitui o mock. Rota: /custeio/custo_hectare.php
   Guard: custos.custo_hectare
   Custo consolidado por válvula ÷ área. Área usada: área plantada
   da safra (agro_safra_talhoes) quando o filtro de safra está
   ativo e ela existe; senão a área cadastral da válvula.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$fSafra = (int)($_GET['safra'] ?? 0);
$fIni   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';

$where  = "cl.tenant_id = :t AND cl.talhao_id IS NOT NULL";
$params = [':t' => vero_tenant()];
if ($fSafra > 0)  { $where .= " AND cl.safra_id = :s";          $params[':s'] = $fSafra; }
if ($fIni !== '') { $where .= " AND cl.data_competencia >= :i"; $params[':i'] = $fIni; }
if ($fFim !== '') { $where .= " AND cl.data_competencia <= :f"; $params[':f'] = $fFim; }

$linhas = vero_rows(
    "SELECT cl.talhao_id, SUM(cl.valor) AS total,
            t.codigo AS talhao, t.area_ha, f.nome AS fazenda,
            st.area_plantada_ha, cu.nome AS cultura
       FROM custeio_lancamentos cl
       LEFT JOIN agro_talhoes t ON t.id = cl.talhao_id
       LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
       LEFT JOIN agro_safra_talhoes st ON st.id = cl.safra_talhao_id
       LEFT JOIN agro_culturas cu ON cu.id = COALESCE(cl.cultura_id, st.cultura_id)
      WHERE {$where}
      GROUP BY cl.talhao_id, t.codigo, t.area_ha, f.nome, st.area_plantada_ha, cu.nome
      ORDER BY total DESC", $params);

$dados = [];
foreach ($linhas as $l) {
    $area = null;
    if ($fSafra > 0 && $l['area_plantada_ha'] !== null && (float)$l['area_plantada_ha'] > 0) {
        $area = (float)$l['area_plantada_ha'];
        $origemArea = 'safra';
    } elseif ($l['area_ha'] !== null && (float)$l['area_ha'] > 0) {
        $area = (float)$l['area_ha'];
        $origemArea = 'válvula';
    } else {
        $origemArea = null;
    }
    $dados[] = [
        'rotulo'  => trim(($l['fazenda'] ?? '') . ' — ' . ($l['talhao'] ?? 'Sem válvula'), ' —'),
        'cultura' => $l['cultura'],
        'total'   => (float)$l['total'],
        'area'    => $area,
        'origem'  => $origemArea,
        'por_ha'  => $area > 0 ? (float)$l['total'] / $area : null,
    ];
}
usort($dados, static fn($a, $b) => ($b['por_ha'] ?? -1) <=> ($a['por_ha'] ?? -1));
$maxHa = 0.0;
foreach ($dados as $d) if ($d['por_ha'] !== null && $d['por_ha'] > $maxHa) $maxHa = $d['por_ha'];
$totalGeral = array_sum(array_column($dados, 'total'));
$areaTotal  = array_sum(array_map(static fn($d) => $d['area'] ?? 0.0, $dados));

/* ── R2-02: base do indicador EXPLÍCITA — a "média" abaixo divide o custo pela
   área das VÁLVULAS COM CUSTO no filtro (leitura histórica desta tela, mantida
   p/ não quebrar aceites). Quando há filtro de safra e a área produtiva TOTAL
   da safra (Σ agro_safra_talhoes.area_plantada_ha — base do custo/ha no
   Resultado da Safra e no dashboard executivo) diverge, a segunda leitura é
   exibida ao lado, rotulada. */
$areaSafraProd = $fSafra > 0 ? (float)vero_val(
    "SELECT COALESCE(SUM(st.area_plantada_ha),0) FROM agro_safra_talhoes st
      WHERE st.tenant_id = :t AND st.safra_id = :s", [':t' => vero_tenant(), ':s' => $fSafra]) : 0.0;

$safras = vero_options('agro_safras', 'identificacao');

$GUARD      = ['macro' => 'custos', 'micro' => 'custo_hectare'];
$PAGE_VIEW  = 'custos_custo_hectare';
$PAGE_TITLE = 'Custo por Hectare';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Custo por Hectare', 'Custo consolidado da válvula dividido pela área — plantada da safra quando filtrada, senão a cadastral', null) ?>

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
      <span class="vsub">custo <strong class="vnum">R$ <?= numFmt($totalGeral, 2) ?></strong> ·
        média <strong class="vnum">R$ <?= $areaTotal > 0 ? numFmt($totalGeral / $areaTotal, 2) : '—' ?>/ha</strong>
        <span class="vhint">(base: <?= numFmt($areaTotal, 2) ?> ha das válvulas com custo)</span>
        <?php /* R2-02: segunda leitura, na área produtiva total da safra */ ?>
        <?php if ($areaSafraProd > 0 && abs($areaSafraProd - $areaTotal) > 0.005): ?>
          · <span class="vhint">na área produtiva da safra (<?= numFmt($areaSafraProd, 2) ?> ha):</span>
          <strong class="vnum">R$ <?= numFmt($totalGeral / $areaSafraProd, 2) ?>/ha</strong>
        <?php endif; ?></span>
    </div>

    <?php if (!$dados): ?>
      <div class="vempty">Nenhum lançamento de custeio com válvula no filtro.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Válvula</th><th>Cultura</th>
        <th style="text-align:right">Área (ha)</th>
        <th style="text-align:right">Custo (R$)</th>
        <th style="text-align:right">Custo/ha (R$)</th>
        <th style="width:24%">Comparativo R$/ha</th>
      </tr></thead>
      <tbody>
      <?php foreach ($dados as $d):
          $pct = ($maxHa > 0 && $d['por_ha'] !== null) ? $d['por_ha'] / $maxHa * 100 : 0; ?>
        <tr>
          <td><strong><?= h($d['rotulo']) ?></strong></td>
          <td><?= h($d['cultura'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right">
            <?= $d['area'] !== null ? numFmt($d['area'], 2) : '—' ?>
            <?= $d['origem'] ? '<span class="vhint">(' . $d['origem'] . ')</span>' : '' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($d['total'], 2) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= $d['por_ha'] !== null ? numFmt($d['por_ha'], 2) : 'sem área' ?></strong></td>
          <td><div style="height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
            <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">Válvulas sem área cadastrada aparecem como "sem área" — complete o cadastro em Gestão Agrícola → Válvulas.</div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
