<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_172_regra_premiacao_meta_valor_nullable.php  (itens 5.1/5.3)
   Premiação-na-OS: meta e valor SAEM do cadastro de regra (mudam dia a dia) e
   passam a ser informados no apontamento (por linha). As colunas meta_qtd e
   valor_acima_meta são RELAXADAS para NULL (mantidas p/ histórico; NUNCA drop).
   Aditivo/idempotente. Rodar: php migrations/migration_172_regra_premiacao_meta_valor_nullable.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 172: rh_regras_premiacao meta/valor NULLABLE ==\n";
foreach ([
    'meta_qtd'         => 'DECIMAL(12,3)',
    'valor_acima_meta' => 'DECIMAL(18,6)',
] as $col => $tipo) {
    $nul = (string)$pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rh_regras_premiacao' AND COLUMN_NAME='$col'")->fetchColumn();
    if ($nul === 'NO') {
        $pdo->exec("ALTER TABLE rh_regras_premiacao MODIFY COLUMN $col $tipo NULL");
        echo "  ok $col -> NULL\n";
    } else {
        echo "  - $col já é NULL\n";
    }
}
echo "== 172 concluída ==\n";
