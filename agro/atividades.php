<?php
/* ============================================================
   VERO — Agrícola / Planejamento de Atividades  (CRUD real)
   Substitui o mock. Rota: /agro/atividades.php
   Guard: agricola.planejamento_atividades
   Atividades planejadas por talhão/safra (agro_atividades):
   planejada → em execução → concluída; os apontamentos registram
   a execução em Gestão Agrícola → Apontamentos.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_setor_espelho.php'; /* A1-36: rótulo P-57 (válvula = talhão) */
require_once __DIR__ . '/_os_espelho.php';    /* A1-38: OS = projeção numerada da atividade (P-29) */

const T = 'agro_atividades';
const STATUS_ATIV = ['planejada' => 'Planejada', 'em_execucao' => 'Em execução', 'concluida' => 'Concluída', 'cancelada' => 'Cancelada'];

/* categoria do catálogo → ENUM legado agro_atividades.tipo (sql_mode não-strict
   silenciava texto livre para '' — agora o valor deriva do catálogo, DB-17) */
const CAT_PARA_TIPO = [
    'trato_cultural' => 'tratos_culturais', 'colheita' => 'colheita',
    'aplicacao' => 'aplicacao', 'irrigacao' => 'irrigacao',
    'packing' => 'outro', 'outro' => 'outro',
];

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.planejamento_atividades.editar');
        $id        = vero_int('id');
        $descricao = vero_str('descricao', 200);
        $talhao    = vero_int('talhao_id');
        if ($descricao === null || !$talhao) {
            vero_flash('erro', 'Descrição e talhão são obrigatórios.');
            vero_redirect();
        }
        $safraId = vero_int('safra_id') ?: null;

        /* tipo de atividade do catálogo (DB-17) — deriva o ENUM legado */
        $tipoAtivId = vero_int('tipo_atividade_id') ?: null;
        $tipoEnum = 'outro';
        if ($tipoAtivId) {
            $cat = vero_val("SELECT categoria FROM agro_tipos_atividade WHERE id=:i AND tenant_id=:t",
                [':i' => $tipoAtivId, ':t' => $t]);
            if ($cat === false || $cat === null) { $tipoAtivId = null; }
            else { $tipoEnum = CAT_PARA_TIPO[(string)$cat] ?? 'outro'; }
        }

        /* A11: área prevista e custo previsto são sempre >= 0 */
        $areaPrev  = vero_dec('area_prevista_ha');
        $custoPrev = vero_dec('custo_previsto');
        if (($areaPrev !== null && $areaPrev < 0) || ($custoPrev !== null && $custoPrev < 0)) {
            vero_flash('erro', 'Área prevista e custo previsto não podem ser negativos.');
            vero_redirect();
        }

        $data = [
            'descricao'         => $descricao,
            'tipo'              => $tipoEnum,
            'tipo_atividade_id' => $tipoAtivId,
            'talhao_id'       => (int)$talhao,
            'safra_id'        => $safraId,
            'safra_talhao_id' => $safraId ? (vero_val(
                "SELECT id FROM agro_safra_talhoes WHERE tenant_id=:t AND safra_id=:s AND talhao_id=:tal",
                [':t' => $t, ':s' => $safraId, ':tal' => $talhao]) ?: null) : null,
            'responsavel_id'   => vero_int('responsavel_id') ?: null,
            'data_planejada'   => vero_date('data_planejada'),
            'data_realizada'   => vero_date('data_realizada'),
            'area_prevista_ha' => $areaPrev,
            'custo_previsto'   => $custoPrev,
            'observacao'       => vero_str('observacao', 255),
        ];
        if ($id) { vero_update(T, $id, $data); $flash = 'Atividade atualizada.'; }
        else     { $data['status'] = 'planejada'; $id = vero_insert(T, $data); $flash = 'Atividade planejada.'; }

        /* A1-40 (DB-35): insumos PLANEJADOS — snapshot do custo médio no
           planejamento (editável); alimenta a reserva ORIENTATIVA do A2 (P-60).
           A execução não muda: o consumo real segue pelo apontamento/DF-IF. */
        $piProd  = array_map('intval', (array)($_POST['pi_produto'] ?? []));
        $piQtd   = (array)($_POST['pi_qtd'] ?? []);
        $piCusto = (array)($_POST['pi_custo'] ?? []);
        $piObs   = (array)($_POST['pi_obs'] ?? []);
        $parseDec = static function ($v): ?float { /* pt-BR: "1.234,56" */
            $v = trim((string)$v);
            if ($v === '') return null;
            if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
            elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
            return is_numeric($v) ? (float)$v : null;
        };
        vero_pdo()->prepare("DELETE FROM agro_atividade_insumos WHERE tenant_id=? AND atividade_id=?")
            ->execute([$t, (int)$id]);
        $nIns = 0;
        foreach ($piProd as $ix => $pid) {
            $qtd = $parseDec($piQtd[$ix] ?? '');
            if ($pid <= 0 || $qtd === null || $qtd <= 0) continue;
            if (!vero_val("SELECT id FROM estoque_produtos WHERE id=:i AND tenant_id=:t", [':i' => $pid, ':t' => $t])) continue;
            vero_insert('agro_atividade_insumos', [
                'atividade_id'           => (int)$id,
                'produto_id'             => $pid,
                'quantidade_prevista'    => $qtd,
                'custo_unitario_previsto' => $parseDec($piCusto[$ix] ?? ''),
                'observacao'             => trim((string)($piObs[$ix] ?? '')) ?: null,
            ]);
            $nIns++;
        }
        if ($nIns > 0) $flash .= " {$nIns} insumo(s) planejado(s).";
        /* A1-38: OS-espelho numerada acompanha a atividade (aplicação = DF/IF, sem OS) */
        $atvRow = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => (int)$id, ':t' => $t]);
        if ($atvRow) {
            $osId = vero_a1_os_sync($atvRow);
            if ($osId) {
                $numero = vero_val("SELECT numero FROM agro_ordens_servico WHERE id=:i AND tenant_id=:t",
                    [':i' => $osId, ':t' => $t]);
                $flash .= ' OS ' . $numero . ' sincronizada.';
            }
        }
        vero_flash('ok', $flash);
        vero_redirect();
    }

    if ($acao === 'status') {
        vero_require('agro.planejamento_atividades.editar');
        $id = vero_int('id');
        $novo = (string)($_POST['status'] ?? '');
        if ($id && isset(STATUS_ATIV[$novo])) {
            $upd = ['status' => $novo];
            if ($novo === 'concluida') {
                /* data realizada = último apontamento vinculado (fallback hoje), se ainda vazia */
                $atual = vero_row("SELECT data_realizada FROM " . T . " WHERE id=:i AND tenant_id=:t",
                    [':i' => (int)$id, ':t' => $t]);
                if ($atual && $atual['data_realizada'] === null) {
                    $ult = vero_val(
                        "SELECT MAX(DATE(ap.data_apontamento)) FROM agro_apontamentos ap
                          WHERE ap.tenant_id=:t AND ap.atividade_id=:a",
                        [':t' => $t, ':a' => (int)$id]);
                    $upd['data_realizada'] = ($ult !== false && $ult !== null) ? (string)$ult : date('Y-m-d');
                }
            }
            vero_update(T, (int)$id, $upd);
            $atvRow = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => (int)$id, ':t' => $t]);
            if ($atvRow) vero_a1_os_sync($atvRow); /* A1-38: status da OS espelha */
            vero_flash('ok', 'Atividade marcada como ' . strtolower(STATUS_ATIV[$novo]) . '.');
        }
        vero_redirect();
    }

    if ($acao === 'excluir') { /* cancelamento lógico */
        vero_require('agro.planejamento_atividades.excluir');
        $id = vero_int('id');
        if ($id) {
            vero_update(T, (int)$id, ['status' => 'cancelada']);
            $atvRow = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => (int)$id, ':t' => $t]);
            if ($atvRow) vero_a1_os_sync($atvRow); /* OS cancela junto */
            vero_flash('ok', 'Atividade cancelada.');
        }
        vero_redirect();
    }
}

$fStatus = (string)($_GET['status'] ?? '');
$where  = "a.tenant_id = :t";
$params = [':t' => $t];
if (isset(STATUS_ATIV[$fStatus])) { $where .= " AND a.status = :st"; $params[':st'] = $fStatus; }

/* Planejado×realizado (A1-16): realizado deriva dos apontamentos vinculados
   (área = Σ hectares; custo = custeio das origens rh/insumo/máquina dos
   apontamentos) — sem coluna nova, contrato de leitura com A3 */
$rows = vero_rows(
    "SELECT a.*, tl.codigo AS talhao, fz.nome AS fazenda, sa.identificacao AS safra, op.nome AS responsavel,
            ta.nome AS tipo_atividade_nome,
            (SELECT COUNT(*) FROM agro_apontamentos ap
              WHERE ap.tenant_id = a.tenant_id AND ap.atividade_id = a.id)                    AS apontamentos,
            (SELECT COALESCE(SUM(ap.hectares),0) FROM agro_apontamentos ap
              WHERE ap.tenant_id = a.tenant_id AND ap.atividade_id = a.id)                    AS area_realizada,
            (SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl
              WHERE cl.tenant_id = a.tenant_id AND (
                (cl.origem_tipo = 'rh_producao_item' AND cl.origem_id IN (
                    SELECT rpi.id FROM rh_producao_itens rpi
                      JOIN agro_apontamentos ap2 ON ap2.id = rpi.apontamento_id
                     WHERE rpi.tenant_id = a.tenant_id AND ap2.atividade_id = a.id))
                OR (cl.origem_tipo = 'apontamento_insumo' AND cl.origem_id IN (
                    SELECT ai.id FROM agro_apontamento_insumos ai
                      JOIN agro_apontamentos ap3 ON ap3.id = ai.apontamento_id
                     WHERE ai.tenant_id = a.tenant_id AND ap3.atividade_id = a.id))
                OR (cl.origem_tipo = 'apontamento_maquina' AND cl.origem_id IN (
                    SELECT am.id FROM agro_apontamento_maquinas am
                      JOIN agro_apontamentos ap4 ON ap4.id = am.apontamento_id
                     WHERE am.tenant_id = a.tenant_id AND ap4.atividade_id = a.id))
              ))                                                                              AS custo_realizado
       FROM " . T . " a
       LEFT JOIN agro_talhoes tl ON tl.id = a.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
       LEFT JOIN agro_safras sa ON sa.id = a.safra_id
       LEFT JOIN agro_operadores op ON op.id = a.responsavel_id
       LEFT JOIN agro_tipos_atividade ta ON ta.id = a.tipo_atividade_id
      WHERE {$where}
      ORDER BY FIELD(a.status,'em_execucao','planejada','concluida','cancelada'), a.data_planejada, a.id DESC
      LIMIT 100", $params);

$talhoes = vero_rows(
    "SELECT t.id, t.codigo, f.nome AS fazenda FROM agro_talhoes t
      LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
     WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo", [':t' => $t]);
$talhaoOpts = [];
foreach ($talhoes as $tl) $talhaoOpts[(int)$tl['id']] = ($tl['fazenda'] ? $tl['fazenda'] . ' — ' : '') . $tl['codigo'];
$safras = vero_options('agro_safras', 'identificacao');
$operadores = vero_options('agro_operadores', 'nome');
$tiposAtividade = vero_options('agro_tipos_atividade', 'nome', 'ativo = 1');

$edit = null;
$editInsumos = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t", [':id' => (int)$_GET['editar'], ':t' => $t]);
    if ($edit) {
        $editInsumos = vero_rows(
            "SELECT * FROM agro_atividade_insumos WHERE tenant_id=:t AND atividade_id=:a ORDER BY id",
            [':t' => $t, ':a' => (int)$edit['id']]);
    }
}

/* A1-40: produtos ativos com o custo médio ATUAL (prefill do snapshot) */
$produtosPlan = vero_rows(
    "SELECT p.id, CONCAT(COALESCE(p.codigo,''), CASE WHEN p.codigo IS NULL THEN '' ELSE ' — ' END, p.nome) AS nome,
            p.unidade,
            (SELECT ROUND(SUM(s.valor_total) / NULLIF(SUM(s.quantidade), 0), 4)
               FROM estoque_saldos s WHERE s.tenant_id = p.tenant_id AND s.produto_id = p.id) AS custo_medio
       FROM estoque_produtos p
      WHERE p.tenant_id = :t AND p.ativo = 1
      ORDER BY p.nome", [':t' => $t]);

$badgeSt = static fn(string $s): string => match ($s) {
    'concluida'   => '<span class="vbadge vb-ok">Concluída</span>',
    'em_execucao' => '<span class="vbadge vb-warn">Em execução</span>',
    'cancelada'   => '<span class="vbadge vb-off">Cancelada</span>',
    default       => '<span class="vbadge vb-info">Planejada</span>',
};

$GUARD      = ['macro' => 'agricola', 'micro' => 'planejamento_atividades'];
$PAGE_VIEW  = 'agricola_planejamento_atividades';
$PAGE_TITLE = 'Planejamento de Atividades';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.planejamento_atividades.editar');
$hoje = date('Y-m-d');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Planejamento de Atividades', 'Atividades planejadas por talhão — a execução é registrada nos apontamentos',
        $podeEditar ? '+ Nova atividade' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <select name="status" onchange="this.form.submit()">
          <option value="">Todos os status</option>
          <?php foreach (STATUS_ATIV as $k => $rotulo): ?>
            <option value="<?= $k ?>"<?= $fStatus === $k ? ' selected' : '' ?>><?= h($rotulo) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= count($rows) ?> atividade(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma atividade planejada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Atividade</th><th><?= h(vero_a1_rotulo_area()) ?></th><th>Safra</th>
        <th>Planejado</th><th>Realizado</th><th>Status</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $atrasada = $r['status'] === 'planejada' && $r['data_planejada'] !== null && $r['data_planejada'] < $hoje;
          $custoPrev = $r['custo_previsto'] !== null ? (float)$r['custo_previsto'] : null;
          $custoReal = (float)$r['custo_realizado'];
          $areaPrev  = $r['area_prevista_ha'] !== null ? (float)$r['area_prevista_ha'] : null;
          $areaReal  = (float)$r['area_realizada'];
          $estouro   = $custoPrev !== null && $custoPrev > 0 && $custoReal > $custoPrev; ?>
        <tr<?= $r['status'] === 'cancelada' ? ' style="opacity:.55"' : '' ?>>
          <td><strong><?= h($r['descricao']) ?></strong>
            <?= $r['tipo_atividade_nome'] ? '<span class="vhint">' . h((string)$r['tipo_atividade_nome']) . '</span>'
                : ($r['tipo'] ? '<span class="vhint">' . h((string)$r['tipo']) . '</span>' : '') ?>
            <?= $r['responsavel'] ? '<div class="vhint">Resp.: ' . h((string)$r['responsavel']) . '</div>' : '' ?></td>
          <td><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td><?= h($r['safra'] ?? '—') ?></td>
          <td><?= $r['data_planejada']
                ? '<span class="vbadge ' . ($atrasada ? 'vb-off' : 'vb-info') . '">' . date('d/m/Y', strtotime((string)$r['data_planejada'])) . '</span>'
                : '—' ?>
            <div class="vhint">
              <?= $areaPrev !== null ? numFmt($areaPrev, 2) . ' ha' : 'área —' ?> ·
              <?= $custoPrev !== null ? 'R$ ' . numFmt($custoPrev, 2) : 'custo —' ?>
            </div></td>
          <td><?= $r['data_realizada']
                ? '<span class="vbadge vb-ok">' . date('d/m/Y', strtotime((string)$r['data_realizada'])) . '</span>'
                : ((int)$r['apontamentos'] > 0 ? '<span class="vhint">' . (int)$r['apontamentos'] . ' apontamento(s)</span>' : '—') ?>
            <div class="vhint" style="<?= $estouro ? 'color:#b3261e' : '' ?>">
              <?= numFmt($areaReal, 2) ?> ha · R$ <?= numFmt($custoReal, 2) ?><?php
                if ($custoPrev !== null && $custoPrev > 0) {
                    $desvio = ($custoReal / $custoPrev - 1) * 100;
                    echo ' (' . ($desvio >= 0 ? '+' : '') . numFmt($desvio, 0) . '%)';
                } ?>
            </div></td>
          <td><?= $badgeSt((string)$r['status']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar && $r['status'] === 'planejada'): ?>
              <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="status" value="em_execucao">
                <button class="vicon vicon-acao" type="submit" title="Iniciar" aria-label="Iniciar"><?= vero_ico_seta() ?></button></form>
            <?php endif; ?>
            <?php if ($podeEditar && $r['status'] === 'em_execucao'): ?>
              <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="status" value="concluida">
                <button class="vicon vicon-acao" type="submit" title="Concluir" aria-label="Concluir"><?= vero_ico_check() ?></button></form>
            <?php endif; ?>
            <?php if ($podeEditar && in_array($r['status'], ['planejada', 'em_execucao'], true)): ?>
              <?= vero_btn_editar((int)$r['id']) ?>
            <?php endif; ?>
            <?php if (vero_can('agro.planejamento_atividades.excluir') && in_array($r['status'], ['planejada', 'em_execucao'], true)): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Cancelar esta atividade?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar atividade' : 'Nova atividade' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('descricao', 'Descrição', $edit['descricao'] ?? '', true, 'Ex.: Poda de formação quadra norte') ?></div>
        <?= vero_f_select('tipo_atividade_id', 'Tipo de atividade (catálogo)', $tiposAtividade, $edit['tipo_atividade_id'] ?? null, false, '— Sem tipo —') ?>
        <?= vero_f_select('talhao_id', vero_a1_rotulo_area(), $talhaoOpts, $edit['talhao_id'] ?? '', true, 'Selecione…') ?>
        <?= vero_f_select('safra_id', 'Safra', ['' => 'Sem safra'] + $safras, $edit['safra_id'] ?? '', false, '') ?>
        <?= vero_f_select('responsavel_id', 'Responsável', ['' => 'Sem responsável'] + $operadores, $edit['responsavel_id'] ?? '', false, '') ?>
        <div class="vfield">
          <label>Data planejada</label>
          <input type="date" name="data_planejada" value="<?= h($edit['data_planejada'] ?? '') ?>">
        </div>
        <?= vero_f_text('area_prevista_ha', 'Área prevista (ha)', $edit && $edit['area_prevista_ha'] !== null ? numFmt((float)$edit['area_prevista_ha'], 2) : '') ?>
        <?= vero_f_text('custo_previsto', 'Custo previsto (R$)', $edit && $edit['custo_previsto'] !== null ? numFmt((float)$edit['custo_previsto'], 2) : '', false, 'Informativo — não trava apontamentos') ?>
        <?php if ($edit): ?>
        <div class="vfield">
          <label>Data realizada</label>
          <input type="date" name="data_realizada" value="<?= h($edit['data_realizada'] ?? '') ?>">
          <div class="vhint">Preenchida automaticamente ao concluir (último apontamento)</div>
        </div>
        <?php endif; ?>
        <div class="full"><?= vero_f_text('observacao', 'Observação', $edit['observacao'] ?? '') ?></div>
      </div>

      <!-- A1-40 (DB-35): insumos planejados — alimentam a reserva ORIENTATIVA do estoque (A2/P-60) -->
      <div class="vfield" style="margin-top:10px">
        <label>Insumos planejados (snapshot do custo médio — editável; base da reserva orientativa do estoque)</label>
        <table class="vtable">
          <thead><tr>
            <th style="width:36%">Produto</th>
            <th style="width:15%">Qtd prevista</th>
            <th style="width:17%">Custo unit. (R$)</th>
            <th style="width:15%">Valor (R$)</th>
            <th style="width:22%">Observação</th>
            <th style="width:36px"></th>
          </tr></thead>
          <tbody id="ativ-insumos"></tbody>
        </table>
        <div style="display:flex;gap:10px;align-items:center;margin-top:6px;flex-wrap:wrap">
          <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="ativAddInsumo()">+ Insumo</button>
          <span class="vhint">Σ insumos planejados: <strong class="vnum" id="ativ-soma-insumos">R$ 0,00</strong></span>
          <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="ativUsarSoma()" title="Copia o Σ para o campo Custo previsto — sem sobrescrita silenciosa">Usar Σ como custo previsto</button>
        </div>
      </div>
      <div class="vhint" style="margin-top:8px">O realizado (área e custo) vem dos apontamentos que citam esta atividade — vincule no lançamento do apontamento.</div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<script>
/* A1-40: insumos planejados (DB-35) — snapshot de custo editável + Σ de referência */
const PRODUTOS_PLAN = <?= jsvar(array_map(static fn($p) => [
    'id' => (int)$p['id'], 'nome' => (string)$p['nome'],
    'un' => $p['unidade'] ?? '',
    'custo' => $p['custo_medio'] !== null ? (float)$p['custo_medio'] : null,
], $produtosPlan)) ?>;
const EDIT_INSUMOS_PLAN = <?= jsvar(array_map(static fn($i) => [
    'produto' => (int)$i['produto_id'],
    'qtd' => (float)$i['quantidade_prevista'],
    'custo' => $i['custo_unitario_previsto'] !== null ? (float)$i['custo_unitario_previsto'] : null,
    'obs' => $i['observacao'],
], $editInsumos)) ?>;

function ativDec(v) {
  v = String(v || '').trim();
  if (!v) return 0;
  if (v.includes(',')) v = v.replace(/\./g, '').replace(',', '.');
  return parseFloat(v) || 0;
}
function ativFmt(n) { return n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

function ativRecalc() {
  let soma = 0;
  document.querySelectorAll('#ativ-insumos tr').forEach(tr => {
    const v = ativDec(tr.querySelector('input[name="pi_qtd[]"]').value)
            * ativDec(tr.querySelector('input[name="pi_custo[]"]').value);
    tr.querySelector('.pi-valor').textContent = ativFmt(v);
    soma += v;
  });
  document.getElementById('ativ-soma-insumos').textContent = 'R$ ' + ativFmt(soma);
  return soma;
}

function ativAddInsumo(preset) {
  const tb = document.getElementById('ativ-insumos');
  const tr = document.createElement('tr');
  const opts = ['<option value="">Selecione…</option>']
    .concat(PRODUTOS_PLAN.map(p => `<option value="${p.id}">${esc(p.nome)}${p.un ? ' (' + esc(p.un) + ')' : ''}</option>`)).join('');
  tr.innerHTML = `
    <td><select name="pi_produto[]">${opts}</select></td>
    <td><input type="text" name="pi_qtd[]" placeholder="0,00" style="text-align:right"></td>
    <td><input type="text" name="pi_custo[]" placeholder="custo médio" style="text-align:right"></td>
    <td class="vnum" style="text-align:right"><span class="pi-valor">0,00</span></td>
    <td><input type="text" name="pi_obs[]" placeholder="opcional"></td>
    <td><button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="this.closest('tr').remove(); ativRecalc()">×</button></td>`;
  tb.appendChild(tr);
  const sel = tr.querySelector('select');
  sel.addEventListener('change', () => {
    const p = PRODUTOS_PLAN.find(x => x.id === parseInt(sel.value || '0', 10));
    const custo = tr.querySelector('input[name="pi_custo[]"]');
    if (p && p.custo !== null && custo.value === '') {
      custo.value = ativFmt(p.custo); /* snapshot do custo médio ATUAL — editável */
    }
    ativRecalc();
  });
  tr.querySelectorAll('input[name="pi_qtd[]"], input[name="pi_custo[]"]')
    .forEach(i => i.addEventListener('input', ativRecalc));
  if (preset) {
    sel.value = String(preset.produto);
    tr.querySelector('input[name="pi_qtd[]"]').value = ativFmt(preset.qtd);
    if (preset.custo !== null) tr.querySelector('input[name="pi_custo[]"]').value = ativFmt(preset.custo);
    if (preset.obs) tr.querySelector('input[name="pi_obs[]"]').value = preset.obs;
  }
  ativRecalc();
}

function ativUsarSoma() {
  const campo = document.querySelector('#vm-form input[name="custo_previsto"]');
  if (campo) campo.value = ativFmt(ativRecalc()); /* cópia EXPLÍCITA — nunca silenciosa */
}

EDIT_INSUMOS_PLAN.forEach(i => ativAddInsumo(i));
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
