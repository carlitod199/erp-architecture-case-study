-- VERO — Planejamento de irrigação: horas + vazão (reunião 23/07, X-08).
-- Trava por m³ do Vale = vazão (m³/h) × horas. Vazão vem da bomba da válvula
-- (agro_bombas.vazao_m3h), editável, gravada no planejamento para histórico.
-- Roda 1x via checksum (schema_migrations); idempotência garantida pelo par
-- migrations/migration_185_irrigacao_horas_vazao.php (guarda por information_schema).
ALTER TABLE irrigacao_planejamentos
  ADD COLUMN horas_irrigacao DECIMAL(10,2) NULL AFTER lamina_mm,
  ADD COLUMN vazao_m3h       DECIMAL(10,2) NULL AFTER horas_irrigacao;
