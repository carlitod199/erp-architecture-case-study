<?php
/* ============================================================
   VERO — Gestão Agrícola / Fazendas  (CRUD real)
   Substitui a tela mock. Rota da matriz: /fazendas/index.php
   Guard: agricola.fazendas | Escrita: agro.fazendas.editar/excluir
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'agro_fazendas';

/* Tipos de exploração aceitos (VARCHAR + whitelist — decisão da auditoria, DB-14) */
const TIPOS_EXPLORACAO = [
    'propria'   => 'Própria',
    'arrendada' => 'Arrendada',
    'parceria'  => 'Parceria',
    'comodato'  => 'Comodato',
];

/** Valida dígitos verificadores de CPF (11 díg.) ou CNPJ (14 díg.). */
function faz_cpf_cnpj_valido(string $digitos): bool
{
    $n = strlen($digitos);
    if ($n === 11) {
        if (preg_match('/^(\d)\1{10}$/', $digitos)) return false;
        for ($k = 9; $k <= 10; $k++) {
            $soma = 0;
            for ($i = 0; $i < $k; $i++) $soma += (int)$digitos[$i] * (($k + 1) - $i);
            $dv = (($soma * 10) % 11) % 10;
            if ($dv !== (int)$digitos[$k]) return false;
        }
        return true;
    }
    if ($n === 14) {
        if (preg_match('/^(\d)\1{13}$/', $digitos)) return false;
        foreach ([12, 13] as $k) {
            $pesos = $k === 12 ? [5,4,3,2,9,8,7,6,5,4,3,2] : [6,5,4,3,2,9,8,7,6,5,4,3,2];
            $soma = 0;
            foreach ($pesos as $i => $p) $soma += (int)$digitos[$i] * $p;
            $resto = $soma % 11;
            $dv = $resto < 2 ? 0 : 11 - $resto;
            if ($dv !== (int)$digitos[$k]) return false;
        }
        return true;
    }
    return false;
}

/* ── POST (antes de qualquer HTML — padrão PRG) ─────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.fazendas.editar');

        $id   = vero_int('id');
        $nome = vero_str('nome', 150);
        if ($nome === null) {
            vero_flash('erro', 'Informe o nome da fazenda.');
            vero_redirect();
        }

        /* duplicidade amigável (nome por tenant, só entre ativas) */
        $dup = vero_val(
            "SELECT id FROM " . T . " WHERE tenant_id=:t AND nome=:n AND ativo=1 AND id<>:id",
            [':t' => vero_tenant(), ':n' => $nome, ':id' => (int)$id]
        );
        if ($dup) {
            vero_flash('erro', "Já existe uma fazenda ativa chamada \"{$nome}\".");
            vero_redirect();
        }

        /* CPF/CNPJ: guarda só dígitos; valida DV quando informado */
        $cnpjCpf = vero_str('cnpj_cpf', 18);
        if ($cnpjCpf !== null) {
            $cnpjCpf = preg_replace('/\D+/', '', $cnpjCpf) ?: null;
            if ($cnpjCpf !== null && !faz_cpf_cnpj_valido($cnpjCpf)) {
                vero_flash('erro', 'CPF/CNPJ do proprietário inválido (confira os dígitos).');
                vero_redirect();
            }
        }

        /* D-02 (QA 28/07): Inscrição Estadual ÚNICA por tenant. Antes a mesma
           IE podia repetir em fazendas de titulares/UF distintos (ex.:
           062.123.456.0012 em MG e PE). Compara ignorando a máscara (só
           dígitos). Validação NA APLICAÇÃO: pode haver duplicata legada, então
           NÃO se cria índice único no banco. Unicidade por tenant (não por UF):
           a mesma IE em UFs diferentes é justamente o caso a barrar. */
        $inscricao = vero_str('inscricao', 40);
        if ($inscricao !== null) {
            $ieDigitos = preg_replace('/\D+/', '', $inscricao);
            if ($ieDigitos !== '') {
                $dupIe = vero_row(
                    "SELECT id, nome, uf FROM " . T . "
                      WHERE tenant_id=:t AND ativo=1 AND id<>:id
                        AND REPLACE(REPLACE(REPLACE(REPLACE(inscricao,'.',''),'-',''),'/',''),' ','') = :ie",
                    [':t' => vero_tenant(), ':id' => (int)$id, ':ie' => $ieDigitos]
                );
                if ($dupIe) {
                    $ondeUf = $dupIe['uf'] ? '/' . $dupIe['uf'] : '';
                    vero_flash('erro', "Inscrição estadual \"{$inscricao}\" já está cadastrada na fazenda \"{$dupIe['nome']}{$ondeUf}\". A IE deve ser única.");
                    vero_redirect();
                }
            }
        }

        $tipoExp = (string)($_POST['tipo_exploracao'] ?? '');
        $tipoExp = array_key_exists($tipoExp, TIPOS_EXPLORACAO) ? $tipoExp : null;

        /* responsável precisa ser do tenant */
        $respId = vero_int('responsavel_id');
        if ($respId) {
            $okResp = vero_val("SELECT id FROM agro_operadores WHERE id=:i AND tenant_id=:t",
                [':i' => $respId, ':t' => vero_tenant()]);
            if (!$okResp) $respId = null;
        }

        $data = [
            'nome'            => $nome,
            'proprietario'    => vero_str('proprietario', 150),
            'cnpj_cpf'        => $cnpjCpf,
            'matricula'       => vero_str('matricula', 60),
            'car'             => vero_str('car', 60),
            'ccir'            => vero_str('ccir', 60),
            'inscricao'       => $inscricao,
            'tipo_exploracao' => $tipoExp,
            'responsavel_id'  => $respId,
            'municipio'       => vero_str('municipio', 120),
            'uf'              => vero_str('uf', 2),
            'area_total_ha'   => vero_dec('area_total_ha') ?? 0,
            'latitude'        => vero_dec('latitude'),
            'longitude'       => vero_dec('longitude'),
            'observacao'      => vero_str('observacao', 500),
            'ativo'           => vero_int('ativo') ?? 1,
        ];

        /* D-01 (QA 28/07): a Área Produtiva (= soma dos talhões ativos) não pode
           exceder a Área Total. Antes o sistema só mostrava um ícone ⚠ na
           listagem e mantinha o registro válido — o valor errado propagava para
           talhão e safra. Agora é BLOQUEANTE: ao salvar a fazenda, se a soma dos
           talhões ativos ultrapassar a área total informada, IMPEDE o salvamento.
           (Só se aplica na edição — fazenda nova ainda não tem talhões.) */
        $areaTotal = (float)$data['area_total_ha'];
        if ($id && $areaTotal > 0) {
            $somaTalhoes = (float)vero_val(
                "SELECT COALESCE(SUM(area_ha),0) FROM agro_talhoes
                  WHERE tenant_id=:t AND fazenda_id=:f AND ativo=1",
                [':t' => vero_tenant(), ':f' => (int)$id]
            );
            if ($somaTalhoes > $areaTotal + 0.0001) {
                vero_flash('erro', sprintf(
                    'Área total (%s ha) não pode ser menor que a soma dos talhões ativos (%s ha). Ajuste a área total ou os talhões antes de salvar.',
                    numFmt($areaTotal, 2), numFmt($somaTalhoes, 2)
                ));
                vero_redirect();
            }
        }

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', "Fazenda \"{$nome}\" atualizada.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Fazenda \"{$nome}\" cadastrada.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('agro.fazendas.excluir');
        $id = vero_int('id');
        if ($id) {
            $temTalhao = (int)vero_val(
                "SELECT COUNT(*) FROM agro_talhoes WHERE tenant_id=:t AND fazenda_id=:f AND ativo=1",
                [':t' => vero_tenant(), ':f' => $id]
            );
            if ($temTalhao > 0) {
                vero_flash('aviso', "A fazenda possui {$temTalhao} talhão(ões) ativo(s); ela foi apenas inativada.");
            }
            vero_delete(T, $id); // soft delete (tabela tem `ativo`)
        }
        vero_redirect();
    }
}

/* ── Consulta da listagem ───────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 15;

$where  = "f.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    /* QA-011: placeholder repetido quebra com prepares nativos (HY093) — :q1..:qN */
    $where .= " AND (f.nome LIKE :q1 OR f.municipio LIKE :q2)";
    $params[':q1'] = $params[':q2'] = "%{$q}%";
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " f WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT f.*, op.nome AS responsavel_nome,
            (SELECT COUNT(*) FROM agro_talhoes tl
              WHERE tl.tenant_id = f.tenant_id AND tl.fazenda_id = f.id AND tl.ativo = 1) AS talhoes,
            (SELECT COALESCE(SUM(tl2.area_ha),0) FROM agro_talhoes tl2
              WHERE tl2.tenant_id = f.tenant_id AND tl2.fazenda_id = f.id AND tl2.ativo = 1) AS area_produtiva
       FROM " . T . " f
       LEFT JOIN agro_operadores op ON op.id = f.responsavel_id
      WHERE {$where}
      ORDER BY f.ativo DESC, f.nome
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$operadores = vero_options('agro_operadores', 'nome');

/* Registro em edição (?editar=ID) */
$edit = null;
$editSomaTalhoes = 0.0; // D-01: soma dos talhões ativos da fazenda em edição (validação client-side)
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        $editSomaTalhoes = (float)vero_val(
            "SELECT COALESCE(SUM(area_ha),0) FROM agro_talhoes
              WHERE tenant_id=:t AND fazenda_id=:f AND ativo=1",
            [':t' => vero_tenant(), ':f' => (int)$edit['id']]
        );
    }
}

/* ── Render ─────────────────────────────────────────────────── */
$GUARD      = ['macro' => 'agricola', 'micro' => 'fazendas'];
$PAGE_VIEW  = 'fazendas';
$PAGE_TITLE = 'Fazendas';
$EXTRA_HEAD = vero_assets()
    . '<link rel="stylesheet" href="' . BIOS_BASE . '/assets/vendor/leaflet/leaflet.css">'
    . '<script src="' . BIOS_BASE . '/assets/vendor/leaflet/leaflet.js"></script>';
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.fazendas.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Fazendas', 'Propriedades do grupo — base de talhões, válvulas e safras',
        $podeEditar ? '+ Nova fazenda' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nome ou município…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma fazenda encontrada. <?= $podeEditar ? 'Clique em “+ Nova fazenda” para começar.' : '' ?></div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Fazenda</th><th>Município/UF</th>
        <th style="text-align:right">Área total (ha)</th>
        <th style="text-align:right">Área produtiva (ha)</th>
        <th style="text-align:right">Talhões ativos</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $areaProd  = (float)$r['area_produtiva'];
          $areaTotal = (float)$r['area_total_ha'];
          $excedeu   = $areaTotal > 0 && $areaProd > $areaTotal; ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong>
              <?php
                $sub = [];
                if ($r['proprietario'])                                $sub[] = h((string)$r['proprietario']);
                if ($r['tipo_exploracao'] && isset(TIPOS_EXPLORACAO[$r['tipo_exploracao']]))
                                                                       $sub[] = TIPOS_EXPLORACAO[$r['tipo_exploracao']];
                if ($r['responsavel_nome'])                            $sub[] = 'Resp.: ' . h((string)$r['responsavel_nome']);
                if ($r['inscricao'])                                   $sub[] = 'Inscrição: ' . h((string)$r['inscricao']);
              ?>
              <?= $sub ? '<div class="vhint">' . implode(' · ', $sub) . '</div>' : '' ?></td>
          <td><?php /* A1-54 (UX-03): UF só acompanha município — nada de "/PE" solto */
            echo $r['municipio'] ? h($r['municipio'] . ($r['uf'] ? '/' . $r['uf'] : '')) : ($r['uf'] ? h((string)$r['uf']) : '—'); ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($areaTotal, 2) ?></td>
          <td class="vnum" style="text-align:right;<?= $excedeu ? 'color:#b3261e' : '' ?>"
              <?= $excedeu ? 'title="Soma dos talhões ativos excede a área total declarada"' : '' ?>>
            <?= numFmt($areaProd, 2) ?><?= $excedeu ? ' ⚠' : '' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['talhoes'] ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('agro.fazendas.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar esta fazenda?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<!-- Modal Nova/Editar -->
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar fazenda' : 'Nova fazenda' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post" id="faz-form" data-soma-talhoes="<?= $edit ? h(number_format($editSomaTalhoes, 4, '.', '')) : '0' ?>">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('nome', 'Nome da fazenda', $edit['nome'] ?? '', true) ?></div>
        <?= vero_f_text('proprietario', 'Proprietário', $edit['proprietario'] ?? '', false, 'Nome ou razão social') ?>
        <?= vero_f_text('cnpj_cpf', 'CPF/CNPJ do proprietário', $edit['cnpj_cpf'] ?? '', false, 'Só números — validado ao salvar') ?>
        <?= vero_f_select('tipo_exploracao', 'Tipo de exploração', TIPOS_EXPLORACAO, $edit['tipo_exploracao'] ?? null, false, '— Não informado —') ?>
        <?= vero_f_select('responsavel_id', 'Responsável', $operadores, $edit['responsavel_id'] ?? null, false, '— Não informado —') ?>
        <?= vero_f_text('matricula', 'Matrícula do imóvel', $edit['matricula'] ?? '', false, 'Nº da matrícula no cartório') ?>
        <?= vero_f_text('car', 'CAR', $edit['car'] ?? '', false, 'Cadastro Ambiental Rural') ?>
        <?= vero_f_text('ccir', 'CCIR / INCRA', $edit['ccir'] ?? '') ?>
        <?= vero_f_text('inscricao', 'Inscrição estadual', $edit['inscricao'] ?? '') ?>
        <?= vero_f_text('area_total_ha', 'Área total (ha)', $edit ? numFmt((float)$edit['area_total_ha'], 2) : '') ?>
        <?= vero_f_text('municipio', 'Município', $edit['municipio'] ?? '') ?>
        <?= vero_f_text('uf', 'UF', $edit['uf'] ?? '', false, 'Sigla com 2 letras, ex.: PE') ?>
        <div class="full">
          <label style="display:block;font-size:12px;font-weight:600;color:#4A4034;margin-bottom:6px">
            Localização no mapa <span class="vhint" style="font-weight:400">— busque o endereço ou clique no mapa para marcar (opcional)</span>
          </label>
          <input type="hidden" name="latitude"  id="faz-lat" value="<?= h((string)($edit['latitude'] ?? '')) ?>">
          <input type="hidden" name="longitude" id="faz-lng" value="<?= h((string)($edit['longitude'] ?? '')) ?>">
          <div style="display:flex;gap:6px;margin-bottom:8px">
            <input type="text" id="faz-busca" placeholder="Buscar endereço, cidade ou local…" style="flex:1" autocomplete="off"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();fazBuscar();}">
            <button type="button" class="vbtn vbtn-ghost vbtn-sm" onclick="fazBuscar()">Buscar</button>
            <button type="button" class="vbtn vbtn-ghost vbtn-sm" onclick="fazMinhaLoc()" title="Usar minha localização atual">📍 Aqui</button>
          </div>
          <div id="faz-map" style="height:320px;border-radius:12px;overflow:hidden;border:1px solid #D5CEBF"></div>
          <div class="vhint" id="faz-coord" style="margin-top:6px">
            <?= ($edit['latitude'] ?? '') !== '' && ($edit['latitude'] ?? null) !== null
                ? 'Marcado em ' . h((string)$edit['latitude']) . ', ' . h((string)$edit['longitude']) . ' — arraste o pino para ajustar.'
                : 'Nenhum ponto marcado — a fazenda funciona sem localização, mas o mapa e o clima ficam mais precisos com ela.' ?>
          </div>
        </div>
        <div class="full"><?= vero_f_text('observacao', 'Observações', $edit['observacao'] ?? '') ?></div>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vhint" style="margin-top:8px">A área produtiva é calculada pela soma dos talhões ativos — não é digitada aqui.</div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>

<script>
/* D-01 (QA 28/07): reforço client-side — barra o envio quando a Área total
   informada fica abaixo da soma dos talhões ativos (o servidor também bloqueia).
   Aceita número pt-BR ("5,50" / "1.234,56") espelhando vero_dec(). */
(function () {
  var form = document.getElementById('faz-form');
  if (!form) return;
  function parseNum(s) {
    s = (s || '').trim(); if (s === '') return 0;
    s = s.replace(/\s/g, '');
    if (s.indexOf(',') > -1) { s = s.replace(/\./g, '').replace(',', '.'); }
    else if (/^\d{1,3}(\.\d{3})+$/.test(s)) { s = s.replace(/\./g, ''); }
    var n = parseFloat(s); return isNaN(n) ? 0 : n;
  }
  form.addEventListener('submit', function (e) {
    var soma = parseFloat(form.getAttribute('data-soma-talhoes')) || 0;
    var campo = form.querySelector('[name="area_total_ha"]');
    if (!campo || soma <= 0) return;
    var total = parseNum(campo.value);
    if (total > 0 && total + 0.0001 < soma) {
      e.preventDefault();
      alert('A área total (' + total.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2})
        + ' ha) não pode ser menor que a soma dos talhões ativos ('
        + soma.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ha).');
      campo.focus();
    }
  });
})();
</script>

<script>
/* Seletor de localização da fazenda (25/07): substitui os campos lat/long por
   um mapa de satélite — busca por endereço (Nominatim), clique/arraste do pino
   ou "minha localização" gravam nos campos ocultos latitude/longitude. */
(function () {
  var mapEl = document.getElementById('faz-map');
  if (!mapEl || typeof L === 'undefined') return;
  var latI = document.getElementById('faz-lat'), lngI = document.getElementById('faz-lng'), coord = document.getElementById('faz-coord');
  var temPonto = latI.value !== '' && lngI.value !== '';
  var start = temPonto ? [parseFloat(latI.value), parseFloat(lngI.value)] : [-9.39, -40.5];
  var map = L.map(mapEl).setView(start, temPonto ? 15 : 6);
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    {maxZoom: 19, attribution: 'Imagens © Esri'}).addTo(map);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, opacity: .3, attribution: '© OpenStreetMap'}).addTo(map);

  var marker = null;
  function apply(lat, lng) {
    latI.value = (+lat).toFixed(6);
    lngI.value = (+lng).toFixed(6);
    coord.textContent = 'Marcado em ' + latI.value + ', ' + lngI.value + ' — arraste o pino para ajustar.';
  }
  function setMarker(lat, lng, fly) {
    if (marker) { marker.setLatLng([lat, lng]); }
    else {
      marker = L.marker([lat, lng], {draggable: true}).addTo(map);
      marker.on('dragend', function () { var p = marker.getLatLng(); apply(p.lat, p.lng); });
    }
    apply(lat, lng);
    if (fly) map.setView([lat, lng], Math.max(map.getZoom(), 14));
  }
  if (temPonto) setMarker(parseFloat(latI.value), parseFloat(lngI.value), false);
  map.on('click', function (e) { setMarker(e.latlng.lat, e.latlng.lng, false); });

  window.fazBuscar = function () {
    var q = (document.getElementById('faz-busca').value || '').trim();
    if (!q) return;
    coord.textContent = 'Buscando…';
    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d[0]) setMarker(parseFloat(d[0].lat), parseFloat(d[0].lon), true);
        else coord.textContent = 'Endereço não encontrado — tente outro termo ou clique no mapa.';
      })
      .catch(function () { coord.textContent = 'Busca indisponível (sem internet) — clique no mapa para marcar.'; });
  };
  window.fazMinhaLoc = function () {
    if (!navigator.geolocation) { coord.textContent = 'Geolocalização não suportada neste navegador.'; return; }
    coord.textContent = 'Obtendo sua localização…';
    navigator.geolocation.getCurrentPosition(
      function (p) { setMarker(p.coords.latitude, p.coords.longitude, true); },
      function () { coord.textContent = 'Não foi possível obter a localização (permissão negada). Clique no mapa para marcar.'; });
  };

  /* o mapa vive num modal que abre depois — remede o tamanho quando ele aparece */
  function fix() { setTimeout(function () { map.invalidateSize(); if (marker) map.panTo(marker.getLatLng()); }, 80); }
  var modal = document.getElementById('vm-form');
  if (modal) {
    new MutationObserver(function () { if (modal.classList.contains('open')) fix(); }).observe(modal, {attributes: true, attributeFilter: ['class']});
    if (modal.classList.contains('open')) fix();
  }
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
