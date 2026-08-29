<?php
/* ============================================================
   VERO — MIP / Relatórios MIP  (tela real, leitura)
   Rota: /mip/relatorios_mip.php   Guard: mip.relatorios_mip

   HERO (novo, 20/07): MAPA GEOGRÁFICO dos talhões (Leaflet +
   satélite Esri) colorido pela PRESSÃO de pragas/doenças —
   cor = maior (nível_infestacao ÷ nível_ação × 100) do talhão.
   Abaixo do mapa, o consolidado tabular que já existia:
   pressão por alvo, KPIs de alertas/aplicações — imprimível.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : date('Y-m-d', strtotime('-90 days'));
$fFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : date('Y-m-d');

/* A-02: agrega pela JUNÇÃO multialvo (mig 170) — o cabeçalho
   mip_monitoramentos.alvo_id guarda só o 1º alvo (compat) e escondia os demais. */
$porAlvo = vero_rows(
    "SELECT av.nome, av.tipo, av.nivel_acao,
            COUNT(ma.id) AS leituras,
            AVG(ma.nivel_infestacao) AS media,
            MAX(ma.nivel_infestacao) AS pico,
            SUM(ma.nivel_infestacao >= av.nivel_acao) AS acima
       FROM mip_monitoramento_alvos ma
       JOIN mip_monitoramentos m ON m.id = ma.monitoramento_id AND m.tenant_id = ma.tenant_id
       JOIN mip_alvos av ON av.id = ma.alvo_id
      WHERE ma.tenant_id = :t AND m.data_monitoramento BETWEEN :i AND :f
      GROUP BY av.id, av.nome, av.tipo, av.nivel_acao
      ORDER BY acima DESC, pico DESC", [':t' => $t, ':i' => $fIni, ':f' => $fFim]);

/* Talhões (geometria real do Mapa da Fazenda). Mesma origem da query de
   agro/mapa.php:103 — codigo/nome/area_ha/geometria (GeoJSON, pode ser NULL). */
$talhoes = vero_rows(
    "SELECT t.id, t.codigo, t.nome, t.area_ha, t.geometria, f.nome AS fazenda
       FROM agro_talhoes t
       JOIN agro_fazendas f ON f.id = t.fazenda_id
      WHERE t.tenant_id = :t AND t.ativo = 1
      ORDER BY f.nome, t.codigo", [':t' => $t]);

/* Leituras MIP do período por talhão × alvo × local (MAX do índice no período).
   NÃO filtra status (idem $porAlvo acima — mantém a mesma regra do relatório). */
$leiturasRows = vero_rows(
    "SELECT m.talhao_id AS tid, av.nome AS alvo, av.tipo,
            av.nivel_acao AS nivel, ma.local_infestacao AS local,
            MAX(ma.nivel_infestacao) AS indice
       FROM mip_monitoramento_alvos ma
       JOIN mip_monitoramentos m ON m.id = ma.monitoramento_id AND m.tenant_id = ma.tenant_id
       JOIN mip_alvos av ON av.id = ma.alvo_id
      WHERE ma.tenant_id = :t AND m.data_monitoramento BETWEEN :i AND :f
        AND m.talhao_id IS NOT NULL
      GROUP BY m.talhao_id, av.id, av.nome, av.tipo, av.nivel_acao, ma.local_infestacao
      ORDER BY m.talhao_id, indice DESC", [':t' => $t, ':i' => $fIni, ':f' => $fFim]);

$leiturasPorTalhao = [];
foreach ($leiturasRows as $r) {
    $leiturasPorTalhao[(int)$r['tid']][] = [
        'a' => (string)$r['alvo'],
        't' => (string)$r['tipo'],                 /* praga | doenca | planta_daninha */
        'o' => $r['local'] !== null && $r['local'] !== '' ? (string)$r['local'] : null,
        'i' => (float)$r['indice'],
        'n' => $r['nivel'] !== null ? (float)$r['nivel'] : null,
    ];
}

/* Payload do mapa: TODOS os talhões (com e sem geometria). O JS separa quem
   tem polígono utilizável de quem cai na lista "Sem polígono no cadastro"
   (geometria NULL ou GeoJSON que não parseia). */
$mapTalhoes = array_map(static function ($t) use ($leiturasPorTalhao) {
    $geo = null;
    if (!empty($t['geometria'])) {
        $g = json_decode((string)$t['geometria'], true);
        if (is_array($g) && isset($g['type'], $g['coordinates'])) $geo = $g;
    }
    return [
        'cod'  => (string)$t['codigo'],
        'nome' => (string)($t['nome'] ?? ''),
        'fz'   => (string)$t['fazenda'],
        'area' => (float)$t['area_ha'],
        'geo'  => $geo,
        'L'    => $leiturasPorTalhao[(int)$t['id']] ?? [],
    ];
}, $talhoes);

$comGeometria = 0;
foreach ($mapTalhoes as $mt) { if ($mt['geo'] !== null) $comGeometria++; }


$GUARD      = ['macro' => 'mip', 'micro' => 'relatorios_mip'];
$PAGE_VIEW  = 'mip_relatorios_mip';
$PAGE_TITLE = 'Relatórios MIP';
/* QA-013: Leaflet VENDORIZADO (assets/vendor) — sem CDN. leaflet-draw NÃO é
   necessário aqui (mapa é só leitura). Tiles Esri seguem remotos (base de fundo). */
$EXTRA_HEAD = vero_assets()
    . '<link rel="stylesheet" href="' . BIOS_BASE . '/assets/vendor/leaflet/leaflet.css">'
    . '<script src="' . BIOS_BASE . '/assets/vendor/leaflet/leaflet.js"></script>'
    . '<script defer src="' . BIOS_BASE . '/assets/vendor/echarts/echarts.min.js"></script>'
    . '<style media="print">.vsidebar,.no-print{display:none !important}</style>';

/* Donut das principais pragas/doenças (por nº de leituras no período) — top 7 */
/* matizes DISTINTOS (nao tons do mesmo teal) p/ diferenciar os alvos no donut */
$PALETA_ALVO = ['#005059', '#C97B24', '#6B8E23', '#A6392E', '#5B6BA5', '#8E5A9F', '#D4A017', '#3E8E7E'];
$donutAlvos = [];
foreach ($porAlvo as $i => $r) {
    if ((int)$r['leituras'] <= 0) continue;
    $donutAlvos[] = ['nome' => (string)$r['nome'], 'tipo' => (string)$r['tipo'],
        'leituras' => (int)$r['leituras'], 'cor' => $PALETA_ALVO[count($donutAlvos) % count($PALETA_ALVO)]];
}
require __DIR__ . '/../includes/agro_header.php';
?>
<style>
/* Escopo do hero-mapa MIP (não recria a chrome do sistema) */
.mipmap .mm-pills{display:inline-flex;gap:4px;background:#FBF8F2;border:1px solid #E3D9C8;padding:3px;border-radius:10px}
.mipmap .mm-pill{appearance:none;border:0;background:transparent;color:#8A7C68;font:600 12px inherit;padding:6px 15px;border-radius:7px;cursor:pointer}
.mipmap .mm-pill:hover{color:#241B14}
.mipmap .mm-pill.active{background:#005059;color:#fff}
.mipmap .mm-chint{margin-left:auto;font-size:12px;color:#8A7C68}
.mipmap .mm-chint b{color:#241B14}
.mipmap .mm-grid{display:grid;grid-template-columns:1.9fr 1fr;gap:16px}
.mipmap #mm-map{height:400px;width:100%;border-radius:0 0 13px 13px;z-index:0}
.mipmap .mm-maplegend{background:rgba(255,255,255,.93);border:1px solid #D9CEBB;border-radius:9px;padding:8px 11px;font:12px/1.35 inherit;color:#2B2018;box-shadow:0 1px 6px rgba(8,38,42,.25)}
.mipmap .mm-maplegend .ttl{font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#8A7C68;margin-bottom:6px}
.mipmap .mm-maplegend .lg{display:flex;align-items:center;gap:7px;padding:2px 0}
.mipmap .mm-maplegend .lg i{width:11px;height:11px;border-radius:3px;flex:0 0 auto}
.mipmap .mm-maplegend .lg span{color:#9A8C78;margin-left:auto;padding-left:14px;font-size:10.5px;font-family:ui-monospace,Menlo,monospace}
.mipmap .mm-donut{padding:12px 16px 14px}
.mipmap .mm-donut .dwrap{position:relative;height:170px}
.mipmap .mm-donut .dwrap>#mm-donut{width:100%;height:100%}
.mipmap .mm-donut .dctr{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none}
.mipmap .mm-donut .dctr b{font-family:ui-monospace,Menlo,monospace;font-size:26px;font-weight:700;color:#241B14;line-height:1}
.mipmap .mm-donut .dctr span{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9A8C78;margin-top:3px}
.mipmap .mm-donut .dleg{display:flex;flex-direction:column;gap:6px;margin-top:8px}
.mipmap .mm-donut .dleg .lg{display:flex;align-items:center;gap:8px;font-size:12px;color:#2B2018}
.mipmap .mm-donut .dleg .lg i{width:11px;height:11px;border-radius:3px;flex:0 0 auto}
.mipmap .mm-donut .dleg .lg .tp{color:#9A8C78;font-size:10.5px;text-transform:uppercase;letter-spacing:.03em}
.mipmap .mm-donut .dleg .lg .n{margin-left:auto;font-family:ui-monospace,Menlo,monospace;font-weight:600;color:#241B14}
.mipmap .leaflet-container{background:#0c1f22;font-family:inherit}
.mipmap .mm-side{display:flex;flex-direction:column;gap:16px}
.mipmap .mm-cardhead{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 16px;border-bottom:1px solid #E3D9C8}
.mipmap .mm-cardhead h3{margin:0;font-size:13.5px;font-weight:600;color:#241B14}
.mipmap .mm-cardnote{font-size:11.5px;color:#9A8C78}
.mipmap .mm-legend{padding:12px 16px 14px}
.mipmap .mm-legend .lg{display:flex;align-items:center;gap:9px;font-size:12.5px;color:#2B2018;padding:4px 0}
.mipmap .mm-legend .lg i{width:13px;height:13px;border-radius:3px;flex:0 0 auto}
.mipmap .mm-legend .lg .d{color:#9A8C78;margin-left:auto;font-size:11.5px}
.mipmap .mm-legend .foot{font-size:11px;color:#8A7C68;margin-top:8px;line-height:1.5}
.mipmap .mm-selbox{padding:14px 16px;min-height:96px}
.mipmap .mm-selbox .hint{color:#9A8C78;font-size:12.5px}
.mipmap .mm-selbox .sh{font-weight:700;color:#241B14;font-size:15px;display:flex;align-items:center;gap:8px}
.mipmap .mm-selbox .sh .tag{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#fff;padding:2px 8px;border-radius:20px}
.mipmap .mm-selbox .meta{color:#8A7C68;font-size:11.5px;margin:3px 0 10px}
.mipmap .mm-selbox .row{display:flex;justify-content:space-between;gap:10px;font-size:12.5px;padding:4px 0;border-top:1px solid #E3D9C8}
.mipmap .mm-selbox .row .p{font-family:ui-monospace,Menlo,monospace;font-weight:700}
.mm-tlbl{background:rgba(8,38,42,.72);border:0;border-radius:5px;color:#fff;font-weight:700;font-size:12px;padding:1px 7px;box-shadow:none}
.mm-tlbl::before{display:none}
.mm-pop b{color:#241B14}.mm-pop .h{font-weight:700;color:#241B14;font-size:13.5px}
.mm-pop .sub{color:#8A7C68;font-size:11.5px;margin:2px 0 8px}
.mm-pop .al{display:flex;justify-content:space-between;gap:12px;padding:3px 0;border-top:1px solid #E3D9C8}
.mm-pop .al .p{font-family:ui-monospace,Menlo,monospace;font-weight:700}
@media(max-width:960px){.mipmap .mm-grid{grid-template-columns:1fr}.mipmap .mm-chint{margin-left:0;width:100%}}
</style>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Relatórios MIP', 'Mapa de pressão dos talhões e pressão por alvo', null) ?>

  <!-- ===== HERO: mapa geográfico de pressão ===== -->
  <div class="mipmap">
    <div class="vcard vtoolbar no-print" style="gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
      <div class="mm-pills" id="mm-pills">
        <button class="mm-pill active" type="button" data-s="todos">Todos</button>
        <button class="mm-pill" type="button" data-s="praga">Pragas</button>
        <button class="mm-pill" type="button" data-s="doenca">Doenças</button>
      </div>
      <div class="mm-chint" id="mm-chint"></div>
    </div>

    <div class="mm-grid">
      <div class="vcard" style="padding:0;overflow:hidden">
        <div class="mm-cardhead"><h3>Mapa da fazenda — satélite</h3><span class="mm-cardnote">clique num talhão · geometria do cadastro VERO</span></div>
        <div id="mm-map"></div>
      </div>

      <div class="mm-side">
        <div class="vcard mm-selbox" id="mm-selbox">
          <div class="hint">Clique num talhão no mapa para ver as leituras de pragas e doenças.</div>
        </div>
        <?php if ($donutAlvos): ?>
        <div class="vcard mm-donut">
          <div class="mm-cardhead" style="padding:0 0 10px;border-bottom:0"><h3>Principais pragas e doenças</h3><span class="mm-cardnote">por nº de leituras no período</span></div>
          <div class="dwrap"><div id="mm-donut"></div>
            <div class="dctr"><b id="mm-donut-tot">—</b><span>leituras</span></div>
          </div>
          <div class="dleg" id="mm-donut-leg"></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ===== Nível de Infestação (situação atual por alvo×válvula) ===== -->
  <?php
  /* Situação atual: última leitura de cada alvo×válvula contra o nível de ação,
     com pico dos últimos 30 dias (trazido de mip/nivel_infestacao.php). */
  $nivelRows = vero_rows(
      "SELECT m.nivel_infestacao, m.unidade, m.data_monitoramento,
              av.nome AS alvo, av.tipo AS alvo_tipo, av.nivel_acao,
              tl.codigo AS talhao, fz.nome AS fazenda,
              (SELECT MAX(m3.nivel_infestacao) FROM mip_monitoramentos m3
                WHERE m3.tenant_id = m.tenant_id AND m3.alvo_id = m.alvo_id AND m3.talhao_id = m.talhao_id
                  AND m3.data_monitoramento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS pico_30d
         FROM mip_monitoramentos m
         JOIN (SELECT alvo_id, talhao_id, MAX(CONCAT(data_monitoramento, LPAD(id,10,'0'))) AS chave
                 FROM mip_monitoramentos WHERE tenant_id = :t1 GROUP BY alvo_id, talhao_id) ult
           ON ult.alvo_id = m.alvo_id AND ult.talhao_id = m.talhao_id
          AND CONCAT(m.data_monitoramento, LPAD(m.id,10,'0')) = ult.chave
         LEFT JOIN mip_alvos av ON av.id = m.alvo_id
         LEFT JOIN agro_talhoes tl ON tl.id = m.talhao_id
         LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
        WHERE m.tenant_id = :t2
        ORDER BY (m.nivel_infestacao >= av.nivel_acao) DESC, m.nivel_infestacao DESC",
      [':t1' => $t, ':t2' => $t]);
  $acimaN = 0;
  foreach ($nivelRows as $r) if ($r['nivel_acao'] !== null && (float)$r['nivel_infestacao'] >= (float)$r['nivel_acao']) $acimaN++;
  ?>
  <div class="vcard" style="margin-top:16px">
    <div class="vtoolbar">
      <strong>Nível de Infestação</strong>
      <span class="vsub" style="margin-left:10px"><?= count($nivelRows) ?> combinação(ões) alvo×válvula ·
        <strong style="color:<?= $acimaN > 0 ? '#b3261e' : 'var(--vero-ok,#1a7f4b)' ?>"><?= $acimaN ?> no nível de ação</strong></span>
    </div>
    <?php if (!$nivelRows): ?>
      <div class="vempty">Nenhum monitoramento registrado ainda.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Alvo</th><th>Tipo</th><th>Válvula</th>
        <th>Última leitura</th>
        <th class="num">Índice atual</th>
        <th class="num">Pico 30d</th>
        <th class="num">Nível de ação</th>
        <th style="width:20%">Posição</th>
      </tr></thead>
      <tbody>
      <?php foreach ($nivelRows as $r):
          $idx   = (float)$r['nivel_infestacao'];
          $nivel = $r['nivel_acao'] !== null ? (float)$r['nivel_acao'] : null;
          $pct   = $nivel !== null && $nivel > 0 ? min($idx / $nivel * 100, 200) : 0;
          $acima = $nivel !== null && $idx >= $nivel; ?>
        <tr>
          <td><strong><?= h($r['alvo'] ?? '—') ?></strong></td>
          <td><span class="vbadge vb-info"><?= h(ucfirst(str_replace('_', ' ', (string)($r['alvo_tipo'] ?? '—')))) ?></span></td>
          <td><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_monitoramento'])) ?></td>
          <td class="vnum" style="text-align:right;<?= $acima ? 'color:#b3261e;font-weight:700' : '' ?>">
            <?= numFmt($idx, 1) ?> <span class="vhint"><?= h($r['unidade'] ?? '%') ?></span></td>
          <td class="num"><?= $r['pico_30d'] !== null ? numFmt((float)$r['pico_30d'], 1) : '—' ?></td>
          <td class="num"><?= $nivel !== null ? numFmt($nivel, 1) : '—' ?></td>
          <td><div style="display:flex;align-items:center;gap:8px">
            <div style="flex:1;height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden;position:relative">
              <div style="height:100%;width:<?= number_format(min($pct / 2, 100), 1, '.', '') ?>%;
                          background:<?= $acima ? '#b3261e' : 'var(--vero-ok,#1a7f4b)' ?>;border-radius:5px"></div>
              <div style="position:absolute;left:50%;top:-2px;bottom:-2px;width:2px;background:#333" title="nível de ação"></div>
            </div>
            <span class="vnum vhint"><?= $nivel !== null ? numFmt($idx / max($nivel, 0.001) * 100, 0) . '%' : '—' ?></span>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">A marca central da barra é o nível de ação (100%). Índices no nível ou acima geram alerta em MIP → Alertas Fitossanitários. Decisões de controle são do responsável técnico.</div>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var COL={pos:"#0E7E72",olive:"#4E9CA1",amber:"#B57C1A",danger:"#B23A2E",gray:"#9a8c78"};
  function classify(pm){return pm>=200?{k:"Crítico",c:COL.danger}:pm>=100?{k:"Alerta",c:COL.amber}:pm>=50?{k:"Atenção",c:COL.olive}:{k:"Controlado",c:COL.pos};}
  function nf(n,d){d=d||0;return Number(n).toLocaleString("pt-BR",{minimumFractionDigits:d,maximumFractionDigits:d});}
  var pct=function(n){return Math.round(n)+"%";};

  var TAL=<?= jsvar($mapTalhoes) ?>;

  /* GeoJSON (Polygon/MultiPolygon) → LatLngs do L.polygon.
     GeoJSON é [lng,lat]; Leaflet quer [lat,lng] — invertemos aqui.
     Retorna null se não houver anel utilizável (→ lista "sem polígono"). */
  function ring2ll(r){return r.map(function(c){return [c[1],c[0]];});}
  function geoToLatLngs(g){
    if(!g||!g.type||!g.coordinates)return null;
    var out;
    if(g.type==="Polygon"){ out=g.coordinates.map(ring2ll); }
    else if(g.type==="MultiPolygon"){ out=g.coordinates.map(function(poly){return poly.map(ring2ll);}); }
    else return null;
    return (out&&out.length&&out[0]&&out[0].length)?out:null;
  }

  /* pressão de um alvo = índice ÷ nível de ação × 100 (nível<=0 é ignorado) */
  function pOf(l){return (l.n&&l.n>0)?(l.i/l.n*100):null;}
  function press(t,scope){
    var best=null,top=null;
    (t.L||[]).forEach(function(l){
      if(scope!=="todos"&&l.t!==scope)return;
      var pm=pOf(l); if(pm===null)return;
      if(best===null||pm>best){best=pm;top=l;}
    });
    return best===null?null:{pm:best,top:top};
  }

  var withPoly=[], noPoly=[];
  TAL.forEach(function(t){
    var ll=geoToLatLngs(t.geo);
    if(ll){t._ll=ll;withPoly.push(t);}else{noPoly.push(t);}
  });

  var scope="todos";
  function styleFor(t){
    var p=press(t,scope), c=p?classify(p.pm).c:COL.gray;
    return {color:"#fff",weight:2,opacity:.9,fillColor:c,fillOpacity:p?.62:.34,dashArray:p?null:"4 4"};
  }
  function tipoLbl(tp){return tp==="praga"?"Praga":tp==="doenca"?"Doença":tp==="planta_daninha"?"Planta daninha":tp;}
  function leituraLine(l,cls){
    var pm=pOf(l), s=pm===null?{c:COL.gray}:classify(pm);
    var loc=l.o?' <span style="color:#9A8C78">· '+l.o+'</span>':'';
    var val=pm===null?'—':pct(pm);
    var det=(pm===null||l.n===null)?'':'<span style="color:#9A8C78;font-weight:400"> ('+nf(l.i,1)+'/'+nf(l.n,0)+')</span>';
    return '<div class="'+cls+'"><span>'+l.a+loc+'</span><span class="p" style="color:'+s.c+'">'+val+det+'</span></div>';
  }
  function popupHtml(t){
    var h='<div class="mm-pop"><div class="h">Talhão '+t.cod+'</div><div class="sub">'+t.fz+' · '+nf(t.area,2)+' ha</div>';
    var ls=(t.L||[]).filter(function(l){return scope==="todos"||l.t===scope;});
    if(!ls.length){ h+='<div style="color:#8A7C68">Sem leitura no período.</div>'; }
    else { ls.forEach(function(l){ h+=leituraLine(l,'al'); }); }
    return h+'</div>';
  }
  function select(t){
    var p=press(t,"todos");
    var tag=p?classify(p.pm):{k:"Sem leitura",c:COL.gray};
    var html='<div class="sh">Talhão '+t.cod+' <span class="tag" style="background:'+tag.c+'">'+tag.k+'</span></div>'+
      '<div class="meta">'+t.fz+' · '+nf(t.area,2)+' ha'+(t.nome?' · '+t.nome:'')+'</div>';
    if(!(t.L||[]).length){ html+='<div class="hint">Sem leitura de pragas/doenças no período.</div>'; }
    else { t.L.forEach(function(l){ html+=leituraLine(l,'row'); }); }
    document.getElementById("mm-selbox").innerHTML=html;
  }

  var map=L.map("mm-map",{zoomControl:true,scrollWheelZoom:true}).setView([-9.39,-40.5],11);
  L.tileLayer("https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",
    {maxZoom:19,attribution:"Imagens © Esri World Imagery"}).addTo(map);

  /* legenda de pressão DENTRO do mapa (canto inferior direito) */
  var mapLegend=L.control({position:"bottomright"});
  mapLegend.onAdd=function(){
    var d=L.DomUtil.create("div","mm-maplegend");
    d.innerHTML='<div class="ttl">Pressão</div>'+
      '<div class="lg"><i style="background:'+COL.pos+'"></i>Controlado <span>&lt;50%</span></div>'+
      '<div class="lg"><i style="background:'+COL.olive+'"></i>Atenção <span>50–99%</span></div>'+
      '<div class="lg"><i style="background:'+COL.amber+'"></i>Alerta <span>100–199%</span></div>'+
      '<div class="lg"><i style="background:'+COL.danger+'"></i>Crítico <span>≥200%</span></div>'+
      '<div class="lg"><i style="background:'+COL.gray+';opacity:.55"></i>Sem leitura</div>';
    L.DomEvent.disableClickPropagation(d);
    return d;
  };
  mapLegend.addTo(map);

  var layers=[];
  withPoly.forEach(function(t){
    var poly=L.polygon(t._ll,styleFor(t)).addTo(map);
    poly.bindTooltip(t.cod,{permanent:true,direction:"center",className:"mm-tlbl",offset:[0,0]});
    poly.bindPopup(popupHtml(t),{maxWidth:280});
    poly.on("click",function(){select(t);});
    poly.on("mouseover",function(){poly.setStyle({weight:3,fillOpacity:.78});});
    poly.on("mouseout",function(){poly.setStyle(styleFor(t));});
    t._poly=poly; layers.push(poly);
  });
  if(layers.length){
    var grp=L.featureGroup(layers);
    try{ map.fitBounds(grp.getBounds().pad(0.25)); }catch(e){}
  }
  setTimeout(function(){map.invalidateSize();},150);
  window.addEventListener("resize",function(){map.invalidateSize();});

  function updateHint(){
    var withData=withPoly.filter(function(t){return press(t,scope);}).length;
    var crit=withPoly.filter(function(t){var p=press(t,scope);return p&&p.pm>=200;}).length;
    document.getElementById("mm-chint").innerHTML="<b>"+withPoly.length+"</b> talhões com polígono · <b>"+withData+"</b> com leitura"+
      (scope==="todos"?"":" ("+(scope==="praga"?"pragas":"doenças")+")")+" · <b>"+crit+"</b> crítico(s) · <b>"+noPoly.length+"</b> sem polígono";
  }
  function restyle(){ withPoly.forEach(function(t){t._poly.setStyle(styleFor(t));t._poly.setPopupContent(popupHtml(t));}); updateHint(); }

  document.querySelectorAll("#mm-pills .mm-pill").forEach(function(b){
    b.addEventListener("click",function(){
      document.querySelectorAll("#mm-pills .mm-pill").forEach(function(x){x.classList.remove("active");});
      this.classList.add("active"); scope=this.getAttribute("data-s"); restyle();
    });
  });

  updateHint();
})();

/* Donut das principais pragas/doenças (ECharts local; enhancement) */
function mmDonutInit(){
  var host=document.getElementById("mm-donut"); if(!host) return;
  var D=<?= jsvar($donutAlvos) ?>;
  if(!D.length || typeof echarts==="undefined") return;
  var reduced=window.matchMedia("(prefers-reduced-motion: reduce)").matches || navigator.webdriver===true;
  var total=D.reduce(function(s,x){return s+x.leituras;},0);
  var totEl=document.getElementById("mm-donut-tot"); if(totEl) totEl.textContent=total;
  var ch=echarts.init(host,null,{renderer:"canvas"});
  ch.setOption({
    textStyle:{fontFamily:"inherit"},
    animationDuration: reduced?0:600,
    tooltip:{trigger:"item",formatter:function(p){return p.data.name+" ("+p.data.tp+")<br/><b>"+p.value+"</b> leitura(s) · "+Math.round(p.value/total*100)+"%";}},
    series:[{type:"pie",radius:["58%","82%"],center:["50%","50%"],avoidLabelOverlap:false,
      label:{show:false},labelLine:{show:false},padAngle:2,
      itemStyle:{borderColor:"#fff",borderWidth:2,borderRadius:4},
      data:D.map(function(a){return {value:a.leituras,name:a.nome,tp:a.tipo==="doenca"?"doença":(a.tipo==="planta_daninha"?"planta daninha":"praga"),itemStyle:{color:a.cor}};})}]
  });
  /* clique numa fatia: centro mostra o valor do alvo; clicar de novo volta ao total */
  var sel=null;
  function centro(b, s){ if(totEl){ totEl.textContent=b; totEl.nextElementSibling.textContent=s; } }
  ch.on("click", function(p){
    if(sel===p.name){ sel=null; centro(total, "leituras"); }
    else { sel=p.name; centro(p.value, p.name); }
  });
  document.getElementById("mm-donut-leg").innerHTML=D.map(function(a){
    var tp=a.tipo==="doenca"?"doença":(a.tipo==="planta_daninha"?"planta daninha":"praga");
    return '<div class="lg"><i style="background:'+a.cor+'"></i>'+a.nome+' <span class="tp">'+tp+'</span><span class="n">'+a.leituras+'</span></div>';
  }).join("");
  window.addEventListener("resize",function(){ch.resize();});
}
/* echarts entra com defer → só está pronto no DOMContentLoaded/load */
if(document.readyState==="loading") document.addEventListener("DOMContentLoaded",mmDonutInit); else mmDonutInit();
</script>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
