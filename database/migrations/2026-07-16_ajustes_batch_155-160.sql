-- ============================================================
-- VERO — Migração de produção (vero_db) — batch 15-16/07/2026
-- Cobre as mudanças de schema aplicadas no dev (migrations PHP 155-160):
--   porta-enxerto (cadastro + FKs), cor da uva, fenologia por variedade
--   (versionada), estado de poda por válvula, potência da bomba.
-- ADITIVA e IDEMPOTENTE (MySQL 8): CREATE TABLE IF NOT EXISTS + guarda de
-- coluna via information_schema. SEM DROP (as colunas de texto antigas
-- porta_enxerto ficam no lugar, sem uso — evita perda de dado; limpeza
-- futura com backup). Permissões (agro.safra.*, fenologia.aprovar) são de
-- CÓDIGO (includes/permissions.php) — não exigem SQL.
-- Aplicar UMA vez; reexecução é inócua.
-- ============================================================

-- ---------- (155) cadastro de porta-enxerto ----------
CREATE TABLE IF NOT EXISTS agro_porta_enxertos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  codigo VARCHAR(20) NULL,
  nome VARCHAR(80) NOT NULL,
  descricao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pe_tenant_ativo (tenant_id, ativo),
  UNIQUE KEY uq_pe_tenant_nome (tenant_id, nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- (157) fenologia por variedade (versionada) ----------
CREATE TABLE IF NOT EXISTS agro_variedade_fenologia (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  variedade_id BIGINT UNSIGNED NOT NULL,
  versao INT NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'rascunho',
  aprovado_por BIGINT UNSIGNED NULL,
  aprovado_em DATETIME NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vfen_tenant_var_versao (tenant_id, variedade_id, versao),
  KEY idx_vfen_var_status (tenant_id, variedade_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agro_variedade_fases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  fenologia_id BIGINT UNSIGNED NOT NULL,
  ordem INT NOT NULL DEFAULT 1,
  nome VARCHAR(80) NOT NULL,
  dia_inicio INT NOT NULL,
  dia_fim INT NOT NULL,
  volume_mm_dia DECIMAL(8,2) NULL,
  observacao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vfases_fen (tenant_id, fenologia_id, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- colunas idempotentes (guarda via information_schema) ----------
-- (155) agro_talhoes.porta_enxerto_id
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_talhoes' AND COLUMN_NAME='porta_enxerto_id');
SET @s := IF(@c=0, 'ALTER TABLE agro_talhoes ADD COLUMN porta_enxerto_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- (155) analise_faixas.porta_enxerto_id  (mantém a coluna texto porta_enxerto, sem DROP)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='analise_faixas' AND COLUMN_NAME='porta_enxerto_id');
SET @s := IF(@c=0, 'ALTER TABLE analise_faixas ADD COLUMN porta_enxerto_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- (156) agro_variedades.cor_baga
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_variedades' AND COLUMN_NAME='cor_baga');
SET @s := IF(@c=0, 'ALTER TABLE agro_variedades ADD COLUMN cor_baga VARCHAR(20) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- (159) agro_safra_talhoes: estado de poda por válvula
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_safra_talhoes' AND COLUMN_NAME='data_poda');
SET @s := IF(@c=0, 'ALTER TABLE agro_safra_talhoes ADD COLUMN data_poda DATE NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_safra_talhoes' AND COLUMN_NAME='poda_status');
SET @s := IF(@c=0, "ALTER TABLE agro_safra_talhoes ADD COLUMN poda_status VARCHAR(20) NOT NULL DEFAULT 'pendente'", 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_safra_talhoes' AND COLUMN_NAME='poda_confirmada_em');
SET @s := IF(@c=0, 'ALTER TABLE agro_safra_talhoes ADD COLUMN poda_confirmada_em DATETIME NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_safra_talhoes' AND COLUMN_NAME='poda_confirmada_por');
SET @s := IF(@c=0, 'ALTER TABLE agro_safra_talhoes ADD COLUMN poda_confirmada_por BIGINT UNSIGNED NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- (160) agro_bombas.potencia_kw
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_bombas' AND COLUMN_NAME='potencia_kw');
SET @s := IF(@c=0, 'ALTER TABLE agro_bombas ADD COLUMN potencia_kw DECIMAL(10,2) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- seed de porta-enxertos comuns (idempotente por UNIQUE tenant+nome) ----------
INSERT IGNORE INTO agro_porta_enxertos (tenant_id, nome, ativo)
SELECT t.id, x.nome, 1
  FROM tenants t
  CROSS JOIN (
    SELECT 'IAC 572 (Campinas)' AS nome UNION ALL
    SELECT 'IAC 766 (Campinas)' UNION ALL
    SELECT 'Paulsen 1103' UNION ALL
    SELECT 'SO4' UNION ALL
    SELECT 'Freedom' UNION ALL
    SELECT 'Harmony'
  ) x;

-- FIM da migração 2026-07-16.
