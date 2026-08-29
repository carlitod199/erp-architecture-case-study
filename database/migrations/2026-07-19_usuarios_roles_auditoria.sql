-- ============================================================
-- VERO — 2026-07-19_usuarios_roles_auditoria.sql  (QA-BUG1, mig 176)
-- Criar/editar/inativar usuário caía em "Erro interno": vero_insert/vero_update
-- sempre gravam created_by/updated_by, mas `usuarios` e `roles` (auth pré-CRUD)
-- não tinham as colunas (42S22). Padrão das demais tabelas: BIGINT UNSIGNED NULL.
-- Idempotente (guardas via information_schema). Aditivo, sem DROP.
-- ============================================================
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'created_by');
SET @sql := IF(@c = 0, 'ALTER TABLE usuarios ADD COLUMN created_by BIGINT UNSIGNED NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'updated_by');
SET @sql := IF(@c = 0, 'ALTER TABLE usuarios ADD COLUMN updated_by BIGINT UNSIGNED NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'created_by');
SET @sql := IF(@c = 0, 'ALTER TABLE roles ADD COLUMN created_by BIGINT UNSIGNED NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'updated_by');
SET @sql := IF(@c = 0, 'ALTER TABLE roles ADD COLUMN updated_by BIGINT UNSIGNED NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
