<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_156_variedade_cor_baga.php  (ajustes 15/07 · item 3b)
   Tipo de uva de mesa por COR da baga (vermelha/branca/preta).
   Categórico = VARCHAR + validação em PHP (MySQL 5.7 não-estrito) — convenção do projeto.
   Aditivo, idempotente. Rodar: php migrations/migration_156_variedade_cor_baga.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 156: agro_variedades.cor_baga ==\n";

$existe = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_variedades' AND COLUMN_NAME = 'cor_baga'")->fetchColumn();
if (!$existe) {
    $pdo->exec("ALTER TABLE agro_variedades ADD COLUMN cor_baga VARCHAR(20) NULL AFTER tipo_uso");
    echo "  ok cor_baga (VARCHAR(20) NULL) — valores válidos: vermelha|branca|preta (validado em PHP)\n";
} else {
    echo "  - cor_baga já existe\n";
}
echo "== 156 concluída ==\n";
