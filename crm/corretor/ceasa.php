<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Corretor / Preços CEASA (protótipo demo)
   Rota: /crm/corretor/ceasa · dados: crm/_mock.php
   Cotações por variedade × classificação × calibre; o número de
   planejamento é o valor "comum" (moda), não o máximo.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

/* Praças acompanhadas pelo corretor — TODO mover para _mock.php */
$pracas = ['CEAGESP · São Paulo', 'CEASA · Recife', 'CEASA · Belo Horizonte'];

$selPraca = '<select style="padding:7px 10px;border-radius:9px;border:1px solid var(--crm-line2);font-size:12px">';
foreach ($pracas as $p) $selPraca .= '<option>' . h($p) . '</option>';
$selPraca .= '</select>';

crm_shell_start([
    'modulo' => 'corretor',
    'micro'  => 'ceasa',
    'titulo' => 'Preços CEASA',
    'sub'    => 'Cotações por variedade × classificação × calibre · valor "comum" (moda)',
    'acoes'  => $selPraca,
]);
?>

<!-- Filtro por produto (estado visual — protótipo) -->
<div class="crm-tabs">
  <span class="crm-tab on">Todos</span>
  <span class="crm-tab">Manga</span>
  <span class="crm-tab">Uva</span>
</div>

<div class="crm-card">
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Produto</th><th>Variedade</th><th>Classificação</th><th>Calibre</th>
          <th class="num">Mín</th><th class="num">Comum</th><th class="num">Máx</th><th class="num">Tendência</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($M['ceasa'] as $r): $premium = in_array($r['cl'], ['Exportação', 'Extra'], true); ?>
          <tr>
            <td><strong><?= h($r['p']) ?></strong></td>
            <td><?= h($r['v']) ?></td>
            <td><?= crm_pill($r['cl'], $premium ? 'teal' : 'grey') ?></td>
            <td style="font:12px var(--num,'IBM Plex Mono')"><?= h($r['cal']) ?></td>
            <td class="num"><?= crm_brl((float)$r['min'], 2) ?></td>
            <td class="num" style="font-weight:700;color:var(--crm-teal)"><?= crm_brl((float)$r['com'], 2) ?></td>
            <td class="num"><?= crm_brl((float)$r['max'], 2) ?></td>
            <td class="num"><?= crm_trend((float)$r['t']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php crm_shell_end();
