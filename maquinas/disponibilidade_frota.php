<?php
/* ============================================================
   VERO — Máquinas / Disponibilidade da Frota  (tela real)
   Substitui o mock. Rota: /maquinas/disponibilidade_frota.php
   Guard: maquinas.disponibilidade_frota
   Status da frota (ativa/manutenção/inativa) com troca rápida de
   status e manutenções abertas por máquina.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'status') {
        vero_require('maquinas.disponibilidade_frota.editar');
        $id = vero_int('id');
        $novo = (string)($_POST['status'] ?? '');
        $maq = $id ? vero_row("SELECT * FROM maquinas WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if ($maq && in_array($novo, ['ativa', 'manutencao', 'inativa'], true)) {
            vero_update('maquinas', (int)$id, ['status' => $novo]);
            vero_flash('ok', $maq['nome'] . ' agora está "' . $novo . '".');
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT m.*, f.nome AS fazenda,
            (SELECT COUNT(*) FROM maquina_manutencoes mn
              WHERE mn.tenant_id = m.tenant_id AND mn.maquina_id = m.id AND mn.status = 'aberta') AS manut_abertas,
            (SELECT MAX(ab.data_abastecimento) FROM maquina_abastecimentos ab
              WHERE ab.tenant_id = m.tenant_id AND ab.maquina_id = m.id) AS ultimo_abastecimento
       FROM maquinas m
       LEFT JOIN agro_fazendas f ON f.id = m.fazenda_id
      WHERE m.tenant_id = :t AND m.ativo = 1
      ORDER BY FIELD(m.status,'manutencao','ativa','inativa'), m.codigo", [':t' => $t]);

$kpi = ['ativa' => 0, 'manutencao' => 0, 'inativa' => 0];
foreach ($rows as $r) $kpi[(string)$r['status']] = ($kpi[(string)$r['status']] ?? 0) + 1;
$totFrota = count($rows);
$disp = $totFrota > 0 ? $kpi['ativa'] / $totFrota * 100 : 0;

$GUARD      = ['macro' => 'maquinas', 'micro' => 'disponibilidade_frota'];
$PAGE_VIEW  = 'maquinas_disponibilidade_frota';
$PAGE_TITLE = 'Disponibilidade da Frota';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('maquinas.disponibilidade_frota.editar');
$base = rtrim(BIOS_BASE, '/');
$badgeSt = static fn(string $s): string => match ($s) {
    'ativa'      => '<span class="vbadge vb-ok">Ativa</span>',
    'manutencao' => '<span class="vbadge vb-warn">Em manutenção</span>',
    default      => '<span class="vbadge vb-off">Inativa</span>',
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Disponibilidade da Frota', 'Status operacional das máquinas e manutenções em aberto', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Disponibilidade</span>
        <strong class="vnum" style="font-size:1.25rem;color:<?= $disp >= 80 ? 'var(--vero-ok,#1a7f4b)' : '#b3261e' ?>">
          <?= numFmt($disp, 0) ?>%</strong></div>
      <div class="vkpi"><span class="vhint">Ativas</span>
        <strong class="vnum" style="font-size:1.25rem;color:var(--vero-ok,#1a7f4b)"><?= $kpi['ativa'] ?></strong></div>
      <div class="vkpi"><span class="vhint">Em manutenção</span>
        <strong class="vnum" style="font-size:1.25rem;color:#8A6D1A"><?= $kpi['manutencao'] ?></strong></div>
      <div class="vkpi"><span class="vhint">Inativas</span>
        <strong class="vnum" style="font-size:1.25rem"><?= $kpi['inativa'] ?></strong></div>
    </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Frota</strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/maquinas/manutencao.php">Manutenções</a></div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma máquina cadastrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Máquina</th><th>Fazenda</th><th>Status</th>
        <th style="text-align:right">Manut. abertas</th>
        <th>Último abastecimento</th>
        <th style="text-align:right">Horímetro (h)</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong class="vnum"><?= h($r['codigo']) ?></strong> <?= h($r['nome']) ?>
            <span class="vhint"><?= h((string)($r['tipo'] ?? '')) ?></span></td>
          <td><?= h($r['fazenda'] ?? '—') ?></td>
          <td><?= $badgeSt((string)$r['status']) ?></td>
          <td class="vnum" style="text-align:right;<?= (int)$r['manut_abertas'] > 0 ? 'color:#b3261e;font-weight:700' : '' ?>">
            <?= (int)$r['manut_abertas'] ?></td>
          <td class="vnum"><?= $r['ultimo_abastecimento'] ? date('d/m/Y', strtotime((string)$r['ultimo_abastecimento'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $r['horimetro_atual'] !== null ? numFmt((float)$r['horimetro_atual'], 1) : '—' ?></td>
          <td><div class="vactions">
            <?= vero_btn_icone(vero_ico_olho(), 'Ficha consolidada da máquina', '', $base . '/maquinas/ficha_maquina?id=' . (int)$r['id']) ?>
            <?php if ($podeEditar): foreach (['ativa' => 'Ativar', 'manutencao' => 'Manutenção', 'inativa' => 'Inativar'] as $st => $rotulo):
                if ($st === $r['status']) continue; ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="status">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="status" value="<?= $st ?>">
                <button class="vbtn vbtn-ghost vbtn-sm" type="submit"><?= $rotulo ?></button>
              </form>
            <?php endforeach; endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
