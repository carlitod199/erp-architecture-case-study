<?php
/* ============================================================
   VERO — Compras / Recebimentos  (tela real)
   Substitui o mock. Rota: /compras/recebimentos.php
   Guard: compras.recebimentos
   Tabelas: compras_recebimentos/_itens; a confirmação usa
   vero_srv_compra_confirmar_recebimento: ENTRADA no estoque ao
   custo do recebimento + conta a PAGAR no razão (hash-chain).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_helpers.php';

const T = 'compras_recebimentos';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'receber') {
        vero_require('compras.recebimentos.editar');

        $pedidoId = vero_int('pedido_id');
        $data     = vero_date('data_recebimento') ?? date('Y-m-d');
        $vencimento = vero_date('data_vencimento');
        /* A2-F2-10 + campo estruturado (A0 19/07): a condição vem de um preset
           fechado ou do campo "personalizada" com padrão validado — texto que o
           parser não entende NÃO passa mais silenciosamente como título único. */
        $condPreset = (string)($_POST['condicao_preset'] ?? '');
        $condCustom = trim((string)($_POST['condicao_custom'] ?? ''));
        if ($condPreset === '__custom') {
            $condCustom = preg_replace('/\s+/', '', $condCustom);
            if (!preg_match('/^\d{1,3}(\/\d{1,3})+$/', $condCustom)) {
                vero_flash('erro', 'Condição personalizada inválida — informe os dias separados por barra (ex.: 45/90).');
                vero_redirect(BIOS_BASE . '/compras/recebimentos?pedido=' . (int)$pedidoId);
            }
            $condicao = $condCustom;
        } else {
            $condicao = preg_match('/^\d{1,2}x\d{1,3}$|^\d{1,3}(\/\d{1,3})+$/', $condPreset) ? $condPreset : '';
        }
        $condicao = mb_substr($condicao, 0, 60);
        /* compat: POST antigo (harness/testes) com condicao_pagamento direto —
           só entra se casar com a gramática estrita */
        if ($condicao === '' && !isset($_POST['condicao_preset']) && isset($_POST['condicao_pagamento'])) {
            $legado = trim((string)$_POST['condicao_pagamento']);
            if (preg_match('/^\d{1,2}x\d{1,3}$|^\d{1,3}(\/\d{1,3})+$/', $legado)
                || mb_stripos($legado, 'vista') !== false) {
                $condicao = mb_substr($legado, 0, 60);
            }
        }

        $pedido = $pedidoId ? vero_row(
            "SELECT * FROM compras_pedidos WHERE id=:i AND tenant_id=:t AND status IN ('aprovado','recebido_parcial')",
            [':i' => $pedidoId, ':t' => vero_tenant()]) : null;
        if (!$pedido) {
            vero_flash('erro', 'Pedido inválido ou não liberado para recebimento (precisa estar aprovado).');
            vero_redirect(BIOS_BASE . '/compras/recebimentos');
        }

        /* A2-F2-10: XML da NF-e (opcional) — registra o documento fiscal via
           service do A3 (idempotente por chave, SEM conta a pagar — o título
           nasce só do recebimento) e CONFERE fornecedor/valor com o pedido. */
        $xmlConteudo = null;
        if (!empty($_FILES['nfe_xml']['tmp_name']) && is_uploaded_file($_FILES['nfe_xml']['tmp_name'])) {
            $xmlConteudo = (string)file_get_contents($_FILES['nfe_xml']['tmp_name']);
        }

        $iItem  = (array)($_POST['i_item'] ?? []);
        $iQtd   = (array)($_POST['i_qtd'] ?? []);
        $iCusto = (array)($_POST['i_custo'] ?? []);
        $iVal   = (array)($_POST['i_validade'] ?? []);
        $parseDec = static function ($v): float {
            $v = trim((string)$v);
            if ($v === '') return 0.0;
            if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
            elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
            return is_numeric($v) ? (float)$v : 0.0;
        };

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            /* conferência por XML antes de qualquer efeito (aborta cedo se a nota
               não for do fornecedor do pedido) */
            $nfeInfo = null;
            if ($xmlConteudo !== null) {
                require_once __DIR__ . '/../fiscal/_fiscal_services.php';
                $nfeInfo = fiscal_importar_nfe_xml($xmlConteudo);
                $docFornecedorId = (int)vero_val(
                    "SELECT fornecedor_id FROM fiscal_documentos WHERE id=:d AND tenant_id=:t",
                    [':d' => (int)$nfeInfo['documento_id'], ':t' => vero_tenant()]);
                if ($docFornecedorId && $docFornecedorId !== (int)$pedido['fornecedor_id']) {
                    throw new RuntimeException('A NF-e importada é de OUTRO fornecedor (doc #'
                        . (int)$nfeInfo['documento_id'] . ') — não corresponde ao pedido ' . $pedido['numero'] . '.');
                }
                if (!$nfeInfo['ja_existia']) {
                    fiscal_anexar_arquivo((int)$nfeInfo['documento_id'], $_FILES['nfe_xml'], 'nfe');
                }
            }

            $recId = vero_insert(T, [
                'pedido_id'        => $pedidoId,
                'numero'           => compras_next_numero(T, 'RC'),
                'tipo'             => 'parcial', /* o service ajusta p/ 'total' quando o pedido zera a pendência (A2-F2-3) */
                'almoxarifado_id'  => vero_srv_almox_padrao(),
                'status'           => 'rascunho',
                'data_recebimento' => $data . ' 00:00:00',
            ]);

            $algum = false;
            $valorReceb = 0.0; /* mesma matemática do service — base das parcelas */
            foreach ($iItem as $ix => $pedidoItemId) {
                $qtd = $parseDec($iQtd[$ix] ?? '');
                if ($qtd <= 0) continue;
                $item = vero_row(
                    "SELECT * FROM compras_pedido_itens WHERE id=:i AND tenant_id=:t AND pedido_id=:p",
                    [':i' => (int)$pedidoItemId, ':t' => vero_tenant(), ':p' => $pedidoId]);
                if (!$item) continue;
                $pendente = (float)$item['quantidade'] - (float)$item['quantidade_recebida'];
                if ($qtd > $pendente + 0.0001) {
                    throw new RuntimeException('Quantidade recebida maior que a pendente no item "'
                        . ($item['descricao'] ?? $item['id']) . '" (pendente: ' . numFmt($pendente, 2) . ').');
                }
                $custo = $parseDec($iCusto[$ix] ?? '');
                if ($custo <= 0) $custo = (float)$item['valor_unitario'];
                $valData = trim((string)($iVal[$ix] ?? ''));
                $valData = preg_match('/^\d{4}-\d{2}-\d{2}$/', $valData) ? $valData : null;

                $pdo->prepare("INSERT INTO compras_recebimento_itens
                               (tenant_id, recebimento_id, pedido_item_id, produto_id, quantidade, custo_unitario, validade)
                               VALUES (?,?,?,?,?,?,?)")
                    ->execute([vero_tenant(), $recId, (int)$item['id'],
                               $item['produto_id'] !== null ? (int)$item['produto_id'] : null,
                               $qtd, $custo, $valData]);
                $valorReceb += round($qtd * $custo, 2);
                $algum = true;
            }
            if (!$algum) throw new RuntimeException('Informe a quantidade recebida de ao menos um item.');

            /* A2-F2-10: condição → N parcelas (null = título único, comportamento atual) */
            $parcelasDef = vero_srv_parcelas_de_condicao($condicao, $valorReceb, $data);
            /* F-04 (auditoria 19/07): título único SEM vencimento entrava no "em
               aberto" mas fora dos buckets do fluxo de caixa. Se a condição não
               gera parcelas, o vencimento é obrigatório. */
            if ($parcelasDef === null && $vencimento === null) {
                throw new RuntimeException('Informe o vencimento do título único (ou uma condição de pagamento que gere parcelas).');
            }
            $res = vero_srv_compra_confirmar_recebimento($recId, $vencimento, $parcelasDef);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Recebimento não confirmado: ' . h($e->getMessage()));
            vero_redirect(BIOS_BASE . '/compras/recebimentos?pedido=' . (int)$pedidoId);
        }

        if ($parcelasDef !== null) {
            $resumo = implode('; ', array_map(
                static fn($p) => date('d/m', strtotime($p['vencimento'])) . ' R$ ' . numFmt($p['valor'], 2),
                $parcelasDef));
            $msgFin = count($parcelasDef) . ' parcela(s) a PAGAR (' . $resumo . ') lançadas no Financeiro';
        } else {
            $msgFin = 'a conta a PAGAR de R$ ' . numFmt($res['valor'], 2)
                . ($vencimento ? ' (venc. ' . date('d/m/Y', strtotime($vencimento)) . ')' : '')
                . ' foi lançada no Financeiro';
        }
        vero_flash('ok', "Recebimento confirmado — {$res['no_estoque']} item(ns) deram entrada no estoque e {$msgFin}.");
        if ($nfeInfo !== null) {
            $difNfe = abs((float)$nfeInfo['valor'] - $res['valor']);
            vero_flash($difNfe > 0.01 ? 'aviso' : 'ok',
                'NF-e ' . ($nfeInfo['numero'] !== '' ? $nfeInfo['numero'] : $nfeInfo['chave'])
                . ($nfeInfo['ja_existia'] ? ' (já registrada no Fiscal)' : ' registrada no Fiscal')
                . ' — valor da nota R$ ' . numFmt((float)$nfeInfo['valor'], 2)
                . ($difNfe > 0.01
                    ? ' DIVERGE do recebimento (R$ ' . numFmt($res['valor'], 2) . ' — confira frete/impostos).'
                    : ' confere com o recebimento.'));
        }
        vero_redirect(BIOS_BASE . '/compras/recebimentos');
    }

    if ($acao === 'estornar') {   /* #33: des-receber um recebimento confirmado */
        vero_require('compras.recebimentos.editar');
        $recId = vero_int('id');
        try {
            $r = vero_srv_compra_estornar_recebimento($recId);
            vero_flash('ok', "Recebimento estornado — {$r['itens']} item(ns) devolvidos do estoque e "
                . "{$r['titulos']} conta(s) a pagar cancelada(s); o pedido voltou a ficar disponível para receber.");
        } catch (Throwable $e) {
            vero_flash('erro', 'Recebimento não estornado: ' . h($e->getMessage()));
        }
        vero_redirect(BIOS_BASE . '/compras/recebimentos');
    }
}

/* ── Dados ──────────────────────────────────────────────────── */
$pedidoSel = null;
$itensPendentes = [];
if (!empty($_GET['pedido'])) {
    $pedidoSel = vero_row(
        "SELECT p.*, f.nome AS fornecedor FROM compras_pedidos p
           JOIN fornecedores f ON f.id = p.fornecedor_id
          WHERE p.id = :i AND p.tenant_id = :t AND p.status IN ('aprovado','recebido_parcial')",
        [':i' => (int)$_GET['pedido'], ':t' => vero_tenant()]);
    if ($pedidoSel) {
        $itensPendentes = vero_rows(
            "SELECT i.*, ep.nome AS produto_nome, ep.codigo AS produto_codigo, ep.controla_validade
               FROM compras_pedido_itens i
               LEFT JOIN estoque_produtos ep ON ep.id = i.produto_id
              WHERE i.tenant_id = :t AND i.pedido_id = :p
                AND i.quantidade_recebida < i.quantidade - 0.0001
              ORDER BY i.id",
            [':t' => vero_tenant(), ':p' => (int)$pedidoSel['id']]);
    }
}

$aguardando = vero_rows(
    "SELECT p.*, f.nome AS fornecedor FROM compras_pedidos p
       JOIN fornecedores f ON f.id = p.fornecedor_id
      WHERE p.tenant_id = :t AND p.status IN ('aprovado','recebido_parcial')
      ORDER BY p.id DESC", [':t' => vero_tenant()]);

$confirmados = vero_rows(
    "SELECT r.*, p.numero AS pedido_numero, f.nome AS fornecedor,
            (SELECT COALESCE(SUM(ri.quantidade * ri.custo_unitario),0)
               FROM compras_recebimento_itens ri
              WHERE ri.tenant_id = r.tenant_id AND ri.recebimento_id = r.id) AS valor
       FROM " . T . " r
       JOIN compras_pedidos p ON p.id = r.pedido_id
       JOIN fornecedores f ON f.id = p.fornecedor_id
      WHERE r.tenant_id = :t AND r.status = 'confirmado'
      ORDER BY r.id DESC LIMIT 15", [':t' => vero_tenant()]);

$GUARD      = ['macro' => 'compras', 'micro' => 'recebimentos'];
$PAGE_VIEW  = 'compras_recebimentos';
$PAGE_TITLE = 'Recebimentos';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('compras.recebimentos.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Recebimentos de Compra', 'Conferência do pedido aprovado → entrada no estoque ao custo real + conta a pagar no Financeiro', null) ?>

<?php if ($pedidoSel && $podeEditar): ?>
  <div class="vcard" style="margin-bottom:16px">
    <div class="vtoolbar">
      <strong style="font-size:14px">Receber pedido <?= h($pedidoSel['numero']) ?></strong>
      <span class="vsub"><?= h($pedidoSel['fornecedor']) ?></span>
      <div style="flex:1"></div>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/compras/recebimentos">← Cancelar</a>
    </div>
    <?php if (!$itensPendentes): ?>
      <div class="vempty">Nada pendente neste pedido.</div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="receber">
      <input type="hidden" name="pedido_id" value="<?= (int)$pedidoSel['id'] ?>">
      <table class="vdata">
        <thead><tr>
          <th>Item</th>
          <th class="num">Pendente</th>
          <th style="width:130px;text-align:right">Qtd recebida</th>
          <th style="width:130px;text-align:right">Custo unit. (R$)</th>
          <th style="width:150px">Validade (perecível)</th>
        </tr></thead>
        <tbody>
        <?php
        /* A2-F2-3: frete do pedido rateado no custo unitário sugerido,
           proporcional ao valor de cada item (o almoxarife pode sobrescrever) */
        $freteTotal = (float)($pedidoSel['frete_valor'] ?? 0);
        $valorPedido = (float)$pedidoSel['valor_total'];
        foreach ($itensPendentes as $it): $pend = (float)$it['quantidade'] - (float)$it['quantidade_recebida'];
            $custoSugerido = (float)$it['valor_unitario'];
            $freteUnit = 0.0;
            if ($freteTotal > 0 && $valorPedido > 0 && (float)$it['quantidade'] > 0) {
                $freteUnit = $freteTotal * ((float)$it['valor_total'] / $valorPedido) / (float)$it['quantidade'];
                $custoSugerido += $freteUnit;
            }
        ?>
          <tr>
            <td>
              <input type="hidden" name="i_item[]" value="<?= (int)$it['id'] ?>">
              <?= $it['produto_nome']
                    ? '<strong class="vnum">' . h($it['produto_codigo']) . '</strong> ' . h($it['produto_nome'])
                    : h($it['descricao'] ?? '—') . ' <span class="vhint">(sem produto — não entra no estoque)</span>' ?>
            </td>
            <td class="vnum" style="text-align:right"><?= numFmt($pend, 2) ?></td>
            <td><input type="text" name="i_qtd[]" style="text-align:right" value="<?= numFmt($pend, 2) ?>"></td>
            <td><input type="text" name="i_custo[]" style="text-align:right" value="<?= numFmt($custoSugerido, 2) ?>">
              <?= $freteUnit > 0 ? '<div class="vhint">inclui frete ' . numFmt($freteUnit, 2) . '/un</div>' : '' ?></td>
            <td><input type="date" name="i_validade[]"<?= (int)($it['controla_validade'] ?? 0) === 1 ? ' required' : '' ?>></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="vtoolbar" style="border-top:1px solid #EEE8DB;border-bottom:0;flex-wrap:wrap">
        <div class="vfield">
          <label>Data do recebimento *</label>
          <input type="date" name="data_recebimento" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="vfield">
          <label>Condição de pagamento</label>
          <?php /* Campo ESTRUTURADO (pedido A0 19/07): texto livre virava
                   silenciosamente "título único" quando o parser não entendia.
                   Presets cobrem a gramática do service (vazio/à vista, D/D…,
                   NxD); "Personalizada" exige o padrão validado client+server. */
                $condPedido = trim((string)($pedidoSel['condicao_pagamento'] ?? ''));
                $presets = ['' => 'À vista / título único (usa o Vencimento)',
                            '30/60' => '30/60', '30/60/90' => '30/60/90',
                            '2x30' => '2x30 (30/60)', '3x30' => '3x30 (30/60/90)',
                            '4x30' => '4x30'];
                $condNorm = mb_strtolower($condPedido);
                $condEhPreset = isset($presets[$condPedido])
                    || $condNorm === '' || str_contains($condNorm, 'vista'); ?>
          <select name="condicao_preset" id="rc-cond-preset">
            <?php foreach ($presets as $v => $rot): ?>
              <option value="<?= h($v) ?>"<?= $condPedido === $v || ($v === '' && $condEhPreset && !isset($presets[$condPedido])) ? ' selected' : '' ?>><?= h($rot) ?></option>
            <?php endforeach; ?>
            <option value="__custom"<?= !$condEhPreset && !isset($presets[$condPedido]) ? ' selected' : '' ?>>Personalizada (dias por barra)…</option>
          </select>
          <input type="text" name="condicao_custom" id="rc-cond-custom"
                 value="<?= !$condEhPreset && !isset($presets[$condPedido]) ? h($condPedido) : '' ?>"
                 placeholder="ex.: 45/90 ou 28/56/84" pattern="\d{1,3}(\s*/\s*\d{1,3})+"
                 style="margin-top:6px;<?= !$condEhPreset && !isset($presets[$condPedido]) ? '' : 'display:none' ?>">
          <div class="vhint">2+ parcelas geram N títulos (última absorve centavos)<?=
              $condPedido !== '' ? ' · pedido: ' . h($condPedido) : '' ?></div>
          <script>
          (function(){
            var s = document.getElementById('rc-cond-preset'),
                c = document.getElementById('rc-cond-custom');
            if (s && c) s.addEventListener('change', function(){
              var custom = s.value === '__custom';
              c.style.display = custom ? '' : 'none';
              c.required = custom;
              if (!custom) c.value = '';
            });
          })();
          </script>
        </div>
        <div class="vfield">
          <label>Vencimento (título único)</label>
          <input type="date" name="data_vencimento">
          <div class="vhint">obrigatório sem parcelas; ignorado quando a condição gera parcelas</div>
        </div>
        <div class="vfield">
          <label>XML da NF-e (opcional)</label>
          <input type="file" name="nfe_xml" accept=".xml,text/xml">
          <div class="vhint">registra no Fiscal e confere fornecedor/valor</div>
        </div>
        <div style="flex:1"></div>
        <button class="vbtn vbtn-primary" type="submit">Confirmar recebimento</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">
    <div class="vcard">
      <div class="vtoolbar"><strong style="font-size:14px">Pedidos aguardando recebimento</strong></div>
      <?php if (!$aguardando): ?>
        <div class="vempty">Nenhum pedido aprovado pendente.</div>
      <?php else: ?>
      <div class="vdata-wrap">
      <table class="vdata">
        <thead><tr><th>Pedido</th><th>Fornecedor</th><th class="num">Valor (R$)</th><th>Status</th><th class="num">Ações</th></tr></thead>
        <tbody>
        <?php foreach ($aguardando as $p): ?>
          <tr>
            <td><strong class="vnum"><?= h($p['numero']) ?></strong></td>
            <td><?= h($p['fornecedor']) ?></td>
            <td class="num"><?= numFmt((float)$p['valor_total'], 2) ?></td>
            <td><?= $p['status'] === 'aprovado'
                  ? '<span class="vbadge vb-ok">Aprovado</span>'
                  : '<span class="vbadge vb-warn">Parcial</span>' ?></td>
            <td class="num"><?php if ($podeEditar): ?><?= vero_btn_icone(vero_ico_receber(), 'Receber pedido', '', '?pedido=' . (int)$p['id']) ?><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong style="font-size:14px">Recebimentos confirmados</strong></div>
      <?php if (!$confirmados): ?>
        <div class="vempty">Nenhum recebimento confirmado.</div>
      <?php else: ?>
      <div class="vdata-wrap">
      <table class="vdata">
        <thead><tr><th>Nº</th><th>Pedido</th><th>Fornecedor</th><th>Data</th><th class="num">Valor (R$)</th><th class="num">Ações</th></tr></thead>
        <tbody>
        <?php foreach ($confirmados as $r): ?>
          <tr>
            <td><strong class="vnum"><?= h($r['numero'] ?? (string)$r['id']) ?></strong>
              <?= $r['tipo'] === 'total' ? ' <span class="vbadge vb-ok">total</span>' : ' <span class="vbadge vb-warn">parcial</span>' ?></td>
            <td class="vnum"><?= h($r['pedido_numero']) ?></td>
            <td><?= h($r['fornecedor']) ?></td>
            <td class="vnum" style="white-space:nowrap"><?= date('d/m/Y', strtotime((string)$r['data_recebimento'])) ?></td>
            <td class="num"><strong><?= numFmt((float)$r['valor'], 2) ?></strong></td>
            <td class="num"><div class="vactions"><?php if ($podeEditar): ?>
              <?= vero_btn_icone_post(vero_ico_voltar(), 'Estornar recebimento', 'estornar', (int)$r['id'],
                    'Estornar este recebimento? Devolve as entradas do estoque e cancela as contas a pagar geradas. Bloqueado se algo já foi consumido do estoque ou se alguma conta já foi paga.', true) ?>
            <?php endif; ?></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="vhint" style="padding:10px 14px">Cada recebimento confirmado gera: <strong>entrada no estoque</strong> ao custo real, <strong>conta(s) a pagar</strong> no Financeiro e <strong>atualização do pedido</strong>. A confirmação não duplica estoque nem financeiro.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
