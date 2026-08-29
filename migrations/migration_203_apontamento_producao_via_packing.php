<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_203_apontamento_producao_via_packing.php
   Flag no apontamento de colheita: produção das pessoas apurada no PACKING
   (leitura de crachá/caixa) em vez de digitada. Com o flag ativo, a grade
   manual em /agro/apontamentos fica read-only e é preenchida pelas leituras.
   Idempotente. Rodar: php migrations/migration_203_apontamento_producao_via_packing.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 203: agro_apontamentos.producao_via_packing ==\n";

$temColuna = static function (PDO $pdo, string $tab, string $col): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $st->execute([':t' => $tab, ':c' => $col]);
    return (int)$st->fetchColumn() > 0;
};

if (!$temColuna($pdo, 'agro_apontamentos', 'producao_via_packing')) {
    $pdo->exec(
        "ALTER TABLE agro_apontamentos
           ADD COLUMN producao_via_packing TINYINT(1) NOT NULL DEFAULT 0 AFTER origem");
    echo "  + coluna producao_via_packing criada\n";
} else {
    echo "  = coluna producao_via_packing já existe\n";
}

echo "== 203 concluída ==\n";
