<?php
declare(strict_types=1);
/* ============================================================
   VERO — Custeio / Execução de rateio (A3-T5 — P-07 aprovada)
   Spec: VERO_A3_ANALISE.md §6. Aplica NO FECHAMENTO, manual e
   idempotente, com memória de cálculo e CONTRAPARTIDA NEGATIVA
   (aprovada pelo cliente): a linha indireta original fica intacta
   e uma linha negativa "sem talhão" anula o valor rateado — as
   consolidações existentes continuam corretas sem mudar queries.
   Mecânica (respeita uq_lanc_origem): cada cota/contrapartida tem
   a PRÓPRIA linha em custeio_rateio_execucoes (memoria JSON) e o
   custeio referencia origem_tipo='rateio_execucao', origem_id=
   linha da execução. Fonte: lançamentos da MESMA safra sem
   talhao_id (exclui os próprios rateio_execucao). Lançamentos sem
   safra (folha/depreciação) NÃO entram no rateio por safra —
   rateio entre safras é fase futura (documentado na tela).
   Uma aplicação vigente por safra: reexecutar exige desfazer.
   ============================================================ */

/** Denominadores por talhão conforme a base da regra. @return array<int,float> talhao_id => peso */
function rateio_denominadores(int $safraId, string $base, array $config): array
{
    $t = vero_tenant();
    if ($base === 'area') {
        $rows = vero_rows("SELECT talhao_id, SUM(area_plantada_ha) AS v FROM agro_safra_talhoes
                            WHERE tenant_id=:t AND safra_id=:s GROUP BY talhao_id", [':t' => $t, ':s' => $safraId]);
    } elseif ($base === 'producao') {
        $rows = vero_rows("SELECT talhao_id, SUM(kg_total_realizado) AS v FROM colheita_registros
                            WHERE tenant_id=:t AND safra_id=:s GROUP BY talhao_id", [':t' => $t, ':s' => $safraId]);
    } elseif ($base === 'custo_direto') {
        $rows = vero_rows("SELECT talhao_id, SUM(valor) AS v FROM custeio_lancamentos
                            WHERE tenant_id=:t AND safra_id=:s AND talhao_id IS NOT NULL
                              AND origem_tipo <> 'rateio_execucao' GROUP BY talhao_id", [':t' => $t, ':s' => $safraId]);
    } elseif ($base === 'manual') {
        $pcts = $config['percentuais'] ?? null;
        if (!is_array($pcts) || !$pcts) throw new RuntimeException('Regra manual sem {"percentuais": {talhao_id: pct}} no config.');
        $soma = array_sum(array_map('floatval', $pcts));
        if (abs($soma - 100.0) > 0.01) throw new RuntimeException('Percentuais manuais somam ' . $soma . '% (exigido 100%).');
        $out = [];
        foreach ($pcts as $tal => $pct) $out[(int)$tal] = (float)$pct;
        return $out;
    } else {
        throw new RuntimeException('Base de rateio desconhecida: ' . $base);
    }
    $out = [];
    foreach ($rows as $r) if ((float)$r['v'] > 0) $out[(int)$r['talhao_id']] = (float)$r['v'];
    return $out;
}

/** Aplica a regra na safra. @return array{linhas:int, total:float} */
function rateio_aplicar(int $rateioId, int $safraId): array
{
    $t = vero_tenant();
    $pdo = vero_pdo();

    $regra = vero_row("SELECT * FROM custeio_rateios WHERE id=:i AND tenant_id=:t AND ativo=1",
        [':i' => $rateioId, ':t' => $t]);
    if (!$regra) throw new RuntimeException('Regra de rateio inválida/inativa.');

    $vigentes = (int)vero_val("SELECT COUNT(*) FROM custeio_rateio_execucoes
                                WHERE tenant_id=:t AND safra_id=:s AND status='aplicada'", [':t' => $t, ':s' => $safraId]);
    if ($vigentes > 0) throw new RuntimeException('Já existe rateio aplicado nesta safra — desfaça antes de reaplicar.');

    $fech = vero_row("SELECT status FROM custeio_fechamentos WHERE tenant_id=:t AND safra_id=:s",
        [':t' => $t, ':s' => $safraId]);
    if ($fech && $fech['status'] === 'fechado') throw new RuntimeException('Safra fechada — reabra para aplicar rateio.');

    $config = json_decode((string)($regra['config'] ?? '{}'), true) ?: [];
    $pesos = rateio_denominadores($safraId, (string)$regra['base'], $config);
    if (!$pesos) throw new RuntimeException('Nenhum denominador para a base "' . $regra['base'] . '" nesta safra.');
    $somaPesos = array_sum($pesos);

    $fontes = vero_rows(
        "SELECT COALESCE(categoria,'outros') AS categoria, SUM(valor) AS total
           FROM custeio_lancamentos
          WHERE tenant_id=:t AND safra_id=:s AND talhao_id IS NULL
            AND origem_tipo <> 'rateio_execucao'
          GROUP BY categoria HAVING total > 0", [':t' => $t, ':s' => $safraId]);
    if (!$fontes) throw new RuntimeException('Nenhum custo indireto (sem talhão) nesta safra para ratear.');

    $centro = vero_srv_centro_custo('RAT', 'Rateio');
    $agora = date('Y-m-d H:i:s');
    $linhas = 0; $totalGeral = 0.0;

    foreach ($fontes as $f) {
        $cat = (string)$f['categoria'];
        $centTotal = (int)round((float)$f['total'] * 100);
        $totalGeral += $centTotal / 100;

        /* cotas em centavos; sobra vai para a MAIOR cota (spec §6) */
        $cotas = []; $acum = 0; $maiorTal = null; $maiorCent = -1;
        foreach ($pesos as $tal => $peso) {
            $cent = (int)floor($centTotal * $peso / $somaPesos);
            $cotas[$tal] = $cent; $acum += $cent;
            if ($cent > $maiorCent) { $maiorCent = $cent; $maiorTal = $tal; }
        }
        if ($maiorTal !== null) $cotas[$maiorTal] += $centTotal - $acum;

        foreach ($cotas as $tal => $cent) {
            if ($cent === 0) continue;
            $stId = vero_val("SELECT id FROM agro_safra_talhoes WHERE tenant_id=:t AND safra_id=:s AND talhao_id=:ta",
                [':t' => $t, ':s' => $safraId, ':ta' => $tal]);
            $execId = vero_insert('custeio_rateio_execucoes', [
                'rateio_id' => $rateioId, 'safra_id' => $safraId, 'base_aplicada' => (string)$regra['base'],
                'valor_origem' => $centTotal / 100, 'status' => 'aplicada',
                'memoria' => json_encode(['tipo' => 'cota', 'categoria' => $cat, 'talhao_id' => (int)$tal,
                    'peso' => $pesos[$tal], 'soma_pesos' => $somaPesos, 'valor' => $cent / 100], JSON_UNESCAPED_UNICODE),
                'executado_por' => vero_uid(), 'executado_em' => $agora,
            ]);
            vero_insert('custeio_lancamentos', [
                'safra_id' => $safraId, 'safra_talhao_id' => $stId ? (int)$stId : null, 'talhao_id' => (int)$tal,
                'centro_custo_id' => $centro, 'plano_conta_id' => custeio_plano_id_por_codigo('3.99'),
                'categoria' => $cat, 'origem_tipo' => 'rateio_execucao', 'origem_id' => $execId,
                'valor' => $cent / 100, 'data_competencia' => date('Y-m-d'),
            ]);
            $linhas++;
        }
        /* contrapartida negativa sem talhão (aprovada na P-07) */
        $execId = vero_insert('custeio_rateio_execucoes', [
            'rateio_id' => $rateioId, 'safra_id' => $safraId, 'base_aplicada' => (string)$regra['base'],
            'valor_origem' => $centTotal / 100, 'status' => 'aplicada',
            'memoria' => json_encode(['tipo' => 'contrapartida', 'categoria' => $cat,
                'valor' => -$centTotal / 100], JSON_UNESCAPED_UNICODE),
            'executado_por' => vero_uid(), 'executado_em' => $agora,
        ]);
        vero_insert('custeio_lancamentos', [
            'safra_id' => $safraId, 'centro_custo_id' => $centro,
            'plano_conta_id' => custeio_plano_id_por_codigo('3.99'),
            'categoria' => $cat, 'origem_tipo' => 'rateio_execucao', 'origem_id' => $execId,
            'valor' => -$centTotal / 100, 'data_competencia' => date('Y-m-d'),
        ]);
        $linhas++;
    }
    return ['linhas' => $linhas, 'total' => $totalGeral];
}

/** Desfaz TODAS as execuções aplicadas da safra (remove custeio + marca desfeita). @return int linhas removidas */
function rateio_desfazer(int $safraId): int
{
    $t = vero_tenant();
    $pdo = vero_pdo();
    $st = $pdo->prepare(
        "DELETE cl FROM custeio_lancamentos cl
          WHERE cl.tenant_id = ? AND cl.origem_tipo = 'rateio_execucao'
            AND cl.origem_id IN (SELECT id FROM custeio_rateio_execucoes
                                  WHERE tenant_id = ? AND safra_id = ? AND status = 'aplicada')");
    $st->execute([$t, $t, $safraId]);
    $n = $st->rowCount();
    $pdo->prepare("UPDATE custeio_rateio_execucoes SET status='desfeita', updated_by=?
                    WHERE tenant_id=? AND safra_id=? AND status='aplicada'")
        ->execute([vero_uid(), $t, $safraId]);
    return $n;
}
