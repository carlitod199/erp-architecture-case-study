<?php
declare(strict_types=1);
/* ============================================================
   VERO — Custeio / Rateio de COMBUSTÍVEL por HORAS (A3, P-123/125)
   Decisão FINAL 08/07 (retificação P-123): o custo_hora da máquina
   NÃO embute combustível → o combustível (maquina_abastecimentos.
   valor_total, que já emite 1 lançamento nível-máquina SEM safra)
   é alocado às válvulas/safras por RATEIO POR HORAS dos apontamentos
   da máquina no período — NUNCA por área (isso é o fallback da T31,
   para folha/depreciação que não têm operação a que se prender).

   fuel_custo_hora(máquina, mês) = valor ÷ Σ horas dos apontamentos
   cada apontamento recebe fuel_custo_hora × suas horas → cota NA
   válvula(safra_talhao) do apontamento (mais preciso que a T31, que
   para na safra com talhão NULL). Contrapartida negativa sem safra
   anula o original (mesma mecânica P-07/T31: original intacto).

   Atribuição DIRETA (exceção, P-125): se o abastecimento carimbou
   apontamento_id (mig.149), 100%% vai à válvula daquele apontamento,
   fora do bolo do rateio. Anti-dupla-contagem: rateado OU direto.

   Idempotente por lançamento (memoria.tipo='combustivel_cota' +
   lancamento_id). "Período" = MÊS da competência (mesmo critério da
   T31: abastecimento e apontamento raramente caem no mesmo dia).
   ============================================================ */

/** Regra guarda-chuva do rateio de combustível (get-or-create). */
function comb_regra_id(): int
{
    $t = vero_tenant();
    $id = vero_val("SELECT id FROM custeio_rateios WHERE tenant_id=:t AND nome='Rateio de combustível por horas'", [':t' => $t]);
    if ($id) return (int)$id;
    return vero_insert('custeio_rateios', [
        'nome' => 'Rateio de combustível por horas', 'base' => 'manual',
        'config' => json_encode(['tipo' => 'rateio_combustivel', 'base_real' => 'horas'], JSON_UNESCAPED_UNICODE),
        'ativo' => 1,
    ]);
}

/** Abastecimentos (lançamentos nível-máquina, SEM safra) ainda NÃO rateados. */
function comb_pendentes(): array
{
    return vero_rows(
        "SELECT cl.*, ab.maquina_id, ab.apontamento_id AS ab_apontamento_id
           FROM custeio_lancamentos cl
           JOIN maquina_abastecimentos ab ON ab.id = cl.origem_id AND ab.tenant_id = cl.tenant_id
          WHERE cl.tenant_id = :t AND cl.origem_tipo = 'maquina_abastecimento'
            AND cl.safra_id IS NULL
            AND NOT EXISTS (
                SELECT 1 FROM custeio_rateio_execucoes e
                 WHERE e.tenant_id = cl.tenant_id AND e.status = 'aplicada'
                   AND JSON_UNQUOTE(JSON_EXTRACT(e.memoria, '$.lancamento_id')) = CAST(cl.id AS CHAR)
                   AND JSON_UNQUOTE(JSON_EXTRACT(e.memoria, '$.tipo')) = 'combustivel_cota')
          ORDER BY cl.data_competencia, cl.id", [':t' => vero_tenant()]);
}

/** Deriva a válvula (safra_talhao) de um apontamento no mês.
 *  Usa a.safra_talhao_id se preenchido; senão mapeia talhao_id → safra
 *  ATIVA que sobrepõe o mês. Retorna [safra_talhao_id, safra_id] ou null
 *  (apontamento em talhão fora de qualquer safra ativa — não rateável).
 *  @return array{0:int,1:int}|null */
function comb_valvula_do_apontamento(array $ap, string $mesIni, string $mesFim): ?array
{
    if ($ap['safra_talhao_id'] !== null) {
        $sid = vero_val("SELECT safra_id FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t",
            [':i' => (int)$ap['safra_talhao_id'], ':t' => vero_tenant()]);
        return $sid ? [(int)$ap['safra_talhao_id'], (int)$sid] : null;
    }
    if ($ap['talhao_id'] === null) return null;
    $row = vero_row(
        "SELECT st.id, st.safra_id FROM agro_safra_talhoes st
           JOIN agro_safras s ON s.id = st.safra_id AND s.tenant_id = st.tenant_id
          WHERE st.tenant_id = :t AND st.talhao_id = :tl AND s.status = 'ativa'
            AND s.data_inicio <= :f AND (s.data_fim IS NULL OR s.data_fim >= :i)
          ORDER BY s.data_inicio DESC LIMIT 1",
        [':t' => vero_tenant(), ':tl' => (int)$ap['talhao_id'], ':f' => $mesFim, ':i' => $mesIni]);
    return $row ? [(int)$row['id'], (int)$row['safra_id']] : null;
}

/** Apontamentos da máquina no mês, com horas>0 e válvula derivável.
 *  @return list<array{apontamento_id:int,horas:float,safra_talhao_id:int,safra_id:int}> */
function comb_apontamentos_rateaveis(int $maquinaId, string $compet): array
{
    $mesIni = date('Y-m-01', strtotime($compet));
    $mesFim = date('Y-m-t', strtotime($compet));
    $rows = vero_rows(
        "SELECT am.apontamento_id, am.horas, a.talhao_id, a.safra_talhao_id
           FROM agro_apontamento_maquinas am
           JOIN agro_apontamentos a ON a.id = am.apontamento_id AND a.tenant_id = am.tenant_id
          WHERE am.tenant_id = :t AND am.maquina_id = :m AND am.horas > 0
            AND DATE(a.data_apontamento) BETWEEN :i AND :f
          ORDER BY am.apontamento_id",
        [':t' => vero_tenant(), ':m' => $maquinaId, ':i' => $mesIni, ':f' => $mesFim]);
    $out = [];
    foreach ($rows as $r) {
        $v = comb_valvula_do_apontamento($r, $mesIni, $mesFim);
        if ($v === null) continue; /* apontamento em talhão fora de safra ativa */
        $out[] = ['apontamento_id' => (int)$r['apontamento_id'], 'horas' => (float)$r['horas'],
                  'safra_talhao_id' => $v[0], 'safra_id' => $v[1]];
    }
    return $out;
}

/** Grava as cotas + contrapartida de UM abastecimento. Metadados na memória. */
function comb_gravar(array $cl, int $regraId, array $cotas, string $agora): int
{
    /* $cotas: list<{safra_talhao_id,safra_id,cent,memoria_extra}> */
    $t = vero_tenant();
    $centTotal = (int)round((float)$cl['valor'] * 100);
    $compet = (string)$cl['data_competencia'];
    $linhas = 0; $maiorSafra = null;
    foreach ($cotas as $c) {
        if ($c['cent'] === 0) continue;
        $execId = vero_insert('custeio_rateio_execucoes', [
            'rateio_id' => $regraId, 'safra_id' => $c['safra_id'], 'base_aplicada' => 'horas',
            'valor_origem' => $centTotal / 100, 'status' => 'aplicada',
            'memoria' => json_encode(array_merge([
                'tipo' => 'combustivel_cota', 'lancamento_id' => (int)$cl['id'],
                'origem_tipo' => $cl['origem_tipo'], 'categoria' => $cl['categoria'],
                'competencia' => $compet, 'safra_talhao_id' => $c['safra_talhao_id'],
                'valor' => $c['cent'] / 100,
            ], $c['memoria_extra']), JSON_UNESCAPED_UNICODE),
            'executado_por' => vero_uid(), 'executado_em' => $agora,
        ]);
        vero_insert('custeio_lancamentos', [
            'safra_id' => $c['safra_id'], 'safra_talhao_id' => $c['safra_talhao_id'],
            'centro_custo_id' => $cl['centro_custo_id'] !== null ? (int)$cl['centro_custo_id'] : null,
            'plano_conta_id' => $cl['plano_conta_id'] !== null ? (int)$cl['plano_conta_id'] : null,
            'categoria' => $cl['categoria'], 'origem_tipo' => 'rateio_execucao', 'origem_id' => $execId,
            'valor' => $c['cent'] / 100, 'data_competencia' => $compet,
        ]);
        $linhas++;
        $maiorSafra = $c['safra_id'];
    }
    /* contrapartida negativa SEM safra — anula o original nas leituras sem safra */
    $execId = vero_insert('custeio_rateio_execucoes', [
        'rateio_id' => $regraId, 'safra_id' => $maiorSafra, 'base_aplicada' => 'horas',
        'valor_origem' => $centTotal / 100, 'status' => 'aplicada',
        'memoria' => json_encode(['tipo' => 'combustivel_contrapartida', 'lancamento_id' => (int)$cl['id'],
            'valor' => -$centTotal / 100], JSON_UNESCAPED_UNICODE),
        'executado_por' => vero_uid(), 'executado_em' => $agora,
    ]);
    vero_insert('custeio_lancamentos', [
        'centro_custo_id' => $cl['centro_custo_id'] !== null ? (int)$cl['centro_custo_id'] : null,
        'plano_conta_id' => $cl['plano_conta_id'] !== null ? (int)$cl['plano_conta_id'] : null,
        'categoria' => $cl['categoria'], 'origem_tipo' => 'rateio_execucao', 'origem_id' => $execId,
        'valor' => -$centTotal / 100, 'data_competencia' => $compet,
    ]);
    return $linhas + 1;
}

/**
 * Rateia TODOS os abastecimentos pendentes por horas.
 * @return array{rateados:int, pulados:array<string>, linhas:int, total:float}
 */
function comb_executar(): array
{
    $regraId = comb_regra_id();
    $agora = date('Y-m-d H:i:s');
    $rateados = 0; $linhas = 0; $total = 0.0; $pulados = [];

    foreach (comb_pendentes() as $cl) {
        $clId = (int)$cl['id'];
        $maquinaId = (int)$cl['maquina_id'];
        $compet = (string)$cl['data_competencia'];
        $centTotal = (int)round((float)$cl['valor'] * 100);

        /* Atribuição DIRETA (exceção P-125): abastecimento carimbou apontamento_id */
        if ($cl['ab_apontamento_id'] !== null) {
            $mesIni = date('Y-m-01', strtotime($compet));
            $mesFim = date('Y-m-t', strtotime($compet));
            $ap = vero_row(
                "SELECT am.apontamento_id, am.horas, a.talhao_id, a.safra_talhao_id
                   FROM agro_apontamento_maquinas am
                   JOIN agro_apontamentos a ON a.id = am.apontamento_id AND a.tenant_id = am.tenant_id
                  WHERE am.tenant_id = :t AND am.apontamento_id = :ap LIMIT 1",
                [':t' => vero_tenant(), ':ap' => (int)$cl['ab_apontamento_id']]);
            $v = $ap ? comb_valvula_do_apontamento($ap, $mesIni, $mesFim) : null;
            if ($v === null) {
                $pulados[] = "#{$clId} (R$ " . numFmt((float)$cl['valor'], 2) . "): atribuição direta ao apontamento {$cl['ab_apontamento_id']}, mas ele não está em safra ativa";
                continue;
            }
            $linhas += comb_gravar($cl, $regraId, [[
                'safra_talhao_id' => $v[0], 'safra_id' => $v[1], 'cent' => $centTotal,
                'memoria_extra' => ['modo' => 'direto', 'apontamento_id' => (int)$cl['ab_apontamento_id']],
            ]], $agora);
            $rateados++; $total += $centTotal / 100;
            continue;
        }

        /* Rateio por horas entre os apontamentos da máquina no mês */
        $aps = comb_apontamentos_rateaveis($maquinaId, $compet);
        if (!$aps) {
            $pulados[] = "#{$clId} (máquina {$maquinaId}, R$ " . numFmt((float)$cl['valor'], 2)
                . "): sem horas de apontamento em safra ativa em {$compet} — registre horas ou atribua direto";
            continue;
        }
        $somaHoras = array_sum(array_column($aps, 'horas'));
        $cotas = []; $acum = 0; $maiorIx = 0; $maiorCent = -1;
        foreach ($aps as $ix => $ap) {
            $cent = (int)floor($centTotal * $ap['horas'] / $somaHoras);
            $cotas[$ix] = ['safra_talhao_id' => $ap['safra_talhao_id'], 'safra_id' => $ap['safra_id'],
                'cent' => $cent, 'memoria_extra' => ['modo' => 'rateio_horas',
                    'apontamento_id' => $ap['apontamento_id'], 'horas' => $ap['horas'],
                    'soma_horas' => $somaHoras, 'fuel_custo_hora' => round($centTotal / 100 / $somaHoras, 6)]];
            $acum += $cent;
            if ($cent > $maiorCent) { $maiorCent = $cent; $maiorIx = $ix; }
        }
        $cotas[$maiorIx]['cent'] += $centTotal - $acum; /* sobra na maior cota (§6) */
        $linhas += comb_gravar($cl, $regraId, array_values($cotas), $agora);
        $rateados++; $total += $centTotal / 100;
    }
    return ['rateados' => $rateados, 'pulados' => $pulados, 'linhas' => $linhas, 'total' => $total];
}

/** Desfaz TODOS os rateios de combustível aplicados. @return int linhas removidas */
function comb_desfazer(): int
{
    $t = vero_tenant();
    $pdo = vero_pdo();
    $regraId = comb_regra_id();
    $st = $pdo->prepare(
        "DELETE cl FROM custeio_lancamentos cl
          WHERE cl.tenant_id = ? AND cl.origem_tipo = 'rateio_execucao'
            AND cl.origem_id IN (SELECT id FROM custeio_rateio_execucoes
                                  WHERE tenant_id = ? AND rateio_id = ? AND status = 'aplicada')");
    $st->execute([$t, $t, $regraId]);
    $n = $st->rowCount();
    $pdo->prepare("UPDATE custeio_rateio_execucoes SET status='desfeita', updated_by=?
                    WHERE tenant_id=? AND rateio_id=? AND status='aplicada'")
        ->execute([vero_uid(), $t, $regraId]);
    return $n;
}
