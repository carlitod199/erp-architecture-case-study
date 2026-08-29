<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Próximas Ações (protótipo demo)
   Rota: /crm/consultor/acoes
   Fonte: docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.acoes)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* Dados locais fiéis ao mockup — TODO mover para _mock.php
   rota: tela de destino + id (detalhes usam ?id=) */
$ACOES = [
    ['quando' => 'atrasado', 'dias' => 12, 'tipo' => 'Oportunidade parada', 'prod' => 'Carlos Mendes',
     't' => 'Retomar O-112 · Santa Helena', 'x' => 'Sem movimentação há 12 dias na etapa Negociação. R$ 315.000 em risco.',
     'href' => crm_url('consultor', 'oportunidade') . '?id=O-112'],
    ['quando' => 'atrasado', 'dias' => 47, 'tipo' => 'Cliente sem contato', 'prod' => 'Antônio Ribeiro',
     't' => 'Ligar para Antônio Ribeiro', 'x' => '47 dias sem contato. Compra caiu 55% vs. 2025 e há R$ 18.400 vencidos.',
     'href' => crm_url('consultor', 'produtor') . '?id=P04'],
    ['quando' => 'atrasado', 'dias' => 61, 'tipo' => 'Cliente sem contato', 'prod' => 'José Bezerra',
     't' => 'Reativar José Bezerra', 'x' => '61 dias sem contato, sem oportunidade aberta e potencial de R$ 380 mil/ano.',
     'href' => crm_url('consultor', 'produtor') . '?id=P08'],
    ['quando' => 'hoje', 'dias' => 0, 'tipo' => 'Visita', 'prod' => 'João Almeida',
     't' => 'Visita 07:30 · Fazenda Boa Vista', 'x' => 'Avaliar míldio no U-02 e fechar programa de pré-colheita do U-03.',
     'href' => crm_url('consultor', 'visita') . '?id=V214'],
    ['quando' => 'hoje', 'dias' => 0, 'tipo' => 'Visita', 'prod' => 'Fernanda Sá',
     't' => 'Visita 09:45 · Nova Aliança', 'x' => '2ª visita de prospecção. Levar diagnóstico de rachadura e proposta do programa de cálcio.',
     'href' => crm_url('consultor', 'visita') . '?id=V215'],
    ['quando' => 'hoje', 'dias' => 0, 'tipo' => 'Visita', 'prod' => 'Carlos Mendes',
     't' => 'Visita 13:30 · Santa Helena', 'x' => 'Retomar contato após 32 dias. Oportunidade O-112 parada.',
     'href' => crm_url('consultor', 'visita') . '?id=V216'],
    ['quando' => 'hoje', 'dias' => 0, 'tipo' => 'Visita', 'prod' => 'Helena Vasconcelos',
     't' => 'Visita 16:00 · Bom Jesus', 'x' => 'Pós-venda: conferir resultado do bioinsumo aplicado em 04/08.',
     'href' => crm_url('consultor', 'visita') . '?id=V217'],
    ['quando' => 'hoje', 'dias' => 0, 'tipo' => 'Follow-up', 'prod' => 'João Almeida',
     't' => 'Enviar cotação do programa de pré-colheita · U-03', 'x' => 'Prometido na visita V212 de 19/08. Vence hoje.',
     'href' => crm_url('consultor', 'oportunidade') . '?id=O-115'],
    ['quando' => 'proximo', 'dias' => 2, 'tipo' => 'Follow-up', 'prod' => 'Maria Oliveira',
     't' => 'Enviar programa de floração revisado (LMR-UE)', 'x' => 'Vale Verde · importador atualizou lista de moléculas restritas.',
     'href' => crm_url('consultor', 'oportunidade') . '?id=O-121'],
    ['quando' => 'proximo', 'dias' => 3, 'tipo' => 'Proposta', 'prod' => 'Roberto Nakamura',
     't' => 'Enviar proposta de adubação de reposição', 'x' => 'Riacho Grande · análise de solo indicou K abaixo do ideal no T12.',
     'href' => crm_url('consultor', 'oportunidade') . '?id=O-119'],
    ['quando' => 'proximo', 'dias' => 4, 'tipo' => 'Fenológico', 'prod' => 'João Almeida',
     't' => 'Cotação de PBZ para 30 ha · Boa Vista II', 'x' => 'Janela de aplicação estimada para 01–07/09. Cotar antes.',
     'href' => crm_url('consultor', 'oportunidade') . '?id=O-124'],
    ['quando' => 'proximo', 'dias' => 6, 'tipo' => 'Visita', 'prod' => 'Helena Vasconcelos',
     't' => 'Visita de avaliação do bioinsumo · Bom Jesus', 'x' => '21 dias após a aplicação, conforme recomendado.',
     'href' => crm_url('consultor', 'produtor') . '?id=P07'],
];

/* Prioridade visual por atraso/urgência (fiel ao mockup: pri dgr/amber/info) */
$PRI = [
    'Retomar O-112 · Santa Helena' => 'red', 'Ligar para Antônio Ribeiro' => 'red', 'Reativar José Bezerra' => 'red',
    'Visita 13:30 · Santa Helena' => 'amber', 'Enviar cotação do programa de pré-colheita · U-03' => 'amber',
    'Cotação de PBZ para 30 ha · Boa Vista II' => 'amber',
];

$GRUPOS = [
    'atrasado' => ['Atrasados', 'red'],
    'hoje'     => ['Hoje', 'amber'],
    'proximo'  => ['Próximos 7 dias', 'blue'],
];

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'acoes',
    'titulo' => 'Próximas Ações',
]);
?>

<?php /* callout de automações removido a pedido do gestor 25/08 */ ?>
<div class="crm-tabs">
  <span class="crm-tab on">Todas (<?= count($ACOES) ?>)</span>
  <span class="crm-tab" data-toast="Filtro demonstrativo">Visitas</span>
  <span class="crm-tab" data-toast="Filtro demonstrativo">Follow-up</span>
  <span class="crm-tab" data-toast="Filtro demonstrativo">Comercial</span>
  <span class="crm-tab" data-toast="Filtro demonstrativo">Fenológico</span>
</div>

<?php foreach ($GRUPOS as $quando => [$rotulo, $corGrupo]):
    $lista = array_values(array_filter($ACOES, fn($a) => $a['quando'] === $quando));
    if (!$lista) continue;
?>
  <div class="crm-card" style="margin-bottom:14px">
    <div class="crm-card__head">
      <span class="crm-card__title"><?= h($rotulo) ?></span>
      <?= crm_pill((string)count($lista), $corGrupo) ?>
    </div>
    <?php foreach ($lista as $a):
        $pri = $PRI[$a['t']] ?? 'teal';
    ?>
      <div class="crm-ag" data-href="<?= $a['href'] ?>" style="cursor:pointer">
        <?= crm_avatar($a['prod'], $pri === 'teal' ? 'teal' : $pri) ?>
        <span class="crm-ag__body">
          <div class="crm-ag__t"><?= h($a['t']) ?> <?= crm_pill($a['tipo'], 'grey') ?></div>
          <div class="crm-ag__sub"><?= h($a['x']) ?></div>
        </span>
        <?php if ($quando === 'atrasado'): ?>
          <?= crm_pill($a['dias'] . ' dias', 'red') ?>
        <?php elseif ($quando === 'hoje'): ?>
          <?= crm_pill('hoje', 'amber') ?>
        <?php else: ?>
          <?= crm_pill('+' . $a['dias'] . ' d', 'blue') ?>
        <?php endif; ?>
        <button type="button" class="vbtn vbtn-sm vbtn-ghost" data-toast="Ação concluída">Concluir</button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<?php crm_shell_end();
