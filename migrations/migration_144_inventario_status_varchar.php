<?php
declare(strict_types=1);

// ============================================================================
// migration_144_inventario_status_varchar.php | VERO — A0 (05/07/2026)
//   Ativa a aprovação em 2 passos do inventário (A2-F2-18, dormente):
//   estoque_inventarios.status era ENUM legado sem 'contado' — sql_mode
//   não-strict gravaria '' (corrupção silenciosa, caso compras_cotacoes).
//   Regra permanente: categórico = VARCHAR + validação PHP.
//   ENUM('aberto','em_contagem','concluido','cancelado') → VARCHAR(15)
//   (valores existentes preservados; novos: 'contado' + demais na tela).
// Idempotente. Backup: backup_pre_144_*.sql.
// ============================================================================

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$log = fn(string $m) => print($m . "\n");

$log("== migration 144 — inventário: status ENUM → VARCHAR (2 passos ativos) ==");
$tipo = $pdo->query("SELECT column_type FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'estoque_inventarios' AND column_name = 'status'")->fetchColumn();
if (str_starts_with((string)$tipo, 'enum')) {
    $pdo->exec("ALTER TABLE estoque_inventarios MODIFY status VARCHAR(15) NOT NULL DEFAULT 'aberto'
                COMMENT 'aberto|em_contagem|contado|concluido|cancelado — VARCHAR validado em PHP (A2-F2-18)'");
    $log("  ~ status: $tipo → varchar(15)");
} else { $log("  = já é $tipo"); }
$log("== migration 144 concluída ==");
