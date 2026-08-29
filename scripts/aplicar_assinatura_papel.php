<?php
declare(strict_types=1);
/* Aplica migrations/2026-07-23_assinatura_papel.sql (coluna papel na assinatura
   de aplicação). Idempotente. Uso: php scripts/aplicar_assinatura_papel.php */

$cfg = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4',
    $cfg['user'], $cfg['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$tem = $pdo->query("SHOW COLUMNS FROM agro_aplicacao_assinaturas LIKE 'papel'")->fetch();
if ($tem) {
    echo "coluna papel já existe — nada a fazer\n";
} else {
    $pdo->exec("ALTER TABLE agro_aplicacao_assinaturas ADD COLUMN papel VARCHAR(20) NOT NULL DEFAULT 'operador' AFTER operador_nome");
    echo "coluna papel adicionada\n";
}
