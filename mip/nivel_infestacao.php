<?php
/* ============================================================
   VERO — MIP / Nível de Infestação  (tela real, leitura)
   Substitui o mock. Rota: /mip/nivel_infestacao.php
   Guard: mip.nivel_infestacao
   Situação atual: última leitura de cada alvo×válvula contra o
   nível de ação, com pico dos últimos 30 dias.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

/* última leitura por alvo×válvula (MySQL 5.7 — via join na data máxima) */
$rows = vero_rows(
    "SELECT m.*, av.nome AS alvo, av.tipo AS alvo_tipo, av.nivel_acao,
            tl.codigo AS talhao, fz.nome AS fazenda,
            (SELECT MAX(m3.nivel_infestacao) FROM mip_monitoramentos m3
              WHERE m3.tenant_id = m.tenant_id AND m3.alvo_id = m.alvo_id AND m3.talhao_id = m.talhao_id
                AND m3.data_monitoramento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS pico_30d
       FROM mip_monitoramentos m
       JOIN (SELECT alvo_id, talhao_id, MAX(CONCAT(data_monitoramento, LPAD(id,10,'0'))) AS chave
               FROM mip_monitoramentos WHERE tenant_id = :t1 GROUP BY alvo_id, talhao_id) ult
         ON ult.alvo_id = m.alvo_id AND ult.talhao_id = m.talhao_id
        AND CONCAT(m.data_monitoramento, LPAD(m.id,10,'0')) = ult.chave
       LEFT JOIN mip_alvos av ON av.id = m.alvo_id
       LEFT JOIN agro_talhoes tl ON tl.id = m.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
      WHERE m.tenant_id = :t2
      ORDER BY (m.nivel_infestacao >= av.nivel_acao) DESC, m.nivel_infestacao DESC",
    [':t1' => $t, ':t2' => $t]);

$acimaN = 0;
foreach ($rows as $r) {
    if ($r['nivel_acao'] !== null && (float)$r['nivel_infestacao'] >= (float)$r['nivel_acao']) $acimaN++;
}

$GUARD      = ['macro' => 'mip', 'micro' => 'nivel_infestacao'];
$PAGE_VIEW  = 'mip_nivel_infestacao';
$PAGE_TITLE = 'Nível de Infestação';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Nível de Infestação', 'Última leitura de cada alvo por válvula contra o nível de ação do RT', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <span class="vsub"><?= count($rows) ?> combinação(ões) alvo×válvula ·
        <strong style="color:<?= $acimaN > 0 ? '#b3261e' : 'var(--vero-ok,#1a7f4b)' ?>"><?= $acimaN ?> no nível de ação</strong></span>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/mip/monitoramento.php">Monitoramentos</a>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum monitoramento registrado ainda.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Alvo</th><th>Tipo</th><th>Válvula</th>
        <th>Última leitura</th>
        <th class="num">Índice atual</th>
        <th class="num">Pico 30d</th>
        <th class="num">Nível de ação</th>
        <th style="width:20%">Posição</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $idx   = (float)$r['nivel_infestacao'];
          $nivel = $r['nivel_acao'] !== null ? (float)$r['nivel_acao'] : null;
          $pct   = $nivel !== null && $nivel > 0 ? min($idx / $nivel * 100, 200) : 0;
          $acima = $nivel !== null && $idx >= $nivel; ?>
        <tr>
          <td><strong><?= h($r['alvo'] ?? '—') ?></strong></td>
          <td><span class="vbadge vb-info"><?= h(ucfirst(str_replace('_', ' ', (string)($r['alvo_tipo'] ?? '—')))) ?></span></td>
          <td><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_monitoramento'])) ?></td>
          <td class="vnum" style="text-align:right;<?= $acima ? 'color:#b3261e;font-weight:700' : '' ?>">
            <?= numFmt($idx, 1) ?> <span class="vhint"><?= h($r['unidade'] ?? '%') ?></span></td>
          <td class="num"><?= $r['pico_30d'] !== null ? numFmt((float)$r['pico_30d'], 1) : '—' ?></td>
          <td class="num"><?= $nivel !== null ? numFmt($nivel, 1) : '—' ?></td>
          <td><div style="display:flex;align-items:center;gap:8px">
            <div style="flex:1;height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden;position:relative">
              <div style="height:100%;width:<?= number_format(min($pct / 2, 100), 1, '.', '') ?>%;
                          background:<?= $acima ? '#b3261e' : 'var(--vero-ok,#1a7f4b)' ?>;border-radius:5px"></div>
              <div style="position:absolute;left:50%;top:-2px;bottom:-2px;width:2px;background:#333" title="nível de ação"></div>
            </div>
            <span class="vnum vhint"><?= $nivel !== null ? numFmt($idx / max($nivel, 0.001) * 100, 0) . '%' : '—' ?></span>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">A marca central da barra é o nível de ação (100%). Índices no nível ou acima geram alerta em MIP → Alertas Fitossanitários.</div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
