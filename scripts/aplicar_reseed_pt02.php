<?php
/* ============================================================
   VERO — scripts/aplicar_reseed_pt02.php
   Aplica database/migrations/2026-07-20_reseed_pt02_operador.sql:
   reinsere na role `operador` os 3 slugs de AÇÃO do PT-02 que o
   reseed de perfis C-25 (18/07) removeu. Idempotente — rodar de
   novo não duplica. Uso: php scripts/aplicar_reseed_pt02.php
   ============================================================ */
declare(strict_types=1);

$cfg = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4',
    $cfg['user'],
    $cfg['pass'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$sql = file_get_contents(__DIR__ . '/../database/migrations/2026-07-20_reseed_pt02_operador.sql');
$n = $pdo->exec($sql);
echo "Grants reinseridos: {$n}\n";

foreach (['mip.alertas_fitossanitarios.editar',
          'mip.aplicacoes_defensivos.editar',
          'maquinas.horimetro.editar'] as $slug) {
    $tem = $pdo->query(
        "SELECT COUNT(*) FROM roles r
           JOIN role_permissions rp ON rp.role_id = r.id
           JOIN permissions p ON p.id = rp.permission_id
          WHERE r.slug = 'operador' AND p.slug = " . $pdo->quote($slug)
    )->fetchColumn();
    echo $slug . ' => ' . ($tem ? 'OK' : 'FALTA') . "\n";
}
