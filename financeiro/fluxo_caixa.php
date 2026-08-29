<?php
/* ============================================================
   VERO — Financeiro / Fluxo de Caixa  (tela real)
   Rota: /financeiro/fluxo_caixa.php   Guard: financeiro.fluxo_caixa
   Redesenho A4 p/ o protótipo docs/vero_fluxo_caixa.html:
   KPIs + gráficos (ECharts vendor LOCAL) no lugar da tabela mensal.
   NENHUMA métrica inventada — todas as séries vêm das mesmas queries
   reais que a versão em tabela já usava (movimentacoes_financeiras +
   comercial_contratos). Sem dado → gráfico mostra estado vazio.
   Fonte: movimentacoes_financeiras (razão hash-chain)
   Realizado = status 'pago' por data_pagamento;
   Previsto  = status 'aberto' por vencimento.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$ano = (int)($_GET['ano'] ?? date('Y'));
if ($ano < 2000 || $ano > 2100) $ano = (int)date('Y');

$anos = array_map('intval', array_column(vero_rows(
    "SELECT DISTINCT YEAR(COALESCE(data_pagamento, data_vencimento, data_competencia)) AS a
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status <> 'cancelado'
        AND COALESCE(data_pagamento, data_vencimento, data_competencia) IS NOT NULL
      ORDER BY a DESC", [':t' => vero_tenant()]), 'a'));
if (!in_array($ano, $anos, true)) $anos[] = $ano;

/* Realizado: caixa efetivo por mês de pagamento */
$realizado = vero_rows(
    "SELECT MONTH(data_pagamento) AS mes,
            COALESCE(SUM(CASE WHEN tipo = 'receber' THEN valor ELSE 0 END),0) AS entradas,
            COALESCE(SUM(CASE WHEN tipo = 'pagar' THEN valor ELSE 0 END),0) AS saidas
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status = 'pago' AND YEAR(data_pagamento) = :a
      GROUP BY MONTH(data_pagamento)", [':t' => vero_tenant(), ':a' => $ano]);

/* Previsto: abertos por mês de VENCIMENTO real. F-04 (auditoria 19/07):
   título sem vencimento NÃO entra mais disfarçado no mês da competência —
   ganha bucket explícito "Sem vencimento" (abaixo), senão o total do fluxo
   não fecha com o "em aberto" das telas de contas. */
$previsto = vero_rows(
    "SELECT MONTH(data_vencimento) AS mes,
            COALESCE(SUM(CASE WHEN tipo = 'receber' THEN valor ELSE 0 END),0) AS entradas,
            COALESCE(SUM(CASE WHEN tipo = 'pagar' THEN valor ELSE 0 END),0) AS saidas
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status = 'aberto'
        AND YEAR(data_vencimento) = :a
      GROUP BY MONTH(data_vencimento)",
    [':t' => vero_tenant(), ':a' => $ano]);

/* F-04: abertos SEM data de vencimento (qualquer ano — não pertencem a mês
   nenhum). Entram no bucket "Sem vencimento" e no TOTAL previsto, e ficam
   listados para o gestor dar vencimento na tela de origem/contas. */
$semVenc = vero_rows(
    "SELECT id, tipo, valor, descricao, documento, origem_tipo, data_competencia
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status = 'aberto' AND data_vencimento IS NULL
      ORDER BY tipo, id", [':t' => vero_tenant()]);
$svE = 0.0; $svS = 0.0;
foreach ($semVenc as $sv) {
    if ($sv['tipo'] === 'receber') $svE += (float)$sv['valor']; else $svS += (float)$sv['valor'];
}

$meses = [];
for ($m = 1; $m <= 12; $m++) $meses[$m] = ['re' => 0.0, 'rs' => 0.0, 'pe' => 0.0, 'ps' => 0.0];
foreach ($realizado as $r) { $meses[(int)$r['mes']]['re'] = (float)$r['entradas']; $meses[(int)$r['mes']]['rs'] = (float)$r['saidas']; }
foreach ($previsto as $r)  { $meses[(int)$r['mes']]['pe'] = (float)$r['entradas']; $meses[(int)$r['mes']]['ps'] = (float)$r['saidas']; }

/* Saldo realizado acumulado de anos anteriores (carrega para janeiro) */
$saldoAnterior = (float)vero_val(
    "SELECT COALESCE(SUM(CASE WHEN tipo = 'receber' THEN valor ELSE -valor END),0)
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status = 'pago' AND YEAR(data_pagamento) < :a",
    [':t' => vero_tenant(), ':a' => $ano]);

$totRE = array_sum(array_column($meses, 're'));
$totRS = array_sum(array_column($meses, 'rs'));
$totPE = array_sum(array_column($meses, 'pe')) + $svE;   /* F-04: inclui sem vencimento */
$totPS = array_sum(array_column($meses, 'ps')) + $svS;

/* Vencidos em aberto (qualquer ano) — alerta de caixa */
$vencido = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN tipo = 'pagar' THEN valor ELSE 0 END),0) AS pagar,
            COALESCE(SUM(CASE WHEN tipo = 'receber' THEN valor ELSE 0 END),0) AS receber
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND status = 'aberto'
        AND data_vencimento IS NOT NULL AND data_vencimento < CURDATE()", [':t' => vero_tenant()]);

/* A3-T17 (P-09): contratos de pré-venda ATIVOS = compromisso futuro de
   receita (valor RESTANTE = saldo kg × preço travado). INFORMATIVO — não
   entra no acumulado do razão até virar venda faturada. */
$contratosPrev = vero_rows(
    "SELECT ct.numero, ct.data_vencimento, ct.preco_kg,
            COALESCE(NULLIF(cc.nome_fantasia,''), cc.razao_social) AS comprador,
            ct.kg_contratado - COALESCE((SELECT SUM(v.kg_total) FROM comercial_vendas v
              WHERE v.tenant_id = ct.tenant_id AND v.contrato_id = ct.id AND v.status <> 'cancelada'), 0) AS kg_saldo
       FROM comercial_contratos ct
       JOIN comercial_compradores cc ON cc.id = ct.comprador_id
      WHERE ct.tenant_id = :t AND ct.status = 'ativo'
     HAVING kg_saldo > 0
      ORDER BY ct.data_vencimento", [':t' => vero_tenant()]);
$totContratos = 0.0;
foreach ($contratosPrev as $cp) $totContratos += (float)$cp['kg_saldo'] * (float)$cp['preco_kg'];

$NOME_MES = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];

/* ── Payload das séries p/ ECharts (LOCAL) — só reempacota o que já foi lido ── */
$serieEnt = $serieSai = $serieRec = $seriePag = [];
for ($m = 1; $m <= 12; $m++) {
    $serieEnt[] = round($meses[$m]['re'], 2);
    $serieSai[] = round($meses[$m]['rs'], 2);
    $serieRec[] = round($meses[$m]['pe'], 2);
    $seriePag[] = round($meses[$m]['ps'], 2);
}
$anoAtual = (int)date('Y');
/* índice (0-based) do "hoje" na série: ano passado = tudo realizado (Dez);
   ano futuro = tudo projeção (-1); ano corrente = mês atual. */
$idxAtual = $ano < $anoAtual ? 11 : ($ano > $anoAtual ? -1 : (int)date('n') - 1);

$FLUXO = [
    'meses'        => array_values($NOME_MES),
    'ano'          => $ano,
    'entradas'     => $serieEnt,
    'saidas'       => $serieSai,
    'aReceber'     => $serieRec,
    'aPagar'       => $seriePag,
    'saldoInicial' => round($saldoAnterior, 2),
    'atual'        => $idxAtual,
    'semVenc'      => ['pagar' => round($svS, 2), 'receber' => round($svE, 2)],
    'titulos'      => array_map(static fn($sv) => [
        'tipo'   => (string)$sv['tipo'],
        'desc'   => (string)($sv['descricao'] ?? '—'),
        'origem' => $sv['origem_tipo'] !== null ? str_replace('_', ' ', (string)$sv['origem_tipo']) : 'manual',
        'comp'   => $sv['data_competencia'] ? date('d/m/Y', strtotime((string)$sv['data_competencia'])) : '—',
        'valor'  => round((float)$sv['valor'], 2),
    ], $semVenc),
    'contratos'    => array_map(static fn($cp) => [
        'contrato'  => (string)$cp['numero'],
        'comprador' => (string)$cp['comprador'],
        'entrega'   => $cp['data_vencimento'] ? date('d/m/Y', strtotime((string)$cp['data_vencimento'])) : '—',
        'kg'        => round((float)$cp['kg_saldo'], 0),
        'preco'     => round((float)$cp['preco_kg'], 4),
        'receita'   => round((float)$cp['kg_saldo'] * (float)$cp['preco_kg'], 2),
    ], $contratosPrev),
];

$temMovimento = ($totRE || $totRS || $totPE || $totPS);

$GUARD      = ['macro' => 'financeiro', 'micro' => 'fluxo_caixa'];
$PAGE_VIEW  = 'financeiro_fluxo_caixa';
$PAGE_TITLE = 'Fluxo de Caixa';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<style>
/* ===== Fluxo de Caixa A4 — escopado em .fcx (tokens VERO, casca clara) ===== */
.fcx{--ac:#005059;--acd:#00363D;--ac3:#2A767C;--olive:#4E9CA1;--warm:#FBF8F2;--track:#EEE6D6;
  --ink:#241B14;--ink2:#2B2018;--mut:#8A7C68;--mut2:#9A8C78;--bd:#E3D9C8;--bd2:#DDD2BF;
  --pos:#0E7E72;--pos-bg:#DDEDEB;--amber:#B57C1A;--amber-d:#7A5410;--amber-bg:#F3E7C8;
  --danger:#B23A2E;--danger-bg:#F2DCD8;--num:'IBM Plex Mono',ui-monospace,monospace}
.fcx .fseg{display:inline-flex;background:var(--track);border-radius:8px;padding:3px;gap:2px}
.fcx .fseg button{border:0;background:transparent;color:var(--mut);font:700 12.5px 'IBM Plex Sans',sans-serif;padding:6px 14px;border-radius:6px;cursor:pointer;transition:.18s}
.fcx .fseg button.on{background:#fff;color:var(--ac);box-shadow:0 1px 3px rgba(0,0,0,.1)}
.fcx .kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:13px;margin-bottom:16px}
.fcx .kpi{background:#fff;border:1px solid var(--bd);border-radius:13px;padding:15px 16px;box-shadow:0 1px 2px rgba(36,27,20,.05);position:relative;overflow:hidden}
.fcx .kpi .strip{position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--ac3)}
.fcx .kpi .lab{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut);font-weight:700;margin-bottom:7px}
.fcx .kpi .val{font-family:var(--num);font-size:22px;font-weight:600;line-height:1.05;letter-spacing:-1px;white-space:nowrap;color:var(--ink)}
.fcx .kpi .val small{font-size:12px;color:var(--mut);font-weight:500;letter-spacing:0}
.fcx .kpi .sub{font-size:11px;color:var(--mut);margin-top:6px;font-weight:500}
.fcx .kpi.pos .strip{background:var(--pos)}.fcx .kpi.pos .val{color:var(--pos)}
.fcx .kpi.danger .strip{background:var(--danger)}.fcx .kpi.danger .val{color:var(--danger)}
.fcx .kpi.amber .strip{background:var(--amber)}.fcx .kpi.amber .val{color:var(--amber-d)}
.fcx .fgrid{display:grid;gap:16px}
.fcx .g2{grid-template-columns:1.55fr 1fr}
.fcx .fcard{background:#fff;border:1px solid var(--bd);border-radius:13px;box-shadow:0 1px 2px rgba(36,27,20,.05);padding:16px 18px 10px;display:flex;flex-direction:column;min-width:0}
.fcx .fcard h3{font-size:14.5px;font-weight:700;letter-spacing:-.2px;color:var(--ink2)}
.fcx .fcard .desc{font-size:11.5px;color:var(--mut);font-weight:500;margin-top:2px}
.fcx .legend-inline{display:flex;gap:14px;flex-wrap:wrap;font-size:11.5px;color:var(--mut);font-weight:600;padding:6px 2px 10px}
.fcx .legend-inline i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:-1px}
.fcx .fchart{width:100%}
.fcx .mt16{margin-top:16px}
.fcx .flist{padding:2px 0 8px;overflow:auto;max-height:330px}
.fcx .lrow{display:flex;align-items:center;gap:11px;padding:10px 2px;border-bottom:1px solid var(--bd)}
.fcx .lrow:last-child{border-bottom:0}
.fcx .lrow .ld{flex:1;min-width:0}
.fcx .lrow .lt{font-weight:600;font-size:12.5px;color:var(--ink)}
.fcx .lrow .ls{font-size:11px;color:var(--mut);margin-top:1px}
.fcx .lrow .lv{font-family:var(--num);font-weight:600;font-size:13px;white-space:nowrap}
.fcx .chip{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;padding:2px 7px;border-radius:20px}
.fcx .chip.pay{background:var(--danger-bg);color:var(--danger)}.fcx .chip.rec{background:var(--pos-bg);color:var(--pos)}.fcx .chip.warn{background:var(--amber-bg);color:var(--amber-d)}
.fcx .fempty{padding:40px 14px;text-align:center;color:var(--mut2);font-size:13px}
.fcx .fnote{padding:10px 14px;font-size:11.5px;color:var(--mut2)}
@media(max-width:1200px){.fcx .kpis{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1080px){.fcx .kpis{grid-template-columns:repeat(2,1fr)}.fcx .g2{grid-template-columns:1fr}}
@media(max-width:520px){.fcx .kpis{grid-template-columns:1fr}}
</style>

<div class="vwrap fcx">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Fluxo de Caixa', 'Caixa realizado (baixas) e previsão dos títulos em aberto, ao longo do ano', null) ?>

  <div class="vcard" style="margin-bottom:16px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <label class="vhint">Ano</label>
        <select name="ano" onchange="this.form.submit()">
          <?php foreach ($anos as $a): ?>
            <option value="<?= $a ?>"<?= $a === $ano ? ' selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <div style="display:flex;flex-direction:column;gap:5px">
        <label class="vhint">Visão do gráfico</label>
        <div class="fseg" id="fSeg">
          <button type="button" data-v="mensal" class="on">Mensal</button>
          <button type="button" data-v="acumulado">Acumulado</button>
        </div>
      </div>
      <span class="vsub" style="margin-left:auto">Saldo realizado até <?= $ano - 1 ?>:
        <strong class="vnum">R$ <?= numFmt($saldoAnterior, 2) ?></strong></span>
      <a href="<?= rtrim(BIOS_BASE, '/') ?>/financeiro/fluxo_caixa" class="vhint"
         style="text-decoration:underline;text-underline-offset:2px">Limpar filtros</a>
    </div>
  </div>

  <!-- KPIs (render server-side; degrada sem JS) -->
  <div class="kpis">
    <div class="kpi pos"><div class="strip"></div>
      <div class="lab">Entradas realizadas</div>
      <div class="val">R$ <?= numFmt($totRE, 2) ?></div>
      <div class="sub">baixas no ano <?= $ano ?></div></div>
    <div class="kpi danger"><div class="strip"></div>
      <div class="lab">Saídas realizadas</div>
      <div class="val">R$ <?= numFmt($totRS, 2) ?></div>
      <div class="sub">pagamentos no ano</div></div>
    <div class="kpi"><div class="strip"></div>
      <div class="lab">Saldo do ano</div>
      <div class="val" style="<?= ($totRE - $totRS) < 0 ? 'color:var(--danger)' : '' ?>">R$ <?= numFmt($totRE - $totRS, 2) ?></div>
      <div class="sub">entradas − saídas realizadas</div></div>
    <div class="kpi amber"><div class="strip"></div>
      <div class="lab">Previsto em aberto</div>
      <div class="val">+<?= numFmt($totPE, 0) ?> <small>/ −<?= numFmt($totPS, 0) ?></small></div>
      <div class="sub">a receber / a pagar<?= ($svE || $svS) ? ' · inclui sem venc.' : '' ?></div></div>
    <div class="kpi"><div class="strip"></div>
      <div class="lab">Receita futura (pré-venda)</div>
      <div class="val">R$ <?= numFmt($totContratos, 2) ?></div>
      <div class="sub"><?= count($contratosPrev) ?> contrato(s) ativo(s)</div></div>
  </div>

  <?php if (!$temMovimento && !$contratosPrev): ?>
    <div class="vcard"><div class="fempty">Nenhuma movimentação financeira em <?= $ano ?>.
      Registre baixas ou títulos em <a href="<?= rtrim(BIOS_BASE, '/') ?>/financeiro/contas_receber">Contas a Receber</a> /
      <a href="<?= rtrim(BIOS_BASE, '/') ?>/financeiro/contas_pagar">Contas a Pagar</a>.</div></div>
  <?php else: ?>

  <!-- Linha 1: entradas×saídas + previsto em aberto -->
  <div class="fgrid g2">
    <div class="fcard">
      <div><h3 id="fMainTitle">Entradas × Saídas por mês</h3>
        <div class="desc" id="fMainDesc">Realizado (baixas) com saldo acumulado sobreposto</div></div>
      <div class="legend-inline">
        <span><i style="background:var(--pos)"></i>Entradas</span>
        <span><i style="background:var(--danger)"></i>Saídas</span>
        <span><i style="background:var(--ac3)"></i>Saldo acumulado</span>
      </div>
      <div class="fchart" id="fMain" style="height:330px"></div>
    </div>
    <div class="fcard">
      <div><h3>Previsto em aberto</h3>
        <div class="desc">A receber × a pagar por mês (títulos com vencimento) + sem venc.</div></div>
      <div class="fchart" id="fOpen" style="height:330px"></div>
    </div>
  </div>

  <!-- Linha 2: projeção de saldo + títulos em aberto/contratos -->
  <div class="fgrid g2 mt16">
    <div class="fcard">
      <div><h3>Projeção de saldo · realizado + previsto</h3>
        <div class="desc">Saldo acumulado realizado até hoje, projetado adiante pelos títulos em aberto</div></div>
      <div class="fchart" id="fRun" style="height:320px"></div>
    </div>
    <div class="fcard">
      <div><h3>Títulos em aberto &amp; contratos</h3>
        <div class="desc">Sem vencimento definido e receita futura de pré-venda</div></div>
      <div class="flist" id="fList">
        <div class="fempty" style="padding:24px 8px">Nada em aberto sem vencimento e nenhum contrato de pré-venda ativo.</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($semVenc): ?>
  <div class="vcard" style="margin-top:16px">
    <div class="vtoolbar"><strong>Títulos em aberto sem data de vencimento</strong>
      <span class="vsub">pagar <strong class="vnum">R$ <?= numFmt($svS, 2) ?></strong> ·
        receber <strong class="vnum">R$ <?= numFmt($svE, 2) ?></strong></span>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= rtrim(BIOS_BASE, '/') ?>/financeiro/contas_pagar">Contas a Pagar</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= rtrim(BIOS_BASE, '/') ?>/financeiro/contas_receber">Contas a Receber</a></div>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>Tipo</th><th>Descrição</th><th>Documento</th><th>Origem</th>
        <th>Competência</th><th style="text-align:right">Valor (R$)</th></tr></thead>
      <tbody>
      <?php foreach ($semVenc as $sv): ?>
        <tr>
          <td><?= $sv['tipo'] === 'receber'
                ? '<span class="vbadge vb-ok">a receber</span>'
                : '<span class="vbadge vb-warn">a pagar</span>' ?></td>
          <td><strong><?= h((string)($sv['descricao'] ?? '—')) ?></strong></td>
          <td class="vnum"><?= h((string)($sv['documento'] ?: '—')) ?></td>
          <td><?= $sv['origem_tipo'] !== null
                ? '<span class="vbadge vb-info">' . h(str_replace('_', ' ', (string)$sv['origem_tipo'])) . '</span>'
                : '<span class="vhint">manual</span>' ?></td>
          <td class="vnum"><?= $sv['data_competencia'] ? date('d/m/Y', strtotime((string)$sv['data_competencia'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$sv['valor'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="fnote">
      Defina o vencimento na tela de origem (título com origem) ou em Contas a Pagar/Receber (manual)
      para o valor entrar na projeção mensal. Sem isso ele permanece no bucket "Sem venc." — nunca some do total previsto.
    </div>
  </div>
  <?php endif; ?>

  <?php if ($contratosPrev): ?>
  <div class="vcard" style="margin-top:16px">
    <div class="vtoolbar"><strong>Compromissos de contratos de pré-venda (previsto informativo)</strong>
      <span class="vsub">total restante: <strong class="vnum">R$ <?= numFmt($totContratos, 2) ?></strong></span>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= rtrim(BIOS_BASE, '/') ?>/comercial/contratos_venda">Contratos</a></div>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>Contrato</th><th>Comprador</th><th>Entrega/venc.</th>
        <th style="text-align:right">Saldo (kg)</th><th style="text-align:right">Preço (R$/kg)</th>
        <th style="text-align:right">Receita futura (R$)</th></tr></thead>
      <tbody>
      <?php foreach ($contratosPrev as $cp): ?>
        <tr>
          <td class="vnum"><strong><?= h((string)$cp['numero']) ?></strong></td>
          <td><?= h((string)$cp['comprador']) ?></td>
          <td class="vnum"><?= $cp['data_vencimento'] ? date('d/m/Y', strtotime((string)$cp['data_vencimento'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$cp['kg_saldo'], 0) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$cp['preco_kg'], 4) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$cp['kg_saldo'] * (float)$cp['preco_kg'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="fnote">
      Receita futura de contratos ATIVOS a preço travado — informativa: só entra no razão
      quando a venda for faturada (aí vira "previsto" pelo vencimento do título).
    </div>
  </div>
  <?php endif; ?>

  <div class="fnote" style="text-align:center;margin-top:18px">
    Realizado usa a data da baixa; previsto usa o vencimento. Títulos sem vencimento entram no total
    previsto mas não em nenhum mês. Contratos de pré-venda são informativos até o faturamento.
    Cancelados ficam fora. O acumulado parte do saldo realizado dos anos anteriores.
  </div>
</div>

<script defer src="<?= $base ?>/assets/vendor/echarts/echarts.min.js"></script>
<script>
(function(){
  var F = <?= jsvar($FLUXO) ?>;
  var C = {pos:'#0E7E72',danger:'#B23A2E',ac:'#005059',ac3:'#2A767C',amber:'#B57C1A',
           mut:'#8A7C68',bd:'#E3D9C8',bd2:'#DDD2BF',ink:'#241B14',surface:'#fff'};
  var MONO = "'IBM Plex Mono',ui-monospace,monospace";
  var S = {view:'mensal'};

  var brl = function(v){ return v==null ? '—' : 'R$ ' + Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); };
  var kAxis = function(v){ return Math.abs(v)>=1000 ? (v/1000).toLocaleString('pt-BR',{maximumFractionDigits:1})+'k' : String(v); };
  var tip = { backgroundColor:C.surface, borderColor:C.bd, textStyle:{color:C.ink,fontFamily:"'IBM Plex Sans',sans-serif",fontSize:12},
    extraCssText:'box-shadow:0 8px 24px -12px rgba(8,38,42,.35);border-radius:9px' };
  var axC = { axisLine:{lineStyle:{color:C.bd2}}, axisTick:{show:false}, axisLabel:{color:C.mut,fontSize:11}, splitLine:{lineStyle:{color:C.bd}} };
  var axV = function(){ return { type:'value', axisLine:{lineStyle:{color:C.bd2}}, axisTick:{show:false},
    axisLabel:{color:C.mut,fontSize:11,fontFamily:MONO,formatter:kAxis}, splitLine:{lineStyle:{color:C.bd}} }; };

  function acumulado(){ var acc=[], run=F.saldoInicial; for(var i=0;i<12;i++){ run += F.entradas[i]-F.saidas[i]; acc.push(Math.round(run*100)/100); } return acc; }

  var chMain, chOpen, chRun;

  function renderMain(){
    var acc = acumulado();
    if(S.view==='mensal'){
      chMain.setOption({
        tooltip:Object.assign({trigger:'axis',axisPointer:{type:'shadow'},valueFormatter:brl}, tip),
        grid:{left:58,right:58,top:14,bottom:26},
        xAxis:Object.assign({type:'category',data:F.meses}, axC),
        yAxis:[ axV(), Object.assign(axV(),{splitLine:{show:false}}) ],
        series:[
          {type:'bar',name:'Entradas',data:F.entradas,barMaxWidth:18,itemStyle:{color:C.pos,borderRadius:[4,4,0,0]}},
          {type:'bar',name:'Saídas',data:F.saidas,barMaxWidth:18,itemStyle:{color:C.danger,borderRadius:[4,4,0,0]}},
          {type:'line',name:'Saldo acumulado',data:acc,yAxisIndex:1,smooth:true,symbolSize:6,
            lineStyle:{width:2.5,color:C.ac3},itemStyle:{color:C.ac3}}
        ]
      }, true);
      document.getElementById('fMainTitle').textContent='Entradas × Saídas por mês';
      document.getElementById('fMainDesc').textContent='Realizado (baixas) com saldo acumulado sobreposto';
    } else {
      var base=[],inc=[],dec=[],run=F.saldoInicial;
      for(var i=0;i<12;i++){ var net=F.entradas[i]-F.saidas[i];
        if(net>=0){ base.push(run); inc.push(net); dec.push(0); run+=net; }
        else { run+=net; base.push(run); inc.push(0); dec.push(-net); } }
      chMain.setOption({
        tooltip:Object.assign({trigger:'axis',formatter:function(ps){var i=ps[0].dataIndex;var net=F.entradas[i]-F.saidas[i];
          return F.meses[i]+'/'+F.ano+'<br/>Movimento: <b>'+brl(net)+'</b><br/>Saldo: <b>'+brl(acc[i])+'</b>';}}, tip),
        grid:{left:58,right:16,top:14,bottom:26},
        xAxis:Object.assign({type:'category',data:F.meses}, axC),
        yAxis:axV(),
        series:[
          {type:'bar',stack:'t',itemStyle:{color:'transparent'},data:base,silent:true},
          {type:'bar',stack:'t',name:'Entrada líq.',data:inc,barMaxWidth:26,itemStyle:{color:C.pos,borderRadius:[4,4,0,0]}},
          {type:'bar',stack:'t',name:'Saída líq.',data:dec,barMaxWidth:26,itemStyle:{color:C.danger,borderRadius:[4,4,0,0]}}
        ]
      }, true);
      document.getElementById('fMainTitle').textContent='Cascata de saldo mensal (waterfall)';
      document.getElementById('fMainDesc').textContent='Como cada mês soma/subtrai do saldo acumulado';
    }
  }

  function renderOpen(){
    var cats=[],rec=[],pag=[];
    for(var i=0;i<12;i++){ if(F.aReceber[i]||F.aPagar[i]){ cats.push(F.meses[i]); rec.push(F.aReceber[i]); pag.push(-F.aPagar[i]); } }
    if(F.semVenc.pagar||F.semVenc.receber){ cats.push('Sem venc.'); rec.push(F.semVenc.receber); pag.push(-F.semVenc.pagar); }
    if(!cats.length){ document.getElementById('fOpen').innerHTML='<div class="fempty">Nenhum título em aberto.</div>'; return; }
    chOpen.setOption({
      tooltip:Object.assign({trigger:'axis',axisPointer:{type:'shadow'},valueFormatter:function(v){return brl(Math.abs(v));}}, tip),
      legend:{top:0,textStyle:{color:C.mut,fontSize:11.5},itemWidth:14,itemHeight:8},
      grid:{left:52,right:16,top:34,bottom:24},
      xAxis:Object.assign({type:'category',data:cats}, axC, {axisLabel:{color:C.ink,fontWeight:600,fontSize:10.5}}),
      yAxis:axV(),
      series:[
        {type:'bar',name:'A receber',data:rec,barMaxWidth:20,itemStyle:{color:C.pos,borderRadius:[4,4,0,0]}},
        {type:'bar',name:'A pagar',data:pag,barMaxWidth:20,itemStyle:{color:C.danger,borderRadius:[0,0,4,4]}}
      ]
    });
  }

  function renderRun(){
    var acc=acumulado(), at=F.atual;
    var realizado=acc.map(function(v,i){ return (at>=0 && i<=at)?v:null; });
    var proj=[], start=at>=0?at:0, run=at>=0?acc[at]:F.saldoInicial;
    for(var i=0;i<12;i++){ if(i<start){ proj.push(null); } else if(i===start){ proj.push(Math.round(run*100)/100); }
      else { run += (F.aReceber[i]-F.aPagar[i]); proj.push(Math.round(run*100)/100); } }
    var series=[
      {type:'line',name:'Saldo realizado',data:realizado,smooth:true,symbolSize:6,connectNulls:false,
        lineStyle:{width:3,color:C.pos},itemStyle:{color:C.pos},
        areaStyle:{color:new echarts.graphic.LinearGradient(0,0,0,1,[{offset:0,color:'rgba(14,126,114,.20)'},{offset:1,color:'rgba(14,126,114,.02)'}])}},
      {type:'line',name:'Projeção (previsto)',data:proj,smooth:true,symbolSize:6,
        lineStyle:{width:2.5,color:C.amber,type:'dashed'},itemStyle:{color:C.amber}}
    ];
    if(at>=0 && at<=11){
      series.push({type:'line',data:F.meses.map(function(){return null;}),symbol:'none',silent:true,tooltip:{show:false},
        markLine:{silent:true,symbol:'none',data:[{xAxis:F.meses[at]}],lineStyle:{color:C.ac3,type:'dotted',width:1.5},
          label:{formatter:'hoje',color:C.ac3,fontSize:10}}});
    }
    chRun.setOption({
      tooltip:Object.assign({trigger:'axis',valueFormatter:brl}, tip),
      legend:{top:0,textStyle:{color:C.mut,fontSize:11.5},itemWidth:14,itemHeight:8},
      grid:{left:58,right:16,top:34,bottom:26},
      xAxis:Object.assign({type:'category',data:F.meses,boundaryGap:false}, axC),
      yAxis:axV(),
      series:series
    });
  }

  function renderList(){
    var el=document.getElementById('fList'), items=[];
    F.titulos.forEach(function(t){ var pay=t.tipo==='pagar';
      items.push({chip:pay?'pay':'rec',lab:pay?'A pagar · sem venc.':'A receber · sem venc.',
        t:t.desc, s:t.origem+' · comp. '+t.comp, v:(pay?'−':'+')+brl(t.valor), col:pay?C.danger:C.pos}); });
    F.contratos.forEach(function(c){
      items.push({chip:'warn',lab:'Pré-venda · informativo',
        t:c.contrato+' · '+c.comprador, s:'entrega '+c.entrega+' · '+c.kg+' kg × '+brl(c.preco),
        v:brl(c.receita), col:C.amber}); });
    if(!items.length) return;
    el.innerHTML = items.map(function(it){ return '<div class="lrow"><div class="ld"><div class="lt">'+
      it.t+'</div><div class="ls"><span class="chip '+it.chip+'">'+it.lab+'</span> · '+it.s+'</div></div>'+
      '<div class="lv" style="color:'+it.col+'">'+it.v+'</div></div>'; }).join('');
  }

  function boot(){
    if(typeof echarts==='undefined') return;
    var elM=document.getElementById('fMain'), elO=document.getElementById('fOpen'), elR=document.getElementById('fRun');
    if(!elM) return; /* estado vazio: charts não existem */
    chMain=echarts.init(elM); chOpen=echarts.init(elO); chRun=echarts.init(elR);
    renderMain(); renderOpen(); renderRun(); renderList();
    window.addEventListener('resize',function(){ [chMain,chOpen,chRun].forEach(function(c){ if(c) c.resize(); }); });
    document.getElementById('fSeg').addEventListener('click',function(e){ var b=e.target.closest('button'); if(!b) return;
      [].forEach.call(this.children,function(x){ x.classList.remove('on'); }); b.classList.add('on'); S.view=b.dataset.v; renderMain(); });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot); else boot();
})();
</script>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
