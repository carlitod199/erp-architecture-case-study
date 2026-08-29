<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_168_variedade_fase_calda.php  (gestor 17/07)
   Volume de CALDA (L/ha) por FASE da fenologia da variedade — a calda cresce
   com o dossel, então varia por fase (como o volume_mm_dia de irrigação). A DF
   de pulverização puxa esse valor pela fase resolvida na data.
   Aditivo/idempotente, NO DROP. Rodar: php migrations/migration_168_variedade_fase_calda.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 168: agro_variedade_fases.volume_calda_ha_l ==\n";
$existe = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_variedade_fases' AND COLUMN_NAME='volume_calda_ha_l'")->fetchColumn();
if (!$existe) {
    $pdo->exec("ALTER TABLE agro_variedade_fases ADD COLUMN volume_calda_ha_l DECIMAL(10,2) NULL AFTER volume_mm_dia");
    echo "  ok volume_calda_ha_l (DECIMAL L/ha) por fase\n";
} else {
    echo "  - volume_calda_ha_l já existe\n";
}
echo "== 168 concluída ==\n";
