<?php
/* ============================================================
   VERO — Irrigação / Setores de Irrigação  (tela real, leitura)
   Substitui o mock. Rota: /irrigacao/setores_irrigacao.php
   Guard: irrigacao.setores_irrigacao
   Visão consolidada dos setores/válvulas (agro_setores) sob a
   ótica da irrigação: área, válvula, horas e lâmina apontadas no
   válvula. O cadastro fica em Gestão Agrícola → Válvulas.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : date('Y-m-d', strtotime('-30 days'));
$fFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : date('Y-m-d');

$rows = vero_rows(
    "SELECT s.*, tl.codigo AS talhao, tl.area_ha AS talhao_area, fz.nome AS fazenda,
            (SELECT COALESCE(SUM(a.horas),0) FROM irrigacao_apontamentos a
              WHERE a.tenant_id = s.tenant_id AND a.talhao_id = s.talhao_id
                AND a.data_apontamento BETWEEN :i1 AND :f1) AS horas,
            (SELECT COALESCE(SUM(a2.lamina_mm),0) FROM irrigacao_apontamentos a2
              WHERE a2.tenant_id = s.tenant_id AND a2.talhao_id = s.talhao_id
                AND a2.data_apontamento BETWEEN :i2 AND :f2) AS lamina
       FROM agro_setores s
       LEFT JOIN agro_talhoes tl ON tl.id = s.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = s.fazenda_id
      WHERE s.tenant_id = :t AND s.ativo = 1
      ORDER BY fz.nome, tl.codigo, s.nome",
    [':t' => $t, ':i1' => $fIni, ':f1' => $fFim, ':i2' => $fIni, ':f2' => $fFim]);

$GUARD      = ['macro' => 'irrigacao', 'micro' => 'setores_irrigacao'];
$PAGE_VIEW  = 'irrigacao_setores_irrigacao';
$PAGE_TITLE = 'Válvulas (Irrigação)';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Válvulas (Irrigação)', 'Suas válvulas vistas pela irrigação — horas e lâmina no período. É a mesma válvula do cadastro (Gestão Agrícola → Válvulas), aqui sob a ótica da água.', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <label class="vhint">Período</label>
        <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub"><?= count($rows) ?> válvula(s) ·
        <a href="<?= $base ?>/agro/valvulas.php">cadastro de válvulas</a></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma válvula ativa — cadastre em Gestão Agrícola → Válvulas.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Válvula</th><th>Fazenda / Talhão</th>
        <th class="num">Área (ha)</th>
        <th class="num">Horas no período</th>
        <th class="num">Lâmina (mm)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome'] ?? $r['codigo'] ?? '—') ?></strong>
            <?= $r['codigo'] && $r['nome'] ? '<span class="vhint">' . h((string)$r['codigo']) . '</span>' : '' ?></td>
          <td><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td class="num"><?= $r['area_ha'] !== null ? numFmt((float)$r['area_ha'], 2)
                : ($r['talhao_area'] !== null ? numFmt((float)$r['talhao_area'], 2) . ' <span class="vhint">(válvula)</span>' : '—') ?></td>
          <td class="num"><?= numFmt((float)$r['horas'], 1) ?></td>
          <td class="num"><strong><?= numFmt((float)$r['lamina'], 1) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      Horas e lâmina vêm dos apontamentos de irrigação de cada válvula no período selecionado.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
