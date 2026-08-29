<?php
/* ============================================================
   VERO — Dashboard / Indicadores e Alertas  (tela real)
   Substitui o mock. Rota: /dashboard/indicadores_alertas.php
   Guard: dashboard.indicadores_alertas
   Fila unificada de alertas de TODAS as categorias (estoque,
   nutrição, MIP) com reconhecer/resolver — espelha as filas dos
   módulos.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');
    if (in_array($acao, ['reconhecer', 'resolver'], true)) {
        vero_require('dashboard.indicadores_alertas.editar');
        $id = vero_int('id');
        $alerta = $id ? vero_row("SELECT * FROM agro_alertas WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
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
}

$fCategoria = (string)($_GET['categoria'] ?? '');
$fStatus    = (string)($_GET['status'] ?? 'ativos');

$where  = "al.tenant_id = :t";
$params = [':t' => $t];
if ($fCategoria !== '') { $where .= " AND al.categoria = :c"; $params[':c'] = $fCategoria; }
if ($fStatus === 'ativos') { $where .= " AND al.status IN ('aberto','reconhecido')"; }
elseif (in_array($fStatus, ['aberto', 'reconhecido', 'resolvido'], true)) {
    $where .= " AND al.status = :st"; $params[':st'] = $fStatus;
}

$porCategoria = vero_rows(
    "SELECT categoria, COUNT(*) AS total, SUM(severidade='critico') AS criticos
       FROM agro_alertas WHERE tenant_id = :t AND status = 'aberto'
      GROUP BY categoria ORDER BY criticos DESC, total DESC", [':t' => $t]);

$rows = vero_rows(
    "SELECT al.*, tl.codigo AS talhao, fz.nome AS fazenda, u.nome AS reconhecedor
       FROM agro_alertas al
       LEFT JOIN agro_talhoes tl ON tl.id = al.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = COALESCE(al.fazenda_id, tl.fazenda_id)
       LEFT JOIN usuarios u ON u.id = al.reconhecido_por
      WHERE {$where}
      ORDER BY (al.status='aberto') DESC, FIELD(al.severidade,'critico','atencao','info'), al.data DESC
      LIMIT 150", $params);

$categorias = array_map('strval', array_column(vero_rows(
    "SELECT DISTINCT categoria FROM agro_alertas WHERE tenant_id = :t ORDER BY categoria", [':t' => $t]), 'categoria'));

$rotaCategoria = [
    'estoque'  => '/estoque/alertas',
    'mip'      => '/mip/alertas_fitossanitarios',
    'nutricao' => '/nutricao/painel_nutrientes',
];

$GUARD      = ['macro' => 'dashboard', 'micro' => 'indicadores_alertas'];
$PAGE_VIEW  = 'dashboard_indicadores_alertas';
$PAGE_TITLE = 'Indicadores e Alertas';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('dashboard.indicadores_alertas.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Indicadores e Alertas', 'Fila unificada de alertas de todos os módulos — mesma ação das filas locais', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;padding:12px 14px">
      <?php if (!$porCategoria): ?>
        <div class="vkpi"><span class="vhint">Alertas abertos</span>
          <strong class="vnum" style="font-size:1.25rem;color:var(--vero-ok,#1a7f4b)">0 ✓</strong></div>
      <?php else: foreach ($porCategoria as $pc): ?>
        <div class="vkpi"><span class="vhint"><?= h(ucfirst((string)$pc['categoria'])) ?> abertos</span>
          <strong class="vnum" style="font-size:1.25rem;color:<?= (int)$pc['criticos'] > 0 ? '#b3261e' : '#8A6D1A' ?>">
            <?= (int)$pc['total'] ?></strong>
          <?= (int)$pc['criticos'] > 0 ? '<span class="vhint" style="color:#b3261e">' . (int)$pc['criticos'] . ' crítico(s)</span>' : '' ?></div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="categoria" onchange="this.form.submit()">
          <option value="">Todas as categorias</option>
          <?php foreach ($categorias as $c): ?>
            <option value="<?= h($c) ?>"<?= $fCategoria === $c ? ' selected' : '' ?>><?= h(ucfirst($c)) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
          <option value="ativos"<?= $fStatus === 'ativos' ? ' selected' : '' ?>>Ativos</option>
          <option value="aberto"<?= $fStatus === 'aberto' ? ' selected' : '' ?>>Só abertos</option>
          <option value="reconhecido"<?= $fStatus === 'reconhecido' ? ' selected' : '' ?>>Só reconhecidos</option>
          <option value="resolvido"<?= $fStatus === 'resolvido' ? ' selected' : '' ?>>Resolvidos</option>
        </select>
      </form>
      <span class="vsub"><?= count($rows) ?> alerta(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum alerta no filtro. ✓</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Categoria</th><th>Severidade</th><th>Alerta</th><th>Local</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $al): ?>
        <tr<?= $al['status'] === 'resolvido' ? ' style="opacity:.6"' : '' ?>>
          <td class="vnum"><?= $al['data'] ? date('d/m/Y', strtotime((string)$al['data'])) : '—' ?></td>
          <td><?php if (isset($rotaCategoria[(string)$al['categoria']])): ?>
              <a href="<?= $base . $rotaCategoria[(string)$al['categoria']] ?>" style="text-decoration:none">
                <span class="vbadge vb-info"><?= h(ucfirst((string)$al['categoria'])) ?></span></a>
            <?php else: ?>
              <span class="vbadge vb-info"><?= h(ucfirst((string)$al['categoria'])) ?></span>
            <?php endif; ?></td>
          <td><?= $al['severidade'] === 'critico' ? '<span class="vbadge vb-off">Crítico</span>'
                : ($al['severidade'] === 'atencao' ? '<span class="vbadge vb-warn">Atenção</span>'
                : '<span class="vbadge vb-info">Info</span>') ?></td>
          <td><strong><?= h($al['titulo'] ?? '—') ?></strong>
            <?= $al['mensagem'] ? '<div class="vhint">' . h(mb_substr((string)$al['mensagem'], 0, 90)) . '</div>' : '' ?></td>
          <td><?= h(trim(($al['fazenda'] ?? '') . ' — ' . ($al['talhao'] ?? ''), ' —') ?: '—') ?></td>
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
                <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Reconhecer</button>
              </form>
            <?php endif; ?>
            <?php if ($podeEditar && in_array($al['status'], ['aberto', 'reconhecido'], true)): ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="resolver">
                <input type="hidden" name="id" value="<?= (int)$al['id'] ?>">
                <button class="vbtn vbtn-primary vbtn-sm" type="submit">Resolver</button>
              </form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">Alertas de estoque e MIP são reemitidos automaticamente enquanto a condição persistir; os nutricionais dependem da validação do RT.</div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
