<?php
declare(strict_types=1);
/* ============================================================
   VERO — Comercial / Tabela de preços (F2, P-113 — migration 151)
   Resolve o preço vigente para uma venda pela dimensão MAIS
   ESPECÍFICA que casa. Dimensões (todas opcionais na regra = curinga):
   cultura, variedade, calibre, embalagem, comprador(cliente), canal, safra.
   Uma regra "casa" se, para cada dimensão que ELA restringe (não-NULL),
   a venda tem o mesmo valor. Vence a de MAIOR especificidade (mais
   dimensões restritas); empate → vigência mais recente. Vigência:
   vigencia_inicio <= data <= vigencia_fim (ou fim aberto). ativo=1.
   Preço é SUGESTÃO — a venda pode sobrepor (override com trilha).
   ============================================================ */

/** Dimensões numéricas (id) e textuais da tabela de preços. */
function preco_dims_num(): array { return ['cultura_id', 'variedade_id', 'comprador_id', 'canal_id', 'safra_id']; }
function preco_dims_txt(): array { return ['calibre', 'embalagem']; }

/* Colunas de dados da regra de preço (sem os campos de sistema). */
const PRECO_COLS = ['cultura_id', 'variedade_id', 'calibre', 'embalagem', 'comprador_id',
    'canal_id', 'safra_id', 'preco', 'moeda', 'vigencia_inicio', 'vigencia_fim', 'ativo'];

/** Persiste (insert/update) uma regra de preço.
 *  NB: comercial_tabela_precos (migration 151) NÃO tem coluna updated_by, então
 *  não dá p/ usar vero_insert/vero_update (que a injetam) — persistência crua
 *  casando com as colunas reais. GAP sinalizado ao A0 (padronizar updated_by/at). */
function preco_persist(array $d, ?int $id): void
{
    $pdo = vero_pdo();
    if ($id) {
        $set = implode(', ', array_map(static fn($c) => "{$c} = :{$c}", PRECO_COLS));
        $st = $pdo->prepare("UPDATE comercial_tabela_precos SET {$set} WHERE id = :id AND tenant_id = :t");
        foreach (PRECO_COLS as $c) $st->bindValue(":{$c}", $d[$c] ?? null);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':t', vero_tenant(), PDO::PARAM_INT);
        $st->execute();
    } else {
        $cols = array_merge(PRECO_COLS, ['tenant_id', 'created_by']);
        $st = $pdo->prepare("INSERT INTO comercial_tabela_precos (" . implode(',', $cols) . ")
                             VALUES (:" . implode(',:', $cols) . ")");
        foreach (PRECO_COLS as $c) $st->bindValue(":{$c}", $d[$c] ?? null);
        $st->bindValue(':tenant_id', vero_tenant(), PDO::PARAM_INT);
        $st->bindValue(':created_by', vero_uid(), PDO::PARAM_INT);
        $st->execute();
    }
}

/**
 * Resolve a melhor regra de preço para as dimensões dadas.
 * @param array $dims chaves: cultura_id/variedade_id/comprador_id/canal_id/safra_id/calibre/embalagem
 * @return array|null a linha de comercial_tabela_precos vencedora, ou null
 */
function preco_resolver(array $dims, ?string $data = null): ?array
{
    $data = $data && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : date('Y-m-d');
    $rows = vero_rows(
        "SELECT * FROM comercial_tabela_precos
          WHERE tenant_id = :t AND ativo = 1
            AND vigencia_inicio <= :d1 AND (vigencia_fim IS NULL OR vigencia_fim >= :d2)",
        [':t' => vero_tenant(), ':d1' => $data, ':d2' => $data]); /* QA-011: :d distinto (native prepares) */

    $best = null; $bestScore = -1;
    foreach ($rows as $r) {
        $score = 0; $casa = true;
        foreach (preco_dims_num() as $c) {
            if ($r[$c] !== null) {
                if ((int)$r[$c] !== (int)($dims[$c] ?? 0)) { $casa = false; break; }
                $score++;
            }
        }
        if ($casa) {
            foreach (preco_dims_txt() as $c) {
                if ($r[$c] !== null && $r[$c] !== '') {
                    if ((string)$r[$c] !== (string)($dims[$c] ?? '')) { $casa = false; break; }
                    $score++;
                }
            }
        }
        if (!$casa) continue;
        if ($score > $bestScore
            || ($score === $bestScore && $best !== null && (string)$r['vigencia_inicio'] > (string)$best['vigencia_inicio'])) {
            $best = $r; $bestScore = $score;
        }
    }
    return $best;
}

/** Rótulo legível de uma regra de preço (dimensões restritas). */
function preco_rotulo_regra(array $r): string
{
    // Auditoria seg. 23/07 (A-4): lookups escopados por tenant. Sem AND tenant_id
    // um id de outro tenant (sequencial) vazava a razão social/rótulo alheio.
    $partes = [];
    if ($r['cultura_id'] !== null)   $partes[] = (string)vero_val("SELECT nome FROM agro_culturas WHERE id=:i AND tenant_id=:t", [':i' => (int)$r['cultura_id'], ':t' => vero_tenant()]);
    if ($r['variedade_id'] !== null) $partes[] = (string)vero_val("SELECT nome FROM agro_variedades WHERE id=:i AND tenant_id=:t", [':i' => (int)$r['variedade_id'], ':t' => vero_tenant()]);
    if (($r['calibre'] ?? '') !== '')   $partes[] = 'cal.' . $r['calibre'];
    if (($r['embalagem'] ?? '') !== '') $partes[] = $r['embalagem'];
    if ($r['comprador_id'] !== null) $partes[] = (string)vero_val("SELECT razao_social FROM comercial_compradores WHERE id=:i AND tenant_id=:t", [':i' => (int)$r['comprador_id'], ':t' => vero_tenant()]);
    if ($r['canal_id'] !== null)     $partes[] = (string)vero_val("SELECT nome FROM comercial_canais WHERE id=:i AND tenant_id=:t", [':i' => (int)$r['canal_id'], ':t' => vero_tenant()]);
    if ($r['safra_id'] !== null)     $partes[] = (string)vero_val("SELECT identificacao FROM agro_safras WHERE id=:i AND tenant_id=:t", [':i' => (int)$r['safra_id'], ':t' => vero_tenant()]);
    return $partes ? implode(' · ', array_filter($partes)) : 'Preço geral (todas as dimensões)';
}
