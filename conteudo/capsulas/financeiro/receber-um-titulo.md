---
slug: receber-um-titulo
titulo: "Receber um título para registrar a entrada"
tipo: FAZER
modulo: financeiro
objetivo: receber
jornada: [continuo]
nivel: iniciante
duracao_seg: 140
rotas:
  - rota: /financeiro/contas_receber.php
    principal: true
tela_app: baixa_titulo
permissoes: [financeiro.contas_receber.editar]
papeis:
  nucleo: [financeiro, gestor]
  consulta: [dono]
relacoes:
  prerequisito: [lancar-uma-conta-a-receber]
  relacionado: [baixar-um-titulo]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Receber um título é dar baixa num recebível — registrar que o dinheiro entrou. É o gêmeo do baixar em Contas a Pagar: fecha o ciclo do lado das entradas e faz o valor virar caixa efetivo no fluxo. Enquanto o título fica "aberto", ele é só uma entrada prevista.

## Como fazer
1. Abra **Contas a Receber** e localize o título **em aberto** que foi pago.
2. Clique em **Baixar** na linha do título.
3. Informe a **data do recebimento** e a **forma** (dinheiro, PIX, transferência…).
4. Confirme — este é o passo que **registra a entrada e joga o valor no caixa efetivo** do fluxo.
5. Se o título veio de uma venda, a baixa também **marca a venda como paga**; confira que o status virou "pago".

## Erros comuns
- **Receber com a data errada.** O valor entra no caixa no dia errado e distorce o saldo do período. Use a data real em que o dinheiro caiu, não a de hoje.
- **Baixar o título errado.** Confira descrição e valor antes de confirmar — a baixa quita aquele recebível específico.
- **Recebeu por engano?** Use **Estornar** para o título voltar a ficar aberto; não crie um lançamento novo para "compensar".

## Antes e depois
Antes: Ter o recebível lançado (ou gerado por uma venda). Depois: Conferir o fluxo de caixa com a entrada já efetivada.

## Roteiro do vídeo
- Abrir Contas a Receber com um título em aberto.
- Clicar em Baixar, preencher data e forma do recebimento.
- Confirmar e mostrar o título virando "pago".
- Mostrar o estorno reabrindo o título, para o caso de erro.
