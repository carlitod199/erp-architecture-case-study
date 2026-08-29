-- ============================================================
-- VERO — 2026-07-16_monitoramento_local.sql  (migration 163 · item 8.4)
-- mip_monitoramentos.local_infestacao (folha|ramo|cacho — VARCHAR+whitelist PHP).
-- DEVE rodar ANTES do 2026-07-17_monitoramento_multialvo.sql (que lê esta coluna).
-- Aditivo, idempotente, NO DROP. (Par de produção do migration_163_monitoramento_local.php)
-- ============================================================
SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mip_monitoramentos' AND COLUMN_NAME = 'local_infestacao');
SET @sql := IF(@e = 0,
    'ALTER TABLE mip_monitoramentos ADD COLUMN local_infestacao VARCHAR(20) NULL AFTER quantidade_encontrada',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
