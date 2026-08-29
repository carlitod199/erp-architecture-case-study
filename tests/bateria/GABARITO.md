# VERO — Bateria de Testes: GABARITO (valores calculados à mão)

> Escrito ANTES do código de teste (regra 4 da especificação A5-QA). Cada assert da
> bateria compara com os valores DESTA tabela — nunca com "o que o sistema
> devolveu na primeira execução". Datas canônicas: competência **2026-07**.
> Tenant de teste: **`QA BATERIA — NÃO USAR`** (+ tenant auxiliar
> `QA BATERIA 2 — NÃO USAR` para o teste cross-tenant). Nada fora deles.

## Convenções da massa

| Item | Valor canônico |
| --- | --- |
| Fazenda | QA Fazenda Bateria |
| Válvulas (modo unificado, `agro.valvula_igual_talhao='1'`) | **QA-1A**: 4,00 ha, 1.000 plantas · **QA-2B**: 2,50 ha |
| Cultura | QA Uva (exige_classificacao=1, produto colheita QA-UVA, almox padrão) |
| Variedade | QA Vitória; fenologia por variedade aprovada, dia0=poda: Brotação 0–45 (calda 300 L/ha), Floração 46–90 (500), Maturação 91–200 (400) |
| Safra | QA 2026/2, ativa, `data_inicio` = poda = **2026-06-01**; vínculos às 2 válvulas (área plantada 4,00 / 2,50) com `data_poda=2026-06-01`, poda finalizada |
| Pessoas | CLT1 "QA Colaborador CLT" salário **1.664,00**, 0 dep. · CLT2 "QA Colaborador Teto" salário **9.000,00**, 0 dep. · Terceirizado produção "QA Produção" · Diarista "QA Diarista" (diária 90,00) |
| Atividade | "QA Poda" (trato_cultural, unidade caixa) × cultura QA Uva; regra de premiação vigência aberta (meta/valor por linha — rework 5.1) |
| Produtos | QA-FERT (990001, kg, controla_validade=1) · QA-DEF (990002, L, defensivo) · QA-UVA (990003, kg, produto da colheita) |
| Usuários | qa.super (super_admin) + qa.gestor / qa.operador / qa.financeiro / qa.consulta (roles do tenant QA, matriz = `scripts/seed_perfis_padrao.php`) |
| Parâmetros | `agro.valvula_igual_talhao='1'` · `compras.alcada_valor='1000'` |

---

## F2 — Estoque, Compras, FEFO, custo médio ponderado

| Entrada | Cálculo à mão | Esperado | Onde conferir |
| --- | --- | --- | --- |
| Entrada inicial QA-FERT 90 kg × R$ 3,50, validade 2026-12-31 (data 2026-07-05) | 90 × 3,50 | saldo 90 kg; valor 315,00; CM 3,500000; lote L…-001 val. 2026-12-31 | `estoque_saldos`, `estoque_lotes`; tela estoque/produtos |
| Pedido direto 200 kg × R$ 3,80 = 760,00 ≤ alçada 1.000 | — | pedido auto-**aprovado** (`compras_aprovacoes` nível 1 aprovado) | `compras_pedidos.status`; tela compras/pedidos |
| Recebimento 200 kg × R$ 3,80, validade 2026-10-31 (data 2026-07-08) | 315 + 760 = 1.075,00; 1.075 ÷ 290 | saldo **290 kg**; valor 1.075,00; **CM 3,706897** (≈3,7069); 2º lote val. 2026-10-31; pedido `recebido` | `estoque_saldos`; `estoque_lotes`; `compras_pedidos` |
| Conta a pagar do recebimento | 200 × 3,80 | **760,00**, tipo pagar, aberto, origem `compras_recebimento`, única (idempotente) | `movimentacoes_financeiras`; financeiro/contas_pagar |
| Pedido 2: 500 kg × 3,80 = 1.900,00 > alçada | — | status `aprovacao` → aprovar → `aprovado` → cancelar → `cancelado` (sem estoque/financeiro) | `compras_pedidos`, `compras_aprovacoes` |
| **FEFO** — saída 10 kg em 2026-07-10 (apontamento F3) | validade mais próxima = 2026-10-31 | consumo integral no lote 2026-10-31 (190 kg restam); lote 2026-12-31 intacto (90 kg) | `estoque_movimentacao_lotes`, `estoque_lotes` |
| Custo da saída | round(10 × 3,706897; 2) | **37,07**; saldo 280 kg; valor 1.037,93 | `estoque_movimentacoes`, `estoque_saldos` |

## F3 — Apontamento em dois estágios + premiação + idempotência

| Entrada | Cálculo à mão | Esperado | Onde conferir |
| --- | --- | --- | --- |
| `acao=iniciar` (2026-07-10, QA-1A, QA Poda, responsável CLT1) | — | status `iniciado`, `iniciado_em` preenchido, OS gerada (em execução), sem itens/custeio | `agro_apontamentos`, `agro_ordens_servico` |
| `acao=finalizar` — CLT1: 130 cx, meta 100, R$ 1,20/cx | (130 − 100) × 1,20 | premiação **36,00** (`valor_total`), `qtd_acima_meta=30`, `meta_aplicada=100` (snapshot na linha) | `rh_producao_itens` (modalidade premiacao) |
| Terceirizado produção: 120 plantas × R$ 2,00 | 120 × 2,00 | **240,00** | `rh_producao_itens` (producao) |
| Diarista: 1 diária × R$ 90,00 | 1 × 90,00 | **90,00** | `rh_producao_itens` (diaria) |
| Custeio mão de obra do apontamento | 36 + 240 + 90 | **366,00** (3 linhas origem `rh_producao_item`) | `custeio_lancamentos` categoria mao_de_obra |
| Insumo QA-FERT 10 kg | ver F2 | custeio insumos **37,07** (origem `apontamento_insumo`) | `custeio_lancamentos` |
| **Idempotência**: `acao=salvar` (reedição) com os MESMOS valores | estorno + reemissão | custeio do apontamento continua 366,00 + 37,07 (sem duplicar); saldo estoque continua 280 kg; saída antiga estornada logicamente + nova ativa | `custeio_lancamentos`, `estoque_movimentacoes` |
| Status pós-finalizar | — | apontamento sai de `iniciado` (finalizado_em/por preenchidos); OS concluída | `agro_apontamentos` |

Calculadora de diárias (decisão "sempre ceil"): 1.905 plantas ÷ 1 dia ÷ 100/diária
→ ceil(19,05) = **20 diárias**. (`vero_srv_diarias_necessarias(1905, 1, 100)`.)

## F4 — MIP (monitoramento multialvo → aplicação DF)

| Entrada | Cálculo à mão | Esperado | Onde conferir |
| --- | --- | --- | --- |
| Alvo "QA Traça" nível de ação 5; monitoramento 2026-07-09 QA-2B com qtd 8, local folha + 2º alvo "QA Míldio" qtd 2 | 8 ≥ 5; 2 < 5 | ao `enviar`: **1 alerta** MIP (só do alvo acima do nível), `requer_validacao_tecnica=1`; 2 linhas em `mip_monitoramento_alvos` | `agro_alertas` categoria mip |
| Aplicação DF direta 2026-07-11, QA-2B, pulverização, 2 maquinários, calda 500 L/ha, céu sol, QA-DEF 2 L | estoque QA-DEF: 10 L × 12,00; saída 2 L × 12,00 | doc **DF numerado** (série DF, nº 1 da fazenda); 2 linhas `agro_aplicacao_maquinas`; `condicao_ceu='sol'`; `volume_calda_ha_l=500`; baixa FEFO 2 L; custeio insumos **24,00** origem `aplicacao` | `agro_aplicacoes`, `agro_aplicacao_itens`, `custeio_lancamentos` |
| Fase resolvida em 2026-07-11 | dias desde poda = 40 | fase **Brotação** (0–45) por variedade | `agro_aplicacoes.variedade_fase_id`, `dias_desde_poda=40` |

## F5 — Nutrição (faixa por fase → análise → classificação/alerta)

| Entrada | Cálculo à mão | Esperado | Onde conferir |
| --- | --- | --- | --- |
| Faixa foliar N: mín 2,0 · ideal 2,5–3,5 · máx 4,0 (variedade QA Vitória) | 1,8 < 2,0 | análise foliar 2026-07-09 com N=1,8 → classificação **muito_baixo**; alerta nutrição **crítico**, `requer_validacao_tecnica=1` | `analise_foliar_resultados.classificacao`, `agro_alertas` |
| 2º nutriente K=3,0 (faixa 2,0/2,5–3,5/4,0) | 2,5 ≤ 3,0 ≤ 3,5 | **adequado**, sem alerta | idem |

## F6 — Colheita → Venda → Contas a receber

| Entrada | Cálculo à mão | Esperado | Onde conferir |
| --- | --- | --- | --- |
| Previsto: 25.000 kg/ha × 4,00 ha (QA-1A) | 25.000 × 4 | **100.000 kg** (`kg_total_previsto`) | `colheita_registros` |
| Faturamento previsto: 100% cat1 × R$ 5,20 | 100.000 × 5,20 | **520.000,00** | `colheita_registros.faturamento_previsto` |
| Realizado: 23.937,50 kg/ha × 4,00 ha | 23.937,5 × 4 | **95.750 kg** | `colheita_registros.kg_total_realizado` |
| Classificação realizado: premium 40% × 6,50 · cat1 40% × 5,00 · cat2 20% × 4,15 | 38.300×6,50=248.950,00 · 38.300×5,00=191.500,00 · 19.150×4,15=79.472,50 | faturamento realizado **519.922,50** | `colheita_classificacoes`, `colheita_registros` |
| Confirmar entrada no estoque (2026-07-15) | custeio QA-1A até a data = 37,07+366,00+200,00 = **603,07**; ÷ 95.750 kg | lote **COLH-2026-…** 95.750 kg, custo unit. **0,006298** (provisório P-85); 2ª confirmação → `ja_existia` (idempotente) | `estoque_lotes` |
| Venda 2026-07-16 (lote COLH, comprador QA Comprador), mesmos % e preços | idem colheita | `kg_total=95.750`, `valor_total=`**519.922,50**; saída de estoque do lote ao custo do lote: round(95.750×0,006298;2)=**603,03** (CPV) | `comercial_vendas`, `estoque_movimentacoes` |
| Conta a receber | — | **1 título único** 519.922,50, aberto, origem `comercial_venda` ativa | `movimentacoes_financeiras` |
| Reedição da venda (muda vencimento p/ 2026-08-20 = campo selado) | DB-23 | título antigo `cancelado` (`origem_ativa` NULL, `substituida_por_id`), novo título único ativo; **continua 1 origem ativa** | `movimentacoes_financeiras` |
| Venda sem colheita (negativo) | — | POST sem `lote_id` → flash erro "Venda nova exige o LOTE", nada gravado | `comercial_vendas` |

## F7 — Financeiro (baixa/estorno/hash-chain)

| Entrada | Cálculo à mão | Esperado | Onde conferir |
| --- | --- | --- | --- |
| Baixar a conta do pedido (760,00) em 2026-07-20, forma `pix` | — | status `pago`, `data_pagamento` preenchida | `movimentacoes_financeiras` |
| Baixar o título da venda (519.922,50) em 2026-07-25 | — | `pago`; venda `status_pagamento='pago'` | idem + `comercial_vendas` |
| Fluxo de caixa do mês (realizado) | 519.922,50 − 760,00 | entradas **519.922,50**, saídas **760,00**, líquido **519.162,50** | financeiro/fluxo_caixa (query `status='pago'` por mês) |
| Estorno da baixa (760,00) | A3-T34 | volta a `aberto`, `data_pagamento` NULL e **`forma_pagamento` NULL** | `movimentacoes_financeiras` |
| Estorno da baixa da venda | — | `aberto`; venda `status_pagamento='pendente'`; fluxo realizado do mês volta a **0,00** | idem |
| Hash-chain do tenant QA | recomputar SHA-256 elo a elo | cadeia **íntegra** (elo + conteúdo), 0 divergências; exatamente **1** linha `origem_ativa` por origem | lógica de `financeiro/verificador_razao.php` |
| Cancelamento com origem (negativo) | — | `excluir` de título com `origem_tipo` ≠ NULL → recusado | `_contas_base.php` |

## F8 — Custeio (rateio, sem-safra, resultado da safra)

Custeio canônico do tenant QA após F1–F7 (13 linhas):

| Categoria | Origem | Valor | Safra? |
| --- | --- | --- | --- |
| insumos | apontamento_insumo (QA-1A) | 37,07 | sim |
| mao_de_obra | rh_producao_item ×3 (QA-1A) | 366,00 | sim |
| irrigacao | irrigacao_consumo ×2 (QA-1A: água 80,00 + energia 120,00) | 200,00 | sim |
| insumos | aplicacao (QA-2B) | 24,00 | sim |
| maquinas | maquina_abastecimento (avulso, 50 L × 6,00) | 300,00 | **não** |
| depreciacao | patrimonio_depreciacao | 1.666,67 | **não** |
| mao_de_obra | rh_folha_lancamento (folha fechada) | 16.574,68 | **não** |

| Assert | Cálculo à mão | Esperado |
| --- | --- | --- |
| Custo por talhão (matriz) QA-1A | 37,07+366,00+200,00 | **603,07** (insumos 37,07 · MDO 366,00 · irrigação 200,00) |
| Custo por talhão QA-2B | — | **24,00** |
| Custo/ha QA-1A | 603,07 ÷ 4,00 | **150,77** |
| Resultado da safra | 519.922,50 − (603,07+24,00) | vendas 519.922,50; custo **627,07**; resultado **519.295,43**; margem **99,88%** |
| Atribuição sem-safra (manual) | 300,00+1.666,67+16.574,68 = 18.541,35 sobre a única safra ativa | cotas atribuídas à safra QA + contrapartidas negativas; **desfazer** → soma sem-safra volta a 18.541,35 e cotas somem |
| Trava de fechamento | fechar safra → lançar custeio na safra é bloqueado; reabrir → liberado | `custeio_fechamentos` + `vero_srv_custeio_pode_lancar` |

## F9 — RH / Folha (INSS/IRRF persistidos)

Encargos (tela pessoas/encargos, vigência 2026-01-01, pcts FGTS 8 · INSS patronal 20 ·
RAT 2 · Terceiros 5,8 · Férias 11,11 · 13º 8,33 · Outros 0 = **55,24%**):

| Bruto | FGTS | INSS pat. | RAT | Terceiros | Férias | 13º | Total encargos |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1.664,00 | 133,12 | 332,80 | 33,28 | 96,51 | 184,87 | 138,61 | **919,19** (aceite do legado) |

Folha competência 2026-07 (bruto = salário + premiações do mês):

| Colaborador | Bruto | INSS empregado (progressivo 2026) | IRRF | Líquido | Encargos patronais | Custo total |
| --- | --- | --- | --- | --- | --- | --- |
| CLT1 (1.664,00 + premiação 36,00) | **1.700,00** | 1.518×7,5% + 182×9% = 113,85+16,38 = **130,23** | base 1.569,77 ≤ 2.428,80 → **0,00** | **1.569,77** | 1.700×55,24% = **939,08** | **2.639,08** |
| CLT2 (teto) | **9.000,00** | 113,85+114,83+167,63+555,32 = **951,63** (teto 2026) | base 8.048,37 → ×27,5% − 908,73 = **1.304,57** | **6.743,80** | 9.000×55,24% = **4.971,60** | **13.971,60** |

- INSS teto, conferência por faixa: (1.518−0)×7,5%=113,85 · (2.793,88−1.518)×9%=114,8292 · (4.190,83−2.793,88)×12%=167,634 · (8.157,41−4.190,83)×14%=555,3212 → soma 951,6344 → **951,63**.
- IRRF CLT2: 9.000 − 951,63 = 8.048,37 → 8.048,37×0,275 = 2.213,3018 − 908,73 = 1.304,5718 → **1.304,57**.
- Fechar período → custeio mão de obra = Σ custo_total − Σ premiações = 16.610,68 − 36,00 = **16.574,68**; reabrir remove (idempotente).
- Regerar a folha (2× `gerar`) não duplica lançamentos (delete+reinsere).

## F10 — Patrimônio / Máquinas / Irrigação

| Entrada | Cálculo à mão | Esperado | Onde conferir |
| --- | --- | --- | --- |
| Ativo QA Pulverizador: aquisição 250.000,00 (2026-06-15), residual 50.000,00, vida 120 m | (250.000−50.000) ÷ 120 = 1.666,666… | depreciação 2026-07: **1.666,67**; gerar 2× → **1 linha** (idempotente por ativo×competência); custeio depreciacao 1.666,67 | `patrimonio_depreciacoes`, `custeio_lancamentos` |
| Valor patrimonial | 250.000 − 1.666,67 | **248.333,33** | patrimonio/valor_patrimonial |
| Abastecimento QA-TRATOR 50 L, R$ 300,00, horímetro 100 | — | gravado; `maquinas.horimetro_atual=100`; custeio maquinas **300,00** | `maquina_abastecimentos`, `custeio_lancamentos` |
| Horímetro regressivo (negativo): novo abastecimento horímetro 90 | 90 < 100 | **REJEITADO** (flash erro, nenhuma linha nova, horímetro segue 100) | `maquina_abastecimentos` |
| Irrigação QA-1A 2026-07-10: água 100 m³ = 80,00; energia 200 kWh = 120,00 | 80+120 | custeio irrigação **200,00** (2 linhas); reedição não duplica | `irrigacao_consumos`, `custeio_lancamentos` |

## F11 — Fiscal (XML NF-e sintético)

| Entrada | Esperado | Onde conferir |
| --- | --- | --- |
| XML NF-e 4.00 sintético, chave 44 dígitos fixa, vNF 1.234,56, emitente CNPJ novo | documento `nfe` importado, valor 1.234,56, fornecedor criado (get-or-create) | `fiscal_documentos`, `fornecedores` |
| Reimportar o MESMO XML | **`ja_existia`** — continua 1 documento (idempotente por chave) | `fiscal_documentos` |

## F12 — Exports CSV

Para cada export testado (relatórios do motor `_rel_base.php` + folha):
primeiros 3 bytes = **BOM `EF BB BF`**; separador **`;`**; decimais com
**vírgula** (sem milhar); e o MESMO gate de permissão da tela (perfil sem
`relatorios.<micro>.ver` → bloqueado, nunca CSV vazio "200 OK").

## Smoke (10) e Permissões (40)

- Rotas extraídas VIVAS de `bios_menu_macros()` (incl. micros `oculto`), logado
  como super_admin: **HTTP 200**, sem `Fatal error/Warning/Parse error` no corpo,
  sem o marcador de erro inline do bootstrap ("Parte desta página não pôde ser
  carregada"), sem redirect para login.
- Para cada perfil (gestor/operador/financeiro/consulta): expectativa POR ROTA
  calculada de `role_permissions` + `vero_dbn_perm` (a mesma regra do guard);
  divergência (200 onde devia 403 e vice-versa) = FAIL. Positivo E negativo.
- Cross-tenant: registro do tenant QA (fazenda) não aparece nem é editável
  logado no tenant QA2 (`vero_update` com escopo → 0 linhas afetadas).
- CSRF: POST sem token → rejeitado (403/flash), NUNCA 500 nem gravação.
- Upload de mapa com `<!DOCTYPE` no KML → recusado (`mapa_xml_safe`).
- Vigência de premiação sobreposta → recusada.
- ENUM/whitelist inválido (ex.: categoria de atividade inexistente) → flash erro,
  nada gravado (proteção de tela contra o sql_mode não-strict).

## Botões (50)

Inventário completo de `name="acao"` por grep no working tree (lista congelada
no script). Para CADA ação: (a) POST sem CSRF → rejeitado; (b) POST com CSRF e
campos obrigatórios vazios / id inexistente (999999999) → flash de erro ou
no-op, **sem 500 e sem efeito fora do tenant QA**. Payload válido completo =
coberto pelos fluxos F1–F12 (mapeamento no script; ações destrutivas por
último, só no tenant QA).
