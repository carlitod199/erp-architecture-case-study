-- ============================================================
-- VERO — 2026-07-30_ia_acoes.sql  (Agente Operacional de IA — trilha de auditoria)
-- Uma linha por CAPABILITY executada pelo agente de IA, encadeada por
-- hash (mesma técnica do razão financeiro em movimentacoes_financeiras):
--   hash = SHA-256(hash_anterior . payload_canônico), pegando o
--   hash_anterior da última linha do MESMO tenant (ordem por id).
-- A trilha é INSERT-only e a cadeia é verificável de ponta a ponta.
-- Aditivo, idempotente, NO DROP.
-- ============================================================
CREATE TABLE IF NOT EXISTS ia_acoes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  usuario_id BIGINT UNSIGNED NOT NULL,
  session_id VARCHAR(64) NOT NULL,
  capability VARCHAR(80) NOT NULL,
  params_json JSON NULL,
  resultado TEXT NULL,
  recurso_tipo VARCHAR(40) NULL,
  recurso_id BIGINT NULL,
  confirmado_em DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  hash CHAR(64) NOT NULL,
  hash_anterior CHAR(64) NULL,
  PRIMARY KEY (id),
  KEY idx_ia_acoes_tenant (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
