<?php
/* ============================================================
   VERO — OS-espelho 1:1 da atividade (domínio A1 — A1-38/A1-33)
   Arbitragem A0 (04/07): atividade planejada é a entidade-MESTRE;
   a OS é sua projeção NUMERADA 1:1 (figura mantida — P-29), com
   status espelhado e as FKs que o schema já tinha:
   agro_ordens_servico.atividade_id e agro_apontamentos.ordem_servico_id.
   Default arbitrado: OS para TODA atividade planejada (P-59 ao
   cliente — se preferir sob demanda, vira toggle).
   Exceção: atividade de APLICAÇÃO não gera OS — a DF/IF (A1-26)
   já é a OS de aplicação (sem dupla numeração do mesmo trabalho).
   ============================================================ */
declare(strict_types=1);

/** Mapa status da atividade → status da OS (espelho). */
function vero_a1_os_status(string $statusAtividade): string
{
    return match ($statusAtividade) {
        'em_execucao' => 'em_execucao',
        'concluida'   => 'concluida',
        'cancelada'   => 'cancelada',
        default       => 'aberta', // planejada
    };
}

/**
 * Garante e SINCRONIZA a OS-espelho de uma atividade (get-or-create).
 * Retorna o id da OS, ou NULL para atividades de aplicação (DF/IF é a OS).
 * Numeração OSAAAA-NNNN atômica via GET_LOCK (padrão do projeto).
 * @param array $atv linha de agro_atividades
 */
function vero_a1_os_sync(array $atv): ?int
{
    if ((string)$atv['tipo'] === 'aplicacao') return null;
    $t = vero_tenant();

    $upd = [
        'talhao_id'      => (int)$atv['talhao_id'],
        'status'         => vero_a1_os_status((string)$atv['status']),
        'data_abertura'  => $atv['data_planejada'] ?: date('Y-m-d'),
        'data_conclusao' => (string)$atv['status'] === 'concluida'
                              ? ($atv['data_realizada'] ?: date('Y-m-d')) : null,
    ];

    /* espelho existente: a OS mais antiga da atividade (1:1 por convenção;
       duplicatas legadas ficam como histórico até a limpeza P-04) */
    $osId = vero_val(
        "SELECT id FROM agro_ordens_servico
          WHERE tenant_id = :t AND atividade_id = :a ORDER BY id LIMIT 1",
        [':t' => $t, ':a' => (int)$atv['id']]);
    if ($osId) {
        vero_update('agro_ordens_servico', (int)$osId, $upd);
        return (int)$osId;
    }

    $pdo = vero_pdo();
    $lock = 'vero_os_num_' . $t;
    $pdo->prepare("SELECT GET_LOCK(?, 5)")->execute([$lock]);
    try {
        $seq = (int)vero_val(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(numero, 8) AS UNSIGNED)), 0)
               FROM agro_ordens_servico WHERE tenant_id = :t AND numero LIKE :p",
            [':t' => $t, ':p' => 'OS' . date('Y') . '-%']) + 1;
        $upd['numero']       = 'OS' . date('Y') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        $upd['atividade_id'] = (int)$atv['id'];
        return vero_insert('agro_ordens_servico', $upd);
    } finally {
        $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lock]);
    }
}

/** OS-espelho de uma atividade (id) ou NULL — p/ o apontamento derivar a FK. */
function vero_a1_os_da_atividade(?int $atividadeId): ?int
{
    if (!$atividadeId) return null;
    $id = vero_val(
        "SELECT id FROM agro_ordens_servico
          WHERE tenant_id = :t AND atividade_id = :a ORDER BY id LIMIT 1",
        [':t' => vero_tenant(), ':a' => $atividadeId]);
    return $id ? (int)$id : null;
}
