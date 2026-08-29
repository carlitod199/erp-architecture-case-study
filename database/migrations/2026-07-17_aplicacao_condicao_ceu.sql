-- ============================================================
-- VERO — 2026-07-17_aplicacao_condicao_ceu.sql  (item 6.8)
-- Condição climática CATEGÓRICA na DF: sol|noite|nublado|chuva (VARCHAR+whitelist).
-- Distinta de condicao_climatica (JSON vento/temp/umidade). Aditivo, idempotente, NO DROP.
-- ============================================================
SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'agro_aplicacoes' AND COLUMN_NAME = 'condicao_ceu');
SET @sql := IF(@e = 0,
    'ALTER TABLE agro_aplicacoes ADD COLUMN condicao_ceu VARCHAR(20) NULL AFTER condicao_climatica',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
