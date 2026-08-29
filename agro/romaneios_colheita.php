<?php
/* ============================================================
   VERO — Agrícola / Romaneios de Colheita  (CRUD real)
   Substitui o mock. Rota: /agro/romaneios_colheita.php
   Guard: agricola.romaneios_colheita
   Cargas de colheita (colheita_cargas): romaneio, válvula, peso —
   trilha do que saiu do campo, ligada ao registro de colheita.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php'; /* A-01: pack → estoque */

const T = 'colheita_cargas';

/* destino da produção (VARCHAR + whitelist — DB-20) */
const DESTINOS = [
    'venda'       => 'Venda',
    'packing'     => 'Packing house',
    'armazenagem' => 'Armazenagem',
    'descarte'    => 'Descarte',
    'doacao'      => 'Doação',
];
/* classificação tipada — mesmas categorias de colheita_classificacoes */
const CLASSIFICACOES = ['Premium' => 'Premium', 'CAT 1' => 'CAT 1', 'CAT 2' => 'CAT 2', 'CAT 3' => 'CAT 3', 'Perdidos' => 'Perdidos'];
/* Apontamento manual por unidade (P-07/P-08): a uva é comercializada por
   PALETE (~110 caixas); paletes não se misturam (um por classificação). Para
   quem não usa o pack automático, aponta a quantidade por unidade na carga. */
const UNIDADES_APONT = ['caixa' => 'Caixa', 'palete' => 'Palete', 'cumbuca' => 'Cumbuca'];
const CAIXAS_POR_PALETE_PADRAO = 110;

/**
 * Apontamento manual formatado com a conversão caixa↔palete (110 cx = 1 palete
 * por default, ou o fator da carga). Cumbuca não converte para palete.
 * Retorna HTML pronto para a célula da tabela.
 */
function rc_apont_html(?string $unidade, $qtd, $caixasPorPalete): string
{
    if ($unidade === null || $qtd === null || (float)$qtd <= 0 || !isset(UNIDADES_APONT[$unidade])) {
        return '<span class="vhint">—</span>';
    }
    $q     = (float)$qtd;
    $fator = ($caixasPorPalete !== null && (int)$caixasPorPalete > 0)
        ? (int)$caixasPorPalete : CAIXAS_POR_PALETE_PADRAO;
    $qFmt  = rtrim(rtrim(numFmt($q, 3), '0'), ',');
    $html  = '<strong>' . h($qFmt) . '</strong> ' . h(UNIDADES_APONT[$unidade]) . ($q != 1.0 ? 's' : '');
    if ($unidade === 'caixa') {
        $html .= '<div class="vhint">≈ ' . h(numFmt($q / $fator, 2)) . ' palete(s) · ' . $fator . ' cx/pal</div>';
    } elseif ($unidade === 'palete') {
        $html .= '<div class="vhint">= ' . h(numFmt($q * $fator, 0)) . ' cx · ' . $fator . ' cx/pal</div>';
    }
    return $html;
}

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.romaneios_colheita.editar');
        $id     = vero_int('id');
        $numero = vero_str('romaneio', 40);
        $peso   = vero_dec('peso_kg');
        $talhao = vero_int('talhao_id');
        if ($numero === null || $peso === null || $peso <= 0 || !$talhao) {
            vero_flash('erro', 'Romaneio, válvula e peso (maior que zero) são obrigatórios.');
            vero_redirect();
        }
        /* QA-010: valida as FKs ANTES do insert — id inválido (ex.: colheita
           apagada entre o GET e o POST) vira flash orientativo, não 500 */
        if (!vero_val("SELECT id FROM agro_talhoes WHERE id=:i AND tenant_id=:t", [':i' => $talhao, ':t' => $t])) {
            vero_flash('erro', 'Válvula inválido — recarregue a página e selecione novamente.');
            vero_redirect();
        }
        $registroId = vero_int('registro_id') ?: null;
        if ($registroId && !vero_val("SELECT id FROM colheita_registros WHERE id=:i AND tenant_id=:t",
                [':i' => $registroId, ':t' => $t])) {
            vero_flash('erro', 'O registro de colheita selecionado não existe mais (pode ter sido excluído) — recarregue a página e escolha outro, ou salve a carga sem vínculo.');
            vero_redirect();
        }
        /* Safra do lote (rastreabilidade — pedido 23/07): explícita e derivada da
           válvula via agro_safra_talhoes. Valida que a safra escolhida É desta
           válvula; a explícita vence, senão herda do registro de colheita. */
        $safraTalhaoId = vero_int('safra_talhao_id') ?: null;
        if ($safraTalhaoId && !vero_val(
                "SELECT id FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t AND talhao_id=:tal",
                [':i' => $safraTalhaoId, ':t' => $t, ':tal' => (int)$talhao])) {
            vero_flash('erro', 'A safra selecionada não pertence a esta válvula — recarregue a página e escolha novamente.');
            vero_redirect();
        }
        $destino = vero_str('destino', 20);
        if ($destino !== null && !isset(DESTINOS[$destino])) $destino = null;
        /* classificação tipada (aceita valor legado texto-livre já gravado) */
        $classif = vero_str('classificacao', 40);

        /* Apontamento manual por unidade (P-07/P-08): unidade + quantidade +
           fator caixas→palete. Whitelist ou NULL; quantidade > 0 ou NULL. */
        $unidadeApont = vero_str('unidade_apont', 20);
        if ($unidadeApont !== null && !isset(UNIDADES_APONT[$unidadeApont])) $unidadeApont = null;
        $qtdApont = vero_dec('qtd_apont');
        if ($qtdApont !== null && $qtdApont <= 0) $qtdApont = null;
        $caixasPorPalete = vero_int('caixas_por_palete');
        if ($caixasPorPalete !== null && $caixasPorPalete <= 0) $caixasPorPalete = null;
        /* sem unidade não há apontamento; sem apontamento não guarda fator */
        if ($unidadeApont === null) { $qtdApont = null; $caixasPorPalete = null; }

        $data = [
            'romaneio'      => $numero,
            'talhao_id'     => (int)$talhao,
            'registro_id'   => $registroId,
            'safra_talhao_id' => $safraTalhaoId ?: ($registroId ? (vero_val(
                "SELECT safra_talhao_id FROM colheita_registros WHERE id=:i AND tenant_id=:t",
                [':i' => $registroId, ':t' => $t]) ?: null) : null),
            'data_carga'    => vero_date('data_carga') ?? date('Y-m-d'),
            'peso_kg'       => $peso,
            'classificacao' => $classif,
            'unidade_apont' => $unidadeApont,
            'qtd_apont'     => $qtdApont,
            'caixas_por_palete' => $caixasPorPalete,
            'destino'       => $destino,
            'origem'        => 'web',
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Carga {$numero} atualizada."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Carga {$numero} registrada."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('agro.romaneios_colheita.excluir');
        $id = vero_int('id');
        if ($id) {
            vero_pdo()->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")->execute([$t, (int)$id]);
            vero_flash('ok', 'Carga excluída.');
        }
        vero_redirect();
    }

    /* A-01: pack → estoque — posta a carga apontada como produto acabado (SKU).
       Um lote PACK- por carga (1 classificação; paletes não se misturam). */
    if ($acao === 'pack_postar') {
        vero_require('agro.romaneios_colheita.editar');
        $id    = vero_int('id');
        $skuId = vero_int('sku_id');
        if (!$id || !$skuId) {
            vero_flash('erro', 'Selecione o SKU de produto acabado para postar a carga no estoque.');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $r = vero_srv_pack_confirmar_entrada((int)$id, (int)$skuId);
            $pdo->commit();
            if ($r['ja_existia']) {
                vero_flash('aviso', 'Esta carga JÁ tem entrada ativa no estoque: lote ' . $r['lote_codigo']
                    . '. Nada foi duplicado.');
            } else {
                vero_flash('ok', 'Carga postada no estoque — lote ' . $r['lote_codigo'] . ': '
                    . rtrim(rtrim(numFmt($r['qtd'], 3), '0'), ',') . ' ' . $r['unidade'] . '(s) de produto acabado.');
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Não foi possível postar no estoque: ' . h($e->getMessage()));
        }
        vero_redirect();
    }

    /* A-01: estorna a entrada de pack ativa (devolve o saldo, marca o lote PACK- estornado). */
    if ($acao === 'pack_estornar') {
        vero_require('agro.romaneios_colheita.editar');
        $id = vero_int('id');
        if ($id) {
            $pdo = vero_pdo();
            $pdo->beginTransaction();
            try {
                $ok = vero_srv_pack_estornar_entrada((int)$id);
                $pdo->commit();
                vero_flash($ok ? 'ok' : 'aviso', $ok
                    ? 'Entrada de pack ESTORNADA — saldo devolvido e lote PACK- marcado como estornado; a carga permanece.'
                    : 'Esta carga não tem entrada ativa de pack no estoque.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                vero_flash('erro', 'Não foi possível estornar: ' . h($e->getMessage()));
            }
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT c.*, tl.codigo AS talhao, fz.nome AS fazenda, cr.data_colheita,
            sf.identificacao AS safra
       FROM " . T . " c
       LEFT JOIN agro_talhoes tl ON tl.id = c.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
       LEFT JOIN colheita_registros cr ON cr.id = c.registro_id
       LEFT JOIN agro_safra_talhoes st ON st.id = c.safra_talhao_id AND st.tenant_id = c.tenant_id
       LEFT JOIN agro_safras sf ON sf.id = st.safra_id
      WHERE c.tenant_id = :t
      ORDER BY c.data_carga DESC, c.id DESC LIMIT 100", [':t' => $t]);
$totPeso = array_sum(array_map(static fn($r) => (float)$r['peso_kg'], $rows));

/* A-01: pack → estoque. SKUs postáveis (com item de estoque) + status de pack por
   carga. Guarda contra schema sem o módulo packing (ph_skus pode não existir). */
$temSkus = (bool)vero_val(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ph_skus' LIMIT 1");
$skusPost = [];
$packPorCarga = [];
if ($temSkus) {
    foreach (vero_rows(
        "SELECT id, codigo, descricao, unidade_comercial FROM ph_skus
          WHERE tenant_id = :t AND ativo = 1 AND produto_estoque_id IS NOT NULL
          ORDER BY codigo", [':t' => $t]) as $s) {
        $skusPost[(int)$s['id']] = trim((string)$s['codigo'] . ' — ' . (string)$s['descricao'])
            . ($s['unidade_comercial'] ? ' [' . $s['unidade_comercial'] . ']' : '');
    }
    $cargaIds = array_map(static fn($r) => (int)$r['id'], $rows);
    if ($cargaIds) {
        $in = implode(',', $cargaIds);
        foreach (vero_rows(
            "SELECT m.origem_id, m.quantidade, l.codigo_lote, l.status AS lote_status
               FROM estoque_movimentacoes m
               LEFT JOIN estoque_lotes l ON l.id = m.lote_id
              WHERE m.tenant_id = :t AND m.origem_tipo = 'ph_carga'
                AND m.origem_id IN ($in) AND m.tipo = 'entrada' AND m.estornado_em IS NULL",
            [':t' => $t]) as $pk) {
            $packPorCarga[(int)$pk['origem_id']] = $pk;
        }
    }
}

$talhoes = vero_rows(
    "SELECT t.id, t.codigo, f.nome AS fazenda FROM agro_talhoes t
      LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
     WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo", [':t' => $t]);
$talhaoOpts = [];
foreach ($talhoes as $tl) $talhaoOpts[(int)$tl['id']] = ($tl['fazenda'] ? $tl['fazenda'] . ' — ' : '') . $tl['codigo'];
$registros = vero_rows(
    "SELECT cr.id, cr.data_colheita, tl.codigo FROM colheita_registros cr
      LEFT JOIN agro_talhoes tl ON tl.id = cr.talhao_id
     WHERE cr.tenant_id = :t ORDER BY cr.data_colheita DESC LIMIT 50", [':t' => $t]);
$regOpts = [];
foreach ($registros as $rg) {
    $regOpts[(int)$rg['id']] = date('d/m/Y', strtotime((string)$rg['data_colheita'])) . ' — ' . ($rg['codigo'] ?? 'válvula');
}

/* Safras por válvula (rastreabilidade do lote): a safra é escolhida a partir
   da válvula selecionada. Uma válvula pode ter mais de uma safra no tempo —
   a ativa vem primeiro. Vira mapa JS p/ o select dependente do form. */
$safraTalRows = vero_rows(
    "SELECT st.id, st.talhao_id, s.identificacao, s.status
       FROM agro_safra_talhoes st
       JOIN agro_safras s ON s.id = st.safra_id AND s.tenant_id = st.tenant_id
      WHERE st.tenant_id = :t
      ORDER BY (s.status = 'ativa') DESC, s.data_inicio DESC, s.identificacao", [':t' => $t]);
$safrasPorValvula = [];
foreach ($safraTalRows as $s) {
    $safrasPorValvula[(int)$s['talhao_id']][] = [
        'id'    => (int)$s['id'],
        'label' => (string)$s['identificacao'] . ($s['status'] ? ' · ' . $s['status'] : ''),
    ];
}

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t", [':id' => (int)$_GET['editar'], ':t' => $t]);
}

$GUARD      = ['macro' => 'agricola', 'micro' => 'romaneios_colheita'];
$PAGE_VIEW  = 'agricola_romaneios_colheita';
$PAGE_TITLE = 'Romaneios de Colheita';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.romaneios_colheita.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Romaneios de Colheita', 'Cargas que saíram do campo — trilha por válvula, ligável ao registro de colheita',
        $podeEditar ? '+ Nova carga' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <span class="vsub"><?= count($rows) ?> carga(s)</span>
      <span class="vsub">total <strong class="vnum"><?= numFmt($totPeso, 0) ?> kg</strong></span>
    </div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma carga de colheita registrada.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Romaneio</th><th>Data</th><th>Válvula</th><th>Safra</th><th>Classificação</th>
        <th>Apontamento</th>
        <?php if ($temSkus): ?><th>Estoque (pack)</th><?php endif; ?>
        <th>Destino</th><th>Registro de colheita</th>
        <th class="num">Peso (kg)</th>
        <th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong class="vnum"><?= h($r['romaneio']) ?></strong></td>
          <td class="vnum"><?= $r['data_carga'] ? date('d/m/Y', strtotime((string)$r['data_carga'])) : '—' ?></td>
          <td><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td><?= $r['safra'] ? '<span style="font-weight:600;color:#1E6B34">' . h((string)$r['safra']) . '</span>' : '<span class="vhint">—</span>' ?></td>
          <td><?= $r['classificacao'] ? h((string)$r['classificacao']) : '<span class="vhint">—</span>' ?></td>
          <td><?= rc_apont_html($r['unidade_apont'] ?? null, $r['qtd_apont'] ?? null, $r['caixas_por_palete'] ?? null) ?></td>
          <?php if ($temSkus):
            $cid      = (int)$r['id'];
            $temApont = ($r['unidade_apont'] ?? null) !== null && (float)($r['qtd_apont'] ?? 0) > 0;
            $pk       = $packPorCarga[$cid] ?? null;
          ?>
          <td>
            <?php if ($pk !== null): ?>
              <span class="vbadge vb-ok" title="Produto acabado no estoque"><?= h((string)($pk['codigo_lote'] ?? 'PACK')) ?></span>
              <div class="vhint"><?= rtrim(rtrim(numFmt((float)$pk['quantidade'], 3), '0'), ',') ?> un.</div>
              <?php if ($podeEditar): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Estornar a entrada de pack desta carga? O saldo será devolvido.')">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                  <input type="hidden" name="acao" value="pack_estornar">
                  <input type="hidden" name="id" value="<?= $cid ?>">
                  <button type="submit" class="vbtn vbtn-ghost vbtn-sm" style="margin-top:4px">Estornar</button>
                </form>
              <?php endif; ?>
            <?php elseif (!$temApont): ?>
              <span class="vhint">requer apontamento</span>
            <?php elseif ($podeEditar && $skusPost): ?>
              <form method="post" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="pack_postar">
                <input type="hidden" name="id" value="<?= $cid ?>">
                <select name="sku_id" required style="max-width:180px">
                  <option value="">— SKU —</option>
                  <?php foreach ($skusPost as $sid => $slbl): ?>
                    <option value="<?= (int)$sid ?>"><?= h($slbl) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="vbtn vbtn-primary vbtn-sm">Postar</button>
              </form>
            <?php elseif ($podeEditar): ?>
              <span class="vhint">nenhum SKU com item de estoque —
                <a href="<?= h(BIOS_BASE) ?>/packing/skus.php">cadastre</a></span>
            <?php else: ?>
              <span class="vhint">—</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td><?= $r['destino'] && isset(DESTINOS[$r['destino']])
                ? '<span style="font-weight:600;color:' . ($r['destino'] === 'descarte' ? '#9A8C78' : '#1E6B34') . '">' . DESTINOS[$r['destino']] . '</span>'
                : '<span class="vhint">—</span>' ?></td>
          <td class="vnum"><?= $r['data_colheita'] ? date('d/m/Y', strtotime((string)$r['data_colheita'])) : '<span class="vhint">—</span>' ?></td>
          <td class="num"><strong><?= numFmt((float)$r['peso_kg'], 0) ?></strong></td>
          <td class="num"><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('agro.romaneios_colheita.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir esta carga?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar carga' : 'Nova carga de colheita' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('romaneio', 'Nº do romaneio', $edit['romaneio'] ?? '', true, 'Ex.: RC2026-0001') ?>
        <?= vero_f_select('talhao_id', 'Válvula', $talhaoOpts, $edit['talhao_id'] ?? '', true, 'Selecione…') ?>
        <div class="vfield">
          <label>Safra <span class="vhint">— da válvula (rastreabilidade do lote)</span></label>
          <select name="safra_talhao_id" id="rc-safra">
            <option value="">— Selecione a válvula primeiro —</option>
          </select>
        </div>
        <?= vero_f_text('peso_kg', 'Peso (kg)', $edit ? numFmt((float)$edit['peso_kg'], 0) : '', true) ?>
        <div class="vfield">
          <label>Data da carga</label>
          <input type="date" name="data_carga" value="<?= h($edit['data_carga'] ?? date('Y-m-d')) ?>">
        </div>
        <?php
          /* classificação tipada; valor legado fora da lista continua selecionável */
          $classifOpts = CLASSIFICACOES;
          if ($edit && $edit['classificacao'] !== null && $edit['classificacao'] !== ''
              && !isset($classifOpts[$edit['classificacao']])) {
              $classifOpts = [$edit['classificacao'] => $edit['classificacao'] . ' (legado)'] + $classifOpts;
          }
        ?>
        <?= vero_f_select('classificacao', 'Classificação', $classifOpts, $edit['classificacao'] ?? null, false, '— Não classificada —') ?>
        <?= vero_f_select('destino', 'Destino da produção', DESTINOS, $edit['destino'] ?? null, false, '— Não informado —') ?>

        <div class="full"><div class="vhint" style="font-weight:600;margin-top:4px">Apontamento manual (opcional) — quantidade da classificação acima por unidade. Paletes não se misturam: 1 palete por classificação.</div></div>
        <?= vero_f_select('unidade_apont', 'Unidade', UNIDADES_APONT, $edit['unidade_apont'] ?? null, false, '— Só por peso —') ?>
        <?= vero_f_text('qtd_apont', 'Quantidade', isset($edit['qtd_apont']) && $edit['qtd_apont'] !== null ? rtrim(rtrim(numFmt((float)$edit['qtd_apont'], 3), '0'), ',') : '', false, 'Ex.: 30', 'number') ?>
        <?= vero_f_text('caixas_por_palete', 'Caixas por palete', isset($edit['caixas_por_palete']) && $edit['caixas_por_palete'] !== null ? (string)(int)$edit['caixas_por_palete'] : (string)CAIXAS_POR_PALETE_PADRAO, false, 'Conversão caixa → palete (default 110)', 'number') ?>
        <?= vero_f_select('registro_id', 'Registro de colheita', ['' => 'Nenhum'] + $regOpts, $edit['registro_id'] ?? '', false, '') ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<script>
/* Safra dependente da válvula: ao escolher a válvula, lista as safras dela
   (agro_safra_talhoes). A ativa vem primeiro. Rastreia o lote pela safra. */
(function () {
  var SAFRAS = <?= jsvar($safrasPorValvula) ?>;
  var EDIT_SAFRA = <?= (int)($edit['safra_talhao_id'] ?? 0) ?>;
  var sel = document.getElementById('rc-safra');
  var val = document.querySelector('#vm-form [name="talhao_id"]');
  if (!sel || !val) return;
  function popula(talhaoId, selId) {
    var lista = SAFRAS[talhaoId] || [];
    if (!lista.length) {
      sel.innerHTML = '<option value="">— Sem safra cadastrada nesta válvula —</option>';
      return;
    }
    sel.innerHTML = '<option value="">— Selecione a safra —</option>' +
      lista.map(function (s) {
        return '<option value="' + s.id + '"' + (String(s.id) === String(selId) ? ' selected' : '') +
               '>' + esc(s.label) + '</option>';
      }).join('');
  }
  val.addEventListener('change', function () { popula(val.value, 0); });
  if (val.value) popula(val.value, EDIT_SAFRA);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
