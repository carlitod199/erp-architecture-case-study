-- ============================================================
-- VERO — 2026-07-27_variedade_cachos_por_planta.sql  (WP-CALC Tarefa A / Z-06 / W-02)
-- Raleio (unidade 'cacho') zerava o "Total a fazer" por faltar "cachos por planta".
-- Decisão do gestor: "cachos por planta" fica na VARIEDADE. Alimenta a calculadora
-- de mão de obra: trabalho = num_plantas do talhão × cachos_por_planta da variedade
-- da válvula (agro_talhoes.variedade_id → agro_variedades).
-- Idempotente (guarda via information_schema); par: migrations/migration_191_*.php.
-- ============================================================
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_variedades'
                AND COLUMN_NAME = 'cachos_por_planta');
SET @sql := IF(@tem = 0,
  'ALTER TABLE agro_variedades ADD COLUMN cachos_por_planta DECIMAL(10,2) NULL AFTER produtividade_esperada',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
