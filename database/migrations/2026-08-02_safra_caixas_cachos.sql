-- ============================================================
-- VERO — 2026-08-02_safra_caixas_cachos.sql  (V-06 / DECISÃO D-2, reunião 29/07)
-- Move os parâmetros de colheita/raleio da VARIEDADE para a SAFRA (com fallback
-- na variedade/cultura). O "safra" aqui é o vínculo Safra × Válvula
-- (agro_safra_talhoes): é onde vive cultura_id (condiciona os campos de uva) e
-- onde o mercado muda por válvula/safra (interno = mais cachos × exportação =
-- menos cachos). A previsão de colheita (colheita_registros) também é por
-- safra_talhao_id, então o "previsto" (V-05) fica coerente com este vínculo.
--
--   caixas_por_planta  — nº de caixas por planta na safra (colheita/embalamento)
--   cachos_por_planta  — nº de cachos por planta na safra (raleio);
--                        fallback: agro_variedades.cachos_por_planta
--   peso_caixa_kg      — peso da caixa (kg) na safra;
--                        fallback: agro_culturas.peso_unidade_kg
--
-- Todos NULL = "não definido nesta safra" → consumir o fallback da variedade/
-- cultura. Migração da pasta OFICIAL (migrar.sh roda UMA vez, rastreada por
-- checksum) → ALTER puro, sem IF NOT EXISTS.
-- ============================================================
ALTER TABLE agro_safra_talhoes
  ADD COLUMN caixas_por_planta DECIMAL(10,2) NULL AFTER unidade_produtividade,
  ADD COLUMN cachos_por_planta DECIMAL(10,2) NULL AFTER caixas_por_planta,
  ADD COLUMN peso_caixa_kg     DECIMAL(10,3) NULL AFTER cachos_por_planta;
