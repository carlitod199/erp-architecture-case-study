-- ============================================================
-- VERO — 2026-07-17_apontamento_dois_estagios.sql  (gestor 17/07)
-- Apontamento em 2 estágios: status 'iniciado' + auditoria (iniciado_em/
-- finalizado_em/finalizado_por). ordem_servico_id (já existe) recebe a OS.
-- Aditivo, idempotente, NO DROP. Aplicar no próximo deploy.
-- ============================================================

-- 1) status: adiciona 'iniciado' ao enum (preserva os demais) --------------
SET @has := (SELECT LOCATE("'iniciado'", COLUMN_TYPE) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_apontamentos' AND COLUMN_NAME = 'status');
SET @sql := IF(@has = 0,
    "ALTER TABLE agro_apontamentos MODIFY COLUMN status ENUM('iniciado','pendente','validado','recusado') NOT NULL DEFAULT 'pendente'",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) iniciado_em -----------------------------------------------------------
SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'agro_apontamentos' AND COLUMN_NAME = 'iniciado_em');
SET @sql := IF(@e = 0, 'ALTER TABLE agro_apontamentos ADD COLUMN iniciado_em DATETIME NULL AFTER status', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) finalizado_em ---------------------------------------------------------
SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'agro_apontamentos' AND COLUMN_NAME = 'finalizado_em');
SET @sql := IF(@e = 0, 'ALTER TABLE agro_apontamentos ADD COLUMN finalizado_em DATETIME NULL AFTER iniciado_em', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) finalizado_por --------------------------------------------------------
SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'agro_apontamentos' AND COLUMN_NAME = 'finalizado_por');
SET @sql := IF(@e = 0, 'ALTER TABLE agro_apontamentos ADD COLUMN finalizado_por BIGINT UNSIGNED NULL AFTER finalizado_em', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
