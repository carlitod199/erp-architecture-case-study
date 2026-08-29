-- ============================================================
-- VERO — 2026-07-18_monitoramento_alvo_local.sql  (C-27, reunião 18/07)
-- Permite o MESMO alvo em LOCAIS diferentes (Cigarrinha na folha + no cacho):
-- a UNIQUE da junção passa de (tenant, mon, alvo) para (tenant, mon, alvo, local).
-- Idempotente (guardas via information_schema). Cria a nova ANTES de derrubar a
-- antiga (nunca fica sem proteção). Local NULL: duplicata é barrada no código.
-- ============================================================
SET @has_new := (SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'mip_monitoramento_alvos'
                    AND INDEX_NAME = 'uq_mon_alvo_local');
SET @sql := IF(@has_new = 0,
  'ALTER TABLE mip_monitoramento_alvos ADD UNIQUE KEY uq_mon_alvo_local (tenant_id, monitoramento_id, alvo_id, local_infestacao)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_old := (SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'mip_monitoramento_alvos'
                    AND INDEX_NAME = 'uq_mon_alvo');
SET @sql := IF(@has_old > 0,
  'ALTER TABLE mip_monitoramento_alvos DROP INDEX uq_mon_alvo',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
