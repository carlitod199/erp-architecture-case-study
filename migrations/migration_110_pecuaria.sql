-- ============================================================
-- VERO Agro — Módulo PECUÁRIA (Fase 2)
-- database/migrations/agro/migration_110_pecuaria.sql
-- ------------------------------------------------------------
-- Depende de: 101–109 (MVP agro) já aplicadas.
--   Usa: custeio_lancamentos (motor de custo polimórfico),
--        plano_contas, estoque_* (insumos/medicamentos),
--        talhões/áreas, safras, fornecedores (core), clientes (core),
--        pendencias (fila do field app).
-- Convenção: BIGINT UNSIGNED em ids/tenant_id; DECIMAL(18,6) custo
--   unitário; DECIMAL(18,2) monetário; DECIMAL(12,3) peso (kg);
--   InnoDB / utf8mb4; audit cols indexadas SEM FK p/ users.
-- Numeração: se o módulo de Máquinas já reservou 110, renumere
--   este arquivo para 111 (não há dependência entre eles).
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- [0] PONTO ÚNICO DE EXTENSÃO DO MVP
-- O custeio do MVP acumula custo por talhão/safra. Pecuária
-- precisa do LOTE como centro de custo. Tornamos o "alvo" do
-- custeio polimórfico (alvo_tipo/alvo_id) para que talhão, safra
-- E lote convivam no MESMO ledger — sem duplicar o motor.
--
-- ATENÇÃO: MySQL 5.7/8.0 não tem ADD COLUMN IF NOT EXISTS.
-- Rode este bloco SOMENTE se custeio_lancamentos ainda não tiver
-- alvo polimórfico. Se já tiver, pule para a seção [1].
-- ------------------------------------------------------------
-- ALTER TABLE custeio_lancamentos
--   ADD COLUMN alvo_tipo VARCHAR(40) NULL AFTER origem_id,
--   ADD COLUMN alvo_id   BIGINT UNSIGNED NULL AFTER alvo_tipo,
--   ADD KEY idx_custeio_alvo (tenant_id, alvo_tipo, alvo_id);
-- Backfill dos lançamentos existentes (exemplo):
--   UPDATE custeio_lancamentos SET alvo_tipo='talhao', alvo_id=talhao_id
--    WHERE talhao_id IS NOT NULL AND alvo_tipo IS NULL;
--   UPDATE custeio_lancamentos SET alvo_tipo='safra',  alvo_id=safra_id
--    WHERE safra_id  IS NOT NULL AND alvo_tipo IS NULL;

-- ------------------------------------------------------------
-- [1] CATÁLOGO: ESPÉCIES  ("item animal no catálogo" do Aegro)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pecuaria_especies (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  nome        VARCHAR(80)  NOT NULL,                 -- Bovino, Suíno, Ovino...
  finalidade  ENUM('corte','leite','reproducao','trabalho','outro')
              NOT NULL DEFAULT 'corte',
  unidade_peso ENUM('kg','@') NOT NULL DEFAULT 'kg', -- @ = arroba (corte)
  ativo       TINYINT(1) NOT NULL DEFAULT 1,
  created_by  BIGINT UNSIGNED NULL,
  updated_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pec_especie (tenant_id, nome),
  KEY idx_pec_especie_tenant (tenant_id),
  KEY idx_pec_especie_created_by (created_by),
  KEY idx_pec_especie_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- [2] CATÁLOGO: CATEGORIAS  (Bezerro, Garrote, Novilha, Boi, Vaca, Touro)
-- Ordenadas pelo ciclo de vida -> base da recategorização.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pecuaria_categorias (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  especie_id  BIGINT UNSIGNED NOT NULL,
  nome        VARCHAR(80) NOT NULL,                  -- "Bezerro(a)", "Boi gordo"...
  sexo        ENUM('M','F','MISTO') NOT NULL DEFAULT 'MISTO',
  ordem       SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- progressão do ciclo
  idade_min_meses SMALLINT UNSIGNED NULL,            -- p/ sugerir recategorização
  idade_max_meses SMALLINT UNSIGNED NULL,
  ativo       TINYINT(1) NOT NULL DEFAULT 1,
  created_by  BIGINT UNSIGNED NULL,
  updated_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pec_categoria (tenant_id, especie_id, nome),
  KEY idx_pec_categoria_tenant (tenant_id),
  KEY idx_pec_categoria_especie (especie_id),
  KEY idx_pec_categoria_created_by (created_by),
  KEY idx_pec_categoria_updated_by (updated_by),
  CONSTRAINT fk_pec_categoria_especie
    FOREIGN KEY (especie_id) REFERENCES pecuaria_especies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- [3] LOTES  (unidade operacional = "Gestão de lotes" do Aegro)
-- saldo_cabecas é DENORMALIZADO, mantido pelos services a partir
-- das movimentações (sem trigger — coerente com a arquitetura
-- de convergência de custo por serviço do MVP).
-- talhao_id / safra_id: FKs cross-módulo (ver seção [9]).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pecuaria_lotes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  codigo          VARCHAR(40)  NOT NULL,             -- ex.: "LOTE-2026-001"
  nome            VARCHAR(120) NOT NULL,
  especie_id      BIGINT UNSIGNED NOT NULL,
  categoria_id    BIGINT UNSIGNED NULL,              -- categoria predominante
  talhao_id       BIGINT UNSIGNED NULL,              -- pasto/área (cross-módulo)
  safra_id        BIGINT UNSIGNED NULL,              -- ciclo de engorda (opcional)
  saldo_cabecas   INT UNSIGNED NOT NULL DEFAULT 0,   -- derivado de movimentações
  data_abertura   DATE NULL,
  data_encerramento DATE NULL,
  status          ENUM('ativo','encerrado') NOT NULL DEFAULT 'ativo',
  observacao      VARCHAR(255) NULL,
  created_by      BIGINT UNSIGNED NULL,
  updated_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pec_lote (tenant_id, codigo),
  KEY idx_pec_lote_tenant (tenant_id),
  KEY idx_pec_lote_status (tenant_id, status),
  KEY idx_pec_lote_especie (especie_id),
  KEY idx_pec_lote_categoria (categoria_id),
  KEY idx_pec_lote_talhao (talhao_id),
  KEY idx_pec_lote_safra (safra_id),
  KEY idx_pec_lote_created_by (created_by),
  KEY idx_pec_lote_updated_by (updated_by),
  CONSTRAINT fk_pec_lote_especie
    FOREIGN KEY (especie_id) REFERENCES pecuaria_especies(id),
  CONSTRAINT fk_pec_lote_categoria
    FOREIGN KEY (categoria_id) REFERENCES pecuaria_categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- [4] ANIMAIS  (camada individual OPCIONAL — brinco/RFID)
-- O rebanho funciona por headcount no lote; quem rastreia
-- individualmente popula esta tabela. Movimentações podem ou não
-- referenciar animais específicos.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pecuaria_animais (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  lote_id         BIGINT UNSIGNED NULL,              -- lote atual
  especie_id      BIGINT UNSIGNED NOT NULL,
  categoria_id    BIGINT UNSIGNED NULL,
  identificacao   VARCHAR(60) NOT NULL,              -- brinco/RFID/SISBOV
  sexo            ENUM('M','F') NULL,
  data_nascimento DATE NULL,
  peso_atual      DECIMAL(12,3) NULL,                -- kg (última pesagem)
  data_peso_atual DATE NULL,
  origem          ENUM('nascimento','compra','transferencia') NULL,
  status          ENUM('ativo','vendido','morto','transferido','abatido')
                  NOT NULL DEFAULT 'ativo',
  observacao      VARCHAR(255) NULL,
  created_by      BIGINT UNSIGNED NULL,
  updated_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pec_animal (tenant_id, identificacao),
  KEY idx_pec_animal_tenant (tenant_id),
  KEY idx_pec_animal_lote (lote_id),
  KEY idx_pec_animal_status (tenant_id, status),
  KEY idx_pec_animal_especie (especie_id),
  KEY idx_pec_animal_created_by (created_by),
  KEY idx_pec_animal_updated_by (updated_by),
  CONSTRAINT fk_pec_animal_lote
    FOREIGN KEY (lote_id) REFERENCES pecuaria_lotes(id),
  CONSTRAINT fk_pec_animal_especie
    FOREIGN KEY (especie_id) REFERENCES pecuaria_especies(id),
  CONSTRAINT fk_pec_animal_categoria
    FOREIGN KEY (categoria_id) REFERENCES pecuaria_categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- [5] MOVIMENTAÇÕES  (FONTE DA VERDADE de saldo + gancho financeiro)
-- entrada_* sobe saldo; saida_* desce; transferência usa lote_destino_id.
-- compra/venda carregam valor e contraparte -> disparam financeiro
-- e custeio via origem_tipo='pecuaria_movimentacao'.
-- fornecedor_id / cliente_id: FKs cross-módulo (ver seção [9]).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pecuaria_movimentacoes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  lote_id         BIGINT UNSIGNED NOT NULL,
  tipo            ENUM(
                    'entrada_compra','entrada_nascimento','entrada_transferencia',
                    'entrada_ajuste',
                    'saida_venda','saida_morte','saida_abate','saida_consumo',
                    'saida_transferencia','saida_ajuste'
                  ) NOT NULL,
  data            DATE NOT NULL,
  categoria_id    BIGINT UNSIGNED NULL,              -- categoria no momento
  quantidade      INT UNSIGNED NOT NULL,             -- cabeças
  peso_total      DECIMAL(12,3) NULL,                -- kg movimentados
  peso_medio      DECIMAL(12,3) NULL,                -- kg/cabeça (derivado)
  valor_unitario  DECIMAL(18,6) NULL,                -- R$/cabeça ou R$/@
  valor_total     DECIMAL(18,2) NULL,                -- R$ (compra/venda)
  base_valor      ENUM('cabeca','arroba','kg') NULL, -- como valor_unitario é cotado
  fornecedor_id   BIGINT UNSIGNED NULL,              -- compra (core)
  cliente_id      BIGINT UNSIGNED NULL,              -- venda (core)
  lote_destino_id BIGINT UNSIGNED NULL,              -- transferência interna
  documento       VARCHAR(60) NULL,                  -- NF/GTA/recibo
  observacao      VARCHAR(255) NULL,
  created_by      BIGINT UNSIGNED NULL,
  updated_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pec_mov_tenant (tenant_id),
  KEY idx_pec_mov_lote (lote_id),
  KEY idx_pec_mov_tipo (tenant_id, tipo),
  KEY idx_pec_mov_data (tenant_id, data),
  KEY idx_pec_mov_destino (lote_destino_id),
  KEY idx_pec_mov_fornecedor (fornecedor_id),
  KEY idx_pec_mov_cliente (cliente_id),
  KEY idx_pec_mov_created_by (created_by),
  KEY idx_pec_mov_updated_by (updated_by),
  CONSTRAINT fk_pec_mov_lote
    FOREIGN KEY (lote_id) REFERENCES pecuaria_lotes(id),
  CONSTRAINT fk_pec_mov_lote_destino
    FOREIGN KEY (lote_destino_id) REFERENCES pecuaria_lotes(id),
  CONSTRAINT fk_pec_mov_categoria
    FOREIGN KEY (categoria_id) REFERENCES pecuaria_categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vínculo N:N opcional movimentação <-> animais (quando há rastreio individual)
CREATE TABLE IF NOT EXISTS pecuaria_movimentacao_animais (
  movimentacao_id BIGINT UNSIGNED NOT NULL,
  animal_id       BIGINT UNSIGNED NOT NULL,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (movimentacao_id, animal_id),
  KEY idx_pec_mov_animal_tenant (tenant_id),
  KEY idx_pec_mov_animal_animal (animal_id),
  CONSTRAINT fk_pec_mov_animal_mov
    FOREIGN KEY (movimentacao_id) REFERENCES pecuaria_movimentacoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_pec_mov_animal_animal
    FOREIGN KEY (animal_id) REFERENCES pecuaria_animais(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- [6] RECATEGORIZAÇÃO  (bezerro -> garrote -> boi; muda valor do ativo)
-- Não gera financeiro. Pode transferir custo proporcional entre
-- lotes/categorias via custeio (origem_tipo='pecuaria_recategorizacao').
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pecuaria_recategorizacoes (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id           BIGINT UNSIGNED NOT NULL,
  lote_id             BIGINT UNSIGNED NOT NULL,
  lote_destino_id     BIGINT UNSIGNED NULL,          -- NULL = mesmo lote
  categoria_origem_id BIGINT UNSIGNED NOT NULL,
  categoria_destino_id BIGINT UNSIGNED NOT NULL,
  quantidade          INT UNSIGNED NOT NULL,
  data                DATE NOT NULL,
  observacao          VARCHAR(255) NULL,
  created_by          BIGINT UNSIGNED NULL,
  updated_by          BIGINT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pec_recat_tenant (tenant_id),
  KEY idx_pec_recat_lote (lote_id),
  KEY idx_pec_recat_data (tenant_id, data),
  KEY idx_pec_recat_created_by (created_by),
  KEY idx_pec_recat_updated_by (updated_by),
  CONSTRAINT fk_pec_recat_lote
    FOREIGN KEY (lote_id) REFERENCES pecuaria_lotes(id),
  CONSTRAINT fk_pec_recat_lote_destino
    FOREIGN KEY (lote_destino_id) REFERENCES pecuaria_lotes(id),
  CONSTRAINT fk_pec_recat_cat_origem
    FOREIGN KEY (categoria_origem_id) REFERENCES pecuaria_categorias(id),
  CONSTRAINT fk_pec_recat_cat_destino
    FOREIGN KEY (categoria_destino_id) REFERENCES pecuaria_categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- [7] PESAGENS  (peso médio do rebanho + GMD)
-- Sem efeito financeiro. lote_id OU animal_id preenchido.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pecuaria_pesagens (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id           BIGINT UNSIGNED NOT NULL,
  lote_id             BIGINT UNSIGNED NULL,
  animal_id           BIGINT UNSIGNED NULL,
  data                DATE NOT NULL,
  tipo                ENUM('individual','amostral','total') NOT NULL DEFAULT 'amostral',
  quantidade_amostra  INT UNSIGNED NULL,             -- nº cabeças pesadas (amostral)
  peso_total          DECIMAL(12,3) NOT NULL,        -- kg
  peso_medio          DECIMAL(12,3) NOT NULL,        -- kg/cabeça
  gmd                 DECIMAL(8,3) NULL,             -- ganho médio diário kg/dia (derivado)
  observacao          VARCHAR(255) NULL,
  created_by          BIGINT UNSIGNED NULL,
  updated_by          BIGINT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pec_pesagem_tenant (tenant_id),
  KEY idx_pec_pesagem_lote (lote_id, data),
  KEY idx_pec_pesagem_animal (animal_id, data),
  KEY idx_pec_pesagem_created_by (created_by),
  KEY idx_pec_pesagem_updated_by (updated_by),
  CONSTRAINT fk_pec_pesagem_lote
    FOREIGN KEY (lote_id) REFERENCES pecuaria_lotes(id),
  CONSTRAINT fk_pec_pesagem_animal
    FOREIGN KEY (animal_id) REFERENCES pecuaria_animais(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- [8] EVENTOS SANITÁRIOS / MANEJO  (consome estoque + gera custo)
-- vacinação/vermifugação/medicação consomem medicamento do estoque
-- de insumos (reuso) e lançam custo no lote via custeio
-- (origem_tipo='pecuaria_evento_sanitario').
-- produto_id / estoque_movimentacao_id: cross-módulo (ver seção [9]).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pecuaria_eventos_sanitarios (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id               BIGINT UNSIGNED NOT NULL,
  lote_id                 BIGINT UNSIGNED NULL,
  animal_id               BIGINT UNSIGNED NULL,
  tipo                    ENUM('vacinacao','vermifugacao','medicacao',
                               'exame','reproducao','manejo','outro')
                          NOT NULL DEFAULT 'manejo',
  data                    DATE NOT NULL,
  produto_id              BIGINT UNSIGNED NULL,      -- insumo/medicamento (estoque agro)
  quantidade_produto      DECIMAL(18,6) NULL,        -- dose consumida
  estoque_movimentacao_id BIGINT UNSIGNED NULL,      -- saída de estoque gerada
  cabecas_aplicadas       INT UNSIGNED NULL,
  custo                   DECIMAL(18,2) NULL,        -- produto + serviço (R$)
  responsavel             VARCHAR(120) NULL,
  observacao              VARCHAR(255) NULL,
  created_by              BIGINT UNSIGNED NULL,
  updated_by              BIGINT UNSIGNED NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pec_san_tenant (tenant_id),
  KEY idx_pec_san_lote (lote_id, data),
  KEY idx_pec_san_animal (animal_id, data),
  KEY idx_pec_san_tipo (tenant_id, tipo),
  KEY idx_pec_san_produto (produto_id),
  KEY idx_pec_san_created_by (created_by),
  KEY idx_pec_san_updated_by (updated_by),
  CONSTRAINT fk_pec_san_lote
    FOREIGN KEY (lote_id) REFERENCES pecuaria_lotes(id),
  CONSTRAINT fk_pec_san_animal
    FOREIGN KEY (animal_id) REFERENCES pecuaria_animais(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- [9] FKs CROSS-MÓDULO (DEFERRED)
-- As tabelas-alvo vivem no esqueleto agro (101–109) e no core.
-- Aplicadas via ALTER para resolver ordem de criação — mesmo
-- padrão do fornecedor em estoque_lotes. Ajuste os NOMES das
-- tabelas conforme o seu schema antes de rodar.
-- ============================================================
-- ALTER TABLE pecuaria_lotes
--   ADD CONSTRAINT fk_pec_lote_talhao FOREIGN KEY (talhao_id) REFERENCES agro_talhoes(id),
--   ADD CONSTRAINT fk_pec_lote_safra  FOREIGN KEY (safra_id)  REFERENCES agro_safras(id);
-- ALTER TABLE pecuaria_movimentacoes
--   ADD CONSTRAINT fk_pec_mov_fornecedor FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id),
--   ADD CONSTRAINT fk_pec_mov_cliente    FOREIGN KEY (cliente_id)    REFERENCES clientes(id);
-- ALTER TABLE pecuaria_eventos_sanitarios
--   ADD CONSTRAINT fk_pec_san_produto FOREIGN KEY (produto_id) REFERENCES estoque_produtos(id),
--   ADD CONSTRAINT fk_pec_san_estoque_mov FOREIGN KEY (estoque_movimentacao_id) REFERENCES estoque_movimentacoes(id);

-- ============================================================
-- [10] SEED — PERMISSÕES  (tabela global, sem tenant_id)
-- Schema: permissions(slug, label, modulo). Idempotente por slug.
-- ============================================================
INSERT INTO permissions (slug, label, modulo) VALUES
  ('pecuaria.ver',                 'Visualizar pecuária',                'pecuaria'),
  ('pecuaria.especie.gerenciar',   'Gerenciar espécies/categorias',      'pecuaria'),
  ('pecuaria.lote.ver',            'Ver lotes',                          'pecuaria'),
  ('pecuaria.lote.criar',          'Criar lote',                         'pecuaria'),
  ('pecuaria.lote.editar',         'Editar lote',                        'pecuaria'),
  ('pecuaria.lote.encerrar',       'Encerrar lote',                      'pecuaria'),
  ('pecuaria.animal.ver',          'Ver animais (individual)',           'pecuaria'),
  ('pecuaria.animal.gerenciar',    'Gerenciar animais (individual)',     'pecuaria'),
  ('pecuaria.movimentacao.ver',    'Ver movimentações',                  'pecuaria'),
  ('pecuaria.movimentacao.lancar', 'Lançar entrada/saída/transferência', 'pecuaria'),
  ('pecuaria.compra',              'Comprar animais (gera financeiro)',  'pecuaria'),
  ('pecuaria.venda',               'Vender animais (gera financeiro)',   'pecuaria'),
  ('pecuaria.recategorizacao',     'Recategorizar animais',              'pecuaria'),
  ('pecuaria.pesagem.ver',         'Ver pesagens',                       'pecuaria'),
  ('pecuaria.pesagem.lancar',      'Lançar pesagem',                     'pecuaria'),
  ('pecuaria.sanidade.ver',        'Ver eventos sanitários',             'pecuaria'),
  ('pecuaria.sanidade.lancar',     'Lançar evento sanitário',            'pecuaria'),
  ('pecuaria.custo.ver',           'Ver custos/rentabilidade do rebanho','pecuaria'),
  ('pecuaria.patrimonio.ver',      'Ver ativo biológico no patrimônio',  'pecuaria')
ON DUPLICATE KEY UPDATE label = VALUES(label), modulo = VALUES(modulo);

-- ============================================================
-- [11] SEED — PLANO DE CONTAS (ramo Pecuária)
-- plano_contas hierárquico; tipo ENUM('receita','despesa').
-- AJUSTE os nomes de coluna (codigo/nome/tipo/parent_id/tenant_id)
-- e o tenant_id conforme o seed do MVP. Exemplo p/ Fazenda Boa Vista.
-- ============================================================
-- Pai (despesa) — Pecuária
-- INSERT INTO plano_contas (tenant_id, codigo, nome, tipo, parent_id) VALUES
--   (:tenant, '4.3',     'Pecuária',                 'despesa', NULL),
--   (:tenant, '4.3.01',  'Aquisição de animais',     'despesa', @pai_pec),
--   (:tenant, '4.3.02',  'Sanidade animal',          'despesa', @pai_pec),
--   (:tenant, '4.3.03',  'Alimentação/suplementação','despesa', @pai_pec),
--   (:tenant, '4.3.04',  'Mortalidade/perdas',       'despesa', @pai_pec),
--   (:tenant, '4.3.05',  'Manejo/mão de obra',       'despesa', @pai_pec);
-- Receita — Venda de animais
-- INSERT INTO plano_contas (tenant_id, codigo, nome, tipo, parent_id) VALUES
--   (:tenant, '3.2',     'Venda de animais',         'receita', NULL);

-- ============================================================
-- FIM — migration_110_pecuaria.sql
-- Próximo: services (PecuariaCompraService, VendaService, SanidadeService,
-- MovimentacaoService, PesagemService, RecategorizacaoService) +
-- RebanhoQueryService (saldo, peso médio, custo/@, ativo biológico).
-- ============================================================
