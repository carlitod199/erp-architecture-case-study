<?php
declare(strict_types=1);

// ============================================================================
// migration_145_venda_lote.php | VERO — Pacote T27a (A0, 05/07/2026)
//   DB-50: comercial_vendas.lote_id (NULL, aditiva — análise A3-T27 validada;
//   P-87..89 respondidas). colheita_registro_id MANTIDO (derivado do lote nas
//   vendas novas; legadas híbridas com lote NULL = fluxo antigo, P-87).
//   A baixa em si é a T27b (tela A3) via vero_srv_estoque_saida($loteId).
// Idempotente. Backup: backup_pre_145_*.sql.
// ============================================================================

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$log = fn(string $m) => print($m . "\n");
$columnExists = function (string $t, string $c) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$t, $c]);
    return (bool)$st->fetchColumn();
};
$fkExists = function (string $n) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE table_schema = DATABASE() AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'");
    $st->execute([$n]);
    return (bool)$st->fetchColumn();
};

$log("== migration 145 — DB-50 venda × lote (T27a) ==");
if (!$columnExists('comercial_vendas', 'lote_id')) {
    $pdo->exec("ALTER TABLE comercial_vendas ADD COLUMN lote_id BIGINT UNSIGNED NULL
                COMMENT 'lote (COLH-) baixado pela venda — NULL = venda legada/hibrida (P-87)'");
    $log("  + comercial_vendas.lote_id");
} else { $log("  = lote_id já existe"); }
if (!$fkExists('fk_venda_lote')) {
    $pdo->exec("ALTER TABLE comercial_vendas ADD CONSTRAINT fk_venda_lote FOREIGN KEY (lote_id) REFERENCES estoque_lotes (id)");
    $log("  + FK fk_venda_lote");
} else { $log("  = FK já existe"); }
$log("== migration 145 concluída ==");
