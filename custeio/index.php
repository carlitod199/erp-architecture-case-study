<?php
/* ============================================================
   VERO — Custos / Visão Geral  (tela real, leitura)
   Substitui o dashboard mock legado (que ainda falava em "alvo"
   polimórfico — vocabulário que nunca existiu no schema).
   Rota: /custeio/index.php · Guard: custos.custo_talhao
   Landing do módulo: custo da safra, custo/ha, orçamento
   consumido e composição por categoria, com atalhos.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

$safras = vero_rows(
    "SELECT id, identificacao, status FROM agro_safras
      WHERE tenant_id = :t
      ORDER BY FIELD(status,'ativa','planejada','encerrada'), identificacao DESC", [':t' => $t]);
$fSafra = (int)($_GET['safra'] ?? 0);
if (!$fSafra && $safras) $fSafra = (int)$safras[0]['id'];

$custeioCat = $fSafra ? vero_rows(
    "SELECT COALESCE(categoria,'outros') AS categoria, SUM(valor) AS total, COUNT(*) AS lancamentos
       FROM custeio_lancamentos
      WHERE tenant_id = :t AND safra_id = :s
      GROUP BY categoria ORDER BY total DESC", [':t' => $t, ':s' => $fSafra]) : [];
$custoSafra = array_sum(array_map(static fn($c) => (float)$c['total'], $custeioCat));
$numLanc = array_sum(array_map(static fn($c) => (int)$c['lancamentos'], $custeioCat));

$areaPlantada = $fSafra ? (float)vero_val(
    "SELECT COALESCE(SUM(area_plantada_ha),0) FROM agro_safra_talhoes
      WHERE tenant_id = :t AND safra_id = :s", [':t' => $t, ':s' => $fSafra]) : 0.0;
$custoHa = $areaPlantada > 0 ? $custoSafra / $areaPlantada : null;

$orc = $fSafra ? vero_row(
    "SELECT id, status, valor_total FROM custeio_orcamentos
      WHERE tenant_id = :t AND safra_id = :s
      ORDER BY FIELD(status,'vigente','rascunho','encerrado'), id DESC LIMIT 1",
    [':t' => $t, ':s' => $fSafra]) : null;
$pctOrc = ($orc && (float)$orc['valor_total'] > 0) ? $custoSafra / (float)$orc['valor_total'] * 100 : null;

$fechamento = $fSafra ? vero_row(
    "SELECT status, data_fechamento, valor_total FROM custeio_fechamentos
      WHERE tenant_id = :t AND safra_id = :s LIMIT 1", [':t' => $t, ':s' => $fSafra]) : null;

/* Últimos lançamentos do custeio da safra */
$ultimos = $fSafra ? vero_rows(
    "SELECT cl.data_competencia, cl.categoria, cl.origem_tipo, cl.valor, tl.codigo AS talhao
       FROM custeio_lancamentos cl
       LEFT JOIN agro_talhoes tl ON tl.id = cl.talhao_id
      WHERE cl.tenant_id = :t AND cl.safra_id = :s
      ORDER BY cl.id DESC LIMIT 8", [':t' => $t, ':s' => $fSafra]) : [];

$GUARD      = ['macro' => 'custos', 'micro' => 'custo_talhao'];
$PAGE_VIEW  = 'custeio';
$PAGE_TITLE = 'Custos — Visão Geral';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$rotuloCat = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Custos — Visão Geral',
      'Custo acumulado da safra, orçamento consumido e composição por categoria', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <select name="safra" onchange="this.form.submit()">
          <?php foreach ($safras as $s): ?>
            <option value="<?= (int)$s['id'] ?>"<?= $fSafra === (int)$s['id'] ? ' selected' : '' ?>>
              <?= h($s['identificacao']) ?> (<?= h((string)$s['status']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($fechamento): ?>
        <span class="vbadge <?= $fechamento['status'] === 'fechado' ? 'vb-off' : 'vb-warn' ?>">
          fechamento: <?= h((string)$fechamento['status']) ?> em <?= date('d/m/Y', strtotime((string)$fechamento['data_fechamento'])) ?></span>
      <?php endif; ?>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Custo da safra</span>
        <strong class="vnum" style="font-size:1.2rem">R$ <?= numFmt($custoSafra, 2) ?></strong>
        <span class="vhint"><?= $numLanc ?> lançamento(s)</span></div>
      <div class="vkpi"><span class="vhint">Custo / ha plantado</span>
        <strong class="vnum" style="font-size:1.2rem"><?= $custoHa !== null ? 'R$ ' . numFmt($custoHa, 2) : '—' ?></strong>
        <span class="vhint"><?= numFmt($areaPlantada, 2) ?> ha na safra</span></div>
      <div class="vkpi"><span class="vhint">Orçamento consumido</span>
        <strong class="vnum" style="font-size:1.2rem;<?= $pctOrc !== null && $pctOrc > 100 ? 'color:#b3261e' : '' ?>">
          <?= $pctOrc !== null ? numFmt($pctOrc, 1) . '%' : '—' ?></strong>
        <?php if ($orc): ?><span class="vhint">orçado: R$ <?= numFmt((float)$orc['valor_total'], 2) ?> (<?= h((string)$orc['status']) ?>)</span>
        <?php else: ?><span class="vhint">sem orçamento — <a href="<?= $base ?>/custeio/orcamento.php">criar</a></span><?php endif; ?></div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;padding:0 14px 12px">
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/custeio/custos.php?safra=<?= $fSafra ?>">Custo por Válvula</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/custeio/custo_categoria.php">Por Categoria</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/custeio/custo_hectare.php">Por Hectare</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/custeio/realizado.php?safra=<?= $fSafra ?>">Orçado × Realizado</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/custeio/resultado_safra.php?safra=<?= $fSafra ?>">Resultado da Safra</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/custeio/fechamento.php">Fechamento</a>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Composição do custo por categoria</strong></div>
      <?php if (!$custeioCat): ?>
        <div class="vempty">Nenhum custo lançado para esta safra.</div>
      <?php else: ?>
      <div class="vdata-wrap">
      <table class="vdata">
        <thead><tr><th>Categoria</th><th class="num">Total (R$)</th><th style="width:36%">Participação</th></tr></thead>
        <tbody>
        <?php foreach ($custeioCat as $c):
            $pct = $custoSafra > 0 ? (float)$c['total'] / $custoSafra * 100 : 0; ?>
          <tr>
            <td><strong><?= h($rotuloCat((string)$c['categoria'])) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$c['total'], 2) ?></td>
            <td><div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
                <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
              </div>
              <span class="vnum vhint"><?= numFmt($pct, 1) ?>%</span>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong>Últimos lançamentos</strong>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/custeio/custo_categoria.php">Todos</a></div>
      <?php if (!$ultimos): ?>
        <div class="vempty">Nenhum lançamento de custeio nesta safra.</div>
      <?php else: ?>
      <div class="vdata-wrap">
      <table class="vdata">
        <thead><tr><th>Competência</th><th>Categoria</th><th>Origem</th><th>Válvula</th><th class="num">Valor (R$)</th></tr></thead>
        <tbody>
        <?php foreach ($ultimos as $u): ?>
          <tr>
            <td class="vnum"><?= date('d/m/Y', strtotime((string)$u['data_competencia'])) ?></td>
            <td><?= h($rotuloCat((string)($u['categoria'] ?? 'outros'))) ?></td>
            <td><span class="vhint"><?= h((string)$u['origem_tipo']) ?></span></td>
            <td><?= $u['talhao'] ? h($u['talhao']) : '<span style="color:#8A6D1A;font-weight:600">sem válvula</span>' ?></td>
            <td class="num"><strong><?= numFmt((float)$u['valor'], 2) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="vhint" style="padding:8px 14px">
        Todo módulo emite custo aqui com <code>origem_tipo/origem_id</code> e dimensões
        safra/válvula/cultura — não há lançamento manual de custeio.
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
