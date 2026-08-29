<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Corretor / Financeiro operacional (protótipo demo)
   Rota: /crm/corretor/financeiro · dados: crm/_mock.php
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

crm_shell_start([
    'modulo' => 'corretor',
    'micro'  => 'financeiro',
    'titulo' => 'Financeiro operacional',
    'sub'    => 'Corretagem · posição do dia · resultado por operação',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Saldo operacional', crm_brl(184300), crm_trend(2) . ' hoje', 'teal') ?>
  <?= crm_kpi('A receber (7d)', crm_brl(96400), '5 títulos', 'blue') ?>
  <?= crm_kpi('A pagar (7d)', crm_brl(71200), 'produtores e frete', 'amber') ?>
  <?= crm_kpi('Vencido', crm_brl(14200), 'CEASA-MG', 'red') ?>
</div>

<div class="crm-g2">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Contas a receber</span>
      <?= crm_pill(count($M['fin_receber']) . ' títulos', 'blue') ?>
    </div>
    <div class="crm-tblwrap">
      <table class="crm-tbl">
        <thead>
          <tr><th>Quem</th><th class="num">Valor</th><th>Vencimento</th></tr>
        </thead>
        <tbody>
          <?php foreach ($M['fin_receber'] as $t): ?>
          <tr>
            <td><?= h($t['quem']) ?></td>
            <td class="num"><?= crm_brl((float)$t['valor']) ?></td>
            <td><?= crm_pill($t['venc'], $t['cor']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Contas a pagar</span>
      <?= crm_pill(count($M['fin_pagar']) . ' títulos', 'amber') ?>
    </div>
    <div class="crm-tblwrap">
      <table class="crm-tbl">
        <thead>
          <tr><th>Quem</th><th class="num">Valor</th><th>Vencimento</th></tr>
        </thead>
        <tbody>
          <?php foreach ($M['fin_pagar'] as $t): ?>
          <tr>
            <td><?= h($t['quem']) ?></td>
            <td class="num"><?= crm_brl((float)$t['valor']) ?></td>
            <td><?= crm_pill($t['venc'], $t['cor']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="crm-card" style="margin-top:14px">
  <div class="crm-card__head">
    <span class="crm-card__title">Resultado por carregamento</span>
    <span class="crm-sub">margem = venda − frete − comissão</span>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Carregamento</th><th>Destino</th>
          <th class="num">Venda</th><th class="num">Custos</th>
          <th class="num">Margem</th><th class="num">R$/kg</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($M['cargas'] as $c):
            $peso   = array_sum(array_column($c['itens'], 'peso'));
            $custos = (float)$c['frete'] + (float)$c['com'];
            $margem = (float)$c['venda'] - $custos;
            $rskg   = $peso > 0 ? $margem / $peso : 0.0;
        ?>
        <tr>
          <td><span style="font-family:var(--num,'IBM Plex Mono')">#<?= h($c['id']) ?></span></td>
          <td><?= h($c['dest']) ?></td>
          <td class="num"><?= crm_brl((float)$c['venda']) ?></td>
          <td class="num"><?= crm_brl($custos) ?></td>
          <td class="num"><strong style="color:var(--crm-green)"><?= crm_brl($margem) ?></strong></td>
          <td class="num"><?= crm_num($rskg, 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php crm_shell_end();
