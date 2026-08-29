-- ============================================================
-- VERO — 2026-07-20_ia_uso.sql  (previsibilidade de custo da IA)
-- Medidor de uso do assistente: uma linha por chamada (chat/transcrição)
-- com tokens/segundos consumidos. Alimenta as cotas diárias por usuário
-- (tenant_parametros ia.cota_chats_dia / ia.cota_audio_min_dia) e o
-- alerta de orçamento mensal (ia.orcamento_tokens_mes).
-- Aditivo, idempotente, NO DROP.
-- ============================================================
CREATE TABLE IF NOT EXISTS ia_uso (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  usuario_id BIGINT UNSIGNED NOT NULL,
  tipo VARCHAR(20) NOT NULL,             -- chat | transcricao
  tokens_entrada INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_saida INT UNSIGNED NOT NULL DEFAULT 0,
  audio_segundos INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ia_uso_dia (tenant_id, usuario_id, tipo, created_at),
  KEY idx_ia_uso_mes (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
