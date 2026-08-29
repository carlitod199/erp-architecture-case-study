<?php
/* ============================================================
   VERO — Faixas climáticas de referência do RT (A1-34 / P-32)
   Parâmetros por tenant (registrados em agro/clima.php pelo RT):
     agro.aplic_vento_max_kmh · agro.aplic_temp_max_c · agro.aplic_ur_min_pct
   Uso: AVISO visual na aplicação quando o clima registrado sai da
   faixa. Regra 1: o sistema SINALIZA o registro do RT — nunca
   recomenda nem trava; a decisão de aplicar é sempre do RT.
   Faixa vazia ('') = sem confronto.
   ============================================================ */
declare(strict_types=1);

/** Faixas registradas (null = não configurada). */
function vero_a1_faixas_clima_rt(): array
{
    static $f = null;
    if ($f === null) {
        $lê = static function (string $chave): ?float {
            $v = vero_srv_param($chave, '');
            return $v !== '' && is_numeric($v) ? (float)$v : null;
        };
        $f = [
            'vento_max' => $lê('agro.aplic_vento_max_kmh'),
            'temp_max'  => $lê('agro.aplic_temp_max_c'),
            'ur_min'    => $lê('agro.aplic_ur_min_pct'),
        ];
    }
    return $f;
}

/** Avisos (strings prontas) para os valores registrados; [] se dentro/sem faixa. */
function vero_a1_avisos_clima_rt(?float $ventoKmh, ?float $tempC, ?float $urPct): array
{
    $fx = vero_a1_faixas_clima_rt();
    $avisos = [];
    if ($ventoKmh !== null && $fx['vento_max'] !== null && $ventoKmh > $fx['vento_max']) {
        $avisos[] = 'Vento registrado ' . numFmt($ventoKmh, 1) . ' km/h acima da faixa de referência do RT ('
                  . numFmt($fx['vento_max'], 1) . ' km/h) — avaliação do RT.';
    }
    if ($tempC !== null && $fx['temp_max'] !== null && $tempC > $fx['temp_max']) {
        $avisos[] = 'Temperatura registrada ' . numFmt($tempC, 1) . ' °C acima da faixa de referência do RT ('
                  . numFmt($fx['temp_max'], 1) . ' °C) — avaliação do RT.';
    }
    if ($urPct !== null && $fx['ur_min'] !== null && $urPct < $fx['ur_min']) {
        $avisos[] = 'Umidade relativa registrada ' . numFmt($urPct, 0) . '% abaixo da faixa de referência do RT ('
                  . numFmt($fx['ur_min'], 0) . '%) — avaliação do RT.';
    }
    return $avisos;
}

/** Hint de campo mostrando a faixa registrada (ou o texto padrão sem faixa). */
function vero_a1_hint_faixa(string $qual, string $semFaixa): string
{
    $fx = vero_a1_faixas_clima_rt();
    return match ($qual) {
        'vento' => $fx['vento_max'] !== null ? 'Faixa do RT: até ' . numFmt($fx['vento_max'], 1) . ' km/h (aviso, não trava)' : $semFaixa,
        'temp'  => $fx['temp_max'] !== null ? 'Faixa do RT: até ' . numFmt($fx['temp_max'], 1) . ' °C (aviso, não trava)' : $semFaixa,
        'ur'    => $fx['ur_min'] !== null ? 'Faixa do RT: mín. ' . numFmt($fx['ur_min'], 0) . '% (aviso, não trava)' : $semFaixa,
        default => $semFaixa,
    };
}
