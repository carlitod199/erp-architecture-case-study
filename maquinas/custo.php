<?php
/* ============================================================
   VERO — Máquinas / Custo Operacional  (tela real, leitura)
   Substitui o mock. Rota: /maquinas/custo.php
   Guard: maquinas.custo_operacional
   Custo por máquina no ano: abastecimentos + manutenções
   executadas, com custo/hora usando o horímetro atual.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fAno = (int)($_GET['ano'] ?? date('Y'));
if ($fAno < 2000 || $fAno > 2100) $fAno = (int)date('Y');

$anos = array_map('intval', array_column(vero_rows(
    "SELECT DISTINCT YEAR(data_abastecimento) AS a FROM maquina_abastecimentos
      WHERE tenant_id = :t ORDER BY a DESC", [':t' => $t]), 'a'));
if (!in_array($fAno, $anos, true)) $anos[] = $fAno;

$rows = vero_rows(
    "SELECT m.*,
            (SELECT COALESCE(SUM(ab.valor_total),0) FROM maquina_abastecimentos ab
              WHERE ab.tenant_id = m.tenant_id AND ab.maquina_id = m.id
                AND YEAR(ab.data_abastecimento) = :a1) AS combustivel,
            (SELECT COALESCE(SUM(ab2.litros),0) FROM maquina_abastecimentos ab2
              WHERE ab2.tenant_id = m.tenant_id AND ab2.maquina_id = m.id
                AND YEAR(ab2.data_abastecimento) = :a2) AS litros,
            (SELECT COALESCE(SUM(mn.custo),0) FROM maquina_manutencoes mn
              WHERE mn.tenant_id = m.tenant_id AND mn.maquina_id = m.id
                AND mn.status = 'executada' AND YEAR(mn.data_manutencao) = :a3) AS manutencao
       FROM maquinas m
      WHERE m.tenant_id = :t AND m.ativo = 1
      ORDER BY m.codigo", [':t' => $t, ':a1' => $fAno, ':a2' => $fAno, ':a3' => $fAno]);

$totComb = array_sum(array_map(static fn($r) => (float)$r['combustivel'], $rows));
$totManut = array_sum(array_map(static fn($r) => (float)$r['manutencao'], $rows));
$maxTot = 0.0;
foreach ($rows as $r) $maxTot = max($maxTot, (float)$r['combustivel'] + (float)$r['manutencao']);

/* P-75 (CSO): valores em R$ só com o proxy financeiro; sem ele, mascara (•••). */
$veCusto = function_exists('vero_can') ? vero_can('financeiro.dre_agro.ver') : true;

$GUARD      = ['macro' => 'maquinas', 'micro' => 'custo_operacional'];
$PAGE_VIEW  = 'maquinas_custo_operacional';
$PAGE_TITLE = 'Custo Operacional';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Custo Operacional', 'Combustível + manutenção por máquina no ano, com custo médio por litro', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px">
        <select name="ano" onchange="this.form.submit()">
          <?php foreach ($anos as $a): ?>
            <option value="<?= $a ?>"<?= $a === $fAno ? ' selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub">combustível <strong class="vnum">R$ <?= $veCusto ? numFmt($totComb, 2) : '•••' ?></strong> ·
        manutenção <strong class="vnum">R$ <?= $veCusto ? numFmt($totManut, 2) : '•••' ?></strong> ·
        total <strong class="vnum">R$ <?= $veCusto ? numFmt($totComb + $totManut, 2) : '•••' ?></strong></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma máquina ativa.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Máquina</th>
        <th style="text-align:right">Litros</th>
        <th style="text-align:right">Combustível (R$)</th>
        <th style="text-align:right">R$/L médio</th>
        <th style="text-align:right">Manutenção (R$)</th>
        <th style="text-align:right">Total (R$)</th>
        <th style="text-align:right">Horímetro (h)</th>
        <th style="width:18%">Comparativo</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $tot = (float)$r['combustivel'] + (float)$r['manutencao'];
          $pct = $maxTot > 0 ? $tot / $maxTot * 100 : 0; ?>
        <tr>
          <td><strong class="vnum"><?= h($r['codigo']) ?></strong> <?= h($r['nome']) ?>
            <span class="vhint"><?= h((string)($r['tipo'] ?? '')) ?></span></td>
          <td class="vnum" style="text-align:right"><?= (float)$r['litros'] > 0 ? numFmt((float)$r['litros'], 1) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= !$veCusto ? '•••' : ((float)$r['combustivel'] > 0 ? numFmt((float)$r['combustivel'], 2) : '—') ?></td>
          <td class="vnum" style="text-align:right"><?= !$veCusto ? '•••' : ((float)$r['litros'] > 0 ? numFmt((float)$r['combustivel'] / (float)$r['litros'], 2) : '—') ?></td>
          <td class="vnum" style="text-align:right"><?= !$veCusto ? '•••' : ((float)$r['manutencao'] > 0 ? numFmt((float)$r['manutencao'], 2) : '—') ?></td>
          <td class="vnum" style="text-align:right"><strong><?= $veCusto ? numFmt($tot, 2) : '•••' ?></strong></td>
          <td class="vnum" style="text-align:right"><?= $r['horimetro_atual'] !== null ? numFmt((float)$r['horimetro_atual'], 1) : '—' ?></td>
          <td><?php if ($veCusto): ?><div style="height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
            <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
          </div><?php else: ?><span class="vhint">restrito</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">Abastecimentos e manutenções executadas no ano. O custeio por válvula (origem máquina) fica em Custos → Custo por Válvula.</div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
