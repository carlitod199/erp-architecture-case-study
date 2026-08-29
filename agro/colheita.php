<?php
/* ============================================================
   VERO — Agrícola / Colheita (visão agrícola)  (tela real, leitura)
   Substitui o mock. Rota: /agro/colheita.php
   Guard: agricola.colheita
   Recorte dos registros de colheita sob a ótica do campo —
   o registro completo (com classificações) fica em Colheita.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fSafra = (int)($_GET['safra'] ?? 0);

/* Onda 4 (item 6): apontamento de colheita INLINE nesta tela.
   Reusa 100% da regra crítica — o formulário é o parcial colheita/_form.php
   e o POST vai para o handler de /colheita/index.php (origem=agro), que
   valida (Σ % ≤ 100), grava e faz a entrada no estoque. Aqui NÃO há POST. */
$podeEditar = vero_can('agro.colheita.editar');
$modoNovo   = isset($_GET['novo']) && $podeEditar;

$where  = "cr.tenant_id = :t";
$params = [':t' => $t];
if ($fSafra > 0) { $where .= " AND cr.safra_id = :s"; $params[':s'] = $fSafra; }

$rows = vero_rows(
    "SELECT cr.*, tl.codigo AS talhao, fz.nome AS fazenda, sa.identificacao AS safra,
            cu.nome AS cultura, va.nome AS variedade
       FROM colheita_registros cr
       LEFT JOIN agro_talhoes tl ON tl.id = cr.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
       LEFT JOIN agro_safras sa ON sa.id = cr.safra_id
       LEFT JOIN agro_culturas cu ON cu.id = cr.cultura_id
       LEFT JOIN agro_variedades va ON va.id = cr.variedade_id
      WHERE {$where}
      ORDER BY cr.data_colheita DESC, cr.id DESC LIMIT 100", $params);

$totPrev = array_sum(array_map(static fn($r) => (float)($r['kg_total_previsto'] ?? 0), $rows));
$totReal = array_sum(array_map(static fn($r) => (float)($r['kg_total_realizado'] ?? 0), $rows));

$safras = vero_options('agro_safras', 'identificacao');

$GUARD      = ['macro' => 'agricola', 'micro' => 'colheita'];
$PAGE_VIEW  = 'agricola_colheita';
$PAGE_TITLE = 'Colheita (visão agrícola)';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>

<?php if ($modoNovo): ?>
  <div class="vhead">
    <div>
      <h1>Registrar colheita</h1>
      <div class="vsub">Apontamento direto no campo — mesmas regras do módulo Colheita (classificação Σ % ≤ 100, entrada no estoque)</div>
    </div>
    <a class="vbtn vbtn-ghost" href="<?= $base ?>/agro/colheita.php">← Voltar</a>
  </div>
  <?php
    /* Formulário compartilhado. Novo registro (sem edição): posta para o
       handler completo de /colheita/index.php, que devolve para cá. */
    $edit         = null;
    $editClassifs = [];
    $FORM_ACTION  = $base . '/colheita/index';
    $FORM_ORIGEM  = 'agro';
    $FORM_CANCEL  = $base . '/agro/colheita';
    require __DIR__ . '/../colheita/_form.php';
  ?>
<?php else: ?>
  <div class="vhead">
    <div>
      <h1>Colheita</h1>
      <div class="vsub">Registros de colheita sob a ótica do campo — o registro completo fica no módulo Colheita</div>
    </div>
    <?php if ($podeEditar): ?>
      <a class="vbtn vbtn-primary" href="?novo=1<?= $fSafra > 0 ? '&safra=' . $fSafra : '' ?>">+ Registrar colheita</a>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <select name="safra" onchange="this.form.submit()">
          <option value="">Todas as safras</option>
          <?php foreach ($safras as $sid => $sn): ?>
            <option value="<?= $sid ?>"<?= $fSafra === (int)$sid ? ' selected' : '' ?>><?= h($sn) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub">previsto <strong class="vnum"><?= numFmt($totPrev, 0) ?> kg</strong> ·
        realizado <strong class="vnum"><?= numFmt($totReal, 0) ?> kg</strong> ·
        <a href="<?= $base ?>/colheita/index.php">registros completos</a></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum registro de colheita.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Data</th><th>Válvula</th><th>Cultura / Variedade</th><th>Safra</th>
        <th class="num">Previsto (kg)</th>
        <th class="num">Realizado (kg)</th>
        <th style="width:20%">Realização</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $prev = (float)($r['kg_total_previsto'] ?? 0);
          $real = (float)($r['kg_total_realizado'] ?? 0);
          $pct = $prev > 0 ? $real / $prev * 100 : null;
          $cor = $pct === null ? '#9A8C78' : ($pct >= 100 ? '#1E6B34' : ($pct >= 70 ? '#005059' : '#8A6D1A')); ?>
        <tr>
          <td class="vnum"><strong><?= $r['data_colheita'] ? date('d/m/Y', strtotime((string)$r['data_colheita'])) : '—' ?></strong></td>
          <td><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td><?= h(trim(($r['cultura'] ?? '') . ($r['variedade'] ? ' / ' . $r['variedade'] : ''), ' /') ?: '—') ?></td>
          <td><?= h($r['safra'] ?? '—') ?></td>
          <td class="num"><?= $prev > 0 ? numFmt($prev, 0) : '—' ?></td>
          <td class="num"><strong><?= numFmt($real, 0) ?></strong></td>
          <td><?php if ($pct === null): ?><span class="vhint">sem previsão</span>
            <?php else: ?><div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:8px;background:#F2EDE2;border-radius:4px;overflow:hidden">
                <div style="height:100%;width:<?= number_format(min($pct, 100), 1, '.', '') ?>%;background:<?= $cor ?>;border-radius:4px"></div>
              </div>
              <span class="vnum vhint" style="color:<?= $cor ?>"><?= numFmt($pct, 1) ?>%</span>
            </div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
