---
slug: consultar-consumo-de-agua-e-energia
titulo: "Consultar o consumo de água e energia da irrigação"
tipo: CONSULTAR
modulo: irrigacao
objetivo: consultar
jornada: [safra]
nivel: iniciante
duracao_seg: 110
rotas:
  - rota: /irrigacao/consumo_agua.php
    principal: true
  - rota: /irrigacao/consumo_energia.php
permissoes: [irrigacao.consumo_agua.ver]
papeis:
  nucleo: [gestor, rt_gerente, financeiro, dono]
  consulta: [encarregado]
relacoes:
  prerequisito: [registrar-apontamento-de-irrigacao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Estas telas consolidam quanto de água (m³) e energia (kWh) a irrigação gastou — por lançamento, por válvula e por mês —, com o custo. São de leitura: o número nasce nos apontamentos de irrigação. Servem para acompanhar consumo, comparar válvulas e entender o custo da água na safra.

## Como fazer
1. Abra **Consumo de Água** (ou **Consumo de Energia**) e filtre por válvula e período.
2. Leia o detalhe por lançamento e a consolidação por válvula e por mês.
3. Observe o selo de custo: **auto** significa que o valor bate com a tarifa vigente; **manual** significa que foi digitado ou lançado antes da tarifa.
4. Se um consumo esperado não aparece, verifique se o apontamento de origem foi salvo com a quantidade.

## Antes e depois
Antes: Registrar os apontamentos de irrigação com os consumos. Depois: Cruzar o custo com o Custo Realizado da safra.

## Roteiro do vídeo
- Abrir Consumo de Água, filtrar por válvula e período.
- Narrar a leitura do detalhe e da consolidação por mês.
- Explicar o selo auto × manual e sua ligação com a tarifa.
