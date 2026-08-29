<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Meu dia · Modo Vendedor (foco no campo)
   Rota: /crm/revenda/meu-dia · dados: crm/_mock.php
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M  = crm_mock();
$c1 = $M['clientes']['c1'];                 /* próxima parada: Fazenda Santa Helena */
$o2 = $M['opps']['o2'];                     /* oportunidade aberta da parada */
$concB = $M['concorrencia'][1];             /* Concorrente B · ProtectSC equiv. */
$sex   = $M['clima'][1];                    /* sexta · 12mm */

/* TODO mover para _mock.php — dado de detalhe ainda não fixado no mock central */
$ULTIMA_COMPRA = 'há 12 dias';

/* Nome curto da oportunidade p/ o KV: "Manejo de oídio - 120 ha" vira "Manejo de oídio" */
$oppCurto = trim(explode('·', $o2['nome'])[0]);

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'meu_dia',
    'titulo' => 'Meu dia',
    'sub'    => 'Modo Vendedor · foco no essencial para o campo',
    'papel'  => 'vendedor',
]);
?>

<div class="crm-g12">
  <!-- Roteiro do dia -->
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Hoje · <?= count($M['agenda']) ?> paradas</span>
      <?= crm_pill('13/08', 'teal') ?>
    </div>
    <div class="crm-tl">
      <?php foreach ($M['agenda'] as $i => $a):
          /* título sem o prefixo do tipo: "Visita · Fazenda X" vira "Fazenda X" */
          $partes = explode('·', $a['t'], 2);
          $titulo = trim($partes[1] ?? $partes[0]);
      ?>
      <div class="crm-tl__item">
        <span class="crm-tl__dot<?= $a['cor'] !== 'teal' ? ' d-' . h($a['cor']) : '' ?>"></span>
        <div class="crm-tl__dt"><?= h($a['h']) ?> · <?= h($a['tipo']) ?></div>
        <div class="crm-tl__t"><?= h($titulo) ?></div>
        <div class="crm-tl__sub"><?= h($a['sub']) ?></div>
        <?php if ($i === 0): ?>
          <button type="button" class="vbtn vbtn-primary vbtn-sm" style="margin-top:8px"
                  data-toast="Check-in registrado · Santa Helena">Fazer check-in</button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Preparação da próxima visita -->
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Próxima parada · <?= h($c1['nome']) ?></span>
      <?= crm_pill($M['agenda'][0]['h'], 'teal') ?>
    </div>
    <div class="crm-sub" style="margin:-6px 0 12px">
      <?= h($c1['cidade']) ?> · <?= h($c1['cultura']) ?> · <?= crm_num((float)$c1['area']) ?> ha
    </div>

    <div class="crm-card__title" style="margin-bottom:6px">O que preciso saber antes da visita</div>
    <?= crm_kv('Última compra', h($ULTIMA_COMPRA)) ?>
    <?= crm_kv('Produtos em uso', h(implode(' · ', $c1['produtos']))) ?>
    <?= crm_kv('Cultura / área', h($c1['props'][0]['cultura']) . ' · ' . crm_num((float)$c1['area']) . ' ha') ?>
    <?= crm_kv('Oportunidade aberta', h($oppCurto) . ' · ' . crm_brl((float)$o2['valor'])) ?>
    <?= crm_kv('Concorrência', h($concB['conc']) . ' a ' . crm_brl((float)$concB['cp']) . ' na região') ?>
    <?= crm_kv('Clima 5 dias', 'chuva sexta (' . crm_num((float)$sex['ch']) . 'mm)') ?>
  </div>
</div>

<?php crm_shell_end();
