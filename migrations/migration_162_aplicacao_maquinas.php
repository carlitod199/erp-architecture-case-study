<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_162_aplicacao_maquinas.php  (reunião 16/07 · itens 6.5 e 6.4)
   6.5: MÚLTIPLOS maquinários por aplicação (uva usa trator + pulverizador
        juntos). Junção agro_aplicacao_maquinas; migra o maquina_id único
        existente para a junção. Mantém agro_aplicacoes.maquina_id (compat).
   6.4: volume de calda POR HECTARE (L/ha aplicado) — distinto do total do
        tanque (volume_calda_l já existe).
   Aditivo, idempotente. Rodar: php migrations/migration_162_aplicacao_maquinas.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 162: múltiplos maquinários + calda L/ha ==\n";

/* 6.5 — junção */
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agro_aplicacao_maquinas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  aplicacao_id BIGINT UNSIGNED NOT NULL,
  maquina_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_aplic_maq (tenant_id, aplicacao_id, maquina_id),
  KEY idx_aplic (tenant_id, aplicacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok agro_aplicacao_maquinas\n";

/* migra o maquina_id único -> junção (idempotente pela UNIQUE) */
$pdo->exec("INSERT IGNORE INTO agro_aplicacao_maquinas (tenant_id, aplicacao_id, maquina_id)
            SELECT tenant_id, id, maquina_id FROM agro_aplicacoes WHERE maquina_id IS NOT NULL");
echo "  ok migrou maquina_id existente para a junção\n";

/* 6.4 — L/ha aplicado */
$existe = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_aplicacoes' AND COLUMN_NAME='volume_calda_ha_l'")->fetchColumn();
if (!$existe) {
    $pdo->exec("ALTER TABLE agro_aplicacoes ADD COLUMN volume_calda_ha_l DECIMAL(12,2) NULL AFTER volume_calda_l");
    echo "  ok volume_calda_ha_l (L/ha aplicado) — distinto do total do tanque\n";
} else {
    echo "  - volume_calda_ha_l já existe\n";
}
echo "== 162 concluída ==\n";
