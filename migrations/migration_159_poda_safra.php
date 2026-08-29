<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_159_poda_safra.php  (Onda 5 · item 2d)
   Estado de PODA por VÁLVULA no vínculo safra↔talhão (decisões gestor 15/07):
   safra agrupa válvulas; cada válvula tem seu dia 0 = último apontamento de poda;
   estado explícito 'pendente'|'finalizada' + trilha de confirmação (auditável).
   O resolver da fenologia passa a usar agro_safra_talhoes.data_poda como dia 0.
   Aditivo, idempotente. Rodar: php migrations/migration_159_poda_safra.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 159: estado de poda por válvula (agro_safra_talhoes) ==\n";

$col = function (string $c) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_safra_talhoes' AND COLUMN_NAME=:c");
    $st->execute([':c' => $c]);
    return (int)$st->fetchColumn() > 0;
};
$add = [
    'data_poda'           => "ADD COLUMN data_poda DATE NULL",                                   // dia 0 da fenologia (último apontamento de poda)
    'poda_status'         => "ADD COLUMN poda_status VARCHAR(20) NOT NULL DEFAULT 'pendente'",    // pendente | finalizada (validado em PHP)
    'poda_confirmada_em'  => "ADD COLUMN poda_confirmada_em DATETIME NULL",
    'poda_confirmada_por' => "ADD COLUMN poda_confirmada_por BIGINT UNSIGNED NULL",
];
foreach ($add as $c => $sql) {
    if (!$col($c)) { $pdo->exec("ALTER TABLE agro_safra_talhoes {$sql}"); echo "  ok {$c}\n"; }
    else echo "  - {$c} já existe\n";
}
echo "  (permissões agro.safra.* registradas no catálogo em includes/permissions.php)\n";
echo "== 159 concluída ==\n";
