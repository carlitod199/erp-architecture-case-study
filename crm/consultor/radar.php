<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Radar do Consultor (protótipo)
   Rota: /crm/consultor/radar · alertas acionáveis gerados pelas
   automações — cada item mostra a regra que o gerou.
   Dados fictícios (Vale do São Francisco · uva e manga).
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* TODO mover para _mock.php — itens do radar (fiéis ao mockup).
   cor: red = crítico · amber = atenção · blue = rota · green = positivo
   acts: [rótulo, rota|null, ?id]  (rota null = ação fake via toast) */
$RADAR = [
    ['cor' => 'red', 't' => 'Antônio Ribeiro fecha o ciclo com resultado negativo de R$ 24,9 mil',
     'x' => 'Inadimplência de R$ 18.400 sobre R$ 86 mil de receita líquida, com prazo médio de 84 dias. A margem de contribuição já é negativa — cada nova venda a prazo amplia o prejuízo.',
     'why' => 'DRE por cliente · margem de contribuição',
     'acts' => [['Abrir DRE', 'dre-cliente', 'P04'], ['Bloquear venda a prazo', null]]],
    ['cor' => 'red', 't' => 'Santa Helena · sais acumulados no bulbo molhado do U-04',
     'x' => 'Laudo AN-0409: CE 1,68 dS/m e PST 11,4%, ambos acima do limite. Sem correção, a perda de vigor é progressiva. Plano de correção estimado em R$ 126 mil ainda não apresentado.',
     'why' => 'Análise de solo · CE e PST',
     'acts' => [['Ver laudo', 'analise', 'AN-0409'], ['Abrir oportunidade', 'oportunidade', 'O-127']]],
    ['cor' => 'red', 't' => 'Fazenda Boa Vista · T03 em janela de carência',
     'x' => 'Última aplicação em 18/08 com produto de 14 dias de carência. Nenhuma aplicação pode ser recomendada antes de 01/09 — colheita prevista para 21/09.',
     'why' => 'Regra · carência × data de colheita',
     'acts' => [['Ver talhão', 'talhoes', null], ['Bloquear recomendação', null]]],
    ['cor' => 'red', 't' => 'Vale Verde · 2 moléculas do programa saíram da lista do importador',
     'x' => 'O programa de floração do T07 contém dois ativos que o importador removeu da lista aprovada para a UE. Substituição sugerida disponível.',
     'why' => 'Regra · LMR do mercado de destino',
     'acts' => [['Ver alternativas', 'recomendacoes', null], ['Abrir oportunidade', 'oportunidade', 'O-121']]],
    ['cor' => 'red', 't' => 'Antônio Ribeiro sem contato há 47 dias',
     'x' => 'Queda de 55% na compra vs. 2025, R$ 18.400 vencidos e concorrente registrado na fazenda em julho. Classe B, potencial R$ 430 mil/ano.',
     'why' => 'Regra · dias sem contato × queda de compra',
     'acts' => [['Abrir produtor', 'produtor', 'P04'], ['Agendar visita', 'agenda', null]]],
    ['cor' => 'amber', 't' => 'U-03 sem análise foliar há 2 ciclos, e colhe em 21/09',
     'x' => 'A referência é um laudo por ciclo, em pleno florescimento. Sem análise não há como calibrar o programa de pré-colheita que está em proposta (O-115, R$ 186 mil).',
     'why' => 'Periodicidade de amostragem × estágio',
     'acts' => [['Ver pendências', 'analises', null], ['Agendar coleta', null]]],
    ['cor' => 'amber', 't' => 'Carlos Mendes: R$ 450 mil de receita e resultado negativo',
     'x' => 'Desconto de 4,6% somado a 68 dias de prazo consome 44% da margem bruta. É o segundo maior cliente da carteira em faturamento e o segundo pior em resultado.',
     'why' => 'DRE por cliente · desconto × prazo',
     'acts' => [['Abrir DRE', 'dre-cliente', 'P02'], ['Sugerir teto de desconto', null]]],
    ['cor' => 'amber', 't' => 'O-112 parada há 12 dias em Negociação',
     'x' => 'R$ 315.000. Tempo médio nessa etapa na sua carteira é de 6 dias. Nenhuma interação registrada desde 13/08.',
     'why' => 'Regra · tempo em etapa × média histórica',
     'acts' => [['Abrir oportunidade', 'oportunidade', 'O-112'], ['Registrar contato', null]]],
    ['cor' => 'amber', 't' => 'Boa Vista II entra na janela de PBZ em ~7 dias',
     'x' => '2º fluxo vegetativo em maturação desde 02/08. Janela estimada: 01 a 07/09. Volume para 30 ha ainda não cotado.',
     'why' => 'Regra · fenologia da manga × histórico do talhão',
     'acts' => [['Ver talhão', 'talhoes', null], ['Cotar PBZ', 'oportunidade', 'O-124']]],
    ['cor' => 'amber', 't' => 'Chuva prevista para 27/08 em Lagoa Grande — risco de míldio',
     'x' => '2 propriedades da sua carteira com uva em fase suscetível (T05 e T06, Santa Helena). Última aplicação preventiva há 16 dias.',
     'why' => 'Clima × fenologia × histórico de aplicação', 'demo' => 'clima',
     'acts' => [['Ver propriedades', 'propriedades', null], ['Criar visita', 'agenda', null]]],
    ['cor' => 'blue', 't' => 'Você estará a menos de 14 km de 3 propriedades amanhã',
     'x' => 'Roteiro de 26/08 passa por Juazeiro. Vale Verde, São José e Serra Branca estão no caminho — as duas últimas sem visita há mais de 45 dias.',
     'why' => 'Rota × dias sem visita',
     'acts' => [['Ver rota', 'rota', null], ['Encaixar visitas', 'agenda', null]]],
    ['cor' => 'green', 't' => '6 de 8 visitas da semana já têm próxima ação registrada',
     'x' => 'Duas visitas de 19/08 e 22/08 ainda estão sem próxima ação definida. Visitas sem próxima ação convertem 3x menos na sua carteira.',
     'why' => 'Qualidade de registro',
     'acts' => [['Ver visitas', 'visitas', null]]],
];

/* Item do radar: faixa de prioridade + título + regra que o gerou + ações */
function co_radar_item(array $r): string
{
    $demo = isset($r['demo']) ? ' ' . crm_demo((string)$r['demo']) : '';
    $acts = '';
    foreach ($r['acts'] as $a) {
        if ($a[1] === null) {
            $acts .= '<button type="button" class="vbtn vbtn-sm" data-toast="'
                   . h($a[0] . ' · demonstrativo') . '">' . h($a[0]) . '</button> ';
        } else {
            $url = crm_url('consultor', $a[1]) . (isset($a[2]) && $a[2] !== null ? '?id=' . rawurlencode($a[2]) : '');
            $acts .= '<a class="vbtn vbtn-sm" href="' . h($url) . '">' . h($a[0]) . '</a> ';
        }
    }
    return '<div class="crm-ag">'
         . '<span class="crm-ag__bar b-' . h($r['cor']) . '"></span>'
         . '<span class="crm-ag__body">'
         . '<div class="crm-ag__t">' . h($r['t']) . $demo . '</div>'
         . '<div class="crm-ag__sub" style="margin:2px 0 8px">' . h($r['x']) . '</div>'
         . '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">' . $acts
         . '<span style="font:600 10px var(--num,\'IBM Plex Mono\');text-transform:uppercase;letter-spacing:.6px;color:var(--crm-ink3)">'
         . h($r['why']) . '</span></div>'
         . '</span></div>';
}

/* Card de um grupo do radar */
function co_radar_card(string $titulo, string $pilCor, array $itens): string
{
    $h = '<div class="crm-card"><div class="crm-card__head">'
       . '<span class="crm-card__title">' . h($titulo) . '</span>'
       . crm_pill(count($itens) . (count($itens) === 1 ? ' item' : ' itens'), $pilCor)
       . '</div>';
    foreach ($itens as $r) $h .= co_radar_item($r);
    return $h . '</div>';
}

$criticos  = array_values(array_filter($RADAR, fn($r) => $r['cor'] === 'red'));
$atencao   = array_values(array_filter($RADAR, fn($r) => $r['cor'] === 'amber'));
$rotaOp    = array_values(array_filter($RADAR, fn($r) => $r['cor'] === 'blue'));
$positivos = array_values(array_filter($RADAR, fn($r) => $r['cor'] === 'green'));

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'radar',
    'titulo' => 'Radar do Consultor',
    'acoes'  => '<a class="vbtn vbtn-primary" href="' . h(crm_url('consultor', 'automacoes')) . '">Ver as 26 regras</a>',
]);
?>

<?= crm_callout('<strong>O radar responde uma pergunta só: o que eu deveria fazer agora?</strong> '
    . 'Cada item mostra a regra que o gerou — o consultor entende o porquê, não recebe uma caixa-preta.', 'teal') ?>

<div class="crm-g4">
  <?= crm_kpi('Alertas ativos', '12', '5 críticos · 5 atenção', 'amber') ?>
  <?= crm_kpi('Valor em risco', 'R$ 951 mil', 'pipeline, carteira e margem', 'red') ?>
  <?= crm_kpi('Janelas fenológicas', '2', 'abrindo em até 7 dias', 'blue') ?>
  <?= crm_kpi('Ações geradas no mês', '61', '49 concluídas · 80%', 'green') ?>
</div>

<div class="crm-g2">
  <div style="display:grid;gap:14px;align-content:start">
    <?= co_radar_card('Críticos · ação imediata', 'red', $criticos) ?>
    <?= co_radar_card('Positivos · reforço', 'green', $positivos) ?>
  </div>
  <div style="display:grid;gap:14px;align-content:start">
    <?= co_radar_card('Atenção · nos próximos dias', 'amber', $atencao) ?>
    <?= co_radar_card('Oportunidades de rota · aproveitar deslocamento', 'blue', $rotaOp) ?>
  </div>
</div>

<?php crm_shell_end();
