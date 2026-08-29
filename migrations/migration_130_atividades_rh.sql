-- ============================================================================
-- migration_130_atividades_rh.sql  |  VERO
-- Catálogo de atividades por cultura + mão de obra variável (premiação CLT,
-- terceirizados por diária/produção) + folha simplificada + encargos por tenant.
-- ----------------------------------------------------------------------------
-- Pré-requisitos: check_prerequisites_130.sql 100% OK (inclui migration 120).
-- Backup obrigatório antes:  mysqldump --single-transaction vero_db > pre_130.sql
-- ATENÇÃO: DDL em MySQL faz commit implícito (não é transacional). A segurança
-- vem de: backup + IF NOT EXISTS (idempotência) + rollback_130.sql.
-- Convenções VERO/VERO: InnoDB, utf8mb4_unicode_ci, tenant_id em tudo,
-- auditoria created/updated_at/by, created_by SEM FK, monetário DECIMAL(18,2),
-- valor unitário DECIMAL(18,6), quantidade DECIMAL(12,3), sem triggers.
-- FK rígida só para tabelas consolidadas (101/102); refs à 120 = índice sem FK.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- 1) agro_tipos_atividade — CATÁLOGO de operações (Poda, Colheita, Desbrota,
--    Amarrio, Raleio, Embalamento...). Diferente de agro_atividades (que é a
--    atividade PLANEJADA por talhão e permanece intocada).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_tipos_atividade (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    nome            VARCHAR(120)    NOT NULL,
    categoria       ENUM('trato_cultural','colheita','aplicacao','irrigacao','packing','outro') NOT NULL DEFAULT 'trato_cultural',
    unidade_padrao  ENUM('planta','caixa','kg','ha','metro_linear','hora','outro') NULL COMMENT 'unidade de produção default para premiação',
    exige_producao  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = normalmente apontada com quantidade produzida',
    ativo           TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by      BIGINT UNSIGNED NULL,
    updated_by      BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tipo_atividade (tenant_id, nome),
    KEY idx_tipoativ_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2) agro_tipo_atividade_culturas — N:N atividade × cultura.
--    Requisito: "apenas as atividades aplicáveis por cultura" no apontamento.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_tipo_atividade_culturas (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    tipo_atividade_id  BIGINT UNSIGNED NOT NULL,
    cultura_id         BIGINT UNSIGNED NOT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tipoativ_cultura (tenant_id, tipo_atividade_id, cultura_id),
    KEY idx_tac_cultura (cultura_id),
    CONSTRAINT fk_tac_tipo    FOREIGN KEY (tipo_atividade_id) REFERENCES agro_tipos_atividade (id) ON DELETE CASCADE,
    CONSTRAINT fk_tac_cultura FOREIGN KEY (cultura_id)        REFERENCES agro_culturas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3) agro_apontamentos — vincular ao catálogo + fase fenológica + hectares.
--    Colunas novas, nada é removido.
-- ----------------------------------------------------------------------------
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE agro_apontamentos
     ADD COLUMN tipo_atividade_id BIGINT UNSIGNED NULL AFTER atividade_id,
     ADD COLUMN fenologia_id      BIGINT UNSIGNED NULL AFTER tipo_atividade_id,
     ADD COLUMN hectares          DECIMAL(12,4)   NULL AFTER fenologia_id,
     ADD KEY idx_apont_tipoativ (tipo_atividade_id),
     ADD KEY idx_apont_fenologia (fenologia_id)',
  'SELECT ''apontamentos ja alterado''')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'agro_apontamentos' AND column_name = 'tipo_atividade_id');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- fenologia_id referencia agro_fenologia_estagios (migration 120): índice sem FK (convenção).

-- ----------------------------------------------------------------------------
-- 4) agro_operadores — dados de vínculo/salário p/ folha e premiação.
-- ----------------------------------------------------------------------------
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE agro_operadores
     ADD COLUMN tipo_vinculo   ENUM(''clt'',''diarista'',''terceirizado'',''outro'') NOT NULL DEFAULT ''clt'' AFTER funcao,
     ADD COLUMN salario_mensal DECIMAL(18,2) NULL AFTER tipo_vinculo,
     ADD COLUMN documento      VARCHAR(20)   NULL AFTER salario_mensal',
  'SELECT ''operadores ja alterado''')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'agro_operadores' AND column_name = 'tipo_vinculo');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 5) rh_terceirizados — prestadores de serviço (diária ou produção).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rh_terceirizados (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    nome               VARCHAR(150)  NOT NULL,
    documento          VARCHAR(20)   NULL,
    telefone           VARCHAR(20)   NULL,
    modalidade_padrao  ENUM('diaria','producao') NOT NULL DEFAULT 'producao',
    valor_diaria       DECIMAL(18,2) NULL,
    observacao         VARCHAR(255)  NULL,
    ativo              TINYINT(1)    NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_terc_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6) rh_regras_premiacao — meta + valor por unidade acima da meta, por
--    atividade (e opcionalmente por cultura). Ex.: Poda, meta 100 plantas/dia,
--    R$ 1,20/planta acima.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rh_regras_premiacao (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    tipo_atividade_id  BIGINT UNSIGNED NOT NULL,
    cultura_id         BIGINT UNSIGNED NULL COMMENT 'NULL = vale para todas as culturas da atividade',
    unidade            ENUM('planta','caixa','kg','ha','metro_linear','hora','outro') NOT NULL,
    meta_qtd           DECIMAL(12,3)  NOT NULL DEFAULT 0 COMMENT 'meta por diária/turno',
    valor_acima_meta   DECIMAL(18,6)  NOT NULL DEFAULT 0 COMMENT 'R$ por unidade acima da meta',
    vigencia_inicio    DATE NULL,
    vigencia_fim       DATE NULL,
    ativo              TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_regra_tenant_ativ (tenant_id, tipo_atividade_id),
    CONSTRAINT fk_regra_tipoativ FOREIGN KEY (tipo_atividade_id) REFERENCES agro_tipos_atividade (id),
    CONSTRAINT fk_regra_cultura  FOREIGN KEY (cultura_id)        REFERENCES agro_culturas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7) rh_producao_itens — produção/premiação por pessoa dentro de um
--    apontamento. Vale para colaborador (premiação acima da meta) e
--    terceirizado (produção ou diária). peso_kg cobre contentor de peso
--    variável (20–35 kg) na colheita. Todo item emite custo em
--    custeio_lancamentos via service (origem_tipo='rh_producao_item').
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rh_producao_itens (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    apontamento_id      BIGINT UNSIGNED NOT NULL,
    origem_pessoa       ENUM('colaborador','terceirizado') NOT NULL,
    operador_id         BIGINT UNSIGNED NULL,
    terceirizado_id     BIGINT UNSIGNED NULL,
    modalidade          ENUM('premiacao','producao','diaria') NOT NULL,
    regra_premiacao_id  BIGINT UNSIGNED NULL,
    unidade             ENUM('planta','caixa','kg','ha','metro_linear','hora','outro') NULL,
    quantidade          DECIMAL(12,3) NOT NULL DEFAULT 0,
    peso_kg             DECIMAL(12,3) NULL COMMENT 'peso real quando unidade=caixa/contentor variável',
    meta_aplicada       DECIMAL(12,3) NULL COMMENT 'snapshot da meta no momento do apontamento',
    valor_unitario      DECIMAL(18,6) NOT NULL DEFAULT 0,
    qtd_acima_meta      DECIMAL(12,3) NULL,
    valor_total         DECIMAL(18,2) NOT NULL DEFAULT 0,
    data_trabalho       DATE NOT NULL,
    observacao          VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_rhprod_tenant_data (tenant_id, data_trabalho),
    KEY idx_rhprod_apont (apontamento_id),
    KEY idx_rhprod_operador (operador_id),
    KEY idx_rhprod_terceirizado (terceirizado_id),
    CONSTRAINT fk_rhprod_apont FOREIGN KEY (apontamento_id)     REFERENCES agro_apontamentos (id) ON DELETE CASCADE,
    CONSTRAINT fk_rhprod_oper  FOREIGN KEY (operador_id)        REFERENCES agro_operadores (id),
    CONSTRAINT fk_rhprod_terc  FOREIGN KEY (terceirizado_id)    REFERENCES rh_terceirizados (id),
    CONSTRAINT fk_rhprod_regra FOREIGN KEY (regra_premiacao_id) REFERENCES rh_regras_premiacao (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8) rh_encargos_config — percentuais PARAMETRIZÁVEIS POR TENANT (decisão
--    validada 03/07/2026), com vigência. Seed com os valores das telas do sistema legado.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rh_encargos_config (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    vigencia_inicio    DATE NOT NULL,
    fgts_pct           DECIMAL(6,3) NOT NULL DEFAULT 8.000,
    inss_patronal_pct  DECIMAL(6,3) NOT NULL DEFAULT 20.000,
    rat_pct            DECIMAL(6,3) NOT NULL DEFAULT 2.000,
    terceiros_pct      DECIMAL(6,3) NOT NULL DEFAULT 5.800,
    ferias_pct         DECIMAL(6,3) NOT NULL DEFAULT 11.110,
    decimo_pct         DECIMAL(6,3) NOT NULL DEFAULT 8.330,
    outros_pct         DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    ativo              TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_encargos_vigencia (tenant_id, vigencia_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO rh_encargos_config (tenant_id, vigencia_inicio)
SELECT t.id, '2026-01-01' FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM rh_encargos_config c WHERE c.tenant_id = t.id);

-- ----------------------------------------------------------------------------
-- 9) rh_folha_periodos + rh_folha_lancamentos — folha simplificada
--    (lançamentos do mês + encargos calculados, como nas telas do sistema legado).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rh_folha_periodos (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    competencia DATE NOT NULL COMMENT 'primeiro dia do mês',
    status      ENUM('aberto','fechado') NOT NULL DEFAULT 'aberto',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_folha_competencia (tenant_id, competencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rh_folha_lancamentos (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    periodo_id         BIGINT UNSIGNED NOT NULL,
    operador_id        BIGINT UNSIGNED NOT NULL,
    salario_base       DECIMAL(18,2) NOT NULL DEFAULT 0,
    horas_extras       DECIMAL(10,2) NOT NULL DEFAULT 0,
    valor_hora_extra   DECIMAL(18,6) NOT NULL DEFAULT 0,
    horas_noturnas     DECIMAL(10,2) NOT NULL DEFAULT 0,
    valor_hora_noturna DECIMAL(18,6) NOT NULL DEFAULT 0,
    premiacoes_total   DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'somatório de rh_producao_itens do mês (snapshot)',
    total_bruto        DECIMAL(18,2) NOT NULL DEFAULT 0,
    enc_fgts           DECIMAL(18,2) NOT NULL DEFAULT 0,
    enc_inss_patronal  DECIMAL(18,2) NOT NULL DEFAULT 0,
    enc_rat            DECIMAL(18,2) NOT NULL DEFAULT 0,
    enc_terceiros      DECIMAL(18,2) NOT NULL DEFAULT 0,
    enc_ferias         DECIMAL(18,2) NOT NULL DEFAULT 0,
    enc_decimo         DECIMAL(18,2) NOT NULL DEFAULT 0,
    enc_outros         DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_encargos     DECIMAL(18,2) NOT NULL DEFAULT 0,
    custo_total        DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_folha_lanc (periodo_id, operador_id),
    KEY idx_folhal_tenant (tenant_id),
    CONSTRAINT fk_folhal_periodo  FOREIGN KEY (periodo_id)  REFERENCES rh_folha_periodos (id) ON DELETE CASCADE,
    CONSTRAINT fk_folhal_operador FOREIGN KEY (operador_id) REFERENCES agro_operadores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Seed do catálogo de atividades citadas nos requisitos (por tenant existente).
-- Vínculo com culturas fica a cargo da tela (culturas do cliente ainda não
-- estão cadastradas no banco).
-- ----------------------------------------------------------------------------
INSERT INTO agro_tipos_atividade (tenant_id, nome, categoria, unidade_padrao, exige_producao)
SELECT t.id, v.nome, v.categoria, v.unidade, v.exige
FROM tenants t
JOIN (
  SELECT 'Poda'        AS nome, 'trato_cultural' AS categoria, 'planta' AS unidade, 1 AS exige UNION ALL
  SELECT 'Colheita',            'colheita',                    'kg',              1 UNION ALL
  SELECT 'Desbrota',            'trato_cultural',              'planta',          0 UNION ALL
  SELECT 'Desponte',            'trato_cultural',              'planta',          0 UNION ALL
  SELECT 'Raleio',              'trato_cultural',              'planta',          0 UNION ALL
  SELECT 'Amarrio',             'trato_cultural',              'planta',          0 UNION ALL
  SELECT 'Degrana',             'trato_cultural',              'planta',          0 UNION ALL
  SELECT 'Embalamento',         'packing',                     'caixa',           1 UNION ALL
  SELECT 'Irrigação',           'irrigacao',                   'hora',            0
) v ON 1=1
WHERE NOT EXISTS (
  SELECT 1 FROM agro_tipos_atividade a
  WHERE a.tenant_id = t.id AND a.nome = v.nome
);

-- Fim da migration 130.
