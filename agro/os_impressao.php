<?php
/* ============================================================
   VERO — Agrícola / Impresso de OS de campo  (A1-39)
   Rota: /agro/os_impressao.php?id=N (ou ?ids=1,2,3 — lote)
   Guard: agricola.ordens_servico.ver (leitura; página de impressão
   standalone, padrão do impresso DF31 — A1-27)
   Instrução de trabalho para operações que NÃO são aplicação
   (poda, raleio, tratos, colheita…): identificação numerada,
   conteúdo da atividade-mestre, execuções registradas e bloco
   em branco para o campo + assinaturas.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/../includes/print_doc.php';   // A0-22: cabeçalho/rodapé canônico (adoção-demo, green-light A0 ae0fd4e)
require_once __DIR__ . '/_setor_espelho.php';

vero_require('agro.ordens_servico.ver');

$t = vero_tenant();

$ids = [];
if (!empty($_GET['id']))  $ids[] = (int)$_GET['id'];
if (!empty($_GET['ids'])) foreach (explode(',', (string)$_GET['ids']) as $x) $ids[] = (int)$x;
$ids = array_slice(array_values(array_unique(array_filter($ids))), 0, 50);
if (!$ids) { http_response_code(400); exit('Informe ?id= ou ?ids= da(s) OS.'); }

$docs = [];
foreach ($ids as $osId) {
    $os = vero_row(
        "SELECT os.*, tl.codigo AS talhao, tl.area_ha AS talhao_area,
                fz.nome AS fazenda, fz.municipio, fz.uf,
                at.descricao AS atividade, at.observacao AS instrucoes,
                at.data_planejada, at.data_realizada, at.area_prevista_ha,
                at.custo_previsto, at.status AS atv_status, at.safra_id,
                sa.identificacao AS safra, ta.nome AS tipo_atividade,
                op.nome AS responsavel, ue.nome AS emissor,
                /* Fallbacks p/ OS gerada por apontamento (sem atividade planejada —
                   fluxo de 2 estágios): puxa contexto do apontamento vinculado. */
                (SELECT ta2.nome FROM agro_apontamentos ap2
                   JOIN agro_tipos_atividade ta2 ON ta2.id = ap2.tipo_atividade_id
                  WHERE ap2.tenant_id = os.tenant_id AND ap2.ordem_servico_id = os.id
                  ORDER BY ap2.id LIMIT 1) AS ap_tipo,
                (SELECT op2.nome FROM agro_apontamentos ap2
                   JOIN agro_operadores op2 ON op2.id = ap2.responsavel_id
                  WHERE ap2.tenant_id = os.tenant_id AND ap2.ordem_servico_id = os.id
                  ORDER BY ap2.id LIMIT 1) AS ap_resp,
                (SELECT sa2.identificacao FROM agro_apontamentos ap2
                   JOIN agro_safra_talhoes st2 ON st2.id = ap2.safra_talhao_id
                   JOIN agro_safras sa2 ON sa2.id = st2.safra_id
                  WHERE ap2.tenant_id = os.tenant_id AND ap2.ordem_servico_id = os.id
                  ORDER BY ap2.id LIMIT 1) AS ap_safra,
                (SELECT ap2.observacao FROM agro_apontamentos ap2
                  WHERE ap2.tenant_id = os.tenant_id AND ap2.ordem_servico_id = os.id
                  ORDER BY ap2.id LIMIT 1) AS ap_obs
           FROM agro_ordens_servico os
           LEFT JOIN agro_talhoes tl ON tl.id = os.talhao_id
           LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
           LEFT JOIN agro_atividades at ON at.id = os.atividade_id
           LEFT JOIN agro_safras sa ON sa.id = at.safra_id
           LEFT JOIN agro_tipos_atividade ta ON ta.id = at.tipo_atividade_id
           LEFT JOIN agro_operadores op ON op.id = at.responsavel_id
           LEFT JOIN usuarios ue ON ue.id = os.created_by
          WHERE os.id = :i AND os.tenant_id = :t",
        [':i' => $osId, ':t' => $t]);
    if (!$os) continue;
    $os['_exec'] = vero_rows(
        "SELECT ap.id, DATE(ap.data_apontamento) AS data, ap.hectares, ap.planejamento_mo,
                (SELECT COUNT(*) FROM rh_producao_itens rp
                  WHERE rp.tenant_id = ap.tenant_id AND rp.apontamento_id = ap.id) AS pessoas,
                /* horas de mão de obra: linhas de produção lançadas em unidade=hora (V-15) */
                (SELECT COALESCE(SUM(rp3.quantidade),0) FROM rh_producao_itens rp3
                  WHERE rp3.tenant_id = ap.tenant_id AND rp3.apontamento_id = ap.id AND rp3.unidade = 'hora') AS horas,
                (SELECT COALESCE(SUM(rp2.valor_total),0) FROM rh_producao_itens rp2
                  WHERE rp2.tenant_id = ap.tenant_id AND rp2.apontamento_id = ap.id) AS mao_obra
           FROM agro_apontamentos ap
          WHERE ap.tenant_id = :t AND ap.ordem_servico_id = :o
          ORDER BY ap.data_apontamento",
        [':t' => $t, ':o' => $osId]);
    /* Colaboradores da OS (nomes) — pessoas lançadas nos apontamentos vinculados */
    $os['_pessoas'] = vero_rows(
        "SELECT COALESCE(o.nome, tc.nome) AS nome, ri.origem_pessoa AS vinculo,
                ri.quantidade, ri.unidade, ri.peso_kg, ri.meta_aplicada, ri.valor_total
           FROM rh_producao_itens ri
           JOIN agro_apontamentos ap ON ap.id = ri.apontamento_id AND ap.tenant_id = ri.tenant_id
           LEFT JOIN agro_operadores  o  ON o.id  = ri.operador_id     AND o.tenant_id  = ri.tenant_id
           LEFT JOIN rh_terceirizados tc ON tc.id = ri.terceirizado_id AND tc.tenant_id = ri.tenant_id
          WHERE ri.tenant_id = :t AND ap.ordem_servico_id = :o
          ORDER BY nome, ri.id",
        [':t' => $t, ':o' => $osId]);
    /* Planejamento da mão de obra (JSON do 1º apontamento que tiver) */
    $os['_plan'] = null;
    foreach ($os['_exec'] as $e) {
        if (!empty($e['planejamento_mo'])) {
            $pj = json_decode((string)$e['planejamento_mo'], true);
            if (is_array($pj) && $pj) { $os['_plan'] = $pj; break; }
        }
    }
    $docs[] = $os;
}
if (!$docs) { http_response_code(404); exit('OS não encontrada(s).'); }

$fmt = static fn(float $n, int $d = 2): string => number_format($n, $d, ',', '.');
$dBR = static fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
$rotulo = vero_a1_rotulo_area();
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Impressão OS — VERO</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font: 12px/1.45 "IBM Plex Sans", Arial, sans-serif; color: #1F2421; background: #F4F1E9; padding: 16px; }
  .doc { background: #fff; border: 1px solid #C9C1AE; border-radius: 6px; max-width: 860px;
         margin: 0 auto 20px; padding: 22px 26px; page-break-after: always; }
  .doc:last-child { page-break-after: auto; }
  h1 { font-size: 19px; letter-spacing: .5px; }
  h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .8px; color: #005059;
       border-bottom: 2px solid #005059; padding-bottom: 3px; margin: 16px 0 8px; }
  table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  th, td { border: 1px solid #C9C1AE; padding: 4px 7px; text-align: left; }
  th { background: #EFEBE0; font-size: 10.5px; text-transform: uppercase; letter-spacing: .4px; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px 14px; margin-top: 6px; }
  .grid .lbl { font-size: 9.5px; text-transform: uppercase; color: #6B7069; letter-spacing: .4px; }
  .grid .val { font-weight: 600; }
  .topo { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #005059; padding-bottom: 10px; }
  .docnum { font-size: 26px; font-weight: 700; color: #005059; }
  .badge { display: inline-block; border: 1px solid #005059; color: #005059; border-radius: 4px;
           padding: 1px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; }
  .instr { border: 1px solid #C9C1AE; border-radius: 4px; padding: 8px 10px; min-height: 52px; margin-top: 4px; white-space: pre-wrap; }
  .assin { display: grid; grid-template-columns: repeat(3, 1fr); gap: 26px; margin-top: 26px; }
  .assin div { border-top: 1px solid #1F2421; text-align: center; font-size: 10.5px; padding-top: 3px; }
  .toolbar { max-width: 860px; margin: 0 auto 14px; display: flex; gap: 10px; }
  .toolbar button, .toolbar a { font: 13px "IBM Plex Sans", Arial; padding: 8px 16px; border-radius: 6px;
    border: 1px solid #005059; background: #005059; color: #fff; cursor: pointer; text-decoration: none; }
  .toolbar a.ghost { background: #fff; color: #005059; }
  .obs { font-size: 10.5px; color: #6B7069; margin-top: 10px; }
  @media print { body { background: #fff; padding: 0; } .toolbar { display: none; }
                 .doc { border: none; max-width: none; margin: 0; padding: 0; } }
</style>
<?= print_doc_css() /* A0-22: @page A4 + cabeçalho/rodapé canônico */ ?>
</head>
<body>
<div class="toolbar">
  <button onclick="window.print()">🖨 Imprimir<?= count($docs) > 1 ? ' (' . count($docs) . ' OS)' : '' ?></button>
  <a class="ghost" href="<?= BIOS_BASE ?>/agro/ordens_servico">← Voltar à fila</a>
</div>

<?php foreach ($docs as $os):
    $aberta = in_array((string)$os['status'], ['aberta', 'em_execucao'], true);
?>
<div class="doc">
  <?= print_doc_cabecalho('Ordem de Serviço de Campo', [
      'Nº'       => (string)$os['numero'],
      'Abertura' => $dBR($os['data_abertura']),
      'Situação' => strtoupper(str_replace('_', ' ', (string)$os['status'])),
  ]) ?>
  <div style="margin:2px 0 12px;font-size:11px;color:#5A5344">
    <strong><?= h((string)($os['fazenda'] ?? '—')) ?><?= $os['municipio'] ? ' · ' . h((string)$os['municipio']) . '/' . h((string)$os['uf']) : '' ?></strong>
    · <?= h((string)($os['tipo_atividade'] ?? $os['ap_tipo'] ?? 'Atividade de campo')) ?>
  </div>

  <?php
    /* Descrição: atividade planejada quando houver; senão a observação do
       apontamento; senão rótulo genérico com o tipo. */
    $descricao = $os['atividade']
        ?: ($os['ap_obs']
            ?: ($os['ap_tipo'] ? 'Apontamento de campo (' . $os['ap_tipo'] . ')' : '— OS sem atividade vinculada —'));
  ?>
  <h2>Trabalho</h2>
  <div class="grid">
    <div style="grid-column:1/-1"><div class="lbl">Descrição</div>
      <div class="val" style="font-size:14px"><?= h((string)$descricao) ?></div></div>
    <div><div class="lbl"><?= h($rotulo) ?></div>
      <div class="val"><?= h((string)($os['talhao'] ?? '—')) ?><?= $os['talhao_area'] !== null ? ' (' . $fmt((float)$os['talhao_area']) . ' ha)' : '' ?></div></div>
    <div><div class="lbl">Safra</div><div class="val"><?= h($os['safra'] ?? $os['ap_safra'] ?? '—') ?></div></div>
    <div><div class="lbl">Área prevista</div>
      <div class="val"><?= $os['area_prevista_ha'] !== null ? $fmt((float)$os['area_prevista_ha']) . ' ha' : '—' ?></div></div>
    <div><div class="lbl">Data planejada</div><div class="val"><?= $dBR($os['data_planejada']) ?></div></div>
    <div><div class="lbl">Responsável</div><div class="val"><?= h($os['responsavel'] ?? $os['ap_resp'] ?? '') ?: '—' ?></div></div>
    <div><div class="lbl">Custo previsto</div>
      <div class="val"><?= $os['custo_previsto'] !== null ? 'R$ ' . $fmt((float)$os['custo_previsto']) : '—' ?></div></div>
    <div><div class="lbl">Emitida por</div><div class="val"><?= h($os['emissor'] ?? '') ?: '—' ?></div></div>
    <div><div class="lbl">Conclusão</div><div class="val"><?= $aberta ? '____/____/______' : $dBR($os['data_conclusao']) ?></div></div>
  </div>

  <?php if (!empty($os['_plan'])): $pl = $os['_plan'];
      $baseMap = ['prazo' => 'Dias → nº de pessoas', 'pessoas' => 'Pessoas → nº de dias'];
      $plCampos = [
          'total'    => 'Total a fazer',
          'unidade'  => 'Unidade',
          'base'     => 'Base do cálculo',
          'dias'     => 'Dias',
          'pessoas'  => 'Pessoas',
          'meta'     => 'Meta (un/pessoa/dia)',
          'media'    => 'Média (un/pessoa/dia)',
          'premio'   => 'Premiação (R$/un acima)',
      ]; ?>
  <h2>Planejamento da mão de obra</h2>
  <div class="grid">
    <?php foreach ($plCampos as $k => $lbl):
        $v = $pl[$k] ?? '';
        if ($v === '' || $v === null) continue;
        if ($k === 'base') $v = $baseMap[(string)$v] ?? $v; ?>
    <div><div class="lbl"><?= h($lbl) ?></div><div class="val"><?= h((string)$v) ?></div></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <h2>Instruções de trabalho</h2>
  <div class="instr"><?= h((string)($os['instrucoes'] ?? $os['ap_obs'] ?? '')) ?></div>

  <h2>Execuções registradas (apontamentos)</h2>
  <?php if ($os['_exec']): ?>
  <table>
    <thead><tr><th>Data</th><th class="num">Área (ha)</th><th class="num">Pessoas</th><th class="num">Horas</th><th class="num">Mão de obra (R$)</th></tr></thead>
    <tbody>
    <?php $totMo = 0.0; $totH = 0.0; foreach ($os['_exec'] as $e): $totMo += (float)$e['mao_obra']; $totH += (float)$e['horas']; ?>
      <tr>
        <td class="num"><?= $dBR($e['data']) ?></td>
        <td class="num"><?= $e['hectares'] !== null ? $fmt((float)$e['hectares']) : '—' ?></td>
        <td class="num"><?= (int)$e['pessoas'] ?></td>
        <td class="num"><?= (float)$e['horas'] > 0 ? $fmt((float)$e['horas'], 1) : '—' ?></td>
        <td class="num"><?= $fmt((float)$e['mao_obra']) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr><td colspan="3"><strong>Total</strong></td><td class="num"><strong><?= $totH > 0 ? $fmt($totH, 1) : '—' ?></strong></td><td class="num"><strong><?= $fmt($totMo) ?></strong></td></tr>
    </tbody>
  </table>
  <?php else: ?>
  <table>
    <thead><tr><th>Data</th><th>Equipe / pessoas</th><th class="num">Horas</th><th>Área trabalhada</th><th>Observações do campo</th></tr></thead>
    <tbody>
      <?php for ($k = 0; $k < 4; $k++): ?>
      <tr><td style="height:26px">&nbsp;</td><td></td><td></td><td></td><td></td></tr>
      <?php endfor; ?>
    </tbody>
  </table>
  <div class="obs">Preencher no campo e transcrever em Agrícola → Apontamentos (vinculando a atividade — a OS acompanha).</div>
  <?php endif; ?>

  <?php if (!empty($os['_pessoas'])): ?>
  <h2>Colaboradores</h2>
  <table>
    <thead><tr><th>Nome</th><th>Vínculo</th><th class="num">Produção</th><th class="num">Peso (kg)</th><th class="num">Valor (R$)</th></tr></thead>
    <tbody>
    <?php foreach ($os['_pessoas'] as $ps): ?>
      <tr>
        <td><?= h((string)($ps['nome'] ?? '—')) ?: '—' ?></td>
        <td><?= ($ps['vinculo'] ?? '') === 'terceirizado' ? 'Terceirizado' : 'Colaborador' ?></td>
        <td class="num"><?= $fmt((float)($ps['quantidade'] ?? 0)) ?> <?= h((string)($ps['unidade'] ?? '')) ?></td>
        <td class="num"><?= ($ps['peso_kg'] ?? null) !== null && (float)$ps['peso_kg'] > 0 ? $fmt((float)$ps['peso_kg'], 1) : '—' ?></td>
        <td class="num"><?= $fmt((float)($ps['valor_total'] ?? 0)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <div class="assin">
    <div>Emissor<?= $os['emissor'] ? ' — ' . h((string)$os['emissor']) : '' ?></div>
    <div>Responsável<?= $os['responsavel'] ? ' — ' . h((string)$os['responsavel']) : '' ?></div>
    <div>Supervisor / coordenador</div>
  </div>

</div>
<?php endforeach; ?>
<?= print_doc_rodape('Projeção numerada da atividade planejada — aplicações de defensivo/fertirrigação têm documento próprio (DF/IF)') ?>
</body>
</html>
