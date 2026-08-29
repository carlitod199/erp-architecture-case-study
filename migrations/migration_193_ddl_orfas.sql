-- ============================================================================
-- migration_193_ddl_orfas.sql  |  VERO
-- Versiona o DDL de 5 tabelas que eram usadas pelo código mas NAO tinham
-- CREATE TABLE em migrations/ (existiam só em dumps/backups) — Sprint Zero #6.
--   almoxarifados        (estoque/auditoria.php, base do kardex de packing)
--   estoque_saldos       (includes/vero_services.php)
--   comercial_romaneios  (comercial/vendas.php)
--   comercial_logistica  (comercial/vendas.php)
--   custeio_rateios      (custeio/fechamento.php)
-- Fonte do DDL: deploy/_local/vero_homolog_dump.sql (homologação).
-- Idempotente: CREATE TABLE IF NOT EXISTS — ambientes existentes ficam intactos;
-- serve para criar ambientes NOVOS sem depender de dump. AUTO_INCREMENT zerado.
-- ATENÇÃO: validar contra o schema de produção real antes de confiar em ambiente
-- novo (dump != produção é o risco conhecido). NAO inclui armazenagem_* (é só
-- slug de menu, sem tabela real). Backup antes (mysqldump). Rollback: DROP das
-- tabelas apenas em ambiente novo onde foram criadas por esta migration.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---- almoxarifados ----
CREATE TABLE IF NOT EXISTS `almoxarifados` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `fazenda_id` bigint(20) unsigned DEFAULT NULL,
  `nome` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_almox_tenant` (`tenant_id`),
  KEY `idx_almox_fazenda` (`fazenda_id`),
  CONSTRAINT `fk_almox_fazenda` FOREIGN KEY (`fazenda_id`) REFERENCES `agro_fazendas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_almox_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- estoque_saldos ----
CREATE TABLE IF NOT EXISTS `estoque_saldos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `produto_id` bigint(20) unsigned NOT NULL,
  `almoxarifado_id` bigint(20) unsigned NOT NULL,
  `quantidade` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `custo_medio` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `valor_total` decimal(18,2) NOT NULL DEFAULT '0.00',
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_saldo` (`tenant_id`,`produto_id`,`almoxarifado_id`),
  KEY `idx_saldo_tenant` (`tenant_id`),
  KEY `fk_saldo_produto` (`produto_id`),
  KEY `fk_saldo_almox` (`almoxarifado_id`),
  CONSTRAINT `fk_saldo_almox` FOREIGN KEY (`almoxarifado_id`) REFERENCES `almoxarifados` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_saldo_produto` FOREIGN KEY (`produto_id`) REFERENCES `estoque_produtos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_saldo_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- comercial_romaneios ----
CREATE TABLE IF NOT EXISTS `comercial_romaneios` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `venda_id` bigint(20) unsigned DEFAULT NULL,
  `colheita_carga_id` bigint(20) unsigned DEFAULT NULL,
  `romaneio` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `peso_kg` decimal(18,3) NOT NULL DEFAULT '0.000',
  `data_romaneio` date NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comrom_tenant` (`tenant_id`),
  KEY `idx_comrom_venda` (`venda_id`),
  KEY `idx_comrom_carga` (`colheita_carga_id`),
  CONSTRAINT `fk_comrom_carga` FOREIGN KEY (`colheita_carga_id`) REFERENCES `colheita_cargas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comrom_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comrom_venda` FOREIGN KEY (`venda_id`) REFERENCES `comercial_vendas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- comercial_logistica ----
CREATE TABLE IF NOT EXISTS `comercial_logistica` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `venda_id` bigint(20) unsigned DEFAULT NULL,
  `tipo_frete` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transportadora` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `placa` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `frete` decimal(18,2) NOT NULL DEFAULT '0.00',
  `cte_numero` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('previsto','em_transito','entregue','cancelado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'previsto',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comlog_tenant` (`tenant_id`),
  KEY `idx_comlog_venda` (`venda_id`),
  CONSTRAINT `fk_comlog_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comlog_venda` FOREIGN KEY (`venda_id`) REFERENCES `comercial_vendas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- custeio_rateios ----
CREATE TABLE IF NOT EXISTS `custeio_rateios` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `nome` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `base` enum('area','producao','custo_direto','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'area',
  `config` json DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rateio_tenant` (`tenant_id`),
  CONSTRAINT `fk_rateio_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;
