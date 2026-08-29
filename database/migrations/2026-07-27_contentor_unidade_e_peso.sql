-- ============================================================
-- VERO — 2026-07-27_contentor_unidade_e_peso.sql  (WP-CALC Tarefa B / Z-05)
-- Unidade 'contentor' na colheita a granel + peso do contentor.
-- (1) agro_tipos_atividade.unidade_padrao ENUM ganha 'contentor' (antes de 'outro').
--     MODIFY aditivo: preserva TODOS os valores existentes na mesma ordem.
-- (2) agro_culturas.peso_contentor_kg (default 20) — fonte SEPARADA de
--     peso_unidade_kg (peso da caixa de embalamento), para caixa (~5kg) e
--     contentor (~20kg) coexistirem sem colisão. Editável em
--     custeio/parametros_cultura.php. Conversão: contentores = produção kg ÷ peso.
-- Idempotente (guarda via information_schema); par: migrations/migration_192_*.php.
-- ============================================================
SET @tem := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_tipos_atividade'
                AND COLUMN_NAME = 'unidade_padrao');
SET @sql := IF(@tem NOT LIKE '%contentor%',
  'ALTER TABLE agro_tipos_atividade MODIFY COLUMN unidade_padrao ENUM(''planta'',''caixa'',''kg'',''ha'',''metro_linear'',''hora'',''cacho'',''fila'',''contentor'',''outro'') COLLATE utf8mb4_unicode_ci DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_culturas'
                AND COLUMN_NAME = 'peso_contentor_kg');
SET @sql := IF(@tem = 0,
  'ALTER TABLE agro_culturas ADD COLUMN peso_contentor_kg DECIMAL(10,3) NOT NULL DEFAULT 20.000 AFTER peso_unidade_kg',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
