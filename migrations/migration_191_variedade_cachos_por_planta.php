<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_191_variedade_cachos_por_planta.php  (WP-CALC Tarefa A / Z-06 / W-02)
   "Cachos por planta" na VARIEDADE — destrava o raleio
   (unidade 'cacho') na calculadora de MO: trabalho = num_plantas do talhão
   × cachos_por_planta da variedade da válvula (agro_talhoes.variedade_id).
   Idempotente (checa information_schema). Par SQL de produção:
     database/migrations/2026-07-27_variedade_cachos_por_planta.sql
   Rodar: php migrations/migration_191_variedade_cachos_por_planta.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 191: variedade cachos_por_planta ==\n";

$st = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_variedades' AND COLUMN_NAME = 'cachos_por_planta'");
$st->execute();
if ((int)$st->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE agro_variedades ADD COLUMN cachos_por_planta DECIMAL(10,2) NULL AFTER produtividade_esperada");
    echo "  + cachos_por_planta\n";
} else { echo "  = cachos_por_planta já existe\n"; }

echo "== 191 concluída ==\n";
