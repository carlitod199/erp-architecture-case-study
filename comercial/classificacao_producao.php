<?php
/* ============================================================
   VERO — Comercial / Classificação da Produção  (tela real, leitura)
   Substitui o mock. Rota: /comercial/classificacao_producao.php
   Guard: comercial.classificacao_producao
   Classificações da colheita (colheita_classificacoes): categorias
   previstas × realizadas com kg, preço e faturamento — o registro
   fica na tela de Colheita.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fSafra = (int)($_GET['safra'] ?? 0);

$where  = "cr.tenant_id = :t";
$params = [':t' => $t];
if ($fSafra > 0) { $where .= " AND cr.safra_id = :s"; $params[':s'] = $fSafra; }

/* consolida por categoria×momento */
$linhas = vero_rows(
    "SELECT cc.momento, cc.categoria,
            AVG(cc.percentual) AS pct_medio,
            SUM(cc.kg_calculado) AS kg,
            AVG(cc.preco_kg) AS preco_medio,
            SUM(cc.faturamento) AS faturamento
       FROM colheita_classificacoes cc
       JOIN colheita_registros cr ON cr.id = cc.registro_id
      WHERE {$where}
      GROUP BY cc.momento, cc.categoria
      ORDER BY cc.momento, faturamento DESC", $params);

$porMomento = [];
foreach ($linhas as $l) $porMomento[(string)$l['momento']][] = $l;

$safras = vero_options('agro_safras', 'identificacao');
$rotuloMomento = ['previsto' => 'Previsto', 'realizado' => 'Realizado'];

$GUARD      = ['macro' => 'comercial', 'micro' => 'classificacao_producao'];
$PAGE_VIEW  = 'comercial_classificacao_producao';
$PAGE_TITLE = 'Classificação da Produção';

/* ── Export CSV (antes de qualquer HTML) ─────────────────────────
   Relatório read-only: baixa as classificações consolidadas já filtradas
   (momento × categoria). Reusa o helper compartilhado vero_csv_stream e o
   guard canônico bios_guard (chamado manualmente pois não passa pelo header). */
if (($_GET['csv'] ?? '') === '1') {
    require_once __DIR__ . '/../includes/menu_agro.php';
    bios_guard($GUARD['macro'], $GUARD['micro']);
    require_once __DIR__ . '/../compras/_export.php';
    $colunas = [
        'momento' => 'Momento', 'categoria' => 'Categoria', 'pct_medio' => '% médio',
        'kg' => 'Kg', 'preco_medio' => 'R$/kg médio', 'faturamento' => 'Faturamento (R$)',
    ];
    $formato = ['pct_medio' => 'dec2', 'kg' => 'dec0', 'preco_medio' => 'dec2', 'faturamento' => 'dec2'];
    $rowsCsv = [];
    foreach ($linhas as $l) {
        $rowsCsv[] = [
            'momento'     => $rotuloMomento[(string)$l['momento']] ?? ucfirst((string)$l['momento']),
            'categoria'   => ucfirst((string)$l['categoria']),
            'pct_medio'   => (float)$l['pct_medio'],
            'kg'          => (float)($l['kg'] ?? 0),
            'preco_medio' => $l['preco_medio'] !== null ? (float)$l['preco_medio'] : '',
            'faturamento' => (float)($l['faturamento'] ?? 0),
        ];
    }
    vero_csv_stream('comercial', 'classificacao_producao', $rowsCsv, $colunas, $formato);
}

$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$qsBase = http_build_query(array_filter(['safra' => $fSafra ?: null]));
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Classificação da Produção', 'Categorias da colheita — previsto × realizado com kg, preço médio e faturamento', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <select name="safra" onchange="this.form.submit()">
          <option value="">Todas as safras</option>
          <?php foreach ($safras as $sid => $sn): ?>
            <option value="<?= $sid ?>"<?= $fSafra === (int)$sid ? ' selected' : '' ?>><?= h($sn) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($porMomento): ?>
        <a class="vbtn vbtn-ghost vbtn-sm no-print" href="?<?= $qsBase ? h($qsBase) . '&' : '' ?>csv=1">Exportar CSV</a>
        <button class="vbtn vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button>
      <?php endif; ?>
      <a class="vbtn vbtn-ghost vbtn-sm no-print" href="<?= $base ?>/colheita/index.php">Registros de colheita</a>
    </div>
  </div>

  <?php if (!$porMomento): ?>
    <div class="vcard"><div class="vempty">Nenhuma classificação registrada — as classificações nascem nos registros de colheita.</div></div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px">
    <?php foreach ($porMomento as $momento => $lista):
        $totFat = array_sum(array_map(static fn($l) => (float)($l['faturamento'] ?? 0), $lista));
        $totKg  = array_sum(array_map(static fn($l) => (float)($l['kg'] ?? 0), $lista)); ?>
    <div class="vcard">
      <div class="vtoolbar"><strong><?= h($rotuloMomento[(string)$momento] ?? ucfirst((string)$momento)) ?></strong>
        <span class="vsub"><?= numFmt($totKg, 0) ?> kg · R$ <?= numFmt($totFat, 2) ?></span></div>
      <table class="vtable">
        <thead><tr>
          <th>Categoria</th>
          <th style="text-align:right">% médio</th>
          <th style="text-align:right">Kg</th>
          <th style="text-align:right">R$/kg médio</th>
          <th style="text-align:right">Faturamento (R$)</th>
        </tr></thead>
        <tbody>
        <?php foreach ($lista as $l): ?>
          <tr>
            <td><strong><?= h(ucfirst((string)$l['categoria'])) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$l['pct_medio'], 1) ?>%</td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)($l['kg'] ?? 0), 0) ?></td>
            <td class="vnum" style="text-align:right"><?= $l['preco_medio'] !== null ? numFmt((float)$l['preco_medio'], 2) : '—' ?></td>
            <td class="vnum" style="text-align:right"><strong><?= numFmt((float)($l['faturamento'] ?? 0), 2) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
