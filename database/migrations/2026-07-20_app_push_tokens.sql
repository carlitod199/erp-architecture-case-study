-- ============================================================
-- VERO — 2026-07-20_app_push_tokens.sql  (Onda 7.5 — notificações push)
-- Tokens Expo Push dos aparelhos do app de campo. O app registra em
-- POST /api/v1/push/registrar; o servidor notifica via exp.host ao criar
-- alertas. Aditivo, idempotente, NO DROP.
-- Obs.: push remoto NÃO funciona no Expo Go (SDK 53+) — só no build EAS.
-- ============================================================
CREATE TABLE IF NOT EXISTS app_push_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  usuario_id BIGINT UNSIGNED NOT NULL,
  expo_token VARCHAR(255) NOT NULL,
  plataforma VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_token (expo_token),
  KEY idx_push_tenant (tenant_id, usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
