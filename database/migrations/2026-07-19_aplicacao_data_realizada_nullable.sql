-- ============================================================
-- VERO — 2026-07-19_aplicacao_data_realizada_nullable.sql  (QA-BUG3, mig 177)
-- C-11 (dois estágios): na EMISSÃO de OS (DF/IF) a data REALIZADA não existe —
-- só é gravada na confirmação da execução. Coluna `data` vira NULL-able;
-- exibição/consultas já usam COALESCE(ap.data, ap.data_prevista).
-- Idempotente (guarda via information_schema). Sem DROP, sem perda de dados.
-- ============================================================
SET @nn := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_aplicacoes'
               AND COLUMN_NAME = 'data' AND IS_NULLABLE = 'NO');
SET @sql := IF(@nn > 0,
  'ALTER TABLE agro_aplicacoes MODIFY COLUMN `data` DATE NULL COMMENT ''data REALIZADA - NULL em OS emitida (preenchida na confirmacao)''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
