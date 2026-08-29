<?php
declare(strict_types=1);
/* ============================================================
   VERO — Custeio / Atribuição de lançamentos SEM SAFRA (A3-T31,
   NEG-01 — P-98 aceita 06/07: proporcional à ÁREA PLANTADA das
   safras ATIVAS no período de competência).
   Etapa ANTERIOR ao rateio por talhão: o lançamento sem safra
   (folha/depreciação/abastecimento…) vira COTAS COM SAFRA (talhão
   NULL — ou seja, custo indireto DA safra, que o rateio por talhão
   existente distribui depois) + CONTRAPARTIDA NEGATIVA SEM SAFRA
   anulando o original (mesma mecânica P-07: original intacto,
   consolidações não mudam de query).
   Idempotente POR LANÇAMENTO (memoria.lancamento_id nas execuções,
   status aplicada). Respeita uq_lanc_origem (1 linha de execução
   por cota/contrapartida) e a trava de fechamento por safra.
   ============================================================ */

/** Regra guarda-chuva das atribuições (get-or-create). */
function atrib_regra_id(): int
{
    $t = vero_tenant();
    $id = vero_val("SELECT id FROM custeio_rateios WHERE tenant_id=:t AND nome='Atribuição sem safra'", [':t' => $t]);
    if ($id) return (int)$id;
    return vero_insert('custeio_rateios', [
        'nome' => 'Atribuição sem safra', 'base' => 'area',
        'config' => json_encode(['tipo' => 'atribuicao_sem_safra']), 'ativo' => 1,
    ]);
}

/** Lançamentos sem safra ainda NÃO atribuídos (exclui linhas do próprio rateio). */
function atrib_pendentes(): array
{
    return vero_rows(
        "SELECT cl.* FROM custeio_lancamentos cl
          WHERE cl.tenant_id = :t AND cl.safra_id IS NULL
            AND cl.origem_tipo <> 'rateio_execucao'
            AND cl.origem_tipo <> 'maquina_abastecimento' /* P-125: combustível é rateado por HORAS (motor próprio), não por área aqui */
            AND NOT EXISTS (
                SELECT 1 FROM custeio_rateio_execucoes e
                 WHERE e.tenant_id = cl.tenant_id AND e.status = 'aplicada'
                   AND JSON_UNQUOTE(JSON_EXTRACT(e.memoria, '$.lancamento_id')) = CAST(cl.id AS CHAR)
                   AND JSON_UNQUOTE(JSON_EXTRACT(e.memoria, '$.tipo')) = 'atribuicao_cota')
          ORDER BY cl.data_competencia, cl.id", [':t' => vero_tenant()]);
}

/** Safras ATIVAS no período de competência, com área plantada.
 *  "Período" = o MÊS da competência (P-98): folha e depreciação lançam no 1º
 *  dia do mês — exigir o dia exato deixaria de capturar safra iniciada no
 *  meio do mês. Critério: a safra sobrepõe QUALQUER dia do mês.
 *  @return array<int,float> safra_id => área */
function atrib_safras_periodo(string $competencia): array
{
    $mesIni = date('Y-m-01', strtotime($competencia));
    $mesFim = date('Y-m-t', strtotime($competencia));
    $rows = vero_rows(
        "SELECT s.id, (SELECT COALESCE(SUM(st.area_plantada_ha),0) FROM agro_safra_talhoes st
                        WHERE st.tenant_id = s.tenant_id AND st.safra_id = s.id) AS area
           FROM agro_safras s
          WHERE s.tenant_id = :t AND s.status = 'ativa'
            AND s.data_inicio <= :c1 AND (s.data_fim IS NULL OR s.data_fim >= :c2)",
        [':t' => vero_tenant(), ':c1' => $mesFim, ':c2' => $mesIni]);
    $out = [];
    foreach ($rows as $r) if ((float)$r['area'] > 0) $out[(int)$r['id']] = (float)$r['area'];
    return $out;
}

/**
 * Executa a atribuição em TODOS os pendentes.
 * @return array{atribuidos:int, pulados:array<string>, linhas:int, total:float}
 */
function atrib_executar(): array
{
    $t = vero_tenant();
    $regraId = atrib_regra_id();
    $agora = date('Y-m-d H:i:s');
    $atribuidos = 0; $linhas = 0; $total = 0.0; $pulados = [];

    foreach (atrib_pendentes() as $cl) {
        $clId = (int)$cl['id'];
        $compet = (string)$cl['data_competencia'];
        $pesos = atrib_safras_periodo($compet);
        if (!$pesos) {
            $pulados[] = "#{$clId} ({$cl['categoria']}, " . numFmt((float)$cl['valor'], 2) . "): nenhuma safra ATIVA com área em {$compet}";
            continue;
        }
        $bloqueada = null;
        foreach (array_keys($pesos) as $sid) {
            $guard = vero_srv_custeio_pode_lancar($sid);
            if (!$guard['pode']) { $bloqueada = "safra {$sid} fechada"; break; }
        }
        if ($bloqueada !== null) {
            $pulados[] = "#{$clId}: {$bloqueada} — reabra para atribuir";
            continue;
        }

        $somaPesos = array_sum($pesos);
        $centTotal = (int)round((float)$cl['valor'] * 100);
        /* cotas em centavos; sobra na MAIOR cota (mesma regra do rateio §6) */
        $cotas = []; $acum = 0; $maiorSafra = null; $maiorCent = -1;
        foreach ($pesos as $sid => $peso) {
            $cent = (int)floor($centTotal * $peso / $somaPesos);
            $cotas[$sid] = $cent; $acum += $cent;
            if ($cent > $maiorCent) { $maiorCent = $cent; $maiorSafra = $sid; }
        }
        if ($maiorSafra !== null) $cotas[$maiorSafra] += $centTotal - $acum;

        foreach ($cotas as $sid => $cent) {
            if ($cent === 0) continue;
            $execId = vero_insert('custeio_rateio_execucoes', [
                'rateio_id' => $regraId, 'safra_id' => $sid, 'base_aplicada' => 'area',
                'valor_origem' => $centTotal / 100, 'status' => 'aplicada',
                'memoria' => json_encode(['tipo' => 'atribuicao_cota', 'lancamento_id' => $clId,
                    'origem_tipo' => $cl['origem_tipo'], 'categoria' => $cl['categoria'],
                    'competencia' => $compet, 'peso' => $pesos[$sid], 'soma_pesos' => $somaPesos,
                    'valor' => $cent / 100], JSON_UNESCAPED_UNICODE),
                'executado_por' => vero_uid(), 'executado_em' => $agora,
            ]);
            vero_insert('custeio_lancamentos', [
                'safra_id' => $sid, /* talhao NULL: vira custo INDIRETO da safra (rateio por talhão distribui depois) */
                'centro_custo_id' => $cl['centro_custo_id'] !== null ? (int)$cl['centro_custo_id'] : null,
                'plano_conta_id' => $cl['plano_conta_id'] !== null ? (int)$cl['plano_conta_id'] : null,
                'categoria' => $cl['categoria'], 'origem_tipo' => 'rateio_execucao', 'origem_id' => $execId,
                'valor' => $cent / 100, 'data_competencia' => $compet,
            ]);
            $linhas++;
        }
        /* contrapartida negativa SEM safra (anula o original nas leituras "sem safra") */
        $execId = vero_insert('custeio_rateio_execucoes', [
            'rateio_id' => $regraId, 'safra_id' => (int)$maiorSafra, 'base_aplicada' => 'area',
            'valor_origem' => $centTotal / 100, 'status' => 'aplicada',
            'memoria' => json_encode(['tipo' => 'atribuicao_contrapartida', 'lancamento_id' => $clId,
                'valor' => -$centTotal / 100], JSON_UNESCAPED_UNICODE),
            'executado_por' => vero_uid(), 'executado_em' => $agora,
        ]);
        vero_insert('custeio_lancamentos', [
            'centro_custo_id' => $cl['centro_custo_id'] !== null ? (int)$cl['centro_custo_id'] : null,
            'plano_conta_id' => $cl['plano_conta_id'] !== null ? (int)$cl['plano_conta_id'] : null,
            'categoria' => $cl['categoria'], 'origem_tipo' => 'rateio_execucao', 'origem_id' => $execId,
            'valor' => -$centTotal / 100, 'data_competencia' => $compet,
        ]);
        $linhas++;
        $atribuidos++;
        $total += $centTotal / 100;
    }
    return ['atribuidos' => $atribuidos, 'pulados' => $pulados, 'linhas' => $linhas, 'total' => $total];
}

/** Desfaz TODAS as atribuições aplicadas (remove custeio + marca desfeita). @return int linhas removidas */
function atrib_desfazer(): int
{
    $t = vero_tenant();
    $pdo = vero_pdo();
    $regraId = atrib_regra_id();
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
