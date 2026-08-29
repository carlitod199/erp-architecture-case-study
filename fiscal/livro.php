<?php
/* ============================================================
   VERO — Fiscal / Livro Caixa do Produtor  (tela real)
   Substitui o mock. Rota: /fiscal/livro.php
   Guard: fiscal.livro_caixa_produtor
   Livro caixa (fiscal_livro_caixa): lançamentos manuais + geração
   a partir do razão pago do período (idempotente por histórico
   marcado [RAZAO#id]). Saldo corrente e impressão. Apuração
   oficial (IRPF atividade rural) é da contabilidade.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'fiscal_livro_caixa';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('fiscal.livro_caixa_produtor.editar');
        $id        = vero_int('id');
        $historico = vero_str('historico', 255);
        $valor     = vero_dec('valor');
        $dataL     = vero_date('data_lancamento');
        $tipo      = (string)($_POST['tipo'] ?? 'saida');
        if (!in_array($tipo, ['entrada', 'saida'], true)) $tipo = 'saida';
        if ($historico === null || $valor === null || $valor <= 0 || $dataL === null) {
            vero_flash('erro', 'Data, histórico e valor (maior que zero) são obrigatórios.');
            vero_redirect();
        }
        $data = [
            'data_lancamento' => $dataL, 'historico' => $historico,
            'tipo' => $tipo, 'valor' => $valor,
            'plano_conta_id' => vero_int('plano_conta_id') ?: null,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', 'Lançamento atualizado.'); }
        else     { vero_insert(T, $data);      vero_flash('ok', 'Lançamento registrado no livro.'); }
        vero_redirect();
    }

    if ($acao === 'gerar_razao') {
        vero_require('fiscal.livro_caixa_produtor.editar');
        $ini = vero_date('ini');
        $fim = vero_date('fim');
        if ($ini === null || $fim === null || $fim < $ini) {
            vero_flash('erro', 'Informe o período para gerar do razão.');
            vero_redirect();
        }
        $movs = vero_rows(
            "SELECT * FROM movimentacoes_financeiras
              WHERE tenant_id = :t AND status = 'pago' AND data_pagamento BETWEEN :i AND :f
              ORDER BY data_pagamento, id", [':t' => $t, ':i' => $ini, ':f' => $fim]);
        $gerados = 0; $pulados = 0;
        foreach ($movs as $m) {
            $marca = '[RAZAO#' . (int)$m['id'] . ']';
            $ja = vero_val("SELECT id FROM " . T . " WHERE tenant_id = :t AND historico LIKE :m",
                [':t' => $t, ':m' => '%' . $marca]);
            if ($ja) { $pulados++; continue; }
            vero_insert(T, [
                'data_lancamento' => (string)$m['data_pagamento'],
                'historico' => mb_substr((string)($m['descricao'] ?? 'Movimentação'), 0, 235) . ' ' . $marca,
                'tipo' => $m['tipo'] === 'receber' ? 'entrada' : 'saida',
                'valor' => (float)$m['valor'],
            ]);
            $gerados++;
        }
        vero_flash('ok', "Geração do razão: {$gerados} lançamento(s) criado(s), {$pulados} já existiam (não duplicados).");
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('fiscal.livro_caixa_produtor.excluir');
        $id = vero_int('id');
        if ($id) {
            vero_pdo()->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")->execute([$t, (int)$id]);
            vero_flash('ok', 'Lançamento removido do livro.');
        }
        vero_redirect();
    }
}

$fAno = (int)($_GET['ano'] ?? date('Y'));
if ($fAno < 2000 || $fAno > 2100) $fAno = (int)date('Y');

$rows = vero_rows(
    "SELECT l.*, pc.codigo AS conta_codigo, pc.nome AS conta_nome FROM " . T . " l
       LEFT JOIN plano_contas pc ON pc.id = l.plano_conta_id
      WHERE l.tenant_id = :t AND YEAR(l.data_lancamento) = :a
      ORDER BY l.data_lancamento, l.id", [':t' => $t, ':a' => $fAno]);

$totE = 0.0; $totS = 0.0;
foreach ($rows as $r) {
    if ($r['tipo'] === 'entrada') $totE += (float)$r['valor'];
    else $totS += (float)$r['valor'];
}

$anos = array_map('intval', array_column(vero_rows(
    "SELECT DISTINCT YEAR(data_lancamento) AS a FROM " . T . " WHERE tenant_id = :t ORDER BY a DESC", [':t' => $t]), 'a'));
if (!in_array($fAno, $anos, true)) $anos[] = $fAno;

$contas = [];
foreach (vero_rows("SELECT id, codigo, nome FROM plano_contas
    WHERE tenant_id = :t AND ativo = 1 AND aceita_lancamento = 1 ORDER BY codigo", [':t' => $t]) as $pc) {
    $contas[(int)$pc['id']] = $pc['codigo'] . ' — ' . $pc['nome'];
}

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => (int)$_GET['editar'], ':t' => $t]);
}

$GUARD      = ['macro' => 'fiscal', 'micro' => 'livro_caixa_produtor'];
$PAGE_VIEW  = 'fiscal_livro_caixa_produtor';
$PAGE_TITLE = 'Livro Caixa do Produtor';
$EXTRA_HEAD = vero_assets() . '<style media="print">.vsidebar,.no-print{display:none !important}</style>';
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('fiscal.livro_caixa_produtor.editar');
$empresa = (string)vero_val("SELECT nome FROM tenants WHERE id = :t", [':t' => $t]);
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Livro Caixa do Produtor', 'Entradas e saídas da atividade rural — a apuração oficial (IRPF) é da contabilidade',
        $podeEditar ? '+ Lançamento manual' : null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;align-items:center" class="no-print">
        <label class="vhint">Ano</label>
        <select name="ano" onchange="this.form.submit()">
          <?php foreach ($anos as $a): ?>
            <option value="<?= $a ?>"<?= $a === $fAno ? ' selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><strong><?= h($empresa) ?></strong> ·
        entradas <strong class="vnum" style="color:var(--vero-ok,#1a7f4b)">R$ <?= numFmt($totE, 2) ?></strong> ·
        saídas <strong class="vnum" style="color:#b3261e">R$ <?= numFmt($totS, 2) ?></strong> ·
        resultado <strong class="vnum">R$ <?= numFmt($totE - $totS, 2) ?></strong></span>
      <button class="vbtn vbtn-primary vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button>
    </div>
    <?php if ($podeEditar): ?>
    <form class="vform no-print" method="post"
          style="padding:0 14px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="gerar_razao">
      <div class="vfield"><label>Gerar do razão pago — início</label>
        <input type="date" name="ini" value="<?= $fAno ?>-01-01" required></div>
      <div class="vfield"><label>Fim</label>
        <input type="date" name="fim" value="<?= $fAno === (int)date('Y') ? date('Y-m-d') : $fAno . '-12-31' ?>" required></div>
      <button class="vbtn vbtn-ghost" type="submit"
              onclick="return confirm('Gerar lançamentos do razão pago no período? Já gerados não duplicam.')">
        Gerar do razão</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum lançamento em <?= $fAno ?> — gere do razão pago ou lance manualmente.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Histórico</th><th>Conta</th>
        <th style="text-align:right">Entrada (R$)</th>
        <th style="text-align:right">Saída (R$)</th>
        <th style="text-align:right">Saldo (R$)</th>
        <th style="text-align:right" class="no-print">Ações</th>
      </tr></thead>
      <tbody>
      <?php $saldo = 0.0; foreach ($rows as $r):
          $saldo += $r['tipo'] === 'entrada' ? (float)$r['valor'] : -(float)$r['valor'];
          $doRazao = str_contains((string)$r['historico'], '[RAZAO#'); ?>
        <tr>
          <td class="vnum"><strong><?= date('d/m/Y', strtotime((string)$r['data_lancamento'])) ?></strong></td>
          <td><?= h(preg_replace('/\s*\[RAZAO#\d+\]$/', '', (string)$r['historico'])) ?>
            <?= $doRazao ? '<span class="vbadge vb-info">razão</span>' : '' ?></td>
          <td class="vnum vhint"><?= h($r['conta_codigo'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right;color:var(--vero-ok,#1a7f4b)">
            <?= $r['tipo'] === 'entrada' ? numFmt((float)$r['valor'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right;color:#b3261e">
            <?= $r['tipo'] === 'saida' ? numFmt((float)$r['valor'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right;<?= $saldo < 0 ? 'color:#b3261e' : '' ?>"><strong><?= numFmt($saldo, 2) ?></strong></td>
          <td class="no-print"><div class="vactions">
            <?php if ($podeEditar && !$doRazao): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('fiscal.livro_caixa_produtor.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Remover este lançamento do livro?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">
      Lançamentos gerados do razão carregam a marca interna e não duplicam ao regerar; edite-os pelo
      financeiro (a fonte). A escrituração oficial do livro caixa digital é responsabilidade da contabilidade.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar lançamento' : 'Lançamento manual no livro' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="vfield">
          <label>Data *</label>
          <input type="date" name="data_lancamento" value="<?= h($edit['data_lancamento'] ?? date('Y-m-d')) ?>" required>
        </div>
        <?= vero_f_select('tipo', 'Tipo', ['entrada' => 'Entrada', 'saida' => 'Saída'], $edit['tipo'] ?? 'saida', true, '') ?>
        <div class="full"><?= vero_f_text('historico', 'Histórico', $edit['historico'] ?? '', true) ?></div>
        <?= vero_f_text('valor', 'Valor (R$)', $edit ? numFmt((float)$edit['valor'], 2) : '', true) ?>
        <?= vero_f_select('plano_conta_id', 'Conta (plano de contas)', ['' => 'Sem conta'] + $contas, $edit['plano_conta_id'] ?? '', false, '') ?>
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
