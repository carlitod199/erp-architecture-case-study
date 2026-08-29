<?php
declare(strict_types=1);

// ============================================================================
// migration_137_pre_venda.php | VERO
// Pacote A0-09 — decisão do cliente P-09 (04/07/2026, 2ª rodada):
//   DB-02: comercial_contratos (pré-venda com preço travado) — modelo enxuto
//          aprovado (kg contratado, preço/kg, vencimento, status VARCHAR)
//          + comercial_vendas.contrato_id (vínculo opcional p/ abater saldo).
//   Contrato ativo entra no fluxo de caixa previsto (tarefa A3-T17).
//   Sem liquidação/washout nesta fase.
// Idempotente. Backup: backup_pre_137_*.sql.
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
$columnExists = function (string $t, string $c) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$t, $c]);
    return (bool)$st->fetchColumn();
};
$fkExists = function (string $name) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY' AND constraint_name = ?");
    $st->execute([$name]);
    return (bool)$st->fetchColumn();
};

$log("== migration 137 — contratos de pré-venda (pacote A0-09) ==");

if (!$tableExists('comercial_contratos')) {
    $pdo->exec("CREATE TABLE comercial_contratos (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id       BIGINT UNSIGNED NOT NULL,
        comprador_id    BIGINT UNSIGNED NOT NULL,
        cultura_id      BIGINT UNSIGNED NULL,
        safra_id        BIGINT UNSIGNED NULL,
        numero          VARCHAR(20) NULL,
        kg_contratado   DECIMAL(18,3) NOT NULL DEFAULT 0,
        preco_kg        DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT 'preco travado',
        data_contrato   DATE NULL,
        data_vencimento DATE NULL,
        status          VARCHAR(10) NOT NULL DEFAULT 'rascunho' COMMENT 'rascunho, ativo, cumprido, cancelado - validacao PHP',
        observacao      VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by BIGINT UNSIGNED NULL,
        updated_by BIGINT UNSIGNED NULL,
        PRIMARY KEY (id),
        KEY idx_cc_tenant (tenant_id),
        KEY idx_cc_comprador (comprador_id),
        KEY idx_cc_safra (safra_id),
        CONSTRAINT fk_cc_comprador FOREIGN KEY (comprador_id) REFERENCES comercial_compradores (id),
        CONSTRAINT fk_cc_safra     FOREIGN KEY (safra_id)     REFERENCES agro_safras (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela comercial_contratos");
} else { $log("  = comercial_contratos já existe"); }

if (!$columnExists('comercial_vendas', 'contrato_id')) {
    $pdo->exec("ALTER TABLE comercial_vendas ADD COLUMN contrato_id BIGINT UNSIGNED NULL COMMENT 'vinculo opcional com pre-venda (abate saldo do contrato)'");
    $log("  + comercial_vendas.contrato_id");
} else { $log("  = comercial_vendas.contrato_id já existe"); }
if (!$fkExists('fk_venda_contrato')) {
    $pdo->exec("ALTER TABLE comercial_vendas ADD CONSTRAINT fk_venda_contrato FOREIGN KEY (contrato_id) REFERENCES comercial_contratos (id)");
    $log("  + FK fk_venda_contrato");
} else { $log("  = FK já existe"); }

$log("== migration 137 concluída ==");
