-- ============================================================
-- VERO — 2026-07-17_nutricao_fase_variedade.sql  (Opção B · Parte 2 · Nutrição)
-- Remapeia a nutrição para a fenologia POR VARIEDADE:
--   analise_faixas.variedade_fase_id  → faixa por variedade × fase (agro_variedade_fases.id)
--   analise_foliar.variedade_fase_id  → fase resolvida (variedade+data) da amostra
--   analise_foliar.dias_desde_poda    → snapshot de auditoria
-- Modelo antigo (fenologia_id) preservado: NADA é apagado. Sem FK física.
-- Aditivo, idempotente (guards via information_schema), NO DROP.
-- ============================================================

-- analise_faixas.variedade_fase_id -------------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analise_faixas'
      AND COLUMN_NAME = 'variedade_fase_id');
SET @sql := IF(@exists = 0,
    'ALTER TABLE analise_faixas ADD COLUMN variedade_fase_id BIGINT UNSIGNED NULL AFTER fenologia_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analise_faixas'
      AND INDEX_NAME = 'idx_faixa_var_fase');
SET @sql := IF(@exists = 0,
    'ALTER TABLE analise_faixas ADD KEY idx_faixa_var_fase (tenant_id, variedade_fase_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- analise_foliar.variedade_fase_id -------------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analise_foliar'
      AND COLUMN_NAME = 'variedade_fase_id');
SET @sql := IF(@exists = 0,
    'ALTER TABLE analise_foliar ADD COLUMN variedade_fase_id BIGINT UNSIGNED NULL AFTER fenologia_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- analise_foliar.dias_desde_poda (snapshot de auditoria) ---------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analise_foliar'
      AND COLUMN_NAME = 'dias_desde_poda');
SET @sql := IF(@exists = 0,
    'ALTER TABLE analise_foliar ADD COLUMN dias_desde_poda INT NULL AFTER variedade_fase_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analise_foliar'
      AND INDEX_NAME = 'idx_foliar_var_fase');
SET @sql := IF(@exists = 0,
    'ALTER TABLE analise_foliar ADD KEY idx_foliar_var_fase (tenant_id, variedade_fase_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
