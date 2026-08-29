<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_166_nutricao_fase_variedade.php  (Opção B · Parte 2 · Nutrição)
   Remapeia a nutrição da fenologia por-cultura para a fenologia POR VARIEDADE:
     - analise_faixas.variedade_fase_id → faixa nutricional por variedade × fase
       (agro_variedade_fases.id). fenologia_id (por-cultura) segue como fallback.
     - analise_foliar.variedade_fase_id → fase resolvida (variedade+data) da amostra
       + analise_foliar.dias_desde_poda (snapshot de auditoria).
   Modelo antigo (fenologia_id) preservado: NADA é apagado. Sem FK física.
   Aditivo, idempotente, NO DROP.
   Rodar: php migrations/migration_166_nutricao_fase_variedade.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 166: nutrição (faixas + análise foliar) fase por variedade ==\n";

$colExists = static function (PDO $pdo, string $table, string $name): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:tb AND COLUMN_NAME=:cn");
    $st->execute([':tb' => $table, ':cn' => $name]);
    return (int)$st->fetchColumn() > 0;
};
$idxExists = static function (PDO $pdo, string $table, string $name): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:tb AND INDEX_NAME=:ix");
    $st->execute([':tb' => $table, ':ix' => $name]);
    return (int)$st->fetchColumn() > 0;
};

/* analise_faixas: faixa por variedade × fase ------------------------------- */
if (!$colExists($pdo, 'analise_faixas', 'variedade_fase_id')) {
    $pdo->exec("ALTER TABLE analise_faixas
                ADD COLUMN variedade_fase_id BIGINT UNSIGNED NULL AFTER fenologia_id");
    echo "  ok analise_faixas.variedade_fase_id (BIGINT NULL)\n";
} else {
    echo "  - analise_faixas.variedade_fase_id já existe\n";
}
if (!$idxExists($pdo, 'analise_faixas', 'idx_faixa_var_fase')) {
    $pdo->exec("ALTER TABLE analise_faixas ADD KEY idx_faixa_var_fase (tenant_id, variedade_fase_id)");
    echo "  ok idx_faixa_var_fase\n";
} else {
    echo "  - idx_faixa_var_fase já existe\n";
}

/* analise_foliar: fase resolvida (variedade+data) + snapshot de dias -------- */
if (!$colExists($pdo, 'analise_foliar', 'variedade_fase_id')) {
    $pdo->exec("ALTER TABLE analise_foliar
                ADD COLUMN variedade_fase_id BIGINT UNSIGNED NULL AFTER fenologia_id");
    echo "  ok analise_foliar.variedade_fase_id (BIGINT NULL)\n";
} else {
    echo "  - analise_foliar.variedade_fase_id já existe\n";
}
if (!$colExists($pdo, 'analise_foliar', 'dias_desde_poda')) {
    $pdo->exec("ALTER TABLE analise_foliar
                ADD COLUMN dias_desde_poda INT NULL AFTER variedade_fase_id");
    echo "  ok analise_foliar.dias_desde_poda (INT NULL)\n";
} else {
    echo "  - analise_foliar.dias_desde_poda já existe\n";
}
if (!$idxExists($pdo, 'analise_foliar', 'idx_foliar_var_fase')) {
    $pdo->exec("ALTER TABLE analise_foliar ADD KEY idx_foliar_var_fase (tenant_id, variedade_fase_id)");
    echo "  ok idx_foliar_var_fase\n";
} else {
    echo "  - idx_foliar_var_fase já existe\n";
}

echo "== 166 concluída ==\n";
