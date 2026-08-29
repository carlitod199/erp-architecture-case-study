<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Agenda & Visitas (protótipo demo)
   Rota: /crm/revenda/agenda · dados: crm/_mock.php
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M   = crm_mock();
$cli = $M['clientes'];

/* Rótulo do tipo de compromisso (a cor acompanha $a['cor'] do mock) */
$tipos = ['visita' => 'Visita', 'call' => 'Follow-up', 'proposta' => 'Proposta', 'reuniao' => 'Reunião'];

/* TODO mover para _mock.php */
$resumoDia = [
    ['Visitas', '2'],
    ['Follow-ups', '1'],
    ['Propostas', '1'],
    ['Km estimado', '148 km'],
];

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'agenda',
    'titulo' => 'Agenda & Visitas',
    'sub'    => 'Quinta, 13 de agosto · 5 compromissos · 2 visitas',
    'papel'  => 'vendedor',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-nova-visita\')">＋ Nova visita</button>',
]);
?>

<div class="crm-tabs">
  <span class="crm-tab on">Dia</span>
  <span class="crm-tab">Semana</span>
  <span class="crm-tab">Mês</span>
</div>

<div class="crm-g23">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Compromissos de hoje</span>
      <?= crm_pill(count($M['agenda']) . ' compromissos', 'teal') ?>
    </div>
    <?php foreach ($M['agenda'] as $a): ?>
      <div class="crm-ag">
        <span class="crm-ag__h"><?= h($a['h']) ?></span>
        <span class="crm-ag__bar b-<?= h($a['cor']) ?>"></span>
        <span class="crm-ag__body">
          <div class="crm-ag__t"><?= h($a['t']) ?></div>
          <div class="crm-ag__sub"><?= h($a['sub']) ?></div>
        </span>
        <?= crm_pill($tipos[$a['tipo']] ?? $a['tipo'], $a['cor']) ?>
        <?php if ($a['tipo'] === 'visita'): ?>
          <button type="button" class="vbtn vbtn-sm" data-toast="Visita iniciada, check-in registrado">Iniciar</button>
        <?php else: ?>
          <a class="vbtn vbtn-sm vbtn-ghost" href="<?= crm_url('revenda', 'cliente') ?>?id=<?= h($a['cliente']) ?>">Abrir</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Resumo do dia</span>
    </div>
    <?php foreach ($resumoDia as [$k, $v]): ?>
      <?= crm_kv($k, h($v)) ?>
    <?php endforeach; ?>
    <?= crm_callout(
        '<strong>Rota sugerida</strong> '
        . '<br>Rota sugerida economiza <strong>32 km</strong>: Santa Helena › Nova Era › São José.'
        . '<div style="margin-top:8px"><a class="vbtn vbtn-sm" href="' . crm_url('revenda', 'mapa') . '">Ver no mapa</a></div>',
        'teal'
    ) ?>
  </div>
</div>

<!-- Modal: nova visita (mock — sem POST) -->
<div class="vmodal" id="vm-nova-visita">
  <div class="vbox">
    <header>
      <h2>Nova visita</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-nova-visita')">×</button>
    </header>
    <div class="vform">
      <div class="vgrid">
        <div class="vfield full">
          <label>Cliente</label>
          <select>
            <?php foreach ($cli as $c): ?>
              <option><?= h($c['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Data</label>
          <input type="text" value="13/08/2026">
        </div>
        <div class="vfield">
          <label>Hora</label>
          <input type="text" value="14:00">
        </div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-nova-visita')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Visita agendada">Agendar</button>
      </div>
    </div>
  </div>
</div>

<?php crm_shell_end();
