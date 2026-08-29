-- ============================================================================
--  VERO Agro · Migração 101 — Fazendas & Talhões (cadastro territorial)
-- ----------------------------------------------------------------------------
--  Módulo-base do vertical agrícola. Define as fazendas (unidade produtiva que
--  particiona todo o sistema), o catálogo de culturas, os talhões (CENTRO DE
--  CUSTO primário — alvo_tipo='talhao' no custeio) e o catálogo de
--  produtos/insumos. É referenciado por Safras, Atividades, Colheita, Estoque,
--  Compras, Pecuária, Máquinas e Custeio.
--
--  Convenções: BIGINT UNSIGNED em ids/tenant_id · DECIMAL(18,6) custo unitário,
--  (12,3) área/peso, (10,7) coordenadas · utf8mb4_unicode_ci · InnoDB ·
--  colunas de auditoria SEM FK para users · lógica em serviços (sem triggers).
--
--  Idempotente: CREATE TABLE IF NOT EXISTS + ALTER condicional (evolução de
--  scaffold). Em base nova cria tudo; em base com scaffold prévio, os ALTER
--  reconciliam colunas que faltem. Não destrutivo.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1) agro_culturas — catálogo de culturas (Soja, Milho, Algodão, Pastagem...)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agro_culturas` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`       BIGINT UNSIGNED NOT NULL,
  `nome`            VARCHAR(120)    NOT NULL,
  `nome_cientifico` VARCHAR(160)    NULL,
  `tipo`            ENUM('grao','fibra','pastagem','perene','olericola','outro') NOT NULL DEFAULT 'grao',
  `unidade_padrao`  VARCHAR(20)     NOT NULL DEFAULT 'sc60',  -- sc60, sc50, t, @, kg
  `ciclo_dias`      SMALLINT UNSIGNED NULL,
  `cor`             CHAR(7)         NULL,                      -- hex p/ UI (#4F7D3A)
  `ativo`           TINYINT(1)      NOT NULL DEFAULT 1,
  `created_by`      BIGINT UNSIGNED NULL,
  `updated_by`      BIGINT UNSIGNED NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cultura_tenant_nome` (`tenant_id`,`nome`),
  KEY `ix_cultura_tenant_ativo` (`tenant_id`,`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2) agro_fazendas — propriedade / unidade produtiva (particiona o tenant)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agro_fazendas` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`          BIGINT UNSIGNED NOT NULL,
  `codigo`             VARCHAR(20)     NOT NULL,            -- ex.: BV, SH
  `nome`               VARCHAR(160)    NOT NULL,
  `razao_social`       VARCHAR(200)    NULL,
  `cnpj_cpf`           VARCHAR(18)     NULL,                -- p/ Fiscal/NF-e
  `inscricao_estadual` VARCHAR(20)     NULL,
  `car`                VARCHAR(60)     NULL,                -- Cadastro Ambiental Rural
  `ccir`               VARCHAR(40)     NULL,                -- registro INCRA
  `municipio`          VARCHAR(120)    NULL,
  `uf`                 CHAR(2)         NULL,
  `endereco`           VARCHAR(240)    NULL,
  `area_total_ha`      DECIMAL(12,3)   NOT NULL DEFAULT 0,  -- área registrada da propriedade
  `latitude`           DECIMAL(10,7)   NULL,                -- sede
  `longitude`          DECIMAL(10,7)   NULL,
  `ativo`              TINYINT(1)      NOT NULL DEFAULT 1,
  `observacoes`        VARCHAR(500)    NULL,
  `created_by`         BIGINT UNSIGNED NULL,
  `updated_by`         BIGINT UNSIGNED NULL,
  `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fazenda_tenant_codigo` (`tenant_id`,`codigo`),
  KEY `ix_fazenda_tenant_ativo` (`tenant_id`,`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3) agro_talhoes — área / CENTRO DE CUSTO primário (alvo_tipo='talhao')
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agro_talhoes` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`        BIGINT UNSIGNED NOT NULL,
  `fazenda_id`       BIGINT UNSIGNED NOT NULL,
  `codigo`           VARCHAR(20)     NOT NULL,             -- ex.: 3C
  `nome`             VARCHAR(160)    NULL,
  `area_ha`          DECIMAL(12,3)   NOT NULL DEFAULT 0,
  `tipo_solo`        VARCHAR(80)     NULL,                 -- Latossolo, Argissolo...
  `aptidao`          ENUM('lavoura','pastagem','reserva','infra','outro') NOT NULL DEFAULT 'lavoura',
  `cultura_atual_id` BIGINT UNSIGNED NULL,                -- cache (fonte: safra ativa)
  `safra_atual_id`   BIGINT UNSIGNED NULL,                -- cache (ref. lógica p/ agro_safras)
  `geometria`        GEOMETRY        NULL,                -- polígono GIS (SRID 4326 por convenção)
  `centroide_lat`    DECIMAL(10,7)   NULL,                -- pino no mapa
  `centroide_lng`    DECIMAL(10,7)   NULL,
  `ativo`            TINYINT(1)      NOT NULL DEFAULT 1,
  `observacoes`      VARCHAR(500)    NULL,
  `created_by`       BIGINT UNSIGNED NULL,
  `updated_by`       BIGINT UNSIGNED NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_talhao_fazenda_codigo` (`tenant_id`,`fazenda_id`,`codigo`),
  KEY `ix_talhao_fazenda` (`fazenda_id`),
  KEY `ix_talhao_cultura` (`cultura_atual_id`),
  KEY `ix_talhao_tenant_ativo` (`tenant_id`,`ativo`),
  CONSTRAINT `fk_talhao_fazenda`  FOREIGN KEY (`fazenda_id`)       REFERENCES `agro_fazendas`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_talhao_cultura`  FOREIGN KEY (`cultura_atual_id`) REFERENCES `agro_culturas`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4) agro_produtos — catálogo mestre de produtos/insumos
--    (saldo e custo médio móvel ficam no Estoque; aqui é só master data)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agro_produtos` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`        BIGINT UNSIGNED NOT NULL,
  `codigo`           VARCHAR(40)     NOT NULL,             -- SKU
  `nome`             VARCHAR(180)    NOT NULL,
  `categoria`        ENUM('defensivo','fertilizante','corretivo','semente','combustivel','veterinario','peca','outro') NOT NULL,
  `subcategoria`     VARCHAR(60)     NULL,                 -- herbicida/fungicida/inseticida · NPK...
  `unidade`          VARCHAR(20)     NOT NULL,             -- L, kg, sc, dose, un
  `principio_ativo`  VARCHAR(160)    NULL,
  `registro_mapa`    VARCHAR(40)     NULL,                 -- registro do defensivo
  `custo_referencia` DECIMAL(18,6)   NOT NULL DEFAULT 0,   -- referência · custo médio real vem do Estoque
  `estoque_minimo`   DECIMAL(18,3)   NOT NULL DEFAULT 0,   -- p/ alerta de mínimo
  `cultura_id`       BIGINT UNSIGNED NULL,                 -- sementes vinculadas a cultura
  `ativo`            TINYINT(1)      NOT NULL DEFAULT 1,
  `created_by`       BIGINT UNSIGNED NULL,
  `updated_by`       BIGINT UNSIGNED NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_produto_tenant_codigo` (`tenant_id`,`codigo`),
  KEY `ix_produto_tenant_cat` (`tenant_id`,`categoria`,`ativo`),
  KEY `ix_produto_cultura` (`cultura_id`),
  CONSTRAINT `fk_produto_cultura` FOREIGN KEY (`cultura_id`) REFERENCES `agro_culturas`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5) ALTER condicional — evolução de scaffold pré-existente
--    Adiciona colunas territoriais/GIS caso a base já tenha as tabelas sem elas.
-- ----------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_talhoes' AND COLUMN_NAME='geometria');
SET @sql := IF(@col=0,'ALTER TABLE `agro_talhoes` ADD COLUMN `geometria` GEOMETRY NULL AFTER `safra_atual_id`','DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_talhoes' AND COLUMN_NAME='centroide_lat');
SET @sql := IF(@col=0,'ALTER TABLE `agro_talhoes` ADD COLUMN `centroide_lat` DECIMAL(10,7) NULL, ADD COLUMN `centroide_lng` DECIMAL(10,7) NULL','DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_fazendas' AND COLUMN_NAME='car');
SET @sql := IF(@col=0,'ALTER TABLE `agro_fazendas` ADD COLUMN `car` VARCHAR(60) NULL AFTER `inscricao_estadual`','DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------------------
-- 6) Seed de permissões (globais — tabela permissions sem tenant; idempotente)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`slug`,`label`,`modulo`) VALUES
  ('agro.fazenda.ver',      'Ver fazendas',              'agro'),
  ('agro.fazenda.criar',    'Criar fazendas',            'agro'),
  ('agro.fazenda.editar',   'Editar fazendas',           'agro'),
  ('agro.fazenda.excluir',  'Excluir fazendas',          'agro'),
  ('agro.talhao.ver',       'Ver talhões',               'agro'),
  ('agro.talhao.criar',     'Criar talhões',             'agro'),
  ('agro.talhao.editar',    'Editar talhões',            'agro'),
  ('agro.talhao.excluir',   'Excluir talhões',           'agro'),
  ('agro.talhao.geometria', 'Editar geometria (mapa)',   'agro'),
  ('agro.cultura.ver',      'Ver culturas',              'agro'),
  ('agro.cultura.gerenciar','Gerenciar culturas',        'agro'),
  ('agro.produto.ver',      'Ver produtos/insumos',      'agro'),
  ('agro.produto.gerenciar','Gerenciar produtos/insumos','agro');

-- ----------------------------------------------------------------------------
-- 7) Seed de culturas-base (opcional) — defina @tenant antes de rodar este bloco
--    SET @tenant := <id_do_tenant>;
-- ----------------------------------------------------------------------------
-- INSERT IGNORE INTO `agro_culturas` (`tenant_id`,`nome`,`tipo`,`unidade_padrao`,`ciclo_dias`,`cor`) VALUES
--   (@tenant,'Soja',   'grao',    'sc60', 120, '#4F7D3A'),
--   (@tenant,'Milho',  'grao',    'sc60', 140, '#B57C1A'),
--   (@tenant,'Algodão','fibra',   '@',    180, '#C9C2A0'),
--   (@tenant,'Pastagem','pastagem','kg',  NULL, '#8BA84A');

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
--  FIM · Migração 101 — Fazendas & Talhões
-- ============================================================================
