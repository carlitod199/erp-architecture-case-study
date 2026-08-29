-- VERO — 2026-07-17_produto_nao_registrado.sql (item 9.1)
-- Flag "produto não registrado" em estoque_produtos. Aditivo, idempotente, NO DROP.
SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='estoque_produtos' AND COLUMN_NAME='nao_registrado');
SET @sql := IF(@e=0, 'ALTER TABLE estoque_produtos ADD COLUMN nao_registrado TINYINT(1) NOT NULL DEFAULT 0 AFTER registro_mapa', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
