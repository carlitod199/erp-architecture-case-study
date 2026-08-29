<?php
/* ============================================================
   VERO — logout.php  v2.0
   Encerramento seguro de sessão com auditoria
   ============================================================ */

require_once __DIR__ . '/includes/functions.php'; // define BIOS_BASE

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ── Captura dados antes de destruir a sessão ─────────────── */
$userId   = (int) ($_SESSION['user_id']   ?? 0);
$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$email    = $_SESSION['user_email'] ?? null;

/* ── Auditoria de logout ────────────────────────────────────── */
if ($userId > 0) {
    try {
        require_once __DIR__ . '/includes/db.php';

        $ip = '0.0.0.0';
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $candidate = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ip = $candidate;
                    break;
                }
            }
        }

        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);

        $pdo->prepare(
            "INSERT INTO auth_audit_logs
             (tenant_id, user_id, email, acao, ip, user_agent, status, detalhes)
             VALUES (?, ?, ?, 'logout', ?, ?, 'sucesso', ?)"
        )->execute([
            $tenantId ?: null,
            $userId,
            $email,
            $ip,
            $ua,
            'Logout iniciado pelo usuário',
        ]);
    } catch (Throwable $e) {
        /* Não interrompe o logout por falha de log */
        error_log('[VERO:logout_audit] ' . $e->getMessage());
    }
}

/* ── Destruição completa da sessão ─────────────────────────── */
$_SESSION = [];
session_unset();
session_destroy();

/* Remove o cookie de sessão explicitamente */
$cookieName   = session_name();
$cookieParams = session_get_cookie_params();

setcookie(
    $cookieName,
    '',
    [
        'expires'  => time() - 86400,
        'path'     => $cookieParams['path']   ?: '/',
        'domain'   => $cookieParams['domain'] ?: '',
        'secure'   => $cookieParams['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]
);

header('Location: ' . BIOS_BASE . '/index?logout=1');
exit;
