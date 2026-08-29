<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/reconciliacao_indicadores.php   (RECON · A3)
   Verificador READ-ONLY da auditoria matemática de 19/07/2026
   (docs/VERO_AUDIT_MATH_20260719_TRIAGEM.md): recalcula cada
   indicador a partir das LINHAS BRUTAS do schema REAL e compara
   com o valor exibido nas telas (referência colhida em 19/07).

   O pacote SQL original foi escrito com schema INFERIDO da
   interface; mapeamento inferido → real:

     contas_receber            → movimentacoes_financeiras (tipo='receber')
     contas_pagar              → movimentacoes_financeiras (tipo='pagar')
     vendas                    → comercial_vendas (status<>'cancelada')
     colheitas                 → colheita_registros
     produtos                  → estoque_produtos (+ estoque_saldos = saldo EXIBIDO)
     movimentacoes_estoque     → estoque_movimentacoes (estorno LÓGICO: estornado_em)
     safras                    → agro_safras
     custeio (inalterado)      → custeio_lancamentos (safra_id NULLable)
     entrada de colheita       → estoque_movimentacoes.origem_tipo='colheita'
     baixa de venda            → estoque_movimentacoes.origem_tipo='comercial_venda'

   Uso:  php scripts/reconciliacao_indicadores.php [tenant_id]
         (tenant default = 1)
   Saída: CALCULADO vs TELA(19/07) por bloco; OK se |Δ| < 0,01.
   Exit code: nº de DIVERGÊNCIAS confirmadas (0 = tudo OK).
   GARANTIA: apenas SELECT/SHOW — nada é gravado no banco.
   ============================================================ */

require __DIR__ . '/../includes/db.php';
$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$TENANT = isset($argv[1]) ? max(1, (int)$argv[1]) : 1;
$TOL    = 0.01; /* divergência ≥ 0,01 (R$ ou kg) = linha no relatório */

/* Valores de TELA capturados na auditoria de 19/07/2026 (referência).
   Se o banco mudou desde então, o script reporta o número real. */
$REF = [
    'fin_receber_aberto'   => 6500.00,
    'fin_pagar_aberto'     => 1598.50,
    'fin_posicao'          => 4901.50,
    'fin_caixa_realizado'  => 521325.00,
    'custeio_2026_1'       => 3145.86,
    'custeio_ano_2026'     => 19007.37,
    'custeio_sem_safra'    => 13780.00,
    'com_fat_2026_1'       => 528922.50,
    'com_kg_2026_1'        => 97750.0,
    'col_realizado_2026_1' => 192000.0,
    'col_previsto_2026_1'  => 200000.0,
    'f05_gap_2026_1'       => 92000.0,
    'f06_gap_2026_1'       => 96750.0,
    'prova_saldo_estoque'  => 99000.0,
    'prova_colhido_vendido'=> 94250.0,
];

$DIVERGENCIAS = [];
$NA = [];

function q(PDO $pdo, string $sql, array $p = []): array {
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function qv(PDO $pdo, string $sql, array $p = []): float {
    $st = $pdo->prepare($sql); $st->execute($p);
    return (float)$st->fetchColumn();
}
function fmt(float $v, int $d = 2): string {
    return number_format($v, $d, ',', '.');
}
/** diferença em CENTAVOS exatos (evita 0,00999… float mascarar Δ de 1 centavo) */
function delta_cents(float $a, float $b): int {
    return (int)round(($a - $b) * 100);
}
/** compara CALCULADO × referência de tela; registra divergência global */
function chk(string $rotulo, float $calc, ?float $ref, int $dec = 2): void {
    global $DIVERGENCIAS;
    if ($ref === null) {
        printf("  %-52s calc %18s   (sem referência de tela)\n", $rotulo, fmt($calc, $dec));
        return;
    }
    $delta = $calc - $ref;
    $ok = delta_cents($calc, $ref) === 0;
    printf("  %-52s calc %18s | tela %18s  %s\n",
        $rotulo, fmt($calc, $dec), fmt($ref, $dec),
        $ok ? '[OK]' : '[DIVERGÊNCIA Δ ' . fmt($delta, $dec) . ']');
    if (!$ok) $DIVERGENCIAS[] = $rotulo . ' — calc ' . fmt($calc, $dec) . ' vs tela ' . fmt($ref, $dec)
        . ' (Δ ' . fmt($delta, $dec) . ')';
}
function tab_existe(PDO $pdo, string $t): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$t]);
    return (bool)$st->fetchColumn();
}
function col_existe(PDO $pdo, string $t, string $c): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$t, $c]);
    return (bool)$st->fetchColumn();
}
function secao(string $titulo): void {
    echo "\n" . str_repeat('=', 78) . "\n== {$titulo}\n" . str_repeat('=', 78) . "\n";
}

/* ---------- cabeçalho + guarda de schema ---------- */
echo "== VERO :: RECON — reconciliação matemática de indicadores (READ-ONLY) ==\n";
echo "banco:  " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
echo "tenant: {$TENANT}";
try {
    $tn = q($pdo, "SELECT nome FROM tenants WHERE id = ?", [$TENANT]);
    if ($tn) echo ' (' . $tn[0]['nome'] . ')';
} catch (Throwable $e) { /* tabela tenants pode ter outro nome — só informativo */ }
echo "\ndata:   " . date('Y-m-d H:i:s') . "\n";
echo "refs de tela: 19/07/2026 (se o banco mudou, vale o CALCULADO)\n";
echo "\nmapeamento schema inferido -> real:\n";
foreach ([
    'contas_receber'        => "movimentacoes_financeiras (tipo='receber')",
    'contas_pagar'          => "movimentacoes_financeiras (tipo='pagar')",
    'vendas'                => "comercial_vendas (status<>'cancelada')",
    'colheitas'             => 'colheita_registros',
    'produtos'              => 'estoque_produtos + estoque_saldos (exibido)',
    'movimentacoes_estoque' => 'estoque_movimentacoes (estornado_em IS NULL = ativa)',
    'safras'                => 'agro_safras',
    'custeio_lancamentos'   => 'custeio_lancamentos (real, safra_id nullable)',
] as $inf => $real) printf("  %-22s -> %s\n", $inf, $real);

$tabelasNecessarias = ['movimentacoes_financeiras', 'comercial_vendas', 'colheita_registros',
    'custeio_lancamentos', 'agro_safras', 'agro_safra_talhoes', 'estoque_produtos',
    'estoque_movimentacoes', 'estoque_saldos'];
$faltas = [];
foreach ($tabelasNecessarias as $t) if (!tab_existe($pdo, $t)) $faltas[] = $t;
if ($faltas) {
    echo "\n[ABORTADO] tabelas ausentes no banco: " . implode(', ', $faltas) . "\n";
    exit(2);
}

/* safras do tenant (chave por identificação, ex. '2026.1') */
$safras = q($pdo, "SELECT id, identificacao, status FROM agro_safras WHERE tenant_id = ? ORDER BY identificacao", [$TENANT]);
$safraPorIdent = [];
foreach ($safras as $s) $safraPorIdent[$s['identificacao']] = (int)$s['id'];

/* ============================================================
   1. FINANCEIRO (movimentacoes_financeiras)
   Tela: contas_receber/contas_pagar somam status='aberto';
   cancelamento é LÓGICO (status='cancelado'); caixa realizado =
   status='pago' (receber − pagar) por data_pagamento.
   ============================================================ */
secao('1. FINANCEIRO — em aberto, posição, F-04, caixa realizado, fluxo previsto');

$recAberto = qv($pdo, "SELECT COALESCE(SUM(valor),0) FROM movimentacoes_financeiras
    WHERE tenant_id = ? AND tipo = 'receber' AND status = 'aberto'", [$TENANT]);
$pagAberto = qv($pdo, "SELECT COALESCE(SUM(valor),0) FROM movimentacoes_financeiras
    WHERE tenant_id = ? AND tipo = 'pagar' AND status = 'aberto'", [$TENANT]);
chk('A receber em aberto (R$)', $recAberto, $REF['fin_receber_aberto']);
chk('A pagar em aberto (R$)',   $pagAberto, $REF['fin_pagar_aberto']);
chk('Posição (receber − pagar) (R$)', $recAberto - $pagAberto, $REF['fin_posicao']);

/* status fora do fluxo das telas (enum tem 'previsto' e 'baixado' — as telas
   só usam aberto/pago/cancelado; se houver linhas nesses status, avisar) */
foreach (q($pdo, "SELECT status, COUNT(*) n, COALESCE(SUM(valor),0) v FROM movimentacoes_financeiras
    WHERE tenant_id = ? AND status IN ('previsto','baixado') GROUP BY status", [$TENANT]) as $r) {
    echo "  [ATENÇÃO] {$r['n']} título(s) com status '{$r['status']}' (R$ " . fmt((float)$r['v'])
        . ") — fora do cálculo das telas (aberto/pago).\n";
}

/* F-04: títulos ABERTOS sem vencimento */
$semVenc = q($pdo, "SELECT id, tipo, documento, descricao, valor, data_competencia
    FROM movimentacoes_financeiras
    WHERE tenant_id = ? AND status = 'aberto' AND data_vencimento IS NULL
    ORDER BY tipo, id", [$TENANT]);
echo "\n  F-04 — títulos em aberto SEM data_vencimento: " . count($semVenc) . "\n";
$foraFluxo = 0.0;
foreach ($semVenc as $r) {
    $bucketComp = $r['data_competencia'] !== null; /* fluxo usa COALESCE(venc, competência) */
    if (!$bucketComp) $foraFluxo += (float)$r['valor'];
    printf("    #%-6d %-8s %-14s %-34s R$ %12s  %s\n",
        (int)$r['id'], $r['tipo'], (string)($r['documento'] ?? '—'),
        mb_substr((string)($r['descricao'] ?? ''), 0, 34), fmt((float)$r['valor']),
        $bucketComp ? '(entra no fluxo pela COMPETÊNCIA)' : '(FORA de qualquer bucket do fluxo)');
}
if ($foraFluxo > 0) {
    echo "    => R$ " . fmt($foraFluxo) . " em aberto FORA do fluxo previsto (sem venc. e sem competência)\n";
}

/* Caixa realizado (baixas): Σ(receber pago) − Σ(pagar pago), todo o histórico */
$caixaReal = qv($pdo, "SELECT COALESCE(SUM(CASE WHEN tipo='receber' THEN valor
        WHEN tipo='pagar' THEN -valor ELSE 0 END),0)
    FROM movimentacoes_financeiras WHERE tenant_id = ? AND status = 'pago'", [$TENANT]);
echo "\n";
chk('Caixa realizado acumulado (baixas) (R$)', $caixaReal, $REF['fin_caixa_realizado']);

/* Fluxo previsto por mês de vencimento (fallback competência) — como a tela */
echo "\n  Fluxo PREVISTO (abertos) por mês de COALESCE(vencimento, competência):\n";
$fluxo = q($pdo, "SELECT DATE_FORMAT(COALESCE(data_vencimento, data_competencia), '%Y-%m') AS mes,
        COALESCE(SUM(CASE WHEN tipo='receber' THEN valor ELSE 0 END),0) AS entradas,
        COALESCE(SUM(CASE WHEN tipo='pagar'  THEN valor ELSE 0 END),0) AS saidas
    FROM movimentacoes_financeiras
    WHERE tenant_id = ? AND status = 'aberto'
      AND COALESCE(data_vencimento, data_competencia) IS NOT NULL
    GROUP BY mes ORDER BY mes", [$TENANT]);
$somaFluxoE = 0.0; $somaFluxoS = 0.0;
foreach ($fluxo as $f) {
    $somaFluxoE += (float)$f['entradas']; $somaFluxoS += (float)$f['saidas'];
    printf("    %s   +%14s   -%14s\n", $f['mes'], fmt((float)$f['entradas']), fmt((float)$f['saidas']));
}
if ($foraFluxo > 0) printf("    %-9s +/-%12s   <- bucket 'Sem vencimento' proposto no F-04\n", 'SEM DATA', fmt($foraFluxo));
$gapFluxo = ($recAberto + $pagAberto) - ($somaFluxoE + $somaFluxoS + $foraFluxo);
echo '  Prova F-04: Σ abertos (' . fmt($recAberto + $pagAberto) . ') − Σ buckets+sem-data ('
    . fmt($somaFluxoE + $somaFluxoS + $foraFluxo) . ') = ' . fmt($gapFluxo)
    . (abs($gapFluxo) < $TOL ? '  [OK — fluxo fecha com o em-aberto]' : '  [DIVERGÊNCIA interna]') . "\n";
if (abs($gapFluxo) >= $TOL) $DIVERGENCIAS[] = 'F-04: fluxo previsto não fecha com o total em aberto (Δ ' . fmt($gapFluxo) . ')';

/* ============================================================
   2. CUSTEIO (custeio_lancamentos)
   Mesmas fontes de custeio/resultado_safra.php:
   custo = Σ custeio_lancamentos.valor por safra_id.
   ============================================================ */
secao('2. CUSTEIO — safra × categoria, ano de competência, resultado da safra, OBS-A');

echo "  Custo por safra × categoria:\n";
$mat = q($pdo, "SELECT COALESCE(s.identificacao, '(sem safra)') AS safra,
        COALESCE(cl.categoria, '(sem categoria)') AS categoria,
        COUNT(*) n, COALESCE(SUM(cl.valor),0) v
    FROM custeio_lancamentos cl
    LEFT JOIN agro_safras s ON s.id = cl.safra_id
    WHERE cl.tenant_id = ?
    GROUP BY safra, categoria ORDER BY safra, categoria", [$TENANT]);
foreach ($mat as $m) {
    printf("    %-14s %-24s %4d lanç.  R$ %14s\n", $m['safra'], $m['categoria'], (int)$m['n'], fmt((float)$m['v']));
}

echo "\n  Custo por ano de competência:\n";
foreach (q($pdo, "SELECT YEAR(data_competencia) AS ano, COALESCE(SUM(valor),0) v
    FROM custeio_lancamentos WHERE tenant_id = ? GROUP BY ano ORDER BY ano", [$TENANT]) as $a) {
    printf("    %-6s R$ %14s\n", (string)$a['ano'], fmt((float)$a['v']));
}

$custoSafra1 = isset($safraPorIdent['2026.1'])
    ? qv($pdo, "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id = ? AND safra_id = ?",
        [$TENANT, $safraPorIdent['2026.1']]) : null;
$custoAno2026 = qv($pdo, "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos
    WHERE tenant_id = ? AND YEAR(data_competencia) = 2026", [$TENANT]);
$semSafra = qv($pdo, "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos
    WHERE tenant_id = ? AND safra_id IS NULL", [$TENANT]);
echo "\n";
if ($custoSafra1 !== null) chk('Custo da safra 2026.1 (R$)', $custoSafra1, $REF['custeio_2026_1']);
else { echo "  [N/A] safra '2026.1' não encontrada no tenant {$TENANT}\n"; $NA[] = 'custo 2026.1'; }
chk('Custo ano-competência 2026 (R$)', $custoAno2026, $REF['custeio_ano_2026']);
chk('Custeio SEM safra (OBS-A, informativo) (R$)', $semSafra, $REF['custeio_sem_safra']);
echo "  (OBS-A é comportamento por design — atribuição sem-safra é ato manual na tela de Rateios)\n";

echo "\n  Resultado da safra (MESMAS fontes de custeio/resultado_safra.php):\n";
$res = q($pdo, "SELECT s.id, s.identificacao,
        COALESCE((SELECT SUM(cl.valor) FROM custeio_lancamentos cl
          WHERE cl.tenant_id = s.tenant_id AND cl.safra_id = s.id),0) AS custo,
        COALESCE((SELECT SUM(v.valor_total) FROM comercial_vendas v
          WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id AND v.status <> 'cancelada'),0) AS vendas,
        COALESCE((SELECT SUM(v.kg_total) FROM comercial_vendas v
          WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id AND v.status <> 'cancelada'),0) AS kg_vendidos,
        COALESCE((SELECT SUM(r.kg_total_realizado) FROM colheita_registros r
          WHERE r.tenant_id = s.tenant_id AND r.safra_id = s.id),0) AS kg_colhidos,
        COALESCE((SELECT SUM(st.area_plantada_ha) FROM agro_safra_talhoes st
          WHERE st.tenant_id = s.tenant_id AND st.safra_id = s.id),0) AS area_ha
    FROM agro_safras s WHERE s.tenant_id = ? ORDER BY s.identificacao", [$TENANT]);
printf("    %-10s %10s %14s %14s %14s %12s %10s %14s %8s\n",
    'safra', 'área ha', 'kg colhidos', 'vendas R$', 'custo R$', 'custo/ha', 'custo/kg', 'result. bruto', 'margem');
foreach ($res as $r) {
    $custo = (float)$r['custo']; $vendas = (float)$r['vendas'];
    $area = (float)$r['area_ha']; $kgC = (float)$r['kg_colhidos'];
    printf("    %-10s %10s %14s %14s %14s %12s %10s %14s %8s\n",
        $r['identificacao'], fmt($area), fmt($kgC, 0), fmt($vendas), fmt($custo),
        $area > 0 ? fmt($custo / $area) : '—', $kgC > 0 ? fmt($custo / $kgC, 4) : '—',
        fmt($vendas - $custo), $vendas > 0 ? fmt(($vendas - $custo) / $vendas * 100, 1) . '%' : '—');
}

/* ============================================================
   3. ESTOQUE (estoque_movimentacoes vs estoque_saldos)
   Saldo recalculado = Σ entradas − Σ saídas ± ajustes (qtd
   assinada), EXCLUINDO estornadas (estornado_em IS NULL).
   Transferências são pares entrada/saída — anulam no produto.
   Exibido = estoque_saldos (mantido pelos services).
   ============================================================ */
secao('3. ESTOQUE — saldo recalculado vs exibido; valor (OBS-C)');

$recalc = q($pdo, "SELECT p.id, p.codigo, p.nome, p.unidade,
        COALESCE(SUM(CASE WHEN mv.tipo='entrada' THEN mv.quantidade
                          WHEN mv.tipo='saida'   THEN -mv.quantidade
                          WHEN mv.tipo='ajuste'  THEN mv.quantidade
                          ELSE 0 END),0) AS saldo_calc,
        COALESCE((SELECT SUM(s.quantidade) FROM estoque_saldos s
          WHERE s.tenant_id = p.tenant_id AND s.produto_id = p.id),0) AS saldo_exibido
    FROM estoque_produtos p
    LEFT JOIN estoque_movimentacoes mv
      ON mv.produto_id = p.id AND mv.tenant_id = p.tenant_id AND mv.estornado_em IS NULL
    WHERE p.tenant_id = ?
    GROUP BY p.id, p.codigo, p.nome, p.unidade
    ORDER BY p.codigo", [$TENANT]);
$divSaldo = 0;
foreach ($recalc as $r) {
    $delta = (float)$r['saldo_calc'] - (float)$r['saldo_exibido'];
    if (delta_cents((float)$r['saldo_calc'], (float)$r['saldo_exibido']) !== 0) {
        $divSaldo++;
        printf("  [DIVERGÊNCIA] %-6s %-32s calc %14s | exibido %14s | Δ %s %s\n",
            $r['codigo'], mb_substr((string)$r['nome'], 0, 32), fmt((float)$r['saldo_calc']),
            fmt((float)$r['saldo_exibido']), fmt($delta), $r['unidade']);
        $DIVERGENCIAS[] = "Estoque saldo {$r['codigo']} {$r['nome']}: calc " . fmt((float)$r['saldo_calc'])
            . ' vs exibido ' . fmt((float)$r['saldo_exibido']);
    }
}
echo '  ' . count($recalc) . " produto(s) verificados — " . ($divSaldo === 0
    ? "TODOS os saldos exibidos batem com Σ movimentações ativas. [OK]\n"
    : "{$divSaldo} com divergência de saldo.\n");

/* OBS-C: valor exibido vs ROUND(saldo × custo_medio, 2), por linha de saldo */
$valLinhas = q($pdo, "SELECT s.produto_id, p.codigo, p.nome, s.almoxarifado_id,
        s.quantidade, s.custo_medio, s.valor_total,
        ROUND(s.quantidade * s.custo_medio, 2) AS valor_calc
    FROM estoque_saldos s JOIN estoque_produtos p ON p.id = s.produto_id
    WHERE s.tenant_id = ?", [$TENANT]);
$divVal = 0; $maiorDeltaVal = 0.0;
foreach ($valLinhas as $r) {
    $delta = delta_cents((float)$r['valor_calc'], (float)$r['valor_total']) / 100.0;
    $maiorDeltaVal = max($maiorDeltaVal, abs($delta));
    if ($delta != 0.0) {
        $divVal++;
        printf("  [OBS-C] %-6s %-30s almox %-3d  ROUND(%s × %s)=%s | exibido %s | Δ %s\n",
            $r['codigo'], mb_substr((string)$r['nome'], 0, 30), (int)$r['almoxarifado_id'],
            fmt((float)$r['quantidade']), fmt((float)$r['custo_medio'], 6),
            fmt((float)$r['valor_calc']), fmt((float)$r['valor_total']), fmt($delta));
        /* Decisão A0 19/07: Δ de ATÉ 1 centavo por linha é WARN, não divergência —
           valor_total é o canônico (ledger); custo_medio deriva dele truncado a 6
           casas, então recompor saldo×custo pode residual 0,01 por construção.
           Acima de 0,01 continua contando como divergência real. */
        if (abs($delta) > 0.01) {
            $DIVERGENCIAS[] = "OBS-C valor estoque {$r['codigo']} almox {$r['almoxarifado_id']}: Δ " . fmt($delta);
        }
    }
}
echo '  OBS-C: ' . count($valLinhas) . ' linha(s) de saldo — '
    . ($divVal === 0 ? 'valor_total ≡ ROUND(qtd×custo_médio,2) em todas (maior |Δ| '
        . fmt($maiorDeltaVal, 4) . "). [OK]\n" : "{$divVal} com Δ ≥ 0,01.\n");
if ($divVal > 0) {
    echo "  Nota OBS-C: custo_medio é persistido TRUNCADO a 6 casas (valor_total/quantidade);\n";
    echo "  a tela de Produtos exibe s.valor_total direto (canônico) — o resíduo de 1 centavo\n";
    echo "  aparece só em quem RECOMPUTA saldo×custo_medio (a fórmula do pacote de auditoria).\n";
}

/* ============================================================
   4. COMERCIAL (comercial_vendas, status <> 'cancelada')
   ============================================================ */
secao('4. COMERCIAL — faturamento, kg e preço médio por safra');

$com = q($pdo, "SELECT COALESCE(s.identificacao,'(sem safra)') AS safra, COUNT(*) n,
        COALESCE(SUM(v.valor_total),0) fat, COALESCE(SUM(v.kg_total),0) kg
    FROM comercial_vendas v LEFT JOIN agro_safras s ON s.id = v.safra_id
    WHERE v.tenant_id = ? AND v.status <> 'cancelada'
    GROUP BY safra ORDER BY safra", [$TENANT]);
$fat1 = null; $kg1 = null;
foreach ($com as $c) {
    printf("    %-14s %3d venda(s)  R$ %16s   %14s kg   preço médio %s R$/kg\n",
        $c['safra'], (int)$c['n'], fmt((float)$c['fat']), fmt((float)$c['kg'], 0),
        (float)$c['kg'] > 0 ? fmt((float)$c['fat'] / (float)$c['kg'], 4) : '—');
    if ($c['safra'] === '2026.1') { $fat1 = (float)$c['fat']; $kg1 = (float)$c['kg']; }
}
$canc = q($pdo, "SELECT COUNT(*) n, COALESCE(SUM(valor_total),0) v FROM comercial_vendas
    WHERE tenant_id = ? AND status = 'cancelada'", [$TENANT])[0];
if ((int)$canc['n'] > 0) echo "    (excluídas {$canc['n']} venda(s) cancelada(s), R$ " . fmt((float)$canc['v']) . ")\n";
echo "\n";
if ($fat1 !== null) {
    chk('Faturamento safra 2026.1 (R$)', $fat1, $REF['com_fat_2026_1']);
    chk('kg vendidos safra 2026.1', $kg1, $REF['com_kg_2026_1'], 0);
} else { echo "  [N/A] nenhuma venda não-cancelada na safra 2026.1\n"; $NA[] = 'comercial 2026.1'; }

/* ============================================================
   5. COLHEITA (colheita_registros)
   ============================================================ */
secao('5. COLHEITA — previsto × realizado por safra, produtividade');

$colh = q($pdo, "SELECT COALESCE(s.identificacao,'(sem safra)') AS safra, COUNT(*) n,
        COALESCE(SUM(r.kg_total_previsto),0) kg_prev, COALESCE(SUM(r.kg_total_realizado),0) kg_real
    FROM colheita_registros r LEFT JOIN agro_safras s ON s.id = r.safra_id
    WHERE r.tenant_id = ? GROUP BY safra ORDER BY safra", [$TENANT]);
$colReal1 = null; $colPrev1 = null;
foreach ($colh as $c) {
    $areaS = isset($safraPorIdent[$c['safra']])
        ? qv($pdo, "SELECT COALESCE(SUM(area_plantada_ha),0) FROM agro_safra_talhoes
            WHERE tenant_id = ? AND safra_id = ?", [$TENANT, $safraPorIdent[$c['safra']]]) : 0.0;
    printf("    %-14s %3d registro(s)  previsto %14s kg  realizado %14s kg  ating. %6s  produtiv. %s\n",
        $c['safra'], (int)$c['n'], fmt((float)$c['kg_prev'], 0), fmt((float)$c['kg_real'], 0),
        (float)$c['kg_prev'] > 0 ? fmt((float)$c['kg_real'] / (float)$c['kg_prev'] * 100, 1) . '%' : '—',
        $areaS > 0 ? fmt((float)$c['kg_real'] / $areaS, 0) . ' kg/ha' : '—');
    if ($c['safra'] === '2026.1') { $colReal1 = (float)$c['kg_real']; $colPrev1 = (float)$c['kg_prev']; }
}
echo "\n";
if ($colReal1 !== null) {
    chk('kg colhidos (realizado) safra 2026.1', $colReal1, $REF['col_realizado_2026_1'], 0);
    chk('kg previstos safra 2026.1', $colPrev1, $REF['col_previsto_2026_1'], 0);
} else { echo "  [N/A] nenhum registro de colheita na safra 2026.1\n"; $NA[] = 'colheita 2026.1'; }

/* ============================================================
   6. CROSS-MÓDULO — F-05, F-06 e prova física
   Entrada de colheita: estoque_movimentacoes origem_tipo='colheita',
   origem_id = colheita_registros.id (service vero_srv_colheita_confirmar_entrada).
   Baixa de venda: origem_tipo='comercial_venda', origem_id = venda.
   ============================================================ */
secao('6. CROSS-MÓDULO — colheita×estoque (F-05), venda×estoque (F-06), prova física');

foreach ($safras as $s) {
    $sid = (int)$s['id']; $ident = $s['identificacao'];

    $kgColhido = qv($pdo, "SELECT COALESCE(SUM(kg_total_realizado),0) FROM colheita_registros
        WHERE tenant_id = ? AND safra_id = ?", [$TENANT, $sid]);
    $kgEntrado = qv($pdo, "SELECT COALESCE(SUM(m.quantidade),0)
        FROM estoque_movimentacoes m
        JOIN colheita_registros r ON r.id = m.origem_id AND r.tenant_id = m.tenant_id
        WHERE m.tenant_id = ? AND m.origem_tipo = 'colheita' AND m.tipo = 'entrada'
          AND m.estornado_em IS NULL AND r.safra_id = ?", [$TENANT, $sid]);
    $kgVendido = qv($pdo, "SELECT COALESCE(SUM(kg_total),0) FROM comercial_vendas
        WHERE tenant_id = ? AND safra_id = ? AND status <> 'cancelada'", [$TENANT, $sid]);
    $kgBaixado = qv($pdo, "SELECT COALESCE(SUM(m.quantidade),0)
        FROM estoque_movimentacoes m
        JOIN comercial_vendas v ON v.id = m.origem_id AND v.tenant_id = m.tenant_id
        WHERE m.tenant_id = ? AND m.origem_tipo = 'comercial_venda' AND m.tipo = 'saida'
          AND m.estornado_em IS NULL AND v.safra_id = ? AND v.status <> 'cancelada'", [$TENANT, $sid]);

    if ($kgColhido == 0.0 && $kgVendido == 0.0 && $kgEntrado == 0.0 && $kgBaixado == 0.0) continue;

    echo "  Safra {$ident}:\n";
    $gap05 = $kgColhido - $kgEntrado;
    $gap06 = $kgVendido - $kgBaixado;
    printf("    F-05  colhido %14s kg | entradas origem 'colheita' %14s kg | GAP %14s kg\n",
        fmt($kgColhido, 0), fmt($kgEntrado, 0), fmt($gap05, 0));
    printf("    F-06  vendido %14s kg | saídas origem 'comercial_venda' %11s kg | GAP %14s kg\n",
        fmt($kgVendido, 0), fmt($kgBaixado, 0), fmt($gap06, 0));
    if ($ident === '2026.1') {
        chk('F-05 gap 2026.1 (kg, ref. auditoria)', $gap05, $REF['f05_gap_2026_1'], 0);
        chk('F-06 gap 2026.1 (kg, ref. auditoria)', $gap06, $REF['f06_gap_2026_1'], 0);
        echo "    (gap > 0 NÃO é erro de conta: o fluxo colheita→estoque→venda é opcional por design;\n";
        echo "     a triagem manda criar PAINEL DE INTEGRIDADE (A1/A2), não backfill.)\n";
    }

    /* colheitas não postadas — detalhe do F-05 */
    $naoPostadas = q($pdo, "SELECT r.id, r.data_colheita, r.kg_total_realizado
        FROM colheita_registros r
        WHERE r.tenant_id = ? AND r.safra_id = ? AND r.kg_total_realizado > 0
          AND NOT EXISTS (SELECT 1 FROM estoque_movimentacoes m
            WHERE m.tenant_id = r.tenant_id AND m.origem_tipo = 'colheita'
              AND m.origem_id = r.id AND m.tipo = 'entrada' AND m.estornado_em IS NULL)
        ORDER BY r.id", [$TENANT, $sid]);
    foreach ($naoPostadas as $np) {
        printf("      colheita #%d (%s) NÃO postada no estoque: %s kg\n",
            (int)$np['id'], (string)$np['data_colheita'], fmt((float)$np['kg_total_realizado'], 0));
    }
    $vendasSemBaixa = q($pdo, "SELECT v.id, v.numero, v.kg_total
        FROM comercial_vendas v
        WHERE v.tenant_id = ? AND v.safra_id = ? AND v.status <> 'cancelada' AND v.kg_total > 0
          AND NOT EXISTS (SELECT 1 FROM estoque_movimentacoes m
            WHERE m.tenant_id = v.tenant_id AND m.origem_tipo = 'comercial_venda'
              AND m.origem_id = v.id AND m.tipo = 'saida' AND m.estornado_em IS NULL)
        ORDER BY v.id", [$TENANT, $sid]);
    if ($vendasSemBaixa) {
        echo '      vendas sem baixa de estoque: ' . count($vendasSemBaixa) . ' (';
        echo implode(', ', array_map(fn($v) => $v['numero'] . '=' . fmt((float)$v['kg_total'], 0) . 'kg', $vendasSemBaixa)) . ")\n";
    }
}

/* Prova física — produto ACABADO da colheita (agro_culturas.produto_estoque_colheita_id) */
echo "\n  Prova física (produto acabado da colheita):\n";
if (!col_existe($pdo, 'agro_culturas', 'produto_estoque_colheita_id')) {
    echo "  [N/A] agro_culturas.produto_estoque_colheita_id não existe neste banco.\n";
    $NA[] = 'prova física (coluna do produto de colheita ausente)';
} else {
    $prods = q($pdo, "SELECT DISTINCT c.produto_estoque_colheita_id AS pid, p.codigo, p.nome
        FROM agro_culturas c JOIN estoque_produtos p ON p.id = c.produto_estoque_colheita_id
        WHERE c.tenant_id = ? AND c.produto_estoque_colheita_id IS NOT NULL", [$TENANT]);
    if (!$prods) { echo "  [N/A] nenhuma cultura com produto de estoque de colheita configurado.\n"; $NA[] = 'prova física (sem produto configurado)'; }
    foreach ($prods as $pp) {
        $pid = (int)$pp['pid'];
        $saldoProd = qv($pdo, "SELECT COALESCE(SUM(quantidade),0) FROM estoque_saldos
            WHERE tenant_id = ? AND produto_id = ?", [$TENANT, $pid]);
        $kgColhTot = qv($pdo, "SELECT COALESCE(SUM(kg_total_realizado),0) FROM colheita_registros WHERE tenant_id = ?", [$TENANT]);
        $kgVendTot = qv($pdo, "SELECT COALESCE(SUM(kg_total),0) FROM comercial_vendas
            WHERE tenant_id = ? AND status <> 'cancelada'", [$TENANT]);
        $teorico = $kgColhTot - $kgVendTot;
        printf("    %-6s %-30s saldo em estoque %14s kg | colhido−vendido (teórico) %14s kg | Δ %s kg\n",
            $pp['codigo'], mb_substr((string)$pp['nome'], 0, 30), fmt($saldoProd, 0), fmt($teorico, 0), fmt($saldoProd - $teorico, 0));
        chk('  Prova física: saldo estoque (kg)', $saldoProd, $REF['prova_saldo_estoque'], 0);
        chk('  Prova física: colhido − vendido (kg)', $teorico, $REF['prova_colhido_vendido'], 0);
        echo "    (Δ entre as pontas é CONSEQUÊNCIA direta de F-05/F-06 — kg colhidos não postados e\n";
        echo "     vendas sem baixa; não é erro aritmético de nenhuma das telas.)\n";
    }
}

/* ============================================================
   RESUMO
   ============================================================ */
secao('RESUMO');
if (!$DIVERGENCIAS) {
    echo "  Nenhuma divergência aritmética ≥ 0,01 entre CALCULADO e telas de referência.\n";
} else {
    echo '  ' . count($DIVERGENCIAS) . " divergência(s):\n";
    foreach ($DIVERGENCIAS as $d) echo "    - {$d}\n";
}
if ($NA) {
    echo "  Não aplicável nesta base: " . implode('; ', $NA) . "\n";
}
echo "  Lembrete: F-05/F-06/prova física são GAPS DE PROCESSO esperados (fluxo opcional);\n";
echo "  contam como divergência apenas se fugirem da referência da auditoria de 19/07.\n";
exit(count($DIVERGENCIAS) > 0 ? 1 : 0);
