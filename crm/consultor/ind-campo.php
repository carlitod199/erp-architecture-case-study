<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Indicadores · Campo (protótipo)
   Rota: /crm/consultor/ind-campo · atividade de campo, aderência
   ao plano e qualidade técnica das recomendações. Dados fictícios.
   Tabs: Comercial · Campo (esta) · Carteira.
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

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'ind_campo',
    'titulo' => 'Indicadores · Campo',
]);
?>

<?= co_tabs_ind('ind-campo') ?>

<div class="crm-g4">
  <?= crm_kpi('Visitas realizadas', '23', '<strong>+15%</strong> vs. julho', 'green') ?>
  <?= crm_kpi('Aderência ao plano', '88%', '23 de 26 planejadas', 'green') ?>
  <?= crm_kpi('Km rodados', '1.480', '64 km por visita', 'teal') ?>
  <?= crm_kpi('Visitas com próxima ação', '91%', '<strong style="color:var(--crm-red)">2 sem próximo passo</strong>', 'amber') ?>
</div>

<div class="crm-g2">

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Visitas por tipo · mês</span>
    </div>
    <?= co_hbars([
        ['Técnica',             11, '11', 'teal'],
        ['Captação', 5,  '5',  'teal'],
        ['Pós-venda',           4,  '4',  'green'],
        ['Prospecção',          2,  '2',  'blue'],
        ['Follow-up',           1,  '1',  'amber'],
    ]) ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Visitas por semana · tendência</span>
    </div>
    <?= co_hbars([
        ['Sem. 31', 5, '5', 'teal'],
        ['Sem. 32', 6, '6', 'teal'],
        ['Sem. 33', 4, '4', 'amber'],
        ['Sem. 34', 8, '8', 'green'],
    ]) ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Recomendações &amp; conformidade · qualidade técnica</span>
    </div>
    <?= crm_kv('Recomendações emitidas', '48') ?>
    <?= crm_kv('Com ART vinculada', '31 ' . crm_pill('65%', 'green')) ?>
    <?= crm_kv('Bloqueadas por carência', '4') ?>
    <?= crm_kv('Bloqueadas por LMR do destino', '2') ?>
    <?= crm_kv('Registradas no caderno', '44 ' . crm_pill('92%', 'green')) ?>
    <?= crm_kv('Taxa de adoção', '67% ' . crm_pill('+9 p.p.', 'green')) ?>
    <?= crm_callout('Taxa de adoção = recomendações que viraram aplicação registrada no caderno de campo.', 'teal') ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Da recomendação ao resultado · a ponte que ninguém faz</span>
    </div>
    <div class="crm-tl">
      <div class="crm-tl__item"><span class="crm-tl__dot"></span>
        <div class="crm-tl__dt">Recomendação</div>
        <div class="crm-tl__t">48 recomendações técnicas</div>
        <div class="crm-tl__sub">Geradas em visita, com problema diagnosticado e talhão identificado.</div>
      </div>
      <div class="crm-tl__item"><span class="crm-tl__dot d-blue"></span>
        <div class="crm-tl__dt">Adoção</div>
        <div class="crm-tl__t">32 viraram aplicação</div>
        <div class="crm-tl__sub">67% de adoção — registrado no caderno de campo.</div>
      </div>
      <div class="crm-tl__item"><span class="crm-tl__dot d-amber"></span>
        <div class="crm-tl__dt">Venda</div>
        <div class="crm-tl__t">R$ 388 mil faturados</div>
        <div class="crm-tl__sub">81% do faturamento veio de itens recomendados em visita.</div>
      </div>
      <div class="crm-tl__item"><span class="crm-tl__dot d-green"></span>
        <div class="crm-tl__dt">Resultado</div>
        <div class="crm-tl__t">Produtividade dos talhões acompanhados <?= crm_demo('caderno de campo') ?></div>
        <div class="crm-tl__sub">+8% de produtividade média nos talhões com programa completo vs. parcial.</div>
      </div>
    </div>
  </div>

</div>

<?php crm_shell_end();
