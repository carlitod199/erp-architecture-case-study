<?php
declare(strict_types=1);
/* ============================================================
   VERO — Comercial / Despesas de venda (F1, P-112 — migration 151)
   Fecha o furo da MARGEM IRREAL: além do CPV do lote (T27), a venda
   passa a descontar as DESPESAS DE COMERCIALIZAÇÃO (frete, comissão,
   embalagem, imposto, taxa…). Cada despesa tem uma BASE de cálculo:
     • valor       — R$ fixo digitado;
     • percentual  — % da RECEITA da venda (ex.: comissão da trading);
     • por_unidade — R$ por kg/unidade × kg comercializado (ex.: embalagem).
   O valor R$ resultante é CONGELADO na linha (comercial_venda_despesas).
     margem líquida = receita − CPV(lote) − Σ despesas
   NB: a tabela (mig.151) não tem updated_by → insert/delete crus (a
   despesa é INSERT-only + remoção de correção; não toca o razão/hash).
   ============================================================ */

const DESPESA_BASES = [
    'valor'       => 'Valor fixo (R$)',
    'percentual'  => '% da receita',
    'por_unidade' => 'R$ por kg/unidade',
];

/** Valor R$ da despesa a partir da base. $input = % (percentual) ou R$ (valor/por_unidade). */
function despesa_calc(string $base, ?float $input, float $receita, float $kg): float
{
    $input = $input ?? 0.0;
    return match ($base) {
        'percentual'  => round($receita * $input / 100, 2),
        'por_unidade' => round($input * $kg, 2),
        default       => round($input, 2), /* valor fixo */
    };
}

/** Despesas de uma venda (com o nome do tipo). */
function despesas_venda(int $vendaId): array
{
    return vero_rows(
        "SELECT d.*, t.nome AS tipo_nome FROM comercial_venda_despesas d
           LEFT JOIN comercial_tipos_despesa t ON t.id = d.tipo_despesa_id
          WHERE d.tenant_id = :t AND d.venda_id = :v ORDER BY d.id",
        [':t' => vero_tenant(), ':v' => $vendaId]);
}

/** Soma R$ das despesas da venda. */
function despesas_total(int $vendaId): float
{
    return (float)vero_val(
        "SELECT COALESCE(SUM(valor),0) FROM comercial_venda_despesas WHERE tenant_id = :t AND venda_id = :v",
        [':t' => vero_tenant(), ':v' => $vendaId]);
}

/** CPV real da venda = saídas ATIVAS de estoque do lote × custo unitário (T27c). */
function venda_cpv(int $vendaId): float
{
    return (float)vero_val(
        "SELECT COALESCE(SUM(quantidade * custo_unitario),0) FROM estoque_movimentacoes
          WHERE tenant_id = :t AND origem_tipo = 'comercial_venda' AND origem_id = :v
            AND tipo = 'saida' AND estornado_em IS NULL",
        [':t' => vero_tenant(), ':v' => $vendaId]);
}

/** Insere uma despesa (valor R$ já calculado). Insert cru (tabela sem updated_by). */
function despesa_add(int $vendaId, ?int $tipoId, ?string $descricao, string $base, ?float $percentual, float $valor): void
{
    if (!isset(DESPESA_BASES[$base])) $base = 'valor';
    vero_pdo()->prepare(
        "INSERT INTO comercial_venda_despesas (tenant_id, venda_id, tipo_despesa_id, descricao, base, percentual, valor, created_by)
         VALUES (:t, :v, :tp, :d, :b, :p, :val, :cb)")
        ->execute([
            ':t' => vero_tenant(), ':v' => $vendaId, ':tp' => $tipoId ?: null,
            ':d' => ($descricao !== null && $descricao !== '') ? mb_substr($descricao, 0, 120) : null,
            ':b' => $base, ':p' => $base === 'percentual' ? $percentual : null,
            ':val' => round($valor, 2), ':cb' => vero_uid(),
        ]);
}

/** Remove uma despesa (correção). */
function despesa_remove(int $id, int $vendaId): void
{
    vero_pdo()->prepare("DELETE FROM comercial_venda_despesas WHERE id = :i AND venda_id = :v AND tenant_id = :t")
        ->execute([':i' => $id, ':v' => $vendaId, ':t' => vero_tenant()]);
}
