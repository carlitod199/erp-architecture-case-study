<?php
declare(strict_types=1);

// ============================================================================
// migration_136_rateio_metas.php | VERO
// Pacote A0-08 — decisões do cliente de 04/07/2026:
//   DB-24 (P-07 APROVADA): custeio_rateio_execucoes — execução de rateio no
//          fechamento, manual, idempotente, com memória de cálculo (JSON) e
//          contrapartida negativa no "sem talhão". status VARCHAR (não ENUM).
//          Novo origem_tipo de custeio (contrato): 'rateio_execucao'.
//   DB-26 (P-44 VALIDADA — cliente QUER metas formais): gestao_metas — metas
//          por safra×indicador (custo_ha, kg_total, margem_pct, …) p/ o
//          dashboard executivo e o blueprint A4-04.
//   DB-25 (P-41/P-42 decididas) é só contrato (origens rh_folha_lancamento e
//          patrimonio_depreciacao; categorias depreciacao/administrativo).
// Idempotente. Backup: backup_pre_136_*.sql (gerado antes).
// ============================================================================

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$log = function (string $m): void { echo $m . "\n"; };
$tableExists = function (string $t) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$t]);
    return (bool)$st->fetchColumn();
};

$AUDIT = "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL";

$log("== migration 136 — rateio executável + metas (pacote A0-08) ==");

$log("[DB-24] custeio_rateio_execucoes (P-07)");
if (!$tableExists('custeio_rateio_execucoes')) {
    $pdo->exec("CREATE TABLE custeio_rateio_execucoes (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id     BIGINT UNSIGNED NOT NULL,
        rateio_id     BIGINT UNSIGNED NOT NULL,
        safra_id      BIGINT UNSIGNED NOT NULL,
        base_aplicada VARCHAR(15) NOT NULL COMMENT 'area, producao, custo_direto, manual - validacao PHP',
        valor_origem  DECIMAL(18,2) NOT NULL DEFAULT 0,
        status        VARCHAR(10) NOT NULL DEFAULT 'aplicada' COMMENT 'aplicada | desfeita - validacao PHP',
        memoria       JSON NULL COMMENT 'denominadores e cotas - exigencia de auditoria',
        executado_por BIGINT UNSIGNED NULL,
        executado_em  DATETIME NULL,
        $AUDIT,
        PRIMARY KEY (id),
        KEY idx_cre_tenant (tenant_id),
        KEY idx_cre_safra (safra_id),
        KEY idx_cre_rateio (rateio_id),
        CONSTRAINT fk_cre_rateio FOREIGN KEY (rateio_id) REFERENCES custeio_rateios (id),
        CONSTRAINT fk_cre_safra  FOREIGN KEY (safra_id)  REFERENCES agro_safras (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela custeio_rateio_execucoes");
} else { $log("  = já existe"); }

$log("[DB-26] gestao_metas (P-44 — metas formais)");
if (!$tableExists('gestao_metas')) {
    $pdo->exec("CREATE TABLE gestao_metas (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id  BIGINT UNSIGNED NOT NULL,
        safra_id   BIGINT UNSIGNED NOT NULL,
        indicador  VARCHAR(40) NOT NULL COMMENT 'custo_ha, custo_kg, kg_total, faturamento, margem_pct, ... - validacao PHP',
        valor_meta DECIMAL(18,4) NOT NULL,
        observacao VARCHAR(255) NULL,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_meta (tenant_id, safra_id, indicador),
        CONSTRAINT fk_meta_safra FOREIGN KEY (safra_id) REFERENCES agro_safras (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela gestao_metas");
} else { $log("  = já existe"); }

$log("== migration 136 concluída ==");
