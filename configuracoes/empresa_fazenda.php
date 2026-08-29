<?php
/* ============================================================
   VERO — Configurações / Empresa e Fazenda  (tela real)
   Rota: /configuracoes/empresa_fazenda.php
   Guard: configuracoes.empresa_fazenda

   Cadastro COMPLETO do emitente (tenant) + a fazenda-sede:
     · Empresa   — nome + razão social
     · Fiscal    — CNPJ + Inscrição Estadual
     · Endereço  — logradouro / município / UF / CEP da empresa
     · Sede & Áreas — fazenda-sede, área total (ha) e área produtiva
     · Mapa      — localiza a sede (Leaflet vendorizado, sem CDN)

   O cadastro geral de fazendas/talhões continua em /fazendas/ — aqui
   tratamos só a EMPRESA e a SEDE, sem duplicar aquele CRUD.

   Schema-aware: os campos da empresa (razão social, CNPJ, IE, endereço,
   fazenda_sede_id) só são gravados nas colunas que JÁ existem em `tenants`.
   Hoje `tenants` só tem `nome`; os demais ficam prontos no formulário e
   passam a gravar sozinhos assim que a migration do A0 criar as colunas
   (ver relatório). A sede grava em `agro_fazendas` (colunas já existentes).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

/* Campos candidatos da empresa (coluna em `tenants` => valor). Só entram no
   UPDATE os que existem de fato — placeholders distintos evitam HY093. */
function ef_tenant_update(int $tenant, array $fields): int
{
    $set = [];
    $bind = [];
    foreach ($fields as $col => $val) {
        if (!vero_has_column('tenants', $col)) continue;
        $ph = ':v_' . $col;
        $set[] = "`{$col}` = {$ph}";
        $bind[$ph] = $val;
    }
    if (!$set) return 0;
    if (vero_has_column('tenants', 'updated_at')) $set[] = '`updated_at` = NOW()';
    $bind[':_id'] = $tenant;
    $sql = 'UPDATE tenants SET ' . implode(', ', $set) . ' WHERE id = :_id LIMIT 1';
    vero_pdo()->prepare($sql)->execute($bind);
    return count($set);
}

/** Valida os dígitos verificadores de um CNPJ (14 dígitos). */
function ef_cnpj_valido(string $digitos): bool
{
    if (strlen($digitos) !== 14 || preg_match('/^(\d)\1{13}$/', $digitos)) return false;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    /* ── Empresa / Fiscal / Endereço (grava em `tenants`) ─────────── */
    if ($acao === 'salvar_empresa') {
        vero_require('configuracoes.empresa_fazenda.editar');

        $nome = vero_str('nome', 150);
        if ($nome === null) {
            vero_flash('erro', 'O nome da empresa é obrigatório.');
            vero_redirect();
        }

        /* CNPJ: guarda só dígitos; valida DV quando informado */
        $cnpj = vero_str('cnpj', 18);
        if ($cnpj !== null) {
            $cnpj = preg_replace('/\D+/', '', $cnpj) ?: null;
            if ($cnpj !== null && !ef_cnpj_valido($cnpj)) {
                vero_flash('erro', 'CNPJ inválido — confira os 14 dígitos.');
                vero_redirect();
            }
        }

        $sedeId = vero_int('fazenda_sede_id');
        if ($sedeId) {
            /* a sede precisa ser uma fazenda do próprio tenant */
            $ok = vero_val("SELECT id FROM agro_fazendas WHERE id = :i AND tenant_id = :t",
                [':i' => $sedeId, ':t' => $t]);
            if (!$ok) $sedeId = null;
        }

        $gravadas = ef_tenant_update($t, [
            'nome'               => $nome,
            'razao_social'       => vero_str('razao_social', 200),
            'cnpj'               => $cnpj,
            'inscricao_estadual' => vero_str('inscricao_estadual', 20),
            'endereco'           => vero_str('endereco', 240),
            'cep'                => vero_str('cep', 9),
            'municipio'          => vero_str('municipio', 120),
            'uf'                 => vero_str('uf', 2),
            'fazenda_sede_id'    => $sedeId,
        ]);

        vero_flash('ok', $gravadas > 1
            ? 'Dados da empresa atualizados.'
            : 'Nome da empresa atualizado.');
        vero_redirect();
    }

    /* ── Sede & localização (grava em `agro_fazendas` — colunas já existem) ── */
    if ($acao === 'salvar_sede') {
        vero_require('configuracoes.empresa_fazenda.editar');

        $fid = vero_int('fazenda_sede_id') ?: vero_int('fazenda_id');
        $existe = $fid ? vero_val("SELECT id FROM agro_fazendas WHERE id = :i AND tenant_id = :t",
            [':i' => $fid, ':t' => $t]) : null;
        if (!$existe) {
            vero_flash('erro', 'Selecione a fazenda-sede.');
            vero_redirect();
        }

        $lat = vero_dec('latitude');
        $lng = vero_dec('longitude');
        if (($lat !== null && ($lat < -90 || $lat > 90)) ||
            ($lng !== null && ($lng < -180 || $lng > 180))) {
            vero_flash('erro', 'Coordenadas fora da faixa válida.');
            vero_redirect();
        }

        vero_update('agro_fazendas', (int)$fid, [
            'area_total_ha' => vero_dec('area_total_ha') ?? 0,
            'latitude'      => $lat,
            'longitude'     => $lng,
        ]);

        /* memoriza qual fazenda é a sede, se a coluna já existir */
        if (vero_has_column('tenants', 'fazenda_sede_id')) {
            ef_tenant_update($t, ['fazenda_sede_id' => (int)$fid]);
        }

        vero_flash('ok', 'Sede e localização atualizadas.');
        vero_redirect();
    }
}

$empresa  = vero_row("SELECT * FROM tenants WHERE id = :t", [':t' => $t]);
$usuarios = (int)vero_val("SELECT COUNT(*) FROM usuarios WHERE tenant_id = :t AND ativo = 1", [':t' => $t]);

$fazendas = vero_rows(
    "SELECT f.*,
            (SELECT COUNT(*) FROM agro_talhoes tl WHERE tl.tenant_id = f.tenant_id AND tl.fazenda_id = f.id AND tl.ativo = 1) AS talhoes,
            (SELECT COALESCE(SUM(tl2.area_ha),0) FROM agro_talhoes tl2 WHERE tl2.tenant_id = f.tenant_id AND tl2.fazenda_id = f.id AND tl2.ativo = 1) AS area
       FROM agro_fazendas f
      WHERE f.tenant_id = :t ORDER BY f.ativo DESC, f.nome", [':t' => $t]);

$ativas = array_values(array_filter($fazendas, static fn($f) => (int)$f['ativo'] === 1));

/* Qual fazenda é a sede: coluna dedicada (se existir) → 1ª com coordenada → 1ª ativa. */
$sedeId = vero_has_column('tenants', 'fazenda_sede_id') ? (int)($empresa['fazenda_sede_id'] ?? 0) : 0;
$sede = null;
foreach ($fazendas as $f) if ((int)$f['id'] === $sedeId) { $sede = $f; break; }
if (!$sede) {
    foreach ($ativas as $f) if ($f['latitude'] !== null && $f['longitude'] !== null) { $sede = $f; break; }
}
if (!$sede && $ativas) $sede = $ativas[0];

$sedeOpts = [];
foreach ($ativas as $f) $sedeOpts[(int)$f['id']] = (string)$f['nome'];

/* Colunas de empresa que ainda não existem — aviso discreto ao gestor. */
$campos = ['razao_social', 'cnpj', 'inscricao_estadual', 'endereco', 'cep', 'municipio', 'uf'];
$faltam = array_values(array_filter($campos, static fn($c) => !vero_has_column('tenants', $c)));
$fiscalPersiste = !$faltam; // se todas existem, o form já grava tudo

$GUARD      = ['macro' => 'configuracoes', 'micro' => 'empresa_fazenda'];
$PAGE_VIEW  = 'configuracoes_empresa_fazenda';
$PAGE_TITLE = 'Empresa e Fazenda';

/* Leaflet vendorizado (assets/vendor — NUNCA CDN), como em agro/mapa.php */
$EXTRA_HEAD = vero_assets()
    . '<link rel="stylesheet" href="' . BIOS_BASE . '/assets/vendor/leaflet/leaflet.css">'
    . '<script src="' . BIOS_BASE . '/assets/vendor/leaflet/leaflet.js"></script>';

require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$podeEditar = vero_can('configuracoes.empresa_fazenda.editar');
$dis = $podeEditar ? '' : ' disabled';

$sedeLat = $sede && $sede['latitude']  !== null ? (float)$sede['latitude']  : null;
$sedeLng = $sede && $sede['longitude'] !== null ? (float)$sede['longitude'] : null;
$sedeAreaTotal = $sede ? (float)$sede['area_total_ha'] : 0.0;
$sedeAreaProd  = $sede ? (float)$sede['area']          : 0.0;
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Empresa e Fazenda', 'Identificação do emitente (empresa) e da fazenda-sede', null) ?>

  <form class="vform" method="post" style="margin-bottom:14px">
    <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
    <input type="hidden" name="acao" value="salvar_empresa">

    <!-- EMPRESA -->
    <div class="vcard" style="margin-bottom:14px">
      <div class="vtoolbar"><strong>Empresa</strong>
        <span class="vsub">tenant #<?= (int)$empresa['id'] ?> · <?= $usuarios ?> usuário(s) ativo(s) ·
          criada em <?= date('d/m/Y', strtotime((string)$empresa['created_at'])) ?></span></div>
      <div class="vgrid" style="padding:14px">
        <div class="vfield">
          <label>Nome da empresa *</label>
          <input type="text" name="nome" value="<?= h((string)$empresa['nome']) ?>" maxlength="150" required<?= $dis ?>>
          <div class="vhint">Aparece no seletor do topo e nos relatórios.</div>
        </div>
        <div class="vfield">
          <label>Razão social</label>
          <input type="text" name="razao_social" value="<?= h((string)($empresa['razao_social'] ?? '')) ?>" maxlength="200"<?= $dis ?>>
          <div class="vhint">Nome jurídico completo, como no cartão CNPJ.</div>
        </div>
      </div>
    </div>

    <!-- FISCAL -->
    <div class="vcard" style="margin-bottom:14px">
      <div class="vtoolbar"><strong>Fiscal</strong>
        <span class="vsub">usado na emissão de NF-e</span></div>
      <div class="vgrid" style="padding:14px">
        <div class="vfield">
          <label>CNPJ</label>
          <input type="text" name="cnpj" value="<?= h((string)($empresa['cnpj'] ?? '')) ?>" maxlength="18" placeholder="00.000.000/0000-00"<?= $dis ?>>
          <div class="vhint">Só números — os 14 dígitos são conferidos ao salvar.</div>
        </div>
        <div class="vfield">
          <label>Inscrição estadual</label>
          <input type="text" name="inscricao_estadual" value="<?= h((string)($empresa['inscricao_estadual'] ?? '')) ?>" maxlength="20"<?= $dis ?>>
          <div class="vhint">Deixe em branco se a empresa for isenta.</div>
        </div>
      </div>
    </div>

    <!-- ENDEREÇO -->
    <div class="vcard" style="margin-bottom:14px">
      <div class="vtoolbar"><strong>Endereço da empresa</strong></div>
      <div class="vgrid" style="padding:14px">
        <div class="vfield full">
          <label>Logradouro</label>
          <input type="text" name="endereco" value="<?= h((string)($empresa['endereco'] ?? '')) ?>" maxlength="240" placeholder="Rua, número, bairro"<?= $dis ?>>
        </div>
        <div class="vfield">
          <label>Município</label>
          <input type="text" name="municipio" value="<?= h((string)($empresa['municipio'] ?? '')) ?>" maxlength="120"<?= $dis ?>>
        </div>
        <div class="vfield">
          <label>UF</label>
          <input type="text" name="uf" value="<?= h((string)($empresa['uf'] ?? '')) ?>" maxlength="2" placeholder="PE"<?= $dis ?>>
        </div>
        <div class="vfield">
          <label>CEP</label>
          <input type="text" name="cep" value="<?= h((string)($empresa['cep'] ?? '')) ?>" maxlength="9" placeholder="00000-000"<?= $dis ?>>
        </div>
      </div>

      <?php if (!$fiscalPersiste): ?>
        <div class="vhint" style="padding:0 14px 12px;color:#8a6d1a">
          Os campos de razão social, CNPJ, inscrição e endereço ficam prontos aqui, mas ainda não há
          coluna para gravá-los na tabela da empresa — passam a salvar automaticamente quando a
          migration correspondente for aplicada. Por ora, apenas o <strong>nome</strong> é gravado.
        </div>
      <?php endif; ?>

      <?php if ($podeEditar): ?>
        <div class="vform-actions" style="padding:0 14px 14px">
          <button class="vbtn vbtn-primary" type="submit">Salvar dados da empresa</button>
        </div>
      <?php endif; ?>
    </div>
  </form>

  <!-- SEDE & ÁREAS + MAPA -->
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Sede &amp; áreas</strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/fazendas/index.php">Gerenciar fazendas</a></div>

    <?php if (!$ativas): ?>
      <div class="vempty">Nenhuma fazenda ativa. Cadastre a propriedade em “Gerenciar fazendas” para definir a sede.</div>
    <?php else: ?>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar_sede">
      <input type="hidden" name="fazenda_id" id="sede-id" value="<?= (int)($sede['id'] ?? 0) ?>">

      <div class="vgrid" style="padding:14px">
        <div class="vfield">
          <label>Fazenda-sede</label>
          <select name="fazenda_sede_id" id="sel-sede" onchange="efTrocaSede(this.value)"<?= $dis ?>>
            <?php foreach ($sedeOpts as $k => $v): ?>
              <option value="<?= (int)$k ?>"<?= (int)$k === (int)($sede['id'] ?? 0) ? ' selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="vhint">Propriedade principal (matriz) da empresa.</div>
        </div>
        <div class="vfield">
          <label>Área total (ha)</label>
          <input type="text" name="area_total_ha" id="sede-area" value="<?= $sedeAreaTotal > 0 ? numFmt($sedeAreaTotal, 2) : '' ?>" inputmode="decimal"<?= $dis ?>>
          <div class="vhint">Área registrada da propriedade.</div>
        </div>
        <div class="vfield">
          <label>Área produtiva (ha)</label>
          <input type="text" value="<?= numFmt($sedeAreaProd, 2) ?>" disabled>
          <div class="vhint">Soma dos talhões ativos — calculada, não editável.</div>
        </div>
        <div class="vfield">
          <label>Latitude</label>
          <input type="text" name="latitude" id="sede-lat" value="<?= $sedeLat !== null ? h((string)$sedeLat) : '' ?>" inputmode="decimal" placeholder="-9.3900000"<?= $dis ?>>
        </div>
        <div class="vfield">
          <label>Longitude</label>
          <input type="text" name="longitude" id="sede-lng" value="<?= $sedeLng !== null ? h((string)$sedeLng) : '' ?>" inputmode="decimal" placeholder="-40.5000000"<?= $dis ?>>
          <div class="vhint">Arraste o pino no mapa ou clique para posicionar a sede.</div>
        </div>
      </div>

      <div id="mapa-sede" style="height:360px;margin:0 14px;border-radius:12px;overflow:hidden;border:1px solid #D5CEBF"></div>

      <?php if ($podeEditar): ?>
        <div class="vform-actions" style="padding:14px">
          <button class="vbtn vbtn-primary" type="submit">Salvar sede e localização</button>
        </div>
      <?php else: ?>
        <div class="vhint" style="padding:14px">Você não tem permissão para editar — apenas visualização.</div>
      <?php endif; ?>
    </form>
    <?php endif; ?>
  </div>

  <!-- RESUMO DAS FAZENDAS -->
  <div class="vcard">
    <div class="vtoolbar"><strong>Fazendas do grupo</strong>
      <span class="vsub"><?= count($fazendas) ?> cadastrada(s)</span></div>
    <?php if (!$fazendas): ?>
      <div class="vempty">Nenhuma fazenda cadastrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Fazenda</th><th>Município/UF</th>
        <th style="text-align:right">Talhões ativos</th>
        <th style="text-align:right">Área total (ha)</th>
        <th style="text-align:right">Área produtiva (ha)</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($fazendas as $f): $ehSede = (int)$f['id'] === (int)($sede['id'] ?? 0); ?>
        <tr>
          <td><strong><?= h((string)$f['nome']) ?></strong><?= $ehSede ? ' <span class="vbadge vb-ok">Sede</span>' : '' ?></td>
          <td><?= h(trim(((string)($f['municipio'] ?? '')) . (isset($f['uf']) && $f['uf'] ? '/' . $f['uf'] : ''), '/') ?: '—') ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$f['talhoes'] ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$f['area_total_ha'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$f['area'], 2) ?></td>
          <td><?= vero_b_ativo($f['ativo']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($ativas): ?>
<script>
/* Coordenadas de cada fazenda ativa p/ reposicionar o mapa ao trocar de sede. */
var EF_SEDES = <?= jsvar(array_map(static function ($f) {
    return [
        'id'   => (int)$f['id'],
        'nome' => (string)$f['nome'],
        'lat'  => $f['latitude']  !== null ? (float)$f['latitude']  : null,
        'lng'  => $f['longitude'] !== null ? (float)$f['longitude'] : null,
        'area' => (float)$f['area_total_ha'],
    ];
}, $ativas)) ?>;
var EF_PODE = <?= $podeEditar ? 'true' : 'false' ?>;

(function () {
  var CENTRO = [-9.39, -40.5]; // Vale do São Francisco (fallback)
  var map = L.map('mapa-sede').setView(CENTRO, 12);
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 19, attribution: 'Imagens © Esri World Imagery'
  }).addTo(map);

  var inLat = document.getElementById('sede-lat');
  var inLng = document.getElementById('sede-lng');
  var marker = null;

  function posInicial() {
    var la = parseFloat((inLat.value || '').replace(',', '.'));
    var ln = parseFloat((inLng.value || '').replace(',', '.'));
    return (isFinite(la) && isFinite(ln)) ? [la, ln] : null;
  }

  function setMarker(latlng, escreve) {
    if (!marker) {
      marker = L.marker(latlng, {draggable: EF_PODE}).addTo(map);
      if (EF_PODE) marker.on('dragend', function () {
        var p = marker.getLatLng(); grava(p.lat, p.lng);
      });
    } else {
      marker.setLatLng(latlng);
    }
    if (escreve) grava(latlng[0], latlng[1]);
  }

  function grava(la, ln) {
    inLat.value = la.toFixed(7);
    inLng.value = ln.toFixed(7);
  }

  var p0 = posInicial();
  if (p0) { setMarker(p0, false); map.setView(p0, 14); }

  if (EF_PODE) {
    map.on('click', function (e) { setMarker([e.latlng.lat, e.latlng.lng], true); });
  }

  function fromInputs() {
    var p = posInicial();
    if (p) { setMarker(p, false); map.setView(p, map.getZoom() < 12 ? 14 : map.getZoom()); }
  }
  inLat.addEventListener('change', fromInputs);
  inLng.addEventListener('change', fromInputs);

  /* Trocar a sede no select reposiciona mapa/área e sincroniza o fazenda_id. */
  window.efTrocaSede = function (id) {
    id = parseInt(id, 10);
    document.getElementById('sede-id').value = id || 0;
    var s = EF_SEDES.filter(function (x) { return x.id === id; })[0];
    if (!s) return;
    document.getElementById('sede-area').value = s.area > 0 ? s.area.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '';
    if (s.lat !== null && s.lng !== null) {
      grava(s.lat, s.lng); setMarker([s.lat, s.lng], false); map.setView([s.lat, s.lng], 14);
    } else {
      inLat.value = ''; inLng.value = '';
      if (marker) { map.removeLayer(marker); marker = null; }
      map.setView(CENTRO, 12);
    }
  };

  setTimeout(function () { map.invalidateSize(); }, 150);
  window.addEventListener('resize', function () { map.invalidateSize(); });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
