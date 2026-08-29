<?php
declare(strict_types=1);

// ============================================================================
// migration_142_estoque_governanca.php | VERO — Pacote A0-16 (05/07/2026)
//   Governança do estoque (auditoria A0-15 / EST-001..025):
//   DB-47: estoque_inventarios.aprovado_por/aprovado_em (EST-014 — aprovação
//          em 2 passos; tela A2-F2-18)
//   Higiene EST-002: os 5 movimentos LEGADOS sem origem_tipo (seed 03/07)
//          recebem origem_tipo='manual' + marca na observação (dado preservado)
//   Trava de período (EST-018) NÃO entra aqui — aguarda P-81 do cliente.
// Idempotente. Backup: backup_pre_142_*.sql.
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

$log("== migration 142 — governança do estoque (A0-16) ==");

$log("[DB-47] estoque_inventarios: aprovação");
foreach ([
    'aprovado_por' => "BIGINT UNSIGNED NULL COMMENT 'EST-014: aprovador do ajuste (2 passos — tela A2-F2-18)'",
    'aprovado_em' => "DATETIME NULL",
] as $col => $def) {
    if (!$columnExists('estoque_inventarios', $col)) {
        $pdo->exec("ALTER TABLE estoque_inventarios ADD COLUMN $col $def");
        $log("  + estoque_inventarios.$col");
    } else { $log("  = $col já existe"); }
}

$log("[EST-002] rotular movimentos legados sem origem");
$n = $pdo->exec("UPDATE estoque_movimentacoes
    SET origem_tipo = 'manual',
        observacao = CONCAT(COALESCE(observacao,''), ' [origem rotulada manual — legado seed, A0-16]')
    WHERE origem_tipo IS NULL OR origem_tipo = ''");
$log($n > 0 ? "  ~ $n movimento(s) rotulado(s) como manual" : "  = nenhum movimento sem origem");

$log("== migration 142 concluída ==");
