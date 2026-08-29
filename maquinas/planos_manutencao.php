<?php
/* ============================================================
   VERO — Máquinas / Planos de Manutenção Preventiva (A2-F2-4, DB-11)
   Rota: /maquinas/planos_manutencao.php (link na tela de Manutenções)
   Guard: maquinas.manutencao_preventiva (mesmo micro da manutenção —
   slug/menu próprios pendem de rodada de globais do A0, ver TASK_BOARD)
   Plano por máquina: intervalo em HORAS e/ou DIAS (vence o primeiro),
   antecedência de alerta, referência da última execução. Alertas
   idempotentes na categoria `maquinas` (agro_alertas) reemitidos a
   cada leitura de horímetro/execução de OS.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';

const T = 'maquina_planos_manutencao';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('maquinas.manutencao_preventiva.editar');
        $id        = vero_int('id');
        $maquinaId = vero_int('maquina_id');
        $descricao = vero_str('descricao', 255);
        $intHoras  = vero_dec('intervalo_horas');
        $intDias   = vero_int('intervalo_dias');
        $okMaq = $maquinaId ? vero_val("SELECT id FROM maquinas WHERE id=:i AND tenant_id=:t",
            [':i' => $maquinaId, ':t' => vero_tenant()]) : null;
        if (!$okMaq || $descricao === null) {
            vero_flash('erro', 'Máquina e descrição do plano são obrigatórias.');
            vero_redirect();
        }
        if (($intHoras === null || $intHoras <= 0) && ($intDias === null || $intDias <= 0)) {
            vero_flash('erro', 'Informe o intervalo em horas de horímetro E/OU em dias (o que vencer primeiro dispara o alerta).');
            vero_redirect();
        }
        /* A11: antecedência e leitura de horímetro nunca são negativas */
        $antecHoras = vero_dec('antecedencia_horas') ?? 20;
        $horimUltima = vero_dec('horimetro_ultima');
        if ($antecHoras < 0 || ($horimUltima !== null && $horimUltima < 0)) {
            vero_flash('erro', 'Antecedência e horímetro não podem ser negativos.');
            vero_redirect();
        }
        $dados = [
            'maquina_id'        => $maquinaId,
            'descricao'         => $descricao,
            'intervalo_horas'   => $intHoras > 0 ? $intHoras : null,
            'intervalo_dias'    => $intDias > 0 ? $intDias : null,
            'antecedencia_horas'=> $antecHoras,
            'antecedencia_dias' => vero_int('antecedencia_dias') ?? 7,
            'horimetro_ultima'  => $horimUltima,
            'data_ultima'       => vero_date('data_ultima'),
            'ativo'             => vero_int('ativo') ?? 1,
        ];
        if ($id) {
            $ok = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => vero_tenant()]);
            if ($ok) { vero_update(T, $id, $dados); vero_flash('ok', 'Plano atualizado.'); }
        } else {
            vero_insert(T, $dados);
            vero_flash('ok', 'Plano criado — o alerta dispara na antecedência configurada.');
        }
        vero_srv_maquina_reemitir_alertas((int)$maquinaId);
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('maquinas.manutencao_preventiva.excluir');
        $id = vero_int('id');
        $pl = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if ($pl) {
            vero_delete(T, (int)$id); /* soft delete (tem `ativo`) */
            vero_srv_maquina_reemitir_alertas((int)$pl['maquina_id']);
            vero_flash('ok', 'Plano inativado — alertas removidos.');
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT pl.*, m.codigo AS maq_codigo, m.nome AS maq_nome, m.horimetro_atual,
            (SELECT COUNT(*) FROM agro_alertas al
              WHERE al.tenant_id = pl.tenant_id AND al.categoria = 'maquinas'
                AND al.origem_tipo = 'maquina_plano' AND al.origem_id = pl.id AND al.status = 'aberto') AS alertas
       FROM " . T . " pl
       JOIN maquinas m ON m.id = pl.maquina_id
      WHERE pl.tenant_id = :t
      ORDER BY pl.ativo DESC, m.nome, pl.descricao", [':t' => vero_tenant()]);

$maquinas = vero_options('maquinas', 'nome', 'ativo = 1');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'maquinas', 'micro' => 'manutencao_preventiva'];
$PAGE_VIEW  = 'maquinas_planos_manutencao';
$PAGE_TITLE = 'Planos de Manutenção';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('maquinas.manutencao_preventiva.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Planos de Manutenção Preventiva',
        'Intervalo por horímetro e/ou calendário (vence o primeiro) — alertas automáticos na antecedência',
        $podeEditar ? '+ Novo plano' : null) ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Planos</strong>
      <span class="vsub"><?= count($rows) ?> plano(s)</span>
      <div style="flex:1"></div>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/maquinas/manutencao.php">← Manutenções</a></div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum plano — crie um para receber alertas de revisão (ex.: "Revisão 250 h").</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Máquina</th><th>Plano</th>
        <th style="text-align:right">Intervalo</th>
        <th style="text-align:right">Última execução</th>
        <th style="text-align:right">Próxima</th>
        <th>Situação</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $proxH = $r['intervalo_horas'] !== null
              ? (float)($r['horimetro_ultima'] ?? 0) + (float)$r['intervalo_horas'] : null;
          $faltamH = $proxH !== null ? $proxH - (float)$r['horimetro_atual'] : null;
          $proxD = ($r['intervalo_dias'] !== null && $r['data_ultima'] !== null)
              ? strtotime((string)$r['data_ultima'] . ' +' . (int)$r['intervalo_dias'] . ' days') : null;
      ?>
        <tr<?= (int)$r['ativo'] !== 1 ? ' style="opacity:.55"' : '' ?>>
          <td><strong><?= h($r['maq_codigo'] . ' — ' . $r['maq_nome']) ?></strong>
            <div class="vhint">horímetro atual <?= numFmt((float)$r['horimetro_atual'], 1) ?> h</div></td>
          <td><?= h($r['descricao']) ?></td>
          <td class="vnum" style="text-align:right">
            <?= $r['intervalo_horas'] !== null ? numFmt((float)$r['intervalo_horas'], 0) . ' h' : '' ?>
            <?= $r['intervalo_horas'] !== null && $r['intervalo_dias'] !== null ? ' · ' : '' ?>
            <?= $r['intervalo_dias'] !== null ? (int)$r['intervalo_dias'] . ' d' : '' ?></td>
          <td class="vnum" style="text-align:right">
            <?= $r['horimetro_ultima'] !== null ? numFmt((float)$r['horimetro_ultima'], 1) . ' h' : '—' ?>
            <?= $r['data_ultima'] !== null ? '<div class="vhint">' . date('d/m/Y', strtotime((string)$r['data_ultima'])) . '</div>' : '' ?></td>
          <td class="vnum" style="text-align:right">
            <?= $proxH !== null ? numFmt($proxH, 1) . ' h' : '' ?>
            <?= $proxD !== null ? '<div class="vhint">' . date('d/m/Y', $proxD) . '</div>' : '' ?></td>
          <td><?php
            if ((int)$r['ativo'] !== 1) echo '<span class="vbadge vb-off">Inativo</span>';
            elseif ((int)$r['alertas'] > 0) echo '<span class="vbadge vb-warn">revisão pendente</span>';
            elseif ($faltamH !== null) echo '<span class="vbadge vb-ok">faltam ' . numFmt(max(0, $faltamH), 1) . ' h</span>';
            else echo '<span class="vbadge vb-ok">em dia</span>';
          ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('maquinas.manutencao_preventiva.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este plano? Os alertas dele serão removidos.') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header><h2><?= $edit ? 'Editar plano' : 'Novo plano preventivo' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button></header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_select('maquina_id', 'Máquina', $maquinas, $edit['maquina_id'] ?? null, true) ?>
        <?= vero_f_text('descricao', 'Descrição do plano', $edit['descricao'] ?? '', true, 'ex.: Revisão 250 h — óleo e filtros') ?>
        <?= vero_f_text('intervalo_horas', 'Intervalo (horas de horímetro)',
              $edit && $edit['intervalo_horas'] !== null ? numFmt((float)$edit['intervalo_horas'], 0) : '', false, 'ex.: 250') ?>
        <?= vero_f_text('intervalo_dias', 'Intervalo (dias)',
              $edit && $edit['intervalo_dias'] !== null ? (string)(int)$edit['intervalo_dias'] : '', false, 'ex.: 180 — vence o que chegar primeiro') ?>
        <?= vero_f_text('antecedencia_horas', 'Alertar faltando (horas)',
              $edit ? numFmt((float)($edit['antecedencia_horas'] ?? 20), 0) : '20', false) ?>
        <?= vero_f_text('antecedencia_dias', 'Alertar faltando (dias)',
              $edit ? (string)(int)($edit['antecedencia_dias'] ?? 7) : '7', false) ?>
        <?= vero_f_text('horimetro_ultima', 'Horímetro da última execução',
              $edit && $edit['horimetro_ultima'] !== null ? numFmt((float)$edit['horimetro_ultima'], 1) : '', false, 'atualizado automaticamente ao executar OS do plano') ?>
        <div class="vfield"><label>Data da última execução</label>
          <input type="date" name="data_ultima" value="<?= h((string)($edit['data_ultima'] ?? '')) ?>"></div>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativo', 0 => 'Inativo'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar plano</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
