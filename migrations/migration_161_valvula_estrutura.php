<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_161_valvula_estrutura.php  (reunião 16/07 · item 4.2)
   Estrutura do sistema de condução na válvula/talhão: latada | espaldeira | Y.
   Categórico = VARCHAR + validação PHP (convenção). Aditivo, idempotente.
   Rodar: php migrations/migration_161_valvula_estrutura.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 161: agro_talhoes.estrutura_sistema ==\n";
$existe = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_talhoes' AND COLUMN_NAME='estrutura_sistema'")->fetchColumn();
if (!$existe) {
    $pdo->exec("ALTER TABLE agro_talhoes ADD COLUMN estrutura_sistema VARCHAR(20) NULL AFTER variedade_id");
    echo "  ok estrutura_sistema (VARCHAR NULL) — valores: latada|espaldeira|y (validado em PHP)\n";
} else {
    echo "  - estrutura_sistema já existe\n";
}
echo "== 161 concluída ==\n";
