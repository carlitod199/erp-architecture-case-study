<?php
/* ============================================================
   VERO — Custos / Resultado da Safra  (tela real, leitura)
   Substitui o mock. Rota: /custeio/resultado_safra.php
   Guard: custos.resultado_safra
   Por safra: custo (custeio) × faturamento (vendas confirmadas,
   com fallback no faturamento estimado da colheita) = resultado
   bruto e margem; detalhamento por válvula da safra.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

$safras = vero_rows(
    "SELECT s.id, s.identificacao, s.status,
            COALESCE((SELECT SUM(cl.valor) FROM custeio_lancamentos cl
              WHERE cl.tenant_id = s.tenant_id AND cl.safra_id = s.id), 0) AS custo,
            COALESCE((SELECT SUM(v.valor_total) FROM comercial_vendas v
              WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id AND v.status <> 'cancelada'), 0) AS vendas,
            COALESCE((SELECT SUM(v.kg_total) FROM comercial_vendas v
              WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id AND v.status <> 'cancelada'), 0) AS kg_vendidos,
            COALESCE((SELECT SUM(r.kg_total_realizado) FROM colheita_registros r
              WHERE r.tenant_id = s.tenant_id AND r.safra_id = s.id), 0) AS kg_colhidos,
            COALESCE((SELECT SUM(r.faturamento_realizado) FROM colheita_registros r
              WHERE r.tenant_id = s.tenant_id AND r.safra_id = s.id), 0) AS fat_estimado,
            COALESCE((SELECT SUM(st.area_plantada_ha) FROM agro_safra_talhoes st
              WHERE st.tenant_id = s.tenant_id AND st.safra_id = s.id), 0) AS area_ha,
            /* A3-T27c: CPV comercial = saídas ATIVAS de venda × custo do lote (provisório
               até o fechamento — P-89: congelado na saída); só vendas COM lote entram */
            COALESCE((SELECT SUM(m.quantidade * m.custo_unitario)
               FROM estoque_movimentacoes m
               JOIN comercial_vendas v4 ON v4.id = m.origem_id AND v4.tenant_id = m.tenant_id
              WHERE m.tenant_id = s.tenant_id AND m.origem_tipo = 'comercial_venda'
                AND m.tipo = 'saida' AND m.estornado_em IS NULL
                AND v4.safra_id = s.id AND v4.status <> 'cancelada'), 0) AS cpv_lote,
            COALESCE((SELECT SUM(v5.valor_total) FROM comercial_vendas v5
              WHERE v5.tenant_id = s.tenant_id AND v5.safra_id = s.id
                AND v5.status <> 'cancelada' AND v5.lote_id IS NOT NULL), 0) AS vendas_com_lote,
            /* A3-F1: despesas de comercialização das vendas da safra (frete/comissão/
               embalagem/imposto) — margem líquida = vendas − CPV − despesas */
            COALESCE((SELECT SUM(d.valor)
               FROM comercial_venda_despesas d
               JOIN comercial_vendas v6 ON v6.id = d.venda_id AND v6.tenant_id = d.tenant_id
              WHERE d.tenant_id = s.tenant_id AND v6.safra_id = s.id
                AND v6.status <> 'cancelada'), 0) AS despesas_comerc
       FROM agro_safras s
      WHERE s.tenant_id = :t
      ORDER BY s.identificacao DESC", [':t' => $t]);

$fSafra = (int)($_GET['safra'] ?? 0);
if (!$fSafra && $safras) $fSafra = (int)$safras[0]['id'];

$detalhe = [];
$vendasSemVinculo = 0.0;
$kgSafraTotal = 0.0;
if ($fSafra) {
    /* P-43 (aprovada): vendas SEM vínculo de colheita/válvula são rateadas
       entre as válvulas pela proporção de kg colhidos na safra */
    $vendasSemVinculo = (float)vero_val(
        "SELECT COALESCE(SUM(v.valor_total),0) FROM comercial_vendas v
          WHERE v.tenant_id = :t AND v.safra_id = :s AND v.status <> 'cancelada'
            AND v.colheita_registro_id IS NULL", [':t' => $t, ':s' => $fSafra]);
    $kgSafraTotal = (float)vero_val(
        "SELECT COALESCE(SUM(r.kg_total_realizado),0) FROM colheita_registros r
          WHERE r.tenant_id = :t AND r.safra_id = :s", [':t' => $t, ':s' => $fSafra]);
    $detalhe = vero_rows(
        "SELECT st.id, t2.codigo AS talhao, f.nome AS fazenda, c.nome AS cultura,
                st.area_plantada_ha,
                COALESCE((SELECT SUM(cl.valor) FROM custeio_lancamentos cl
                  WHERE cl.tenant_id = st.tenant_id AND cl.safra_talhao_id = st.id), 0) AS custo,
                COALESCE((SELECT SUM(r.kg_total_realizado) FROM colheita_registros r
                  WHERE r.tenant_id = st.tenant_id AND r.safra_talhao_id = st.id), 0) AS kg,
                COALESCE((SELECT SUM(v.valor_total) FROM comercial_vendas v
                  JOIN colheita_registros r2 ON r2.id = v.colheita_registro_id
                  WHERE v.tenant_id = st.tenant_id AND r2.safra_talhao_id = st.id
                    AND v.status <> 'cancelada'), 0) AS vendas
           FROM agro_safra_talhoes st
           JOIN agro_talhoes t2 ON t2.id = st.talhao_id
           JOIN agro_fazendas f ON f.id = t2.fazenda_id
           JOIN agro_culturas c ON c.id = st.cultura_id
          WHERE st.tenant_id = :t AND st.safra_id = :s
          ORDER BY f.nome, t2.codigo", [':t' => $t, ':s' => $fSafra]);
}

/* ── R2-04: custos SEM SAFRA pendentes de rateio (visibilidade, sem alocar) ──
   Soma LÍQUIDA de custeio_lancamentos com safra_id IS NULL: a atribuição P-98
   (custeio/_atribuicao_sem_safra.php) grava contrapartida NEGATIVA sem safra
   anulando o original já atribuído (mecânica P-07), então a soma simples
   sobra exatamente o que ainda aguarda rateio — sem excluir/duplicar nada.
   O rateio em si é MANUAL no fechamento (P-125); esta tela só dá visibilidade. */
$pendRateio = (float)vero_val(
    "SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl
      WHERE cl.tenant_id = :t AND cl.safra_id IS NULL", [':t' => $t]);
$pendRateioCat = abs($pendRateio) > 0.005 ? vero_rows(
    "SELECT COALESCE(cl.categoria,'outros') AS categoria, SUM(cl.valor) AS total
       FROM custeio_lancamentos cl
      WHERE cl.tenant_id = :t AND cl.safra_id IS NULL
      GROUP BY COALESCE(cl.categoria,'outros')
     HAVING ABS(SUM(cl.valor)) > 0.005
      ORDER BY total DESC", [':t' => $t]) : [];
/* Σ resultados de TODAS as safras (a tabela comparativa é sempre todas):
   reconciliação Σ resultados − pendências = resultado bruto do DRE gerencial */
$somaResultados = 0.0;
foreach ($safras as $sTmp) $somaResultados += (float)$sTmp['vendas'] - (float)$sTmp['custo'];
unset($sTmp);

$GUARD      = ['macro' => 'custos', 'micro' => 'resultado_safra'];
$PAGE_VIEW  = 'custos_resultado_safra';
$PAGE_TITLE = 'Resultado da Safra';

/* ── Export CSV (antes de qualquer HTML) ─────────────────────────
   Relatório read-only. csv=safras → comparativo entre safras;
   csv=detalhe → detalhe por válvula da safra selecionada. Reusa o
   helper compartilhado vero_csv_stream e o guard canônico bios_guard. */
$csvKey = (string)($_GET['csv'] ?? '');
if ($csvKey === 'safras' || $csvKey === 'detalhe') {
    require_once __DIR__ . '/../includes/menu_agro.php';
    bios_guard($GUARD['macro'], $GUARD['micro']);
    require_once __DIR__ . '/../compras/_export.php';

    if ($csvKey === 'safras') {
        $colunas = [
            'identificacao' => 'Safra', 'status' => 'Status', 'area_ha' => 'Área (ha)',
            'kg_colhidos' => 'kg colhidos', 'kg_vendidos' => 'kg vendidos',
            'vendas' => 'Faturamento vendas (R$)', 'custo' => 'Custo (R$)',
            'custo_ha' => 'Custo/ha (R$)', 'custo_kg' => 'Custo/kg (R$)',
            'resultado' => 'Resultado bruto (R$)', 'margem_pct' => 'Margem (%)',
            'cpv_lote' => 'CPV lote (R$)', 'despesas_comerc' => 'Despesas (R$)',
            'margem_liquida' => 'Margem líquida (R$)',
        ];
        $formato = [
            'area_ha' => 'dec2', 'kg_colhidos' => 'dec0', 'kg_vendidos' => 'dec0',
            'vendas' => 'dec2', 'custo' => 'dec2', 'custo_ha' => 'dec2', 'custo_kg' => 'dec2',
            'resultado' => 'dec2', 'margem_pct' => 'dec2', 'cpv_lote' => 'dec2',
            'despesas_comerc' => 'dec2', 'margem_liquida' => 'dec2',
        ];
        $rowsCsv = [];
        foreach ($safras as $s) {
            $vendas = (float)$s['vendas']; $custo = (float)$s['custo'];
            $area = (float)$s['area_ha']; $kgC = (float)$s['kg_colhidos'];
            $vComLote = (float)$s['vendas_com_lote'];
            $rowsCsv[] = [
                'identificacao' => $s['identificacao'], 'status' => $s['status'],
                'area_ha' => $area, 'kg_colhidos' => $kgC, 'kg_vendidos' => (float)$s['kg_vendidos'],
                'vendas' => $vendas, 'custo' => $custo,
                'custo_ha' => $area > 0 ? $custo / $area : '', 'custo_kg' => $kgC > 0 ? $custo / $kgC : '',
                'resultado' => $vendas - $custo, 'margem_pct' => $vendas > 0 ? ($vendas - $custo) / $vendas * 100 : '',
                'cpv_lote' => (float)$s['cpv_lote'], 'despesas_comerc' => (float)$s['despesas_comerc'],
                'margem_liquida' => $vComLote > 0 ? $vComLote - (float)$s['cpv_lote'] - (float)$s['despesas_comerc'] : '',
            ];
        }
        vero_csv_stream('custeio', 'resultado_safra', $rowsCsv, $colunas, $formato);
    } else { /* detalhe */
        $colunas = [
            'valvula' => 'Válvula', 'cultura' => 'Cultura', 'area_plantada_ha' => 'Área (ha)',
            'kg' => 'kg colhidos', 'vendas' => 'Vendas (R$)', 'custo' => 'Custo (R$)',
            'custo_ha' => 'Custo/ha (R$)', 'custo_kg' => 'Custo/kg (R$)',
            'resultado' => 'Resultado (R$)', 'resultado_ha' => 'Resultado/ha (R$)',
        ];
        $formato = [
            'area_plantada_ha' => 'dec2', 'kg' => 'dec0', 'vendas' => 'dec2', 'custo' => 'dec2',
            'custo_ha' => 'dec2', 'custo_kg' => 'dec2', 'resultado' => 'dec2', 'resultado_ha' => 'dec2',
        ];
        $rowsCsv = [];
        foreach ($detalhe as $d) {
            $area = (float)$d['area_plantada_ha']; $kg = (float)$d['kg']; $custoD = (float)$d['custo'];
            $rateada = ($vendasSemVinculo > 0 && $kgSafraTotal > 0) ? $vendasSemVinculo * $kg / $kgSafraTotal : 0.0;
            $vendasTal = (float)$d['vendas'] + $rateada; $res = $vendasTal - $custoD;
            $rowsCsv[] = [
                'valvula' => $d['fazenda'] . ' — ' . $d['talhao'], 'cultura' => $d['cultura'],
                'area_plantada_ha' => $area, 'kg' => $kg, 'vendas' => $vendasTal, 'custo' => $custoD,
                'custo_ha' => $area > 0 ? $custoD / $area : '', 'custo_kg' => $kg > 0 ? $custoD / $kg : '',
                'resultado' => $res, 'resultado_ha' => $area > 0 ? $res / $area : '',
            ];
        }
        vero_csv_stream('custeio', 'resultado_safra_detalhe', $rowsCsv, $colunas, $formato);
    }
}

$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Resultado da Safra', 'Custo do custeio × faturamento das vendas por safra — resultado bruto e margem', null) ?>

  <div class="vcard" style="margin-bottom:16px">
    <?php if (!$safras): ?>
      <div class="vempty">Nenhuma safra cadastrada.</div>
    <?php else: ?>
    <div class="vtoolbar"><strong style="font-size:14px">Comparativo entre safras</strong>
      <span class="vsub">
        <a class="vbtn vbtn-ghost vbtn-sm no-print" href="?csv=safras">Exportar CSV</a>
        <button class="vbtn vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button>
      </span>
    </div>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th>Safra</th>
        <th style="text-align:right">Área (ha)</th>
        <th style="text-align:right">kg colhidos</th>
        <th style="text-align:right">kg vendidos</th>
        <th style="text-align:right">Faturamento vendas (R$)</th>
        <th style="text-align:right">Custo (R$)</th>
        <th style="text-align:right">Custo/ha (R$)</th>
        <th style="text-align:right">Custo/kg (R$)</th>
        <th style="text-align:right">Resultado bruto (R$)</th>
        <th style="text-align:right">Margem</th>
        <th style="text-align:right">CPV lote (R$)*</th>
        <th style="text-align:right">Despesas (R$)*</th>
        <th style="text-align:right">Margem líquida*</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($safras as $s):
          $vendas = (float)$s['vendas'];
          $custo  = (float)$s['custo'];
          $area   = (float)$s['area_ha'];
          $kgColhidos = (float)$s['kg_colhidos'];
          $resultado = $vendas - $custo;
          $margem = $vendas > 0 ? $resultado / $vendas * 100 : null;
          $custoHa = $area > 0 ? $custo / $area : null;
          $custoKg = $kgColhidos > 0 ? $custo / $kgColhidos : null; ?>
        <tr<?= $fSafra === (int)$s['id'] ? ' style="background:#FAF8F1"' : '' ?>>
          <td><strong><?= h($s['identificacao']) ?></strong>
            <span class="vhint"><?= h((string)$s['status']) ?></span></td>
          <td class="vnum" style="text-align:right"><?= numFmt($area, 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($kgColhidos, 0) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$s['kg_vendidos'], 0) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($vendas, 2) ?>
            <?= $vendas <= 0 && (float)$s['fat_estimado'] > 0
                  ? '<div class="vhint">estim. colheita: ' . numFmt((float)$s['fat_estimado'], 2) . '</div>' : '' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($custo, 2) ?></td>
          <td class="vnum" style="text-align:right"><?= $custoHa !== null ? numFmt($custoHa, 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $custoKg !== null ? numFmt($custoKg, 2) : '—' ?></td>
          <td class="vnum" style="text-align:right;font-weight:700;color:<?= $resultado >= 0 ? '#1E6B34' : '#9A3B2A' ?>">
            <?= numFmt($resultado, 2) ?></td>
          <td class="vnum" style="text-align:right"><?= $margem !== null ? numFmt($margem, 1) . '%' : '—' ?></td>
          <?php /* A3-T27c/F1: margem LÍQUIDA = vendas com lote − CPV do lote − despesas de
                   comercialização (F1). NÃO somar com o custo da safra ao lado — leituras
                   distintas (contrato A4) */
                $cpv = (float)$s['cpv_lote'];
                $vComLote = (float)$s['vendas_com_lote'];
                $despC = (float)$s['despesas_comerc'];
                $margLiq = $vComLote - $cpv - $despC; ?>
          <td class="vnum" style="text-align:right"><?= $cpv > 0 ? numFmt($cpv, 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $despC > 0 ? numFmt($despC, 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $vComLote > 0
              ? '<strong>' . numFmt($margLiq, 2) . '</strong> <span class="vhint">(' . numFmt($margLiq / $vComLote * 100, 1) . '%)</span>'
              : '—' ?></td>
          <td style="text-align:right"><div class="vactions"><?= vero_btn_icone(vero_ico_olho(), 'Detalhar', '', '?safra=' . (int)$s['id']) ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if (abs($pendRateio) > 0.005): ?>
    <div style="margin:10px 14px 0;padding:10px 12px;border:1px solid #E4CE96;background:#F9F1DC;border-radius:8px;font-size:13px;color:#7A5410">
      <strong>Custos pendentes de rateio (não alocados a safras): R$ <?= numFmt($pendRateio, 2) ?></strong>
      <?php if ($pendRateioCat): ?>
        <span class="vhint">(<?= implode(' + ', array_map(static fn($c) =>
            h(ucfirst(str_replace('_', ' ', (string)$c['categoria']))) . ' ' . numFmt((float)$c['total'], 2), $pendRateioCat)) ?>)</span>
      <?php endif; ?>
      — este valor não entra no resultado de nenhuma safra até o rateio manual do fechamento.
      <a href="<?= rtrim(BIOS_BASE, '/') ?>/custeio/rateios">Ir para Rateios</a>
      <div class="vnum" style="margin-top:6px">
        Σ resultados das safras R$ <?= numFmt($somaResultados, 2) ?> − pendências R$ <?= numFmt($pendRateio, 2) ?>
        = <strong>R$ <?= numFmt($somaResultados - $pendRateio, 2) ?></strong> (= resultado bruto do DRE gerencial)
      </div>
    </div>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      Esta tabela é o comparativo entre safras (a safra do VERO é o ciclo de poda — compare
      2026.1 × 2026.2 do mesmo parreiral). Custo/kg usa os kg colhidos; margem usa o faturamento
      das vendas confirmadas. Área (ha) e Custo/ha usam a área PRODUTIVA da safra (Σ área
      plantada das válvulas vinculadas à safra), que pode diferir da área cadastral das válvulas.
      <br>*CPV lote (T27c): kg vendido × custo do lote agrícola na SAÍDA (provisório até o
      fechamento da safra — P-89); só vendas COM lote entram; a margem comercial compara com o
      faturamento dessas mesmas vendas. NÃO some CPV com o "Custo (R$)" ao lado — o custo do
      lote JÁ NASCE do custo da safra (leituras distintas do mesmo custo; contrato A4).
    </div>
    <?php endif; ?>
  </div>

  <?php if ($fSafra): ?>
  <div class="vcard">
    <div class="vtoolbar"><strong style="font-size:14px">Detalhe por válvula da safra</strong>
      <?php if ($detalhe): ?>
      <span class="vsub">
        <a class="vbtn vbtn-ghost vbtn-sm no-print" href="?safra=<?= (int)$fSafra ?>&csv=detalhe">Exportar CSV</a>
        <button class="vbtn vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button>
      </span>
      <?php endif; ?>
    </div>
    <?php if (!$detalhe): ?>
      <div class="vempty">Nenhum válvula vinculada a esta safra.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th>Válvula</th><th>Cultura</th>
        <th style="text-align:right">Área (ha)</th>
        <th style="text-align:right">kg colhidos</th>
        <th style="text-align:right">Vendas (R$)</th>
        <th style="text-align:right">Custo (R$)</th>
        <th style="text-align:right">Custo/ha (R$)</th>
        <th style="text-align:right">Custo/kg (R$)</th>
        <th style="text-align:right">Resultado (R$)</th>
        <th style="text-align:right">Resultado/ha (R$)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($detalhe as $d):
          $area = (float)$d['area_plantada_ha'];
          $kg = (float)$d['kg'];
          /* P-43: soma a fatia rateada das vendas sem vínculo (por kg colhido) */
          $rateada = ($vendasSemVinculo > 0 && $kgSafraTotal > 0) ? $vendasSemVinculo * $kg / $kgSafraTotal : 0.0;
          $vendasTal = (float)$d['vendas'] + $rateada;
          $res = $vendasTal - (float)$d['custo']; ?>
        <tr>
          <td><strong><?= h($d['fazenda']) ?> — <?= h($d['talhao']) ?></strong></td>
          <td><?= h($d['cultura']) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($area, 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($kg, 0) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($vendasTal, 2) ?>
            <?= $rateada > 0 ? '<div class="vhint">inclui rateada: ' . numFmt($rateada, 2) . '</div>' : '' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$d['custo'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= $area > 0 ? numFmt((float)$d['custo'] / $area, 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $kg > 0 ? numFmt((float)$d['custo'] / $kg, 2) : '—' ?></td>
          <td class="vnum" style="text-align:right;font-weight:700;color:<?= $res >= 0 ? '#1E6B34' : '#9A3B2A' ?>">
            <?= numFmt($res, 2) ?></td>
          <td class="vnum" style="text-align:right"><?= $area > 0 ? numFmt($res / $area, 2) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      A receita da válvula soma as vendas amarradas à colheita do próprio válvula e a FATIA RATEADA
      das vendas da safra sem vínculo de colheita — rateio pela proporção de kg colhidos da válvula.
      Custos indiretos entram por válvula após a execução dos rateios
      no fechamento.
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
