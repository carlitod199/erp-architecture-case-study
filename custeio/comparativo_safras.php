<?php
/* ============================================================
   VERO — Custos / Comparativo entre Safras  (tela real, leitura)
   Rota: /custeio/comparativo_safras.php (micro custos.comparativo_safras,
   criado no A0-04; rota real no $rotasReais é 1 linha do A0)
   Guard: custos.comparativo_safras
   Safras lado a lado (a safra do VERO é o ciclo de poda — o
   comparativo responde "a 2026.2 foi melhor que a 2026.1 no mesmo
   parreiral?"): área, produção, faturamento, custo por categoria,
   custo/ha, custo/kg, resultado e margem. A3-T12.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

$safrasAll = vero_rows(
    "SELECT id, identificacao, status FROM agro_safras
      WHERE tenant_id = :t ORDER BY identificacao DESC", [':t' => $t]);

/* Seleção: até 4 safras; default = as 2 mais recentes */
$sel = array_filter(array_map('intval', (array)($_GET['safras'] ?? [])));
if (!$sel) $sel = array_slice(array_map(static fn($s) => (int)$s['id'], $safrasAll), 0, 2);
$sel = array_slice(array_values(array_unique($sel)), 0, 4);

$dados = [];
$categorias = [];
foreach ($sel as $sid) {
    /* placeholders :t/:s usados UMA vez cada (EMULATE_PREPARES=false não
       aceita repetição — correção da auditoria de 04/07): a query ancora
       em agro_safras e as subqueries correlacionam por s.tenant_id/s.id */
    $base = vero_row(
        "SELECT s.id, s.identificacao, s.status,
            COALESCE((SELECT SUM(st.area_plantada_ha) FROM agro_safra_talhoes st
              WHERE st.tenant_id = s.tenant_id AND st.safra_id = s.id), 0) AS area_ha,
            COALESCE((SELECT SUM(r.kg_total_realizado) FROM colheita_registros r
              WHERE r.tenant_id = s.tenant_id AND r.safra_id = s.id), 0) AS kg_colhidos,
            COALESCE((SELECT SUM(v.valor_total) FROM comercial_vendas v
              WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id AND v.status <> 'cancelada'), 0) AS faturamento,
            COALESCE((SELECT SUM(v2.kg_total) FROM comercial_vendas v2
              WHERE v2.tenant_id = s.tenant_id AND v2.safra_id = s.id AND v2.status <> 'cancelada'), 0) AS kg_vendidos,
            COALESCE((SELECT SUM(cl.valor) FROM custeio_lancamentos cl
              WHERE cl.tenant_id = s.tenant_id AND cl.safra_id = s.id), 0) AS custo
           FROM agro_safras s
          WHERE s.tenant_id = :t AND s.id = :s",
        [':t' => $t, ':s' => $sid]);
    if (!$base) continue;
    $info = ['identificacao' => $base['identificacao'], 'status' => $base['status']];

    $porCat = [];
    foreach (vero_rows(
        "SELECT COALESCE(categoria,'outros') AS categoria, SUM(valor) AS total
           FROM custeio_lancamentos WHERE tenant_id = :t AND safra_id = :s GROUP BY categoria",
        [':t' => $t, ':s' => $sid]) as $c) {
        $porCat[(string)$c['categoria']] = (float)$c['total'];
        $categorias[(string)$c['categoria']] = true;
    }

    $dados[$sid] = ['info' => $info, 'base' => $base, 'cat' => $porCat];
}
$categorias = array_keys($categorias);
sort($categorias);

$GUARD      = ['macro' => 'custos', 'micro' => 'comparativo_safras'];
$PAGE_VIEW  = 'custos_comparativo_safras';
$PAGE_TITLE = 'Comparativo entre Safras';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$rotuloCat = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));
$fmt = static fn(?float $v, int $d = 2): string => $v !== null ? numFmt($v, $d) : '—';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Comparativo entre Safras',
      'Safras (ciclos) lado a lado: produção, custo, resultado e composição — até 4 por vez', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <form method="get" class="vtoolbar" style="flex-wrap:wrap;gap:10px">
      <?php foreach ($safrasAll as $s): ?>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:.9rem">
          <input type="checkbox" name="safras[]" value="<?= (int)$s['id'] ?>"
                 <?= in_array((int)$s['id'], $sel, true) ? 'checked' : '' ?>>
          <?= h($s['identificacao']) ?> <span class="vhint">(<?= h((string)$s['status']) ?>)</span>
        </label>
      <?php endforeach; ?>
      <button class="vbtn vbtn-primary vbtn-sm" type="submit">Comparar</button>
    </form>
  </div>

  <?php if (!$dados): ?>
    <div class="vcard"><div class="vempty">Selecione ao menos uma safra.</div></div>
  <?php else: ?>
  <div class="vcard">
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th>Indicador</th>
        <?php foreach ($dados as $d): ?>
          <th style="text-align:right"><?= h($d['info']['identificacao']) ?>
            <span class="vhint">(<?= h((string)$d['info']['status']) ?>)</span></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php
        $linhas = [
            ['Área plantada (ha)',      static fn($d) => numFmt((float)$d['base']['area_ha'], 2)],
            ['kg colhidos',             static fn($d) => numFmt((float)$d['base']['kg_colhidos'], 0)],
            ['Produtividade (kg/ha)',   static fn($d) => (float)$d['base']['area_ha'] > 0
                ? numFmt((float)$d['base']['kg_colhidos'] / (float)$d['base']['area_ha'], 0) : '—'],
            ['Faturamento vendas (R$)', static fn($d) => numFmt((float)$d['base']['faturamento'], 2)],
            ['Preço médio (R$/kg)',     static fn($d) => (float)$d['base']['kg_vendidos'] > 0
                ? numFmt((float)$d['base']['faturamento'] / (float)$d['base']['kg_vendidos'], 2) : '—'],
            ['Custo total (R$)',        static fn($d) => numFmt((float)$d['base']['custo'], 2)],
            ['Custo/ha (R$)',           static fn($d) => (float)$d['base']['area_ha'] > 0
                ? numFmt((float)$d['base']['custo'] / (float)$d['base']['area_ha'], 2) : '—'],
            ['Custo/kg (R$)',           static fn($d) => (float)$d['base']['kg_colhidos'] > 0
                ? numFmt((float)$d['base']['custo'] / (float)$d['base']['kg_colhidos'], 2) : '—'],
        ];
        foreach ($linhas as [$rotulo, $fn]): ?>
        <tr>
          <td><strong><?= h($rotulo) ?></strong></td>
          <?php foreach ($dados as $d): ?>
            <td class="vnum" style="text-align:right"><?= $fn($d) ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>

        <tr><td colspan="<?= count($dados) + 1 ?>" style="background:#FAF8F1"><strong>Custo por categoria (R$)</strong></td></tr>
        <?php foreach ($categorias as $cat): ?>
        <tr>
          <td style="padding-left:28px"><?= h($rotuloCat($cat)) ?></td>
          <?php foreach ($dados as $d): ?>
            <td class="vnum" style="text-align:right"><?= isset($d['cat'][$cat]) ? numFmt($d['cat'][$cat], 2) : '—' ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>

        <tr style="border-top:2px solid var(--vero-border,#ccc)">
          <td><strong>Resultado bruto (R$)</strong></td>
          <?php foreach ($dados as $d):
              $res = (float)$d['base']['faturamento'] - (float)$d['base']['custo']; ?>
            <td class="vnum" style="text-align:right;font-weight:700;color:<?= $res >= 0 ? '#1E6B34' : '#9A3B2A' ?>">
              <?= numFmt($res, 2) ?></td>
          <?php endforeach; ?>
        </tr>
        <tr>
          <td><strong>Margem</strong></td>
          <?php foreach ($dados as $d):
              $fat = (float)$d['base']['faturamento'];
              $margem = $fat > 0 ? ($fat - (float)$d['base']['custo']) / $fat * 100 : null; ?>
            <td class="vnum" style="text-align:right"><?= $margem !== null ? numFmt($margem, 1) . '%' : '—' ?></td>
          <?php endforeach; ?>
        </tr>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      Compare ciclos do mesmo parreiral (ex.: 2026.1 × 2026.2). Custos indiretos ainda não rateados e
      folha/depreciação fora do custeio (P-07/P-41/P-42) valem igualmente para todas as safras comparadas.
      Detalhe por talhão em <a href="<?= $base ?>/custeio/resultado_safra.php">Resultado da Safra</a>.
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
