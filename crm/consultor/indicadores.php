<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Indicadores · Comercial (protótipo)
   Rota: /crm/consultor/indicadores · vendas, pipeline, origem
   das oportunidades e resultado da carteira. Dados fictícios.
   Tabs: Comercial (esta) · Campo · Carteira.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* Tabs de navegação entre as três telas de indicadores */
function co_tabs_ind(string $on): string
{
    $tabs = [['indicadores', 'Comercial'], ['ind-campo', 'Campo'], ['ind-carteira', 'Carteira']];
    $h = '<div class="crm-tabs">';
    foreach ($tabs as [$rota, $lbl]) {
        $h .= '<a class="crm-tab' . ($rota === $on ? ' on' : '') . '" style="text-decoration:none" href="'
            . h(crm_url('consultor', $rota)) . '">' . h($lbl) . '</a>';
    }
    return $h . '</div>';
}

/* Barras horizontais rótulo → barra → valor (crm-hbars) */
function co_hbars(array $rows): string
{
    $max = 0.0;
    foreach ($rows as $r) $max = max($max, (float)$r[1]);
    $h = '<div class="crm-hbars">';
    foreach ($rows as $r) {
        $pct = $max > 0 ? (float)$r[1] / $max * 100 : 0;
        $h .= '<div class="crm-hbar"><span>' . h($r[0]) . '</span>'
            . crm_bar($pct, $r[3] ?? 'teal')
            . '<span class="num">' . h($r[2]) . '</span></div>';
    }
    return $h . '</div>';
}

/* Donut em SVG inline (cores via var(--crm-*)) + legenda */
function co_donut(array $segs): string
{
    $tot = 0.0;
    foreach ($segs as $s) $tot += (float)$s[1];
    $r = 54; $c = 2 * M_PI * $r; $off = 0.0;
    $arcs = '';
    foreach ($segs as $s) {
        $len = $tot > 0 ? (float)$s[1] / $tot * $c : 0;
        $arcs .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" stroke-width="20"'
               . ' style="stroke:var(--crm-' . h($s[2]) . ')"'
               . ' stroke-dasharray="' . number_format($len, 2, '.', '') . ' ' . number_format($c - $len, 2, '.', '') . '"'
               . ' stroke-dashoffset="' . number_format(-$off, 2, '.', '') . '"'
               . ' transform="rotate(-90 70 70)"/>';
        $off += $len;
    }
    $leg = '';
    foreach ($segs as $s) {
        $leg .= '<div style="display:flex;align-items:center;gap:8px;font-size:12px;padding:3px 0">'
              . '<span style="width:10px;height:10px;border-radius:3px;flex:0 0 auto;background:var(--crm-' . h($s[2]) . ')"></span>'
              . '<span style="color:var(--crm-ink2)">' . h($s[0]) . '</span>'
              . '<strong style="margin-left:auto">' . h((string)$s[1]) . '</strong></div>';
    }
    return '<div style="display:flex;gap:22px;align-items:center;flex-wrap:wrap">'
         . '<svg width="140" height="140" viewBox="0 0 140 140" style="flex:0 0 auto">' . $arcs . '</svg>'
         . '<div style="flex:1;min-width:180px">' . $leg . '</div></div>';
}

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'indicadores',
    'titulo' => 'Indicadores · Comercial',
]);
?>

<?= co_tabs_ind('indicadores') ?>

<div class="crm-g4">
  <?= crm_kpi('Vendas no ciclo', 'R$ 388 mil', '<strong>62%</strong> da meta de R$ 620 mil', 'green') ?>
  <?= crm_kpi('Pipeline ponderado', 'R$ 353 mil', 'previsão de fechamento', 'blue') ?>
  <?= crm_kpi('Conversão', '58%', '<strong>+6 p.p.</strong> vs. ciclo anterior', 'green') ?>
  <?= crm_kpi('Ciclo médio de venda', '50 d', '<strong>−11 d</strong> vs. ciclo anterior', 'green') ?>
</div>

<div class="crm-g2">

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Realizado vs. meta · ciclo 2026.2</span>
    </div>
    <?= co_hbars([
        ['Mai',           72000,  'R$ 72 mil',  'teal'],
        ['Jun',           96000,  'R$ 96 mil',  'teal'],
        ['Jul',           114000, 'R$ 114 mil', 'teal'],
        ['Ago (parcial)', 106000, 'R$ 106 mil', 'amber'],
        ['Meta mensal',   124000, 'R$ 124 mil', 'grey'],
    ]) ?>
    <?= crm_callout('Projeção de fechamento do mês pelo ritmo atual + pipeline ponderado: '
        . '<strong>R$ 131 mil</strong> — acima da meta.', 'green') ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Pipeline por etapa · funil</span>
      <?= crm_pill('R$ 1,25 mi em aberto', 'teal') ?>
    </div>
    <?= co_hbars([
        ['Prospecção',            132000, 'R$ 132 mil', 'grey'],
        ['Diagnóstico técnico',   58000,  'R$ 58 mil',  'blue'],
        ['Recomendação',          386400, 'R$ 386 mil', 'teal'],
        ['Proposta',              172000, 'R$ 172 mil', 'teal'],
        ['Conformidade & crédito',186000, 'R$ 186 mil', 'amber'],
        ['Negociação',            315000, 'R$ 315 mil', 'green'],
    ]) ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Origem das oportunidades · gatilhos</span>
    </div>
    <?= co_donut([
        ['Fenológico',        3, 'teal'],
        ['Problema técnico',  2, 'blue'],
        ['Recompra de ciclo', 2, 'amber'],
        ['Conformidade',      1, 'violet'],
        ['Reativação',        1, 'grey'],
    ]) ?>
    <?= crm_callout('Metade do pipeline nasceu de um gatilho automático, não de prospecção manual.', 'teal') ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Ticket médio por cultura · comercial</span>
    </div>
    <?= co_hbars([
        ['Uva · exportação',   186000, 'R$ 186 mil', 'teal'],
        ['Uva · interno',      74000,  'R$ 74 mil',  'blue'],
        ['Manga · exportação', 98000,  'R$ 98 mil',  'amber'],
        ['Manga · interno',    54000,  'R$ 54 mil',  'grey'],
    ]) ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Resultado da carteira · DRE consolidado</span>
      <?= crm_demo('ERP') ?>
    </div>
    <div style="display:flex;gap:26px;flex-wrap:wrap;margin-bottom:14px">
      <div>
        <div class="crm-card__title">Receita líquida</div>
        <div style="font:600 20px var(--num,'IBM Plex Mono')">R$ 1,90 mi</div>
      </div>
      <div>
        <div class="crm-card__title">Margem contrib.</div>
        <div style="font:600 20px var(--num,'IBM Plex Mono')">R$ 198 mil</div>
        <div class="crm-sub">10,4%</div>
      </div>
      <div>
        <div class="crm-card__title">Resultado</div>
        <div style="font:600 20px var(--num,'IBM Plex Mono');color:var(--crm-green)">R$ 53 mil</div>
        <div class="crm-sub">2,8%</div>
      </div>
    </div>
    <?= co_hbars([
        ['Margem bruta',        21.5, '21,5%', 'teal'],
        ['Marg. contribuição',  10.4, '10,4%', 'teal'],
        ['Resultado',           2.8,  '2,8%',  'amber'],
    ]) ?>
    <?= crm_callout('Três clientes fecham no vermelho e consomem <strong>R$ 36,9 mil</strong> do resultado. '
        . '<a href="' . h(crm_url('consultor', 'dre')) . '" style="color:var(--crm-teal);font-weight:600">Ver DRE por cliente ›</a>', 'amber') ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Da análise ao pedido · nutrição como origem de venda</span>
    </div>
    <div class="crm-tl">
      <div class="crm-tl__item"><span class="crm-tl__dot"></span>
        <div class="crm-tl__dt">Laudos</div>
        <div class="crm-tl__t">8 análises no ciclo</div>
        <div class="crm-tl__sub">5 foliares e 3 de solo, entre import de PDF e digitação.</div>
      </div>
      <div class="crm-tl__item"><span class="crm-tl__dot d-amber"></span>
        <div class="crm-tl__dt">Desvios</div>
        <div class="crm-tl__t">12 parâmetros fora da faixa</div>
        <div class="crm-tl__sub">Em 5 dos 7 laudos já interpretados.</div>
      </div>
      <div class="crm-tl__item"><span class="crm-tl__dot d-blue"></span>
        <div class="crm-tl__dt">Recomendação</div>
        <div class="crm-tl__t">6 viraram recomendação técnica</div>
        <div class="crm-tl__sub">Com talhão, dose e janela de aplicação definidos.</div>
      </div>
      <div class="crm-tl__item"><span class="crm-tl__dot d-green"></span>
        <div class="crm-tl__dt">Venda</div>
        <div class="crm-tl__t">4 abriram oportunidade · R$ 460 mil</div>
        <div class="crm-tl__sub">O-118, O-119, O-126 e O-127 nasceram de um laudo.</div>
      </div>
    </div>
  </div>

</div>

<?php crm_shell_end();
