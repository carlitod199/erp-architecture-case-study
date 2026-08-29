<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Oportunidade (detalhe + funil interativo)
   Rota: /crm/revenda/oportunidade?id=oX · dados: crm/_mock.php
   Barra de funil controlada por crmOppInit/crmOppMove (crm.js).
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M  = crm_mock();
$id = $_GET['id'] ?? 'o2';
$o  = $M['opps'][$id] ?? $M['opps']['o2'];
$c  = $M['clientes'][$o['cliente']];

/* preço unitário por nome de produto (catálogo do mock) */
$precos = [];
foreach ($M['produtos'] as $p) {
    $precos[$p['nome']] = $p;
}
$itens = array_map('trim', explode(' + ', (string)$o['prod']));
$dias  = (int)$o['dias'];

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'oportunidade',
    'titulo' => $o['nome'],
    'sub'    => $c['nome'] . ' · ' . $c['cidade'],
    'papel'  => 'vendedor',
    'acoes'  => '<a class="vbtn vbtn-primary" href="' . crm_url('revenda', 'pedidos') . '">Criar pedido</a>',
]);
?>

<a class="crm-crumb" href="<?= crm_url('revenda', 'pipeline') ?>">‹ Pipeline</a>

<div class="crm-g3">
  <?= crm_kpi('Valor', crm_brl((float)$o['valor']), '', 'teal') ?>
  <?= crm_kpi('Probabilidade', (int)$o['prob'] . '%', '', 'blue') ?>
  <?= crm_kpi('Etapa atual', '<span id="crm-etapa-atual"></span>', '', 'amber') ?>
</div>

<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title">Progresso no funil</span>
  </div>
  <div id="crm-funil" style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px">
    <?php foreach ($M['etapas'] as $i => $etapa): ?>
      <div data-etapa="<?= (int)$i ?>">
        <div style="font:10px var(--num,'IBM Plex Mono');text-transform:uppercase;letter-spacing:.8px;color:var(--crm-ink3);margin-bottom:6px"><?= h($etapa) ?></div>
        <span class="crm-track"><span class="crm-fill" style="width:0%"></span></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px">
    <button class="vbtn" onclick="crmOppMove(-1)">‹ Voltar etapa</button>
    <button class="vbtn vbtn-primary" id="crm-btn-avancar" onclick="crmOppMove(1)"></button>
    <span id="crm-opp-ganha" style="display:none"><?= crm_pill('Ganha · pedido gerado', 'green') ?></span>
  </div>
</div>

<div class="crm-g2">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Produtos</span>
      <?= crm_pill(count($itens) . ' item' . (count($itens) > 1 ? 'ns' : ''), 'teal') ?>
    </div>
    <?php foreach ($itens as $item):
        $p = $precos[$item] ?? null; ?>
      <?= crm_kv($item, $p !== null ? crm_brl((float)$p['preco'], 2) . '/' . h($p['un']) : '—') ?>
    <?php endforeach; ?>
    <a class="vbtn" style="margin-top:12px" href="<?= crm_url('revenda', 'roi') ?>">Simular ROI desta proposta</a>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Próximos passos</span>
    </div>
    <?= crm_callout(
        $dias > 0
          ? 'Fechar em <strong>' . $dias . ' dias</strong> para não perder a janela de aplicação.'
          : 'Fechar <strong>hoje</strong> para não perder a janela de aplicação.',
        'amber'
    ) ?>
    <div class="crm-chips">
      <span class="crm-chip on">Enviar proposta revisada</span>
      <span class="crm-chip">Agendar visita de fechamento</span>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  crmOppInit({etapa: <?= (int)$o['etapa'] ?>, etapas: <?= jsvar($M['etapas']) ?>});
});
</script>

<?php crm_shell_end();
