<?php

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
    'host'    => $env['DB_HOST'] ?? 'localhost',
    'dbname'  => $env['DB_NAME'] ?? 'vero_db',
    'user'    => $env['DB_USER'] ?? 'vero_user',
    'pass'    => $env['DB_PASS'] ?? '',
    'charset' => 'utf8mb4',
];
