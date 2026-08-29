<?php
/* ============================================================
   VERO — Estoque / helper de RESERVA DERIVADA (A2-F2-14, P-60)
   Incluído por produtos.php e index.php (arquivo do módulo A2 —
   não é include global).
   Reserva ORIENTATIVA: nada é gravado
   e nenhuma saída é bloqueada — o número é DERIVADO na leitura:

     reservado(produto) = Σ quantidade_prevista dos insumos
       planejados (agro_atividade_insumos) de atividades com
       status planejada/em_execucao
     − Σ quantidade já consumida VINCULADA a essas atividades
       (agro_apontamento_insumos via agro_apontamentos.atividade_id)
     (piso 0 por produto)

     disponível = saldo físico − reservado (piso 0)

   Contrato DB-35: A2 apenas LÊ as tabelas do A1. Atividade
   concluída/cancelada libera a reserva automaticamente (sai do
   filtro de status). Alerta idempotente de "disponível abaixo do
   mínimo" NÃO entra aqui (tocaria service global — pacote A0).
   ============================================================ */
declare(strict_types=1);

/**
 * Reservas derivadas por produto do tenant.
 * @return array<int, float> [produto_id => quantidade reservada (>0)]
 */
function estoque_reservas_por_produto(): array
{
    $t = vero_tenant();
    $reservas = [];
    foreach (vero_rows(
        "SELECT ai.produto_id, SUM(ai.quantidade_prevista) AS previsto
           FROM agro_atividade_insumos ai
           JOIN agro_atividades a ON a.id = ai.atividade_id
          WHERE ai.tenant_id = :t AND a.status IN ('planejada','em_execucao')
          GROUP BY ai.produto_id", [':t' => $t]) as $r) {
        $reservas[(int)$r['produto_id']] = (float)$r['previsto'];
    }
    if (!$reservas) return [];
    foreach (vero_rows(
        "SELECT api.produto_id, SUM(api.quantidade) AS consumido
           FROM agro_apontamento_insumos api
           JOIN agro_apontamentos ap ON ap.id = api.apontamento_id
           JOIN agro_atividades a ON a.id = ap.atividade_id
          WHERE api.tenant_id = :t AND a.status IN ('planejada','em_execucao')
          GROUP BY api.produto_id", [':t' => $t]) as $r) {
        $pid = (int)$r['produto_id'];
        if (isset($reservas[$pid])) {
            $reservas[$pid] = max(0.0, $reservas[$pid] - (float)$r['consumido']);
        }
    }
    return array_filter($reservas, static fn(float $q): bool => $q > 0.0001);
}
