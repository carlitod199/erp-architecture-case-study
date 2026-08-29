<?php
/* ============================================================
   VERO — Máquinas / Peças e Serviços  (tela real, leitura)
   Substitui o mock. Rota: /maquinas/pecas_servicos.php
   Guard: maquinas.pecas_servicos
   Gasto de manutenção por máquina (preventiva × corretiva) —
   o detalhe de cada intervenção fica em Manutenções.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fAno = (int)($_GET['ano'] ?? date('Y'));
if ($fAno < 2000 || $fAno > 2100) $fAno = (int)date('Y');

$anos = array_map('intval', array_column(vero_rows(
    "SELECT DISTINCT YEAR(data_manutencao) AS a FROM maquina_manutencoes
      WHERE tenant_id = :t AND data_manutencao IS NOT NULL ORDER BY a DESC", [':t' => $t]), 'a'));
if (!in_array($fAno, $anos, true)) $anos[] = $fAno;

$rows = vero_rows(
    "SELECT m.id, m.codigo, m.nome,
            COALESCE(SUM(CASE WHEN mn.tipo='preventiva' AND mn.status='executada' THEN mn.custo END),0) AS preventiva,
            COALESCE(SUM(CASE WHEN mn.tipo='corretiva' AND mn.status='executada' THEN mn.custo END),0) AS corretiva,
            COUNT(CASE WHEN mn.status='executada' THEN 1 END) AS intervencoes
       FROM maquinas m
       LEFT JOIN maquina_manutencoes mn ON mn.maquina_id = m.id AND mn.tenant_id = m.tenant_id
            AND YEAR(mn.data_manutencao) = :a
      WHERE m.tenant_id = :t AND m.ativo = 1
      GROUP BY m.id, m.codigo, m.nome
      ORDER BY (COALESCE(SUM(CASE WHEN mn.tipo='preventiva' AND mn.status='executada' THEN mn.custo END),0)
              + COALESCE(SUM(CASE WHEN mn.tipo='corretiva' AND mn.status='executada' THEN mn.custo END),0)) DESC",
    [':t' => $t, ':a' => $fAno]);

$totPrev = array_sum(array_map(static fn($r) => (float)$r['preventiva'], $rows));
$totCorr = array_sum(array_map(static fn($r) => (float)$r['corretiva'], $rows));
$maxTot = 0.0;
foreach ($rows as $r) $maxTot = max($maxTot, (float)$r['preventiva'] + (float)$r['corretiva']);

/* A2-F2-4: itens REAIS das OS executadas (peças do estoque + serviços) */
$itensReais = vero_rows(
    "SELECT mi.tipo, mi.descricao, mi.quantidade, mi.valor_total,
            m.codigo AS maq_codigo, m.nome AS maq_nome, mn.data_manutencao,
            p.codigo AS prod_codigo, p.unidade
       FROM maquina_manutencao_itens mi
       JOIN maquina_manutencoes mn ON mn.id = mi.manutencao_id AND mn.status = 'executada'
       JOIN maquinas m ON m.id = mn.maquina_id
       LEFT JOIN estoque_produtos p ON p.id = mi.produto_id
      WHERE mi.tenant_id = :t AND YEAR(mn.data_manutencao) = :a
      ORDER BY mn.data_manutencao DESC, mi.id DESC LIMIT 60", [':t' => $t, ':a' => $fAno]);

$GUARD      = ['macro' => 'maquinas', 'micro' => 'pecas_servicos'];
$PAGE_VIEW  = 'maquinas_pecas_servicos';
$PAGE_TITLE = 'Peças e Serviços';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
/* P-75 (CSO): valores em R$ só com o proxy financeiro; sem ele, mascara (•••). */
$veCusto = function_exists('vero_can') ? vero_can('financeiro.dre_agro.ver') : true;
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Peças e Serviços', 'Gasto de manutenção executada por máquina — preventiva × corretiva', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px">
        <select name="ano" onchange="this.form.submit()">
          <?php foreach ($anos as $a): ?>
            <option value="<?= $a ?>"<?= $a === $fAno ? ' selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub">preventiva <strong class="vnum">R$ <?= $veCusto ? numFmt($totPrev, 2) : '•••' ?></strong> ·
        corretiva <strong class="vnum">R$ <?= $veCusto ? numFmt($totCorr, 2) : '•••' ?></strong> ·
        <a href="<?= $base ?>/maquinas/manutencao.php">manutenções</a></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma máquina ativa.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Máquina</th>
        <th style="text-align:right">Intervenções</th>
        <th style="text-align:right">Preventiva (R$)</th>
        <th style="text-align:right">Corretiva (R$)</th>
        <th style="text-align:right">Total (R$)</th>
        <th style="width:22%">Comparativo</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $tot = (float)$r['preventiva'] + (float)$r['corretiva'];
          $pct = $maxTot > 0 ? $tot / $maxTot * 100 : 0; ?>
        <tr>
          <td><strong class="vnum"><?= h($r['codigo']) ?></strong> <?= h($r['nome']) ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['intervencoes'] ?></td>
          <td class="vnum" style="text-align:right"><?= !$veCusto ? '•••' : ((float)$r['preventiva'] > 0 ? numFmt((float)$r['preventiva'], 2) : '—') ?></td>
          <td class="vnum" style="text-align:right;<?= ($veCusto && (float)$r['corretiva'] > 0) ? 'color:#b3261e' : '' ?>">
            <?= !$veCusto ? '•••' : ((float)$r['corretiva'] > 0 ? numFmt((float)$r['corretiva'], 2) : '—') ?></td>
          <td class="vnum" style="text-align:right"><strong><?= $veCusto ? numFmt($tot, 2) : '•••' ?></strong></td>
          <td><?php if ($veCusto): ?><div style="height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
            <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
          </div><?php else: ?><span class="vhint">restrito</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">Considera manutenções executadas no ano selecionado. Corretiva alta em relação à preventiva sinaliza revisão do plano de manutenção.</div>
    <?php endif; ?>
  </div>

  <div class="vcard" style="margin-top:14px">
    <div class="vtoolbar"><strong>Peças e serviços das OS executadas (<?= $fAno ?>)</strong>
      <span class="vsub"><?= count($itensReais) ?> item(ns)</span></div>
    <?php if (!$itensReais): ?>
      <div class="vempty">Nenhum item de OS executada no ano — peças passam a aparecer aqui quando as OS usarem itens (Manutenções).</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Máquina</th><th>Tipo</th><th>Item</th>
        <th style="text-align:right">Qtd</th><th style="text-align:right">Valor (R$)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($itensReais as $it): ?>
        <tr>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$it['data_manutencao'])) ?></td>
          <td><strong><?= h($it['maq_codigo'] . ' — ' . $it['maq_nome']) ?></strong></td>
          <td><?= $it['tipo'] === 'peca'
                ? '<span class="vbadge vb-info">Peça</span>'
                : '<span class="vbadge vb-warn">Serviço</span>' ?></td>
          <td><?= $it['prod_codigo'] ? '<strong class="vnum">' . h($it['prod_codigo']) . '</strong> ' : '' ?><?= h($it['descricao'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$it['quantidade'], 2) ?>
            <span class="vhint"><?= h($it['unidade'] ?? '') ?></span></td>
          <td class="vnum" style="text-align:right"><strong><?= $veCusto ? numFmt((float)$it['valor_total'], 2) : '•••' ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
