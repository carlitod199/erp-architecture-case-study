<?php
/* ============================================================
   VERO — keepalive.php (A0-21 / SESS-01 do relatório de QA)
   GET autenticado que só renova a sessão (auth.php atualiza
   last_activity em toda requisição). O aviso de expiração do
   agro_header chama isto quando o usuário pede "continuar
   conectado". Sem sessão → auth redireciona (o JS trata).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'ok' => true,
    'inatividade_timeout' => SESSION_INACTIVITY_TIMEOUT,
    'csrf_token' => csrf(),   // X-04/Y-01: front reidrata os forms com o token válido
    'renovado_em' => date('c'),
]);
