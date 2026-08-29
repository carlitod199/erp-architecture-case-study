<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_163_monitoramento_local.php  (reunião 16/07 · item 8.4)
   Local de infestação no monitoramento: folha | ramo | cacho (onde o alvo
   foi encontrado). Categórico = VARCHAR + validação PHP. A quantidade de
   unidades encontradas já existe (quantidade_encontrada). Aditivo, idempotente.
   Rodar: php migrations/migration_163_monitoramento_local.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 163: mip_monitoramentos.local_infestacao ==\n";
$existe = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mip_monitoramentos' AND COLUMN_NAME='local_infestacao'")->fetchColumn();
if (!$existe) {
    $pdo->exec("ALTER TABLE mip_monitoramentos ADD COLUMN local_infestacao VARCHAR(20) NULL AFTER quantidade_encontrada");
    echo "  ok local_infestacao (VARCHAR) — valores: folha|ramo|cacho (validado em PHP)\n";
} else {
    echo "  - local_infestacao já existe\n";
}
echo "== 163 concluída ==\n";
