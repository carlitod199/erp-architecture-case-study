<?php
/* ============================================================
   VERO — Irrigação / Impresso de Ordem de Irrigação  (W-08/N-03)
   Rota: /irrigacao/apontamento_impressao.php?id=N (ou ?ids=1,2,3 — lote)
   Guard: irrigacao.apontamentos_irrigacao.ver (leitura; página de
   impressão standalone, padrão do impresso DF31/OS — A1-27/A1-39)

   Ordem de irrigação enxuta para o ENCARREGADO SEM INTERNET: válvula,
   bomba (vazão/potência), horas/lâmina, água (m³)/energia (kWh) com
   custo, clima AUTOMÁTICO (Open-Meteo pelo ponto da fazenda — degrada
   em silêncio offline) e CAMPOS MANUAIS EM BRANCO para preencher no
   campo: irrigante, tratorista, nº do trator, horário início/fim.
   Regra 1: tudo aqui é REGISTRO — o sistema não recomenda lâmina/vazão.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/../includes/print_doc.php'; // A0-22/P-106: cabeçalho/rodapé canônico (logo VERO + emissor)

vero_require('irrigacao.apontamentos_irrigacao.ver');

$t = vero_tenant();

/* ids: único (?id=) ou lote (?ids=1,2,3 — máx. 50 por tirada) */
$ids = [];
if (!empty($_GET['id']))  $ids[] = (int)$_GET['id'];
if (!empty($_GET['ids'])) foreach (explode(',', (string)$_GET['ids']) as $x) $ids[] = (int)$x;
$ids = array_slice(array_values(array_unique(array_filter($ids))), 0, 50);
if (!$ids) { http_response_code(400); exit('Informe ?id= ou ?ids= do(s) apontamento(s) de irrigação.'); }

$docs = [];
foreach ($ids as $apId) {
    $ap = vero_row(
        "SELECT a.*, t.codigo AS talhao, t.area_ha AS talhao_area, t.fazenda_id AS fz_id,
                f.nome AS fazenda, f.municipio, f.uf,
                s.identificacao AS safra, cu.nome AS cultura, va.nome AS variedade,
                ue.nome AS emissor, pv.nome AS pivo,
                pl.lamina_mm AS plan_lamina, pl.data_inicio AS plan_ini, pl.data_fim AS plan_fim
           FROM irrigacao_apontamentos a
           JOIN agro_talhoes t ON t.id = a.talhao_id
           JOIN agro_fazendas f ON f.id = t.fazenda_id
           LEFT JOIN agro_safra_talhoes st ON st.id = a.safra_talhao_id
           LEFT JOIN agro_safras s ON s.id = st.safra_id
           LEFT JOIN agro_culturas cu ON cu.id = st.cultura_id
           LEFT JOIN agro_variedades va ON va.id = t.variedade_id
           LEFT JOIN usuarios ue ON ue.id = a.created_by
           LEFT JOIN agro_pivos pv ON pv.id = a.pivo_id
           LEFT JOIN irrigacao_planejamentos pl ON pl.id = a.planejamento_id
          WHERE a.id = :i AND a.tenant_id = :t",
        [':i' => $apId, ':t' => $t]);
    if (!$ap) continue;

    /* consumos por tipo (água/energia) — quantidade e custo lançados no custeio */
    $ap['_consumo'] = [];
    foreach (vero_rows(
        "SELECT tipo, SUM(quantidade) AS qtd, SUM(custo) AS custo, MAX(unidade) AS unidade
           FROM irrigacao_consumos WHERE tenant_id = :t AND apontamento_id = :a GROUP BY tipo",
        [':t' => $t, ':a' => $apId]) as $c) {
        $ap['_consumo'][(string)$c['tipo']] = $c;
    }

    /* bomba(s) da válvula: setor(es) da válvula → bomba(s) ativas (vazão/potência).
       DISTINCT evita contar a mesma bomba em vários setores da válvula. */
    $ap['_bombas'] = vero_rows(
        "SELECT DISTINCT b.nome, b.vazao_m3h, b.potencia_kw
           FROM agro_setores s
           JOIN agro_bomba_valvulas bv ON bv.setor_id = s.id AND bv.tenant_id = s.tenant_id
           JOIN agro_bombas b ON b.id = bv.bomba_id AND b.tenant_id = s.tenant_id AND b.ativo = 1
          WHERE s.tenant_id = :t AND s.ativo = 1 AND s.talhao_id = :ta",
        [':t' => $t, ':ta' => (int)$ap['talhao_id']]);

    $docs[] = $ap;
}
if (!$docs) { http_response_code(404); exit('Apontamento(s) de irrigação não encontrado(s).'); }

$fmt = static fn(float $n, int $d = 2): string => number_format($n, $d, ',', '.');
$dBR = static fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';

/* Clima AUTOMÁTICO memoizado por fazenda (Open-Meteo pelo ponto da propriedade;
   degrada em silêncio → null offline, aí o bloco vira preenchimento manual). */
$climaCache = [];
$climaFazenda = static function (int $fzId) use (&$climaCache): ?array {
    if (array_key_exists($fzId, $climaCache)) return $climaCache[$fzId];
    $pt = vero_clima_ponto_fazenda($fzId);
    $prev = $pt ? vero_clima_previsao($pt['lat'], $pt['lon'], 1) : null;
    return $climaCache[$fzId] = ($prev['current'] ?? null);
};
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ordem de Irrigação — VERO</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font: 12px/1.45 "IBM Plex Sans", Arial, sans-serif; color: #1F2421; background: #F4F1E9; padding: 16px; }
  .doc { background: #fff; border: 1px solid #C9C1AE; border-radius: 6px; max-width: 860px;
         margin: 0 auto 20px; padding: 22px 26px; page-break-after: always; }
  .doc:last-child { page-break-after: auto; }
  h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .8px; color: #005059;
       border-bottom: 2px solid #005059; padding-bottom: 3px; margin: 16px 0 8px; }
  table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  th, td { border: 1px solid #C9C1AE; padding: 4px 7px; text-align: left; vertical-align: top; }
  th { background: #EFEBE0; font-size: 10.5px; text-transform: uppercase; letter-spacing: .4px; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px 14px; margin-top: 6px; }
  .grid .lbl { font-size: 9.5px; text-transform: uppercase; color: #6B7069; letter-spacing: .4px; }
  .grid .val { font-weight: 600; }
  .fill { border-bottom: 1px solid #1F2421; min-height: 15px; display: block; }
  .toolbar { max-width: 860px; margin: 0 auto 14px; display: flex; gap: 10px; }
  .toolbar button, .toolbar a { font: 13px "IBM Plex Sans", Arial; padding: 8px 16px; border-radius: 6px;
    border: 1px solid #005059; background: #005059; color: #fff; cursor: pointer; text-decoration: none; }
  .toolbar a.ghost { background: #fff; color: #005059; }
  .assin { display: grid; grid-template-columns: repeat(3, 1fr); gap: 26px; margin-top: 30px; }
  .assin div { border-top: 1px solid #1F2421; text-align: center; font-size: 10.5px; padding-top: 3px; }
  .obs { font-size: 10.5px; color: #6B7069; margin-top: 10px; }
  @media print { body { background: #fff; padding: 0; } .toolbar { display: none; }
                 .doc { border: none; max-width: none; margin: 0; padding: 0; } }
</style>
<?= print_doc_css() /* A0-22: @page A4 + cabeçalho/rodapé canônico (logo VERO + emissor) */ ?>
</head>
<body>
<div class="toolbar">
  <button onclick="window.print()">🖨 Imprimir<?= count($docs) > 1 ? ' (' . count($docs) . ' ordens)' : '' ?></button>
  <a class="ghost" href="<?= BIOS_BASE ?>/irrigacao/apontamentos_irrigacao">← Voltar aos apontamentos</a>
</div>

<?php foreach ($docs as $ap):
    $agua    = $ap['_consumo']['agua']    ?? null;
    $energia = $ap['_consumo']['energia'] ?? null;
    $custo   = (float)($agua['custo'] ?? 0) + (float)($energia['custo'] ?? 0);
    $clima   = $climaFazenda((int)$ap['fz_id']);
    $sistema = $ap['pivo'] ?: ($ap['_bombas'] ? '' : null);
?>
<div class="doc">
  <?= print_doc_cabecalho('Ordem de Irrigação', [
      'Nº'      => '#' . (int)$ap['id'],
      'Data'    => $dBR((string)$ap['data_apontamento']),
      'Válvula' => (string)$ap['talhao'],
  ]) ?>
  <div style="margin:2px 0 12px;font-size:11px;color:#5A5344">
    <strong><?= h((string)$ap['fazenda']) ?><?= $ap['municipio'] ? ' · ' . h((string)$ap['municipio']) . '/' . h((string)$ap['uf']) : '' ?></strong>
    · Irrigação<?= $ap['cultura'] ? ' — ' . h(trim(($ap['cultura'] ?? '') . ' ' . ($ap['variedade'] ?? ''))) : '' ?>
  </div>

  <h2>Identificação</h2>
  <div class="grid">
    <div><div class="lbl">Válvula</div><div class="val"><?= h((string)$ap['talhao']) ?><?= $ap['talhao_area'] !== null ? ' (' . $fmt((float)$ap['talhao_area']) . ' ha)' : '' ?></div></div>
    <div><div class="lbl">Safra</div><div class="val"><?= h($ap['safra'] ?? '—') ?></div></div>
    <div><div class="lbl">Pivô / sistema</div><div class="val"><?= h($ap['pivo'] ?? '—') ?></div></div>
    <div><div class="lbl">Data</div><div class="val"><?= $dBR((string)$ap['data_apontamento']) ?></div></div>
    <div><div class="lbl">Horas de irrigação</div><div class="val"><?= (float)$ap['horas'] > 0 ? $fmt((float)$ap['horas'], 1) . ' h' : '____:____' ?></div></div>
    <div><div class="lbl">Lâmina (mm)</div><div class="val"><?= (float)$ap['lamina_mm'] > 0 ? $fmt((float)$ap['lamina_mm'], 1) : '________' ?></div></div>
    <div><div class="lbl">Lâmina-alvo planejada</div><div class="val"><?= $ap['plan_lamina'] !== null ? $fmt((float)$ap['plan_lamina'], 1) . ' mm' : '—' ?></div></div>
    <div><div class="lbl">Emitido por</div><div class="val"><?= h($ap['emissor'] ?? '') ?: '—' ?></div></div>
  </div>

  <h2>Bomba(s) da válvula</h2>
  <?php if ($ap['_bombas']): ?>
  <table>
    <thead><tr><th>Bomba</th><th class="num">Vazão (m³/h)</th><th class="num">Potência (kW)</th></tr></thead>
    <tbody>
    <?php foreach ($ap['_bombas'] as $b): ?>
      <tr>
        <td><strong><?= h((string)($b['nome'] ?? '—')) ?></strong></td>
        <td class="num"><?= $b['vazao_m3h'] !== null ? $fmt((float)$b['vazao_m3h'], 1) : '—' ?></td>
        <td class="num"><?= $b['potencia_kw'] !== null ? $fmt((float)$b['potencia_kw'], 1) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="obs">Sem bomba vinculada à válvula — informe água e energia manualmente abaixo.</div>
  <?php endif; ?>

  <h2>Consumos do período</h2>
  <table>
    <thead><tr><th>Recurso</th><th class="num">Quantidade</th><th class="num">Custo (R$)</th></tr></thead>
    <tbody>
      <tr>
        <td><strong>Água</strong></td>
        <td class="num"><?= $agua && (float)$agua['qtd'] > 0 ? $fmt((float)$agua['qtd'], 1) . ' m³' : '____________' ?></td>
        <td class="num"><?= $agua && (float)$agua['custo'] > 0 ? $fmt((float)$agua['custo'], 2) : '—' ?></td>
      </tr>
      <tr>
        <td><strong>Energia</strong></td>
        <td class="num"><?= $energia && (float)$energia['qtd'] > 0 ? $fmt((float)$energia['qtd'], 1) . ' kWh' : '____________' ?></td>
        <td class="num"><?= $energia && (float)$energia['custo'] > 0 ? $fmt((float)$energia['custo'], 2) : '—' ?></td>
      </tr>
      <tr>
        <td colspan="2"><strong>Total lançado no custeio</strong></td>
        <td class="num"><strong><?= $custo > 0 ? $fmt($custo, 2) : '—' ?></strong></td>
      </tr>
    </tbody>
  </table>

  <h2>Clima<?= $clima ? ' (automático — ponto da fazenda)' : ' (preencher no campo)' ?></h2>
  <?php if ($clima): ?>
  <div class="grid">
    <div><div class="lbl">Tempo</div><div class="val"><?= h((string)($clima['icone'] ?? '')) ?> <?= h((string)($clima['texto'] ?? '—')) ?></div></div>
    <div><div class="lbl">Temperatura</div><div class="val"><?= isset($clima['temp']) ? $fmt((float)$clima['temp'], 0) . ' °C' : '—' ?></div></div>
    <div><div class="lbl">Umidade</div><div class="val"><?= isset($clima['umidade']) ? (int)$clima['umidade'] . '%' : '—' ?></div></div>
    <div><div class="lbl">Vento</div><div class="val"><?= isset($clima['vento']) ? $fmt((float)$clima['vento'], 0) . ' km/h' : '—' ?></div></div>
    <div><div class="lbl">Chuva (atual)</div><div class="val"><?= isset($clima['chuva']) ? $fmt((float)$clima['chuva'], 1) . ' mm' : '—' ?></div></div>
    <div style="grid-column:span 3"><div class="lbl">Chuva no campo (mm) — preencher</div><div class="val"><span class="fill">&nbsp;</span></div></div>
  </div>
  <?php else: ?>
  <div class="grid">
    <div><div class="lbl">Tempo</div><div class="val"><span class="fill">&nbsp;</span></div></div>
    <div><div class="lbl">Temperatura (°C)</div><div class="val"><span class="fill">&nbsp;</span></div></div>
    <div><div class="lbl">Umidade (%)</div><div class="val"><span class="fill">&nbsp;</span></div></div>
    <div><div class="lbl">Vento (km/h)</div><div class="val"><span class="fill">&nbsp;</span></div></div>
    <div><div class="lbl">Chuva (mm)</div><div class="val"><span class="fill">&nbsp;</span></div></div>
  </div>
  <div class="obs">Clima automático indisponível na emissão (sem internet ou ponto da fazenda não cadastrado) — anotar manualmente.</div>
  <?php endif; ?>

  <h2>Execução no campo (preencher)</h2>
  <div class="grid">
    <div><div class="lbl">Irrigante</div><div class="val"><span class="fill">&nbsp;</span></div></div>
    <div><div class="lbl">Tratorista</div><div class="val"><span class="fill">&nbsp;</span></div></div>
    <div><div class="lbl">Nº do trator</div><div class="val"><span class="fill">&nbsp;</span></div></div>
    <div><div class="lbl">Horímetro início → fim</div><div class="val"><span class="fill">&nbsp;</span></div></div>
    <div><div class="lbl">Horário início</div><div class="val">____:____</div></div>
    <div><div class="lbl">Horário término</div><div class="val">____:____</div></div>
    <div><div class="lbl">Pressão da linha (bar)</div><div class="val"><span class="fill">&nbsp;</span></div></div>
    <div><div class="lbl">Válvula/registro conferido</div><div class="val">( ) sim&nbsp;&nbsp;( ) não</div></div>
    <div style="grid-column:1/-1"><div class="lbl">Observações do campo</div>
      <div class="val"><span class="fill" style="min-height:24px">&nbsp;</span></div></div>
  </div>

  <div class="assin">
    <div>Irrigante</div>
    <div>Encarregado / emissor<?= $ap['emissor'] ? ' — ' . h((string)$ap['emissor']) : '' ?></div>
    <div>Supervisor / coordenador</div>
  </div>

  <div class="obs">
    Ordem de irrigação #<?= (int)$ap['id'] ?> gerada pelo VERO em <?= date('d/m/Y H:i') ?> · registro para
    custeio e manejo. Transcrever os campos preenchidos em Irrigação → Apontamentos.
  </div>
</div>
<?php endforeach; ?>
<?= print_doc_rodape('Ordem de irrigação — preencher no campo e transcrever em Irrigação → Apontamentos') ?>
</body>
</html>
