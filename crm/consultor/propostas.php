<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Propostas & Pedidos
   Rota: /crm/consultor/propostas
   Propostas comerciais e pedidos integrados ao ERP (demo).
   Dados fiéis ao mockup docs/VERO_CRM_Consultor_Frutas_Mockup.html.
   TODO mover os dados para _mock.php.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* produtor => nome */
$PRODUTORES = [
    'P01' => 'João Almeida',
    'P02' => 'Carlos Mendes',
    'P03' => 'Maria Oliveira',
    'P06' => 'Roberto Nakamura',
    'P07' => 'Helena Vasconcelos',
];

$PROPOSTAS = [
    ['id' => 'PR-0442', 'opor' => 'O-115', 'prod' => 'P01', 'titulo' => 'Programa pré-colheita U-03',       'valor' => 186000, 'status' => 'Aguardando',    'emit' => '20/08/2026', 'val' => '05/09/2026', 'itens' => 6],
    ['id' => 'PR-0439', 'opor' => 'O-121', 'prod' => 'P03', 'titulo' => 'Programa de floração LMR-UE',      'valor' => 98000,  'status' => 'Em revisão',    'emit' => '15/08/2026', 'val' => '10/09/2026', 'itens' => 4],
    ['id' => 'PR-0435', 'opor' => 'O-112', 'prod' => 'P02', 'titulo' => 'Renovação fitossanitária 2026.2',  'valor' => 315000, 'status' => 'Em negociação', 'emit' => '08/08/2026', 'val' => '30/08/2026', 'itens' => 11],
    ['id' => 'PD-3391', 'opor' => 'O-104', 'prod' => 'P07', 'titulo' => 'Pedido · nutrição foliar 2º ciclo', 'valor' => 41000, 'status' => 'Entregue',      'emit' => '12/08/2026', 'val' => '—',          'itens' => 3],
    ['id' => 'PD-3387', 'opor' => '',      'prod' => 'P06', 'titulo' => 'Pedido · adubação de cobertura',   'valor' => 63500,  'status' => 'Faturado',      'emit' => '04/08/2026', 'val' => '—',          'itens' => 5],
];

$corStatus = static function (string $s): string {
    if ($s === 'Faturado' || $s === 'Entregue') return 'green';
    if ($s === 'Aguardando') return 'amber';
    return 'blue';   /* Em revisão / Em negociação */
};

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'propostas',
    'titulo' => 'Propostas & Pedidos',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-nova-pr\')">＋ Nova proposta</button>',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Propostas abertas', '3', crm_brl(599000) . ' em análise', 'blue') ?>
  <?= crm_kpi('Pedidos no mês', '2', crm_brl(104500) . ' faturados', 'green') ?>
  <?= crm_kpi('Taxa de aceite', '58%', crm_trend(6.0, ' p.p.') . ' vs. ciclo anterior', 'teal') ?>
  <?= crm_kpi('Vencendo em 7 dias', '1', 'PR-0435 · ' . crm_brl(315000), 'amber') ?>
</div>

<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title"><?= count($PROPOSTAS) ?> registro(s)</span>
    <?= crm_demo('Integração ERP') ?>
  </div>
  <div class="crm-tabs">
    <span class="crm-tab on">Todas (5)</span>
    <span class="crm-tab">Propostas (3)</span>
    <span class="crm-tab">Pedidos (2)</span>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Documento</th>
          <th>Produtor</th>
          <th>Título</th>
          <th class="num">Itens</th>
          <th class="num">Valor</th>
          <th>Emissão</th>
          <th>Validade</th>
          <th>Status</th>
          <th class="num">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($PROPOSTAS as $pr): ?>
        <tr class="tap" <?= $pr['opor'] !== ''
            ? 'data-href="' . crm_url('consultor', 'oportunidade') . '?id=' . h($pr['opor']) . '"'
            : 'data-toast="Documento sem oportunidade vinculada · demonstrativo"' ?>>
          <td><strong><?= h($pr['id']) ?></strong></td>
          <td><?= h($PRODUTORES[$pr['prod']]) ?></td>
          <td><?= h($pr['titulo']) ?></td>
          <td class="num"><?= (int)$pr['itens'] ?></td>
          <td class="num"><strong><?= crm_brl((float)$pr['valor']) ?></strong></td>
          <td class="sub"><?= h($pr['emit']) ?></td>
          <td class="sub"><?= h($pr['val']) ?></td>
          <td><?= crm_pill($pr['status'], $corStatus($pr['status'])) ?></td>
          <td class="num" style="white-space:nowrap">
            <button type="button" class="vbtn" data-toast="PDF de <?= h($pr['id']) ?> gerado">PDF</button>
            <button type="button" class="vbtn" data-toast="<?= h($pr['id']) ?> enviado por WhatsApp · demonstrativo">Enviar</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?= crm_callout(
    'Proposta aceita vira <strong>pedido no ERP</strong> sem redigitação — itens, produtor e condição comercial '
    . 'seguem para o faturamento. ' . crm_demo('Integração ERP'),
    'teal'
) ?>

<!-- Modal: nova proposta (demo — sem persistência) -->
<div class="vmodal" id="vm-nova-pr">
  <div class="vbox">
    <header>
      <h2>Nova proposta</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-nova-pr')">×</button>
    </header>
    <div class="vform">
      <div class="vfield">
        <label>Produtor</label>
        <select>
          <?php foreach ($PRODUTORES as $nome): ?>
            <option><?= h($nome) ?></option>
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
        <label>Validade</label>
        <input type="text" placeholder="10/09/2026">
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-nova-pr')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Proposta criada">Criar</button>
      </div>
    </div>
  </div>
</div>

<?php crm_shell_end();
