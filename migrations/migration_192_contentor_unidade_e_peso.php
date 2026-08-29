<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_192_contentor_unidade_e_peso.php  (WP-CALC Tarefa B / Z-05)
   (1) 'contentor' no ENUM agro_tipos_atividade.unidade_padrao (antes de 'outro').
   (2) agro_culturas.peso_contentor_kg (default 20) — fonte SEPARADA do peso da
       caixa de embalamento (peso_unidade_kg), para caixa e contentor coexistirem.
   Idempotente (checa information_schema). Par SQL de produção:
     database/migrations/2026-07-27_contentor_unidade_e_peso.sql
   Rodar: php migrations/migration_192_contentor_unidade_e_peso.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 192: unidade contentor + peso do contentor ==\n";

$tipo = (string)$pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_tipos_atividade' AND COLUMN_NAME = 'unidade_padrao'")
    ->fetchColumn();
if (stripos($tipo, 'contentor') === false) {
    $pdo->exec("ALTER TABLE agro_tipos_atividade MODIFY COLUMN unidade_padrao
        ENUM('planta','caixa','kg','ha','metro_linear','hora','cacho','fila','contentor','outro')
        COLLATE utf8mb4_unicode_ci DEFAULT NULL");
    echo "  + 'contentor' no ENUM unidade_padrao\n";
} else { echo "  = 'contentor' já está no ENUM\n"; }

$st = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_culturas' AND COLUMN_NAME = 'peso_contentor_kg'");
$st->execute();
if ((int)$st->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE agro_culturas ADD COLUMN peso_contentor_kg DECIMAL(10,3) NOT NULL DEFAULT 20.000 AFTER peso_unidade_kg");
    echo "  + agro_culturas.peso_contentor_kg (default 20)\n";
} else { echo "  = peso_contentor_kg já existe\n"; }

echo "== 192 concluída ==\n";
