<?php
/* ============================================================
   VERO — Comercial / base compartilhada de Faturamento
   (auditoria UX 19/07 — unificação das telas "por comprador" ×
   "por cultura": UM relatório com toggle de dimensão)
   Incluída por faturamento_comprador.php e faturamento_cultura.php,
   que definem ANTES do require:
     $FAT_DIM = 'comprador' | 'cultura'   (dimensão pré-selecionada)
   Slugs/permissões PRESERVADOS: cada rota mantém seu guard
   (comercial.faturamento_comprador / comercial.faturamento_cultura)
   e o menu continua apontando para as duas rotas — o toggle navega
   entre elas levando os filtros. SQLs idênticos aos das telas
   originais (totais não mudam).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$FAT_DIM = ($FAT_DIM ?? 'comprador') === 'cultura' ? 'cultura' : 'comprador';

$fSafra = (int)($_GET['safra'] ?? 0);
$fIni   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';

$where  = "v.tenant_id = :t AND v.status <> 'cancelada'";
$params = [':t' => vero_tenant()];
if ($fSafra > 0)  { $where .= " AND v.safra_id = :s";   $params[':s'] = $fSafra; }
if ($fIni !== '') { $where .= " AND v.data_venda >= :i"; $params[':i'] = $fIni; }
if ($fFim !== '') { $where .= " AND v.data_venda <= :f"; $params[':f'] = $fFim; }

if ($FAT_DIM === 'comprador') {
    $linhas = vero_rows(
        "SELECT v.comprador_id, COALESCE(c.nome_fantasia, c.razao_social, v.cliente, 'Sem comprador') AS comprador,
                COUNT(*) AS vendas, COALESCE(SUM(v.kg_total),0) AS kg,
                COALESCE(SUM(v.valor_total),0) AS faturamento,
                COALESCE(SUM(CASE WHEN v.status_pagamento = 'pago' THEN v.valor_total ELSE 0 END),0) AS recebido,
                MIN(v.data_venda) AS primeira, MAX(v.data_venda) AS ultima
           FROM comercial_vendas v
           LEFT JOIN comercial_compradores c ON c.id = v.comprador_id
          WHERE {$where}
          GROUP BY v.comprador_id, comprador
          ORDER BY faturamento DESC", $params);
} else {
    $linhas = vero_rows(
        "SELECT cu.id AS cultura_id, COALESCE(cu.nome, 'Sem cultura') AS cultura,
                va.nome AS variedade,
                COUNT(*) AS vendas, COALESCE(SUM(v.kg_total),0) AS kg,
                COALESCE(SUM(v.valor_total),0) AS faturamento,
                COALESCE(SUM(CASE WHEN v.status_pagamento = 'pago' THEN v.valor_total ELSE 0 END),0) AS recebido
           FROM comercial_vendas v
           LEFT JOIN colheita_registros cr ON cr.id = v.colheita_registro_id
           LEFT JOIN agro_culturas cu ON cu.id = cr.cultura_id
           LEFT JOIN agro_variedades va ON va.id = cr.variedade_id
          WHERE {$where}
          GROUP BY cu.id, cultura, va.nome
          ORDER BY faturamento DESC", $params);
}

$totFat = array_sum(array_map(static fn($l) => (float)$l['faturamento'], $linhas));
$totKg  = array_sum(array_map(static fn($l) => (float)$l['kg'], $linhas));
$totRec = array_sum(array_map(static fn($l) => (float)$l['recebido'], $linhas));

$safras = vero_options('agro_safras', 'identificacao');

$GUARD      = ['macro' => 'comercial', 'micro' => 'faturamento_' . $FAT_DIM];
$PAGE_VIEW  = 'comercial_faturamento_' . $FAT_DIM;
$PAGE_TITLE = $FAT_DIM === 'comprador' ? 'Faturamento por Comprador' : 'Faturamento por Cultura';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$qsFiltros = http_build_query(array_filter([
    'safra' => $fSafra > 0 ? $fSafra : null, 'ini' => $fIni ?: null, 'fim' => $fFim ?: null]));
$qsFiltros = $qsFiltros !== '' ? '?' . $qsFiltros : '';
$rotas = [
    'comprador' => ['rota' => '/comercial/faturamento_comprador', 'rotulo' => 'Por comprador',
                    'pode' => vero_can('comercial.faturamento_comprador.ver')],
    'cultura'   => ['rota' => '/comercial/faturamento_cultura', 'rotulo' => 'Por cultura',
                    'pode' => vero_can('comercial.faturamento_cultura.ver')],
];
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header($PAGE_TITLE,
        $FAT_DIM === 'comprador'
            ? 'Vendas consolidadas por comprador — recebido × a receber e participação no faturamento'
            : 'Vendas consolidadas por cultura e variedade da colheita de origem', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <div style="display:inline-flex;border:1px solid var(--vero-border,#D8CFBE);border-radius:8px;overflow:hidden" role="group" aria-label="Dimensão do relatório">
        <?php foreach ($rotas as $dk => $ri): if (!$ri['pode']) continue; ?>
          <a href="<?= $base . $ri['rota'] . $qsFiltros ?>"
             style="padding:6px 12px;font-size:.85rem;text-decoration:none;<?= $dk === $FAT_DIM
                ? 'background:#005059;color:#fff;font-weight:600' : '' ?>"
             <?= $dk === $FAT_DIM ? 'aria-current="page"' : '' ?>><?= h($ri['rotulo']) ?></a>
        <?php endforeach; ?>
      </div>
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
      <?php if ($FAT_DIM === 'comprador'): ?>
      <span class="vsub">faturamento <strong class="vnum">R$ <?= numFmt($totFat, 2) ?></strong> ·
        <?= numFmt($totKg, 0) ?> kg ·
        recebido <strong class="vnum">R$ <?= numFmt($totRec, 2) ?></strong></span>
      <?php else: ?>
      <span class="vsub">faturamento <strong class="vnum">R$ <?= numFmt($totFat, 2) ?></strong> ·
        <?= numFmt($totKg, 0) ?> kg</span>
      <?php endif; ?>
    </div>

    <?php if (!$linhas): ?>
      <div class="vempty">Nenhuma venda no filtro — as vendas nascem em Comercialização.</div>
    <?php elseif ($FAT_DIM === 'comprador'): ?>
    <table class="vtable">
      <thead><tr>
        <th>Comprador</th>
        <th style="text-align:right">Vendas</th>
        <th style="text-align:right">Volume (kg)</th>
        <th style="text-align:right">Faturamento (R$)</th>
        <th style="text-align:right">R$/kg médio</th>
        <th style="text-align:right">Recebido (R$)</th>
        <th style="text-align:right">A receber (R$)</th>
        <th style="width:20%">Participação</th>
      </tr></thead>
      <tbody>
      <?php foreach ($linhas as $l):
          $fat = (float)$l['faturamento']; $kg = (float)$l['kg']; $rec = (float)$l['recebido'];
          $pct = $totFat > 0 ? $fat / $totFat * 100 : 0; ?>
        <tr>
          <td><strong><?= h($l['comprador']) ?></strong>
            <span class="vhint"><?= date('d/m/Y', strtotime((string)$l['primeira'])) ?>
              <?= $l['ultima'] !== $l['primeira'] ? '– ' . date('d/m/Y', strtotime((string)$l['ultima'])) : '' ?></span></td>
          <td class="vnum" style="text-align:right"><?= (int)$l['vendas'] ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($kg, 0) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($fat, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= $kg > 0 ? numFmt($fat / $kg, 2) : '—' ?></td>
          <td class="vnum" style="text-align:right;color:var(--vero-ok,#1a7f4b)"><?= numFmt($rec, 2) ?></td>
          <td class="vnum" style="text-align:right;<?= $fat - $rec > 0 ? 'color:#b3261e' : '' ?>"><?= numFmt($fat - $rec, 2) ?></td>
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
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Cultura</th><th>Variedade</th>
        <th style="text-align:right">Vendas</th>
        <th style="text-align:right">Volume (kg)</th>
        <th style="text-align:right">Faturamento (R$)</th>
        <th style="text-align:right">R$/kg médio</th>
        <th style="text-align:right">Recebido (R$)</th>
        <th style="width:20%">Participação</th>
      </tr></thead>
      <tbody>
      <?php foreach ($linhas as $l):
          $fat = (float)$l['faturamento']; $kg = (float)$l['kg'];
          $pct = $totFat > 0 ? $fat / $totFat * 100 : 0; ?>
        <tr>
          <td><strong><?= h($l['cultura']) ?></strong></td>
          <td><?= h($l['variedade'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$l['vendas'] ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($kg, 0) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($fat, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= $kg > 0 ? numFmt($fat / $kg, 2) : '—' ?></td>
          <td class="vnum" style="text-align:right;color:var(--vero-ok,#1a7f4b)"><?= numFmt((float)$l['recebido'], 2) ?></td>
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
