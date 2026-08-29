<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Oportunidades (lista do funil)
   Rota: /crm/revenda/oportunidades · dados: crm/_mock.php
   Visão tabular do pipeline; linha clica pro detalhe.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();
$nomesClientes = array_map(fn($c) => $c['nome'], $M['clientes']);   /* id => nome */

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'oportunidades',
    'titulo' => 'Oportunidades',
    'sub'    => 'Todas as oportunidades abertas',
    'papel'  => 'vendedor',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-nova-opp\')">＋ Nova</button>',
]);
?>

<div class="crm-card">
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Oportunidade</th>
          <th>Cliente</th>
          <th>Produtos</th>
          <th>Etapa</th>
          <th class="num">Valor</th>
          <th>Prob.</th>
          <th>Prazo</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($M['opps'] as $o): $dias = (int)$o['dias']; ?>
        <tr class="tap" data-href="<?= crm_url('revenda', 'oportunidade') ?>?id=<?= h($o['id']) ?>">
          <td><strong><?= h($o['nome']) ?></strong></td>
          <td><?= h($nomesClientes[$o['cliente']] ?? '—') ?></td>
          <td class="sub"><?= h($o['prod']) ?></td>
          <td><?= crm_pill($M['etapas'][$o['etapa']], 'blue') ?></td>
          <td class="num"><?= crm_brl((float)$o['valor']) ?></td>
          <td><?= crm_pill((int)$o['prob'] . '%', 'teal') ?></td>
          <td>
            <?php if ($dias === 0): ?>
              hoje
            <?php elseif ($dias <= 3): ?>
              <?= crm_pill($dias . 'd', 'amber') ?>
            <?php else: ?>
              <?= $dias ?>d
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
        <label>Cliente</label>
        <select>
          <?php foreach ($nomesClientes as $nome): ?>
            <option><?= h($nome) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vfield">
        <label>Título</label>
        <input type="text" placeholder="Ex.: Programa nutricional safra 26/27">
      </div>
      <div class="vfield">
        <label>Valor estimado</label>
        <input type="text" placeholder="R$ 52.200">
      </div>
      <div class="vfield">
        <label>Etapa</label>
        <select>
          <?php foreach ($M['etapas'] as $etapa): ?>
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
