-- ============================================================
-- VERO — 2026-07-21_apontamento_planejamento_mo.sql
-- V-01/V-02: a meta, a premiação e o planejamento digitados na
-- CRIAÇÃO do apontamento (calculadora de MO) não podem se perder ao "Iniciar".
-- Guarda o snapshot no próprio apontamento (JSON): {total, unidade, base, dias,
-- pessoas, meta, premio} — reaberto na finalização e usado p/ semear a meta/valor
-- das linhas de produção (rh_producao_itens).
-- Aditivo, idempotente (checa a coluna antes), NO DROP.
-- ============================================================
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'agro_apontamentos'
             AND COLUMN_NAME = 'planejamento_mo');
SET @s := IF(@c = 0,
  "ALTER TABLE agro_apontamentos ADD COLUMN planejamento_mo JSON NULL COMMENT 'snapshot da calculadora de MO na criacao (V-01/V-02)'",
  'DO 0');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
