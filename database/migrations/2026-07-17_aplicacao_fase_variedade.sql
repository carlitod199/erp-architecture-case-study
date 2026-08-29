-- ============================================================
-- VERO — 2026-07-17_aplicacao_fase_variedade.sql  (Opção B · Parte 1 · MIP/DF)
-- Persiste na aplicação (DF/IF) a fase pela fenologia POR VARIEDADE (dias desde a poda).
-- fenologia_id (catálogo por cultura, A1-29) permanece como compat/fallback.
-- Espelho da 2026-07-16_apontamento_fase_variedade.sql (mig 164).
-- Aditivo, idempotente (guards via information_schema), NO DROP. Sem FK física.
-- ============================================================

-- variedade_fase_id (agro_variedade_fases.id) --------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_aplicacoes'
      AND COLUMN_NAME = 'variedade_fase_id');
SET @sql := IF(@exists = 0,
    'ALTER TABLE agro_aplicacoes ADD COLUMN variedade_fase_id BIGINT UNSIGNED NULL AFTER fenologia_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- dias_desde_poda (snapshot de auditoria) ------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_aplicacoes'
      AND COLUMN_NAME = 'dias_desde_poda');
SET @sql := IF(@exists = 0,
    'ALTER TABLE agro_aplicacoes ADD COLUMN dias_desde_poda INT NULL AFTER variedade_fase_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- índice de apoio p/ leitura por fase ----------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_aplicacoes'
      AND INDEX_NAME = 'idx_aplic_var_fase');
SET @sql := IF(@exists = 0,
    'ALTER TABLE agro_aplicacoes ADD KEY idx_aplic_var_fase (tenant_id, variedade_fase_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
