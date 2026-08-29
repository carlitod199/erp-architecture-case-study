<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/vero_services.php
   Regras de negócio em funções puras (testáveis isoladamente)
   + emissão idempotente de custo em custeio_lancamentos.
   Nenhuma fórmula de premiação/custo deve viver no HTML das telas.
   ============================================================ */

require_once __DIR__ . '/vero_crud.php';
require_once __DIR__ . '/../custeio/_plano_map.php'; /* mapa origem→plano de contas (contrato A3-T10) */

/* ───────────────────────── Cálculos puros ───────────────────────── */

/**
 * Premiação CLT: (realizado − meta) × R$/unidade, nunca negativa.
 * Aceite validado: 130 realizado, meta 100, R$ 1,20 → R$ 36,00.
 * @return array{qtd_acima: float, valor_total: float}
 */
function vero_srv_premiacao_calc(float $quantidade, float $meta, float $valorUnitario): array
{
    $acima = max(0.0, $quantidade - $meta);
    return ['qtd_acima' => $acima, 'valor_total' => round($acima * $valorUnitario, 2)];
}

/**
 * Produção/diária de terceirizado: quantidade × valor unitário.
 * Aceite validado: 120 plantas × R$ 2,00 = R$ 240,00.
 */
function vero_srv_valor_producao(float $quantidade, float $valorUnitario): float
{
    return round($quantidade * $valorUnitario, 2);
}

/**
 * Calculadora de diárias: nº de plantas ÷ dias = plantas/dia;
 * diárias/dia = plantas/dia ÷ meta, SEMPRE arredondado para cima.
 * @return array{plantas_dia: float, diarias_dia: int, diarias_total: int}
 */
function vero_srv_diarias_necessarias(float $totalPlantas, int $dias, float $metaPorDiaria): array
{
    if ($dias < 1 || $metaPorDiaria <= 0 || $totalPlantas <= 0) {
        return ['plantas_dia' => 0.0, 'diarias_dia' => 0, 'diarias_total' => 0];
    }
    $plantasDia = $totalPlantas / $dias;
    $diariasDia = (int)ceil($plantasDia / $metaPorDiaria);
    return [
        'plantas_dia'   => round($plantasDia, 2),
        'diarias_dia'   => $diariasDia,
        'diarias_total' => $diariasDia * $dias,
    ];
}

/* ───────────────────────── Regra de premiação vigente ───────────────────────── */

/**
 * Regra de premiação vigente para atividade × cultura na data.
 * Preferência: regra específica da cultura > regra "todas as culturas".
 * Retorna null se não houver — o sistema NUNCA inventa regra (D5-análoga).
 */
function vero_srv_regra_premiacao(int $tipoAtividadeId, ?int $culturaId, string $data): ?array
{
    $sql = "SELECT * FROM rh_regras_premiacao
             WHERE tenant_id = :t AND tipo_atividade_id = :a AND ativo = 1
               AND (vigencia_inicio IS NULL OR vigencia_inicio <= :d1)
               AND (vigencia_fim    IS NULL OR vigencia_fim    >= :d2)
               AND (cultura_id IS NULL OR cultura_id = :c)
             ORDER BY (cultura_id IS NULL), id DESC
             LIMIT 1";
    return vero_row($sql, [
        ':t' => vero_tenant(), ':a' => $tipoAtividadeId,
        ':d1' => $data, ':d2' => $data, ':c' => $culturaId ?? 0,
    ]);
}

/* ───────────────────────── Centro de custo padrão ───────────────────────── */

/** Get-or-create de centro de custo por código (custeio exige centro NOT NULL). */
function vero_srv_centro_custo(string $codigo, string $nome): int
{
    $id = vero_val(
        "SELECT id FROM centros_custo WHERE tenant_id = :t AND codigo = :c LIMIT 1",
        [':t' => vero_tenant(), ':c' => $codigo]
    );
    if ($id) return (int)$id;
    return vero_insert('centros_custo', [
        'codigo'    => $codigo,
        'nome'      => $nome,
        'descricao' => 'Centro de custo criado automaticamente pelo VERO',
        'ativo'     => 1,
    ]);
}

function vero_srv_centro_custo_mdo(): int
{
    return vero_srv_centro_custo('MDO', 'Mão de Obra');
}

/* ───────────────────────── Estoque ───────────────────────── */

/** Get-or-create do grupo padrão de produtos (estoque_produtos.grupo_id NOT NULL). */
function vero_srv_grupo_estoque_padrao(): int
{
    $id = vero_val("SELECT id FROM estoque_grupos WHERE tenant_id = :t AND nome = 'Insumos Agrícolas' LIMIT 1",
        [':t' => vero_tenant()]);
    if ($id) return (int)$id;
    return vero_insert('estoque_grupos', ['nome' => 'Insumos Agrícolas', 'tipo' => 'insumo', 'ativo' => 1]);
}

/** Get-or-create do almoxarifado padrão (saldos/movimentações exigem almoxarifado). */
function vero_srv_almox_padrao(): int
{
    $id = vero_val("SELECT id FROM almoxarifados WHERE tenant_id = :t AND ativo = 1 ORDER BY id LIMIT 1",
        [':t' => vero_tenant()]);
    if ($id) return (int)$id;
    return vero_insert('almoxarifados', ['nome' => 'Almoxarifado Central', 'tipo' => 'central', 'ativo' => 1]);
}

/** Linha de saldo do produto no almoxarifado (cria zerada se não existir). */
function vero_srv_estoque_saldo(int $produtoId, int $almoxId): array
{
    $s = vero_row("SELECT * FROM estoque_saldos WHERE tenant_id=:t AND produto_id=:p AND almoxarifado_id=:a",
        [':t' => vero_tenant(), ':p' => $produtoId, ':a' => $almoxId]);
    if ($s) return $s;
    vero_pdo()->prepare(
        "INSERT INTO estoque_saldos (tenant_id, produto_id, almoxarifado_id, quantidade, custo_medio, valor_total)
         VALUES (?,?,?,0,0,0)")->execute([vero_tenant(), $produtoId, $almoxId]);
    return vero_row("SELECT * FROM estoque_saldos WHERE tenant_id=:t AND produto_id=:p AND almoxarifado_id=:a",
        [':t' => vero_tenant(), ':p' => $produtoId, ':a' => $almoxId]);
}

/**
 * Entrada de estoque com custo médio ponderado. Se houver validade (perecível),
 * cria o LOTE correspondente (base do FEFO e dos alertas de vencimento).
 * Retorna o id da movimentação. Chamar dentro de transação.
 */
function vero_srv_estoque_entrada(int $produtoId, int $almoxId, float $qtd, float $custoUnit, string $data,
                                  ?string $origemTipo = null, ?int $origemId = null, ?string $obs = null,
                                  ?string $validade = null, ?int $fornecedorId = null): int
{
    if ($qtd <= 0) throw new RuntimeException('Quantidade de entrada deve ser maior que zero.');
    vero_srv_estoque_exigir_periodo_aberto($data); /* P-81 (EST-018) */
    $s = vero_srv_estoque_saldo($produtoId, $almoxId); /* get-or-create + id da linha */
    /* A12 (auditoria R3): incremento RELATIVO atômico — o saldo lido acima nunca é
       gravado de volta (evita lost update entre duas entradas/uma entrada+saída
       simultâneas). custo_medio é recalculado NO SQL sobre os valores já
       atualizados (MySQL avalia o SET da esquerda para a direita com o valor novo). */
    vero_pdo()->prepare(
        "UPDATE estoque_saldos
            SET quantidade = quantidade + ?,
                valor_total = valor_total + ?,
                custo_medio = IF(quantidade > 0, valor_total / quantidade, 0),
                atualizado_em = NOW()
          WHERE id = ? AND tenant_id = ?")
        ->execute([$qtd, round($qtd * $custoUnit, 2), (int)$s['id'], vero_tenant()]);

    $loteId = null;
    if ($validade !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $validade)) {
        $seq = (int)vero_val("SELECT COUNT(*) FROM estoque_lotes WHERE tenant_id=:t AND produto_id=:p",
            [':t' => vero_tenant(), ':p' => $produtoId]) + 1;
        vero_pdo()->prepare(
            "INSERT INTO estoque_lotes (tenant_id, produto_id, almoxarifado_id, codigo_lote, validade,
                                        fornecedor_id, custo_unitario, quantidade, created_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([vero_tenant(), $produtoId, $almoxId,
                       'L' . date('ymd') . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT),
                       $validade, $fornecedorId, $custoUnit, $qtd, vero_uid(), vero_uid()]);
        $loteId = (int)vero_pdo()->lastInsertId();
    }

    $movId = vero_insert('estoque_movimentacoes', [
        'produto_id' => $produtoId, 'almoxarifado_id' => $almoxId, 'lote_id' => $loteId, 'tipo' => 'entrada',
        'quantidade' => $qtd, 'custo_unitario' => $custoUnit, 'valor_total' => round($qtd * $custoUnit, 2),
        'origem_tipo' => $origemTipo, 'origem_id' => $origemId, 'observacao' => $obs,
        'data_movimento' => $data . ' 00:00:00',
    ]);
    vero_srv_estoque_reemitir_alertas($produtoId);
    return $movId;
}

/**
 * Saída de estoque ao custo médio, consumindo lotes por VALIDADE MAIS
 * PRÓXIMA primeiro (FEFO) — pedido do cliente para perecíveis: as
 * aplicações puxam automaticamente o lote de prazo mais curto.
 * T27a (A0, 05/07): $loteId APONTA um lote específico (não-FEFO — a carga
 * física tem lote; ex.: venda baixa o lote COLH-). Nesse modo o custo do
 * movimento é o CUSTO DO LOTE (CPV real — EST-012), valida saldo/status DO
 * lote e o rateio persistido é single-lote. Vencido/P-81 valem igual.
 * Lança RuntimeException se saldo insuficiente.
 * @return array{mov_id: int, custo_total: float, custo_unitario: float, lote_info: ?string}
 */
function vero_srv_estoque_saida(int $produtoId, int $almoxId, float $qtd, string $data,
                                ?string $origemTipo = null, ?int $origemId = null, ?string $obs = null,
                                ?int $safraTalhaoId = null, ?int $centroCustoId = null,
                                bool $permitirVencido = false, ?int $loteId = null): array
{
    if ($qtd <= 0) throw new RuntimeException('Quantidade de saída deve ser maior que zero.');
    vero_srv_estoque_exigir_periodo_aberto($data, $safraTalhaoId); /* P-81 (EST-018) */
    $s = vero_srv_estoque_saldo($produtoId, $almoxId);
    if ((float)$s['quantidade'] + 1e-9 < $qtd) {
        throw new RuntimeException('Saldo insuficiente em estoque (disponível: ' . (float)$s['quantidade'] . ').');
    }

    if ($loteId !== null) { /* T27a: lote apontado */
        $loteAp = vero_row(
            "SELECT * FROM estoque_lotes
              WHERE tenant_id = :t AND id = :i AND produto_id = :p AND almoxarifado_id = :a",
            [':t' => vero_tenant(), ':i' => $loteId, ':p' => $produtoId, ':a' => $almoxId]);
        if (!$loteAp) throw new RuntimeException('Lote inválido para este produto/almoxarifado.');
        $stLote = (string)($loteAp['status'] ?? 'disponivel');
        if (in_array($stLote, ['bloqueado', 'estornado'], true)) {
            throw new RuntimeException('Lote ' . $loteAp['codigo_lote'] . ' está "' . $stLote . '" — não pode ser consumido.');
        }
        if ((float)$loteAp['quantidade'] + 1e-9 < $qtd) {
            throw new RuntimeException('Saldo insuficiente no LOTE ' . $loteAp['codigo_lote']
                . ' (disponível: ' . (float)$loteAp['quantidade'] . ').');
        }
        $lotes = [$loteAp];
    } else {
        /* lotes carregados ANTES de qualquer mutação — o FEFO abaixo reusa */
        $lotes = vero_rows(
            "SELECT * FROM estoque_lotes
              WHERE tenant_id = :t AND produto_id = :p AND almoxarifado_id = :a AND quantidade > 0
              ORDER BY (validade IS NULL), validade, id",
            [':t' => vero_tenant(), ':p' => $produtoId, ':a' => $almoxId]);
    }

    /* P-23 (decisão do cliente 04/07, A0-10): saída que consumiria lote JÁ
       VENCIDO exige CONFIRMAÇÃO explícita ($permitirVencido=true, marcada
       pelo usuário na tela após decisão do RT). Simulação do FEFO sem mutar. */
    if (!$permitirVencido) {
        $hoje = date('Y-m-d');
        $simula = $qtd;
        $vencidosTocados = [];
        foreach ($lotes as $lote) {
            if ($simula <= 1e-9) break;
            $abateSim = min($simula, (float)$lote['quantidade']);
            if ($lote['validade'] !== null && (string)$lote['validade'] < $hoje) {
                $vencidosTocados[] = $lote['codigo_lote'] . ' (venc. '
                    . date('d/m/Y', strtotime((string)$lote['validade'])) . ')';
            }
            $simula -= $abateSim;
        }
        if ($vencidosTocados) {
            /* prefixo LOTE_VENCIDO: é CONTRATO com as telas (A2-F2-12a) — elas
               detectam pelo prefixo e oferecem a confirmação que reenvia com
               $permitirVencido=true. Não alterar sem A0. */
            throw new RuntimeException('LOTE_VENCIDO: esta saída consumiria lote(s) vencido(s): '
                . implode(', ', $vencidosTocados)
                . '. Confirme explicitamente o uso/descarte (decisão do RT) para prosseguir.');
        }
    }

    /* T27a: lote apontado sai ao CUSTO DO LOTE (CPV real); FEFO segue no médio */
    $custoUnit  = $loteId !== null ? (float)$lotes[0]['custo_unitario'] : (float)$s['custo_medio'];
    $custoTotal = round($qtd * $custoUnit, 2);
    /* A12 (auditoria R3): baixa do saldo ATÔMICA e condicional — decremento relativo
       em SQL + guard `quantidade >= :q`. Se outra transação consumiu o saldo entre a
       leitura (linha ~194) e aqui, o UPDATE afeta 0 linhas → lança e o chamador (que
       abre transação) reverte. Impede lost update / venda a descoberto sob concorrência.
       Placeholders nomeados distintos p/ o mesmo valor (HY093). */
    $stSaldo = vero_pdo()->prepare(
        "UPDATE estoque_saldos
            SET quantidade = quantidade - :q,
                valor_total = GREATEST(0, valor_total - :ct),
                atualizado_em = NOW()
          WHERE id = :id AND tenant_id = :t AND quantidade >= :q2");
    $stSaldo->execute([':q' => $qtd, ':ct' => $custoTotal, ':id' => (int)$s['id'], ':t' => vero_tenant(), ':q2' => $qtd]);
    if ($stSaldo->rowCount() !== 1) {
        throw new RuntimeException('Saldo insuficiente em estoque (consumido por outra operação simultânea). Refaça a saída.');
    }

    /* FEFO: abate dos lotes com validade mais próxima primeiro */
    $restante = $qtd;
    $primeiroLote = null;
    $consumidos = [];
    $rateio = []; /* [lote_id => quantidade] — persistido em estoque_movimentacao_lotes (DB-07) */
    foreach ($lotes as $lote) {
        if ($restante <= 1e-9) break;
        $abate = min($restante, (float)$lote['quantidade']);
        /* A12: decremento do lote também condicional (quantidade >= :ab) — se o lote
           foi consumido por outra transação, afeta 0 linhas → lança e reverte tudo. */
        $stLote = vero_pdo()->prepare(
            "UPDATE estoque_lotes SET quantidade = quantidade - :ab
              WHERE tenant_id = :t AND id = :id AND quantidade >= :ab2");
        $stLote->execute([':ab' => $abate, ':t' => vero_tenant(), ':id' => (int)$lote['id'], ':ab2' => $abate]);
        if ($stLote->rowCount() !== 1) {
            throw new RuntimeException('Saldo do lote insuficiente (consumido por outra operação simultânea). Refaça a saída.');
        }
        $restante -= $abate;
        $primeiroLote = $primeiroLote ?? (int)$lote['id'];
        $rateio[(int)$lote['id']] = $abate;
        $consumidos[] = $lote['codigo_lote']
            . ($lote['validade'] ? ' (val. ' . date('d/m/Y', strtotime((string)$lote['validade'])) . ')' : '');
    }

    $movId = vero_insert('estoque_movimentacoes', [
        'produto_id' => $produtoId, 'almoxarifado_id' => $almoxId, 'lote_id' => $primeiroLote, 'tipo' => 'saida',
        'quantidade' => $qtd, 'custo_unitario' => $custoUnit, 'valor_total' => $custoTotal,
        'origem_tipo' => $origemTipo, 'origem_id' => $origemId,
        'observacao' => $obs ?? ($consumidos
            ? ($loteId !== null ? 'Lote apontado: ' : 'FEFO: ') . implode(', ', $consumidos) : null),
        'safra_talhao_id' => $safraTalhaoId, 'centro_custo_id' => $centroCustoId,
        'data_movimento' => $data . ' 00:00:00',
    ]);
    /* rateio FEFO por lote persistido — o estorno devolve a cada lote exatamente
       o que dele saiu (DB-07; tabela sem auditoria → INSERT direto) */
    foreach ($rateio as $loteId => $qtdLote) {
        vero_pdo()->prepare(
            "INSERT INTO estoque_movimentacao_lotes (tenant_id, movimentacao_id, lote_id, quantidade)
             VALUES (?,?,?,?)")->execute([vero_tenant(), $movId, $loteId, $qtdLote]);
    }
    vero_srv_estoque_reemitir_alertas($produtoId);
    return ['mov_id' => $movId, 'custo_total' => $custoTotal, 'custo_unitario' => $custoUnit,
            'lote_info' => $consumidos ? implode(', ', $consumidos) : null];
}

/* dias de antecedência do aviso de vencimento (parametrização fina por produto: fase 2) */
const VERO_ESTOQUE_AVISO_VENCIMENTO_DIAS = 30;

/**
 * Reemite os alertas de estoque de um produto (idempotente):
 * - abaixo do estoque mínimo (parametrizado por produto em estoque_minimo);
 * - lotes vencidos (crítico) ou vencendo em até 30 dias (atenção).
 * Chamada automática em toda entrada/saída/estorno.
 */
function vero_srv_estoque_reemitir_alertas(int $produtoId): void
{
    $t = vero_tenant();
    $pdo = vero_pdo();

    $produto = vero_row("SELECT * FROM estoque_produtos WHERE id=:i AND tenant_id=:t",
        [':i' => $produtoId, ':t' => $t]);
    if (!$produto) return;

    $pdo->prepare("DELETE FROM agro_alertas
                    WHERE tenant_id=? AND categoria='estoque' AND origem_tipo='estoque_produto' AND origem_id=?")
        ->execute([$t, $produtoId]);
    $pdo->prepare("DELETE al FROM agro_alertas al
                    WHERE al.tenant_id=? AND al.categoria='estoque' AND al.origem_tipo='estoque_lote'
                      AND al.origem_id IN (SELECT id FROM estoque_lotes WHERE tenant_id=? AND produto_id=?)")
        ->execute([$t, $t, $produtoId]);

    $rotulo = ($produto['codigo'] ? $produto['codigo'] . ' — ' : '') . $produto['nome'];

    /* estoque mínimo */
    $saldo = (float)vero_val("SELECT COALESCE(SUM(quantidade),0) FROM estoque_saldos
                               WHERE tenant_id=:t AND produto_id=:p", [':t' => $t, ':p' => $produtoId]);
    $minimo = (float)$produto['estoque_minimo'];
    if ($minimo > 0 && $saldo < $minimo) {
        vero_insert('agro_alertas', [
            'categoria'   => 'estoque',
            'origem_tipo' => 'estoque_produto',
            'origem_id'   => $produtoId,
            'severidade'  => $saldo <= 0 ? 'critico' : 'atencao',
            'titulo'      => $rotulo . ' abaixo do estoque mínimo',
            'mensagem'    => 'Saldo atual ' . numFmt($saldo, 2) . ' ' . $produto['unidade']
                             . ' — mínimo parametrizado ' . numFmt($minimo, 2) . ' ' . $produto['unidade']
                             . '. Gere uma solicitação de compra.',
            'requer_validacao_tecnica' => 0,
            'status'      => 'aberto',
            'data'        => date('Y-m-d'),
        ]);
    }

    /* validade de lotes (perecíveis) */
    $limite = date('Y-m-d', strtotime('+' . VERO_ESTOQUE_AVISO_VENCIMENTO_DIAS . ' days'));
    $lotes = vero_rows(
        "SELECT * FROM estoque_lotes
          WHERE tenant_id = :t AND produto_id = :p AND quantidade > 0
            AND validade IS NOT NULL AND validade <= :lim",
        [':t' => $t, ':p' => $produtoId, ':lim' => $limite]);
    foreach ($lotes as $lote) {
        $vencido = (string)$lote['validade'] < date('Y-m-d');
        $dias = (int)floor((strtotime((string)$lote['validade']) - strtotime(date('Y-m-d'))) / 86400);
        vero_insert('agro_alertas', [
            'categoria'   => 'estoque',
            'origem_tipo' => 'estoque_lote',
            'origem_id'   => (int)$lote['id'],
            'severidade'  => $vencido ? 'critico' : 'atencao',
            'titulo'      => $rotulo . ' — lote ' . $lote['codigo_lote']
                             . ($vencido ? ' VENCIDO' : ' vence em ' . $dias . ' dia(s)'),
            'mensagem'    => numFmt((float)$lote['quantidade'], 2) . ' ' . $produto['unidade']
                             . ' com validade ' . date('d/m/Y', strtotime((string)$lote['validade']))
                             . '. FEFO já prioriza este lote nas aplicações'
                             . ($vencido ? ' — segregue e descarte conforme orientação do RT.' : '.'),
            'requer_validacao_tecnica' => $vencido ? 1 : 0,
            'status'      => 'aberto',
            'data'        => date('Y-m-d'),
        ]);
    }
}

/**
 * Estorna uma movimentação (devolve/retira o saldo) de forma AUDITÁVEL (DB-07):
 * marca a linha original (estornado_em/estornado_por) e grava um contra-movimento
 * vinculado por mov_ref_id — nada é apagado. Idempotente: linha já estornada é
 * ignorada. Devolução por lote usa o rateio FEFO persistido em
 * estoque_movimentacao_lotes; movimentos LEGADOS (sem rateio gravado) devolvem
 * ao lote_id registrado (primeiro lote), comportamento antigo documentado.
 * Assinatura preservada (contrato validado pelo A1 no P-19).
 */
function vero_srv_estoque_estornar_mov(array $mov): void
{
    $t = vero_tenant();
    $pdo = vero_pdo();

    /* idempotência do estorno lógico: nunca estornar duas vezes */
    $atual = vero_row("SELECT estornado_em FROM estoque_movimentacoes WHERE tenant_id=:t AND id=:i",
        [':t' => $t, ':i' => (int)$mov['id']]);
    if (!$atual || $atual['estornado_em'] !== null) return;
    /* P-81 (EST-018): estornar movimento de período FECHADO também altera o
       valor retroativamente — exige reabertura formal antes */
    vero_srv_estoque_exigir_periodo_aberto(substr((string)$mov['data_movimento'], 0, 10),
        isset($mov['safra_talhao_id']) ? (int)$mov['safra_talhao_id'] : null);

    $s = vero_srv_estoque_saldo((int)$mov['produto_id'], (int)$mov['almoxarifado_id']);
    /* A12 (auditoria R3): estorno em delta RELATIVO atômico. Estorno de SAÍDA
       devolve (soma, nunca falha); estorno de ENTRADA retira com guard
       `quantidade >= qtd` — se o estoque já foi consumido por outra operação
       concorrente, afeta 0 linhas e lança (mesma mensagem do fluxo anterior).
       custo_medio recalculado no SQL sobre os valores novos; saldo zerado
       mantém o custo anterior (comportamento preservado). */
    $qMov = (float)$mov['quantidade'];
    $vMov = (float)$mov['valor_total'];
    if ($mov['tipo'] === 'saida') {
        $pdo->prepare(
            "UPDATE estoque_saldos
                SET quantidade = quantidade + ?,
                    valor_total = valor_total + ?,
                    custo_medio = IF(quantidade > 0, valor_total / quantidade, custo_medio),
                    atualizado_em = NOW()
              WHERE id = ? AND tenant_id = ?")
            ->execute([$qMov, $vMov, (int)$s['id'], $t]);
    } else {
        $stSaldo = $pdo->prepare(
            "UPDATE estoque_saldos
                SET quantidade = quantidade - ?,
                    valor_total = GREATEST(0, valor_total - ?),
                    custo_medio = IF(quantidade > 0, valor_total / quantidade, custo_medio),
                    atualizado_em = NOW()
              WHERE id = ? AND tenant_id = ? AND quantidade >= ?")
        ;
        $stSaldo->execute([$qMov, $vMov, (int)$s['id'], $t, $qMov]);
        if ($stSaldo->rowCount() !== 1) {
            throw new RuntimeException('Estorno deixaria o saldo negativo.');
        }
    }

    /* devolução aos lotes: rateio persistido (exato) ou legado (lote registrado) */
    $rateio = vero_rows(
        "SELECT lote_id, quantidade FROM estoque_movimentacao_lotes
          WHERE tenant_id = :t AND movimentacao_id = :m",
        [':t' => $t, ':m' => (int)$mov['id']]);
    if ($rateio) {
        foreach ($rateio as $r) {
            $delta = $mov['tipo'] === 'saida' ? (float)$r['quantidade'] : -(float)$r['quantidade'];
            $pdo->prepare("UPDATE estoque_lotes SET quantidade = GREATEST(0, quantidade + ?) WHERE tenant_id = ? AND id = ?")
                ->execute([$delta, $t, (int)$r['lote_id']]);
        }
    } elseif ($mov['lote_id'] !== null) {
        $delta = $mov['tipo'] === 'saida' ? (float)$mov['quantidade'] : -(float)$mov['quantidade'];
        $pdo->prepare("UPDATE estoque_lotes SET quantidade = GREATEST(0, quantidade + ?) WHERE tenant_id = ? AND id = ?")
            ->execute([$delta, $t, (int)$mov['lote_id']]);
    }

    /* marca a original e grava o contra-movimento (ambos fora das visões ativas:
       filtrar estornado_em IS NULL; o par completo permanece na trilha) */
    $pdo->prepare("UPDATE estoque_movimentacoes SET estornado_em = NOW(), estornado_por = ? WHERE tenant_id = ? AND id = ?")
        ->execute([vero_uid(), $t, (int)$mov['id']]);
    $contraId = vero_insert('estoque_movimentacoes', [
        'produto_id' => (int)$mov['produto_id'], 'almoxarifado_id' => (int)$mov['almoxarifado_id'],
        'lote_id' => $mov['lote_id'] !== null ? (int)$mov['lote_id'] : null,
        'tipo' => $mov['tipo'] === 'saida' ? 'entrada' : 'saida',
        'quantidade' => (float)$mov['quantidade'], 'custo_unitario' => (float)$mov['custo_unitario'],
        'valor_total' => (float)$mov['valor_total'],
        'origem_tipo' => null, 'origem_id' => null,
        'mov_ref_id' => (int)$mov['id'],
        'observacao' => 'Estorno da movimentação #' . (int)$mov['id']
                        . ($mov['origem_tipo'] ? ' (' . $mov['origem_tipo'] . ' ' . $mov['origem_id'] . ')' : ''),
        'data_movimento' => date('Y-m-d H:i:s'),
    ]);
    $pdo->prepare("UPDATE estoque_movimentacoes SET estornado_em = NOW(), estornado_por = ?, mov_ref_id = ? WHERE tenant_id = ? AND id = ?")
        ->execute([vero_uid(), (int)$mov['id'], $t, $contraId]);
    $pdo->prepare("UPDATE estoque_movimentacoes SET mov_ref_id = ? WHERE tenant_id = ? AND id = ?")
        ->execute([$contraId, $t, (int)$mov['id']]);
    vero_srv_estoque_reemitir_alertas((int)$mov['produto_id']);
}

/**
 * Transferência entre almoxarifados PRESERVANDO o(s) lote(s) de origem
 * (Sprint Zero packing #2). Faz a saída FEFO na origem e recria no DESTINO os
 * mesmos lotes consumidos — codigo_lote, validade e colheita_registro_id
 * preservados; o almoxarifado é o destino, o que o UNIQUE uq_lotes permite
 * (a chave inclui almoxarifado_id). O custo entra ao custo_unitario da saída
 * (= custo médio da origem), preservando o valor TOTAL global do produto. O
 * rateio de lote da ENTRADA é persistido, então o estorno reverte por lote
 * (mesmo caminho da linha ~450). Chamar DENTRO de transação.
 * @return array{saida: array, entrada_mov_id: int, custo_unitario: float}
 */
function vero_srv_estoque_transferir(int $produtoId, int $origemId, int $destinoId, float $qtd,
                                     string $data, bool $permitirVencido = false): array
{
    $t = vero_tenant();
    $pdo = vero_pdo();

    /* 1) saída FEFO na origem (persiste o rateio origem em estoque_movimentacao_lotes) */
    $saida = vero_srv_estoque_saida($produtoId, $origemId, $qtd, $data, 'transferencia', null,
        'Transferência para outro almoxarifado', null, null, $permitirVencido);
    $custoUnit = (float)$saida['custo_unitario'];

    /* 2) lotes consumidos na saída — o rastreio a preservar no destino */
    $consumidos = vero_rows(
        "SELECT eml.quantidade AS qtd, el.codigo_lote, el.validade, el.colheita_registro_id, el.fornecedor_id
           FROM estoque_movimentacao_lotes eml
           JOIN estoque_lotes el ON el.id = eml.lote_id
          WHERE eml.tenant_id = :t AND eml.movimentacao_id = :m",
        [':t' => $t, ':m' => (int)$saida['mov_id']]);

    /* 3) saldo do destino: incremento RELATIVO atômico (mesmo padrão de vero_srv_estoque_entrada) */
    $sd = vero_srv_estoque_saldo($produtoId, $destinoId);
    $pdo->prepare(
        "UPDATE estoque_saldos
            SET quantidade = quantidade + ?, valor_total = valor_total + ?,
                custo_medio = IF(quantidade > 0, valor_total / quantidade, 0), atualizado_em = NOW()
          WHERE id = ? AND tenant_id = ?")
        ->execute([$qtd, round($qtd * $custoUnit, 2), (int)$sd['id'], $t]);

    /* 4) recria cada lote no destino (upsert por uq_lotes) e monta o rateio da entrada */
    $rateioEntrada = [];
    $primeiroLote  = null;
    foreach ($consumidos as $c) {
        $q = (float)$c['qtd'];
        $loteDestId = (int)(vero_val(
            "SELECT id FROM estoque_lotes
              WHERE tenant_id=:t AND produto_id=:p AND almoxarifado_id=:a AND codigo_lote=:c",
            [':t' => $t, ':p' => $produtoId, ':a' => $destinoId, ':c' => $c['codigo_lote']]) ?? 0);
        if ($loteDestId > 0) {
            $pdo->prepare("UPDATE estoque_lotes SET quantidade = quantidade + ?, updated_by = ? WHERE tenant_id = ? AND id = ?")
                ->execute([$q, vero_uid(), $t, $loteDestId]);
        } else {
            $pdo->prepare(
                "INSERT INTO estoque_lotes
                    (tenant_id, produto_id, almoxarifado_id, codigo_lote, validade, fornecedor_id,
                     colheita_registro_id, custo_unitario, quantidade, created_by, updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$t, $produtoId, $destinoId, $c['codigo_lote'], $c['validade'], $c['fornecedor_id'],
                           $c['colheita_registro_id'], $custoUnit, $q, vero_uid(), vero_uid()]);
            $loteDestId = (int)$pdo->lastInsertId();
        }
        $primeiroLote = $primeiroLote ?? $loteDestId;
        $rateioEntrada[$loteDestId] = ($rateioEntrada[$loteDestId] ?? 0.0) + $q;
    }

    /* 5) movimentação de entrada (audit) referenciando o lote destino */
    $movEntrada = vero_insert('estoque_movimentacoes', [
        'produto_id' => $produtoId, 'almoxarifado_id' => $destinoId, 'lote_id' => $primeiroLote, 'tipo' => 'entrada',
        'quantidade' => $qtd, 'custo_unitario' => $custoUnit, 'valor_total' => round($qtd * $custoUnit, 2),
        'origem_tipo' => 'transferencia', 'origem_id' => null,
        'observacao' => 'Transferência recebida (lote preservado)',
        'data_movimento' => $data . ' 00:00:00',
    ]);

    /* 6) rateio de lote da entrada → o estorno reverte por lote */
    foreach ($rateioEntrada as $lid => $q) {
        $pdo->prepare("INSERT INTO estoque_movimentacao_lotes (tenant_id, movimentacao_id, lote_id, quantidade) VALUES (?,?,?,?)")
            ->execute([$t, $movEntrada, (int)$lid, $q]);
    }

    /* 7) vincula o par (DB-07): destino na saída + mov_ref cruzado */
    $pdo->prepare("UPDATE estoque_movimentacoes SET almoxarifado_destino_id = ?, mov_ref_id = ? WHERE tenant_id = ? AND id = ?")
        ->execute([$destinoId, $movEntrada, $t, (int)$saida['mov_id']]);
    $pdo->prepare("UPDATE estoque_movimentacoes SET mov_ref_id = ? WHERE tenant_id = ? AND id = ?")
        ->execute([(int)$saida['mov_id'], $t, $movEntrada]);

    vero_srv_estoque_reemitir_alertas($produtoId);
    return ['saida' => $saida, 'entrada_mov_id' => $movEntrada, 'custo_unitario' => $custoUnit];
}

/**
 * Reemite os alertas de MANUTENÇÃO PREVENTIVA de uma máquina (A2-F2-4, DB-11).
 * Categoria 'maquinas' (dono A2 — contrato), origem_tipo 'maquina_plano',
 * idempotente por plano: apaga e reavalia todos os planos ativos da máquina.
 * Vence o que chegar primeiro: horas (horímetro) OU dias (calendário).
 * Chamar após: salvar/excluir plano, executar manutenção de plano,
 * atualizar horímetro (tela de horímetro e abastecimento).
 */
function vero_srv_maquina_reemitir_alertas(int $maquinaId): void
{
    $t = vero_tenant();
    $planosIds = array_column(vero_rows(
        "SELECT id FROM maquina_planos_manutencao WHERE tenant_id=:t AND maquina_id=:m",
        [':t' => $t, ':m' => $maquinaId]), 'id');
    if ($planosIds) {
        $marks = implode(',', array_fill(0, count($planosIds), '?'));
        vero_pdo()->prepare("DELETE FROM agro_alertas
                              WHERE tenant_id=? AND categoria='maquinas' AND origem_tipo='maquina_plano'
                                AND origem_id IN ({$marks})")
            ->execute(array_merge([$t], array_map('intval', $planosIds)));
    }

    $maq = vero_row("SELECT * FROM maquinas WHERE id=:i AND tenant_id=:t", [':i' => $maquinaId, ':t' => $t]);
    if (!$maq || (int)$maq['ativo'] !== 1) return;
    $rotuloMaq = $maq['codigo'] . ' — ' . $maq['nome'];

    $planos = vero_rows("SELECT * FROM maquina_planos_manutencao
                          WHERE tenant_id=:t AND maquina_id=:m AND ativo=1", [':t' => $t, ':m' => $maquinaId]);
    foreach ($planos as $pl) {
        $motivos = [];
        $critico = false;
        if ($pl['intervalo_horas'] !== null && (float)$pl['intervalo_horas'] > 0) {
            $alvo = (float)($pl['horimetro_ultima'] ?? 0) + (float)$pl['intervalo_horas'];
            $faltam = $alvo - (float)$maq['horimetro_atual'];
            $antecede = (float)($pl['antecedencia_horas'] ?? 0);
            if ($faltam <= 0) { $motivos[] = 'horímetro vencido há ' . numFmt(-$faltam, 1) . ' h (alvo ' . numFmt($alvo, 1) . ')'; $critico = true; }
            elseif ($faltam <= $antecede) { $motivos[] = 'faltam ' . numFmt($faltam, 1) . ' h para a revisão (alvo ' . numFmt($alvo, 1) . ')'; }
        }
        if ($pl['intervalo_dias'] !== null && (int)$pl['intervalo_dias'] > 0 && $pl['data_ultima'] !== null) {
            $alvoData = strtotime((string)$pl['data_ultima'] . ' +' . (int)$pl['intervalo_dias'] . ' days');
            $faltamD = (int)floor(($alvoData - strtotime(date('Y-m-d'))) / 86400);
            $antecedeD = (int)($pl['antecedencia_dias'] ?? 0);
            if ($faltamD <= 0) { $motivos[] = 'prazo vencido há ' . (-$faltamD) . ' dia(s) (' . date('d/m/Y', $alvoData) . ')'; $critico = true; }
            elseif ($faltamD <= $antecedeD) { $motivos[] = 'vence em ' . $faltamD . ' dia(s) (' . date('d/m/Y', $alvoData) . ')'; }
        }
        if (!$motivos) continue;
        vero_insert('agro_alertas', [
            'categoria'   => 'maquinas',
            'origem_tipo' => 'maquina_plano',
            'origem_id'   => (int)$pl['id'],
            'severidade'  => $critico ? 'critico' : 'atencao',
            'titulo'      => $rotuloMaq . ' — ' . $pl['descricao'] . ($critico ? ' VENCIDA' : ' próxima'),
            'mensagem'    => implode('; ', $motivos) . '. Abra a OS em Máquinas → Manutenções.',
            'requer_validacao_tecnica' => 0,
            'status'      => 'aberto',
            'data'        => date('Y-m-d'),
        ]);
    }
}

/* motivos aceitos no ajuste tipado (DB-07 — VARCHAR + validação em PHP) */
const VERO_ESTOQUE_MOTIVOS_AJUSTE = [
    'perda'              => 'Perda',
    'quebra'             => 'Quebra',
    'vencimento_descarte'=> 'Vencimento/Descarte',
    'roubo_extravio'     => 'Roubo/Extravio',
    'acerto_inventario'  => 'Acerto de inventário',
    'devolucao_campo'    => 'Devolução de campo',
    'outro'              => 'Outro',
];

/**
 * Ajuste tipado de estoque (DB-07, A2-F2-2): movimento `tipo='ajuste'` com
 * quantidade ASSINADA (delta) e motivo obrigatório. Positivo entra ao custo
 * médio atual (não altera o custo médio); negativo sai ao custo médio.
 * $loteId: ajusta AQUELE lote (inventário por lote, descarte de lote vencido);
 * sem lote e delta negativo → consome FEFO como uma saída. Rateio por lote
 * persistido em estoque_movimentacao_lotes (delta assinado) para estorno exato.
 * Retorna o id do movimento.
 */
function vero_srv_estoque_ajuste(int $produtoId, int $almoxId, float $delta, string $motivo, string $data,
                                 ?int $loteId = null, ?string $obs = null,
                                 ?string $origemTipo = null, ?int $origemId = null): int
{
    if (abs($delta) <= 1e-9) throw new RuntimeException('Ajuste com quantidade zero.');
    if (!isset(VERO_ESTOQUE_MOTIVOS_AJUSTE[$motivo])) throw new RuntimeException('Motivo de ajuste inválido.');
    vero_srv_estoque_exigir_periodo_aberto($data); /* P-81 (EST-018) */
    $t = vero_tenant();
    $pdo = vero_pdo();

    $s = vero_srv_estoque_saldo($produtoId, $almoxId);
    $novaQtd = (float)$s['quantidade'] + $delta;
    if ($novaQtd < -1e-9) {
        throw new RuntimeException('Ajuste deixaria o saldo negativo (disponível: ' . (float)$s['quantidade'] . ').');
    }
    $custoUnit  = (float)$s['custo_medio'];
    $valorDelta = round($delta * $custoUnit, 2);
    /* A12 (auditoria R3): delta RELATIVO atômico; redução leva guard
       `quantidade >= |delta|` — se outra operação consumiu o saldo entre a
       checagem acima e aqui, afeta 0 linhas → lança e o chamador (transação)
       reverte. custo_medio segue inalterado no ajuste (regra DB-07). */
    if ($delta < 0) {
        $stSaldo = $pdo->prepare(
            "UPDATE estoque_saldos
                SET quantidade = quantidade + ?, valor_total = GREATEST(0, valor_total + ?), atualizado_em = NOW()
              WHERE id = ? AND tenant_id = ? AND quantidade >= ?");
        $stSaldo->execute([$delta, $valorDelta, (int)$s['id'], $t, -$delta]);
        if ($stSaldo->rowCount() !== 1) {
            throw new RuntimeException('Saldo insuficiente em estoque (consumido por outra operação simultânea). Refaça o ajuste.');
        }
    } else {
        $pdo->prepare(
            "UPDATE estoque_saldos
                SET quantidade = quantidade + ?, valor_total = GREATEST(0, valor_total + ?), atualizado_em = NOW()
              WHERE id = ? AND tenant_id = ?")
            ->execute([$delta, $valorDelta, (int)$s['id'], $t]);
    }

    /* lotes */
    $rateio = []; /* [lote_id => delta assinado] */
    $movLoteId = $loteId;
    if ($loteId !== null) {
        $lote = vero_row("SELECT * FROM estoque_lotes
                           WHERE tenant_id=:t AND id=:l AND produto_id=:p AND almoxarifado_id=:a",
            [':t' => $t, ':l' => $loteId, ':p' => $produtoId, ':a' => $almoxId]);
        if (!$lote) throw new RuntimeException('Lote não pertence a este produto/almoxarifado.');
        if ((float)$lote['quantidade'] + $delta < -1e-9) {
            throw new RuntimeException('Ajuste deixaria o lote ' . $lote['codigo_lote'] . ' negativo.');
        }
        /* A12: redução do lote com guard `quantidade >= |delta|` (concorrência) */
        if ($delta < 0) {
            $stLote = $pdo->prepare("UPDATE estoque_lotes SET quantidade = quantidade + ?
                                      WHERE tenant_id=? AND id=? AND quantidade >= ?");
            $stLote->execute([$delta, $t, $loteId, -$delta]);
            if ($stLote->rowCount() !== 1) {
                throw new RuntimeException('Ajuste deixaria o lote ' . $lote['codigo_lote'] . ' negativo.');
            }
        } else {
            $pdo->prepare("UPDATE estoque_lotes SET quantidade = quantidade + ? WHERE tenant_id=? AND id=?")
                ->execute([$delta, $t, $loteId]);
        }
        $rateio[$loteId] = $delta;
    } elseif ($delta < 0) {
        /* redução sem lote indicado: consome FEFO */
        $restante = -$delta;
        $lotes = vero_rows("SELECT * FROM estoque_lotes
                             WHERE tenant_id=:t AND produto_id=:p AND almoxarifado_id=:a AND quantidade > 0
                             ORDER BY (validade IS NULL), validade, id",
            [':t' => $t, ':p' => $produtoId, ':a' => $almoxId]);
        foreach ($lotes as $lote) {
            if ($restante <= 1e-9) break;
            $abate = min($restante, (float)$lote['quantidade']);
            /* A12: guard como na saída — lote consumido por outra transação → lança */
            $stLote = $pdo->prepare("UPDATE estoque_lotes SET quantidade = quantidade - ?
                                      WHERE tenant_id=? AND id=? AND quantidade >= ?");
            $stLote->execute([$abate, $t, (int)$lote['id'], $abate]);
            if ($stLote->rowCount() !== 1) {
                throw new RuntimeException('Saldo do lote insuficiente (consumido por outra operação simultânea). Refaça o ajuste.');
            }
            $restante -= $abate;
            $rateio[(int)$lote['id']] = -$abate;
            $movLoteId = $movLoteId ?? (int)$lote['id'];
        }
    }

    $movId = vero_insert('estoque_movimentacoes', [
        'produto_id' => $produtoId, 'almoxarifado_id' => $almoxId, 'lote_id' => $movLoteId,
        'tipo' => 'ajuste', 'quantidade' => $delta, 'custo_unitario' => $custoUnit,
        'valor_total' => $valorDelta, 'motivo' => $motivo,
        'origem_tipo' => $origemTipo, 'origem_id' => $origemId, 'observacao' => $obs,
        'data_movimento' => $data . ' 00:00:00',
    ]);
    foreach ($rateio as $lid => $q) {
        $pdo->prepare("INSERT INTO estoque_movimentacao_lotes (tenant_id, movimentacao_id, lote_id, quantidade)
                       VALUES (?,?,?,?)")->execute([$t, $movId, $lid, $q]);
    }
    vero_srv_estoque_reemitir_alertas($produtoId);
    return $movId;
}

/**
 * Devolução de campo (DB-07, A2-F2-2): sobra de apontamento/aplicação volta ao
 * estoque AO CUSTO DA SAÍDA ORIGINAL, vinculada por mov_ref_id, limitada ao que
 * saiu menos devoluções anteriores. Devolve aos lotes pelo rateio FEFO da saída
 * (ordem inversa — devolve primeiro ao lote de validade mais distante).
 * Retorna o id do movimento de entrada.
 */
function vero_srv_estoque_devolucao_campo(int $movSaidaId, float $qtd, string $data, ?string $obs = null): int
{
    if ($qtd <= 0) throw new RuntimeException('Quantidade de devolução deve ser maior que zero.');
    $t = vero_tenant();
    $pdo = vero_pdo();

    $mov = vero_row("SELECT * FROM estoque_movimentacoes WHERE tenant_id=:t AND id=:i", [':t' => $t, ':i' => $movSaidaId]);
    if (!$mov || $mov['tipo'] !== 'saida' || $mov['estornado_em'] !== null) {
        throw new RuntimeException('Movimentação de saída inválida (ou já estornada) para devolução.');
    }
    $jaDevolvido = (float)vero_val(
        "SELECT COALESCE(SUM(quantidade),0) FROM estoque_movimentacoes
          WHERE tenant_id=:t AND origem_tipo='devolucao_campo' AND mov_ref_id=:m AND estornado_em IS NULL",
        [':t' => $t, ':m' => $movSaidaId]);
    $disponivel = (float)$mov['quantidade'] - $jaDevolvido;
    if ($qtd > $disponivel + 1e-9) {
        throw new RuntimeException('Devolução excede o disponível (' . $disponivel . ') desta saída.');
    }

    $custoUnit = (float)$mov['custo_unitario'];
    $s = vero_srv_estoque_saldo((int)$mov['produto_id'], (int)$mov['almoxarifado_id']);
    /* A12 (auditoria R3): devolução soma delta RELATIVO atômico (incremento nunca
       falha); custo_medio recalculado no SQL sobre os valores já atualizados. */
    $pdo->prepare(
        "UPDATE estoque_saldos
            SET quantidade = quantidade + ?,
                valor_total = valor_total + ?,
                custo_medio = IF(quantidade > 0, valor_total / quantidade, custo_medio),
                atualizado_em = NOW()
          WHERE id = ? AND tenant_id = ?")
        ->execute([$qtd, round($qtd * $custoUnit, 2), (int)$s['id'], $t]);

    /* devolve aos lotes do rateio da saída, ordem inversa (validade mais distante primeiro) */
    $rateioSaida = vero_rows(
        "SELECT ml.lote_id, ml.quantidade FROM estoque_movimentacao_lotes ml
          JOIN estoque_lotes l ON l.id = ml.lote_id
         WHERE ml.tenant_id = :t AND ml.movimentacao_id = :m
         ORDER BY (l.validade IS NULL) DESC, l.validade DESC, l.id DESC",
        [':t' => $t, ':m' => $movSaidaId]);
    $restante = $qtd;
    $rateioDev = [];
    foreach ($rateioSaida as $r) {
        if ($restante <= 1e-9) break;
        $volta = min($restante, abs((float)$r['quantidade']));
        $pdo->prepare("UPDATE estoque_lotes SET quantidade = quantidade + ? WHERE tenant_id=? AND id=?")
            ->execute([$volta, $t, (int)$r['lote_id']]);
        $rateioDev[(int)$r['lote_id']] = $volta;
        $restante -= $volta;
    }
    /* saída legada sem rateio: devolve ao lote registrado, se houver */
    if ($restante > 1e-9 && $mov['lote_id'] !== null && !$rateioSaida) {
        $pdo->prepare("UPDATE estoque_lotes SET quantidade = quantidade + ? WHERE tenant_id=? AND id=?")
            ->execute([$restante, $t, (int)$mov['lote_id']]);
        $rateioDev[(int)$mov['lote_id']] = $restante;
        $restante = 0.0;
    }

    $movId = vero_insert('estoque_movimentacoes', [
        'produto_id' => (int)$mov['produto_id'], 'almoxarifado_id' => (int)$mov['almoxarifado_id'],
        'lote_id' => $rateioDev ? (int)array_key_first($rateioDev) : ($mov['lote_id'] !== null ? (int)$mov['lote_id'] : null),
        'tipo' => 'entrada', 'quantidade' => $qtd, 'custo_unitario' => $custoUnit,
        'valor_total' => round($qtd * $custoUnit, 2), 'motivo' => 'devolucao_campo',
        'origem_tipo' => 'devolucao_campo', 'origem_id' => $movSaidaId, 'mov_ref_id' => $movSaidaId,
        'observacao' => $obs ?? ('Devolução de campo da saída #' . $movSaidaId
            . ($mov['origem_tipo'] ? ' (' . $mov['origem_tipo'] . ' ' . $mov['origem_id'] . ')' : '')),
        'data_movimento' => $data . ' 00:00:00',
    ]);
    foreach ($rateioDev as $lid => $q) {
        $pdo->prepare("INSERT INTO estoque_movimentacao_lotes (tenant_id, movimentacao_id, lote_id, quantidade)
                       VALUES (?,?,?,?)")->execute([$t, $movId, $lid, $q]);
    }
    vero_srv_estoque_reemitir_alertas((int)$mov['produto_id']);
    return $movId;
}

/* ───────────────────────── Emissão de custeio (idempotente) ───────────────────────── */

/**
 * Reemite os lançamentos de custeio dos itens de produção de um apontamento.
 * Idempotente: remove os lançamentos anteriores (origem rh_producao_item dos
 * itens do apontamento) e regrava a partir do estado atual dos itens.
 * Itens com valor 0 não geram lançamento.
 * Chamar DENTRO da transação que gravou os itens.
 */
function vero_srv_apontamento_reemitir_custeio(int $apontamentoId): void
{
    $pdo = vero_pdo();
    $t   = vero_tenant();

    $apont = vero_row(
        "SELECT a.*, st.safra_id, st.cultura_id
           FROM agro_apontamentos a
           LEFT JOIN agro_safra_talhoes st ON st.id = a.safra_talhao_id
          WHERE a.id = :id AND a.tenant_id = :t",
        [':id' => $apontamentoId, ':t' => $t]
    );
    if (!$apont) return;

    $itens = vero_rows(
        "SELECT id, quantidade, valor_total FROM rh_producao_itens
          WHERE tenant_id = :t AND apontamento_id = :a",
        [':t' => $t, ':a' => $apontamentoId]
    );

    /* limpa lançamentos de TODOS os itens (atuais e antigos) deste apontamento */
    $pdo->prepare(
        "DELETE cl FROM custeio_lancamentos cl
          WHERE cl.tenant_id = ? AND cl.origem_tipo = 'rh_producao_item'
            AND cl.origem_id IN (SELECT id FROM rh_producao_itens WHERE tenant_id = ? AND apontamento_id = ?)"
    )->execute([$t, $t, $apontamentoId]);

    $centro = vero_srv_centro_custo_mdo();
    $dataComp = substr((string)$apont['data_apontamento'], 0, 10);

    foreach ($itens as $item) {
        if ((float)$item['valor_total'] <= 0) continue;
        vero_insert('custeio_lancamentos', [
            'safra_id'        => $apont['safra_id'] !== null ? (int)$apont['safra_id'] : null,
            'safra_talhao_id' => $apont['safra_talhao_id'] !== null ? (int)$apont['safra_talhao_id'] : null,
            'talhao_id'       => $apont['talhao_id'] !== null ? (int)$apont['talhao_id'] : null, // talhão nullable (#3): apontamento sem válvula (packing) → custeio com talhao_id NULL (D6/RN-01; evita FK inválida id 0)
            'cultura_id'      => $apont['cultura_id'] !== null ? (int)$apont['cultura_id'] : null,
            'centro_custo_id' => $centro,
            'categoria'       => 'mao_de_obra',
            'plano_conta_id'  => custeio_plano_conta_id('rh_producao_item'),
            'origem_tipo'     => 'rh_producao_item',
            'origem_id'       => (int)$item['id'],
            'valor'           => (float)$item['valor_total'],
            'quantidade'      => (float)$item['quantidade'],
            'data_competencia'=> $dataComp,
        ]);
    }
}

/** Remove itens de produção e custeio de um apontamento (para edição/exclusão). */
function vero_srv_apontamento_limpar_itens(int $apontamentoId): void
{
    $pdo = vero_pdo();
    $t   = vero_tenant();
    $pdo->prepare(
        "DELETE cl FROM custeio_lancamentos cl
          WHERE cl.tenant_id = ? AND cl.origem_tipo = 'rh_producao_item'
            AND cl.origem_id IN (SELECT id FROM rh_producao_itens WHERE tenant_id = ? AND apontamento_id = ?)"
    )->execute([$t, $t, $apontamentoId]);
    $pdo->prepare("DELETE FROM rh_producao_itens WHERE tenant_id = ? AND apontamento_id = ?")
        ->execute([$t, $apontamentoId]);
}

/* ───────────────────────── Financeiro (razão com hash-chain) ─────────────────────────
   PADRÃO DO HASH (definido em 03/07/2026 — pendente de validação formal P-01):
   - hash_anterior = hash_atual do lançamento anterior do MESMO tenant (ordem por id);
   - hash_atual = SHA-256("tenant|tipo|valor|competencia|vencimento|descricao|origem_tipo|origem_id|hash_anterior")
     calculado NO MOMENTO DA CRIAÇÃO e nunca recalculado;
   - baixa/estorno de baixa NÃO recalculam o hash (status/datas ficam fora da fórmula;
     auditoria de mudanças em auth_audit_logs);
   - REEMISSÃO (DB-23, A0-04): se um campo SELADO muda (valor/competência/vencimento/
     descrição), a linha antiga é CANCELADA (status='cancelado', origem_ativa=NULL,
     substituida_por_id=nova) e uma NOVA linha encadeada é inserida com a mesma origem —
     o razão é genuinamente INSERT-only para valores e o verificador é determinístico;
   - campos da DB-21 (forma_pagamento, documento, parcela_num/total, grupo_id) ficam
     FORA da fórmula — a cadeia existente permanece válida;
   - a verificação da cadeia recomputa o hash com os campos atuais: divergência = campo
     alterado após a criação; elo quebrado = remoção/inserção fora de ordem. */

function vero_srv_fin_hash(array $d, ?string $hashAnterior): string
{
    return hash('sha256', implode('|', [
        (string)vero_tenant(), (string)$d['tipo'], number_format((float)$d['valor'], 2, '.', ''),
        (string)($d['data_competencia'] ?? ''), (string)($d['data_vencimento'] ?? ''),
        (string)($d['descricao'] ?? ''), (string)($d['origem_tipo'] ?? ''),
        (string)($d['origem_id'] ?? ''), (string)($hashAnterior ?? ''),
    ]));
}

/**
 * Lança (ou reemite) uma movimentação financeira de forma idempotente por origem.
 * Reemissão (DB-23): se já existe lançamento ATIVO para (origem_tipo, origem_id)
 * e nenhum campo selado mudou, só atualiza os campos fora do hash (forma de
 * pagamento etc.); se um campo selado mudou, CANCELA a linha antiga (lógico,
 * origem_ativa=NULL) e INSERE uma nova encadeada — valores nunca sofrem UPDATE.
 * Campos aceitos além dos selados (todos FORA do hash): status, forma_pagamento,
 * documento, parcela_num, parcela_total, grupo_id, safra_id, talhao_id,
 * plano_conta_id, centro_custo_id.
 * Chamar dentro de transação. Retorna o id da movimentação (nova, se reemitida).
 */
function vero_srv_fin_lancar(array $d): int
{
    $t   = vero_tenant();
    $pdo = vero_pdo();

    $foraDoHash = [];
    foreach (['forma_pagamento', 'documento', 'parcela_num', 'parcela_total', 'grupo_id'] as $campo) {
        if (array_key_exists($campo, $d)) $foraDoHash[$campo] = $d[$campo];
    }

    $substituida = null;
    if (!empty($d['origem_tipo']) && !empty($d['origem_id'])) {
        $exist = vero_row(
            "SELECT * FROM movimentacoes_financeiras
              WHERE tenant_id=:t AND origem_tipo=:ot AND origem_id=:oi AND origem_ativa = 1",
            [':t' => $t, ':ot' => $d['origem_tipo'], ':oi' => (int)$d['origem_id']]);
        if ($exist) {
            $seladoIgual =
                number_format((float)$exist['valor'], 2, '.', '') === number_format((float)$d['valor'], 2, '.', '')
                && (string)($exist['data_vencimento'] ?? '')  === (string)($d['data_vencimento'] ?? '')
                && (string)($exist['data_competencia'] ?? '') === (string)($d['data_competencia'] ?? '')
                && (string)($exist['descricao'] ?? '')        === (string)($d['descricao'] ?? '');
            if ($seladoIgual) {
                if ($foraDoHash) vero_update('movimentacoes_financeiras', (int)$exist['id'], $foraDoHash);
                return (int)$exist['id'];
            }
            /* campo selado mudou → cancela a antiga e insere nova encadeada (DB-23) */
            vero_update('movimentacoes_financeiras', (int)$exist['id'], [
                'status' => 'cancelado', 'origem_ativa' => null,
            ]);
            $substituida = $exist;
        }
    }

    /* trava o fim da cadeia do tenant para encadear com segurança */
    $st = $pdo->prepare(
        "SELECT hash_atual FROM movimentacoes_financeiras
          WHERE tenant_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $st->execute([$t]);
    $hashAnterior = $st->fetchColumn() ?: null;

    /* reemissão preserva o estado de baixa da linha substituída (fora do hash) */
    $status = $d['status'] ?? ($substituida !== null ? (string)$substituida['status'] : 'aberto');
    if ($status === 'cancelado') $status = 'aberto';

    $novaId = vero_insert('movimentacoes_financeiras', array_merge([
        'tipo'             => $d['tipo'],
        'status'           => $status,
        'valor'            => (float)$d['valor'],
        'data_competencia' => $d['data_competencia'] ?? null,
        'data_vencimento'  => $d['data_vencimento'] ?? null,
        'data_pagamento'   => $substituida !== null && $substituida['status'] === 'pago'
                              ? $substituida['data_pagamento'] : null,
        'descricao'        => $d['descricao'] ?? null,
        'origem_tipo'      => $d['origem_tipo'] ?? null,
        'origem_id'        => isset($d['origem_id']) ? (int)$d['origem_id'] : null,
        'origem_ativa'     => !empty($d['origem_tipo']) && !empty($d['origem_id']) ? 1 : null,
        'safra_id'         => isset($d['safra_id']) ? (int)$d['safra_id'] : ($substituida['safra_id'] ?? null),
        'talhao_id'        => isset($d['talhao_id']) ? (int)$d['talhao_id'] : ($substituida['talhao_id'] ?? null),
        'plano_conta_id'   => $d['plano_conta_id'] ?? ($substituida['plano_conta_id'] ?? null),
        'centro_custo_id'  => $d['centro_custo_id'] ?? ($substituida['centro_custo_id'] ?? null),
        'hash_anterior'    => $hashAnterior,
        'hash_atual'       => vero_srv_fin_hash($d, $hashAnterior),
    ], $foraDoHash));

    if ($substituida !== null) {
        vero_update('movimentacoes_financeiras', (int)$substituida['id'], ['substituida_por_id' => $novaId]);
    }
    return $novaId;
}

/**
 * Baixa (pagamento/recebimento) de uma movimentação: status pago + data.
 * Propaga para a origem quando conhecida (ex.: venda → status_pagamento).
 */
function vero_srv_fin_baixar(int $movId, string $dataPagamento): void
{
    $mov = vero_row("SELECT * FROM movimentacoes_financeiras WHERE id=:i AND tenant_id=:t",
        [':i' => $movId, ':t' => vero_tenant()]);
    if (!$mov) throw new RuntimeException('Movimentação inválida.');
    if ($mov['status'] === 'cancelado') throw new RuntimeException('Movimentação cancelada não pode ser baixada.');

    vero_update('movimentacoes_financeiras', $movId, [
        'status' => 'pago', 'data_pagamento' => $dataPagamento,
    ]);
    if ($mov['origem_tipo'] === 'comercial_venda' && $mov['origem_id'] !== null) {
        vero_update('comercial_vendas', (int)$mov['origem_id'], [
            'status_pagamento' => 'pago', 'data_pagamento' => $dataPagamento,
        ]);
    }
}

/** Estorno da baixa: volta para aberto (propaga para a origem). */
function vero_srv_fin_estornar_baixa(int $movId): void
{
    $mov = vero_row("SELECT * FROM movimentacoes_financeiras WHERE id=:i AND tenant_id=:t",
        [':i' => $movId, ':t' => vero_tenant()]);
    if (!$mov || $mov['status'] !== 'pago') throw new RuntimeException('Só movimentações pagas podem ser estornadas.');

    /* A3-T34 (FIN-03): forma_pagamento é dado DA BAIXA (DB-21, fora do hash) —
       estornar limpa junto, senão o título reaberto exibe forma que não houve */
    vero_update('movimentacoes_financeiras', $movId, [
        'status' => 'aberto', 'data_pagamento' => null, 'forma_pagamento' => null,
    ]);
    if ($mov['origem_tipo'] === 'comercial_venda' && $mov['origem_id'] !== null) {
        vero_update('comercial_vendas', (int)$mov['origem_id'], [
            'status_pagamento' => 'pendente', 'data_pagamento' => null,
        ]);
    }
}

/**
 * Casa um nutriente extraído (laudo IA/CSV) com o catálogo do tenant:
 * primeiro por símbolo exato, depois por nome (contido/contendo).
 * @param array $nutrientes linhas de analise_nutrientes (id, nome, simbolo)
 */
function vero_srv_casar_nutriente(array $nutrientes, string $simbolo, string $nome): ?int
{
    $simbolo = mb_strtolower(trim($simbolo));
    $nome    = mb_strtolower(trim($nome));
    foreach ($nutrientes as $n) {
        if ($simbolo !== '' && mb_strtolower((string)$n['simbolo']) === $simbolo) return (int)$n['id'];
    }
    foreach ($nutrientes as $n) {
        $nn = mb_strtolower((string)$n['nome']);
        if ($nome !== '' && ($nn === $nome || str_contains($nn, $nome) || str_contains($nome, $nn))) return (int)$n['id'];
    }
    return null;
}

/* ───────────────────────── Encargos CLT (D4: parametrizáveis por tenant) ───────────────────────── */

/** Configuração de encargos vigente na data (a mais recente com vigência ≤ data). */
function vero_srv_encargos_vigente(?string $data = null): ?array
{
    return vero_row(
        "SELECT * FROM rh_encargos_config
          WHERE tenant_id = :t AND ativo = 1 AND vigencia_inicio <= :d
          ORDER BY vigencia_inicio DESC LIMIT 1",
        [':t' => vero_tenant(), ':d' => $data ?? date('Y-m-d')]);
}

/**
 * Calcula cada encargo = bruto × pct (função pura).
 * Aceite validado (prints do sistema legado): bruto 1.664,00 → FGTS 133,12 /
 * INSS 332,80 / RAT 33,28 / Terceiros 96,51 / Férias 184,87 /
 * 13º 138,61 → total 919,19 (≈ 55,24%).
 * @return array{fgts: float, inss_patronal: float, rat: float, terceiros: float,
 *               ferias: float, decimo: float, outros: float, total: float, custo_total: float}
 */
function vero_srv_encargos_calc(float $bruto, array $cfg): array
{
    $calc = static fn(string $campo): float => round($bruto * (float)$cfg[$campo] / 100, 2);
    $e = [
        'fgts'          => $calc('fgts_pct'),
        'inss_patronal' => $calc('inss_patronal_pct'),
        'rat'           => $calc('rat_pct'),
        'terceiros'     => $calc('terceiros_pct'),
        'ferias'        => $calc('ferias_pct'),
        'decimo'        => $calc('decimo_pct'),
        'outros'        => $calc('outros_pct'),
    ];
    $e['total'] = round(array_sum($e), 2);
    $e['custo_total'] = round($bruto + $e['total'], 2);
    return $e;
}

/* ───────────────────────── Nutrição: faixas, classificação e alertas ─────────────────────────
   D5: faixas vêm do RT/laboratório via cadastro — o sistema NUNCA inventa
   faixa nem classifica sem faixa cadastrada (resultado fica "sem faixa"). */

/**
 * Faixa vigente para tipo (solo|foliar) × nutriente, preferindo a mais específica.
 * Opção B (mig 166): a fase POR VARIEDADE (variedade_fase_id) tem prioridade sobre a
 * fase por cultura (fenologia_id, fallback). Ordem de especificidade:
 *   variedade_fase > variedade+fenologia > variedade > fenologia > genérica.
 * Compat: sem variedade_fase_id na análise (:vf = 0), faixas com variedade_fase_id
 * preenchido não casam e o comportamento é idêntico ao modelo antigo.
 */
function vero_srv_faixa_para(string $tipo, int $nutrienteId, ?int $variedadeId, ?int $fenologiaId, ?int $variedadeFaseId = null): ?array
{
    return vero_row(
        "SELECT * FROM analise_faixas
          WHERE tenant_id = :t AND tipo = :tp AND nutriente_id = :n AND ativo = 1
            AND (variedade_id IS NULL OR variedade_id = :v)
            AND (fenologia_id IS NULL OR fenologia_id = :f)
            AND (variedade_fase_id IS NULL OR variedade_fase_id = :vf)
          ORDER BY (variedade_fase_id IS NULL), (variedade_id IS NULL), (fenologia_id IS NULL), id DESC
          LIMIT 1",
        [':t' => vero_tenant(), ':tp' => $tipo, ':n' => $nutrienteId,
         ':v' => $variedadeId ?? 0, ':f' => $fenologiaId ?? 0, ':vf' => $variedadeFaseId ?? 0]);
}

/** Classifica um valor contra a faixa (função pura). Null = faixa insuficiente. */
function vero_srv_classificar(float $valor, array $faixa): ?string
{
    $min  = $faixa['minimo']    !== null ? (float)$faixa['minimo']    : null;
    $iMin = $faixa['ideal_min'] !== null ? (float)$faixa['ideal_min'] : null;
    $iMax = $faixa['ideal_max'] !== null ? (float)$faixa['ideal_max'] : null;
    $max  = $faixa['maximo']    !== null ? (float)$faixa['maximo']    : null;
    if ($iMin === null || $iMax === null) return null; // faixa sem ideal não classifica

    if ($min !== null && $valor < $min)  return 'muito_baixo';
    if ($valor < $iMin)                  return 'baixo';
    if ($valor <= $iMax)                 return 'adequado';
    if ($max === null || $valor <= $max) return 'alto';
    return 'excessivo';
}

/**
 * Reclassifica os resultados de uma análise (solo|foliar) contra as faixas
 * e reemite os alertas (idempotente: remove os alertas anteriores da análise).
 * Alerta: muito_baixo/excessivo = crítico; baixo/alto = atenção; sempre
 * requer_validacao_tecnica = 1 (o sistema não recomenda, só sinaliza).
 */
function vero_srv_analise_classificar(string $tipo, int $analiseId): array
{
    $t = vero_tenant();
    $tabelaA = $tipo === 'solo' ? 'analise_solo' : 'analise_foliar';
    $tabelaR = $tipo === 'solo' ? 'analise_solo_resultados' : 'analise_foliar_resultados';

    $analise = vero_row("SELECT * FROM {$tabelaA} WHERE id=:i AND tenant_id=:t", [':i' => $analiseId, ':t' => $t]);
    if (!$analise) return ['classificados' => 0, 'sem_faixa' => 0, 'alertas' => 0];

    $variedadeId = $tipo === 'foliar' && $analise['variedade_id'] !== null ? (int)$analise['variedade_id'] : null;
    $fenologiaId = $tipo === 'foliar' && $analise['fenologia_id'] !== null ? (int)$analise['fenologia_id'] : null;
    /* Opção B (mig 166): fase POR VARIEDADE resolvida da amostra — prioritária na
       escolha da faixa; fenologia_id (cultura) segue como fallback. */
    $variedadeFaseId = $tipo === 'foliar' && ($analise['variedade_fase_id'] ?? null) !== null
        ? (int)$analise['variedade_fase_id'] : null;

    $pdo = vero_pdo();
    $pdo->prepare("DELETE FROM agro_alertas WHERE tenant_id=? AND categoria='nutricao' AND origem_tipo=? AND origem_id=?")
        ->execute([$t, $tabelaA, $analiseId]);

    $resultados = vero_rows(
        "SELECT r.*, n.nome AS nutriente_nome, n.simbolo
           FROM {$tabelaR} r JOIN analise_nutrientes n ON n.id = r.nutriente_id
          WHERE r.tenant_id = :t AND r.analise_id = :a",
        [':t' => $t, ':a' => $analiseId]);

    $cont = ['classificados' => 0, 'sem_faixa' => 0, 'alertas' => 0];
    foreach ($resultados as $r) {
        $faixa = vero_srv_faixa_para($tipo, (int)$r['nutriente_id'], $variedadeId, $fenologiaId, $variedadeFaseId);
        $classif = $faixa ? vero_srv_classificar((float)$r['valor'], $faixa) : null;
        $pdo->prepare("UPDATE {$tabelaR} SET classificacao=?, faixa_id=? WHERE tenant_id=? AND id=?")
            ->execute([$classif, $faixa ? (int)$faixa['id'] : null, $t, (int)$r['id']]);
        if ($classif === null) { $cont['sem_faixa']++; continue; }
        $cont['classificados']++;
        if (in_array($classif, ['muito_baixo', 'baixo', 'alto', 'excessivo'], true)) {
            $cont['alertas']++;
            $rotulo = str_replace('_', ' ', $classif);
            vero_insert('agro_alertas', [
                'categoria'    => 'nutricao',
                'origem_tipo'  => $tabelaA,
                'origem_id'    => $analiseId,
                'fazenda_id'   => $analise['fazenda_id'] !== null ? (int)$analise['fazenda_id'] : null,
                'talhao_id'    => $analise['talhao_id'] !== null ? (int)$analise['talhao_id'] : null,
                'safra_id'     => $analise['safra_id'] !== null ? (int)$analise['safra_id'] : null,
                'severidade'   => in_array($classif, ['muito_baixo', 'excessivo'], true) ? 'critico' : 'atencao',
                'titulo'       => ($r['simbolo'] ?: $r['nutriente_nome']) . ' ' . $rotulo
                                  . ' (análise ' . ($tipo === 'solo' ? 'de solo' : 'foliar') . ')',
                'mensagem'     => $r['nutriente_nome'] . ': ' . rtrim(rtrim(number_format((float)$r['valor'], 4, ',', '.'), '0'), ',')
                                  . ' ' . ($r['unidade'] ?? '') . ' — faixa ideal '
                                  . numFmt((float)$faixa['ideal_min'], 2) . ' a ' . numFmt((float)$faixa['ideal_max'], 2)
                                  . '. Sugestão pendente de validação do responsável técnico.',
                'requer_validacao_tecnica' => 1,
                'status'       => 'aberto',
                'data'         => (string)$analise['data_amostra'],
            ]);
        }
    }
    return $cont;
}

/* ───────────────────────── Compras: recebimento ─────────────────────────
   Fecha a amarração compra → estoque → contas a pagar:
   cada item com produto vira ENTRADA de estoque ao custo do recebimento;
   o total confirmado vira conta a PAGAR (origem compras_recebimento,
   idempotente pela unique de origem no razão). */

/**
 * Confirma um recebimento em rascunho: entradas de estoque, baixa das
 * quantidades no pedido, status do pedido e conta a pagar.
 * Chamar dentro de transação. Retorna ['valor' => float, 'no_estoque' => int].
 */
/**
 * Converte uma condição de pagamento em definição de parcelas (A0-09 / P-21).
 * Formatos aceitos (registro livre do pedido, ex.: "2x 30/60"):
 *   "à vista" | "" | null  → null (título único, comportamento atual)
 *   "30/60/90"             → 3 parcelas nesses dias após $dataBase
 *   "2x 30/60"             → dias explícitos (o "2x" é redundante)
 *   "3x30"                 → 3 parcelas mensais de 30 em 30 dias
 * Valores rateados em CENTAVOS (última parcela absorve a sobra — soma exata).
 * Retorna lista de ['vencimento' => Y-m-d, 'valor' => float] ou null.
 */
/* Condições de pagamento canônicas — a CHAVE é o valor gravado (formato
   aceito por vero_srv_parcelas_de_condicao); o rótulo é a exibição.
   Fonte única para Fornecedores e Pedidos de compra — campo NUNCA é aberto,
   senão a geração de parcelas cai em título único silenciosamente. */
const CONDICOES_PAGAMENTO = [
    'à vista'  => 'À vista',
    '30d'      => '30 dias',
    '28d'      => '28 dias',
    '30/60'    => '30/60 dias',
    '28/56'    => '28/56 dias',
    '30/60/90' => '30/60/90 dias',
    '28/56/84' => '28/56/84 dias',
];

function vero_srv_parcelas_de_condicao(?string $condicao, float $valorTotal, string $dataBase): ?array
{
    $condicao = mb_strtolower(trim((string)$condicao));
    if ($condicao === '' || str_contains($condicao, 'vista')) return null;

    $dias = [];
    if (preg_match('/^(\d{1,2})\s*x\s*(\d{1,3})$/', $condicao, $m)) {
        /* "3x30" → 30/60/90 */
        for ($i = 1; $i <= (int)$m[1]; $i++) $dias[] = (int)$m[2] * $i;
    } elseif (preg_match_all('/\d{1,3}/', preg_replace('/^\d{1,2}\s*x\s*/', '', $condicao), $m)
              && !empty($m[0])) {
        /* "2x 30/60" ou "30/60/90" → dias explícitos */
        $dias = array_map('intval', $m[0]);
    }
    $dias = array_values(array_unique(array_filter($dias, static fn($d) => $d > 0)));
    if (count($dias) < 2) return null; /* 0/1 parcela = título único */
    sort($dias);

    $n = count($dias);
    $centTotal = (int)round($valorTotal * 100);
    $centBase  = intdiv($centTotal, $n);
    $out = [];
    foreach ($dias as $i => $d) {
        $cent = $i === $n - 1 ? $centTotal - $centBase * ($n - 1) : $centBase;
        $out[] = [
            'vencimento' => date('Y-m-d', strtotime($dataBase . ' +' . $d . ' days')),
            'valor'      => $cent / 100,
        ];
    }
    return $out;
}

function vero_srv_compra_confirmar_recebimento(int $recebimentoId, ?string $vencimento,
                                               ?array $parcelasDef = null): array
{
    $t = vero_tenant();
    $pdo = vero_pdo();

    $rec = vero_row(
        "SELECT r.*, p.numero AS pedido_numero, p.id AS pid, f.nome AS fornecedor
           FROM compras_recebimentos r
           JOIN compras_pedidos p ON p.id = r.pedido_id
           JOIN fornecedores f ON f.id = p.fornecedor_id
          WHERE r.id = :i AND r.tenant_id = :t AND r.status = 'rascunho'",
        [':i' => $recebimentoId, ':t' => $t]);
    if (!$rec) throw new RuntimeException('Recebimento inválido ou já confirmado.');

    $itens = vero_rows(
        "SELECT ri.*, pi.quantidade AS qtd_pedida, pi.quantidade_recebida AS qtd_ja_recebida
           FROM compras_recebimento_itens ri
           JOIN compras_pedido_itens pi ON pi.id = ri.pedido_item_id
          WHERE ri.tenant_id = :t AND ri.recebimento_id = :r",
        [':t' => $t, ':r' => $recebimentoId]);
    if (!$itens) throw new RuntimeException('Recebimento sem itens.');

    $valor = 0.0;
    $noEstoque = 0;
    $dataMov = substr((string)$rec['data_recebimento'], 0, 10);
    foreach ($itens as $item) {
        $qtd   = (float)$item['quantidade'];
        $custo = (float)$item['custo_unitario'];
        if ($qtd <= 0) continue;
        $valor += round($qtd * $custo, 2);
        if ($item['produto_id'] !== null) {
            $fornecedorId = (int)vero_val("SELECT fornecedor_id FROM compras_pedidos WHERE id = :p AND tenant_id = :t",
                [':p' => (int)$rec['pid'], ':t' => $t]);
            vero_srv_estoque_entrada((int)$item['produto_id'], (int)$rec['almoxarifado_id'],
                $qtd, $custo, $dataMov, 'compras_recebimento', (int)$item['id'],
                'Recebimento ' . ($rec['numero'] ?? $recebimentoId) . ' — pedido ' . $rec['pedido_numero'],
                $item['validade'] !== null ? (string)$item['validade'] : null,
                $fornecedorId ?: null);
            $noEstoque++;
        }
        $pdo->prepare("UPDATE compras_pedido_itens SET quantidade_recebida = quantidade_recebida + ?
                        WHERE tenant_id = ? AND id = ?")
            ->execute([$qtd, $t, (int)$item['pedido_item_id']]);
    }
    if ($valor <= 0) throw new RuntimeException('Nenhuma quantidade recebida informada.');

    vero_update('compras_recebimentos', $recebimentoId, ['status' => 'confirmado']);
    /* A2-F2-3: o campo `tipo` do recebimento passa a refletir a realidade
       (o comentário antigo em recebimentos.php prometia isso e nada fazia) */

    /* status do pedido: recebido quando nada mais pendente */
    $pendentes = (int)vero_val(
        "SELECT COUNT(*) FROM compras_pedido_itens
          WHERE tenant_id = :t AND pedido_id = :p AND quantidade_recebida < quantidade - 0.0001",
        [':t' => $t, ':p' => (int)$rec['pid']]);
    vero_update('compras_pedidos', (int)$rec['pid'], [
        'status' => $pendentes === 0 ? 'recebido' : 'recebido_parcial',
    ]);
    vero_update('compras_recebimentos', $recebimentoId, [
        'tipo' => $pendentes === 0 ? 'total' : 'parcial',
    ]);

    /* ── contas a pagar (A0-09 / P-21): título único OU N parcelas ──
       Padrão idêntico à venda parcelada (A3-T14): a 1ª parcela carrega a
       ORIGEM (compras_recebimento — idempotência preservada); as demais
       nascem SEM origem, agrupadas por grupo_id = id da 1ª. Valores em
       centavos já rateados pelo chamador ($parcelasDef) — validamos a soma. */
    $descBase = 'Recebimento ' . ($rec['numero'] ?? $recebimentoId)
              . ' — pedido ' . $rec['pedido_numero'] . ' — ' . $rec['fornecedor'];

    if ($parcelasDef !== null && count($parcelasDef) >= 2) {
        $soma = 0.0;
        foreach ($parcelasDef as $p) $soma += (float)$p['valor'];
        if (abs($soma - round($valor, 2)) > 0.01) {
            throw new RuntimeException('Soma das parcelas (' . numFmt($soma, 2)
                . ') difere do total recebido (' . numFmt($valor, 2) . ').');
        }
        $total = count($parcelasDef);
        $grupoId = null;
        foreach (array_values($parcelasDef) as $i => $p) {
            $movId = vero_srv_fin_lancar([
                'tipo'             => 'pagar',
                'valor'            => round((float)$p['valor'], 2),
                'data_competencia' => $dataMov,
                'data_vencimento'  => $p['vencimento'] ?? $vencimento,
                'descricao'        => $descBase . ' (parcela ' . ($i + 1) . '/' . $total . ')',
                'origem_tipo'      => $i === 0 ? 'compras_recebimento' : null,
                'origem_id'        => $i === 0 ? $recebimentoId : null,
                'parcela_num'      => $i + 1,
                'parcela_total'    => $total,
                'grupo_id'         => $grupoId,
            ]);
            if ($i === 0) {
                $grupoId = $movId;
                vero_update('movimentacoes_financeiras', $movId, ['grupo_id' => $grupoId]);
            }
        }
    } else {
        vero_srv_fin_lancar([
            'tipo'             => 'pagar',
            'valor'            => round($valor, 2),
            'data_competencia' => $dataMov,
            'data_vencimento'  => $vencimento,
            'descricao'        => $descBase,
            'origem_tipo'      => 'compras_recebimento',
            'origem_id'        => $recebimentoId,
        ]);
    }

    return ['valor' => round($valor, 2), 'no_estoque' => $noEstoque];
}

/**
 * #33: ESTORNA um recebimento de compra CONFIRMADO (des-receber). Reverte as três
 * pontas que a confirmação criou: (1) entradas de estoque — pelo primitivo
 * vero_srv_estoque_estornar_mov, que JÁ bloqueia se o estoque foi consumido
 * ("saldo negativo") ou o período está fechado; (2) contas a pagar geradas —
 * canceladas (status='cancelado'; não afeta o hash-chain do razão, por desenho);
 * (3) quantidade_recebida dos itens do pedido + status do recebimento/pedido.
 * Guarda: recusa se qualquer conta a pagar já foi PAGA (baixada) — estornar a
 * baixa no Financeiro é pré-requisito. Transacional (rollback total em falha).
 */
function vero_srv_compra_estornar_recebimento(int $recebimentoId): array
{
    $t   = vero_tenant();
    $pdo = vero_pdo();

    $rec = vero_row(
        "SELECT r.*, p.id AS pid FROM compras_recebimentos r
           JOIN compras_pedidos p ON p.id = r.pedido_id
          WHERE r.id = :i AND r.tenant_id = :t AND r.status = 'confirmado'",
        [':i' => $recebimentoId, ':t' => $t]);
    if (!$rec) throw new RuntimeException('Recebimento não encontrado ou não está confirmado.');

    /* títulos a pagar do recebimento: o de ORIGEM + as parcelas do mesmo grupo */
    $origem = vero_row(
        "SELECT id, grupo_id FROM movimentacoes_financeiras
          WHERE tenant_id = :t AND origem_tipo = 'compras_recebimento' AND origem_id = :r LIMIT 1",
        [':t' => $t, ':r' => $recebimentoId]);
    $titulos = [];
    if ($origem) {
        $gid = (int)($origem['grupo_id'] ?: $origem['id']);
        $titulos = vero_rows(
            "SELECT id, status FROM movimentacoes_financeiras
              WHERE tenant_id = :t AND (id = :g OR grupo_id = :g2)", [':t' => $t, ':g' => $gid, ':g2' => $gid]);
    }
    foreach ($titulos as $tt) {
        if ((string)$tt['status'] === 'pago') {
            throw new RuntimeException('Há conta a pagar deste recebimento já PAGA/baixada — '
                . 'estorne a baixa no Financeiro antes de estornar o recebimento.');
        }
    }

    $pdo->beginTransaction();
    try {
        $itens = vero_rows(
            "SELECT id, pedido_item_id, quantidade, produto_id
               FROM compras_recebimento_itens WHERE tenant_id = :t AND recebimento_id = :r",
            [':t' => $t, ':r' => $recebimentoId]);
        foreach ($itens as $it) {
            if ($it['produto_id'] !== null) {
                /* estorna a(s) entrada(s) desta linha (throw se consumido/fechado) */
                $movs = vero_rows(
                    "SELECT * FROM estoque_movimentacoes
                      WHERE tenant_id = :t AND origem_tipo = 'compras_recebimento'
                        AND origem_id = :m AND estornado_em IS NULL",
                    [':t' => $t, ':m' => (int)$it['id']]);
                foreach ($movs as $mv) vero_srv_estoque_estornar_mov($mv);
            }
            $pdo->prepare("UPDATE compras_pedido_itens
                              SET quantidade_recebida = GREATEST(0, quantidade_recebida - ?)
                            WHERE tenant_id = ? AND id = ?")
                ->execute([(float)$it['quantidade'], $t, (int)$it['pedido_item_id']]);
        }

        foreach ($titulos as $tt) {
            vero_update('movimentacoes_financeiras', (int)$tt['id'], ['status' => 'cancelado']);
        }

        /* status 'cancelado' (enum não tem 'estornado'): sai da lista de confirmados */
        vero_update('compras_recebimentos', $recebimentoId, ['status' => 'cancelado']);

        /* pedido volta: se ainda restou algo recebido (outro recebimento), parcial; senão aprovado */
        $recebidoAinda = (int)vero_val(
            "SELECT COUNT(*) FROM compras_pedido_itens
              WHERE tenant_id = :t AND pedido_id = :p AND quantidade_recebida > 0.0001",
            [':t' => $t, ':p' => (int)$rec['pid']]);
        vero_update('compras_pedidos', (int)$rec['pid'],
            ['status' => $recebidoAinda > 0 ? 'recebido_parcial' : 'aprovado']);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['titulos' => count($titulos), 'itens' => count($itens)];
}

/* ───────────────────────── Insumos do apontamento ───────────────────────── */

/**
 * Grava um insumo do apontamento: linha em agro_apontamento_insumos +
 * baixa de estoque ao custo médio + lançamento de custeio (categoria insumos).
 * Chamar dentro da transação do apontamento. Lança exceção se saldo insuficiente.
 */
function vero_srv_apontamento_gravar_insumo(array $apont, int $produtoId, float $qtd, ?float $dose): void
{
    $t = vero_tenant();
    $pdo = vero_pdo();
    $pdo->prepare(
        "INSERT INTO agro_apontamento_insumos (tenant_id, apontamento_id, produto_id, quantidade, dose)
         VALUES (?,?,?,?,?)")
        ->execute([$t, (int)$apont['id'], $produtoId, $qtd, $dose]);
    $insumoId = (int)$pdo->lastInsertId();

    $data   = substr((string)$apont['data_apontamento'], 0, 10);
    $centro = vero_srv_centro_custo('INS', 'Insumos');
    $saida  = vero_srv_estoque_saida(
        $produtoId, vero_srv_almox_padrao(), $qtd, $data,
        'apontamento_insumo', $insumoId, null,
        $apont['safra_talhao_id'] !== null ? (int)$apont['safra_talhao_id'] : null, $centro
    );
    if ($saida['custo_total'] > 0) {
        vero_insert('custeio_lancamentos', [
            'safra_id'        => $apont['safra_id'] !== null ? (int)$apont['safra_id'] : null,
            'safra_talhao_id' => $apont['safra_talhao_id'] !== null ? (int)$apont['safra_talhao_id'] : null,
            'talhao_id'       => $apont['talhao_id'] !== null ? (int)$apont['talhao_id'] : null, // talhão nullable (#3): apontamento sem válvula (packing) → custeio com talhao_id NULL (D6/RN-01; evita FK inválida id 0)
            'cultura_id'      => $apont['cultura_id'] !== null ? (int)$apont['cultura_id'] : null,
            'centro_custo_id' => $centro,
            'categoria'       => 'insumos',
            'plano_conta_id'  => custeio_plano_conta_id('apontamento_insumo'),
            'origem_tipo'     => 'apontamento_insumo',
            'origem_id'       => $insumoId,
            'valor'           => $saida['custo_total'],
            'quantidade'      => $qtd,
            'data_competencia'=> $data,
        ]);
    }
}

/**
 * Remove os insumos de um apontamento devolvendo o saldo ao estoque e
 * apagando movimentações + custeio (idempotência da reedição/exclusão).
 */
function vero_srv_apontamento_limpar_insumos(int $apontamentoId): void
{
    $pdo = vero_pdo();
    $t   = vero_tenant();
    $insumos = vero_rows(
        "SELECT id FROM agro_apontamento_insumos WHERE tenant_id = :t AND apontamento_id = :a",
        [':t' => $t, ':a' => $apontamentoId]);
    foreach ($insumos as $ins) {
        $movs = vero_rows(
            "SELECT * FROM estoque_movimentacoes
              WHERE tenant_id = :t AND origem_tipo = 'apontamento_insumo' AND origem_id = :o
                AND estornado_em IS NULL",
            [':t' => $t, ':o' => (int)$ins['id']]);
        foreach ($movs as $mov) vero_srv_estoque_estornar_mov($mov);
        $pdo->prepare(
            "DELETE FROM custeio_lancamentos
              WHERE tenant_id = ? AND origem_tipo = 'apontamento_insumo' AND origem_id = ?")
            ->execute([$t, (int)$ins['id']]);
    }
    $pdo->prepare("DELETE FROM agro_apontamento_insumos WHERE tenant_id = ? AND apontamento_id = ?")
        ->execute([$t, $apontamentoId]);
}

/* ───────────────────────── Parâmetros por tenant (infra A0 — DB-08) ─────────────────────────
   tenant_parametros é o ÚNICO mecanismo de parametrização por tenant — proibido
   criar mecanismos paralelos (DECISIONS 04/07). Chave ausente = comportamento
   padrão do módulo (ex.: compras.alcada_valor ausente → todo pedido exige aprovação). */

/** Lê um parâmetro do tenant. Retorna $default se a chave não existir. */
function vero_srv_param(string $chave, ?string $default = null): ?string
{
    $v = vero_val("SELECT valor FROM tenant_parametros WHERE tenant_id = :t AND chave = :c",
        [':t' => vero_tenant(), ':c' => $chave]);
    return $v !== false && $v !== null ? (string)$v : $default;
}

/** Grava (upsert) um parâmetro do tenant. */
function vero_srv_param_set(string $chave, string $valor, ?string $descricao = null): void
{
    $id = vero_val("SELECT id FROM tenant_parametros WHERE tenant_id = :t AND chave = :c",
        [':t' => vero_tenant(), ':c' => $chave]);
    if ($id) {
        vero_update('tenant_parametros', (int)$id,
            $descricao !== null ? ['valor' => $valor, 'descricao' => $descricao] : ['valor' => $valor]);
    } else {
        vero_insert('tenant_parametros', ['chave' => $chave, 'valor' => $valor, 'descricao' => $descricao]);
    }
}

/* ───────────────────────── Guard de fechamento de safra (P-06) ─────────────────────────
   CRIADA no pacote A0-04; o ENFORCEMENT (chamada por todos os emissores de
   custeio) só entra com a tarefa A3-T6 após o cliente validar a P-06.
   Contrato: razão financeiro NÃO trava — só custeio. */

/**
 * Verifica se a safra aceita lançamento de custeio (bloqueia se o fechamento
 * da safra estiver com status 'fechado').
 * @return array{pode: bool, motivo: ?string}
 */
function vero_srv_custeio_pode_lancar(?int $safraId): array
{
    if ($safraId === null) return ['pode' => true, 'motivo' => null];
    $fech = vero_row(
        "SELECT f.*, s.identificacao AS safra_nome
           FROM custeio_fechamentos f
           JOIN agro_safras s ON s.id = f.safra_id
          WHERE f.tenant_id = :t AND f.safra_id = :s AND f.status = 'fechado'
          LIMIT 1",
        [':t' => vero_tenant(), ':s' => $safraId]);
    if (!$fech) return ['pode' => true, 'motivo' => null];
    return [
        'pode'   => false,
        'motivo' => 'Safra ' . $fech['safra_nome'] . ' fechada'
                    . ($fech['data_fechamento'] ? ' em ' . date('d/m/Y', strtotime((string)$fech['data_fechamento'])) : '')
                    . ' — reabra o fechamento para lançar custos.',
    ];
}

/* ───────────────────────── Máquinas no apontamento (arbitragem A0-03 / DB-13) ─────────────────────────
   Dono do FLUXO: A1 (bloco "Máquinas" em agro/apontamentos.php).
   Dono do SERVICE e do custo-hora: A2. Custeio origem 'apontamento_maquina'
   leva o custo de máquina ao talhão pela primeira vez. */

/**
 * Grava o uso de máquina em um apontamento: linha em agro_apontamento_maquinas
 * (com snapshot do custo-hora) + custeio idempotente categoria 'maquinas'.
 * $custoHora null ou 0 = custo desconhecido → grava as horas SEM custeio
 * (o sistema não inventa custo; o painel do A2 fornece o custo-hora efetivo).
 * Chamar dentro da transação do apontamento.
 */
function vero_srv_apontamento_gravar_maquina(array $apont, int $maquinaId, float $horas, ?float $custoHora): void
{
    if ($horas <= 0) throw new RuntimeException('Horas de máquina devem ser maiores que zero.');
    $t = vero_tenant();
    $pdo = vero_pdo();
    /* tabela sem colunas de auditoria → INSERT direto (regra 4 do DB_CONTRACT) */
    $pdo->prepare(
        "INSERT INTO agro_apontamento_maquinas (tenant_id, apontamento_id, maquina_id, horas, custo_hora)
         VALUES (?,?,?,?,?)")
        ->execute([$t, (int)$apont['id'], $maquinaId, $horas, $custoHora]);
    $usoId = (int)$pdo->lastInsertId();

    $custoTotal = $custoHora !== null ? round($horas * $custoHora, 2) : 0.0;
    if ($custoTotal > 0) {
        vero_insert('custeio_lancamentos', [
            'safra_id'        => $apont['safra_id'] !== null ? (int)$apont['safra_id'] : null,
            'safra_talhao_id' => $apont['safra_talhao_id'] !== null ? (int)$apont['safra_talhao_id'] : null,
            'talhao_id'       => $apont['talhao_id'] !== null ? (int)$apont['talhao_id'] : null, // talhão nullable (#3): apontamento sem válvula (packing) → custeio com talhao_id NULL (D6/RN-01; evita FK inválida id 0)
            'cultura_id'      => $apont['cultura_id'] !== null ? (int)$apont['cultura_id'] : null,
            'centro_custo_id' => vero_srv_centro_custo('MAQ', 'Máquinas'),
            'categoria'       => 'maquinas',
            /* 'apontamento_maquina' ainda sem entrada no mapa → cai no 3.99;
               A3 decide a conta na revisão do mapa (nota da auditoria) */
            'plano_conta_id'  => custeio_plano_conta_id('apontamento_maquina'),
            'origem_tipo'     => 'apontamento_maquina',
            'origem_id'       => $usoId,
            'valor'           => $custoTotal,
            'quantidade'      => $horas,
            'data_competencia'=> substr((string)$apont['data_apontamento'], 0, 10),
        ]);
    }
}

/** Remove os usos de máquina de um apontamento + custeio (reedição/exclusão). */
function vero_srv_apontamento_limpar_maquinas(int $apontamentoId): void
{
    $pdo = vero_pdo();
    $t   = vero_tenant();
    $pdo->prepare(
        "DELETE cl FROM custeio_lancamentos cl
          WHERE cl.tenant_id = ? AND cl.origem_tipo = 'apontamento_maquina'
            AND cl.origem_id IN (SELECT id FROM agro_apontamento_maquinas WHERE tenant_id = ? AND apontamento_id = ?)"
    )->execute([$t, $t, $apontamentoId]);
    $pdo->prepare("DELETE FROM agro_apontamento_maquinas WHERE tenant_id = ? AND apontamento_id = ?")
        ->execute([$t, $apontamentoId]);
}

/* ───────────────────────── Numeração de documentos DF/IF (A0-07 / P-46) ─────────────────────────
   DF e IF são a MESMA figura (OS de aplicação): pulverizada → DF,
   fertirrigação → IF. Numeração sequencial POR FAZENDA e por série
   (decisão do cliente, P-46), atômica via GET_LOCK — nunca COUNT+1.
   Documento numerado NUNCA é apagado (cancelamento lógico mantém o
   número; furos na sequência são auditáveis e esperados). */

/**
 * Próximo número de documento de aplicação para (fazenda, série).
 * $serie: 'DF' (pulverizacao/tratamento/indutor_brotacao/foliar/outro)
 * ou 'IF' (fertirrigacao). Chamar DENTRO da transação da emissão.
 */
function vero_srv_doc_numero(int $fazendaId, string $serie): int
{
    if (!in_array($serie, ['DF', 'IF'], true)) {
        throw new RuntimeException('Série de documento inválida (use DF ou IF).');
    }
    $t = vero_tenant();
    $pdo = vero_pdo();
    $chave = "vero_doc_{$t}_{$fazendaId}_{$serie}";
    $st = $pdo->prepare("SELECT GET_LOCK(?, 5)");
    $st->execute([$chave]);
    if ((int)$st->fetchColumn() !== 1) {
        throw new RuntimeException('Não foi possível obter o lock de numeração — tente novamente.');
    }
    try {
        $max = (int)vero_val(
            "SELECT COALESCE(MAX(doc_numero), 0) FROM agro_aplicacoes
              WHERE tenant_id = :t AND fazenda_id = :f AND doc_serie = :s",
            [':t' => $t, ':f' => $fazendaId, ':s' => $serie]);
        return $max + 1;
    } finally {
        $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$chave]);
    }
}

/** Série do documento pelo tipo da aplicação (fertirrigacao → IF; demais → DF). */
function vero_srv_doc_serie_por_tipo(string $tipoAplicacao): string
{
    return $tipoAplicacao === 'fertirrigacao' ? 'IF' : 'DF';
}

/* ───────────────────────── Carência informada (A1-19 / DB-18) ─────────────────────────
   Regra 1 inviolável: a carência vem da BULA registrada pelo RT no item da
   aplicação (agro_aplicacao_itens.carencia_dias) — o sistema NUNCA sugere
   valor; só confronta datas registradas e SINALIZA. */

/**
 * Carências ativas de um talhão na data: aplicações não canceladas cujo
 * período de carência (data da aplicação + carencia_dias do item) alcança a
 * data consultada. Base do alerta de colheita (categoria 'residuo',
 * origem_tipo 'colheita_carencia' — dono A1).
 * @return array<int, array{aplicacao_id:int, data_aplicacao:string, produto:string,
 *                          carencia_dias:int, liberado_em:string, dias_restantes:int}>
 */
function vero_srv_talhao_carencias(int $talhaoId, string $data): array
{
    $rows = vero_rows(
        "SELECT a.id AS aplicacao_id, a.data AS data_aplicacao, i.carencia_dias,
                COALESCE(p.nome, i.ingrediente_ativo, 'item da aplicação') AS produto,
                DATE_ADD(a.data, INTERVAL i.carencia_dias DAY) AS liberado_em
           FROM agro_aplicacoes a
           JOIN agro_aplicacao_itens i ON i.aplicacao_id = a.id AND i.tenant_id = a.tenant_id
           LEFT JOIN estoque_produtos p ON p.id = i.produto_id
          WHERE a.tenant_id = :t AND a.talhao_id = :ta
            AND a.status NOT IN ('cancelada', 'rascunho')
            AND i.carencia_dias IS NOT NULL AND i.carencia_dias > 0
            AND DATE_ADD(a.data, INTERVAL i.carencia_dias DAY) >= :d
          ORDER BY liberado_em DESC",
        [':t' => vero_tenant(), ':ta' => $talhaoId, ':d' => $data]);
    foreach ($rows as &$r) {
        $r['dias_restantes'] = (int)floor((strtotime((string)$r['liberado_em']) - strtotime($data)) / 86400);
    }
    return $rows;
}


/* ============================================================
   MOTOR DE CUSTO DE PRODUÇÃO (A0-13, Fase 1 — 05/07/2026)
   Fórmulas do §5 de docs/VERO_CUSTO_PRODUCAO_SPEC.md em um único
   lugar. Funções PURAS de cálculo + validação de mapa_realizado
   (anti-duplicação). CRUD/telas são do A3 (vero_crud); nenhum
   service aqui grava. Campos categóricos são VARCHAR no banco —
   estas listas são a validação PHP obrigatória (regra permanente).
   ============================================================ */

const VERO_CUSTO_METODOS = ['manual_ha', 'valor_total_area', 'quantidade_valor_unitario',
    'maquina_hora', 'estoque_consumo', 'compra_recebida', 'folha_rateada',
    'patrimonio_depreciacao', 'percentual'];
const VERO_CUSTO_ORIGENS_ITEM = ['custeio', 'manual', 'compra'];
const VERO_CUSTO_TIPOS_GRUPO = ['variavel', 'fixo', 'operacional'];
const VERO_CUSTO_STATUS_ORCAMENTO = ['rascunho', 'aprovado', 'em_execucao', 'fechado', 'cancelado'];
const VERO_CUSTO_TIPOS_CICLO = ['anual', 'perene'];

/**
 * Previsto R$/ha de UM item de orçamento (linha de agro_custo_orcamento_itens
 * já com metodo_calculo do item). Método 'percentual' retorna 0.0 aqui — é
 * resolvido em vero_srv_custo_indicadores (precisa das bases; percentual não
 * referencia percentual). quantidade_prevista é POR HECTARE nos métodos
 * quantidade×unitário e hora de máquina.
 */
function vero_srv_custo_item_previsto_ha(array $linha, float $areaHa): float
{
    $metodo = (string)($linha['metodo_calculo'] ?? 'manual_ha');
    switch ($metodo) {
        case 'quantidade_valor_unitario':
        case 'maquina_hora':
            return (float)($linha['quantidade_prevista'] ?? 0) * (float)($linha['valor_unitario_previsto'] ?? 0);
        case 'valor_total_area':
            return $areaHa > 0 ? (float)($linha['valor_previsto_total'] ?? 0) / $areaHa : 0.0;
        case 'percentual':
            return 0.0;
        default: /* manual_ha, estoque_consumo, compra_recebida, folha_rateada, patrimonio_depreciacao */
            return (float)($linha['valor_previsto_ha'] ?? 0);
    }
}

/**
 * Indicadores do orçamento (§5 do SPEC). Entrada: cabeçalho
 * ['area_ha','produtividade_prevista_ha','preco_previsto_unidade'] + linhas
 * (join orcamento_itens × custo_itens × grupos: precisa de item_id, nome,
 * grupo_id, grupo_nome, grupo_tipo, metodo_calculo, percentual, percentual_base
 * e os campos de valor). Duas passadas: itens não-percentuais formam a base;
 * percentuais aplicam sobre grupo ou total (ordem topológica trivial).
 * Divisões por zero retornam NULL no indicador (tela mostra "—", nunca INF).
 */
function vero_srv_custo_indicadores(array $cab, array $linhas): array
{
    $area = (float)($cab['area_ha'] ?? 0);
    $prod = (float)($cab['produtividade_prevista_ha'] ?? 0);
    $preco = (float)($cab['preco_previsto_unidade'] ?? 0);

    $itens = [];
    $grupos = [];
    $basePorGrupo = [];
    $baseTotal = 0.0;
    foreach ($linhas as $l) {
        $ha = vero_srv_custo_item_previsto_ha($l, $area);
        $gid = (int)($l['grupo_id'] ?? 0);
        $itens[(int)$l['item_id']] = [
            'nome' => (string)($l['nome'] ?? ''), 'grupo_id' => $gid,
            'metodo' => (string)($l['metodo_calculo'] ?? ''), 'previsto_ha' => $ha,
        ];
        if (!isset($grupos[$gid])) {
            $grupos[$gid] = ['nome' => (string)($l['grupo_nome'] ?? ''),
                'tipo' => (string)($l['grupo_tipo'] ?? 'variavel'), 'previsto_ha' => 0.0];
        }
        if ((string)($l['metodo_calculo'] ?? '') !== 'percentual') {
            $basePorGrupo[$gid] = ($basePorGrupo[$gid] ?? 0.0) + $ha;
            $baseTotal += $ha;
        }
    }
    foreach ($linhas as $l) { /* 2ª passada: percentuais sobre a base */
        if ((string)($l['metodo_calculo'] ?? '') !== 'percentual') continue;
        $gid = (int)($l['grupo_id'] ?? 0);
        $base = ((string)($l['percentual_base'] ?? 'total')) === 'grupo' ? ($basePorGrupo[$gid] ?? 0.0) : $baseTotal;
        $itens[(int)$l['item_id']]['previsto_ha'] = $base * (float)($l['percentual'] ?? 0) / 100.0;
    }

    $custoHa = 0.0;
    $tipos = ['variavel' => 0.0, 'fixo' => 0.0, 'operacional' => 0.0];
    foreach ($itens as $it) {
        $custoHa += $it['previsto_ha'];
        $grupos[$it['grupo_id']]['previsto_ha'] += $it['previsto_ha'];
    }
    foreach ($grupos as $g) $tipos[$g['tipo']] = ($tipos[$g['tipo']] ?? 0.0) + $g['previsto_ha'];
    foreach ($itens as &$it) $it['participacao_pct'] = $custoHa > 0 ? round($it['previsto_ha'] / $custoHa * 100, 2) : 0.0;
    unset($it);
    foreach ($grupos as &$g) $g['participacao_pct'] = $custoHa > 0 ? round($g['previsto_ha'] / $custoHa * 100, 2) : 0.0;
    unset($g);

    $receitaHa = $prod * $preco;
    return [
        'itens' => $itens, 'grupos' => $grupos, 'tipos' => $tipos,
        'custo_total_ha' => $custoHa,
        'custo_total_area' => $custoHa * $area,
        'custo_por_unidade' => $prod > 0 ? $custoHa / $prod : null,
        'custo_equivalente' => $preco > 0 ? $custoHa / $preco : null, /* "unidades p/ pagar a lavoura" */
        'receita_bruta_ha' => $receitaHa,
        'margem_ha' => $receitaHa - $custoHa,
        'margem_pct' => $receitaHa > 0 ? ($receitaHa - $custoHa) / $receitaHa * 100 : null,
        'produtividade_equilibrio' => $preco > 0 ? $custoHa / $preco : null,
        'preco_equilibrio' => $prod > 0 ? $custoHa / $prod : null,
    ];
}

/**
 * Conflitos de mapa_realizado entre itens ATIVOS de uma metodologia
 * (anti-duplicação, DECISIONS 05/07): sobreposição EXATA (mesmo mapa
 * normalizado) = a tela BLOQUEIA a gravação; PARCIAL (compartilham ≥1
 * origem com mapas diferentes) = aviso na configuração + relatório F2.
 * @return array<int, array{tipo:string, item_a:string, item_b:string}>
 */
function vero_srv_custo_mapa_conflitos(int $metodologiaId): array
{
    $rows = vero_rows(
        "SELECT i.id, i.nome, i.mapa_realizado
           FROM agro_custo_itens i
           JOIN agro_custo_grupos g ON g.id = i.grupo_id
          WHERE i.tenant_id = :t AND g.metodologia_id = :m AND i.ativo = 1 AND g.ativo = 1
            AND i.mapa_realizado IS NOT NULL",
        [':t' => vero_tenant(), ':m' => $metodologiaId]);
    $mapas = [];
    foreach ($rows as $r) {
        $m = json_decode((string)$r['mapa_realizado'], true);
        if (!is_array($m)) continue;
        foreach (['origens', 'categorias', 'planos'] as $k) {
            $m[$k] = array_values(array_unique(array_map('strval', (array)($m[$k] ?? []))));
            sort($m[$k]);
        }
        $mapas[] = ['nome' => (string)$r['nome'], 'chave' => json_encode([$m['origens'], $m['categorias'], $m['planos']]), 'origens' => $m['origens']];
    }
    $conflitos = [];
    $n = count($mapas);
    for ($a = 0; $a < $n; $a++) {
        for ($b = $a + 1; $b < $n; $b++) {
            if ($mapas[$a]['chave'] === $mapas[$b]['chave']) {
                $conflitos[] = ['tipo' => 'exata', 'item_a' => $mapas[$a]['nome'], 'item_b' => $mapas[$b]['nome']];
            } elseif ($mapas[$a]['origens'] && array_intersect($mapas[$a]['origens'], $mapas[$b]['origens'])) {
                $conflitos[] = ['tipo' => 'parcial', 'item_a' => $mapas[$a]['nome'], 'item_b' => $mapas[$b]['nome']];
            }
        }
    }
    return $conflitos;
}


/* ============================================================
   CÓDIGO DE PRODUTO — 6 DÍGITOS (A0-14, 05/07/2026)
   ============================================================ */

/**
 * Próximo código de produto do tenant: sequencial numérico zero-padded
 * ("000004"). GET_LOCK evita duas sugestões iguais em criações
 * simultâneas (mesmo padrão de vero_srv_doc_numero); ainda assim a
 * garantia final é a UNIQUE (tenant, codigo) — o chamador trata
 * duplicidade re-pedindo o código. Validação de formato nas telas:
 * ^[0-9]{6}$ (coluna VARCHAR(6) desde a migration 141).
 */
function vero_srv_produto_proximo_codigo(): string
{
    $pdo = vero_pdo();
    $t = vero_tenant();
    $lock = 'vero_prod_cod_' . $t;
    $st = $pdo->prepare("SELECT GET_LOCK(:l, 5)");
    $st->execute([':l' => $lock]);
    if ((int)$st->fetchColumn() !== 1) {
        throw new RuntimeException('Não foi possível obter o lock de numeração de produto.');
    }
    try {
        $max = (int)vero_val(
            "SELECT COALESCE(MAX(CAST(codigo AS UNSIGNED)),0) FROM estoque_produtos
              WHERE tenant_id = :t AND codigo REGEXP '^[0-9]{6}\$'", [':t' => $t]);
        $prox = $max + 1;
        if ($prox > 999999) {
            throw new RuntimeException('Faixa de códigos de 6 dígitos esgotada (999999).');
        }
        return str_pad((string)$prox, 6, '0', STR_PAD_LEFT);
    } finally {
        $pdo->prepare("SELECT RELEASE_LOCK(:l)")->execute([':l' => $lock]);
    }
}


/* ============================================================
   TRAVA DE PERÍODO DO ESTOQUE (P-81 aprovada 05/07 — EST-018)
   Fonte de verdade = custeio_fechamentos (a MESMA do custeio):
   reabertura formal já existe (custos → Fechamento → reabrir).
   ============================================================ */

/**
 * Movimento de estoque respeita fechamento (P-81, opção A do cliente):
 * 1. safra VINCULADA fechada → bloqueia (mesma trava do custeio);
 * 2. sem vínculo → bloqueia se a data ≤ MAIOR data_fechamento entre as
 *    safras com status 'fechado' (corte de período; reabrir a safra
 *    remove o corte dela). Limitação registrada: com safras
 *    SOBREPOSTAS no tempo, o corte por data pode bloquear movimento
 *    legítimo de safra aberta — vincule a safra no movimento (ou
 *    reabra) nesses casos.
 * Contrato com as telas: exceções levam prefixo 'PERIODO_FECHADO: '
 * (mesmo padrão do LOTE_VENCIDO) — a tela orienta a reabertura formal.
 * @return array{pode: bool, motivo: ?string}
 */
function vero_srv_estoque_pode_movimentar(string $data, ?int $safraTalhaoId = null): array
{
    if ($safraTalhaoId) {
        $safraId = (int)(vero_val(
            "SELECT safra_id FROM agro_safra_talhoes WHERE id = :i AND tenant_id = :t",
            [':i' => $safraTalhaoId, ':t' => vero_tenant()]) ?? 0);
        if ($safraId) {
            $r = vero_srv_custeio_pode_lancar($safraId);
            if (!$r['pode']) return $r;
        }
    }
    $corte = vero_val(
        "SELECT MAX(data_fechamento) FROM custeio_fechamentos WHERE tenant_id = :t AND status = 'fechado'",
        [':t' => vero_tenant()]);
    if ($corte !== null && $corte !== false && substr($data, 0, 10) <= substr((string)$corte, 0, 10)) {
        return ['pode' => false, 'motivo' => 'Período fechado até ' . date('d/m/Y', strtotime((string)$corte))
            . ' — movimento retroativo exige REABERTURA formal do fechamento de safra (Custos → Fechamento).'];
    }
    return ['pode' => true, 'motivo' => null];
}

/** Lança a exceção padronizada da trava (prefixo é contrato com as telas). */
function vero_srv_estoque_exigir_periodo_aberto(string $data, ?int $safraTalhaoId = null): void
{
    $r = vero_srv_estoque_pode_movimentar($data, $safraTalhaoId);
    if (!$r['pode']) throw new RuntimeException('PERIODO_FECHADO: ' . $r['motivo']);
}


/* ============================================================
   COLHEITA → ESTOQUE (A0-18 F1, 05/07/2026 — P-82..86 aceitas)
   Fluxo: colheita registrada → classificação → confirmar entrada
   → lote agrícola COLH- + entrada real (origem colheita, custo
   provisório snapshot). A entrada NÃO lança custeio novo (o custo
   já está no talhão — valorizar estoque ≠ custo adicional).
   Venda baixa o lote na F2. Tela/gatilho: A1 (A1-42).
   ============================================================ */

const VERO_LOTE_STATUS = ['disponivel', 'em_classificacao', 'bloqueado', 'consumido', 'estornado'];

/**
 * Confirma a entrada da colheita no estoque (idempotente por origem).
 * §12 do mandato: exige safra, talhão, produto configurado na cultura e
 * kg > 0; com exige_classificacao=1, exige classificação REALIZADO e só
 * o kg APROVADO (linhas sem causa_perda) vira saldo (P-84).
 * Custo provisório (P-85) = Σ custeio(safra,talhão até a data) ÷ Σ kg
 * colhido (colheitas do mesmo safra_talhao até a data) — snapshot no
 * movimento; revalorização no fechamento é F3 (EST-013).
 * Respeita P-81 (período fechado) via vero_srv_estoque_entrada.
 * Chamar dentro de transação.
 * @return array{ja_existia: bool, mov_id: int, lote_id: int, lote_codigo: string,
 *               kg: float, custo_unitario: float}
 */
function vero_srv_colheita_confirmar_entrada(int $colheitaId): array
{
    $t = vero_tenant();
    $col = vero_row(
        "SELECT cr.*, c.produto_estoque_colheita_id AS prod_id, c.almoxarifado_colheita_id AS almox_id,
                c.exige_classificacao, c.nome AS cultura_nome, tl.codigo AS talhao_codigo
           FROM colheita_registros cr
           JOIN agro_culturas c ON c.id = cr.cultura_id
           LEFT JOIN agro_talhoes tl ON tl.id = cr.talhao_id
          WHERE cr.id = :i AND cr.tenant_id = :t", [':i' => $colheitaId, ':t' => $t]);
    if (!$col) throw new RuntimeException('Colheita inválida.');
    if (!$col['safra_id'] || !$col['talhao_id']) throw new RuntimeException('Colheita sem safra/talhão — entrada bloqueada (§12).');
    if ((float)$col['kg_total_realizado'] <= 0) throw new RuntimeException('Colheita sem quantidade realizada (> 0) — registre a produção antes.');
    if (!$col['prod_id']) throw new RuntimeException('Cultura "' . $col['cultura_nome'] . '" sem produto de estoque configurado — defina em Culturas (produto gerado pela colheita).');
    $almoxId = (int)($col['almox_id'] ?: 0);
    if (!$almoxId) throw new RuntimeException('Cultura sem local padrão de entrada (almoxarifado da colheita).');

    /* idempotência: entrada ativa desta colheita já existe → devolve a existente */
    $existente = vero_row(
        "SELECT m.id, m.lote_id, m.quantidade, m.custo_unitario, l.codigo_lote
           FROM estoque_movimentacoes m LEFT JOIN estoque_lotes l ON l.id = m.lote_id
          WHERE m.tenant_id = :t AND m.origem_tipo = 'colheita' AND m.origem_id = :o
            AND m.tipo = 'entrada' AND m.estornado_em IS NULL",
        [':t' => $t, ':o' => $colheitaId]);
    if ($existente) {
        return ['ja_existia' => true, 'mov_id' => (int)$existente['id'], 'lote_id' => (int)$existente['lote_id'],
                'lote_codigo' => (string)$existente['codigo_lote'], 'kg' => (float)$existente['quantidade'],
                'custo_unitario' => (float)$existente['custo_unitario']];
    }

    /* kg aprovado (P-84) */
    if ((int)$col['exige_classificacao'] === 1) {
        $classif = vero_rows(
            "SELECT kg_calculado, causa_perda FROM colheita_classificacoes
              WHERE tenant_id = :t AND registro_id = :r AND momento = 'realizado'",
            [':t' => $t, ':r' => $colheitaId]);
        if (!$classif) throw new RuntimeException('Cultura exige CLASSIFICAÇÃO antes da entrada — registre a classificação realizada.');
        $kg = 0.0;
        foreach ($classif as $cl) {
            if (trim((string)($cl['causa_perda'] ?? '')) === '') $kg += (float)$cl['kg_calculado'];
        }
        if ($kg <= 0) throw new RuntimeException('Nenhum kg APROVADO na classificação (tudo perda/descarte) — nada a estocar; o histórico permanece na colheita.');
    } else {
        $kg = (float)$col['kg_total_realizado'];
    }

    /* custo provisório (P-85): acumulado do safra_talhao ÷ kg colhido até a data.
       Sprint Zero packing #5 / Decisão 6 (RN-01): esta soma filtra talhao_id = :ta,
       então o custo INDUSTRIAL de packing NÃO contamina o custo do lote agrícola —
       desde que o packing lance custeio com talhao_id = NULL (migration 194 já
       tornou a coluna nullable). NÃO adicionar custo de packing a este par
       (safra, talhao); se um dia um custeio de packing precisar de defesa extra,
       excluir os origem_tipo 'ph_*' aqui — não antes de eles existirem. */
    $data = substr((string)$col['data_colheita'], 0, 10);
    $custoAcum = (float)(vero_val(
        "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos
          WHERE tenant_id = :t AND safra_id = :s AND talhao_id = :ta AND data_competencia <= :d",
        [':t' => $t, ':s' => (int)$col['safra_id'], ':ta' => (int)$col['talhao_id'], ':d' => $data]) ?? 0);
    $kgAcum = (float)(vero_val(
        "SELECT COALESCE(SUM(kg_total_realizado),0) FROM colheita_registros
          WHERE tenant_id = :t AND safra_talhao_id = :st AND data_colheita <= :d",
        [':t' => $t, ':st' => (int)$col['safra_talhao_id'], ':d' => $data]) ?? 0);
    $custoUnit = $kgAcum > 0 ? round($custoAcum / $kgAcum, 6) : 0.0;

    /* entrada real (P-81 e saldo dentro do service) — SEM custeio novo (anti-dup §2.3) */
    $obs = 'Colheita #' . $colheitaId . ' — ' . $col['cultura_nome']
         . ($col['talhao_codigo'] ? ' talhão ' . $col['talhao_codigo'] : '')
         . ' · custo PROVISÓRIO (fechamento pendente)';
    $movId = vero_srv_estoque_entrada((int)$col['prod_id'], $almoxId, $kg, $custoUnit, $data, 'colheita', $colheitaId, $obs);

    /* lote agrícola COLH-AAAA-TALHAO-SEQ (P-83: variedade fica NO LOTE).
       Packing (gestor 19/08): o ACEITE da recepção pode ter criado antes um
       lote de RASTREABILIDADE desta colheita (quantidade 0 / custo 0 —
       ph_recepcao_lote_colh), cujo código já pode estar impresso em caixas.
       A entrada oficial ADOTA esse lote (vira o lote real, com kg e custo)
       em vez de criar um segundo código para o mesmo batch. */
    $placeholder = vero_row(
        "SELECT id, codigo_lote FROM estoque_lotes
          WHERE tenant_id = :t AND colheita_registro_id = :c AND codigo_lote LIKE 'COLH-%'
            AND status = 'disponivel' AND quantidade = 0 AND custo_unitario = 0
          ORDER BY id DESC LIMIT 1", [':t' => $t, ':c' => $colheitaId]);
    if ($placeholder) {
        $loteId = (int)$placeholder['id'];
        $codigo = (string)$placeholder['codigo_lote'];
        vero_pdo()->prepare(
            "UPDATE estoque_lotes
                SET produto_id = ?, almoxarifado_id = ?, custo_unitario = ?, quantidade = ?,
                    safra_talhao_id = ?, variedade_id = ?, updated_by = ?
              WHERE tenant_id = ? AND id = ?")
            ->execute([(int)$col['prod_id'], $almoxId, $custoUnit, $kg,
                       (int)$col['safra_talhao_id'] ?: null,
                       $col['variedade_id'] !== null ? (int)$col['variedade_id'] : null,
                       vero_uid(), $t, $loteId]);
    } else {
        $seq = (int)vero_val("SELECT COUNT(*) FROM estoque_lotes WHERE tenant_id = :t AND colheita_registro_id IS NOT NULL",
            [':t' => $t]) + 1;
        do { /* anda a sequência se o código já existir (ex.: lote de rastreabilidade criado no packing) */
            $codigo = 'COLH-' . substr($data, 0, 4) . '-' . strtoupper((string)($col['talhao_codigo'] ?: $col['talhao_id']))
                    . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
            $jaExiste = vero_val("SELECT id FROM estoque_lotes WHERE tenant_id = :t AND codigo_lote = :c",
                [':t' => $t, ':c' => $codigo]);
            $seq++;
        } while ($jaExiste);
        vero_pdo()->prepare(
            "INSERT INTO estoque_lotes (tenant_id, produto_id, almoxarifado_id, codigo_lote, validade,
                                        fornecedor_id, custo_unitario, quantidade, colheita_registro_id,
                                        safra_talhao_id, variedade_id, status, created_by, updated_by)
             VALUES (?,?,?,?,NULL,NULL,?,?,?,?,?, 'disponivel', ?, ?)")
            ->execute([$t, (int)$col['prod_id'], $almoxId, $codigo, $custoUnit, $kg, $colheitaId,
                       (int)$col['safra_talhao_id'] ?: null, $col['variedade_id'] !== null ? (int)$col['variedade_id'] : null,
                       vero_uid(), vero_uid()]);
        $loteId = (int)vero_pdo()->lastInsertId();
    }
    vero_pdo()->prepare("UPDATE estoque_movimentacoes SET lote_id = ?, safra_talhao_id = ? WHERE tenant_id = ? AND id = ?")
        ->execute([$loteId, (int)$col['safra_talhao_id'] ?: null, $t, $movId]);

    return ['ja_existia' => false, 'mov_id' => $movId, 'lote_id' => $loteId, 'lote_codigo' => $codigo,
            'kg' => $kg, 'custo_unitario' => $custoUnit];
}

/**
 * Estorna a entrada ativa da colheita (edição/cancelamento — EST-023):
 * estorno lógico do movimento (devolve o lote via lote_id) + lote marcado
 * 'estornado'. Respeita P-81. Reeditar = estornar → confirmar de novo.
 * @return bool true se havia entrada ativa e foi estornada
 */
function vero_srv_colheita_estornar_entrada(int $colheitaId): bool
{
    $t = vero_tenant();
    $mov = vero_row(
        "SELECT * FROM estoque_movimentacoes
          WHERE tenant_id = :t AND origem_tipo = 'colheita' AND origem_id = :o
            AND tipo = 'entrada' AND estornado_em IS NULL",
        [':t' => $t, ':o' => $colheitaId]);
    if (!$mov) return false;
    vero_srv_estoque_estornar_mov($mov);
    if ($mov['lote_id'] !== null) {
        vero_pdo()->prepare("UPDATE estoque_lotes SET status = 'estornado' WHERE tenant_id = ? AND id = ?")
            ->execute([$t, (int)$mov['lote_id']]);
    }
    return true;
}

/* ============================================================
   PACK (produto acabado) → ESTOQUE (A-01, reunião 03/08)
   Espelha o padrão colheita→estoque: uma CARGA de romaneio
   (colheita_cargas) apontada por unidade (unidade_apont/qtd_apont)
   é postada no estoque como o SKU de produto acabado
   (ph_skus.produto_estoque_id), convertendo caixa↔palete pelo
   fator caixas_por_palete. UM lote/movimentação por CARGA — e a
   carga carrega UMA classificação (Premium/CAT), pois paletes não
   se misturam. Lote PACK-, idempotente por origem ('ph_carga'),
   reversível na edição (estorno lógico + lote 'estornado').
   O estoque de produto acabado é contado na UNIDADE COMERCIAL do
   SKU (caixa/palete/cumbuca), não em kg. A entrada NÃO lança
   custeio: o custo agrícola já está no lote COLH-; o custeio
   INDUSTRIAL de packing é fase futura ($custoUnit=0 default,
   parametrizável). Chamar dentro de transação (respeita P-81 via
   vero_srv_estoque_entrada). Gatilho: agro/romaneios_colheita.php.
   ============================================================ */

/**
 * Converte a quantidade apontada da unidade da CARGA para a unidade
 * COMERCIAL do SKU. Só caixa↔palete têm conversão definida (pelo fator
 * caixas_por_palete); cumbuca (e qualquer par não previsto) só posta na
 * MESMA unidade. Lança RuntimeException se a conversão não é definida.
 */
function vero_srv_pack_converter_unidade(string $de, float $qtd, string $para, int $fator): float
{
    if ($fator <= 0) $fator = 110;
    if ($de === $para) return $qtd;
    if ($de === 'caixa'  && $para === 'palete') return $qtd / $fator;
    if ($de === 'palete' && $para === 'caixa')  return $qtd * $fator;
    throw new RuntimeException('Conversão de "' . $de . '" para "' . $para
        . '" não é definida (só caixa↔palete convertem; cumbuca posta na mesma unidade). '
        . 'Ajuste a unidade do apontamento da carga ou a unidade comercial do SKU.');
}

/**
 * Confirma a entrada de uma CARGA de romaneio no estoque como produto
 * acabado (SKU), idempotente por origem 'ph_carga'. Exige apontamento
 * por unidade na carga (unidade_apont + qtd_apont > 0) e SKU com item de
 * estoque + unidade (almoxarifado de packing) configurados.
 * @return array{ja_existia: bool, mov_id: int, lote_id: int, lote_codigo: string,
 *               qtd: float, unidade: string, custo_unitario: float}
 */
function vero_srv_pack_confirmar_entrada(int $cargaId, int $skuId, float $custoUnit = 0.0): array
{
    $t = vero_tenant();
    $carga = vero_row(
        "SELECT c.*, tl.codigo AS talhao_codigo
           FROM colheita_cargas c
           LEFT JOIN agro_talhoes tl ON tl.id = c.talhao_id
          WHERE c.id = :i AND c.tenant_id = :t", [':i' => $cargaId, ':t' => $t]);
    if (!$carga) throw new RuntimeException('Carga de romaneio inválida.');
    $unidadeApont = (string)($carga['unidade_apont'] ?? '');
    $qtdApont     = (float)($carga['qtd_apont'] ?? 0);
    if ($unidadeApont === '' || $qtdApont <= 0) {
        throw new RuntimeException('Carga sem apontamento por unidade (unidade + quantidade > 0) — '
            . 'informe a quantidade por unidade no romaneio antes de postar no estoque.');
    }

    $sku = vero_row("SELECT * FROM ph_skus WHERE id = :i AND tenant_id = :t AND ativo = 1",
        [':i' => $skuId, ':t' => $t]);
    if (!$sku) throw new RuntimeException('SKU de produto acabado inválido ou inativo.');
    $skuRot  = (string)($sku['codigo'] ?? $skuId);
    $prodId  = (int)($sku['produto_estoque_id'] ?? 0);
    if (!$prodId) throw new RuntimeException('SKU "' . $skuRot . '" sem item de estoque vinculado — '
        . 'defina o "Item de estoque" no cadastro do SKU.');
    $almoxId = (int)($sku['unidade_id'] ?? 0);
    if (!$almoxId) throw new RuntimeException('SKU "' . $skuRot . '" sem unidade (almoxarifado de packing) — '
        . 'defina a Unidade no cadastro do SKU.');
    /* segurança de tenant nas FKs do SKU */
    if (!vero_val("SELECT id FROM estoque_produtos WHERE id=:i AND tenant_id=:t", [':i' => $prodId, ':t' => $t])) {
        throw new RuntimeException('O item de estoque do SKU não pertence a este tenant.');
    }
    if (!vero_val("SELECT id FROM almoxarifados WHERE id=:i AND tenant_id=:t", [':i' => $almoxId, ':t' => $t])) {
        throw new RuntimeException('O almoxarifado do SKU não pertence a este tenant.');
    }

    /* idempotência: entrada ativa desta carga já existe → devolve a existente */
    $existente = vero_row(
        "SELECT m.id, m.lote_id, m.quantidade, m.custo_unitario, l.codigo_lote
           FROM estoque_movimentacoes m LEFT JOIN estoque_lotes l ON l.id = m.lote_id
          WHERE m.tenant_id = :t AND m.origem_tipo = 'ph_carga' AND m.origem_id = :o
            AND m.tipo = 'entrada' AND m.estornado_em IS NULL",
        [':t' => $t, ':o' => $cargaId]);
    if ($existente) {
        return ['ja_existia' => true, 'mov_id' => (int)$existente['id'], 'lote_id' => (int)$existente['lote_id'],
                'lote_codigo' => (string)$existente['codigo_lote'], 'qtd' => (float)$existente['quantidade'],
                'unidade' => (string)($sku['unidade_comercial'] ?? $unidadeApont),
                'custo_unitario' => (float)$existente['custo_unitario']];
    }

    /* unidade comercial do SKU = unidade do estoque de acabado; sem ela, posta na unidade da carga */
    $unidadeSku = (string)($sku['unidade_comercial'] ?? '');
    if ($unidadeSku === '') $unidadeSku = $unidadeApont;
    $fator = ((int)($carga['caixas_por_palete'] ?? 0)) ?: (((int)($sku['caixas_por_palete'] ?? 0)) ?: 110);
    $qtd   = round(vero_srv_pack_converter_unidade($unidadeApont, $qtdApont, $unidadeSku, $fator), 4);
    if ($qtd <= 0) throw new RuntimeException('A quantidade convertida resultou em zero — verifique unidade e fator caixas/palete.');

    $data    = substr((string)($carga['data_carga'] ?? date('Y-m-d')), 0, 10);
    $classif = trim((string)($carga['classificacao'] ?? ''));
    $qtdTxt  = rtrim(rtrim(number_format($qtd, 3, '.', ''), '0'), '.');
    $obs = 'Pack — carga ' . ((string)($carga['romaneio'] ?? '') !== '' ? $carga['romaneio'] : '#' . $cargaId)
         . ($classif !== '' ? ' · ' . $classif : '')
         . ' · SKU ' . $skuRot . ' · ' . $qtdTxt . ' ' . $unidadeSku
         . ' · custo PENDENTE (custeio industrial de packing — fase futura)';
    $movId = vero_srv_estoque_entrada($prodId, $almoxId, $qtd, $custoUnit, $data, 'ph_carga', $cargaId, $obs);

    /* lote PACK-AAAA-<sku>-SEQ — 1 lote por carga (= 1 classificação; paletes não se misturam) */
    $seq = (int)vero_val("SELECT COUNT(*) FROM estoque_lotes WHERE tenant_id = :t AND codigo_lote LIKE 'PACK-%'",
        [':t' => $t]) + 1;
    $skuTag = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '', $skuRot));
    if ($skuTag === '') $skuTag = (string)$skuId;
    $skuTag = substr($skuTag, 0, 24);
    $codigo = 'PACK-' . substr($data, 0, 4) . '-' . $skuTag . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    $regId    = $carga['registro_id'] !== null ? (int)$carga['registro_id'] : null;
    $safraTal = $carga['safra_talhao_id'] !== null ? (int)$carga['safra_talhao_id'] : null;
    $varId    = $sku['variedade_id'] !== null ? (int)$sku['variedade_id'] : null;
    vero_pdo()->prepare(
        "INSERT INTO estoque_lotes (tenant_id, produto_id, almoxarifado_id, codigo_lote, validade,
                                    fornecedor_id, custo_unitario, quantidade, colheita_registro_id,
                                    safra_talhao_id, variedade_id, status, created_by, updated_by)
         VALUES (?,?,?,?,NULL,NULL,?,?,?,?,?, 'disponivel', ?, ?)")
        ->execute([$t, $prodId, $almoxId, $codigo, $custoUnit, $qtd, $regId, $safraTal, $varId, vero_uid(), vero_uid()]);
    $loteId = (int)vero_pdo()->lastInsertId();
    vero_pdo()->prepare("UPDATE estoque_movimentacoes SET lote_id = ?, safra_talhao_id = ? WHERE tenant_id = ? AND id = ?")
        ->execute([$loteId, $safraTal, $t, $movId]);

    return ['ja_existia' => false, 'mov_id' => $movId, 'lote_id' => $loteId, 'lote_codigo' => $codigo,
            'qtd' => $qtd, 'unidade' => $unidadeSku, 'custo_unitario' => $custoUnit];
}

/**
 * Estorna a entrada ativa de pack desta carga (edição/cancelamento):
 * estorno lógico do movimento (devolve o lote via lote_id) + lote PACK-
 * marcado 'estornado'. Se o lote já foi vendido/consumido, o estorno do
 * saldo falha (guard do service) e a exceção sobe. Reeditar = estornar
 * → confirmar de novo. Respeita P-81.
 * @return bool true se havia entrada ativa e foi estornada
 */
function vero_srv_pack_estornar_entrada(int $cargaId): bool
{
    $t = vero_tenant();
    $mov = vero_row(
        "SELECT * FROM estoque_movimentacoes
          WHERE tenant_id = :t AND origem_tipo = 'ph_carga' AND origem_id = :o
            AND tipo = 'entrada' AND estornado_em IS NULL",
        [':t' => $t, ':o' => $cargaId]);
    if (!$mov) return false;
    vero_srv_estoque_estornar_mov($mov);
    if ($mov['lote_id'] !== null) {
        vero_pdo()->prepare("UPDATE estoque_lotes SET status = 'estornado' WHERE tenant_id = ? AND id = ?")
            ->execute([$t, (int)$mov['lote_id']]);
    }
    return true;
}

/* ═══════════════════════ Clima (Open-Meteo) ═══════════════════════
   Ponto ÚNICO server-side p/ a previsão do tempo. O clima POR VÁLVULA
   segue no Mapa da Fazenda (client-side, agro/mapa.php); aqui é o clima
   GERAL da fazenda para os dashboards. Fonte grátis e sem chave.
   ================================================================= */

/** Código WMO → [ícone emoji, texto pt-BR]. Mesma tabela do Mapa (agro/mapa.php). */
function vero_clima_wmo(int $code): array
{
    static $WMO = [
        0 => ['☀️', 'Céu limpo'], 1 => ['🌤️', 'Predom. limpo'], 2 => ['⛅', 'Parc. nublado'], 3 => ['☁️', 'Nublado'],
        45 => ['🌫️', 'Névoa'], 48 => ['🌫️', 'Névoa gelada'], 51 => ['🌦️', 'Garoa fraca'], 53 => ['🌦️', 'Garoa'], 55 => ['🌦️', 'Garoa forte'],
        61 => ['🌧️', 'Chuva fraca'], 63 => ['🌧️', 'Chuva'], 65 => ['🌧️', 'Chuva forte'], 66 => ['🌧️', 'Chuva gelada'], 67 => ['🌧️', 'Chuva gelada'],
        71 => ['🌨️', 'Neve fraca'], 73 => ['🌨️', 'Neve'], 75 => ['🌨️', 'Neve forte'], 80 => ['🌦️', 'Pancadas'], 81 => ['🌦️', 'Pancadas'],
        82 => ['⛈️', 'Pancadas fortes'], 95 => ['⛈️', 'Tempestade'], 96 => ['⛈️', 'Tempestade/granizo'], 99 => ['⛈️', 'Tempestade/granizo'],
    ];
    return $WMO[$code] ?? ['🌡️', '—'];
}

/**
 * Ponto (lat/lon) GERAL da fazenda para o clima. Reusa a mesma derivação de
 * centroide do Mapa/DF (mip/aplicacoes.php): usa latitude/longitude do cadastro
 * de agro_talhoes; se vazias mas há polígono desenhado (geometria GeoJSON),
 * deriva o CENTRO do anel externo. Faz a MÉDIA dos pontos de todos os talhões
 * (ativos) do tenant/fazenda → um ponto representativo da propriedade.
 * @return array{lat: float, lon: float}|null  null se não há ponto derivável.
 */
function vero_clima_ponto_fazenda(int $fazendaId = 0): ?array
{
    $t = vero_tenant();
    $cond = $fazendaId > 0 ? ' AND fazenda_id = :fz' : '';
    $par  = $fazendaId > 0 ? [':t' => $t, ':fz' => $fazendaId] : [':t' => $t];
    $rows = vero_rows(
        "SELECT latitude, longitude, geometria FROM agro_talhoes
          WHERE tenant_id = :t AND ativo = 1{$cond}", $par);

    $sx = 0.0; $sy = 0.0; $n = 0;
    foreach ($rows as $tl) {
        $lat = $tl['latitude']  !== null ? (float)$tl['latitude']  : null;
        $lon = $tl['longitude'] !== null ? (float)$tl['longitude'] : null;
        if (($lat === null || $lon === null) && !empty($tl['geometria'])) {
            $g = json_decode((string)$tl['geometria'], true);
            $ring = null;
            if (isset($g['type'], $g['coordinates'])) {
                if ($g['type'] === 'Polygon')          $ring = $g['coordinates'][0] ?? null;
                elseif ($g['type'] === 'MultiPolygon') $ring = $g['coordinates'][0][0] ?? null;
            }
            if (is_array($ring) && count($ring) >= 3) {
                $rx = 0.0; $ry = 0.0; $rn = 0;
                foreach ($ring as $c) { if (isset($c[0], $c[1])) { $rx += (float)$c[0]; $ry += (float)$c[1]; $rn++; } }
                if ($rn > 0) { $lon = $rx / $rn; $lat = $ry / $rn; }
            }
        }
        if ($lat !== null && $lon !== null) { $sx += $lon; $sy += $lat; $n++; }
    }
    if ($n === 0) return null;
    return ['lat' => $sy / $n, 'lon' => $sx / $n];
}

/**
 * Previsão do tempo (Open-Meteo) para um ponto. Server-side, com timeout curto,
 * CA bundle (Windows/WAMP) e cache leve em arquivo (30 min) para não martelar a
 * API nem pesar o dashboard. Degrada em silêncio: retorna null se a API falhar,
 * o cabo estiver caído ou o cURL não existir — o chamador mostra "indisponível".
 * @return array{current: array, days: array<int,array>}|null
 */
function vero_clima_previsao(float $lat, float $lon, int $dias = 4): ?array
{
    $dias = max(1, min(16, $dias));
    $la = round($lat, 3); $lo = round($lon, 3);

    // Cache leve (30 min) por lat/lon/dias — reduz latência e chamadas externas.
    $ttl   = 1800;
    $chave = sprintf('vero_clima_%s_%s_%d', str_replace('.', '_', (string)$la), str_replace('.', '_', (string)$lo), $dias);
    $cache = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $chave . '.json';
    if (is_file($cache) && (time() - filemtime($cache)) < $ttl) {
        $raw = @file_get_contents($cache);
        $c   = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($c)) return $c;
    }

    if (!function_exists('curl_init')) return null;
    $url = 'https://api.open-meteo.com/v1/forecast'
        . '?latitude=' . $la . '&longitude=' . $lo
        . '&current=temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m'
        . '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max'
        . '&timezone=auto&forecast_days=' . $dias;

    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true, // P-8
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 4,   // timeout curto: dashboard não pode travar
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        // CA no Windows/WAMP: mesmo esquema do proxy de IA / push (BIOS_CURL_CAINFO).
        $ca = getenv('BIOS_CURL_CAINFO') ?: dirname(__DIR__) . '/extras/ssl/cacert.pem';
        if (!is_file($ca)) $ca = dirname(PHP_BINARY) . '/extras/ssl/cacert.pem';
        if (is_file($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) return null;

        $d = json_decode((string)$body, true);
        if (!is_array($d) || empty($d['current']) || empty($d['daily']['time'])) return null;

        $cur = $d['current'];
        [$icone, $texto] = vero_clima_wmo((int)($cur['weather_code'] ?? -1));
        $out = [
            'current' => [
                'temp'     => round((float)($cur['temperature_2m'] ?? 0)),
                'sensacao' => round((float)($cur['apparent_temperature'] ?? 0)),
                'umidade'  => (int)round((float)($cur['relative_humidity_2m'] ?? 0)),
                'vento'    => round((float)($cur['wind_speed_10m'] ?? 0)),
                'chuva'    => round((float)($cur['precipitation'] ?? 0), 1),
                'icone'    => $icone,
                'texto'    => $texto,
            ],
            'days' => [],
        ];
        $du = $d['daily'];
        $sem = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
        foreach ($du['time'] as $i => $iso) {
            [$dIcone] = vero_clima_wmo((int)($du['weather_code'][$i] ?? -1));
            $out['days'][] = [
                'data'    => $iso,
                'dia'     => $sem[(int)date('w', strtotime($iso . ' 00:00'))] ?? '',
                'max'     => round((float)($du['temperature_2m_max'][$i] ?? 0)),
                'min'     => round((float)($du['temperature_2m_min'][$i] ?? 0)),
                'chuvapct'=> (int)round((float)($du['precipitation_probability_max'][$i] ?? 0)),
                'icone'   => $dIcone,
            ];
        }
        @file_put_contents($cache, json_encode($out, JSON_UNESCAPED_UNICODE));
        return $out;
    } catch (Throwable $e) {
        // clima é cortesia — nunca quebra o dashboard; mas registra o motivo,
        // senão falha de rede, credencial e erro de config ficam indistinguíveis
        // (ex.: open_basedir no is_file($ca) virou "Previsão indisponível" mudo).
        error_log('vero_clima_previsao: ' . $e->getMessage());
        return null;
    }
}
