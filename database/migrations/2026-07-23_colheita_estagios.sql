-- VERO — Colheita em ESTÁGIOS (gestor 23/07), espelhando o apontamento.
-- app LANÇA (status='pendente', origem='app') → escritório FINALIZA no web
-- (status='finalizada'). Registros existentes e criados no web ficam finalizada/web.
-- Roda 1x via checksum (schema_migrations); colunas novas.
ALTER TABLE colheita_registros
  ADD COLUMN origem ENUM('web','app')          NOT NULL DEFAULT 'web'        AFTER observacao,
  ADD COLUMN status ENUM('pendente','finalizada') NOT NULL DEFAULT 'finalizada' AFTER origem;
