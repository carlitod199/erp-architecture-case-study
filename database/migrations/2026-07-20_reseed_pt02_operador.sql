-- ============================================================
-- VERO — 2026-07-20_reseed_pt02_operador.sql
-- REGRESSÃO detectada na auditoria do app de campo (20/07): o reseed dos
-- perfis C-25 (18/07) removeu da role `operador` os 3 slugs de AÇÃO que o
-- PT-02 (CSO, 08/07) tinha concedido — desde então o operador com role real
-- não consegue, pelo app: reconhecer alerta, confirmar/assinar DF e
-- registrar horímetro/abastecimento (403 sem_permissao).
-- Reinsere os grants. Idempotente (NOT EXISTS), aditivo, NO DROP.
-- ============================================================
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p
 WHERE r.slug = 'operador' AND r.ativo = 1
   AND p.slug IN ('mip.alertas_fitossanitarios.editar',
                  'mip.aplicacoes_defensivos.editar',
                  'maquinas.horimetro.editar')
   AND NOT EXISTS (SELECT 1 FROM role_permissions rp
                    WHERE rp.role_id = r.id AND rp.permission_id = p.id);
