<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Rota & Mapa da Carteira (protótipo)
   Rota: /crm/consultor/rota · dados fiéis ao mockup
   docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.rota)
   Mapa REAL: Leaflet local + tiles Esri
   World Imagery — o mesmo par do mapa da fazenda do ERP e do
   crm/revenda/mapa.php. Paradas do roteiro com as coordenadas
   reais do Meu Dia; demais produtores plotados pelo status.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* TODO mover para _mock.php */
/* Paradas do roteiro (coords = mesmas do meu-dia.php) */
$PARADAS = [
    ['n' => 1, 'nome' => 'Fazenda Boa Vista',    'geo' => [-9.3891, -40.5030], 'vid' => 'V214',
     'info' => 'João Almeida · Petrolina · PE · 07:30'],
    ['n' => 2, 'nome' => 'Fazenda Nova Aliança', 'geo' => [-8.8061, -39.8266], 'vid' => 'V215',
     'info' => 'Fernanda Sá · Santa Maria da Boa Vista · PE · 09:45'],
    ['n' => 3, 'nome' => 'Fazenda Santa Helena', 'geo' => [-8.9905, -40.2712], 'vid' => 'V216',
     'info' => 'Carlos Mendes · Lagoa Grande · PE · 13:30'],
    ['n' => 4, 'nome' => 'Fazenda Bom Jesus',    'geo' => [-9.3455, -40.5610], 'vid' => 'V217',
     'info' => 'Helena Vasconcelos · Petrolina · PE · 16:00'],
];
/* Demais produtores da carteira: cor pelo status (tokens claros do VERO) */
$CARTEIRA_PINS = [
    ['nome' => 'Boa Vista II',  'geo' => [-9.4370, -40.6120], 'cor' => '#8A7C68',
     'info' => 'João Almeida · em dia'],
    ['nome' => 'Vale Verde',    'geo' => [-9.2210, -40.3480], 'cor' => '#8A7C68',
     'info' => 'Maria Oliveira · em dia'],
    ['nome' => 'São José',      'geo' => [-9.0910, -40.1350], 'cor' => '#B23A2E',
     'info' => 'Antônio Ribeiro · 47 dias sem contato'],
    ['nome' => 'Serra Branca',  'geo' => [-8.8830, -40.3660], 'cor' => '#B23A2E',
     'info' => 'José Bezerra · 61 dias sem contato'],
    ['nome' => 'Riacho Grande', 'geo' => [-9.2470, -40.6640], 'cor' => '#B57C1A',
     'info' => 'Roberto Nakamura · visita vencendo'],
];

/* Sequência otimizada do dia: [ordem, hora, propriedade, mun, tipo, km, visitaId] */
$ROTEIRO = [
    [1, '07:30', 'Fazenda Boa Vista',    'Petrolina · PE',                 'Captação', 12, 'V214'],
    [2, '09:45', 'Fazenda Nova Aliança', 'Santa Maria da Boa Vista · PE',  'Prospecção',          54, 'V215'],
    [3, '13:30', 'Fazenda Santa Helena', 'Lagoa Grande · PE',              'Follow-up',           38, 'V216'],
    [4, '16:00', 'Fazenda Bom Jesus',    'Petrolina · PE',                 'Pós-venda',           24, 'V217'],
];

/* Encaixes por proximidade: [propriedade, distância, motivo, cor] */
$ENCAIXES = [
    ['Serra Branca', '11 km do trecho das 13:30', 'José Bezerra · 61 dias sem contato · sem oportunidade aberta', 'red'],
    ['Vale Verde',   '8 km do trecho das 09:45',  'Maria Oliveira · programa de floração pendente de envio',      'amber'],
    ['São José',     '14 km do trecho das 16:00', 'Antônio Ribeiro · 47 dias sem contato · R$ 18.400 vencidos',   'red'],
];

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'rota',
    'titulo' => 'Rota & Mapa da Carteira',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" data-toast="Roteiro recalculado">Recalcular roteiro</button>',
]);
?>

<?php /* KPIs retirados a pedido do gestor 25/08 — a tela é o mapa + sequência;
         o resumo (128 km · 4 paradas) já vive no badge do próprio mapa */ ?>

<div class="crm-tabs">
  <span class="crm-tab on">Hoje</span>
  <span class="crm-tab">Semana</span>
  <span class="crm-tab">Carteira</span>
</div>

<link rel="stylesheet" href="<?= BIOS_BASE ?>/assets/vendor/leaflet/leaflet.css">
<script src="<?= BIOS_BASE ?>/assets/vendor/leaflet/leaflet.js"></script>

<div class="crm-g23">
  <div class="crm-card" style="padding:10px">
    <div style="position:relative">
      <div id="crm-mapa-rota" style="height:540px;border-radius:10px;overflow:hidden"></div>
      <div style="position:absolute;right:12px;top:12px;z-index:1000;background:var(--crm-card);border:1px solid var(--crm-line2);border-radius:10px;padding:9px 13px;text-align:right;box-shadow:0 4px 14px rgba(36,27,20,.15)">
        <div class="crm-card__title">Roteiro de hoje</div>
        <div style="font:600 18px var(--num,'IBM Plex Mono');color:var(--crm-teal)">128 km</div>
        <div class="crm-sub">4 paradas · 07:00 → 17:40</div>
      </div>
    </div>
    <div style="display:flex;gap:14px;margin:10px 6px 2px;font-size:11px;color:var(--crm-ink2);flex-wrap:wrap">
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#005059;margin-right:4px"></i>Roteiro de hoje</span>
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#B57C1A;margin-right:4px"></i>Visita vencendo</span>
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#B23A2E;margin-right:4px"></i>Sem contato &gt; 45 dias</span>
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#8A7C68;margin-right:4px"></i>Em dia</span>
    </div>
  </div>

  <div>
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Sequência otimizada</span>
        <?= crm_pill('roteiro', 'teal') ?>
      </div>
      <?php foreach ($ROTEIRO as [$n, $hora, $propn, $mun, $tipo, $km, $vid]): ?>
        <div class="crm-ag" data-href="<?= crm_url('consultor', 'visita') ?>?id=<?= h($vid) ?>" style="cursor:pointer">
          <span class="crm-ag__h"><?= h($hora) ?></span>
          <span class="crm-ag__bar"></span>
          <span class="crm-ag__body">
            <div class="crm-ag__t"><?= crm_pill((string)$n, 'grey') ?> <?= h($propn) ?></div>
            <div class="crm-ag__sub"><?= h($mun) ?> · <?= h($tipo) ?></div>
          </span>
          <?= crm_pill($km . ' km', 'grey') ?>
        </div>
      <?php endforeach; ?>
      <div class="crm-ag">
        <span class="crm-ag__h">17:40</span>
        <span class="crm-ag__bar b-grey"></span>
        <span class="crm-ag__body">
          <div class="crm-ag__t">Retorno · Petrolina</div>
          <div class="crm-ag__sub">Total 128 km · 3h05 de deslocamento</div>
        </span>
      </div>
    </div>

    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Encaixes por proximidade</span>
        <span class="crm-sub">Oportunidade de rota</span>
      </div>
      <?php foreach ($ENCAIXES as $i => [$propn, $dist, $motivo, $cor]): ?>
        <div style="display:flex;gap:10px;align-items:flex-start;padding:10px 0;<?= $i > 0 ? 'border-top:1px solid var(--crm-line)' : '' ?>">
          <span style="flex:0 0 8px;width:8px;height:8px;border-radius:50%;margin-top:5px;background:var(--crm-<?= h($cor) ?>)"></span>
          <span style="flex:1;min-width:0">
            <div style="font-size:12.5px;font-weight:600"><?= h($propn) ?> · <span style="font-weight:500;color:var(--crm-ink2)"><?= h($dist) ?></span></div>
            <div class="crm-sub"><?= h($motivo) ?></div>
          </span>
          <button type="button" class="vbtn vbtn-sm" data-toast="Visita encaixada no roteiro">Encaixar</button>
        </div>
      <?php endforeach; ?>
      <?= crm_callout(
          '<strong>Roteiro de amanhã passa por Juazeiro.</strong> Vale Verde, São José e Serra Branca estão no caminho '
          . '— as duas últimas sem visita há mais de 45 dias.',
          'teal'
      ) ?>
    </div>
  </div>
</div>

<script>
/* Mapa REAL da rota: Leaflet local + Esri World Imagery (satélite) com camada
   de rótulos — mesmo padrão de crm/revenda/mapa.php e do mapa da fazenda.
   Paradas do roteiro = marcadores NUMERADOS ligados por linha tracejada;
   demais produtores = círculos coloridos pelo status. */
document.addEventListener('DOMContentLoaded', function () {
  if (typeof L === 'undefined') return;              /* sem tiles/rede: degrada sem quebrar */
  var el = document.getElementById('crm-mapa-rota');
  if (!el) return;

  var mapa = L.map(el, { scrollWheelZoom: true });
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 18, attribution: 'Imagens © Esri',
  }).addTo(mapa);
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 18,
  }).addTo(mapa);

  var paradas  = <?= jsvar($PARADAS) ?>;
  var carteira = <?= jsvar($CARTEIRA_PINS) ?>;
  var urlVisita = '<?= crm_url('consultor', 'visita') ?>';
  var bounds = [];

  /* linha do roteiro (ordem das paradas) */
  L.polyline(paradas.map(function (p) { return p.geo; }), {
    color: '#005059', weight: 3, dashArray: '7 7', opacity: .85,
  }).addTo(mapa);

  paradas.forEach(function (p) {
    var pin = L.marker(p.geo, {
      icon: L.divIcon({
        className: '',
        html: '<div style="width:26px;height:26px;border-radius:50%;background:#005059;color:#fff;'
            + 'border:2.5px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35);display:flex;align-items:center;'
            + 'justify-content:center;font:700 12px \'IBM Plex Mono\',monospace">' + p.n + '</div>',
        iconSize: [26, 26], iconAnchor: [13, 13],
      }),
    }).addTo(mapa);
    pin.bindTooltip(p.nome, { direction: 'top', offset: [0, -12] });
    pin.bindPopup(
      '<div style="font:600 13px \'IBM Plex Sans\',sans-serif;margin-bottom:2px">' + p.n + ' · ' + p.nome + '</div>'
      + '<div style="font-size:11.5px;color:#6B7069">' + p.info + '</div>'
      + '<a href="' + urlVisita + '?id=' + p.vid + '" style="font-size:12px;font-weight:600;color:#005059">Abrir visita</a>'
    );
    bounds.push(p.geo);
  });

  carteira.forEach(function (m) {
    var pin = L.circleMarker(m.geo, {
      radius: 8, color: '#FFFFFF', weight: 2.5, fillColor: m.cor, fillOpacity: 1,
    }).addTo(mapa);
    pin.bindTooltip(m.nome, { direction: 'top', offset: [0, -8] });
    pin.bindPopup(
      '<div style="font:600 13px \'IBM Plex Sans\',sans-serif;margin-bottom:2px">' + m.nome + '</div>'
      + '<div style="font-size:11.5px;color:#6B7069">' + m.info + '</div>'
    );
    bounds.push(m.geo);
  });

  mapa.fitBounds(bounds, { padding: [40, 40] });
});
</script>

<?php crm_shell_end();
