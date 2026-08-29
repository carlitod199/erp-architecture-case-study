-- ============================================================
-- VERO Agro — migration 120: Core Financeiro
-- Razão financeiro único e auditável (hash-chain), contas
-- bancárias e conciliação. NÃO cria motor paralelo: é o destino
-- de custeio_lancamentos.movimentacao_financeira_id.
-- AP/AR e fluxo de caixa derivam de movimentacoes_financeiras.
-- Idempotente.
-- ============================================================

CREATE TABLE IF NOT EXISTS contas_bancarias (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  nome        VARCHAR(120) NOT NULL,
  banco       VARCHAR(80)  NULL,
  agencia     VARCHAR(20)  NULL,
  conta       VARCHAR(30)  NULL,
  tipo        ENUM('corrente','poupanca','caixa','aplicacao') NOT NULL DEFAULT 'corrente',
  saldo_inicial DECIMAL(18,2) NOT NULL DEFAULT 0,
  ativo       TINYINT(1) NOT NULL DEFAULT 1,
  created_by  BIGINT UNSIGNED NULL,
  updated_by  BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cb_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Razão financeiro (AP/AR/movimentações) com cadeia de integridade
CREATE TABLE IF NOT EXISTS movimentacoes_financeiras (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        BIGINT UNSIGNED NOT NULL,
  tipo             ENUM('pagar','receber','transferencia') NOT NULL,
  status           ENUM('previsto','aberto','pago','baixado','cancelado') NOT NULL DEFAULT 'aberto',
  plano_conta_id   BIGINT UNSIGNED NULL,
  centro_custo_id  BIGINT UNSIGNED NULL,
  conta_bancaria_id BIGINT UNSIGNED NULL,
  fornecedor_id    BIGINT UNSIGNED NULL,
  safra_id         BIGINT UNSIGNED NULL,
  talhao_id        BIGINT UNSIGNED NULL,
  descricao        VARCHAR(255) NULL,
  valor            DECIMAL(18,2) NOT NULL,
  data_competencia DATE NULL,
  data_vencimento  DATE NULL,
  data_pagamento   DATE NULL,
  -- idempotência: nunca duplica financeiro a partir da mesma origem
  origem_tipo      VARCHAR(40) NULL,
  origem_id        BIGINT UNSIGNED NULL,
  -- cadeia de hash (razão auditável)
  hash_anterior    CHAR(64) NULL,
  hash_atual       CHAR(64) NULL,
  created_by       BIGINT UNSIGNED NULL,
  updated_by       BIGINT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mf_tenant (tenant_id),
  KEY idx_mf_tipo_status (tipo, status),
  KEY idx_mf_venc (data_vencimento),
  KEY idx_mf_plano (plano_conta_id),
  KEY idx_mf_cc (centro_custo_id),
  UNIQUE KEY uq_mf_origem (tenant_id, origem_tipo, origem_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conciliacao_bancaria (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        BIGINT UNSIGNED NOT NULL,
  conta_bancaria_id BIGINT UNSIGNED NOT NULL,
  referencia       VARCHAR(20) NULL,
  data_inicio      DATE NULL,
  data_fim         DATE NULL,
  status           ENUM('aberta','conciliada','divergente') NOT NULL DEFAULT 'aberta',
  saldo_extrato    DECIMAL(18,2) NULL,
  saldo_sistema    DECIMAL(18,2) NULL,
  created_by       BIGINT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_conc_tenant (tenant_id),
  KEY idx_conc_conta (conta_bancaria_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conciliacao_itens (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        BIGINT UNSIGNED NOT NULL,
  conciliacao_id   BIGINT UNSIGNED NOT NULL,
  movimentacao_id  BIGINT UNSIGNED NULL,
  data_extrato     DATE NULL,
  descricao_extrato VARCHAR(255) NULL,
  valor_extrato    DECIMAL(18,2) NULL,
  conciliado       TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_ci_conc (conciliacao_id),
  KEY idx_ci_mov (movimentacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
