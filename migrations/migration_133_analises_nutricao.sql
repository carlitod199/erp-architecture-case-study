-- ============================================================================
-- migration_133_analises_nutricao.sql  |  VERO
-- Núcleo do diferencial: análises de solo e foliares, catálogo de nutrientes,
-- faixas nutricionais (mín/ideal/máx por variedade/porta-enxerto/fase — quem
-- fornece as faixas é o RT/laboratório, decisão validada 03/07/2026) e base
-- para o Dashboard Nutricional (radar, ranking, alertas).
-- IA de extração de laudo (MVP, orçamento R$ 600/mês): usa agro_ia_extracoes
-- (migration 120) — texto original + JSON + confiança + revisão humana
-- OBRIGATÓRIA antes de virar resultado. O sistema classifica SOMENTE contra
-- faixas cadastradas; nunca inventa referência.
-- Alertas nutricionais: usa agro_alertas (120, polimórfica) —
-- origem_tipo='analise_solo'|'analise_foliar'.
-- Pré-requisitos: migrations 120 e 130 aplicadas. Backup antes.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- 1) analise_nutrientes — catálogo de parâmetros (solo e/ou foliar).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS analise_nutrientes (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    nome           VARCHAR(80) NOT NULL,
    simbolo        VARCHAR(20) NULL,
    aplicacao      ENUM('solo','foliar','ambos') NOT NULL DEFAULT 'ambos',
    unidade_padrao VARCHAR(20) NULL COMMENT 'ex.: mg/dm3, g/kg, %, cmolc/dm3',
    ordem          INT NOT NULL DEFAULT 0,
    ativo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nutriente (tenant_id, nome, aplicacao),
    KEY idx_nutriente_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed (parâmetros vistos nas telas do sistema legado e laudos enviados)
INSERT INTO analise_nutrientes (tenant_id, nome, simbolo, aplicacao, unidade_padrao, ordem)
SELECT t.id, v.nome, v.simbolo, v.aplicacao, v.unidade, v.ordem
FROM tenants t
JOIN (
  SELECT 'pH (H2O)' AS nome, 'pH' AS simbolo, 'solo' AS aplicacao, '-' AS unidade, 1 AS ordem UNION ALL
  SELECT 'pH (CaCl2)',        'pH',   'solo',  '-',          2  UNION ALL
  SELECT 'Matéria orgânica',  'MO',   'solo',  'g/kg',       3  UNION ALL
  SELECT 'Fósforo',           'P',    'ambos', 'mg/dm3',     4  UNION ALL
  SELECT 'Potássio',          'K',    'ambos', 'mg/dm3',     5  UNION ALL
  SELECT 'Cálcio',            'Ca',   'ambos', 'cmolc/dm3',  6  UNION ALL
  SELECT 'Magnésio',          'Mg',   'ambos', 'cmolc/dm3',  7  UNION ALL
  SELECT 'Enxofre',           'S',    'ambos', 'mg/dm3',     8  UNION ALL
  SELECT 'Alumínio',          'Al',   'solo',  'cmolc/dm3',  9  UNION ALL
  SELECT 'CTC',               'CTC',  'solo',  'cmolc/dm3',  10 UNION ALL
  SELECT 'Saturação de bases','V',    'solo',  '%',          11 UNION ALL
  SELECT 'Nitrogênio',        'N',    'foliar','g/kg',       12 UNION ALL
  SELECT 'Boro',              'B',    'ambos', 'mg/dm3',     13 UNION ALL
  SELECT 'Cobre',             'Cu',   'ambos', 'mg/dm3',     14 UNION ALL
  SELECT 'Ferro',             'Fe',   'ambos', 'mg/dm3',     15 UNION ALL
  SELECT 'Manganês',          'Mn',   'ambos', 'mg/dm3',     16 UNION ALL
  SELECT 'Zinco',             'Zn',   'ambos', 'mg/dm3',     17
) v ON 1=1
WHERE NOT EXISTS (
  SELECT 1 FROM analise_nutrientes n
  WHERE n.tenant_id = t.id AND n.nome = v.nome AND n.aplicacao = v.aplicacao
);

-- ----------------------------------------------------------------------------
-- 2) analise_solo (cabeçalho) + resultados.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS analise_solo (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    fazenda_id     BIGINT UNSIGNED NULL,
    talhao_id      BIGINT UNSIGNED NULL,
    setor_id       BIGINT UNSIGNED NULL,
    safra_id       BIGINT UNSIGNED NULL,
    laboratorio_id BIGINT UNSIGNED NULL COMMENT 'ref agro_laboratorios (120) — sem FK por convenção',
    data_amostra   DATE NOT NULL,
    profundidade   VARCHAR(20) NULL COMMENT 'ex.: 0-20 cm',
    origem         ENUM('manual','excel','ia') NOT NULL DEFAULT 'manual',
    ia_extracao_id BIGINT UNSIGNED NULL COMMENT 'ref agro_ia_extracoes (120) — sem FK',
    status         ENUM('rascunho','registrado','validado') NOT NULL DEFAULT 'registrado',
    observacao     VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_asolo_tenant_data (tenant_id, data_amostra),
    KEY idx_asolo_talhao (talhao_id),
    KEY idx_asolo_setor (setor_id),
    KEY idx_asolo_safra (safra_id),
    CONSTRAINT fk_asolo_fazenda FOREIGN KEY (fazenda_id) REFERENCES agro_fazendas (id),
    CONSTRAINT fk_asolo_talhao  FOREIGN KEY (talhao_id)  REFERENCES agro_talhoes (id),
    CONSTRAINT fk_asolo_setor   FOREIGN KEY (setor_id)   REFERENCES agro_setores (id),
    CONSTRAINT fk_asolo_safra   FOREIGN KEY (safra_id)   REFERENCES agro_safras (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analise_solo_resultados (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    analise_id    BIGINT UNSIGNED NOT NULL,
    nutriente_id  BIGINT UNSIGNED NOT NULL,
    valor         DECIMAL(14,4) NOT NULL,
    unidade       VARCHAR(20) NULL,
    classificacao ENUM('muito_baixo','baixo','adequado','alto','excessivo') NULL COMMENT 'derivada da faixa cadastrada; NULL = sem faixa',
    faixa_id      BIGINT UNSIGNED NULL COMMENT 'snapshot da faixa usada',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asolo_result (analise_id, nutriente_id),
    KEY idx_asolor_tenant (tenant_id),
    CONSTRAINT fk_asolor_analise   FOREIGN KEY (analise_id)   REFERENCES analise_solo (id) ON DELETE CASCADE,
    CONSTRAINT fk_asolor_nutriente FOREIGN KEY (nutriente_id) REFERENCES analise_nutrientes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3) analise_foliar (cabeçalho) + resultados.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS analise_foliar (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    fazenda_id     BIGINT UNSIGNED NULL,
    talhao_id      BIGINT UNSIGNED NULL,
    setor_id       BIGINT UNSIGNED NULL,
    safra_id       BIGINT UNSIGNED NULL,
    variedade_id   BIGINT UNSIGNED NULL COMMENT 'ref agro_variedades (120) — sem FK',
    fenologia_id   BIGINT UNSIGNED NULL COMMENT 'ref agro_fenologia_estagios (120) — sem FK',
    parte_folha    ENUM('limbo','peciolo','folha_inteira') NULL,
    laboratorio_id BIGINT UNSIGNED NULL,
    data_amostra   DATE NOT NULL,
    origem         ENUM('manual','excel','ia') NOT NULL DEFAULT 'manual',
    ia_extracao_id BIGINT UNSIGNED NULL,
    status         ENUM('rascunho','registrado','validado') NOT NULL DEFAULT 'registrado',
    observacao     VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_afoliar_tenant_data (tenant_id, data_amostra),
    KEY idx_afoliar_talhao (talhao_id),
    KEY idx_afoliar_variedade (variedade_id),
    KEY idx_afoliar_fenologia (fenologia_id),
    CONSTRAINT fk_afoliar_fazenda FOREIGN KEY (fazenda_id) REFERENCES agro_fazendas (id),
    CONSTRAINT fk_afoliar_talhao  FOREIGN KEY (talhao_id)  REFERENCES agro_talhoes (id),
    CONSTRAINT fk_afoliar_setor   FOREIGN KEY (setor_id)   REFERENCES agro_setores (id),
    CONSTRAINT fk_afoliar_safra   FOREIGN KEY (safra_id)   REFERENCES agro_safras (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analise_foliar_resultados (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    analise_id    BIGINT UNSIGNED NOT NULL,
    nutriente_id  BIGINT UNSIGNED NOT NULL,
    valor         DECIMAL(14,4) NOT NULL,
    unidade       VARCHAR(20) NULL,
    classificacao ENUM('muito_baixo','baixo','adequado','alto','excessivo') NULL,
    faixa_id      BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_afoliar_result (analise_id, nutriente_id),
    KEY idx_afoliarr_tenant (tenant_id),
    CONSTRAINT fk_afoliarr_analise   FOREIGN KEY (analise_id)   REFERENCES analise_foliar (id) ON DELETE CASCADE,
    CONSTRAINT fk_afoliarr_nutriente FOREIGN KEY (nutriente_id) REFERENCES analise_nutrientes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4) analise_faixas — referências mín/ideal/máx cadastradas pelo RT/lab
--    (tela "Nova faixa nutricional" do sistema legado: tipo, nutriente, unidade,
--    variedade opcional, porta-enxerto opcional, fase opcional).
--    O sistema NUNCA classifica sem faixa cadastrada.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS analise_faixas (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    tipo           ENUM('solo','foliar') NOT NULL,
    nutriente_id   BIGINT UNSIGNED NOT NULL,
    unidade        VARCHAR(20) NULL,
    variedade_id   BIGINT UNSIGNED NULL COMMENT 'ref agro_variedades (120) — sem FK',
    porta_enxerto  VARCHAR(80)  NULL,
    fenologia_id   BIGINT UNSIGNED NULL COMMENT 'ref agro_fenologia_estagios (120) — sem FK',
    minimo         DECIMAL(14,4) NULL,
    ideal_min      DECIMAL(14,4) NULL,
    ideal_max      DECIMAL(14,4) NULL,
    maximo         DECIMAL(14,4) NULL,
    observacao     VARCHAR(255) NULL,
    ativo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_faixa_lookup (tenant_id, tipo, nutriente_id, variedade_id, fenologia_id),
    CONSTRAINT fk_faixa_nutriente FOREIGN KEY (nutriente_id) REFERENCES analise_nutrientes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Nota: telas do sistema legado usam Mínimo/Ideal/Máximo (3 valores). ideal_min/ideal_max
-- cobrem tanto ideal pontual (ideal_min=ideal_max) quanto faixa "adeq 30–35".

-- ----------------------------------------------------------------------------
-- 5) Análise de amido e microbiológica (laudos enviados): FASE 2 — pendente
--    de validação do fluxo com o RT. Nenhuma tabela criada nesta migration.
-- ----------------------------------------------------------------------------

-- Fim da migration 133.
