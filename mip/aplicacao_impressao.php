<?php
/* ============================================================
   VERO — MIP / Impresso DF/IF  (layout DF31 — A1-27)
   Rota: /mip/aplicacao_impressao.php?id=N  (documento único)
         /mip/aplicacao_impressao.php?ids=1,2,3  (LOTE — pacote do auditor, A1-31)
   Guard: mip.aplicacoes_defensivos.ver (leitura; sem menu — página de impressão)
   Conteúdo por documento: cabeçalho numerado, instruções (fenologia,
   justificativa MIP, RT, máquina/bomba, clima), produtos com bula
   (dose, carência, intervalo, nº máx, LMR, nutrientes %), produtos
   POR VÁLVULA (proporcional ao volume da linha), CÁLCULO POR TANQUE
   (capacidade da máquina; última carga parcial), datas de colheita
   prevista × permitida (carência máx.) e bloco de CONFIRMAÇÃO
   (preenchido quando executada; em branco p/ campo quando emitida)
   com operadores/EPI e linhas de assinatura (P-48: app assina depois).
   Regra 1: tudo aqui é REGISTRO — nada é recomendado pelo sistema.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/../includes/print_doc.php'; // A0-22/P-106: cabeçalho/rodapé canônico (logo VERO + emissor)

vero_require('mip.aplicacoes_defensivos.ver');

$t = vero_tenant();

const TIPOS_APLIC_IMP = [
    'pulverizacao' => 'Pulverização (defensivo)', 'fertirrigacao' => 'Fertirrigação',
    'foliar' => 'Adubação foliar', 'indutor_brotacao' => 'Indutor de brotação',
    'tratamento' => 'Tratamento', 'outro' => 'Outro',
];

/* ids: único (?id=) ou lote (?ids=1,2,3 — máx. 50 por tirada) */
$ids = [];
if (!empty($_GET['id']))  $ids[] = (int)$_GET['id'];
if (!empty($_GET['ids'])) foreach (explode(',', (string)$_GET['ids']) as $x) $ids[] = (int)$x;
$ids = array_slice(array_values(array_unique(array_filter($ids))), 0, 50);
if (!$ids) { http_response_code(400); exit('Informe ?id= ou ?ids= do(s) documento(s).'); }

$docs = [];
foreach ($ids as $docId) {
    $ap = vero_row(
        "SELECT ap.*, tl.codigo AS talhao, tl.area_ha AS talhao_area, fz.nome AS fazenda,
                fz.municipio, fz.uf, sa.identificacao AS safra,
                u.nome AS validador, ue.nome AS emissor,
                mq.nome AS maquina, mq.capacidade_tanque_l,
                rt.nome AS rt_nome, bm.nome AS bomba_nome,
                vr.nome AS variedade, cu.nome AS cultura,
                fe.codigo AS feno_cod, fe.nome AS feno_nome, vf.nome AS var_fase_nome,
                mo.data_monitoramento AS mon_data, alv.nome AS mon_alvo,
                mo.nivel_infestacao AS mon_indice, mo.unidade AS mon_unidade
           FROM agro_aplicacoes ap
           LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
           LEFT JOIN agro_fazendas fz ON fz.id = ap.fazenda_id
           LEFT JOIN agro_safras sa ON sa.id = ap.safra_id
           LEFT JOIN usuarios u ON u.id = ap.validado_por
           LEFT JOIN usuarios ue ON ue.id = ap.created_by
           LEFT JOIN maquinas mq ON mq.id = ap.maquina_id
           LEFT JOIN agro_operadores rt ON rt.id = ap.responsavel_tecnico_id
           LEFT JOIN agro_bombas bm ON bm.id = ap.bomba_id
           LEFT JOIN agro_variedades vr ON vr.id = ap.variedade_id
           LEFT JOIN agro_culturas cu ON cu.id = vr.cultura_id
           LEFT JOIN agro_fenologia_estagios fe ON fe.id = ap.fenologia_id
           LEFT JOIN agro_variedade_fases vf ON vf.id = ap.variedade_fase_id AND vf.tenant_id = ap.tenant_id
           LEFT JOIN mip_monitoramentos mo ON mo.id = ap.monitoramento_id
           LEFT JOIN mip_alvos alv ON alv.id = mo.alvo_id
          WHERE ap.id = :i AND ap.tenant_id = :t",
        [':i' => $docId, ':t' => $t]);
    if (!$ap) continue;
    $itensTodos = vero_rows(
        "SELECT i.*, p.nome AS produto_nome, p.codigo AS produto_codigo, p.registro_mapa, p.nao_registrado, p.tipo_insumo
           FROM agro_aplicacao_itens i
           LEFT JOIN estoque_produtos p ON p.id = i.produto_id
          WHERE i.tenant_id = :t AND i.aplicacao_id = :a ORDER BY i.id",
        [':t' => $t, ':a' => $docId]);
    /* N-01 / D-6 (Caminho A; regra do gestor 11/08): a MESMA rota imprime dois papéis.
       OS emitida (status planejada — instrução de campo) → TODOS os produtos, com os
       sem registro marcados (o aplicador precisa da mistura de tanque completa).
       DF/IF executada/validada (documento oficial, rastreabilidade GlobalG.A.P.) →
       SÓ produtos COM registro MAPA; os demais seguem lançados na aplicação e na
       relação de campo (tela mip/aplicacoes) — aqui só se conta quantos, p/ o rodapé.
       A exigência de registro vale SÓ para DEFENSIVO (Lei 7.802/1989 — mesma regra
       de estoque/produtos L260 e estoque/auditoria); fertilizante/corretivo/adjuvante
       têm regime próprio (Lei 6.894/1980) e nunca são omitidos do oficial. */
    $osCampo = ((string)$ap['status'] === 'planejada');
    $ap['_itens'] = [];
    $ap['_sem_registro'] = 0;
    foreach ($itensTodos as $it) {
        /* sem registro = DEFENSIVO com registro_mapa vazio OU flag nao_registrado=1 (mig 171) */
        $semReg = (string)($it['tipo_insumo'] ?? '') === 'defensivo'
            && (trim((string)($it['registro_mapa'] ?? '')) === '' || (int)($it['nao_registrado'] ?? 0) === 1);
        if ($semReg) { $ap['_sem_registro']++; }
        $it['_sem_registro'] = $semReg;
        if (!$semReg || $osCampo) { $ap['_itens'][] = $it; }
    }
    $ap['_valvulas'] = vero_rows(
        "SELECT av.*, s.codigo AS setor_codigo
           FROM agro_aplicacao_valvulas av
           LEFT JOIN agro_setores s ON s.id = av.setor_id
          WHERE av.tenant_id = :t AND av.aplicacao_id = :a ORDER BY av.id",
        [':t' => $t, ':a' => $docId]);
    $ap['_operadores'] = vero_rows(
        "SELECT ao.*, op.nome AS operador_nome
           FROM agro_aplicacao_operadores ao
           LEFT JOIN agro_operadores op ON op.id = ao.operador_id
          WHERE ao.tenant_id = :t AND ao.aplicacao_id = :a ORDER BY ao.id",
        [':t' => $t, ':a' => $docId]);
    /* assinaturas coletadas no app (P-48: app assina depois) — SVG por assinante */
    $ap['_assinaturas'] = vero_rows(
        "SELECT operador_id, operador_nome, assinatura_svg, assinado_em
           FROM agro_aplicacao_assinaturas
          WHERE tenant_id = :t AND aplicacao_id = :a ORDER BY assinado_em, id",
        [':t' => $t, ':a' => $docId]);
    $docs[] = $ap;
}
if (!$docs) { http_response_code(404); exit('Documento(s) não encontrado(s).'); }

$fmt  = static fn(float $n, int $d = 2): string => number_format($n, $d, ',', '.');
$dBR  = static fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
$hM   = static fn(?string $d): string => $d ? date('H:i', strtotime($d)) : '—';
/* assinatura do app (SVG desenhado no celular) → renderiza como <img> data-URI.
   SVG embutido em <img> é contexto passivo (não roda <script>, não busca rede) → anti-XSS. */
$sigImg = static function (?string $svg, string $cls = 'assin-svg'): string {
    $svg = trim((string)$svg);
    if ($svg === '' || stripos($svg, '<svg') === false) return '';
    return '<img class="' . $cls . '" alt="assinatura" src="data:image/svg+xml;base64,'
         . base64_encode($svg) . '">';
};
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Impressão DF/IF — VERO</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font: 12px/1.45 "IBM Plex Sans", Arial, sans-serif; color: #1F2421; background: #F4F1E9; padding: 16px; }
  .doc { background: #fff; border: 1px solid #C9C1AE; border-radius: 6px; max-width: 900px;
         margin: 0 auto 20px; padding: 22px 26px; page-break-after: always; }
  .doc:last-child { page-break-after: auto; }
  h1 { font-size: 19px; letter-spacing: .5px; }
  h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .8px; color: #005059;
       border-bottom: 2px solid #005059; padding-bottom: 3px; margin: 16px 0 8px; }
  table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  th, td { border: 1px solid #C9C1AE; padding: 4px 7px; text-align: left; vertical-align: top; }
  th { background: #EFEBE0; font-size: 10.5px; text-transform: uppercase; letter-spacing: .4px; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px 14px; margin-top: 6px; }
  .grid .lbl { font-size: 9.5px; text-transform: uppercase; color: #6B7069; letter-spacing: .4px; }
  .grid .val { font-weight: 600; }
  /* pulveriz. 23/07: linha em branco p/ preencher à mão (ex.: maquinário) */
  .fill-line { display: inline-block; min-width: 150px; border-bottom: 1px solid #999; }
  .topo { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #005059; padding-bottom: 10px; }
  .docnum { font-size: 26px; font-weight: 700; color: #005059; }
  .badge { display: inline-block; border: 1px solid #005059; color: #005059; border-radius: 4px;
           padding: 1px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; }
  .assin { display: grid; grid-template-columns: repeat(3, 1fr); gap: 26px; margin-top: 26px; }
  .assin div { border-top: 1px solid #1F2421; text-align: center; font-size: 10.5px; padding-top: 3px; }
  .assin-svg { display: block; height: 46px; max-width: 170px; margin: 0 auto 2px; }
  .sig-cell .assin-svg { height: 32px; margin: 0 0 1px; }
  .sig-meta { display: block; font-size: 8.5px; color: #6B7069; }
  .assin-app { display: flex; flex-wrap: wrap; gap: 14px 30px; margin-top: 4px; }
  .assin-app-item { text-align: center; min-width: 150px; }
  .assin-app-nome { font-weight: 600; font-size: 10.5px; border-top: 1px solid #1F2421; padding-top: 2px; }
  .alertbox { border: 1.5px solid #9A3B2A; color: #9A3B2A; padding: 6px 10px; border-radius: 4px; margin-top: 8px; font-weight: 600; }
  .toolbar { max-width: 900px; margin: 0 auto 14px; display: flex; gap: 10px; }
  .toolbar button, .toolbar a { font: 13px "IBM Plex Sans", Arial; padding: 8px 16px; border-radius: 6px;
    border: 1px solid #005059; background: #005059; color: #fff; cursor: pointer; text-decoration: none; }
  .toolbar a.ghost { background: #fff; color: #005059; }
  .obs { font-size: 10.5px; color: #6B7069; margin-top: 10px; }
  @media print { body { background: #fff; padding: 0; } .toolbar { display: none; }
                 .doc { border: none; max-width: none; margin: 0; padding: 8mm 6mm; } }
</style>
<?= print_doc_css() /* A0-22: @page A4 + cabeçalho/rodapé canônico (logo VERO + emissor) */ ?>
</head>
<body>
<div class="toolbar">
  <button onclick="window.print()">🖨 Imprimir<?= count($docs) > 1 ? ' (' . count($docs) . ' documentos)' : '' ?></button>
  <a class="ghost" href="<?= BIOS_BASE ?>/mip/aplicacoes">← Voltar às aplicações</a>
</div>

<?php foreach ($docs as $ap):
    $docTxt   = $ap['doc_serie'] ? $ap['doc_serie'] . $ap['doc_numero'] : 'Aplicação #' . $ap['id'];
    $emitida  = $ap['status'] === 'planejada';
    $itens    = $ap['_itens'];
    $valvulas = $ap['_valvulas'];
    $volTotal = (float)($ap['volume_calda_l'] ?? 0);
    $params   = $ap['parametros_aplicacao'] ? (json_decode((string)$ap['parametros_aplicacao'], true) ?: []) : [];
    $clima    = $ap['condicao_climatica'] ? (json_decode((string)$ap['condicao_climatica'], true) ?: []) : [];
    $conf     = $ap['confirmacao'] ? (json_decode((string)$ap['confirmacao'], true) ?: []) : [];

    /* datas de colheita: permitida = data + MAIOR carência dos itens */
    $carenciaMax = 0;
    foreach ($itens as $i) $carenciaMax = max($carenciaMax, (int)($i['carencia_dias'] ?? 0));
    $dataBase = (string)($ap['data'] ?? $ap['data_prevista'] ?? date('Y-m-d'));
    $colheitaPermitida = $carenciaMax > 0 ? date('Y-m-d', strtotime($dataBase . " +{$carenciaMax} days")) : null;

    /* casa assinaturas do app: por operador (vai na linha do EPI) x avulsas (adm/RT → bloco próprio) */
    $opIds = array_map('intval', array_column($ap['_operadores'], 'operador_id'));
    $assinPorOp = [];
    $assinAvulsas = [];
    foreach (($ap['_assinaturas'] ?? []) as $as) {
        $oid = $as['operador_id'] !== null ? (int)$as['operador_id'] : 0;
        if ($oid && in_array($oid, $opIds, true)) $assinPorOp[$oid] = $as;
        else                                      $assinAvulsas[] = $as;
    }

    /* cálculo por tanque (capacidade da máquina) — última carga parcial destacada */
    $capTanque = $ap['capacidade_tanque_l'] !== null ? (float)$ap['capacidade_tanque_l'] : null;
    $tanques = null;
    if ($capTanque !== null && $capTanque > 0 && $volTotal > 0) {
        $nCheios = (int)floor($volTotal / $capTanque);
        $resto   = round($volTotal - $nCheios * $capTanque, 2);
        $tanques = ['cheios' => $nCheios, 'resto' => $resto, 'total' => $nCheios + ($resto > 0 ? 1 : 0)];
    }
?>
<div class="doc">
  <?= print_doc_cabecalho(
        $ap['doc_serie'] === 'IF' ? 'Documento de Fertirrigação (IF)' : 'Documento de Pulverização (DF)',
        [
          'Nº'       => $docTxt,
          'Tipo'     => TIPOS_APLIC_IMP[(string)$ap['tipo']] ?? ucfirst((string)$ap['tipo']),
          'Situação' => $emitida ? 'Emitida — aguarda execução'
                        : ($ap['status'] === 'validada' ? 'Validada pelo RT'
                        : ($ap['status'] === 'cancelada' ? 'Cancelada' : 'Executada')),
          'Emissão'  => $dBR((string)($ap['created_at'] ?? null)),
        ]) ?>

  <h2>Identificação</h2>
  <div class="grid">
    <div><div class="lbl">Fazenda</div><div class="val"><?= h((string)$ap['fazenda']) ?><?= $ap['municipio'] ? ' · ' . h((string)$ap['municipio']) . '/' . h((string)$ap['uf']) : '' ?></div></div>
    <div><div class="lbl">Válvula</div><div class="val"><?= h((string)$ap['talhao']) ?> (<?= $fmt((float)$ap['talhao_area']) ?> ha)</div></div>
    <div><div class="lbl">Safra</div><div class="val"><?= h($ap['safra'] ?? '—') ?></div></div>
    <div><div class="lbl">Cultura / variedade</div><div class="val"><?= h(trim(($ap['cultura'] ?? '') . ' ' . ($ap['variedade'] ?? '')) ?: '—') ?></div></div>
    <div><div class="lbl">Fase fenológica</div><div class="val"><?php
        /* fase POR VARIEDADE (mig 165) tem prioridade; estágio por cultura como fallback */
        $impFase = $ap['var_fase_nome'] ?? null;
        if ($impFase === null && $ap['feno_cod']) $impFase = $ap['feno_cod'] . ' — ' . $ap['feno_nome'];
        echo $impFase ? h($impFase) : '—';
        if ($impFase !== null && ($ap['dias_desde_poda'] ?? null) !== null) echo ' <span style="color:#6B7069">(' . (int)$ap['dias_desde_poda'] . 'd)</span>';
    ?></div></div>
    <div><div class="lbl">Data prevista</div><div class="val"><?= $dBR($ap['data_prevista']) ?></div></div>
    <div><div class="lbl">Data de execução</div><div class="val"><?= $emitida ? '____/____/______' : $dBR($ap['data']) ?></div></div>
    <div><div class="lbl">Área aplicada</div><div class="val"><?= $ap['area_aplicada_ha'] !== null ? $fmt((float)$ap['area_aplicada_ha']) . ' ha' : '—' ?></div></div>
    <div><div class="lbl">Volume de calda</div><div class="val"><?= $volTotal > 0 ? $fmt($volTotal, 0) . ' L' : '—' ?></div></div>
    <div><div class="lbl"><?= $ap['doc_serie'] === 'IF' ? 'Bomba' : 'Máquina / equipamento' ?></div>
      <?php /* pulveriz. 23/07: sem traço — deixa LINHA em branco para preencher o
               maquinário à mão no campo (o cliente completa na execução). Se já há
               máquina registrada, mostra o nome + linha para adicionar as demais. */
        $maqNome = $ap['doc_serie'] === 'IF' ? ($ap['bomba_nome'] ?? '') : ($ap['maquina'] ?? ''); ?>
      <div class="val"><?= $maqNome !== '' ? h($maqNome) . ' ' : '' ?><span class="fill-line">&nbsp;</span></div></div>
    <div><div class="lbl">Resp. técnico (receita)</div><div class="val"><?= h($ap['rt_nome'] ?? '') ?: '—' ?></div></div>
    <div><div class="lbl">Emitido por</div><div class="val"><?= h($ap['emissor'] ?? '') ?: '—' ?></div></div>
    <div><div class="lbl">Justificativa (MIP)</div>
      <div class="val"><?= $ap['mon_alvo']
          ? h($ap['mon_alvo']) . ' — índice ' . $fmt((float)$ap['mon_indice']) . h((string)($ap['mon_unidade'] ?? '%')) . ' em ' . $dBR($ap['mon_data'])
          : '—' ?></div></div>
    <?php if ($ap['doc_serie'] === 'IF'): ?>
    <div><div class="lbl">Tempo de irrigação</div><div class="val"><?= isset($params['tempo_irrigacao_h']) ? $fmt((float)$params['tempo_irrigacao_h'], 1) . ' h' : '—' ?></div></div>
    <?php endif; ?>
    <?php if (isset($params['faixa_m']) || isset($params['gota_micras']) || isset($params['altura_m']) || isset($params['velocidade_ms'])): ?>
    <div><div class="lbl">Drone — faixa/vel./gota/altura</div><div class="val">
      <?= isset($params['faixa_m']) ? $fmt((float)$params['faixa_m'], 1) . ' m' : '—' ?> ·
      <?= isset($params['velocidade_ms']) ? $fmt((float)$params['velocidade_ms'], 1) . ' m/s' : '—' ?> ·
      <?= isset($params['gota_micras']) ? $fmt((float)$params['gota_micras'], 0) . ' µ' : '—' ?> ·
      <?= isset($params['altura_m']) ? $fmt((float)$params['altura_m'], 1) . ' m' : '—' ?></div></div>
    <?php endif; ?>
    <?php if (isset($params['bico']) || isset($params['mancha']) || isset($params['horimetro_inicial'])): ?>
    <div><div class="lbl">Trator — bico/mancha/horímetros</div><div class="val">
      <?= h((string)($params['bico'] ?? '—')) ?> · <?= h((string)($params['mancha'] ?? '—')) ?> ·
      <?= isset($params['horimetro_inicial']) ? $fmt((float)$params['horimetro_inicial'], 1) : '____' ?> →
      <?= isset($params['horimetro_final']) ? $fmt((float)$params['horimetro_final'], 1) : '____' ?></div></div>
    <?php endif; ?>
    <div><div class="lbl">Clima previsto (emissão)</div><div class="val">
      <?= $clima ? implode(' · ', array_filter([
            isset($clima['vento_kmh']) && $clima['vento_kmh'] !== null ? 'vento ' . $fmt((float)$clima['vento_kmh'], 1) . ' km/h' : null,
            isset($clima['temperatura_c']) && $clima['temperatura_c'] !== null ? $fmt((float)$clima['temperatura_c'], 1) . ' °C' : null,
            isset($clima['umidade_pct']) && $clima['umidade_pct'] !== null ? 'UR ' . $fmt((float)$clima['umidade_pct'], 0) . '%' : null,
          ])) : '—' ?></div></div>
  </div>

  <h2>Produtos da calda (bula registrada pelo RT — cópia no documento)</h2>
  <table>
    <thead><tr>
      <th>Produto</th><th class="num">Dose</th><th class="num">Qtd <?= $emitida ? 'prevista' : 'consumida' ?></th>
      <th class="num">Carência (d)</th><th class="num">Reentrada (h)</th>
      <th class="num">Intervalo (d)</th><th class="num">Nº máx</th><th class="num">LMR (d)</th>
      <th>Nutrientes (%)</th>
    </tr></thead>
    <tbody>
    <?php foreach ($itens as $i):
        $nutri = $i['nutrientes_snapshot'] ? (json_decode((string)$i['nutrientes_snapshot'], true) ?: []) : []; ?>
      <tr>
        <td><strong><?= h(trim(($i['produto_codigo'] ? $i['produto_codigo'] . ' — ' : '') . ($i['produto_nome'] ?? $i['ingrediente_ativo'] ?? '—'))) ?></strong>
          <?php /* N-01: item sem registro só chega aqui na OS de campo — marca no papel */ ?>
          <?= !empty($i['_sem_registro']) ? ' <span class="badge" style="border-color:#9A3B2A;color:#9A3B2A">sem registro</span>' : '' ?>
          <?= $i['finalidade'] ? '<br><span style="font-size:10px;color:#6B7069">' . h((string)$i['finalidade']) . '</span>' : '' ?></td>
        <td class="num"><?= $i['dose_valor'] !== null ? $fmt((float)$i['dose_valor']) . ' ' . h((string)($i['dose_unidade'] ?? '')) : '—' ?></td>
        <td class="num"><?= $fmt((float)$i['quantidade_consumida']) ?> <?= h((string)($i['quantidade_unidade'] ?? '')) ?></td>
        <td class="num"><?= $i['carencia_dias'] !== null ? (int)$i['carencia_dias'] : '—' ?></td>
        <td class="num"><?= $i['intervalo_reentrada_horas'] !== null ? (int)$i['intervalo_reentrada_horas'] : '—' ?></td>
        <td class="num"><?= $i['intervalo_aplicacoes_dias'] !== null ? (int)$i['intervalo_aplicacoes_dias'] : '—' ?></td>
        <td class="num"><?= $i['num_max_aplicacoes'] !== null ? (int)$i['num_max_aplicacoes'] : '—' ?></td>
        <td class="num"><?= $i['lmr_dias'] !== null ? (int)$i['lmr_dias'] : '—' ?></td>
        <td style="font-size:10px"><?php
            $parts = [];
            foreach ($nutri as $sym => $pct) $parts[] = h((string)$sym) . ' ' . $fmt((float)$pct, 2) . '%';
            echo $parts ? implode(' · ', $parts) : '—';
        ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($valvulas): ?>
  <h2>Válvulas da calda — produto proporcional ao volume da linha</h2>
  <table>
    <thead><tr>
      <th>Válvula</th><th class="num">Área (ha)</th><th class="num">Volume (L)</th>
      <?php foreach ($itens as $i): ?>
        <th class="num"><?= h((string)($i['produto_codigo'] ?? $i['produto_nome'] ?? '?')) ?></th>
      <?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($valvulas as $v):
        $share = ($volTotal > 0 && $v['volume_calda_l'] !== null) ? (float)$v['volume_calda_l'] / $volTotal
               : (count($valvulas) > 0 ? 1 / count($valvulas) : 0); ?>
      <tr>
        <td><strong><?= h((string)($v['setor_codigo'] ?? ('#' . $v['setor_id']))) ?></strong></td>
        <td class="num"><?= $v['area_ha'] !== null ? $fmt((float)$v['area_ha']) : '—' ?></td>
        <td class="num"><?= $v['volume_calda_l'] !== null ? $fmt((float)$v['volume_calda_l'], 0) : '—' ?></td>
        <?php foreach ($itens as $i): ?>
          <td class="num"><?= $fmt((float)$i['quantidade_consumida'] * $share) ?> <?= h((string)($i['quantidade_unidade'] ?? '')) ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h2>Cálculo por capacidade do tanque</h2>
  <?php if ($tanques): ?>
  <table>
    <thead><tr>
      <th>Carga</th><th class="num">Água/calda (L)</th>
      <?php foreach ($itens as $i): ?>
        <th class="num"><?= h((string)($i['produto_codigo'] ?? $i['produto_nome'] ?? '?')) ?></th>
      <?php endforeach; ?>
    </tr></thead>
    <tbody>
      <?php if ($tanques['cheios'] > 0): ?>
      <tr>
        <td><strong><?= $tanques['cheios'] ?>× tanque cheio</strong> (<?= $fmt($capTanque, 0) ?> L — <?= h((string)($ap['doc_serie'] === 'IF' ? $ap['bomba_nome'] : $ap['maquina'])) ?>)</td>
        <td class="num"><?= $fmt($capTanque, 0) ?></td>
        <?php foreach ($itens as $i): ?>
          <td class="num"><?= $fmt((float)$i['quantidade_consumida'] * $capTanque / $volTotal) ?> <?= h((string)($i['quantidade_unidade'] ?? '')) ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endif; ?>
      <?php if ($tanques['resto'] > 0): ?>
      <tr>
        <td><strong>Última carga (parcial)</strong></td>
        <td class="num"><?= $fmt($tanques['resto'], 0) ?></td>
        <?php foreach ($itens as $i): ?>
          <td class="num"><?= $fmt((float)$i['quantidade_consumida'] * $tanques['resto'] / $volTotal) ?> <?= h((string)($i['quantidade_unidade'] ?? '')) ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endif; ?>
      <tr>
        <td><strong>Total — <?= $tanques['total'] ?> carga(s)</strong></td>
        <td class="num"><?= $fmt($volTotal, 0) ?></td>
        <?php foreach ($itens as $i): ?>
          <td class="num"><strong><?= $fmt((float)$i['quantidade_consumida']) ?> <?= h((string)($i['quantidade_unidade'] ?? '')) ?></strong></td>
        <?php endforeach; ?>
      </tr>
    </tbody>
  </table>
  <?php else: ?>
  <div class="obs"><?= $volTotal <= 0
      ? 'Sem volume de calda informado — cálculo por tanque indisponível.'
      : 'Máquina sem capacidade de tanque cadastrada (Máquinas → Cadastro) — cálculo por tanque indisponível.' ?></div>
  <?php endif; ?>

  <h2>Datas de colheita</h2>
  <div class="grid" style="grid-template-columns:repeat(2,1fr)">
    <div><div class="lbl">Colheita PERMITIDA a partir de (carência máx. <?= $carenciaMax ?> dia(s))</div>
      <div class="val" style="font-size:15px"><?= $colheitaPermitida ? $dBR($colheitaPermitida) : 'sem carência registrada' ?></div></div>
    <div><div class="lbl">Confronto na colheita</div>
      <div class="val">registro de colheita dentro da carência gera alerta crítico de resíduo (automático)</div></div>
  </div>
  <?php if ($colheitaPermitida && $colheitaPermitida > date('Y-m-d')): ?>
    <div class="alertbox">⚠ ÁREA EM CARÊNCIA — colheita liberada somente a partir de <?= $dBR($colheitaPermitida) ?>.</div>
  <?php endif; ?>

  <h2>Confirmação pós-aplicação<?= $emitida ? ' (preencher no campo)' : '' ?></h2>
  <div class="grid">
    <div><div class="lbl">Início</div><div class="val"><?= $emitida ? '____:____' : $hM($ap['executada_inicio']) ?></div></div>
    <div><div class="lbl">Término</div><div class="val"><?= $emitida ? '____:____' : $hM($ap['executada_fim']) ?></div></div>
    <div><div class="lbl">Céu</div><div class="val"><?= $emitida ? '( ) noite ( ) sol ( ) nublado ( ) chuva' : h((string)($conf['ceu'] ?? '—')) ?></div></div>
    <div><div class="lbl">Vento</div><div class="val"><?= $emitida ? '( ) brisa ( ) moderado ( ) forte' : h((string)($conf['vento_class'] ?? '—')) ?></div></div>
    <div><div class="lbl">Vento (km/h)</div><div class="val"><?= $emitida ? '________' : (isset($conf['vento_kmh_real']) && $conf['vento_kmh_real'] !== null ? $fmt((float)$conf['vento_kmh_real'], 1) : '—') ?></div></div>
    <div><div class="lbl">Pluviosidade (mm)</div><div class="val"><?= $emitida ? '________' : (isset($conf['pluviosidade_mm']) && $conf['pluviosidade_mm'] !== null ? $fmt((float)$conf['pluviosidade_mm'], 1) : '—') ?></div></div>
    <div><div class="lbl">Tríplice lavagem</div><div class="val"><?= $emitida ? '( ) sim ( ) não' : ((int)($ap['triplice_lavagem'] ?? 0) === 1 ? 'SIM' : 'não registrado') ?></div></div>
    <div><div class="lbl">Destino da sobra de calda</div><div class="val"><?= $emitida ? '______________________' : h((string)($conf['destino_sobra_calda'] ?? '—')) ?></div></div>
  </div>

  <h2>Operadores / EPI</h2>
  <table>
    <thead><tr><th>Operador</th><th>Código EPI</th><th>EPI lavado</th><th>Condição do EPI</th><th>Assinatura</th></tr></thead>
    <tbody>
    <?php if ($ap['_operadores']): foreach ($ap['_operadores'] as $op): ?>
      <tr>
        <td><?= h((string)($op['operador_nome'] ?? '—')) ?></td>
        <td><?= h((string)($op['epi_codigo'] ?? '')) ?: '—' ?></td>
        <td><?= $op['epi_lavagem'] === null ? '—' : ((int)$op['epi_lavagem'] === 1 ? 'sim' : 'não') ?></td>
        <td><?= h((string)($op['epi_condicao'] ?? '')) ?: '—' ?></td>
        <td class="sig-cell"><?php
          $oid = (int)($op['operador_id'] ?? 0);
          if ($oid && isset($assinPorOp[$oid])):
              echo $sigImg($assinPorOp[$oid]['assinatura_svg']);
              echo '<span class="sig-meta">assinado no app em ' . date('d/m/Y H:i', strtotime((string)$assinPorOp[$oid]['assinado_em'])) . '</span>';
          elseif ($op['assinado_em']):
              echo 'assinado digitalmente em ' . date('d/m/Y H:i', strtotime((string)$op['assinado_em']));
          endif; ?></td>
      </tr>
    <?php endforeach; endif; ?>
    <?php for ($k = count($ap['_operadores']); $k < max(3, count($ap['_operadores'])); $k++): ?>
      <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
    <?php endfor; ?>
    </tbody>
  </table>

  <?php if ($assinAvulsas): ?>
  <h2>Assinatura(s) coletada(s) no app</h2>
  <div class="assin-app">
    <?php foreach ($assinAvulsas as $as): ?>
      <div class="assin-app-item">
        <?= $sigImg($as['assinatura_svg']) ?>
        <div class="assin-app-nome"><?= h((string)($as['operador_nome'] ?? '')) ?: '—' ?></div>
        <span class="sig-meta">assinado no app em <?= date('d/m/Y H:i', strtotime((string)$as['assinado_em'])) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="assin">
    <div>Emissor<?= $ap['emissor'] ? ' — ' . h((string)$ap['emissor']) : '' ?></div>
    <div>Responsável técnico<?= $ap['rt_nome'] ? ' — ' . h((string)$ap['rt_nome']) : '' ?>
      <?= $ap['validador'] ? '<br>validado no sistema por ' . h((string)$ap['validador']) : '' ?></div>
    <div>Supervisor / coordenador</div>
  </div>

  <div class="obs">
    Documento <?= h($docTxt) ?> gerado pelo VERO em <?= date('d/m/Y H:i') ?> · registro para fins de
    certificação (GlobalG.A.P. IFA). Retenção mínima: 2 anos (AF 2.1).
    <?php if (($ap['_sem_registro'] ?? 0) > 0): ?>
      <br><?php if ($emitida): /* OS de campo: itens presentes, marcados */ ?>
        <strong><?= (int)$ap['_sem_registro'] ?> <?= (int)$ap['_sem_registro'] === 1 ? 'item sem registro MAPA' : 'itens sem registro MAPA' ?></strong>
        nesta OS de campo — após a execução, o documento oficial (DF/IF) sai sem esses itens.
      <?php else: ?>
        <strong><?= (int)$ap['_sem_registro'] ?> <?= (int)$ap['_sem_registro'] === 1 ? 'item sem registro MAPA omitido' : 'itens sem registro MAPA omitidos' ?></strong>
        deste documento oficial — ver a relação de campo do aplicador (Aplicações) para a lista completa.
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?= print_doc_rodape('Registro para certificação (GlobalG.A.P. IFA)') ?>
</body>
</html>
