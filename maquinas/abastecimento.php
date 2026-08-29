<?php
/* ============================================================
   VERO — Máquinas / Abastecimentos  (tela real)
   Substitui o mock. Rota: /maquinas/abastecimento.php
   Guard: maquinas.abastecimentos
   Tabela: maquina_abastecimentos. Cada lançamento:
   - atualiza o horímetro da máquina (+ leitura em maquina_horimetros);
   - emite custo em custeio_lancamentos (origem maquina_abastecimento,
     categoria maquinas, centro MAQ) — removido na exclusão.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/../custeio/_plano_map.php'; /* A3-T10: plano de contas no custeio */

const T = 'maquina_abastecimentos';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('maquinas.abastecimentos.editar');

        $maquinaId = vero_int('maquina_id');
        $data      = vero_date('data_abastecimento');
        $litros    = vero_dec('litros');
        $valor     = vero_dec('valor_total');
        $horimetro = vero_dec('horimetro');

        $maquina = $maquinaId ? vero_row("SELECT * FROM maquinas WHERE id=:i AND tenant_id=:t",
            [':i' => $maquinaId, ':t' => vero_tenant()]) : null;
        if (!$maquina || $data === null || $litros === null || $litros <= 0 || $valor === null || $valor < 0) {
            vero_flash('erro', 'Máquina, data, litros e valor são obrigatórios.');
            vero_redirect();
        }
        if ($horimetro !== null && $horimetro < (float)$maquina['horimetro_atual']) {
            vero_flash('erro', 'Horímetro informado (' . numFmt($horimetro, 1) . 'h) é menor que o atual da máquina ('
                . numFmt((float)$maquina['horimetro_atual'], 1) . 'h).');
            vero_redirect();
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $abId = vero_insert(T, [
                'maquina_id'         => $maquinaId,
                'litros'             => $litros,
                'valor_total'        => round($valor, 2),
                'horimetro'          => $horimetro,
                'data_abastecimento' => $data . ' 00:00:00',
            ]);
            if ($horimetro !== null) {
                vero_update('maquinas', $maquinaId, ['horimetro_atual' => $horimetro]);
                vero_insert('maquina_horimetros', [
                    'maquina_id' => $maquinaId, 'data_leitura' => $data, 'horimetro' => $horimetro,
                ]);
                vero_srv_maquina_reemitir_alertas((int)$maquinaId); /* planos preventivos (A2-F2-4) */
            }
            if ($valor > 0) {
                vero_insert('custeio_lancamentos', [
                    'centro_custo_id' => vero_srv_centro_custo('MAQ', 'Máquinas'),
                    'plano_conta_id'  => custeio_plano_conta_id('maquina_abastecimento'),
                    'categoria'       => 'maquinas',
                    'origem_tipo'     => 'maquina_abastecimento',
                    'origem_id'       => $abId,
                    'valor'           => round($valor, 2),
                    'quantidade'      => $litros,
                    'data_competencia'=> $data,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao salvar: ' . h($e->getMessage()));
            vero_redirect();
        }
        vero_flash('ok', 'Abastecimento registrado — ' . numFmt($litros, 1) . ' L por R$ ' . numFmt($valor, 2)
            . ' lançado no custeio (categoria máquinas).');
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('maquinas.abastecimentos.excluir');
        $id = vero_int('id');
        $ab = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if ($ab) {
            $pdo = vero_pdo();
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM custeio_lancamentos
                                WHERE tenant_id=? AND origem_tipo='maquina_abastecimento' AND origem_id=?")
                    ->execute([vero_tenant(), (int)$id]);
                $pdo->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")
                    ->execute([vero_tenant(), (int)$id]);
                $pdo->commit();
                vero_flash('ok', 'Abastecimento excluído (custeio removido; horímetro da máquina não é revertido).');
            } catch (Throwable $e) {
                $pdo->rollBack();
                vero_flash('erro', 'Erro ao excluir: ' . h($e->getMessage()));
            }
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$fMaquina = (int)($_GET['maquina'] ?? 0);
$page     = max(1, (int)($_GET['pg'] ?? 1));
$perPage  = 20;

$where  = "a.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($fMaquina > 0) { $where .= " AND a.maquina_id = :m"; $params[':m'] = $fMaquina; }

$tot = vero_row("SELECT COUNT(*) AS linhas, COALESCE(SUM(a.litros),0) AS litros,
                        COALESCE(SUM(a.valor_total),0) AS valor
                   FROM " . T . " a WHERE {$where}", $params);
$rows = vero_rows(
    "SELECT a.*, m.codigo AS maq_codigo, m.nome AS maq_nome
       FROM " . T . " a
       LEFT JOIN maquinas m ON m.id = a.maquina_id
      WHERE {$where}
      ORDER BY a.data_abastecimento DESC, a.id DESC
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

$maquinas = vero_rows("SELECT id, codigo, nome, horimetro_atual FROM maquinas
                        WHERE tenant_id = :t AND ativo = 1 ORDER BY codigo", [':t' => vero_tenant()]);

$GUARD      = ['macro' => 'maquinas', 'micro' => 'abastecimentos'];
$PAGE_VIEW  = 'maquinas_abastecimentos';
$PAGE_TITLE = 'Abastecimentos';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('maquinas.abastecimentos.editar');
/* P-75 (CSO): valores em R$ só com o proxy financeiro; litros/horímetro visíveis. */
$veCusto = function_exists('vero_can') ? vero_can('financeiro.dre_agro.ver') : true;
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Abastecimentos', 'Combustível por máquina com horímetro — cada lançamento vira custo (categoria máquinas)',
        $podeEditar ? '+ Novo abastecimento' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="maquina" onchange="this.form.submit()">
          <option value="">Todas as máquinas</option>
          <?php foreach ($maquinas as $m): ?>
            <option value="<?= (int)$m['id'] ?>"<?= $fMaquina === (int)$m['id'] ? ' selected' : '' ?>>
              <?= h($m['codigo'] . ' — ' . $m['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= (int)$tot['linhas'] ?> registro(s) ·
        <?= numFmt((float)$tot['litros'], 1) ?> L ·
        <strong class="vnum">R$ <?= $veCusto ? numFmt((float)$tot['valor'], 2) : '•••' ?></strong></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum abastecimento<?= !$maquinas ? ' — cadastre uma máquina primeiro' : '' ?>.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Máquina</th>
        <th style="text-align:right">Litros</th>
        <th style="text-align:right">Valor (R$)</th>
        <th style="text-align:right">R$/L</th>
        <th style="text-align:right">Horímetro (h)</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_abastecimento'])) ?></td>
          <td><strong><?= h(($r['maq_codigo'] ? $r['maq_codigo'] . ' — ' : '') . ($r['maq_nome'] ?? '—')) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['litros'], 1) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= $veCusto ? numFmt((float)$r['valor_total'], 2) : '•••' ?></strong></td>
          <td class="vnum" style="text-align:right"><?= !$veCusto ? '•••' : ((float)$r['litros'] > 0 ? numFmt((float)$r['valor_total'] / (float)$r['litros'], 2) : '—') ?></td>
          <td class="vnum" style="text-align:right"><?= $r['horimetro'] !== null ? numFmt((float)$r['horimetro'], 1) : '—' ?></td>
          <td><div class="vactions">
            <?php if (vero_can('maquinas.abastecimentos.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este abastecimento? O lançamento de custeio será removido.') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, (int)$tot['linhas'], $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal" id="vm-form">
  <div class="vbox">
    <header>
      <h2>Novo abastecimento</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <div class="vgrid">
        <div class="vfield full">
          <label>Máquina *</label>
          <select name="maquina_id" id="ab-maquina" required>
            <option value="">— Selecione —</option>
            <?php foreach ($maquinas as $m): ?>
              <option value="<?= (int)$m['id'] ?>" data-horimetro="<?= h((string)$m['horimetro_atual']) ?>">
                <?= h($m['codigo'] . ' — ' . $m['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="vhint" id="ab-hint"></div>
        </div>
        <div class="vfield">
          <label>Data *</label>
          <input type="date" name="data_abastecimento" required value="<?= date('Y-m-d') ?>">
        </div>
        <?= vero_f_text('litros', 'Litros *', '', true) ?>
        <?= vero_f_text('valor_total', 'Valor total (R$) *', '', true) ?>
        <?= vero_f_text('horimetro', 'Horímetro na bomba (h)', '', false, 'Atualiza o horímetro da máquina') ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Registrar</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('ab-maquina').addEventListener('change', function () {
  const opt = this.selectedOptions[0];
  document.getElementById('ab-hint').textContent =
    opt && opt.dataset.horimetro ? 'Horímetro atual: ' + opt.dataset.horimetro + ' h' : '';
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
