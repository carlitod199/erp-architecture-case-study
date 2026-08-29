<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_177_aplicacao_data_realizada_nullable.php  (A1-BUG3 19/07;
   renumerada 176→177 pelo A0 — a 176 é a auditoria de usuarios/roles)
   C-11 (dois estágios, complemento da mig 167): na EMISSÃO de OS (DF/IF) a
   "data realizada" (agro_aplicacoes.data) NÃO existe — ela só é gravada na
   CONFIRMAÇÃO da execução. A coluna vira NULL-able; a exibição/consultas já
   usam COALESCE(ap.data, ap.data_prevista) desde a A1-26.
   Aditivo/idempotente, NO DROP. Rodar: php migrations/migration_177_aplicacao_data_realizada_nullable.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 177: agro_aplicacoes.data (realizada) NULL-able ==\n";

$nullable = (string)$pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_aplicacoes' AND COLUMN_NAME='data'")->fetchColumn();
if ($nullable === 'NO') {
    $pdo->exec("ALTER TABLE agro_aplicacoes
                MODIFY COLUMN `data` DATE NULL COMMENT 'data REALIZADA - NULL em OS emitida (preenchida na confirmacao)'");
    echo "  ok `data` agora aceita NULL\n";
} else {
    echo "  - `data` já é NULL-able\n";
}

echo "== 177 concluída ==\n";
