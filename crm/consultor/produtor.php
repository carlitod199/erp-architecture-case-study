<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Produtor 360 (detalhe da carteira)
   Rota: /crm/consultor/produtor?id=P01
   Fonte: docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.produtor)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* Dados locais fiéis ao mockup — Vale do São Francisco (uva e manga).
   TODO mover para _mock.php */
$PRODUTORES = [
    'P01' => ['nome' => 'João Almeida', 'grupo' => 'Grupo Almeida Agrícola', 'doc' => 'CNPJ 11.234.567/0001-09', 'mun' => 'Petrolina · PE', 'fone' => '(87) 9 8811-2043', 'email' => 'joao@example.com',
        'status' => 'Ativo', 'classe' => 'A', 'pot' => 'R$ 1,4 mi/ano', 'ha' => 96, 'culturas' => ['Uva', 'Manga'], 'cor' => 'teal',
        'ult' => 'há 6 dias', 'ultDias' => 6, 'resp' => 'Rafael Moura', 'cert' => ['GLOBALG.A.P.', 'PIF'],
        'credito' => ['limite' => 420000, 'usado' => 186500, 'venc' => 0],
        'compras' => [['2026', 742000], ['2025', 618000], ['2024', 503000]], 'vis12' => 9,
        'dre' => ['res' => 38908, 'resp' => 5.7, 'mcp' => 13.4, 'mbp' => 21.6, 'desc' => 1.8, 'fin' => 2.0, 'roi' => 15.0],
        'obs' => 'Decisor é o próprio João; o filho André cuida da parte técnica e testa produtos novos. Prefere reunião cedo, antes das 8h.'],
    'P02' => ['nome' => 'Carlos Mendes', 'grupo' => 'Fazenda Santa Helena Ltda.', 'doc' => 'CNPJ 22.845.110/0001-77', 'mun' => 'Lagoa Grande · PE', 'fone' => '(87) 9 8734-5510', 'email' => 'carlos@example.com',
        'status' => 'Ativo', 'classe' => 'A', 'pot' => 'R$ 980 mil/ano', 'ha' => 64, 'culturas' => ['Uva'], 'cor' => 'blue',
        'ult' => 'há 32 dias', 'ultDias' => 32, 'resp' => 'Rafael Moura', 'cert' => ['GLOBALG.A.P.'],
        'credito' => ['limite' => 300000, 'usado' => 271000, 'venc' => 0],
        'compras' => [['2026', 498000], ['2025', 540000], ['2024', 466000]], 'vis12' => 3,
        'dre' => ['res' => -9105, 'resp' => -2.0, 'mcp' => 5.6, 'mbp' => 18.8, 'desc' => 4.6, 'fin' => 3.2, 'roi' => 6.0],
        'obs' => 'Só compra depois de ver ensaio comparativo. Sensível a preço no fungicida, não na nutrição.'],
    'P03' => ['nome' => 'Maria Oliveira', 'grupo' => 'Agrícola Vale Verde', 'doc' => 'CNPJ 33.119.004/0001-21', 'mun' => 'Juazeiro · BA', 'fone' => '(74) 9 9912-7788', 'email' => 'maria@example.com',
        'status' => 'Ativo', 'classe' => 'B', 'pot' => 'R$ 610 mil/ano', 'ha' => 41, 'culturas' => ['Manga'], 'cor' => 'green',
        'ult' => 'há 11 dias', 'ultDias' => 11, 'resp' => 'Rafael Moura', 'cert' => ['PIF', 'Rainforest'],
        'credito' => ['limite' => 180000, 'usado' => 64200, 'venc' => 0],
        'compras' => [['2026', 312000], ['2025', 288000], ['2024', 240000]], 'vis12' => 3,
        'dre' => ['res' => 22845, 'resp' => 8.0, 'mcp' => 15.6, 'mbp' => 24.2, 'desc' => 1.2, 'fin' => 1.7, 'roi' => 7.8],
        'obs' => 'Exporta para UE via packing house terceiro. Muito atenta a LMR e a lista de moléculas do importador.'],
    'P04' => ['nome' => 'Antônio Ribeiro', 'grupo' => 'Fazenda São José', 'doc' => 'CPF 412.***.***-08', 'mun' => 'Casa Nova · BA', 'fone' => '(74) 9 9845-1120', 'email' => 'antonio.ribeiro@example.com',
        'status' => 'Em risco', 'classe' => 'B', 'pot' => 'R$ 430 mil/ano', 'ha' => 28, 'culturas' => ['Uva', 'Manga'], 'cor' => 'red',
        'ult' => 'há 47 dias', 'ultDias' => 47, 'resp' => 'Rafael Moura', 'cert' => [],
        'credito' => ['limite' => 120000, 'usado' => 98000, 'venc' => 18400],
        'compras' => [['2026', 96000], ['2025', 214000], ['2024', 198000]], 'vis12' => 2,
        'dre' => ['res' => -24922, 'resp' => -29.0, 'mcp' => -21.3, 'mbp' => 16.4, 'desc' => 3.8, 'fin' => 4.0, 'roi' => -7.5],
        'obs' => 'Caiu 55% na compra vs. 2025. Concorrente Agroinsumos Vale esteve na fazenda em julho.'],
    'P05' => ['nome' => 'Fernanda Sá', 'grupo' => 'Frutas Nova Aliança', 'doc' => 'CNPJ 44.652.331/0001-63', 'mun' => 'Santa Maria da Boa Vista · PE', 'fone' => '(87) 9 9670-3341', 'email' => 'fernanda@example.com',
        'status' => 'Prospect', 'classe' => 'A', 'pot' => 'R$ 1,1 mi/ano', 'ha' => 74, 'culturas' => ['Uva'], 'cor' => 'violet',
        'ult' => 'há 3 dias', 'ultDias' => 3, 'resp' => 'Rafael Moura', 'cert' => ['GLOBALG.A.P.'],
        'credito' => null, 'compras' => [], 'vis12' => 4, 'dre' => null,
        'obs' => 'Hoje compra 100% de concorrente. Abertura veio pelo problema de rachadura de baga em Arra 15.'],
    'P06' => ['nome' => 'Roberto Nakamura', 'grupo' => 'Agropecuária Riacho Grande', 'doc' => 'CNPJ 55.220.887/0001-45', 'mun' => 'Curaçá · BA', 'fone' => '(74) 9 9733-2214', 'email' => 'roberto@example.com',
        'status' => 'Ativo', 'classe' => 'B', 'pot' => 'R$ 520 mil/ano', 'ha' => 35, 'culturas' => ['Manga'], 'cor' => 'teal',
        'ult' => 'há 19 dias', 'ultDias' => 19, 'resp' => 'Rafael Moura', 'cert' => ['PIF'],
        'credito' => ['limite' => 200000, 'usado' => 41000, 'venc' => 0],
        'compras' => [['2026', 268000], ['2025', 255000], ['2024', 231000]], 'vis12' => 3,
        'dre' => ['res' => 15735, 'resp' => 6.4, 'mcp' => 14.1, 'mbp' => 22.8, 'desc' => 1.4, 'fin' => 1.8, 'roi' => 8.8],
        'obs' => 'Muito organizado. Já usa caderno de campo digital próprio; quer integração.'],
    'P07' => ['nome' => 'Helena Vasconcelos', 'grupo' => 'Fazenda Bom Jesus', 'doc' => 'CNPJ 66.771.902/0001-30', 'mun' => 'Petrolina · PE', 'fone' => '(87) 9 8890-4417', 'email' => 'helena@example.com',
        'status' => 'Ativo', 'classe' => 'C', 'pot' => 'R$ 190 mil/ano', 'ha' => 14, 'culturas' => ['Uva'], 'cor' => 'green',
        'ult' => 'há 8 dias', 'ultDias' => 8, 'resp' => 'Rafael Moura', 'cert' => [],
        'credito' => ['limite' => 70000, 'usado' => 22000, 'venc' => 0],
        'compras' => [['2026', 118000], ['2025', 104000], ['2024', 89000]], 'vis12' => 4,
        'dre' => ['res' => 12260, 'resp' => 11.4, 'mcp' => 19.0, 'mbp' => 27.4, 'desc' => 0.9, 'fin' => 1.3, 'roi' => 10.8],
        'obs' => 'Área pequena, alta recompra. Bom cliente para bioinsumos.'],
    'P08' => ['nome' => 'José Bezerra', 'grupo' => 'Fazenda Serra Branca', 'doc' => 'CPF 305.***.***-22', 'mun' => 'Orocó · PE', 'fone' => '(87) 9 9601-8842', 'email' => 'jbezerra@example.com',
        'status' => 'Em risco', 'classe' => 'B', 'pot' => 'R$ 380 mil/ano', 'ha' => 22, 'culturas' => ['Manga'], 'cor' => 'amber',
        'ult' => 'há 61 dias', 'ultDias' => 61, 'resp' => 'Rafael Moura', 'cert' => [],
        'credito' => ['limite' => 110000, 'usado' => 0, 'venc' => 0],
        'compras' => [['2026', 54000], ['2025', 186000], ['2024', 171000]], 'vis12' => 2,
        'dre' => ['res' => -2827, 'resp' => -5.8, 'mcp' => 1.9, 'mbp' => 17.2, 'desc' => 2.8, 'fin' => 3.4, 'roi' => 0.5],
        'obs' => 'Sem contato há 2 meses. Sem oportunidade aberta apesar do potencial.'],
];

/* Propriedades por produtor */
$PROPRIEDADES = [
    'P01' => [
        ['id' => 'F01', 'nome' => 'Fazenda Boa Vista',    'mun' => 'Petrolina · PE',    'cultura' => 'Uva',   'vars' => 'BRS Vitória · Arra 15 · Sweet Globe', 'ha' => 62, 'destino' => 'Exportação UE / EUA',            'ultVisita' => '19/08/2026'],
        ['id' => 'F07', 'nome' => 'Fazenda Boa Vista II', 'mun' => 'Lagoa Grande · PE', 'cultura' => 'Manga', 'vars' => 'Palmer · Kent',                        'ha' => 34, 'destino' => 'Exportação UE',                  'ultVisita' => '02/08/2026'],
    ],
    'P02' => [['id' => 'F02', 'nome' => 'Fazenda Santa Helena',  'mun' => 'Lagoa Grande · PE',           'cultura' => 'Uva',         'vars' => 'Timpson · Crimson Seedless · Itália', 'ha' => 64, 'destino' => 'Exportação UE / Mercado interno', 'ultVisita' => '24/07/2026']],
    'P03' => [['id' => 'F03', 'nome' => 'Fazenda Vale Verde',    'mun' => 'Juazeiro · BA',               'cultura' => 'Manga',       'vars' => 'Palmer · Keitt · Tommy Atkins',       'ha' => 41, 'destino' => 'Exportação UE',                  'ultVisita' => '14/08/2026']],
    'P04' => [['id' => 'F04', 'nome' => 'Fazenda São José',      'mun' => 'Casa Nova · BA',              'cultura' => 'Uva / Manga', 'vars' => 'Itália · Benitaka · Rosa',            'ha' => 28, 'destino' => 'Mercado interno',                'ultVisita' => '09/07/2026']],
    'P05' => [['id' => 'F05', 'nome' => 'Fazenda Nova Aliança',  'mun' => 'Santa Maria da Boa Vista · PE', 'cultura' => 'Uva',       'vars' => 'Arra 15 · Sugar Crisp · BRS Isis',    'ha' => 74, 'destino' => 'Exportação UE / RU',             'ultVisita' => '22/08/2026']],
    'P06' => [['id' => 'F06', 'nome' => 'Fazenda Riacho Grande', 'mun' => 'Curaçá · BA',                 'cultura' => 'Manga',       'vars' => 'Kent · Palmer',                       'ha' => 35, 'destino' => 'Exportação UE / Mercado interno', 'ultVisita' => '06/08/2026']],
    'P07' => [['id' => 'F08', 'nome' => 'Fazenda Bom Jesus',     'mun' => 'Petrolina · PE',              'cultura' => 'Uva',         'vars' => 'Itália · BRS Vitória',                'ha' => 14, 'destino' => 'Mercado interno',                'ultVisita' => '17/08/2026']],
    'P08' => [['id' => 'F09', 'nome' => 'Fazenda Serra Branca',  'mun' => 'Orocó · PE',                  'cultura' => 'Manga',       'vars' => 'Tommy Atkins · Espada',               'ha' => 22, 'destino' => 'Mercado interno',                'ultVisita' => '25/06/2026']],
];

/* Oportunidades por produtor (etapa já resolvida em rótulo) */
$OPORTUNIDADES = [
    'P01' => [
        ['id' => 'O-115', 'titulo' => 'Programa pré-colheita · Boa Vista U-03', 'gatilho' => 'Fenológico · pré-colheita',      'etapa' => 'Conformidade & crédito', 'cor' => 'blue',  'valor' => 186000, 'prob' => 70,  'prox' => 'Enviar cotação',              'proxData' => '26/08/2026'],
        ['id' => 'O-126', 'titulo' => 'Correção de boro · Boa Vista U-02',      'gatilho' => 'Análise foliar · B deficiente',  'etapa' => 'Recomendação',           'cor' => 'blue',  'valor' => 18400,  'prob' => 75,  'prox' => 'Fechar na visita de hoje',    'proxData' => '25/08/2026'],
        ['id' => 'O-124', 'titulo' => 'PBZ indução 2026/27 · Boa Vista II',     'gatilho' => 'Fenológico · janela de PBZ',     'etapa' => 'Diagnóstico técnico',    'cor' => 'blue',  'valor' => 58000,  'prob' => 80,  'prox' => 'Cotação para 30 ha',          'proxData' => '29/08/2026'],
    ],
    'P02' => [
        ['id' => 'O-112', 'titulo' => 'Renovação programa fitossanitário · Santa Helena', 'gatilho' => 'Recompra de ciclo',            'etapa' => 'Negociação',   'cor' => 'blue', 'valor' => 315000, 'prob' => 40, 'prox' => 'Retomar negociação — parada',            'proxData' => '25/08/2026'],
        ['id' => 'O-127', 'titulo' => 'Correção de salinidade · Santa Helena',            'gatilho' => 'Análise de solo · CE e PST altos', 'etapa' => 'Recomendação', 'cor' => 'blue', 'valor' => 126000, 'prob' => 50, 'prox' => 'Apresentar plano de correção na visita', 'proxData' => '25/08/2026'],
    ],
    'P03' => [['id' => 'O-121', 'titulo' => 'Programa de floração LMR-UE · Vale Verde', 'gatilho' => 'Conformidade · lista do importador', 'etapa' => 'Proposta',     'cor' => 'blue',  'valor' => 98000,  'prob' => 60,  'prox' => 'Enviar programa revisado',   'proxData' => '27/08/2026']],
    'P04' => [['id' => 'O-108', 'titulo' => 'Programa completo safra · São José',       'gatilho' => 'Reativação de carteira',            'etapa' => 'Prospecção',   'cor' => 'blue',  'valor' => 132000, 'prob' => 20,  'prox' => 'Ligar — 47 dias sem contato', 'proxData' => '25/08/2026']],
    'P05' => [['id' => 'O-118', 'titulo' => 'Programa de cálcio · Nova Aliança',        'gatilho' => 'Problema técnico · rachadura',      'etapa' => 'Recomendação', 'cor' => 'blue',  'valor' => 242000, 'prob' => 45,  'prox' => 'Apresentar proposta técnica', 'proxData' => '25/08/2026']],
    'P06' => [['id' => 'O-119', 'titulo' => 'Adubação de reposição · Riacho Grande',    'gatilho' => 'Análise de solo',                   'etapa' => 'Proposta',     'cor' => 'blue',  'valor' => 74000,  'prob' => 55,  'prox' => 'Enviar proposta',             'proxData' => '28/08/2026']],
    'P07' => [['id' => 'O-104', 'titulo' => 'Nutrição foliar 2º ciclo · Bom Jesus',     'gatilho' => 'Recompra de ciclo',                 'etapa' => 'Ganha',        'cor' => 'green', 'valor' => 41000,  'prob' => 100, 'prox' => 'Acompanhar resultado',        'proxData' => '25/08/2026']],
    'P08' => [['id' => 'O-101', 'titulo' => 'Programa fitossanitário · Serra Branca',   'gatilho' => 'Reativação',                        'etapa' => 'Perdida',      'cor' => 'red',   'valor' => 88000,  'prob' => 0,   'prox' => '—',                           'proxData' => '—']],
];

/* Linha do tempo por produtor (visitas realizadas + recomendações) */
$TIMELINE = [
    'P01' => [
        ['dt' => '19/08', 't' => 'Visita técnica · Fazenda Boa Vista',      'sub' => 'Míldio 4% no T02; T03 entrou em janela de carência.',                 'dot' => 'teal'],
        ['dt' => '19/08', 't' => 'Recomendação R-0912 · míldio no U-02',    'sub' => 'Fungicida sistêmico em até 48h + reforço protetor em 7 dias.',        'dot' => 'violet'],
        ['dt' => '11/08', 't' => 'Recomendação R-0916 · boro deficiente',   'sub' => 'Ácido bórico 0,1–0,2% em duas aplicações antes da antese.',           'dot' => 'violet'],
        ['dt' => '02/08', 't' => 'Visita técnica · Boa Vista II',           'sub' => '2º fluxo vegetativo em maturação; janela de PBZ estimada para 01–07/09.', 'dot' => 'teal'],
    ],
    'P02' => [
        ['dt' => '29/07', 't' => 'Recomendação R-0911 · salinidade no U-04', 'sub' => 'CE 1,68 dS/m e PST 11,4% — lixiviação, K₂SO₄ e gesso agrícola.',     'dot' => 'red'],
        ['dt' => '24/07', 't' => 'Ligação · cotação de fungicida',           'sub' => 'Último contato registrado — 32 dias atrás.',                          'dot' => 'blue'],
    ],
    'P03' => [
        ['dt' => '14/08', 't' => 'Visita técnica · indução floral',          'sub' => 'Resposta ao PBZ uniforme no T07; lista do importador atualizada.',    'dot' => 'teal'],
        ['dt' => '07/08', 't' => 'Recomendação R-0914 · N excessivo',        'sub' => 'Suspender N até a florada; reforçar o estresse hídrico.',             'dot' => 'violet'],
    ],
    'P04' => [
        ['dt' => '09/07', 't' => 'Visita técnica · Fazenda São José',        'sub' => 'Última visita realizada — 47 dias atrás.',                            'dot' => 'teal'],
        ['dt' => 'jul',   't' => 'Concorrente registrado na fazenda',        'sub' => 'Agroinsumos Vale esteve na propriedade em julho.',                    'dot' => 'red'],
    ],
    'P05' => [
        ['dt' => '22/08', 't' => 'Primeira visita · diagnóstico geral',      'sub' => 'Rachadura de baga em 12% no T10; sem programa de cálcio definido.',   'dot' => 'teal'],
        ['dt' => '22/08', 't' => 'Recomendação R-0905 · rachadura de baga',  'sub' => 'Programa de cálcio + boro do chumbinho ao amolecimento.',             'dot' => 'violet'],
    ],
    'P06' => [
        ['dt' => '06/08', 't' => 'Visita técnica · poda pós-colheita',       'sub' => 'Poda 60% concluída; análise de solo do T12 com K abaixo do ideal.',   'dot' => 'teal'],
        ['dt' => '06/08', 't' => 'Recomendação R-0907 · reposição de K',     'sub' => 'Adubação de reposição · 180 kg/ha de K2O parcelado.',                 'dot' => 'violet'],
    ],
    'P07' => [
        ['dt' => '19/08', 't' => 'Recomendação R-0918 · zinco deficiente',   'sub' => 'Sulfato de zinco foliar 0,3% em 2–3 aplicações.',                     'dot' => 'violet'],
        ['dt' => '17/08', 't' => 'Visita pós-venda · entrega PD-3391',       'sub' => 'Bioinsumo aplicado no T13; reavaliar em 21 dias.',                    'dot' => 'teal'],
    ],
    'P08' => [
        ['dt' => '04/08', 't' => 'Oportunidade O-101 perdida',               'sub' => 'Programa fitossanitário · R$ 88.000.',                                'dot' => 'red'],
        ['dt' => '25/06', 't' => 'Visita técnica · Fazenda Serra Branca',    'sub' => 'Último contato registrado — 61 dias atrás.',                          'dot' => 'teal'],
    ],
];

$id = (string)($_GET['id'] ?? 'P01');
if (!isset($PRODUTORES[$id])) $id = 'P01';        /* fallback: primeiro da carteira */
$p     = $PRODUTORES[$id];
$props = $PROPRIEDADES[$id];
$opps  = $OPORTUNIDADES[$id];
$tl    = $TIMELINE[$id];

$alvo     = ['A' => 15, 'B' => 30, 'C' => 45][$p['classe']];
$late     = $p['ultDias'] > 30;
$prospect = $p['status'] === 'Prospect';
$quedaPct = (count($p['compras']) > 1 && $p['compras'][0][1] < $p['compras'][1][1])
    ? (int)round((1 - $p['compras'][0][1] / $p['compras'][1][1]) * 100) : 0;

$abertas   = array_filter($opps, fn($o) => !in_array($o['etapa'], ['Ganha', 'Perdida'], true));
$abertasRs = array_sum(array_column($abertas, 'valor'));

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'produtor',
    'titulo' => 'Produtor',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-registrar-visita\')">＋ Registrar visita</button>',
]);
?>

<a class="crm-crumb" href="<?= crm_url('consultor', 'produtores') ?>">‹ Produtores</a>

<?php if ($late): ?>
  <?= crm_callout(
      '<strong>' . h(str_replace('há ', '', $p['ult'])) . ' sem contato.</strong> A frequência-alvo da classe '
      . h($p['classe']) . ' é de ' . $alvo . ' dias.'
      . ($quedaPct > 0 ? ' A compra caiu <strong>' . $quedaPct . '%</strong> em relação ao ciclo anterior.' : '')
      . '<div style="margin-top:8px"><button type="button" class="vbtn vbtn-sm" data-toast="Visita agendada · demonstrativo">Agendar visita</button></div>',
      'red'
  ) ?>
<?php endif; ?>

<!-- Cabeçalho do produtor -->
<div class="crm-card" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <?= crm_avatar($p['nome'], $p['cor'], 'g') ?>
  <div style="flex:1;min-width:220px">
    <div style="font-size:16px;font-weight:700"><?= h($p['nome']) ?></div>
    <div class="crm-sub"><?= h($p['grupo']) ?></div>
    <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
      <?= $p['status'] === 'Em risco' ? crm_pill('Em risco', 'red') : crm_status_pill($p['status']) ?>
      <?= crm_pill('Classe ' . $p['classe'], 'grey') ?>
      <?php foreach ($p['culturas'] as $cu): ?>
        <?= crm_pill($cu, $cu === 'Manga' ? 'amber' : 'teal') ?>
      <?php endforeach; ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button type="button" class="vbtn" data-toast="Abrindo conversa · demonstrativo">WhatsApp</button>
    <button type="button" class="vbtn" data-toast="Ligando · demonstrativo">Ligar</button>
    <a class="vbtn" href="<?= crm_url('consultor', 'agenda') ?>">Agenda</a>
  </div>
</div>

<div class="crm-g4">
  <?= crm_kpi('Propriedades', (string)count($props),
        crm_num((float)array_sum(array_column($props, 'ha'))) . ' ha', 'teal') ?>
  <?= crm_kpi('Visitas 12 meses', (string)$p['vis12'], 'última ' . h($p['ult']),
        $late ? 'amber' : 'teal') ?>
  <?= crm_kpi('Oportunidades abertas', (string)count($abertas),
        $abertasRs > 0 ? crm_brl((float)$abertasRs) : 'nenhum valor em aberto', 'blue') ?>
  <?= crm_kpi('Ciclo 2026',
        $p['compras'] ? crm_brl((float)$p['compras'][0][1]) : '—',
        $p['compras'] && isset($p['compras'][1])
            ? crm_trend(round(($p['compras'][0][1] / $p['compras'][1][1] - 1) * 100)) . ' vs. 2025'
            : 'prospect · ainda sem compra',
        $quedaPct > 0 ? 'red' : 'green') ?>
</div>

<div class="crm-g12">
  <div>
    <!-- Perfil -->
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Perfil</span>
      </div>
      <?= crm_kv('Documento', h($p['doc'])) ?>
      <?= crm_kv('Município', h($p['mun'])) ?>
      <?= crm_kv('Telefone', h($p['fone'])) ?>
      <?= crm_kv('E-mail', h($p['email'])) ?>
      <?= crm_kv('Consultor responsável', h($p['resp'])) ?>
      <?= crm_kv('Potencial estimado', h($p['pot'])) ?>
      <?= crm_kv('Certificações', $p['cert']
            ? implode(' ', array_map(fn($c) => crm_pill($c, 'green'), $p['cert']))
            : '—') ?>
    </div>

    <!-- Crédito & financeiro (viria do ERP) -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Crédito &amp; financeiro</span>
        <?= crm_demo('ERP') ?>
      </div>
      <?php if ($p['credito']): $cr = $p['credito']; $pct = (int)round($cr['usado'] / $cr['limite'] * 100); ?>
        <div style="font-size:11.5px;color:var(--crm-ink2);margin-bottom:4px">Limite utilizado · <?= $pct ?>%</div>
        <?= crm_bar((float)$pct, $pct > 85 ? 'red' : ($pct > 60 ? 'amber' : 'teal')) ?>
        <div style="margin-top:10px">
          <?= crm_kv('Limite', crm_brl((float)$cr['limite'])) ?>
          <?= crm_kv('Disponível', crm_brl((float)($cr['limite'] - $cr['usado']))) ?>
          <?= crm_kv('Em aberto', crm_brl((float)$cr['usado'])) ?>
          <?= crm_kv('Vencido', $cr['venc'] > 0
                ? '<span style="color:var(--crm-red)">' . crm_brl((float)$cr['venc']) . '</span>'
                : crm_brl(0)) ?>
        </div>
      <?php else: ?>
        <div class="crm-empty">Prospect sem cadastro de crédito.<br>A análise é disparada na etapa <strong>Conformidade &amp; crédito</strong> do pipeline.</div>
      <?php endif; ?>
    </div>

    <!-- Compras por ciclo -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Compras por ciclo</span>
        <?= crm_demo('ERP') ?>
      </div>
      <?php if ($p['compras']): $max = max(array_column($p['compras'], 1)); ?>
        <div class="crm-hbars">
          <?php foreach ($p['compras'] as $i => $cp): ?>
          <div class="crm-hbar">
            <span><?= h($cp[0]) ?></span>
            <?= crm_bar($cp[1] / $max * 100, $i === 0 && $quedaPct > 0 ? 'red' : 'teal') ?>
            <span class="num"><?= crm_brl((float)$cp[1]) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="font-size:11px;color:var(--crm-ink3);margin-top:8px">Ciclo 2026 ainda em andamento.</div>
      <?php else: ?>
        <div class="crm-empty">Sem histórico de compra.</div>
      <?php endif; ?>
    </div>

    <!-- Rentabilidade · DRE -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Rentabilidade · DRE · ciclo 2026</span>
        <?php if ($p['dre'] && $p['dre']['res'] < 0): ?><?= crm_pill('No vermelho', 'red') ?><?php else: ?><?= crm_demo('ERP') ?><?php endif; ?>
      </div>
      <?php if ($p['dre']): $d = $p['dre']; $neg = $d['res'] < 0; ?>
        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:10px">
          <div>
            <div style="font-size:10.5px;color:var(--crm-ink3);text-transform:uppercase;letter-spacing:1px">Resultado</div>
            <div style="font-size:21px;font-weight:700;color:var(--crm-<?= $neg ? 'red' : 'green' ?>)">
              <?= ($neg ? '−' : '') . crm_brl(abs((float)$d['res'])) ?>
            </div>
            <div style="font-size:11px;color:var(--crm-ink3)"><?= crm_num(abs((float)$d['resp']), 1) ?>% da receita líquida</div>
          </div>
          <div>
            <div style="font-size:10.5px;color:var(--crm-ink3);text-transform:uppercase;letter-spacing:1px">Marg. contribuição</div>
            <div style="font-size:21px;font-weight:700;<?= $d['mcp'] < 0 ? 'color:var(--crm-red)' : '' ?>"><?= crm_num((float)$d['mcp'], 1) ?>%</div>
            <div style="font-size:11px;color:var(--crm-ink3)">carteira 10,4%</div>
          </div>
        </div>
        <?= crm_kv('Margem bruta', crm_num((float)$d['mbp'], 1) . '%') ?>
        <?= crm_kv('Desconto concedido', crm_num((float)$d['desc'], 1) . '%') ?>
        <?= crm_kv('Custo financeiro', crm_num((float)$d['fin'], 1) . '%') ?>
        <?= crm_kv('ROI de atendimento', crm_num((float)$d['roi'], 1) . '×') ?>
        <a class="vbtn" style="margin-top:12px;width:100%;text-align:center"
           href="<?= crm_url('consultor', 'dre-cliente') ?>?id=<?= h($id) ?>">Abrir DRE completo</a>
      <?php else: ?>
        <div class="crm-empty">Prospect sem faturamento no ciclo.<br>O DRE abre no primeiro pedido faturado.</div>
      <?php endif; ?>
    </div>

    <!-- Notas do consultor -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Notas do consultor</span>
      </div>
      <div style="font-size:12.5px;line-height:1.65;color:var(--crm-ink2)"><?= h($p['obs']) ?></div>
      <div style="font-size:11px;color:var(--crm-ink3);margin-top:10px">Editado por Rafael Moura em 22/08/2026.</div>
    </div>
  </div>

  <div>
    <!-- Propriedades -->
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Propriedades</span>
        <?= crm_pill(count($props) . ' propriedade' . (count($props) > 1 ? 's' : ''), 'teal') ?>
      </div>
      <div class="crm-tblwrap">
        <table class="crm-tbl">
          <thead>
            <tr><th>Propriedade</th><th>Cultura / variedades</th><th class="num">Área</th><th>Destino</th><th>Última visita</th></tr>
          </thead>
          <tbody>
            <?php foreach ($props as $f): ?>
            <tr class="tap" data-href="<?= crm_url('consultor', 'propriedade') ?>?id=<?= h($f['id']) ?>">
              <td><strong><?= h($f['nome']) ?></strong><div class="sub"><?= h($f['mun']) ?></div></td>
              <td><?= crm_pill($f['cultura'], $f['cultura'] === 'Manga' ? 'amber' : 'teal') ?><div class="sub"><?= h($f['vars']) ?></div></td>
              <td class="num"><?= crm_num((float)$f['ha']) ?> ha</td>
              <td><?= h($f['destino']) ?></td>
              <td><?= h($f['ultVisita']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Oportunidades -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Oportunidades</span>
        <?= crm_pill((string)count($opps), 'blue') ?>
      </div>
      <?php if (!$opps): ?>
        <div class="crm-empty">Nenhuma oportunidade registrada.<br>Potencial de <?= h($p['pot']) ?> sem oportunidade aberta — o radar já sinalizou isso.</div>
      <?php else: ?>
      <div class="crm-tblwrap">
        <table class="crm-tbl">
          <thead>
            <tr><th>Oportunidade</th><th>Etapa</th><th class="num">Valor</th><th class="num">Prob.</th><th>Próxima ação</th></tr>
          </thead>
          <tbody>
            <?php foreach ($opps as $o): ?>
            <tr class="tap" data-href="<?= crm_url('consultor', 'oportunidade') ?>?id=<?= h($o['id']) ?>">
              <td><strong><?= h($o['titulo']) ?></strong><div class="sub"><?= h($o['id']) ?> · <?= h($o['gatilho']) ?></div></td>
              <td><?= crm_pill($o['etapa'], $o['cor']) ?></td>
              <td class="num"><strong><?= crm_brl((float)$o['valor']) ?></strong></td>
              <td class="num"><?= (int)$o['prob'] ?>%</td>
              <td><?= h($o['prox']) ?><div class="sub"><?= h($o['proxData']) ?></div></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Linha do tempo -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Linha do tempo</span>
      </div>
      <div class="crm-tl">
        <?php foreach ($tl as $t): ?>
        <div class="crm-tl__item">
          <span class="crm-tl__dot<?= $t['dot'] !== 'teal' ? ' d-' . h($t['dot']) : '' ?>"></span>
          <div class="crm-tl__dt"><?= h($t['dt']) ?></div>
          <div class="crm-tl__t"><?= h($t['t']) ?></div>
          <div class="crm-tl__sub"><?= h($t['sub']) ?></div>
        </div>
        <?php endforeach; ?>
        <div class="crm-tl__item">
          <span class="crm-tl__dot d-blue"></span>
          <div class="crm-tl__dt"><?= $prospect ? '15/08' : '12/01' ?></div>
          <div class="crm-tl__t">Cadastro · produtor incorporado à carteira</div>
          <div class="crm-tl__sub">Responsável: Rafael Moura.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal "Registrar visita" — demo -->
<div class="vmodal" id="vm-registrar-visita">
  <div class="vbox">
    <header>
      <h2>Registrar visita</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-registrar-visita')">×</button>
    </header>
    <form class="vform" onsubmit="return false">
      <div class="vgrid">
        <?= vero_f_text('produtor', 'Produtor', $p['nome'], true) ?>
        <?= vero_f_select('tipo', 'Tipo de visita', [
              'tecnica'    => 'Técnica',
              'comercial'  => 'Captação',
              'prospeccao' => 'Prospecção',
              'posvenda'   => 'Pós-venda',
            ], 'tecnica', true) ?>
      </div>
      <?= vero_f_text('objetivo', 'Objetivo', 'Avaliar míldio no U-02 e fechar programa de pré-colheita') ?>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-registrar-visita')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Visita registrada na linha do tempo · demonstrativo">Salvar</button>
      </div>
    </form>
  </div>
</div>

<?php crm_shell_end();
