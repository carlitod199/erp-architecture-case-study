-- ============================================================
-- VERO — 2026-07-30_ia_preferencias.sql  (Agente Operacional de IA — preferências)
-- Chave/valor por (tenant, usuário) para o agente de IA lembrar escolhas
-- do operador (ex.: safra padrão, verbosidade, unidade preferida).
-- Upsert por UNIQUE(tenant_id, usuario_id, chave).
-- Aditivo, idempotente, NO DROP.
-- ============================================================
CREATE TABLE IF NOT EXISTS ia_preferencias (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  usuario_id BIGINT UNSIGNED NOT NULL,
  chave VARCHAR(60) NOT NULL,
  valor TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ia_pref (tenant_id, usuario_id, chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
