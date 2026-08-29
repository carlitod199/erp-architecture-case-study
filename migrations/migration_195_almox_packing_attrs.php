<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_195_almox_packing_attrs.php  (Packing House Onda 1 · tarefa 2)
   Atributos industriais da UNIDADE de packing no próprio `almoxarifados`
   (Decisão 1 da §0.4: unidade = almoxarifado tipo='packing', sem ph_unidades).
   Colunas nullable, só relevantes para tipo='packing'; aditivo, idempotente.
   Rodar: php migrations/migration_195_almox_packing_attrs.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 195: almoxarifados — atributos de unidade de packing ==\n";

$cols = [
    'ggn'              => "VARCHAR(13) NULL COMMENT 'GLOBALG.A.P. Number (unidade de packing)'",
    'registro_mapa_uc' => "VARCHAR(40) NULL COMMENT 'registro MAPA da Unidade de Consolidacao'",
    'codigo_gacc'      => "VARCHAR(40) NULL COMMENT 'codigo de registro GACC (China)'",
    'gln'              => "VARCHAR(13) NULL COMMENT 'GS1 Global Location Number'",
    'prefixo_gs1'      => "VARCHAR(12) NULL COMMENT 'prefixo de empresa GS1'",
];
$st = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'almoxarifados' AND COLUMN_NAME = :c");
foreach ($cols as $nome => $def) {
    $st->execute([':c' => $nome]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE almoxarifados ADD COLUMN {$nome} {$def}");
        echo "  + almoxarifados.{$nome}\n";
    } else {
        echo "  = {$nome} já existe\n";
    }
}
echo "== 195 concluída ==\n";
