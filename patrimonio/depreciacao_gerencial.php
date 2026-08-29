<?php
/* ============================================================
   VERO — Patrimônio / Depreciação Gerencial  (tela real)
   Substitui o mock. Rota: /patrimonio/depreciacao_gerencial.php
   Guard: patrimonio.depreciacao_gerencial
   Depreciação LINEAR GERENCIAL por competência:
     mensal = (aquisição − residual) ÷ vida útil (do ativo ou da categoria)
     acumulada limitada a (aquisição − residual)
   Idempotente por (ativo, competência) — gerar de novo não duplica.
   NÃO substitui a depreciação contábil/fiscal.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/../custeio/_plano_map.php'; /* A3-T4: plano de contas no custeio */
require_once __DIR__ . '/_depreciacao_teorica.php';  /* R3-01: leitura linear teórica */

$t = vero_tenant();

/* A3-T4 (P-42/P-24 aprovadas): depreciação de ativo PRODUTIVO vira custo
   (categoria `depreciacao`, sem talhão — indireto rateável). Fora do
   custeio: categoria "Terras" (não deprecia) e categorias com
   "administrativ" no nome (P-42: só produtivos). Fonte única = patrimônio
   (P-24): o custo-hora da frota é indicador e NÃO emite custeio. */
function dep_e_produtiva(?string $categoria): bool
{
    if ($categoria === null) return true; /* sem categoria = assume produtivo */
    return stripos($categoria, 'administrativ') === false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'gerar') {
        vero_require('patrimonio.depreciacao_gerencial.editar');
        $comp = vero_str('competencia', 7); // AAAA-MM
        if ($comp === null || !preg_match('/^\d{4}-\d{2}$/', $comp)) {
            vero_flash('erro', 'Informe a competência (mês/ano).');
            vero_redirect();
        }
        $compData = $comp . '-01';

        $ativos = vero_rows(
            "SELECT a.*, c.vida_util_meses AS cat_vida, c.nome AS cat_nome FROM patrimonio_ativos a
              LEFT JOIN patrimonio_categorias c ON c.id = a.categoria_id
             WHERE a.tenant_id = :t AND a.ativo = 1", [':t' => $t]);

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $gerados = 0; $pulados = 0; $semVida = 0;
            foreach ($ativos as $a) {
                $vida = $a['vida_util_meses'] !== null ? (int)$a['vida_util_meses']
                      : ($a['cat_vida'] !== null ? (int)$a['cat_vida'] : null);
                $baseDep = (float)$a['valor_aquisicao'] - (float)($a['valor_residual'] ?? 0);
                if ($vida === null || $vida <= 0 || $baseDep <= 0) { $semVida++; continue; }
                if ($a['data_aquisicao'] !== null && $a['data_aquisicao'] > $compData) { $pulados++; continue; }

                $ja = vero_val("SELECT id FROM patrimonio_depreciacoes
                                 WHERE tenant_id=:t AND ativo_id=:a AND competencia=:c",
                    [':t' => $t, ':a' => (int)$a['id'], ':c' => $compData]);
                if ($ja) { $pulados++; continue; }

                $mensal = $baseDep / $vida;
                $acumAnterior = (float)(vero_val(
                    "SELECT valor_acumulado FROM patrimonio_depreciacoes
                      WHERE tenant_id=:t AND ativo_id=:a AND competencia < :c
                      ORDER BY competencia DESC LIMIT 1",
                    [':t' => $t, ':a' => (int)$a['id'], ':c' => $compData]) ?? 0);
                if ($acumAnterior >= $baseDep - 0.005) { $pulados++; continue; } // já depreciado por completo
                $valor = min($mensal, $baseDep - $acumAnterior);

                $depId = vero_insert('patrimonio_depreciacoes', [
                    'ativo_id'        => (int)$a['id'],
                    'competencia'     => $compData,
                    'valor'           => round($valor, 2),
                    'valor_acumulado' => round($acumAnterior + $valor, 2),
                ]);
                /* A3-T4: ativo produtivo → custeio (idempotente por origem;
                   a geração já pula competência existente) */
                if (dep_e_produtiva($a['cat_nome'] ?? null)) {
                    vero_insert('custeio_lancamentos', [
                        'centro_custo_id'  => vero_srv_centro_custo('DEP', 'Depreciação'),
                        'plano_conta_id'   => custeio_plano_conta_id('patrimonio_depreciacao'),
                        'categoria'        => 'depreciacao',
                        'origem_tipo'      => 'patrimonio_depreciacao',
                        'origem_id'        => $depId,
                        'valor'            => round($valor, 2),
                        'data_competencia' => $compData,
                    ]);
                }
                $gerados++;
            }
            /* sweep: depreciações da competência geradas ANTES da T4 (sem custeio) */
            $retro = 0;
            foreach (vero_rows(
                "SELECT d.id, d.valor, c.nome AS cat_nome
                   FROM patrimonio_depreciacoes d
                   JOIN patrimonio_ativos a ON a.id = d.ativo_id
                   LEFT JOIN patrimonio_categorias c ON c.id = a.categoria_id
                  WHERE d.tenant_id = :t AND d.competencia = :c
                    AND NOT EXISTS (SELECT 1 FROM custeio_lancamentos cl
                                     WHERE cl.tenant_id = d.tenant_id
                                       AND cl.origem_tipo = 'patrimonio_depreciacao' AND cl.origem_id = d.id)",
                [':t' => $t, ':c' => $compData]) as $d) {
                if (!dep_e_produtiva($d['cat_nome'] ?? null)) continue;
                vero_insert('custeio_lancamentos', [
                    'centro_custo_id'  => vero_srv_centro_custo('DEP', 'Depreciação'),
                    'plano_conta_id'   => custeio_plano_conta_id('patrimonio_depreciacao'),
                    'categoria'        => 'depreciacao',
                    'origem_tipo'      => 'patrimonio_depreciacao',
                    'origem_id'        => (int)$d['id'],
                    'valor'            => round((float)$d['valor'], 2),
                    'data_competencia' => $compData,
                ]);
                $retro++;
            }
            $pdo->commit();
            vero_flash('ok', "Competência " . date('m/Y', strtotime($compData)) .
                ": {$gerados} lançamento(s) gerado(s), {$pulados} já lançado(s)/não aplicável(is)" .
                ($semVida ? ", {$semVida} sem vida útil (não depreciam)" : '') .
                ($retro ? ", {$retro} custeio(s) retroativo(s) emitido(s)" : '') . '.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Falha ao gerar: ' . $e->getMessage());
        }
        vero_redirect('?comp=' . $comp);
    }
}

$fComp = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['comp'] ?? '')) ? (string)$_GET['comp'] : date('Y-m');

$competencias = array_map('strval', array_column(vero_rows(
    "SELECT DISTINCT DATE_FORMAT(competencia, '%Y-%m') AS c FROM patrimonio_depreciacoes
      WHERE tenant_id = :t ORDER BY c DESC", [':t' => $t]), 'c'));
if (!in_array($fComp, $competencias, true)) $competencias[] = $fComp;

$rows = vero_rows(
    "SELECT d.*, a.descricao, a.valor_aquisicao, a.valor_residual, a.data_aquisicao,
            a.vida_util_meses, c.vida_util_meses AS cat_vida, c.nome AS categoria,
            (SELECT COUNT(*) FROM patrimonio_depreciacoes d2
              WHERE d2.tenant_id = d.tenant_id AND d2.ativo_id = d.ativo_id
                AND d2.competencia <= d.competencia) AS dep_qtd
       FROM patrimonio_depreciacoes d
       JOIN patrimonio_ativos a ON a.id = d.ativo_id
       LEFT JOIN patrimonio_categorias c ON c.id = a.categoria_id
      WHERE d.tenant_id = :t AND DATE_FORMAT(d.competencia, '%Y-%m') = :c
      ORDER BY c.nome, a.descricao", [':t' => $t, ':c' => $fComp]);
$totMes = array_sum(array_map(static fn($r) => (float)$r['valor'], $rows));

/* R3-01: aviso de competências NÃO geradas desde a aquisição (posição até o mês
   corrente). Só leitura — o backfill em massa NÃO é feito aqui (decisão A0). */
$pendAtivos = [];
foreach (vero_rows(
    "SELECT a.descricao, a.valor_aquisicao, a.valor_residual, a.data_aquisicao,
            a.vida_util_meses, c.vida_util_meses AS cat_vida,
            (SELECT COUNT(*) FROM patrimonio_depreciacoes d
              WHERE d.tenant_id = a.tenant_id AND d.ativo_id = a.id) AS dep_qtd
       FROM patrimonio_ativos a
       LEFT JOIN patrimonio_categorias c ON c.id = a.categoria_id
      WHERE a.tenant_id = :t AND a.ativo = 1
      ORDER BY a.descricao", [':t' => $t]) as $pa) {
    $teoPend = pat_teorica($pa);
    if ($teoPend !== null && $teoPend['pendentes'] > 0) {
        $pendAtivos[] = ['descricao' => (string)$pa['descricao']] + $teoPend;
    }
}
$totPendentes = (int)array_sum(array_column($pendAtivos, 'pendentes'));

$GUARD      = ['macro' => 'patrimonio', 'micro' => 'depreciacao_gerencial'];
$PAGE_VIEW  = 'patrimonio_depreciacao_gerencial';
$PAGE_TITLE = 'Depreciação Gerencial';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('patrimonio.depreciacao_gerencial.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Depreciação Gerencial', 'Depreciação linear mensal por competência — visão gerencial, não substitui a contábil/fiscal', null) ?>

  <?php if ($totPendentes > 0): ?>
  <div class="vcard" style="margin-bottom:14px;border-left:4px solid #b3261e">
    <div style="padding:12px 14px">
      <strong>Atenção:</strong> há <strong><?= $totPendentes ?></strong> competência(s) desde a
      aquisição sem depreciação gerada em <?= count($pendAtivos) ?> ativo(s) — gere-as em ordem
      (uma competência por vez) ou solicite o backfill ao A0. O backfill em massa não está
      autorizado nesta tela.
      <div class="vhint" style="padding-top:6px">
        <?php foreach ($pendAtivos as $p): ?>
          <?= h($p['descricao']) ?>: <?= (int)$p['geradas'] ?> de <?= (int)$p['meses'] ?> competência(s)
          gerada(s) — <?= (int)$p['pendentes'] ?> pendente(s); linear desde a aquisição
          R$ <?= numFmt($p['teorica'], 2) ?> (cota mensal R$ <?= numFmt($p['cota'], 2) ?>).<br>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <label class="vhint">Competência</label>
        <select name="comp" onchange="this.form.submit()">
          <?php foreach ($competencias as $c): ?>
            <option value="<?= h($c) ?>"<?= $c === $fComp ? ' selected' : '' ?>><?= date('m/Y', strtotime($c . '-01')) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($podeEditar): ?>
      <form method="post" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="gerar">
        <input type="month" name="competencia" value="<?= h($fComp) ?>" required>
        <button class="vbtn vbtn-primary" type="submit">Gerar depreciação do mês</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Lançamentos de <?= date('m/Y', strtotime($fComp . '-01')) ?></strong>
      <span class="vsub"><?= count($rows) ?> ativo(s) ·
        total do mês <strong class="vnum">R$ <?= numFmt($totMes, 2) ?></strong></span></div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma depreciação lançada nesta competência<?= $podeEditar ? ' — use "Gerar depreciação do mês"' : '' ?>.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Ativo</th><th>Categoria</th>
        <th style="text-align:right">Aquisição (R$)</th>
        <th style="text-align:right">Depreciação do mês (R$)</th>
        <th style="text-align:right">Acumulada GERADA (R$)</th>
        <th style="text-align:right">Valor líquido gerencial (R$)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $liquido = max((float)$r['valor_aquisicao'] - (float)$r['valor_acumulado'], (float)($r['valor_residual'] ?? 0));
          $teo = pat_teorica($r, $fComp);               /* R3-01: teórica até esta competência */
          $divergente = pat_diverge($teo, (float)$r['valor_acumulado']); ?>
        <tr>
          <td><strong><?= h($r['descricao']) ?></strong></td>
          <td><span class="vbadge vb-info"><?= h($r['categoria'] ?? '—') ?></span></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['valor_aquisicao'], 2) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['valor'], 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['valor_acumulado'], 2) ?>
            <?php if ($divergente): ?>
              <div class="vhint">linear desde a aquisição:<br>R$ <?= numFmt($teo['teorica'], 2) ?>
                (<?= (int)$teo['meses'] ?> compet., <?= (int)$teo['pendentes'] ?> não gerada<?= $teo['pendentes'] === 1 ? '' : 's' ?>)</div>
            <?php endif; ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($liquido, 2) ?></strong>
            <?php if ($divergente): ?>
              <div class="vhint">econômico estimado:<br>R$ <?= numFmt($teo['liquido_econ'], 2) ?></div>
            <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      Gerar de novo a mesma competência não duplica. Ativos sem vida útil
      (ex.: terras) não entram. A acumulada GERADA soma apenas as competências lançadas e trava em
      (aquisição − residual); quando há competências não geradas, a leitura "linear desde a
      aquisição" mostra o acumulado teórico e o líquido econômico estimado.
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
