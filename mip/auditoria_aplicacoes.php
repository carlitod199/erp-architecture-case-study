<?php
/* ============================================================
   VERO — MIP / Consulta do Auditor  (tela real, leitura — A1-31)
   Rota da matriz: /mip/auditoria_aplicacoes.php (micro mip.auditoria_aplicacoes)
   Guard: mip.auditoria_aplicacoes
   "O auditor pede as últimas DFs e IFs das áreas que estão colhendo"
   (GlobalG.A.P. IFA — CB 7.4.1/FV 32.03, Major Must): parte das áreas
   COM COLHEITA no filtro e lista as últimas aplicações de cada uma,
   com o confronto carência × data real de colheita CALCULADO
   (verde = fora da carência; vermelho = colhida DENTRO da carência)
   e impressão em LOTE (pacote do auditor numa tirada — A1-27 ?ids=).
   Mesma verdade do alerta `residuo` da colheita, em formato de evidência.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

/* ── Filtros ────────────────────────────────────────────────── */
$fFazenda = (int)($_GET['fazenda'] ?? 0);
$fSafra   = (int)($_GET['safra'] ?? 0);
$fSetor   = (int)($_GET['valvula'] ?? 0);
$fIni     = (string)($_GET['ini'] ?? '');
$fFim     = (string)($_GET['fim'] ?? '');
if ($fIni !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fIni)) $fIni = '';
if ($fFim !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFim)) $fFim = '';
$nUltimas = min(20, max(1, (int)($_GET['n'] ?? 5)));

/* ── 1) Áreas em colheita (registros de colheita no filtro) ── */
$where  = "cr.tenant_id = :t";
$params = [':t' => $t];
if ($fSafra > 0)  { $where .= " AND cr.safra_id = :s";  $params[':s'] = $fSafra; }
if ($fSetor > 0)  { $where .= " AND cr.setor_id = :se"; $params[':se'] = $fSetor; }
if ($fIni !== '') { $where .= " AND cr.data_colheita >= :i"; $params[':i'] = $fIni; }
if ($fFim !== '') { $where .= " AND cr.data_colheita <= :f"; $params[':f'] = $fFim; }
if ($fFazenda > 0) { $where .= " AND tl.fazenda_id = :fz"; $params[':fz'] = $fFazenda; }

$colheitas = vero_rows(
    "SELECT cr.id, cr.talhao_id, cr.data_colheita, cr.kg_total_realizado,
            tl.codigo AS talhao, fz.nome AS fazenda, sa.identificacao AS safra,
            se.codigo AS valvula
       FROM colheita_registros cr
       JOIN agro_talhoes tl ON tl.id = cr.talhao_id
       JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
       JOIN agro_safras sa ON sa.id = cr.safra_id
       LEFT JOIN agro_setores se ON se.id = cr.setor_id
      WHERE {$where}
      ORDER BY cr.data_colheita DESC, cr.id DESC
      LIMIT 200", $params);

/* agrupa por talhão (área): colheitas + últimas DF/IF */
$areas = [];
foreach ($colheitas as $c) {
    $tid = (int)$c['talhao_id'];
    if (!isset($areas[$tid])) {
        $areas[$tid] = [
            'talhao' => $c['talhao'], 'fazenda' => $c['fazenda'],
            'colheitas' => [], 'docs' => [],
        ];
    }
    $areas[$tid]['colheitas'][] = $c;
}

/* ── 2) Últimas DF/IF de cada área (não canceladas) + confronto ── */
foreach ($areas as $tid => &$area) {
    $docs = vero_rows(
        "SELECT ap.id, ap.doc_serie, ap.doc_numero, ap.tipo, ap.data, ap.status,
                sa.identificacao AS safra,
                (SELECT GROUP_CONCAT(CONCAT(COALESCE(p.codigo, p.nome, i.ingrediente_ativo, '?'),
                        CASE WHEN i.dose_valor IS NOT NULL
                             THEN CONCAT(' ', ROUND(i.dose_valor, 2), ' ', COALESCE(i.dose_unidade, ''))
                             ELSE '' END) SEPARATOR ' | ')
                   FROM agro_aplicacao_itens i
                   LEFT JOIN estoque_produtos p ON p.id = i.produto_id
                  WHERE i.tenant_id = ap.tenant_id AND i.aplicacao_id = ap.id)      AS produtos,
                (SELECT MAX(COALESCE(i2.carencia_dias, 0))
                   FROM agro_aplicacao_itens i2
                  WHERE i2.tenant_id = ap.tenant_id AND i2.aplicacao_id = ap.id)    AS carencia_max
           FROM agro_aplicacoes ap
           LEFT JOIN agro_safras sa ON sa.id = ap.safra_id
          WHERE ap.tenant_id = :t AND ap.talhao_id = :ta AND ap.status <> 'cancelada'
          ORDER BY COALESCE(ap.data, ap.data_prevista) DESC, ap.id DESC
          LIMIT {$nUltimas}",
        [':t' => $t, ':ta' => $tid]);

    foreach ($docs as &$d) {
        $car = (int)($d['carencia_max'] ?? 0);
        $d['liberado_em'] = ($car > 0 && $d['data'])
            ? date('Y-m-d', strtotime((string)$d['data'] . " +{$car} days")) : null;
        /* confronto: alguma colheita DESTA área caiu dentro da carência do doc? */
        $d['violacoes'] = [];
        if ($d['liberado_em']) {
            foreach ($area['colheitas'] as $c) {
                if ((string)$c['data_colheita'] >= (string)$d['data']
                    && (string)$c['data_colheita'] < $d['liberado_em']) {
                    $d['violacoes'][] = (string)$c['data_colheita'];
                }
            }
        }
        $d['produtos'] = (string)($d['produtos'] ?? '');
    }
    unset($d);
    $area['docs'] = $docs;
}
unset($area);

$fazendas = vero_options('agro_fazendas', 'nome', 'ativo = 1');
$safras   = vero_options('agro_safras', 'identificacao');
$setoresOpt = [];
foreach (vero_rows(
    "SELECT s.id, CONCAT(COALESCE(f.nome,''), ' — ', s.codigo) AS label
       FROM agro_setores s LEFT JOIN agro_fazendas f ON f.id = s.fazenda_id
      WHERE s.tenant_id = :t AND s.ativo = 1 ORDER BY f.nome, s.codigo", [':t' => $t]
) as $so) { $setoresOpt[(int)$so['id']] = (string)$so['label']; }

$GUARD      = ['macro' => 'mip', 'micro' => 'auditoria_aplicacoes'];
$PAGE_VIEW  = 'mip_auditoria_aplicacoes';
$PAGE_TITLE = 'Consulta do Auditor';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Consulta do Auditor — DF/IF × Colheita',
        'Áreas em colheita e as últimas aplicações de cada uma, com o confronto carência × data real (GlobalG.A.P. IFA — CB 7.4)', null) ?>

  <div class="vcard" style="margin-bottom:16px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="fazenda" onchange="this.form.submit()">
          <option value="">Todas as fazendas</option>
          <?php foreach ($fazendas as $fid => $fn): ?>
            <option value="<?= $fid ?>"<?= $fFazenda === $fid ? ' selected' : '' ?>><?= h($fn) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="safra" onchange="this.form.submit()">
          <option value="">Todas as safras</option>
          <?php foreach ($safras as $sid => $sn): ?>
            <option value="<?= $sid ?>"<?= $fSafra === $sid ? ' selected' : '' ?>><?= h($sn) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="valvula" onchange="this.form.submit()">
          <option value="">Todas as válvulas</option>
          <?php foreach ($setoresOpt as $sid => $sn): ?>
            <option value="<?= $sid ?>"<?= $fSetor === $sid ? ' selected' : '' ?>><?= h($sn) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="ini" value="<?= h($fIni) ?>" title="Colheitas a partir de">
        <input type="date" name="fim" value="<?= h($fFim) ?>" title="Colheitas até">
        <select name="n" onchange="this.form.submit()" title="Quantas últimas aplicações por área">
          <?php foreach ([3, 5, 10, 20] as $nn): ?>
            <option value="<?= $nn ?>"<?= $nUltimas === $nn ? ' selected' : '' ?>>últimas <?= $nn ?></option>
          <?php endforeach; ?>
        </select>
        <button class="vbtn vbtn-ghost" type="submit">Filtrar</button>
      </form>
      <span class="vsub"><?= count($areas) ?> área(s) em colheita</span>
    </div>
  </div>

  <?php if (!$areas): ?>
    <div class="vcard"><div class="vempty">Nenhuma colheita no filtro — o pacote do auditor parte das áreas que estão colhendo.</div></div>
  <?php endif; ?>

  <?php foreach ($areas as $tid => $area):
      $idsDocs = array_column(array_filter($area['docs'], static fn($d) => $d['doc_serie'] !== null), 'id');
      $temViolacao = false;
      foreach ($area['docs'] as $d) if ($d['violacoes']) { $temViolacao = true; break; }
  ?>
  <div class="vcard" style="margin-bottom:16px">
    <div class="vtoolbar" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
      <strong><?= h($area['fazenda']) ?> — talhão <?= h($area['talhao']) ?>
        <?php if ($temViolacao): ?><span class="vbadge vb-off">⚠ colheita em carência</span>
        <?php else: ?><span class="vbadge vb-ok">carências respeitadas</span><?php endif; ?>
      </strong>
      <span style="display:flex;gap:8px;align-items:center">
        <span class="vhint"><?= count($area['colheitas']) ?> colheita(s):
          <?= implode(', ', array_map(static fn($c) => date('d/m', strtotime((string)$c['data_colheita'])), array_slice($area['colheitas'], 0, 6))) ?><?= count($area['colheitas']) > 6 ? '…' : '' ?></span>
        <?php if ($idsDocs): ?>
          <a class="vbtn vbtn-primary vbtn-sm" target="_blank"
             href="<?= BIOS_BASE ?>/mip/aplicacao_impressao?ids=<?= implode(',', array_map('intval', $idsDocs)) ?>">
            🖨 Imprimir pacote (<?= count($idsDocs) ?> doc.)</a>
        <?php endif; ?>
      </span>
    </div>

    <?php if (!$area['docs']): ?>
      <div class="vempty" style="color:#b3261e">Área em colheita SEM nenhuma aplicação registrada — ponto de atenção para a auditoria.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Documento</th><th>Data</th><th>Tipo</th><th>Safra</th>
        <th>Produtos / doses</th>
        <th class="num">Carência máx.</th>
        <th>Colheita permitida a partir de</th>
        <th>Confronto</th>
        <th class="num"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($area['docs'] as $d): ?>
        <tr>
          <td><?= $d['doc_serie']
                ? '<strong class="vnum">' . h((string)$d['doc_serie']) . (int)$d['doc_numero'] . '</strong>'
                : '<span class="vhint">#' . (int)$d['id'] . ' (sem número — anterior à DF/IF)</span>' ?></td>
          <td class="vnum"><?= $d['data'] ? date('d/m/Y', strtotime((string)$d['data'])) : '—' ?></td>
          <td><span class="vbadge vb-info"><?= h(ucfirst((string)$d['tipo'])) ?></span></td>
          <td><?= h($d['safra'] ?? '—') ?></td>
          <td style="font-size:12px"><?= h((string)$d['produtos']) ?: '—' ?></td>
          <td class="num"><?= (int)$d['carencia_max'] > 0 ? (int)$d['carencia_max'] . ' d' : '—' ?></td>
          <td class="vnum"><?= $d['liberado_em'] ? date('d/m/Y', strtotime((string)$d['liberado_em'])) : '—' ?></td>
          <td><?php if ($d['violacoes']): ?>
                <span class="vbadge vb-off">✗ colhida em <?= implode(', ', array_map(static fn($v) => date('d/m', strtotime($v)), $d['violacoes'])) ?> — DENTRO da carência</span>
              <?php elseif ($d['liberado_em']): ?>
                <span class="vbadge vb-ok">✓ respeitada</span>
              <?php else: ?>
                <span class="vhint">sem carência registrada</span>
              <?php endif; ?></td>
          <td style="text-align:right">
            <div class="vactions"><?= vero_btn_icone(vero_ico_olho(), 'Ver', '', BIOS_BASE . '/mip/aplicacoes?ver=' . (int)$d['id']) ?></div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
