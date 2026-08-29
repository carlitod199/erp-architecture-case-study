<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_173_monitoramento_alvo_local.php  (C-27 · reunião 18/07)
   MESMO alvo em LOCAIS diferentes no monitoramento MIP: a UNIQUE da junção
   mip_monitoramento_alvos passa de (tenant, mon, alvo) para
   (tenant, mon, alvo, local_infestacao). Cria a nova antes de derrubar a
   antiga. Idempotente. Local NULL: duplicata é barrada no código da tela.
   Rodar: php migrations/migration_173_monitoramento_alvo_local.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 173: UNIQUE alvo+local no monitoramento ==\n";

$idx = static function (PDO $pdo, string $nome): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mip_monitoramento_alvos' AND INDEX_NAME = :n");
    $st->execute([':n' => $nome]);
    return (int)$st->fetchColumn() > 0;
};

if (!$idx($pdo, 'uq_mon_alvo_local')) {
    $pdo->exec("ALTER TABLE mip_monitoramento_alvos
        ADD UNIQUE KEY uq_mon_alvo_local (tenant_id, monitoramento_id, alvo_id, local_infestacao)");
    echo "  ok criada uq_mon_alvo_local\n";
} else {
    echo "  ok uq_mon_alvo_local já existe\n";
}

if ($idx($pdo, 'uq_mon_alvo')) {
    $pdo->exec("ALTER TABLE mip_monitoramento_alvos DROP INDEX uq_mon_alvo");
    echo "  ok removida uq_mon_alvo (antiga, sem local)\n";
} else {
    echo "  ok uq_mon_alvo já removida\n";
}

echo "== 173 concluída ==\n";
