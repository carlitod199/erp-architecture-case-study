<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_185_irrigacao_horas_vazao.php
   Planejamento de irrigação: horas de irrigação + vazão (reunião 23/07,
   X-08). "Trava" por m³ do Vale = vazão (m³/h) × horas. A vazão é puxada da
   bomba da válvula (agro_setores → agro_bomba_valvulas → agro_bombas.vazao_m3h),
   mas fica editável e gravada no planejamento p/ histórico.
   Idempotente (checa information_schema). Rodar:
       php migrations/migration_185_irrigacao_horas_vazao.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 185: irrigação horas/vazão ==\n";

$temCol = function (string $col) use ($pdo): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'irrigacao_planejamentos' AND COLUMN_NAME = :c");
    $st->execute([':c' => $col]);
    return (int)$st->fetchColumn() > 0;
};

if (!$temCol('horas_irrigacao')) {
    $pdo->exec("ALTER TABLE irrigacao_planejamentos ADD COLUMN horas_irrigacao DECIMAL(10,2) NULL AFTER lamina_mm");
    echo "  + horas_irrigacao\n";
} else { echo "  = horas_irrigacao já existe\n"; }

if (!$temCol('vazao_m3h')) {
    $pdo->exec("ALTER TABLE irrigacao_planejamentos ADD COLUMN vazao_m3h DECIMAL(10,2) NULL AFTER horas_irrigacao");
    echo "  + vazao_m3h\n";
} else { echo "  = vazao_m3h já existe\n"; }

echo "== 185 concluída ==\n";
