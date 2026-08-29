<?php
/* ============================================================
   VERO — Estoque / Alertas de Estoque  (tela real)
   Substitui o mock. Rota: /estoque/alertas.php
   Guard: estoque.estoque_critico (slug da matriz do menu - corrigido no A0-04/P-26; era estoque.alertas)
   Fila dos alertas categoria 'estoque' (mínimo e vencimento) com
   reconhecer/resolver — os alertas são reemitidos automaticamente
   a cada movimentação enquanto a condição persistir.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');
    if (in_array($acao, ['reconhecer', 'resolver'], true)) {
        vero_require('estoque.estoque_critico.editar');
        $id = vero_int('id');
        $alerta = $id ? vero_row("SELECT * FROM agro_alertas WHERE id=:i AND tenant_id=:t AND categoria='estoque'",
            [':i' => $id, ':t' => $t]) : null;
        if ($alerta) {
            vero_update('agro_alertas', (int)$id, [
                'status'          => $acao === 'resolver' ? 'resolvido' : 'reconhecido',
                'reconhecido_por' => vero_uid(),
                'reconhecido_em'  => date('Y-m-d H:i:s'),
            ]);
            vero_flash('ok', 'Alerta ' . ($acao === 'resolver' ? 'resolvido' : 'reconhecido') .
                ' — se a condição persistir, ele volta na próxima movimentação.');
        }
        vero_redirect();
    }
}

$fStatus = (string)($_GET['status'] ?? 'ativos');
$where  = "al.tenant_id = :t AND al.categoria = 'estoque'";
$params = [':t' => $t];
if ($fStatus === 'ativos') { $where .= " AND al.status IN ('aberto','reconhecido')"; }
elseif (in_array($fStatus, ['aberto', 'reconhecido', 'resolvido'], true)) {
    $where .= " AND al.status = :st"; $params[':st'] = $fStatus;
}

$rows = vero_rows(
    "SELECT al.*, u.nome AS reconhecedor FROM agro_alertas al
       LEFT JOIN usuarios u ON u.id = al.reconhecido_por
      WHERE {$where}
      ORDER BY (al.status='aberto') DESC, FIELD(al.severidade,'critico','atencao','info'), al.data DESC
      LIMIT 100", $params);

$kpi = vero_row(
    "SELECT SUM(status='aberto') AS abertos, SUM(status='aberto' AND severidade='critico') AS criticos
       FROM agro_alertas WHERE tenant_id = :t AND categoria = 'estoque'", [':t' => $t]);

$GUARD      = ['macro' => 'estoque', 'micro' => 'estoque_critico'];
$PAGE_VIEW  = 'estoque_alertas';
$PAGE_TITLE = 'Alertas de Estoque';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('estoque.estoque_critico.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Alertas de Estoque', 'Estoque mínimo e vencimento de lotes — reemitidos a cada movimentação enquanto persistirem', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <select name="status" onchange="this.form.submit()">
          <option value="ativos"<?= $fStatus === 'ativos' ? ' selected' : '' ?>>Ativos (abertos + reconhecidos)</option>
          <option value="aberto"<?= $fStatus === 'aberto' ? ' selected' : '' ?>>Só abertos</option>
          <option value="reconhecido"<?= $fStatus === 'reconhecido' ? ' selected' : '' ?>>Só reconhecidos</option>
          <option value="resolvido"<?= $fStatus === 'resolvido' ? ' selected' : '' ?>>Resolvidos</option>
        </select>
      </form>
      <span class="vsub"><?= (int)($kpi['abertos'] ?? 0) ?> aberto(s) ·
        <?= (int)($kpi['criticos'] ?? 0) ?> crítico(s) ·
        <a href="<?= $base ?>/estoque/produtos.php">produtos</a> · <a href="<?= $base ?>/estoque/lotes.php">lotes</a></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum alerta de estoque no filtro. ✓</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Severidade</th><th>Alerta</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $al): ?>
        <tr<?= $al['status'] === 'resolvido' ? ' style="opacity:.6"' : '' ?>>
          <td class="vnum"><?= $al['data'] ? date('d/m/Y', strtotime((string)$al['data'])) : '—' ?></td>
          <td><?= $al['severidade'] === 'critico' ? '<span class="vbadge vb-off">Crítico</span>'
                : ($al['severidade'] === 'atencao' ? '<span class="vbadge vb-warn">Atenção</span>'
                : '<span class="vbadge vb-info">Info</span>') ?></td>
          <td><strong><?= h($al['titulo'] ?? '—') ?></strong>
            <?= $al['mensagem'] ? '<div class="vhint">' . h((string)$al['mensagem']) . '</div>' : '' ?></td>
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
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      Resolver aqui não altera o estoque — reponha o produto ou trate o lote; enquanto a condição persistir,
      o alerta é reemitido automaticamente na próxima movimentação.
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
