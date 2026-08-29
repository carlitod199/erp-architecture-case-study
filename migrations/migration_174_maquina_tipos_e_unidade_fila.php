<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_174_maquina_tipos_e_unidade_fila.php  (C-14 + C-45 · 18/07)
   C-14: maquinas.tipo += estercadeira/rocadeira/bandejao.
   C-45: agro_tipos_atividade.unidade_padrao += 'fila'.
   ENUM aditivo (valores existentes preservados na mesma ordem). Idempotente.
   Rodar: php migrations/migration_174_maquina_tipos_e_unidade_fila.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 174: tipos de máquina + unidade fila ==\n";

$tipoCol = static function (PDO $pdo, string $tabela, string $coluna): string {
    $st = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $st->execute([':t' => $tabela, ':c' => $coluna]);
    return (string)$st->fetchColumn();
};

if (!str_contains($tipoCol($pdo, 'maquinas', 'tipo'), 'estercadeira')) {
    $pdo->exec("ALTER TABLE maquinas MODIFY COLUMN tipo
        ENUM('trator','colheitadeira','pulverizador','implemento','veiculo','estercadeira','rocadeira','bandejao','outro')
        NOT NULL DEFAULT 'trator'");
    echo "  ok maquinas.tipo += estercadeira/rocadeira/bandejao\n";
} else {
    echo "  ok maquinas.tipo já tinha os tipos novos\n";
}

if (!str_contains($tipoCol($pdo, 'agro_tipos_atividade', 'unidade_padrao'), 'fila')) {
    $pdo->exec("ALTER TABLE agro_tipos_atividade MODIFY COLUMN unidade_padrao
        ENUM('planta','caixa','kg','ha','metro_linear','hora','cacho','fila','outro') NULL DEFAULT NULL");
    echo "  ok unidade_padrao += fila\n";
} else {
    echo "  ok unidade_padrao já tinha fila\n";
}

echo "== 174 concluída ==\n";
