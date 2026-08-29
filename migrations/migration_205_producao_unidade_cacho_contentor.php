<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_205_producao_unidade_cacho_contentor.php
   rh_producao_itens.unidade ganhava truncação (SQLSTATE 01000 / 1265 "Data
   truncated for column 'unidade'") ao FINALIZAR apontamento de atividade cujo
   unidade_padrao é 'cacho' ou 'contentor' — o enum não os tinha. Adiciona os dois.
   Idempotente. Rodar: php migrations/migration_205_producao_unidade_cacho_contentor.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 205: rh_producao_itens.unidade +cacho +contentor ==\n";

$tipo = '';
foreach ($pdo->query("SHOW COLUMNS FROM rh_producao_itens") as $r) {
    if ($r['Field'] === 'unidade') { $tipo = (string)$r['Type']; break; }
}
if (stripos($tipo, "'cacho'") !== false && stripos($tipo, "'contentor'") !== false) {
    echo "  = enum já contém cacho e contentor\n";
} else {
    $pdo->exec("ALTER TABLE rh_producao_itens
        MODIFY COLUMN unidade ENUM('planta','caixa','kg','ha','metro_linear','hora','cacho','contentor','outro') NULL DEFAULT NULL");
    echo "  + cacho e contentor adicionados ao enum\n";
}

echo "== 205 concluída ==\n";
