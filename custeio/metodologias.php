<?php
/* ============================================================
   VERO — Custos / Metodologias de Custo de Produção (A3-T24)
   Rota: /custeio/metodologias.php · Guard: custeio.metodologias
   SPEC: docs/VERO_CUSTO_PRODUCAO_SPEC.md §3/§6 (A0-12/A0-13).
   CRUD metodologia + grupos + itens aninhados. Categóricos são
   VARCHAR validados pelas listas VERO_CUSTO_* dos services.
   mapa_realizado (origens/categorias/planos) é o coração da
   derivação do realizado (F2): ao salvar item, roda
   vero_srv_custo_mapa_conflitos — sobreposição EXATA envolvendo o
   item BLOQUEIA (rollback); PARCIAL apenas avisa (DECISIONS 05/07).
   Seed "Padrão VERO" (anual/perene) é editável/inativável, nunca
   obrigatório.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';

/* origens de custeio conhecidas (contratos do DB_CONTRACT) e categorias */
const CUSTO_ORIGENS_CUSTEIO = ['aplicacao', 'apontamento_insumo', 'rh_producao_item',
    'rh_folha_lancamento', 'irrigacao_consumo', 'maquina_abastecimento',
    'maquina_manutencao', 'apontamento_maquina', 'patrimonio_depreciacao', 'rateio_execucao'];
const CUSTO_CATEGORIAS = ['mao_de_obra', 'insumos', 'maquinas', 'irrigacao', 'mip', 'depreciacao', 'administrativo', 'outros'];

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'metodologia') {
        vero_require('custeio.metodologias.editar');
        $id = vero_int('id');
        $nome = vero_str('nome', 100);
        $ciclo = (string)($_POST['tipo_ciclo'] ?? '');
        if ($nome === null || !in_array($ciclo, VERO_CUSTO_TIPOS_CICLO, true)) {
            vero_flash('erro', 'Nome e tipo de ciclo (anual|perene) são obrigatórios.');
            vero_redirect();
        }
        $dados = [
            'nome' => $nome, 'descricao' => vero_str('descricao', 255), 'tipo_ciclo' => $ciclo,
            'formacao_rateio_safras' => $ciclo === 'perene' ? (vero_int('formacao_rateio_safras') ?: null) : null,
        ];
        if ($id) {
            if (!vero_val("SELECT id FROM agro_custo_metodologias WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t])) {
                vero_flash('erro', 'Metodologia inválida.'); vero_redirect();
            }
            vero_update('agro_custo_metodologias', $id, $dados);
            vero_flash('ok', 'Metodologia atualizada.');
        } else {
            $id = vero_insert('agro_custo_metodologias', $dados + ['padrao' => 0, 'ativo' => 1]);
            vero_flash('ok', 'Metodologia criada — adicione grupos e itens.');
        }
        vero_redirect('?met=' . (int)$id);
    }

    if ($acao === 'metodologia_ativo') {
        vero_require('custeio.metodologias.editar');
        $id = vero_int('id');
        $m = $id ? vero_row("SELECT * FROM agro_custo_metodologias WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if ($m) {
            $novo = (int)$m['ativo'] === 1 ? 0 : 1;
            if ($novo === 0 && (int)vero_val("SELECT COUNT(*) FROM agro_custo_orcamentos
                WHERE tenant_id=:t AND metodologia_id=:m AND status IN ('rascunho','aprovado','em_execucao')",
                [':t' => $t, ':m' => $id]) > 0) {
                vero_flash('erro', 'Há orçamentos abertos usando esta metodologia — feche/cancele antes de inativar.');
            } else {
                vero_update('agro_custo_metodologias', (int)$id, ['ativo' => $novo]);
                vero_flash('ok', $novo ? 'Metodologia reativada.' : 'Metodologia inativada (orçamentos fechados preservados).');
            }
        }
        vero_redirect('?met=' . (int)$id);
    }

    if ($acao === 'grupo') {
        vero_require('custeio.metodologias.editar');
        $id = vero_int('id');
        $metId = vero_int('metodologia_id');
        $nome = vero_str('nome', 100);
        $tipo = (string)($_POST['tipo'] ?? '');
        if (!$metId || $nome === null || !in_array($tipo, VERO_CUSTO_TIPOS_GRUPO, true)) {
            vero_flash('erro', 'Metodologia, nome e tipo (variável|fixo|operacional) são obrigatórios.');
            vero_redirect();
        }
        if (!vero_val("SELECT id FROM agro_custo_metodologias WHERE id=:i AND tenant_id=:t", [':i' => $metId, ':t' => $t])) {
            vero_flash('erro', 'Metodologia inválida.'); vero_redirect();
        }
        $dados = ['nome' => $nome, 'tipo' => $tipo, 'descricao' => vero_str('descricao', 255),
                  'ordem' => vero_int('ordem') ?: 99];
        if ($id) {
            $g = vero_row("SELECT * FROM agro_custo_grupos WHERE id=:i AND tenant_id=:t AND metodologia_id=:m",
                [':i' => $id, ':t' => $t, ':m' => $metId]);
            if (!$g) { vero_flash('erro', 'Grupo inválido.'); vero_redirect(); }
            vero_update('agro_custo_grupos', $id, $dados);
            vero_flash('ok', 'Grupo atualizado.');
        } else {
            vero_insert('agro_custo_grupos', $dados + ['metodologia_id' => $metId, 'ativo' => 1]);
            vero_flash('ok', 'Grupo criado.');
        }
        vero_redirect('?met=' . $metId);
    }

    if ($acao === 'grupo_ativo') {
        vero_require('custeio.metodologias.editar');
        $id = vero_int('id');
        $g = $id ? vero_row("SELECT * FROM agro_custo_grupos WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if ($g) {
            vero_update('agro_custo_grupos', (int)$id, ['ativo' => (int)$g['ativo'] === 1 ? 0 : 1]);
            vero_flash('ok', (int)$g['ativo'] === 1 ? 'Grupo inativado (itens saem do cálculo).' : 'Grupo reativado.');
        }
        vero_redirect('?met=' . (int)($g['metodologia_id'] ?? 0));
    }

    if ($acao === 'item') {
        vero_require('custeio.metodologias.editar');
        $id = vero_int('id');
        $grupoId = vero_int('grupo_id');
        $nome = vero_str('nome', 120);
        $metodo = (string)($_POST['metodo_calculo'] ?? '');
        $origem = (string)($_POST['origem'] ?? '');
        $grupo = $grupoId ? vero_row(
            "SELECT g.*, m.nome AS met_nome FROM agro_custo_grupos g
              JOIN agro_custo_metodologias m ON m.id = g.metodologia_id
             WHERE g.id=:i AND g.tenant_id=:t", [':i' => $grupoId, ':t' => $t]) : null;
        if (!$grupo || $nome === null || !in_array($metodo, VERO_CUSTO_METODOS, true)
            || !in_array($origem, VERO_CUSTO_ORIGENS_ITEM, true)) {
            vero_flash('erro', 'Grupo, nome, método e origem válidos são obrigatórios (listas VERO_CUSTO_*).');
            vero_redirect();
        }
        if ($metodo === 'formula_customizada') { /* fora da v1 (SPEC §3 †) */
            vero_flash('erro', 'Método formula_customizada é fase 4 — fora da v1.');
            vero_redirect();
        }
        $percBase = null; $perc = null;
        if ($metodo === 'percentual') {
            $percBase = (string)($_POST['percentual_base'] ?? '');
            $perc = vero_dec('percentual');
            if (!in_array($percBase, ['grupo', 'total'], true) || $perc === null || $perc <= 0) {
                vero_flash('erro', 'Método percentual exige base (grupo|total) e percentual > 0.');
                vero_redirect();
            }
        }
        /* mapa_realizado: origens/categorias validadas por whitelist; planos = ids do plano de contas do tenant */
        $mapa = null;
        if ($origem === 'custeio') {
            $mOr = array_values(array_intersect((array)($_POST['mapa_origens'] ?? []), CUSTO_ORIGENS_CUSTEIO));
            $mCat = array_values(array_intersect((array)($_POST['mapa_categorias'] ?? []), CUSTO_CATEGORIAS));
            $mPl = [];
            foreach ((array)($_POST['mapa_planos'] ?? []) as $pid) {
                $pid = (int)$pid;
                if ($pid && vero_val("SELECT id FROM plano_contas WHERE id=:i AND tenant_id=:t", [':i' => $pid, ':t' => $t])) $mPl[] = $pid;
            }
            if (!$mOr && !$mCat && !$mPl) {
                vero_flash('erro', 'Item de origem CUSTEIO exige mapa (ao menos uma origem, categoria ou conta) — é ele que deriva o realizado.');
                vero_redirect();
            }
            $mapa = json_encode(['origens' => $mOr, 'categorias' => $mCat, 'planos' => $mPl], JSON_UNESCAPED_UNICODE);
        }
        $dados = [
            'nome' => $nome, 'descricao' => vero_str('descricao', 255),
            'unidade_calculo' => vero_str('unidade_calculo', 20),
            'metodo_calculo' => $metodo, 'origem' => $origem,
            'percentual_base' => $percBase, 'percentual' => $perc,
            'mapa_realizado' => $mapa, 'ordem' => vero_int('ordem') ?: 99,
        ];
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($id) {
                if (!vero_val("SELECT id FROM agro_custo_itens WHERE id=:i AND tenant_id=:t AND grupo_id=:g",
                    [':i' => $id, ':t' => $t, ':g' => $grupoId])) throw new RuntimeException('Item inválido.');
                vero_update('agro_custo_itens', $id, $dados);
            } else {
                $id = vero_insert('agro_custo_itens', $dados + ['grupo_id' => $grupoId, 'ativo' => 1]);
            }
            /* anti-duplicação (DECISIONS 05/07): EXATA envolvendo este item BLOQUEIA */
            $avisos = [];
            foreach (vero_srv_custo_mapa_conflitos((int)$grupo['metodologia_id']) as $c) {
                $envolve = in_array($nome, [$c['item_a'], $c['item_b']], true);
                if ($c['tipo'] === 'exata' && $envolve) {
                    throw new RuntimeException("Mapa IDÊNTICO ao do item \"{$c['item_a']}\"/\"{$c['item_b']}\" — o mesmo lançamento seria contado duas vezes. Ajuste o mapa.");
                }
                if ($c['tipo'] === 'parcial' && $envolve) {
                    $avisos[] = "compartilha origem com \"" . ($c['item_a'] === $nome ? $c['item_b'] : $c['item_a']) . "\"";
                }
            }
            $pdo->commit();
            vero_flash('ok', 'Item salvo.');
            foreach (array_unique($avisos) as $av) {
                vero_flash('aviso', "⚠ Sobreposição PARCIAL de mapa: {$av} — confira no relatório de mapeamento (F2) se é intencional.");
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect('?met=' . (int)$grupo['metodologia_id']);
    }

    if ($acao === 'item_ativo') {
        vero_require('custeio.metodologias.editar');
        $id = vero_int('id');
        $i = $id ? vero_row("SELECT i.*, g.metodologia_id FROM agro_custo_itens i
            JOIN agro_custo_grupos g ON g.id=i.grupo_id WHERE i.id=:i AND i.tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if ($i) {
            vero_update('agro_custo_itens', (int)$id, ['ativo' => (int)$i['ativo'] === 1 ? 0 : 1]);
            vero_flash('ok', (int)$i['ativo'] === 1 ? 'Item inativado.' : 'Item reativado.');
        }
        vero_redirect('?met=' . (int)($i['metodologia_id'] ?? 0));
    }
}

/* ── Dados ── */
$metodologias = vero_rows("SELECT * FROM agro_custo_metodologias WHERE tenant_id=:t ORDER BY ativo DESC, nome", [':t' => $t]);
$fMet = (int)($_GET['met'] ?? 0);
if (!$fMet && $metodologias) $fMet = (int)$metodologias[0]['id'];
$metSel = null;
foreach ($metodologias as $m) if ((int)$m['id'] === $fMet) { $metSel = $m; break; }

$grupos = $fMet ? vero_rows("SELECT * FROM agro_custo_grupos WHERE tenant_id=:t AND metodologia_id=:m ORDER BY ordem, id",
    [':t' => $t, ':m' => $fMet]) : [];
$itensPorGrupo = [];
if ($grupos) {
    foreach (vero_rows(
        "SELECT i.* FROM agro_custo_itens i JOIN agro_custo_grupos g ON g.id = i.grupo_id
          WHERE i.tenant_id = :t AND g.metodologia_id = :m ORDER BY i.ordem, i.id", [':t' => $t, ':m' => $fMet]) as $i) {
        $itensPorGrupo[(int)$i['grupo_id']][] = $i;
    }
}
$conflitos = $fMet ? vero_srv_custo_mapa_conflitos($fMet) : [];
$planos = vero_rows("SELECT id, codigo, nome FROM plano_contas WHERE tenant_id=:t AND ativo=1 AND aceita_lancamento=1 ORDER BY codigo", [':t' => $t]);

$editItem = null;
if (!empty($_GET['editar_item'])) {
    $editItem = vero_row("SELECT i.*, g.metodologia_id FROM agro_custo_itens i
        JOIN agro_custo_grupos g ON g.id=i.grupo_id WHERE i.id=:i AND i.tenant_id=:t",
        [':i' => (int)$_GET['editar_item'], ':t' => $t]);
}
$mapaEdit = $editItem ? (json_decode((string)($editItem['mapa_realizado'] ?? ''), true) ?: []) : [];

$GUARD      = ['macro' => 'custos', 'micro' => 'metodologias'];
$PAGE_VIEW  = 'custos_metodologias';
$PAGE_TITLE = 'Metodologias de Custo';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
$podeEditar = vero_can('custeio.metodologias.editar');
$rotCat = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Metodologias de Custo de Produção',
      'Grupos e itens com mapa do realizado — o custeio existente alimenta, nada é digitado duas vezes', null) ?>

  <?php
  $nGrupos = count($grupos);
  $nItens  = 0; foreach ($itensPorGrupo as $__gi) { $nItens += count($__gi); }
  $nConf   = count($conflitos);
  ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <label style="font-size:12px;font-weight:600;color:#4A4034;margin:0">Metodologia</label>
      <form method="get" style="display:flex;gap:8px;align-items:center;margin:0">
        <select name="met" onchange="this.form.submit()">
          <?php foreach ($metodologias as $m): ?>
            <option value="<?= (int)$m['id'] ?>"<?= $fMet === (int)$m['id'] ? ' selected' : '' ?>>
              <?= h($m['nome']) ?> (<?= h((string)$m['tipo_ciclo']) ?>)<?= (int)$m['ativo'] ? '' : ' — inativa' ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($metSel): ?>
        <span class="vbadge <?= (int)$metSel['ativo'] ? 'vb-ok' : 'vb-off' ?>"><?= (int)$metSel['ativo'] ? 'Ativa' : 'Inativa' ?></span>
        <span class="vhint"><?= h($metSel['descricao'] ?? '') ?>
          <?= $metSel['tipo_ciclo'] === 'perene' && $metSel['formacao_rateio_safras']
              ? ' · formação rateada em ' . (int)$metSel['formacao_rateio_safras'] . ' safras' : '' ?></span>
      <?php endif; ?>
      <span style="flex:1"></span>
      <?php if ($podeEditar && $metSel): ?>
      <form method="post" data-confirm="<?= (int)$metSel['ativo'] ? 'Inativar' : 'Reativar' ?> esta metodologia?"<?= (int)$metSel['ativo'] ? ' data-confirm-danger' : '' ?> data-confirm-ok="<?= (int)$metSel['ativo'] ? 'Inativar' : 'Reativar' ?>" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="metodologia_ativo">
        <input type="hidden" name="id" value="<?= (int)$metSel['id'] ?>">
        <button class="vbtn vbtn-ghost vbtn-sm" type="submit"><?= (int)$metSel['ativo'] ? 'Inativar' : 'Reativar' ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php if ($metSel): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Ciclo</span><strong><?= h(ucfirst((string)$metSel['tipo_ciclo'])) ?></strong></div>
      <div class="vkpi"><span class="vhint">Grupos</span><strong class="vnum"><?= $nGrupos ?></strong></div>
      <div class="vkpi"><span class="vhint">Itens</span><strong class="vnum"><?= $nItens ?></strong></div>
      <div class="vkpi"><span class="vhint">Sobreposições de mapa</span><strong class="vnum" style="color:<?= $nConf ? '#B23A2E' : '#0E7E72' ?>"><?= $nConf ?></strong></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($conflitos): ?>
  <div class="vcard" style="margin-bottom:14px;border-color:#E4C4BC;background:#FBF3F0">
    <div class="vtoolbar" style="border-bottom-color:#E9D4CD"><strong style="color:#9A3B2A">⚠ Sobreposições de mapa do realizado</strong>
      <span class="vhint">um lançamento de custeio deve ser capturado por UM item — EXATA gera dupla contagem; parcial é apenas aviso</span></div>
    <div style="padding:10px 14px">
      <?php foreach ($conflitos as $c): ?>
        <div class="vhint" style="color:<?= $c['tipo'] === 'exata' ? '#b3261e' : '#B07A1C' ?>;font-size:12.5px;margin:0 0 4px">
          ⚠ Sobreposição <?= $c['tipo'] === 'exata' ? 'EXATA (dupla contagem!)' : 'parcial' ?>:
          <?= h($c['item_a']) ?> × <?= h($c['item_b']) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($podeEditar): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Nova metodologia</strong>
      <span class="vhint">o ponto de partida: crie a metodologia e depois adicione grupos e itens</span></div>
    <form class="vform" method="post" style="padding:12px 14px;display:flex;gap:12px;flex-wrap:wrap;align-items:end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="metodologia">
      <div class="vfield" style="flex:2 1 200px"><label>Nome</label><input type="text" name="nome" placeholder="Ex.: Uva de mesa — perene" required></div>
      <div class="vfield" style="flex:1 1 140px"><label>Ciclo</label><select name="tipo_ciclo">
        <?php foreach (VERO_CUSTO_TIPOS_CICLO as $tc): ?><option value="<?= $tc ?>"><?= h(ucfirst($tc)) ?></option><?php endforeach; ?>
      </select></div>
      <div class="vfield" style="flex:0 1 170px"><label>Rateio formação (se perene)</label><input type="text" name="formacao_rateio_safras" placeholder="nº de safras"></div>
      <div class="vfield" style="flex:2 1 200px"><label>Descrição</label><input type="text" name="descricao" placeholder="opcional"></div>
      <button class="vbtn vbtn-primary" type="submit">Criar</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($metSel && $grupos): ?>
    <div style="display:flex;align-items:baseline;gap:8px;margin:4px 2px 10px">
      <strong style="font-size:14px;color:#1E1610">Grupos e itens</strong>
      <span class="vhint">estrutura de custo da metodologia selecionada</span>
    </div>
  <?php endif; ?>

  <?php foreach ($grupos as $g): $itens = $itensPorGrupo[(int)$g['id']] ?? []; ?>
  <div class="vcard" style="margin-bottom:14px<?= (int)$g['ativo'] ? '' : ';opacity:.55' ?>">
    <div class="vtoolbar">
      <strong><?= (int)$g['ordem'] ?>. <?= h($g['nome']) ?></strong>
      <span class="vbadge <?= ['variavel' => 'vb-ok', 'fixo' => 'vb-info', 'operacional' => 'vb-warn'][$g['tipo']] ?? 'vb-off' ?>"><?= h(ucfirst((string)$g['tipo'])) ?></span>
      <?php if (!$g['ativo']): ?><span class="vbadge vb-off">Inativo</span><?php endif; ?>
      <span class="vhint"><?= count($itens) ?> item(ns)</span>
      <span style="flex:1"></span>
      <?php if ($podeEditar): ?>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="grupo_ativo"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
        <button class="vbtn vbtn-ghost vbtn-sm" type="submit"><?= (int)$g['ativo'] ? 'Inativar' : 'Reativar' ?></button></form>
      <?php endif; ?>
    </div>
    <?php if (!$itens): ?><div class="vempty">Sem itens neste grupo.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th style="width:40px">Ord.</th><th>Item</th><th>Método</th><th>Origem</th>
        <th>Mapa do realizado</th><th>Situação</th><th style="text-align:right">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($itens as $i):
          $mp = json_decode((string)($i['mapa_realizado'] ?? ''), true) ?: []; ?>
        <tr<?= (int)$i['ativo'] ? '' : ' style="opacity:.5"' ?>>
          <td class="vnum"><?= (int)$i['ordem'] ?></td>
          <td><strong><?= h($i['nome']) ?></strong>
            <?= $i['unidade_calculo'] ? '<span class="vhint">(' . h((string)$i['unidade_calculo']) . ')</span>' : '' ?></td>
          <td><span class="vhint"><?= h((string)$i['metodo_calculo']) ?><?=
            $i['metodo_calculo'] === 'percentual' ? ' ' . numFmt((float)$i['percentual'], 2) . '% do ' . h((string)$i['percentual_base']) : '' ?></span></td>
          <td><?= h((string)$i['origem']) ?></td>
          <td class="vhint" style="font-size:.78rem"><?php
            $partes = [];
            if (!empty($mp['origens'])) $partes[] = 'origens: ' . implode(', ', $mp['origens']);
            if (!empty($mp['categorias'])) $partes[] = 'categorias: ' . implode(', ', array_map($rotCat, $mp['categorias']));
            if (!empty($mp['planos'])) $partes[] = 'contas: ' . count($mp['planos']);
            echo $partes ? h(implode(' · ', $partes)) : '—';
          ?></td>
          <td><?= (int)$i['ativo'] ? '<span class="vbadge vb-ok">Ativo</span>' : '<span class="vbadge vb-off">Inativo</span>' ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?>
              <?= vero_btn_icone(vero_ico_lapis(), 'Editar', '', '?met=' . rawurlencode((string)$fMet) . '&editar_item=' . (int)$i['id']) ?>
              <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="item_ativo"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                <button class="vicon <?= (int)$i['ativo'] ? 'vicon-del' : 'vicon-acao' ?>" type="submit" title="<?= (int)$i['ativo'] ? 'Inativar' : 'Reativar' ?>" aria-label="<?= (int)$i['ativo'] ? 'Inativar' : 'Reativar' ?>"><?= (int)$i['ativo'] ? vero_ico_lixeira() : vero_ico_check() ?></button></form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <?php if ($podeEditar && $fMet): ?>
  <div style="display:flex;align-items:baseline;gap:8px;margin:4px 2px 10px">
    <strong style="font-size:14px;color:#1E1610">Adicionar à metodologia</strong>
    <span class="vhint">crie um grupo e depois inclua itens nele</span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Novo grupo</strong></div>
      <form class="vform" method="post" style="padding:10px 14px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="grupo">
        <input type="hidden" name="metodologia_id" value="<?= $fMet ?>">
        <div class="vgrid">
          <?= vero_f_text('nome', 'Nome *', '', true) ?>
          <div class="vfield"><label>Tipo *</label><select name="tipo">
            <?php foreach (VERO_CUSTO_TIPOS_GRUPO as $tg): ?><option value="<?= $tg ?>"><?= h(ucfirst($tg)) ?></option><?php endforeach; ?>
          </select></div>
          <?= vero_f_text('ordem', 'Ordem', '') ?>
          <?= vero_f_text('descricao', 'Descrição', '') ?>
        </div>
        <div class="vform-actions"><button class="vbtn vbtn-primary" type="submit">Criar grupo</button></div>
      </form>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong><?= $editItem ? 'Editar item — ' . h($editItem['nome']) : 'Novo item' ?></strong>
        <?php if ($editItem): ?><a class="vbtn vbtn-ghost vbtn-sm" href="?met=<?= $fMet ?>">cancelar edição</a><?php endif; ?></div>
      <form class="vform" method="post" style="padding:10px 14px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="item">
        <input type="hidden" name="id" value="<?= $editItem ? (int)$editItem['id'] : '' ?>">
        <div class="vgrid">
          <div class="vfield"><label>Grupo *</label><select name="grupo_id" required>
            <?php foreach ($grupos as $g): if (!(int)$g['ativo']) continue; ?>
              <option value="<?= (int)$g['id'] ?>"<?= $editItem && (int)$editItem['grupo_id'] === (int)$g['id'] ? ' selected' : '' ?>>
                <?= h($g['nome']) ?></option>
            <?php endforeach; ?>
          </select></div>
          <?= vero_f_text('nome', 'Nome *', $editItem['nome'] ?? '', true) ?>
          <div class="vfield"><label>Método de cálculo *</label><select name="metodo_calculo" id="f-metodo">
            <?php foreach (VERO_CUSTO_METODOS as $mc): ?>
              <option value="<?= $mc ?>"<?= ($editItem['metodo_calculo'] ?? '') === $mc ? ' selected' : '' ?>><?= h($mc) ?></option>
            <?php endforeach; ?>
          </select></div>
          <div class="vfield"><label>Origem *</label><select name="origem">
            <?php foreach (VERO_CUSTO_ORIGENS_ITEM as $oi): ?>
              <option value="<?= $oi ?>"<?= ($editItem['origem'] ?? 'custeio') === $oi ? ' selected' : '' ?>><?= h($oi) ?></option>
            <?php endforeach; ?>
          </select></div>
          <?= vero_f_text('unidade_calculo', 'Unidade (ex.: L/ha, h/ha)', $editItem['unidade_calculo'] ?? '') ?>
          <?= vero_f_text('ordem', 'Ordem', $editItem ? (string)(int)$editItem['ordem'] : '') ?>
          <div class="vfield"><label>Percentual (se método percentual)</label>
            <input type="text" name="percentual" value="<?= $editItem && $editItem['percentual'] !== null ? numFmt((float)$editItem['percentual'], 2) : '' ?>"></div>
          <div class="vfield"><label>Base do percentual</label><select name="percentual_base">
            <option value="">—</option>
            <option value="grupo"<?= ($editItem['percentual_base'] ?? '') === 'grupo' ? ' selected' : '' ?>>grupo</option>
            <option value="total"<?= ($editItem['percentual_base'] ?? '') === 'total' ? ' selected' : '' ?>>total</option>
          </select></div>
          <div class="full"><?= vero_f_text('descricao', 'Descrição', $editItem['descricao'] ?? '') ?></div>
        </div>
        <div class="vfield" style="margin-top:8px"><label>Mapa do realizado (obrigatório se origem = custeio)</label>
          <div class="vhint">Origens de custeio:</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php foreach (CUSTO_ORIGENS_CUSTEIO as $oc): ?>
              <label style="display:inline-flex;gap:4px;font-size:.82rem"><input type="checkbox" name="mapa_origens[]" value="<?= $oc ?>"
                <?= in_array($oc, (array)($mapaEdit['origens'] ?? []), true) ? ' checked' : '' ?>> <?= h($oc) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="vhint" style="margin-top:6px">Categorias:</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php foreach (CUSTO_CATEGORIAS as $cc): ?>
              <label style="display:inline-flex;gap:4px;font-size:.82rem"><input type="checkbox" name="mapa_categorias[]" value="<?= $cc ?>"
                <?= in_array($cc, (array)($mapaEdit['categorias'] ?? []), true) ? ' checked' : '' ?>> <?= h($rotCat($cc)) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="vhint" style="margin-top:6px">Contas do plano (Ctrl+clique p/ múltiplas):</div>
          <select name="mapa_planos[]" multiple size="4" style="min-width:280px">
            <?php foreach ($planos as $pl): ?>
              <option value="<?= (int)$pl['id'] ?>"<?= in_array((int)$pl['id'], array_map('intval', (array)($mapaEdit['planos'] ?? [])), true) ? ' selected' : '' ?>>
                <?= h($pl['codigo']) ?> — <?= h($pl['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vhint" style="margin-top:8px">
          Anti-duplicação: mapa IDÊNTICO ao de outro item ativo BLOQUEIA a gravação; sobreposição
          parcial gera aviso (um lançamento de custeio deve ser capturado por UM item).
        </div>
        <div class="vform-actions"><button class="vbtn vbtn-primary" type="submit">Salvar item</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
