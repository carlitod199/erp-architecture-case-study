<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_178_apontamento_planejamento_mo.php
   V-01/V-02: a meta, a premiação e o planejamento digitados na
   CRIAÇÃO do apontamento (calculadora de MO) não podem se perder ao "Iniciar".
   Guarda o snapshot do planejamento no próprio apontamento (JSON) para reabrir
   na finalização e semear a meta/valor das linhas de produção.
   Estrutura: {total, unidade, base, dias, pessoas, meta, premio}.
   Aditivo/idempotente, NO DROP. Rodar: php migrations/migration_178_apontamento_planejamento_mo.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 178: agro_apontamentos.planejamento_mo (JSON snapshot da calc) ==\n";

$existe = (string)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_apontamentos' AND COLUMN_NAME='planejamento_mo'")->fetchColumn();
if ($existe === '0') {
    $pdo->exec("ALTER TABLE agro_apontamentos
                ADD COLUMN `planejamento_mo` JSON NULL
                COMMENT 'snapshot da calculadora de MO na criacao (total/unidade/base/dias/pessoas/meta/premio) — V-01/V-02'");
    echo "  ok coluna planejamento_mo criada\n";
} else {
    echo "  - coluna planejamento_mo ja existe\n";
}

echo "== 178 concluida ==\n";
