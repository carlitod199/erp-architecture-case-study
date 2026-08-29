<?php
/* ============================================================
   VERO — Estoque / helper de AUDITORIA de ações críticas (A2-F2-19)
   Registra no auth_audit_logs (helper global _auth_tryAuditLog do
   auth.php — best-effort, nunca quebra o fluxo) as ações críticas
   do estoque: estornos, ajustes, devoluções, bloqueio de lote,
   aprovação de inventário e saída com lote vencido confirmada.
   Arquivo do módulo A2 — não é include global.
   ============================================================ */
declare(strict_types=1);

/**
 * Trata o contrato de guarda `PERIODO_FECHADO:` (A0/EST-018 — os services de
 * entrada/saída/ajuste/estorno bloqueiam datas de período fechado). Converte a
 * exceção em flash de AVISO com orientação de reabertura (como LOTE_VENCIDO).
 * Retorna true se tratou (o catch não deve reprocessar a mensagem).
 */
function estoque_flash_guarda(Throwable $e): bool
{
    $msg = $e->getMessage();
    if (str_starts_with($msg, 'PERIODO_FECHADO:')) {
        $texto = trim(substr($msg, strlen('PERIODO_FECHADO:')));
        if (mb_stripos($texto, 'reab') === false) { /* orienta só se o service ainda não orientou */
            $texto .= ' Para lançar nesta data, reabra o fechamento em Custos → Fechamento de Safra.';
        }
        vero_flash('aviso', '⚠ ' . $texto);
        return true;
    }
    return false;
}

/** Grava ação crítica de estoque na trilha de auditoria do sistema. */
function estoque_audit(string $acao, string $detalhes): void
{
    if (!function_exists('_auth_tryAuditLog')) return;
    _auth_tryAuditLog([
        'tenant_id'  => vero_tenant(),
        'user_id'    => vero_uid(),
        'acao'       => $acao,               /* ex.: estoque_estorno */
        'ip'         => function_exists('_auth_clientIp') ? _auth_clientIp() : ($_SERVER['REMOTE_ADDR'] ?? null),
        'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'status'     => 'sucesso',
        'detalhes'   => mb_substr($detalhes, 0, 500),
    ]);
}
