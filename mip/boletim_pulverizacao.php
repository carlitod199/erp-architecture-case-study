<?php
/* ============================================================
   VERO — MIP / Boletim Diário de Pulverização  (P-102, A1-DF-refino)
   Rota: /mip/boletim_pulverizacao.php?data=YYYY-MM-DD[&serie=DF|IF][&fazenda=N]
   Guard: mip.aplicacoes_defensivos.ver (leitura; página de impressão, sem menu)

   Documento ÚNICO que CONSOLIDA todas as DFs/IFs de uma DATA numa tabela —
   UMA linha por documento, PRESERVANDO número, carência e RT de cada
   (P-102: "não fundir caldas numa DF" — o boletim agrupa para IMPRESSÃO,
   os documentos seguem distintos e rastreáveis). Regra 1: tudo é REGISTRO.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/print_doc.php'; // A0-22/P-106: cabeçalho/rodapé canônico (logo VERO + emissor)

vero_require('mip.aplicacoes_defensivos.ver');

$t = vero_tenant();

const TIPOS_BOL = [
    'pulverizacao' => 'Pulverização', 'fertirrigacao' => 'Fertirrigação',
    'foliar' => 'Adubação foliar', 'indutor_brotacao' => 'Indutor de brotação',
    'tratamento' => 'Tratamento', 'outro' => 'Outro',
];
const STATUS_BOL = [
    'planejada' => 'Emitida', 'registrada' => 'Registrada',
    'validada' => 'Validada (RT)', 'cancelada' => 'Cancelada',
];
const BICO_BOL = [
    'laranja' => 'Laranja', 'verde' => 'Verde', 'amarelo' => 'Amarelo', 'lilas' => 'Lilás',
    'azul' => 'Azul', 'vermelho' => 'Vermelho', 'marrom' => 'Marrom', 'cinza' => 'Cinza', 'branco' => 'Branco', 'outro' => 'Outro',
];

/* #53: o boletim cobre um PERÍODO (N dias), não só o dia. data_fim = data final
   (default hoje; aceita ?data legado); dias = quantos dias para trás (inclui o fim). */
$dataFim = (($d = (string)($_GET['data_fim'] ?? $_GET['data'] ?? '')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : date('Y-m-d');
$dias    = (int)($_GET['dias'] ?? 1);
if ($dias < 1)  { $dias = 1; }
if ($dias > 92) { $dias = 92; }   // teto de ~3 meses
$dataIni = date('Y-m-d', strtotime($dataFim . ' -' . ($dias - 1) . ' days'));
$fSerie  = in_array((string)($_GET['serie'] ?? ''), ['DF', 'IF'], true) ? (string)$_GET['serie'] : '';
$fFaz    = (int)($_GET['fazenda'] ?? 0);

$where  = "ap.tenant_id = :t AND ap.data BETWEEN :di AND :df AND ap.status <> 'cancelada'";
$params = [':t' => $t, ':di' => $dataIni, ':df' => $dataFim];
if ($fSerie !== '') { $where .= " AND ap.doc_serie = :sr"; $params[':sr'] = $fSerie; }
if ($fFaz > 0)      { $where .= " AND ap.fazenda_id = :f"; $params[':f'] = $fFaz; }

$dfs = vero_rows(
    "SELECT ap.id, ap.data, ap.doc_serie, ap.doc_numero, ap.tipo, ap.status,
            ap.area_aplicada_ha, ap.volume_calda_l, ap.parametros_aplicacao,
            tl.codigo AS talhao, fz.nome AS fazenda, sa.identificacao AS safra,
            rt.nome AS rt_nome
       FROM agro_aplicacoes ap
       LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = ap.fazenda_id
       LEFT JOIN agro_safras sa ON sa.id = ap.safra_id
       LEFT JOIN agro_operadores rt ON rt.id = ap.responsavel_tecnico_id
      WHERE {$where}
      ORDER BY ap.data, ap.doc_serie, ap.doc_numero, ap.id",
    $params);

/* itens de todas as DFs do dia, agrupados por aplicação (1 query) */
$itensPorDf = [];
if ($dfs) {
    $ids = array_map(static fn($r) => (int)$r['id'], $dfs);
    $ph  = [];
    $pIt = [':t' => $t];
    foreach ($ids as $i => $id) { $ph[] = ":a{$i}"; $pIt[":a{$i}"] = $id; }
    $itens = vero_rows(
        "SELECT it.aplicacao_id, it.dose_valor, it.dose_unidade, it.carencia_dias,
                p.nome AS produto, p.registro_mapa, p.nao_registrado, p.tipo_insumo
           FROM agro_aplicacao_itens it
           LEFT JOIN estoque_produtos p ON p.id = it.produto_id
          WHERE it.tenant_id = :t AND it.aplicacao_id IN (" . implode(',', $ph) . ")
          ORDER BY it.id",
        $pIt);
    foreach ($itens as $it) $itensPorDf[(int)$it['aplicacao_id']][] = $it;
}

$empresa = vero_val("SELECT nome FROM tenants WHERE id = :t", [':t' => $t]) ?: 'VERO';
$dataFimFmt = date('d/m/Y', strtotime($dataFim));
$dataIniFmt = date('d/m/Y', strtotime($dataIni));
$periodoFmt = $dias > 1 ? ($dataIniFmt . ' a ' . $dataFimFmt) : $dataFimFmt;
$hoje    = date('d/m/Y H:i');
/* N-01 / D-6 (Caminho A): documento OFICIAL lista SÓ produtos COM registro MAPA
   (registro_mapa preenchido). Os "sem registro" seguem lançados na aplicação e
   aparecem na relação do aplicador (tela mip/aplicacoes) — aqui só se conta
   quantos foram omitidos, para o rodapé. */
$totalSemReg = 0;
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Boletim de Pulverização — <?= h($periodoFmt) ?></title>
<?= print_doc_css() /* A0-22: cabeçalho/rodapé canônico; o @page landscape abaixo prevalece (cascata) */ ?>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font: 12px/1.45 "IBM Plex Sans", Arial, sans-serif; color: #1F2421; background: #F4F1E9; padding: 16px; }
  .doc { background: #fff; border: 1px solid #C9C1AE; border-radius: 6px; max-width: 1000px; margin: 0 auto; padding: 22px 26px; }
  .topo { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #005059; padding-bottom: 10px; }
  h1 { font-size: 19px; letter-spacing: .4px; color: #005059; }
  .sub { font-size: 11px; color: #6B7069; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; margin-top: 14px; }
  th, td { border: 1px solid #C9C1AE; padding: 5px 7px; text-align: left; vertical-align: top; font-size: 11px; }
  th { background: #EFEBE0; font-size: 10px; text-transform: uppercase; letter-spacing: .4px; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .docnum { font-weight: 700; color: #005059; white-space: nowrap; }
  .prod { font-size: 10.5px; }
  .prod em { color: #6B7069; font-style: normal; }
  .warn { color: #9A3B2A; font-weight: 600; }
  .assin { display: grid; grid-template-columns: repeat(2, 1fr); gap: 40px; margin-top: 34px; }
  .assin div { border-top: 1px solid #1F2421; text-align: center; font-size: 10.5px; padding-top: 3px; }
  .foot { font-size: 9.5px; color: #6B7069; margin-top: 18px; border-top: 1px solid #C9C1AE; padding-top: 6px; }
  .empty { padding: 30px; text-align: center; color: #6B7069; }
  .toolbar { max-width: 1000px; margin: 0 auto 14px; display: flex; gap: 10px; align-items: center; }
  .toolbar button, .toolbar a { font: 13px "IBM Plex Sans", Arial; padding: 8px 16px; border-radius: 6px;
    border: 1px solid #005059; background: #005059; color: #fff; cursor: pointer; text-decoration: none; }
  .toolbar a.ghost { background: #fff; color: #005059; }
  .toolbar input { font: 13px "IBM Plex Sans", Arial; padding: 7px 10px; border: 1px solid #C9C1AE; border-radius: 6px; }
  @page { size: A4 landscape; margin: 10mm; }
  @media print { body { background: #fff; padding: 0; } .toolbar { display: none; }
                 .doc { border: none; max-width: none; margin: 0; padding: 0; } }
</style>
</head>
<body>
<div class="toolbar">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex:1">
    <label>Últimos</label>
    <input type="number" name="dias" value="<?= $dias ?>" min="1" max="92" style="width:66px" onchange="this.form.submit()">
    <label>dia(s) até</label>
    <input type="date" name="data_fim" value="<?= h($dataFim) ?>" onchange="this.form.submit()">
    <?php if ($fSerie !== ''): ?><input type="hidden" name="serie" value="<?= h($fSerie) ?>"><?php endif; ?>
  </form>
  <button onclick="window.print()">🖨 Imprimir boletim</button>
  <a class="ghost" href="<?= BIOS_BASE ?>/mip/aplicacoes">← Voltar às aplicações</a>
</div>

<div class="doc">
  <?= print_doc_cabecalho($dias > 1 ? 'Boletim de Pulverização (período)' : 'Boletim Diário de Pulverização', array_filter([
        'Período'     => $periodoFmt,
        'Documentos'  => (string)count($dfs),
        'Série'       => $fSerie !== '' ? $fSerie : null,
      ])) ?>

  <?php if (!$dfs): ?>
    <div class="empty">Nenhuma pulverização/aplicação registrada em <?= h($periodoFmt) ?>.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th>Nº</th><th>Data</th><th>Válvula / Fazenda</th><th>Tipo</th><th>Produtos (dose)</th>
      <th>Bico / Filas</th><th class="num">Carência</th><th>Colheita permitida</th>
      <th>RT</th><th class="num">Área/Calda</th><th>Situação</th>
    </tr></thead>
    <tbody>
    <?php foreach ($dfs as $r):
      $par = $r['parametros_aplicacao'] ? (json_decode((string)$r['parametros_aplicacao'], true) ?: []) : [];
      $its = $itensPorDf[(int)$r['id']] ?? [];
      /* N-01 / D-6: no oficial só entram itens COM registro MAPA e SEM a flag
         nao_registrado; a exigência vale SÓ p/ DEFENSIVO (Lei 7.802/1989) —
         fertilizante/corretivo (Lei 6.894/1980) nunca são omitidos (mig 171) */
      $itsReg = [];
      foreach ($its as $it) {
        $semReg = (string)($it['tipo_insumo'] ?? '') === 'defensivo'
            && (trim((string)($it['registro_mapa'] ?? '')) === '' || (int)($it['nao_registrado'] ?? 0) === 1);
        if ($semReg) { $totalSemReg++; }
        else { $itsReg[] = $it; }
      }
      $its = $itsReg;
      $carMax = 0;
      foreach ($its as $it) if ($it['carencia_dias'] !== null) $carMax = max($carMax, (int)$it['carencia_dias']);
      $colheita = $carMax > 0 ? date('d/m/Y', strtotime((string)$r['data'] . ' +' . $carMax . ' days')) : '—';
      $bico  = !empty($par['bico_tipo']) ? (BICO_BOL[$par['bico_tipo']] ?? (string)$par['bico_tipo']) : '';
      $filas = !empty($par['filas']) ? (string)$par['filas'] : '';
      $bicoFilas = trim($bico . ($filas ? ' / ' . $filas : ''), ' /') ?: '—';
    ?>
      <tr>
        <td class="docnum"><?= $r['doc_serie'] ? h((string)$r['doc_serie']) . (int)$r['doc_numero'] : '#' . (int)$r['id'] ?></td>
        <td class="num"><?= date('d/m/Y', strtotime((string)$r['data'])) ?></td>
        <td><strong><?= h($r['talhao'] ?? '—') ?></strong><br><span class="prod"><em><?= h($r['fazenda'] ?? '') ?></em></span></td>
        <td><?= h(TIPOS_BOL[$r['tipo']] ?? (string)$r['tipo']) ?></td>
        <td class="prod">
          <?php if (!$its): ?>—<?php else: foreach ($its as $it): ?>
            <?= h($it['produto'] ?? '—') ?><?php if ($it['dose_valor'] !== null): ?> <em><?= numFmt((float)$it['dose_valor'], 2) ?> <?= h((string)($it['dose_unidade'] ?? '')) ?></em><?php endif; ?><br>
          <?php endforeach; endif; ?>
        </td>
        <td class="prod"><?= h($bicoFilas) ?></td>
        <td class="num"><?= $carMax > 0 ? $carMax . ' d' : '—' ?></td>
        <td<?= $carMax > 0 ? ' class="warn"' : '' ?>><?= h($colheita) ?></td>
        <td><?= h($r['rt_nome'] ?? '') ?: '—' ?></td>
        <td class="num"><?= $r['area_aplicada_ha'] !== null ? numFmt((float)$r['area_aplicada_ha'], 2) . ' ha' : '—' ?><br><span class="prod"><em><?= $r['volume_calda_l'] !== null ? numFmt((float)$r['volume_calda_l'], 0) . ' L' : '' ?></em></span></td>
        <td><?= h(STATUS_BOL[$r['status']] ?? (string)$r['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="assin">
    <div>Responsável Técnico</div>
    <div>Encarregado / Responsável pela frente</div>
  </div>
  <?php endif; ?>

  <div class="foot">
    Boletim consolidado para impressão — cada documento preserva número, carência e RT próprios.
    <?php if ($totalSemReg > 0): ?>
      <br><strong><?= (int)$totalSemReg ?> <?= $totalSemReg === 1 ? 'item sem registro MAPA omitido' : 'itens sem registro MAPA omitidos' ?></strong>
      deste boletim oficial — consulte a relação de campo do aplicador (Aplicações) para a lista completa.
    <?php endif; ?>
    Gerado pelo VERO em <?= h($hoje) ?>.
  </div>
</div>
<?= print_doc_rodape('Boletim consolidado de pulverizações') ?>
</body>
</html>
