<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Corretor / Dashboard da operação (protótipo demo)
   Rota: /crm/corretor/dashboard · dados: crm/_mock.php
   Corretor Vale Frutas Comercial · Petrolina/Juazeiro.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

crm_shell_start([
    'modulo' => 'corretor',
    'micro'  => 'dashboard',
    'titulo' => 'Dashboard',
    'sub'    => 'Vale Frutas Comercial · Petrolina/Juazeiro · quinta, 13/08 · 06:10',
]);
?>

<?php $hoje = $M['clima'][0]; /* ícone SVG: crm_icone_clima() em _lib.php */ ?>
<div style="display:grid;gap:14px;margin:14px 0">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Clima · Petrolina</span>
    </div>
    <?php /* fundo do mini-card por condição do tempo */
    $condClasse = ['☀' => 'sol', '⛅' => 'nublado', '🌧' => 'chuva']; ?>
    <div class="crm-clima2">
      <div class="crm-clima2__hoje">
        <span class="crm-clima2__ic"><?= crm_icone_clima($hoje['ic'], 38) ?></span>
        <div>
          <div class="crm-clima2__temp"><?= (int)$hoje['max'] ?>°</div>
          <div class="crm-clima2__min">mín <?= (int)$hoje['min'] ?>° · <?= $hoje['ch'] > 0 ? (int)$hoje['ch'] . ' mm de chuva' : 'sem chuva' ?></div>
          <div class="crm-clima2__lbl">Hoje · Petrolina</div>
        </div>
      </div>
      <div class="crm-clima2__dias">
        <?php foreach (array_slice($M['clima'], 1) as $d): ?>
          <div class="crm-clima2__dia crm-clima2__dia--<?= $condClasse[$d['ic']] ?? 'sol' ?>">
            <span class="d"><?= h($d['d']) ?></span>
            <?= crm_icone_clima($d['ic'], 22) ?>
            <span class="t"><?= (int)$d['max'] ?>° <small><?= (int)$d['min'] ?>°</small></span>
            <span class="ch<?= $d['ch'] > 0 ? ' tem' : '' ?>"><?= $d['ch'] > 0 ? (int)$d['ch'] . ' mm' : '—' ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Preços CEASA · destaques</span>
      <a class="vbtn vbtn-sm" href="<?= crm_url('corretor', 'ceasa') ?>">Ver todos ›</a>
    </div>
    <div class="crm-tblwrap">
      <table class="crm-tbl">
        <thead>
          <tr><th>Produto</th><th class="num">Comum</th><th class="num">Tendência</th></tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($M['ceasa'], 0, 5) as $r): ?>
            <tr>
              <td><strong><?= h($r['v']) ?></strong>
                  <div class="sub"><?= h($r['cl']) ?></div></td>
              <td class="num" style="font-weight:700"><?= crm_brl((float)$r['com'], 2) ?>/kg</td>
              <td class="num"><?= crm_trend((float)$r['t']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="crm-g3">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Carregamentos de hoje</span>
      <a class="vbtn vbtn-sm" href="<?= crm_url('corretor', 'carregamentos') ?>">Gerenciar ›</a>
    </div>
    <?php foreach ($M['cargas'] as $c): $peso = array_sum(array_column($c['itens'], 'peso')); ?>
      <div class="crm-ag" data-href="<?= crm_url('corretor', 'carregamentos') ?>" style="cursor:pointer">
        <span class="crm-ag__bar b-<?= h($c['cor']) ?>"></span>
        <span class="crm-ag__body">
          <div class="crm-ag__t">#<?= h($c['id']) ?> · <?= h($c['dest']) ?></div>
          <div class="crm-ag__sub"><?= h($c['mot']) ?> · <?= h($c['cam']) ?> · <?= crm_num((float)$peso) ?> kg</div>
        </span>
        <?= crm_pill($c['status'], $c['cor']) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Precisa da sua atenção</span>
      <?= crm_pill('3', 'red') ?>
    </div>
    <div data-href="<?= crm_url('corretor', 'financeiro') ?>" style="cursor:pointer">
      <?= crm_callout('Recebível <strong>CEASA-MG · R$ 14.200</strong> vence hoje.', 'red') ?>
    </div>
    <div data-href="<?= crm_url('corretor', 'ceasa') ?>" style="cursor:pointer">
      <?= crm_callout('Uva Itália <strong>+11%</strong> por baixa oferta: janela de venda.', 'amber') ?>
    </div>
    <div data-href="<?= crm_url('corretor', 'carregamentos') ?>" style="cursor:pointer">
      <?= crm_callout('Carga <strong>#0813-09</strong> (Kent) sem confirmação de destino.', 'teal') ?>
    </div>
  </div>

  <?php
    $perdasKg    = array_sum(array_column($M['perdas'], 'kg'));
    $perdasValor = array_sum(array_column($M['perdas'], 'valor'));
    $perdasMaxKg = max(array_column($M['perdas'], 'kg'));
  ?>
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Perdas do dia</span>
      <?= crm_pill(crm_num((float)$perdasKg) . ' kg', 'red') ?>
    </div>
    <div style="margin-bottom:12px">
      <div style="font-size:22px;font-weight:700;color:var(--crm-red)"><?= crm_brl((float)$perdasValor) ?></div>
      <div style="font-size:11.5px;color:var(--crm-ink2)">estimativa do dia · 2,9% do volume do dia</div>
    </div>
    <div style="display:grid;gap:9px">
      <?php foreach ($M['perdas'] as $p): ?>
        <div style="display:grid;grid-template-columns:1fr 72px auto;gap:10px;align-items:center;font-size:12px">
          <span><?= h($p['motivo']) ?></span>
          <?= crm_bar($p['kg'] / $perdasMaxKg * 100, $p['kg'] >= $perdasMaxKg ? 'red' : 'amber') ?>
          <span style="font:11px var(--num,'IBM Plex Mono');text-align:right;color:var(--crm-ink2)"><?= crm_num((float)$p['kg']) ?> kg · <?= crm_brl((float)$p['valor']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:12px;text-align:right">
      <a class="vbtn vbtn-sm" href="<?= crm_url('corretor', 'producao') ?>">Ver produção &amp; estoque ›</a>
    </div>
  </div>
</div>

<?php crm_shell_end();
