---
slug: cotar-fornecedores-e-escolher-a-vencedora
titulo: "Cotar fornecedores e escolher a cotação vencedora"
tipo: FAZER
modulo: compras
objetivo: cotar
jornada: [continuo]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /compras/cotacoes.php
    principal: true
permissoes: [compras.cotacoes.editar]
papeis:
  nucleo: [comprador, gestor]
  consulta: [encarregado, dono]
relacoes:
  prerequisito: [abrir-uma-solicitacao-de-compra]
  proximo: [emitir-um-pedido-de-compra]
  relacionado: [cadastrar-um-fornecedor]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A cotação compara fornecedores para a mesma solicitação. Cada fornecedor cotado vira uma linha com os itens precificados, e o comparativo mostra os totais lado a lado. Você marca a vencedora — as demais ficam recusadas, mas continuam no comparativo para preservar a história da decisão.

## Como fazer
1. Abra **Cotações** e selecione a **solicitação de compra** que quer cotar.
2. Adicione uma cotação por **fornecedor**: informe a data e os **preços** de cada item da solicitação.
3. Repita para os outros fornecedores e leia o **comparativo** de totais lado a lado.
4. Clique em **Escolher** na melhor — ela vira "escolhida" e as outras, "recusadas". Depois use **Gerar pedido**.

## Erros comuns
- **Cotar uma solicitação sem itens.** A cotação copia os itens da solicitação; se ela estiver vazia, o sistema recusa — volte e complete a solicitação primeiro.
- **Escolher pelo menor total ignorando prazo e frete.** O comparativo mostra o valor, mas prazo de entrega e condição de pagamento entram na decisão real — olhe além do número.
- **Achar que "escolher" já gera o pedido.** Marcar a vencedora só define a decisão; o pedido de compra é criado no passo seguinte, com "Gerar pedido".

## Antes e depois
Antes: Ter a solicitação de compra aberta com itens. Depois: Gerar o pedido de compra a partir da cotação escolhida.

## Roteiro do vídeo
- Abrir Cotações e escolher uma solicitação.
- Cadastrar duas cotações de fornecedores diferentes com preços.
- Mostrar o comparativo de totais lado a lado.
- Escolher a vencedora e apontar o botão Gerar pedido.
