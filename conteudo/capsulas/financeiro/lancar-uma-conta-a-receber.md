---
slug: lancar-uma-conta-a-receber
titulo: "Lançar uma conta a receber"
tipo: FAZER
modulo: financeiro
objetivo: lancar
jornada: [continuo]
nivel: iniciante
duracao_seg: 150
rotas:
  - rota: /financeiro/contas_receber.php
    principal: true
tela_app: contas_receber
permissoes: [financeiro.contas_receber.editar]
papeis:
  nucleo: [financeiro, gestor]
  consulta: [dono]
relacoes:
  relacionado: [receber-um-titulo, baixar-um-titulo]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Uma conta a receber é um valor que a operação tem para entrar — uma venda a prazo, um acerto, um reembolso. Lançá-la coloca o título no razão de recebíveis e o joga na projeção do fluxo de caixa. Vendas já geram recebíveis sozinhas; use o lançamento manual para o que não veio de uma venda.

## Como fazer
1. Abra **Contas a Receber** e clique em **+ Novo lançamento**.
2. Preencha **descrição** e **valor** (maior que zero).
3. Informe a **data de vencimento** — ela é obrigatória no lançamento novo.
4. Se quiser, classifique o título (centro de custo, plano de contas, safra) e defina **parcelas**.
5. Clique em **Salvar**. O título entra como **aberto** e passa a contar na projeção do fluxo de caixa.

## Erros comuns
- **Salvar sem vencimento.** O sistema exige vencimento no título novo — sem ele, o valor cairia no bucket "Sem vencimento" e sumiria da projeção mensal do fluxo.
- **Lançar à mão um recebível que já veio da venda.** Vendas geram recebíveis automaticamente; lançar de novo duplica o valor. O manual é só para o que não tem venda de origem.
- **Misturar parcelamento com rateio.** Os dois juntos não são suportados — escolha lançar em parcelas OU ratear entre centros, não os dois.

## Antes e depois
Antes: Ter o acerto ou a cobrança que originou o valor a receber. Depois: Receber o título quando o dinheiro entrar.

## Roteiro do vídeo
- Abrir Contas a Receber e clicar em + Novo lançamento.
- Preencher descrição, valor e vencimento.
- Salvar e mostrar o título entrando como "aberto".
- Cortar para o fluxo de caixa mostrando o valor na projeção.
