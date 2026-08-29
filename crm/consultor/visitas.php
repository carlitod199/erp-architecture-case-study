<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Visitas (protótipo demo)
   Rota: /crm/consultor/visitas · dados fiéis ao mockup
   docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.visitas)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* TODO mover para _mock.php */
$VISITAS = [
    ['id' => 'V214', 'data' => '25/08/2026', 'hora' => '07:30', 'prod' => 'João Almeida',      'propn' => 'Fazenda Boa Vista',     'mun' => 'Petrolina · PE',
     'tipo' => 'Captação', 'obj' => 'Avaliar míldio no U-02 e fechar programa de pré-colheita', 'dur' => '—',
     'proxima' => '', 'proxData' => '', 'status' => 'Agendada'],
    ['id' => 'V215', 'data' => '25/08/2026', 'hora' => '09:45', 'prod' => 'Fernanda Sá',       'propn' => 'Fazenda Nova Aliança',  'mun' => 'Santa Maria da Boa Vista · PE',
     'tipo' => 'Prospecção', 'obj' => '2ª visita — apresentar diagnóstico de rachadura em Arra 15', 'dur' => '—',
     'proxima' => '', 'proxData' => '', 'status' => 'Agendada'],
    ['id' => 'V216', 'data' => '25/08/2026', 'hora' => '13:30', 'prod' => 'Carlos Mendes',     'propn' => 'Fazenda Santa Helena',  'mun' => 'Lagoa Grande · PE',
     'tipo' => 'Follow-up', 'obj' => 'Retomar contato — 32 dias sem visita; oportunidade parada', 'dur' => '—',
     'proxima' => '', 'proxData' => '', 'status' => 'Agendada'],
    ['id' => 'V217', 'data' => '25/08/2026', 'hora' => '16:00', 'prod' => 'Helena Vasconcelos', 'propn' => 'Fazenda Bom Jesus',    'mun' => 'Petrolina · PE',
     'tipo' => 'Pós-venda', 'obj' => 'Conferir resultado do bioinsumo aplicado em 04/08', 'dur' => '—',
     'proxima' => '', 'proxData' => '', 'status' => 'Agendada'],
    ['id' => 'V213', 'data' => '22/08/2026', 'hora' => '08:00', 'prod' => 'Fernanda Sá',       'propn' => 'Fazenda Nova Aliança',  'mun' => 'Santa Maria da Boa Vista · PE',
     'tipo' => 'Prospecção', 'obj' => 'Primeira visita — diagnóstico geral', 'dur' => '1h50',
     'proxima' => 'Apresentar proposta técnica do programa de cálcio', 'proxData' => '25/08/2026', 'status' => 'Realizada'],
    ['id' => 'V212', 'data' => '19/08/2026', 'hora' => '07:15', 'prod' => 'João Almeida',      'propn' => 'Fazenda Boa Vista',     'mun' => 'Petrolina · PE',
     'tipo' => 'Técnica', 'obj' => 'Monitoramento fitossanitário pós-chuva', 'dur' => '2h10',
     'proxima' => 'Enviar cotação do programa de pré-colheita', 'proxData' => '26/08/2026', 'status' => 'Realizada'],
    ['id' => 'V211', 'data' => '17/08/2026', 'hora' => '14:00', 'prod' => 'Helena Vasconcelos', 'propn' => 'Fazenda Bom Jesus',    'mun' => 'Petrolina · PE',
     'tipo' => 'Pós-venda', 'obj' => 'Entrega e orientação de aplicação', 'dur' => '0h50',
     'proxima' => 'Visita de avaliação do bioinsumo', 'proxData' => '25/08/2026', 'status' => 'Realizada'],
    ['id' => 'V210', 'data' => '14/08/2026', 'hora' => '08:30', 'prod' => 'Maria Oliveira',    'propn' => 'Fazenda Vale Verde',    'mun' => 'Juazeiro · BA',
     'tipo' => 'Técnica', 'obj' => 'Acompanhar resposta à indução floral', 'dur' => '1h30',
     'proxima' => 'Enviar programa de floração revisado (compatível LMR-UE)', 'proxData' => '27/08/2026', 'status' => 'Realizada'],
    ['id' => 'V209', 'data' => '06/08/2026', 'hora' => '09:00', 'prod' => 'Roberto Nakamura',  'propn' => 'Fazenda Riacho Grande', 'mun' => 'Curaçá · BA',
     'tipo' => 'Técnica', 'obj' => 'Poda pós-colheita e adubação de reposição', 'dur' => '1h20',
     'proxima' => 'Enviar proposta de adubação de reposição', 'proxData' => '28/08/2026', 'status' => 'Realizada'],
    ['id' => 'V208', 'data' => '02/08/2026', 'hora' => '07:40', 'prod' => 'João Almeida',      'propn' => 'Fazenda Boa Vista II',  'mun' => 'Lagoa Grande · PE',
     'tipo' => 'Técnica', 'obj' => 'Avaliar 2º fluxo vegetativo antes da janela de PBZ', 'dur' => '1h10',
     'proxima' => 'Cotação de PBZ para 30 ha', 'proxData' => '29/08/2026', 'status' => 'Realizada'],
];

/* Produtores da carteira (select do modal) */
$PRODUTORES_SEL = [
    'João Almeida · Grupo Almeida Agrícola',
    'Carlos Mendes · Fazenda Santa Helena Ltda.',
    'Maria Oliveira · Agrícola Vale Verde',
    'Antônio Ribeiro · Fazenda São José',
    'Fernanda Sá · Frutas Nova Aliança',
    'Roberto Nakamura · Agropecuária Riacho Grande',
    'Helena Vasconcelos · Fazenda Bom Jesus',
    'José Bezerra · Fazenda Serra Branca',
];

$corTipo = [
    'Técnica'             => 'teal',
    'Captação' => 'teal',
    'Prospecção'          => 'blue',
    'Follow-up'           => 'amber',
    'Pós-venda'           => 'violet',
];

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'visitas',
    'titulo' => 'Visitas',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-reg-visita\')">＋ Registrar visita</button>',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Visitas no mês', '23', crm_trend(15) . ' vs. julho', 'green') ?>
  <?= crm_kpi('Planejadas vs. realizadas', '23/26', '88% de aderência', 'blue') ?>
  <?= crm_kpi('Duração média', '1h28', 'por visita técnica', 'teal') ?>
  <?= crm_kpi('Sem próxima ação', '2', 'convertem 3x menos', 'red') ?>
</div>

<div class="crm-chips">
  <span class="crm-chip on">Todos os tipos</span>
  <span class="crm-chip">Técnica</span>
  <span class="crm-chip">Comercial</span>
  <span class="crm-chip">Prospecção</span>
  <span class="crm-chip">Pós-venda</span>
  <span class="crm-chip">Últimos 30 dias</span>
</div>

<div class="crm-card" style="padding:0;overflow:hidden">
  <div class="crm-card__head" style="padding:14px 18px 0;margin-bottom:10px">
    <span class="crm-card__title">Registros de visita</span>
    <?= crm_pill(count($VISITAS) . ' registros', 'teal') ?>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Data</th>
          <th>Produtor / propriedade</th>
          <th>Tipo</th>
          <th>Objetivo</th>
          <th class="num">Duração</th>
          <th>Próxima ação</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($VISITAS as $v): ?>
        <tr class="tap" data-href="<?= crm_url('consultor', 'visita') ?>?id=<?= h($v['id']) ?>">
          <td><strong><?= h($v['data']) ?></strong><div class="sub"><?= h($v['hora']) ?></div></td>
          <td><strong><?= h($v['prod']) ?></strong><div class="sub"><?= h($v['propn']) ?> · <?= h($v['mun']) ?></div></td>
          <td><?= crm_pill($v['tipo'], $corTipo[$v['tipo']] ?? 'grey') ?></td>
          <td style="max-width:280px"><?= h($v['obj']) ?></td>
          <td class="num"><?= h($v['dur']) ?></td>
          <td>
            <?php if ($v['proxima'] !== ''): ?>
              <?= h($v['proxima']) ?><div class="sub"><?= h($v['proxData']) ?></div>
            <?php else: ?>
              <?= crm_pill('—', 'grey') ?>
            <?php endif; ?>
          </td>
          <td><?= crm_pill($v['status'], $v['status'] === 'Realizada' ? 'green' : 'blue') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php /* callout "visita sem próxima ação não encerra" removido a pedido do gestor 25/08 */ ?>

<!-- Modal: registrar visita (mock — sem POST) -->
<div class="vmodal" id="vm-reg-visita">
  <div class="vbox">
    <header>
      <h2>Registrar visita</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-reg-visita')">×</button>
    </header>
    <div class="vform">
      <div style="font-size:12px;color:var(--crm-ink2);margin-bottom:12px">
        Check-in em 25/08/2026 07:34 · −9,3891 / −40,5030 · <?= crm_pill('GPS confirmado', 'green') ?>
        <?= crm_pill('offline · sincroniza depois', 'grey') ?> <?= crm_demo('check-in GPS') ?>
      </div>
      <div class="vgrid">
        <div class="vfield">
          <label>Produtor</label>
          <select>
            <?php foreach ($PRODUTORES_SEL as $p): ?>
              <option><?= h($p) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Propriedade</label>
          <select>
            <option>Fazenda Boa Vista · Petrolina/PE</option>
            <option>Fazenda Boa Vista II · Lagoa Grande/PE</option>
          </select>
        </div>
        <div class="vfield">
          <label>Tipo de visita</label>
          <select>
            <option>Técnica</option>
            <option>Captação</option>
            <option>Prospecção</option>
            <option>Follow-up</option>
            <option>Pós-venda</option>
          </select>
        </div>
        <div class="vfield">
          <label>Talhão avaliado</label>
          <select>
            <option>U-02 · Arra 15 · 22 ha</option>
            <option>U-01 · BRS Vitória · 18 ha</option>
            <option>U-03 · Sweet Globe · 14 ha</option>
          </select>
        </div>
        <div class="vfield full">
          <label>Objetivo</label>
          <input type="text" value="Avaliar míldio no U-02 e fechar programa de pré-colheita">
        </div>
        <div class="vfield full">
          <label>Achados em campo</label>
          <textarea rows="3">Focos iniciais de míldio na face norte do U-02, incidência estimada em 4%. UR acima de 80% nas últimas 3 noites.</textarea>
          <div style="display:flex;gap:7px;margin-top:8px">
            <button type="button" class="vbtn vbtn-sm vbtn-ghost" data-toast="Gravando áudio · demonstrativo">Ditar</button>
            <button type="button" class="vbtn vbtn-sm vbtn-ghost" data-toast="Câmera aberta · demonstrativo">Foto (3)</button>
          </div>
        </div>
      </div>
      <?= crm_callout(
          '<strong>Bloqueio de conformidade:</strong> o talhão U-03 está em carência de 14 dias até 01/09 e a colheita é 21/09. '
          . 'Nenhum produto pode ser recomendado nesse talhão — o sistema sugere 2 alternativas de carência curta.',
          'red'
      ) ?>
      <div class="vgrid">
        <div class="vfield">
          <label>Próxima ação *</label>
          <input type="text" value="Enviar cotação do programa de pré-colheita">
        </div>
        <div class="vfield">
          <label>Data da próxima ação *</label>
          <input type="text" value="26/08/2026">
        </div>
        <div class="vfield">
          <label>Oportunidade vinculada</label>
          <select>
            <option>O-115 · Programa pré-colheita · R$ 186.000</option>
            <option>Criar nova oportunidade</option>
            <option>Nenhuma</option>
          </select>
        </div>
        <div class="vfield">
          <label>Interesse identificado</label>
          <select>
            <option>Fungicida de carência curta</option>
            <option>Nutrição foliar</option>
            <option>Bioinsumo</option>
            <option>Regulador de crescimento</option>
          </select>
        </div>
      </div>
      <div style="font-size:11px;color:var(--crm-ink3);margin-top:6px">
        Próxima ação obrigatória. Visitas sem próxima ação convertem 3x menos.
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-reg-visita')">Cancelar</button>
        <button class="vbtn vbtn-ghost" type="button" data-toast="Rascunho salvo offline">Salvar rascunho</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Visita registrada · relatório gerado · 3 automações disparadas">Encerrar visita</button>
      </div>
    </div>
  </div>
</div>

<?php crm_shell_end();
