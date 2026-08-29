<?php
/* ============================================================
   VERO — Configurações / Auditoria  (tela real, leitura)
   Substitui o mock. Rota: /configuracoes/auditoria.php
   Guard: configuracoes.auditoria
   Trilha de autenticação e permissões (auth_audit_logs):
   logins, logouts, falhas e negações, com filtros.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fAcao   = (string)($_GET['acao'] ?? '');
$fStatus = (string)($_GET['status'] ?? '');
$fIni    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 30;

$where  = "l.tenant_id = :t";
$params = [':t' => $t];
if ($fAcao !== '')   { $where .= " AND l.acao = :a";   $params[':a'] = $fAcao; }
if ($fStatus !== '') { $where .= " AND l.status = :s"; $params[':s'] = $fStatus; }
if ($fIni !== '')    { $where .= " AND l.created_at >= :i"; $params[':i'] = $fIni . ' 00:00:00'; }
if ($fFim !== '')    { $where .= " AND l.created_at <= :f"; $params[':f'] = $fFim . ' 23:59:59'; }

$total = (int)vero_val("SELECT COUNT(*) FROM auth_audit_logs l WHERE {$where}", $params);
$rows = vero_rows(
    "SELECT l.*, u.nome AS usuario FROM auth_audit_logs l
       LEFT JOIN usuarios u ON u.id = l.user_id
      WHERE {$where}
      ORDER BY l.id DESC
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

$acoes = array_map('strval', array_column(vero_rows(
    "SELECT DISTINCT acao FROM auth_audit_logs WHERE tenant_id = :t ORDER BY acao", [':t' => $t]), 'acao'));
$statuses = array_map('strval', array_column(vero_rows(
    "SELECT DISTINCT status FROM auth_audit_logs WHERE tenant_id = :t ORDER BY status", [':t' => $t]), 'status'));

$GUARD      = ['macro' => 'configuracoes', 'micro' => 'auditoria'];
$PAGE_VIEW  = 'configuracoes_auditoria';
$PAGE_TITLE = 'Auditoria';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Auditoria', 'Trilha de autenticação e acessos — logins, logouts, falhas e negações de permissão', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="acao" onchange="this.form.submit()">
          <option value="">Todas as ações</option>
          <?php foreach ($acoes as $a): ?>
            <option value="<?= h($a) ?>"<?= $fAcao === $a ? ' selected' : '' ?>><?= h(str_replace('_', ' ', $a)) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
          <option value="">Todos os status</option>
          <?php foreach ($statuses as $s): ?>
            <option value="<?= h($s) ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= h(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub"><?= $total ?> evento(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum evento no filtro.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data/hora</th><th>Usuário</th><th>Ação</th><th>Status</th><th>IP</th><th>Detalhes</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="vnum"><strong><?= date('d/m/Y H:i', strtotime((string)$r['created_at'])) ?></strong></td>
          <td><?= h($r['usuario'] ?? $r['email'] ?? '—') ?>
            <?= $r['usuario'] && $r['email'] ? '<div class="vhint">' . h((string)$r['email']) . '</div>' : '' ?></td>
          <td><span class="vbadge vb-info"><?= h(str_replace('_', ' ', (string)$r['acao'])) ?></span></td>
          <td><?= $r['status'] === 'sucesso'
                ? '<span class="vbadge vb-ok">Sucesso</span>'
                : '<span class="vbadge vb-off">' . h(ucfirst((string)$r['status'])) . '</span>' ?></td>
          <td class="vnum"><?= h($r['ip'] ?? '—') ?></td>
          <td class="vhint"><?= h(mb_substr((string)($r['detalhes'] ?? ''), 0, 80)) ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      O razão financeiro tem trilha própria (hash encadeado por lançamento); alterações de cadastro guardam
      created_by/updated_by em cada tabela.
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
