-- 23/07/2026 — Assinatura de aplicação em DOIS papéis (desenho do gestor):
-- OPERADOR (executou, obrigatório) × RT (receituário agronômico, pode assinar
-- depois no web). Coluna aditiva; default 'operador' preserva as existentes.
-- Aplicar no deploy do servidor01 (scripts/aplicar_assinatura_papel.php).

ALTER TABLE agro_aplicacao_assinaturas
  ADD COLUMN papel VARCHAR(20) NOT NULL DEFAULT 'operador'
  AFTER operador_nome;
