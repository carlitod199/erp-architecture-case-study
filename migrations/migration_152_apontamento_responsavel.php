<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_152_apontamento_responsavel.php
   #15 (P-105, cliente 08/07): o apontamento passa a ter um RESPONSÁVEL
   pela frente/operação — distinto de created_by (quem digitou) e dos
   operadores (quem executou). Cliente respondeu: SEMPRE OBRIGATÓRIO
   (validado no POST; sai no impresso/certificação).
     + agro_apontamentos.responsavel_id  BIGINT UNSIGNED NULL
       (NULL nas linhas ANTIGAS — a obrigatoriedade vale para os novos/
        editados, imposta na tela; a coluna nasce nullable p/ não quebrar
        a massa existente)
     + índice (tenant_id, responsavel_id)
     + FK → agro_operadores(id) ON DELETE SET NULL (o encarregado é um
       colaborador; mesma referência de operador_id)
   Aditivo e idempotente (checa information_schema).
   Rodar: php migrations/migration_152_apontamento_responsavel.php
   ============================================================ */

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'],
    $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$db = $config['dbname'];

echo "== migration 152: responsável do apontamento (#15 / P-105) ==\n";

$colExiste = static function (PDO $pdo, string $db, string $tab, string $col): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute([':d' => $db, ':t' => $tab, ':c' => $col]);
    return (bool)$q->fetchColumn();
};
$idxExiste = static function (PDO $pdo, string $db, string $tab, string $idx): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND INDEX_NAME=:i");
    $q->execute([':d' => $db, ':t' => $tab, ':i' => $idx]);
    return (bool)$q->fetchColumn();
};
$fkExiste = static function (PDO $pdo, string $db, string $tab, string $fk): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                         WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND CONSTRAINT_NAME=:c
                           AND CONSTRAINT_TYPE='FOREIGN KEY'");
    $q->execute([':d' => $db, ':t' => $tab, ':c' => $fk]);
    return (bool)$q->fetchColumn();
};

foreach (['agro_apontamentos', 'agro_operadores'] as $t) {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t");
    $q->execute([':d' => $db, ':t' => $t]);
    if (!$q->fetchColumn()) { fwrite(STDERR, "ABORTA: tabela {$t} não existe.\n"); exit(1); }
}

/* 1) coluna */
if (!$colExiste($pdo, $db, 'agro_apontamentos', 'responsavel_id')) {
    $pdo->exec("ALTER TABLE agro_apontamentos
                ADD COLUMN responsavel_id BIGINT UNSIGNED NULL AFTER operador_id");
    echo "  + coluna responsavel_id\n";
} else {
    echo "  = coluna responsavel_id já existe\n";
}

/* 2) índice */
if (!$idxExiste($pdo, $db, 'agro_apontamentos', 'ix_apont_tenant_resp')) {
    $pdo->exec("ALTER TABLE agro_apontamentos
                ADD INDEX ix_apont_tenant_resp (tenant_id, responsavel_id)");
    echo "  + índice ix_apont_tenant_resp\n";
} else {
    echo "  = índice já existe\n";
}

/* 3) FK → agro_operadores(id) ON DELETE SET NULL */
if (!$fkExiste($pdo, $db, 'agro_apontamentos', 'fk_apont_responsavel')) {
    $pdo->exec("ALTER TABLE agro_apontamentos
                ADD CONSTRAINT fk_apont_responsavel
                FOREIGN KEY (responsavel_id) REFERENCES agro_operadores(id)
                ON DELETE SET NULL ON UPDATE CASCADE");
    echo "  + FK fk_apont_responsavel (ON DELETE SET NULL)\n";
} else {
    echo "  = FK já existe\n";
}

echo "== 152 concluída ==\n";
