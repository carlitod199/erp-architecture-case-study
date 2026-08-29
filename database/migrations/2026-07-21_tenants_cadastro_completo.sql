-- VERO #48 — colunas de cadastro completo da empresa em `tenants`.
-- A tela configuracoes/empresa_fazenda.php grava schema-aware; estas colunas
-- fazem os campos fiscais/endereco/sede persistirem. Aditivo, idempotente.
-- (Aplique via o runner de migrations por checksum; ADD COLUMN roda uma vez.)
ALTER TABLE tenants
  ADD COLUMN razao_social       VARCHAR(200) NULL,
  ADD COLUMN cnpj               VARCHAR(18)  NULL COMMENT 'so digitos (14)',
  ADD COLUMN inscricao_estadual VARCHAR(20)  NULL,
  ADD COLUMN endereco           VARCHAR(240) NULL,
  ADD COLUMN municipio          VARCHAR(120) NULL,
  ADD COLUMN uf                 CHAR(2)      NULL,
  ADD COLUMN cep                VARCHAR(9)   NULL,
  ADD COLUMN fazenda_sede_id    BIGINT UNSIGNED NULL COMMENT 'fazenda-sede do grupo';
