<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Oportunidade (detalhe + funil)
   Rota: /crm/consultor/oportunidade?id=O-115 (fallback: 1ª)
   Funil interativo via crmOppInit/crmOppMove (assets/js/crm.js);
   trava de Conformidade & crédito (carência, LMR, crédito).
   Dados fiéis ao mockup docs/VERO_CRM_Consultor_Frutas_Mockup.html.
   TODO mover os dados para _mock.php.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$ETAPAS = ['Prospecção', 'Diagnóstico técnico', 'Recomendação', 'Proposta',
           'Conformidade & crédito', 'Negociação', 'Ganha', 'Perdida'];

/* produtor => [nome, credito{limite,usado,venc,status}, cert[]] */
$PRODUTORES = [
    'P01' => ['nome' => 'João Almeida',       'credito' => ['limite' => 420000, 'usado' => 186500, 'venc' => 0,     'status' => 'ok'],      'cert' => ['GLOBALG.A.P.', 'PIF']],
    'P02' => ['nome' => 'Carlos Mendes',      'credito' => ['limite' => 300000, 'usado' => 271000, 'venc' => 0,     'status' => 'atencao'], 'cert' => ['GLOBALG.A.P.']],
    'P03' => ['nome' => 'Maria Oliveira',     'credito' => ['limite' => 180000, 'usado' => 64200,  'venc' => 0,     'status' => 'ok'],      'cert' => ['PIF', 'Rainforest']],
    'P04' => ['nome' => 'Antônio Ribeiro',    'credito' => ['limite' => 120000, 'usado' => 98000,  'venc' => 18400, 'status' => 'risco'],   'cert' => []],
    'P05' => ['nome' => 'Fernanda Sá',        'credito' => ['limite' => 0,      'usado' => 0,      'venc' => 0,     'status' => 'novo'],    'cert' => ['GLOBALG.A.P.']],
    'P06' => ['nome' => 'Roberto Nakamura',   'credito' => ['limite' => 200000, 'usado' => 41000,  'venc' => 0,     'status' => 'ok'],      'cert' => ['PIF']],
    'P07' => ['nome' => 'Helena Vasconcelos', 'credito' => ['limite' => 70000,  'usado' => 22000,  'venc' => 0,     'status' => 'ok'],      'cert' => []],
    'P08' => ['nome' => 'José Bezerra',       'credito' => ['limite' => 110000, 'usado' => 0,      'venc' => 0,     'status' => 'ok'],      'cert' => []],
];

/* propriedade => [nome, mercado de destino] */
$PROPRIEDADES = [
    'F01' => ['Fazenda Boa Vista',     'Exportação UE / EUA'],
    'F02' => ['Fazenda Santa Helena',  'Exportação UE / Mercado interno'],
    'F03' => ['Fazenda Vale Verde',    'Exportação UE'],
    'F04' => ['Fazenda São José',      'Mercado interno'],
    'F05' => ['Fazenda Nova Aliança',  'Exportação UE / RU'],
    'F06' => ['Fazenda Riacho Grande', 'Exportação UE / Mercado interno'],
    'F07' => ['Fazenda Boa Vista II',  'Exportação UE'],
    'F08' => ['Fazenda Bom Jesus',     'Mercado interno'],
    'F09' => ['Fazenda Serra Branca',  'Mercado interno'],
];

$OPPS = [
    'O-115' => ['titulo' => 'Programa pré-colheita · Boa Vista U-03',        'prod' => 'P01', 'propId' => 'F01', 'etapa' => 4, 'valor' => 186000, 'prob' => 70,  'gatilho' => 'Fenológico · pré-colheita',          'prox' => 'Enviar cotação',                         'proxData' => '26/08/2026', 'parado' => 2,  'prev' => '05/09/2026',    'prods' => 'Fungicidas de carência curta, nutrição foliar', 'resp' => 'Rafael Moura'],
    'O-118' => ['titulo' => 'Programa de cálcio · Nova Aliança',             'prod' => 'P05', 'propId' => 'F05', 'etapa' => 2, 'valor' => 242000, 'prob' => 45,  'gatilho' => 'Problema técnico · rachadura',       'prox' => 'Apresentar proposta técnica',            'proxData' => '25/08/2026', 'parado' => 3,  'prev' => '20/09/2026',    'prods' => 'Cálcio + boro, sensor de umidade',              'resp' => 'Rafael Moura'],
    'O-121' => ['titulo' => 'Programa de floração LMR-UE · Vale Verde',      'prod' => 'P03', 'propId' => 'F03', 'etapa' => 3, 'valor' => 98000,  'prob' => 60,  'gatilho' => 'Conformidade · lista do importador', 'prox' => 'Enviar programa revisado',               'proxData' => '27/08/2026', 'parado' => 1,  'prev' => '10/09/2026',    'prods' => 'Fungicidas compatíveis UE, bioinsumos',         'resp' => 'Rafael Moura'],
    'O-119' => ['titulo' => 'Adubação de reposição · Riacho Grande',         'prod' => 'P06', 'propId' => 'F06', 'etapa' => 3, 'valor' => 74000,  'prob' => 55,  'gatilho' => 'Análise de solo',                    'prox' => 'Enviar proposta',                        'proxData' => '28/08/2026', 'parado' => 5,  'prev' => '15/09/2026',    'prods' => 'Cloreto de potássio, correção',                 'resp' => 'Rafael Moura'],
    'O-112' => ['titulo' => 'Renovação programa fitossanitário · Santa Helena', 'prod' => 'P02', 'propId' => 'F02', 'etapa' => 5, 'valor' => 315000, 'prob' => 40, 'gatilho' => 'Recompra de ciclo',                 'prox' => 'Retomar negociação — parada',            'proxData' => '25/08/2026', 'parado' => 12, 'prev' => '30/08/2026',    'prods' => 'Fungicidas, acaricidas',                        'resp' => 'Rafael Moura'],
    'O-124' => ['titulo' => 'PBZ indução 2026/27 · Boa Vista II',            'prod' => 'P01', 'propId' => 'F07', 'etapa' => 1, 'valor' => 58000,  'prob' => 80,  'gatilho' => 'Fenológico · janela de PBZ',         'prox' => 'Cotação para 30 ha',                     'proxData' => '29/08/2026', 'parado' => 0,  'prev' => '02/09/2026',    'prods' => 'Paclobutrazol',                                 'resp' => 'Rafael Moura'],
    'O-108' => ['titulo' => 'Programa completo safra · São José',            'prod' => 'P04', 'propId' => 'F04', 'etapa' => 0, 'valor' => 132000, 'prob' => 20,  'gatilho' => 'Reativação de carteira',             'prox' => 'Ligar — 47 dias sem contato',            'proxData' => '25/08/2026', 'parado' => 24, 'prev' => '—',             'prods' => 'Programa completo',                             'resp' => 'Rafael Moura'],
    'O-104' => ['titulo' => 'Nutrição foliar 2º ciclo · Bom Jesus',          'prod' => 'P07', 'propId' => 'F08', 'etapa' => 6, 'valor' => 41000,  'prob' => 100, 'gatilho' => 'Recompra de ciclo',                  'prox' => 'Acompanhar resultado',                   'proxData' => '25/08/2026', 'parado' => 0,  'prev' => 'Ganha 12/08',   'prods' => 'Bioinsumo, foliar',                             'resp' => 'Rafael Moura'],
    'O-126' => ['titulo' => 'Correção de boro · Boa Vista U-02',             'prod' => 'P01', 'propId' => 'F01', 'etapa' => 2, 'valor' => 18400,  'prob' => 75,  'gatilho' => 'Análise foliar · B deficiente',      'prox' => 'Fechar na visita de hoje',               'proxData' => '25/08/2026', 'parado' => 1,  'prev' => '27/08/2026',    'prods' => 'Ácido bórico, boro via solo',                   'resp' => 'Rafael Moura'],
    'O-127' => ['titulo' => 'Correção de salinidade · Santa Helena',         'prod' => 'P02', 'propId' => 'F02', 'etapa' => 2, 'valor' => 126000, 'prob' => 50,  'gatilho' => 'Análise de solo · CE e PST altos',   'prox' => 'Apresentar plano de correção na visita', 'proxData' => '25/08/2026', 'parado' => 4,  'prev' => '12/09/2026',    'prods' => 'Gesso agrícola, K₂SO₄, manejo de lixiviação',   'resp' => 'Rafael Moura'],
    'O-101' => ['titulo' => 'Programa fitossanitário · Serra Branca',        'prod' => 'P08', 'propId' => 'F09', 'etapa' => 7, 'valor' => 88000,  'prob' => 0,   'gatilho' => 'Reativação',                         'prox' => '—',                                      'proxData' => '—',          'parado' => 0,  'prev' => 'Perdida 04/08', 'prods' => '—',                                             'resp' => 'Rafael Moura'],
];

$id = (string)($_GET['id'] ?? 'O-115');
if (!isset($OPPS[$id])) $id = array_key_first($OPPS);
$o    = $OPPS[$id];
$p    = $PRODUTORES[$o['prod']];
$f    = $PROPRIEDADES[$o['propId']];
$cred = $p['credito'];

$ganha   = $o['etapa'] === 6;
$perdida = $o['etapa'] === 7;

/* Composição estimada (itens da proposta) — O-115 detalhada, demais genérica */
$ITENS = $id === 'O-115'
    ? [['Fungicida sistêmico (carência 7 d)', '14 ha', '1,2 L', 58400],
       ['Fungicida protetor',                 '14 ha', '2,0 kg', 41200],
       ['Nutrição foliar Ca+B',               '14 ha', '3,0 L', 52800],
       ['Bioinsumo pós-colheita',             '14 ha', '1,0 L', 33600]]
    : [['Programa completo', '—', '—', (float)$o['valor']]];

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'oportunidade',
    'titulo' => 'Oportunidade',
    'acoes'  => $perdida ? '' : '<button type="button" class="vbtn vbtn-primary" data-toast="Proposta enviada ao produtor">Enviar proposta</button>',
]);
?>

<a class="crm-crumb" href="<?= crm_url('consultor', 'pipeline') ?>">‹ Pipeline</a>

<?php if ($o['parado'] > 7 && !$ganha && !$perdida): ?>
  <?= crm_callout(
      '<strong>Parada há ' . (int)$o['parado'] . ' dias</strong> na etapa ' . h($ETAPAS[$o['etapa']])
      . '. O tempo médio nessa etapa na sua carteira é de 6 dias. <strong>' . crm_brl((float)$o['valor']) . '</strong> em risco. '
      . '<button type="button" class="vbtn" style="margin-left:8px" data-toast="Contato registrado">Registrar contato</button>',
      'red'
  ) ?>
<?php endif; ?>

<div class="crm-g3">
  <?= crm_kpi('Valor', crm_brl((float)$o['valor']), 'Ponderado: ' . crm_brl(round($o['valor'] * $o['prob'] / 100)), 'teal') ?>
  <?= crm_kpi('Probabilidade', (int)$o['prob'] . '%', crm_bar((float)$o['prob'], $o['prob'] >= 70 ? 'green' : ($o['prob'] >= 40 ? 'teal' : 'amber')), 'blue') ?>
  <?php if ($perdida): ?>
    <?= crm_kpi('Etapa atual', 'Perdida', h($o['prev']), 'red') ?>
  <?php else: ?>
    <?= crm_kpi('Etapa atual', '<span id="crm-etapa-atual"></span>', 'Previsão de fechamento: ' . h($o['prev']), 'amber') ?>
  <?php endif; ?>
</div>

<?php if (!$perdida): ?>
<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title">Progresso no funil</span>
    <span class="crm-pill p-grey"><?= h($id) ?></span>
  </div>
  <div id="crm-funil" style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px">
    <?php foreach (array_slice($ETAPAS, 0, 6) as $i => $etapa): ?>
      <div data-etapa="<?= (int)$i ?>">
        <div style="font:10px var(--num,'IBM Plex Mono');text-transform:uppercase;letter-spacing:.8px;color:var(--crm-ink3);margin-bottom:6px"><?= h($etapa) ?></div>
        <span class="crm-track"><span class="crm-fill" style="width:0%"></span></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px">
    <button class="vbtn" onclick="crmOppMove(-1)">‹ Voltar etapa</button>
    <button class="vbtn vbtn-primary" id="crm-btn-avancar" onclick="crmOppMove(1)"></button>
    <span id="crm-opp-ganha" style="display:none"><?= crm_pill('Ganha · pedido gerado', 'green') ?></span>
  </div>
</div>
<?php else: ?>
  <?= crm_callout('<strong>Oportunidade encerrada como Perdida em 04/08/2026.</strong> Motivo registrado no fechamento: sem resposta após 3 tentativas de contato. O produtor segue na carteira para reativação futura.', 'red') ?>
<?php endif; ?>

<div class="crm-g2">
  <div>
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Resumo da oportunidade</span>
        <?= crm_pill($o['gatilho'], 'grey') ?>
      </div>
      <div style="font-size:15px;font-weight:700;margin-bottom:10px"><?= h($o['titulo']) ?></div>
      <?= crm_kv('Produtor', h($p['nome'])) ?>
      <?= crm_kv('Propriedade', h($f[0])) ?>
      <?= crm_kv('Gatilho de origem', h($o['gatilho'])) ?>
      <?= crm_kv('Produtos / solução', h($o['prods'])) ?>
      <?= crm_kv('Previsão de fechamento', h($o['prev'])) ?>
      <?= crm_kv('Responsável', h($o['resp'])) ?>
    </div>

    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Conformidade &amp; crédito · trava de avanço</span>
        <?= crm_demo('Regras + ERP') ?>
      </div>
      <?= crm_kv('Carência × colheita', $id === 'O-115'
          ? crm_pill('Bloqueio', 'red') . ' U-03 em carência até 01/09 — produtos de carência longa bloqueados'
          : crm_pill('OK', 'green') . ' Sem conflito com a janela de colheita') ?>
      <?= crm_kv('Lista do mercado de destino', $id === 'O-121'
          ? crm_pill('Atenção', 'amber') . ' 2 ativos fora da lista aprovada pelo importador (UE) — alternativas sugeridas'
          : crm_pill('OK', 'green') . ' Itens compatíveis com ' . h($f[1])) ?>
      <?php
        $credCor = ['ok' => 'green', 'novo' => 'blue', 'atencao' => 'amber', 'risco' => 'red'][$cred['status']] ?? 'grey';
        $credTxt = $cred['limite'] > 0
            ? crm_brl((float)($cred['limite'] - $cred['usado'])) . ' disponíveis de ' . crm_brl((float)$cred['limite'])
              . ($cred['venc'] > 0 ? ' · ' . crm_brl((float)$cred['venc']) . ' vencidos' : '')
            : 'Prospect — análise de crédito ainda não solicitada';
        $credRot = ['ok' => 'OK', 'novo' => 'Novo', 'atencao' => 'Atenção', 'risco' => 'Risco'][$cred['status']] ?? '—';
      ?>
      <?= crm_kv('Crédito', crm_pill($credRot, $credCor) . ' ' . h($credTxt)) ?>
      <?= crm_kv('Certificação', count($p['cert']) > 0
          ? crm_pill('OK', 'green') . ' ' . h(implode(' · ', $p['cert'])) . ' — recomendação alimenta o caderno de campo'
          : crm_pill('Atenção', 'amber') . ' Sem certificação — caderno de campo não é mantido') ?>
    </div>

    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Composição estimada · itens da proposta</span>
        <?= crm_demo('Preços do ERP') ?>
      </div>
      <div class="crm-tblwrap">
        <table class="crm-tbl">
          <thead>
            <tr><th>Item</th><th class="num">Área</th><th class="num">Dose/ha</th><th class="num">Valor</th></tr>
          </thead>
          <tbody>
            <?php foreach ($ITENS as [$item, $area, $dose, $valor]): ?>
            <tr>
              <td><?= h($item) ?></td>
              <td class="num"><?= h($area) ?></td>
              <td class="num"><?= h($dose) ?></td>
              <td class="num"><strong><?= crm_brl((float)$valor) ?></strong></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <?php if (!$perdida): ?>
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Próxima ação</span>
        <?= $o['parado'] > 7 ? crm_pill('atrasada', 'red') : crm_pill('definida', 'green') ?>
      </div>
      <?= crm_callout(
          '<strong>' . h($o['prox']) . '</strong><br>Vence em <strong>' . h($o['proxData']) . '</strong> · responsável ' . h($o['resp']) . '.',
          $o['parado'] > 7 ? 'red' : 'amber'
      ) ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="vbtn" data-toast="Ação concluída">Concluir</button>
        <button type="button" class="vbtn" data-toast="Ação reagendada">Reagendar</button>
      </div>
    </div>
    <?php endif; ?>

    <div class="crm-card" <?= $perdida ? '' : 'style="margin-top:14px"' ?>>
      <div class="crm-card__head">
        <span class="crm-card__title">Histórico da oportunidade</span>
      </div>
      <div class="crm-tl">
        <?php if ($id === 'O-115'): ?>
          <div class="crm-tl__item">
            <span class="crm-tl__dot d-amber"></span>
            <div class="crm-tl__dt">25/08/2026 · Radar</div>
            <div class="crm-tl__t">Ação criada: enviar cotação</div>
            <div class="crm-tl__sub">Promessa registrada na visita V212 venceu hoje.</div>
          </div>
          <div class="crm-tl__item">
            <span class="crm-tl__dot d-blue"></span>
            <div class="crm-tl__dt">20/08/2026 · Proposta</div>
            <div class="crm-tl__t">PR-0442 emitida · <?= crm_brl(186000) ?></div>
            <div class="crm-tl__sub">6 itens. Válida até 05/09/2026.</div>
          </div>
          <div class="crm-tl__item">
            <span class="crm-tl__dot"></span>
            <div class="crm-tl__dt">19/08/2026 · Visita V212</div>
            <div class="crm-tl__t">Diagnóstico em campo</div>
            <div class="crm-tl__sub">André Almeida pediu cotação do programa de pré-colheita do U-03. Talhão entrou em carência.</div>
          </div>
          <div class="crm-tl__item">
            <span class="crm-tl__dot d-green"></span>
            <div class="crm-tl__dt">12/08/2026 · Gatilho fenológico</div>
            <div class="crm-tl__t">Oportunidade criada automaticamente <?= crm_demo('Automação') ?></div>
            <div class="crm-tl__sub">U-03 entrou no estágio de amolecimento de bagas — janela de pré-colheita aberta.</div>
          </div>
        <?php else: ?>
          <div class="crm-tl__item">
            <span class="crm-tl__dot d-amber"></span>
            <div class="crm-tl__dt"><?= h($o['proxData']) ?> · Próxima ação</div>
            <div class="crm-tl__t"><?= h($o['prox']) ?></div>
            <div class="crm-tl__sub">Responsável: <?= h($o['resp']) ?>.</div>
          </div>
          <div class="crm-tl__item">
            <span class="crm-tl__dot d-blue"></span>
            <div class="crm-tl__dt">Etapa atual</div>
            <div class="crm-tl__t"><?= h($ETAPAS[$o['etapa']]) ?></div>
            <div class="crm-tl__sub"><?= $o['parado'] > 0 ? 'Parada há ' . (int)$o['parado'] . ' dia(s).' : 'Movimentada hoje.' ?></div>
          </div>
          <div class="crm-tl__item">
            <span class="crm-tl__dot d-green"></span>
            <div class="crm-tl__dt">Origem</div>
            <div class="crm-tl__t"><?= h($o['gatilho']) ?></div>
            <div class="crm-tl__sub">Oportunidade criada a partir deste gatilho.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (!$perdida): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  crmOppInit({etapa: <?= (int)$o['etapa'] ?>, etapas: <?= jsvar(array_slice($ETAPAS, 0, 7)) ?>});
});
</script>
<?php endif; ?>

<?php crm_shell_end();
