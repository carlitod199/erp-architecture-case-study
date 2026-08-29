<?php
declare(strict_types=1);
/* VERO — teste de conexão do banco (P-03): valida config/database.php SEM
   expor a senha. Uso: php scripts/db_ping.php  → "OK" ou o erro. */
if (PHP_SAPI !== 'cli') exit("Somente CLI.\n");
$c = require __DIR__ . '/../config/database.php';
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['dbname'], $c['charset'] ?? 'utf8mb4'),
        $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
    );
    $v = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "OK — conectado a {$c['host']}/{$c['dbname']} (MySQL {$v})\n";
} catch (Throwable $e) {
    echo "FALHA — " . $e->getMessage() . "\n";
    exit(1);
}
