-- ============================================================
-- VERO — 2026-07-17_monitoramento_multialvo.sql  (múltiplos alvos por monitoramento)
-- Junção mip_monitoramento_alvos (dados por alvo). mip_monitoramentos.alvo_id
-- mantido = 1º alvo (compat). Aditivo, idempotente, NO DROP.
-- ============================================================
CREATE TABLE IF NOT EXISTS mip_monitoramento_alvos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  monitoramento_id BIGINT UNSIGNED NOT NULL,
  alvo_id BIGINT UNSIGNED NOT NULL,
  nivel_infestacao DECIMAL(10,2) NULL,
  quantidade_encontrada DECIMAL(18,4) NULL,
  local_infestacao VARCHAR(20) NULL,
  severidade_qualitativa VARCHAR(10) NULL,
  observacao VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mon_alvo (tenant_id, monitoramento_id, alvo_id),
  KEY idx_mon (tenant_id, monitoramento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migra o alvo único existente -> junção (idempotente pela UNIQUE)
INSERT IGNORE INTO mip_monitoramento_alvos
    (tenant_id, monitoramento_id, alvo_id, nivel_infestacao, quantidade_encontrada,
     local_infestacao, severidade_qualitativa, created_by)
SELECT tenant_id, id, alvo_id, nivel_infestacao, quantidade_encontrada,
       local_infestacao, severidade_qualitativa, created_by
  FROM mip_monitoramentos;
