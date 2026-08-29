<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Comparativo de soluções (protótipo)
   Rota: /crm/revenda/comparativo · lado a lado convencional ×
   biológico para o vendedor explicar na visita. Dados mock.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

/* TODO mover para _mock.php — programas comparados (uva Crimson · 120 ha) */
$comp = [
    'conv' => [
        'titulo'   => 'PROGRAMA CONVENCIONAL',
        'custo_ha' => 240,   'inv'    => 28800,
        'prod'     => 38,    'preco'  => 2.80,
        'receita'  => 12768000,          /* 38 t × 1.000 kg × R$ 2,80 × 120 ha */
        'margem'   => 26,
        'result'   => 12739200,          /* receita − investimento no programa */
    ],
    'bio' => [
        'titulo'   => 'PROGRAMA BIOLÓGICO · BioRoot',
        'custo_ha' => 310,   'inv'    => 37200,
        'prod'     => 41,    'preco'  => 2.95,
        'receita'  => 14514000,          /* 41 t × 1.000 kg × R$ 2,95 × 120 ha */
        'margem'   => 31,
        'result'   => 14476800,
    ],
];

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'comparativo',
    'titulo' => 'Comparativo de soluções',
    'sub'    => 'Convencional × Biológico · uva Crimson · 120 ha · fácil de explicar ao cliente',
    'papel'  => 'vendedor',
]);
?>

<div class="crm-g2">

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title"><?= h($comp['conv']['titulo']) ?></span>
    </div>
    <?= crm_kv('Custo/ha', crm_brl((float)$comp['conv']['custo_ha'])) ?>
    <?= crm_kv('Investimento (120 ha)', crm_brl((float)$comp['conv']['inv'])) ?>
    <?= crm_kv('Produtividade', crm_num((float)$comp['conv']['prod']) . ' t/ha') ?>
    <?= crm_kv('Preço de venda', crm_brl((float)$comp['conv']['preco'], 2) . '/kg') ?>
    <?= crm_kv('Receita', crm_brl((float)$comp['conv']['receita'])) ?>
    <?= crm_kv('Margem', crm_num((float)$comp['conv']['margem']) . '%') ?>
    <div style="margin-top:14px">
      <div class="crm-card__title">Resultado (receita − programa)</div>
      <div style="font-size:26px;font-weight:700;color:var(--crm-grey)"><?= crm_brl((float)$comp['conv']['result']) ?></div>
    </div>
  </div>

  <div class="crm-card" style="border-color:var(--crm-teal-d)">
    <div class="crm-card__head">
      <span class="crm-card__title"><?= h($comp['bio']['titulo']) ?></span>
      <?= crm_pill('Recomendado', 'teal') ?>
    </div>
    <?= crm_kv('Custo/ha', crm_brl((float)$comp['bio']['custo_ha'])) ?>
    <?= crm_kv('Investimento (120 ha)', crm_brl((float)$comp['bio']['inv'])) ?>
    <?= crm_kv('Produtividade', crm_num((float)$comp['bio']['prod']) . ' t/ha') ?>
    <?= crm_kv('Preço de venda', crm_brl((float)$comp['bio']['preco'], 2) . '/kg · prêmio de qualidade') ?>
    <?= crm_kv('Receita', crm_brl((float)$comp['bio']['receita'])) ?>
    <?= crm_kv('Margem', crm_num((float)$comp['bio']['margem']) . '%') ?>
    <div style="margin-top:14px">
      <div class="crm-card__title">Resultado (receita − programa)</div>
      <div style="font-size:26px;font-weight:700;color:var(--crm-teal)"><?= crm_brl((float)$comp['bio']['result']) ?></div>
    </div>
  </div>

</div>

<?php crm_shell_end();
