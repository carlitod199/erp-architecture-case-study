-- VERO — 2026-07-17_regra_premiacao_meta_valor_nullable.sql (itens 5.1/5.3)
-- meta/valor saem do cadastro (vão p/ o apontamento). Relaxa p/ NULL, mantém colunas (histórico), NO DROP.
SET @n := (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rh_regras_premiacao' AND COLUMN_NAME='meta_qtd');
SET @sql := IF(@n='NO','ALTER TABLE rh_regras_premiacao MODIFY COLUMN meta_qtd DECIMAL(12,3) NULL','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @n := (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rh_regras_premiacao' AND COLUMN_NAME='valor_acima_meta');
SET @sql := IF(@n='NO','ALTER TABLE rh_regras_premiacao MODIFY COLUMN valor_acima_meta DECIMAL(18,6) NULL','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
