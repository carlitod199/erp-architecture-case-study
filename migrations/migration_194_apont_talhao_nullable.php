<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_194_apont_talhao_nullable.php  (Sprint Zero packing #3)
   Torna agro_apontamentos.talhao_id NULLABLE. Preparação para o módulo
   Packing House: um apontamento de packing não tem válvula/talhão.
   NÃO muda comportamento atual — toda linha existente tem talhão, e a
   validação em agro/apontamentos.php:174 continua exigindo talhão para as
   categorias agrícolas (a dispensa por categoria será feita quando a
   categoria 'packing' existir de fato, ver comentário no handler).
   Idempotente (checa information_schema.IS_NULLABLE).
   Rodar: php migrations/migration_194_apont_talhao_nullable.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 194: agro_apontamentos.talhao_id nullable ==\n";

$nullable = (string)$pdo->query(
    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_apontamentos' AND COLUMN_NAME = 'talhao_id'")
    ->fetchColumn();

if ($nullable === 'NO') {
    $pdo->exec("ALTER TABLE agro_apontamentos MODIFY COLUMN talhao_id BIGINT UNSIGNED NULL");
    echo "  ~ talhao_id agora aceita NULL\n";
} elseif ($nullable === '') {
    echo "  ! coluna talhao_id não encontrada — verifique agro_apontamentos\n";
} else {
    echo "  = talhao_id já é nullable\n";
}

echo "== 194 concluída ==\n";
