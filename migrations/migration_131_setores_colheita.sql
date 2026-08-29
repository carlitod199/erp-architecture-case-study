-- ============================================================================
-- migration_131_setores_colheita.sql  |  VERO
-- Válvula = setor de irrigação (decisão validada 03/07/2026) + colheita por
-- válvula/safra com previsto × realizado e classificação Premium/CAT1/CAT2/
-- CAT3/perdidos com preço e faturamento calculado.
-- Pré-requisitos: check_prerequisites_130.sql OK; migration_130 aplicada.
-- Backup obrigatório antes (mysqldump). DDL não é transacional: segurança =
-- backup + idempotência + rollback_131.sql.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- 1) agro_setores → vira a entidade "válvula".
--    - talhao_id passa a aceitar NULL (print sistema legado mostra válvula 5A sem
--      talhão associado; o vínculo pode ser feito depois).
--    - fazenda_id direto facilita válvula ainda não amarrada a talhão.
--    - codigo curto ("5A", "2D"), tipo e área.
-- ----------------------------------------------------------------------------
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE agro_setores
     MODIFY COLUMN talhao_id BIGINT UNSIGNED NULL,
     ADD COLUMN fazenda_id BIGINT UNSIGNED NULL AFTER tenant_id,
     ADD COLUMN codigo     VARCHAR(20)     NULL AFTER nome,
     ADD COLUMN tipo       ENUM(''valvula'',''setor'') NOT NULL DEFAULT ''valvula'' AFTER codigo,
     ADD COLUMN area_ha    DECIMAL(12,4)   NOT NULL DEFAULT 0 AFTER tipo,
     ADD COLUMN ativo      TINYINT(1)      NOT NULL DEFAULT 1 AFTER area_ha,
     ADD KEY idx_setores_fazenda (fazenda_id),
     ADD CONSTRAINT fk_setores_fazenda FOREIGN KEY (fazenda_id) REFERENCES agro_fazendas (id)',
  'SELECT ''agro_setores ja alterado''')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'agro_setores' AND column_name = 'codigo');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2) colheita_registros — colheita por válvula × safra, previsto × realizado.
--    Cabeçalho ganha setor/válvula, variedade e totais consolidados; o
--    detalhamento por categoria fica em colheita_classificacoes.
--    kg_total = producao_kg_ha × área da válvula (calculado em service,
--    persistido como snapshot).
-- ----------------------------------------------------------------------------
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE colheita_registros
     ADD COLUMN setor_id                 BIGINT UNSIGNED NULL AFTER talhao_id,
     ADD COLUMN variedade_id             BIGINT UNSIGNED NULL AFTER cultura_id,
     ADD COLUMN producao_prevista_kg_ha  DECIMAL(12,3) NULL,
     ADD COLUMN producao_realizada_kg_ha DECIMAL(12,3) NULL,
     ADD COLUMN kg_total_previsto        DECIMAL(18,3) NOT NULL DEFAULT 0,
     ADD COLUMN kg_total_realizado       DECIMAL(18,3) NOT NULL DEFAULT 0,
     ADD COLUMN faturamento_previsto     DECIMAL(18,2) NOT NULL DEFAULT 0,
     ADD COLUMN faturamento_realizado    DECIMAL(18,2) NOT NULL DEFAULT 0,
     ADD COLUMN observacao               VARCHAR(255)  NULL,
     ADD KEY idx_colhreg_setor (setor_id),
     ADD KEY idx_colhreg_variedade (variedade_id),
     ADD CONSTRAINT fk_colhreg_setor FOREIGN KEY (setor_id) REFERENCES agro_setores (id)',
  'SELECT ''colheita_registros ja alterado''')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'colheita_registros' AND column_name = 'setor_id');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- variedade_id referencia agro_variedades (migration 120): índice sem FK (convenção).

-- ----------------------------------------------------------------------------
-- 3) colheita_classificacoes — % por categoria, preço/kg, kg e faturamento
--    calculados. momento distingue Previsto × Realizado (telas do requisito).
--    Regra de negócio (service): soma dos percentuais por momento ≤ 100;
--    'perdidos' não tem preço.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS colheita_classificacoes (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id    BIGINT UNSIGNED NOT NULL,
    registro_id  BIGINT UNSIGNED NOT NULL,
    momento      ENUM('previsto','realizado') NOT NULL,
    categoria    ENUM('premium','cat1','cat2','cat3','perdidos') NOT NULL,
    percentual   DECIMAL(6,2)  NOT NULL DEFAULT 0,
    preco_kg     DECIMAL(18,6) NOT NULL DEFAULT 0,
    kg_calculado DECIMAL(18,3) NOT NULL DEFAULT 0,
    faturamento  DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_colhclass (registro_id, momento, categoria),
    KEY idx_colhclass_tenant (tenant_id),
    CONSTRAINT fk_colhclass_registro FOREIGN KEY (registro_id) REFERENCES colheita_registros (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fim da migration 131.
