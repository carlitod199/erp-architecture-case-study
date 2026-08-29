<?php
/* ============================================================
   VERO — Patrimônio / base compartilhada de Ativos
   Incluída por ativos.php (todas as categorias) e pelos recortes
   terras.php / benfeitorias.php / equipamentos.php /
   veiculos_ativos.php, que definem:
     $PAT_MICRO, $PAT_VIEW, $PAT_TITULO, $PAT_SUB
     $PAT_CATEGORIA — nome da categoria padrão do recorte, ou null
                      para a tela geral (categoria vira campo do form)
   Categorias padrão são get-or-create por nome com vida útil/taxa
   default; a depreciação é GERENCIAL (não contábil/fiscal).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_depreciacao_teorica.php'; /* R3-01: leitura linear teórica */

/* categorias padrão: nome => [vida_util_meses, taxa_anual %] — vida 0 = não deprecia (terras) */
const PAT_CATEGORIAS_PADRAO = [
    'Terras'        => [0, 0.0],
    'Benfeitorias'  => [300, 4.0],
    'Equipamentos'  => [120, 10.0],
    'Veículos'      => [60, 20.0],
    'Máquinas'      => [120, 10.0],
];

function pat_categoria_id(string $nome): int
{
    $id = vero_val("SELECT id FROM patrimonio_categorias WHERE tenant_id = :t AND nome = :n",
        [':t' => vero_tenant(), ':n' => $nome]);
    if ($id) return (int)$id;
    [$vida, $taxa] = PAT_CATEGORIAS_PADRAO[$nome] ?? [120, 10.0];
    return vero_insert('patrimonio_categorias', [
        'nome' => $nome, 'vida_util_meses' => $vida, 'taxa_depreciacao' => $taxa, 'ativo' => 1,
    ]);
}

/* garante as categorias padrão na primeira visita */
foreach (array_keys(PAT_CATEGORIAS_PADRAO) as $catNome) pat_categoria_id($catNome);

$permBase = 'patrimonio.' . $PAT_MICRO;
$catFixaId = $PAT_CATEGORIA !== null ? pat_categoria_id($PAT_CATEGORIA) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require($permBase . '.editar');
        $id        = vero_int('id');
        $descricao = vero_str('descricao', 200);
        $valor     = vero_dec('valor_aquisicao');
        // A-5: categoria vinda do POST validada por tenant (a fixa vem do contexto).
        $catId     = $catFixaId ?? vero_fk_tenant('patrimonio_categorias', vero_int('categoria_id'));
        if ($descricao === null || $valor === null || $valor <= 0 || !$catId) {
            vero_flash('erro', 'Descrição, categoria e valor de aquisição (maior que zero) são obrigatórios.');
            vero_redirect();
        }
        $valorResidual = vero_dec('valor_residual') ?? 0;
        if ($valorResidual < 0) { /* A11: valor residual nunca é negativo */
            vero_flash('erro', 'O valor residual não pode ser negativo.');
            vero_redirect();
        }
        $data = [
            'categoria_id'    => (int)$catId,
            'maquina_id'      => vero_fk_tenant('maquinas', vero_int('maquina_id')), // A-5
            'descricao'       => $descricao,
            'valor_aquisicao' => $valor,
            'data_aquisicao'  => vero_date('data_aquisicao'),
            'vida_util_meses' => vero_int('vida_util_meses') ?: null,
            'valor_residual'  => $valorResidual,
            'ativo'           => vero_int('ativo') ?? 1,
        ];
        if ($id) { vero_update('patrimonio_ativos', $id, $data); vero_flash('ok', 'Ativo atualizado.'); }
        else     { vero_insert('patrimonio_ativos', $data);      vero_flash('ok', 'Ativo cadastrado.'); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require($permBase . '.excluir');
        $id = vero_int('id');
        if ($id) {
            $dep = (int)vero_val("SELECT COUNT(*) FROM patrimonio_depreciacoes WHERE tenant_id=:t AND ativo_id=:a",
                [':t' => vero_tenant(), ':a' => $id]);
            if ($dep > 0) {
                vero_update('patrimonio_ativos', (int)$id, ['ativo' => 0]);
                vero_flash('erro', "Ativo tem {$dep} depreciação(ões) lançada(s) — inativado (baixa) em vez de excluído.");
            } else {
                vero_delete('patrimonio_ativos', $id);
            }
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$where  = "a.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($catFixaId !== null) { $where .= " AND a.categoria_id = :c"; $params[':c'] = $catFixaId; }

$rows = vero_rows(
    "SELECT a.*, c.nome AS categoria, c.vida_util_meses AS cat_vida, m.nome AS maquina,
            (SELECT d.valor_acumulado FROM patrimonio_depreciacoes d
              WHERE d.tenant_id = a.tenant_id AND d.ativo_id = a.id
              ORDER BY d.competencia DESC LIMIT 1) AS depreciacao_acumulada,
            (SELECT COUNT(*) FROM patrimonio_depreciacoes d2
              WHERE d2.tenant_id = a.tenant_id AND d2.ativo_id = a.id) AS dep_qtd
       FROM patrimonio_ativos a
       LEFT JOIN patrimonio_categorias c ON c.id = a.categoria_id
       LEFT JOIN maquinas m ON m.id = a.maquina_id
      WHERE {$where}
      ORDER BY a.ativo DESC, c.nome, a.descricao", $params);

$totAquisicao = 0.0; $totLiquido = 0.0; $totLiquidoEcon = 0.0; $temDivergencia = false;
foreach ($rows as $r) {
    if ((int)$r['ativo'] !== 1) continue;
    $acum = (float)($r['depreciacao_acumulada'] ?? 0);
    $liq  = max((float)$r['valor_aquisicao'] - $acum, (float)($r['valor_residual'] ?? 0));
    $totAquisicao += (float)$r['valor_aquisicao'];
    $totLiquido   += $liq;
    /* R3-01: leitura linear desde a aquisição (não grava nada) */
    $teo = pat_teorica($r);
    if (pat_diverge($teo, $acum)) $temDivergencia = true;
    $totLiquidoEcon += $teo !== null ? $teo['liquido_econ'] : $liq;
}

$categorias = vero_options('patrimonio_categorias', 'nome');
$maquinas   = vero_rows("SELECT id, CONCAT(codigo, ' — ', nome) AS nome FROM maquinas
                          WHERE tenant_id = :t AND ativo = 1 ORDER BY codigo", [':t' => vero_tenant()]);
$maquinaOpts = [];
foreach ($maquinas as $m) $maquinaOpts[(int)$m['id']] = (string)$m['nome'];

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM patrimonio_ativos WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'patrimonio', 'micro' => $PAT_MICRO];
$PAGE_VIEW  = $PAT_VIEW;
$PAGE_TITLE = $PAT_TITULO;
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can($permBase . '.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header($PAT_TITULO, $PAT_SUB, $podeEditar ? '+ Novo ativo' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <span class="vsub"><?= count($rows) ?> ativo(s)</span>
      <span class="vsub">aquisição <strong class="vnum">R$ <?= numFmt($totAquisicao, 2) ?></strong> ·
        valor líquido gerencial (depr. gerada) <strong class="vnum">R$ <?= numFmt($totLiquido, 2) ?></strong>
        <?php if ($temDivergencia): ?> ·
          líquido econômico estimado (linear desde a aquisição) <strong class="vnum">R$ <?= numFmt($totLiquidoEcon, 2) ?></strong>
        <?php endif; ?> ·
        <a href="<?= $base ?>/patrimonio/depreciacao_gerencial.php">depreciação</a></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum ativo cadastrado<?= $PAT_CATEGORIA ? ' em ' . h($PAT_CATEGORIA) : '' ?>.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Descrição</th>
        <?php if ($catFixaId === null): ?><th>Categoria</th><?php endif; ?>
        <th>Aquisição</th>
        <th style="text-align:right">Valor (R$)</th>
        <th style="text-align:right">Vida útil</th>
        <th style="text-align:right">Depr. acumulada GERADA (R$)</th>
        <th style="text-align:right">Líquido gerencial (R$)</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $acum = (float)($r['depreciacao_acumulada'] ?? 0);
          $liquido = max((float)$r['valor_aquisicao'] - $acum, (float)($r['valor_residual'] ?? 0));
          $vida = $r['vida_util_meses'] ?? $r['cat_vida'];
          $teo = pat_teorica($r);                       /* R3-01 */
          $divergente = (int)$r['ativo'] === 1 && pat_diverge($teo, $acum); ?>
        <tr<?= (int)$r['ativo'] !== 1 ? ' style="opacity:.55"' : '' ?>>
          <td><strong><?= h($r['descricao']) ?></strong>
            <?= $r['maquina'] ? '<div class="vhint">máquina: ' . h((string)$r['maquina']) . '</div>' : '' ?></td>
          <?php if ($catFixaId === null): ?>
            <td><span class="vbadge vb-info"><?= h($r['categoria'] ?? '—') ?></span></td>
          <?php endif; ?>
          <td class="vnum"><?= $r['data_aquisicao'] ? date('d/m/Y', strtotime((string)$r['data_aquisicao'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['valor_aquisicao'], 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= $vida !== null && (int)$vida > 0 ? (int)$vida . ' m' : '<span class="vhint">não deprecia</span>' ?></td>
          <td class="vnum" style="text-align:right"><?= $acum > 0 ? numFmt($acum, 2) : '—' ?>
            <?php if ($divergente): ?>
              <div class="vhint">linear desde a aquisição:<br>R$ <?= numFmt($teo['teorica'], 2) ?>
                (<?= (int)$teo['meses'] ?> compet., <?= (int)$teo['pendentes'] ?> não gerada<?= $teo['pendentes'] === 1 ? '' : 's' ?>)</div>
            <?php endif; ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($liquido, 2) ?></strong>
            <?php if ($divergente): ?>
              <div class="vhint">econômico estimado:<br>R$ <?= numFmt($teo['liquido_econ'], 2) ?></div>
            <?php endif; ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can($permBase . '.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir/baixar este ativo?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">
      Depreciação linear gerencial: (aquisição − residual) ÷ vida útil, lançada por competência em
      Patrimônio → Depreciação. A coluna "acumulada GERADA" soma apenas as competências já lançadas;
      quando houver competências não geradas, a leitura "linear desde a aquisição" mostra o
      acumulado teórico e o valor líquido econômico estimado. Não substitui a contábil/fiscal.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar ativo' : 'Novo ativo' . ($PAT_CATEGORIA ? ' — ' . h($PAT_CATEGORIA) : '') ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('descricao', 'Descrição', $edit['descricao'] ?? '', true) ?></div>
        <?php if ($catFixaId === null): ?>
          <?= vero_f_select('categoria_id', 'Categoria', $categorias, $edit['categoria_id'] ?? '', true, 'Selecione…') ?>
        <?php endif; ?>
        <?= vero_f_text('valor_aquisicao', 'Valor de aquisição (R$)', $edit ? numFmt((float)$edit['valor_aquisicao'], 2) : '', true) ?>
        <div class="vfield">
          <label>Data de aquisição</label>
          <input type="date" name="data_aquisicao" value="<?= h($edit['data_aquisicao'] ?? '') ?>">
        </div>
        <?= vero_f_text('vida_util_meses', 'Vida útil (meses)', (string)($edit['vida_util_meses'] ?? ''), false, 'Vazio = usa a da categoria') ?>
        <?= vero_f_text('valor_residual', 'Valor residual (R$)', $edit && $edit['valor_residual'] !== null ? numFmt((float)$edit['valor_residual'], 2) : '', false) ?>
        <?= vero_f_select('maquina_id', 'Máquina vinculada', ['' => 'Nenhuma'] + $maquinaOpts, $edit['maquina_id'] ?? '', false, '') ?>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativo', 0 => 'Baixado'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
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
