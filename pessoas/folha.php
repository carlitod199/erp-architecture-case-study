<?php
/* ============================================================
   VERO — Pessoas / Folha Simplificada  (tela real)
   Tela nova. Rota da matriz: /pessoas/folha.php
   Guard: pessoas.folha | Escrita: pessoas.folha.editar/excluir
   Tabelas: rh_folha_periodos + rh_folha_lancamentos (mig. 130)
   Gerar lançamentos (período aberto, idempotente): para cada CLT
   ativo com salário → salário base + premiações do mês (soma de
   rh_producao_itens modalidade premiação) = bruto; encargos = bruto ×
   percentuais vigentes na competência (vero_srv_encargos_calc).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_folha_liquido.php'; /* A3 ONDA 4 — líquido a pagar (INSS/IRRF) */
require_once __DIR__ . '/../custeio/_plano_map.php'; /* A3-T3: plano de contas no custeio */

const T = 'rh_folha_periodos';

/**
 * A3-T3 (P-41 aprovada): FECHAR o período emite custeio idempotente por
 * lançamento de folha — valor = custo_total − premiacoes_total (premiação
 * já vira custeio pelo apontamento: evita dupla contagem), categoria
 * mao_de_obra, centro MDO, SEM talhão/safra (indireto rateável — P-41).
 * REABRIR remove o custeio da origem (padrão reemissão).
 */
function folha_emitir_custeio(int $periodoId, string $competencia): int
{
    $t = vero_tenant();
    vero_pdo()->prepare(
        "DELETE cl FROM custeio_lancamentos cl
          WHERE cl.tenant_id = ? AND cl.origem_tipo = 'rh_folha_lancamento'
            AND cl.origem_id IN (SELECT id FROM rh_folha_lancamentos WHERE tenant_id = ? AND periodo_id = ?)")
        ->execute([$t, $t, $periodoId]);
    $n = 0;
    foreach (vero_rows("SELECT id, custo_total, premiacoes_total FROM rh_folha_lancamentos
                         WHERE tenant_id = :t AND periodo_id = :p", [':t' => $t, ':p' => $periodoId]) as $l) {
        $valor = round((float)$l['custo_total'] - (float)$l['premiacoes_total'], 2);
        if ($valor <= 0) continue;
        vero_insert('custeio_lancamentos', [
            'centro_custo_id'  => vero_srv_centro_custo('MDO', 'Mão de Obra'),
            'plano_conta_id'   => custeio_plano_conta_id('rh_folha_lancamento'),
            'categoria'        => 'mao_de_obra',
            'origem_tipo'      => 'rh_folha_lancamento',
            'origem_id'        => (int)$l['id'],
            'valor'            => $valor,
            'data_competencia' => $competencia,
        ]);
        $n++;
    }
    return $n;
}

function folha_remover_custeio(int $periodoId): void
{
    $t = vero_tenant();
    vero_pdo()->prepare(
        "DELETE cl FROM custeio_lancamentos cl
          WHERE cl.tenant_id = ? AND cl.origem_tipo = 'rh_folha_lancamento'
            AND cl.origem_id IN (SELECT id FROM rh_folha_lancamentos WHERE tenant_id = ? AND periodo_id = ?)")
        ->execute([$t, $t, $periodoId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'criar_periodo') {
        vero_require('pessoas.folha.editar');
        $comp = vero_str('competencia', 7); // AAAA-MM
        if ($comp === null || !preg_match('/^\d{4}-\d{2}$/', $comp)) {
            vero_flash('erro', 'Informe a competência (mês/ano).');
            vero_redirect();
        }
        $compData = $comp . '-01';
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND competencia=:c",
            [':t' => vero_tenant(), ':c' => $compData]);
        if ($dup) {
            vero_flash('erro', 'Já existe um período para esta competência.');
            vero_redirect();
        }
        $pid = vero_insert(T, ['competencia' => $compData, 'status' => 'aberto']);
        vero_flash('ok', 'Período criado — gere os lançamentos.');
        vero_redirect(BIOS_BASE . '/pessoas/folha?ver=' . $pid);
    }

    if ($acao === 'gerar') {
        vero_require('pessoas.folha.editar');
        $pid = vero_int('periodo_id');
        $periodo = $pid ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $pid, ':t' => vero_tenant()]) : null;
        if (!$periodo || $periodo['status'] !== 'aberto') {
            vero_flash('erro', 'Período inválido ou fechado.');
            vero_redirect(BIOS_BASE . '/pessoas/folha');
        }
        $comp = (string)$periodo['competencia'];
        $cfg = vero_srv_encargos_vigente($comp);
        if (!$cfg) {
            vero_flash('erro', 'Sem configuração de encargos vigente para a competência — cadastre em Pessoas → Encargos CLT.');
            vero_redirect(BIOS_BASE . '/pessoas/folha?ver=' . $pid);
        }
        $inicio = $comp;
        $fim = date('Y-m-t', strtotime($comp));

        $clts = vero_rows(
            "SELECT * FROM agro_operadores
              WHERE tenant_id = :t AND ativo = 1 AND tipo_vinculo = 'clt' AND salario_mensal IS NOT NULL",
            [':t' => vero_tenant()]);

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM rh_folha_lancamentos WHERE tenant_id=? AND periodo_id=?")
                ->execute([vero_tenant(), $pid]);
            $qtd = 0;
            foreach ($clts as $col) {
                $salario = (float)$col['salario_mensal'];
                $premiacoes = (float)vero_val(
                    "SELECT COALESCE(SUM(valor_total),0) FROM rh_producao_itens
                      WHERE tenant_id = :t AND operador_id = :o AND modalidade = 'premiacao'
                        AND data_trabalho BETWEEN :i AND :f",
                    [':t' => vero_tenant(), ':o' => (int)$col['id'], ':i' => $inicio, ':f' => $fim]);
                $bruto = round($salario + $premiacoes, 2);
                $e = vero_srv_encargos_calc($bruto, $cfg);
                /* A3 ONDA 4 (M1/M2): descontos do empregado + líquido, CONGELADOS na geração
                   (como os encargos). dependentes vêm do cadastro eSocial (M1). Faltas/
                   adiantamento/EPI são descontos VARIÁVEIS — entrada futura, 0 por ora. */
                $deps = (int)($col['dependentes'] ?? 0);
                $liq  = folha_liquido($bruto, $deps);
                $totalDesc = round($liq['inss'] + $liq['irrf'], 2); /* + faltas/adiant./epi quando houver entrada */
                vero_insert('rh_folha_lancamentos', [
                    'periodo_id'        => $pid,
                    'operador_id'       => (int)$col['id'],
                    'salario_base'      => $salario,
                    'premiacoes_total'  => $premiacoes,
                    'total_bruto'       => $bruto,
                    'enc_fgts'          => $e['fgts'],
                    'enc_inss_patronal' => $e['inss_patronal'],
                    'enc_rat'           => $e['rat'],
                    'enc_terceiros'     => $e['terceiros'],
                    'enc_ferias'        => $e['ferias'],
                    'enc_decimo'        => $e['decimo'],
                    'enc_outros'        => $e['outros'],
                    'total_encargos'    => $e['total'],
                    'custo_total'       => $e['custo_total'],
                    'desc_inss'         => $liq['inss'],
                    'desc_irrf'         => $liq['irrf'],
                    'desc_faltas'       => 0,
                    'desc_adiantamento' => 0,
                    'desc_epi'          => 0,
                    'total_descontos'   => $totalDesc,
                    'liquido'           => round($bruto - $totalDesc, 2),
                ]);
                $qtd++;
            }
            $pdo->commit();
            vero_flash('ok', "Lançamentos gerados para {$qtd} colaborador(es) CLT (premiações do mês incluídas no bruto).");
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao gerar: ' . h($e->getMessage()));
        }
        vero_redirect(BIOS_BASE . '/pessoas/folha?ver=' . $pid);
    }

    if ($acao === 'status') {
        vero_require('pessoas.folha.editar');
        $pid = vero_int('periodo_id');
        $novo = ($_POST['novo_status'] ?? '') === 'fechado' ? 'fechado' : 'aberto';
        $periodo = $pid ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $pid, ':t' => vero_tenant()]) : null;
        if ($periodo) {
            $pdo = vero_pdo();
            $pdo->beginTransaction();
            try {
                vero_update(T, (int)$pid, ['status' => $novo]);
                if ($novo === 'fechado') { /* A3-T3: folha fechada vira custo de MDO */
                    $n = folha_emitir_custeio((int)$pid, (string)$periodo['competencia']);
                    $msg = "Período fechado — {$n} lançamento(s) de salário+encargos emitidos ao custeio (premiações já entram pelo apontamento).";
                } else {
                    folha_remover_custeio((int)$pid);
                    $msg = 'Período reaberto — custeio da folha removido (reemitido no próximo fechamento).';
                }
                $pdo->commit();
                vero_flash('ok', $msg);
            } catch (Throwable $e) {
                $pdo->rollBack();
                vero_flash('erro', 'Erro: ' . h($e->getMessage()));
            }
        }
        vero_redirect(BIOS_BASE . '/pessoas/folha?ver=' . (int)$pid);
    }

    if ($acao === 'excluir') {
        vero_require('pessoas.folha.excluir');
        $pid = vero_int('id');
        $periodo = $pid ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $pid, ':t' => vero_tenant()]) : null;
        if ($periodo) {
            if ($periodo['status'] === 'fechado') {
                vero_flash('erro', 'Período fechado não pode ser excluído — reabra antes.');
            } else {
                $pdo = vero_pdo();
                $pdo->prepare("DELETE FROM rh_folha_lancamentos WHERE tenant_id=? AND periodo_id=?")
                    ->execute([vero_tenant(), $pid]);
                $pdo->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")
                    ->execute([vero_tenant(), $pid]);
                vero_flash('ok', 'Período excluído.');
            }
        }
        vero_redirect(BIOS_BASE . '/pessoas/folha');
    }
}

/* ── Dados ──────────────────────────────────────────────────── */
$ver = null;
$lancamentos = [];
/* ── A3 (P-118): EXPORTAÇÃO da folha para o CONTADOR (planilha) ──────────
   eSocial no piloto = SÓ organiza/relata os dados (não transmite eventos).
   Este CSV entrega a folha do período (proventos + encargos por colaborador)
   pronta p/ o contador. UTF-8+BOM, ';', decimal com vírgula (convenção do
   projeto). Repete o guard ANTES de streamar (lição F2-23) e sai sem HTML.
   Campos cadastrais eSocial (NIS/PIS, CBO, admissão) = migration A0 (ONDA 4). */
$csvPer = (int)($_GET['csv'] ?? 0);
if ($csvPer > 0) {
    if (function_exists('requirePermission')) requirePermission('pessoas.folha.ver');
    $per = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $csvPer, ':t' => vero_tenant()]);
    if (!$per) { http_response_code(404); exit('período inválido'); }
    $rows = vero_rows(
        "SELECT o.nome, o.funcao, o.tipo_vinculo, o.nis_pis, o.cbo, l.salario_base, l.horas_extras, l.valor_hora_extra,
                l.horas_noturnas, l.valor_hora_noturna, l.premiacoes_total, l.total_bruto,
                l.enc_fgts, l.enc_inss_patronal, l.enc_rat, l.enc_terceiros, l.enc_ferias,
                l.enc_decimo, l.enc_outros, l.total_encargos, l.custo_total,
                l.desc_inss, l.desc_irrf, l.desc_faltas, l.desc_adiantamento, l.desc_epi,
                l.total_descontos, l.liquido
           FROM rh_folha_lancamentos l
           JOIN agro_operadores o ON o.id = l.operador_id AND o.tenant_id = l.tenant_id
          WHERE l.tenant_id = :t AND l.periodo_id = :p ORDER BY o.nome",
        [':t' => vero_tenant(), ':p' => $csvPer]);
    $cols = [
        'nome' => 'Colaborador', 'funcao' => 'Função', 'tipo_vinculo' => 'Vínculo',
        'nis_pis' => 'NIS/PIS', 'cbo' => 'CBO',
        'salario_base' => 'Salário base', 'horas_extras' => 'Horas extras', 'valor_hora_extra' => 'Valor HE',
        'horas_noturnas' => 'Horas noturnas', 'valor_hora_noturna' => 'Valor h.noturna',
        'premiacoes_total' => 'Premiações', 'total_bruto' => 'Total bruto',
        'enc_fgts' => 'FGTS', 'enc_inss_patronal' => 'INSS patronal', 'enc_rat' => 'RAT',
        'enc_terceiros' => 'Terceiros (SENAR)', 'enc_ferias' => 'Férias+1/3', 'enc_decimo' => '13º',
        'enc_outros' => 'Outros', 'total_encargos' => 'Total encargos', 'custo_total' => 'Custo total',
        'desc_inss' => 'Desc. INSS', 'desc_irrf' => 'Desc. IRRF', 'desc_faltas' => 'Faltas',
        'desc_adiantamento' => 'Adiantamento', 'desc_epi' => 'Desc. EPI',
        'total_descontos' => 'Total descontos', 'liquido' => 'Líquido a pagar',
    ];
    $numCols = array_diff(array_keys($cols), ['nome', 'funcao', 'tipo_vinculo', 'nis_pis', 'cbo']);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="vero_folha_' . date('Ym', strtotime((string)$per['competencia'])) . '_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_values($cols), ';');
    foreach ($rows as $r) {
        $linha = [];
        foreach ($cols as $campo => $_lab) {
            $v = $r[$campo] ?? '';
            $linha[] = in_array($campo, $numCols, true) ? number_format((float)$v, 2, ',', '') : (string)$v;
        }
        fputcsv($out, $linha, ';');
    }
    fclose($out);
    exit;
}

if (!empty($_GET['ver'])) {
    $ver = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
        [':i' => (int)$_GET['ver'], ':t' => vero_tenant()]);
    if ($ver) {
        $lancamentos = vero_rows(
            "SELECT l.*, o.nome, o.funcao FROM rh_folha_lancamentos l
               JOIN agro_operadores o ON o.id = l.operador_id
              WHERE l.tenant_id = :t AND l.periodo_id = :p ORDER BY o.nome",
            [':t' => vero_tenant(), ':p' => (int)$ver['id']]);
    }
}

$periodos = vero_rows(
    "SELECT p.*,
            (SELECT COUNT(*) FROM rh_folha_lancamentos l
              WHERE l.tenant_id = p.tenant_id AND l.periodo_id = p.id) AS lancamentos,
            (SELECT COALESCE(SUM(l.custo_total),0) FROM rh_folha_lancamentos l
              WHERE l.tenant_id = p.tenant_id AND l.periodo_id = p.id) AS custo
       FROM " . T . " p
      WHERE p.tenant_id = :t ORDER BY p.competencia DESC",
    [':t' => vero_tenant()]);

$GUARD      = ['macro' => 'pessoas', 'micro' => 'folha'];
$PAGE_VIEW  = 'pessoas_folha';
$PAGE_TITLE = 'Folha Simplificada';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('pessoas.folha.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <div class="vhead">
    <div>
      <h1>Folha Simplificada (CLT)</h1>
      <div class="vsub">Períodos mensais: salário + premiações do apontamento = bruto; encargos pela vigência da competência</div>
    </div>
    <?php if ($podeEditar): ?>
    <form method="post" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="criar_periodo">
      <input type="month" name="competencia" value="<?= date('Y-m') ?>" required
             style="border:1px solid #D5CEBF;border-radius:9px;padding:9px 11px;font:13px 'IBM Plex Sans';background:#fff">
      <button class="vbtn vbtn-primary" type="submit">+ Criar período</button>
    </form>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start">
    <div class="vcard">
      <div class="vtoolbar"><strong style="font-size:14px">Períodos</strong></div>
      <?php if (!$periodos): ?>
        <div class="vempty">Nenhum período criado.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Competência</th><th style="text-align:right">Custo (R$)</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($periodos as $p): ?>
          <tr<?= $ver && (int)$ver['id'] === (int)$p['id'] ? ' style="background:#FAF8F1"' : '' ?>>
            <td><a href="?ver=<?= (int)$p['id'] ?>" style="font-weight:600;color:#005059;text-decoration:none">
              <?= date('m/Y', strtotime((string)$p['competencia'])) ?></a>
              <span class="vhint"><?= (int)$p['lancamentos'] ?> lanç.</span></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$p['custo'], 2) ?></td>
            <td><?= $p['status'] === 'fechado'
                  ? '<span class="vbadge vb-info">Fechado</span>'
                  : '<span class="vbadge vb-warn">Aberto</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <?php if (!$ver): ?>
        <div class="vempty">Selecione um período à esquerda (ou crie um novo).</div>
      <?php else: ?>
      <div class="vtoolbar">
        <strong style="font-size:14px">Folha <?= date('m/Y', strtotime((string)$ver['competencia'])) ?></strong>
        <?= $ver['status'] === 'fechado'
              ? '<span class="vbadge vb-info">Fechado</span>'
              : '<span class="vbadge vb-warn">Aberto</span>' ?>
        <div style="flex:1"></div>
        <?php if ($lancamentos): ?>
          <a class="vbtn vbtn-ghost vbtn-sm" href="?csv=<?= (int)$ver['id'] ?>" title="Planilha da folha (proventos + encargos) para o contador">⬇ Exportar p/ contador</a>
        <?php endif; ?>
        <?php if ($podeEditar): ?>
          <?php if ($ver['status'] === 'aberto'): ?>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="gerar">
              <input type="hidden" name="periodo_id" value="<?= (int)$ver['id'] ?>">
              <button class="vbtn vbtn-primary vbtn-sm" type="submit"><?= $lancamentos ? 'Regerar lançamentos' : 'Gerar lançamentos' ?></button>
            </form>
            <form method="post" data-confirm="Fechar o período? Lançamentos ficam congelados." data-confirm-ok="Fechar período" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="status">
              <input type="hidden" name="novo_status" value="fechado">
              <input type="hidden" name="periodo_id" value="<?= (int)$ver['id'] ?>">
              <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Fechar período</button>
            </form>
            <?php if (vero_can('pessoas.folha.excluir')): ?>
              <?= vero_btn_excluir((int)$ver['id'], 'Excluir este período e seus lançamentos?') ?>
            <?php endif; ?>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="status">
              <input type="hidden" name="novo_status" value="aberto">
              <input type="hidden" name="periodo_id" value="<?= (int)$ver['id'] ?>">
              <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Reabrir</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php if (!$lancamentos): ?>
        <div class="vempty">Sem lançamentos — clique em “Gerar lançamentos”.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr>
          <th>Colaborador</th>
          <th style="text-align:right">Salário</th>
          <th style="text-align:right">Premiações</th>
          <th style="text-align:right">Bruto</th>
          <th style="text-align:right">Encargos</th>
          <th style="text-align:right">Custo total</th>
          <th style="text-align:right" title="Descontos do empregado">INSS</th>
          <th style="text-align:right">IRRF</th>
          <th style="text-align:right" title="Bruto − INSS − IRRF">Líquido a pagar</th>
        </tr></thead>
        <tbody>
        <?php
        $tt = ['sal' => 0.0, 'prem' => 0.0, 'bruto' => 0.0, 'enc' => 0.0, 'custo' => 0.0,
               'inss' => 0.0, 'irrf' => 0.0, 'liq' => 0.0];
        foreach ($lancamentos as $l):
            /* lê o líquido PERSISTIDO (congelado na geração); legado (NULL) computa na hora */
            $liq = $l['liquido'] !== null
                ? ['inss' => (float)$l['desc_inss'], 'irrf' => (float)$l['desc_irrf'], 'liquido' => (float)$l['liquido']]
                : folha_liquido((float)$l['total_bruto']);
            $tt['sal'] += (float)$l['salario_base']; $tt['prem'] += (float)$l['premiacoes_total'];
            $tt['bruto'] += (float)$l['total_bruto']; $tt['enc'] += (float)$l['total_encargos'];
            $tt['custo'] += (float)$l['custo_total'];
            $tt['inss'] += $liq['inss']; $tt['irrf'] += $liq['irrf']; $tt['liq'] += $liq['liquido'];
        ?>
          <tr>
            <td><strong><?= h($l['nome']) ?></strong>
              <?= $l['funcao'] ? '<span class="vhint">' . h($l['funcao']) . '</span>' : '' ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$l['salario_base'], 2) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$l['premiacoes_total'], 2) ?></td>
            <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$l['total_bruto'], 2) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$l['total_encargos'], 2) ?></td>
            <td class="vnum" style="text-align:right"><strong style="color:#005059"><?= numFmt((float)$l['custo_total'], 2) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= numFmt($liq['inss'], 2) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt($liq['irrf'], 2) ?></td>
            <td class="vnum" style="text-align:right"><strong style="color:#1E6B34"><?= numFmt($liq['liquido'], 2) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td style="font-weight:700">Total</td>
          <td class="vnum" style="text-align:right;font-weight:700"><?= numFmt($tt['sal'], 2) ?></td>
          <td class="vnum" style="text-align:right;font-weight:700"><?= numFmt($tt['prem'], 2) ?></td>
          <td class="vnum" style="text-align:right;font-weight:700"><?= numFmt($tt['bruto'], 2) ?></td>
          <td class="vnum" style="text-align:right;font-weight:700"><?= numFmt($tt['enc'], 2) ?></td>
          <td class="vnum" style="text-align:right;font-weight:700;color:#005059"><?= numFmt($tt['custo'], 2) ?></td>
          <td class="vnum" style="text-align:right;font-weight:700"><?= numFmt($tt['inss'], 2) ?></td>
          <td class="vnum" style="text-align:right;font-weight:700"><?= numFmt($tt['irrf'], 2) ?></td>
          <td class="vnum" style="text-align:right;font-weight:700;color:#1E6B34"><?= numFmt($tt['liq'], 2) ?></td>
        </tr></tfoot>
      </table>
      <div class="vhint" style="padding:8px 14px">
        INSS/IRRF do empregado = tabelas PROGRESSIVAS 2026 (referência editável em
        <code>tenant_parametros</code> — <strong>conferir com o contador</strong>). Líquido = bruto − INSS − IRRF.
        Descontos variáveis (faltas, adiantamento, EPI danificado) e nº de dependentes exigem os campos
        eSocial/rubricas (migration A0 — VERO_A3_Folha_ONDA4_Spec.md). "Custo total" é o lado do EMPREGADOR.
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
