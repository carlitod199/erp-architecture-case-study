<?php
/* ============================================================
   VERO — Relatórios / Integridade Produção → Estoque → Venda
   (tela real, READ-ONLY — vigia dos achados F-05/F-06 da
   auditoria matemática 19/07, docs/VERO_AUDIT_MATH_20260719_TRIAGEM.md)
   Rota: /relatorios/integridade_producao.php?safra=<id>&aplicar=1
   Guard: relatorios.integridade_producao — slug PRÓPRIO (decisão A0
   19/07): micro na matriz (macro relatorios, oculto) + launcher no menu
   Relatórios; catálogo sincronizado e grants espelhados de quem tinha
   relatorios.relatorios_safra.ver (scripts/seed 19/07).

   O encadeamento produção→estoque→venda é OPCIONAL por design:
   a colheita só entra no estoque pela confirmação (CTA "Confirmar
   entrada" em /colheita/index.php, service A0-18) e a venda só baixa
   estoque quando aponta lote (T27a/T33). Esta tela NÃO escreve nada:
   ela cruza as três pontas por safra e expõe o Δ para o gestor
   reconciliar ANTES do fechamento. Zero UPDATE/INSERT/DELETE.
   Export CSV das pendências: ?csv=colheitas_pendentes|vendas_pendentes
   (padrão _rel_base: BOM UTF-8, separador ';').
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

/* tolerância de fechamento — auditoria 19/07: divergência ≥ 1 kg = linha */
const INTEG_TOL_KG = 1.0;

$t        = vero_tenant();
$fSafra   = (int)($_GET['safra'] ?? 0);
$aplicado = (($_GET['aplicar'] ?? '') === '1') && $fSafra > 0;

/* ── Bloco A: colheitas realizadas × entradas origem 'colheita' ── */
function integ_colheitas(int $t, int $safraId): array
{
    return vero_rows(
        "SELECT cr.id, cr.data_colheita, cu.nome AS cultura, tl.codigo AS talhao,
                COALESCE(cr.kg_total_realizado, 0) AS colhido_kg,
                COALESCE(perd.kg, 0)               AS perda_kg,
                COALESCE(ent.kg, 0)                AS entrada_kg,
                COALESCE(cr.kg_total_realizado, 0) - COALESCE(ent.kg, 0) AS gap_kg
           FROM colheita_registros cr
           LEFT JOIN agro_culturas cu ON cu.id = cr.cultura_id
           LEFT JOIN agro_talhoes  tl ON tl.id = cr.talhao_id
           LEFT JOIN (SELECT origem_id, SUM(quantidade) AS kg
                        FROM estoque_movimentacoes
                       WHERE tenant_id = :t2 AND origem_tipo = 'colheita'
                         AND tipo = 'entrada' AND estornado_em IS NULL
                       GROUP BY origem_id) ent ON ent.origem_id = cr.id
           LEFT JOIN (SELECT registro_id, SUM(kg_calculado) AS kg
                        FROM colheita_classificacoes
                       WHERE tenant_id = :t3 AND momento = 'realizado' AND categoria = 'perdidos'
                       GROUP BY registro_id) perd ON perd.registro_id = cr.id
          WHERE cr.tenant_id = :t AND cr.safra_id = :s
          ORDER BY cr.data_colheita, cr.id",
        [':t' => $t, ':t2' => $t, ':t3' => $t, ':s' => $safraId]);
}

/* ── Bloco B: vendas confirmadas/faturadas × saídas origem 'comercial_venda' ── */
function integ_vendas(int $t, int $safraId): array
{
    return vero_rows(
        "SELECT v.id, v.numero, v.cliente, v.data_venda, v.status, v.lote_id,
                COALESCE(v.kg_total, 0) AS vendido_kg,
                COALESCE(bx.kg, 0)      AS baixa_kg,
                COALESCE(v.kg_total, 0) - COALESCE(bx.kg, 0) AS gap_kg
           FROM comercial_vendas v
           LEFT JOIN (SELECT origem_id, SUM(quantidade) AS kg
                        FROM estoque_movimentacoes
                       WHERE tenant_id = :t2 AND origem_tipo = 'comercial_venda'
                         AND tipo = 'saida' AND estornado_em IS NULL
                       GROUP BY origem_id) bx ON bx.origem_id = v.id
          WHERE v.tenant_id = :t AND v.safra_id = :s
            AND v.status IN ('confirmada','faturada')
          ORDER BY v.data_venda, v.id",
        [':t' => $t, ':t2' => $t, ':s' => $safraId]);
}

/* ── Bloco C: prova física por produto acabado (culturas c/ produto de colheita) ── */
function integ_prova(int $t, int $safraId): array
{
    /* produto(s) gerados pela colheita — agrupa culturas que apontam o mesmo produto */
    $cults = vero_rows(
        "SELECT cu.id AS cultura_id, cu.nome AS cultura,
                cu.produto_estoque_colheita_id AS produto_id, p.nome AS produto
           FROM agro_culturas cu
           JOIN estoque_produtos p ON p.id = cu.produto_estoque_colheita_id AND p.tenant_id = cu.tenant_id
          WHERE cu.tenant_id = :t AND cu.produto_estoque_colheita_id IS NOT NULL",
        [':t' => $t]);
    $produtos = [];
    foreach ($cults as $c) {
        $pid = (int)$c['produto_id'];
        $produtos[$pid]['produto']       = (string)$c['produto'];
        $produtos[$pid]['culturas'][]    = (int)$c['cultura_id'];
        $produtos[$pid]['cultura_nomes'][] = (string)$c['cultura'];
    }

    /* vendas da safra SEM vínculo resolvível de produto (nem lote, nem colheita→cultura) */
    $semVinculo = (float)vero_val(
        "SELECT COALESCE(SUM(v.kg_total), 0)
           FROM comercial_vendas v
           LEFT JOIN colheita_registros cr ON cr.id = v.colheita_registro_id AND cr.tenant_id = v.tenant_id
           LEFT JOIN agro_culturas cu ON cu.id = cr.cultura_id AND cu.tenant_id = v.tenant_id
          WHERE v.tenant_id = :t AND v.safra_id = :s AND v.status IN ('confirmada','faturada')
            AND v.lote_id IS NULL
            AND (v.colheita_registro_id IS NULL OR cu.produto_estoque_colheita_id IS NULL)",
        [':t' => $t, ':s' => $safraId]);

    $linhas = [];
    foreach ($produtos as $pid => $info) {
        $inCult = implode(',', array_map('intval', $info['culturas'])); /* ids int — safe */

        $saldo = (float)vero_val(
            "SELECT COALESCE(SUM(quantidade), 0) FROM estoque_saldos
              WHERE tenant_id = :t AND produto_id = :p", [':t' => $t, ':p' => $pid]);

        $colhido = (float)vero_val(
            "SELECT COALESCE(SUM(kg_total_realizado), 0) FROM colheita_registros
              WHERE tenant_id = :t AND safra_id = :s AND cultura_id IN ($inCult)",
            [':t' => $t, ':s' => $safraId]);

        /* vendido atribuído ao produto: lote → produto; senão colheita → cultura → produto */
        $vendido = (float)vero_val(
            "SELECT COALESCE(SUM(v.kg_total), 0)
               FROM comercial_vendas v
               LEFT JOIN estoque_lotes lo ON lo.id = v.lote_id AND lo.tenant_id = v.tenant_id
               LEFT JOIN colheita_registros cr ON cr.id = v.colheita_registro_id AND cr.tenant_id = v.tenant_id
               LEFT JOIN agro_culturas cu ON cu.id = cr.cultura_id AND cu.tenant_id = v.tenant_id
              WHERE v.tenant_id = :t AND v.safra_id = :s AND v.status IN ('confirmada','faturada')
                AND (lo.produto_id = :p
                     OR (lo.id IS NULL AND cu.produto_estoque_colheita_id = :p2))",
            [':t' => $t, ':s' => $safraId, ':p' => $pid, ':p2' => $pid]);

        $notaSemVinculo = false;
        if (count($produtos) === 1 && $semVinculo > 0) {
            /* um único produto acabado no tenant: vendas sem vínculo só podem ser dele */
            $vendido += $semVinculo;
            $notaSemVinculo = true;
        }

        /* componentes do saldo (movimentos ATIVOS do produto, todas as épocas) */
        $mov = vero_row(
            "SELECT
                COALESCE(SUM(CASE WHEN m.origem_tipo = 'colheita' AND m.tipo = 'entrada'
                                   AND cr.safra_id = :s THEN m.quantidade ELSE 0 END), 0) AS ent_colheita_safra,
                COALESCE(SUM(CASE WHEN m.origem_tipo = 'colheita' AND m.tipo = 'entrada'
                                   AND (cr.safra_id IS NULL OR cr.safra_id <> :s2) THEN m.quantidade ELSE 0 END), 0) AS ent_colheita_outras,
                COALESCE(SUM(CASE WHEN m.origem_tipo = 'comercial_venda' AND m.tipo = 'saida'
                                   AND vv.safra_id = :s3 THEN m.quantidade ELSE 0 END), 0) AS baixa_venda_safra,
                COALESCE(SUM(CASE WHEN m.origem_tipo = 'comercial_venda' AND m.tipo = 'saida'
                                   AND (vv.safra_id IS NULL OR vv.safra_id <> :s4) THEN m.quantidade ELSE 0 END), 0) AS baixa_venda_outras,
                COALESCE(SUM(CASE WHEN m.origem_tipo IS NULL OR m.origem_tipo NOT IN ('colheita','comercial_venda')
                                  THEN CASE m.tipo WHEN 'entrada' THEN m.quantidade
                                                   WHEN 'saida'   THEN -m.quantidade
                                                   ELSE m.quantidade END
                                  ELSE 0 END), 0) AS outras_net
               FROM estoque_movimentacoes m
               LEFT JOIN colheita_registros cr ON m.origem_tipo = 'colheita'
                    AND cr.id = m.origem_id AND cr.tenant_id = m.tenant_id
               LEFT JOIN comercial_vendas vv ON m.origem_tipo = 'comercial_venda'
                    AND vv.id = m.origem_id AND vv.tenant_id = m.tenant_id
              WHERE m.tenant_id = :t AND m.produto_id = :p AND m.estornado_em IS NULL",
            [':t' => $t, ':p' => $pid, ':s' => $safraId, ':s2' => $safraId, ':s3' => $safraId, ':s4' => $safraId]) ?: [];

        $gapA = $colhido - (float)($mov['ent_colheita_safra'] ?? 0);   /* F-05 no recorte do produto */
        $gapB = $vendido - (float)($mov['baixa_venda_safra'] ?? 0);    /* F-06 no recorte do produto */
        $delta = $saldo - ($colhido - $vendido);
        /* identidade contábil: Δ = gapB − gapA + colheitas de outras safras
           − baixas de outras safras + demais movimentações (líquido) */
        $deltaExplicado = $gapB - $gapA
            + (float)($mov['ent_colheita_outras'] ?? 0)
            - (float)($mov['baixa_venda_outras'] ?? 0)
            + (float)($mov['outras_net'] ?? 0);

        $linhas[] = [
            'produto'          => $info['produto'],
            'culturas'         => implode(', ', array_unique($info['cultura_nomes'])),
            'colhido_kg'       => $colhido,
            'vendido_kg'       => $vendido,
            'teorico_kg'       => $colhido - $vendido,
            'saldo_kg'         => $saldo,
            'delta_kg'         => $delta,
            'gap_colheitas'    => $gapA,
            'gap_vendas'       => $gapB,
            'ent_outras_safras'  => (float)($mov['ent_colheita_outras'] ?? 0),
            'baixa_outras_safras'=> (float)($mov['baixa_venda_outras'] ?? 0),
            'outras_net'       => (float)($mov['outras_net'] ?? 0),
            'delta_explicado'  => $deltaExplicado,
            'nota_sem_vinculo' => $notaSemVinculo ? $semVinculo : 0.0,
        ];
    }
    return ['linhas' => $linhas, 'sem_vinculo' => $semVinculo, 'n_produtos' => count($produtos)];
}

/* ── Export CSV das pendências (padrão _rel_base: BOM, ';') — antes do HTML ── */
$csvKey = (string)($_GET['csv'] ?? '');
if ($csvKey !== '' && $fSafra > 0) {
    if (function_exists('requirePermission')) requirePermission('relatorios.relatorios_safra.ver');
    $defs = [
        'colheitas_pendentes' => [
            'colunas' => ['id' => 'Colheita', 'data_colheita' => 'Data', 'cultura' => 'Cultura',
                          'talhao' => 'Válvula', 'colhido_kg' => 'Colhido (kg)', 'perda_kg' => 'Perda classificada (kg)',
                          'entrada_kg' => 'Entrada no estoque (kg)', 'gap_kg' => 'Δ sem postar (kg)'],
            'rows' => array_values(array_filter(integ_colheitas($t, $fSafra),
                static fn($r) => (float)$r['gap_kg'] > INTEG_TOL_KG)),
        ],
        'vendas_pendentes' => [
            'colunas' => ['numero' => 'Venda', 'cliente' => 'Cliente', 'data_venda' => 'Data', 'status' => 'Status',
                          'vendido_kg' => 'Vendido (kg)', 'baixa_kg' => 'Baixado do estoque (kg)',
                          'gap_kg' => 'Δ sem baixa (kg)'],
            'rows' => array_values(array_filter(integ_vendas($t, $fSafra),
                static fn($r) => (float)$r['gap_kg'] > INTEG_TOL_KG)),
        ],
    ];
    if (isset($defs[$csvKey])) {
        $ds = $defs[$csvKey];
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="vero_integridade_' . $csvKey . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); /* BOM p/ Excel */
        fputcsv($out, array_values($ds['colunas']), ';');
        foreach ($ds['rows'] as $r) {
            $linha = [];
            foreach (array_keys($ds['colunas']) as $campo) {
                $v = $r[$campo] ?? '';
                if (str_ends_with($campo, '_kg') && $v !== '') $v = number_format((float)$v, 3, ',', '');
                $linha[] = $v;
            }
            fputcsv($out, $linha, ';');
        }
        fclose($out);
        exit;
    }
}

$safras = vero_rows(
    "SELECT id, identificacao, status FROM agro_safras
      WHERE tenant_id = :t ORDER BY identificacao DESC", [':t' => $t]);
if ($fSafra <= 0) { /* pré-seleciona a safra ativa no filtro (nada é carregado sem aplicar) */
    foreach ($safras as $sa) if ($sa['status'] === 'ativa') { $fSafra = (int)$sa['id']; break; }
}

$GUARD      = ['macro' => 'relatorios', 'micro' => 'integridade_producao'];
/* A5-NAV (bug 21/07): o macro 'relatorios' está OCULTO (fusão C-24 → macro
   'dashboard'/"Relatórios"), então ancorar a sidebar por
   'relatorios_integridade_producao' resolvia num macro sem submenu visível
   (submenu SUMIA). Esta tela é drill-down de "Relatórios de Safra"; ancoramos
   nessa view (macro 'dashboard' visível) p/ o submenu Relatórios aparecer e
   destacar a origem. O $GUARD acima mantém a permissão real (relatorios.*). */
$PAGE_VIEW  = 'relatorios_relatorios_safra';
$PAGE_TITLE = 'Integridade Produção → Estoque → Venda';
$EXTRA_HEAD = vero_assets() . '<style media="print">.vsidebar,.no-print{display:none !important}</style>';
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');

$fmtKg = static fn(float $v): string => numFmt($v, abs($v - round($v)) > 0.0005 ? 3 : 0);
$badge = static function (float $delta) use ($fmtKg): string {
    return abs($delta) <= INTEG_TOL_KG
        ? '<span class="vbadge vb-ok">OK — Δ 0 kg</span>'
        : '<span class="vbadge vb-warn">Atenção — Δ ' . h($fmtKg($delta)) . ' kg</span>';
};

$safraNome = '';
foreach ($safras as $sa) if ((int)$sa['id'] === $fSafra) $safraNome = (string)$sa['identificacao'];

if ($aplicado) {
    $colheitas = integ_colheitas($t, $fSafra);
    $vendas    = integ_vendas($t, $fSafra);
    $prova     = integ_prova($t, $fSafra);

    $totColhido = array_sum(array_map(static fn($r) => (float)$r['colhido_kg'], $colheitas));
    $totEntrada = array_sum(array_map(static fn($r) => (float)$r['entrada_kg'], $colheitas));
    $gapColheita = $totColhido - $totEntrada;
    $colheitasPend = array_values(array_filter($colheitas, static fn($r) => (float)$r['gap_kg'] > INTEG_TOL_KG));

    $totVendido = array_sum(array_map(static fn($r) => (float)$r['vendido_kg'], $vendas));
    $totBaixa   = array_sum(array_map(static fn($r) => (float)$r['baixa_kg'], $vendas));
    $gapVenda   = $totVendido - $totBaixa;
    $vendasPend = array_values(array_filter($vendas, static fn($r) => (float)$r['gap_kg'] > INTEG_TOL_KG));
}
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Integridade Produção → Estoque → Venda',
      'Cross-check por safra: colheita × entrada no estoque × baixa por venda — painel READ-ONLY (F-05/F-06)', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center" class="no-print">
        <label class="vhint" for="integ-safra">Safra</label>
        <select name="safra" id="integ-safra" required>
          <option value="">— escolha —</option>
          <?php foreach ($safras as $sa): ?>
            <option value="<?= (int)$sa['id'] ?>"<?= (int)$sa['id'] === $fSafra ? ' selected' : '' ?>>
              <?= h((string)$sa['identificacao']) ?><?= $sa['status'] === 'ativa' ? ' (ativa)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="aplicar" value="1">
        <button class="vbtn vbtn-primary vbtn-sm" type="submit">Gerar painel</button>
      </form>
      <a class="vbtn vbtn-ghost vbtn-sm no-print" href="<?= $base ?>/relatorios/relatorios_safra.php">Relatórios de safra</a>
      <?php if ($aplicado): ?><button class="vbtn vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button><?php endif; ?>
    </div>
  </div>

  <?php if (!$aplicado): ?>
  <div class="vcard">
    <div class="vempty" style="text-align:center;padding:28px 16px">
      <div style="font-size:15px;font-weight:600;margin-bottom:4px">Escolha a safra e clique em <em>Gerar painel</em></div>
      <div class="vhint">O painel cruza as três pontas da produção: colheitas realizadas × entradas no estoque
        (origem colheita), vendas confirmadas × baixas de estoque (origem venda) e a prova física do produto
        acabado (saldo atual × colhido − vendido). Nada é carregado antes do filtro.</div>
    </div>
  </div>
  <?php else: ?>

  <!-- ── Bloco A: colheita → estoque (F-05) ─────────────────── -->
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <strong>1 · Colheita realizada × entrada no estoque</strong>
      <?= $badge($gapColheita) ?>
      <span class="vsub">Safra <?= h($safraNome) ?> · colhido <strong><?= h($fmtKg($totColhido)) ?> kg</strong>
        · entradas origem colheita <strong><?= h($fmtKg($totEntrada)) ?> kg</strong>
        <?php if ($colheitasPend): ?> ·
        <a class="no-print" href="?safra=<?= $fSafra ?>&aplicar=1&csv=colheitas_pendentes">Exportar pendências (CSV)</a>
        <?php endif; ?></span>
    </div>
    <?php if (!$colheitasPend): ?>
      <div class="vempty">Todas as colheitas da safra têm entrada ativa no estoque<?= $colheitas ? '' : ' (não há colheitas na safra)' ?>.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>Colheita</th><th>Data</th><th>Cultura</th><th>Válvula</th>
        <th style="text-align:right">Colhido (kg)</th><th style="text-align:right">Perda classificada (kg)</th>
        <th style="text-align:right">Entrada (kg)</th><th style="text-align:right">Δ sem postar (kg)</th>
        <th class="no-print"></th></tr></thead>
      <tbody>
      <?php foreach ($colheitasPend as $r): ?>
        <tr>
          <td>#<?= (int)$r['id'] ?></td>
          <td><?= date('d/m/Y', strtotime((string)$r['data_colheita'])) ?></td>
          <td><?= h((string)($r['cultura'] ?? '—')) ?></td>
          <td><?= h((string)($r['talhao'] ?? '—')) ?></td>
          <td style="text-align:right"><?= h($fmtKg((float)$r['colhido_kg'])) ?></td>
          <td style="text-align:right"><?= h($fmtKg((float)$r['perda_kg'])) ?></td>
          <td style="text-align:right"><?= h($fmtKg((float)$r['entrada_kg'])) ?></td>
          <td style="text-align:right"><strong><?= h($fmtKg((float)$r['gap_kg'])) ?></strong></td>
          <td class="no-print" style="text-align:right">
            <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/colheita/index.php"
               title="A confirmação é o CTA 'Confirmar entrada' na listagem de colheitas">Abrir colheita</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="margin-top:6px">A entrada no estoque considera só o kg APROVADO na classificação —
      a coluna "Perda classificada" mostra o que nunca entraria. Confirmar a entrada é ação do gestor em
      <a href="<?= $base ?>/colheita/index.php">Colheita</a> (botão "Confirmar entrada").</div>
    <?php endif; ?>
  </div>

  <!-- ── Bloco B: venda → baixa de estoque (F-06) ───────────── -->
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <strong>2 · Vendas confirmadas × baixa de estoque</strong>
      <?= $badge($gapVenda) ?>
      <span class="vsub">vendido <strong><?= h($fmtKg($totVendido)) ?> kg</strong>
        · baixado origem venda <strong><?= h($fmtKg($totBaixa)) ?> kg</strong>
        <?php if ($vendasPend): ?> ·
        <a class="no-print" href="?safra=<?= $fSafra ?>&aplicar=1&csv=vendas_pendentes">Exportar pendências (CSV)</a>
        <?php endif; ?></span>
    </div>
    <?php if (!$vendasPend): ?>
      <div class="vempty">Todas as vendas confirmadas da safra baixaram estoque<?= $vendas ? '' : ' (não há vendas confirmadas na safra)' ?>.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>Venda</th><th>Cliente</th><th>Data</th><th>Status</th>
        <th style="text-align:right">Vendido (kg)</th><th style="text-align:right">Baixado (kg)</th>
        <th style="text-align:right">Δ sem baixa (kg)</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php foreach ($vendasPend as $r): ?>
        <tr>
          <td><?= h((string)$r['numero']) ?></td>
          <td><?= h((string)($r['cliente'] ?? '—')) ?></td>
          <td><?= date('d/m/Y', strtotime((string)$r['data_venda'])) ?></td>
          <td><?= h((string)$r['status']) ?><?= $r['lote_id'] === null ? ' <span class="vbadge vb-off">sem lote</span>' : '' ?></td>
          <td style="text-align:right"><?= h($fmtKg((float)$r['vendido_kg'])) ?></td>
          <td style="text-align:right"><?= h($fmtKg((float)$r['baixa_kg'])) ?></td>
          <td style="text-align:right"><strong><?= h($fmtKg((float)$r['gap_kg'])) ?></strong></td>
          <td class="no-print" style="text-align:right">
            <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/comercial/vendas.php?editar=<?= (int)$r['id'] ?>"
               title="Aponte o lote COLH- na venda para baixar o estoque (T33)">Abrir venda</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="margin-top:6px">Venda SEM lote não baixa estoque (design T33) — para reconciliar,
      abra a venda e aponte o lote COLH- correspondente, ou registre a saída no módulo Estoque.</div>
    <?php endif; ?>
  </div>

  <!-- ── Bloco C: prova física do produto acabado ───────────── -->
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>3 · Prova física do produto acabado</strong>
      <span class="vsub">saldo atual em estoque × (colhido − vendido) da safra</span></div>
    <?php if (!$prova['linhas']): ?>
      <div class="vempty">Nenhuma cultura tem produto de estoque de colheita configurado —
        defina em Culturas (produto gerado pela colheita) para habilitar a prova física.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>Produto</th><th>Cultura(s)</th>
        <th style="text-align:right">Colhido (kg)</th><th style="text-align:right">Vendido (kg)</th>
        <th style="text-align:right">Teórico colhido−vendido (kg)</th>
        <th style="text-align:right">Saldo atual (kg)</th><th style="text-align:right">Δ (kg)</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($prova['linhas'] as $r): ?>
        <tr>
          <td><?= h($r['produto']) ?></td>
          <td><?= h($r['culturas']) ?></td>
          <td style="text-align:right"><?= h($fmtKg($r['colhido_kg'])) ?></td>
          <td style="text-align:right"><?= h($fmtKg($r['vendido_kg'])) ?></td>
          <td style="text-align:right"><?= h($fmtKg($r['teorico_kg'])) ?></td>
          <td style="text-align:right"><?= h($fmtKg($r['saldo_kg'])) ?></td>
          <td style="text-align:right"><strong><?= h($fmtKg($r['delta_kg'])) ?></strong></td>
          <td><?= $badge($r['delta_kg']) ?></td>
        </tr>
        <?php if (abs($r['delta_kg']) > INTEG_TOL_KG): ?>
        <tr>
          <td colspan="8" class="vhint">
            Δ explicado: <strong><?= h($fmtKg($r['gap_vendas'])) ?> kg</strong> vendidos sem baixa (bloco 2)
            − <strong><?= h($fmtKg($r['gap_colheitas'])) ?> kg</strong> colhidos sem postar (bloco 1,
              em kg BRUTOS — as perdas classificadas das colheitas não postadas
              seguem dentro deste número; R2-01 da auditoria 19/07)
            <?php if (abs($r['ent_outras_safras']) > 0.0005): ?>
              + <?= h($fmtKg($r['ent_outras_safras'])) ?> kg de colheitas de outras safras<?php endif; ?>
            <?php if (abs($r['baixa_outras_safras']) > 0.0005): ?>
              − <?= h($fmtKg($r['baixa_outras_safras'])) ?> kg de baixas de vendas de outras safras<?php endif; ?>
            <?php if (abs($r['outras_net']) > 0.0005): ?>
              + <?= h($fmtKg($r['outras_net'])) ?> kg de outras movimentações do produto (ajustes/inventário/manual)<?php endif; ?>
            = <strong><?= h($fmtKg($r['delta_explicado'])) ?> kg</strong>
            <?= abs($r['delta_explicado'] - $r['delta_kg']) <= 0.005
                ? '<span class="vbadge vb-ok">conta fecha</span>'
                : '<span class="vbadge vb-warn">resíduo de ' . h($fmtKg($r['delta_kg'] - $r['delta_explicado'])) . ' kg — investigar</span>' ?>
            <?php if ($r['nota_sem_vinculo'] > 0): ?>
              <br>Nota: <?= h($fmtKg($r['nota_sem_vinculo'])) ?> kg de vendas sem lote/colheita vinculados foram
              atribuídos a este produto (único produto acabado do tenant).<?php endif; ?>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($prova['n_produtos'] > 1 && $prova['sem_vinculo'] > INTEG_TOL_KG): ?>
        <tr><td colspan="8" class="vhint">⚠ <?= h($fmtKg($prova['sem_vinculo'])) ?> kg vendidos na safra sem lote
          nem colheita vinculados — não atribuíveis a um produto específico (há mais de um produto acabado).</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="margin-top:6px">O saldo atual do produto é o saldo TOTAL em estoque (todos os
      almoxarifados, todas as safras); entradas/baixas de outras safras entram na explicação do Δ acima.</div>
    <?php endif; ?>
  </div>

  <!-- ── Rodapé ─────────────────────────────────────────────── -->
  <div class="vcard">
    <div class="vhint">
      <strong>Por que existem gaps?</strong> O encadeamento produção → estoque → venda é <strong>opcional por
      design</strong>: a colheita só entra no estoque quando o gestor confirma a entrada (CTA "Confirmar entrada"
      na tela de Colheita) e a venda só baixa estoque quando aponta um lote (decisão T33). Nada disso é erro de
      lançamento — mas nada vigiava a diferença. Este painel existe para <strong>fechar a conta antes do
      fechamento da safra</strong>: reconciliar (confirmar entradas pendentes e apontar lotes nas vendas) é ação
      do gestor, pelas telas de origem — este painel é somente leitura e não altera nenhum dado.
    </div>
  </div>
  <?php endif; /* $aplicado */ ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
