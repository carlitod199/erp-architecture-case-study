---
slug: calcular-diarias-de-mao-de-obra
titulo: "Calcular quantas diárias uma tarefa precisa"
tipo: CONSULTAR
modulo: operacao-agricola
objetivo: dimensionar
jornada: [safra]
nivel: iniciante
duracao_seg: 120
rotas:
  - rota: /agro/calculadora.php
    principal: true
permissoes: [agricola.calculadora]
papeis:
  nucleo: [encarregado, gestor, rt_gerente]
  consulta: [operador, dono]
relacoes:
  prerequisito: [definir-parametros-de-rendimento]
  relacionado: [planejar-atividade]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A calculadora estima quantas diárias uma tarefa precisa para caber no prazo, usando o rendimento cadastrado do tipo de atividade. Ela orienta o dimensionamento da equipe — não grava nada e não decide por você.

## Como fazer
1. Abra a **Calculadora de Diárias** e escolha a **atividade** (o rendimento vigente já entra como meta).
2. Para tarefas por planta (poda etc.), informe **nº de plantas** e **dias**; a conta é plantas ÷ dias ÷ meta, arredondada para cima.
3. Para **colheita** (unidade caixa/kg), informe a **produção prevista em kg**, os dias e o **peso da caixa** — a meta é convertida em kg.
4. Escolha **Própria** ou **Terceirizada** e, se quiser, o custo da diária para ver o custo total rotulado.
5. Leia o resultado de diárias necessárias; ajuste dias ou meta para simular cenários.

## Antes e depois
Antes: Cadastrar os parâmetros de rendimento do tipo de atividade. Depois: Planejar a atividade e emitir a Ordem de Serviço com a equipe dimensionada.

## Roteiro do vídeo
- Abrir a Calculadora e escolher a atividade Poda.
- Digitar nº de plantas e dias; mostrar as diárias arredondando para cima.
- Trocar para uma atividade de colheita e demonstrar produção em kg × peso da caixa.
