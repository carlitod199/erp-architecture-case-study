-- ============================================================
-- VERO — 2026-07-18_maquina_tipos_e_unidade_fila.sql  (C-14 + C-45, reunião 18/07)
-- C-14: maquinas.tipo ganha estercadeira / rocadeira / bandejao (aplicação de
--       Dormex/cianamida). C-45: agro_tipos_atividade.unidade_padrao ganha
--       'fila' (fileiras roçadas — Roçadeira STIHL).
-- ENUM aditivo: MODIFY preservando TODOS os valores existentes na mesma ordem
-- (novos antes de 'outro'). Idempotente (guarda via information_schema).
-- ============================================================
SET @tem := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maquinas' AND COLUMN_NAME = 'tipo');
SET @sql := IF(@tem NOT LIKE '%estercadeira%',
  'ALTER TABLE maquinas MODIFY COLUMN tipo ENUM(''trator'',''colheitadeira'',''pulverizador'',''implemento'',''veiculo'',''estercadeira'',''rocadeira'',''bandejao'',''outro'') NOT NULL DEFAULT ''trator''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @tem := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_tipos_atividade' AND COLUMN_NAME = 'unidade_padrao');
SET @sql := IF(@tem NOT LIKE '%fila%',
  'ALTER TABLE agro_tipos_atividade MODIFY COLUMN unidade_padrao ENUM(''planta'',''caixa'',''kg'',''ha'',''metro_linear'',''hora'',''cacho'',''fila'',''outro'') NULL DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
