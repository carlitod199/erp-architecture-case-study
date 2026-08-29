-- ============================================================================
-- rollback_130_133.sql  |  VERO
-- Reverte as migrations 130–133 na ORDEM INVERSA (133 → 130).
-- Uso: apenas em homolog/implantação. Se já houver dados de produção nas
-- tabelas novas, prefira restaurar o backup pre_13x.sql em vez deste script.
-- Idempotente: usa IF EXISTS e checagem de coluna.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- 133: análises ----------
DROP TABLE IF EXISTS analise_faixas;
DROP TABLE IF EXISTS analise_foliar_resultados;
DROP TABLE IF EXISTS analise_foliar;
DROP TABLE IF EXISTS analise_solo_resultados;
DROP TABLE IF EXISTS analise_solo;
DROP TABLE IF EXISTS analise_nutrientes;

-- ---------- 132: comercial ----------
DROP TABLE IF EXISTS comercial_venda_qualidades;

SET @s := (SELECT IF(COUNT(*)>0,
  'ALTER TABLE comercial_vendas
     DROP FOREIGN KEY fk_venda_comprador,
     DROP FOREIGN KEY fk_venda_setor,
     DROP FOREIGN KEY fk_venda_colheita,
     DROP COLUMN comprador_id,
     DROP COLUMN talhao_id,
     DROP COLUMN setor_id,
     DROP COLUMN colheita_registro_id,
     DROP COLUMN kg_total,
     DROP COLUMN frutos_perdidos_pct,
     DROP COLUMN data_vencimento,
     DROP COLUMN status_pagamento,
     DROP COLUMN data_pagamento,
     DROP COLUMN movimentacao_id,
     DROP COLUMN observacao',
  'SELECT ''comercial_vendas ja revertido''')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'comercial_vendas' AND column_name = 'comprador_id');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS comercial_compradores;

-- ---------- 131: setores / colheita ----------
DROP TABLE IF EXISTS colheita_classificacoes;

SET @s := (SELECT IF(COUNT(*)>0,
  'ALTER TABLE colheita_registros
     DROP FOREIGN KEY fk_colhreg_setor,
     DROP COLUMN setor_id,
     DROP COLUMN variedade_id,
     DROP COLUMN producao_prevista_kg_ha,
     DROP COLUMN producao_realizada_kg_ha,
     DROP COLUMN kg_total_previsto,
     DROP COLUMN kg_total_realizado,
     DROP COLUMN faturamento_previsto,
     DROP COLUMN faturamento_realizado,
     DROP COLUMN observacao',
  'SELECT ''colheita_registros ja revertido''')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'colheita_registros' AND column_name = 'setor_id');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)>0,
  'ALTER TABLE agro_setores
     DROP FOREIGN KEY fk_setores_fazenda,
     DROP COLUMN fazenda_id,
     DROP COLUMN codigo,
     DROP COLUMN tipo,
     DROP COLUMN area_ha,
     DROP COLUMN ativo',
  'SELECT ''agro_setores ja revertido''')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'agro_setores' AND column_name = 'codigo');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- Obs.: talhao_id volta a NULL permitido? Reversão para NOT NULL só é segura
-- se não houver linhas com talhao_id NULL; por isso NÃO é revertida aqui.

-- ---------- 130: atividades / RH ----------
DROP TABLE IF EXISTS rh_folha_lancamentos;
DROP TABLE IF EXISTS rh_folha_periodos;
DROP TABLE IF EXISTS rh_encargos_config;
DROP TABLE IF EXISTS rh_producao_itens;
DROP TABLE IF EXISTS rh_regras_premiacao;
DROP TABLE IF EXISTS rh_terceirizados;

SET @s := (SELECT IF(COUNT(*)>0,
  'ALTER TABLE agro_operadores
     DROP COLUMN tipo_vinculo,
     DROP COLUMN salario_mensal,
     DROP COLUMN documento',
  'SELECT ''agro_operadores ja revertido''')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'agro_operadores' AND column_name = 'tipo_vinculo');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*)>0,
  'ALTER TABLE agro_apontamentos
     DROP COLUMN tipo_atividade_id,
     DROP COLUMN fenologia_id,
     DROP COLUMN hectares',
  'SELECT ''agro_apontamentos ja revertido''')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'agro_apontamentos' AND column_name = 'tipo_atividade_id');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS agro_tipo_atividade_culturas;
DROP TABLE IF EXISTS agro_tipos_atividade;

SET FOREIGN_KEY_CHECKS = 1;
-- Fim do rollback 130–133.
