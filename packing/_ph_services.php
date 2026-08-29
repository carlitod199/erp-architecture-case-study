<?php
declare(strict_types=1);
/* ============================================================
   VERO — Packing House / Serviços de contexto ativo (ph_ctx)
   Onda 1 · tarefa 1 (Decisão 2 da §0.4): contexto ativo ESCOPADO ao
   packing em sessão, com helper único — NÃO é o contexto global do ERP.
   Unidade = almoxarifado tipo='packing' (Decisão 1: não criamos ph_unidades
   no MVP). Turno é um rótulo operacional (whitelist).
   ============================================================ */

/* Turnos do MVP (VARCHAR + whitelist em PHP — convenção do projeto). */
const PH_TURNOS = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'];

/** Unidades de packing do tenant (almoxarifados tipo='packing'): id => nome. */
function ph_ctx_unidades(): array
{
    $out = [];
    foreach (vero_rows(
        "SELECT id, nome FROM almoxarifados
          WHERE tenant_id = :t AND tipo = 'packing'
          ORDER BY nome", [':t' => vero_tenant()]) as $r) {
        $out[(int)$r['id']] = (string)$r['nome'];
    }
    return $out;
}

/** Grava o contexto ativo de packing na sessão (namespaced ph_ctx). Valida a
 *  unidade contra o tenant e o tipo; turno contra a whitelist. */
function ph_ctx_set(?int $unidadeId, ?string $turno): void
{
    $ok = null;
    if ($unidadeId) {
        $ok = vero_val(
            "SELECT id FROM almoxarifados WHERE id = :i AND tenant_id = :t AND tipo = 'packing'",
            [':i' => $unidadeId, ':t' => vero_tenant()]);
    }
    $_SESSION['ph_ctx'] = [
        'unidade_id' => $ok ? (int)$unidadeId : null,
        'turno'      => isset(PH_TURNOS[(string)$turno]) ? (string)$turno : null,
    ];
}

/** Lê o contexto ativo; se só há UMA unidade e nada foi escolhido, assume-a
 *  (conveniência de chão de fábrica). Retorna ['unidade_id'=>?int,'turno'=>?string]. */
function ph_ctx_get(): array
{
    $ctx = $_SESSION['ph_ctx'] ?? ['unidade_id' => null, 'turno' => null];
    if (($ctx['unidade_id'] ?? null) === null) {
        $uni = ph_ctx_unidades();
        if (count($uni) === 1) $ctx['unidade_id'] = (int)array_key_first($uni);
    }
    return ['unidade_id' => $ctx['unidade_id'] ?? null, 'turno' => $ctx['turno'] ?? null];
}

/** Linha da unidade ativa (almoxarifado tipo packing) ou null. */
function ph_ctx_unidade_atual(): ?array
{
    $id = ph_ctx_get()['unidade_id'];
    if (!$id) return null;
    return vero_row(
        "SELECT * FROM almoxarifados WHERE id = :i AND tenant_id = :t AND tipo = 'packing'",
        [':i' => $id, ':t' => vero_tenant()]);
}
