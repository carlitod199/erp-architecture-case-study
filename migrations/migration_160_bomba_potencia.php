<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_160_bomba_potencia.php  (consumos de irrigação da bomba)
   Adiciona potência (kW) ao cadastro de bomba, p/ o apontamento de irrigação
   calcular energia = potência × horas (água já sai de vazão × horas).
   Decisão gestor 16/07: fonte = BOMBA; consumo auto-preenchido e editável.
   Aditivo, idempotente. Rodar: php migrations/migration_160_bomba_potencia.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 160: agro_bombas.potencia_kw ==\n";
$existe = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_bombas' AND COLUMN_NAME='potencia_kw'")->fetchColumn();
if (!$existe) {
    $pdo->exec("ALTER TABLE agro_bombas ADD COLUMN potencia_kw DECIMAL(10,2) NULL AFTER vazao_m3h");
    echo "  ok potencia_kw (DECIMAL kW) — energia = potencia_kw x horas\n";
} else {
    echo "  - potencia_kw já existe\n";
}
echo "== 160 concluída ==\n";
