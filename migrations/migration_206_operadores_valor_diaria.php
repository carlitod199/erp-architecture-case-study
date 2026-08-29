<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_206_operadores_valor_diaria.php
   Adiciona agro_operadores.valor_diaria (R$/dia) — o "trabalhador rural"/diarista
   lança a diária direta; a calculadora de MO tira a MÉDIA das diárias dos
   colaboradores (caminho 2 "pessoas e equipe"). Idempotente.
   Rodar: php migrations/migration_206_operadores_valor_diaria.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 206: agro_operadores.valor_diaria ==\n";

$existe = false;
foreach ($pdo->query("SHOW COLUMNS FROM agro_operadores") as $r) {
    if ($r['Field'] === 'valor_diaria') { $existe = true; break; }
}
if ($existe) {
    echo "  = coluna valor_diaria já existe\n";
} else {
    $pdo->exec("ALTER TABLE agro_operadores
        ADD COLUMN valor_diaria DECIMAL(18,2) NULL DEFAULT NULL AFTER salario_mensal");
    echo "  + valor_diaria adicionada\n";
}

echo "== 206 concluída ==\n";
