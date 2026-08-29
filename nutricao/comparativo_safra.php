<?php
/* ============================================================
   VERO — Nutrição / Comparativo por Safra  (tela real, leitura)
   Substitui o mock. Rota: /nutricao/comparativo_safra.php
   Guard: nutricao.comparativo_safra
   Média dos resultados de análise por nutriente, com as safras
   em colunas — evolução nutricional entre ciclos.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fTipo = in_array((string)($_GET['tipo'] ?? ''), ['solo', 'foliar'], true) ? (string)$_GET['tipo'] : 'solo';
$tabA = $fTipo === 'solo' ? 'analise_solo' : 'analise_foliar';
$tabR = $tabA . '_resultados';

$linhas = vero_rows(
    "SELECT sa.id AS safra_id, sa.identificacao AS safra,
            n.id AS nutriente_id, n.nome AS nutriente, n.simbolo, n.ordem, r.unidade,
            AVG(r.valor) AS media, COUNT(*) AS amostras
       FROM {$tabR} r
       JOIN {$tabA} a ON a.id = r.analise_id
       LEFT JOIN agro_safras sa ON sa.id = a.safra_id
       LEFT JOIN analise_nutrientes n ON n.id = r.nutriente_id
      WHERE r.tenant_id = :t
      GROUP BY sa.id, sa.identificacao, n.id, n.nome, n.simbolo, n.ordem, r.unidade", [':t' => $t]);

$safras = [];
$nutrientes = [];
$matriz = [];
foreach ($linhas as $l) {
    $sid = $l['safra_id'] !== null ? (int)$l['safra_id'] : 0;
    $safras[$sid] = $l['safra'] ?? 'Sem safra';
    $nid = $l['nutriente_id'] !== null ? (int)$l['nutriente_id'] : 0;
    $nutrientes[$nid] = [
        'nome' => $l['nutriente'] ?? 'Nutriente', 'simbolo' => $l['simbolo'],
        'ordem' => (int)($l['ordem'] ?? 99), 'unidade' => $l['unidade'],
    ];
    $matriz[$nid][$sid] = ['media' => (float)$l['media'], 'amostras' => (int)$l['amostras']];
}
krsort($safras);
uasort($nutrientes, static fn($a, $b) => $a['ordem'] <=> $b['ordem']);

$GUARD      = ['macro' => 'nutricao', 'micro' => 'comparativo_safra'];
$PAGE_VIEW  = 'nutricao_comparativo_safra';
$PAGE_TITLE = 'Comparativo por Safra';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Comparativo por Safra', 'Média dos resultados de análise por nutriente, safra a safra', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <select name="tipo" onchange="this.form.submit()">
          <option value="solo"<?= $fTipo === 'solo' ? ' selected' : '' ?>>Análises de solo</option>
          <option value="foliar"<?= $fTipo === 'foliar' ? ' selected' : '' ?>>Análises foliares</option>
        </select>
      </form>
      <span class="vsub"><?= count($nutrientes) ?> nutriente(s) × <?= count($safras) ?> safra(s)</span>
    </div>

    <?php if (!$matriz): ?>
      <div class="vempty">Nenhum resultado de análise <?= $fTipo === 'solo' ? 'de solo' : 'foliar' ?> ainda.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Nutriente</th><th>Unidade</th>
        <?php foreach ($safras as $sid => $sn): ?>
          <th class="num"><?= h((string)$sn) ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($nutrientes as $nid => $n): ?>
        <tr>
          <td><strong class="vnum"><?= h($n['simbolo'] ?? '') ?></strong> <?= h((string)$n['nome']) ?></td>
          <td class="vhint"><?= h((string)($n['unidade'] ?? '—')) ?></td>
          <?php foreach (array_keys($safras) as $sid): ?>
            <td class="num">
              <?php if (isset($matriz[$nid][$sid])): ?>
                <strong><?= numFmt($matriz[$nid][$sid]['media'], 2) ?></strong>
                <span class="vhint">(<?= $matriz[$nid][$sid]['amostras'] ?>)</span>
              <?php else: ?>—<?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    </div>
    <div class="vhint" style="padding:10px 14px">Média simples dos resultados da safra (nº de amostras entre parênteses). A classificação individual segue as faixas do RT nas telas de análise.</div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
