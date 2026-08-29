-- VERO — MIP parametrizável (reunião 23/07, X-02/Y-03).
-- Metodologia de amostragem vira PARÂMETRO (planta/folha/caixa/severidade) com
-- multiplicador (unidades por planta): espaço amostral = unidades × plantas;
-- índice = encontradas ÷ espaço × 100. Legados >100% são arquivados (Y-03).
-- Roda 1x via checksum (schema_migrations). Idempotência das colunas garantida
-- pelo par migrations/migration_184_mip_metodologia.php (guarda por information_schema).
ALTER TABLE agro_fazendas
  ADD COLUMN mip_metodologia         VARCHAR(16) NOT NULL DEFAULT 'planta' AFTER nome,
  ADD COLUMN mip_unidades_por_planta INT NULL                              AFTER mip_metodologia;

ALTER TABLE mip_monitoramentos
  ADD COLUMN metodologia         VARCHAR(16) NOT NULL DEFAULT 'planta' AFTER plantas_amostradas,
  ADD COLUMN unidades_por_planta INT NULL                              AFTER metodologia,
  ADD COLUMN arquivado           TINYINT(1)  NOT NULL DEFAULT 0        AFTER unidades_por_planta;

-- Y-03: arquiva os legados anômalos (>100%) herdados das metodologias antigas.
UPDATE mip_monitoramentos SET arquivado = 1 WHERE nivel_infestacao > 100;
