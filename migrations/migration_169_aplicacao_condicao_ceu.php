<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_169_aplicacao_condicao_ceu.php  (reunião · item 6.8)
   Condição climática CATEGÓRICA na aplicação (DF): sol | noite | nublado | chuva.
   Distinta de condicao_climatica (JSON com vento/temp/umidade). VARCHAR +
   whitelist em PHP. Aditivo/idempotente, NO DROP.
   Rodar: php migrations/migration_169_aplicacao_condicao_ceu.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 169: agro_aplicacoes.condicao_ceu ==\n";
$existe = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_aplicacoes' AND COLUMN_NAME='condicao_ceu'")->fetchColumn();
if (!$existe) {
    $pdo->exec("ALTER TABLE agro_aplicacoes ADD COLUMN condicao_ceu VARCHAR(20) NULL AFTER condicao_climatica");
    echo "  ok condicao_ceu (VARCHAR) — valores: sol|noite|nublado|chuva (validado em PHP)\n";
} else {
    echo "  - condicao_ceu já existe\n";
}
echo "== 169 concluída ==\n";
