-- ============================================================
-- VERO — 2026-07-18_monitoramento_plantas_amostradas.sql  (C-28, reunião 18/07)
-- Consolidação por área: nº de PLANTAS AMOSTRADAS na leitura (flexível, sem
-- N fixo de 20). Índice consolidado = qtd encontrada ÷ amostradas × 100
-- (regra de 3), calculado na tela e no servidor quando o índice não é digitado.
-- Aditivo/idempotente, NO DROP.
-- ============================================================
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mip_monitoramentos'
                AND COLUMN_NAME = 'plantas_amostradas');
SET @sql := IF(@tem = 0,
  'ALTER TABLE mip_monitoramentos ADD COLUMN plantas_amostradas INT UNSIGNED NULL DEFAULT NULL AFTER unidade',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
