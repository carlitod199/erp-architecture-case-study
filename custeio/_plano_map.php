<?php
declare(strict_types=1);
/* ============================================================
   VERO — Custeio / mapa origem→plano de contas (A3-T10)
   Liga o custeio ao plano de contas gerencial (seed 3.x) SEM
   inventar classificação: cada origem de custeio tem uma conta
   inequívoca; o que não tiver mapa cai em 3.99 (Outros custos).
   Contrato (DB_CONTRACT, linha plano_contas): o mapa abaixo é a
   classificação padrão; os emissores gravam
   `custeio_lancamentos.plano_conta_id` chamando
   custeio_plano_conta_id() — a coluna existe desde a migration
   120 e estava sempre NULL.
   Emissores em `includes/vero_services.php` (rh_producao_item,
   apontamento_insumo) adotam o mapa no pacote A0-04.
   ============================================================ */

/* origem_tipo → código no plano_contas (seed) */
const VERO_PLANO_MAP_ORIGEM = [
    'rh_producao_item'      => '3.05', // Mão de obra (Gente)
    'rh_folha_lancamento'   => '3.05', // Mão de obra — salário+encargos da folha (A3-T3)
    'patrimonio_depreciacao'=> '3.99', // Outros custos (sem conta própria no seed — A3-T4)
    'apontamento_insumo'    => '3.02', // Adubo e fertilizante (defensivo sai por aplicação)
    'irrigacao_consumo'     => '3.07', // Irrigação
    'maquina_abastecimento' => '3.09', // Combustível e lubrificantes
    'maquina_manutencao'    => '3.11', // Manutenção
    'apontamento_maquina'   => '3.08', // Máquinas e frota (hora-máquina — ajuste auditoria 04/07)
    'monitoramento'         => '3.04', // Tratos culturais (monitoramento MIP)
    'aplicacao'             => '3.01', // Defensivos (default; refinado por tipo abaixo)
];

/* tipo de agro_aplicacoes → código (refina a origem 'aplicacao') */
const VERO_PLANO_MAP_APLICACAO_TIPO = [
    'pulverizacao'     => '3.01', // Defensivos
    'tratamento'       => '3.01',
    'indutor_brotacao' => '3.01',
    'fertirrigacao'    => '3.02', // Adubo e fertilizante
    'foliar'           => '3.03', // Nutrição foliar
    'outro'            => '3.99',
];

const VERO_PLANO_CODIGO_FALLBACK = '3.99'; // Outros custos de produção

/**
 * Resolve o id da conta (tenant atual) para um código do plano.
 * Conta inexistente/inativa → NULL (nunca inventa classificação).
 */
function custeio_plano_id_por_codigo(string $codigo): ?int
{
    static $cache = [];
    $t = vero_tenant();
    $k = $t . '|' . $codigo;
    if (!array_key_exists($k, $cache)) {
        $id = vero_val(
            "SELECT id FROM plano_contas
              WHERE tenant_id = :t AND codigo = :c AND ativo = 1 AND aceita_lancamento = 1",
            [':t' => $t, ':c' => $codigo]);
        $cache[$k] = $id !== null && $id !== false ? (int)$id : null;
    }
    return $cache[$k];
}

/**
 * Conta padrão para um lançamento de custeio, pela origem.
 * $aplicacaoTipo refina a origem 'aplicacao' (o emissor conhece o tipo).
 */
function custeio_plano_conta_id(string $origemTipo, ?string $aplicacaoTipo = null): ?int
{
    if ($origemTipo === 'aplicacao' && $aplicacaoTipo !== null
        && isset(VERO_PLANO_MAP_APLICACAO_TIPO[$aplicacaoTipo])) {
        $codigo = VERO_PLANO_MAP_APLICACAO_TIPO[$aplicacaoTipo];
    } else {
        $codigo = VERO_PLANO_MAP_ORIGEM[$origemTipo] ?? VERO_PLANO_CODIGO_FALLBACK;
    }
    return custeio_plano_id_por_codigo($codigo)
        ?? custeio_plano_id_por_codigo(VERO_PLANO_CODIGO_FALLBACK);
}

/**
 * Backfill idempotente: classifica lançamentos existentes com
 * plano_conta_id NULL usando o mesmo mapa (aplicações refinadas
 * pelo tipo via JOIN). Não toca em `valor` (contrato preservado);
 * reexecutar não altera linhas já classificadas.
 * @return int linhas atualizadas
 */
function custeio_plano_backfill(): int
{
    $t = vero_tenant();
    $pdo = vero_pdo();
    $n = 0;

    foreach (VERO_PLANO_MAP_ORIGEM as $origem => $codigo) {
        if ($origem === 'aplicacao') continue;
        $contaId = custeio_plano_id_por_codigo($codigo);
        if ($contaId === null) continue;
        $st = $pdo->prepare(
            "UPDATE custeio_lancamentos SET plano_conta_id = ?
              WHERE tenant_id = ? AND plano_conta_id IS NULL AND origem_tipo = ?");
        $st->execute([$contaId, $t, $origem]);
        $n += $st->rowCount();
    }

    /* aplicações: refina pelo tipo */
    foreach (VERO_PLANO_MAP_APLICACAO_TIPO as $tipo => $codigo) {
        $contaId = custeio_plano_id_por_codigo($codigo);
        if ($contaId === null) continue;
        $st = $pdo->prepare(
            "UPDATE custeio_lancamentos cl
               JOIN agro_aplicacoes ap ON ap.id = cl.origem_id AND ap.tenant_id = cl.tenant_id
                SET cl.plano_conta_id = ?
              WHERE cl.tenant_id = ? AND cl.plano_conta_id IS NULL
                AND cl.origem_tipo = 'aplicacao' AND ap.tipo = ?");
        $st->execute([$contaId, $t, $tipo]);
        $n += $st->rowCount();
        /* multi-válvula: origem 'aplicacao_valvula' chega à aplicação pela linha DB-29 */
        $st = $pdo->prepare(
            "UPDATE custeio_lancamentos cl
               JOIN agro_aplicacao_valvulas av ON av.id = cl.origem_id AND av.tenant_id = cl.tenant_id
               JOIN agro_aplicacoes ap ON ap.id = av.aplicacao_id AND ap.tenant_id = cl.tenant_id
                SET cl.plano_conta_id = ?
              WHERE cl.tenant_id = ? AND cl.plano_conta_id IS NULL
                AND cl.origem_tipo = 'aplicacao_valvula' AND ap.tipo = ?");
        $st->execute([$contaId, $t, $tipo]);
        $n += $st->rowCount();
    }

    /* resto sem mapa → 3.99 */
    $fallback = custeio_plano_id_por_codigo(VERO_PLANO_CODIGO_FALLBACK);
    if ($fallback !== null) {
        $st = $pdo->prepare(
            "UPDATE custeio_lancamentos SET plano_conta_id = ?
              WHERE tenant_id = ? AND plano_conta_id IS NULL");
        $st->execute([$fallback, $t]);
        $n += $st->rowCount();
    }
    return $n;
}
