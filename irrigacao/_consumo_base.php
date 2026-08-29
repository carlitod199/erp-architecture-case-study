<?php
/* ============================================================
   VERO — Irrigação / base compartilhada de Consumos
   Incluída por consumo_agua.php e consumo_energia.php, que definem:
     $CON_TIPO ('agua'|'energia'), $CON_MICRO, $CON_VIEW,
     $CON_TITULO, $CON_SUB, $CON_UN (rótulo da unidade)
   Fonte: irrigacao_consumos × irrigacao_apontamentos — detalhe por
   lançamento + consolidação por válvula e por mês.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$fTalhao = (int)($_GET['talhao'] ?? 0);
$fIni    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';

$t = vero_tenant();
$where  = "c.tenant_id = :t AND c.tipo = :tp";
$params = [':t' => $t, ':tp' => $CON_TIPO];
if ($fTalhao > 0)  { $where .= " AND a.talhao_id = :tal";        $params[':tal'] = $fTalhao; }
if ($fIni !== '')  { $where .= " AND a.data_apontamento >= :i";  $params[':i'] = $fIni; }
if ($fFim !== '')  { $where .= " AND a.data_apontamento <= :f";  $params[':f'] = $fFim; }

$linhas = vero_rows(
    "SELECT c.quantidade, c.unidade, c.custo,
            a.data_apontamento, a.horas, a.lamina_mm,
            tl.codigo AS talhao, fz.nome AS fazenda, tl.area_ha
       FROM irrigacao_consumos c
       JOIN irrigacao_apontamentos a ON a.id = c.apontamento_id
       LEFT JOIN agro_talhoes tl ON tl.id = a.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
      WHERE {$where}
      ORDER BY a.data_apontamento DESC, c.id DESC LIMIT 300", $params);

/* R2-05: rastreabilidade do custo. O schema não guarda a distinção manual×auto
   (C-21) — derivamos: |custo − qtd × tarifa vigente| ≤ 0,01 → compatível com a
   tarifa (badge "auto"); senão foi digitado ou lançado antes da tarifa (badge
   "manual" — não derivável da tarifa atual). */
$tarifasIrr = json_decode((string)(vero_val(
    "SELECT valor FROM tenant_parametros WHERE tenant_id = :t AND chave = 'irrigacao.tarifas'",
    [':t' => $t]) ?: ''), true) ?: [];
$tarifaVig = (float)($tarifasIrr[$CON_TIPO === 'agua' ? 'agua_m3' : 'energia_kwh'] ?? 0);

$totQtd   = array_sum(array_map(static fn($l) => (float)$l['quantidade'], $linhas));
$totCusto = array_sum(array_map(static fn($l) => (float)($l['custo'] ?? 0), $linhas));
$totHoras = array_sum(array_map(static fn($l) => (float)($l['horas'] ?? 0), $linhas));

/* consolidação por válvula */
$porTalhao = [];
foreach ($linhas as $l) {
    $k = trim(($l['fazenda'] ?? '') . ' — ' . ($l['talhao'] ?? 'Sem válvula'), ' —');
    if (!isset($porTalhao[$k])) $porTalhao[$k] = ['qtd' => 0.0, 'custo' => 0.0, 'area' => $l['area_ha'] !== null ? (float)$l['area_ha'] : null];
    $porTalhao[$k]['qtd']   += (float)$l['quantidade'];
    $porTalhao[$k]['custo'] += (float)($l['custo'] ?? 0);
}
uasort($porTalhao, static fn($a, $b) => $b['qtd'] <=> $a['qtd']);
$maxQtd = $porTalhao ? max(array_column($porTalhao, 'qtd')) : 0.0;

$talhoes = vero_rows(
    "SELECT t.id, t.codigo, f.nome AS fazenda FROM agro_talhoes t
      LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
     WHERE t.tenant_id = :t ORDER BY f.nome, t.codigo", [':t' => $t]);

$GUARD      = ['macro' => 'irrigacao', 'micro' => $CON_MICRO];
$PAGE_VIEW  = $CON_VIEW;
$PAGE_TITLE = $CON_TITULO;
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header($CON_TITULO, $CON_SUB, null) ?>
  <?php require_once __DIR__ . '/_consumos_abas.php'; echo vero_consumos_abas($CON_TIPO); /* C-42 */ ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <select name="talhao" onchange="this.form.submit()">
          <option value="">Todas as válvulas</option>
          <?php foreach ($talhoes as $tl): ?>
            <option value="<?= (int)$tl['id'] ?>"<?= $fTalhao === (int)$tl['id'] ? ' selected' : '' ?>>
              <?= h(($tl['fazenda'] ? $tl['fazenda'] . ' — ' : '') . $tl['codigo']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub">total <strong class="vnum"><?= numFmt($totQtd, 1) ?> <?= h($CON_UN) ?></strong> ·
        custo <strong class="vnum">R$ <?= numFmt($totCusto, 2) ?></strong> ·
        <?= numFmt($totHoras, 1) ?> h irrigadas</span>
    </div>

    <?php if ($porTalhao): ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Válvula</th>
        <th class="num">Consumo (<?= h($CON_UN) ?>)</th>
        <th class="num"><?= h($CON_UN) ?>/ha</th>
        <th class="num">Custo (R$)</th>
        <th class="num">R$/<?= h($CON_UN) ?></th>
        <th style="width:22%">Comparativo</th>
      </tr></thead>
      <tbody>
      <?php foreach ($porTalhao as $rotulo => $d):
          $pct = $maxQtd > 0 ? $d['qtd'] / $maxQtd * 100 : 0; ?>
        <tr>
          <td><strong><?= h((string)$rotulo) ?></strong>
            <?= $d['area'] !== null ? '<span class="vhint">' . numFmt($d['area'], 2) . ' ha</span>' : '' ?></td>
          <td class="num"><strong><?= numFmt($d['qtd'], 1) ?></strong></td>
          <td class="num"><?= $d['area'] > 0 ? numFmt($d['qtd'] / $d['area'], 1) : '—' ?></td>
          <td class="num"><?= numFmt($d['custo'], 2) ?></td>
          <td class="num"><?= $d['qtd'] > 0 ? numFmt($d['custo'] / $d['qtd'], 4) : '—' ?></td>
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

  <div class="vcard">
    <div class="vtoolbar"><strong>Lançamentos</strong>
      <span class="vsub"><?= count($linhas) ?> registro(s)<?= count($linhas) === 300 ? ' (últimos 300)' : '' ?></span></div>
    <?php if (!$linhas): ?>
      <div class="vempty">Nenhum consumo no filtro — os consumos nascem nos apontamentos de irrigação.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Data</th><th>Válvula</th>
        <th class="num">Horas</th>
        <th class="num">Lâmina (mm)</th>
        <?php if ($CON_TIPO === 'agua'): ?>
          <th class="num" title="Lâmina implícita do consumo = m³ ÷ (área da válvula em ha × 10)">Lâmina do consumo (mm)</th>
        <?php endif; ?>
        <th class="num">Consumo</th>
        <th class="num">Custo (R$)</th>
        <th>Origem do custo</th>
      </tr></thead>
      <tbody>
      <?php foreach ($linhas as $l):
          /* R2-05: origem derivada — auto (qtd × tarifa vigente, ±0,01) × manual */
          $qtd   = (float)$l['quantidade'];
          $custo = $l['custo'] !== null ? (float)$l['custo'] : null;
          if ($custo === null || abs($custo) < 0.005) {
              $origemHtml = '<span class="vhint">sem custo</span>';
          } elseif ($tarifaVig > 0 && $qtd > 0 && abs($custo - round($qtd * $tarifaVig, 2)) <= 0.01) {
              $origemHtml = '<span class="vbadge vb-ok" title="Custo compatível com quantidade × tarifa vigente">auto (' . numFmt($qtd, 1) . ' × tarifa R$ ' . numFmt($tarifaVig, 4) . ')</span>';
          } else {
              $origemHtml = '<span class="vbadge vb-off" title="Custo digitado no apontamento (ou anterior à tarifa) — não derivável da tarifa atual'
                  . ($tarifaVig > 0 ? ' de R$ ' . numFmt($tarifaVig, 4) : '') . '">manual / tarifa da época</span>';
          }
      ?>
        <tr>
          <td class="vnum"><strong><?= date('d/m/Y', strtotime((string)$l['data_apontamento'])) ?></strong></td>
          <td><?= h(trim(($l['fazenda'] ?? '') . ' — ' . ($l['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td class="num"><?= $l['horas'] !== null ? numFmt((float)$l['horas'], 1) : '—' ?></td>
          <td class="num"><?= $l['lamina_mm'] !== null ? numFmt((float)$l['lamina_mm'], 1) : '—' ?></td>
          <?php if ($CON_TIPO === 'agua'): ?>
            <td class="num" title="<?= numFmt($qtd, 1) ?> m³ ÷ (<?= $l['area_ha'] !== null ? numFmt((float)$l['area_ha'], 2) : '?' ?> ha × 10)">
              <?= $l['area_ha'] !== null && (float)$l['area_ha'] > 0 ? numFmt($qtd / ((float)$l['area_ha'] * 10), 2) : '—' ?></td>
          <?php endif; ?>
          <td class="num"><strong><?= numFmt($qtd, 1) ?></strong>
            <span class="vhint"><?= h($l['unidade'] ?? $CON_UN) ?></span></td>
          <td class="num"><?= $custo !== null ? numFmt($custo, 2) : '—' ?></td>
          <td><?= $origemHtml ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      <strong>Origem do custo</strong>: “auto” quando o valor equivale a quantidade × tarifa vigente
      (<?= $tarifaVig > 0 ? 'R$ ' . numFmt($tarifaVig, 4) . '/' . h($CON_UN) : 'nenhuma tarifa gravada' ?>, tolerância R$ 0,01);
      “manual / tarifa da época” quando foi digitado no apontamento ou lançado antes da tarifa — não derivável da tarifa atual.
      <?php if ($CON_TIPO === 'agua'): ?>
        <br><strong>Lâmina do consumo</strong> = consumo (m³) ÷ (área da válvula em ha × 10) — confronte com a lâmina registrada no apontamento.
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
