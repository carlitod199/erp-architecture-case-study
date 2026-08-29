<?php
/* ============================================================
   VERO — Dashboard Executivo  (tela real, leitura)
   Substitui o mock. Rota: /dashboard/dashboard_executivo.php
   Guard: dashboard.dashboard_executivo
   Visão do administrador com filtro de safra/VÁLVULA (P-03):
   estrutura, financeiro, custo × orçamento, produção × previsto,
   operação e estoque. "Planejado" = orçamento vigente + colheita
   prevista (metas formais dependem de P-44).
   Válvula filtra área/custo/faturamento/produção; financeiro, operação
   e estoque são globais do tenant. Orçamento (por safra) é rateado por
   área da válvula quando uma válvula é selecionada.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$d30 = date('Y-m-d', strtotime('-30 days'));
$mesIni = date('Y-m-01');
$mesFim = date('Y-m-t');

/* ---- Filtros: safra (default: ativa mais recente) e VÁLVULA (talhão) ----
   P-03: o antigo filtro de fazenda foi trocado por seletor de VÁLVULA —
   área, custo, faturamento e produção passam a ser recortados por válvula. */
$safras = vero_rows(
    "SELECT id, identificacao, status FROM agro_safras
      WHERE tenant_id = :t
      ORDER BY FIELD(status,'ativa','planejada','encerrada'), identificacao DESC", [':t' => $t]);
$safraRot = vero_safra_rotulos($safras); /* P-04: id => rótulo curto (2026.2, sem "-NN") */
$talhoes = vero_options('agro_talhoes', 'codigo');

$fSafra = (int)($_GET['safra'] ?? 0);
if (!$fSafra && $safras) $fSafra = (int)$safras[0]['id'];
$fTalhao = (int)($_GET['talhao'] ?? 0);

$safraSel = null;
foreach ($safras as $s) if ((int)$s['id'] === $fSafra) { $safraSel = $s; break; }
$safraSelRot = $safraSel ? ($safraRot[(int)$safraSel['id']] ?? vero_safra_rotulo((string)$safraSel['identificacao'])) : '';

/* Condição de válvula (aplicada via talhão) */
$condTalSt = $fTalhao ? " AND tl.id = :tl" : "";
$pTal = $fTalhao ? [':tl' => $fTalhao] : [];

/* ---- Estrutura ---- */
$estrutura = vero_row(
    "SELECT COUNT(*) AS fazendas, COALESCE(SUM(area_total_ha),0) AS area_total
       FROM agro_fazendas WHERE tenant_id = :t AND ativo = 1",
    [':t' => $t]);
$safrasAtivas = (int)vero_val(
    "SELECT COUNT(*) FROM agro_safras WHERE tenant_id = :t AND status = 'ativa'", [':t' => $t]);
$areaPlantada = (float)vero_val(
    "SELECT COALESCE(SUM(st.area_plantada_ha),0)
       FROM agro_safra_talhoes st JOIN agro_talhoes tl ON tl.id = st.talhao_id
      WHERE st.tenant_id = :t AND st.safra_id = :s{$condTalSt}",
    [':t' => $t, ':s' => $fSafra] + $pTal);

/* ---- Financeiro (global do tenant) ---- */
$posicao = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN tipo='receber' AND status='aberto' THEN valor END),0) AS receber_aberto,
            COALESCE(SUM(CASE WHEN tipo='pagar' AND status='aberto' THEN valor END),0) AS pagar_aberto,
            COALESCE(SUM(CASE WHEN tipo='receber' AND status='aberto' AND data_vencimento < CURDATE() THEN valor END),0) AS receber_vencido,
            COALESCE(SUM(CASE WHEN tipo='pagar' AND status='aberto' AND data_vencimento < CURDATE() THEN valor END),0) AS pagar_vencido,
            COALESCE(SUM(CASE WHEN status='aberto' AND data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY) THEN 1 END),0) AS venc_15d
       FROM movimentacoes_financeiras WHERE tenant_id = :t", [':t' => $t]);
$caixaMes = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN tipo='receber' THEN valor END),0) AS entradas,
            COALESCE(SUM(CASE WHEN tipo='pagar' THEN valor END),0) AS saidas
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status='pago' AND data_pagamento BETWEEN :i AND :f",
    [':t' => $t, ':i' => $mesIni, ':f' => $mesFim]);
$saldoMes = (float)$caixaMes['entradas'] - (float)$caixaMes['saidas'];

/* ---- Custo da safra (por categoria, com filtro de fazenda) ---- */
$custeioCat = vero_rows(
    "SELECT COALESCE(cl.categoria,'outros') AS categoria, SUM(cl.valor) AS total
       FROM custeio_lancamentos cl
       LEFT JOIN agro_talhoes tl ON tl.id = cl.talhao_id
      WHERE cl.tenant_id = :t AND cl.safra_id = :s" . ($fTalhao ? " AND cl.talhao_id = :tl" : "") . "
      GROUP BY categoria ORDER BY total DESC",
    [':t' => $t, ':s' => $fSafra] + $pTal);
$custoSafra = array_sum(array_map(static fn($c) => (float)$c['total'], $custeioCat));
$custoHa = $areaPlantada > 0 ? $custoSafra / $areaPlantada : null;

/* Orçamento da safra (vigente, ou o mais recente) — sempre da safra inteira */
$orc = vero_row(
    "SELECT id, status, valor_total FROM custeio_orcamentos
      WHERE tenant_id = :t AND safra_id = :s
      ORDER BY FIELD(status,'vigente','rascunho','encerrado'), id DESC LIMIT 1",
    [':t' => $t, ':s' => $fSafra]);
/* P-03: com válvula filtrada, o custo do card é o da válvula ($custoSafra) e o
   orçamento é RATEADO pela área da válvula (o orçado é por safra inteira). */
$custoSafraTotal = $custoSafra;
$orcTotal = $orc ? (float)$orc['valor_total'] : 0.0;
$orcComparar = $orcTotal;
if ($fTalhao && $orcTotal > 0) {
    $areaSafraTot = (float)vero_val(
        "SELECT COALESCE(SUM(area_plantada_ha),0) FROM agro_safra_talhoes WHERE tenant_id=:t AND safra_id=:s",
        [':t' => $t, ':s' => $fSafra]);
    $orcComparar = $areaSafraTot > 0 ? $orcTotal * $areaPlantada / $areaSafraTot : 0.0;
}
$pctOrc = $orcComparar > 0 ? $custoSafraTotal / $orcComparar * 100 : null;

/* ---- Produção e comercialização da safra ---- */
$producao = vero_row(
    "SELECT COALESCE(SUM(cr.kg_total_previsto),0) AS previsto,
            COALESCE(SUM(cr.kg_total_realizado),0) AS realizado
       FROM colheita_registros cr
       LEFT JOIN agro_safra_talhoes st ON st.id = cr.safra_talhao_id
       LEFT JOIN agro_talhoes tl ON tl.id = st.talhao_id
      WHERE cr.tenant_id = :t AND cr.safra_id = :s" . ($fTalhao ? " AND tl.id = :tl" : ""),
    [':t' => $t, ':s' => $fSafra] + $pTal);
$vendasSafra = vero_row(
    "SELECT COALESCE(SUM(valor_total),0) AS faturamento, COALESCE(SUM(kg_total),0) AS kg
       FROM comercial_vendas
      WHERE tenant_id = :t AND safra_id = :s AND status <> 'cancelada'" . ($fTalhao ? " AND talhao_id = :tl" : ""),
    [':t' => $t, ':s' => $fSafra] + $pTal);
$faturamento = (float)$vendasSafra['faturamento'];
$precoMedio  = (float)$vendasSafra['kg'] > 0 ? $faturamento / (float)$vendasSafra['kg'] : null;
$resultado   = $faturamento - $custoSafraTotal;
$margem      = $faturamento > 0 ? $resultado / $faturamento * 100 : null;
$custoKg     = (float)$producao['realizado'] > 0 ? $custoSafraTotal / (float)$producao['realizado'] : null;

/* ── R2-04: custos SEM SAFRA pendentes de rateio — soma LÍQUIDA (a atribuição
   P-98 em custeio/_atribuicao_sem_safra.php grava contrapartida NEGATIVA sem
   safra anulando o original já atribuído, mecânica P-07). O resultado bruto
   por safra não enxerga esse valor até o rateio MANUAL do fechamento (P-125);
   o card só dá visibilidade. Usado pelo piloto e pelo render clássico. */
$pendSemSafra = (float)vero_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos
      WHERE tenant_id = :t AND safra_id IS NULL", [':t' => $t]);
/* P-75 (padrão do dashboard.php): R$ mascarado sem o proxy financeiro */
$veFin   = vero_can('financeiro.dre_agro.ver');
$pendFmt = $veFin ? 'R$ ' . numFmt($pendSemSafra, 2) : '•••';

/* ---- Operação (30 dias, global) ---- */
$atividades = vero_row(
    "SELECT COALESCE(SUM(status='planejada'),0) AS planejadas,
            COALESCE(SUM(status='em_execucao'),0) AS em_execucao,
            COALESCE(SUM(status='concluida' AND updated_at >= :d),0) AS concluidas_30d,
            COALESCE(SUM(status='planejada' AND data_planejada < CURDATE()),0) AS atrasadas
       FROM agro_atividades WHERE tenant_id = :t", [':t' => $t, ':d' => $d30]);
$apont30 = (int)vero_val(
    "SELECT COUNT(*) FROM agro_apontamentos WHERE tenant_id = :t AND data_apontamento >= :d",
    [':t' => $t, ':d' => $d30]);
$alertas = vero_rows(
    "SELECT categoria, COUNT(*) AS total, SUM(severidade='critico') AS criticos
       FROM agro_alertas WHERE tenant_id = :t AND status='aberto'
      GROUP BY categoria ORDER BY criticos DESC, total DESC", [':t' => $t]);
$alertasAbertos  = array_sum(array_map(static fn($a) => (int)$a['total'], $alertas));
$alertasCriticos = array_sum(array_map(static fn($a) => (int)$a['criticos'], $alertas));

/* ---- Estoque (global) ---- */
$estoqueMin = (int)vero_val(
    "SELECT COUNT(*) FROM estoque_produtos p
      WHERE p.tenant_id = :t AND p.ativo = 1 AND p.estoque_minimo IS NOT NULL AND p.estoque_minimo > 0
        AND COALESCE((SELECT SUM(s.quantidade) FROM estoque_saldos s
                       WHERE s.tenant_id = p.tenant_id AND s.produto_id = p.id),0) < p.estoque_minimo",
    [':t' => $t]);
$lotesVenc = (int)vero_val(
    "SELECT COUNT(*) FROM estoque_lotes l
      WHERE l.tenant_id = :t AND l.quantidade > 0 AND l.validade IS NOT NULL
        AND l.validade <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)", [':t' => $t]);

$GUARD      = ['macro' => 'dashboard', 'micro' => 'dashboard_executivo'];
$PAGE_VIEW  = 'dashboard_dashboard_executivo';
$PAGE_TITLE = 'Dashboard Executivo';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$rotuloCat = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));
$qs = static fn(array $extra = []) => http_build_query(array_filter(['safra' => $fSafra, 'talhao' => $fTalhao] + $extra));

/* Redesenho ECharts (A4-04/A4-05) — DEFAULT; aprovação formal pela auditoria
   A0 do lote. Escape hatch reversível: ?classico=1 volta ao render anterior
   (mantido abaixo até a auditoria confirmar a remoção). Reusa todas as
   variáveis acima; o piloto não tem queries próprias. */
if (empty($_GET['classico'])) {
    require __DIR__ . '/_executivo_piloto.php';
    require __DIR__ . '/../includes/agro_footer_simple.php';
    return;
}
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Dashboard Executivo',
      'Visão consolidada da fazenda: dinheiro, custo, produção e pendências — planejado × realizado', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <select name="safra" onchange="this.form.submit()">
          <?php foreach ($safras as $s): ?>
            <option value="<?= (int)$s['id'] ?>"<?= $fSafra === (int)$s['id'] ? ' selected' : '' ?>>
              <?= h($safraRot[(int)$s['id']] ?? $s['identificacao']) ?> (<?= h((string)$s['status']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <select name="talhao" onchange="this.form.submit()">
          <option value="0">Todas as válvulas</option>
          <?php foreach ($talhoes as $tid => $tcod): ?>
            <option value="<?= (int)$tid ?>"<?= $fTalhao === (int)$tid ? ' selected' : '' ?>><?= h($tcod) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vhint">válvula filtra área, custo, faturamento e produção; financeiro, operação e estoque são do tenant inteiro</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Fazendas ativas</span>
        <strong class="vnum" style="font-size:1.25rem"><?= (int)$estrutura['fazendas'] ?></strong>
        <span class="vhint"><?= numFmt((float)$estrutura['area_total'], 1) ?> ha totais</span></div>
      <div class="vkpi"><span class="vhint">Área plantada (safra)</span>
        <strong class="vnum" style="font-size:1.25rem"><?= numFmt($areaPlantada, 2) ?> ha</strong></div>
      <div class="vkpi"><span class="vhint">Safras ativas</span>
        <strong class="vnum" style="font-size:1.25rem"><?= $safrasAtivas ?></strong>
        <?php if ($safraSel): ?><span class="vhint">em foco: <?= h($safraSelRot) ?></span><?php endif; ?></div>
      <div class="vkpi"><span class="vhint">Alertas abertos</span>
        <strong class="vnum" style="font-size:1.25rem;color:<?= $alertasCriticos > 0 ? '#b3261e' : ($alertasAbertos > 0 ? '#B07A1C' : 'var(--vero-ok,#1a7f4b)') ?>">
          <?= $alertasAbertos ?></strong>
        <?php if ($alertasCriticos > 0): ?><span class="vhint" style="color:#b3261e"><?= $alertasCriticos ?> crítico(s)</span><?php endif; ?></div>
    </div>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Financeiro</strong>
      <span style="display:flex;gap:6px">
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/financeiro/fluxo_caixa.php">Fluxo de caixa</a>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/dashboard/dashboard_financeiro.php">Detalhe</a>
      </span></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">A receber em aberto</span>
        <strong class="vnum" style="font-size:1.15rem;color:var(--vero-ok,#1a7f4b)">R$ <?= numFmt((float)$posicao['receber_aberto'], 2) ?></strong>
        <?php if ((float)$posicao['receber_vencido'] > 0): ?>
          <span class="vhint" style="color:#b3261e">vencido: R$ <?= numFmt((float)$posicao['receber_vencido'], 2) ?></span>
        <?php endif; ?></div>
      <div class="vkpi"><span class="vhint">A pagar em aberto</span>
        <strong class="vnum" style="font-size:1.15rem;color:#b3261e">R$ <?= numFmt((float)$posicao['pagar_aberto'], 2) ?></strong>
        <?php if ((float)$posicao['pagar_vencido'] > 0): ?>
          <span class="vhint" style="color:#b3261e">vencido: R$ <?= numFmt((float)$posicao['pagar_vencido'], 2) ?></span>
        <?php endif; ?></div>
      <div class="vkpi"><span class="vhint">Caixa de <?= date('m/Y') ?></span>
        <strong class="vnum" style="font-size:1.15rem;<?= $saldoMes < 0 ? 'color:#b3261e' : '' ?>">R$ <?= numFmt($saldoMes, 2) ?></strong>
        <span class="vhint">+<?= numFmt((float)$caixaMes['entradas'], 2) ?> / −<?= numFmt((float)$caixaMes['saidas'], 2) ?></span></div>
      <div class="vkpi"><span class="vhint">Títulos vencendo (15d)</span>
        <strong class="vnum" style="font-size:1.15rem;<?= (int)$posicao['venc_15d'] > 0 ? 'color:#B07A1C' : '' ?>"><?= (int)$posicao['venc_15d'] ?></strong></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Custo da safra × orçamento</strong>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/custeio/realizado.php?safra=<?= $fSafra ?>">Detalhe</a></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;padding:12px 14px 0">
        <div class="vkpi"><span class="vhint">Custo realizado</span>
          <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($custoSafra, 2) ?></strong></div>
        <div class="vkpi"><span class="vhint">Custo / ha</span>
          <strong class="vnum" style="font-size:1.15rem"><?= $custoHa !== null ? 'R$ ' . numFmt($custoHa, 2) : '—' ?></strong></div>
        <div class="vkpi"><span class="vhint">Orçamento consumido</span>
          <strong class="vnum" style="font-size:1.15rem;<?= $pctOrc !== null && $pctOrc > 100 ? 'color:#b3261e' : '' ?>">
            <?= $pctOrc !== null ? numFmt($pctOrc, 1) . '%' : '—' ?></strong>
          <?php if ($orc): ?><span class="vhint">orçado: R$ <?= numFmt($fTalhao ? $orcComparar : (float)$orc['valor_total'], 2) ?> (<?= h((string)$orc['status']) ?>)<?= $fTalhao ? ' · rateado p/ válvula' : '' ?></span>
          <?php else: ?><span class="vhint">sem orçamento para a safra</span><?php endif; ?></div>
      </div>
      <?php if (!$custeioCat): ?>
        <div class="vempty">Nenhum custo lançado para esta safra<?= $fTalhao ? ' nesta válvula' : '' ?>.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Categoria</th><th style="text-align:right">Total (R$)</th><th style="width:36%">Participação</th></tr></thead>
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
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong>Produção e resultado da safra</strong>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/custeio/resultado_safra.php?safra=<?= $fSafra ?>">Detalhe</a></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;padding:12px 14px">
        <div class="vkpi"><span class="vhint">Colheita prevista</span>
          <strong class="vnum" style="font-size:1.15rem"><?= numFmt((float)$producao['previsto'], 0) ?> kg</strong></div>
        <div class="vkpi"><span class="vhint">Colheita realizada</span>
          <strong class="vnum" style="font-size:1.15rem"><?= numFmt((float)$producao['realizado'], 0) ?> kg</strong>
          <?php if ((float)$producao['previsto'] > 0): ?>
            <span class="vhint"><?= numFmt((float)$producao['realizado'] / (float)$producao['previsto'] * 100, 1) ?>% do previsto</span>
          <?php endif; ?></div>
        <div class="vkpi"><span class="vhint">Faturamento (vendas)</span>
          <strong class="vnum" style="font-size:1.15rem">R$ <?= numFmt($faturamento, 2) ?></strong>
          <?php if ($precoMedio !== null): ?><span class="vhint">preço médio R$ <?= numFmt($precoMedio, 2) ?>/kg</span><?php endif; ?></div>
        <div class="vkpi"><span class="vhint">Custo / kg colhido</span>
          <strong class="vnum" style="font-size:1.15rem"><?= $custoKg !== null ? 'R$ ' . numFmt($custoKg, 2) : '—' ?></strong></div>
        <div class="vkpi"><span class="vhint" title="Faturamento − custeio total da safra (inclui descontos/depreciação) = Res. Líquido no Financeiro (glossário T30)">Resultado líquido</span>
          <strong class="vnum" style="font-size:1.15rem;color:<?= $resultado >= 0 ? 'var(--vero-ok,#1a7f4b)' : '#b3261e' ?>">
            R$ <?= numFmt($resultado, 2) ?></strong>
          <?php if ($margem !== null): ?><span class="vhint">margem líq. <?= numFmt($margem, 1) ?>%</span><?php endif; ?>
          <?php /* R2-04: pendências sem safra fora do resultado até o rateio */ ?>
          <?php if ($pendSemSafra > 0.005): ?><span class="vhint" style="color:#B07A1C">+ <?= $pendFmt ?> pendentes de rateio</span><?php endif; ?></div>
      </div>
      <div class="vhint" style="padding:0 14px 12px">
        Resultado líquido = faturamento das vendas da safra − custeio total da safra (inclui descontos/depreciação;
        mesma definição do "Res. Líquido" do Financeiro — glossário T30). Sem filtro de fazenda no faturamento
        (a venda não carrega fazenda). Metas formais por indicador dependem da validação P-44.
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Operação</strong>
        <span style="display:flex;gap:6px">
          <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/agro/atividades.php">Atividades</a>
          <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/dashboard/dashboard_operacional.php">Detalhe</a>
        </span></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;padding:12px 14px">
        <div class="vkpi"><span class="vhint">Atividades planejadas</span>
          <strong class="vnum" style="font-size:1.15rem"><?= (int)$atividades['planejadas'] ?></strong>
          <?php if ((int)$atividades['atrasadas'] > 0): ?>
            <span class="vhint" style="color:#b3261e"><?= (int)$atividades['atrasadas'] ?> atrasada(s)</span><?php endif; ?></div>
        <div class="vkpi"><span class="vhint">Em execução</span>
          <strong class="vnum" style="font-size:1.15rem"><?= (int)$atividades['em_execucao'] ?></strong></div>
        <div class="vkpi"><span class="vhint">Concluídas (30d)</span>
          <strong class="vnum" style="font-size:1.15rem"><?= (int)$atividades['concluidas_30d'] ?></strong></div>
        <div class="vkpi"><span class="vhint">Apontamentos (30d)</span>
          <strong class="vnum" style="font-size:1.15rem"><?= $apont30 ?></strong></div>
      </div>
      <?php if ($alertas): ?>
      <table class="vtable">
        <thead><tr><th>Alertas por categoria</th><th style="text-align:right">Abertos</th><th style="text-align:right">Críticos</th></tr></thead>
        <tbody>
        <?php foreach ($alertas as $al): ?>
          <tr>
            <td><strong><?= h(ucfirst((string)$al['categoria'])) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= (int)$al['total'] ?></td>
            <td class="vnum" style="text-align:right;<?= (int)$al['criticos'] > 0 ? 'color:#b3261e;font-weight:700' : '' ?>"><?= (int)$al['criticos'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="padding:8px 14px">
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/dashboard/indicadores_alertas.php">Fila unificada de alertas</a>
      </div>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong>Estoque</strong>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/produtos.php">Produtos</a></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;padding:12px 14px">
        <div class="vkpi"><span class="vhint">Abaixo do mínimo</span>
          <strong class="vnum" style="font-size:1.25rem;color:<?= $estoqueMin > 0 ? '#b3261e' : 'var(--vero-ok,#1a7f4b)' ?>"><?= $estoqueMin ?></strong>
          <span class="vhint">produto(s)</span></div>
        <div class="vkpi"><span class="vhint">Lotes vencendo (30d)</span>
          <strong class="vnum" style="font-size:1.25rem;color:<?= $lotesVenc > 0 ? '#B07A1C' : 'var(--vero-ok,#1a7f4b)' ?>"><?= $lotesVenc ?></strong>
          <span class="vhint">lote(s)</span></div>
      </div>
      <div class="vhint" style="padding:0 14px 12px">
        Detalhe de produtos e lotes no <a href="<?= $base ?>/dashboard/dashboard_operacional.php">dashboard operacional</a>
        e em <a href="<?= $base ?>/estoque/alertas.php">Estoque → Alertas</a>.
      </div>
    </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Planejado × realizado da safra<?= $safraSel ? ' ' . h($safraSelRot) : '' ?></strong></div>
    <table class="vtable">
      <thead><tr><th>Indicador</th><th style="text-align:right">Planejado</th><th style="text-align:right">Realizado</th><th style="width:30%">Execução</th></tr></thead>
      <tbody>
        <?php
        $linhas = [
            ['Custo (R$)', $orc ? (float)$orc['valor_total'] : null, $custoSafraTotal, 2, true],
            ['Colheita (kg)', (float)$producao['previsto'] > 0 ? (float)$producao['previsto'] : null, (float)$producao['realizado'], 0, false],
        ];
        /* A3-T16 (P-44): metas formais confrontadas com o realizado */
        $metasSafra = vero_rows("SELECT indicador, valor_meta FROM gestao_metas WHERE tenant_id=:t AND safra_id=:s",
            [':t' => $t, ':s' => $fSafra]);
        $realPorInd = [
            'custo_total' => [$custoSafraTotal, 2, true],
            'custo_ha'    => [$custoHa ?? 0.0, 2, true],
            'kg_total'    => [(float)$producao['realizado'], 0, false],
            'kg_ha'       => [$areaPlantada > 0 ? (float)$producao['realizado'] / $areaPlantada : 0.0, 0, false],
            'faturamento' => [$faturamento, 2, false],
            'preco_kg'    => [$precoMedio ?? 0.0, 2, false],
            'margem_pct'  => [$margem ?? 0.0, 1, false],
        ];
        $rotuloInd = ['custo_total' => 'Meta: custo total (R$)', 'custo_ha' => 'Meta: custo/ha (R$)',
            'kg_total' => 'Meta: colheita (kg)', 'kg_ha' => 'Meta: kg/ha', 'faturamento' => 'Meta: faturamento (R$)',
            'preco_kg' => 'Meta: preço médio (R$/kg)', 'margem_pct' => 'Meta: margem (%)'];
        foreach ($metasSafra as $mt) {
            $k = (string)$mt['indicador'];
            if (!isset($realPorInd[$k])) continue;
            [$real, $dec, $estouroRuim] = $realPorInd[$k];
            $linhas[] = [$rotuloInd[$k] ?? $k, (float)$mt['valor_meta'], $real, $dec, $estouroRuim];
        }
        foreach ($linhas as [$rotulo, $plan, $real, $dec, $estouroRuim]):
            $pct = ($plan !== null && $plan > 0) ? $real / $plan * 100 : null;
            $alerta = $pct !== null && $estouroRuim && $pct > 100; ?>
        <tr>
          <td><strong><?= h($rotulo) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= $plan !== null ? numFmt($plan, $dec) : '<span class="vhint">sem plano</span>' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($real, $dec) ?></strong></td>
          <td><?php if ($pct !== null): ?>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
                <div style="height:100%;width:<?= number_format(min($pct, 100), 1, '.', '') ?>%;background:<?= $alerta ? '#b3261e' : '#005059' ?>;border-radius:5px"></div>
              </div>
              <span class="vnum vhint" style="<?= $alerta ? 'color:#b3261e;font-weight:700' : '' ?>"><?= numFmt($pct, 1) ?>%</span>
            </div>
          <?php else: ?><span class="vhint">—</span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">
      Planejado = orçamento da safra (Custos → Orçamento) e colheita prevista (registros de colheita).
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
