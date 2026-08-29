<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Pedidos & Vendas (protótipo demo)
   Rota: /crm/revenda/pedidos · dados: crm/_mock.php
   Pedido nasce no CRM e é faturado no ERP VERO (sem duplicação).
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

/* KPIs do mês (resumo comercial) */ /* TODO mover para _mock.php */
$kpisPedidos = [
    ['Em aberto',         '3',  'R$ 112.000',        'blue'],
    ['Aprovados',         '2',  'R$ 78.000',         'teal'],
    ['Faturados no mês',  '12', 'R$ 342.000',        'green'],
    ['Pendentes',         '1',  'crédito em análise', 'amber'],
];

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'pedidos',
    'titulo' => 'Pedidos & Vendas',
    'sub'    => 'Criados no CRM',
    'papel'  => 'vendedor',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-novo-pedido\')">＋ Novo pedido</button>',
]);
?>

<div class="crm-g4">
  <?php foreach ($kpisPedidos as [$rot, $val, $pe, $cor]): ?>
    <?= crm_kpi($rot, $val, $pe, $cor) ?>
  <?php endforeach; ?>
</div>

<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title">Pedidos recentes</span>
    <?= crm_pill(count($M['pedidos']) . ' pedidos', 'teal') ?>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Pedido</th>
          <th>Cliente</th>
          <th>Produtos</th>
          <th class="num">Valor</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($M['pedidos'] as $p): ?>
        <tr class="tap" data-toast="Pedido #<?= h($p['num']) ?> aberto no ERP (demonstrativo)">
          <td><strong style="font-family:var(--num,'IBM Plex Mono')">#<?= h($p['num']) ?></strong></td>
          <td><?= h($p['cliente']) ?></td>
          <td class="sub"><?= h($p['prod']) ?></td>
          <td class="num"><?= crm_brl((float)$p['valor']) ?></td>
          <td><?= crm_pill($p['status'], $p['cor']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: novo pedido (demo — sem persistência) -->
<div class="vmodal" id="vm-novo-pedido">
  <div class="vbox">
    <header>
      <h2>Novo pedido</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-novo-pedido')">×</button>
    </header>
    <div class="vform">
      <div class="vfield">
        <label>Cliente</label>
        <select>
          <?php foreach ($M['clientes'] as $c): ?>
            <option><?= h($c['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vfield">
        <label>Propriedade</label>
        <select>
          <?php foreach ($M['clientes']['c1']['props'] as $pr): ?>
            <option><?= h($pr['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vfield">
        <label>Produtos</label>
        <div class="crm-chips" style="margin:6px 0 0">
          <span class="crm-chip on">ProtectSC · R$ 145</span>
          <span class="crm-chip on">SpreadFix · R$ 32</span>
          <span class="crm-chip">＋</span>
        </div>
      </div>
      <div class="vfield">
        <label>Quantidade</label>
        <input type="text" value="120 L">
      </div>
      <div class="vfield">
        <label>Condição</label>
        <select>
          <option>28 dias</option>
          <option>À vista</option>
        </select>
      </div>
      <?= crm_kv('Total estimado', '<span style="color:var(--crm-teal)">' . crm_brl(52200) . '</span>') ?>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-novo-pedido')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Pedido enviado ao ERP para faturamento">Enviar pedido</button>
      </div>
    </div>
  </div>
</div>

<?php crm_shell_end();
