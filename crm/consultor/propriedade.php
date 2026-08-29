<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Propriedade (detalhe da fazenda)
   Rota: /crm/consultor/propriedade?id=F01
   Fonte: docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.propriedade)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* Dados locais fiéis ao mockup — Vale do São Francisco (uva e manga).
   TODO mover para _mock.php */
$FENO_UVA   = ['Poda', 'Brotação', 'Cresc. veget.', 'Floração', 'Chumbinho', 'Amolecimento', 'Maturação', 'Colheita'];
$FENO_MANGA = ['Poda pós-colheita', 'Fluxo vegetativo', 'Indução (PBZ)', 'Estresse hídrico', 'Floração', 'Pegamento', 'Cresc. fruto', 'Colheita'];

$PROPRIEDADES = [
    'F01' => ['nome' => 'Fazenda Boa Vista',     'prodId' => 'P01', 'prodNome' => 'João Almeida',       'mun' => 'Petrolina · PE',                'loc' => '-9,3891 / -40,5030', 'ha' => 62, 'haProd' => 54, 'cultura' => 'Uva',         'vars' => ['BRS Vitória', 'Arra 15', 'Sweet Globe'], 'irrig' => 'Gotejamento',   'cond' => 'Latada',     'plantas' => 1333, 'ciclos' => '2,5', 'cert' => ['GLOBALG.A.P.', 'PIF'], 'destino' => 'Exportação UE / EUA',             'packing' => 'Próprio',  'resp' => 'André Almeida (técnico)', 'visitas' => 14, 'ultVisita' => '19/08/2026', 'pot' => 'R$ 860 mil', 'mediaPolo' => 'média do Vale: 20–26 t/ha'],
    'F07' => ['nome' => 'Fazenda Boa Vista II',  'prodId' => 'P01', 'prodNome' => 'João Almeida',       'mun' => 'Lagoa Grande · PE',             'loc' => '-8,9941 / -40,2760', 'ha' => 34, 'haProd' => 30, 'cultura' => 'Manga',       'vars' => ['Palmer', 'Kent'],                        'irrig' => 'Microaspersão', 'cond' => '—',          'plantas' => 278,  'ciclos' => '1',   'cert' => ['GLOBALG.A.P.'],        'destino' => 'Exportação UE',                   'packing' => 'Terceiro', 'resp' => 'André Almeida (técnico)', 'visitas' => 6,  'ultVisita' => '02/08/2026', 'pot' => 'R$ 540 mil', 'mediaPolo' => 'média do polo: 28 t/ha'],
    'F02' => ['nome' => 'Fazenda Santa Helena',  'prodId' => 'P02', 'prodNome' => 'Carlos Mendes',      'mun' => 'Lagoa Grande · PE',             'loc' => '-8,9905 / -40,2712', 'ha' => 64, 'haProd' => 58, 'cultura' => 'Uva',         'vars' => ['Timpson', 'Crimson Seedless', 'Itália'], 'irrig' => 'Gotejamento',   'cond' => 'Latada',     'plantas' => 1250, 'ciclos' => '2',   'cert' => ['GLOBALG.A.P.'],        'destino' => 'Exportação UE / Mercado interno', 'packing' => 'Próprio',  'resp' => 'Carlos Mendes',           'visitas' => 9,  'ultVisita' => '24/07/2026', 'pot' => 'R$ 980 mil', 'mediaPolo' => 'média do Vale: 20–26 t/ha'],
    'F03' => ['nome' => 'Fazenda Vale Verde',    'prodId' => 'P03', 'prodNome' => 'Maria Oliveira',     'mun' => 'Juazeiro · BA',                 'loc' => '-9,4112 / -40,4988', 'ha' => 41, 'haProd' => 38, 'cultura' => 'Manga',       'vars' => ['Palmer', 'Keitt', 'Tommy Atkins'],       'irrig' => 'Microaspersão', 'cond' => '—',          'plantas' => 312,  'ciclos' => '1',   'cert' => ['PIF', 'Rainforest'],   'destino' => 'Exportação UE',                   'packing' => 'Terceiro', 'resp' => 'Maria Oliveira',          'visitas' => 11, 'ultVisita' => '14/08/2026', 'pot' => 'R$ 610 mil', 'mediaPolo' => 'média do polo: 28 t/ha'],
    'F04' => ['nome' => 'Fazenda São José',      'prodId' => 'P04', 'prodNome' => 'Antônio Ribeiro',    'mun' => 'Casa Nova · BA',                'loc' => '-9,1620 / -40,9704', 'ha' => 28, 'haProd' => 24, 'cultura' => 'Uva / Manga', 'vars' => ['Itália', 'Benitaka', 'Rosa'],            'irrig' => 'Microaspersão', 'cond' => 'Latada',     'plantas' => 1111, 'ciclos' => '2',   'cert' => [],                      'destino' => 'Mercado interno',                 'packing' => 'Terceiro', 'resp' => 'Antônio Ribeiro',         'visitas' => 4,  'ultVisita' => '09/07/2026', 'pot' => 'R$ 430 mil', 'mediaPolo' => 'média do Vale: 20–26 t/ha'],
    'F05' => ['nome' => 'Fazenda Nova Aliança',  'prodId' => 'P05', 'prodNome' => 'Fernanda Sá',        'mun' => 'Santa Maria da Boa Vista · PE', 'loc' => '-8,8061 / -39,8266', 'ha' => 74, 'haProd' => 68, 'cultura' => 'Uva',         'vars' => ['Arra 15', 'Sugar Crisp', 'BRS Isis'],    'irrig' => 'Gotejamento',   'cond' => 'Latada / Y', 'plantas' => 1667, 'ciclos' => '2,5', 'cert' => ['GLOBALG.A.P.'],        'destino' => 'Exportação UE / RU',              'packing' => 'Próprio',  'resp' => 'Fernanda Sá',             'visitas' => 1,  'ultVisita' => '22/08/2026', 'pot' => 'R$ 1,1 mi',  'mediaPolo' => 'média do Vale: 20–26 t/ha'],
    'F06' => ['nome' => 'Fazenda Riacho Grande', 'prodId' => 'P06', 'prodNome' => 'Roberto Nakamura',   'mun' => 'Curaçá · BA',                   'loc' => '-9,0281 / -39,9098', 'ha' => 35, 'haProd' => 33, 'cultura' => 'Manga',       'vars' => ['Kent', 'Palmer'],                        'irrig' => 'Gotejamento',   'cond' => '—',          'plantas' => 250,  'ciclos' => '1',   'cert' => ['PIF'],                 'destino' => 'Exportação UE / Mercado interno', 'packing' => 'Terceiro', 'resp' => 'Roberto Nakamura',        'visitas' => 7,  'ultVisita' => '06/08/2026', 'pot' => 'R$ 520 mil', 'mediaPolo' => 'média do polo: 28 t/ha'],
    'F08' => ['nome' => 'Fazenda Bom Jesus',     'prodId' => 'P07', 'prodNome' => 'Helena Vasconcelos', 'mun' => 'Petrolina · PE',                'loc' => '-9,3455 / -40,5610', 'ha' => 14, 'haProd' => 12, 'cultura' => 'Uva',         'vars' => ['Itália', 'BRS Vitória'],                 'irrig' => 'Gotejamento',   'cond' => 'Latada',     'plantas' => 1333, 'ciclos' => '2',   'cert' => [],                      'destino' => 'Mercado interno',                 'packing' => 'Terceiro', 'resp' => 'Helena Vasconcelos',      'visitas' => 5,  'ultVisita' => '17/08/2026', 'pot' => 'R$ 190 mil', 'mediaPolo' => 'média do Vale: 20–26 t/ha'],
    'F09' => ['nome' => 'Fazenda Serra Branca',  'prodId' => 'P08', 'prodNome' => 'José Bezerra',       'mun' => 'Orocó · PE',                    'loc' => '-8,6103 / -39,6011', 'ha' => 22, 'haProd' => 20, 'cultura' => 'Manga',       'vars' => ['Tommy Atkins', 'Espada'],                'irrig' => 'Microaspersão', 'cond' => '—',          'plantas' => 400,  'ciclos' => '1',   'cert' => [],                      'destino' => 'Mercado interno',                 'packing' => 'Terceiro', 'resp' => 'José Bezerra',            'visitas' => 3,  'ultVisita' => '25/06/2026', 'pot' => 'R$ 380 mil', 'mediaPolo' => 'média do polo: 28 t/ha'],
];

/* Talhões por propriedade — 'feno': uva|manga; 'estagio': índice do estágio atual */
$TALHOES = [
    'F01' => [
        ['cod' => 'U-01', 'cultura' => 'Uva',   'vari' => 'BRS Vitória',      'ha' => 18, 'plantio' => 2019, 'feno' => 'uva',   'estagio' => 5, 'dias' => 78,  'podaEm' => '08/06/2026', 'colheita' => '12/10/2026', 'prodEsp' => '26 t/ha', 'ciclo' => '2026.2',  'risco' => 'ok',       'carencia' => 0],
        ['cod' => 'U-02', 'cultura' => 'Uva',   'vari' => 'Arra 15',          'ha' => 22, 'plantio' => 2021, 'feno' => 'uva',   'estagio' => 3, 'dias' => 36,  'podaEm' => '20/07/2026', 'colheita' => '22/11/2026', 'prodEsp' => '24 t/ha', 'ciclo' => '2026.2',  'risco' => 'atencao',  'carencia' => 0],
        ['cod' => 'U-03', 'cultura' => 'Uva',   'vari' => 'Sweet Globe',      'ha' => 14, 'plantio' => 2022, 'feno' => 'uva',   'estagio' => 6, 'dias' => 98,  'podaEm' => '19/05/2026', 'colheita' => '21/09/2026', 'prodEsp' => '22 t/ha', 'ciclo' => '2026.2',  'risco' => 'bloqueio', 'carencia' => 14],
    ],
    'F07' => [
        ['cod' => 'M-01', 'cultura' => 'Manga', 'vari' => 'Palmer',           'ha' => 30, 'plantio' => 2016, 'feno' => 'manga', 'estagio' => 2, 'dias' => 41,  'podaEm' => '15/07/2026', 'colheita' => '—',          'prodEsp' => '29 t/ha', 'ciclo' => '2026/27', 'risco' => 'ok',       'carencia' => 0],
    ],
    'F02' => [
        ['cod' => 'U-04', 'cultura' => 'Uva',   'vari' => 'Timpson',          'ha' => 32, 'plantio' => 2020, 'feno' => 'uva',   'estagio' => 4, 'dias' => 52,  'podaEm' => '04/07/2026', 'colheita' => '01/11/2026', 'prodEsp' => '23 t/ha', 'ciclo' => '2026.2',  'risco' => 'atencao',  'carencia' => 0],
        ['cod' => 'U-05', 'cultura' => 'Uva',   'vari' => 'Crimson Seedless', 'ha' => 26, 'plantio' => 2018, 'feno' => 'uva',   'estagio' => 2, 'dias' => 22,  'podaEm' => '03/08/2026', 'colheita' => '04/12/2026', 'prodEsp' => '20 t/ha', 'ciclo' => '2026.2',  'risco' => 'ok',       'carencia' => 0],
    ],
    'F03' => [
        ['cod' => 'M-02', 'cultura' => 'Manga', 'vari' => 'Palmer',           'ha' => 20, 'plantio' => 2015, 'feno' => 'manga', 'estagio' => 4, 'dias' => 96,  'podaEm' => '21/05/2026', 'colheita' => '—',          'prodEsp' => '31 t/ha', 'ciclo' => '2026/27', 'risco' => 'ok',       'carencia' => 0],
        ['cod' => 'M-03', 'cultura' => 'Manga', 'vari' => 'Keitt',            'ha' => 18, 'plantio' => 2017, 'feno' => 'manga', 'estagio' => 1, 'dias' => 14,  'podaEm' => '11/08/2026', 'colheita' => '—',          'prodEsp' => '27 t/ha', 'ciclo' => '2026/27', 'risco' => 'ok',       'carencia' => 0],
    ],
    'F04' => [
        ['cod' => 'U-06', 'cultura' => 'Uva',   'vari' => 'Itália',           'ha' => 16, 'plantio' => 2014, 'feno' => 'uva',   'estagio' => 5, 'dias' => 81,  'podaEm' => '05/06/2026', 'colheita' => '08/10/2026', 'prodEsp' => '19 t/ha', 'ciclo' => '2026.2',  'risco' => 'atencao',  'carencia' => 0],
    ],
    'F05' => [
        ['cod' => 'U-07', 'cultura' => 'Uva',   'vari' => 'Arra 15',          'ha' => 38, 'plantio' => 2022, 'feno' => 'uva',   'estagio' => 5, 'dias' => 74,  'podaEm' => '12/06/2026', 'colheita' => '15/10/2026', 'prodEsp' => '25 t/ha', 'ciclo' => '2026.2',  'risco' => 'bloqueio', 'carencia' => 0],
        ['cod' => 'U-08', 'cultura' => 'Uva',   'vari' => 'Sugar Crisp',      'ha' => 30, 'plantio' => 2023, 'feno' => 'uva',   'estagio' => 3, 'dias' => 33,  'podaEm' => '23/07/2026', 'colheita' => '25/11/2026', 'prodEsp' => '21 t/ha', 'ciclo' => '2026.2',  'risco' => 'ok',       'carencia' => 0],
    ],
    'F06' => [
        ['cod' => 'M-04', 'cultura' => 'Manga', 'vari' => 'Kent',             'ha' => 33, 'plantio' => 2013, 'feno' => 'manga', 'estagio' => 3, 'dias' => 62,  'podaEm' => '24/06/2026', 'colheita' => '—',          'prodEsp' => '28 t/ha', 'ciclo' => '2026/27', 'risco' => 'ok',       'carencia' => 0],
    ],
    'F08' => [
        ['cod' => 'U-09', 'cultura' => 'Uva',   'vari' => 'Itália',           'ha' => 12, 'plantio' => 2012, 'feno' => 'uva',   'estagio' => 6, 'dias' => 104, 'podaEm' => '13/05/2026', 'colheita' => '15/09/2026', 'prodEsp' => '18 t/ha', 'ciclo' => '2026.2',  'risco' => 'atencao',  'carencia' => 7],
    ],
    'F09' => [
        ['cod' => 'M-05', 'cultura' => 'Manga', 'vari' => 'Tommy Atkins',     'ha' => 20, 'plantio' => 2011, 'feno' => 'manga', 'estagio' => 0, 'dias' => 6,   'podaEm' => '19/08/2026', 'colheita' => '—',          'prodEsp' => '22 t/ha', 'ciclo' => '2026/27', 'risco' => 'ok',       'carencia' => 0],
    ],
];

/* Visitas realizadas por propriedade */
$VISITAS = [
    'F01' => [['dt' => '19/08/2026', 'tipo' => 'Técnica · 2h10', 'obj' => 'Monitoramento fitossanitário pós-chuva',
               'resumo' => 'Choveu 18 mm no dia 17. Encontrados focos iniciais de míldio no T02 (Arra 15), face norte. T03 entra em carência — última aplicação em 18/08 com produto de 14 dias.']],
    'F07' => [['dt' => '02/08/2026', 'tipo' => 'Técnica · 1h10', 'obj' => 'Avaliar 2º fluxo vegetativo antes da janela de PBZ',
               'resumo' => '2º fluxo em maturação. Janela de aplicação de PBZ estimada para a primeira semana de setembro. Volume estimado para 30 ha.']],
    'F02' => [],
    'F03' => [['dt' => '14/08/2026', 'tipo' => 'Técnica · 1h30', 'obj' => 'Acompanhar resposta à indução floral',
               'resumo' => 'PBZ aplicado em 21/05 no T07. Ramos maduros, resposta uniforme. Importador incluiu duas moléculas na lista de restrição — revisar o programa de floração.']],
    'F04' => [],
    'F05' => [['dt' => '22/08/2026', 'tipo' => 'Prospecção · 1h50', 'obj' => 'Primeira visita — diagnóstico geral',
               'resumo' => 'Pomar de Arra 15 com 12% de rachadura de baga no T10, concentrada nas linhas 4–9. Sem programa de cálcio definido. Compra hoje 100% da Agroinsumos Vale.']],
    'F06' => [['dt' => '06/08/2026', 'tipo' => 'Técnica · 1h20', 'obj' => 'Poda pós-colheita e adubação de reposição',
               'resumo' => 'Poda de limpeza concluída em 60% da área. Análise de solo do T12 indica K abaixo do ideal. Roberto quer proposta de adubação de reposição.']],
    'F08' => [['dt' => '17/08/2026', 'tipo' => 'Pós-venda · 0h50', 'obj' => 'Entrega e orientação de aplicação',
               'resumo' => 'Entregue o pedido PD-3391. Orientada a aplicação do bioinsumo no T13. Helena quer avaliar resultado antes de ampliar para o ciclo seguinte.']],
    'F09' => [],
];

/* Análises de solo e foliar por propriedade — 'desvios': null = em análise */
$ANALISES = [
    'F01' => [
        ['id' => 'AN-0418', 'tipo' => 'Foliar', 'talhao' => 'U-02', 'coleta' => '04/08/2026', 'lab' => 'NutriFolha Juazeiro', 'estagio' => 'Pleno florescimento',   'desvios' => 1],
        ['id' => 'AN-0405', 'tipo' => 'Solo',   'talhao' => 'U-01', 'coleta' => '09/07/2026', 'lab' => 'Agrolab Vale',        'estagio' => 'Pós-colheita',          'desvios' => 0],
    ],
    'F07' => [['id' => 'AN-0428', 'tipo' => 'Foliar', 'talhao' => 'M-01', 'coleta' => '20/08/2026', 'lab' => 'Agrolab Vale',        'estagio' => 'Pré-indução',           'desvios' => null]],
    'F02' => [['id' => 'AN-0409', 'tipo' => 'Solo',   'talhao' => 'U-04', 'coleta' => '21/07/2026', 'lab' => 'Agrolab Vale',        'estagio' => 'Pós-colheita',          'desvios' => 3]],
    'F03' => [['id' => 'AN-0415', 'tipo' => 'Foliar', 'talhao' => 'M-02', 'coleta' => '30/07/2026', 'lab' => 'Agrolab Vale',        'estagio' => 'Pré-florescimento',     'desvios' => 1]],
    'F04' => [],
    'F05' => [['id' => 'AN-0421', 'tipo' => 'Foliar', 'talhao' => 'U-07', 'coleta' => '08/08/2026', 'lab' => 'NutriFolha Juazeiro', 'estagio' => 'Pleno florescimento',   'desvios' => 4]],
    'F06' => [['id' => 'AN-0412', 'tipo' => 'Solo',   'talhao' => 'M-04', 'coleta' => '28/07/2026', 'lab' => 'Agrolab Vale',        'estagio' => 'Pós-colheita / repouso', 'desvios' => 2]],
    'F08' => [['id' => 'AN-0424', 'tipo' => 'Foliar', 'talhao' => 'U-09', 'coleta' => '12/08/2026', 'lab' => 'NutriFolha Juazeiro', 'estagio' => 'Pleno florescimento',   'desvios' => 1]],
    'F09' => [],
];

$id = (string)($_GET['id'] ?? 'F01');
if (!isset($PROPRIEDADES[$id])) $id = 'F01';      /* fallback: primeira da carteira */
$f   = $PROPRIEDADES[$id];
$ts  = $TALHOES[$id];
$vis = $VISITAS[$id];
$ans = $ANALISES[$id];

$bloqueados = array_column(array_filter($ts, fn($t) => $t['risco'] === 'bloqueio'), 'cod');
$exporta    = strpos($f['destino'], 'Exportação') !== false;

/* Barra de fenologia (o mockup usa .feno/.st8 — recriada aqui com tokens claros) */
$fenoBar = function (array $estagios, int $atual, int $dias, bool $mini = false): string {
    $html = '<div class="crm-feno' . ($mini ? ' crm-feno--mini' : '') . '">';
    foreach ($estagios as $i => $nome) {
        $cls = $i < $atual ? ' done' : ($i === $atual ? ' now' : '');
        $html .= '<span class="crm-feno__st' . $cls . '" title="' . h($nome) . '">' . h($nome)
               . ($i === $atual && !$mini ? '<span class="crm-feno__dd">' . $dias . ' d</span>' : '')
               . '</span>';
    }
    return $html . '</div>';
};
$riscoPill = fn(string $r): string => $r === 'bloqueio'
    ? crm_pill('Carência', 'red')
    : ($r === 'atencao' ? crm_pill('Atenção', 'amber') : crm_pill('OK', 'green'));

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'propriedade',
    'titulo' => 'Propriedade',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" data-toast="Visita registrada · demonstrativo">＋ Registrar visita</button>',
]);
?>

<style>
/* Fenologia (.feno do mockup) — recriada com os tokens claros do VERO */
.crm-app .crm-feno { display: flex; gap: 3px; margin: 8px 0 2px; }
.crm-app .crm-feno__st {
  flex: 1; min-width: 0; text-align: center;
  font: 600 9.5px var(--num, 'IBM Plex Mono'); text-transform: uppercase; letter-spacing: .4px;
  color: var(--crm-ink3); padding: 6px 2px 4px; border-top: 3px solid var(--crm-line2);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.crm-app .crm-feno__st.done { border-top-color: var(--crm-teal); color: var(--crm-ink2); }
.crm-app .crm-feno__st.now {
  border-top-color: var(--crm-amber); color: var(--crm-ink); font-weight: 700;
  background: var(--crm-bg2); border-radius: 0 0 6px 6px;
}
.crm-app .crm-feno__dd { display: block; font-size: 9px; font-weight: 700; color: var(--crm-amber); margin-top: 1px; }
.crm-app .crm-feno--mini { gap: 2px; margin: 0 0 3px; }
.crm-app .crm-feno--mini .crm-feno__st { font-size: 8.5px; letter-spacing: .2px; padding: 4px 1px 2px; }
</style>

<a class="crm-crumb" href="<?= crm_url('consultor', 'propriedades') ?>">‹ Propriedades</a>

<!-- Cabeçalho da propriedade -->
<div class="crm-card" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <?= crm_avatar($f['nome'], $f['cultura'] === 'Manga' ? 'amber' : 'teal', 'g') ?>
  <div style="flex:1;min-width:220px">
    <div style="font-size:16px;font-weight:700"><?= h($f['nome']) ?></div>
    <div class="crm-sub"><?= h($f['mun']) ?> · <?= h($f['loc']) ?></div>
    <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($f['cultura'] === 'Uva / Manga'): ?>
        <?= crm_pill('Uva', 'teal') ?> <?= crm_pill('Manga', 'amber') ?>
      <?php else: ?>
        <?= crm_pill($f['cultura'], $f['cultura'] === 'Manga' ? 'amber' : 'teal') ?>
      <?php endif; ?>
      <?php foreach ($f['cert'] as $c): ?><?= crm_pill($c, 'green') ?><?php endforeach; ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="vbtn" href="<?= crm_url('consultor', 'produtor') ?>?id=<?= h($f['prodId']) ?>">Ver produtor</a>
    <a class="vbtn" href="<?= crm_url('consultor', 'rota') ?>">Rota</a>
  </div>
</div>

<div class="crm-g4">
  <?= crm_kpi('Talhões', (string)count($ts),
        crm_num((float)array_sum(array_column($ts, 'ha'))) . ' ha', 'teal') ?>
  <?= crm_kpi('Visitas no ciclo', (string)$f['visitas'], 'última ' . h($f['ultVisita']), 'teal') ?>
  <?= crm_kpi('Potencial', h($f['pot']), 'por ciclo comercial', 'green') ?>
  <?= crm_kpi('Produtividade esperada', h($ts[0]['prodEsp']), h($f['mediaPolo']), 'blue') ?>
</div>

<div class="crm-g12">
  <div>
    <!-- Ficha da propriedade -->
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Ficha</span>
      </div>
      <?= crm_kv('Produtor', '<a href="' . crm_url('consultor', 'produtor') . '?id=' . h($f['prodId']) . '" style="color:var(--crm-teal)">' . h($f['prodNome']) . '</a>') ?>
      <?= crm_kv('Resp. na fazenda', h($f['resp'])) ?>
      <?= crm_kv('Área total', crm_num((float)$f['ha']) . ' ha') ?>
      <?= crm_kv('Área produtiva', crm_num((float)$f['haProd']) . ' ha') ?>
      <?= crm_kv('Ciclos/ano', h($f['ciclos'])) ?>
      <?= crm_kv('Irrigação', h($f['irrig'])) ?>
      <?= crm_kv('Condução', h($f['cond'])) ?>
      <?= crm_kv('Plantas/ha', crm_num((float)$f['plantas'])) ?>
      <?= crm_kv('Packing house', h($f['packing'])) ?>
      <?= crm_kv('Destino da fruta', h($f['destino'])) ?>
      <div style="margin-top:12px">
        <div style="font:600 10.5px var(--num,'IBM Plex Mono');text-transform:uppercase;letter-spacing:1px;color:var(--crm-ink3);margin-bottom:6px">Variedades</div>
        <?php foreach ($f['vars'] as $v): ?><?= crm_pill($v, 'grey') ?> <?php endforeach; ?>
      </div>
    </div>

    <!-- Análises de solo e foliar -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Análises · solo e foliar</span>
        <?= crm_demo('laboratório') ?>
      </div>
      <?php if (!$ans): ?>
        <div class="crm-empty">Sem laudo registrado nesta propriedade.<br>Solo: 1 por ciclo, no repouso. Foliar: 1 por ciclo, em pleno florescimento.</div>
      <?php else: ?>
        <?php foreach ($ans as $a): ?>
        <div class="crm-ag" data-href="<?= crm_url('consultor', 'analise') ?>?id=<?= h($a['id']) ?>" style="cursor:pointer">
          <span class="crm-ag__body">
            <div class="crm-ag__t"><?= crm_pill($a['tipo'], $a['tipo'] === 'Solo' ? 'grey' : 'teal') ?> <?= crm_pill($a['talhao'], 'grey') ?> <?= h($a['coleta']) ?></div>
            <div class="crm-ag__sub"><?= h($a['lab']) ?> · <?= h($a['estagio']) ?></div>
          </span>
          <?php if ($a['desvios'] === null): ?>
            <?= crm_pill('em análise', 'amber') ?>
          <?php elseif ($a['desvios'] === 0): ?>
            <?= crm_pill('conforme', 'green') ?>
          <?php else: ?>
            <?= crm_pill($a['desvios'] . ' desvio' . ($a['desvios'] > 1 ? 's' : ''), $a['desvios'] > 2 ? 'red' : 'amber') ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <a class="vbtn vbtn-sm" style="margin-top:10px" href="<?= crm_url('consultor', 'analises') ?>">Todas as análises</a>
      <?php endif; ?>
    </div>

    <!-- Conformidade -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Conformidade · exportação</span>
        <?= crm_demo('regra de mercado') ?>
      </div>
      <?= crm_callout('Recomendações filtradas pela lista de moléculas aprovadas para <strong>' . h($f['destino']) . '</strong>.', $exporta ? 'teal' : 'green') ?>
      <?= crm_kv('Caderno de campo', $f['cert']
            ? crm_pill('Em dia · 34 registros no ciclo', 'green')
            : crm_pill('Não mantido', 'amber')) ?>
      <?= crm_kv('Última análise de resíduo', $f['cert'] ? '12/07/2026 · conforme' : '—') ?>
      <?= crm_kv('Talhões bloqueados por carência', $bloqueados
            ? crm_pill(implode(', ', $bloqueados), 'red')
            : crm_pill('Nenhum', 'green')) ?>
    </div>
  </div>

  <div>
    <!-- Talhões & ciclo produtivo -->
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Talhões &amp; ciclo produtivo</span>
        <?= crm_demo('projeção fenológica') ?>
      </div>
      <?php foreach ($ts as $i => $t): ?>
      <div style="padding:14px 0;<?= $i < count($ts) - 1 ? 'border-bottom:1px solid var(--crm-line)' : '' ?>">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px">
          <?= crm_pill($t['cod'], 'grey') ?>
          <strong style="font-size:13.5px"><?= h($t['vari']) ?></strong>
          <span style="font-size:11px;color:var(--crm-ink3)"><?= crm_num((float)$t['ha']) ?> ha · plantio <?= (int)$t['plantio'] ?> · ciclo <?= h($t['ciclo']) ?></span>
          <?= $riscoPill($t['risco']) ?>
          <span style="flex:1"></span>
          <a class="vbtn vbtn-sm" href="<?= crm_url('consultor', 'talhoes') ?>">Detalhes</a>
        </div>
        <?= $fenoBar($t['feno'] === 'manga' ? $FENO_MANGA : $FENO_UVA, (int)$t['estagio'], (int)$t['dias']) ?>
        <div style="display:flex;gap:20px;margin-top:9px;flex-wrap:wrap;font-size:12px">
          <span><span style="color:var(--crm-ink3)">Poda</span> <strong><?= h($t['podaEm']) ?></strong></span>
          <span><span style="color:var(--crm-ink3)">Colheita prev.</span> <strong><?= h($t['colheita']) ?></strong></span>
          <span><span style="color:var(--crm-ink3)">Prod. esperada</span> <strong><?= h($t['prodEsp']) ?></strong></span>
          <?php if ($t['carencia'] > 0): ?>
            <span><span style="color:var(--crm-ink3)">Carência ativa</span> <strong style="color:var(--crm-red)"><?= (int)$t['carencia'] ?> dias</strong></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Histórico de visitas -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Histórico de visitas</span>
        <?= crm_pill($f['visitas'] . ' no ciclo', 'teal') ?>
      </div>
      <?php if (!$vis): ?>
        <div class="crm-empty">Sem visitas registradas nesta propriedade.</div>
      <?php else: ?>
      <div class="crm-tl">
        <?php foreach ($vis as $v): ?>
        <div class="crm-tl__item">
          <span class="crm-tl__dot"></span>
          <div class="crm-tl__dt"><?= h($v['dt']) ?> · <?= h($v['tipo']) ?></div>
          <div class="crm-tl__t"><?= h($v['obj']) ?></div>
          <div class="crm-tl__sub"><?= h($v['resumo']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <a class="vbtn vbtn-sm" style="margin-top:10px" href="<?= crm_url('consultor', 'visitas') ?>">Todas as visitas</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php crm_shell_end();
