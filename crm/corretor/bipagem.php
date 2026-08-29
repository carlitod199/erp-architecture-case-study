<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Corretor / Bipagem de cargas (protótipo demo)
   Rota: /crm/corretor/bipagem · dados: crm/_mock.php
   Leitor simulado de código de barras: cada bip entra na lista
   de leituras e nos contadores em estado LOCAL (JS) — nada
   persiste. Dados iniciais 100% do mock ($M['bipagens']).
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

$cargaAtivaId = '0813-08';

/* Cargas do dia → capacidade (soma de caixas) e mix de produtos,
   usados pelo leitor JS para sortear a variedade de cada bip. */
$cargasJs   = [];
$cargaAtiva = null;
foreach ($M['cargas'] as $c) {
    $cargasJs[$c['id']] = [
        'dest'  => $c['dest'],
        'meta'  => array_sum(array_column($c['itens'], 'cx')),
        'prods' => array_map(static fn($i) => [$i['v'], $i['cl']], $c['itens']),
    ];
    if ($c['id'] === $cargaAtivaId) $cargaAtiva = $c;
}

$bipadas = count($M['bipagens']);                       /* leituras do mock */
$metaCx  = $cargasJs[$cargaAtivaId]['meta'];            /* 450 cx na 0813-08 */
$mono    = "font-family:var(--num,'IBM Plex Mono')";

crm_shell_start([
    'modulo' => 'corretor',
    'micro'  => 'bipagem',
    'titulo' => 'Bipagem de cargas',
    'sub'    => 'Leitor de caixas · packing house · 13/08',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Bipadas hoje', '<span id="bipHoje">' . $bipadas . '</span>', 'caixas registradas no leitor', 'teal') ?>
  <?= crm_kpi('Carga ativa', '#' . h($cargaAtivaId), h($cargaAtiva['dest']) . ' · ' . h($cargaAtiva['cam']), 'blue') ?>
  <?= crm_kpi('Progresso da carga', '<span id="bipKpiN">' . $bipadas . '</span> de <span id="bipKpiM">' . $metaCx . '</span>', 'caixas da carga bipadas', 'green') ?>
  <?= crm_kpi('Divergências', '0', 'nenhum código fora da carga', 'amber') ?>
</div>

<div class="crm-g12">

  <!-- Leitor -->
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Leitor</span>
      <?= crm_pill('Simulado', 'teal') ?>
    </div>
    <div class="vform">
      <div class="vfield">
        <label for="bipCod">Código da caixa</label>
        <input type="text" id="bipCod" autofocus autocomplete="off" inputmode="numeric"
               placeholder="Bipe ou digite o código da caixa"
               style="<?= $mono ?>;font-size:13px;letter-spacing:.5px;padding:10px 12px">
      </div>
      <div class="vfield">
        <label for="bipCarga">Carga ativa</label>
        <select id="bipCarga">
          <?php foreach ($M['cargas'] as $c): ?>
            <option value="<?= h($c['id']) ?>"<?= $c['id'] === $cargaAtivaId ? ' selected' : '' ?>>
              #<?= h($c['id']) ?> · <?= h($c['dest']) ?> · <?= h($c['status']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="vbtn vbtn-primary" type="button" id="bipBtn" style="width:100%;margin-top:4px">
        Registrar bip
      </button>
      <div style="margin-top:16px">
        <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--crm-ink2);margin-bottom:5px">
          <span>Progresso da carga</span>
          <strong style="<?= $mono ?>;color:var(--crm-ink)"><span id="bipBarN"><?= $bipadas ?></span> de <span id="bipBarM"><?= $metaCx ?></span> caixas</strong>
        </div>
        <span class="crm-track"><span class="crm-fill" id="bipFill" style="width:<?= sprintf('%.1f', $bipadas / $metaCx * 100) ?>%"></span></span>
      </div>
    </div>
  </div>

  <!-- Últimas leituras -->
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Últimas leituras</span>
      <span class="crm-sub">novas leituras entram no topo</span>
    </div>
    <div class="crm-tblwrap">
      <table class="crm-tbl">
        <thead>
          <tr>
            <th>Hora</th><th>Código</th><th>Carga</th><th>Produto</th><th class="num">Peso</th>
          </tr>
        </thead>
        <tbody id="bipTbody">
          <?php foreach ($M['bipagens'] as $b): ?>
          <tr>
            <td style="<?= $mono ?>;font-size:12px"><?= h($b['hora']) ?></td>
            <td style="<?= $mono ?>;font-size:12px"><?= h($b['codigo']) ?></td>
            <td>#<?= h($b['carga']) ?></td>
            <td><strong><?= h($b['v']) ?></strong> <span class="sub"><?= h($b['cl']) ?></span></td>
            <td class="num"><?= crm_num((float)$b['peso'], 1) ?> kg</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>


<script>
/* Leitor simulado — estado 100% client-side (protótipo, nada persiste). */
document.addEventListener('DOMContentLoaded', function () {
  var CARGAS  = <?= jsvar($cargasJs) ?>;
  var bipadas = <?= $bipadas ?>;         /* leituras do mock */
  var seq     = 26;                      /* próximo EAN-13 fake: 78983574101 + NN */

  var elCod   = document.getElementById('bipCod');
  var elCarga = document.getElementById('bipCarga');
  var elBtn   = document.getElementById('bipBtn');
  var elTbody = document.getElementById('bipTbody');
  var elHoje  = document.getElementById('bipHoje');
  var elKpiN  = document.getElementById('bipKpiN');
  var elKpiM  = document.getElementById('bipKpiM');
  var elBarN  = document.getElementById('bipBarN');
  var elBarM  = document.getElementById('bipBarM');
  var elFill  = document.getElementById('bipFill');
  if (!elCod || !elCarga || !elBtn || !elTbody) return;

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function atualiza() {
    var carga = CARGAS[elCarga.value];
    var meta  = carga ? carga.meta : 1;
    elHoje.textContent = bipadas;
    elKpiN.textContent = bipadas;
    elBarN.textContent = bipadas;
    elKpiM.textContent = meta;
    elBarM.textContent = meta;
    elFill.style.width = Math.min(100, bipadas / meta * 100).toFixed(1) + '%';
  }

  function bipar() {
    var id    = elCarga.value;
    var carga = CARGAS[id];
    if (!carga) return;

    var cod = elCod.value.trim();
    if (cod === '') {
      cod = '78983574101' + String(seq).padStart(2, '0');
      seq += 1;
    }

    var agora = new Date();
    var hora  = String(agora.getHours()).padStart(2, '0') + ':'
              + String(agora.getMinutes()).padStart(2, '0');
    var prod  = carga.prods[Math.floor(Math.random() * carga.prods.length)];
    var peso  = (4 + Math.random() * 0.5).toFixed(1).replace('.', ',');

    var mono = "font-family:var(--num,'IBM Plex Mono');font-size:12px";
    elTbody.insertAdjacentHTML('afterbegin',
        '<tr>'
      + '<td style="' + mono + '">' + hora + '</td>'
      + '<td style="' + mono + '">' + esc(cod) + '</td>'
      + '<td>#' + esc(id) + '</td>'
      + '<td><strong>' + esc(prod[0]) + '</strong> <span class="sub">' + esc(prod[1]) + '</span></td>'
      + '<td class="num">' + peso + ' kg</td>'
      + '</tr>');

    bipadas += 1;
    atualiza();
    if (window.crmToast) window.crmToast('Caixa registrada na carga #' + id);
    elCod.value = '';
    elCod.focus();
  }

  elBtn.addEventListener('click', bipar);
  elCod.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') { ev.preventDefault(); bipar(); }
  });
  elCarga.addEventListener('change', atualiza);
});
</script>

<?php crm_shell_end();
