-- ============================================================
-- VERO — 2026-07-16_apontamento_fase_variedade.sql  (reunião 16/07 · item 1.1, lado save)
-- Persiste no apontamento a fase pela fenologia POR VARIEDADE (dias desde a poda).
-- fenologia_id (catálogo por cultura, A1-29) permanece como compat/fallback.
-- Aditivo, idempotente (guards via information_schema), NO DROP. Sem FK física.
-- Aplicar no próximo deploy junto de 161/162/163 (ver VERO_DEPLOY_PRODUCAO.md).
-- ============================================================

-- variedade_fase_id (agro_variedade_fases.id) --------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_apontamentos'
      AND COLUMN_NAME = 'variedade_fase_id');
SET @sql := IF(@exists = 0,
    'ALTER TABLE agro_apontamentos ADD COLUMN variedade_fase_id BIGINT UNSIGNED NULL AFTER fenologia_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- dias_desde_poda (snapshot de auditoria) ------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_apontamentos'
      AND COLUMN_NAME = 'dias_desde_poda');
SET @sql := IF(@exists = 0,
    'ALTER TABLE agro_apontamentos ADD COLUMN dias_desde_poda INT NULL AFTER variedade_fase_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- índice de apoio p/ leitura por fase ----------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_apontamentos'
      AND INDEX_NAME = 'idx_apont_var_fase');
SET @sql := IF(@exists = 0,
    'ALTER TABLE agro_apontamentos ADD KEY idx_apont_var_fase (tenant_id, variedade_fase_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
