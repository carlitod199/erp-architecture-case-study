-- ============================================================
-- VERO — 2026-07-16_valvula_estrutura.sql  (migration 161)
-- agro_talhoes.estrutura_sistema (latada|espaldeira|y — VARCHAR+whitelist PHP).
-- Aditivo, idempotente, NO DROP. (Par de produção do migration_161_valvula_estrutura.php)
-- ============================================================
SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'agro_talhoes' AND COLUMN_NAME = 'estrutura_sistema');
SET @sql := IF(@e = 0,
    'ALTER TABLE agro_talhoes ADD COLUMN estrutura_sistema VARCHAR(20) NULL AFTER variedade_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
