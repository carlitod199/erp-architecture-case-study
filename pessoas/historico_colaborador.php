<?php
/* ============================================================
   VERO — Pessoas / Histórico por Colaborador  (relatório)
   Rota: /pessoas/historico_colaborador.php · Guard: pessoas.historico_colaborador
   Padrão relatório (25/07, pedido do cliente):
     • SEM colaborador selecionado → RELATÓRIO CONSOLIDADO de TODOS os
       colaboradores no período: dias, horas, diárias, produção, premiação
       e custo de folha por pessoa + linha de TOTAL, com Exportar CSV e Imprimir.
     • COM ?colaborador=N → drill-down: a linha do tempo detalhada da pessoa
       (dias trabalhados, produção/premiação do dia e folhas geradas).
   Fontes: rh_producao_itens (realizado de MDO, por modalidade) e
   rh_folha_lancamentos (folha com encargos).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const HIST_VINCULOS = ['clt' => 'CLT', 'diarista' => 'Diarista', 'terceirizado' => 'Terceirizado', 'outro' => 'Outro'];

$fOp  = (int)($_GET['colaborador'] ?? 0);
$fIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';
$t    = vero_tenant();

/* Cláusula de data segura: $ini/$fim são validados como Y-m-d (ou ''), então
   podem ser embutidos direto (evita placeholders repetidos — o PDO roda sem
   emulação e não aceita o mesmo nome em vários subselects). */
function hist_dclause(string $col, string $ini, string $fim): string
{
    $c = '';
    if ($ini !== '') $c .= " AND {$col} >= '{$ini}'";
    if ($fim !== '') $c .= " AND {$col} <= '{$fim}'";
    return $c;
}

/** Consolidado por colaborador no período (só quem teve alguma movimentação).
    rh_producao_itens é a fonte única do realizado de MDO (origem_pessoa =
    'colaborador'): dias = dias distintos apontados; horas = quantidade em
    unidade 'hora'; diária/produção/premiação separadas pela modalidade. */
function hist_consolidado(string $ini, string $fim): array
{
    $pi = hist_dclause('pi.data_trabalho', $ini, $fim);
    $fl = hist_dclause('fp.competencia', $ini, $fim);
    $wPi = "pi.tenant_id=o.tenant_id AND pi.origem_pessoa='colaborador' AND pi.operador_id=o.id {$pi}";
    $sql =
        "SELECT o.id, o.nome, o.funcao, o.tipo_vinculo, o.ativo,
                (SELECT COUNT(DISTINCT pi.data_trabalho) FROM rh_producao_itens pi WHERE {$wPi}) AS dias,
                (SELECT COALESCE(SUM(CASE WHEN pi.unidade='hora' THEN pi.quantidade END),0) FROM rh_producao_itens pi WHERE {$wPi}) AS horas,
                (SELECT COALESCE(SUM(CASE WHEN pi.modalidade='diaria'    THEN pi.valor_total END),0) FROM rh_producao_itens pi WHERE {$wPi}) AS diarias,
                (SELECT COALESCE(SUM(CASE WHEN pi.modalidade='producao'  THEN pi.valor_total END),0) FROM rh_producao_itens pi WHERE {$wPi}) AS producao,
                (SELECT COALESCE(SUM(CASE WHEN pi.modalidade='premiacao' THEN pi.valor_total END),0) FROM rh_producao_itens pi WHERE {$wPi}) AS premiacao,
                (SELECT COALESCE(SUM(fl.custo_total),0) FROM rh_folha_lancamentos fl
                   JOIN rh_folha_periodos fp ON fp.id=fl.periodo_id
                  WHERE fl.tenant_id=o.tenant_id AND fl.operador_id=o.id {$fl}) AS folha
           FROM agro_operadores o
          WHERE o.tenant_id=:t
          ORDER BY o.ativo DESC, o.nome";
    $rows = vero_rows($sql, [':t' => vero_tenant()]);
    return array_values(array_filter($rows, static fn($r) =>
        ((float)$r['dias'] + (float)$r['horas'] + (float)$r['diarias'] + (float)$r['producao'] + (float)$r['premiacao'] + (float)$r['folha']) > 0));
}

/* ── Export CSV do consolidado (antes de qualquer HTML) ─────── */
if (($_GET['csv'] ?? '') === '1' && $fOp === 0) {
    if (function_exists('bios_guard')) bios_guard('pessoas', 'historico_colaborador');
    elseif (function_exists('requirePermission')) requirePermission('pessoas.historico_colaborador.ver');

    $rows = hist_consolidado($fIni, $fFim);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="vero_historico_colaboradores_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ Excel
    fputcsv($out, ['Colaborador', 'Função', 'Vínculo', 'Dias', 'Horas', 'Diárias (R$)', 'Produção (R$)', 'Premiação (R$)', 'Custo folha (R$)'], ';');
    $tot = ['dias' => 0, 'horas' => 0.0, 'diarias' => 0.0, 'producao' => 0.0, 'premiacao' => 0.0, 'folha' => 0.0];
    foreach ($rows as $r) {
        fputcsv($out, [
            (string)$r['nome'],
            (string)($r['funcao'] ?? ''),
            HIST_VINCULOS[$r['tipo_vinculo']] ?? (string)$r['tipo_vinculo'],
            number_format((float)$r['dias'], 0, ',', ''),
            number_format((float)$r['horas'], 1, ',', ''),
            number_format((float)$r['diarias'], 2, ',', ''),
            number_format((float)$r['producao'], 2, ',', ''),
            number_format((float)$r['premiacao'], 2, ',', ''),
            number_format((float)$r['folha'], 2, ',', ''),
        ], ';');
        foreach ($tot as $k => $_) $tot[$k] += (float)$r[$k];
    }
    fputcsv($out, ['TOTAL', '', '',
        number_format($tot['dias'], 0, ',', ''),
        number_format($tot['horas'], 1, ',', ''),
        number_format($tot['diarias'], 2, ',', ''),
        number_format($tot['producao'], 2, ',', ''),
        number_format($tot['premiacao'], 2, ',', ''),
        number_format($tot['folha'], 2, ',', ''),
    ], ';');
    fclose($out);
    exit;
}

$colaboradores = vero_rows(
    "SELECT id, nome, funcao, tipo_vinculo FROM agro_operadores
      WHERE tenant_id = :t ORDER BY ativo DESC, nome", [':t' => $t]);

/* ── Modo DRILL-DOWN (um colaborador) ou CONSOLIDADO (todos) ── */
$colab = $fOp > 0
    ? vero_row("SELECT * FROM agro_operadores WHERE id = :i AND tenant_id = :t", [':i' => $fOp, ':t' => $t])
    : null;

$dias = [];
$folhas = [];
$resumo = ['dias' => 0, 'horas' => 0.0, 'diarias' => 0.0, 'producao' => 0.0, 'premiacao' => 0.0, 'folha' => 0.0];
$consol = [];
$totC   = ['dias' => 0, 'horas' => 0.0, 'diarias' => 0.0, 'producao' => 0.0, 'premiacao' => 0.0, 'folha' => 0.0];

if ($colab) {
    /* linha do tempo detalhada (drill-down): um registro por apontamento×dia
       do colaborador em rh_producao_itens. Horas = quantidade em unidade
       'hora'; Diária (R$) = itens de modalidade 'diaria' do dia. */
    $w = "pi.tenant_id = :t AND pi.origem_pessoa = 'colaborador' AND pi.operador_id = :o";
    $p = [':t' => $t, ':o' => $fOp];
    if ($fIni !== '') { $w .= " AND pi.data_trabalho >= :i"; $p[':i'] = $fIni; }
    if ($fFim !== '') { $w .= " AND pi.data_trabalho <= :f"; $p[':f'] = $fFim; }

    $linhas = vero_rows(
        "SELECT pi.data_trabalho, pi.apontamento_id,
                COALESCE(SUM(CASE WHEN pi.unidade='hora' THEN pi.quantidade END),0) AS horas,
                COALESCE(SUM(CASE WHEN pi.modalidade='diaria' THEN pi.valor_total END),0) AS custo,
                ta.nome AS atividade, tl.codigo AS talhao, fz.nome AS fazenda
           FROM rh_producao_itens pi
           LEFT JOIN agro_apontamentos a ON a.id = pi.apontamento_id
           LEFT JOIN agro_tipos_atividade ta ON ta.id = a.tipo_atividade_id
           LEFT JOIN agro_talhoes tl ON tl.id = a.talhao_id
           LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
          WHERE {$w}
          GROUP BY pi.apontamento_id, pi.data_trabalho, ta.nome, tl.codigo, fz.nome
          ORDER BY pi.data_trabalho DESC, pi.apontamento_id DESC LIMIT 300", $p);

    /* produção/premiação por apontamento×dia (diária fica fora: já está na
       coluna própria da linha do tempo — evita contar duas vezes) */
    $prodPorChave = [];
    foreach (vero_rows(
        "SELECT pi.apontamento_id, pi.data_trabalho, pi.modalidade,
                COALESCE(SUM(pi.quantidade),0) AS qtd, COALESCE(SUM(pi.valor_total),0) AS valor
           FROM rh_producao_itens pi WHERE {$w} AND pi.modalidade IN ('producao','premiacao')
          GROUP BY pi.apontamento_id, pi.data_trabalho, pi.modalidade", $p) as $r) {
        $k = $r['apontamento_id'] . '|' . $r['data_trabalho'];
        $prodPorChave[$k][(string)$r['modalidade']] = ['qtd' => (float)$r['qtd'], 'valor' => (float)$r['valor']];
        if ($r['modalidade'] === 'premiacao') $resumo['premiacao'] += (float)$r['valor'];
        else                                  $resumo['producao']  += (float)$r['valor'];
    }

    $diasVistos = [];
    foreach ($linhas as $l) {
        $k = $l['apontamento_id'] . '|' . $l['data_trabalho'];
        $dias[] = $l + ['prod' => $prodPorChave[$k] ?? []];
        $diasVistos[(string)$l['data_trabalho']] = true; /* dias = dias DISTINTOS apontados */
        $resumo['horas']   += (float)($l['horas'] ?? 0);
        $resumo['diarias'] += (float)($l['custo'] ?? 0);
    }
    $resumo['dias'] = count($diasVistos);

    $folhas = vero_rows(
        "SELECT fp.competencia, fp.status, fl.salario_base, fl.premiacoes_total,
                fl.total_bruto, fl.total_encargos, fl.custo_total
           FROM rh_folha_lancamentos fl
           JOIN rh_folha_periodos fp ON fp.id = fl.periodo_id
          WHERE fl.tenant_id = :t AND fl.operador_id = :o
          ORDER BY fp.competencia DESC LIMIT 24", [':t' => $t, ':o' => $fOp]);
    $resumo['folha'] = array_sum(array_map(static fn($fr) => (float)$fr['custo_total'], $folhas));
} else {
    /* consolidado de todos */
    $consol = hist_consolidado($fIni, $fFim);
    foreach ($consol as $r) {
        foreach ($totC as $k => $_) $totC[$k] += (float)$r[$k];
    }
}

$periodoTxt = ($fIni !== '' || $fFim !== '')
    ? (($fIni !== '' ? date('d/m/Y', strtotime($fIni)) : '…') . ' – ' . ($fFim !== '' ? date('d/m/Y', strtotime($fFim)) : '…'))
    : 'todo o período';
$qs = static fn(array $extra = []) => http_build_query(array_filter(array_merge(
    ['colaborador' => $fOp ?: null, 'ini' => $fIni ?: null, 'fim' => $fFim ?: null], $extra), static fn($v) => $v !== null && $v !== ''));

$GUARD      = ['macro' => 'pessoas', 'micro' => 'historico_colaborador'];
$PAGE_VIEW  = 'pessoas_historico_colaborador';
$PAGE_TITLE = 'Histórico por Colaborador';
$EXTRA_HEAD = vero_assets() . '<style media="print">.vsidebar,.vtopbar,.no-print{display:none !important}</style>';
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Histórico por Colaborador',
        $colab ? 'Linha do tempo detalhada do colaborador' : 'Relatório consolidado — dias, produção, premiações e folha por colaborador no período', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center" class="no-print">
        <select name="colaborador" onchange="this.form.submit()">
          <option value="">Todos os colaboradores (consolidado)</option>
          <?php foreach ($colaboradores as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= $fOp === (int)$c['id'] ? ' selected' : '' ?>>
              <?= h($c['nome'] . ($c['funcao'] ? ' — ' . $c['funcao'] : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <label class="vhint" style="margin:0">Período</label>
        <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub"><?= h($periodoTxt) ?></span>
      <?php if (!$colab): ?>
        <a class="vbtn vbtn-ghost vbtn-sm no-print" href="?<?= h($qs(['csv' => '1'])) ?>">Exportar CSV</a>
      <?php else: ?>
        <a class="vbtn vbtn-ghost vbtn-sm no-print" href="?<?= h($qs(['colaborador' => ''])) ?>">← Consolidado</a>
      <?php endif; ?>
      <button class="vbtn vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button>
    </div>
  </div>

  <?php if (!$colab): /* ═══ CONSOLIDADO (todos) ═══ */ ?>
    <div class="vcard">
      <div class="vtoolbar"><strong>Colaboradores no período</strong>
        <span class="vsub"><?= count($consol) ?> com movimentação</span></div>
      <?php if (!$consol): ?>
        <div class="vempty">Nenhuma movimentação de colaboradores no período selecionado.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table class="vtable">
        <thead><tr>
          <th>Colaborador</th><th>Função</th><th>Vínculo</th>
          <th style="text-align:right">Dias</th>
          <th style="text-align:right">Horas</th>
          <th style="text-align:right">Diárias (R$)</th>
          <th style="text-align:right">Produção (R$)</th>
          <th style="text-align:right">Premiação (R$)</th>
          <th style="text-align:right">Custo folha (R$)</th>
          <th class="no-print"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($consol as $r): ?>
          <tr<?= (int)$r['ativo'] === 0 ? ' class="is-off"' : '' ?>>
            <td><strong><?= h($r['nome']) ?></strong></td>
            <td><?= h($r['funcao'] ?? '') ?: '—' ?></td>
            <td><span class="vbadge <?= $r['tipo_vinculo'] === 'clt' ? 'vb-info' : 'vb-warn' ?>"><?= h(HIST_VINCULOS[$r['tipo_vinculo']] ?? $r['tipo_vinculo']) ?></span></td>
            <td class="vnum" style="text-align:right"><?= (int)$r['dias'] ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$r['horas'], 1) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$r['diarias'], 2) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$r['producao'], 2) ?></td>
            <td class="vnum" style="text-align:right;color:var(--vero-ok,#1a7f4b)"><?= numFmt((float)$r['premiacao'], 2) ?></td>
            <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['folha'], 2) ?></strong></td>
            <td class="no-print" style="text-align:right"><a class="vbtn vbtn-ghost vbtn-sm" href="?<?= h($qs(['colaborador' => (string)(int)$r['id']])) ?>">Detalhe</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th>TOTAL</th><th></th><th></th>
            <th class="vnum" style="text-align:right"><?= (int)$totC['dias'] ?></th>
            <th class="vnum" style="text-align:right"><?= numFmt($totC['horas'], 1) ?></th>
            <th class="vnum" style="text-align:right"><?= numFmt($totC['diarias'], 2) ?></th>
            <th class="vnum" style="text-align:right"><?= numFmt($totC['producao'], 2) ?></th>
            <th class="vnum" style="text-align:right"><?= numFmt($totC['premiacao'], 2) ?></th>
            <th class="vnum" style="text-align:right"><?= numFmt($totC['folha'], 2) ?></th>
            <th class="no-print"></th>
          </tr>
        </tfoot>
      </table>
      </div>
      <?php endif; ?>
    </div>

  <?php else: /* ═══ DRILL-DOWN (um colaborador) ═══ */ ?>
    <div class="vcard" style="margin-bottom:14px">
      <div class="vtoolbar"><strong><?= h((string)$colab['nome']) ?></strong>
        <span class="vsub"><span class="vbadge vb-info"><?= h(HIST_VINCULOS[$colab['tipo_vinculo']] ?? str_replace('_', ' ', (string)$colab['tipo_vinculo'])) ?></span>
          <?= $colab['salario_mensal'] !== null ? ' salário R$ ' . numFmt((float)$colab['salario_mensal'], 2) : '' ?></span></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;padding:12px 14px">
        <div class="vkpi"><span class="vhint">Dias apontados</span>
          <strong class="vnum" style="font-size:1.15rem"><?= (int)$resumo['dias'] ?></strong></div>
        <div class="vkpi"><span class="vhint">Diárias (R$)</span>
          <strong class="vnum" style="font-size:1.15rem"><?= numFmt($resumo['diarias'], 2) ?></strong></div>
        <div class="vkpi"><span class="vhint">Produção (R$)</span>
          <strong class="vnum" style="font-size:1.15rem"><?= numFmt($resumo['producao'], 2) ?></strong></div>
        <div class="vkpi"><span class="vhint">Premiações (R$)</span>
          <strong class="vnum" style="font-size:1.15rem;color:var(--vero-ok,#1a7f4b)"><?= numFmt($resumo['premiacao'], 2) ?></strong></div>
        <div class="vkpi"><span class="vhint">Folhas (custo c/ encargos)</span>
          <strong class="vnum" style="font-size:1.15rem"><?= numFmt($resumo['folha'], 2) ?></strong></div>
      </div>
    </div>

    <div class="vcard" style="margin-bottom:14px">
      <div class="vtoolbar"><strong>Dias trabalhados</strong>
        <span class="vsub"><?= count($dias) ?> registro(s)<?= count($dias) === 300 ? ' (últimos 300)' : '' ?></span></div>
      <?php if (!$dias): ?>
        <div class="vempty">Nenhum apontamento no período.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr>
          <th>Data</th><th>Atividade</th><th>Válvula</th>
          <th style="text-align:right">Horas</th>
          <th style="text-align:right">Diária (R$)</th>
          <th style="text-align:right">Produção</th>
          <th style="text-align:right">Premiação (R$)</th>
        </tr></thead>
        <tbody>
        <?php foreach ($dias as $d):
            $prodDia = $d['prod']['producao'] ?? null;
            $premDia = $d['prod']['premiacao'] ?? null; ?>
          <tr>
            <td class="vnum"><strong><?= date('d/m/Y', strtotime((string)$d['data_trabalho'])) ?></strong></td>
            <td><?= h($d['atividade'] ?? '—') ?></td>
            <td><?= h(trim(($d['fazenda'] ?? '') . ' — ' . ($d['talhao'] ?? ''), ' —') ?: '—') ?></td>
            <td class="vnum" style="text-align:right"><?= (float)$d['horas'] > 0 ? numFmt((float)$d['horas'], 1) : '—' ?></td>
            <td class="vnum" style="text-align:right"><?= (float)$d['custo'] > 0 ? numFmt((float)$d['custo'], 2) : '—' ?></td>
            <td class="vnum" style="text-align:right"><?= $prodDia
                ? numFmt($prodDia['qtd'], 0) . ' <span class="vhint">un</span> · R$ ' . numFmt($prodDia['valor'], 2) : '—' ?></td>
            <td class="vnum" style="text-align:right;color:var(--vero-ok,#1a7f4b)"><?= $premDia ? numFmt($premDia['valor'], 2) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong>Folhas geradas</strong>
        <span class="vsub"><?= count($folhas) ?> período(s)</span></div>
      <?php if (!$folhas): ?>
        <div class="vempty">Nenhuma folha gerada para este colaborador.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr>
          <th>Período</th><th>Status</th>
          <th style="text-align:right">Salário base (R$)</th>
          <th style="text-align:right">Premiações (R$)</th>
          <th style="text-align:right">Bruto (R$)</th>
          <th style="text-align:right">Encargos (R$)</th>
          <th style="text-align:right">Custo total (R$)</th>
        </tr></thead>
        <tbody>
        <?php foreach ($folhas as $fRow): ?>
          <tr>
            <td class="vnum"><strong><?= date('m/Y', strtotime((string)$fRow['competencia'])) ?></strong></td>
            <td><span class="vbadge <?= $fRow['status'] === 'fechado' ? 'vb-ok' : 'vb-warn' ?>"><?= h(ucfirst((string)$fRow['status'])) ?></span></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$fRow['salario_base'], 2) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$fRow['premiacoes_total'], 2) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$fRow['total_bruto'], 2) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$fRow['total_encargos'], 2) ?></td>
            <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$fRow['custo_total'], 2) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
