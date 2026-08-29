-- ============================================================
-- VERO — 2026-07-17_variedade_fase_calda.sql  (gestor 17/07)
-- Volume de CALDA (L/ha) por FASE da fenologia da variedade (a DF puxa pela fase
-- resolvida na data). Aditivo, idempotente, NO DROP.
-- ============================================================
SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'agro_variedade_fases' AND COLUMN_NAME = 'volume_calda_ha_l');
SET @sql := IF(@e = 0,
    'ALTER TABLE agro_variedade_fases ADD COLUMN volume_calda_ha_l DECIMAL(10,2) NULL AFTER volume_mm_dia',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
