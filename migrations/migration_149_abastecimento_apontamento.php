<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_149_abastecimento_apontamento.php
   Contrato A1-56 (arbitrado A0, 08/07): vínculo abastecimento↔apontamento.
   Opção A (FK direto, sem tabela de ligação):
     + maquina_abastecimentos.apontamento_id  BIGINT UNSIGNED NULL
       (NULL = reabastecimento avulso/nível-máquina, o default — P-125a;
        set = combustível atribuído à operação → válvula/safra)
     + índice (tenant_id, apontamento_id)
     + FK → agro_apontamentos(id) ON DELETE SET NULL
   QUEM ESCREVE: A1 (fluxo do apontamento). LÊ: A2 (ficha da máquina) + custeio.
   Aditivo e idempotente (checa information_schema). Não relança estoque/custeio.
   Rodar: php migrations/migration_149_abastecimento_apontamento.php
   ============================================================ */

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'],
    $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$db = $config['dbname'];

echo "== migration 149: abastecimento ↔ apontamento (A1-56) ==\n";

/* helpers idempotentes */
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

/* pré-requisitos */
foreach (['maquina_abastecimentos', 'agro_apontamentos'] as $t) {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t");
    $q->execute([':d' => $db, ':t' => $t]);
    if (!$q->fetchColumn()) {
        fwrite(STDERR, "ABORTA: tabela {$t} não existe.\n");
        exit(1);
    }
}

/* 1) coluna */
if (!$colExiste($pdo, $db, 'maquina_abastecimentos', 'apontamento_id')) {
    $pdo->exec("ALTER TABLE maquina_abastecimentos
                ADD COLUMN apontamento_id BIGINT UNSIGNED NULL AFTER operador_id");
    echo "  + coluna apontamento_id\n";
} else {
    echo "  = coluna apontamento_id já existe\n";
}

/* 2) índice (tenant_id, apontamento_id) */
if (!$idxExiste($pdo, $db, 'maquina_abastecimentos', 'ix_abast_tenant_apont')) {
    $pdo->exec("ALTER TABLE maquina_abastecimentos
                ADD INDEX ix_abast_tenant_apont (tenant_id, apontamento_id)");
    echo "  + índice ix_abast_tenant_apont\n";
} else {
    echo "  = índice já existe\n";
}

/* 3) FK → agro_apontamentos(id) ON DELETE SET NULL
   (só cria se as duas tabelas forem InnoDB — pré-condição do schema VERO) */
if (!$fkExiste($pdo, $db, 'maquina_abastecimentos', 'fk_abast_apontamento')) {
    $pdo->exec("ALTER TABLE maquina_abastecimentos
                ADD CONSTRAINT fk_abast_apontamento
                FOREIGN KEY (apontamento_id) REFERENCES agro_apontamentos(id)
                ON DELETE SET NULL ON UPDATE CASCADE");
    echo "  + FK fk_abast_apontamento (ON DELETE SET NULL)\n";
} else {
    echo "  = FK já existe\n";
}

echo "== 149 concluída ==\n";
