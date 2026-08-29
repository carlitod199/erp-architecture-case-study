<?php
/* ============================================================
   VERO — Irrigação / Planejado vs Realizado  (tela real, leitura)
   Substitui o mock. Rota: /irrigacao/planejado_realizado.php
   Guard: irrigacao.planejado_realizado
   Cada planejamento × lâmina/horas apontadas no período dele,
   com % de atendimento da lâmina-alvo.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fTalhao = (int)($_GET['talhao'] ?? 0);

$where  = "p.tenant_id = :t";
$params = [':t' => $t];
if ($fTalhao > 0) { $where .= " AND p.talhao_id = :tal"; $params[':tal'] = $fTalhao; }

$rows = vero_rows(
    "SELECT p.*, tl.codigo AS talhao, fz.nome AS fazenda, tl.area_ha, pv.nome AS pivo,
            (SELECT COALESCE(SUM(c.quantidade),0) FROM irrigacao_consumos c
              JOIN irrigacao_apontamentos a4 ON a4.id = c.apontamento_id
              WHERE c.tenant_id = p.tenant_id AND c.tipo = 'agua'
                AND a4.tenant_id = p.tenant_id AND a4.talhao_id = p.talhao_id
                AND a4.data_apontamento >= p.data_inicio
                AND (p.data_fim IS NULL OR a4.data_apontamento <= p.data_fim)) AS agua_m3,
            (SELECT COALESCE(SUM(a.lamina_mm),0) FROM irrigacao_apontamentos a
              WHERE a.tenant_id = p.tenant_id AND a.talhao_id = p.talhao_id
                AND a.data_apontamento >= p.data_inicio
                AND (p.data_fim IS NULL OR a.data_apontamento <= p.data_fim)) AS lamina_apontada,
            (SELECT COALESCE(SUM(a2.horas),0) FROM irrigacao_apontamentos a2
              WHERE a2.tenant_id = p.tenant_id AND a2.talhao_id = p.talhao_id
                AND a2.data_apontamento >= p.data_inicio
                AND (p.data_fim IS NULL OR a2.data_apontamento <= p.data_fim)) AS horas,
            (SELECT COUNT(*) FROM irrigacao_apontamentos a3
              WHERE a3.tenant_id = p.tenant_id AND a3.talhao_id = p.talhao_id
                AND a3.data_apontamento >= p.data_inicio
                AND (p.data_fim IS NULL OR a3.data_apontamento <= p.data_fim)) AS eventos
       FROM irrigacao_planejamentos p
       LEFT JOIN agro_talhoes tl ON tl.id = p.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
       LEFT JOIN agro_pivos pv ON pv.id = p.pivo_id
      WHERE {$where}
      ORDER BY p.data_inicio DESC, p.id DESC", $params);

$talhoes = vero_rows(
    "SELECT DISTINCT tl.id, tl.codigo, f.nome AS fazenda
       FROM irrigacao_planejamentos p JOIN agro_talhoes tl ON tl.id = p.talhao_id
       LEFT JOIN agro_fazendas f ON f.id = tl.fazenda_id
      WHERE p.tenant_id = :t ORDER BY f.nome, tl.codigo", [':t' => $t]);

$GUARD      = ['macro' => 'irrigacao', 'micro' => 'planejado_realizado'];
$PAGE_VIEW  = 'irrigacao_planejado_realizado';
$PAGE_TITLE = 'Planejado vs Realizado (Irrigação)';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Planejado vs Realizado', 'Lâmina-alvo dos planejamentos contra a lâmina apontada no período', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="talhao" onchange="this.form.submit()">
          <option value="">Todas as válvulas</option>
          <?php foreach ($talhoes as $tl): ?>
            <option value="<?= (int)$tl['id'] ?>"<?= $fTalhao === (int)$tl['id'] ? ' selected' : '' ?>>
              <?= h(($tl['fazenda'] ? $tl['fazenda'] . ' — ' : '') . $tl['codigo']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= count($rows) ?> planejamento(s) ·
        <a href="<?= $base ?>/irrigacao/planejamento_irrigacao.php">planejar</a></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum planejamento — crie em Irrigação → Planejamento para acompanhar o atendimento.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Válvula</th><th>Período</th>
        <th class="num">Lâmina-alvo (mm)</th>
        <th class="num">Apontada (mm)</th>
        <th class="num" title="Lâmina realizada = consumo de água (m³) ÷ (área da válvula em ha × 10)">Realizada — consumo (mm)</th>
        <th class="num">Horas</th>
        <th class="num">Eventos</th>
        <th style="width:22%">Atendimento</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $alvo = (float)$r['lamina_mm'];
          $apont = (float)$r['lamina_apontada'];
          /* R2-06: lâmina implícita do consumo de água do período */
          $areaHa = $r['area_ha'] !== null ? (float)$r['area_ha'] : 0.0;
          $aguaM3 = (float)$r['agua_m3'];
          $lamReal = $areaHa > 0 ? $aguaM3 / ($areaHa * 10) : null;
          $divergente = $lamReal !== null && $apont > 0 && abs($lamReal - $apont) > 0.05;
          $pct = $alvo > 0 ? $apont / $alvo * 100 : 0;
          $cor = $pct >= 100 ? 'var(--vero-ok,#1a7f4b)' : ($pct >= 70 ? '#8A6D1A' : '#b3261e'); ?>
        <tr>
          <td><strong><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></strong>
            <?= $r['pivo'] ? '<div class="vhint">' . h((string)$r['pivo']) . '</div>' : '' ?></td>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_inicio'])) ?>
            <?= $r['data_fim'] ? '– ' . date('d/m/Y', strtotime((string)$r['data_fim'])) : '<span class="vhint">em aberto</span>' ?></td>
          <td class="num"><strong><?= numFmt($alvo, 1) ?></strong></td>
          <td class="num"><strong><?= numFmt($apont, 1) ?></strong></td>
          <td class="num"<?= $lamReal !== null ? ' title="' . numFmt($aguaM3, 1) . ' m³ ÷ (' . numFmt($areaHa, 2) . ' ha × 10)"' : '' ?>>
            <?php if ($lamReal !== null): ?>
              <strong<?= $divergente ? ' style="color:#8A6D1A"' : '' ?>><?= numFmt($lamReal, 2) ?></strong>
              <?= $divergente ? '<span class="vhint" title="Difere da lâmina apontada — confira o consumo ou a lâmina registrada">≠ apontada</span>' : '' ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="num"><?= numFmt((float)$r['horas'], 1) ?></td>
          <td class="num"><?= (int)$r['eventos'] ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
                <div style="height:100%;width:<?= number_format(min($pct, 100), 1, '.', '') ?>%;background:<?= $cor ?>;border-radius:5px"></div>
              </div>
              <span class="vnum vhint" style="color:<?= $cor ?>"><?= numFmt($pct, 0) ?>%</span>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      Apontada = soma das lâminas dos apontamentos da válvula dentro do período do planejamento.
      Realizada — consumo = consumo de água (m³) ÷ (área da válvula em ha × 10) — lâmina implícita no volume;
      quando difere da apontada, há inconsistência entre a lâmina registrada e o consumo.
      A lâmina-alvo é decisão do responsável pelo manejo.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
