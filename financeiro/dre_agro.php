<?php
/* ============================================================
   VERO — Financeiro / DRE Agro  (tela real, leitura)
   Substitui o mock. Rota: /financeiro/dre_agro.php
   Guard: financeiro.dre_agro
   DRE GERENCIAL por safra ou ano em DUAS VISÕES (G-05):
   — Competência (padrão): receita pelas vendas emitidas (data da
     venda), custeio pela data de competência, depreciação gerencial
     DEDUZIDA em linha própria (R2-03) e despesas manuais pela data
     de competência.
   — Caixa: receita pelos recebimentos efetivados (vendas pagas,
     data do pagamento) e despesas manuais pagas; depreciação não é
     saída de caixa e fica informativa. Limite do schema: o custeio
     operacional não tem baixa de caixa vinculada
     (custeio_lancamentos.movimentacao_financeira_id vazio) — segue
     a data de competência também nesta visão (dito no rodapé).
   Não substitui a DRE contábil.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fSafra = (int)($_GET['safra'] ?? 0);
$fAno   = (int)($_GET['ano'] ?? date('Y'));
if ($fAno < 2000 || $fAno > 2100) $fAno = (int)date('Y');
$porSafra = $fSafra > 0;

/* G-05: visão do regime. 'competencia' é o padrão — é o regime que a
   tela sempre usou para receita e custeio (a versão anterior só tinha
   as despesas manuais por caixa, agora alinhadas por visão). */
$visao = (($_GET['visao'] ?? '') === 'caixa') ? 'caixa' : 'competencia';
$caixa = $visao === 'caixa';

/* Custeio agrupado pelo PLANO DE CONTAS (A3-T10): usa o
   plano_conta_id gravado pelos emissores; linha sem classificação
   agrupa pela categoria com o rótulo "Sem classificação".
   R2-03: a depreciação sai do grupo e vira linha própria
   "Depreciação (gerencial)" na exibição. O corte é por
   categoria='depreciacao' (cobre o lançamento direto do patrimônio,
   origem patrimonio_depreciacao/safra NULL, E a distribuição por
   rateio_execucao que leva a depreciação até a safra) — o mapa
   custeio/_plano_map.php (contrato) fica intocado; só a EXIBIÇÃO
   separa. Demais categorias que caírem em 3.99 continuam no grupo. */
$sqlCusteioPlano = static fn(string $condicao): string =>
    "SELECT pc.codigo,
            COALESCE(pc.nome, CONCAT('Sem classificação — ', COALESCE(cl.categoria,'outros'))) AS nome,
            SUM(cl.valor) AS total
       FROM custeio_lancamentos cl
       LEFT JOIN plano_contas pc ON pc.id = cl.plano_conta_id AND pc.tenant_id = cl.tenant_id
      WHERE cl.tenant_id = :t AND COALESCE(cl.categoria,'') <> 'depreciacao' AND {$condicao}
      GROUP BY pc.id, pc.codigo, pc.nome,
               CASE WHEN pc.id IS NULL THEN COALESCE(cl.categoria,'outros') END
      ORDER BY (pc.codigo IS NULL), pc.codigo, total DESC";

/* Depreciação gerencial lançada no custeio (mesma base que compunha o
   3.99): deduzida na visão competência, informativa na visão caixa. */
$sqlDeprec = static fn(string $condicao): string =>
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos
      WHERE tenant_id = :t AND categoria = 'depreciacao' AND {$condicao}";

if ($porSafra) {
    $receita = (float)vero_val(
        "SELECT COALESCE(SUM(valor_total),0) FROM comercial_vendas
          WHERE tenant_id=:t AND safra_id=:s AND status <> 'cancelada'"
        . ($caixa ? " AND status_pagamento='pago'" : ''),
        [':t' => $t, ':s' => $fSafra]);
    $custeio = vero_rows($sqlCusteioPlano("cl.safra_id = :s"), [':t' => $t, ':s' => $fSafra]);
    $depreciacao = (float)vero_val($sqlDeprec("safra_id = :s"), [':t' => $t, ':s' => $fSafra]);
    $despesasManuais = 0.0; /* despesas manuais não têm vínculo com safra */
} else {
    if ($caixa) {
        /* Caixa: só o que ENTROU (venda paga, pela data do pagamento)
           e o que SAIU (despesa manual paga, pela data do pagamento). */
        $receita = (float)vero_val(
            "SELECT COALESCE(SUM(valor_total),0) FROM comercial_vendas
              WHERE tenant_id=:t AND status <> 'cancelada'
                AND status_pagamento='pago' AND YEAR(data_pagamento)=:a",
            [':t' => $t, ':a' => $fAno]);
        $despesasManuais = (float)vero_val(
            "SELECT COALESCE(SUM(valor),0) FROM movimentacoes_financeiras
              WHERE tenant_id=:t AND tipo='pagar' AND status='pago' AND origem_tipo IS NULL
                AND YEAR(data_pagamento)=:a", [':t' => $t, ':a' => $fAno]);
    } else {
        /* Competência: venda pela data de emissão, despesa manual pela
           data de competência (fallback vencimento/pagamento se nula). */
        $receita = (float)vero_val(
            "SELECT COALESCE(SUM(valor_total),0) FROM comercial_vendas
              WHERE tenant_id=:t AND YEAR(data_venda)=:a AND status <> 'cancelada'",
            [':t' => $t, ':a' => $fAno]);
        $despesasManuais = (float)vero_val(
            "SELECT COALESCE(SUM(valor),0) FROM movimentacoes_financeiras
              WHERE tenant_id=:t AND tipo='pagar' AND origem_tipo IS NULL
                AND status <> 'cancelado'
                AND YEAR(COALESCE(data_competencia, data_vencimento, data_pagamento))=:a",
            [':t' => $t, ':a' => $fAno]);
    }
    $custeio = vero_rows($sqlCusteioPlano("YEAR(cl.data_competencia) = :a"), [':t' => $t, ':a' => $fAno]);
    $depreciacao = (float)vero_val($sqlDeprec("YEAR(data_competencia) = :a"), [':t' => $t, ':a' => $fAno]);
}
$totCusteio = array_sum(array_map(static fn($c) => (float)$c['total'], $custeio));

/* ── R3-02: FOLHA CLT NÃO ALOCADA (disclosure, padrão R2-04) ──
   A folha gerada em Pessoas → Folha só vira custeio quando o PERÍODO é
   FECHADO (pessoas/folha.php, A3-T3: emite custo_total − premiações por
   lançamento; premiações já entram no custeio pelos apontamentos —
   rh_producao_itens). Período ABERTO = salário + encargos fora do
   custeio, fora desta DRE ⇒ resultado gerencial SEM esse custo, nas
   duas visões. Linha INFORMATIVA, NÃO deduzida: a alocação real
   (parte produtiva → custeio por rateio; parte administrativa →
   despesa) é decisão de fechamento pendente do A0 — não inventar aqui.
   No modo safra não há filtro de ano: folha não tem vínculo com safra
   (a folha FECHADA sem rateio já aparece nas pendências de rateio do
   Resultado da Safra — R2-04). */
$folhaNaoAlocada = (float)vero_val(
    "SELECT COALESCE(SUM(fl.custo_total),0)
       FROM rh_folha_lancamentos fl
       JOIN rh_folha_periodos fp ON fp.id = fl.periodo_id
      WHERE fl.tenant_id = :t AND fp.status = 'aberto'"
    . ($porSafra ? '' : " AND YEAR(fp.competencia) = :a"),
    $porSafra ? [':t' => $t] : [':t' => $t, ':a' => $fAno]);

/* Depreciação: deduzida SIM na competência (é custo gerencial do
   período); na visão caixa não é desembolso — fica informativa. */
$depreciacaoDeduzida = $caixa ? 0.0 : $depreciacao;

$margemBruta = $receita - $totCusteio - $depreciacaoDeduzida;
$resultado   = $margemBruta - $despesasManuais;

/* G-04 (Funrural) — PREPARADO, NÃO IMPLEMENTADO: quando o cálculo
   oficial existir (alíquota PF/PJ sobre a receita bruta da
   comercialização da produção rural), a linha
   "(−) Funrural s/ comercialização" entra entre a margem bruta e o
   resultado, nas duas visões (caixa: sobre recebimentos; competência:
   sobre emissões). Não inventar cálculo aqui — depende do G-04.
   $funrural = 0.0; // placeholder da futura dedução */

$safras = vero_options('agro_safras', 'identificacao');
$anos = array_map('intval', array_column(vero_rows(
    "SELECT DISTINCT YEAR(data_venda) AS a FROM comercial_vendas WHERE tenant_id=:t ORDER BY a DESC", [':t' => $t]), 'a'));
if (!in_array($fAno, $anos, true)) $anos[] = $fAno;

$empresa = (string)vero_val("SELECT nome FROM tenants WHERE id = :t", [':t' => $t]);

/* Toggle de visão preservando o período selecionado */
$qsPeriodo = $porSafra ? ['safra' => $fSafra] : ['ano' => $fAno];
$urlVisao = static fn(string $v): string => 'dre_agro.php?' . http_build_query($qsPeriodo + ['visao' => $v]);

$GUARD      = ['macro' => 'financeiro', 'micro' => 'dre_agro'];
$PAGE_VIEW  = 'financeiro_dre_agro';
$PAGE_TITLE = 'DRE Agro';
$EXTRA_HEAD = vero_assets() . '<style media="print">.vsidebar,.no-print{display:none !important}</style>';
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('DRE Agro (gerencial)', 'Receita − custeio (incl. depreciação) − despesas = resultado, em duas visões: competência e caixa — não substitui a DRE contábil', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap" class="no-print">
        <input type="hidden" name="visao" value="<?= h($visao) ?>">
        <select name="safra" onchange="this.form.submit()">
          <option value="">Por ano-calendário</option>
          <?php foreach ($safras as $sid => $sn): ?>
            <option value="<?= $sid ?>"<?= $fSafra === (int)$sid ? ' selected' : '' ?>>Safra <?= h($sn) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$porSafra): ?>
        <select name="ano" onchange="this.form.submit()">
          <?php foreach ($anos as $a): ?>
            <option value="<?= $a ?>"<?= $a === $fAno ? ' selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <span style="display:inline-flex;gap:4px;align-items:center">
          <a class="vbtn vbtn-sm<?= $caixa ? '' : ' vbtn-primary' ?>" href="<?= h($urlVisao('competencia')) ?>">Competência</a>
          <a class="vbtn vbtn-sm<?= $caixa ? ' vbtn-primary' : '' ?>" href="<?= h($urlVisao('caixa')) ?>">Caixa</a>
        </span>
      </form>
      <span class="vsub"><strong><?= h($empresa) ?></strong> ·
        <?= $porSafra ? 'safra ' . h((string)($safras[$fSafra] ?? '')) : 'ano ' . $fAno ?> ·
        visão <strong><?= $caixa ? 'Caixa' : 'Competência' ?></strong></span>
      <button class="vbtn vbtn-primary vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button>
    </div>

    <table class="vtable">
      <tbody>
        <tr style="background:rgba(0,80,89,.06)">
          <td><strong>Receita bruta de vendas</strong>
            <span class="vhint"><?= $caixa ? 'recebida (vendas pagas, data do pagamento)' : 'emitida (data da venda)' ?></span></td>
          <td class="vnum" style="text-align:right;width:180px"><strong><?= numFmt($receita, 2) ?></strong></td>
          <td class="vnum" style="text-align:right;width:90px">100%</td>
        </tr>
        <?php foreach ($custeio as $c): ?>
        <tr>
          <td style="padding-left:34px">(−)
            <?= $c['codigo'] !== null ? '<span class="vnum vhint">' . h((string)$c['codigo']) . '</span> ' : '' ?>
            <?= h((string)$c['nome']) ?></td>
          <td class="vnum" style="text-align:right">(<?= numFmt((float)$c['total'], 2) ?>)</td>
          <td class="vnum" style="text-align:right"><?= $receita > 0 ? numFmt((float)$c['total'] / $receita * 100, 1) . '%' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$custeio && $depreciacaoDeduzida == 0.0): ?>
        <tr><td style="padding-left:34px" class="vhint">(−) Sem custeio no período</td><td></td><td></td></tr>
        <?php endif; ?>
        <?php if (!$caixa && $depreciacao != 0.0): /* R2-03: linha própria, deduzida */ ?>
        <tr>
          <td style="padding-left:34px">(−) <span class="vnum vhint">3.99</span> Depreciação (gerencial)
            <span class="vhint">patrimônio · deduzida</span></td>
          <td class="vnum" style="text-align:right">(<?= numFmt($depreciacao, 2) ?>)</td>
          <td class="vnum" style="text-align:right"><?= $receita > 0 ? numFmt($depreciacao / $receita * 100, 1) . '%' : '—' ?></td>
        </tr>
        <?php endif; ?>
        <tr style="border-top:2px solid var(--vero-border,#ccc)">
          <td><strong>Margem bruta da produção</strong></td>
          <td class="vnum" style="text-align:right;<?= $margemBruta < 0 ? 'color:#b3261e' : '' ?>">
            <strong><?= numFmt($margemBruta, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= $receita > 0 ? numFmt($margemBruta / $receita * 100, 1) . '%' : '—' ?></td>
        </tr>
        <?php if (!$porSafra): ?>
        <tr>
          <td style="padding-left:34px">(−) Despesas administrativas (manuais)
            <span class="vhint"><?= $caixa ? 'pagas (data do pagamento)' : 'por competência (não canceladas)' ?></span></td>
          <td class="vnum" style="text-align:right">(<?= numFmt($despesasManuais, 2) ?>)</td>
          <td class="vnum" style="text-align:right"><?= $receita > 0 ? numFmt($despesasManuais / $receita * 100, 1) . '%' : '—' ?></td>
        </tr>
        <?php endif; ?>
        <?php /* G-04: linha "(−) Funrural s/ comercialização" entra AQUI quando
                 o cálculo existir — ver bloco de preparação no topo do arquivo. */ ?>
        <tr style="border-top:2px solid var(--vero-border,#ccc);background:rgba(26,127,75,.06)">
          <td><strong>Resultado gerencial</strong></td>
          <td class="vnum" style="text-align:right;<?= $resultado < 0 ? 'color:#b3261e' : 'color:var(--vero-ok,#1a7f4b)' ?>">
            <strong><?= numFmt($resultado, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><strong><?= $receita > 0 ? numFmt($resultado / $receita * 100, 1) . '%' : '—' ?></strong></td>
        </tr>
        <?php if ($caixa && $depreciacao != 0.0): ?>
        <tr>
          <td class="vhint" style="padding-left:34px">Informativo: depreciação gerencial do período — não é saída de caixa, não deduzida NESTA visão (deduzida na visão competência)</td>
          <td class="vnum vhint" style="text-align:right"><?= numFmt($depreciacao, 2) ?></td>
          <td></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php if ($folhaNaoAlocada > 0.005): /* R3-02: informativa, NÃO deduzida — nas DUAS visões */ ?>
    <div style="margin:10px 14px 0;padding:10px 12px;border:1px solid #E4CE96;background:#F9F1DC;border-radius:8px;font-size:13px;color:#7A5410">
      <strong>Folha CLT c/ encargos não alocada ao resultado: R$ <?= numFmt($folhaNaoAlocada, 2) ?></strong>
      (alocação por rateio pendente — decisão de fechamento)
      — salário + encargos de período(s) de folha ainda ABERTOS em Pessoas → Folha: só o fechamento do
      período emite esse custo ao custeio; até lá o resultado gerencial acima está SEM esta folha.
      Premiações não entram neste valor — já estão no custeio pelos apontamentos.
      <a href="<?= rtrim(BIOS_BASE, '/') ?>/pessoas/folha">Ir para Folha</a>
    </div>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      <?php if ($caixa): ?>
      <strong>Visão caixa:</strong> receita pelos recebimentos efetivados (vendas pagas, data do pagamento)
      e despesas manuais pagas. Depreciação não é desembolso — informativa, fora do resultado desta visão.
      <br><strong>Limite do schema:</strong> o custeio operacional (mão de obra, insumos, máquinas…) não tem
      baixa de caixa vinculada aos lançamentos — nesta visão ele segue a data de competência dos lançamentos.
      <?php else: ?>
      <strong>Visão competência:</strong> receita pelas vendas emitidas (data da venda), custos pelo custeio
      operacional (data de competência), <strong>depreciação gerencial deduzida</strong> em linha própria e
      despesas manuais pela data de competência.
      <?php endif; ?>
      Impostos (incl. Funrural — linha prevista, cálculo ainda não implementado) e a DRE contábil/fiscal são
      apurados pela contabilidade (módulo Fiscal fora do go-live).
      <?php if ($porSafra && !$caixa): ?>
      <br><strong>Alinhamento:</strong> no modo safra (competência), a margem bruta desta DRE bate com o
      "Resultado bruto" de Custos → Resultado da Safra (mesmas bases: vendas − custeio). Despesas
      administrativas não têm vínculo com safra hoje — passam a entrar quando folha/rateio chegarem ao custeio.
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
