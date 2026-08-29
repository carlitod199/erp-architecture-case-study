<?php
/* ============================================================
   VERO — Gestão Agrícola / Válvulas e Setores  (CRUD real)
   Entidade: agro_setores (migration_131: codigo, tipo, area_ha,
   fazenda_id, talhao_id opcional, ativo).
   Rota: /agro/valvulas.php — micro 'valvulas'. Guard: agricola.valvulas
   Escrita (hierárquico): agro.valvulas.editar/excluir

   MODO UNIFICADO (arbitragem A1-32, decisão do cliente): válvula = talhão.
   Esta é a ÚNICA tela de válvula (a de Cadastros→Válvula/talhoes.php está
   oculta no menu — P-120). Aqui o cadastro grava no registro CANÔNICO
   (agro_talhoes) e sincroniza a válvula-espelho 1:1 (vero_a1_sync_espelho);
   o UPDATE é parcial, então os dados técnicos (solo, variedade, lat/long,
   espaçamento) do talhão são preservados e continuam editáveis no cadastro
   técnico completo (talhoes.php, acessível pela ficha/URL).
   Escrita (unificado): agro.talhoes.editar/excluir.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_setor_espelho.php'; /* A1-36/A1-32: modo unificado */

const T = 'agro_setores';

/* A tela depende da migration 131. Sem ela, avisa em vez de quebrar. */
$mig131    = vero_has_column(T, 'codigo');
$unificado = vero_a1_valvula_unificada();

if ($mig131 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    /* ═══ MODO UNIFICADO: cadastro grava no talhão canônico + sincroniza o espelho ═══ */
    if ($unificado) {
        if ($acao === 'salvar') {
            vero_require('agro.talhoes.editar');
            $id        = vero_int('id'); /* id = talhao_id (registro canônico) */
            $fazendaId = vero_int('fazenda_id');
            $codigo    = vero_str('codigo', 20);

            if (!$fazendaId || $codigo === null) {
                vero_flash('erro', 'Fazenda e código da válvula são obrigatórios.');
                vero_redirect();
            }
            $okFaz = vero_val("SELECT id FROM agro_fazendas WHERE id=:f AND tenant_id=:t",
                [':f' => $fazendaId, ':t' => vero_tenant()]);
            if (!$okFaz) {
                vero_flash('erro', 'Fazenda inválida.');
                vero_redirect();
            }
            /* código único por fazenda (entre ativas) — mesma regra do cadastro canônico */
            $dup = vero_val(
                "SELECT id FROM agro_talhoes
                  WHERE tenant_id=:t AND fazenda_id=:f AND codigo=:c AND ativo=1 AND id<>:id",
                [':t' => vero_tenant(), ':f' => $fazendaId, ':c' => $codigo, ':id' => (int)$id]);
            if ($dup) {
                vero_flash('erro', "Já existe a válvula \"{$codigo}\" nesta fazenda.");
                vero_redirect();
            }

            /* porta-enxerto do tenant (opcional) — mig. 155; atributo da válvula/talhão */
            $portaEnxertoId = vero_int('porta_enxerto_id');
            if ($portaEnxertoId) {
                $okPe = vero_val("SELECT id FROM agro_porta_enxertos WHERE id=:p AND tenant_id=:t",
                    [':p' => $portaEnxertoId, ':t' => vero_tenant()]);
                if (!$okPe) $portaEnxertoId = null;
            }

            /* variedade do tenant (opcional) — item 4.1: passa a ser editável aqui */
            $variedadeId = vero_int('variedade_id');
            if ($variedadeId) {
                $okVar = vero_val("SELECT id FROM agro_variedades WHERE id=:v AND tenant_id=:t",
                    [':v' => $variedadeId, ':t' => vero_tenant()]);
                if (!$okVar) $variedadeId = null;
            }

            /* estrutura de condução (opcional) — item 4.2 / mig. 161; VARCHAR categórico validado */
            $estrutura = vero_str('estrutura_sistema', 20);
            if (!in_array($estrutura, ['latada', 'espaldeira', 'y'], true)) $estrutura = null;

            /* UPDATE PARCIAL: só os campos desta tela; preserva solo/coordenadas/espaçamento */
            $areaHaT = vero_dec('area_ha') ?? 0; /* A11: área nunca é negativa */
            if ($areaHaT < 0) { vero_flash('erro', 'A área não pode ser negativa.'); vero_redirect(); }
            $data = [
                'fazenda_id'        => $fazendaId,
                'codigo'            => $codigo,
                'nome'              => vero_str('nome', 120),
                'area_ha'           => $areaHaT,
                'num_plantas'       => vero_int('num_plantas'),
                'num_filas'         => vero_int('num_filas'),
                'porta_enxerto_id'  => $portaEnxertoId,
                'variedade_id'      => $variedadeId,
                'estrutura_sistema' => $estrutura,
                'ativo'             => vero_int('ativo') ?? 1,
            ];
            if ($id) {
                vero_update('agro_talhoes', $id, $data);
            } else {
                $id = vero_insert('agro_talhoes', $data);
            }

            /* sincroniza a válvula-espelho (talhão é a fonte da verdade) */
            $row = vero_row("SELECT * FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
                [':i' => (int)$id, ':t' => vero_tenant()]);
            if ($row) {
                $espelhoId = vero_a1_sync_espelho($row);
                /* tipo de irrigação é atributo da VÁLVULA → grava no espelho (A1-49) */
                $tipoIrr = vero_str('tipo_irrigacao', 20);
                vero_update('agro_setores', $espelhoId, [
                    'tipo_irrigacao' => in_array($tipoIrr, ['gotejo', 'microaspersao', 'pivo', 'outro'], true) ? $tipoIrr : null,
                ]);
            }
            vero_flash('ok', "Válvula \"{$codigo}\" salva.");
            vero_redirect();
        }

        if ($acao === 'excluir') {
            vero_require('agro.talhoes.excluir');
            $id = vero_int('id'); /* talhao_id */
            if ($id) {
                vero_delete('agro_talhoes', $id); /* soft delete do talhão */
                $row = vero_row("SELECT * FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
                    [':i' => (int)$id, ':t' => vero_tenant()]);
                if ($row) vero_a1_sync_espelho($row); /* espelho acompanha a inativação */
            }
            vero_redirect();
        }
        vero_redirect();
    }

    /* ═══ MODO HIERÁRQUICO: CRUD normal de agro_setores ═══ */
    if ($acao === 'salvar') {
        vero_require('agro.valvulas.editar');

        $id        = vero_int('id');
        $nome      = vero_str('nome', 120);
        $codigo    = vero_str('codigo', 20);
        $fazendaId = vero_int('fazenda_id');
        $talhaoId  = vero_int('talhao_id');

        if ($nome === null && $codigo === null) {
            vero_flash('erro', 'Informe ao menos o código ou o nome da válvula.');
            vero_redirect();
        }
        if (!$fazendaId && !$talhaoId) {
            vero_flash('erro', 'Vincule a válvula a uma fazenda (a válvula é opcional).');
            vero_redirect();
        }
        if ($talhaoId) {
            $tal = vero_row("SELECT id, fazenda_id FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
                [':i' => $talhaoId, ':t' => vero_tenant()]);
            if (!$tal) {
                vero_flash('erro', 'Válvula inválido.');
                vero_redirect();
            }
            $fazendaId = (int)$tal['fazenda_id'];
        } elseif ($fazendaId) {
            $okFaz = vero_val("SELECT id FROM agro_fazendas WHERE id=:f AND tenant_id=:t",
                [':f' => $fazendaId, ':t' => vero_tenant()]);
            if (!$okFaz) {
                vero_flash('erro', 'Fazenda inválida.');
                vero_redirect();
            }
        }
        if ($codigo !== null) {
            $dup = vero_val(
                "SELECT id FROM " . T . "
                  WHERE tenant_id=:t AND fazenda_id <=> :f AND codigo=:c AND ativo=1 AND id<>:id",
                [':t' => vero_tenant(), ':f' => $fazendaId, ':c' => $codigo, ':id' => (int)$id]
            );
            if ($dup) {
                vero_flash('erro', "Já existe a válvula \"{$codigo}\" nesta fazenda.");
                vero_redirect();
            }
        }

        $tipoIrr = vero_str('tipo_irrigacao', 20); /* A1-49 (DB-53) */
        $areaHa = vero_dec('area_ha') ?? 0; /* A11: área nunca é negativa */
        if ($areaHa < 0) { vero_flash('erro', 'A área não pode ser negativa.'); vero_redirect(); }
        $data = [
            'fazenda_id' => $fazendaId,
            'talhao_id'  => $talhaoId,
            'codigo'     => $codigo,
            'nome'       => $nome ?? $codigo,
            'tipo'       => in_array($_POST['tipo'] ?? '', ['valvula', 'setor'], true) ? $_POST['tipo'] : 'valvula',
            'tipo_irrigacao' => in_array($tipoIrr, ['gotejo', 'microaspersao', 'pivo', 'outro'], true) ? $tipoIrr : null,
            'area_ha'    => $areaHa,
            'ativo'      => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', 'Válvula atualizada.');
        } else {
            vero_insert(T, $data);
            vero_flash('ok', 'Válvula cadastrada.');
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('agro.valvulas.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete pós-131
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$rows = [];
$total = 0;
$q = trim((string)($_GET['q'] ?? ''));
$fFazenda = (int)($_GET['fazenda'] ?? 0);
$page = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 15;

/* P-15: oculta válvulas inativas por padrão (?inativos=1 p/ editor). */
$verInativos = !empty($_GET['inativos']) && vero_can('agro.talhoes.editar');

if ($mig131 && $unificado) {
    /* lista as VÁLVULAS canônicas (talhões); tipo de irrigação vem do espelho */
    $where  = "tl.tenant_id = :t";
    $params = [':t' => vero_tenant()];
    if (!$verInativos) { $where .= " AND tl.ativo = 1"; }
    if ($q !== '') {
        $where .= " AND (tl.codigo LIKE :q1 OR tl.nome LIKE :q2)"; /* QA-011 */
        $params[':q1'] = $params[':q2'] = "%{$q}%";
    }
    if ($fFazenda > 0) { $where .= " AND tl.fazenda_id = :f"; $params[':f'] = $fFazenda; }
    $total = (int)vero_val("SELECT COUNT(*) FROM agro_talhoes tl WHERE {$where}", $params);
    $rows  = vero_rows(
        "SELECT tl.id, tl.codigo, tl.nome, tl.area_ha, tl.num_plantas, tl.ativo,
                f.nome AS fazenda_nome,
                (SELECT se.tipo_irrigacao FROM agro_setores se
                  WHERE se.talhao_id = tl.id AND se.tenant_id = tl.tenant_id
                  ORDER BY se.ativo DESC, se.id LIMIT 1) AS tipo_irrigacao
           FROM agro_talhoes tl
      LEFT JOIN agro_fazendas f ON f.id = tl.fazenda_id
          WHERE {$where}
          ORDER BY tl.ativo DESC, f.nome, tl.codigo
          LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
        $params);
} elseif ($mig131) {
    $where  = "s.tenant_id = :t";
    $params = [':t' => vero_tenant()];
    if (!$verInativos) { $where .= " AND s.ativo = 1"; }
    if ($q !== '') {
        $where .= " AND (s.codigo LIKE :q1 OR s.nome LIKE :q2)"; /* QA-011 */
        $params[':q1'] = $params[':q2'] = "%{$q}%";
    }
    if ($fFazenda > 0) { $where .= " AND s.fazenda_id = :f"; $params[':f'] = $fFazenda; }
    $total = (int)vero_val("SELECT COUNT(*) FROM " . T . " s WHERE {$where}", $params);
    $rows  = vero_rows(
        "SELECT s.*, f.nome AS fazenda_nome, tl.codigo AS talhao_codigo
           FROM " . T . " s
      LEFT JOIN agro_fazendas f ON f.id = s.fazenda_id
      LEFT JOIN agro_talhoes  tl ON tl.id = s.talhao_id
          WHERE {$where}
          ORDER BY s.ativo DESC, f.nome, s.codigo, s.nome
          LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
        $params
    );
}

$fazendas = vero_options('agro_fazendas', 'nome', 'ativo = 1');
$talhoes  = [];
foreach (vero_rows(
    "SELECT t.id, CONCAT(f.nome, ' — ', t.codigo) AS label
       FROM agro_talhoes t JOIN agro_fazendas f ON f.id = t.fazenda_id
      WHERE t.tenant_id = :t AND t.ativo = 1
      ORDER BY f.nome, t.codigo",
    [':t' => vero_tenant()]
) as $r) { $talhoes[(int)$r['id']] = (string)$r['label']; }

$portaEnxertosOpt = vero_options('agro_porta_enxertos', 'nome', 'ativo = 1');
$variedadesOpt    = vero_options('agro_variedades', 'nome', 'ativo = 1'); /* item 4.1 */
$estruturasOpt    = ['latada' => 'Latada', 'espaldeira' => 'Espaldeira', 'y' => 'Y']; /* item 4.2 / mig.161 */

/* Registro em edição: no unificado vem de agro_talhoes (+ tipo_irrigação do espelho) */
$edit = null;
if ($mig131 && !empty($_GET['editar'])) {
    if ($unificado) {
        $edit = vero_row("SELECT * FROM agro_talhoes WHERE id=:id AND tenant_id=:t",
            [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
        if ($edit) {
            $edit['tipo_irrigacao'] = vero_val(
                "SELECT tipo_irrigacao FROM agro_setores
                  WHERE talhao_id=:i AND tenant_id=:t ORDER BY ativo DESC, id LIMIT 1",
                [':i' => (int)$edit['id'], ':t' => vero_tenant()]);
        }
    } else {
        $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
            [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    }
}

$GUARD      = ['macro' => 'agricola', 'micro' => 'valvulas'];
$PAGE_VIEW  = 'agricola_valvulas';
$PAGE_TITLE = 'Válvulas / Setores';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = $mig131 && ($unificado ? vero_can('agro.talhoes.editar') : vero_can('agro.valvulas.editar'));
$podeExcluir = $mig131 && ($unificado ? vero_can('agro.talhoes.excluir') : vero_can('agro.valvulas.excluir'));
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Válvulas',
        $unificado
          ? 'Cadastro das válvulas (válvula = unidade produtiva). Área, nº de plantas e irrigação; os dados técnicos ficam na ficha da válvula.'
          : 'Setores de irrigação — unidade operacional de monitoramento, colheita e venda',
        $podeEditar ? '+ Nova válvula' : null) ?>

  <?php if (!$mig131): ?>
    <div class="vflash vflash-aviso">
      A migration <strong>131 (setores/colheita)</strong> ainda não foi aplicada neste banco —
      as colunas de válvula (código, tipo, área) não existem. Aplique
      <code>migration_131_setores_colheita.sql</code> e recarregue esta página.
    </div>
  <?php else: ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="fazenda" onchange="this.form.submit()">
          <option value="">Todas as fazendas</option>
          <?php foreach ($fazendas as $fid => $fn): ?>
            <option value="<?= $fid ?>"<?= $fFazenda === $fid ? ' selected' : '' ?>><?= h($fn) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por código ou nome…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty"><?= ($q !== '' || $fFazenda) ? 'Nenhuma válvula para os filtros selecionados.' : 'Nenhuma válvula cadastrada ainda.' ?></div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Código</th><th>Nome</th>
        <?php if (!$unificado): ?><th>Tipo</th><?php endif; ?>
        <th>Fazenda</th>
        <?php if ($unificado): ?><th>Irrigação</th><th class="num">Plantas</th><?php else: ?><th>Válvula</th><?php endif; ?>
        <th class="num">Área (ha)</th><th>Status</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= (int)$r['ativo'] === 0 ? ' class="is-off"' : '' ?>>
          <td><strong class="vnum"><?= h($r['codigo'] ?? '—') ?></strong></td>
          <td><?= h($r['nome'] ?? '') ?></td>
          <?php if (!$unificado): ?><td><?= $r['tipo'] === 'setor' ? 'Setor' : 'Válvula' ?></td><?php endif; ?>
          <td><?= h($r['fazenda_nome'] ?? '—') ?></td>
          <?php if ($unificado): ?>
            <td><?= h(['gotejo'=>'Gotejo','microaspersao'=>'Microaspersão','pivo'=>'Pivô','outro'=>'Outro'][$r['tipo_irrigacao'] ?? ''] ?? '—') ?></td>
            <td class="num"><?= $r['num_plantas'] !== null ? numFmt((float)$r['num_plantas'], 0) : '—' ?></td>
          <?php else: ?>
            <td class="vnum"><?= h($r['talhao_codigo'] ?? '—') ?></td>
          <?php endif; ?>
          <td class="num"><?= numFmt((float)$r['area_ha'], 2) ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td class="num"><div class="vactions" style="justify-content:flex-end">
            <?php if ($unificado): ?>
              <?= vero_btn_icone(vero_ico_olho(), 'Ver ficha', '', BIOS_BASE . '/agro/talhao_ficha?id=' . (int)$r['id']) ?>
              <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
              <?php if ($podeExcluir && (int)$r['ativo'] === 1): ?>
                <?= vero_btn_excluir((int)$r['id'], 'Inativar esta válvula?') ?>
              <?php endif; ?>
            <?php else: ?>
              <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
              <?php if ($podeExcluir && (int)$r['ativo'] === 1): ?>
                <?= vero_btn_excluir((int)$r['id'], 'Inativar esta válvula?') ?>
              <?php endif; ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar válvula' : 'Nova válvula' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('codigo', 'Código', $edit['codigo'] ?? '', $unificado, 'Ex.: 5A, 2D — único na fazenda') ?>
        <?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', false, 'Se vazio, usa o código') ?>
        <?= vero_f_select('fazenda_id', 'Fazenda', $fazendas, $edit['fazenda_id'] ?? ($fFazenda ?: null), $unificado) ?>
        <?= vero_f_text('area_ha', 'Área (ha)', $edit ? numFmt((float)($edit['area_ha'] ?? 0), 2) : '') ?>
        <?php if ($unificado): ?>
          <?= vero_f_text('num_plantas', 'Nº de plantas', $edit && $edit['num_plantas'] !== null ? (string)(int)$edit['num_plantas'] : '', false, '') ?>
          <?= vero_f_text('num_filas', 'Nº de filas', $edit && ($edit['num_filas'] ?? null) !== null ? (string)(int)$edit['num_filas'] : '', false, '') ?>
          <?= vero_f_select('variedade_id', 'Variedade', $variedadesOpt, $edit['variedade_id'] ?? null, false, '— Não informado —') ?>
          <?= vero_f_select('estrutura_sistema', 'Estrutura de condução', $estruturasOpt, $edit['estrutura_sistema'] ?? null, false, '— Não informado —') ?>
          <?= vero_f_select('porta_enxerto_id', 'Porta-enxerto', $portaEnxertosOpt, $edit['porta_enxerto_id'] ?? null, false, '— Não informado —') ?>
          <?= vero_f_select('tipo_irrigacao', 'Tipo de irrigação',
                ['gotejo' => 'Gotejo', 'microaspersao' => 'Microaspersão', 'pivo' => 'Pivô', 'outro' => 'Outro'],
                $edit['tipo_irrigacao'] ?? null, false, '— Não informado —') ?>
        <?php else: ?>
          <?= vero_f_select('tipo', 'Tipo', ['valvula' => 'Válvula', 'setor' => 'Setor'], $edit['tipo'] ?? 'valvula', true, '') ?>
          <?= vero_f_select('tipo_irrigacao', 'Tipo de irrigação',
                ['gotejo' => 'Gotejo', 'microaspersao' => 'Microaspersão', 'pivo' => 'Pivô', 'outro' => 'Outro'],
                $edit['tipo_irrigacao'] ?? null, false, '— Não informado —') ?>
          <?= vero_f_select('talhao_id', 'Válvula (opcional)', $talhoes, $edit['talhao_id'] ?? null, false, '— Sem válvula por enquanto —') ?>
        <?php endif; ?>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <?php if (!$unificado): ?>
      <div class="vhint" style="margin-top:8px">
        Se uma válvula for selecionado, a fazenda é herdada automaticamente dele.
      </div>
      <?php endif; ?>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
