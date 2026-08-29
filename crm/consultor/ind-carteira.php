<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Indicadores · Carteira (protótipo)
   Rota: /crm/consultor/ind-carteira · cobertura, concentração,
   retenção e mix da carteira de produtores. Dados fictícios.
   Tabs: Comercial · Campo · Carteira (esta).
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* TODO mover para _mock.php — clientes em risco (retenção) */
$RISCO = [
    ['id' => 'P08', 'nome' => 'José Bezerra',    'semContato' => '61 d', 'var' => '−71%', 'sinal' => 'Sem oportunidade aberta',  'cor' => 'red'],
    ['id' => 'P04', 'nome' => 'Antônio Ribeiro', 'semContato' => '47 d', 'var' => '−55%', 'sinal' => 'Concorrente na fazenda',   'cor' => 'red'],
    ['id' => 'P02', 'nome' => 'Carlos Mendes',   'semContato' => '32 d', 'var' => '−8%',  'sinal' => 'Crédito 90% utilizado',    'cor' => 'amber'],
];

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
    'micro'  => 'ind_carteira',
    'titulo' => 'Indicadores · Carteira',
]);
?>

<?= co_tabs_ind('ind-carteira') ?>

<div class="crm-g4">
  <?= crm_kpi('Produtores na carteira', '8', '374 ha · 6 municípios', 'teal') ?>
  <?= crm_kpi('Positivação', '75%', '6 de 8 compraram no ciclo', 'green') ?>
  <?= crm_kpi('Cobertura da frequência', '75%', '<strong style="color:var(--crm-red)">2 fora do alvo</strong>', 'amber') ?>
  <?= crm_kpi('Recompra entre ciclos', '71%', '<strong>−8 p.p.</strong> vs. ciclo anterior', 'red') ?>
</div>

<div class="crm-g2">

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Cobertura por classe · frequência-alvo</span>
    </div>
    <?= co_hbars([
        ['Classe A · 15 d', 100, '3/3', 'green'],
        ['Classe B · 30 d', 50,  '2/4', 'red'],
        ['Classe C · 45 d', 100, '1/1', 'green'],
    ]) ?>
    <?= crm_callout('Dois produtores classe B estão fora da frequência-alvo: '
        . '<strong>Antônio Ribeiro</strong> (47 d) e <strong>José Bezerra</strong> (61 d).', 'amber') ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Carteira por potencial · concentração</span>
    </div>
    <?= co_hbars([
        ['João Almeida',       1400, 'R$ 1,4 mi',  'teal'],
        ['Fernanda Sá',        1100, 'R$ 1,1 mi',  'blue'],
        ['Carlos Mendes',      980,  'R$ 980 mil', 'teal'],
        ['Maria Oliveira',     610,  'R$ 610 mil', 'amber'],
        ['Roberto Nakamura',   520,  'R$ 520 mil', 'amber'],
        ['Antônio Ribeiro',    430,  'R$ 430 mil', 'red'],
        ['José Bezerra',       380,  'R$ 380 mil', 'red'],
        ['Helena Vasconcelos', 190,  'R$ 190 mil', 'teal'],
    ]) ?>
    <?= crm_callout('Os 3 maiores concentram <strong>61%</strong> do potencial da carteira.', 'teal') ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Clientes em risco · retenção</span>
      <?= crm_pill(count($RISCO) . ' produtores', 'red') ?>
    </div>
    <div class="crm-tblwrap">
      <table class="crm-tbl">
        <thead>
          <tr>
            <th>Produtor</th>
            <th class="num">Sem contato</th>
            <th class="num">Variação de compra</th>
            <th>Sinal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($RISCO as $r): ?>
            <tr class="tap" data-href="<?= h(crm_url('consultor', 'produtor') . '?id=' . rawurlencode($r['id'])) ?>">
              <td><strong><?= h($r['nome']) ?></strong></td>
              <td class="num"><?= h($r['semContato']) ?></td>
              <td class="num" style="color:var(--crm-red)"><?= h($r['var']) ?></td>
              <td><?= crm_pill($r['sinal'], $r['cor']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Mix por cultura e destino · perfil da carteira</span>
    </div>
    <?= co_donut([
        ['Uva · exportação',   4, 'teal'],
        ['Uva · interno',      2, 'blue'],
        ['Manga · exportação', 3, 'amber'],
        ['Manga · interno',    2, 'grey'],
    ]) ?>
    <?= crm_callout('7 das 9 propriedades exportam — por isso a trava de LMR e de carência '
        . 'é regra de negócio, não detalhe.', 'teal') ?>
  </div>

</div>

<?php crm_shell_end();
