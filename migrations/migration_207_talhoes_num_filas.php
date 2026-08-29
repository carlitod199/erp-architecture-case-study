<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_207_talhoes_num_filas.php
   "Fila" (fileira): algumas atividades (amarrio, desponte, degrana…) são
   quantificadas pelas FILAS (fileiras) da válvula, não pelas plantas. Adiciona:
     - agro_talhoes.num_filas (total de filas da válvula);
     - unidade 'fila' em rh_producao_itens.unidade (senão o apontamento trunca
       ao gravar a produção). agro_tipos_atividade.unidade_padrao já tem 'fila'.
   Enquanto num_filas estiver vazio, a calculadora cai no nº de plantas (fallback).
   Idempotente. Rodar: php migrations/migration_207_talhoes_num_filas.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 207: filas/fileiras ==\n";

/* 1) agro_talhoes.num_filas */
$temFilas = false;
foreach ($pdo->query("SHOW COLUMNS FROM agro_talhoes") as $r) {
    if ($r['Field'] === 'num_filas') { $temFilas = true; break; }
}
if ($temFilas) {
    echo "  = agro_talhoes.num_filas já existe\n";
} else {
    $pdo->exec("ALTER TABLE agro_talhoes ADD COLUMN num_filas INT NULL DEFAULT NULL AFTER num_plantas");
    echo "  + agro_talhoes.num_filas adicionada\n";
}

/* 2) 'fila' no enum de agro_tipos_atividade.unidade_padrao (normalmente já existe) */
$tipoUP = '';
foreach ($pdo->query("SHOW COLUMNS FROM agro_tipos_atividade") as $r) {
    if ($r['Field'] === 'unidade_padrao') { $tipoUP = (string)$r['Type']; break; }
}
if (stripos($tipoUP, "'fila'") !== false) {
    echo "  = unidade_padrao já tem 'fila'\n";
} else {
    $pdo->exec("ALTER TABLE agro_tipos_atividade
        MODIFY COLUMN unidade_padrao ENUM('planta','caixa','kg','ha','metro_linear','hora','cacho','fila','contentor','outro') NULL DEFAULT NULL");
    echo "  + 'fila' em agro_tipos_atividade.unidade_padrao\n";
}

/* 3) 'fila' no enum de rh_producao_itens.unidade (evita truncation ao finalizar) */
$prodUP = '';
foreach ($pdo->query("SHOW COLUMNS FROM rh_producao_itens") as $r) {
    if ($r['Field'] === 'unidade') { $prodUP = (string)$r['Type']; break; }
}
if (stripos($prodUP, "'fila'") !== false) {
    echo "  = rh_producao_itens.unidade já tem 'fila'\n";
} else {
    $pdo->exec("ALTER TABLE rh_producao_itens
        MODIFY COLUMN unidade ENUM('planta','caixa','kg','ha','metro_linear','hora','cacho','contentor','fila','outro') NULL DEFAULT NULL");
    echo "  + 'fila' em rh_producao_itens.unidade\n";
}

echo "== 207 concluída ==\n";
