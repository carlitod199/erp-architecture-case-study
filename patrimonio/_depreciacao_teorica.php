<?php
/* ============================================================
   VERO — Patrimônio / leitura TEÓRICA da depreciação linear
   (R3-01, auditoria matemática Rodada 3 — honestidade do balanço)
   A geração F1 é manual por competência; ativos antigos podem
   ter competências NÃO geradas e a "acumulada" das telas é só a
   GERADA. Estas funções calculam, SEM gravar nada, a leitura
   linear desde a aquisição:
     cota mensal = (aquisição − residual) ÷ vida útil (ativo ou categoria)
     competência elegível = mês cujo dia 1º ≥ data de aquisição
     (mesma regra da geração F1, que pula quando
      data_aquisicao > 'AAAA-MM-01')
   Terras / vida útil 0 não depreciam. Backfill em massa NÃO é
   feito aqui (decisão A0 pendente) — isto é apenas rótulo/leitura.
   ============================================================ */
declare(strict_types=1);

/** Nº de competências elegíveis da aquisição até $compRef ('AAAA-MM'), inclusive. */
function pat_comps_elegiveis(?string $dataAquisicao, ?string $compRef = null): int
{
    if ($dataAquisicao === null || $dataAquisicao === '') return 0;
    $compRef = $compRef ?? date('Y-m');
    try { $aq = new DateTimeImmutable($dataAquisicao); } catch (Throwable $e) { return 0; }
    $ini = (int)$aq->format('j') === 1 ? $aq : $aq->modify('first day of next month');
    $fim = new DateTimeImmutable($compRef . '-01');
    if ($ini > $fim) return 0;
    return ((int)$fim->format('Y') - (int)$ini->format('Y')) * 12
         + ((int)$fim->format('n') - (int)$ini->format('n')) + 1;
}

/**
 * Leitura teórica linear de um ativo. Espera a linha com valor_aquisicao,
 * valor_residual, data_aquisicao, vida_util_meses e (opcional) cat_vida e
 * dep_qtd (nº de competências já geradas). Retorna null quando o ativo não
 * deprecia (vida 0/nula — ex.: Terras), sem base ou sem competência elegível.
 * Chaves: cota, meses (limitado à vida útil), teorica, liquido_econ,
 * geradas, pendentes.
 */
function pat_teorica(array $a, ?string $compRef = null): ?array
{
    $vida = isset($a['vida_util_meses']) && $a['vida_util_meses'] !== null
          ? (int)$a['vida_util_meses']
          : (isset($a['cat_vida']) && $a['cat_vida'] !== null ? (int)$a['cat_vida'] : null);
    if ($vida === null || $vida <= 0) return null;   /* Terras/vida 0: não deprecia */
    $base = (float)$a['valor_aquisicao'] - (float)($a['valor_residual'] ?? 0);
    if ($base <= 0) return null;
    $eleg = pat_comps_elegiveis(isset($a['data_aquisicao']) ? (string)$a['data_aquisicao'] : null, $compRef);
    if ($eleg <= 0) return null;
    $meses   = min($eleg, $vida);
    $cota    = $base / $vida;
    $teorica = round(min($meses * $cota, $base), 2);
    $geradas = (int)($a['dep_qtd'] ?? 0);
    return [
        'cota'         => round($cota, 2),
        'meses'        => $meses,
        'teorica'      => $teorica,
        'liquido_econ' => round(max((float)$a['valor_aquisicao'] - $teorica,
                                    (float)($a['valor_residual'] ?? 0)), 2),
        'geradas'      => $geradas,
        'pendentes'    => max($meses - $geradas, 0),
    ];
}

/** true quando a leitura teórica diverge da acumulada GERADA (> 1 centavo). */
function pat_diverge(?array $teo, float $acumGerada): bool
{
    return $teo !== null && abs($teo['teorica'] - $acumGerada) > 0.01;
}
