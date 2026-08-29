<?php
/* ============================================================
   VERO — Custos / Orçamento de Safra  (tela real)
   Substitui o mock. Rota: /custeio/orcamento.php
   Guard: custos.orcamento_safra
   Um orçamento por safra (custeio_orcamentos) com itens por
   categoria (custeio_orcamento_itens.valor_previsto). Ciclo:
   rascunho → vigente → encerrado. O comparativo com o realizado
   fica em Custos → Realizado vs Planejado.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const CATEGORIAS_ORC = [
    'mao_de_obra' => 'Mão de obra',
    'insumos'     => 'Insumos',
    'maquinas'    => 'Máquinas',
    'irrigacao'   => 'Irrigação',
    'outros'      => 'Outros',
];

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('custeio.orcamento_safra.editar');
        $id      = vero_int('id');
        $safraId = vero_int('safra_id');
        if (!$safraId) {
            vero_flash('erro', 'Selecione a safra.');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $orc = vero_row("SELECT * FROM custeio_orcamentos WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]);
                if (!$orc) throw new RuntimeException('Orçamento inválido.');
                if ($orc['status'] === 'encerrado') throw new RuntimeException('Orçamento encerrado não pode ser editado.');
            } else {
                $ja = vero_val("SELECT id FROM custeio_orcamentos WHERE tenant_id=:t AND safra_id=:s AND status <> 'encerrado'",
                    [':t' => $t, ':s' => $safraId]);
                if ($ja) throw new RuntimeException('Esta safra já tem orçamento ativo — edite-o ou encerre-o antes.');
                $id = vero_insert('custeio_orcamentos', ['safra_id' => $safraId, 'valor_total' => 0, 'status' => 'rascunho']);
            }

            $total = 0.0;
            $pdo->prepare("DELETE FROM custeio_orcamento_itens WHERE tenant_id = ? AND orcamento_id = ?")
                ->execute([$t, (int)$id]);
            $ins = $pdo->prepare(
                "INSERT INTO custeio_orcamento_itens (tenant_id, orcamento_id, categoria, valor_previsto, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())");
            foreach (array_keys(CATEGORIAS_ORC) as $cat) {
                $v = vero_dec('prev_' . $cat) ?? 0.0;
                if ($v <= 0) continue;
                $ins->execute([$t, (int)$id, $cat, $v]);
                $total += $v;
            }
            vero_update('custeio_orcamentos', (int)$id, ['safra_id' => $safraId, 'valor_total' => $total]);
            $pdo->commit();
            vero_flash('ok', 'Orçamento salvo — total previsto R$ ' . numFmt($total, 2) . '.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if (in_array($acao, ['vigente', 'encerrar', 'reabrir'], true)) {
        vero_require('custeio.orcamento_safra.editar');
        $id = vero_int('id');
        $orc = $id ? vero_row("SELECT * FROM custeio_orcamentos WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if ($orc) {
            $novo = ['vigente' => 'vigente', 'encerrar' => 'encerrado', 'reabrir' => 'rascunho'][$acao];
            if ($acao === 'vigente') {
                /* só um vigente por safra */
                vero_pdo()->prepare("UPDATE custeio_orcamentos SET status='encerrado', updated_at=NOW()
                                      WHERE tenant_id=? AND safra_id=? AND status='vigente' AND id<>?")
                    ->execute([$t, (int)$orc['safra_id'], (int)$id]);
            }
            vero_update('custeio_orcamentos', (int)$id, ['status' => $novo]);
            vero_flash('ok', 'Orçamento ' . ($novo === 'vigente' ? 'tornado vigente' : ($novo === 'encerrado' ? 'encerrado' : 'reaberto como rascunho')) . '.');
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('custeio.orcamento_safra.excluir');
        $id = vero_int('id');
        $orc = $id ? vero_row("SELECT * FROM custeio_orcamentos WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if ($orc && $orc['status'] === 'rascunho') {
            vero_pdo()->prepare("DELETE FROM custeio_orcamento_itens WHERE tenant_id=? AND orcamento_id=?")->execute([$t, (int)$id]);
            vero_pdo()->prepare("DELETE FROM custeio_orcamentos WHERE tenant_id=? AND id=?")->execute([$t, (int)$id]);
            vero_flash('ok', 'Rascunho de orçamento excluído.');
        } else {
            vero_flash('erro', 'Só rascunhos podem ser excluídos — encerre orçamentos vigentes.');
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT o.*, s.identificacao AS safra
       FROM custeio_orcamentos o
       LEFT JOIN agro_safras s ON s.id = o.safra_id
      WHERE o.tenant_id = :t
      ORDER BY FIELD(o.status,'vigente','rascunho','encerrado'), s.identificacao DESC", [':t' => $t]);

$edit = null;
$editItens = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM custeio_orcamentos WHERE id=:i AND tenant_id=:t AND status <> 'encerrado'",
        [':i' => (int)$_GET['editar'], ':t' => $t]);
    if ($edit) {
        foreach (vero_rows("SELECT categoria, SUM(valor_previsto) AS v FROM custeio_orcamento_itens
                             WHERE tenant_id=:t AND orcamento_id=:o GROUP BY categoria",
            [':t' => $t, ':o' => (int)$edit['id']]) as $r) {
            $editItens[(string)$r['categoria']] = (float)$r['v'];
        }
    }
}

$safras = vero_options('agro_safras', 'identificacao');

$GUARD      = ['macro' => 'custos', 'micro' => 'orcamento_safra'];
$PAGE_VIEW  = 'custos_orcamento_safra';
$PAGE_TITLE = 'Orçamento de Safra';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('custeio.orcamento_safra.editar');
$base = rtrim(BIOS_BASE, '/');
$badgeStatus = static fn(string $s): string => match ($s) {
    'vigente'   => '<span class="vbadge vb-ok">Vigente</span>',
    'encerrado' => '<span class="vbadge vb-off">Encerrado</span>',
    default     => '<span class="vbadge vb-warn">Rascunho</span>',
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Orçamento de Safra', 'Previsto por categoria para cada safra — o comparativo fica em Realizado vs Planejado',
        $podeEditar ? '+ Novo orçamento' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum orçamento — crie o da safra vigente para acompanhar o realizado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Safra</th>
        <th style="text-align:right">Total previsto (R$)</th>
        <th>Status</th><th>Criado em</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'encerrado' ? ' style="opacity:.6"' : '' ?>>
          <td><strong><?= h($r['safra'] ?? '—') ?></strong></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['valor_total'], 2) ?></strong></td>
          <td><?= $badgeStatus((string)$r['status']) ?></td>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['created_at'])) ?></td>
          <td><div class="vactions">
            <?= vero_btn_icone(vero_ico_olho(), 'Comparar (orçado × realizado)', '', $base . '/custeio/realizado?safra=' . (int)$r['safra_id']) ?>
            <?php if ($podeEditar && $r['status'] !== 'encerrado'): ?>
              <?= vero_btn_editar((int)$r['id']) ?>
              <?php if ($r['status'] === 'rascunho'): ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                  <input type="hidden" name="acao" value="vigente"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="vicon vicon-acao" type="submit" title="Tornar vigente" aria-label="Tornar vigente"><?= vero_ico_check() ?></button></form>
              <?php else: ?>
                <form method="post" data-confirm="Encerrar este orçamento?" data-confirm-ok="Encerrar" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                  <input type="hidden" name="acao" value="encerrar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="vicon vicon-acao" type="submit" title="Encerrar" aria-label="Encerrar"><?= vero_ico_x() ?></button></form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (vero_can('custeio.orcamento_safra.excluir') && $r['status'] === 'rascunho'): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este rascunho de orçamento?') ?>
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
    <header>
      <h2><?= $edit ? 'Editar orçamento' : 'Novo orçamento de safra' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_select('safra_id', 'Safra', $safras, $edit['safra_id'] ?? '', true, 'Selecione…') ?>
      </div>
      <div class="vfield" style="margin-top:10px">
        <label>Previsto por categoria (R$)</label>
        <table class="vtable">
          <tbody>
          <?php foreach (CATEGORIAS_ORC as $cat => $rotulo): ?>
            <tr>
              <td style="width:50%"><?= h($rotulo) ?></td>
              <td><input type="text" name="prev_<?= $cat ?>" placeholder="0,00" style="width:100%;text-align:right"
                         value="<?= isset($editItens[$cat]) ? numFmt($editItens[$cat], 2) : '' ?>"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="vhint">Deixe em branco (ou zero) as categorias sem previsão.</div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
