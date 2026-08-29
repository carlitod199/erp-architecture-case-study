<?php
/* ============================================================
   VERO — includes/security/SecretBox.php
   Cofre simples para segredos da aplicação
   ============================================================ */

final class SecretBox
{
    private const PREFIX = 'biosbox:v1:';

    public static function encrypt(?string $plain): string
    {
        $plain = (string)($plain ?? '');
        if ($plain === '') {
            return '';
        }

        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'VERO', 16);

        if ($cipher === false || $tag === '') {
            throw new RuntimeException('Falha ao cifrar segredo.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $stored): string
    {
        $stored = (string)($stored ?? '');
        if ($stored === '') {
            return '';
        }

        if (!str_starts_with($stored, self::PREFIX)) {
            // Compatibilidade defensiva: evita quebrar leitura de registros antigos vazios/plain.
            // Em produção, segredos gravados devem sempre ter o prefixo biosbox:v1.
            return $stored;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Segredo cifrado inválido ou adulterado.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'VERO');
        if ($plain === false) {
            throw new RuntimeException('Falha ao decifrar segredo.');
        }

        return $plain;
    }

    public static function mask(?string $value, int $visible = 4): string
    {
        $value = (string)($value ?? '');
        if ($value === '') {
            return '';
        }

        $visible = max(0, min($visible, 8));
        if (strlen($value) <= $visible) {
            return str_repeat('•', max(strlen($value), 6));
        }

        return str_repeat('•', 8) . substr($value, -$visible);
    }

    private static function key(): string
    {
        // Garante que o .env foi carregado (no php-fpm o loader do crypto.php
        // pode não ter rodado ainda neste request). Idempotente: só carrega se faltar.
        if (getenv('BIOS_SECRET_KEY') === false
            && !isset($_ENV['BIOS_SECRET_KEY'], $_SERVER['BIOS_SECRET_KEY'])) {
            if (!function_exists('bios_load_env_file')) {
                $cryptoPath = __DIR__ . '/../crypto.php';
                if (is_file($cryptoPath)) {
                    require_once $cryptoPath;
                }
            }
            if (function_exists('bios_load_env_file')) {
                bios_load_env_file();
            }
        }

        $raw = getenv('BIOS_SECRET_KEY') ?: ($_ENV['BIOS_SECRET_KEY'] ?? $_SERVER['BIOS_SECRET_KEY'] ?? '');
        $raw = trim((string)$raw);

        if ($raw === '') {
            throw new RuntimeException('BIOS_SECRET_KEY não configurada.');
        }

        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }

        if (strlen($raw) === 32) {
            return $raw;
        }

        throw new RuntimeException('BIOS_SECRET_KEY inválida. Use 32 bytes em base64.');
    }
}
