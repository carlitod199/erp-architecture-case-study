-- ============================================================
-- VERO — 2026-07-16_aplicacao_maquinas.sql  (migration 162 · itens 6.5/6.4)
-- Múltiplos maquinários por aplicação (junção) + volume de calda L/ha aplicado.
-- Migra o maquina_id único existente para a junção. Aditivo, idempotente, NO DROP.
-- (Par de produção do migration_162_aplicacao_maquinas.php)
-- ============================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO agro_aplicacao_maquinas (tenant_id, aplicacao_id, maquina_id)
SELECT tenant_id, id, maquina_id FROM agro_aplicacoes WHERE maquina_id IS NOT NULL;

-- volume de calda POR HECTARE aplicado (distinto do total do tanque volume_calda_l)
SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'agro_aplicacoes' AND COLUMN_NAME = 'volume_calda_ha_l');
SET @sql := IF(@e = 0,
    'ALTER TABLE agro_aplicacoes ADD COLUMN volume_calda_ha_l DECIMAL(12,2) NULL AFTER volume_calda_l',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
