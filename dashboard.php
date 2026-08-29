<?php
/* ============================================================
   VERO — Dashboard executivo (tela real)
   Substitui o mock (dados fixos). Guard: dashboard.visao_geral
   Leitura consolidada de vendas, custeio, colheita, financeiro e
   alertas — nenhum número inventado: tudo vem do banco.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/vero_crud.php';

/* UX-08 (blueprint A4-04, aprovado): a raiz CONSOLIDA no Dashboard Executivo
   para quem tem acesso a ele — elimina as duas "caras" divergentes de
   dashboard executivo. Quem não tem a permissão do executivo continua vendo
   esta visão geral. Redirect seguro (só quando pode ver o destino) e sem
   loop. Preserva eventuais filtros da querystring. */
if (hasPermission('dashboard.dashboard_executivo.ver')) {
    $q = $_GET ? ('?' . http_build_query($_GET)) : '';
    header('Location: ' . rtrim(BIOS_BASE, '/') . '/dashboard/dashboard_executivo' . $q);
    exit;
}

$t = vero_tenant();

/* R12-B3 (P-75, decisão A0 19/07): valores em R$ (faturamento, contas, custo,
   resultado, preço) só p/ quem tem o proxy financeiro — sem ele, mascara (•••).
   Métricas operacionais (kg, ha, alertas, datas) permanecem visíveis. */
$veFin = vero_can('financeiro.dre_agro.ver');

/* KPIs */
$fatVendas = (float)vero_val(
    "SELECT COALESCE(SUM(valor_total),0) FROM comercial_vendas
      WHERE tenant_id=:t AND status <> 'cancelada'", [':t' => $t]);
$kgVendido = (float)vero_val(
    "SELECT COALESCE(SUM(kg_total),0) FROM comercial_vendas
      WHERE tenant_id=:t AND status <> 'cancelada'", [':t' => $t]);
$custoTotal = (float)vero_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=:t", [':t' => $t]);
$kgColhido = (float)vero_val(
    "SELECT COALESCE(SUM(kg_total_realizado),0) FROM colheita_registros WHERE tenant_id=:t", [':t' => $t]);
$receberAberto = (float)vero_val(
    "SELECT COALESCE(SUM(valor),0) FROM movimentacoes_financeiras
      WHERE tenant_id=:t AND tipo='receber' AND status='aberto'", [':t' => $t]);
$pagarAberto = (float)vero_val(
    "SELECT COALESCE(SUM(valor),0) FROM movimentacoes_financeiras
      WHERE tenant_id=:t AND tipo='pagar' AND status='aberto'", [':t' => $t]);
$alertasAbertos = (int)vero_val(
    "SELECT COUNT(*) FROM agro_alertas WHERE tenant_id=:t AND status='aberto'", [':t' => $t]);
$areaTotal = (float)vero_val(
    "SELECT COALESCE(SUM(area_total_ha),0) FROM agro_fazendas WHERE tenant_id=:t AND ativo=1", [':t' => $t]);

/* custo por categoria */
$custoCategorias = vero_rows(
    "SELECT COALESCE(categoria,'outros') AS categoria, SUM(valor) AS total
       FROM custeio_lancamentos WHERE tenant_id=:t
      GROUP BY categoria ORDER BY total DESC", [':t' => $t]);
$maxCat = 0.0;
foreach ($custoCategorias as $c) $maxCat = max($maxCat, (float)$c['total']);

/* listas recentes */
$vendasRecentes = vero_rows(
    "SELECT v.numero, v.data_venda, v.valor_total, v.status_pagamento, c.razao_social
       FROM comercial_vendas v
       LEFT JOIN comercial_compradores c ON c.id = v.comprador_id
      WHERE v.tenant_id=:t AND v.status <> 'cancelada'
      ORDER BY v.data_venda DESC, v.id DESC LIMIT 6", [':t' => $t]);
$apontRecentes = vero_rows(
    "SELECT a.data_apontamento, tt.codigo AS talhao, f.nome AS fazenda, ta.nome AS atividade,
            (SELECT COALESCE(SUM(pi.valor_total),0) FROM rh_producao_itens pi
              WHERE pi.tenant_id = a.tenant_id AND pi.apontamento_id = a.id) AS total
       FROM agro_apontamentos a
       JOIN agro_talhoes tt ON tt.id = a.talhao_id
       JOIN agro_fazendas f ON f.id = tt.fazenda_id
       LEFT JOIN agro_tipos_atividade ta ON ta.id = a.tipo_atividade_id
      WHERE a.tenant_id=:t ORDER BY a.data_apontamento DESC, a.id DESC LIMIT 6", [':t' => $t]);
$alertasRecentes = vero_rows(
    "SELECT al.data, al.categoria, al.severidade, al.titulo, tt.codigo AS talhao
       FROM agro_alertas al
       LEFT JOIN agro_talhoes tt ON tt.id = al.talhao_id
      WHERE al.tenant_id=:t AND al.status='aberto'
      ORDER BY FIELD(al.severidade,'critico','atencao','info'), al.data DESC LIMIT 6", [':t' => $t]);

$GUARD      = ['macro' => 'dashboard', 'micro' => 'visao_geral'];
$PAGE_VIEW  = 'dashboard';
$PAGE_TITLE = 'Dashboard';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/includes/agro_header.php';

$kpi = static function (string $rotulo, string $valor, string $sub = '', string $cor = '#005059'): string {
    return '<div class="vcard" style="padding:16px 18px;position:relative;overflow:hidden">'
        . '<div style="position:absolute;left:0;top:0;bottom:0;width:3px;background:' . $cor . '"></div>'
        . '<div class="vhint" style="text-transform:uppercase;letter-spacing:.05em">' . h($rotulo) . '</div>'
        . '<div class="vnum" style="font-size:24px;font-weight:700;color:' . $cor . ';white-space:nowrap">' . $valor . '</div>'
        . ($sub !== '' ? '<div class="vhint">' . h($sub) . '</div>' : '')
        . '</div>';
};
/* P-75: formata R$ ou mascara */
$rs = static fn(float $v): string => $veFin ? 'R$ ' . numFmt($v, 2) : '•••';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Dashboard executivo', 'Números consolidados: vendas, custos, colheita, financeiro e alertas', null) ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px">
    <?= $kpi('Faturamento (vendas)', $rs($fatVendas), numFmt($kgVendido, 0) . ' kg vendidos') ?>
    <?= $kpi('Custo de produção', $rs($custoTotal), 'custeio consolidado', '#8A6D1A') ?>
    <?= $kpi('Colheita realizada', numFmt($kgColhido, 0) . ' kg', $areaTotal > 0 ? numFmt($areaTotal, 2) . ' ha de área total' : '') ?>
    <?= $kpi('Alertas abertos', (string)$alertasAbertos, 'nutrição + MIP', $alertasAbertos > 0 ? '#9A3B2A' : '#1E6B34') ?>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px">
    <?= $kpi('A receber (aberto)', $rs($receberAberto), '', '#1E6B34') ?>
    <?= $kpi('A pagar (aberto)', $rs($pagarAberto), '', '#9A3B2A') ?>
    <?php /* P-75: cor neutra quando mascarado — o verde/vermelho revelaria o sinal */ ?>
    <?= $kpi('Resultado bruto', $rs($fatVendas - $custoTotal), 'vendas − custeio',
             $veFin ? ($fatVendas - $custoTotal >= 0 ? '#1E6B34' : '#9A3B2A') : '#005059') ?>
    <?= $kpi('Preço médio', $kgVendido > 0 ? ($veFin ? 'R$ ' . numFmt($fatVendas / $kgVendido, 2) . '/kg' : '•••') : '—', 'sobre kg vendidos') ?>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr));gap:16px;margin-bottom:16px">
    <div class="vcard">
      <div class="vtoolbar"><strong style="font-size:14px">Custo por categoria</strong></div>
      <?php if (!$custoCategorias): ?>
        <div class="vempty">Sem lançamentos de custeio ainda.</div>
      <?php else: ?>
        <div style="padding:14px 18px;display:flex;flex-direction:column;gap:10px">
        <?php foreach ($custoCategorias as $c): $pct = $maxCat > 0 ? (float)$c['total'] / $maxCat * 100 : 0; ?>
          <div>
            <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:3px">
              <strong><?= h(ucfirst(str_replace('_', ' ', (string)$c['categoria']))) ?></strong>
              <span class="vnum"><?= $rs((float)$c['total']) ?></span>
            </div>
            <div style="height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
              <div style="height:100%;width:<?= numFmt($pct, 1) ?>%;background:#005059;border-radius:5px"></div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong style="font-size:14px">Alertas abertos</strong>
        <div style="flex:1"></div>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/dashboard/indicadores_alertas">Ver alertas</a>
      </div>
      <?php if (!$alertasRecentes): ?>
        <div class="vempty">Nenhum alerta aberto.</div>
      <?php else: ?>
      <table class="vtable">
        <tbody>
        <?php foreach ($alertasRecentes as $al): ?>
          <tr>
            <td class="vnum" style="width:90px"><?= date('d/m/Y', strtotime((string)$al['data'])) ?></td>
            <td style="width:90px"><?= $al['severidade'] === 'critico'
                  ? '<span class="vbadge vb-off">Crítico</span>' : '<span class="vbadge vb-warn">Atenção</span>' ?></td>
            <td><strong><?= h($al['titulo']) ?></strong>
              <span class="vhint"><?= h((string)$al['categoria']) ?><?= $al['talhao'] ? ' · talhão ' . h($al['talhao']) : '' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr));gap:16px">
    <div class="vcard">
      <div class="vtoolbar"><strong style="font-size:14px">Últimas vendas</strong>
        <div style="flex:1"></div>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/dashboard/comercializacao">Comercialização</a>
      </div>
      <?php if (!$vendasRecentes): ?>
        <div class="vempty">Nenhuma venda registrada.</div>
      <?php else: ?>
      <table class="vtable">
        <tbody>
        <?php foreach ($vendasRecentes as $v): ?>
          <tr>
            <td class="vnum" style="width:100px"><strong><?= h($v['numero']) ?></strong></td>
            <td><?= h($v['razao_social'] ?? '—') ?>
              <span class="vhint"><?= date('d/m/Y', strtotime((string)$v['data_venda'])) ?></span></td>
            <td class="vnum" style="text-align:right"><?= $rs((float)$v['valor_total']) ?></td>
            <td style="width:90px;text-align:right"><?= $v['status_pagamento'] === 'pago'
                  ? '<span class="vbadge vb-ok">Pago</span>' : '<span class="vbadge vb-warn">Pendente</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong style="font-size:14px">Últimos apontamentos</strong>
        <div style="flex:1"></div>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/agro/apontamentos">Ver todos</a>
      </div>
      <?php if (!$apontRecentes): ?>
        <div class="vempty">Nenhum apontamento registrado.</div>
      <?php else: ?>
      <table class="vtable">
        <tbody>
        <?php foreach ($apontRecentes as $a): ?>
          <tr>
            <td class="vnum" style="width:90px"><?= date('d/m/Y', strtotime((string)$a['data_apontamento'])) ?></td>
            <td><strong><?= h($a['fazenda']) ?> — <?= h($a['talhao']) ?></strong>
              <span class="vhint"><?= h($a['atividade'] ?? '—') ?></span></td>
            <td class="vnum" style="text-align:right"><?= $rs((float)$a['total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/agro_footer_simple.php';
