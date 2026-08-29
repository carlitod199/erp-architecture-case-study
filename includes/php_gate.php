<?php
/* ============================================================
   VERO - includes/php_gate.php
   Trava de versao minima do PHP (B2 da auditoria Go-Live 17/07).
   O codebase usa sintaxe e funcoes do PHP 8.1+ (tipo de retorno
   `never`, str_starts_with/str_contains, match, enums, named args).
   Num host com PHP < 8.1 isso vira Fatal error silencioso ("white
   screen"). Este arquivo da uma mensagem clara e encerra ANTES.

   ATENCAO: mantenha este arquivo em sintaxe compativel com PHP 5.x/7.x
   (sem tipos de retorno modernos, sem arrow fn, sem match) para que ele
   consiga ser interpretado e executar o aviso mesmo em versoes antigas.
   Inclua-o como PRIMEIRA instrucao dos pontos de entrada, antes de
   qualquer require de arquivo que use sintaxe 8.1+ (ex.: bootstrap.php).
   ============================================================ */

if (PHP_VERSION_ID < 80100) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $atual = PHP_VERSION;
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>VERO</title></head>'
        . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#151716;color:#f4f2e8;font:14px system-ui,Arial,sans-serif">'
        . '<div style="max-width:460px;margin:24px;padding:32px;border:1px solid #454744;'
        . 'border-radius:16px;background:#242522;text-align:center">'
        . '<h1 style="font-size:19px;margin:0 0 10px">Ambiente incompativel</h1>'
        . '<p style="color:#bcb9ad;line-height:1.55;margin:0">O VERO exige <b>PHP 8.1 ou superior</b>. '
        . 'Este servidor esta rodando <b>PHP ' . htmlspecialchars($atual) . '</b>.<br>'
        . 'Atualize a versao do PHP no host antes de continuar.</p>'
        . '</div></body></html>';
    error_log('[VERO][php_gate] PHP ' . $atual . ' < 8.1 — execucao bloqueada.');
    exit(1);
}
