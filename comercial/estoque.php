<?php
/* ============================================================
   VERO — Comercial / Estoque de Produção  (tela real, leitura)
   Substitui o mock. Rota: /comercial/estoque.php
   Guard: comercial.estoque_producao
   Balanço da produção: colhido − vendido = disponível por safra,
   mais as posições de armazenagem declaradas.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

/* Auditoria UX 19/07: além de colhido/vendido, a tela cruza o estoque FÍSICO
   (estoque_movimentacoes de origem colheita/venda, mesma régua do painel de
   Integridade — INTEG_TOL_KG=1) para acionar o gestor quando há inconsistência. */
$balanco = vero_rows(
    "SELECT sa.id, sa.identificacao AS safra,
            (SELECT COALESCE(SUM(cr.kg_total_realizado),0) FROM colheita_registros cr
              WHERE cr.tenant_id = sa.tenant_id AND cr.safra_id = sa.id) AS colhido,
            (SELECT COALESCE(SUM(v.kg_total),0) FROM comercial_vendas v
              WHERE v.tenant_id = sa.tenant_id AND v.safra_id = sa.id AND v.status <> 'cancelada') AS vendido,
            (SELECT COALESCE(SUM(em.quantidade),0) FROM estoque_movimentacoes em
              JOIN colheita_registros cr2 ON cr2.id = em.origem_id AND cr2.tenant_id = em.tenant_id
              WHERE em.tenant_id = sa.tenant_id AND em.origem_tipo = 'colheita' AND em.tipo = 'entrada'
                AND em.estornado_em IS NULL AND cr2.safra_id = sa.id) AS entrada_estoque,
            (SELECT COALESCE(SUM(v2.kg_total),0) FROM comercial_vendas v2
              WHERE v2.tenant_id = sa.tenant_id AND v2.safra_id = sa.id
                AND v2.status IN ('confirmada','faturada')) AS vendido_firme,
            (SELECT COALESCE(SUM(em.quantidade),0) FROM estoque_movimentacoes em
              JOIN comercial_vendas v3 ON v3.id = em.origem_id AND v3.tenant_id = em.tenant_id
              WHERE em.tenant_id = sa.tenant_id AND em.origem_tipo = 'comercial_venda' AND em.tipo = 'saida'
                AND em.estornado_em IS NULL AND v3.safra_id = sa.id) AS baixa_estoque
       FROM agro_safras sa
      WHERE sa.tenant_id = :t
     HAVING colhido > 0 OR vendido > 0
      ORDER BY sa.identificacao DESC", [':t' => $t]);

/* mesma tolerância do painel de Integridade (F-05/F-06) */
const ESTQ_TOL_KG = 1.0;

$posicoes = vero_rows(
    "SELECT a.*, c.nome AS cultura, s.identificacao AS safra
       FROM armazenagem_estoques a
       LEFT JOIN agro_culturas c ON c.id = a.cultura_id
       LEFT JOIN agro_safras s ON s.id = a.safra_id
      WHERE a.tenant_id = :t ORDER BY a.local", [':t' => $t]);

$GUARD      = ['macro' => 'comercial', 'micro' => 'estoque_producao'];
$PAGE_VIEW  = 'comercial_estoque_producao';
$PAGE_TITLE = 'Estoque de Produção';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Estoque de Produção', 'Colhido − vendido por safra e posições de armazenagem declaradas', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Balanço por safra</strong></div>
    <?php if (!$balanco): ?>
      <div class="vempty">Nenhuma colheita ou venda registrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Safra</th>
        <th style="text-align:right">Colhido (kg)</th>
        <th style="text-align:right">Vendido (kg)</th>
        <th style="text-align:right">Disponível (kg)</th>
        <th style="width:22%">% comercializado</th>
      </tr></thead>
      <tbody>
      <?php foreach ($balanco as $b):
          $disp = (float)$b['colhido'] - (float)$b['vendido'];
          $pct = (float)$b['colhido'] > 0 ? (float)$b['vendido'] / (float)$b['colhido'] * 100 : 0;
          /* inconsistência = disponível negativo OU colheita sem entrada no
             estoque OU venda firme sem baixa (gap ≥ 1 kg, régua da Integridade) */
          $gapColheita = (float)$b['colhido'] - (float)$b['entrada_estoque'];
          $gapVenda    = (float)$b['vendido_firme'] - (float)$b['baixa_estoque'];
          $inconsistente = $disp < 0 || abs($gapColheita) >= ESTQ_TOL_KG || abs($gapVenda) >= ESTQ_TOL_KG; ?>
        <tr>
          <td><strong><?= h($b['safra']) ?></strong>
            <?php if ($inconsistente): ?>
              <span class="vbadge vb-warn" title="<?= h(sprintf(
                  'Δ colheita→estoque %s kg · Δ venda→baixa %s kg · disponível %s kg',
                  numFmt($gapColheita, 0), numFmt($gapVenda, 0), numFmt($disp, 0))) ?>">inconsistência</span>
              <?php if (vero_can('relatorios.integridade_producao.ver')): ?>
                <a class="vhint" href="<?= $base ?>/relatorios/integridade_producao.php?safra=<?= (int)$b['id'] ?>&amp;aplicar=1">ver painel de Integridade</a>
              <?php endif; ?>
            <?php endif; ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$b['colhido'], 0) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$b['vendido'], 0) ?></td>
          <td class="vnum" style="text-align:right;<?= $disp < 0 ? 'color:#b3261e' : '' ?>"><strong><?= numFmt($disp, 0) ?></strong></td>
          <td><div style="display:flex;align-items:center;gap:8px">
            <div style="flex:1;height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
              <div style="height:100%;width:<?= number_format(min($pct, 100), 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
            </div>
            <span class="vnum vhint"><?= numFmt($pct, 0) ?>%</span>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">Disponível negativo indica venda acima do colhido registrado — confira os registros de colheita.</div>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Posições de armazenagem</strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/comercial/armazenagem_propria.php">Gerenciar</a></div>
    <?php if (!$posicoes): ?>
      <div class="vempty">Nenhuma posição declarada em armazenagem própria.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Local</th><th>Cultura</th><th>Safra</th><th style="text-align:right">Quantidade</th></tr></thead>
      <tbody>
      <?php foreach ($posicoes as $p): ?>
        <tr>
          <td><strong><?= h($p['local']) ?></strong></td>
          <td><?= h($p['cultura'] ?? '—') ?></td>
          <td><?= h($p['safra'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$p['quantidade'], 0) ?></strong>
            <span class="vhint"><?= h($p['unidade'] ?? 'kg') ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
