<?php
declare(strict_types=1);

if (defined('BIOS_BOOTSTRAP_LOADED')) {
    return;
}
define('BIOS_BOOTSTRAP_LOADED', true);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');

$appEnv = strtolower((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production')));
$configuredLogDir = trim((string)(getenv('BIOS_LOG_DIR') ?: ''));
$logDir = $configuredLogDir !== ''
    ? $configuredLogDir
    : ($appEnv === 'production' ? dirname(__DIR__, 3) . '/logs' : dirname(__DIR__) . '/logs');

/* Produção em container: BIOS_LOG_STDERR=1 -> log vai para php://stderr (coleta por
   container na Fase 6; imune ao open_basedir). Sem a flag, mantém o log em arquivo. */
if ((getenv('BIOS_LOG_STDERR') ?: ($_ENV['BIOS_LOG_STDERR'] ?? '')) === '1') {
    ini_set('error_log', 'php://stderr');
} else {
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    if (is_dir($logDir) && is_writable($logDir)) {
        ini_set('error_log', rtrim($logDir, '/\\') . DIRECTORY_SEPARATOR . 'php_error.log');
    }
}

if (PHP_SAPI === 'cli') {
    return;
}

function bios_is_api_request(): bool
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return str_contains($uri, '/api/')
        || str_contains($accept, 'application/json')
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function bios_render_internal_error(): never
{
    /* A0-20 (ARQ-01 do relatório de pré-homologação): erro DEPOIS do HTML
       começar (headers enviados) não pode fingir página saudável — sinaliza
       INLINE, visível, e encerra o documento com honestidade. O log já foi
       gravado pelo handler antes de chegar aqui. */
    if (headers_sent() && !bios_is_api_request()) {
        echo '<div role="alert" style="margin:16px;padding:14px 16px;border:1px solid #E5B35D;border-left:6px solid #B3261E;'
            . 'border-radius:10px;background:#FDF3E7;color:#4A3B22;font:14px/1.5 \'IBM Plex Sans\',system-ui,sans-serif">'
            . '<strong>⚠ Parte desta página não pôde ser carregada.</strong> '
            . 'O erro foi registrado automaticamente. Recarregue a página; se persistir, avise o suporte.'
            . '</div></main></body></html>';
        exit;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Cache-Control: no-store');
    }

    if (bios_is_api_request()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok' => false,
            'error' => 'internal_error',
            'message' => 'Erro interno no servidor.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $base = defined('BIOS_BASE') ? BIOS_BASE : '';
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>VERO</title>'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#151716;color:#f4f2e8;font:14px Inter,system-ui,sans-serif}'
        . '.box{max-width:420px;margin:24px;padding:32px;border:1px solid #454744;border-radius:16px;background:#242522;text-align:center}'
        . 'h1{font-size:20px;margin:0 0 10px}p{color:#bcb9ad;line-height:1.5}a{display:inline-block;margin-top:14px;color:#fff;text-decoration:none;background:#397ed3;padding:10px 16px;border-radius:9px}</style>'
        . '</head><body><main class="box"><h1>Não foi possível concluir esta operação</h1>'
        . '<p>O erro foi registrado. Tente novamente em instantes.</p>'
        . '<a href="' . htmlspecialchars($base . '/dashboard', ENT_QUOTES, 'UTF-8') . '">Voltar ao início</a>'
        . '</main></body></html>';
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        '[VERO][exception] %s in %s:%d%s%s',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        PHP_EOL,
        $e->getTraceAsString()
    ));
    bios_render_internal_error();
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log(sprintf(
        '[VERO][fatal] %s in %s:%d',
        $error['message'],
        $error['file'],
        $error['line']
    ));
    bios_render_internal_error();
});
