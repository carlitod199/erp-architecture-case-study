<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/_http_runner.php  (A5-QA)
   Gatilho HTTP LOCAL da bateria — permite disparar o run_all
   pelo navegador da própria máquina (ex.: o agente de QA via
   Chrome em http://localhost/vero/tests/bateria/_http_runner.php).
   Segurança: só aceita conexão de 127.0.0.1/::1 E token correto
   (_env.php: runner_token). Nunca publicar em produção.
   Parâmetros: ?token=...&args=--2x  (args opcionais do run_all)
   ============================================================ */

$env = require __DIR__ . '/_env.php';

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Somente localhost.');
}
if ((string)($_GET['token'] ?? '') !== (string)$env['runner_token']) {
    http_response_code(403);
    exit('Token inválido.');
}

set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);

$argsOk = [];
foreach (explode(' ', (string)($_GET['args'] ?? '')) as $a) {
    if (preg_match('/^--(2x|sem-limpeza|so=[0-9]{2})$/', $a)) $argsOk[] = $a;
}

$phpBin = (string)$env['php_bin'];
if (!is_file($phpBin)) $phpBin = 'php';
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg(__DIR__ . '/run_all.php')
     . ($argsOk ? ' ' . implode(' ', array_map('escapeshellarg', $argsOk)) : '') . ' 2>&1';

echo "== VERO bateria — runner local ==\ncmd: {$cmd}\n\n";
$rc = -1;
if (function_exists('passthru')) {
    passthru($cmd, $rc);
    echo "\n== exit code: {$rc} ==\n";
} else {
    echo "exec/passthru desabilitado no PHP do Apache — rode via CLI:\n";
    echo $phpBin . ' ' . __DIR__ . "\\run_all.php --2x\n";
}
