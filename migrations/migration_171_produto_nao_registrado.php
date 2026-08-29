<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_171_produto_nao_registrado.php  (reunião · item 9.1 · Ivanildo)
   Flag "produto não registrado" em estoque_produtos — exigência de clientes
   (certificação/GlobalGAP): marcar explicitamente produtos sem registro MAPA
   (distinto de cadastro incompleto). Boolean. Aditivo/idempotente, NO DROP.
   Rodar: php migrations/migration_171_produto_nao_registrado.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 171: estoque_produtos.nao_registrado ==\n";
$existe = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='estoque_produtos' AND COLUMN_NAME='nao_registrado'")->fetchColumn();
if (!$existe) {
    $pdo->exec("ALTER TABLE estoque_produtos ADD COLUMN nao_registrado TINYINT(1) NOT NULL DEFAULT 0 AFTER registro_mapa");
    echo "  ok nao_registrado (TINYINT default 0)\n";
} else {
    echo "  - nao_registrado já existe\n";
}
echo "== 171 concluída ==\n";
