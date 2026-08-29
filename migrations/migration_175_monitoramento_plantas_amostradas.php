<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_175_monitoramento_plantas_amostradas.php (C-28 · 18/07)
   mip_monitoramentos.plantas_amostradas (INT NULL) — base da consolidação
   por área (índice = encontradas ÷ amostradas × 100). Idempotente.
   Rodar: php migrations/migration_175_monitoramento_plantas_amostradas.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 175: plantas amostradas no monitoramento ==\n";
$st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mip_monitoramentos' AND COLUMN_NAME = 'plantas_amostradas'");
$st->execute();
if ((int)$st->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE mip_monitoramentos ADD COLUMN plantas_amostradas INT UNSIGNED NULL DEFAULT NULL AFTER unidade");
    echo "  ok coluna criada\n";
} else {
    echo "  ok coluna já existia\n";
}
echo "== 175 concluída ==\n";
