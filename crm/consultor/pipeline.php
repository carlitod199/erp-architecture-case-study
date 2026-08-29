<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Pipeline Comercial (kanban do funil)
   Rota: /crm/consultor/pipeline
   Kanban interativo via crmKanbanInit (assets/js/crm.js); as
   duas etapas típicas de fruticultura (Recomendação e
   Conformidade & crédito) entram no funil.
   Dados fiéis ao mockup docs/VERO_CRM_Consultor_Frutas_Mockup.html.
   TODO mover os dados para _mock.php.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$ETAPAS_K = ['Prospecção', 'Diagnóstico técnico', 'Recomendação', 'Proposta',
             'Conformidade & crédito', 'Negociação'];

/* produtor => nome (para o cartão do kanban) */
$PRODUTORES = [
    'P01' => 'João Almeida',
    'P02' => 'Carlos Mendes',
    'P03' => 'Maria Oliveira',
    'P04' => 'Antônio Ribeiro',
    'P05' => 'Fernanda Sá',
    'P06' => 'Roberto Nakamura',
    'P07' => 'Helena Vasconcelos',
    'P08' => 'José Bezerra',
];

/* Abertas (etapa < Ganha) no formato do crmKanbanInit:
   {id, cliente, nome, valor, etapa, prob, dias} — estado local.  */
$OPPS = [
    ['id' => 'O-108', 'cliente' => 'P04', 'nome' => 'Programa completo safra · São José',               'valor' => 132000, 'etapa' => 0, 'prob' => 20, 'dias' => 0],
    ['id' => 'O-124', 'cliente' => 'P01', 'nome' => 'PBZ indução 2026/27 · Boa Vista II',               'valor' => 58000,  'etapa' => 1, 'prob' => 80, 'dias' => 0],
    ['id' => 'O-118', 'cliente' => 'P05', 'nome' => 'Programa de cálcio · Nova Aliança',                'valor' => 242000, 'etapa' => 2, 'prob' => 45, 'dias' => 0],
    ['id' => 'O-126', 'cliente' => 'P01', 'nome' => 'Correção de boro · Boa Vista U-02',                'valor' => 18400,  'etapa' => 2, 'prob' => 75, 'dias' => 0],
    ['id' => 'O-127', 'cliente' => 'P02', 'nome' => 'Correção de salinidade · Santa Helena',            'valor' => 126000, 'etapa' => 2, 'prob' => 50, 'dias' => 0],
    ['id' => 'O-121', 'cliente' => 'P03', 'nome' => 'Programa de floração LMR-UE · Vale Verde',         'valor' => 98000,  'etapa' => 3, 'prob' => 60, 'dias' => 0],
    ['id' => 'O-119', 'cliente' => 'P06', 'nome' => 'Adubação de reposição · Riacho Grande',            'valor' => 74000,  'etapa' => 3, 'prob' => 55, 'dias' => 0],
    ['id' => 'O-115', 'cliente' => 'P01', 'nome' => 'Programa pré-colheita · Boa Vista U-03',           'valor' => 186000, 'etapa' => 4, 'prob' => 70, 'dias' => 0],
    ['id' => 'O-112', 'cliente' => 'P02', 'nome' => 'Renovação programa fitossanitário · Santa Helena', 'valor' => 315000, 'etapa' => 5, 'prob' => 40, 'dias' => 0],
];
$totAberto = array_sum(array_column($OPPS, 'valor'));

/* Fechadas no ciclo + velocidade por etapa (fiéis ao mockup) */
$MOTIVOS_PERDA = [
    ['Preço / condição', 2, 'red'],
    ['Concorrente',      1, 'red'],
    ['Sem crédito',      1, 'amber'],
    ['Sem resposta',     3, 'amber'],
];
$TEMPO_ETAPA = [
    ['Prospecção',             18, 'teal'],
    ['Diagnóstico técnico',     9, 'teal'],
    ['Recomendação',            7, 'teal'],
    ['Proposta',                6, 'teal'],
    ['Conformidade & crédito',  4, 'teal'],
    ['Negociação',              6, 'amber'],
];

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'pipeline',
    'titulo' => 'Pipeline Comercial',
    'acoes'  => '<a class="vbtn vbtn-primary" href="' . crm_url('consultor', 'oportunidades') . '">Ver oportunidades</a>',
]);
?>

<style>
/* Kanban de 6 etapas (o padrão do crm.css é 4 colunas) */
.crm-app .crm-kanban--6 { grid-template-columns: repeat(6, minmax(230px, 1fr)); }
@media (max-width: 1500px) { .crm-app .crm-kanban--6 { grid-template-columns: repeat(6, 250px); } }
/* Barras horizontais com rótulo largo (etapas do funil) */
.crm-app .crm-hbars--w .crm-hbar { grid-template-columns: 175px 1fr 48px; }
</style>

<?= crm_callout(
    '<strong>Pipeline desenhado para fruticultura.</strong> Duas etapas não existem em CRM genérico: '
    . '<strong>Recomendação</strong> (a venda nasce de um diagnóstico técnico, não de um pitch) e '
    . '<strong>Conformidade &amp; crédito</strong> (carência, LMR do mercado de destino e limite disponível '
    . 'travam mais negócios do que preço).',
    'teal'
) ?>

<?= crm_callout(
    '<strong>O-112 parada há 12 dias</strong> na etapa Negociação — tempo médio da carteira nessa etapa é de 6 dias. '
    . crm_brl(315000) . ' em risco. '
    . '<a class="vbtn vbtn-sm vbtn-ghost" style="margin-left:8px" href="' . crm_url('consultor', 'oportunidade') . '?id=O-112">Abrir oportunidade</a>',
    'red'
) ?>

<div class="crm-card" style="margin:14px 0">
  <div class="crm-card__head">
    <span class="crm-card__title">Ciclo 2026.2 · minha carteira · <?= count($OPPS) ?> abertas · <?= crm_brl($totAberto) ?></span>
    <span class="crm-tabs" style="margin:0">
      <span class="crm-tab on">Minha carteira</span>
      <span class="crm-tab" data-toast="Visão de equipe · demonstrativo">Equipe</span>
    </span>
  </div>
  <div id="crm-pipeline" class="crm-kanban crm-kanban--6"></div>
</div>

<div class="crm-g2">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Fechadas no ciclo · ganhas &amp; perdidas</span>
    </div>
    <div style="display:flex;gap:26px;flex-wrap:wrap;margin-bottom:14px">
      <div>
        <div class="crm-card__title" style="letter-spacing:.8px">Ganhas</div>
        <div style="font-size:20px;font-weight:700;color:var(--crm-green)"><?= crm_brl(41000) ?></div>
        <div class="sub" style="font-size:11px;color:var(--crm-ink3)">1 oportunidade</div>
      </div>
      <div>
        <div class="crm-card__title" style="letter-spacing:.8px">Perdidas</div>
        <div style="font-size:20px;font-weight:700;color:var(--crm-red)"><?= crm_brl(88000) ?></div>
        <div class="sub" style="font-size:11px;color:var(--crm-ink3)">1 oportunidade</div>
      </div>
      <div>
        <div class="crm-card__title" style="letter-spacing:.8px">Conversão</div>
        <div style="font-size:20px;font-weight:700">50%</div>
        <div class="sub" style="font-size:11px;color:var(--crm-ink3)">no ciclo</div>
      </div>
    </div>
    <div class="crm-hbars">
      <?php foreach ($MOTIVOS_PERDA as [$rot, $qtd, $cor]): ?>
      <div class="crm-hbar">
        <span><?= h($rot) ?></span>
        <?= crm_bar($qtd / 3 * 100, $cor) ?>
        <span class="num"><?= (int)$qtd ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?= crm_callout('Motivos de perda registrados no fechamento — obrigatório para encerrar como Perdida.', 'teal') ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Velocidade · tempo médio por etapa</span>
    </div>
    <div class="crm-hbars crm-hbars--w">
      <?php foreach ($TEMPO_ETAPA as [$rot, $dias, $cor]): ?>
      <div class="crm-hbar">
        <span><?= h($rot) ?></span>
        <?= crm_bar($dias / 18 * 100, $cor) ?>
        <span class="num"><?= (int)$dias ?> d</span>
      </div>
      <?php endforeach; ?>
    </div>
    <?= crm_callout('Ciclo médio total: <strong>50 dias</strong> da prospecção ao fechamento. Oportunidades acima da média entram no radar.', 'teal') ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  crmKanbanInit('crm-pipeline', <?= jsvar($OPPS) ?>, <?= jsvar($ETAPAS_K) ?>, <?= jsvar($PRODUTORES) ?>, '<?= crm_url('consultor', 'oportunidade') ?>');
});
</script>

<?php crm_shell_end();
