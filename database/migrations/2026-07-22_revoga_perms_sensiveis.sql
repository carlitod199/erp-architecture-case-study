-- VERO #49 — revoga exposicoes sensiveis (decisao 22/07). Idempotente.
-- rt_gerente nao ve folha/custo de MO; operador/encarregado nao veem custo de irrigacao.
DELETE rp FROM role_permissions rp
  JOIN roles r ON r.id = rp.role_id
  JOIN permissions p ON p.id = rp.permission_id
 WHERE (r.slug = 'rt_gerente'  AND p.slug IN ('pessoas.folha.ver','pessoas.folha.editar','pessoas.folha.excluir',
                                              'pessoas.custo_mao_obra.ver','pessoas.custo_mao_obra.editar','pessoas.custo_mao_obra.excluir'))
    OR (r.slug IN ('operador','encarregado') AND p.slug IN ('irrigacao.custo_irrigacao.ver','irrigacao.custo_irrigacao.editar','irrigacao.custo_irrigacao.excluir'));
