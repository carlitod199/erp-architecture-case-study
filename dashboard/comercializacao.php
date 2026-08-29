<?php
/* ============================================================
   VERO — Dashboard / Comercialização  (tela real)
   Tela nova. Rota da matriz: /dashboard/comercializacao.php
   Guard: dashboard.comercializacao
   Faturamento total, kg por comprador, distribuição de qualidade
   (pizza) e produção kg/ha por válvula — leitura consolidada das
   vendas confirmadas e colheitas realizadas.
   CONTRATO A3-T27d: NUNCA somar valor de estoque colhido (lotes COLH-)
   com custo da safra - o custo do lote JA NASCE do custo da safra
   (dupla contagem). CPV comercial e custo de producao = leituras
   DISTINTAS, sempre com rotulos separados.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

$fat = (float)vero_val("SELECT COALESCE(SUM(valor_total),0) FROM comercial_vendas
    WHERE tenant_id=:t AND status <> 'cancelada'", [':t' => $t]);
$kg = (float)vero_val("SELECT COALESCE(SUM(kg_total),0) FROM comercial_vendas
    WHERE tenant_id=:t AND status <> 'cancelada'", [':t' => $t]);
$vendas = (int)vero_val("SELECT COUNT(*) FROM comercial_vendas
    WHERE tenant_id=:t AND status <> 'cancelada'", [':t' => $t]);
$receberAberto = (float)vero_val("SELECT COALESCE(SUM(valor),0) FROM movimentacoes_financeiras
    WHERE tenant_id=:t AND tipo='receber' AND status='aberto'", [':t' => $t]);

$porComprador = vero_rows(
    "SELECT COALESCE(c.razao_social, v.cliente, '—') AS comprador,
            SUM(v.kg_total) AS kg, SUM(v.valor_total) AS valor, COUNT(*) AS vendas
       FROM comercial_vendas v
       LEFT JOIN comercial_compradores c ON c.id = v.comprador_id
      WHERE v.tenant_id = :t AND v.status <> 'cancelada'
      GROUP BY comprador ORDER BY valor DESC", [':t' => $t]);
$maxComprador = 0.0;
foreach ($porComprador as $pc) $maxComprador = max($maxComprador, (float)$pc['valor']);

$qualidades = vero_rows(
    "SELECT q.categoria, SUM(q.kg) AS kg, SUM(q.valor) AS valor
       FROM comercial_venda_qualidades q
       JOIN comercial_vendas v ON v.id = q.venda_id AND v.tenant_id = q.tenant_id
      WHERE q.tenant_id = :t AND v.status <> 'cancelada'
      GROUP BY q.categoria
      ORDER BY FIELD(q.categoria,'premium','cat1','cat2','cat3')", [':t' => $t]);
$kgQual = 0.0;
foreach ($qualidades as $q) $kgQual += (float)$q['kg'];

const QUAL_UI = [
    'premium' => ['Premium', '#005059'],
    'cat1'    => ['CAT 1', '#4E9CA1'],
    'cat2'    => ['CAT 2', '#C9A227'],
    'cat3'    => ['CAT 3', '#9A6D3B'],
];

$porValvula = vero_rows(
    "SELECT se.codigo AS valvula, tt.codigo AS talhao, f.nome AS fazenda,
            se.area_ha, SUM(r.kg_total_realizado) AS kg,
            SUM(r.faturamento_realizado) AS fat_estimado,
            MAX(r.producao_realizada_kg_ha) AS kg_ha
       FROM colheita_registros r
       JOIN agro_setores se ON se.id = r.setor_id
       JOIN agro_talhoes tt ON tt.id = r.talhao_id
       JOIN agro_fazendas f ON f.id = tt.fazenda_id
      WHERE r.tenant_id = :t AND r.kg_total_realizado > 0
      GROUP BY se.id, se.codigo, tt.codigo, f.nome, se.area_ha
      ORDER BY kg DESC", [':t' => $t]);

$GUARD      = ['macro' => 'dashboard', 'micro' => 'comercializacao'];
$PAGE_VIEW  = 'dashboard_comercializacao';
$PAGE_TITLE = 'Comercialização';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');

/* Redesenho ECharts (A4-05) — re-entregue pós-R1; DEFAULT. ?classico=1 = render
   antigo (escape reversível). O pilot aplica as regras R1 (via _dash.php). */
if (empty($_GET['classico'])) {
    require __DIR__ . '/_comercializacao_piloto.php';
    require __DIR__ . '/../includes/agro_footer_simple.php';
    return;
}

/* pizza CSS via conic-gradient */
$fatias = [];
$acum = 0.0;
foreach ($qualidades as $q) {
    if ($kgQual <= 0) break;
    $ini = $acum / $kgQual * 360;
    $acum += (float)$q['kg'];
    $fim = $acum / $kgQual * 360;
    $cor = QUAL_UI[$q['categoria']][1] ?? '#8A7D6E';
    /* graus com ponto decimal (CSS) — não usar numFmt pt-BR aqui */
    $fatias[] = $cor . ' ' . number_format($ini, 1, '.', '') . 'deg ' . number_format($fim, 1, '.', '') . 'deg';
}
$pizzaCss = $fatias ? 'conic-gradient(' . implode(', ', $fatias) . ')' : 'none';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Comercialização', 'Faturamento, compradores, distribuição de qualidade e produção por válvula — vendas confirmadas', null) ?>

  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px">
    <div class="vcard" style="padding:16px 18px">
      <div class="vhint" style="text-transform:uppercase;letter-spacing:.05em">Faturamento total</div>
      <div class="vnum" style="font-size:24px;font-weight:700;color:#005059">R$ <?= numFmt($fat, 2) ?></div>
      <div class="vhint"><?= $vendas ?> venda(s)</div>
    </div>
    <div class="vcard" style="padding:16px 18px">
      <div class="vhint" style="text-transform:uppercase;letter-spacing:.05em">kg comercializados</div>
      <div class="vnum" style="font-size:24px;font-weight:700;color:#005059"><?= numFmt($kg, 0) ?></div>
    </div>
    <div class="vcard" style="padding:16px 18px">
      <div class="vhint" style="text-transform:uppercase;letter-spacing:.05em">Preço médio</div>
      <div class="vnum" style="font-size:24px;font-weight:700;color:#005059"><?= $kg > 0 ? 'R$ ' . numFmt($fat / $kg, 2) : '—' ?><span style="font-size:13px">/kg</span></div>
    </div>
    <div class="vcard" style="padding:16px 18px">
      <div class="vhint" style="text-transform:uppercase;letter-spacing:.05em">A receber (aberto)</div>
      <div class="vnum" style="font-size:24px;font-weight:700;color:#8A6D1A">R$ <?= numFmt($receberAberto, 2) ?></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 380px;gap:16px;margin-bottom:16px">
    <div class="vcard">
      <div class="vtoolbar"><strong style="font-size:14px">Faturamento e kg por comprador</strong></div>
      <?php if (!$porComprador): ?>
        <div class="vempty">Nenhuma venda registrada.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr>
          <th>Comprador</th>
          <th style="text-align:right">Vendas</th>
          <th style="text-align:right">kg</th>
          <th style="text-align:right">Faturamento (R$)</th>
          <th style="width:30%">Participação</th>
        </tr></thead>
        <tbody>
        <?php foreach ($porComprador as $pc): $pct = $maxComprador > 0 ? (float)$pc['valor'] / $maxComprador * 100 : 0; ?>
          <tr>
            <td><strong><?= h($pc['comprador']) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= (int)$pc['vendas'] ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$pc['kg'], 0) ?></td>
            <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$pc['valor'], 2) ?></strong></td>
            <td><div style="height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
              <div style="height:100%;width:<?= numFmt($pct, 1) ?>%;background:#005059;border-radius:5px"></div>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong style="font-size:14px">Qualidade comercializada</strong></div>
      <?php if ($kgQual <= 0): ?>
        <div class="vempty">Sem qualidades registradas.</div>
      <?php else: ?>
      <div style="display:flex;gap:18px;align-items:center;padding:16px 18px">
        <div style="width:140px;height:140px;border-radius:50%;background:<?= $pizzaCss ?>;flex-shrink:0;border:1px solid #DDD6C8"></div>
        <div style="display:flex;flex-direction:column;gap:8px;flex:1">
        <?php foreach ($qualidades as $q):
            [$rotulo, $cor] = QUAL_UI[$q['categoria']] ?? [$q['categoria'], '#8A7D6E'];
            $pct = $kgQual > 0 ? (float)$q['kg'] / $kgQual * 100 : 0; ?>
          <div style="display:flex;align-items:center;gap:8px;font-size:12.5px">
            <span style="width:12px;height:12px;border-radius:3px;background:<?= $cor ?>;flex-shrink:0"></span>
            <strong style="width:70px"><?= h($rotulo) ?></strong>
            <span class="vnum"><?= numFmt($pct, 1) ?>%</span>
            <span class="vhint vnum" style="margin-left:auto"><?= numFmt((float)$q['kg'], 0) ?> kg · R$ <?= numFmt((float)$q['valor'], 2) ?></span>
          </div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong style="font-size:14px">Produção por válvula (colheita realizada)</strong></div>
    <?php if (!$porValvula): ?>
      <div class="vempty">Nenhuma colheita realizada registrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Válvula</th><th>Fazenda / Válvula</th>
        <th style="text-align:right">Área (ha)</th>
        <th style="text-align:right">kg/ha</th>
        <th style="text-align:right">kg colhidos</th>
        <th style="text-align:right">Faturamento estimado (R$)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($porValvula as $pv): ?>
        <tr>
          <td><strong class="vnum"><?= h($pv['valvula']) ?></strong></td>
          <td><?= h($pv['fazenda']) ?> — <?= h($pv['talhao']) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$pv['area_ha'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= $pv['kg_ha'] !== null ? numFmt((float)$pv['kg_ha'], 0) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$pv['kg'], 0) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$pv['fat_estimado'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
