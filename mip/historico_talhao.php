<?php
/* ============================================================
   VERO — MIP / Histórico por Válvula  (tela real, leitura)
   Substitui o mock. Rota: /mip/historico_talhao.php
   Guard: mip.historico_talhao
   Monitoramentos de uma válvula no tempo, por alvo, com o índice
   × nível de ação do alvo e os alertas gerados.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$fTalhao = (int)($_GET['talhao'] ?? 0);
$fAlvo   = (int)($_GET['alvo'] ?? 0);
$fIni    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';

$t = vero_tenant();
$talhoes = vero_rows(
    "SELECT t.id, t.codigo, f.nome AS fazenda FROM agro_talhoes t
      LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
     WHERE t.tenant_id = :t ORDER BY f.nome, t.codigo", [':t' => $t]);
$alvos = vero_rows("SELECT id, nome, tipo, nivel_acao FROM mip_alvos WHERE tenant_id = :t ORDER BY nome", [':t' => $t]);

$monitoramentos = [];
$resumoAlvos = [];
if ($fTalhao > 0) {
    $where  = "m.tenant_id = :t AND m.talhao_id = :tal";
    $params = [':t' => $t, ':tal' => $fTalhao];
    if ($fAlvo > 0)   { $where .= " AND m.alvo_id = :a";             $params[':a'] = $fAlvo; }
    if ($fIni !== '') { $where .= " AND m.data_monitoramento >= :i"; $params[':i'] = $fIni; }
    if ($fFim !== '') { $where .= " AND m.data_monitoramento <= :f"; $params[':f'] = $fFim; }

    $monitoramentos = vero_rows(
        "SELECT m.*, av.nome AS alvo, av.tipo AS alvo_tipo, av.nivel_acao
           FROM mip_monitoramentos m
           LEFT JOIN mip_alvos av ON av.id = m.alvo_id
          WHERE {$where}
          ORDER BY m.data_monitoramento DESC, m.id DESC LIMIT 300", $params);

    /* resumo por alvo: última leitura × nível de ação */
    foreach (array_reverse($monitoramentos) as $m) {
        $resumoAlvos[(int)$m['alvo_id']] = [
            'alvo'   => $m['alvo'] ?? ('Alvo #' . $m['alvo_id']),
            'tipo'   => $m['alvo_tipo'],
            'nivel'  => $m['nivel_acao'] !== null ? (float)$m['nivel_acao'] : null,
            'ultimo' => (float)$m['nivel_infestacao'],
            'data'   => (string)$m['data_monitoramento'],
        ];
    }
}

$GUARD      = ['macro' => 'mip', 'micro' => 'historico_talhao'];
$PAGE_VIEW  = 'mip_historico_talhao';
$PAGE_TITLE = 'Histórico por Válvula (MIP)';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Histórico por Válvula', 'Evolução dos monitoramentos MIP da válvula — índice observado × nível de ação de cada alvo', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <select name="talhao" onchange="this.form.submit()">
          <option value="">Selecione a válvula…</option>
          <?php foreach ($talhoes as $tl): ?>
            <option value="<?= (int)$tl['id'] ?>"<?= $fTalhao === (int)$tl['id'] ? ' selected' : '' ?>>
              <?= h(($tl['fazenda'] ? $tl['fazenda'] . ' — ' : '') . $tl['codigo']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <select name="alvo" onchange="this.form.submit()">
          <option value="">Todos os alvos</option>
          <?php foreach ($alvos as $a): ?>
            <option value="<?= (int)$a['id'] ?>"<?= $fAlvo === (int)$a['id'] ? ' selected' : '' ?>><?= h($a['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub"><?= count($monitoramentos) ?> monitoramento(s)</span>
    </div>

    <?php if ($resumoAlvos): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;padding:12px 14px">
      <?php foreach ($resumoAlvos as $r):
          $acima = $r['nivel'] !== null && $r['ultimo'] >= $r['nivel']; ?>
        <div class="vkpi" style="border-left:4px solid <?= $acima ? '#b3261e' : 'var(--vero-ok,#1a7f4b)' ?>;padding-left:10px">
          <span class="vhint"><?= h((string)$r['alvo']) ?> · última <?= date('d/m', strtotime($r['data'])) ?></span>
          <strong class="vnum" style="font-size:1.1rem;<?= $acima ? 'color:#b3261e' : '' ?>">
            <?= numFmt($r['ultimo'], 1) ?><?= $r['nivel'] !== null ? ' / nível ' . numFmt($r['nivel'], 1) : '' ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <?php if ($fTalhao === 0): ?>
      <div class="vempty">Selecione uma válvula para ver o histórico de monitoramentos.</div>
    <?php elseif (!$monitoramentos): ?>
      <div class="vempty">Nenhum monitoramento para esta válvula no filtro.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Data</th><th>Alvo</th><th>Tipo</th>
        <th class="num">Índice</th>
        <th class="num">Nível de ação</th>
        <th>Situação</th><th>Observação</th>
      </tr></thead>
      <tbody>
      <?php foreach ($monitoramentos as $m):
          $nivel = $m['nivel_acao'] !== null ? (float)$m['nivel_acao'] : null;
          $idx   = (float)$m['nivel_infestacao'];
          $acima = $nivel !== null && $idx >= $nivel;
          $critico = $nivel !== null && $nivel > 0 && $idx >= 2 * $nivel; ?>
        <tr>
          <td class="vnum"><strong><?= date('d/m/Y', strtotime((string)$m['data_monitoramento'])) ?></strong></td>
          <td><strong><?= h($m['alvo'] ?? '—') ?></strong></td>
          <td><span class="vbadge vb-info"><?= h(ucfirst((string)($m['alvo_tipo'] ?? '—'))) ?></span></td>
          <td class="vnum" style="text-align:right;<?= $acima ? 'color:#b3261e;font-weight:700' : '' ?>">
            <?= numFmt($idx, 1) ?> <span class="vhint"><?= h($m['unidade'] ?? '%') ?></span></td>
          <td class="num"><?= $nivel !== null ? numFmt($nivel, 1) : '—' ?></td>
          <td><?= $critico ? '<span class="vbadge vb-off">Crítico (≥2× nível)</span>'
                : ($acima ? '<span class="vbadge vb-warn">No nível de ação</span>'
                          : '<span class="vbadge vb-ok">Abaixo do nível</span>') ?></td>
          <td class="vhint"><?= h(mb_substr((string)($m['observacao'] ?? ''), 0, 60)) ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      Índices no nível de ação geram alerta em MIP → Alertas Fitossanitários.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
