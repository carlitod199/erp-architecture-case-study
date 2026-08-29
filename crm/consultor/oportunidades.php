<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Oportunidades (lista do funil)
   Rota: /crm/consultor/oportunidades
   Dados fiéis ao mockup docs/VERO_CRM_Consultor_Frutas_Mockup.html
   (Vale do São Francisco · uva de mesa e manga).
   TODO mover os dados para _mock.php.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$ETAPAS = ['Prospecção', 'Diagnóstico técnico', 'Recomendação', 'Proposta',
           'Conformidade & crédito', 'Negociação', 'Ganha', 'Perdida'];

/* produtor => [nome, propriedade] */
$PRODUTORES = [
    'P01' => ['João Almeida',       'Fazenda Boa Vista'],
    'P02' => ['Carlos Mendes',      'Fazenda Santa Helena'],
    'P03' => ['Maria Oliveira',     'Fazenda Vale Verde'],
    'P04' => ['Antônio Ribeiro',    'Fazenda São José'],
    'P05' => ['Fernanda Sá',        'Fazenda Nova Aliança'],
    'P06' => ['Roberto Nakamura',   'Fazenda Riacho Grande'],
    'P07' => ['Helena Vasconcelos', 'Fazenda Bom Jesus'],
    'P08' => ['José Bezerra',       'Fazenda Serra Branca'],
];

$OPPS = [
    ['id' => 'O-115', 'titulo' => 'Programa pré-colheita · Boa Vista U-03',        'prod' => 'P01', 'fazenda' => 'Fazenda Boa Vista',     'etapa' => 4, 'valor' => 186000, 'prob' => 70,  'gatilho' => 'Fenológico · pré-colheita',        'prox' => 'Enviar cotação',                       'proxData' => '26/08/2026', 'parado' => 2],
    ['id' => 'O-118', 'titulo' => 'Programa de cálcio · Nova Aliança',             'prod' => 'P05', 'fazenda' => 'Fazenda Nova Aliança',  'etapa' => 2, 'valor' => 242000, 'prob' => 45,  'gatilho' => 'Problema técnico · rachadura',     'prox' => 'Apresentar proposta técnica',          'proxData' => '25/08/2026', 'parado' => 3],
    ['id' => 'O-121', 'titulo' => 'Programa de floração LMR-UE · Vale Verde',      'prod' => 'P03', 'fazenda' => 'Fazenda Vale Verde',    'etapa' => 3, 'valor' => 98000,  'prob' => 60,  'gatilho' => 'Conformidade · lista do importador', 'prox' => 'Enviar programa revisado',           'proxData' => '27/08/2026', 'parado' => 1],
    ['id' => 'O-119', 'titulo' => 'Adubação de reposição · Riacho Grande',         'prod' => 'P06', 'fazenda' => 'Fazenda Riacho Grande', 'etapa' => 3, 'valor' => 74000,  'prob' => 55,  'gatilho' => 'Análise de solo',                  'prox' => 'Enviar proposta',                      'proxData' => '28/08/2026', 'parado' => 5],
    ['id' => 'O-112', 'titulo' => 'Renovação programa fitossanitário · Santa Helena', 'prod' => 'P02', 'fazenda' => 'Fazenda Santa Helena', 'etapa' => 5, 'valor' => 315000, 'prob' => 40, 'gatilho' => 'Recompra de ciclo',               'prox' => 'Retomar negociação — parada',          'proxData' => '25/08/2026', 'parado' => 12],
    ['id' => 'O-124', 'titulo' => 'PBZ indução 2026/27 · Boa Vista II',            'prod' => 'P01', 'fazenda' => 'Fazenda Boa Vista II',  'etapa' => 1, 'valor' => 58000,  'prob' => 80,  'gatilho' => 'Fenológico · janela de PBZ',       'prox' => 'Cotação para 30 ha',                   'proxData' => '29/08/2026', 'parado' => 0],
    ['id' => 'O-108', 'titulo' => 'Programa completo safra · São José',            'prod' => 'P04', 'fazenda' => 'Fazenda São José',      'etapa' => 0, 'valor' => 132000, 'prob' => 20,  'gatilho' => 'Reativação de carteira',           'prox' => 'Ligar — 47 dias sem contato',          'proxData' => '25/08/2026', 'parado' => 24],
    ['id' => 'O-104', 'titulo' => 'Nutrição foliar 2º ciclo · Bom Jesus',          'prod' => 'P07', 'fazenda' => 'Fazenda Bom Jesus',     'etapa' => 6, 'valor' => 41000,  'prob' => 100, 'gatilho' => 'Recompra de ciclo',                'prox' => 'Acompanhar resultado',                 'proxData' => '25/08/2026', 'parado' => 0],
    ['id' => 'O-126', 'titulo' => 'Correção de boro · Boa Vista U-02',             'prod' => 'P01', 'fazenda' => 'Fazenda Boa Vista',     'etapa' => 2, 'valor' => 18400,  'prob' => 75,  'gatilho' => 'Análise foliar · B deficiente',    'prox' => 'Fechar na visita de hoje',             'proxData' => '25/08/2026', 'parado' => 1],
    ['id' => 'O-127', 'titulo' => 'Correção de salinidade · Santa Helena',         'prod' => 'P02', 'fazenda' => 'Fazenda Santa Helena',  'etapa' => 2, 'valor' => 126000, 'prob' => 50,  'gatilho' => 'Análise de solo · CE e PST altos', 'prox' => 'Apresentar plano de correção na visita', 'proxData' => '25/08/2026', 'parado' => 4],
    ['id' => 'O-101', 'titulo' => 'Programa fitossanitário · Serra Branca',        'prod' => 'P08', 'fazenda' => 'Fazenda Serra Branca',  'etapa' => 7, 'valor' => 88000,  'prob' => 0,   'gatilho' => 'Reativação',                       'prox' => '—',                                    'proxData' => '—',          'parado' => 0],
];

/* KPIs calculados sobre as abertas (etapa < Ganha) */
$abertas   = array_values(array_filter($OPPS, fn($o) => $o['etapa'] < 6));
$totAberto = array_sum(array_column($abertas, 'valor'));
$ponderado = array_sum(array_map(fn($o) => $o['valor'] * $o['prob'] / 100, $abertas));
$paradas   = array_values(array_filter($abertas, fn($o) => $o['parado'] > 7));
$ticket    = $totAberto / max(1, count($abertas));

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'oportunidades',
    'titulo' => 'Oportunidades',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-nova-opp\')">＋ Nova oportunidade</button>',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Oportunidades abertas', (string)count($abertas), crm_brl($totAberto) . ' em pipeline', 'blue') ?>
  <?= crm_kpi('Ponderado', crm_brl(round($ponderado)), 'previsão do período', 'green') ?>
  <?= crm_kpi('Paradas há mais de 7 dias', (string)count($paradas), crm_brl(array_sum(array_column($paradas, 'valor'))) . ' em risco', 'red') ?>
  <?= crm_kpi('Ticket médio', crm_brl(round($ticket)), crm_trend(9.0) . ' vs. ciclo anterior', 'teal') ?>
</div>

<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title"><?= count($OPPS) ?> registro(s) · funil do consultor</span>
    <a class="vbtn" href="<?= crm_url('consultor', 'pipeline') ?>">Ver pipeline</a>
  </div>
  <div class="crm-chips">
    <span class="crm-chip on">Todos os gatilhos</span>
    <span class="crm-chip">Fenológico</span>
    <span class="crm-chip">Problema técnico</span>
    <span class="crm-chip">Recompra de ciclo</span>
    <span class="crm-chip">Conformidade</span>
    <span class="crm-chip">Análise de solo / foliar</span>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Oportunidade</th>
          <th>Produtor / propriedade</th>
          <th>Gatilho</th>
          <th>Etapa</th>
          <th class="num">Valor</th>
          <th>Prob.</th>
          <th>Próxima ação</th>
          <th class="num">Parada</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($OPPS as $o):
            $etapaCor = $o['etapa'] === 6 ? 'green' : ($o['etapa'] === 7 ? 'red' : 'blue');
            $probCor  = $o['prob'] >= 70 ? 'green' : ($o['prob'] >= 40 ? 'teal' : 'amber'); ?>
        <tr class="tap" data-href="<?= crm_url('consultor', 'oportunidade') ?>?id=<?= h($o['id']) ?>">
          <td>
            <strong><?= h($o['titulo']) ?></strong>
            <div class="sub"><?= h($o['id']) ?></div>
          </td>
          <td>
            <?= h($PRODUTORES[$o['prod']][0]) ?>
            <div class="sub"><?= h($o['fazenda']) ?></div>
          </td>
          <td><?= crm_pill($o['gatilho'], 'grey') ?></td>
          <td><?= crm_pill($ETAPAS[$o['etapa']], $etapaCor) ?></td>
          <td class="num"><strong><?= crm_brl((float)$o['valor']) ?></strong></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;min-width:110px">
              <?= crm_bar((float)$o['prob'], $probCor) ?>
              <span class="num" style="min-width:34px"><?= (int)$o['prob'] ?>%</span>
            </div>
          </td>
          <td>
            <?= h($o['prox']) ?>
            <div class="sub"><?= h($o['proxData']) ?></div>
          </td>
          <td class="num">
            <?php if ($o['parado'] > 7): ?>
              <?= crm_pill($o['parado'] . ' d', 'red') ?>
            <?php elseif ($o['parado'] > 0): ?>
              <?= crm_pill($o['parado'] . ' d', 'grey') ?>
            <?php else: ?>
              <?= crm_pill('ativa', 'green') ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: nova oportunidade (demo — sem persistência) -->
<div class="vmodal" id="vm-nova-opp">
  <div class="vbox">
    <header>
      <h2>Nova oportunidade</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-nova-opp')">×</button>
    </header>
    <div class="vform">
      <div class="vfield">
        <label>Produtor</label>
        <select>
          <?php foreach ($PRODUTORES as $p): ?>
            <option><?= h($p[0]) ?> · <?= h($p[1]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vfield">
        <label>Título</label>
        <input type="text" placeholder="Ex.: Programa de floração 2026.2">
      </div>
      <div class="vfield">
        <label>Valor estimado</label>
        <input type="text" placeholder="R$ 98.000">
      </div>
      <div class="vfield">
        <label>Etapa</label>
        <select>
          <?php foreach (array_slice($ETAPAS, 0, 6) as $etapa): ?>
            <option><?= h($etapa) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-nova-opp')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Oportunidade criada">Criar</button>
      </div>
    </div>
  </div>
</div>

<?php crm_shell_end();
