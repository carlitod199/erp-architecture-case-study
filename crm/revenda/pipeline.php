<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Pipeline comercial (kanban do funil)
   Rota: /crm/revenda/pipeline · dados: crm/_mock.php
   Kanban renderizado por crmKanbanInit (assets/js/crm.js).
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();
$nomesClientes = array_map(fn($c) => $c['nome'], $M['clientes']);   /* id => nome */

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'pipeline',
    'titulo' => 'Pipeline comercial',
    'sub'    => 'Revenda de insumos · 7 oportunidades · R$ 597.600 · funil ancorado no ciclo de safra',
    'papel'  => 'vendedor',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-nova-opp\')">＋ Nova oportunidade</button>',
]);
?>

<div id="crm-pipeline" class="crm-kanban"></div>

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

<script>
document.addEventListener('DOMContentLoaded', function () {
  crmKanbanInit('crm-pipeline', <?= jsvar(array_values($M['opps'])) ?>, <?= jsvar($M['etapas']) ?>, <?= jsvar($nomesClientes) ?>, '<?= crm_url('revenda', 'oportunidade') ?>');
});
</script>

<?php crm_shell_end();
