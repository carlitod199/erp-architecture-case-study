-- ============================================================
-- VERO — 2026-07-17_fenologia_normaliza_rascunho.sql
-- Fenologia por variedade sem versão/rascunho (gestor 17/07): promove qualquer
-- fenologia 'rascunho' ativa para 'aprovada' (o modelo novo trata tudo como vigente).
-- Idempotente (re-rodar afeta 0 linhas). NO DROP.
-- ============================================================
UPDATE agro_variedade_fenologia
   SET status = 'aprovada', aprovado_em = COALESCE(aprovado_em, NOW())
 WHERE status = 'rascunho' AND ativo = 1;
