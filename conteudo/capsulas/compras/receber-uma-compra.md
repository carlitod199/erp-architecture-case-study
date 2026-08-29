---
slug: receber-uma-compra
titulo: "Receber uma compra que chegou"
tipo: FAZER
modulo: compras
objetivo: receber
jornada: [safra]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /compras/recebimentos.php
    principal: true
tela_app: recebimento_compra
permissoes: [compras.recebimentos.editar]
papeis:
  nucleo: [almoxarifado, encarregado, gestor]
  consulta: [financeiro]
relacoes:
  proximo: [dar-entrada-de-nota-no-estoque]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Receber é o passo que confirma que a mercadoria do pedido chegou de verdade. Ao confirmar, o sistema dá entrada no estoque ao custo da nota e abre a conta a pagar para o fornecedor.

## Como fazer
1. Abra **Recebimentos** e escolha o pedido que chegou (só aparecem os pedidos **aprovados**).
2. Confira item por item — produto, quantidade e custo — contra a nota que veio com a carga.
3. Informe a data do recebimento e a condição de pagamento (à vista ou os dias parcelados, ex.: 45/90).
4. Clique em **Confirmar recebimento** — este é o passo que **dá entrada no estoque e gera a conta a pagar**.
5. Confira que a entrada apareceu no estoque com o custo certo.

## Erros comuns
- **Confirmar sem bater com a nota.** O custo errado entra no estoque e distorce o custo médio de todos os insumos. Confira quantidade e valor antes de clicar.
- **Procurar um pedido que não está aprovado.** Só entra em Recebimentos o pedido já aprovado — se ele não aparece, volte e libere a aprovação primeiro.
- **Digitar a condição de pagamento fora do padrão.** Texto solto não vira parcela; use um preset ou os dias separados por barra (ex.: 45/90), senão o vencimento sai errado.

## Antes e depois
Antes: Aprovar o pedido de compra que autoriza a mercadoria. Depois: Conferir a entrada de nota no estoque que essa confirmação gerou.

## Roteiro do vídeo
- Abrir a tela Recebimentos com um pedido aprovado esperando na lista.
- Narrar: "O pedido está aprovado, a carga chegou. Receber é confirmar o que chegou de verdade."
- Percorrer os itens conferindo quantidade e custo contra a nota na mão.
- Preencher data e condição de pagamento; destacar o botão **Confirmar recebimento** antes de clicar.
- Cortar para o estoque mostrando a entrada nova e para o contas a pagar com a conta gerada.
