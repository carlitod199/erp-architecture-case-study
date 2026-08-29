-- ============================================================================
-- check_prerequisites_120.sql
-- Rode ISTO no banco VERO ANTES de aplicar migration_120_viticultura_backbone.sql
-- Objetivo: confirmar que as tabelas-base existem com os nomes esperados.
-- Nao altera nada. Apenas lista presenca/ausencia.
-- ============================================================================

SELECT
    t.expected_table                                   AS tabela_esperada,
    CASE WHEN ist.table_name IS NULL THEN 'FALTANDO'
         ELSE 'OK' END                                 AS situacao
FROM (
    SELECT 'agro_fazendas'        AS expected_table UNION ALL
    SELECT 'agro_talhoes'         UNION ALL
    SELECT 'agro_culturas'        UNION ALL
    SELECT 'agro_safras'          UNION ALL
    SELECT 'agro_safra_talhoes'   UNION ALL
    SELECT 'estoque_produtos'     UNION ALL
    SELECT 'estoque_lotes'        UNION ALL
    SELECT 'estoque_movimentacoes'UNION ALL
    SELECT 'estoque_saldos'       UNION ALL
    SELECT 'custeio_lancamentos'  UNION ALL
    SELECT 'centros_custo'        UNION ALL
    SELECT 'plano_contas'         UNION ALL
    SELECT 'usuarios'             UNION ALL
    SELECT 'permissions'          UNION ALL
    SELECT 'roles'                UNION ALL
    SELECT 'role_permissions'
) AS t
LEFT JOIN information_schema.tables ist
       ON ist.table_schema = DATABASE()
      AND ist.table_name   = t.expected_table
ORDER BY situacao DESC, tabela_esperada;

-- Conferir tambem as colunas-chave que a migration referencia (PK e tenant_id).
-- A migration_120 assume PK = `id` BIGINT UNSIGNED e coluna `tenant_id` nas tabelas agro_*.
SELECT table_name, column_name, column_type, column_key
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('agro_fazendas','agro_talhoes','agro_culturas','agro_safras','agro_variedades')
  AND column_name IN ('id','tenant_id')
ORDER BY table_name, column_name;

-- Conferir o layout real da tabela de permissoes (a migration assume colunas: name, description).
-- Se diferente, ajuste o BLOCO RBAC da migration antes de aplicar.
SHOW COLUMNS FROM permissions;
