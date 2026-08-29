<?php
/* VERO — Estoque / Histórico de Movimentações (tela real, base compartilhada)
   Guard: estoque.historico_movimentacoes
   A2-F2-2: além da trilha, esta tela executa AJUSTE TIPADO (motivo
   obrigatório, por produto ou por lote — vero_srv_estoque_ajuste) e
   DEVOLUÇÃO DE CAMPO (sobra de apontamento/aplicação volta ao custo da
   saída original — vero_srv_estoque_devolucao_campo).
   A2-F2-16 (EST-001): ESTORNO pela interface com motivo obrigatório —
   exposto SOMENTE para movimentos de origem `manual`/`devolucao_campo`
   (documentos — compra, apontamento, aplicação, inventário — estornam
   pela tela de origem; transferência tem o fluxo do PAR na tela própria).
   Permissão: `estoque.historico_movimentacoes.excluir` (decisão A0 05/07:
   sem slug próprio de estorno — ação destrutiva usa o .excluir do histórico). */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_audit.php'; /* A2-F2-19: ações críticas → auth_audit_logs */

/* motivos de ESTORNO (locais — gravados como texto na observação do contra-movimento) */
const ESTORNO_MOTIVOS = [
    'lancamento_errado' => 'Lançamento errado',
    'quantidade_errada' => 'Quantidade errada',
    'duplicidade'       => 'Duplicidade',
    'outro'             => 'Outro',
];

function mov_pode_estornar(): bool
{
    return vero_can('estoque.historico_movimentacoes.excluir');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'ajustar') {
        vero_require('estoque.historico_movimentacoes.editar');
        $loteId  = vero_int('lote_id');
        $motivo  = vero_str('motivo', 30) ?? '';
        $qtd     = vero_dec('quantidade');
        $direcao = (string)($_POST['direcao'] ?? 'reducao');
        $data    = vero_date('data') ?? date('Y-m-d');
        $obs     = vero_str('observacao', 255);

        if ($loteId) {
            $lote = vero_row("SELECT * FROM estoque_lotes WHERE tenant_id=:t AND id=:l",
                [':t' => vero_tenant(), ':l' => $loteId]);
            $produtoId = $lote ? (int)$lote['produto_id'] : 0;
            $almoxId   = $lote ? (int)$lote['almoxarifado_id'] : 0;
        } else {
            $produtoId = (int)(vero_int('produto_id') ?? 0);
            $almoxId   = (int)(vero_int('almoxarifado_id') ?? 0);
        }
        if (!$produtoId || !$almoxId || $qtd === null || $qtd <= 0) {
            vero_flash('erro', 'Informe lote OU produto+almoxarifado, e a quantidade (> 0) do ajuste.');
            vero_redirect();
        }
        $delta = $direcao === 'acrescimo' ? (float)$qtd : -(float)$qtd;
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            vero_srv_estoque_ajuste($produtoId, $almoxId, $delta, $motivo, $data, $loteId ?: null, $obs, 'manual', null);
            $pdo->commit();
            estoque_audit('estoque_ajuste', "Ajuste tipado produto #{$produtoId} almox #{$almoxId} delta "
                . ($delta > 0 ? '+' : '') . $delta . " motivo {$motivo}" . ($loteId ? " lote #{$loteId}" : ''));
            vero_flash('ok', 'Ajuste registrado (' . ($delta > 0 ? '+' : '') . numFmt($delta, 2) . ') — motivo: '
                . (VERO_ESTOQUE_MOTIVOS_AJUSTE[$motivo] ?? $motivo) . '.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (!estoque_flash_guarda($e)) { /* PERIODO_FECHADO: orientado (EST-018) */
                vero_flash('erro', 'Ajuste não realizado: ' . $e->getMessage());
            }
        }
        vero_redirect();
    }

    if ($acao === 'estornar') {
        if (!mov_pode_estornar()) {
            vero_flash('erro', 'Sem permissão para estornar movimentações.');
            vero_redirect();
        }
        $movId  = vero_int('mov_id');
        $motivo = vero_str('motivo', 30) ?? '';
        $obs    = vero_str('observacao', 200);
        if (!$movId || !isset(ESTORNO_MOTIVOS[$motivo])) {
            vero_flash('erro', 'Movimentação e MOTIVO do estorno são obrigatórios.');
            vero_redirect();
        }
        $mov = vero_row("SELECT * FROM estoque_movimentacoes WHERE tenant_id=:t AND id=:i",
            [':t' => vero_tenant(), ':i' => $movId]);
        if (!$mov || $mov['estornado_em'] !== null) {
            vero_flash('erro', 'Movimentação inválida ou já estornada.');
            vero_redirect();
        }
        if (!in_array((string)($mov['origem_tipo'] ?? ''), ['manual', 'devolucao_campo'], true)) {
            vero_flash('erro', 'Esta movimentação pertence a um documento ('
                . str_replace('_', ' ', (string)$mov['origem_tipo'])
                . ') — estorne pela tela de origem para manter documento e custeio sincronizados. Transferências: use a tela de Transferências (estorna o par).');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            vero_srv_estoque_estornar_mov($mov);
            /* motivo obrigatório anotado no CONTRA-movimento (mov_ref_id do original
               aponta para o contra após o service) — tabela do domínio A2 */
            $contraId = (int)vero_val("SELECT mov_ref_id FROM estoque_movimentacoes WHERE tenant_id=:t AND id=:i",
                [':t' => vero_tenant(), ':i' => $movId]);
            if ($contraId) {
                $pdo->prepare("UPDATE estoque_movimentacoes
                                  SET observacao = CONCAT(COALESCE(observacao,''), ' | Motivo: ', ?)
                                WHERE tenant_id = ? AND id = ?")
                    ->execute([ESTORNO_MOTIVOS[$motivo] . ($obs !== null ? ' — ' . $obs : ''),
                               vero_tenant(), $contraId]);
            }
            $pdo->commit();
            estoque_audit('estoque_estorno', "Estorno da movimentação #{$movId} ({$mov['tipo']} "
                . $mov['quantidade'] . " produto #{$mov['produto_id']}) — motivo: " . ESTORNO_MOTIVOS[$motivo]);
            vero_flash('ok', "Movimentação #{$movId} estornada (" . ESTORNO_MOTIVOS[$motivo]
                . ') — original e contra-movimento permanecem na trilha.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (!estoque_flash_guarda($e)) { /* PERIODO_FECHADO: orientado (EST-018) */
                vero_flash('erro', 'Estorno não realizado: ' . $e->getMessage());
            }
        }
        vero_redirect();
    }

    if ($acao === 'devolver') {
        vero_require('estoque.historico_movimentacoes.editar');
        $movId = vero_int('mov_id');
        $qtd   = vero_dec('quantidade');
        $data  = vero_date('data') ?? date('Y-m-d');
        if (!$movId || $qtd === null || $qtd <= 0) {
            vero_flash('erro', 'Movimentação e quantidade (> 0) são obrigatórias na devolução.');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            vero_srv_estoque_devolucao_campo((int)$movId, (float)$qtd, $data, vero_str('observacao', 255));
            $pdo->commit();
            estoque_audit('estoque_devolucao', "Devolução de campo da saída #{$movId}: {$qtd} de volta ao estoque");
            vero_flash('ok', 'Devolução de campo registrada — ' . numFmt((float)$qtd, 2) . ' de volta ao estoque ao custo da saída original.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (!estoque_flash_guarda($e)) { /* PERIODO_FECHADO: orientado (EST-018) */
                vero_flash('erro', 'Devolução não realizada: ' . $e->getMessage());
            }
        }
        vero_redirect();
    }
}

$MOV_TIPO   = null;
$MOV_MICRO  = 'historico_movimentacoes';
$MOV_VIEW   = 'estoque_historico_movimentacoes';
$MOV_TITULO = 'Histórico de Movimentações';
$MOV_SUB    = 'Trilha completa do estoque — entradas, saídas, ajustes tipados, devoluções, lotes (FEFO) e origens';
$MOV_ACOES  = true; /* habilita ajuste/devolução na base compartilhada */
require __DIR__ . '/_mov_base.php';
