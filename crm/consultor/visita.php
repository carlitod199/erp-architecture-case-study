<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Registro de Visita (detalhe)
   Rota: /crm/consultor/visita?id=Vxxx · fallback: 1ª visita
   Briefing pré-visita (agendada) ou registro completo (realizada),
   fiel a docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.visita)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* TODO mover para _mock.php */
$FENO_UVA   = ['Poda', 'Brot', 'Veg', 'Flor', 'Chumb', 'Amol', 'Mat', 'Colh'];
$FENO_MANGA = ['Poda', 'Fluxo', 'PBZ', 'Estr', 'Flor', 'Peg', 'Fruto', 'Colh'];

$VISITAS = [
    'V214' => [
        'data' => '25/08/2026', 'hora' => '07:30', 'status' => 'Agendada', 'tipo' => 'Captação',
        'obj'  => 'Avaliar míldio no U-02 e fechar programa de pré-colheita',
        'prod' => 'João Almeida', 'grupo' => 'Grupo Almeida Agrícola', 'fone' => '(87) 9 8811-2043', 'cor' => 'teal',
        'classe' => 'A', 'stprod' => 'Ativo', 'cultura' => 'Uva', 'destino' => 'Exportação UE / EUA',
        'propn' => 'Fazenda Boa Vista', 'mun' => 'Petrolina · PE', 'recebe' => 'André Almeida (técnico)',
        'ult' => 'há 6 dias', 'ultDesc' => 'Visita técnica — talhão U-03',
        'cred_disp' => 233500.0, 'cred_venc' => 0.0,
        'pend' => [
            ['red',   'U-03 em janela de carência até 01/09', 'Nenhuma aplicação pode ser recomendada. Colheita prevista 21/09.'],
            ['amber', 'Cotação do programa de pré-colheita prometida em 19/08', 'Vence hoje. Oportunidade O-115 · R$ 186.000.'],
            ['blue',  'Míldio no U-02 · reavaliar incidência', '4% registrado em 19/08. Fungicida sistêmico aplicado em 20/08.'],
        ],
        'talhoes' => [
            ['U-01', 'BRS Vitória', 18, 'ok',       'uva', 5, 78],
            ['U-02', 'Arra 15',     22, 'atencao',  'uva', 3, 36],
            ['U-03', 'Sweet Globe', 14, 'bloqueio', 'uva', 6, 98],
        ],
        'opps' => [['O-115', 'Programa pré-colheita · Boa Vista U-03', 186000], ['O-126', 'Correção de boro · Boa Vista U-02', 18400], ['O-124', 'PBZ indução 2026/27 · Boa Vista II', 58000]],
        'compras' => [742000.0, 618000.0], 'pot' => 'R$ 1,4 mi/ano', 'freq' => 'A · a cada 15 dias',
        'obs' => 'Decisor é o próprio João; o filho André cuida da parte técnica e testa produtos novos. Prefere reunião cedo, antes das 8h.',
    ],
    'V215' => [
        'data' => '25/08/2026', 'hora' => '09:45', 'status' => 'Agendada', 'tipo' => 'Prospecção',
        'obj'  => '2ª visita — apresentar diagnóstico de rachadura em Arra 15',
        'prod' => 'Fernanda Sá', 'grupo' => 'Frutas Nova Aliança', 'fone' => '(87) 9 9670-3341', 'cor' => 'blue',
        'classe' => 'A', 'stprod' => 'Prospect', 'cultura' => 'Uva', 'destino' => 'Exportação UE / RU',
        'propn' => 'Fazenda Nova Aliança', 'mun' => 'Santa Maria da Boa Vista · PE', 'recebe' => 'Fernanda Sá',
        'ult' => 'há 3 dias', 'ultDesc' => 'Primeira visita — diagnóstico',
        'cred_disp' => 0.0, 'cred_venc' => 0.0,
        'pend' => [
            ['amber', 'Apresentar diagnóstico de rachadura (12% no T10)', 'Levantado na visita V213 de 22/08.'],
            ['blue',  'Proposta do programa de cálcio · R$ 242.000', 'Prospect: sem cadastro de crédito. Disparar análise se avançar.'],
        ],
        'talhoes' => [
            ['U-07', 'Arra 15',     38, 'bloqueio', 'uva', 5, 74],
            ['U-08', 'Sugar Crisp', 30, 'ok',       'uva', 3, 33],
        ],
        'opps' => [['O-118', 'Programa de cálcio · Nova Aliança', 242000]],
        'compras' => [], 'pot' => 'R$ 1,1 mi/ano', 'freq' => 'A · a cada 15 dias',
        'obs' => 'Hoje compra 100% de concorrente. Abertura veio pelo problema de rachadura de baga em Arra 15.',
    ],
    'V216' => [
        'data' => '25/08/2026', 'hora' => '13:30', 'status' => 'Agendada', 'tipo' => 'Follow-up',
        'obj'  => 'Retomar contato — 32 dias sem visita; oportunidade parada',
        'prod' => 'Carlos Mendes', 'grupo' => 'Fazenda Santa Helena Ltda.', 'fone' => '(87) 9 8734-5510', 'cor' => 'amber',
        'classe' => 'A', 'stprod' => 'Ativo', 'cultura' => 'Uva', 'destino' => 'Exportação UE / Mercado interno',
        'propn' => 'Fazenda Santa Helena', 'mun' => 'Lagoa Grande · PE', 'recebe' => 'Carlos Mendes',
        'ult' => 'há 32 dias', 'ultDesc' => 'Ligação — cotação de fungicida',
        'cred_disp' => 29000.0, 'cred_venc' => 0.0,
        'pend' => [
            ['red',   '32 dias sem visita · frequência-alvo da classe A é 15 dias', 'Concorrência ativa na região.'],
            ['red',   'O-112 parada há 12 dias em Negociação · R$ 315.000', 'Tempo médio nessa etapa: 6 dias.'],
            ['amber', 'Limite de crédito 90% utilizado', 'R$ 29.000 disponíveis. Avaliar condição antes de propor.'],
        ],
        'talhoes' => [
            ['U-04', 'Timpson',          32, 'atencao', 'uva', 4, 52],
            ['U-05', 'Crimson Seedless', 26, 'ok',      'uva', 2, 22],
        ],
        'opps' => [['O-112', 'Renovação programa fitossanitário · Santa Helena', 315000], ['O-127', 'Correção de salinidade · Santa Helena', 126000]],
        'compras' => [498000.0, 540000.0], 'pot' => 'R$ 980 mil/ano', 'freq' => 'A · a cada 15 dias',
        'obs' => 'Só compra depois de ver ensaio comparativo. Sensível a preço no fungicida, não na nutrição.',
    ],
    'V217' => [
        'data' => '25/08/2026', 'hora' => '16:00', 'status' => 'Agendada', 'tipo' => 'Pós-venda',
        'obj'  => 'Conferir resultado do bioinsumo aplicado em 04/08',
        'prod' => 'Helena Vasconcelos', 'grupo' => 'Fazenda Bom Jesus', 'fone' => '(87) 9 8890-4417', 'cor' => 'green',
        'classe' => 'C', 'stprod' => 'Ativo', 'cultura' => 'Uva', 'destino' => 'Mercado interno',
        'propn' => 'Fazenda Bom Jesus', 'mun' => 'Petrolina · PE', 'recebe' => 'Helena Vasconcelos',
        'ult' => 'há 8 dias', 'ultDesc' => 'Pedido entregue',
        'cred_disp' => 48000.0, 'cred_venc' => 0.0,
        'pend' => [
            ['blue', 'Avaliar resultado do bioinsumo aplicado em 04/08', '21 dias após aplicação, conforme recomendado na visita V211.'],
        ],
        'talhoes' => [
            ['U-09', 'Itália', 12, 'atencao', 'uva', 6, 104],
        ],
        'opps' => [],
        'compras' => [118000.0, 104000.0], 'pot' => 'R$ 190 mil/ano', 'freq' => 'C · a cada 45 dias',
        'obs' => 'Área pequena, alta recompra. Bom cliente para bioinsumos.',
    ],
    'V213' => [
        'data' => '22/08/2026', 'hora' => '08:00', 'status' => 'Realizada', 'tipo' => 'Prospecção', 'dur' => '1h50',
        'obj'  => 'Primeira visita — diagnóstico geral',
        'prod' => 'Fernanda Sá', 'grupo' => 'Frutas Nova Aliança', 'fone' => '(87) 9 9670-3341', 'cor' => 'blue',
        'classe' => 'A', 'stprod' => 'Prospect', 'cultura' => 'Uva', 'destino' => 'Exportação UE / RU',
        'propn' => 'Fazenda Nova Aliança', 'mun' => 'Santa Maria da Boa Vista · PE', 'recebe' => 'Fernanda Sá',
        'ult' => 'há 3 dias', 'ultDesc' => 'Primeira visita — diagnóstico',
        'cred_disp' => 0.0, 'cred_venc' => 0.0,
        'pend' => [
            ['blue', 'Primeira visita de prospecção', 'Sem histórico anterior no sistema.'],
        ],
        'talhoes' => [
            ['U-07', 'Arra 15',     38, 'bloqueio', 'uva', 5, 74],
            ['U-08', 'Sugar Crisp', 30, 'ok',       'uva', 3, 33],
        ],
        'opps' => [['O-118', 'Programa de cálcio · Nova Aliança', 242000]],
        'resumo' => 'Pomar de Arra 15 com 12% de rachadura de baga no T10, concentrada nas linhas 4–9 (menor drenagem). Fernanda relatou que o problema se repete há 2 ciclos. Sem programa de cálcio definido. Compra hoje 100% da Agroinsumos Vale.',
        'achados' => ['Rachadura de baga · 12% no T10', 'Manejo de cálcio ausente na 2ª fase de crescimento', 'Irrigação sem sensor de umidade'],
        'recs' => ['Programa de cálcio + boro do chumbinho até amolecimento', 'Revisão da lâmina na 2ª fase de crescimento'],
        'proxima' => 'Apresentar proposta técnica do programa de cálcio', 'proxData' => '25/08/2026', 'opor' => 'O-118', 'fotos' => 3, 'audio' => '0:47',
    ],
    'V212' => [
        'data' => '19/08/2026', 'hora' => '07:15', 'status' => 'Realizada', 'tipo' => 'Técnica', 'dur' => '2h10',
        'obj'  => 'Monitoramento fitossanitário pós-chuva',
        'prod' => 'João Almeida', 'grupo' => 'Grupo Almeida Agrícola', 'fone' => '(87) 9 8811-2043', 'cor' => 'teal',
        'classe' => 'A', 'stprod' => 'Ativo', 'cultura' => 'Uva', 'destino' => 'Exportação UE / EUA',
        'propn' => 'Fazenda Boa Vista', 'mun' => 'Petrolina · PE', 'recebe' => 'André Almeida (técnico)',
        'ult' => 'há 6 dias', 'ultDesc' => 'Visita técnica — talhão U-03',
        'cred_disp' => 233500.0, 'cred_venc' => 0.0,
        'pend' => [
            ['blue', 'Monitorar focos após chuva de 18 mm em 17/08', 'UR acima de 80% por 3 noites.'],
        ],
        'talhoes' => [
            ['U-01', 'BRS Vitória', 18, 'ok',       'uva', 5, 78],
            ['U-02', 'Arra 15',     22, 'atencao',  'uva', 3, 36],
            ['U-03', 'Sweet Globe', 14, 'bloqueio', 'uva', 6, 98],
        ],
        'opps' => [['O-115', 'Programa pré-colheita · Boa Vista U-03', 186000], ['O-126', 'Correção de boro · Boa Vista U-02', 18400]],
        'resumo' => 'Choveu 18 mm no dia 17. Encontrados focos iniciais de míldio no T02 (Arra 15), face norte. T03 entra em carência — última aplicação em 18/08 com produto de 14 dias; colheita prevista 21/09, folga confortável. André pediu cotação do programa de pré-colheita do T03.',
        'achados' => ['Míldio · incidência 4% no T02', 'Umidade relativa acima de 80% por 3 noites', 'T03 em janela de carência'],
        'recs' => ['Fungicida sistêmico no T02 em até 48h', 'Bloquear qualquer aplicação no T03 até 01/09'],
        'proxima' => 'Enviar cotação do programa de pré-colheita', 'proxData' => '26/08/2026', 'opor' => 'O-115', 'fotos' => 6, 'audio' => '0:47',
        'bloqueio' => 'U-03 marcado como não recomendável até 01/09.',
    ],
    'V211' => [
        'data' => '17/08/2026', 'hora' => '14:00', 'status' => 'Realizada', 'tipo' => 'Pós-venda', 'dur' => '0h50',
        'obj'  => 'Entrega e orientação de aplicação',
        'prod' => 'Helena Vasconcelos', 'grupo' => 'Fazenda Bom Jesus', 'fone' => '(87) 9 8890-4417', 'cor' => 'green',
        'classe' => 'C', 'stprod' => 'Ativo', 'cultura' => 'Uva', 'destino' => 'Mercado interno',
        'propn' => 'Fazenda Bom Jesus', 'mun' => 'Petrolina · PE', 'recebe' => 'Helena Vasconcelos',
        'ult' => 'há 8 dias', 'ultDesc' => 'Pedido entregue',
        'cred_disp' => 48000.0, 'cred_venc' => 0.0,
        'pend' => [
            ['blue', 'Entrega do pedido PD-3391', 'Orientar aplicação do bioinsumo no T13.'],
        ],
        'talhoes' => [
            ['U-09', 'Itália', 12, 'atencao', 'uva', 6, 104],
        ],
        'opps' => [],
        'resumo' => 'Entregue o pedido PD-3391. Orientada a aplicação do bioinsumo no T13. Helena quer avaliar resultado antes de ampliar para o ciclo seguinte.',
        'achados' => ['Aplicação realizada conforme recomendação'],
        'recs' => ['Reavaliar em 21 dias'],
        'proxima' => 'Visita de avaliação do bioinsumo', 'proxData' => '25/08/2026', 'opor' => '', 'fotos' => 2, 'audio' => '0:47',
    ],
    'V210' => [
        'data' => '14/08/2026', 'hora' => '08:30', 'status' => 'Realizada', 'tipo' => 'Técnica', 'dur' => '1h30',
        'obj'  => 'Acompanhar resposta à indução floral',
        'prod' => 'Maria Oliveira', 'grupo' => 'Agrícola Vale Verde', 'fone' => '(74) 9 9912-7788', 'cor' => 'violet',
        'classe' => 'B', 'stprod' => 'Ativo', 'cultura' => 'Manga', 'destino' => 'Exportação UE',
        'propn' => 'Fazenda Vale Verde', 'mun' => 'Juazeiro · BA', 'recebe' => 'Maria Oliveira',
        'ult' => 'há 11 dias', 'ultDesc' => 'Visita técnica — indução floral',
        'cred_disp' => 115800.0, 'cred_venc' => 0.0,
        'pend' => [
            ['blue', 'Conferir resposta ao PBZ aplicado em 21/05', 'Iniciar redução gradual de irrigação se uniforme.'],
        ],
        'talhoes' => [
            ['M-02', 'Palmer', 20, 'ok', 'manga', 4, 96],
            ['M-03', 'Keitt',  18, 'ok', 'manga', 1, 14],
        ],
        'opps' => [['O-121', 'Programa de floração LMR-UE · Vale Verde', 98000]],
        'resumo' => 'PBZ aplicado em 21/05 no T07. Ramos maduros, resposta uniforme. Iniciada a redução gradual da irrigação. Maria confirmou que o importador incluiu duas moléculas na lista de restrição — precisamos revisar o programa de floração.',
        'achados' => ['Resposta ao PBZ uniforme no T07', 'Lista de moléculas do importador foi atualizada'],
        'recs' => ['Substituir fungicida da floração por alternativa compatível com a lista UE', 'Manter corte de irrigação até 60% de brotação'],
        'proxima' => 'Enviar programa de floração revisado (compatível LMR-UE)', 'proxData' => '27/08/2026', 'opor' => 'O-121', 'fotos' => 4, 'audio' => '0:47',
    ],
    'V209' => [
        'data' => '06/08/2026', 'hora' => '09:00', 'status' => 'Realizada', 'tipo' => 'Técnica', 'dur' => '1h20',
        'obj'  => 'Poda pós-colheita e adubação de reposição',
        'prod' => 'Roberto Nakamura', 'grupo' => 'Agropecuária Riacho Grande', 'fone' => '(74) 9 9733-2214', 'cor' => 'teal',
        'classe' => 'B', 'stprod' => 'Ativo', 'cultura' => 'Manga', 'destino' => 'Exportação UE / Mercado interno',
        'propn' => 'Fazenda Riacho Grande', 'mun' => 'Curaçá · BA', 'recebe' => 'Roberto Nakamura',
        'ult' => 'há 19 dias', 'ultDesc' => 'Visita técnica — pós-colheita',
        'cred_disp' => 159000.0, 'cred_venc' => 0.0,
        'pend' => [
            ['blue', 'Acompanhar poda de limpeza pós-colheita', 'Análise de solo do T12 recém-emitida.'],
        ],
        'talhoes' => [
            ['M-04', 'Kent', 33, 'ok', 'manga', 3, 62],
        ],
        'opps' => [['O-119', 'Adubação de reposição · Riacho Grande', 74000]],
        'resumo' => 'Poda de limpeza concluída em 60% da área. Análise de solo do T12 indica K abaixo do ideal. Roberto quer proposta de adubação de reposição.',
        'achados' => ['K abaixo do ideal no T12', 'Poda 60% concluída'],
        'recs' => ['Adubação de reposição com foco em K'],
        'proxima' => 'Enviar proposta de adubação de reposição', 'proxData' => '28/08/2026', 'opor' => 'O-119', 'fotos' => 3, 'audio' => '0:47',
    ],
    'V208' => [
        'data' => '02/08/2026', 'hora' => '07:40', 'status' => 'Realizada', 'tipo' => 'Técnica', 'dur' => '1h10',
        'obj'  => 'Avaliar 2º fluxo vegetativo antes da janela de PBZ',
        'prod' => 'João Almeida', 'grupo' => 'Grupo Almeida Agrícola', 'fone' => '(87) 9 8811-2043', 'cor' => 'teal',
        'classe' => 'A', 'stprod' => 'Ativo', 'cultura' => 'Manga', 'destino' => 'Exportação UE',
        'propn' => 'Fazenda Boa Vista II', 'mun' => 'Lagoa Grande · PE', 'recebe' => 'André Almeida (técnico)',
        'ult' => 'há 6 dias', 'ultDesc' => 'Visita técnica — talhão U-03',
        'cred_disp' => 233500.0, 'cred_venc' => 0.0,
        'pend' => [
            ['blue', 'Avaliar maturação do 2º fluxo vegetativo', 'Janela de PBZ estimada para setembro.'],
        ],
        'talhoes' => [
            ['M-01', 'Palmer', 30, 'ok', 'manga', 2, 41],
        ],
        'opps' => [['O-124', 'PBZ indução 2026/27 · Boa Vista II', 58000]],
        'resumo' => '2º fluxo em maturação. Janela de aplicação de PBZ estimada para a primeira semana de setembro. Volume estimado para 30 ha.',
        'achados' => ['2º fluxo ainda imaturo — aguardar ~3 semanas'],
        'recs' => ['Programar PBZ para 01–07/09'],
        'proxima' => 'Cotação de PBZ para 30 ha', 'proxData' => '29/08/2026', 'opor' => 'O-115', 'fotos' => 2, 'audio' => '0:47',
    ],
];

$id = (string)($_GET['id'] ?? '');
if (!isset($VISITAS[$id])) $id = (string)array_key_first($VISITAS);   /* fallback: 1ª */
$v        = $VISITAS[$id];
$agendada = $v['status'] === 'Agendada';

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'visita',
    'titulo' => 'Registro de Visita',
]);
?>

<style>
/* Fenologia compacta e anexos — classes ausentes no crm.css */
.crm-app .cfeno { display: flex; gap: 3px; margin-top: 5px; }
.crm-app .cfeno span {
  flex: 1; text-align: center; padding: 4px 2px; border-radius: 5px;
  background: var(--track, #EEE6D6); color: var(--crm-ink3);
  font: 600 9px var(--num, 'IBM Plex Mono'); text-transform: uppercase; letter-spacing: .3px;
  white-space: nowrap; overflow: hidden;
}
.crm-app .cfeno span.done { background: var(--crm-teal); background: color-mix(in srgb, var(--crm-teal) 22%, #fff); color: var(--crm-teal-d); }
.crm-app .cfeno span.now  { background: var(--crm-teal); color: #fff; }
.crm-app .canexo {
  width: 74px; height: 60px; border-radius: 9px; border: 1px solid var(--crm-line2);
  background: var(--crm-bg2); display: flex; align-items: center; justify-content: center;
  color: var(--crm-ink3); font: 600 9.5px var(--num, 'IBM Plex Mono'); letter-spacing: .5px;
}
.crm-app .canexo--audio { border-style: dashed; }
</style>

<a class="crm-crumb" href="<?= crm_url('consultor', 'visitas') ?>">‹ Visitas</a>

<!-- Cabeçalho da visita -->
<div class="crm-card" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <?= crm_avatar($v['prod'], $v['cor'], 'g') ?>
  <div style="flex:1;min-width:220px">
    <div style="font-size:16px;font-weight:700"><?= $agendada ? 'Briefing · ' : 'Visita ' ?><?= h($id) ?></div>
    <div class="crm-sub"><?= h($v['propn']) ?> · <?= h($v['data']) ?> <?= h($v['hora']) ?></div>
    <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
      <?= crm_pill($v['tipo'], 'teal') ?>
      <?= crm_pill($v['status'], $agendada ? 'blue' : 'green') ?>
      <?php if (!$agendada): ?><?= crm_pill($v['dur'], 'grey') ?><?php endif; ?>
    </div>
  </div>
</div>

<!-- Briefing pré-visita -->
<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title">Briefing pré-visita <?= crm_demo('montado automaticamente') ?></span>
    <span class="crm-sub">Gerado em 0,4 s a partir de 6 fontes</span>
  </div>
  <?= crm_callout('<strong>Objetivo:</strong> ' . h($v['obj']), 'teal') ?>
  <div class="crm-g4" style="margin:12px 0">
    <div>
      <div class="crm-card__title">Produtor</div>
      <div style="font-size:13px;font-weight:600;margin-top:3px"><?= h($v['prod']) ?></div>
      <div class="crm-sub"><?= h($v['grupo']) ?></div>
    </div>
    <div>
      <div class="crm-card__title">Quem recebe</div>
      <div style="font-size:13px;margin-top:3px"><?= h($v['recebe']) ?></div>
      <div class="crm-sub"><?= h($v['fone']) ?></div>
    </div>
    <div>
      <div class="crm-card__title">Último contato</div>
      <div style="font-size:13px;margin-top:3px"><?= h($v['ult']) ?></div>
      <div class="crm-sub"><?= h($v['ultDesc']) ?></div>
    </div>
    <div>
      <div class="crm-card__title">Crédito disponível</div>
      <div style="font-size:13px;margin-top:3px"><?= $v['cred_disp'] > 0 ? crm_brl($v['cred_disp']) : '—' ?></div>
      <?php if ($v['cred_venc'] > 0): ?>
        <div class="crm-sub" style="color:var(--crm-red)"><?= crm_brl($v['cred_venc']) ?> vencido</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="crm-card__title" style="margin-top:14px">Pendências desta visita</div>
  <div style="border:1px solid var(--crm-line);border-radius:10px;margin-top:6px">
    <?php foreach ($v['pend'] as $i => [$cor, $t, $sub]): ?>
      <div style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;<?= $i > 0 ? 'border-top:1px solid var(--crm-line)' : '' ?>">
        <span style="flex:0 0 8px;width:8px;height:8px;border-radius:50%;margin-top:5px;background:var(--crm-<?= h($cor) ?>)"></span>
        <span>
          <div style="font-size:12.5px;font-weight:600"><?= h($t) ?></div>
          <div class="crm-sub"><?= h($sub) ?></div>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="crm-card__title" style="margin-top:16px">Talhões · estágio no dia da visita</div>
  <?php
  $fenoNomes = [
      'uva'   => ['Poda', 'Brotação', 'Crescimento vegetativo', 'Floração', 'Chumbinho', 'Amolecimento', 'Maturação', 'Colheita'],
      'manga' => ['Poda pós-colheita', 'Fluxo vegetativo', 'Indução (PBZ)', 'Estresse hídrico', 'Floração', 'Pegamento', 'Crescimento do fruto', 'Colheita'],
  ];
  foreach ($v['talhoes'] as [$cod, $vari, $ha, $risco, $cult, $estagio, $dias]):
      $abbr = $cult === 'manga' ? $FENO_MANGA : $FENO_UVA;
      $riscoPill = $risco === 'bloqueio' ? crm_pill('Carência', 'red')
                 : ($risco === 'atencao' ? crm_pill('Atenção', 'amber') : crm_pill('OK', 'green'));
  ?>
    <div style="margin-top:11px">
      <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap">
        <?= crm_pill($cod, 'grey') ?>
        <span style="font-size:12.5px;font-weight:600"><?= h($vari) ?></span>
        <span class="crm-sub"><?= (int)$ha ?> ha · <?= h($fenoNomes[$cult][$estagio]) ?> · dia <?= (int)$dias ?></span>
        <?= $riscoPill ?>
      </div>
      <div class="cfeno">
        <?php foreach ($abbr as $i => $nome): ?>
          <span class="<?= $i < $estagio ? 'done' : ($i === $estagio ? 'now' : '') ?>" title="<?= h($fenoNomes[$cult][$i]) ?>"><?= h($nome) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($v['opps']): ?>
    <div class="crm-card__title" style="margin-top:16px">Oportunidades abertas</div>
    <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
      <?php foreach ($v['opps'] as [$oid, $ot, $oval]): ?>
        <a href="<?= crm_url('consultor', 'oportunidade') ?>?id=<?= h($oid) ?>" style="text-decoration:none"><?= crm_pill($oid . ' · ' . $ot . ' · R$ ' . crm_num($oval / 1000) . ' mil', 'blue') ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($agendada): ?>

<div class="crm-g2">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Iniciar visita</span>
      <?= crm_pill('Check-in', 'teal') ?>
    </div>
    <?= crm_callout(
        'Check-in registra data, hora e coordenada. Funciona sem internet — sincroniza quando a conexão voltar. '
        . crm_demo('GPS offline'),
        'green'
    ) ?>
    <div style="display:flex;gap:9px;margin-top:12px">
      <button type="button" class="vbtn vbtn-primary" style="flex:1" data-toast="Check-in registrado · registro de visita aberto">Fazer check-in e registrar</button>
      <button type="button" class="vbtn vbtn-ghost" data-toast="Navegação aberta · demonstrativo">Navegar</button>
    </div>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Contexto comercial</span>
      <span class="crm-sub">Antes de propor</span>
    </div>
    <?= crm_kv('Ciclo 2026', isset($v['compras'][0]) ? crm_brl($v['compras'][0]) : '—') ?>
    <?= crm_kv('Ciclo 2025', isset($v['compras'][1]) ? crm_brl($v['compras'][1]) : '—') ?>
    <?= crm_kv('Potencial estimado', h($v['pot'])) ?>
    <?= crm_kv('Classe / frequência', h($v['freq'])) ?>
    <div class="crm-card__title" style="margin-top:12px">Notas do consultor</div>
    <div style="font-size:12.5px;line-height:1.6;margin-top:4px"><?= h($v['obs']) ?></div>
  </div>
</div>

<?php else: ?>

<div class="crm-g23">
  <div>
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Registro da visita</span>
        <span class="crm-sub">Realizada · <?= h($v['dur']) ?> · check-in confirmado</span>
      </div>
      <div class="crm-card__title">Resumo</div>
      <div style="font-size:13px;line-height:1.7;margin:4px 0 15px"><?= h($v['resumo']) ?></div>
      <div class="crm-g2" style="margin:0">
        <div>
          <div class="crm-card__title">Achados em campo</div>
          <ul style="margin:6px 0 0;padding-left:17px;font-size:12.5px;line-height:1.8">
            <?php foreach ($v['achados'] as $a): ?><li><?= h($a) ?></li><?php endforeach; ?>
          </ul>
        </div>
        <div>
          <div class="crm-card__title">Recomendações registradas</div>
          <ul style="margin:6px 0 0;padding-left:17px;font-size:12.5px;line-height:1.8">
            <?php foreach ($v['recs'] as $r): ?><li><?= h($r) ?></li><?php endforeach; ?>
          </ul>
        </div>
      </div>
      <div class="crm-card__title" style="margin-top:16px">Anexos</div>
      <div style="display:flex;gap:9px;margin-top:7px;flex-wrap:wrap">
        <?php for ($i = 1; $i <= (int)$v['fotos']; $i++): ?>
          <span class="canexo">FOTO <?= $i ?></span>
        <?php endfor; ?>
        <span class="canexo canexo--audio">ÁUDIO <?= h($v['audio']) ?></span>
      </div>
      <div class="crm-sub" style="margin-top:7px">
        Fotos georreferenciadas no talhão. O áudio foi transcrito e usado para montar o resumo acima. <?= crm_demo('transcrição IA') ?>
      </div>
      <div style="margin-top:12px">
        <button type="button" class="vbtn vbtn-sm vbtn-ghost" data-toast="Relatório técnico gerado em PDF">Gerar relatório</button>
      </div>
    </div>

    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Próxima ação</span>
        <span class="crm-sub">Definida na visita</span>
      </div>
      <div style="font-size:13px;font-weight:600"><?= h($v['proxima']) ?></div>
      <div class="crm-sub" style="margin-top:3px">
        Vence em <strong><?= h($v['proxData']) ?></strong><?= $v['opor'] !== '' ? ' · vinculada à oportunidade <strong>' . h($v['opor']) . '</strong>' : '' ?>.
        Criada automaticamente ao encerrar a visita.
      </div>
      <div style="display:flex;gap:8px;margin-top:10px">
        <?php if ($v['opor'] !== ''): ?>
          <a class="vbtn vbtn-sm vbtn-ghost" href="<?= crm_url('consultor', 'oportunidade') ?>?id=<?= h($v['opor']) ?>">Abrir oportunidade</a>
        <?php endif; ?>
        <a class="vbtn vbtn-sm vbtn-ghost" href="<?= crm_url('consultor', 'acoes') ?>">Ver em Ações</a>
      </div>
    </div>
  </div>

  <div>
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">O que o registro gerou</span>
        <?= crm_demo('automações') ?>
      </div>
      <?php
      $geradas = [
          ['green', 'Relatório técnico em PDF', 'Enviado ao produtor por WhatsApp e anexado ao histórico.'],
          ['green', 'Próxima ação criada', $v['proxima'] . ' · ' . $v['proxData']],
      ];
      if ($v['recs']) {
          $geradas[] = ['green', 'Recomendação registrada', count($v['recs']) . ' recomendação(ões) enviadas ao caderno de campo.'];
      }
      if ($v['opor'] !== '') {
          $geradas[] = ['green', 'Oportunidade atualizada', $v['opor'] . ' avançou de etapa e teve a última interação atualizada.'];
      }
      $geradas[] = ['blue', 'Timeline do produtor atualizada', 'Visita, achados e fotos consolidados na ficha de ' . $v['prod'] . '.'];
      if (isset($v['bloqueio'])) {
          $geradas[] = ['red', 'Bloqueio de carência ativado', $v['bloqueio']];
      }
      foreach ($geradas as $i => [$cor, $t, $sub]): ?>
        <div style="display:flex;gap:10px;align-items:flex-start;padding:9px 0;<?= $i > 0 ? 'border-top:1px solid var(--crm-line)' : '' ?>">
          <span style="flex:0 0 8px;width:8px;height:8px;border-radius:50%;margin-top:5px;background:var(--crm-<?= h($cor) ?>)"></span>
          <span style="flex:1;min-width:0">
            <div style="font-size:12.5px;font-weight:600"><?= h($t) ?></div>
            <div class="crm-sub"><?= h($sub) ?></div>
          </span>
          <?= crm_pill($cor === 'red' ? 'regra' : 'ok', $cor === 'red' ? 'red' : ($cor === 'blue' ? 'blue' : 'green')) ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Ficha rápida</span>
        <span class="crm-sub">Produtor</span>
      </div>
      <div style="display:flex;align-items:center;gap:11px;margin-bottom:12px">
        <?= crm_avatar($v['prod'], $v['cor'], 'g') ?>
        <div>
          <div style="font-weight:700;font-size:13.5px"><?= h($v['prod']) ?></div>
          <div class="crm-sub"><?= h($v['propn']) ?> · <?= h($v['mun']) ?></div>
        </div>
      </div>
      <?= crm_kv('Classe', h($v['classe'])) ?>
      <?= crm_kv('Status', crm_status_pill($v['stprod'] === 'Em risco' ? 'Atenção' : $v['stprod'])) ?>
      <?= crm_kv('Cultura', h($v['cultura'])) ?>
      <?= crm_kv('Destino', h($v['destino'])) ?>
      <a class="vbtn vbtn-sm vbtn-ghost" style="margin-top:12px;display:block;text-align:center" href="<?= crm_url('consultor', 'produtor') ?>">Abrir ficha completa</a>
    </div>
  </div>
</div>

<?php endif; ?>

<?php crm_shell_end();
