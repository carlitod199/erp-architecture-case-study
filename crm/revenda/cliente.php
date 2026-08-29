<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Cliente 360 (detalhe da carteira)
   Rota: /crm/revenda/cliente?id=cX · dados: crm/_mock.php
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M  = crm_mock();
$id = (string)($_GET['id'] ?? 'c1');
if (!isset($M['clientes'][$id])) $id = 'c1';     /* fallback: primeiro da carteira */
$c        = $M['clientes'][$id];
$prospect = $c['status'] === 'Prospect';

/* Oportunidades deste cliente (funil central) */
$opps = array_filter($M['opps'], fn($o) => $o['cliente'] === $id);

/* TODO mover para _mock.php — dados de detalhe ainda não fixados no mock central */
$USO_PRODUTOS = [85, 60, 40, 72, 55];            /* % de recorrência por produto (fake) */
$TIMELINE = [
    ['dt' => '18/07', 't' => 'Pedido faturado ' . crm_brl(31000), 'dot' => 'green',  'demo' => ''],
    ['dt' => '15/07', 't' => 'Ligação de follow-up',              'dot' => 'blue',   'demo' => ''],
    ['dt' => '12/07', 't' => 'Proposta enviada',                  'dot' => 'teal',   'demo' => ''],
    ['dt' => '01/07', 't' => 'Visita técnica',                    'dot' => 'teal',   'demo' => ''],
    ['dt' => '20/06', 't' => 'Simulação de ROI apresentada',      'dot' => 'violet', 'demo' => ''],
];
$FINANCEIRO = ['limite' => 300000, 'aberto' => 48000];

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'cliente',
    'titulo' => $c['nome'],
    'sub'    => $c['tipo'] . ' · ' . $c['cidade'] . ' · ' . $c['cultura'] . ' · ' . crm_num((float)$c['area']) . ' ha',
    'papel'  => 'vendedor',
]);
?>

<a class="crm-crumb" href="<?= crm_url('revenda', 'clientes') ?>">‹ Clientes</a>

<!-- Cabeçalho do cliente -->
<div class="crm-card" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <?= crm_avatar($c['nome'], $c['cor'], 'g') ?>
  <div style="flex:1;min-width:220px">
    <div style="font-size:16px;font-weight:700"><?= h($c['nome']) ?></div>
    <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
      <?= crm_status_pill($c['status']) ?>
      <?= crm_pill($c['seg'], 'grey') ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button type="button" class="vbtn" data-toast="Ligação registrada na timeline">Ligar</button>
    <a class="vbtn" href="<?= crm_url('revenda', 'agenda') ?>">Visita</a>
    <a class="vbtn" href="<?= crm_url('revenda', 'roi') ?>">ROI</a>
  </div>
</div>

<div class="crm-g4">
  <?= crm_kpi('Faturamento 12m',
        $prospect ? '—' : crm_brl((float)$c['fat12']),
        $prospect ? 'ainda sem faturamento' : 'margem ' . crm_num((float)$c['margem']) . '%',
        'green') ?>
  <?= crm_kpi('Variação de consumo',
        crm_trend((float)$c['var_consumo']),
        'vs. padrão histórico',
        $c['var_consumo'] < 0 ? 'red' : 'green') ?>
  <?= crm_kpi('Última visita',
        $prospect ? '—' : (int)$c['ult_visita'] . 'd',
        $prospect ? 'primeira visita pendente' : ($c['ult_visita'] > 14 ? 'acima do ideal' : 'em dia'),
        $prospect ? 'blue' : ($c['ult_visita'] > 14 ? 'amber' : 'teal')) ?>
  <?= crm_kpi('Potencial de compra',
        h($c['pot']),
        'risco ' . strtolower($c['risco']),
        'violet') ?>
</div>

<?php if ($c['var_consumo'] < -10): ?>
  <?= crm_callout(
      '<strong>Alerta de churn.</strong> Este cliente comprava <strong>BioRoot</strong> regularmente. '
      . 'Consumo <strong>' . crm_num(abs((float)$c['var_consumo'])) . '%</strong> abaixo do padrão histórico.'
      . '<div style="margin-top:8px">'
      . '<button type="button" class="vbtn vbtn-sm" data-toast="Oportunidade criada no pipeline">Criar oportunidade</button>'
      . '</div>',
      'red'
  ) ?>
<?php endif; ?>

<div class="crm-g23">
  <div>
    <!-- Perfil agrícola -->
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Perfil agrícola</span>
        <?= crm_pill(count($c['props']) . ' propriedade' . (count($c['props']) > 1 ? 's' : ''), 'teal') ?>
      </div>
      <div class="crm-tblwrap">
        <table class="crm-tbl">
          <thead>
            <tr><th>Propriedade</th><th>Município</th><th class="num">Área (ha)</th><th>Cultura</th></tr>
          </thead>
          <tbody>
            <?php foreach ($c['props'] as $p): ?>
            <tr>
              <td><strong><?= h($p['nome']) ?></strong></td>
              <td><?= h($p['municipio']) ?></td>
              <td class="num"><?= crm_num((float)$p['area']) ?></td>
              <td><?= h($p['cultura']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Produtos & histórico de compra -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Produtos &amp; histórico de compra</span>
        <?php if (!$prospect): ?><?= crm_pill(count($c['produtos']) . ' produtos', 'grey') ?><?php endif; ?>
      </div>
      <?php if ($prospect): ?>
        <div class="crm-empty">Ainda sem histórico de compra</div>
      <?php else: ?>
        <div class="crm-hbars">
          <?php foreach ($c['produtos'] as $i => $prod): $pct = $USO_PRODUTOS[$i % count($USO_PRODUTOS)]; ?>
          <div class="crm-hbar">
            <span><?= h($prod) ?></span>
            <?= crm_bar((float)$pct) ?>
            <span class="num"><?= crm_num((float)$pct) ?>%</span>
          </div>
          <?php endforeach; ?>
        </div>
        <?= crm_callout(
            '<strong>Cross-sell sugerido:</strong> VigorPlus / SpreadFix ',
            'teal'
        ) ?>
      <?php endif; ?>
    </div>

    <!-- Oportunidades -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Oportunidades</span>
        <?= crm_pill((string)count($opps), 'blue') ?>
      </div>
      <?php if (!$opps): ?>
        <div class="crm-empty">Nenhuma oportunidade aberta para este cliente</div>
      <?php else: ?>
      <div class="crm-tblwrap">
        <table class="crm-tbl">
          <thead>
            <tr><th>Oportunidade</th><th>Etapa</th><th class="num">Valor</th><th class="num">Prob.</th></tr>
          </thead>
          <tbody>
            <?php foreach ($opps as $o): ?>
            <tr class="tap" data-href="<?= crm_url('revenda', 'oportunidade') ?>?id=<?= h($o['id']) ?>">
              <td><strong><?= h($o['nome']) ?></strong><div class="sub"><?= h($o['prod']) ?></div></td>
              <td><?= crm_pill($M['etapas'][$o['etapa']], 'blue') ?></td>
              <td class="num"><?= crm_brl((float)$o['valor']) ?></td>
              <td class="num"><?= (int)$o['prob'] ?>%</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <!-- Contatos -->
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Contatos</span>
        <?= crm_pill((string)count($c['contatos']), 'grey') ?>
      </div>
      <?php foreach ($c['contatos'] as $ct): ?>
      <div class="crm-ag">
        <?= crm_avatar($ct['nome'], $c['cor']) ?>
        <span class="crm-ag__body">
          <div class="crm-ag__t"><?= h($ct['nome']) ?></div>
          <div class="crm-ag__sub"><?= h($ct['cargo']) ?></div>
        </span>
        <?= crm_pill($ct['tel'], 'grey') ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Linha do tempo -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Linha do tempo</span>
      </div>
      <div class="crm-tl">
        <?php foreach ($TIMELINE as $tl): ?>
        <div class="crm-tl__item">
          <span class="crm-tl__dot<?= $tl['dot'] !== 'teal' ? ' d-' . h($tl['dot']) : '' ?>"></span>
          <div class="crm-tl__dt"><?= h($tl['dt']) ?></div>
          <div class="crm-tl__t"><?= h($tl['t']) ?><?= $tl['demo'] !== '' ? ' ' . crm_demo($tl['demo']) : '' ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Financeiro (viria do ERP) -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Financeiro</span>
      </div>
      <?= crm_kv('Limite de crédito', crm_brl((float)$FINANCEIRO['limite'])) ?>
      <?= crm_kv('Em aberto', crm_brl((float)$FINANCEIRO['aberto'])) ?>
      <?= crm_kv('Inadimplência', 'Nenhuma') ?>
      <?= crm_kv('Margem média', $c['margem'] > 0 ? crm_num((float)$c['margem']) . '%' : '—') ?>
    </div>
  </div>
</div>

<?php crm_shell_end();
