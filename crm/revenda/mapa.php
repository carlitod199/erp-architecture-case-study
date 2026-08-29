<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Mapa da carteira (protótipo demo)
   Rota: /crm/revenda/mapa · dados: crm/_mock.php
   Mapa REAL da região (Leaflet local + tiles Esri World Imagery,
   mesmo padrão do mapa da fazenda do ERP) com os clientes
   plotados por coordenada ('geo' no mock) + priorização de visitas.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M   = crm_mock();
$cli = $M['clientes'];

/* dados dos marcadores p/ o JS */
$marcadores = [];
$hexCor = ['green' => '#37D39B', 'red' => '#F2606A', 'amber' => '#F0B429',
           'teal' => '#18C4AB', 'blue' => '#5FB0F5', 'violet' => '#A78BFA'];
foreach ($cli as $c) {
    $marcadores[] = [
        'id'     => $c['id'],
        'nome'   => $c['nome'],
        'cidade' => $c['cidade'],
        'info'   => $c['cultura'] . ' · ' . crm_num((float)$c['area']) . ' ha · ' . $c['status'],
        'geo'    => $c['geo'],
        'cor'    => $hexCor[$c['cor']] ?? '#18C4AB',
    ];
}

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'mapa',
    'titulo' => 'Mapa da carteira',
    'sub'    => 'Vale do São Francisco · 5 clientes · filtros por cultura, risco e visita',
    'papel'  => 'vendedor',
]);
?>

<link rel="stylesheet" href="<?= BIOS_BASE ?>/assets/vendor/leaflet/leaflet.css">
<script src="<?= BIOS_BASE ?>/assets/vendor/leaflet/leaflet.js"></script>

<div class="crm-chips">
  <span class="crm-chip on">Todos</span>
  <span class="crm-chip">Uva</span>
  <span class="crm-chip">Manga</span>
  <span class="crm-chip">Sem visita +30d</span>
  <span class="crm-chip">Oportunidade aberta</span>
  <span class="crm-chip">Prospects</span>
</div>

<div class="crm-g23">
  <div class="crm-card" style="padding:10px">
    <div id="crm-mapa" style="height:540px;border-radius:10px;overflow:hidden"></div>
    <div style="display:flex;gap:14px;margin:10px 6px 2px;font-size:11px;color:var(--crm-ink2)">
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#37D39B;margin-right:4px"></i>Ativo</span>
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#F0B429;margin-right:4px"></i>Risco médio</span>
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#F2606A;margin-right:4px"></i>Risco alto</span>
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#5FB0F5;margin-right:4px"></i>Prospect</span>
    </div>
  </div>

  <div>
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Quem devo visitar hoje</span>
      </div>
      <?php foreach ($M['visitar_hoje'] as $v): $c = $cli[$v['cliente']]; ?>
        <div data-href="<?= crm_url('revenda', 'cliente') ?>?id=<?= h($c['id']) ?>"
             style="display:flex;align-items:center;gap:12px;border:1px solid var(--crm-line);border-radius:10px;padding:10px;margin-bottom:9px;cursor:pointer">
          <span style="font:700 24px var(--num,'IBM Plex Mono');color:var(--crm-<?= h($v['cor']) ?>)"><?= (int)$v['score'] ?></span>
          <span style="flex:1;min-width:0">
            <div class="crm-ag__t"><?= h($c['nome']) ?></div>
            <div class="crm-sub"><?= h($v['motivo']) ?></div>
          </span>
          <button type="button" class="vbtn vbtn-sm" data-toast="Visita agendada">Agendar</button>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
/* Mapa real da carteira: Leaflet local + Esri World Imagery (satélite),
   o mesmo par usado no mapa da fazenda do ERP. Clientes plotados por
   coordenada real; popup leva ao Cliente 360. */
document.addEventListener('DOMContentLoaded', function () {
  if (typeof L === 'undefined') return;              /* sem rede: degrada em card vazio */
  var el = document.getElementById('crm-mapa');
  if (!el) return;

  var mapa = L.map(el, { scrollWheelZoom: true });

  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 18, attribution: 'Imagens © Esri',
  }).addTo(mapa);
  /* rótulos de cidades/estradas por cima do satélite */
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 18,
  }).addTo(mapa);

  var marcadores = <?= jsvar($marcadores) ?>;
  var urlCliente = '<?= crm_url('revenda', 'cliente') ?>';
  var grupo = [];

  marcadores.forEach(function (m) {
    var pin = L.circleMarker(m.geo, {
      radius: 9, color: '#FFFFFF', weight: 2.5,
      fillColor: m.cor, fillOpacity: 1,
    }).addTo(mapa);
    pin.bindTooltip(m.nome, { direction: 'top', offset: [0, -8] });
    pin.bindPopup(
      '<div style="font:600 13px \'IBM Plex Sans\',sans-serif;margin-bottom:2px">' + m.nome + '</div>'
      + '<div style="font-size:11.5px;color:#6B7069">' + m.cidade + '<br>' + m.info + '</div>'
      + '<a href="' + urlCliente + '?id=' + m.id + '" style="font-size:12px;font-weight:600;color:#005059">Abrir cliente 360</a>'
    );
    grupo.push(m.geo);
  });

  mapa.fitBounds(grupo, { padding: [40, 40] });
});
</script>

<?php crm_shell_end();
