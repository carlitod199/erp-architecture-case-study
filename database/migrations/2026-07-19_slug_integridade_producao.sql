-- ============================================================
-- VERO — 2026-07-19_slug_integridade_producao.sql
-- Slug próprio do painel de integridade (F-05/F-06, decisão A0 19/07):
-- catálogo `permissions` + grant .ver espelhado de quem tem
-- relatorios.relatorios_safra.ver (ninguém perde acesso).
-- Idempotente (INSERT IGNORE / NOT EXISTS). Só dados, sem DDL.
-- ============================================================
INSERT IGNORE INTO permissions (slug, label, modulo) VALUES
  ('relatorios.integridade_producao.ver',     'Integridade Produção→Estoque — ver',     'Relatórios'),
  ('relatorios.integridade_producao.editar',  'Integridade Produção→Estoque — editar',  'Relatórios'),
  ('relatorios.integridade_producao.excluir', 'Integridade Produção→Estoque — excluir', 'Relatórios');

INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id,
       (SELECT id FROM permissions WHERE slug = 'relatorios.integridade_producao.ver')
  FROM role_permissions rp
  JOIN permissions pb ON pb.id = rp.permission_id
 WHERE pb.slug = 'relatorios.relatorios_safra.ver'
   AND NOT EXISTS (
         SELECT 1 FROM role_permissions rx
          WHERE rx.role_id = rp.role_id
            AND rx.permission_id = (SELECT id FROM permissions
                                     WHERE slug = 'relatorios.integridade_producao.ver'));
