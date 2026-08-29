-- ============================================================
-- VERO — scripts/limpeza_dados_teste.sql
-- Remove os DADOS DE TESTE do tenant 1 criados durante o
-- desenvolvimento (Vinícola do Vale, José da Silva, V2026-0001…)
-- antes da carga real do cliente.
--
-- >>> NÃO RODAR SEM BACKUP: mysqldump --single-transaction antes. <<<
-- >>> Revisar o tenant alvo (@tenant) e cada bloco antes de rodar. <<<
--
-- PRESERVA: usuarios, roles/permissions/role_permissions, planos,
--   seeds de analise_nutrientes, rh_encargos_config e
--   agro_tipos_atividade (catálogo padrão), estrutura completa.
-- REMOVE: todo o transacional e os cadastros de teste do tenant.
-- ============================================================

SET @tenant := 1;

START TRANSACTION;

-- 1) Alertas e IA
DELETE FROM agro_alertas        WHERE tenant_id = @tenant;
DELETE FROM agro_ia_extracoes   WHERE tenant_id = @tenant;

-- 2) Análises de nutrição (resultados → cabeçalhos; faixas de teste)
DELETE FROM analise_solo_resultados   WHERE tenant_id = @tenant;
DELETE FROM analise_foliar_resultados WHERE tenant_id = @tenant;
DELETE FROM analise_solo   WHERE tenant_id = @tenant;
DELETE FROM analise_foliar WHERE tenant_id = @tenant;
DELETE FROM analise_faixas WHERE tenant_id = @tenant;  -- faixa de teste do P; as reais vêm do RT

-- 3) MIP
DELETE FROM mip_recomendacoes  WHERE tenant_id = @tenant;
DELETE FROM mip_monitoramentos WHERE tenant_id = @tenant;
DELETE FROM mip_alvos          WHERE tenant_id = @tenant;

-- 4) Comercial e financeiro (anexos → qualidades → vendas → razão)
DELETE FROM agro_anexos WHERE tenant_id = @tenant
  AND origem_tipo IN ('comercial_venda', 'laudo_nutricional');
DELETE FROM comercial_venda_qualidades WHERE tenant_id = @tenant;
DELETE FROM comercial_vendas           WHERE tenant_id = @tenant;
DELETE FROM comercial_compradores      WHERE tenant_id = @tenant;
-- razão financeiro de teste (a cadeia de hash recomeça limpa)
DELETE FROM movimentacoes_financeiras  WHERE tenant_id = @tenant;

-- 5) Folha
DELETE FROM rh_folha_lancamentos WHERE tenant_id = @tenant;
DELETE FROM rh_folha_periodos    WHERE tenant_id = @tenant;

-- 6) Custeio e apontamentos (custeio → itens/insumos → apontamentos)
DELETE FROM custeio_lancamentos       WHERE tenant_id = @tenant;
DELETE FROM rh_producao_itens         WHERE tenant_id = @tenant;
DELETE FROM agro_apontamento_insumos  WHERE tenant_id = @tenant;
DELETE FROM agro_apontamentos         WHERE tenant_id = @tenant;

-- 7) Estoque (movimentações → saldos → produtos; almox/grupo default ficam
--    e são recriados sob demanda se removidos)
DELETE FROM estoque_movimentacoes WHERE tenant_id = @tenant;
DELETE FROM estoque_saldos        WHERE tenant_id = @tenant;
DELETE FROM estoque_produtos      WHERE tenant_id = @tenant;

-- 8) Pessoas de teste (regras → terceirizados/colaboradores)
DELETE FROM rh_regras_premiacao WHERE tenant_id = @tenant;
DELETE FROM rh_terceirizados    WHERE tenant_id = @tenant;
DELETE FROM agro_operadores     WHERE tenant_id = @tenant;

-- 9) Colheita
DELETE FROM colheita_classificacoes WHERE tenant_id = @tenant;
DELETE FROM colheita_registros      WHERE tenant_id = @tenant;

-- 10) Estrutura agrícola de teste (vínculos → safras → válvulas →
--     variedades/fenologia → talhões → culturas → fazendas)
DELETE FROM agro_safra_talhoes      WHERE tenant_id = @tenant;
DELETE FROM agro_safras             WHERE tenant_id = @tenant;
DELETE FROM agro_setores            WHERE tenant_id = @tenant;
DELETE FROM agro_variedades         WHERE tenant_id = @tenant;
DELETE FROM agro_fenologia_estagios WHERE tenant_id = @tenant;
DELETE FROM agro_tipo_atividade_culturas WHERE tenant_id = @tenant; -- vínculos de teste (catálogo fica)
DELETE FROM agro_talhoes            WHERE tenant_id = @tenant;
DELETE FROM agro_culturas           WHERE tenant_id = @tenant;
DELETE FROM agro_fazendas           WHERE tenant_id = @tenant;

COMMIT;

-- Conferência rápida (deve retornar 0 em tudo):
SELECT
  (SELECT COUNT(*) FROM agro_fazendas        WHERE tenant_id = @tenant) AS fazendas,
  (SELECT COUNT(*) FROM comercial_vendas     WHERE tenant_id = @tenant) AS vendas,
  (SELECT COUNT(*) FROM custeio_lancamentos  WHERE tenant_id = @tenant) AS custeio,
  (SELECT COUNT(*) FROM movimentacoes_financeiras WHERE tenant_id = @tenant) AS razao,
  (SELECT COUNT(*) FROM agro_alertas         WHERE tenant_id = @tenant) AS alertas;

-- Lembrete pós-limpeza: apagar também os arquivos de teste em
--   storage/uploads/vendas/1/  e  storage/uploads/laudos/1/
