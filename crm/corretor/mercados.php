<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Corretor / Clientes & Mercados (protótipo demo)
   Rota: /crm/corretor/mercados · dados: crm/_mock.php
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M    = crm_mock();
$mkts = $M['mercados'];

$volTotal = array_sum(array_column($mkts, 'vol'));   /* 110 t */
$volMax   = max(array_column($mkts, 'vol'));

/* Melhor preço por combinação Produto × Mercado (viria do ERP — DEMONSTRATIVO) */
/* TODO mover para _mock.php */
$prodMercado = [
    ['Palmer 1ª → CEAGESP',     'R$ 2,35/kg'],
    ['Crimson Extra → Recife',  'R$ 8,00/kg'],
    ['Kent Exportação → BH',    'R$ 3,55/kg'],
];

crm_shell_start([
    'modulo' => 'corretor',
    'micro'  => 'mercados',
    'titulo' => 'Clientes & Mercados',
    'sub'    => 'Compradores e destinos · análise Produto × Cliente × Mercado × Período',
    'acoes'  => '<select style="min-width:170px">'
              . '<option>Últimos 30 dias</option>'
              . '<option>Safra atual</option>'
              . '<option>Últimos 12 meses</option>'
              . '</select>',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Mercados ativos', '5', '4 praças CEASA + atacado', 'teal') ?>
  <?= crm_kpi('Volume no período', crm_num($volTotal) . ' t', crm_trend(7), 'blue') ?>
  <?= crm_kpi('Faturamento', crm_brl(312000), 'margem média 6,8%', 'green') ?>
  <?= crm_kpi('Melhor margem', 'Recife', '8,1% · uva', 'amber') ?>
</div>

<div class="crm-g23">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Compradores e destinos</span>
      <?= crm_pill(count($mkts) . ' mercados', 'teal') ?>
    </div>
    <div class="crm-tblwrap">
      <table class="crm-tbl">
        <thead>
          <tr>
            <th>#</th><th>Mercado / destino</th><th class="num">Volume</th>
            <th class="num">Faturamento</th><th>Freq.</th><th>Margem</th><th>Principais produtos</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mkts as $m):
              $mgCor = $m['margem'] >= 8 ? 'green' : ($m['margem'] >= 6.5 ? 'teal' : 'amber');
          ?>
          <tr class="tap" data-toast="Detalhe do mercado (demonstrativo)">
            <td><span style="font-family:var(--num,'IBM Plex Mono')"><?= (int)$m['rank'] ?></span></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <?= crm_avatar($m['nome'], $m['cor']) ?>
                <span>
                  <?= h($m['nome']) ?>
                  <div class="sub"><?= h($m['uf']) ?></div>
                </span>
              </div>
            </td>
            <td class="num"><?= crm_num((float)$m['vol']) ?> t</td>
            <td class="num"><?= crm_brl((float)$m['fat']) ?></td>
            <td><?= h($m['freq']) ?></td>
            <td><?= crm_pill(crm_num((float)$m['margem'], 1) . '%', $mgCor) ?></td>
            <td><span class="sub"><?= h($m['prods']) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Volume por destino</span>
        <span class="crm-sub">toneladas no período</span>
      </div>
      <div class="crm-hbars">
        <?php foreach ($mkts as $m): ?>
        <div class="crm-hbar">
          <span><?= h(explode(' · ', $m['nome'])[1] ?? $m['nome']) ?></span>
          <span class="crm-track"><span class="crm-fill f-<?= h($m['cor']) ?>" style="width:<?= crm_num($m['vol'] / $volMax * 100, 1) ?>%"></span></span>
          <span class="num"><?= crm_num((float)$m['vol']) ?> t</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Produto × Mercado</span>
      </div>
      <?php foreach ($prodMercado as [$combo, $preco]): ?>
        <?= crm_kv($combo, $preco) ?>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:14px">
    </div>
  </div>
</div>

<?php crm_shell_end();
