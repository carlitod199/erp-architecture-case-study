-- ============================================================================
-- migration_132_comercial.sql  |  VERO
-- Comercialização: compradores normalizados (com campos fiscais = "terra
-- pronta" para NFe na fase 2), venda por válvula × safra puxando a colheita,
-- qualidade vendida por categoria, anexos NF/boleto (via agro_anexos) e
-- vínculo com contas a receber (movimentacoes_financeiras).
-- Pré-requisitos: migrations 120, 130 e 131 aplicadas. Backup antes.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- 1) comercial_compradores — hoje `comercial_vendas.cliente` é VARCHAR.
--    Normaliza e já prepara o fiscal (CNPJ, IE, endereço) sem ativar NFe.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comercial_compradores (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    razao_social   VARCHAR(180) NOT NULL,
    nome_fantasia  VARCHAR(180) NULL,
    cnpj_cpf       VARCHAR(18)  NULL,
    inscricao_estadual VARCHAR(30) NULL,
    email          VARCHAR(150) NULL,
    telefone       VARCHAR(20)  NULL,
    logradouro     VARCHAR(180) NULL,
    numero         VARCHAR(20)  NULL,
    bairro         VARCHAR(100) NULL,
    cidade         VARCHAR(100) NULL,
    uf             CHAR(2)      NULL,
    cep            VARCHAR(10)  NULL,
    observacao     VARCHAR(255) NULL,
    ativo          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_comprador_tenant (tenant_id),
    KEY idx_comprador_doc (cnpj_cpf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2) comercial_vendas — venda amarrada a comprador, válvula, safra e colheita.
--    Ao escolher válvula+safra a tela puxa colheita_registros (produção e
--    qualidade) e preenche automaticamente (requisito). Contas a receber:
--    movimentacao_id aponta para movimentacoes_financeiras (tipo='receber',
--    origem_tipo='comercial_venda', origem_id=venda.id — já suportado).
--    Coluna `cliente` (varchar) NÃO é removida nesta fase (rollback fácil);
--    remoção fica para migration de limpeza pós-estabilização.
-- ----------------------------------------------------------------------------
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE comercial_vendas
     ADD COLUMN comprador_id         BIGINT UNSIGNED NULL AFTER cliente,
     ADD COLUMN talhao_id            BIGINT UNSIGNED NULL AFTER safra_id,
     ADD COLUMN setor_id             BIGINT UNSIGNED NULL AFTER talhao_id,
     ADD COLUMN colheita_registro_id BIGINT UNSIGNED NULL AFTER setor_id,
     ADD COLUMN kg_total             DECIMAL(18,3) NOT NULL DEFAULT 0,
     ADD COLUMN frutos_perdidos_pct  DECIMAL(6,2)  NULL,
     ADD COLUMN data_vencimento      DATE NULL,
     ADD COLUMN status_pagamento     ENUM(''pendente'',''pago'',''atrasado'',''cancelado'') NOT NULL DEFAULT ''pendente'',
     ADD COLUMN data_pagamento       DATE NULL,
     ADD COLUMN movimentacao_id      BIGINT UNSIGNED NULL,
     ADD COLUMN observacao           VARCHAR(255) NULL,
     ADD KEY idx_venda_comprador (comprador_id),
     ADD KEY idx_venda_setor (setor_id),
     ADD KEY idx_venda_colheita (colheita_registro_id),
     ADD KEY idx_venda_movimentacao (movimentacao_id),
     ADD CONSTRAINT fk_venda_comprador FOREIGN KEY (comprador_id) REFERENCES comercial_compradores (id),
     ADD CONSTRAINT fk_venda_setor     FOREIGN KEY (setor_id)     REFERENCES agro_setores (id),
     ADD CONSTRAINT fk_venda_colheita  FOREIGN KEY (colheita_registro_id) REFERENCES colheita_registros (id)',
  'SELECT ''comercial_vendas ja alterado''')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'comercial_vendas' AND column_name = 'comprador_id');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- movimentacao_id: sem FK (evita dependência circular com hash-chain; vínculo por service).

-- ----------------------------------------------------------------------------
-- 3) comercial_venda_qualidades — categorias vendidas (Premium/CAT1..3) com
--    %, preço e kg — rastreabilidade produção → destino da venda (requisito).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comercial_venda_qualidades (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    venda_id    BIGINT UNSIGNED NOT NULL,
    categoria   ENUM('premium','cat1','cat2','cat3') NOT NULL,
    percentual  DECIMAL(6,2)  NOT NULL DEFAULT 0,
    preco_kg    DECIMAL(18,6) NOT NULL DEFAULT 0,
    kg          DECIMAL(18,3) NOT NULL DEFAULT 0,
    valor       DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_venda_categoria (venda_id, categoria),
    KEY idx_vq_tenant (tenant_id),
    CONSTRAINT fk_vq_venda FOREIGN KEY (venda_id) REFERENCES comercial_vendas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4) Anexos de NF e boleto: NÃO cria tabela nova. Usa agro_anexos (migration
--    120, polimórfica): origem_tipo='comercial_venda', origem_id=venda.id,
--    categoria do arquivo ('nf' | 'boleto') no campo apropriado da 120.
--    Upload validado no service: pdf/jpg/png, tamanho via UPLOAD_MAX_SIZE.
-- ----------------------------------------------------------------------------

-- Fim da migration 132.
