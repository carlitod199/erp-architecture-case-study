<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_165_aplicacao_fase_variedade.php  (Opção B · Parte 1 · MIP/DF)
   Persiste na aplicação (DF/IF) a FASE resolvida pela fenologia POR VARIEDADE
   (dias desde a poda) — espelho direto da migration 164 (apontamentos).
   A fenologia_id (catálogo por cultura, A1-29) segue intacta como compat/fallback.
     - variedade_fase_id → agro_variedade_fases.id (fase autoritativa)
     - dias_desde_poda   → snapshot dos dias no dia da aplicação (auditoria;
                            reaprovar a fenologia não reescreve o histórico).
   Sem FK física (padrão VERO). Aditivo, idempotente, NO DROP.
   Rodar: php migrations/migration_165_aplicacao_fase_variedade.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 165: agro_aplicacoes fase por variedade ==\n";

$col = static function (string $name): string {
    return "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_aplicacoes' AND COLUMN_NAME='$name'";
};

if (!(int)$pdo->query($col('variedade_fase_id'))->fetchColumn()) {
    $pdo->exec("ALTER TABLE agro_aplicacoes
                ADD COLUMN variedade_fase_id BIGINT UNSIGNED NULL AFTER fenologia_id");
    echo "  ok variedade_fase_id (BIGINT NULL) — fase por variedade (agro_variedade_fases.id)\n";
} else {
    echo "  - variedade_fase_id já existe\n";
}

if (!(int)$pdo->query($col('dias_desde_poda'))->fetchColumn()) {
    $pdo->exec("ALTER TABLE agro_aplicacoes
                ADD COLUMN dias_desde_poda INT NULL AFTER variedade_fase_id");
    echo "  ok dias_desde_poda (INT NULL) — snapshot de dias desde a poda\n";
} else {
    echo "  - dias_desde_poda já existe\n";
}

/* índice de apoio p/ leitura por fase (idempotente) */
$idx = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_aplicacoes' AND INDEX_NAME='idx_aplic_var_fase'")->fetchColumn();
if (!$idx) {
    $pdo->exec("ALTER TABLE agro_aplicacoes ADD KEY idx_aplic_var_fase (tenant_id, variedade_fase_id)");
    echo "  ok idx_aplic_var_fase\n";
} else {
    echo "  - idx_aplic_var_fase já existe\n";
}

echo "== 165 concluída ==\n";
