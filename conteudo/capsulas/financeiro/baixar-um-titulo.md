---
slug: baixar-um-titulo
titulo: "Baixar um título para fechar o ciclo do dinheiro"
tipo: FAZER
modulo: financeiro
objetivo: baixar
jornada: [safra]
nivel: iniciante
duracao_seg: 150
rotas:
  - rota: /financeiro/contas_pagar.php
    principal: true
tela_app: baixa_titulo
permissoes: [financeiro.contas_pagar.editar]
papeis:
  nucleo: [financeiro, gestor]
  consulta: [dono]
relacoes:
  relacionado: [receber-uma-compra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Baixar um título é registrar que a conta foi paga — é o que fecha o ciclo do dinheiro e faz o valor sair do fluxo de caixa. Enquanto o título fica "aberto", ele continua contando como uma saída que ainda vai acontecer.

## Como fazer
1. Abra **Contas a Pagar** e localize o título em aberto que você pagou.
2. Clique em **Baixar** na linha do título.
3. Informe a data do pagamento e a forma de pagamento usada.
4. Confirme a baixa — este é o passo que **quita o título e desconta o valor do fluxo de caixa**.
5. Confira que o título mudou de "aberto" para "pago" na lista.

## Erros comuns
- **Baixar com a data errada.** O valor sai do fluxo de caixa no dia errado e distorce o saldo do período. Sempre use a data real do pagamento, não a de hoje.
- **Baixar o título errado.** Confira descrição e valor antes de confirmar — a baixa quita aquele lançamento específico.
- **Baixar por engano.** Se errou, use **Estornar baixa** para o título voltar a ficar aberto; não crie um lançamento novo para "corrigir".

## Antes e depois
Antes: Receber a compra que gerou o título a pagar. Depois: Conferir o fluxo de caixa com a saída já registrada.

## Roteiro do vídeo
- Abrir Contas a Pagar com um título em aberto visível na lista.
- Narrar: "Baixar um título é dizer que a conta foi paga — é isso que fecha o ciclo do dinheiro."
- Clicar em **Baixar**, preencher data e forma de pagamento.
- Confirmar e mostrar o título virando "pago".
- Cortar para o fluxo de caixa mostrando a saída registrada.
