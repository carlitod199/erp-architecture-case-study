<?php
/* ============================================================
   VERO — Gestão Agrícola / Mapa da Fazenda  (tela real)
   Substitui o mock. Rota da matriz: /agro/mapa.php
   Guard: agricola.mapa_fazenda | Escrita: agro.mapa_fazenda.editar
   Leaflet (CDN) + tiles de satélite Esri World Imagery.
   Geometria das válvulas em agro_talhoes.geometria (GeoJSON — D8,
   MySQL 5.7). Desenho/edição de polígono com Leaflet.draw; clique
   na válvula abre painel com área, válvulas, alertas e custo.
   Importação KML/KMZ: linha de corte nº 2 — fase seguinte.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_setor_espelho.php'; /* A1-37: modo unificado (arbitragem A1-32) */
require_once __DIR__ . '/_mapa_import.php';   /* A1-57: importador KML/KMZ/GeoJSON (XXE-safe) */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    /* A1-57 (P-104): importar mapa KML/KMZ/GeoJSON → geometria das válvulas.
       SECURITY (CSO/PT-01): valida upload real + extensão (allowlist) + tamanho
       ANTES de parsear; o parser é XXE-safe (DOCTYPE rejeitado, sem rede) e o KMZ
       é lido da memória (sem extrair p/ disco). Casa por código/nome da válvula. */
    if ($acao === 'importar_mapa') {
        vero_require('agro.mapa_fazenda.editar');
        $f = $_FILES['arquivo'] ?? null;
        if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
            vero_flash('erro', 'Selecione um arquivo válido (KML, KMZ ou GeoJSON).');
            vero_redirect();
        }
        if ((int)($f['size'] ?? 0) <= 0 || (int)$f['size'] > MAPA_IMPORT_MAX_BYTES) {
            vero_flash('erro', 'Arquivo vazio ou maior que 5 MB.');
            vero_redirect();
        }
        $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, MAPA_IMPORT_EXT, true)) {
            vero_flash('erro', 'Formato não suportado. Aceitos: KML, KMZ e GeoJSON (Shapefile é fase 2).');
            vero_redirect();
        }
        $modoImp = ($_POST['modo_import'] ?? 'so_vazias') === 'sobrescrever' ? 'sobrescrever' : 'so_vazias';
        /* KMZ = zip (lê da memória no helper); demais = conteúdo texto, limitado */
        $content = $ext === 'kmz' ? '' : (string)file_get_contents((string)$f['tmp_name'], false, null, 0, MAPA_IMPORT_MAX_BYTES);
        $feats = mapa_import_parse($ext, $content, (string)$f['tmp_name']);
        if (!$feats) {
            vero_flash('erro', 'Nenhum polígono reconhecido no arquivo (arquivos com DOCTYPE/entidades XML são recusados por segurança).');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $rep = mapa_import_aplicar($feats, $modoImp);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Falha ao importar o mapa: ' . h($e->getMessage()));
            vero_redirect();
        }
        $msg = "Importação: {$rep['casados']} válvula(s) com mapa atualizado";
        if (($rep['area_recalc'] ?? 0) > 0) $msg .= " (área recalculada pelo polígono)";
        if ($rep['ignoradas'] > 0) $msg .= ", {$rep['ignoradas']} já tinha(m) mapa (mantido — use 'sobrescrever' para trocar)";
        if ($rep['sem_nome'] > 0)  $msg .= ", {$rep['sem_nome']} polígono(s) sem nome no arquivo";
        if ($rep['sem_match'])     $msg .= '. Sem correspondência de válvula: ' . implode(', ', array_slice($rep['sem_match'], 0, 12))
                                          . (count($rep['sem_match']) > 12 ? ' …' : '');
        vero_flash($rep['casados'] > 0 ? 'ok' : 'aviso', $msg);
        vero_redirect();
    }

    if ($acao === 'salvar_geometria') {
        vero_require('agro.mapa_fazenda.editar');
        $talhaoId = vero_int('talhao_id');
        $geojson  = trim((string)($_POST['geometria'] ?? ''));

        $talhao = $talhaoId ? vero_row("SELECT * FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
            [':i' => $talhaoId, ':t' => vero_tenant()]) : null;
        if (!$talhao) {
            vero_flash('erro', 'Válvula inválido.');
            vero_redirect();
        }
        if ($geojson === '') {
            vero_update('agro_talhoes', (int)$talhaoId, ['geometria' => null]);
            vero_flash('ok', "Geometria da válvula \"{$talhao['codigo']}\" removida.");
            vero_redirect();
        }
        $geo = json_decode($geojson, true);
        $tipo = is_array($geo) ? ($geo['type'] ?? ($geo['geometry']['type'] ?? '')) : '';
        if (!in_array($tipo, ['Polygon', 'MultiPolygon', 'Feature'], true)) {
            vero_flash('erro', 'Geometria inválida — esperado GeoJSON Polygon/MultiPolygon.');
            vero_redirect();
        }
        /* grava o polígono e RECALCULA a área da válvula pelo próprio desenho */
        $upd = ['geometria' => json_encode($geo, JSON_UNESCAPED_UNICODE)];
        $areaHa = mapa_area_ha_geojson($geo);
        if ($areaHa !== null) $upd['area_ha'] = $areaHa;
        vero_update('agro_talhoes', (int)$talhaoId, $upd);

        /* modo unificado: mantém a válvula-espelho com a nova área */
        if (vero_a1_valvula_unificada()) {
            $row = vero_row("SELECT * FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
                [':i' => (int)$talhaoId, ':t' => vero_tenant()]);
            if ($row) vero_a1_sync_espelho($row);
        }

        $msgArea = $areaHa !== null ? ' Área recalculada: ' . numFmt($areaHa, 2) . ' ha.' : '';
        vero_flash('ok', "Geometria da válvula \"{$talhao['codigo']}\" salva.{$msgArea}");
        vero_redirect();
    }

    /* X-01/Y-02: limpar polígonos importados para poder
       reimportar sem sobreposição. Por fazenda (fazenda_id) ou todas. */
    if ($acao === 'limpar_geometrias') {
        vero_require('agro.mapa_fazenda.editar');
        $fazId = vero_int('fazenda_id') ?: null;
        if ($fazId) {
            $st = vero_pdo()->prepare(
                "UPDATE agro_talhoes SET geometria = NULL WHERE tenant_id = :t AND fazenda_id = :f AND geometria IS NOT NULL");
            $st->execute([':t' => vero_tenant(), ':f' => $fazId]);
        } else {
            $st = vero_pdo()->prepare(
                "UPDATE agro_talhoes SET geometria = NULL WHERE tenant_id = :t AND geometria IS NOT NULL");
            $st->execute([':t' => vero_tenant()]);
        }
        $n = $st->rowCount();
        vero_flash($n > 0 ? 'ok' : 'aviso',
            $n > 0 ? "{$n} polígono(s) removido(s). Agora você pode reimportar o mapa sem sobreposição."
                   : 'Nenhum polígono para remover.');
        vero_redirect();
    }
}

/* válvulas com indicadores para o painel */
$talhoes = vero_rows(
    "SELECT t.id, t.codigo, t.nome, t.area_ha, t.latitude, t.longitude, t.geometria,
            f.nome AS fazenda, t.fazenda_id,
            (SELECT COUNT(*) FROM agro_setores s
              WHERE s.tenant_id = t.tenant_id AND s.talhao_id = t.id AND s.ativo = 1) AS valvulas,
            (SELECT COUNT(*) FROM agro_alertas al
              WHERE al.tenant_id = t.tenant_id AND al.talhao_id = t.id AND al.status = 'aberto') AS alertas,
            (SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl
              WHERE cl.tenant_id = t.tenant_id AND cl.talhao_id = t.id) AS custo,
            (SELECT GROUP_CONCAT(DISTINCT CONCAT(s.identificacao, ' · ', c.nome) SEPARATOR ' | ')
               FROM agro_safra_talhoes st
               JOIN agro_safras s ON s.id = st.safra_id
               JOIN agro_culturas c ON c.id = st.cultura_id
              WHERE st.tenant_id = t.tenant_id AND st.talhao_id = t.id) AS safras
       FROM agro_talhoes t
       JOIN agro_fazendas f ON f.id = t.fazenda_id
      WHERE t.tenant_id = :t AND t.ativo = 1
      ORDER BY f.nome, t.codigo",
    [':t' => vero_tenant()]);

$GUARD      = ['macro' => 'agricola', 'micro' => 'mapa_fazenda'];
$PAGE_VIEW  = 'agricola_mapa_fazenda';
$PAGE_TITLE = 'Mapa da Fazenda';
/* QA-013 (A0, 07/07): Leaflet/Draw VENDORIZADOS (assets/vendor) — regra
   self-host dos dashboards estendida ao mapa; funciona sem internet externa.
   Tiles Esri continuam remotos (mapa de fundo — degrada com aviso offline). */
$EXTRA_HEAD = vero_assets()
    . '<link rel="stylesheet" href="' . BIOS_BASE . '/assets/vendor/leaflet/leaflet.css">'
    . '<link rel="stylesheet" href="' . BIOS_BASE . '/assets/vendor/leaflet-draw/leaflet.draw.css">'
    . '<script src="' . BIOS_BASE . '/assets/vendor/leaflet/leaflet.js"></script>'
    . '<script src="' . BIOS_BASE . '/assets/vendor/leaflet-draw/leaflet.draw.js"></script>';
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.mapa_fazenda.editar');

/* Camadas de clima (online). Chave OWM vem só do backend (tenant_parametros:
   chave 'clima.owm_api_key'); sem card na tela. Vazia = camadas OWM ficam ocultas. */
$owmKey = (string) vero_srv_param('clima.owm_api_key', '');
/* NDVI MODIS 8-Day: snap para o início do período de 8 dias, com ~16 dias de latência */
$gibsTs      = strtotime('-16 days');
$gibsDoy     = (int) date('z', $gibsTs) + 1;
$gibsPeriodo = (int) (floor(($gibsDoy - 1) / 8) * 8) + 1;
$gibsDate    = date('Y-m-d', strtotime(date('Y', $gibsTs) . '-01-01') + ($gibsPeriodo - 1) * 86400);

/* A4 (previsão estendida): clima GERAL da fazenda para 7 dias (hoje + 6).
   Ponto = centroide dos talhões (helper server-side); Open-Meteo forecast_days=7
   com cache de 30 min. Degrada em silêncio: null → painel mostra "indisponível".
   (pedido do usuário 22/07: 7 dias já é suficiente, era 16.) */
$climaFazPt  = vero_clima_ponto_fazenda();
$climaFaz    = $climaFazPt ? vero_clima_previsao($climaFazPt['lat'], $climaFazPt['lon'], 7) : null;
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Mapa da Fazenda', 'Polígonos das válvulas (GeoJSON) sobre imagem de satélite — clique num válvula para o painel; selecione e desenhe para salvar a geometria', null) ?>

  <!-- Previsão (7 dias) GERAL da fazenda — ACIMA do mapa (redesign 22/07) -->
  <style>
    #clima-fazenda{margin:0 auto 16px;padding:0;width:100%;max-width:1240px;overflow:hidden}
    .wx-in{padding:17px 20px 18px}
    /* faixa "agora" ----------------------------------------------------- */
    .wx-head{display:flex;align-items:center;gap:18px;flex-wrap:wrap;
      padding-bottom:16px;border-bottom:1px solid #EFE8DA;margin-bottom:16px}
    .wx-now{display:flex;align-items:center;gap:15px}
    .wx-now-ico{font-size:38px;line-height:1;display:grid;place-items:center;
      width:66px;height:66px;border-radius:18px;flex:0 0 auto;
      background:radial-gradient(120% 120% at 30% 22%,rgba(0,80,89,.11),rgba(0,80,89,.03));
      border:1px solid rgba(0,80,89,.12);filter:drop-shadow(0 3px 8px rgba(0,0,0,.12))}
    .wx-eyebrow{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--accent,#005059);font-weight:700}
    .wx-now-temp{font-size:2.15rem;font-weight:800;color:var(--ink,#241B14);line-height:1.04;letter-spacing:-.02em;margin-top:2px}
    .wx-now-temp span{font-size:1.05rem;font-weight:600;color:var(--muted,#8A7C68);margin-left:2px}
    .wx-now-desc{font-size:12.5px;color:var(--muted,#8A7C68);margin-top:3px}
    /* indicadores ------------------------------------------------------- */
    .wx-meta{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
    .wx-stat{display:flex;align-items:center;gap:10px;background:#FBF9F4;border:1px solid #EFE8DA;
      border-radius:13px;padding:8px 14px;min-width:104px}
    .wx-stat svg{width:18px;height:18px;color:var(--accent,#005059);opacity:.88;flex:0 0 auto}
    .wx-stat .k{font-size:9.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted,#8A7C68);line-height:1.35}
    .wx-stat .v{font-size:14.5px;font-weight:700;color:var(--ink,#241B14);line-height:1.12}
    /* dias -------------------------------------------------------------- */
    .wx-days{display:grid;grid-template-columns:repeat(<?= max(1, count($climaFaz['days'] ?? [1])) ?>,minmax(0,1fr));gap:8px}
    .wx-day{position:relative;text-align:center;padding:14px 6px 12px;border-radius:14px;
      background:#FBFAF6;border:1px solid #EFE8DA;overflow:hidden;
      transition:transform .16s var(--ease,ease),box-shadow .16s,border-color .16s}
    .wx-day:hover{transform:translateY(-2px);box-shadow:0 10px 22px -12px rgba(0,0,0,.24);border-color:#E0D6C4}
    .wx-day.is-today{background:linear-gradient(180deg,rgba(0,80,89,.075),rgba(0,80,89,.015));border-color:rgba(0,80,89,.30)}
    .wx-day.is-today::before{content:"";position:absolute;top:0;left:14px;right:14px;height:3px;
      border-radius:0 0 3px 3px;background:var(--accent,#005059)}
    .wx-dow{font-size:11.5px;font-weight:700;color:var(--ink,#241B14);text-transform:capitalize;letter-spacing:.01em}
    .wx-day.is-today .wx-dow{color:var(--accent,#005059)}
    .wx-date{font-size:10px;color:var(--muted2,#9A8C78);margin-top:1px}
    .wx-ico{font-size:27px;line-height:1.25;margin:7px 0 6px;filter:drop-shadow(0 2px 4px rgba(0,0,0,.10))}
    .wx-temp{font-size:13.5px;display:flex;align-items:baseline;justify-content:center;gap:5px}
    .wx-max{font-weight:800;color:var(--ink,#241B14)}
    .wx-min{color:var(--muted,#9A8C78);font-size:12px}
    .wx-rain{display:inline-flex;align-items:center;gap:3px;font-size:10.5px;font-weight:700;color:#2E7D9A;
      margin-top:8px;background:rgba(46,125,154,.10);border-radius:999px;padding:2px 9px}
    .wx-rain.dry{color:#B6AC98;background:transparent;font-weight:600}
    @media(max-width:760px){.wx-days{grid-template-columns:repeat(4,1fr)!important}
      .wx-meta{width:100%;margin-left:0}.wx-stat{flex:1;min-width:0}}
  </style>
  <div class="vcard" id="clima-fazenda">
    <?php if ($climaFaz): $cur = $climaFaz['current']; ?>
      <div class="wx-in">
      <div class="wx-head">
        <div class="wx-now">
          <span class="wx-now-ico"><?= h($cur['icone']) ?></span>
          <div>
            <div class="wx-eyebrow">Previsão da fazenda · próximos 7 dias</div>
            <div class="wx-now-temp"><?= (int)$cur['temp'] ?><span>°C</span></div>
            <div class="wx-now-desc"><?= h($cur['texto']) ?> · agora</div>
          </div>
        </div>
        <div class="wx-meta">
          <div class="wx-stat">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 14.76V4a2 2 0 1 0-4 0v10.76a4 4 0 1 0 4 0Z"/></svg>
            <div><div class="k">Sensação</div><div class="v"><?= (int)$cur['sensacao'] ?>°C</div></div>
          </div>
          <div class="wx-stat">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.7S6 9.2 6 13a6 6 0 0 0 12 0c0-3.8-6-10.3-6-10.3Z"/></svg>
            <div><div class="k">Umidade</div><div class="v"><?= (int)$cur['umidade'] ?>%</div></div>
          </div>
          <div class="wx-stat">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8h11a2.5 2.5 0 1 0-2.5-2.5M3 12h16a2.5 2.5 0 1 1-2.5 2.5M3 16h9"/></svg>
            <div><div class="k">Vento</div><div class="v"><?= (int)$cur['vento'] ?> <small style="font-size:11px;font-weight:600;color:var(--muted,#8A7C68)">km/h</small></div></div>
          </div>
        </div>
      </div>
      <div class="wx-days">
        <?php foreach ($climaFaz['days'] as $i => $d):
          $rot = $i === 0 ? 'Hoje' : ucfirst((string)$d['dia']);
          $dm  = date('d/m', strtotime((string)$d['data'] . ' 00:00'));
          $ch  = max(0, min(100, (int)$d['chuvapct'])); ?>
          <div class="wx-day<?= $i === 0 ? ' is-today' : '' ?>">
            <div class="wx-dow"><?= h($rot) ?></div>
            <div class="wx-date"><?= h($dm) ?></div>
            <div class="wx-ico"><?= h($d['icone']) ?></div>
            <div class="wx-temp"><span class="wx-max"><?= (int)$d['max'] ?>°</span><span class="wx-min"><?= (int)$d['min'] ?>°</span></div>
            <div class="wx-rain<?= $ch === 0 ? ' dry' : '' ?>">💧 <?= $ch ?>%</div>
          </div>
        <?php endforeach; ?>
      </div>
      </div>
    <?php else: ?>
      <div style="padding:14px 18px">
        <div class="vtoolbar" style="padding:0 0 8px"><strong style="font-size:14px">Previsão da fazenda</strong>
          <span class="vhint">próximos 7 dias</span></div>
        <div class="vempty" style="padding:10px 0">⚠️ Previsão indisponível no momento (sem internet ou talhões sem localização). O mapa segue funcional.</div>
      </div>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start;width:100%;max-width:1240px;margin-inline:auto">
    <div class="vcard" style="overflow:hidden;display:flex;flex-direction:column;padding:0">
      <div id="mapa" style="flex:1 1 auto;height:520px"></div>
    </div>

    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="vcard" id="painel">
        <div class="vtoolbar"><strong style="font-size:14px" id="painel-titulo">Painel da válvula</strong></div>
        <div class="vempty" id="painel-vazio">Clique num polígono ou marcador do mapa.</div>
        <div id="painel-corpo" style="display:none;padding:14px 18px;font-size:13px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 14px">
            <div><div class="vhint">Fazenda</div><strong id="p-fazenda">—</strong></div>
            <div><div class="vhint">Área (ha)</div><strong class="vnum" id="p-area">—</strong></div>
            <?php if (!vero_a1_valvula_unificada()): /* A1-37: 1:1 → sempre 1 */ ?>
            <div><div class="vhint">Válvulas ativas</div><strong class="vnum" id="p-valvulas">—</strong></div>
            <?php endif; ?>
            <div><div class="vhint">Alertas abertos</div><strong class="vnum" id="p-alertas">—</strong></div>
            <div style="grid-column:1/-1"><div class="vhint">Safras vinculadas</div><strong id="p-safras">—</strong></div>
            <div style="grid-column:1/-1"><div class="vhint">Custo acumulado (custeio)</div><strong class="vnum" id="p-custo" style="color:#005059">—</strong></div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid #F0EBDF">
            <a class="vbtn vbtn-ghost vbtn-sm" id="p-ficha" href="#">Abrir ficha</a>
            <a class="vbtn vbtn-ghost vbtn-sm" id="p-custos" href="#">Ver custos</a>
            <a class="vbtn vbtn-ghost vbtn-sm" id="p-hist" href="#">Ver histórico</a>
          </div>
        </div>
      </div>

      <!-- order:1 -> Legenda vai p/ o fim; assim o card "desenhar/editar"
           fica logo abaixo dos detalhes da válvula -->
      <div class="vcard" style="padding:14px 18px;order:1">
        <strong style="font-size:13px;display:block;margin-bottom:10px">Legenda</strong>
        <div style="display:flex;flex-direction:column;gap:7px;font-size:12.5px;color:#4A4034">
          <div style="display:flex;align-items:center;gap:9px"><span style="width:16px;height:12px;border-radius:3px;background:#005059;border:2px solid #00E5C7"></span> Válvula ativo</div>
          <div style="display:flex;align-items:center;gap:9px"><span style="width:16px;height:12px;border-radius:3px;background:#B23A2E;border:2px solid #E0A100"></span> Com alerta aberto</div>
          <div style="display:flex;align-items:center;gap:9px"><span style="width:16px;height:12px;border-radius:3px;background:#6B5F53;border:2px solid #9A8C78"></span> Sem safra vinculada</div>
          <div style="display:flex;align-items:center;gap:9px"><span style="width:16px;height:12px;border-radius:3px;background:#005059;border:2px solid #fff;box-shadow:0 0 0 2px #005059"></span> Selecionado</div>
        </div>
      </div>

      <?php if ($podeEditar): /* desenhar/editar + importar — recolhível, inicia fechado */ ?>
      <details class="vcard mapa-tools" style="padding:0">
        <summary class="mapa-tools__sum">
          <span>Válvula para desenhar/editar</span>
          <svg class="mapa-tools__chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div style="padding:0 18px 16px">
        <div class="vfield">
          <label>Selecione a válvula</label>
          <select id="sel-talhao">
            <option value="">— Selecione —</option>
            <?php foreach ($talhoes as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= h($t['fazenda']) ?> — <?= h($t['codigo']) ?><?= $t['geometria'] ? ' ✓' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <div class="vhint">✓ = já tem polígono. Selecione e use as ferramentas de desenho no mapa; o salvamento pede confirmação.</div>
        </div>
        <form method="post" id="geo-form" style="display:none">
          <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
          <input type="hidden" name="acao" value="salvar_geometria">
          <input type="hidden" name="talhao_id" id="geo-talhao">
          <input type="hidden" name="geometria" id="geo-json">
        </form>

        <!-- A1-57 (P-104): importar mapa KML/KMZ/GeoJSON — casa por código/nome -->
        <form method="post" enctype="multipart/form-data" style="margin-top:14px;padding-top:12px;border-top:1px solid #F0EBDF">
          <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
          <input type="hidden" name="acao" value="importar_mapa">
          <label style="font-weight:600;font-size:13px">Importar mapa da fazenda</label>
          <div class="vhint" style="margin:2px 0 6px">KML, KMZ ou GeoJSON (até 5 MB). Cada polígono casa com a válvula pelo <strong>código ou nome</strong>. Shapefile fica p/ a fase 2.</div>
          <input type="file" name="arquivo" accept=".kml,.kmz,.geojson,.json" required class="map-file">
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:8px">
            <input type="checkbox" name="modo_import" value="sobrescrever">
            Sobrescrever mapas já existentes (padrão: só preenche válvulas sem polígono)
          </label>
          <button type="submit" class="vbtn vbtn-primary vbtn-sm">Importar</button>
        </form>

        <!-- X-01/Y-02: limpar polígonos p/ reimportar sem sobreposição -->
        <?php
          $fazImport = [];
          foreach ($talhoes as $tl) {
              $fid = (int)($tl['fazenda_id'] ?? 0);
              if ($fid && !isset($fazImport[$fid])) $fazImport[$fid] = (string)$tl['fazenda'];
          }
        ?>
        <form method="post" style="margin-top:12px;padding-top:12px;border-top:1px solid #F0EBDF"
              onsubmit="return confirm('Remover os polígonos importados? As válvulas ficam sem desenho até você reimportar. Esta ação não afeta os cadastros, só a geometria do mapa.');">
          <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
          <input type="hidden" name="acao" value="limpar_geometrias">
          <label style="font-weight:600;font-size:13px">Limpar polígonos do mapa</label>
          <div class="vhint" style="margin:2px 0 6px">Apaga o desenho das válvulas para você reimportar do zero (corrige sobreposição). Não remove válvulas nem dados.</div>
          <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <select name="fazenda_id" style="flex:1;min-width:140px">
              <option value="">Todas as fazendas</option>
              <?php foreach ($fazImport as $fid => $fnome): ?>
                <option value="<?= (int)$fid ?>"><?= h($fnome) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="vbtn vbtn-ghost vbtn-sm" style="color:#B3402A">Limpar polígonos</button>
          </div>
        </form>
        </div>
      </details>
      <?php endif; ?>
    </div>
  </div>

  <script>
  const TALHOES = <?= jsvar(array_map(static fn($t) => [
      'id' => (int)$t['id'], 'codigo' => $t['codigo'], 'fazenda' => $t['fazenda'],
      'area' => (float)$t['area_ha'],
      'lat' => $t['latitude'] !== null ? (float)$t['latitude'] : null,
      'lng' => $t['longitude'] !== null ? (float)$t['longitude'] : null,
      'geo' => $t['geometria'] ? json_decode((string)$t['geometria'], true) : null,
      'valvulas' => (int)$t['valvulas'], 'alertas' => (int)$t['alertas'],
      'custo' => (float)$t['custo'], 'safras' => $t['safras'],
  ], $talhoes)) ?>;
  const PODE_EDITAR = <?= $podeEditar ? 'true' : 'false' ?>;
  const BASE = '<?= rtrim(BIOS_BASE, '/') ?>';
  const fmt = (n, d = 2) => n.toLocaleString('pt-BR', {minimumFractionDigits: d, maximumFractionDigits: d});
  /* cor do polígono por estado (legenda): alerta > sem-safra > ativo */
  const corEstado = t => t.alertas > 0 ? {c: '#E0A100', f: '#B23A2E'}
      : (!t.safras ? {c: '#9A8C78', f: '#6B5F53'} : {c: '#00E5C7', f: '#005059'});
  const layers = {}; /* id → {layer, base} para realçar o selecionado */

  /* Vale do São Francisco como fallback de centro */
  const mapa = L.map('mapa').setView([-9.39, -40.5], 12);

  /* Bases: satélite (padrão) × mapa OSM. Rótulos leves ficam sempre sobre o satélite. */
  /* maxNativeZoom (2026-08-17): em Petrolina o Esri World Imagery só tem imagem
     até o zoom 17 — do 18 em diante devolve o placeholder cinza "Map data not
     yet available" (medido: z17 = 13,5 kB de imagem real; z18 e z19 = o MESMO
     arquivo de 2.521 bytes). Sem esta linha o Leaflet pedia tiles inexistentes.
     Com ela, ele reamplia o tile do 17 — borrado, porém é a fazenda. */
  const baseSat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxNativeZoom: 17, maxZoom: 19, attribution: 'Imagens © Esri World Imagery',
  }).addTo(mapa);
  const baseOSM = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '© OpenStreetMap',
  });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, opacity: 0.25, attribution: '© OpenStreetMap',
  }).addTo(mapa);

  /* Camadas de clima/tempo — online; sem internet apenas não pintam (mapa segue funcional). */
  const OWM_KEY   = '<?= h($owmKey) ?>';
  const GIBS_DATE = '<?= h($gibsDate) ?>';
  const overlays = {};

  /* NDVI (vigor da vegetação) — NASA GIBS/MODIS, sem chave */
  overlays['Vegetação · NDVI (NASA)'] = L.tileLayer(
    'https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/MODIS_Terra_NDVI_8Day/default/' + GIBS_DATE +
    '/GoogleMapsCompatible_Level9/{z}/{y}/{x}.png',
    {maxNativeZoom: 9, maxZoom: 19, opacity: 0.75, attribution: 'NDVI © NASA GIBS/MODIS', bounds: [[-85, -180], [85, 180]]});

  /* OpenWeatherMap — só com chave configurada */
  if (OWM_KEY) {
    const owm = (cam, nome) => { overlays[nome] = L.tileLayer(
      'https://tile.openweathermap.org/map/' + cam + '/{z}/{x}/{y}.png?appid=' + OWM_KEY,
      {opacity: 0.6, maxZoom: 19, attribution: '© OpenWeatherMap'}); };
    owm('temp_new', 'Temperatura (OWM)');
    owm('clouds_new', 'Nuvens (OWM)');
    owm('wind_new', 'Vento (OWM)');
    owm('precipitation_new', 'Precipitação (OWM)');
  }

  const ctrlCamadas = L.control.layers(
    {'Satélite': baseSat, 'Mapa (OSM)': baseOSM}, overlays,
    {collapsed: true, position: 'topright'}).addTo(mapa);

  /* Radar de chuva em tempo real — RainViewer (público, sem chave). Frame mais recente. */
  fetch('https://api.rainviewer.com/public/weather-maps.json')
    .then(r => r.json())
    .then(d => {
      const past = (d.radar && d.radar.past) || [];
      if (!past.length) return;
      const f = past[past.length - 1];
      const radar = L.tileLayer(d.host + f.path + '/256/{z}/{x}/{y}/2/1_1.png',
        {opacity: 0.65, maxZoom: 19, attribution: 'Radar © RainViewer'});
      ctrlCamadas.addOverlay(radar, 'Radar de chuva (RainViewer)');
    })
    .catch(() => {});

  const grupoGeo   = L.featureGroup().addTo(mapa); /* polígonos das válvulas — EDITÁVEIS (lápis/lixeira) */
  const markerGrupo = L.featureGroup().addTo(mapa); /* marcadores (válvulas sem polígono) — não editáveis */
  const bounds = [];

  const $ = id => document.getElementById(id);

  function abrirPainel(t) {
    document.getElementById('painel-titulo').textContent = 'Válvula ' + t.codigo;
    document.getElementById('painel-vazio').style.display = 'none';
    document.getElementById('painel-corpo').style.display = '';
    document.getElementById('p-fazenda').textContent = t.fazenda;
    document.getElementById('p-area').textContent = fmt(t.area) + ' ha';
    const pValv = document.getElementById('p-valvulas');
    if (pValv) pValv.textContent = t.valvulas; /* ausente no modo unificado (A1-37) */
    document.getElementById('p-alertas').textContent = t.alertas;
    document.getElementById('p-alertas').style.color = t.alertas > 0 ? '#9A3B2A' : '#1E6B34';
    document.getElementById('p-safras').textContent = t.safras || '—';
    document.getElementById('p-custo').textContent = 'R$ ' + fmt(t.custo);
    document.getElementById('p-ficha').href = BASE + '/agro/talhao_ficha?id=' + t.id;
    document.getElementById('p-custos').href = BASE + '/custeio/custos';
    document.getElementById('p-hist').href = BASE + '/mip/historico_talhao?talhao=' + t.id;
    /* realça o polígono selecionado; volta os demais à cor da legenda */
    Object.keys(layers).forEach(id => {
      const o = layers[id];
      o.layers.forEach(l => { if (l.setStyle) l.setStyle(o.base); });
    });
    if (layers[t.id]) {
      layers[t.id].layers.forEach(l => { if (l.setStyle) l.setStyle({color: '#005059', weight: 4, fillColor: '#005059', fillOpacity: 0.5}); });
    }
  }

  TALHOES.forEach(t => {
    if (t.geo) {
      const st = corEstado(t);
      const base = {color: st.c, weight: 2.5, fillColor: st.f, fillOpacity: 0.35};
      /* extrai as camadas de polígono INDIVIDUAIS (não o grupo geoJSON): só assim
         o Leaflet.draw consegue editar/excluir cada uma; marca com _talhaoId. */
      const gj = L.geoJSON(t.geo, {style: base});
      const lyrs = [];
      gj.eachLayer(l => {
        l._talhaoId = t.id;
        if (l.setStyle) l.setStyle(base);
        l.bindTooltip(t.codigo, {permanent: true, direction: 'center', className: 'mapa-rotulo'});
        l.on('click', () => abrirPainel(t));
        grupoGeo.addLayer(l);
        lyrs.push(l);
      });
      layers[t.id] = {layers: lyrs, base: base};
      try { bounds.push(gj.getBounds()); } catch (e) {}
    } else if (t.lat !== null && t.lng !== null) {
      const m = L.marker([t.lat, t.lng]).addTo(markerGrupo);
      m.bindTooltip('Válvula ' + t.codigo);
      m.on('click', () => abrirPainel(t));
      bounds.push(L.latLngBounds([t.lat, t.lng], [t.lat, t.lng]));
    }
  });
  if (bounds.length) {
    let b = bounds[0];
    bounds.slice(1).forEach(x => b = b.extend(x));
    /* maxZoom no fit (2026-08-17): o cliente tem só 2 polígonos, minúsculos e
       colados (237 m x 160 m juntos). Sem teto, o fitBounds abria no zoom 18 —
       exatamente onde acaba a imagem do Esri aqui — e o mapa nascia cinza. */
    mapa.fitBounds(b.pad(0.25), {maxZoom: 17});
  }
  /* Traz sempre uma válvula selecionada ao abrir: prioriza uma
     com polígono, depois com coordenada, senão a primeira da lista. */
  const _selPadrao = TALHOES.find(t => t.geo) || TALHOES.find(t => t.lat !== null) || TALHOES[0];
  if (_selPadrao) abrirPainel(_selPadrao);
  /* container estica via CSS (grid stretch) — Leaflet precisa remedir o tamanho */
  setTimeout(() => mapa.invalidateSize(), 150);
  window.addEventListener('resize', () => mapa.invalidateSize());

  /* desenho / edição / exclusão (apenas com permissão) */
  if (PODE_EDITAR) {
    /* barra do Leaflet.draw editando o GRUPO DOS POLÍGONOS existentes:
       lápis = mover vértices; lixeira = apagar o desenho da válvula. */
    const controle = new L.Control.Draw({
      draw: {polyline: false, circle: false, rectangle: false, marker: false, circlemarker: false,
             polygon: {shapeOptions: {color: '#F2B33D', weight: 3}}},
      edit: {featureGroup: grupoGeo},
    });
    mapa.addControl(controle);

    const CSRF = document.querySelector('#geo-form input[name="csrf_token"]').value;
    /* salva (ou remove, geom=null) a geometria de uma válvula via POST server-side */
    async function salvarGeo(talhaoId, geom) {
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('acao', 'salvar_geometria');
      fd.append('talhao_id', talhaoId);
      fd.append('geometria', geom ? JSON.stringify(geom) : '');
      await fetch(location.href, {method: 'POST', body: fd});
    }

    /* CRIAR: precisa da válvula escolhida no card "Válvula para desenhar/editar" */
    mapa.on(L.Draw.Event.CREATED, async e => {
      const sel = document.getElementById('sel-talhao');
      const talhaoId = parseInt(sel.value || '0', 10);
      if (!talhaoId) {
        alert('Selecione primeiro a válvula no card "Válvula para desenhar/editar" (abra-o à direita).');
        return;
      }
      const t = TALHOES.find(x => x.id === talhaoId);
      if (confirm('Salvar este polígono como geometria da válvula ' + (t ? t.codigo : talhaoId) + '? A área será recalculada.')) {
        await salvarGeo(talhaoId, e.layer.toGeoJSON().geometry);
        location.reload();
      }
    });

    /* EDITAR: salva cada polígono cujos vértices foram movidos (recalcula área) */
    mapa.on(L.Draw.Event.EDITED, async e => {
      const changed = [];
      e.layers.eachLayer(l => { if (l._talhaoId) changed.push(l); });
      if (!changed.length) return;
      for (const l of changed) await salvarGeo(l._talhaoId, l.toGeoJSON().geometry);
      location.reload();
    });

    /* EXCLUIR: remove só o DESENHO das válvulas marcadas (a área cadastrada é mantida) */
    mapa.on(L.Draw.Event.DELETED, async e => {
      const removed = [];
      e.layers.eachLayer(l => { if (l._talhaoId) removed.push(l); });
      if (!removed.length) return;
      if (!confirm('Remover o desenho da(s) válvula(s) selecionada(s)? Isso apaga só o polígono no mapa; a área e os cadastros são mantidos.')) {
        location.reload(); /* cancela: recarrega para restaurar o que a barra já tirou da tela */
        return;
      }
      for (const l of removed) await salvarGeo(l._talhaoId, null);
      location.reload();
    });
  }
  </script>
  <style>.mapa-rotulo{background:transparent;border:0;box-shadow:none;color:#fff;font-weight:700;font-size:13px;text-shadow:0 1px 3px rgba(0,0,0,.8)}
  /* card recolhível "desenhar/editar" — inicia fechado */
  .mapa-tools__sum{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;
    gap:10px;padding:14px 18px;font-weight:600;font-size:14px;color:#241B14;user-select:none}
  .mapa-tools__sum::-webkit-details-marker{display:none}
  .mapa-tools__sum:hover{color:#005059}
  .mapa-tools__chev{flex:0 0 auto;color:#8A7C68;transition:transform .2s var(--ease,ease)}
  .mapa-tools[open] .mapa-tools__chev{transform:rotate(180deg)}
  .mapa-tools[open] .mapa-tools__sum{border-bottom:1px solid #F0EBDF;margin-bottom:14px}
  .map-file{width:100%;margin-bottom:8px;font-size:13px;color:#5A5344;border:1px solid #D5CEBF;border-radius:9px;background:#FBFAF6;cursor:pointer}
  .map-file::file-selector-button{border:0;background:#005059;color:#fff;font:600 13px 'IBM Plex Sans',system-ui,sans-serif;padding:9px 14px;margin-right:12px;cursor:pointer;transition:background .15s}
  .map-file::file-selector-button:hover{background:#00363D}</style>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
