---
slug: emitir-um-pedido-de-compra
titulo: "Emitir um pedido de compra e enviar para aprovação"
tipo: FAZER
modulo: compras
objetivo: comprar
jornada: [continuo]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /compras/pedidos.php
    principal: true
permissoes: [compras.pedidos_compra.editar]
papeis:
  nucleo: [comprador, gestor]
  consulta: [encarregado, financeiro, dono]
relacoes:
  prerequisito: [cotar-fornecedores-e-escolher-a-vencedora]
  proximo: [aprovar-um-pedido-de-compra, receber-uma-compra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O pedido de compra é o documento firme com o fornecedor: itens, preços, prazo de entrega, frete e condição de pagamento. Ele segue um fluxo — rascunho → aprovação → aprovado → recebido — e pode nascer de uma solicitação ou de uma cotação escolhida. É ele que depois casa com o recebimento no estoque.

## Como fazer
1. Abra **Pedidos de Compra** e clique em **Novo pedido** (ou gere a partir de uma cotação/solicitação).
2. Escolha o **fornecedor**, a **data**, os **itens com preço** e preencha prazo, frete e condição de pagamento.
3. Salve o **rascunho** e revise os valores.
4. Clique em **Enviar para aprovação** — o pedido vai para a fila de Aprovações (ou é liberado direto, se estiver dentro da alçada de valor configurada).

## Erros comuns
- **Deixar o pedido em rascunho e achar que já foi comprado.** Só pedidos em rascunho vão para aprovação; enquanto não enviar, nada acontece com ele.
- **Esquecer prazo de entrega e condição de pagamento.** Esses campos alimentam a ficha do fornecedor (lead time, % no prazo) e o controle de orçamento — preencha para os relatórios fecharem.
- **Vincular à safra errada (ou não vincular).** O vínculo com o talhão/safra é o que joga a compra no custo certo e no controle de orçamento; sem ele, o pedido pode cair em "compras fora do orçamento".

## Antes e depois
Antes: Escolher a cotação vencedora (ou abrir a solicitação). Depois: Aprovar o pedido e, na entrega, receber a compra no estoque.

## Roteiro do vídeo
- Abrir Pedidos e criar um novo a partir de uma cotação escolhida.
- Preencher fornecedor, itens, prazo, frete e condição.
- Salvar o rascunho e revisar o total.
- Enviar para aprovação e mostrar o pedido entrando na fila.
