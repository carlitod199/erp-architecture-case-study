<?php
/* ============================================================
   VERO — Gestão Agrícola / Talhões  (CRUD real)
   Substitui a tela mock. Rota da matriz: /agro/talhoes.php
   Guard: agricola.talhoes | Escrita: agro.talhoes.editar/excluir
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_setor_espelho.php'; /* A1-35: válvula-espelho 1:1 (arbitragem A1-32) */

const T = 'agro_talhoes';

/* A1-34 (P-28): tipo de solo é CATÁLOGO FECHADO mantido pelo RT — parâmetro
   por tenant; default = classificação textural usual. Nada de texto livre. */
const TIPOS_SOLO_DEFAULT = 'Arenoso|Franco-arenoso|Franco|Franco-argiloso|Argiloso|Muito argiloso|Siltoso';
function talhoes_catalogo_solo(): array
{
    $lista = array_filter(array_map('trim',
        explode('|', vero_srv_param('agro.tipos_solo', TIPOS_SOLO_DEFAULT))));
    return array_slice(array_values(array_unique($lista)), 0, 30);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.talhoes.editar');

        $id        = vero_int('id');
        $fazendaId = vero_int('fazenda_id');
        $codigo    = vero_str('codigo', 20);

        if (!$fazendaId || $codigo === null) {
            vero_flash('erro', 'Fazenda e código do talhão são obrigatórios.');
            vero_redirect();
        }
        /* fazenda precisa pertencer ao tenant */
        $okFaz = vero_val("SELECT id FROM agro_fazendas WHERE id=:f AND tenant_id=:t",
            [':f' => $fazendaId, ':t' => vero_tenant()]);
        if (!$okFaz) {
            vero_flash('erro', 'Fazenda inválida.');
            vero_redirect();
        }
        /* código único por fazenda (entre ativos) */
        $dup = vero_val(
            "SELECT id FROM " . T . "
              WHERE tenant_id=:t AND fazenda_id=:f AND codigo=:c AND ativo=1 AND id<>:id",
            [':t' => vero_tenant(), ':f' => $fazendaId, ':c' => $codigo, ':id' => (int)$id]
        );
        if ($dup) {
            vero_flash('erro', "Já existe o talhão \"{$codigo}\" nesta fazenda.");
            vero_redirect();
        }

        /* área produtiva: precisa ser da mesma fazenda do talhão */
        $areaId = vero_int('area_id');
        if ($areaId) {
            $okArea = vero_val("SELECT id FROM agro_areas WHERE id=:a AND tenant_id=:t AND fazenda_id=:f",
                [':a' => $areaId, ':t' => vero_tenant(), ':f' => $fazendaId]);
            if (!$okArea) {
                vero_flash('erro', 'A área produtiva selecionada não pertence à fazenda escolhida.');
                vero_redirect();
            }
        }
        /* variedade do tenant (opcional) */
        $variedadeId = vero_int('variedade_id');
        if ($variedadeId) {
            $okVar = vero_val("SELECT id FROM agro_variedades WHERE id=:v AND tenant_id=:t",
                [':v' => $variedadeId, ':t' => vero_tenant()]);
            if (!$okVar) $variedadeId = null;
        }
        /* porta-enxerto do tenant (opcional) — mig. 155 */
        $portaEnxertoId = vero_int('porta_enxerto_id');
        if ($portaEnxertoId) {
            $okPe = vero_val("SELECT id FROM agro_porta_enxertos WHERE id=:p AND tenant_id=:t",
                [':p' => $portaEnxertoId, ':t' => vero_tenant()]);
            if (!$okPe) $portaEnxertoId = null;
        }

        /* P-28: só valores do catálogo (o valor JÁ GRAVADO no registro é
           preservado se o catálogo mudou depois — sem perder histórico) */
        $tipoSolo = vero_str('tipo_solo', 60);
        if ($tipoSolo !== null && !in_array($tipoSolo, talhoes_catalogo_solo(), true)) {
            $tipoAtual = $id ? vero_val("SELECT tipo_solo FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => vero_tenant()]) : null;
            if ($tipoSolo !== $tipoAtual) {
                vero_flash('erro', "Tipo de solo \"{$tipoSolo}\" fora do catálogo do RT — ajuste o catálogo no rodapé da tela ou escolha um valor da lista.");
                vero_redirect();
            }
        }

        /* A11: área e espaçamentos são grandezas físicas >= 0 (lat/long PODEM ser negativas) */
        $areaHa   = vero_dec('area_ha') ?? 0;
        $espLinha = vero_dec('espacamento_linha_m');
        $espPlanta= vero_dec('espacamento_planta_m');
        if ($areaHa < 0 || ($espLinha !== null && $espLinha < 0) || ($espPlanta !== null && $espPlanta < 0)) {
            vero_flash('erro', 'Área e espaçamentos não podem ser negativos.');
            vero_redirect();
        }

        /* D-01 (QA 28/07): a soma das áreas dos talhões ativos não pode exceder a
           área total da fazenda — este é o ponto onde a inconsistência entrava
           (a fazenda mostrava só um ⚠). BLOQUEIA aqui também, considerando este
           talhão (novo ou editado) e ignorando a versão antiga dele na soma. */
        $areaTotalFaz = (float)vero_val(
            "SELECT area_total_ha FROM agro_fazendas WHERE id=:f AND tenant_id=:t",
            [':f' => $fazendaId, ':t' => vero_tenant()]
        );
        if ($areaTotalFaz > 0) {
            $somaOutros = (float)vero_val(
                "SELECT COALESCE(SUM(area_ha),0) FROM " . T . "
                  WHERE tenant_id=:t AND fazenda_id=:f AND ativo=1 AND id<>:id",
                [':t' => vero_tenant(), ':f' => $fazendaId, ':id' => (int)$id]
            );
            $ativo = vero_int('ativo') ?? 1;
            $somaNova = $somaOutros + ($ativo === 1 ? $areaHa : 0.0);
            if ($somaNova > $areaTotalFaz + 0.0001) {
                vero_flash('erro', sprintf(
                    'A soma dos talhões ativos (%s ha) passaria a exceder a área total da fazenda (%s ha). Reduza a área do talhão ou ajuste a área total da fazenda.',
                    numFmt($somaNova, 2), numFmt($areaTotalFaz, 2)
                ));
                vero_redirect();
            }
        }

        $data = [
            'fazenda_id'           => $fazendaId,
            'area_id'              => $areaId ?: null,
            'codigo'               => $codigo,
            'nome'                 => vero_str('nome', 120),
            'area_ha'              => $areaHa,
            'tipo_solo'            => $tipoSolo,
            'data_plantio'         => vero_date('data_plantio'),
            'espacamento_linha_m'  => $espLinha,
            'espacamento_planta_m' => $espPlanta,
            'num_plantas'          => vero_int('num_plantas'),
            'num_filas'            => vero_int('num_filas'),
            'variedade_id'         => $variedadeId,
            'porta_enxerto_id'     => $portaEnxertoId,
            'observacao'           => vero_str('observacao', 500),
            'latitude'             => vero_dec('latitude'),
            'longitude'            => vero_dec('longitude'),
            'ativo'                => vero_int('ativo') ?? 1,
        ];
        /* geometria (JSON) fica para a tela de Mapa — aqui não é tocada. */

        if ($id) {
            vero_update(T, $id, $data);
            $flash = ($rotulo = vero_a1_rotulo_area()) . " \"{$codigo}\" atualizado(a).";
        } else {
            $id = vero_insert(T, $data);
            $flash = vero_a1_rotulo_area() . " \"{$codigo}\" cadastrado(a).";
        }
        /* A1-35 (arbitragem A1-32): no modo unificado o cadastro do talhão
           garante e sincroniza a válvula-espelho — o usuário cadastra UMA vez */
        if (vero_a1_valvula_unificada()) {
            $row = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => (int)$id, ':t' => vero_tenant()]);
            if ($row) {
                $espelhoId = vero_a1_sync_espelho($row);
                /* A1-49 (DB-53): tipo de irrigação é atributo da VÁLVULA — no
                   modo unificado o cadastro único grava no espelho */
                $tipoIrr = vero_str('tipo_irrigacao', 20);
                if ($tipoIrr !== null && in_array($tipoIrr, ['gotejo', 'microaspersao', 'pivo', 'outro'], true)) {
                    vero_update('agro_setores', $espelhoId, ['tipo_irrigacao' => $tipoIrr]);
                } elseif ($tipoIrr === null) {
                    vero_update('agro_setores', $espelhoId, ['tipo_irrigacao' => null]);
                }
            }
        }
        vero_flash('ok', $flash);
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('agro.talhoes.excluir');
        $id = vero_int('id');
        if ($id) {
            vero_delete(T, $id); // soft delete
            /* espelho acompanha a inativação (modo unificado) */
            if (vero_a1_valvula_unificada()) {
                $row = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
                    [':i' => (int)$id, ':t' => vero_tenant()]);
                if ($row) vero_a1_sync_espelho($row);
            }
        }
        vero_redirect();
    }

    /* A1-34 (P-28): manutenção do catálogo de tipos de solo pelo RT */
    if ($acao === 'catalogo_solo') {
        vero_require('agro.talhoes.editar');
        $lista = array_filter(array_map(static fn($x) => trim(mb_substr($x, 0, 60)),
            explode('|', (string)($_POST['catalogo'] ?? ''))));
        $lista = array_slice(array_values(array_unique($lista)), 0, 30);
        if (!$lista) {
            vero_flash('erro', 'O catálogo precisa de pelo menos 1 tipo de solo.');
            vero_redirect();
        }
        vero_srv_param_set('agro.tipos_solo', implode('|', $lista));
        vero_flash('ok', 'Catálogo de tipos de solo atualizado (' . count($lista) . ' tipo(s)) — registro do RT.');
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q        = trim((string)($_GET['q'] ?? ''));
$fFazenda = (int)($_GET['fazenda'] ?? 0);
$page     = max(1, (int)($_GET['pg'] ?? 1));
$perPage  = 15;

/* P-15: oculta válvulas INATIVAS por padrão; quem edita pode ver
   com ?inativos=1. Banco mantém o soft-delete — só a interface esconde. */
$verInativos = !empty($_GET['inativos']) && vero_can('agro.talhoes.editar');

$where  = "t.tenant_id = :t";
$params = [':t' => vero_tenant()];
if (!$verInativos) {
    $where .= " AND t.ativo = 1";
}
if ($q !== '') {
    /* QA-011: placeholder repetido quebra com prepares nativos (HY093) — :q1..:qN */
    $where .= " AND (t.codigo LIKE :q1 OR t.nome LIKE :q2)";
    $params[':q1'] = $params[':q2'] = "%{$q}%";
}
if ($fFazenda > 0) {
    $where .= " AND t.fazenda_id = :f";
    $params[':f'] = $fFazenda;
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " t WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT t.*, f.nome AS fazenda_nome, v.nome AS variedade_nome,
            (SELECT COUNT(*) FROM agro_setores s
              WHERE s.tenant_id = t.tenant_id AND s.talhao_id = t.id) AS valvulas
       FROM " . T . " t
       JOIN agro_fazendas f ON f.id = t.fazenda_id
       LEFT JOIN agro_variedades v ON v.id = t.variedade_id
      WHERE {$where}
      ORDER BY t.ativo DESC, f.nome, t.codigo
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$fazendas = vero_options('agro_fazendas', 'nome', 'ativo = 1');

/* áreas produtivas (rotuladas com a fazenda) e variedades (com a cultura) */
$areasOpt = [];
foreach (vero_rows(
    "SELECT a.id, CONCAT(f.nome, ' — ', a.nome) AS label
       FROM agro_areas a JOIN agro_fazendas f ON f.id = a.fazenda_id
      WHERE a.tenant_id = :t ORDER BY f.nome, a.nome",
    [':t' => vero_tenant()]
) as $ar) { $areasOpt[(int)$ar['id']] = (string)$ar['label']; }

$variedadesOpt = [];
foreach (vero_rows(
    "SELECT v.id, CONCAT(c.nome, ' — ', v.nome) AS label
       FROM agro_variedades v JOIN agro_culturas c ON c.id = v.cultura_id
      WHERE v.tenant_id = :t AND v.ativo = 1 ORDER BY c.nome, v.nome",
    [':t' => vero_tenant()]
) as $vr) { $variedadesOpt[(int)$vr['id']] = (string)$vr['label']; }

$portaEnxertosOpt = vero_options('agro_porta_enxertos', 'nome', 'ativo = 1');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'agricola', 'micro' => 'talhoes'];
$PAGE_VIEW  = 'agricola_talhoes';
$PAGE_TITLE = vero_a1_rotulo_area(true);
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.talhoes.editar');
$unificado  = vero_a1_valvula_unificada();
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header(vero_a1_rotulo_area(true),
        $unificado
          ? 'Cadastro ÚNICO da unidade produtiva (válvula = talhão nesta fazenda) — a válvula-espelho é criada e sincronizada automaticamente'
          : 'Áreas produtivas por fazenda — base para válvulas, safras e apontamentos',
        $podeEditar ? '+ Nova ' . mb_strtolower(vero_a1_rotulo_area()) : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="fazenda" aria-label="Filtrar por fazenda" onchange="this.form.submit()">
          <option value="">Todas as fazendas</option>
          <?php foreach ($fazendas as $fid => $fn): ?>
            <option value="<?= $fid ?>"<?= $fFazenda === $fid ? ' selected' : '' ?>><?= h($fn) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por código ou nome…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
        <?php if (!empty($fFazenda) || $q !== ''): ?><a class="vbtn vbtn-ghost" href="?" title="Limpar filtros">Limpar</a><?php endif; ?>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">
        Nenhum talhão encontrado.
        <?php if (!$fazendas): ?><br>Cadastre primeiro uma <a href="<?= BIOS_BASE ?>/fazendas/index">fazenda</a>.<?php endif; ?>
      </div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Código</th><th>Nome</th><th>Fazenda</th><th>Variedade</th>
        <th style="text-align:right">Área (ha)</th>
        <th style="text-align:right">Válvulas</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong class="vnum"><?= h($r['codigo']) ?></strong></td>
          <td><?= h($r['nome'] ?? '') ?: '—' ?></td>
          <td><?= h($r['fazenda_nome']) ?></td>
          <td><?= h($r['variedade_nome'] ?? '') ?: '<span class="vhint">—</span>' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['area_ha'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['valvulas'] ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?= vero_btn_icone(vero_ico_olho(), 'Ficha', '', BIOS_BASE . '/agro/talhao_ficha?id=' . (int)$r['id']) ?>
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('agro.talhoes.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este talhão?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>

  <?php if ($podeEditar): /* A1-34 (P-28): catálogo fechado mantido pelo RT */ ?>
  <details class="vcard" style="margin-top:16px;padding:14px 16px">
    <summary style="cursor:pointer"><strong>Catálogo de tipos de solo</strong>
      <span class="vhint">— fechado, definido pelo RT; o cadastro só aceita valores desta lista</span></summary>
    <form method="post" style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="catalogo_solo">
      <input type="text" name="catalogo" style="flex:1;min-width:320px"
             value="<?= h(implode('|', talhoes_catalogo_solo())) ?>">
      <button class="vbtn vbtn-primary" type="submit">Salvar catálogo</button>
      <div class="vhint" style="width:100%">Separar por <code>|</code> (máx. 30 tipos). Valores já gravados fora do catálogo são preservados e marcados na edição.</div>
    </form>
  </details>
  <?php endif; ?>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar talhão' : 'Novo talhão' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_select('fazenda_id', 'Fazenda', $fazendas, $edit['fazenda_id'] ?? ($fFazenda ?: null), true) ?></div>
        <?= vero_f_text('codigo', 'Código do talhão', $edit['codigo'] ?? '', true, 'Curto e único na fazenda, ex.: 2D, 5A') ?>
        <?= vero_f_text('nome', 'Nome (opcional)', $edit['nome'] ?? '') ?>
        <?= vero_f_text('area_ha', 'Área (ha)', $edit ? numFmt((float)$edit['area_ha'], 2) : '') ?>
        <?= vero_f_select('area_id', 'Área produtiva', $areasOpt, $edit['area_id'] ?? null, false, '— Sem área —') ?>
        <?= vero_f_select('variedade_id', 'Variedade principal', $variedadesOpt, $edit['variedade_id'] ?? null, false, '— Não informada —') ?>
        <?= vero_f_select('porta_enxerto_id', 'Porta-enxerto', $portaEnxertosOpt, $edit['porta_enxerto_id'] ?? null, false, '— Não informado —') ?>
        <?php /* A1-34 (P-28): catálogo fechado do RT; valor legado fora do catálogo aparece marcado */
          $catSolo = talhoes_catalogo_solo();
          $soloAtual = $edit['tipo_solo'] ?? null;
          $soloOpts = array_combine($catSolo, $catSolo);
          if ($soloAtual !== null && $soloAtual !== '' && !isset($soloOpts[$soloAtual])) {
              $soloOpts[$soloAtual] = $soloAtual . ' (fora do catálogo)';
          } ?>
        <?= vero_f_select('tipo_solo', 'Tipo de solo', $soloOpts, $soloAtual, false, '— Não informado —') ?>
        <?php /* A1-49: tipo de irrigação (atributo da válvula-espelho no modo unificado) */
          $tipoIrrAtual = null;
          if ($edit && vero_a1_valvula_unificada()) {
              $espId = vero_a1_setor_espelho_id((int)$edit['id']);
              if ($espId) $tipoIrrAtual = vero_val("SELECT tipo_irrigacao FROM agro_setores WHERE id=:i AND tenant_id=:t",
                  [':i' => $espId, ':t' => vero_tenant()]) ?: null;
          } ?>
        <?php if (vero_a1_valvula_unificada()): ?>
          <?= vero_f_select('tipo_irrigacao', 'Tipo de irrigação',
                ['gotejo' => 'Gotejo', 'microaspersao' => 'Microaspersão', 'pivo' => 'Pivô', 'outro' => 'Outro'],
                $tipoIrrAtual, false, '— Não informado —') ?>
        <?php endif; ?>
        <?= vero_f_text('data_plantio', 'Data de plantio', $edit['data_plantio'] ?? '', false, '', 'date') ?>
        <?= vero_f_text('espacamento_linha_m', 'Espaçamento — linha (m)', $edit && $edit['espacamento_linha_m'] !== null ? numFmt((float)$edit['espacamento_linha_m'], 2) : '') ?>
        <?= vero_f_text('espacamento_planta_m', 'Espaçamento — planta (m)', $edit && $edit['espacamento_planta_m'] !== null ? numFmt((float)$edit['espacamento_planta_m'], 2) : '') ?>
        <?= vero_f_text('num_plantas', 'Nº de plantas', $edit['num_plantas'] ?? '', false, 'Contagem real (parreiral tem falhas) — usada na calculadora') ?>
        <?= vero_f_text('num_filas', 'Nº de filas', $edit['num_filas'] ?? '', false, 'Fileiras da válvula — base de amarrio/desponte na calculadora') ?>
        <?= vero_f_text('latitude', 'Latitude', $edit['latitude'] ?? '', false, 'Opcional — mapa') ?>
        <?= vero_f_text('longitude', 'Longitude', $edit['longitude'] ?? '') ?>
        <div class="full"><?= vero_f_text('observacao', 'Observações', $edit['observacao'] ?? '') ?></div>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativo', 0 => 'Inativo'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vhint" style="margin-top:8px">O desenho do polígono (geometria) é feito na tela Mapa da Fazenda. A área produtiva deve pertencer à mesma fazenda.</div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
