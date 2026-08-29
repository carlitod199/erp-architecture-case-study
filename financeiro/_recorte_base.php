<?php
/* ============================================================
   VERO — Financeiro / base compartilhada de recortes do razão
   Incluída por despesas.php e recebimentos.php, que definem:
     $RC_TIPO   = 'pagar' | 'receber'
     $RC_MICRO, $RC_VIEW, $RC_TITULO, $RC_SUB
   Leitura pura de movimentacoes_financeiras com status 'pago'
   (caixa efetivo) — manutenção dos títulos fica em Contas a
   Pagar/Receber; aqui é a visão gerencial do realizado.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$fAno    = (int)($_GET['ano'] ?? date('Y'));
if ($fAno < 2000 || $fAno > 2100) $fAno = (int)date('Y');
$fMes    = (int)($_GET['mes'] ?? 0);
$fOrigem = (string)($_GET['origem'] ?? '');
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 25;

$where  = "m.tenant_id = :t AND m.tipo = :tp AND m.status = 'pago' AND YEAR(m.data_pagamento) = :a";
$params = [':t' => vero_tenant(), ':tp' => $RC_TIPO, ':a' => $fAno];
if ($fMes >= 1 && $fMes <= 12) { $where .= " AND MONTH(m.data_pagamento) = :m"; $params[':m'] = $fMes; }
if ($fOrigem === 'manual')     { $where .= " AND m.origem_tipo IS NULL"; }
elseif ($fOrigem !== '')       { $where .= " AND m.origem_tipo = :o"; $params[':o'] = $fOrigem; }

/* ── Export CSV (antes de qualquer HTML) ─────────────────────────
   Relatório read-only: baixa as MESMAS linhas já filtradas (SEM a
   paginação da tela). Reusa o helper compartilhado vero_csv_stream
   (compras/_export.php) e o guard canônico bios_guard — como o CSV
   roda antes do header, o guard é chamado manualmente (mesma proteção
   da página). O slug do arquivo é o próprio micro (despesas/recebimentos). */
if (($_GET['csv'] ?? '') === '1') {
    require_once __DIR__ . '/../includes/menu_agro.php';
    bios_guard('financeiro', $RC_MICRO);
    require_once __DIR__ . '/../compras/_export.php';
    $rowsCsv = vero_rows(
        "SELECT m.data_pagamento, m.descricao, m.origem_tipo,
                m.data_competencia, m.data_vencimento, m.valor
           FROM movimentacoes_financeiras m
          WHERE {$where}
          ORDER BY m.data_pagamento DESC, m.id DESC", $params);
    foreach ($rowsCsv as &$rCsv) {
        $rCsv['origem_tipo'] = $rCsv['origem_tipo'] !== null
            ? str_replace('_', ' ', (string)$rCsv['origem_tipo']) : 'manual';
    }
    unset($rCsv);
    $colunas = [
        'data_pagamento'   => 'Pagamento',
        'descricao'        => 'Descrição',
        'origem_tipo'      => 'Origem',
        'data_competencia' => 'Competência',
        'data_vencimento'  => 'Vencimento',
        'valor'            => 'Valor (R$)',
    ];
    $formato = [
        'data_pagamento' => 'data', 'data_competencia' => 'data',
        'data_vencimento' => 'data', 'valor' => 'dec2',
    ];
    vero_csv_stream('financeiro', $RC_MICRO, $rowsCsv, $colunas, $formato);
}

$anos = array_map('intval', array_column(vero_rows(
    "SELECT DISTINCT YEAR(data_pagamento) AS a FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND tipo = :tp AND status = 'pago' AND data_pagamento IS NOT NULL
      ORDER BY a DESC", [':t' => vero_tenant(), ':tp' => $RC_TIPO]), 'a'));
if (!in_array($fAno, $anos, true)) $anos[] = $fAno;

$origens = array_map('strval', array_column(vero_rows(
    "SELECT DISTINCT origem_tipo FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND tipo = :tp AND status = 'pago' AND origem_tipo IS NOT NULL
      ORDER BY origem_tipo", [':t' => vero_tenant(), ':tp' => $RC_TIPO]), 'origem_tipo'));

$tot = vero_row("SELECT COUNT(*) AS linhas, COALESCE(SUM(m.valor),0) AS total
                   FROM movimentacoes_financeiras m WHERE {$where}", $params);

/* Resumo mensal do ano (ignora o filtro de mês para dar contexto) */
$whereAno  = "m.tenant_id = :t AND m.tipo = :tp AND m.status = 'pago' AND YEAR(m.data_pagamento) = :a";
$paramsAno = [':t' => vero_tenant(), ':tp' => $RC_TIPO, ':a' => $fAno];
if ($fOrigem === 'manual')  { $whereAno .= " AND m.origem_tipo IS NULL"; }
elseif ($fOrigem !== '')    { $whereAno .= " AND m.origem_tipo = :o"; $paramsAno[':o'] = $fOrigem; }
$porMes = [];
foreach (vero_rows("SELECT MONTH(m.data_pagamento) AS mes, COALESCE(SUM(m.valor),0) AS v
                      FROM movimentacoes_financeiras m WHERE {$whereAno}
                     GROUP BY MONTH(m.data_pagamento)", $paramsAno) as $r) {
    $porMes[(int)$r['mes']] = (float)$r['v'];
}
$maxMes = $porMes ? max($porMes) : 0.0;

$rows = vero_rows(
    "SELECT m.* FROM movimentacoes_financeiras m
      WHERE {$where}
      ORDER BY m.data_pagamento DESC, m.id DESC
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

$NOME_MES = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];

$GUARD      = ['macro' => 'financeiro', 'micro' => $RC_MICRO];
$PAGE_VIEW  = $RC_VIEW;
$PAGE_TITLE = $RC_TITULO;
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$corBarra = $RC_TIPO === 'receber' ? 'var(--vero-ok,#1a7f4b)' : '#b3261e';
$telaOrigem = $RC_TIPO === 'receber' ? 'contas_receber.php' : 'contas_pagar.php';
$qsBase = http_build_query(array_filter(['ano' => $fAno, 'mes' => $fMes ?: null, 'origem' => $fOrigem ?: null]));
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header($RC_TITULO, $RC_SUB, null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="ano" onchange="this.form.submit()">
          <?php foreach ($anos as $a): ?>
            <option value="<?= $a ?>"<?= $a === $fAno ? ' selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
        <select name="mes" onchange="this.form.submit()">
          <option value="0">Todos os meses</option>
          <?php foreach ($NOME_MES as $n => $nm): ?>
            <option value="<?= $n ?>"<?= $n === $fMes ? ' selected' : '' ?>><?= $nm ?></option>
          <?php endforeach; ?>
        </select>
        <select name="origem" onchange="this.form.submit()">
          <option value="">Todas as origens</option>
          <option value="manual"<?= $fOrigem === 'manual' ? ' selected' : '' ?>>Manuais</option>
          <?php foreach ($origens as $o): ?>
            <option value="<?= h($o) ?>"<?= $fOrigem === $o ? ' selected' : '' ?>><?= h(str_replace('_', ' ', $o)) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= (int)$tot['linhas'] ?> registro(s) ·
        total <strong class="vnum">R$ <?= numFmt((float)$tot['total'], 2) ?></strong>
        <?php if ((int)$tot['linhas'] > 0): ?>
          <a class="vbtn vbtn-ghost vbtn-sm no-print" href="?<?= h($qsBase) ?>&csv=1">Exportar CSV</a>
          <button class="vbtn vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button>
        <?php endif; ?></span>
    </div>

    <?php if ($porMes): ?>
    <div style="display:grid;grid-template-columns:repeat(12,1fr);gap:6px;padding:12px 14px;align-items:end">
      <?php for ($m = 1; $m <= 12; $m++):
          $v = $porMes[$m] ?? 0.0;
          $hpx = $maxMes > 0 ? max(3, (int)round(52 * $v / $maxMes)) : 3; ?>
        <a href="?ano=<?= $fAno ?>&mes=<?= $m ?>&origem=<?= h($fOrigem) ?>"
           style="text-decoration:none;text-align:center" title="<?= $NOME_MES[$m] ?>: R$ <?= numFmt($v, 2) ?>">
          <div style="height:56px;display:flex;align-items:flex-end;justify-content:center">
            <div style="width:70%;height:<?= $hpx ?>px;border-radius:3px 3px 0 0;
                        background:<?= $v > 0 ? $corBarra : 'var(--vero-border,#e3e3e3)' ?>;
                        <?= $m === $fMes ? 'outline:2px solid #333;' : '' ?>"></div>
          </div>
          <span class="vhint" style="font-size:.72rem"><?= $NOME_MES[$m] ?></span>
        </a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum lançamento pago no período — as baixas são feitas em
        <a href="<?= h(rtrim(BIOS_BASE, '/')) ?>/financeiro/<?= $telaOrigem ?>">Contas a <?= $RC_TIPO === 'receber' ? 'Receber' : 'Pagar' ?></a>.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Pagamento</th><th>Descrição</th><th>Origem</th>
        <th>Competência</th><th>Vencimento</th>
        <th style="text-align:right">Valor (R$)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $m): ?>
        <tr>
          <td class="vnum"><strong><?= $m['data_pagamento'] ? date('d/m/Y', strtotime((string)$m['data_pagamento'])) : '—' ?></strong></td>
          <td><strong><?= h($m['descricao'] ?? '—') ?></strong></td>
          <td><?= $m['origem_tipo'] !== null
                ? '<span class="vbadge vb-info">' . h(str_replace('_', ' ', (string)$m['origem_tipo'])) . '</span>'
                : '<span class="vhint">manual</span>' ?></td>
          <td class="vnum"><?= $m['data_competencia'] ? date('d/m/Y', strtotime((string)$m['data_competencia'])) : '—' ?></td>
          <td class="vnum"><?= $m['data_vencimento'] ? date('d/m/Y', strtotime((string)$m['data_vencimento'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$m['valor'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, (int)$tot['linhas'], $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
