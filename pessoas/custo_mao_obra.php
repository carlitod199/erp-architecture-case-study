<?php
/* ============================================================
   VERO — Pessoas / Custo de Mão de Obra  (tela real, leitura)
   Substitui o mock. Rota: /pessoas/custo_mao_obra.php
   Guard: pessoas.custo_mao_obra
   Consolida por pessoa: dias/horas/diárias/produção/premiação
   dos apontamentos (rh_producao_itens, por modalidade) e custo
   da folha com encargos (rh_folha_lancamentos). Custeio MDO
   total no cabeçalho.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$fIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';

$t = vero_tenant();

/* Custeio MDO no período (o que foi para o custo da produção) */
$wCust = "tenant_id = :t AND categoria = 'mao_de_obra'";
$pCust = [':t' => $t];
if ($fIni !== '') { $wCust .= " AND data_competencia >= :i"; $pCust[':i'] = $fIni; }
if ($fFim !== '') { $wCust .= " AND data_competencia <= :f"; $pCust[':f'] = $fFim; }
$custeioMdo = (float)vero_val("SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE {$wCust}", $pCust);

/* Apontamentos por pessoa (rh_producao_itens é a fonte ÚNICA do realizado
   de MDO — mesma origem do custeio 'rh_producao_item'). A modalidade separa
   as colunas: diária (terceirizado, quantidade = nº de diárias), produção e
   premiação. Dias = dias distintos com apontamento; horas = quantidade
   apontada em unidade 'hora'. */
$wAp = "pi.tenant_id = :t";
$pAp = [':t' => $t];
if ($fIni !== '') { $wAp .= " AND pi.data_trabalho >= :i"; $pAp[':i'] = $fIni; }
if ($fFim !== '') { $wAp .= " AND pi.data_trabalho <= :f"; $pAp[':f'] = $fFim; }
$apont = vero_rows(
    "SELECT pi.origem_pessoa, pi.operador_id, pi.terceirizado_id,
            COUNT(DISTINCT pi.data_trabalho) AS dias,
            COALESCE(SUM(CASE WHEN pi.unidade = 'hora' THEN pi.quantidade END), 0) AS horas,
            COALESCE(SUM(CASE WHEN pi.modalidade = 'diaria'    THEN pi.valor_total END), 0) AS diarias,
            COALESCE(SUM(CASE WHEN pi.modalidade = 'producao'  THEN pi.valor_total END), 0) AS producao,
            COALESCE(SUM(CASE WHEN pi.modalidade = 'premiacao' THEN pi.valor_total END), 0) AS premiacao
       FROM rh_producao_itens pi WHERE {$wAp}
      GROUP BY pi.origem_pessoa, pi.operador_id, pi.terceirizado_id", $pAp);

/* Folha (custo total com encargos) por operador no período (competência do período da folha) */
$folha = vero_rows(
    "SELECT fl.operador_id, COALESCE(SUM(fl.custo_total),0) AS custo
       FROM rh_folha_lancamentos fl
       JOIN rh_folha_periodos fp ON fp.id = fl.periodo_id
      WHERE fl.tenant_id = :t" .
      ($fIni !== '' ? " AND LAST_DAY(fp.competencia) >= :i" : '') .
      ($fFim !== '' ? " AND fp.competencia <= :f" : '') . "
      GROUP BY fl.operador_id",
    array_merge([':t' => $t], $fIni !== '' ? [':i' => $fIni] : [], $fFim !== '' ? [':f' => $fFim] : []));

/* ── R3-02: da folha acima, quanto JÁ entrou no custeio? Só o FECHAMENTO
   do período emite salário+encargos ao custeio (pessoas/folha.php, A3-T3;
   origem_tipo='rh_folha_lancamento'). O que ainda não entrou explica a
   divergência custeio MDO × consolidado por pessoa do cabeçalho —
   produção/diárias/premiação entram por rh_producao_itens (outro caminho,
   já no custeio); a folha CLT de período ABERTO não está no custo de
   produção nem no DRE. Disclosure apenas — alocação real pendente (A0). */
$folhaTotal = array_sum(array_map(static fn($r) => (float)$r['custo'], $folha));
$folhaNoCusteio = (float)vero_val(
    "SELECT COALESCE(SUM(cl.valor),0)
       FROM custeio_lancamentos cl
       JOIN rh_folha_lancamentos fl ON fl.id = cl.origem_id AND fl.tenant_id = cl.tenant_id
       JOIN rh_folha_periodos fp ON fp.id = fl.periodo_id
      WHERE cl.tenant_id = :t AND cl.origem_tipo = 'rh_folha_lancamento'" .
      ($fIni !== '' ? " AND LAST_DAY(fp.competencia) >= :i" : '') .
      ($fFim !== '' ? " AND fp.competencia <= :f" : ''),
    array_merge([':t' => $t], $fIni !== '' ? [':i' => $fIni] : [], $fFim !== '' ? [':f' => $fFim] : []));
$folhaNaoAlocada = max(0.0, $folhaTotal - $folhaNoCusteio);

/* Monta o mapa por pessoa */
$pessoas = [];
$chave = static function (string $tipo, int $id) { return $tipo . ':' . $id; };
$nova = static fn(string $nome, string $vinculo) => [
    'nome' => $nome, 'vinculo' => $vinculo,
    'dias' => 0, 'horas' => 0.0, 'diarias' => 0.0, 'producao' => 0.0, 'premiacao' => 0.0, 'folha' => 0.0,
];

$operadores = [];
foreach (vero_rows("SELECT id, nome, tipo_vinculo FROM agro_operadores WHERE tenant_id = :t", [':t' => $t]) as $o) {
    $operadores[(int)$o['id']] = $o;
}
$terceirizados = [];
foreach (vero_rows("SELECT id, nome FROM rh_terceirizados WHERE tenant_id = :t", [':t' => $t]) as $o) {
    $terceirizados[(int)$o['id']] = $o;
}

foreach ($apont as $a) {
    if ($a['origem_pessoa'] === 'terceirizado' && $a['terceirizado_id'] !== null) {
        $tc = $terceirizados[(int)$a['terceirizado_id']] ?? null;
        $k = $chave('tc', (int)$a['terceirizado_id']);
        $pessoas[$k] = $pessoas[$k] ?? $nova($tc['nome'] ?? ('Terceirizado #' . $a['terceirizado_id']), 'terceirizado');
    } elseif ($a['operador_id'] !== null) {
        $op = $operadores[(int)$a['operador_id']] ?? null;
        $k = $chave('op', (int)$a['operador_id']);
        $pessoas[$k] = $pessoas[$k] ?? $nova($op['nome'] ?? ('Operador #' . $a['operador_id']), $op['tipo_vinculo'] ?? '—');
    } else {
        continue;
    }
    $pessoas[$k]['dias']      += (int)$a['dias'];
    $pessoas[$k]['horas']     += (float)$a['horas'];
    $pessoas[$k]['diarias']   += (float)$a['diarias'];
    $pessoas[$k]['producao']  += (float)$a['producao'];
    $pessoas[$k]['premiacao'] += (float)$a['premiacao'];
}
foreach ($folha as $fRow) {
    if ($fRow['operador_id'] === null) continue;
    $op = $operadores[(int)$fRow['operador_id']] ?? null;
    $k = $chave('op', (int)$fRow['operador_id']);
    $pessoas[$k] = $pessoas[$k] ?? $nova($op['nome'] ?? ('Operador #' . $fRow['operador_id']), $op['tipo_vinculo'] ?? '—');
    $pessoas[$k]['folha'] += (float)$fRow['custo'];
}

foreach ($pessoas as &$p) {
    $p['total'] = $p['diarias'] + $p['producao'] + $p['premiacao'] + $p['folha'];
}
unset($p);
uasort($pessoas, static fn($a, $b) => $b['total'] <=> $a['total']);
$totGeral = array_sum(array_column($pessoas, 'total'));

$GUARD      = ['macro' => 'pessoas', 'micro' => 'custo_mao_obra'];
$PAGE_VIEW  = 'pessoas_custo_mao_obra';
$PAGE_TITLE = 'Custo de Mão de Obra';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Custo de Mão de Obra', 'Diárias e produção dos apontamentos + folha com encargos, consolidado por pessoa', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <label class="vhint">Período</label>
        <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub">custeio MDO no período: <strong class="vnum">R$ <?= numFmt($custeioMdo, 2) ?></strong> ·
        consolidado por pessoa: <strong class="vnum">R$ <?= numFmt($totGeral, 2) ?></strong></span>
    </div>
    <?php /* R3-02: o aviso "Por que os dois números diferem?" (folha não alocada)
             foi RETIRADO a pedido do gestor (18/08). $folhaNaoAlocada segue
             calculada para o consolidado; a divergência fica sem callout. */ ?>
    <?php if (!$pessoas): ?>
      <div class="vempty">Nenhum custo de mão de obra no período — apontamentos e folha alimentam esta leitura.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Pessoa</th><th>Vínculo</th>
        <th style="text-align:right">Dias</th>
        <th style="text-align:right">Horas</th>
        <th style="text-align:right">Diárias (R$)</th>
        <th style="text-align:right">Produção (R$)</th>
        <th style="text-align:right">Premiação (R$)</th>
        <th style="text-align:right">Folha c/ encargos (R$)</th>
        <th style="text-align:right">Total (R$)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($pessoas as $p): ?>
        <tr>
          <td><strong><?= h($p['nome']) ?></strong></td>
          <td><span class="vbadge vb-info"><?= h(str_replace('_', ' ', (string)$p['vinculo'])) ?></span></td>
          <td class="vnum" style="text-align:right"><?= (int)$p['dias'] ?></td>
          <td class="vnum" style="text-align:right"><?= $p['horas'] > 0 ? numFmt($p['horas'], 1) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $p['diarias'] > 0 ? numFmt($p['diarias'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $p['producao'] > 0 ? numFmt($p['producao'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $p['premiacao'] > 0 ? numFmt($p['premiacao'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $p['folha'] > 0 ? numFmt($p['folha'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($p['total'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">
      Diárias/produção/premiação vêm dos apontamentos de campo; a folha soma o custo total com encargos dos períodos
      gerados em Pessoas → Folha. O custeio MDO do cabeçalho é o que foi lançado no custo da produção — folha
      administrativa e encargos podem não estar amarrados a talhão.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
