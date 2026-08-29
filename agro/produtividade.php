<?php
/* ============================================================
   VERO — Agrícola / Produtividade por Talhão (DASHBOARD, leitura)
   C-17: a tabela virou dashboard — KPIs + barras de
   atingimento POR VÁLVULA clicáveis (drill-down mostra as colheitas
   que compõem o número). Filtro por safra. Rota/slug/guard intactos.
   Rota: /agro/produtividade.php · Guard: agricola.produtividade
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fSafra = (int)($_GET['safra'] ?? 0);

$safras = vero_rows("SELECT id, identificacao FROM agro_safras WHERE tenant_id = :t ORDER BY id DESC", [':t' => $t]);

$where  = "cr.tenant_id = :t";
$params = [':t' => $t];
if ($fSafra > 0) { $where .= " AND cr.safra_id = :s"; $params[':s'] = $fSafra; }

$rows = vero_rows(
    "SELECT cr.talhao_id, cr.safra_id,
            tl.codigo AS talhao, fz.nome AS fazenda, sa.identificacao AS safra,
            tl.area_ha, st.area_plantada_ha, st.produtividade_planejada, st.unidade_produtividade,
            SUM(cr.kg_total_previsto) AS kg_previsto,
            SUM(cr.kg_total_realizado) AS kg_realizado,
            AVG(cr.producao_realizada_kg_ha) AS kgha_medio
       FROM colheita_registros cr
       LEFT JOIN agro_talhoes tl ON tl.id = cr.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
       LEFT JOIN agro_safras sa ON sa.id = cr.safra_id
       LEFT JOIN agro_safra_talhoes st ON st.id = cr.safra_talhao_id
      WHERE {$where}
      GROUP BY cr.talhao_id, cr.safra_id, tl.codigo, fz.nome, sa.identificacao,
               tl.area_ha, st.area_plantada_ha, st.produtividade_planejada, st.unidade_produtividade
      ORDER BY sa.identificacao DESC, fz.nome, tl.codigo", $params);

/* drill-down: colheitas individuais por válvula×safra (mesmo filtro) */
$detalhes = [];
foreach (vero_rows(
    "SELECT cr.talhao_id, cr.safra_id, cr.data_colheita, cr.kg_total_previsto, cr.kg_total_realizado
       FROM colheita_registros cr
      WHERE {$where}
      ORDER BY cr.data_colheita", $params) as $d) {
    $detalhes[(int)$d['talhao_id'] . '-' . (int)$d['safra_id']][] = $d;
}

/* KPIs */
$kgTotal = 0.0; $areaTotal = 0.0; $pctSoma = 0.0; $pctN = 0;
$linhas = [];
foreach ($rows as $r) {
    $area = $r['area_plantada_ha'] !== null && (float)$r['area_plantada_ha'] > 0
          ? (float)$r['area_plantada_ha']
          : ($r['area_ha'] !== null ? (float)$r['area_ha'] : null);
    $kgha = $area > 0 ? (float)$r['kg_realizado'] / $area : ($r['kgha_medio'] !== null ? (float)$r['kgha_medio'] : null);
    $plan = $r['produtividade_planejada'] !== null ? (float)$r['produtividade_planejada'] : null;
    $pct  = ($plan !== null && $plan > 0 && $kgha !== null) ? $kgha / $plan * 100 : null;
    $kgTotal += (float)$r['kg_realizado'];
    if ($area) $areaTotal += $area;
    if ($pct !== null) { $pctSoma += $pct; $pctN++; }
    $linhas[] = ['r' => $r, 'area' => $area, 'kgha' => $kgha, 'plan' => $plan, 'pct' => $pct];
}
$kghaGeral = $areaTotal > 0 ? $kgTotal / $areaTotal : null;
$pctMedio  = $pctN > 0 ? $pctSoma / $pctN : null;

$GUARD      = ['macro' => 'agricola', 'micro' => 'produtividade'];
$PAGE_VIEW  = 'agricola_produtividade';
$PAGE_TITLE = 'Produtividade por Talhão';
$EXTRA_HEAD = vero_assets() . <<<'HTML'
<style>
.prod-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px}
.prod-kpi{background:#fff;border:1px solid #EEE8DB;border-radius:12px;padding:14px 16px}
.prod-kpi .k{font-size:11px;font-weight:700;color:#8A7D6E;text-transform:uppercase;letter-spacing:.05em}
.prod-kpi .v{font-size:22px;font-weight:700;margin-top:4px;font-family:'IBM Plex Mono',monospace}
.prod-linha{cursor:pointer}
.prod-linha:hover{background:#FAF7F0}
.prod-barra{position:relative;height:18px;background:#F0EBE0;border-radius:9px;overflow:hidden;min-width:140px}
.prod-barra > span{position:absolute;inset:0 auto 0 0;border-radius:9px;background:#4E7B54}
.prod-barra > em{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-style:normal;font-size:11px;font-weight:700;color:#2B2018}
.prod-det td{background:#FAF7F0;font-size:12px}
</style>
HTML;
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Produtividade por Talhão', 'Dashboard: atingimento por válvula (clique na linha para ver as colheitas)', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <select name="safra" onchange="this.form.submit()">
          <option value="">Todas as safras</option>
          <?php foreach ($safras as $s): ?>
            <option value="<?= (int)$s['id'] ?>"<?= $fSafra === (int)$s['id'] ? ' selected' : '' ?>><?= h($s['identificacao']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= count($linhas) ?> válvula(ões)×safra(s) com colheita</span>
    </div>
  </div>

  <div class="prod-kpis">
    <div class="prod-kpi"><div class="k">Colhido (kg)</div><div class="v"><?= numFmt($kgTotal, 0) ?></div></div>
    <div class="prod-kpi"><div class="k">Área com colheita (ha)</div><div class="v"><?= numFmt($areaTotal, 2) ?></div></div>
    <div class="prod-kpi"><div class="k">Produtividade média (kg/ha)</div><div class="v"><?= $kghaGeral !== null ? numFmt($kghaGeral, 0) : '—' ?></div></div>
    <div class="prod-kpi"><div class="k">Atingimento médio do plano</div><div class="v"><?= $pctMedio !== null ? numFmt($pctMedio, 1) . '%' : '—' ?></div></div>
  </div>

  <div class="vcard">
    <?php if (!$linhas): ?>
      <div class="vempty">Nenhuma colheita registrada<?= $fSafra ? ' nesta safra' : '' ?>.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Válvula</th><th>Safra</th>
        <th style="text-align:right">Área (ha)</th>
        <th style="text-align:right">kg/ha realizado</th>
        <th style="min-width:160px">Atingimento do plano</th>
        <th style="text-align:right">Colhido (kg)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($linhas as $ln): $r = $ln['r'];
          $chave = (int)$r['talhao_id'] . '-' . (int)$r['safra_id'];
          $dets = $detalhes[$chave] ?? []; ?>
        <tr class="prod-linha" onclick="prodToggle('<?= h($chave) ?>')"
            title="Clique para ver as colheitas desta válvula">
          <td><strong><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></strong></td>
          <td><?= h($r['safra'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= $ln['area'] !== null ? numFmt($ln['area'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= $ln['kgha'] !== null ? numFmt($ln['kgha'], 0) : '—' ?></strong></td>
          <td>
            <?php if ($ln['pct'] !== null): $w = min(100, $ln['pct']);
                $cor = $ln['pct'] >= 100 ? '#4E7B54' : ($ln['pct'] < 80 ? '#B3402A' : '#C89B3C'); ?>
              <div class="prod-barra"><span style="width:<?= numFmt($w, 1) ?>%;background:<?= $cor ?>"></span>
                <em><?= numFmt($ln['pct'], 1) ?>%</em></div>
            <?php else: ?><span class="vhint">sem plano</span><?php endif; ?>
          </td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['kg_realizado'], 0) ?></td>
        </tr>
        <tr class="prod-det" id="det-<?= h($chave) ?>" style="display:none">
          <td colspan="6">
            <?php if ($dets): ?>
              <strong>Colheitas:</strong>
              <?php foreach ($dets as $d): ?>
                <span style="display:inline-block;margin:3px 10px 3px 0">
                  <?= date('d/m/Y', strtotime((string)$d['data_colheita'])) ?> —
                  <?= numFmt((float)$d['kg_total_realizado'], 0) ?> kg
                  <span class="vhint">(previsto <?= numFmt((float)$d['kg_total_previsto'], 0) ?>)</span>
                </span>
              <?php endforeach; ?>
            <?php else: ?><span class="vhint">Sem registros individuais.</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">Área: do plano de safra, ou cadastral da válvula.</div>
    <?php endif; ?>
  </div>
</div>
<script>
function prodToggle(chave) {
  const tr = document.getElementById('det-' + chave);
  if (tr) tr.style.display = tr.style.display === 'none' ? '' : 'none';
}
</script>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
