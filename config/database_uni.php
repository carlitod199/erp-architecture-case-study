<?php
/* ============================================================
   VERO — config/database_uni.php
   Conexão do BANCO SEPARADO da Universidade VERO (decisão 26/07:
   a Universidade NÃO usa o banco do sistema; instância própria).
   Lê UNI_DB_* do .env; fallback = MySQL local do WAMP (dev Fase 0).
   Mesmo formato de retorno de config/database.php.
   ============================================================ */

$envPath = __DIR__ . '/../.env';

$env = [];

if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\"'");

        $env[$key] = $value;
    }
}

return [
    'host'    => $env['UNI_DB_HOST'] ?? '127.0.0.1',
    'dbname'  => $env['UNI_DB_NAME'] ?? 'vero_universidade',
    'user'    => $env['UNI_DB_USER'] ?? 'root',
    'pass'    => $env['UNI_DB_PASS'] ?? '',
    'charset' => 'utf8mb4',
];
