-- ============================================================================
-- migration_120_viticultura_backbone.sql
-- VERO Agro  |  Vertical Viticultura  |  Backbone transversal (somente base)
-- ----------------------------------------------------------------------------
-- Cria APENAS as 10 tabelas transversais reutilizadas por MIP, Grade, Residuos,
-- Dormex, Tratos, Irrigacao, Analises e Drone. NAO cria nenhum dos 11 modulos.
--
-- Convencoes VERO: InnoDB, utf8mb4_unicode_ci, PK BIGINT UNSIGNED `id`,
-- tenant_id em todas, created_at/updated_at/created_by/updated_by,
-- custo unitario DECIMAL(18,6), monetario DECIMAL(18,2), area/peso DECIMAL(12,3),
-- sem triggers, logica em service.
--
-- FK DEFENSIVA:
--   - FK rigida SOMENTE para tabelas agro_* criadas nas migrations 101/102.
--   - Referencias a estoque (produto_id, lote_id) e custeio: colunas indexadas
--     SEM FK nesta etapa (preparam baixa de estoque e lancamento de custeio
--     FUTUROS, feitos por service). Confirmar nomes via check_prerequisites_120.sql
--     e adicionar as FKs numa migration posterior se desejado.
--   - created_by/updated_by/responsavel*/validado_por: SEM FK (regra VERO).
--
-- SEGURANCA AGRONOMICA: dose, carencia, ingrediente ativo e LMR sao apenas
-- REGISTRO HISTORICO, nunca recomendacao. IA e apenas apoio, com revisao humana
-- obrigatoria. Onde depende de RT/Agrofit/MAPA/Anvisa/bula => confirmar.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- 1) agro_variedades  (variedade ligada a cultura)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_variedades (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    cultura_id      BIGINT UNSIGNED NOT NULL,
    nome            VARCHAR(120)    NOT NULL,
    codigo          VARCHAR(40)     NULL,
    tipo_uso        VARCHAR(20)     NULL COMMENT 'mesa|vinho|suco|mista - confirmar lista real com cliente',
    porta_enxerto   VARCHAR(80)     NULL,
    apirenica       TINYINT(1)      NULL COMMENT '1=sem semente - confirmar',
    ciclo_dias      INT             NULL COMMENT 'estimativa de ciclo - confirmar com RT',
    ativo           TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by      BIGINT UNSIGNED NULL,
    updated_by      BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_variedade_tenant_cultura_nome (tenant_id, cultura_id, nome),
    KEY idx_variedade_tenant (tenant_id),
    KEY idx_variedade_cultura (cultura_id),
    CONSTRAINT fk_variedade_cultura FOREIGN KEY (cultura_id) REFERENCES agro_culturas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2) agro_fenologia_estagios  (catalogo de fases fenologicas)
--    Escala (BBCH | Eichhorn-Lorenz | propria) => confirmar com RT.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_fenologia_estagios (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    cultura_id      BIGINT UNSIGNED NOT NULL,
    escala          VARCHAR(30)     NOT NULL COMMENT 'BBCH|E-L|propria - confirmar',
    codigo          VARCHAR(20)     NOT NULL,
    ordem           INT             NOT NULL DEFAULT 0,
    nome            VARCHAR(120)    NOT NULL,
    descricao       TEXT            NULL,
    ativo           TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by      BIGINT UNSIGNED NULL,
    updated_by      BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fenologia_tenant_cultura_escala_codigo (tenant_id, cultura_id, escala, codigo),
    KEY idx_fenologia_tenant (tenant_id),
    KEY idx_fenologia_cultura (cultura_id),
    CONSTRAINT fk_fenologia_cultura FOREIGN KEY (cultura_id) REFERENCES agro_culturas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3) agro_pontos_amostragem  (pontos georreferenciados por talhao)
--    geom POINT SRID 4326 opcional (nulo permitido => sem indice espacial).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_pontos_amostragem (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    talhao_id       BIGINT UNSIGNED NOT NULL,
    rotulo          VARCHAR(80)     NOT NULL,
    geom            GEOMETRY SRID 4326 NULL COMMENT 'ponto (POINT) - SRID 4326, padrao igual agro_talhoes',
    ativo           TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by      BIGINT UNSIGNED NULL,
    updated_by      BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_ponto_tenant (tenant_id),
    KEY idx_ponto_talhao (talhao_id),
    CONSTRAINT fk_ponto_talhao FOREIGN KEY (talhao_id) REFERENCES agro_talhoes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4) agro_laboratorios  (laboratorios de analise)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_laboratorios (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    nome            VARCHAR(160)    NOT NULL,
    cnpj            VARCHAR(20)     NULL,
    contato         VARCHAR(160)    NULL,
    acreditacao     VARCHAR(120)    NULL COMMENT 'ex.: ISO/IEC 17025 - confirmar',
    ativo           TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by      BIGINT UNSIGNED NULL,
    updated_by      BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lab_tenant_nome (tenant_id, nome),
    KEY idx_lab_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5) agro_aplicacoes  (CABECALHO - espinha de aplicacao)
--    Polimorfica por `tipo`. Reusada por pulverizacao, fertirrigacao,
--    indutor de brotacao (Dormex) e foliar. Flags estoque_baixado / custeio_lancado
--    preparam baixa de estoque e lancamento de custeio FUTUROS (via service).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_aplicacoes (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    tipo                    ENUM('pulverizacao','fertirrigacao','indutor_brotacao','foliar','tratamento','outro')
                                            NOT NULL DEFAULT 'pulverizacao',
    fazenda_id              BIGINT UNSIGNED NOT NULL,
    talhao_id               BIGINT UNSIGNED NOT NULL,
    safra_id                BIGINT UNSIGNED NOT NULL,
    variedade_id            BIGINT UNSIGNED NULL,
    fenologia_id            BIGINT UNSIGNED NULL,
    data                    DATE            NOT NULL COMMENT 'data do evento - usada nos indices',
    data_prevista           DATE            NULL,
    responsavel_id          BIGINT UNSIGNED NULL COMMENT 'executor - sem FK (regra VERO)',
    responsavel_tecnico_id  BIGINT UNSIGNED NULL COMMENT 'RT - sem FK - confirmar quem e RT',
    maquina_id              BIGINT UNSIGNED NULL COMMENT 'ref futura ao modulo Maquinas (Fase 2) - sem FK',
    condicao_climatica      JSON            NULL COMMENT 'temp/umidade/vento - registro, nao recomendacao',
    custo_total             DECIMAL(18,2)   NULL COMMENT 'preenchido por service ao lancar custeio',
    estoque_baixado         TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'flag de baixa de estoque futura',
    custeio_lancado         TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'flag de lancamento de custeio futuro',
    status                  ENUM('rascunho','planejada','registrada','validada','cancelada')
                                            NOT NULL DEFAULT 'rascunho',
    validado_por            BIGINT UNSIGNED NULL COMMENT 'RT validador - sem FK',
    validado_em             TIMESTAMP       NULL,
    observacao              TEXT            NULL,
    created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by              BIGINT UNSIGNED NULL,
    updated_by              BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_aplic_tenant (tenant_id),
    KEY idx_aplic_safra (safra_id),
    KEY idx_aplic_talhao (talhao_id),
    KEY idx_aplic_variedade (variedade_id),
    KEY idx_aplic_data (data),
    KEY idx_aplic_tipo (tipo),
    KEY idx_aplic_status (status),
    KEY idx_aplic_maquina (maquina_id),
    CONSTRAINT fk_aplic_fazenda   FOREIGN KEY (fazenda_id)   REFERENCES agro_fazendas (id),
    CONSTRAINT fk_aplic_talhao    FOREIGN KEY (talhao_id)    REFERENCES agro_talhoes (id),
    CONSTRAINT fk_aplic_safra     FOREIGN KEY (safra_id)     REFERENCES agro_safras (id),
    CONSTRAINT fk_aplic_variedade FOREIGN KEY (variedade_id) REFERENCES agro_variedades (id),
    CONSTRAINT fk_aplic_fenologia FOREIGN KEY (fenologia_id) REFERENCES agro_fenologia_estagios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6) agro_aplicacao_itens  (ITENS - produtos usados na aplicacao)
--    dose/carencia/ingrediente_ativo = REGISTRO HISTORICO, nunca recomendacao.
--    produto_id / lote_id = ref a estoque SEM FK (baixa futura por service).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_aplicacao_itens (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    aplicacao_id            BIGINT UNSIGNED NOT NULL,
    produto_id              BIGINT UNSIGNED NULL COMMENT 'ref estoque_produtos - sem FK (confirmar) - baixa futura',
    lote_id                 BIGINT UNSIGNED NULL COMMENT 'ref estoque_lotes - sem FK (confirmar) - rastreio de lote',
    ingrediente_ativo       VARCHAR(160)    NULL COMMENT 'registro historico - confirmar via Agrofit',
    finalidade              VARCHAR(160)    NULL COMMENT 'alvo/finalidade - registro historico',
    dose_valor              DECIMAL(18,6)   NULL COMMENT 'HISTORICO registrado - NAO e recomendacao',
    dose_unidade            VARCHAR(20)     NULL,
    quantidade_consumida    DECIMAL(18,6)   NULL,
    quantidade_unidade      VARCHAR(20)     NULL,
    custo_unitario          DECIMAL(18,6)   NULL COMMENT 'preenchido por service (custo medio do estoque)',
    custo_total             DECIMAL(18,2)   NULL COMMENT 'preenchido por service',
    carencia_dias           INT             NULL COMMENT 'intervalo de seguranca - HISTORICO - confirmar bula/Anvisa',
    observacao              TEXT            NULL,
    created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by              BIGINT UNSIGNED NULL,
    updated_by              BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_aplicitem_tenant (tenant_id),
    KEY idx_aplicitem_aplicacao (aplicacao_id),
    KEY idx_aplicitem_produto (produto_id),
    KEY idx_aplicitem_lote (lote_id),
    CONSTRAINT fk_aplicitem_aplicacao FOREIGN KEY (aplicacao_id)
        REFERENCES agro_aplicacoes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7) agro_receituarios  (receituario/anexo tecnico ligado a aplicacao)
--    Documento em si vai para agro_anexos (origem_tipo='receituario').
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_receituarios (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id               BIGINT UNSIGNED NOT NULL,
    aplicacao_id            BIGINT UNSIGNED NULL,
    numero                  VARCHAR(60)     NULL,
    emitido_por             BIGINT UNSIGNED NULL COMMENT 'RT emissor - sem FK - confirmar',
    emitido_em              DATE            NULL,
    validade                DATE            NULL,
    observacao              TEXT            NULL,
    created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by              BIGINT UNSIGNED NULL,
    updated_by              BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_receit_tenant (tenant_id),
    KEY idx_receit_aplicacao (aplicacao_id),
    CONSTRAINT fk_receit_aplicacao FOREIGN KEY (aplicacao_id)
        REFERENCES agro_aplicacoes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8) agro_anexos  (anexos polimorficos - referencia/URL, nao BLOB)
--    origem_tipo: aplicacao|receituario|monitoramento|ocorrencia|analise_foliar|
--                 analise_solo|analise_micro|residuo|drone|... (modulos futuros)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_anexos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NOT NULL,
    origem_tipo     VARCHAR(40)     NOT NULL,
    origem_id       BIGINT UNSIGNED NOT NULL,
    tipo_arquivo    VARCHAR(20)     NULL COMMENT 'pdf|jpg|png|xlsx|...',
    nome_original   VARCHAR(255)    NULL,
    url             VARCHAR(512)    NOT NULL COMMENT 'referencia/URL - nao armazenar binario aqui',
    tamanho_bytes   BIGINT UNSIGNED NULL,
    hash_sha256     CHAR(64)        NULL COMMENT 'integridade do arquivo',
    descricao       VARCHAR(255)    NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by      BIGINT UNSIGNED NULL,
    updated_by      BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_anexo_tenant (tenant_id),
    KEY idx_anexo_origem (origem_tipo, origem_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9) agro_ia_extracoes  (IA APENAS COMO APOIO - revisao humana obrigatoria)
--    Guarda texto original + json extraido + confianca + status de revisao.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_ia_extracoes (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    origem_tipo         VARCHAR(40)     NOT NULL COMMENT 'analise_foliar|analise_solo|analise_micro|residuo|imagem_sintoma|...',
    origem_id           BIGINT UNSIGNED NULL,
    anexo_id            BIGINT UNSIGNED NULL,
    modelo              VARCHAR(80)     NULL,
    texto_original      LONGTEXT        NULL COMMENT 'preservar o texto bruto do laudo',
    json_extraido       JSON            NULL,
    confianca           DECIMAL(5,4)    NULL COMMENT '0..1 - confianca exibida ao usuario',
    status_revisao      ENUM('pendente','revisado_aprovado','revisado_corrigido','rejeitado')
                                        NOT NULL DEFAULT 'pendente',
    revisado_por        BIGINT UNSIGNED NULL COMMENT 'sem FK',
    revisado_em         TIMESTAMP       NULL,
    observacao_revisao  TEXT            NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by          BIGINT UNSIGNED NULL,
    updated_by          BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_iaext_tenant (tenant_id),
    KEY idx_iaext_origem (origem_tipo, origem_id),
    KEY idx_iaext_status (status_revisao),
    KEY idx_iaext_anexo (anexo_id),
    CONSTRAINT fk_iaext_anexo FOREIGN KEY (anexo_id)
        REFERENCES agro_anexos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10) agro_alertas  (alertas polimorficos - CONFERENCIA, nunca ordem de aplicar)
--     FK nullable para fazenda/talhao/safra (filtragem). origem_* polimorfico.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agro_alertas (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id                   BIGINT UNSIGNED NOT NULL,
    categoria                   VARCHAR(30)     NOT NULL COMMENT 'mip|irrigacao|foliar|solo|micro|residuo|dormex|maquina|drone|grade',
    origem_tipo                 VARCHAR(40)     NULL,
    origem_id                   BIGINT UNSIGNED NULL,
    fazenda_id                  BIGINT UNSIGNED NULL,
    talhao_id                   BIGINT UNSIGNED NULL,
    safra_id                    BIGINT UNSIGNED NULL,
    severidade                  ENUM('info','atencao','critico') NOT NULL DEFAULT 'atencao',
    titulo                      VARCHAR(160)    NOT NULL,
    mensagem                    TEXT            NULL,
    requer_validacao_tecnica    TINYINT(1)      NOT NULL DEFAULT 0,
    status                      ENUM('aberto','reconhecido','resolvido','descartado')
                                                NOT NULL DEFAULT 'aberto',
    data                        DATE            NOT NULL,
    reconhecido_por             BIGINT UNSIGNED NULL,
    reconhecido_em              TIMESTAMP       NULL,
    created_at                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by                  BIGINT UNSIGNED NULL,
    updated_by                  BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_alerta_tenant (tenant_id),
    KEY idx_alerta_categoria (categoria),
    KEY idx_alerta_status (status),
    KEY idx_alerta_talhao (talhao_id),
    KEY idx_alerta_safra (safra_id),
    KEY idx_alerta_data (data),
    KEY idx_alerta_origem (origem_tipo, origem_id),
    CONSTRAINT fk_alerta_fazenda FOREIGN KEY (fazenda_id) REFERENCES agro_fazendas (id) ON DELETE SET NULL,
    CONSTRAINT fk_alerta_talhao  FOREIGN KEY (talhao_id)  REFERENCES agro_talhoes (id)  ON DELETE SET NULL,
    CONSTRAINT fk_alerta_safra   FOREIGN KEY (safra_id)   REFERENCES agro_safras (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- BLOCO RBAC (seed minimo)  ==>  CONFIRMAR layout da tabela `permissions`.
-- Esta migration ASSUME colunas: name (UNIQUE), description.
-- Se o VERO usar outro layout (ex.: slug, guard_name, tenant_id em permissions),
-- ajuste APENAS este bloco. As 10 tabelas acima sao independentes deste bloco.
--
-- Atribuicao a roles (role_permissions) NAO e feita aqui por nao conhecermos os
-- IDs/nomes de roles do ambiente. Template comentado ao final.
-- ============================================================================
INSERT INTO permissions (name, description) VALUES
    ('agro.aplicacao.ver',      'Visualizar aplicacoes de viticultura'),
    ('agro.aplicacao.criar',    'Criar aplicacoes de viticultura'),
    ('agro.aplicacao.editar',   'Editar aplicacoes de viticultura'),
    ('agro.aplicacao.validar',  'Validar tecnicamente aplicacoes (RT)'),
    ('agro.aplicacao.auditar',  'Auditar/consultar historico de aplicacoes'),
    ('agro.anexo.ver',          'Visualizar anexos agro'),
    ('agro.anexo.criar',        'Anexar arquivos agro'),
    ('agro.anexo.editar',       'Editar/remover anexos agro'),
    ('agro.anexo.auditar',      'Auditar anexos agro'),
    ('agro.laudo.ver',          'Visualizar laudos/extracoes IA'),
    ('agro.laudo.importar',     'Importar laudo e disparar extracao IA (apoio)'),
    ('agro.laudo.validar',      'Validar/revisar extracao IA do laudo (humano)'),
    ('agro.laudo.auditar',      'Auditar laudos e revisoes de IA')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Template (descomente e ajuste IDs/nome de role conforme seu ambiente):
-- INSERT INTO role_permissions (role_id, permission_id)
-- SELECT r.id, p.id
-- FROM roles r
-- JOIN permissions p ON p.name IN (
--     'agro.aplicacao.ver','agro.aplicacao.criar','agro.aplicacao.editar',
--     'agro.aplicacao.validar','agro.aplicacao.auditar',
--     'agro.anexo.ver','agro.anexo.criar','agro.anexo.editar','agro.anexo.auditar',
--     'agro.laudo.ver','agro.laudo.importar','agro.laudo.validar','agro.laudo.auditar'
-- )
-- WHERE r.name = 'agronomo'   -- confirmar nome real do perfil
-- ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

-- ============================================================================
-- FIM migration_120_viticultura_backbone.sql
-- ============================================================================
