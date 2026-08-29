<?php
declare(strict_types=1);
/* ============================================================
   VERO — Portal da Universidade — Endpoint de progresso/matrícula
   POST-only. Valida CSRF, executa a ação e redireciona (PRG) de
   volta para a página de origem (campo `voltar`, sempre interno).
   ============================================================ */
require_once __DIR__ . '/../includes/uni_auth.php'; uni_auth_boot(); uni_auth_require();        // exige login + inicia sessão + funções CSRF
require_once __DIR__ . '/../includes/uni_trilhas.php';  // funções de dados (traz uni_portal / uni_h)

$base = BIOS_BASE;
$ctx  = uni_ctx();

/* Só aceita POST. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Método não permitido.';
    exit;
}

/* CSRF obrigatório — token do form contra o da sessão (com janela de tolerância). */
$token = (string)($_POST['csrf_token'] ?? $_POST['_csrf'] ?? '');
if (!uni_csrf_check($token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Falha de segurança (CSRF). Recarregue a página e tente novamente.';
    exit;
}

/* Destino do redirect: só caminhos internos ("/algo"), nunca URL absoluta
   nem protocol-relative ("//host"). Fallback = catálogo da Universidade. */
$destino = (string)($_POST['voltar'] ?? '');
if ($destino === ''
    || $destino[0] !== '/'
    || strncmp($destino, '//', 2) === 0
    || strpos($destino, '\\') !== false
    || strpos($destino, "\n") !== false
    || strpos($destino, "\r") !== false) {
    $destino = $base . '/universidade/';
}

/* Ação. */
$acao = (string)($_POST['acao'] ?? '');
if ($acao === 'progresso') {
    $capsulaId = (int)($_POST['capsula_id'] ?? 0);
    $estado    = (string)($_POST['estado'] ?? '');
    if ($capsulaId > 0) {
        uni_marcar_progresso($ctx, $capsulaId, $estado);
    }
} elseif ($acao === 'matricula') {
    $trilhaId = (int)($_POST['trilha_id'] ?? 0);
    if ($trilhaId > 0) {
        uni_matricular($ctx, $trilhaId);
    }
}

/* PRG: redireciona de volta. */
header('Location: ' . $destino, true, 303);
exit;
