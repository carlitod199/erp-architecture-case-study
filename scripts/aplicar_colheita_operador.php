<?php
declare(strict_types=1);
/* Aplica migrations/2026-07-23_seed_colheita_operador.sql (colheita de campo:
   operador ganha agro.colheita.editar). Idempotente — rodar quantas vezes quiser.
   Uso: php scripts/aplicar_colheita_operador.php */

$cfg = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4',
    $cfg['user'],
    $cfg['pass'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$sql = file_get_contents(__DIR__ . '/../migrations/2026-07-23_seed_colheita_operador.sql');
if ($sql === false) {
    fwrite(STDERR, "migration não encontrada\n");
    exit(1);
}
// remove comentários e executa o statement único
$sql = preg_replace('/^--.*$/m', '', $sql);
$n = $pdo->exec(trim($sql));
echo "grants inseridos: {$n}\n";

$tem = $pdo->query(
    "SELECT COUNT(*) FROM role_permissions rp
      JOIN roles r ON r.id = rp.role_id
      JOIN permissions p ON p.id = rp.permission_id
     WHERE r.slug IN ('operador','encarregado') AND p.slug = 'agro.colheita.editar'"
)->fetchColumn();
echo 'operador tem agro.colheita.editar: ' . ($tem ? 'SIM' : 'NÃO') . "\n";
