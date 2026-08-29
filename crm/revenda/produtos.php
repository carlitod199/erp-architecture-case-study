<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Produtos & Preços (protótipo demo)
   Rota: /crm/revenda/produtos · dados: crm/_mock.php
   Catálogo espelhado do ERP VERO (o CRM não duplica cadastro).
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

/* Cor da pílula por categoria do catálogo */
$corCat = [
    'Biológico'      => 'green',
    'Defensivo'      => 'red',
    'Fertilizante'   => 'teal',
    'Adjuvante'      => 'grey',
    'Bioestimulante' => 'grey',
];

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'produtos',
    'titulo' => 'Produtos & Preços',
    'sub'    => 'Catálogo com base fitossanitária · preço e estoque em tempo real',
    'papel'  => 'vendedor',
]);
?>

<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title">Catálogo da revenda</span>
    <?= crm_pill(count($M['produtos']) . ' produtos', 'teal') ?>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Produto</th>
          <th>Categoria</th>
          <th>Alvo / uso</th>
          <th>Dose</th>
          <th class="num">Preço</th>
          <th class="num">Estoque</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($M['produtos'] as $p): ?>
        <tr>
          <td><strong><?= h($p['nome']) ?></strong></td>
          <td><?= crm_pill($p['cat'], $corCat[$p['cat']] ?? 'grey') ?></td>
          <td><?= h($p['alvo']) ?></td>
          <td><span class="sub" style="font-family:var(--num,'IBM Plex Mono')"><?= h($p['dose']) ?></span></td>
          <td class="num"><?= crm_brl((float)$p['preco'], 2) ?><span class="sub">/<?= h($p['un']) ?></span></td>
          <td class="num">
            <?php $est = crm_num((float)$p['estoque']) . ' ' . $p['un']; ?>
            <?= $p['estoque'] < 200 ? crm_pill($est, 'amber') : h($est) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php crm_shell_end();
