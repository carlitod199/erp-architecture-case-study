<?php
/* ============================================================
   VERO — Irrigação / Custo de Irrigação  (tela real, leitura)
   Substitui o mock. Rota: /irrigacao/custo_irrigacao.php
   Guard: irrigacao.custo_irrigacao
   Custeio da categoria irrigação por válvula e mês, com o detalhe
   dos consumos (água/energia) que o originaram.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fAno = (int)($_GET['ano'] ?? date('Y'));
if ($fAno < 2000 || $fAno > 2100) $fAno = (int)date('Y');

$anos = array_map('intval', array_column(vero_rows(
    "SELECT DISTINCT YEAR(data_competencia) AS a FROM custeio_lancamentos
      WHERE tenant_id = :t AND categoria = 'irrigacao' ORDER BY a DESC", [':t' => $t]), 'a'));
if (!in_array($fAno, $anos, true)) $anos[] = $fAno;

/* por válvula */
$porTalhao = vero_rows(
    "SELECT cl.talhao_id, tl.codigo AS talhao, fz.nome AS fazenda, tl.area_ha,
            SUM(cl.valor) AS total
       FROM custeio_lancamentos cl
       LEFT JOIN agro_talhoes tl ON tl.id = cl.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
      WHERE cl.tenant_id = :t AND cl.categoria = 'irrigacao' AND YEAR(cl.data_competencia) = :a
      GROUP BY cl.talhao_id, tl.codigo, fz.nome, tl.area_ha
      ORDER BY total DESC", [':t' => $t, ':a' => $fAno]);
$totAno = array_sum(array_map(static fn($r) => (float)$r['total'], $porTalhao));
$maxTal = $porTalhao ? max(array_map(static fn($r) => (float)$r['total'], $porTalhao)) : 0.0;

/* por mês */
$porMes = array_fill(1, 12, 0.0);
foreach (vero_rows(
    "SELECT MONTH(data_competencia) AS mes, SUM(valor) AS v FROM custeio_lancamentos
      WHERE tenant_id = :t AND categoria = 'irrigacao' AND YEAR(data_competencia) = :a
      GROUP BY mes", [':t' => $t, ':a' => $fAno]) as $r) {
    $porMes[(int)$r['mes']] = (float)$r['v'];
}
$maxMes = max($porMes);

/* consumos do ano (origem do custo) */
$consumos = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN c.tipo='agua' THEN c.quantidade END),0) AS agua_qtd,
            COALESCE(SUM(CASE WHEN c.tipo='agua' THEN c.custo END),0) AS agua_custo,
            COALESCE(SUM(CASE WHEN c.tipo='energia' THEN c.quantidade END),0) AS energia_qtd,
            COALESCE(SUM(CASE WHEN c.tipo='energia' THEN c.custo END),0) AS energia_custo
       FROM irrigacao_consumos c
       JOIN irrigacao_apontamentos a ON a.id = c.apontamento_id
      WHERE c.tenant_id = :t AND YEAR(a.data_apontamento) = :a", [':t' => $t, ':a' => $fAno]);

$NOME_MES = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];

$GUARD      = ['macro' => 'irrigacao', 'micro' => 'custo_irrigacao'];
$PAGE_VIEW  = 'irrigacao_custo_irrigacao';
$PAGE_TITLE = 'Custo de Irrigação';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Custo de Irrigação', 'Custeio da categoria irrigação — por válvula, por mês e a origem nos consumos', null) ?>
  <?php require_once __DIR__ . '/_consumos_abas.php'; echo vero_consumos_abas('custo'); /* C-42 */ ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <select name="ano" onchange="this.form.submit()">
          <?php foreach ($anos as $a): ?>
            <option value="<?= $a ?>"<?= $a === $fAno ? ' selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub">total <?= $fAno ?>: <strong class="vnum">R$ <?= numFmt($totAno, 2) ?></strong> ·
        água <?= numFmt((float)$consumos['agua_qtd'], 0) ?> m³ (R$ <?= numFmt((float)$consumos['agua_custo'], 2) ?>) ·
        energia <?= numFmt((float)$consumos['energia_qtd'], 0) ?> kWh (R$ <?= numFmt((float)$consumos['energia_custo'], 2) ?>)</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(12,1fr);gap:6px;padding:12px 14px;align-items:end">
      <?php for ($m = 1; $m <= 12; $m++):
          $hpx = $maxMes > 0 ? max(3, (int)round(52 * $porMes[$m] / $maxMes)) : 3; ?>
        <div style="text-align:center" title="<?= $NOME_MES[$m] ?>: R$ <?= numFmt($porMes[$m], 2) ?>">
          <div style="height:56px;display:flex;align-items:flex-end;justify-content:center">
            <div style="width:70%;height:<?= $hpx ?>px;border-radius:3px 3px 0 0;
                        background:<?= $porMes[$m] > 0 ? '#005059' : 'var(--vero-border,#e3e3e3)' ?>"></div>
          </div>
          <span class="vhint" style="font-size:.72rem"><?= $NOME_MES[$m] ?></span>
        </div>
      <?php endfor; ?>
    </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Por válvula</strong></div>
    <?php if (!$porTalhao): ?>
      <div class="vempty">Nenhum custo de irrigação no ano — os custos nascem dos consumos dos apontamentos.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Válvula</th>
        <th class="num">Custo (R$)</th>
        <th class="num">R$/ha</th>
        <th style="width:24%">Comparativo</th>
      </tr></thead>
      <tbody>
      <?php foreach ($porTalhao as $r):
          $pct = $maxTal > 0 ? (float)$r['total'] / $maxTal * 100 : 0; ?>
        <tr>
          <td><strong><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? 'Sem válvula'), ' —') ?: 'Sem válvula') ?></strong>
            <?= $r['area_ha'] !== null ? '<span class="vhint">' . numFmt((float)$r['area_ha'], 2) . ' ha</span>' : '' ?></td>
          <td class="num"><strong><?= numFmt((float)$r['total'], 2) ?></strong></td>
          <td class="num"><?= (float)($r['area_ha'] ?? 0) > 0 ? numFmt((float)$r['total'] / (float)$r['area_ha'], 2) : '—' ?></td>
          <td><div style="height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
            <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
