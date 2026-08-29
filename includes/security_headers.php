<?php
/* ============================================================
   VERO — includes/security_headers.php  (Fase 2)
   Headers de segurança centralizados. Chame bios_security_headers()
   ANTES de qualquer saída. Idempotente e não quebra layout.

   - HSTS apenas sob HTTPS.
   - CSP em modo REPORT-ONLY por padrão (não bloqueia nada): apenas reporta.
     Após validar no navegador (sem violações que quebrem o design), troque
     $enforceCsp=true para passar a bloquear.
   - Compatível com o front atual (inline scripts/handlers/estilos e fontes).
   ============================================================ */

if (!function_exists('bios_security_headers')) {
    function bios_security_headers(bool $enforceCsp = false): void
    {
        if (headers_sent()) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

        // Transporte: força HTTPS por 1 ano (só faz sentido/efeito sob HTTPS).
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        header('X-Permitted-Cross-Domain-Policies: none');

        // CSP: fontes agora SELF-HOST (PT-06 fase 1 — sem CDN). 'unsafe-inline'
        // segue necessário (handlers/estilos inline) até a migração p/ nonce
        // (PT-06 fase 2); frame-ancestors/base-uri/object-src restritivos.
        $csp = "default-src 'self'; "
             . "script-src 'self' 'unsafe-inline'; "
             . "style-src 'self' 'unsafe-inline'; "
             . "img-src 'self' data: blob:; "
             . "font-src 'self' data:; "
             . "connect-src 'self'; "
             . "frame-ancestors 'self'; "
             . "base-uri 'self'; "
             . "form-action 'self'; "
             . "object-src 'none'";

        header(($enforceCsp ? 'Content-Security-Policy: ' : 'Content-Security-Policy-Report-Only: ') . $csp);
    }
}
