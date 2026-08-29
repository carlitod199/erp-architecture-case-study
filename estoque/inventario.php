<?php
/* ============================================================
   VERO — Estoque / Inventário  (tela real)
   Substitui o mock. Rota: /estoque/inventario.php
   Guard: estoque.inventario
   Inventário por almoxarifado (estoque_inventarios): abre com o
   saldo do sistema congelado por item, recebe a contagem e, ao
   concluir, gera movimentos de acerto (entrada/saída) pela
   diferença — trilha completa no histórico.
   A2-F2-8: produto com lotes ativos é contado POR LOTE
   (estoque_inventario_itens.lote_id); a conclusão ajusta o saldo
   do produto via services (custo médio/movimentos preservados) e
   sincroniza a quantidade de cada lote com a contagem física.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_audit.php'; /* A2-F2-19: ações críticas → auth_audit_logs */

$t = vero_tenant();

/* A2-F2-18: aprovação em 2 passos exige o valor 'contado' no ENUM de status
   (pendência DB p/ o A0 — sem ele, sql_mode não-strict gravaria '' e
   corromperia o registro). Detecta uma vez por request. */
function inv_dois_passos_ativo(): bool
{
    static $ativo = null;
    if ($ativo === null) {
        $col = vero_row("SHOW COLUMNS FROM estoque_inventarios WHERE Field = 'status'");
        /* wired A0 (migration 144): VARCHAR aceita 'contado' — ENUM legado só se listar o valor */
        $ativo = $col !== null && (str_contains((string)$col['Type'], "'contado'")
                                   || str_starts_with((string)$col['Type'], 'varchar'));
    }
    return $ativo;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'abrir') {
        vero_require('estoque.inventario.editar');
        $almoxId = vero_int('almoxarifado_id');
        if (!$almoxId) {
            vero_flash('erro', 'Selecione o almoxarifado.');
            vero_redirect();
        }
        $aberto = vero_val("SELECT id FROM estoque_inventarios
                             WHERE tenant_id=:t AND almoxarifado_id=:a AND status IN ('aberto','em_contagem')",
            [':t' => $t, ':a' => $almoxId]);
        if ($aberto) {
            vero_flash('erro', 'Já existe inventário em andamento neste almoxarifado.');
            vero_redirect('?inv=' . (int)$aberto);
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $invId = vero_insert('estoque_inventarios', [
                'almoxarifado_id' => (int)$almoxId,
                'status'          => 'em_contagem',
                'data_inventario' => date('Y-m-d'),
                'justificativa'   => vero_str('justificativa', 255),
            ]);
            $saldos = vero_rows("SELECT * FROM estoque_saldos WHERE tenant_id=:t AND almoxarifado_id=:a",
                [':t' => $t, ':a' => $almoxId]);
            /* saldo_contado é NOT NULL — abre pré-preenchido com o saldo do sistema.
               Produto com lotes ativos gera uma linha POR LOTE (+ resto sem lote,
               se houver diferença entre a soma dos lotes e o saldo do produto). */
            $ins = $pdo->prepare(
                "INSERT INTO estoque_inventario_itens
                    (tenant_id, inventario_id, produto_id, lote_id, saldo_sistema, saldo_contado, diferenca, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), NOW())");
            $lotesQ = $pdo->prepare(
                "SELECT id, quantidade FROM estoque_lotes
                  WHERE tenant_id = ? AND produto_id = ? AND almoxarifado_id = ? AND quantidade > 0
                  ORDER BY (validade IS NULL), validade, id");
            $n = 0;
            foreach ($saldos as $s) {
                $prodId = (int)$s['produto_id'];
                $lotesQ->execute([$t, $prodId, (int)$almoxId]);
                $lotes = $lotesQ->fetchAll(PDO::FETCH_ASSOC);
                if ($lotes) {
                    $somaLotes = 0.0;
                    foreach ($lotes as $l) {
                        $ins->execute([$t, (int)$invId, $prodId, (int)$l['id'], $l['quantidade'], $l['quantidade']]);
                        $somaLotes += (float)$l['quantidade'];
                        $n++;
                    }
                    $resto = (float)$s['quantidade'] - $somaLotes;
                    if (abs($resto) > 0.0001) { /* parcela do saldo sem lote rastreado */
                        $ins->execute([$t, (int)$invId, $prodId, null, $resto, max(0, $resto)]);
                        $n++;
                    }
                } else {
                    $ins->execute([$t, (int)$invId, $prodId, null, $s['quantidade'], $s['quantidade']]);
                    $n++;
                }
            }
            $pdo->commit();
            vero_flash('ok', "Inventário aberto com {$n} item(ns) — informe as contagens e conclua.");
            vero_redirect('?inv=' . (int)$invId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
            vero_redirect();
        }
    }

    /* A2-F2-18 (DB-47): APROVAÇÃO EM 2 PASSOS — fechar a contagem apenas
       GRAVA (status 'contado'); os acertos só entram no estoque na APROVAÇÃO
       (alçada .excluir), que carimba aprovado_por/aprovado_em.
       FEATURE-DETECTION: o ENUM de `status` precisa conter 'contado' (a DB-47
       criou aprovado_por/em mas não estendeu o ENUM — pendência DB p/ o A0;
       sql_mode não-strict gravaria '' e corromperia o registro). Sem o valor,
       o fluxo antigo (concluir aplica direto) permanece. */
    if ($acao === 'concluir') {
        vero_require('estoque.inventario.editar');
        $invId = vero_int('inv_id');
        $inv = $invId ? vero_row("SELECT * FROM estoque_inventarios WHERE id=:i AND tenant_id=:t",
            [':i' => $invId, ':t' => $t]) : null;
        if (!$inv || $inv['status'] !== 'em_contagem') {
            vero_flash('erro', 'Inventário inválido ou fora da fase de contagem.');
            vero_redirect();
        }
        $itens = vero_rows("SELECT * FROM estoque_inventario_itens WHERE tenant_id=:t AND inventario_id=:i",
            [':t' => $t, ':i' => (int)$invId]);
        $contagens = (array)($_POST['contagem'] ?? []);

        $doisPassos = inv_dois_passos_ativo();
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            /* passo 1/2 (ou passo único no modo legado): registra contagens e diferenças */
            $upItem = $pdo->prepare("UPDATE estoque_inventario_itens
                                        SET saldo_contado = ?, diferenca = ?, updated_at = NOW()
                                      WHERE tenant_id = ? AND id = ?");
            $divergentes = 0;
            $ajustes = 0;
            foreach ($itens as $item) {
                $raw = trim((string)($contagens[(int)$item['id']] ?? ''));
                if ($raw === '') throw new RuntimeException('Informe a contagem de todos os itens.');
                if (str_contains($raw, ',')) $raw = str_replace(['.', ','], ['', '.'], $raw);
                if (!is_numeric($raw) || (float)$raw < 0) throw new RuntimeException('Contagem inválida.');
                $contado = (float)$raw;
                $dif = $contado - (float)$item['saldo_sistema'];
                $upItem->execute([$contado, $dif, $t, (int)$item['id']]);
                if (abs($dif) <= 0.0001) continue;
                $divergentes++;
                if (!$doisPassos) { /* modo legado: aplica direto */
                    vero_srv_estoque_ajuste((int)$item['produto_id'], (int)$inv['almoxarifado_id'],
                        $dif, 'acerto_inventario', date('Y-m-d'),
                        !empty($item['lote_id']) ? (int)$item['lote_id'] : null,
                        'Acerto de inventário (' . ($dif > 0 ? 'sobra' : 'falta') . ')',
                        'inventario', (int)$invId);
                    $ajustes++;
                }
            }
            vero_update('estoque_inventarios', (int)$invId,
                ['status' => $doisPassos ? 'contado' : 'concluido']);
            $pdo->commit();
            vero_flash('ok', $doisPassos
                ? "Contagem fechada — {$divergentes} divergência(s) registrada(s). NENHUM acerto foi aplicado ainda: o estoque só muda na APROVAÇÃO."
                : "Inventário concluído — {$ajustes} acerto(s) de estoque gerado(s).");
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
            vero_redirect('?inv=' . (int)$invId);
        }
        if ($doisPassos) vero_redirect('?inv=' . (int)$invId);
        vero_redirect();
    }

    if ($acao === 'aprovar') {
        vero_require('estoque.inventario.excluir'); /* alçada de aprovação */
        $invId = vero_int('inv_id');
        $inv = $invId ? vero_row("SELECT * FROM estoque_inventarios WHERE id=:i AND tenant_id=:t",
            [':i' => $invId, ':t' => $t]) : null;
        if (!$inv || $inv['status'] !== 'contado') {
            vero_flash('erro', 'Só inventários com contagem FECHADA podem ser aprovados.');
            vero_redirect();
        }
        $itens = vero_rows("SELECT * FROM estoque_inventario_itens WHERE tenant_id=:t AND inventario_id=:i",
            [':t' => $t, ':i' => (int)$invId]);
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            /* passo 2/2: aplica os acertos pelas diferenças GRAVADAS, via primitiva
               de AJUSTE TIPADO (motivo 'acerto_inventario'): item de LOTE ajusta o
               lote exato; "sem lote" ajusta o produto (redução consome FEFO). */
            $ajustes = 0;
            foreach ($itens as $item) {
                $dif = (float)($item['diferenca'] ?? 0);
                if (abs($dif) <= 0.0001) continue;
                vero_srv_estoque_ajuste((int)$item['produto_id'], (int)$inv['almoxarifado_id'],
                    $dif, 'acerto_inventario', date('Y-m-d'),
                    !empty($item['lote_id']) ? (int)$item['lote_id'] : null,
                    'Acerto de inventário (' . ($dif > 0 ? 'sobra' : 'falta') . ')',
                    'inventario', (int)$invId);
                $ajustes++;
            }
            vero_update('estoque_inventarios', (int)$invId, ['status' => 'concluido']);
            $pdo->prepare("UPDATE estoque_inventarios SET aprovado_por = ?, aprovado_em = NOW()
                            WHERE tenant_id = ? AND id = ?")
                ->execute([vero_uid(), $t, (int)$invId]);
            $pdo->commit();
            estoque_audit('estoque_inventario_aprovado', "Inventário #{$invId} aprovado — {$ajustes} acerto(s) aplicado(s) (almox #{$inv['almoxarifado_id']})");
            vero_flash('ok', "Inventário APROVADO — {$ajustes} acerto(s) de estoque aplicado(s).");
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (!estoque_flash_guarda($e)) { /* PERIODO_FECHADO: orientado (EST-018) */
                vero_flash('erro', 'Aprovação não realizada: ' . $e->getMessage());
            }
        }
        vero_redirect();
    }

    if ($acao === 'reabrir') {
        vero_require('estoque.inventario.excluir');
        $invId = vero_int('inv_id');
        $inv = $invId ? vero_row("SELECT * FROM estoque_inventarios WHERE id=:i AND tenant_id=:t",
            [':i' => $invId, ':t' => $t]) : null;
        if ($inv && $inv['status'] === 'contado') {
            vero_update('estoque_inventarios', (int)$invId, ['status' => 'em_contagem']);
            vero_flash('ok', 'Contagem reaberta para recontagem — nenhum acerto foi gerado.');
            vero_redirect('?inv=' . (int)$invId);
        }
        vero_redirect();
    }

    if ($acao === 'cancelar') {
        vero_require('estoque.inventario.excluir');
        $invId = vero_int('inv_id');
        $inv = $invId ? vero_row("SELECT * FROM estoque_inventarios WHERE id=:i AND tenant_id=:t",
            [':i' => $invId, ':t' => $t]) : null;
        if ($inv && in_array($inv['status'], ['em_contagem', 'contado'], true)) {
            vero_update('estoque_inventarios', (int)$invId, ['status' => 'cancelado']);
            vero_flash('ok', 'Inventário cancelado — nenhum acerto foi gerado.');
        }
        vero_redirect();
    }
}

$invAtual = null;
$invItens = [];
if (!empty($_GET['inv'])) {
    $invAtual = vero_row(
        "SELECT i.*, a.nome AS almox FROM estoque_inventarios i
          LEFT JOIN almoxarifados a ON a.id = i.almoxarifado_id
         WHERE i.id = :i AND i.tenant_id = :t", [':i' => (int)$_GET['inv'], ':t' => $t]);
    if ($invAtual) {
        $invItens = vero_rows(
            "SELECT it.*, p.codigo, p.nome, p.unidade, l.codigo_lote, l.validade AS lote_validade
               FROM estoque_inventario_itens it
               JOIN estoque_produtos p ON p.id = it.produto_id
               LEFT JOIN estoque_lotes l ON l.id = it.lote_id
             WHERE it.tenant_id = :t AND it.inventario_id = :i
             ORDER BY p.nome, (it.lote_id IS NULL), (l.validade IS NULL), l.validade, it.id",
            [':t' => $t, ':i' => (int)$invAtual['id']]);
    }
}

$historico = vero_rows(
    "SELECT i.*, a.nome AS almox,
            (SELECT COUNT(*) FROM estoque_inventario_itens it
              WHERE it.tenant_id = i.tenant_id AND it.inventario_id = i.id) AS itens,
            (SELECT COUNT(*) FROM estoque_inventario_itens it2
              WHERE it2.tenant_id = i.tenant_id AND it2.inventario_id = i.id AND ABS(COALESCE(it2.diferenca,0)) > 0.0001) AS divergencias
       FROM estoque_inventarios i
       LEFT JOIN almoxarifados a ON a.id = i.almoxarifado_id
      WHERE i.tenant_id = :t ORDER BY i.id DESC LIMIT 20", [':t' => $t]);

$almoxes = vero_rows("SELECT id, nome FROM almoxarifados WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => $t]);

$badgeInv = static fn(string $s): string => match ($s) {
    'concluido'    => '<span class="vbadge vb-ok">Aprovado</span>',
    'contado'      => '<span class="vbadge vb-info">Contado — aguarda aprovação</span>',
    'em_contagem'  => '<span class="vbadge vb-warn">Em contagem</span>',
    'cancelado'    => '<span class="vbadge vb-off">Cancelado</span>',
    default        => '<span class="vbadge vb-info">Aberto</span>',
};

$GUARD      = ['macro' => 'estoque', 'micro' => 'inventario'];
$PAGE_VIEW  = 'estoque_inventario';
$PAGE_TITLE = 'Inventário de Estoque';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('estoque.inventario.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Inventário', 'Contagem física × saldo do sistema — a conclusão gera acertos rastreáveis', null) ?>

  <?php if ($invAtual && $invAtual['status'] === 'em_contagem' && $podeEditar): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Contagem — <?= h($invAtual['almox'] ?? 'almoxarifado') ?>
      · <?= date('d/m/Y', strtotime((string)$invAtual['data_inventario'])) ?></strong>
      <form method="post" data-confirm="Cancelar este inventário? Nenhum acerto será gerado." data-confirm-danger data-confirm-ok="Cancelar inventário" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="cancelar">
        <input type="hidden" name="inv_id" value="<?= (int)$invAtual['id'] ?>">
        <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Cancelar inventário</button>
      </form></div>
    <?php if (!$invItens): ?>
      <div class="vempty">Nenhum item com saldo neste almoxarifado.</div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="concluir">
      <input type="hidden" name="inv_id" value="<?= (int)$invAtual['id'] ?>">
      <table class="vtable">
        <thead><tr>
          <th>Produto</th>
          <th>Lote</th>
          <th style="text-align:right">Saldo do sistema</th>
          <th style="width:180px;text-align:right">Contagem física *</th>
        </tr></thead>
        <tbody>
        <?php foreach ($invItens as $it): ?>
          <tr>
            <td><strong class="vnum"><?= h($it['codigo']) ?></strong> <?= h($it['nome']) ?></td>
            <td><?php if (!empty($it['lote_id'])): ?>
                <span class="vnum"><?= h($it['codigo_lote'] ?? ('#' . (int)$it['lote_id'])) ?></span>
                <?php if (!empty($it['lote_validade'])): ?>
                  <span class="vhint">val. <?= date('d/m/Y', strtotime((string)$it['lote_validade'])) ?></span>
                <?php endif; ?>
              <?php else: ?><span class="vhint">sem lote</span><?php endif; ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$it['saldo_sistema'], 2) ?>
              <span class="vhint"><?= h($it['unidade']) ?></span></td>
            <td><input type="text" name="contagem[<?= (int)$it['id'] ?>]" placeholder="0,00" required
                       style="width:100%;text-align:right"
                       value="<?= $it['saldo_contado'] !== null ? numFmt((float)$it['saldo_contado'], 2) : '' ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="display:flex;justify-content:flex-end;padding:12px 14px">
        <button class="vbtn vbtn-primary" type="submit"
                data-confirm="Fechar a contagem? As diferenças ficam registradas para APROVAÇÃO — nada muda no estoque ainda." data-confirm-ok="Fechar contagem"
                onclick="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
          Fechar contagem (aguarda aprovação)</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
  <?php elseif ($invAtual && $invAtual['status'] === 'contado'): /* A2-F2-18: revisão/aprovação */ ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Revisão — <?= h($invAtual['almox'] ?? 'almoxarifado') ?>
      · <?= date('d/m/Y', strtotime((string)$invAtual['data_inventario'])) ?></strong>
      <span class="vbadge vb-info">Contado — aguarda aprovação</span></div>
    <table class="vtable">
      <thead><tr><th>Produto</th><th>Lote</th>
        <th style="text-align:right">Sistema</th><th style="text-align:right">Contado</th>
        <th style="text-align:right">Diferença</th></tr></thead>
      <tbody>
      <?php foreach ($invItens as $it): $dif = (float)($it['diferenca'] ?? 0); ?>
        <tr<?= abs($dif) > 0.0001 ? '' : ' style="opacity:.6"' ?>>
          <td><strong class="vnum"><?= h($it['codigo']) ?></strong> <?= h($it['nome']) ?></td>
          <td><?= !empty($it['lote_id'])
                ? '<span class="vnum">' . h($it['codigo_lote'] ?? ('#' . (int)$it['lote_id'])) . '</span>'
                : '<span class="vhint">sem lote</span>' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$it['saldo_sistema'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= $it['saldo_contado'] !== null ? numFmt((float)$it['saldo_contado'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right;<?= abs($dif) > 0.0001 ? 'color:#b3261e;font-weight:700' : '' ?>">
            <?= ($dif > 0 ? '+' : '') . numFmt($dif, 2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (vero_can('estoque.inventario.excluir')): ?>
    <div style="display:flex;justify-content:flex-end;gap:8px;padding:12px 14px">
      <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="reabrir"><input type="hidden" name="inv_id" value="<?= (int)$invAtual['id'] ?>">
        <button class="vbtn vbtn-ghost" type="submit">Reabrir contagem</button></form>
      <form method="post" data-confirm="APROVAR o inventário? As diferenças serão aplicadas ao estoque como acertos rastreáveis." data-confirm-ok="Aprovar" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="aprovar"><input type="hidden" name="inv_id" value="<?= (int)$invAtual['id'] ?>">
        <button class="vbtn vbtn-primary" type="submit">Aprovar e aplicar acertos</button></form>
    </div>
    <?php else: ?>
      <div class="vhint" style="padding:0 14px 12px">Aguardando aprovação de quem tem alçada (permissão de exclusão do inventário).</div>
    <?php endif; ?>
  </div>
  <?php elseif ($podeEditar): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Abrir inventário</strong></div>
    <form class="vform" method="post" style="padding:0 14px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="abrir">
      <div class="vfield"><label>Almoxarifado *</label>
        <select name="almoxarifado_id" required>
          <option value="">Selecione…</option>
          <?php foreach ($almoxes as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= h($a['nome']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="vfield" style="flex:1;min-width:220px"><label>Justificativa</label>
        <input type="text" name="justificativa" maxlength="255" placeholder="Ex.: inventário mensal"></div>
      <button class="vbtn vbtn-primary" type="submit">Abrir inventário</button>
    </form>
    <div class="vhint" style="padding:0 14px 12px">Abrir congela o saldo do sistema por item — produtos com lotes ativos são contados <strong>por lote</strong> (a conclusão acerta o saldo e sincroniza cada lote com a contagem). Movimentações feitas durante a contagem serão refletidas apenas no próximo inventário.</div>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Histórico</strong>
      <span class="vsub"><?= count($historico) ?> inventário(s)</span></div>
    <?php if (!$historico): ?>
      <div class="vempty">Nenhum inventário realizado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Almoxarifado</th>
        <th style="text-align:right">Itens</th>
        <th style="text-align:right">Divergências</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($historico as $r): ?>
        <tr<?= $r['status'] === 'cancelado' ? ' style="opacity:.55"' : '' ?>>
          <td class="vnum"><strong><?= $r['data_inventario'] ? date('d/m/Y', strtotime((string)$r['data_inventario'])) : '—' ?></strong></td>
          <td><?= h($r['almox'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['itens'] ?></td>
          <td class="vnum" style="text-align:right;<?= (int)$r['divergencias'] > 0 ? 'color:#b3261e;font-weight:700' : '' ?>">
            <?= (int)$r['divergencias'] ?></td>
          <td><?= $badgeInv((string)$r['status']) ?></td>
          <td><div class="vactions">
            <?php if ($r['status'] === 'em_contagem'): ?>
              <?= vero_btn_icone(vero_ico_seta(), 'Continuar contagem', '', '?inv=' . (int)$r['id']) ?>
            <?php elseif ($r['status'] === 'contado'): ?>
              <?= vero_btn_icone(vero_ico_check(), 'Revisar / aprovar', '', '?inv=' . (int)$r['id']) ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
