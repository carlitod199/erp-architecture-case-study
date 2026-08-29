<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_183_colheita_estagios.php
   Colheita em ESTÁGIOS, espelhando o apontamento:
   o app LANÇA a colheita (status='pendente', origem='app') e o escritório
   FINALIZA no web (status='finalizada'), completando receita/estoque.
   Registros existentes e os criados no web ficam 'finalizada'/'web'.
   Idempotente (checa information_schema). Rodar:
       php migrations/migration_183_colheita_estagios.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 183: colheita em estágios ==\n";

$temCol = function (string $col) use ($pdo): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colheita_registros' AND COLUMN_NAME = :c");
    $st->execute([':c' => $col]);
    return (int)$st->fetchColumn() > 0;
};

if (!$temCol('origem')) {
    $pdo->exec("ALTER TABLE colheita_registros
                ADD COLUMN origem ENUM('web','app') NOT NULL DEFAULT 'web' AFTER observacao");
    echo "  + coluna origem\n";
} else { echo "  = origem já existe\n"; }

if (!$temCol('status')) {
    $pdo->exec("ALTER TABLE colheita_registros
                ADD COLUMN status ENUM('pendente','finalizada') NOT NULL DEFAULT 'finalizada' AFTER origem");
    echo "  + coluna status\n";
} else { echo "  = status já existe\n"; }

echo "== 183 concluída ==\n";
