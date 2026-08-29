<?php
/* ============================================================
   VERO — MIP / Alertas Fitossanitários  (tela real)
   Substitui o mock. Rota: /mip/alertas_fitossanitarios.php
   Guard: mip.alertas_fitossanitarios
   Fila dos alertas categoria 'mip' (gerados pelos monitoramentos
   quando o índice atinge o nível de ação do alvo) com ações
   reconhecer/resolver. O sistema NÃO recomenda produto/dose —
   toda decisão de controle é do responsável técnico.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');
    if (in_array($acao, ['reconhecer', 'resolver'], true)) {
        vero_require('mip.alertas_fitossanitarios.editar');
        $id = vero_int('id');
        $alerta = $id ? vero_row("SELECT * FROM agro_alertas WHERE id=:i AND tenant_id=:t AND categoria='mip'",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if ($alerta) {
            vero_update('agro_alertas', (int)$id, [
                'status'          => $acao === 'resolver' ? 'resolvido' : 'reconhecido',
                'reconhecido_por' => vero_uid(),
                'reconhecido_em'  => date('Y-m-d H:i:s'),
            ]);
            vero_flash('ok', 'Alerta ' . ($acao === 'resolver' ? 'resolvido' : 'reconhecido') . '.');
        }
        vero_redirect();
    }

    /* A1-20: registro da DECISÃO do RT (mip_alerta_acoes — tabela própria, DB-19).
       É um registro humano da ação tomada; o sistema continua sem recomendar nada. */
    if ($acao === 'registrar_acao') {
        vero_require('mip.alertas_fitossanitarios.editar');
        $id    = vero_int('id');
        $texto = vero_str('acao_texto', 500);
        $alerta = $id ? vero_row("SELECT * FROM agro_alertas WHERE id=:i AND tenant_id=:t AND categoria='mip'",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if (!$alerta || $texto === null) {
            vero_flash('erro', 'Descreva a ação/decisão tomada.');
            vero_redirect();
        }
        $aplicacaoId = vero_int('aplicacao_id');
        if ($aplicacaoId && !vero_val(
            "SELECT id FROM agro_aplicacoes WHERE id=:i AND tenant_id=:t AND status <> 'cancelada'",
            [':i' => $aplicacaoId, ':t' => vero_tenant()])) $aplicacaoId = null;
        vero_insert('mip_alerta_acoes', [
            'alerta_id'    => (int)$id,
            'acao'         => $texto,
            'aplicacao_id' => $aplicacaoId ?: null,
        ]);
        vero_flash('ok', 'Ação registrada no alerta' . ($aplicacaoId ? ' (vinculada à aplicação #' . $aplicacaoId . ')' : '') . '.');
        vero_redirect();
    }
}

$t = vero_tenant();
$fStatus = (string)($_GET['status'] ?? 'ativos');
$fTalhao = (int)($_GET['talhao'] ?? 0);

$where  = "al.tenant_id = :t AND al.categoria = 'mip'";
$params = [':t' => $t];
if ($fStatus === 'ativos')            { $where .= " AND al.status IN ('aberto','reconhecido')"; }
elseif (in_array($fStatus, ['aberto', 'reconhecido', 'resolvido'], true)) {
    $where .= " AND al.status = :st"; $params[':st'] = $fStatus;
}
if ($fTalhao > 0) { $where .= " AND al.talhao_id = :tal"; $params[':tal'] = $fTalhao; }

$kpi = vero_row(
    "SELECT SUM(status='aberto') AS abertos,
            SUM(status='aberto' AND severidade='critico') AS criticos,
            SUM(status='reconhecido') AS reconhecidos,
            SUM(status='resolvido') AS resolvidos
       FROM agro_alertas WHERE tenant_id = :t AND categoria = 'mip'", [':t' => $t]);

$alertas = vero_rows(
    "SELECT al.*, tt.codigo AS talhao, f.nome AS fazenda, u.nome AS reconhecedor
       FROM agro_alertas al
       LEFT JOIN agro_talhoes tt ON tt.id = al.talhao_id
       LEFT JOIN agro_fazendas f ON f.id = COALESCE(al.fazenda_id, tt.fazenda_id)
       LEFT JOIN usuarios u ON u.id = al.reconhecido_por
      WHERE {$where}
      ORDER BY (al.status='aberto') DESC, FIELD(al.severidade,'critico','atencao','info'), al.data DESC
      LIMIT 200", $params);

/* ações registradas (mip_alerta_acoes) dos alertas listados */
$acoesPorAlerta = [];
if ($alertas) {
    $ids = implode(',', array_map(static fn($a) => (int)$a['id'], $alertas));
    foreach (vero_rows(
        "SELECT aa.*, u.nome AS autor
           FROM mip_alerta_acoes aa
           LEFT JOIN usuarios u ON u.id = aa.created_by
          WHERE aa.tenant_id = :t AND aa.alerta_id IN ({$ids})
          ORDER BY aa.id", [':t' => $t]) as $aa) {
        $acoesPorAlerta[(int)$aa['alerta_id']][] = $aa;
    }
}

/* aplicações recentes para vincular à ação */
$aplicacoesOpt = [];
foreach (vero_rows(
    "SELECT ap.id, ap.tipo, ap.data, tl.codigo AS talhao
       FROM agro_aplicacoes ap LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
      WHERE ap.tenant_id = :t AND ap.status <> 'cancelada'
      ORDER BY ap.data DESC, ap.id DESC LIMIT 100", [':t' => $t]
) as $ap) {
    $aplicacoesOpt[(int)$ap['id']] = '#' . $ap['id'] . ' · ' . ($ap['data'] ? date('d/m/Y', strtotime((string)$ap['data'])) : '')
        . ' · ' . ucfirst((string)$ap['tipo']) . ($ap['talhao'] ? ' · ' . $ap['talhao'] : '');
}

$talhoes = vero_rows(
    "SELECT DISTINCT tt.id, tt.codigo, f.nome AS fazenda
       FROM agro_alertas al JOIN agro_talhoes tt ON tt.id = al.talhao_id
       LEFT JOIN agro_fazendas f ON f.id = tt.fazenda_id
      WHERE al.tenant_id = :t AND al.categoria = 'mip' ORDER BY f.nome, tt.codigo", [':t' => $t]);

$GUARD      = ['macro' => 'mip', 'micro' => 'alertas_fitossanitarios'];
$PAGE_VIEW  = 'mip_alertas_fitossanitarios';
$PAGE_TITLE = 'Alertas Fitossanitários';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('mip.alertas_fitossanitarios.editar');
$badgeSev = static fn(string $s): string => match ($s) {
    'critico' => '<span class="vbadge vb-off">Crítico</span>',
    'atencao' => '<span class="vbadge vb-warn">Atenção</span>',
    default   => '<span class="vbadge vb-info">Info</span>',
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Alertas Fitossanitários', 'Fila de alertas dos monitoramentos MIP — decisão de controle é do responsável técnico', null) ?>

  <div class="vcard" style="padding:11px 16px;margin-bottom:14px;display:flex;gap:28px;flex-wrap:wrap;align-items:baseline">
    <div><span class="vhint">Abertos&nbsp;</span><strong class="vnum" style="font-size:16px;color:#b3261e"><?= (int)($kpi['abertos'] ?? 0) ?></strong></div>
    <div><span class="vhint">Críticos abertos&nbsp;</span><strong class="vnum" style="font-size:16px;color:#b3261e"><?= (int)($kpi['criticos'] ?? 0) ?></strong></div>
    <div><span class="vhint">Reconhecidos&nbsp;</span><strong class="vnum" style="font-size:16px"><?= (int)($kpi['reconhecidos'] ?? 0) ?></strong></div>
    <div style="margin-left:auto"><span class="vhint">Resolvidos&nbsp;</span><strong class="vnum" style="font-size:16px;color:var(--vero-ok,#1a7f4b)"><?= (int)($kpi['resolvidos'] ?? 0) ?></strong></div>
  </div>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="status" onchange="this.form.submit()">
          <option value="ativos"<?= $fStatus === 'ativos' ? ' selected' : '' ?>>Ativos (abertos + reconhecidos)</option>
          <option value="aberto"<?= $fStatus === 'aberto' ? ' selected' : '' ?>>Só abertos</option>
          <option value="reconhecido"<?= $fStatus === 'reconhecido' ? ' selected' : '' ?>>Só reconhecidos</option>
          <option value="resolvido"<?= $fStatus === 'resolvido' ? ' selected' : '' ?>>Resolvidos</option>
        </select>
        <select name="talhao" onchange="this.form.submit()">
          <option value="">Todas as válvulas</option>
          <?php foreach ($talhoes as $tl): ?>
            <option value="<?= (int)$tl['id'] ?>"<?= $fTalhao === (int)$tl['id'] ? ' selected' : '' ?>>
              <?= h(($tl['fazenda'] ? $tl['fazenda'] . ' — ' : '') . $tl['codigo']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= count($alertas) ?> alerta(s)</span>
    </div>

    <?php if (!$alertas): ?>
      <div class="vempty">Nenhum alerta no filtro — os alertas nascem dos monitoramentos que atingem o nível de ação.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Data</th><th>Severidade</th><th>Válvula</th><th>Alerta</th>
        <th>Status</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($alertas as $al): ?>
        <tr<?= $al['status'] === 'resolvido' ? ' style="opacity:.6"' : '' ?>>
          <td class="vnum"><?= $al['data'] ? date('d/m/Y', strtotime((string)$al['data'])) : '—' ?></td>
          <td><?= $badgeSev((string)$al['severidade']) ?></td>
          <td><?= h(trim(($al['fazenda'] ?? '') . ' — ' . ($al['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td><strong><?= h($al['titulo'] ?? '—') ?></strong>
            <?= $al['mensagem'] ? '<div class="vhint">' . h((string)$al['mensagem']) . '</div>' : '' ?>
            <?php if ((int)$al['requer_validacao_tecnica'] === 1): ?>
              <span class="vbadge vb-warn">validação do RT</span>
            <?php endif; ?>
            <?php foreach ($acoesPorAlerta[(int)$al['id']] ?? [] as $aa): ?>
              <div class="vhint" style="margin-top:4px;border-left:2px solid #005059;padding-left:6px">
                <strong>Ação registrada</strong><?= $aa['autor'] ? ' por ' . h((string)$aa['autor']) : '' ?>:
                <?= h((string)$aa['acao']) ?>
                <?= $aa['aplicacao_id'] ? ' — <a href="' . BIOS_BASE . '/mip/aplicacoes?ver=' . (int)$aa['aplicacao_id'] . '">aplicação #' . (int)$aa['aplicacao_id'] . '</a>' : '' ?>
              </div>
            <?php endforeach; ?></td>
          <td><?php if ($al['status'] === 'aberto'): ?>
                <span class="vbadge vb-off">Aberto</span>
              <?php elseif ($al['status'] === 'reconhecido'): ?>
                <span class="vbadge vb-warn">Reconhecido</span>
                <?= $al['reconhecedor'] ? '<div class="vhint">' . h((string)$al['reconhecedor']) . '</div>' : '' ?>
              <?php else: ?>
                <span class="vbadge vb-ok">Resolvido</span>
              <?php endif; ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar && $al['status'] === 'aberto'): ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="reconhecer">
                <input type="hidden" name="id" value="<?= (int)$al['id'] ?>">
                <button class="vicon vicon-acao" type="submit" title="Reconhecer" aria-label="Reconhecer"><?= vero_ico_olho() ?></button>
              </form>
            <?php endif; ?>
            <?php if ($podeEditar && in_array($al['status'], ['aberto', 'reconhecido'], true)): ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="resolver">
                <input type="hidden" name="id" value="<?= (int)$al['id'] ?>">
                <button class="vicon vicon-acao" type="submit" title="Resolver" aria-label="Resolver"><?= vero_ico_check() ?></button>
              </form>
              <?= vero_btn_icone(vero_ico_lapis(), 'Registrar ação', 'mipAcaoAbrir(' . (int)$al['id'] . ')') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<!-- Modal: registrar ação/decisão do RT (mip_alerta_acoes) -->
<div class="vmodal" id="vm-acao">
  <div class="vbox">
    <header>
      <h2>Registrar ação tomada</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-acao')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="registrar_acao">
      <input type="hidden" name="id" id="acao-alerta-id" value="">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('acao_texto', 'Ação / decisão do RT', '', true, '') ?></div>
        <div class="full"><?= vero_f_select('aplicacao_id', 'Aplicação vinculada (opcional)', $aplicacoesOpt, null, false, '— Nenhuma —') ?></div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-acao')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Registrar</button>
      </div>
    </form>
  </div>
</div>
<script>
function mipAcaoAbrir(id) {
  document.getElementById('acao-alerta-id').value = String(id);
  vModalOpen('vm-acao');
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
