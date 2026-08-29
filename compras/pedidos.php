<?php
/* ============================================================
   VERO — Compras / Pedidos de Compra  (tela real)
   Substitui o mock. Rota: /compras/pedidos.php
   Guard: compras.pedidos_compra
   Tabelas: compras_pedidos + compras_pedido_itens + compras_aprovacoes
   Fluxo: rascunho → (enviar) aprovação → aprovado → recebido
   (parcial/total via tela Recebimentos) | cancelado.
   Pode nascer de uma solicitação (?novo=1&solicitacao=ID).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_helpers.php';

const T = 'compras_pedidos';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('compras.pedidos_compra.editar');

        $id           = vero_int('id');
        $fornecedorId = vero_int('fornecedor_id');
        $data         = vero_date('data_pedido');
        $solicitacaoId = vero_int('solicitacao_id');
        $cotacaoId    = vero_int('cotacao_id');

        /* DB-08: prazo, frete, condição e vínculo de custo */
        $entregaPrev = vero_date('data_entrega_prevista');
        $freteValor  = vero_dec('frete_valor');
        $condicao    = vero_str('condicao_pagamento', 60);
        $safraTalhaoId = vero_int('safra_talhao_id');
        if ($safraTalhaoId) {
            $ok = vero_val("SELECT id FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t",
                [':i' => $safraTalhaoId, ':t' => vero_tenant()]);
            if (!$ok) $safraTalhaoId = null;
        }
        $centroCustoId = vero_int('centro_custo_id');
        if ($centroCustoId) {
            $ok = vero_val("SELECT id FROM centros_custo WHERE id=:i AND tenant_id=:t",
                [':i' => $centroCustoId, ':t' => vero_tenant()]);
            if (!$ok) $centroCustoId = null;
        }
        if ($cotacaoId) {
            $ok = vero_val("SELECT id FROM compras_cotacoes WHERE id=:i AND tenant_id=:t AND status='escolhida'",
                [':i' => $cotacaoId, ':t' => vero_tenant()]);
            if (!$ok) $cotacaoId = null;
        }

        if (!$fornecedorId || $data === null) {
            vero_flash('erro', 'Fornecedor e data do pedido são obrigatórios.');
            vero_redirect();
        }
        if ($freteValor !== null && $freteValor < 0) { /* A11: frete é custo, nunca negativo */
            vero_flash('erro', 'O valor do frete não pode ser negativo.');
            vero_redirect();
        }
        $okForn = vero_val("SELECT id FROM fornecedores WHERE id=:i AND tenant_id=:t AND ativo=1",
            [':i' => $fornecedorId, ':t' => vero_tenant()]);
        if (!$okForn) {
            vero_flash('erro', 'Fornecedor inválido.');
            vero_redirect();
        }

        $iProduto = (array)($_POST['i_produto'] ?? []);
        $iDesc    = (array)($_POST['i_descricao'] ?? []);
        $iQtd     = (array)($_POST['i_qtd'] ?? []);
        $iValor   = (array)($_POST['i_valor'] ?? []);
        $parseDec = static function ($v): float {
            $v = trim((string)$v);
            if ($v === '') return 0.0;
            if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
            elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
            return is_numeric($v) ? (float)$v : 0.0;
        };

        $itens = [];
        $valorTotal = 0.0;
        foreach ($iQtd as $ix => $qtdRaw) {
            $qtd   = $parseDec($qtdRaw);
            $valor = $parseDec($iValor[$ix] ?? '');
            $prodId = (int)($iProduto[$ix] ?? 0);
            $desc = trim((string)($iDesc[$ix] ?? ''));
            if ($qtd <= 0 || (!$prodId && $desc === '')) continue;
            if ($valor < 0) { /* A11: valor unitário do item nunca é negativo */
                vero_flash('erro', 'O valor unitário do item não pode ser negativo.');
                vero_redirect();
            }
            if ($prodId) {
                $ok = vero_val("SELECT id FROM estoque_produtos WHERE id=:i AND tenant_id=:t",
                    [':i' => $prodId, ':t' => vero_tenant()]);
                if (!$ok) continue;
            }
            $totItem = round($qtd * $valor, 2);
            $valorTotal += $totItem;
            $itens[] = [
                'produto_id' => $prodId ?: null,
                'descricao'  => $desc !== '' ? mb_substr($desc, 0, 180) : null,
                'quantidade' => $qtd, 'valor_unitario' => $valor, 'valor_total' => $totItem,
            ];
        }
        if (!$itens) {
            vero_flash('erro', 'Inclua ao menos um item com quantidade e valor.');
            vero_redirect();
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $ped = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
                    [':i' => $id, ':t' => vero_tenant()]);
                if (!$ped || $ped['status'] !== 'rascunho') throw new RuntimeException('Só pedidos em rascunho podem ser editados.');
                vero_update(T, $id, [
                    'fornecedor_id' => $fornecedorId, 'data_pedido' => $data,
                    'valor_total' => round($valorTotal, 2),
                    'data_entrega_prevista' => $entregaPrev, 'frete_valor' => $freteValor,
                    'condicao_pagamento' => $condicao,
                    'safra_talhao_id' => $safraTalhaoId, 'centro_custo_id' => $centroCustoId,
                ]);
                $pdo->prepare("DELETE FROM compras_pedido_itens WHERE tenant_id=? AND pedido_id=?")
                    ->execute([vero_tenant(), $id]);
                $pedId = $id;
            } else {
                if ($solicitacaoId) {
                    $okSol = vero_val("SELECT id FROM compras_solicitacoes WHERE id=:i AND tenant_id=:t AND status IN ('aberta','em_cotacao')",
                        [':i' => $solicitacaoId, ':t' => vero_tenant()]);
                    if (!$okSol) $solicitacaoId = null;
                }
                $pedId = vero_insert(T, [
                    'numero'         => compras_next_numero(T, 'PC'),
                    'fornecedor_id'  => $fornecedorId,
                    'solicitacao_id' => $solicitacaoId ?: null,
                    'cotacao_id'     => $cotacaoId ?: null,
                    'valor_total'    => round($valorTotal, 2),
                    'status'         => 'rascunho',
                    'data_pedido'    => $data,
                    'data_entrega_prevista' => $entregaPrev, 'frete_valor' => $freteValor,
                    'condicao_pagamento' => $condicao,
                    'safra_talhao_id' => $safraTalhaoId, 'centro_custo_id' => $centroCustoId,
                ]);
            }
            foreach ($itens as $item) {
                $pdo->prepare("INSERT INTO compras_pedido_itens
                               (tenant_id, pedido_id, produto_id, descricao, quantidade, valor_unitario, valor_total)
                               VALUES (?,?,?,?,?,?,?)")
                    ->execute([vero_tenant(), $pedId, $item['produto_id'], $item['descricao'],
                               $item['quantidade'], $item['valor_unitario'], $item['valor_total']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao salvar: ' . h($e->getMessage()));
            vero_redirect();
        }
        vero_flash('ok', 'Pedido salvo (rascunho) — total R$ ' . numFmt($valorTotal, 2) . '. Envie para aprovação quando estiver pronto.');
        vero_redirect(BIOS_BASE . '/compras/pedidos');
    }

    if ($acao === 'enviar_aprovacao') {
        vero_require('compras.pedidos_compra.editar');
        $id = vero_int('id');
        $ped = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if (!$ped || $ped['status'] !== 'rascunho') {
            vero_flash('erro', 'Só pedidos em rascunho vão para aprovação.');
            vero_redirect();
        }
        /* alçada por valor (DB-08): chave `compras.alcada_valor` em tenant_parametros.
           Ausente = todo pedido exige aprovação (comportamento anterior). */
        $alcadaRaw = vero_srv_param('compras.alcada_valor');
        $alcada = $alcadaRaw !== null && is_numeric(str_replace(',', '.', $alcadaRaw))
            ? (float)str_replace(',', '.', $alcadaRaw) : null;
        $autoAprova = $alcada !== null && (float)$ped['valor_total'] <= $alcada;

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($autoAprova) {
                vero_update(T, (int)$id, ['status' => 'aprovado']);
                $pdo->prepare("INSERT INTO compras_aprovacoes (tenant_id, pedido_id, nivel, aprovador_id, status, observacao, data_decisao)
                               VALUES (?,?,1,?,'aprovado',?,NOW())")
                    ->execute([vero_tenant(), (int)$id, vero_uid(),
                               'Auto-aprovado por alçada (valor ≤ R$ ' . numFmt($alcada, 2) . ')']);
            } else {
                vero_update(T, (int)$id, ['status' => 'aprovacao']);
                $pdo->prepare("INSERT INTO compras_aprovacoes (tenant_id, pedido_id, nivel, status) VALUES (?,?,1,'pendente')")
                    ->execute([vero_tenant(), (int)$id]);
            }
            if ($ped['solicitacao_id'] !== null) {
                vero_update('compras_solicitacoes', (int)$ped['solicitacao_id'], ['status' => 'convertida']);
            }
            $pdo->commit();
            vero_flash('ok', $autoAprova
                ? "Pedido {$ped['numero']} APROVADO automaticamente (dentro da alçada de R$ " . numFmt($alcada, 2) . ") — pronto para receber."
                : "Pedido {$ped['numero']} enviado para aprovação.");
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro: ' . h($e->getMessage()));
        }
        vero_redirect();
    }

    if ($acao === 'cancelar') {
        vero_require('compras.pedidos_compra.excluir');
        $id = vero_int('id');
        $ped = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if ($ped && in_array($ped['status'], ['rascunho', 'aprovacao', 'aprovado'], true)) {
            vero_update(T, (int)$id, ['status' => 'cancelado']);
            vero_flash('ok', "Pedido {$ped['numero']} cancelado.");
        } else {
            vero_flash('erro', 'Pedidos com recebimento não podem ser cancelados.');
        }
        vero_redirect();
    }
}

/* ── Dados ──────────────────────────────────────────────────── */
$modoForm = isset($_GET['novo']) || !empty($_GET['editar']);

$edit = null;
$editItens = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t AND status='rascunho'",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        $editItens = vero_rows("SELECT * FROM compras_pedido_itens WHERE tenant_id=:t AND pedido_id=:p ORDER BY id",
            [':t' => vero_tenant(), ':p' => (int)$edit['id']]);
    } else {
        $modoForm = false;
    }
}

/* pré-carga a partir de solicitação aberta/em cotação */
$solicitacaoPre = null;
if ($modoForm && !$edit && !empty($_GET['solicitacao'])) {
    $solicitacaoPre = vero_row("SELECT * FROM compras_solicitacoes WHERE id=:i AND tenant_id=:t AND status IN ('aberta','em_cotacao')",
        [':i' => (int)$_GET['solicitacao'], ':t' => vero_tenant()]);
    if ($solicitacaoPre) {
        $editItens = vero_rows(
            "SELECT produto_id, descricao, quantidade, 0 AS valor_unitario
               FROM compras_solicitacao_itens WHERE tenant_id=:t AND solicitacao_id=:s ORDER BY id",
            [':t' => vero_tenant(), ':s' => (int)$solicitacaoPre['id']]);
    }
}

/* A2-F2-3: pré-carga a partir de COTAÇÃO ESCOLHIDA — fornecedor + itens + preços em 1 clique */
$cotacaoPre = null;
if ($modoForm && !$edit && !empty($_GET['cotacao'])) {
    $cotacaoPre = vero_row(
        "SELECT c.*, s.numero AS sol_numero FROM compras_cotacoes c
          LEFT JOIN compras_solicitacoes s ON s.id = c.solicitacao_id
         WHERE c.id = :i AND c.tenant_id = :t AND c.status = 'escolhida'",
        [':i' => (int)$_GET['cotacao'], ':t' => vero_tenant()]);
    if ($cotacaoPre) {
        $jaGerado = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND cotacao_id=:c AND status <> 'cancelado'",
            [':t' => vero_tenant(), ':c' => (int)$cotacaoPre['id']]);
        if ($jaGerado) {
            vero_flash('erro', 'Esta cotação já gerou um pedido ativo.');
            vero_redirect(BIOS_BASE . '/compras/pedidos');
        }
        $editItens = vero_rows(
            "SELECT produto_id, descricao, quantidade, valor_unitario
               FROM compras_cotacao_itens WHERE tenant_id=:t AND cotacao_id=:c ORDER BY id",
            [':t' => vero_tenant(), ':c' => (int)$cotacaoPre['id']]);
        if ($cotacaoPre['solicitacao_id'] !== null) {
            $solicitacaoPre = vero_row("SELECT * FROM compras_solicitacoes WHERE id=:i AND tenant_id=:t",
                [':i' => (int)$cotacaoPre['solicitacao_id'], ':t' => vero_tenant()]);
        }
    }
}

/* selects de vínculo de custo (DB-08) */
$safraTalhoesOpt = [];
foreach (vero_rows(
    "SELECT st.id, CONCAT(sa.identificacao, ' · ', tl.codigo) AS rotulo
       FROM agro_safra_talhoes st
       JOIN agro_safras sa ON sa.id = st.safra_id
       JOIN agro_talhoes tl ON tl.id = st.talhao_id
      WHERE st.tenant_id = :t ORDER BY sa.identificacao DESC, tl.codigo", [':t' => vero_tenant()]) as $stx) {
    $safraTalhoesOpt[(int)$stx['id']] = $stx['rotulo'];
}
$centrosCustoOpt = [];
foreach (vero_rows("SELECT id, CONCAT(codigo, ' — ', nome) AS rotulo FROM centros_custo
                     WHERE tenant_id = :t AND ativo = 1 ORDER BY codigo", [':t' => vero_tenant()]) as $ccx) {
    $centrosCustoOpt[(int)$ccx['id']] = $ccx['rotulo'];
}

/* Condição de pagamento: select fechado (a chave alimenta vero_srv_parcelas_de_condicao
   no recebimento). Preserva valor legado de texto livre se estiver fora do padrão. */
$condOpts = CONDICOES_PAGAMENTO;
if ($edit && ($cpAtual = trim((string)($edit['condicao_pagamento'] ?? ''))) !== '' && !isset($condOpts[$cpAtual])) {
    $condOpts[$cpAtual] = $cpAtual . ' (atual)';
}

$produtos = vero_rows("SELECT id, codigo, nome, unidade FROM estoque_produtos
                        WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => vero_tenant()]);
$fornecedores = vero_options('fornecedores', 'nome', 'ativo = 1');

if (!$modoForm) {
    $fStatus = (string)($_GET['status'] ?? '');
    $page    = max(1, (int)($_GET['pg'] ?? 1));
    $perPage = 15;
    $where  = "p.tenant_id = :t";
    $params = [':t' => vero_tenant()];
    if (in_array($fStatus, ['rascunho', 'aprovacao', 'aprovado', 'recebido_parcial', 'recebido', 'cancelado'], true)) {
        $where .= " AND p.status = :st";
        $params[':st'] = $fStatus;
    }
    $total = (int)vero_val("SELECT COUNT(*) FROM " . T . " p WHERE {$where}", $params);
    $rows  = vero_rows(
        "SELECT p.*, f.nome AS fornecedor, s.numero AS solicitacao_numero,
                (SELECT COUNT(*) FROM compras_pedido_itens i
                  WHERE i.tenant_id = p.tenant_id AND i.pedido_id = p.id) AS itens
           FROM " . T . " p
           JOIN fornecedores f ON f.id = p.fornecedor_id
           LEFT JOIN compras_solicitacoes s ON s.id = p.solicitacao_id
          WHERE {$where}
          ORDER BY p.id DESC
          LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);
}

$GUARD      = ['macro' => 'compras', 'micro' => 'pedidos_compra'];
$PAGE_VIEW  = 'compras_pedidos_compra';
$PAGE_TITLE = 'Pedidos de Compra';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('compras.pedidos_compra.editar');

$badgeStatus = static fn(string $s): string => match ($s) {
    'rascunho'         => '<span class="vbadge vb-info">Rascunho</span>',
    'aprovacao'        => '<span class="vbadge vb-warn">Em aprovação</span>',
    'aprovado'         => '<span class="vbadge vb-ok">Aprovado</span>',
    'recebido_parcial' => '<span class="vbadge vb-warn">Recebido parcial</span>',
    'recebido'         => '<span class="vbadge vb-ok">Recebido</span>',
    'cancelado'        => '<span class="vbadge vb-off">Cancelado</span>',
    default            => h($s),
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>

<?php if (!$modoForm): ?>
  <div class="vhead">
    <div>
      <h1>Pedidos de Compra</h1>
      <div class="vsub">Rascunho → aprovação → aprovado → recebimento (que gera estoque e conta a pagar)</div>
    </div>
    <?php if ($podeEditar): ?>
      <a class="vbtn vbtn-primary" href="?novo=1">+ Novo pedido</a>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="status" aria-label="Filtrar por status" onchange="this.form.submit()">
          <option value="">Todos os status</option>
          <?php foreach (['rascunho' => 'Rascunho', 'aprovacao' => 'Em aprovação', 'aprovado' => 'Aprovados',
                          'recebido_parcial' => 'Recebidos parciais', 'recebido' => 'Recebidos', 'cancelado' => 'Cancelados'] as $sk => $sl): ?>
            <option value="<?= $sk ?>"<?= $fStatus === $sk ? ' selected' : '' ?>><?= $sl ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($fStatus !== ''): ?><a class="vbtn vbtn-ghost" href="?" title="Limpar filtros">Limpar</a><?php endif; ?>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty"><?= $fStatus !== '' ? 'Nenhum pedido para o status selecionado.' : 'Nenhum pedido registrado ainda.' ?></div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Nº</th><th>Data</th><th>Fornecedor</th><th>Origem</th>
        <th class="num">Itens</th><th class="num">Valor (R$)</th>
        <th>Status</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $atrasado = $r['data_entrega_prevista'] !== null
              && in_array($r['status'], ['aprovado', 'recebido_parcial'], true)
              && $r['data_entrega_prevista'] < date('Y-m-d');
          $origem = $r['solicitacao_numero'] ? 'Solicitação ' . h($r['solicitacao_numero'])
              : ($r['cotacao_id'] !== null ? 'Cotação #' . (int)$r['cotacao_id'] : 'Direto'); ?>
        <tr<?= $r['status'] === 'cancelado' ? ' class="is-off"' : '' ?>>
          <td><strong class="vnum"><?= h($r['numero']) ?></strong>
            <?php if ($atrasado): ?><div style="color:#b3261e;font-size:11px;font-weight:600">entrega atrasada</div><?php endif; ?></td>
          <td class="vnum" style="white-space:nowrap"><?= $r['data_pedido'] ? date('d/m/Y', strtotime((string)$r['data_pedido'])) : '—' ?>
            <?= $r['data_entrega_prevista'] ? '<div class="vhint">prev. ' . date('d/m/Y', strtotime((string)$r['data_entrega_prevista'])) . '</div>' : '' ?></td>
          <td><?= h($r['fornecedor']) ?></td>
          <td class="vhint"><?= $origem ?></td>
          <td class="num"><?= (int)$r['itens'] ?></td>
          <td class="num"><strong><?= numFmt((float)$r['valor_total'], 2) ?></strong></td>
          <td><?= $badgeStatus((string)$r['status']) ?></td>
          <td class="num"><div class="vactions" style="justify-content:flex-end">
            <?php if ($podeEditar && $r['status'] === 'rascunho'): ?>
              <?= vero_btn_icone_post(vero_ico_seta(), 'Enviar para aprovação', 'enviar_aprovacao', (int)$r['id']) ?>
              <?= vero_btn_icone(vero_ico_lapis(), 'Editar', '', '?editar=' . (int)$r['id']) ?>
            <?php endif; ?>
            <?php if ($r['status'] === 'aprovacao'): ?>
              <?= vero_btn_icone(vero_ico_olho(), 'Ver fila de aprovação', '', BIOS_BASE . '/compras/aprovacoes') ?>
            <?php endif; ?>
            <?php if (in_array($r['status'], ['aprovado', 'recebido_parcial'], true)): ?>
              <?= vero_btn_icone(vero_ico_receber(), 'Receber', '', BIOS_BASE . '/compras/recebimentos?pedido=' . (int)$r['id']) ?>
            <?php endif; ?>
            <?php if (vero_can('compras.pedidos_compra.excluir') && in_array($r['status'], ['rascunho', 'aprovacao', 'aprovado'], true)): ?>
              <?= vero_btn_icone_post(vero_ico_x(), 'Cancelar', 'cancelar', (int)$r['id'], 'Cancelar este pedido?', true) ?>
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

<?php else: ?>
  <?php if (!$podeEditar): ?>
    <div class="vflash vflash-erro">Sem permissão para registrar pedidos.</div>
  <?php else: ?>
  <div class="vhead">
    <div>
      <h1><?= $edit ? 'Editar pedido ' . h($edit['numero']) : 'Novo pedido de compra' ?></h1>
      <div class="vsub"><?= $solicitacaoPre ? 'Itens pré-carregados da solicitação ' . h($solicitacaoPre['numero']) . ' — informe os preços' : 'Itens do estoque ou descrição livre, com preço por item' ?></div>
    </div>
    <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/compras/pedidos">← Voltar à lista</a>
  </div>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
    <input type="hidden" name="solicitacao_id" value="<?= $solicitacaoPre ? (int)$solicitacaoPre['id'] : (int)($edit['solicitacao_id'] ?? 0) ?>">
    <input type="hidden" name="cotacao_id" value="<?= $cotacaoPre ? (int)$cotacaoPre['id'] : (int)($edit['cotacao_id'] ?? 0) ?>">

    <div class="vcard" style="padding:18px 22px;margin-bottom:16px">
      <?php if ($cotacaoPre): ?>
        <div class="vflash vflash-ok" style="margin-bottom:10px">Gerado da cotação escolhida
          <?= $cotacaoPre['sol_numero'] ? 'da solicitação ' . h($cotacaoPre['sol_numero']) : '#' . (int)$cotacaoPre['id'] ?>
          — fornecedor e preços pré-carregados.</div>
      <?php endif; ?>
      <div class="vgrid">
        <?= vero_f_select('fornecedor_id', 'Fornecedor', $fornecedores,
              $edit['fornecedor_id'] ?? ($cotacaoPre['fornecedor_id'] ?? null), true) ?>
        <div class="vfield">
          <label>Data do pedido *</label>
          <input type="date" name="data_pedido" required
                 value="<?= h($edit && $edit['data_pedido'] ? (string)$edit['data_pedido'] : date('Y-m-d')) ?>">
        </div>
        <div class="vfield">
          <label>Entrega prevista</label>
          <input type="date" name="data_entrega_prevista"
                 value="<?= h((string)($edit['data_entrega_prevista'] ?? '')) ?>">
        </div>
        <?= vero_f_text('frete_valor', 'Frete (R$)',
              $edit && $edit['frete_valor'] !== null ? numFmt((float)$edit['frete_valor'], 2) : '', false,
              'Rateado no custo unitário dos itens no recebimento') ?>
        <?= vero_f_select('condicao_pagamento', 'Condição de pagamento', $condOpts,
              $edit['condicao_pagamento'] ?? null, false, '— Selecione —') ?>
        <?= vero_f_select('safra_talhao_id', 'Safra · Válvula (vínculo de custo)', $safraTalhoesOpt,
              $edit['safra_talhao_id'] ?? null, false, '— Nenhum —') ?>
        <?= vero_f_select('centro_custo_id', 'Centro de custo', $centrosCustoOpt,
              $edit['centro_custo_id'] ?? null, false, '— Nenhum —') ?>
      </div>
      <div class="vhint" style="margin-top:8px">O vínculo de safra alimenta o painel "Compras fora do orçamento".</div>
      <?php if (!$fornecedores): ?>
        <div class="vhint" style="margin-top:8px">Cadastre um fornecedor primeiro em Compras → Fornecedores.</div>
      <?php endif; ?>
    </div>

    <div class="vcard" style="margin-bottom:16px">
      <div class="vtoolbar"><strong style="font-size:14px">Itens do pedido</strong>
        <div style="flex:1"></div>
        <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="addItem()">+ Item</button>
      </div>
      <table class="vtable">
        <thead><tr>
          <th style="width:32%">Produto do estoque</th>
          <th>ou descrição livre</th>
          <th style="width:110px;text-align:right">Qtd</th>
          <th style="width:130px;text-align:right">R$ unitário</th>
          <th style="width:130px;text-align:right">Total</th>
          <th style="width:40px"></th>
        </tr></thead>
        <tbody id="itens-body"></tbody>
        <tfoot><tr>
          <td colspan="4" style="text-align:right;font-weight:600">Total do pedido</td>
          <td class="vnum" style="text-align:right;font-weight:700" id="total-geral">0,00</td>
          <td></td>
        </tr></tfoot>
      </table>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px">
      <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/compras/pedidos">Cancelar</a>
      <button class="vbtn vbtn-primary" type="submit">Salvar rascunho</button>
    </div>
  </form>

  <script>
  const PRODUTOS = <?= jsvar(array_map(static fn($p) => [
      'id' => (int)$p['id'], 'nome' => $p['codigo'] . ' — ' . $p['nome'] . ' (' . $p['unidade'] . ')',
  ], $produtos)) ?>;
  const EDIT_ITENS = <?= jsvar(array_map(static fn($i) => [
      'produto' => $i['produto_id'] !== null ? (int)$i['produto_id'] : null,
      'descricao' => $i['descricao'], 'qtd' => (float)$i['quantidade'],
      'valor' => (float)$i['valor_unitario'],
  ], $editItens)) ?>;

  const fmt = n => n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const dec = v => {
    v = String(v || '').trim();
    if (!v) return 0;
    if (v.includes(',')) v = v.replaceAll('.', '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(v)) v = v.replaceAll('.', '');
    const n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  };

  function addItem(preset) {
    const tb = document.getElementById('itens-body');
    const tr = document.createElement('tr');
    const opts = ['<option value="">— Nenhum (usar descrição) —</option>']
      .concat(PRODUTOS.map(p => `<option value="${p.id}">${esc(p.nome)}</option>`)).join('');
    tr.innerHTML = `
      <td><select name="i_produto[]" aria-label="Produto do item">${opts}</select></td>
      <td><input type="text" name="i_descricao[]" aria-label="Descrição do item" placeholder="Descrição livre"></td>
      <td><input type="text" name="i_qtd[]" class="i-qtd" aria-label="Quantidade" style="text-align:right" placeholder="0"></td>
      <td><input type="text" name="i_valor[]" class="i-valor" aria-label="Valor unitário" style="text-align:right" placeholder="0,00"></td>
      <td class="vnum i-total" style="text-align:right">0,00</td>
      <td><button type="button" class="vclose" title="Remover item" aria-label="Remover item" onclick="this.closest('tr').remove(); recalc()">×</button></td>`;
    tb.appendChild(tr);
    tr.querySelectorAll('input').forEach(el => el.addEventListener('input', recalc));
    if (preset) {
      if (preset.produto) tr.querySelector('select').value = String(preset.produto);
      if (preset.descricao) tr.querySelector('input[name="i_descricao[]"]').value = preset.descricao;
      tr.querySelector('.i-qtd').value = String(preset.qtd).replace('.', ',');
      if (preset.valor) tr.querySelector('.i-valor').value = fmt(preset.valor);
    }
    recalc();
  }
  function recalc() {
    let soma = 0;
    document.querySelectorAll('#itens-body tr').forEach(tr => {
      const tot = dec(tr.querySelector('.i-qtd').value) * dec(tr.querySelector('.i-valor').value);
      soma += tot;
      tr.querySelector('.i-total').textContent = fmt(tot);
    });
    document.getElementById('total-geral').textContent = fmt(soma);
  }
  EDIT_ITENS.forEach(i => addItem(i));
  if (!EDIT_ITENS.length) addItem();
  </script>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
