---
slug: acompanhar-as-despesas-pagas
titulo: "Acompanhar as despesas pagas"
tipo: CONSULTAR
modulo: financeiro
objetivo: acompanhar
jornada: [continuo]
nivel: iniciante
duracao_seg: 90
rotas:
  - rota: /financeiro/despesas.php
    principal: true
tela_app: despesas
permissoes: [financeiro.despesas.ver]
papeis:
  nucleo: [financeiro, gestor]
  consulta: [dono, encarregado]
relacoes:
  relacionado: [baixar-um-titulo, ler-o-fluxo-de-caixa]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Despesas é a visão gerencial do que já saiu do caixa — só saídas **efetivadas** (pagas), recortadas por mês e por origem. É leitura pura: aqui você entende para onde o dinheiro foi, não faz baixa nem edita título. A manutenção dos títulos fica em Contas a Pagar.

## Como fazer
1. Abra **Despesas**. Ela mostra as saídas pagas do ano, com o gráfico por mês.
2. Filtre por **ano, mês e origem** para focar no recorte que interessa.
3. Clique numa **barra do mês** para saltar direto para aquele mês.
4. Leia o **total** do recorte e, se precisar levar para fora, use **Exportar CSV** ou **Imprimir**.

## Erros comuns
- **Procurar uma despesa que ainda não foi paga.** Esta tela só mostra o que já saiu do caixa; título em aberto aparece em Contas a Pagar, não aqui.
- **Tentar corrigir um valor por aqui.** É relatório read-only — para mexer no título, vá à tela de origem (Contas a Pagar).

## Antes e depois
Antes: Registrar as baixas em Contas a Pagar. Depois: Cruzar as saídas com o fluxo de caixa e o DRE.

## Roteiro do vídeo
- Abrir Despesas e filtrar por um mês.
- Clicar numa barra e mostrar o recorte.
- Exportar o CSV do período.
