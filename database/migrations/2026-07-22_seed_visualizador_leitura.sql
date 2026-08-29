-- VERO — auditoria A5: Visualizador vira perfil SOMENTE LEITURA funcional
-- Semeia as permissões '.ver' dos módulos no perfil 'visualizador' (0 perms antes),
-- EXCETO um conjunto sensível (folha/salário, custo de mão de obra, DRE, fluxo de
-- caixa, resultado de safra, custo de irrigação, e telas admin de usuários/perfis)
-- — mesma classificação de sensibilidade da migration 181.
-- Aplica a TODOS os tenants com role slug='visualizador'. Idempotente (NOT EXISTS).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p
 WHERE r.slug = 'visualizador'
   AND p.slug LIKE '%.ver'
   AND p.slug NOT IN (
       'pessoas.folha.ver',
       'pessoas.custo_mao_obra.ver',
       'financeiro.dre_agro.ver',
       'financeiro.fluxo_caixa.ver',
       'custeio.resultado_safra.ver',
       'custos.resultado_safra.ver',
       'irrigacao.custo_irrigacao.ver',
       'configuracoes.usuarios.ver',
       'configuracoes.perfis_acesso.ver'
   )
   AND NOT EXISTS (
       SELECT 1 FROM role_permissions rp
        WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );
