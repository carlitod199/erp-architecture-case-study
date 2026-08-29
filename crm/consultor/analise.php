<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Laudo (detalhe da análise)
   Rota: /crm/consultor/analise?id=AN-xxxx · fallback: 1º laudo
   Resultados × faixa de referência com barras CSS próprias,
   fiel a docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.analise)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* TODO mover para _mock.php */
/* Parâmetro: [nome, unidade, resultado, faixaMin, faixaMax, escalaMin, escalaMax] */
$SOLO_OK = [
    ['pH em água', '', 6.0, 5.5, 6.5, 4, 8], ['Matéria orgânica', 'g/kg', 22.6, 15, 30, 0, 45],
    ['Fósforo (Mehlich-1)', 'mg/dm³', 28, 11, 40, 0, 60], ['Potássio', 'cmolc/dm³', 0.24, 0.16, 0.40, 0, 0.6],
    ['Cálcio', 'cmolc/dm³', 3.4, 1.6, 4.0, 0, 8], ['Magnésio', 'cmolc/dm³', 1.0, 0.7, 1.5, 0, 3],
    ['Alumínio', 'cmolc/dm³', 0, 0, 0.4, 0, 2], ['H + Al', 'cmolc/dm³', 1.4, 0, 2.5, 0, 6],
    ['Soma de bases (SB)', 'cmolc/dm³', 4.64, 2.8, 5.6, 0, 10], ['CTC a pH 7 (T)', 'cmolc/dm³', 6.04, 5.0, 10.0, 0, 15],
    ['Saturação por bases (V)', '%', 77, 60, 80, 0, 100], ['Enxofre (S-SO₄)', 'mg/dm³', 7.8, 5, 10, 0, 20],
    ['Boro', 'mg/dm³', 0.52, 0.35, 0.90, 0, 1.5], ['Cobre', 'mg/dm³', 1.3, 0.8, 1.8, 0, 3],
    ['Ferro', 'mg/dm³', 36, 20, 45, 0, 80], ['Manganês', 'mg/dm³', 8.6, 5, 12, 0, 25],
    ['Zinco', 'mg/dm³', 1.8, 1.0, 2.2, 0, 4], ['Condutividade elétrica', 'dS/m', 0.38, 0, 0.8, 0, 2],
    ['Sódio trocável (PST)', '%', 3.2, 0, 6, 0, 20],
    ['Relação Ca/Mg', '', 3.4, 3, 5, 0, 8], ['Relação Ca/K', '', 14.2, 10, 25, 0, 50], ['Relação Mg/K', '', 4.2, 3, 6, 0, 15],
];
$SOLO_K = [
    ['pH em água', '', 6.2, 5.5, 6.5, 4, 8], ['Matéria orgânica', 'g/kg', 18.4, 15, 30, 0, 45],
    ['Fósforo (Mehlich-1)', 'mg/dm³', 24, 11, 40, 0, 60], ['Potássio', 'cmolc/dm³', 0.11, 0.16, 0.40, 0, 0.6],
    ['Cálcio', 'cmolc/dm³', 3.1, 1.6, 4.0, 0, 8], ['Magnésio', 'cmolc/dm³', 1.1, 0.7, 1.5, 0, 3],
    ['Alumínio', 'cmolc/dm³', 0, 0, 0.4, 0, 2], ['H + Al', 'cmolc/dm³', 1.6, 0, 2.5, 0, 6],
    ['Soma de bases (SB)', 'cmolc/dm³', 4.31, 2.8, 5.6, 0, 10], ['CTC a pH 7 (T)', 'cmolc/dm³', 5.91, 5.0, 10.0, 0, 15],
    ['Saturação por bases (V)', '%', 73, 60, 80, 0, 100], ['Enxofre (S-SO₄)', 'mg/dm³', 8.2, 5, 10, 0, 20],
    ['Boro', 'mg/dm³', 0.48, 0.35, 0.90, 0, 1.5], ['Cobre', 'mg/dm³', 1.2, 0.8, 1.8, 0, 3],
    ['Ferro', 'mg/dm³', 32, 20, 45, 0, 80], ['Manganês', 'mg/dm³', 9.4, 5, 12, 0, 25],
    ['Zinco', 'mg/dm³', 1.6, 1.0, 2.2, 0, 4], ['Condutividade elétrica', 'dS/m', 0.42, 0, 0.8, 0, 2],
    ['Sódio trocável (PST)', '%', 4.1, 0, 6, 0, 20],
    ['Relação Ca/Mg', '', 2.8, 3, 5, 0, 8], ['Relação Ca/K', '', 28.2, 10, 25, 0, 50], ['Relação Mg/K', '', 10.0, 3, 6, 0, 15],
];
$SOLO_SAL = [
    ['pH em água', '', 7.4, 5.5, 6.5, 4, 8], ['Matéria orgânica', 'g/kg', 14.2, 15, 30, 0, 45],
    ['Fósforo (Mehlich-1)', 'mg/dm³', 31, 11, 40, 0, 60], ['Potássio', 'cmolc/dm³', 0.28, 0.16, 0.40, 0, 0.6],
    ['Cálcio', 'cmolc/dm³', 4.6, 1.6, 4.0, 0, 8], ['Magnésio', 'cmolc/dm³', 1.4, 0.7, 1.5, 0, 3],
    ['Alumínio', 'cmolc/dm³', 0, 0, 0.4, 0, 2], ['H + Al', 'cmolc/dm³', 0.9, 0, 2.5, 0, 6],
    ['Soma de bases (SB)', 'cmolc/dm³', 6.40, 2.8, 5.6, 0, 10], ['CTC a pH 7 (T)', 'cmolc/dm³', 7.30, 5.0, 10.0, 0, 15],
    ['Saturação por bases (V)', '%', 88, 60, 80, 0, 100], ['Enxofre (S-SO₄)', 'mg/dm³', 12.4, 5, 10, 0, 20],
    ['Boro', 'mg/dm³', 0.62, 0.35, 0.90, 0, 1.5], ['Cobre', 'mg/dm³', 1.4, 0.8, 1.8, 0, 3],
    ['Ferro', 'mg/dm³', 26, 20, 45, 0, 80], ['Manganês', 'mg/dm³', 7.1, 5, 12, 0, 25],
    ['Zinco', 'mg/dm³', 1.3, 1.0, 2.2, 0, 4], ['Condutividade elétrica', 'dS/m', 1.68, 0, 0.8, 0, 2],
    ['Sódio trocável (PST)', '%', 11.4, 0, 6, 0, 20],
    ['Relação Ca/Mg', '', 3.3, 3, 5, 0, 8], ['Relação Ca/K', '', 16.4, 10, 25, 0, 50], ['Relação Mg/K', '', 5.0, 3, 6, 0, 15],
];
$FOL_B = [
    ['N-nitrato', 'mg/kg', 780, 500, 1200, 0, 1800], ['Nitrogênio total', 'g/kg', 21.4, 17, 30, 0, 40],
    ['Fósforo', 'g/kg', 2.2, 1.5, 5.0, 0, 7], ['Potássio', 'g/kg', 14.8, 10, 20, 0, 30],
    ['Cálcio', 'g/kg', 16.2, 10, 30, 0, 40], ['Magnésio', 'g/kg', 5.4, 3, 15, 0, 20],
    ['Boro', 'mg/kg', 24, 30, 100, 0, 140], ['Cobre', 'mg/kg', 11, 6, 40, 0, 60],
    ['Ferro', 'mg/kg', 96, 40, 300, 0, 400], ['Manganês', 'mg/kg', 58, 30, 150, 0, 200],
    ['Zinco', 'mg/kg', 31, 25, 100, 0, 140],
];
$FOL_CA = [
    ['N-nitrato', 'mg/kg', 1340, 500, 1200, 0, 1800], ['Nitrogênio total', 'g/kg', 32.6, 17, 30, 0, 40],
    ['Fósforo', 'g/kg', 2.4, 1.5, 5.0, 0, 7], ['Potássio', 'g/kg', 21.8, 10, 20, 0, 30],
    ['Cálcio', 'g/kg', 9.4, 10, 30, 0, 40], ['Magnésio', 'g/kg', 2.7, 3, 15, 0, 20],
    ['Boro', 'mg/kg', 34, 30, 100, 0, 140], ['Cobre', 'mg/kg', 9, 6, 40, 0, 60],
    ['Ferro', 'mg/kg', 78, 40, 300, 0, 400], ['Manganês', 'mg/kg', 44, 30, 150, 0, 200],
    ['Zinco', 'mg/kg', 27, 25, 100, 0, 140],
];
$FOL_ZN = [
    ['N-nitrato', 'mg/kg', 620, 500, 1200, 0, 1800], ['Nitrogênio total', 'g/kg', 19.8, 17, 30, 0, 40],
    ['Fósforo', 'g/kg', 1.9, 1.5, 5.0, 0, 7], ['Potássio', 'g/kg', 12.4, 10, 20, 0, 30],
    ['Cálcio', 'g/kg', 13.6, 10, 30, 0, 40], ['Magnésio', 'g/kg', 4.1, 3, 15, 0, 20],
    ['Boro', 'mg/kg', 38, 30, 100, 0, 140], ['Cobre', 'mg/kg', 8, 6, 40, 0, 60],
    ['Ferro', 'mg/kg', 62, 40, 300, 0, 400], ['Manganês', 'mg/kg', 41, 30, 150, 0, 200],
    ['Zinco', 'mg/kg', 18, 25, 100, 0, 140],
];
$FOL_MG = [
    ['Nitrogênio', 'g/kg', 17.5, 12, 14, 0, 22], ['Fósforo', 'g/kg', 1.2, 0.8, 1.6, 0, 2.5],
    ['Potássio', 'g/kg', 6.8, 5.0, 10.0, 0, 14], ['Cálcio', 'g/kg', 24.6, 20, 35, 0, 50],
    ['Magnésio', 'g/kg', 3.4, 2.5, 5.0, 0, 8], ['Enxofre', 'g/kg', 1.1, 0.8, 1.8, 0, 2.5],
    ['Boro', 'mg/kg', 62, 50, 100, 0, 150], ['Cobre', 'mg/kg', 22, 10, 50, 0, 70],
    ['Ferro', 'mg/kg', 118, 50, 200, 0, 260], ['Manganês', 'mg/kg', 74, 50, 100, 0, 140],
    ['Zinco', 'mg/kg', 28, 20, 40, 0, 60], ['Cloro', 'mg/kg', 640, 100, 900, 0, 1800],
];

$ANALISES = [
    'AN-0424' => [
        'tipo' => 'Foliar', 'talhao' => 'U-09', 'vari' => 'Itália', 'ha' => 12,
        'propn' => 'Fazenda Bom Jesus', 'prod' => 'Helena Vasconcelos', 'mun' => 'Petrolina · PE',
        'lab' => 'NutriFolha Juazeiro', 'prot' => 'NF-2026/1180', 'coleta' => '12/08/2026', 'emissao' => '19/08/2026',
        'origem' => 'Import PDF', 'tecido' => 'Pecíolo', 'estagio' => 'Pleno florescimento',
        'amostra' => '100 pecíolos · folha oposta ao 1º cacho', 'ref' => 'Embrapa CT-100 · Tab. 8 (pecíolo, videira)',
        'params' => $FOL_ZN, 'status' => 'Interpretada',
        'diag' => 'Zinco no limite inferior da deficiência, com histórico de "folha pequena" no talhão. Demais nutrientes adequados.',
        'acoes' => ['Sulfato de zinco foliar 0,3% em 2–3 aplicações a partir de 4–5 folhas expandidas', 'Zn via solo na adubação de fundação do próximo ciclo'],
        'rec' => 'R-0918', 'opor' => ''],
    'AN-0421' => [
        'tipo' => 'Foliar', 'talhao' => 'U-07', 'vari' => 'Arra 15', 'ha' => 38,
        'propn' => 'Fazenda Nova Aliança', 'prod' => 'Fernanda Sá', 'mun' => 'Santa Maria da Boa Vista · PE',
        'lab' => 'NutriFolha Juazeiro', 'prot' => 'NF-2026/1174', 'coleta' => '08/08/2026', 'emissao' => '15/08/2026',
        'origem' => 'Import PDF', 'tecido' => 'Pecíolo', 'estagio' => 'Pleno florescimento',
        'amostra' => '100 pecíolos · folha oposta ao 1º cacho', 'ref' => 'Embrapa CT-100 · Tab. 8 (pecíolo, videira)',
        'params' => $FOL_CA, 'status' => 'Interpretada',
        'diag' => 'Desequilíbrio catiônico clássico: N e K acima do adequado com Ca e Mg abaixo. É a explicação nutricional da rachadura de baga de 12% observada na visita V213 — o cacho cresce mais rápido do que a parede celular consegue acompanhar.',
        'acoes' => ['Reduzir N a partir do chumbinho e suspender fonte nítrica na 2ª fase de crescimento', 'Nitrato de cálcio via fertirrigação do chumbinho ao amolecimento', 'Revisar lâmina de irrigação na 2ª fase — oscilação agrava a rachadura'],
        'rec' => 'R-0905', 'opor' => 'O-118'],
    'AN-0418' => [
        'tipo' => 'Foliar', 'talhao' => 'U-02', 'vari' => 'Arra 15', 'ha' => 22,
        'propn' => 'Fazenda Boa Vista', 'prod' => 'João Almeida', 'mun' => 'Petrolina · PE',
        'lab' => 'NutriFolha Juazeiro', 'prot' => 'NF-2026/1169', 'coleta' => '04/08/2026', 'emissao' => '11/08/2026',
        'origem' => 'Import PDF', 'tecido' => 'Pecíolo', 'estagio' => 'Pleno florescimento',
        'amostra' => '100 pecíolos · folha oposta ao 1º cacho', 'ref' => 'Embrapa CT-100 · Tab. 8 (pecíolo, videira)',
        'params' => $FOL_B, 'status' => 'Interpretada',
        'diag' => 'Boro deficiente em plena floração. Risco direto de mau pegamento e millerandage (cachos ralos com bagas miúdas sem semente) neste ciclo.',
        'acoes' => ['Ácido bórico 0,1–0,2% em duas aplicações antes da antese', 'Boro via solo na adubação do ciclo seguinte'],
        'rec' => 'R-0916', 'opor' => 'O-126'],
    'AN-0415' => [
        'tipo' => 'Foliar', 'talhao' => 'M-02', 'vari' => 'Palmer', 'ha' => 20,
        'propn' => 'Fazenda Vale Verde', 'prod' => 'Maria Oliveira', 'mun' => 'Juazeiro · BA',
        'lab' => 'Agrolab Vale', 'prot' => 'AV-2026/4478', 'coleta' => '30/07/2026', 'emissao' => '07/08/2026',
        'origem' => 'Digitação manual', 'tecido' => 'Folha inteira', 'estagio' => '1–2 meses antes do florescimento',
        'amostra' => '4 folhas × 20 plantas · penúltimo fluxo, terço médio', 'ref' => 'Embrapa CT-88 · Tab. 6 (mangueira)',
        'params' => $FOL_MG, 'status' => 'Interpretada',
        'diag' => 'Nitrogênio acima do teor adequado às vésperas da indução. Excesso de N compete com a indução floral: tende a puxar fluxo vegetativo fora de época e produzir florada tardia e desuniforme.',
        'acoes' => ['Suspender N até a florada', 'Revisar dose de ureia da adubação pós-colheita do próximo ciclo', 'Reforçar o estresse hídrico programado e conferir o intervalo do PBZ'],
        'rec' => 'R-0914', 'opor' => ''],
    'AN-0412' => [
        'tipo' => 'Solo', 'talhao' => 'M-04', 'vari' => 'Kent', 'ha' => 33,
        'propn' => 'Fazenda Riacho Grande', 'prod' => 'Roberto Nakamura', 'mun' => 'Curaçá · BA',
        'lab' => 'Agrolab Vale', 'prot' => 'AV-2026/4412', 'coleta' => '28/07/2026', 'emissao' => '04/08/2026',
        'origem' => 'Import PDF', 'tecido' => '—', 'estagio' => 'Pós-colheita / repouso',
        'amostra' => '20 simples · 0–20 cm · projeção da copa', 'ref' => 'Embrapa CT-100 (classes) · Incaper (micros)',
        'params' => $SOLO_K, 'status' => 'Interpretada',
        'diag' => 'Potássio baixo, com Ca/K e Mg/K desequilibrados como consequência. É a origem da proposta de adubação de reposição já em pipeline.',
        'acoes' => ['Adubação de reposição com 180 kg/ha de K₂O parcelado na fertirrigação', 'Reavaliar Ca/Mg na próxima amostragem — está levemente abaixo de 3:1'],
        'rec' => 'R-0907', 'opor' => 'O-119'],
    'AN-0409' => [
        'tipo' => 'Solo', 'talhao' => 'U-04', 'vari' => 'Timpson', 'ha' => 32,
        'propn' => 'Fazenda Santa Helena', 'prod' => 'Carlos Mendes', 'mun' => 'Lagoa Grande · PE',
        'lab' => 'Agrolab Vale', 'prot' => 'AV-2026/4390', 'coleta' => '21/07/2026', 'emissao' => '29/07/2026',
        'origem' => 'Import PDF', 'tecido' => '—', 'estagio' => 'Pós-colheita',
        'amostra' => '20 simples · 0–20 cm · bulbo molhado', 'ref' => 'Embrapa CT-100 (classes) · USSL (salinidade)',
        'params' => $SOLO_SAL, 'status' => 'Interpretada',
        'diag' => 'Acúmulo de sais no bulbo molhado com sodicidade incipiente: CE 1,68 dS/m e PST 11,4%. pH e V% elevados acompanham o quadro. Sem correção, a tendência é queima de borda foliar e perda de vigor progressiva no talhão.',
        'acoes' => ['Aumentar a fração de lixiviação no manejo de irrigação', 'Substituir KCl por K₂SO₄ ou KNO₃ na fertirrigação', 'Gesso agrícola para deslocar o sódio do complexo de troca', 'Monitorar CE do bulbo mensalmente'],
        'rec' => 'R-0911', 'opor' => 'O-127'],
    'AN-0405' => [
        'tipo' => 'Solo', 'talhao' => 'U-01', 'vari' => 'BRS Vitória', 'ha' => 18,
        'propn' => 'Fazenda Boa Vista', 'prod' => 'João Almeida', 'mun' => 'Petrolina · PE',
        'lab' => 'Agrolab Vale', 'prot' => 'AV-2026/4356', 'coleta' => '09/07/2026', 'emissao' => '17/07/2026',
        'origem' => 'Import PDF', 'tecido' => '—', 'estagio' => 'Pós-colheita',
        'amostra' => '20 simples · 0–20 cm · projeção da copa', 'ref' => 'Embrapa CT-100 (classes) · Incaper (micros)',
        'params' => $SOLO_OK, 'status' => 'Interpretada',
        'diag' => 'Todos os parâmetros dentro da faixa adequada. Manter o programa de adubação vigente e reamostrar após a próxima colheita.',
        'acoes' => ['Manter programa de fertirrigação atual', 'Reamostrar após a colheita de outubro'],
        'rec' => '', 'opor' => ''],
    'AN-0428' => [
        'tipo' => 'Foliar', 'talhao' => 'M-01', 'vari' => 'Palmer', 'ha' => 30,
        'propn' => 'Fazenda Boa Vista II', 'prod' => 'João Almeida', 'mun' => 'Lagoa Grande · PE',
        'lab' => 'Agrolab Vale', 'prot' => 'AV-2026/4520', 'coleta' => '20/08/2026', 'emissao' => '—',
        'origem' => 'Aguardando lab.', 'tecido' => 'Folha inteira', 'estagio' => 'Pré-indução',
        'amostra' => '4 folhas × 20 plantas · penúltimo fluxo', 'ref' => 'Embrapa CT-88 · Tab. 6 (mangueira)',
        'params' => null, 'status' => 'Em análise',
        'diag' => 'Coletada antes da janela de PBZ para calibrar o programa de nutrição da indução. Prazo do laboratório: 5 dias úteis.',
        'acoes' => [], 'rec' => '', 'opor' => ''],
];

$id = (string)($_GET['id'] ?? '');
if (!isset($ANALISES[$id])) $id = (string)array_key_first($ANALISES);   /* fallback: 1º */
$a = $ANALISES[$id];

/* Classificação do parâmetro contra a faixa adequada */
$classifica = function (array $p): string {
    [, , $v, $min, $max] = $p;
    if ($v < $min) return 'baixo';
    if ($v > $max) return 'alto';
    return 'adequado';
};
/* Rótulo pt-BR da classificação (foliar usa Deficiente/Excessivo) */
$rotulo = function (string $c, string $tipo): array {
    if ($c === 'adequado') return ['Adequado', 'green'];
    if ($c === 'baixo')    return [$tipo === 'Foliar' ? 'Deficiente' : 'Baixo', 'red'];
    return [$tipo === 'Foliar' ? 'Excessivo' : 'Alto', 'amber'];
};
/* Número pt-BR sem zeros à direita (6.0 → 6 · 0.24 → 0,24) */
$fmt = function (float $v): string {
    $s = number_format($v, 2, ',', '.');
    $s = rtrim(rtrim($s, '0'), ',');
    return $s === '' || $s === '-' ? '0' : $s;
};
/* Posição 0–100 na escala da barra */
$pos = function (float $x, float $lo, float $hi): float {
    $w = $hi - $lo;
    if ($w <= 0) return 0.0;
    return max(0.0, min(100.0, ($x - $lo) / $w * 100));
};

$desvios = $a['params'] === null ? null : array_values(array_filter($a['params'], fn($p) => $classifica($p) !== 'adequado'));

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'analise',
    'titulo' => 'Laudo',
]);
?>

<style>
/* Barra de faixa de referência do nutriente — classe ausente no crm.css */
.crm-app .crng { position: relative; height: 17px; min-width: 170px; }
.crm-app .crng__track { position: absolute; left: 0; right: 0; top: 6px; height: 5px; border-radius: 99px; background: var(--track, #EEE6D6); }
.crm-app .crng__band  { position: absolute; top: 6px; height: 5px; border-radius: 99px; background: var(--crm-teal); opacity: .28; }
.crm-app .crng__mk {
  position: absolute; top: 3px; width: 11px; height: 11px; border-radius: 50%;
  transform: translateX(-50%); box-sizing: border-box;
  border: 2px solid #fff; box-shadow: 0 0 0 1px var(--crm-line2);
}
.crm-app .crng__mk.adequado { background: var(--crm-green); }
.crm-app .crng__mk.baixo    { background: var(--crm-red); }
.crm-app .crng__mk.alto     { background: var(--crm-amber); }
.crm-app .crng-leg { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; color: var(--crm-ink2); }
.crm-app .crng-leg i { display: inline-block; border-radius: 99px; }
.crm-app .crng-leg .lg-band { width: 18px; height: 5px; background: var(--crm-teal); opacity: .28; }
.crm-app .crng-leg .lg-mk { width: 9px; height: 9px; background: var(--crm-green); }
</style>

<a class="crm-crumb" href="<?= crm_url('consultor', 'analises') ?>">‹ Análises · Solo e Foliar</a>

<?php if ($a['params'] === null): ?>

<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title">Laudo <?= h($id) ?> · em análise</span>
    <?= crm_pill('Aguardando o laboratório', 'amber') ?>
  </div>
  <?= crm_callout(h($a['diag']), 'amber') ?>
  <?= crm_kv('Talhão', crm_pill($a['talhao'], 'grey') . ' ' . h($a['vari']) . ' · ' . (int)$a['ha'] . ' ha') ?>
  <?= crm_kv('Propriedade', h($a['propn']) . ' · ' . h($a['prod'])) ?>
  <?= crm_kv('Coleta', h($a['coleta'])) ?>
  <?= crm_kv('Laboratório', h($a['lab']) . ' · ' . h($a['prot'])) ?>
  <?= crm_kv('Amostragem', h($a['amostra'])) ?>
  <?= crm_kv('Referência de interpretação', h($a['ref'])) ?>
</div>

<?php else: ?>

<?php if ($desvios): ?>
  <?= crm_callout(
      '<strong>' . count($desvios) . ' parâmetro(s) fora da faixa:</strong> '
      . h(implode(' · ', array_map(fn($p) => $p[0] . ' (' . $rotulo($classifica($p), $a['tipo'])[0] . ')', $desvios))),
      count($desvios) > 2 ? 'red' : 'amber'
  ) ?>
<?php else: ?>
  <?= crm_callout('<strong>Todos os parâmetros dentro da faixa adequada.</strong> Nenhuma correção necessária neste ciclo.', 'green') ?>
<?php endif; ?>

<div class="crm-g12">
  <div>
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title"><?= h($a['tipo']) ?> · <?= h($id) ?></span>
        <?= crm_pill($a['status'], 'green') ?>
      </div>
      <div style="font-size:16px;font-weight:700;margin-bottom:10px"><?= h($a['vari']) ?> · <?= crm_pill($a['talhao'], 'grey') ?></div>
      <?= crm_kv('Propriedade', h($a['propn']) . '<div class="crm-sub">' . h($a['prod']) . ' · ' . h($a['mun']) . '</div>') ?>
      <?= crm_kv('Área do talhão', (int)$a['ha'] . ' ha') ?>
      <?= crm_kv('Coleta / emissão', h($a['coleta']) . ' → ' . h($a['emissao'])) ?>
      <?= crm_kv('Estágio na coleta', h($a['estagio'])) ?>
      <?php if ($a['tecido'] !== '—'): ?><?= crm_kv('Tecido', h($a['tecido'])) ?><?php endif; ?>
      <?= crm_kv('Amostragem', h($a['amostra'])) ?>
      <?= crm_kv('Laboratório', h($a['lab']) . '<div class="crm-sub">' . h($a['prot']) . '</div>') ?>
      <?= crm_kv('Origem no sistema', crm_pill($a['origem'], 'grey') . ' ' . crm_demo('leitura do laudo')) ?>
      <?= crm_kv('Referência', h($a['ref'])) ?>
      <div style="display:flex;gap:7px;margin-top:14px">
        <button type="button" class="vbtn vbtn-sm vbtn-primary" style="flex:1" data-toast="Laudo em PDF enviado ao produtor">Enviar ao produtor</button>
        <button type="button" class="vbtn vbtn-sm vbtn-ghost" data-toast="Comparativo histórico · demonstrativo">Histórico</button>
      </div>
    </div>

    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Diagnóstico</span>
        <span class="crm-sub">Leitura do consultor</span>
      </div>
      <div style="font-size:13px;line-height:1.7"><?= h($a['diag']) ?></div>
    </div>

    <?php if ($a['acoes']): ?>
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Ações recomendadas</span>
        <span class="crm-sub">Do laudo para o campo</span>
      </div>
      <ul style="margin:0;padding-left:18px;font-size:12.8px;line-height:1.8">
        <?php foreach ($a['acoes'] as $ac): ?><li><?= h($ac) ?></li><?php endforeach; ?>
      </ul>
      <?php if ($a['rec'] !== '' || $a['opor'] !== ''): ?>
        <div style="display:flex;gap:6px;margin-top:13px;flex-wrap:wrap">
          <?php if ($a['rec'] !== ''): ?>
            <a class="vbtn vbtn-sm vbtn-ghost" href="<?= crm_url('consultor', 'recomendacoes') ?>">Recomendação <?= h($a['rec']) ?></a>
          <?php endif; ?>
          <?php if ($a['opor'] !== ''): ?>
            <a class="vbtn vbtn-sm vbtn-ghost" href="<?= crm_url('consultor', 'oportunidade') ?>?id=<?= h($a['opor']) ?>">Oportunidade <?= h($a['opor']) ?></a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div style="margin-top:13px">
          <button type="button" class="vbtn vbtn-sm" data-toast="Recomendação criada a partir do laudo">Gerar recomendação</button>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="crm-card" style="padding:0;overflow:hidden">
    <div class="crm-card__head" style="padding:14px 18px 0;margin-bottom:10px">
      <span class="crm-card__title">Resultados × faixa de referência</span>
      <span class="crng-leg"><i class="lg-band"></i>faixa adequada <i class="lg-mk"></i>resultado</span>
    </div>
    <div class="crm-tblwrap">
      <table class="crm-tbl">
        <thead>
          <tr>
            <th>Parâmetro</th>
            <th>Unid.</th>
            <th class="num">Resultado</th>
            <th class="num">Faixa adequada</th>
            <th style="min-width:190px">Posição na faixa</th>
            <th>Classificação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($a['params'] as $p):
              [$nome, $unid, $v, $min, $max, $lo, $hi] = $p;
              $c = $classifica($p);
              [$rot, $rotCor] = $rotulo($c, $a['tipo']);
              $corValor = $c === 'adequado' ? 'inherit' : ($c === 'baixo' ? 'var(--crm-red)' : 'var(--crm-amber)');
              $bandL = $pos((float)$min, (float)$lo, (float)$hi);
              $bandW = $pos((float)$max, (float)$lo, (float)$hi) - $bandL;
              $mk    = $pos((float)$v, (float)$lo, (float)$hi);
          ?>
          <tr>
            <td style="white-space:nowrap"><strong><?= h($nome) ?></strong></td>
            <td style="white-space:nowrap;color:var(--crm-ink3)"><?= $unid !== '' ? h($unid) : '—' ?></td>
            <td class="num"><strong style="color:<?= $corValor ?>"><?= $fmt((float)$v) ?></strong></td>
            <td class="num" style="white-space:nowrap;color:var(--crm-ink2)"><?= $fmt((float)$min) ?> – <?= $fmt((float)$max) ?></td>
            <td>
              <div class="crng">
                <div class="crng__track"></div>
                <div class="crng__band" style="left:<?= sprintf('%.1f', $bandL) ?>%;width:<?= sprintf('%.1f', $bandW) ?>%"></div>
                <i class="crng__mk <?= h($c) ?>" style="left:<?= sprintf('%.1f', $mk) ?>%"></i>
              </div>
            </td>
            <td style="white-space:nowrap"><?= crm_pill($rot, $rotCor) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php endif; ?>

<?php crm_shell_end();
