<?php
declare(strict_types=1);
/* ============================================================
   VERO — Folha / Líquido a pagar do EMPREGADO (A3, ONDA 4)
   Hoje a folha tem proventos (bruto) e os encargos PATRONAIS (custo do
   empregador). Falta o lado do EMPREGADO: descontos legais (INSS + IRRF)
   → LÍQUIDO A PAGAR (o que o trabalhador recebe). Este helper calcula
   isso de forma PROGRESSIVA a partir de TABELAS CONFIGURÁVEIS — nada de
   alíquota fixa no código (Regra 1 / precedente da calculadora MO, que o
   A0 semeou como "referência editável, validar").

   Tabelas em tenant_parametros (chave-valor, sem migration):
     folha.inss_faixas       — [{ate, aliquota}] progressivo (teto na última)
     folha.irrf_faixas       — [{ate|null, aliquota, deduz}] por faixa
     folha.irrf_dependente   — R$ dedução por dependente
   Semeadas com os valores de 2026 como REFERÊNCIA — **conferir com o
   contador** (podem mudar por lei/ano). Descontos VARIÁVEIS (faltas,
   adiantamento, EPI danificado) e a PERSISTÊNCIA das rubricas = migration
   do A0 (ver spec em VERO_A3_Folha_ONDA4_Spec.md); aqui é cálculo/exibição.
   ============================================================ */

/** Seeds de referência 2026 (editáveis; validar com o contador). */
function folha_liquido_seeds(): array
{
    return [
        'folha.inss_faixas' => [
            ['ate' => 1518.00, 'aliquota' => 7.5],
            ['ate' => 2793.88, 'aliquota' => 9.0],
            ['ate' => 4190.83, 'aliquota' => 12.0],
            ['ate' => 8157.41, 'aliquota' => 14.0], /* teto */
        ],
        'folha.irrf_faixas' => [
            ['ate' => 2428.80, 'aliquota' => 0.0,  'deduz' => 0.00],
            ['ate' => 2826.65, 'aliquota' => 7.5,  'deduz' => 182.16],
            ['ate' => 3751.05, 'aliquota' => 15.0, 'deduz' => 394.16],
            ['ate' => 4664.68, 'aliquota' => 22.5, 'deduz' => 675.49],
            ['ate' => null,    'aliquota' => 27.5, 'deduz' => 908.73], /* acima */
        ],
        'folha.irrf_dependente' => 189.59,
    ];
}

/** Lê um parâmetro JSON da folha; semeia (get-or-seed) na 1ª leitura. */
function folha_param(string $chave)
{
    $v = vero_val("SELECT valor FROM tenant_parametros WHERE tenant_id=:t AND chave=:c",
        [':t' => vero_tenant(), ':c' => $chave]);
    if ($v === null || $v === false || $v === '') { /* vero_val devolve false quando não há linha */
        $seed = folha_liquido_seeds()[$chave] ?? null;
        if ($seed === null) return null;
        vero_pdo()->prepare(
            "INSERT INTO tenant_parametros (tenant_id, chave, valor, descricao, created_by)
             VALUES (:t,:c,:v,:d,:u)")
            ->execute([':t' => vero_tenant(), ':c' => $chave,
                ':v' => json_encode($seed, JSON_UNESCAPED_UNICODE),
                ':d' => 'Folha ONDA 4 — referência 2026, conferir com o contador', ':u' => vero_uid()]);
        return $seed;
    }
    $dec = json_decode((string)$v, true);
    return $dec !== null ? $dec : (is_numeric($v) ? (float)$v : null);
}

/** INSS do empregado — PROGRESSIVO por faixa. */
function folha_inss(float $base): float
{
    $faixas = folha_param('folha.inss_faixas');
    if (!is_array($faixas) || !$faixas) return 0.0;
    $inss = 0.0; $ant = 0.0;
    foreach ($faixas as $f) {
        $ate = (float)$f['ate']; $aliq = (float)$f['aliquota'] / 100;
        if ($base > $ate) { $inss += ($ate - $ant) * $aliq; $ant = $ate; }
        else { $inss += ($base - $ant) * $aliq; return round($inss, 2); }
    }
    return round($inss, 2); /* acima do teto: contribuição máxima */
}

/** IRRF do empregado sobre a base (bruto − INSS − dependentes). */
function folha_irrf(float $bruto, float $inss, int $dependentes = 0): float
{
    $faixas = folha_param('folha.irrf_faixas');
    if (!is_array($faixas) || !$faixas) return 0.0;
    $dedDep = (float)(folha_param('folha.irrf_dependente') ?? 0);
    $base = $bruto - $inss - $dedDep * max(0, $dependentes);
    if ($base <= 0) return 0.0;
    foreach ($faixas as $f) {
        $ate = $f['ate'] !== null ? (float)$f['ate'] : null;
        if ($ate === null || $base <= $ate) {
            $irrf = $base * ((float)$f['aliquota'] / 100) - (float)$f['deduz'];
            return round(max(0.0, $irrf), 2);
        }
    }
    return 0.0;
}

/** Demonstrativo do líquido de um lançamento de folha (bruto − INSS − IRRF). */
function folha_liquido(float $bruto, int $dependentes = 0): array
{
    $inss = folha_inss($bruto);
    $irrf = folha_irrf($bruto, $inss, $dependentes);
    return [
        'bruto'    => round($bruto, 2),
        'inss'     => $inss,
        'irrf'     => $irrf,
        'descontos' => round($inss + $irrf, 2),
        'liquido'  => round($bruto - $inss - $irrf, 2),
    ];
}
