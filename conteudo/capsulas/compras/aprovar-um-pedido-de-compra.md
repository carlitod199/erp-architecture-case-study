---
slug: aprovar-um-pedido-de-compra
titulo: "Aprovar ou rejeitar um pedido de compra"
tipo: FAZER
modulo: compras
objetivo: aprovar
jornada: [continuo]
nivel: intermediario
duracao_seg: 130
rotas:
  - rota: /compras/aprovacoes.php
    principal: true
permissoes: [compras.aprovacoes.editar]
papeis:
  nucleo: [gestor, financeiro, dono]
  consulta: [comprador]
relacoes:
  prerequisito: [emitir-um-pedido-de-compra]
  proximo: [receber-uma-compra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A tela de Aprovações é a alçada: os pedidos enviados ficam numa fila esperando um sim ou não de quem tem autoridade para autorizar a compra. Aprovar libera o pedido para recebimento; rejeitar devolve ao rascunho para o comprador corrigir, com a observação registrada.

## Como fazer
1. Abra **Aprovações** e veja a fila de pedidos pendentes.
2. Abra o pedido e confira fornecedor, itens, valores e prazo.
3. Clique em **Aprovar** para liberar — o pedido fica pronto para ser recebido.
4. Ou clique em **Rejeitar**, escrevendo a **observação** do motivo — ele volta a rascunho para ajuste.

## Erros comuns
- **Aprovar sem ler o pedido.** A aprovação é o controle de gasto; confira valor, fornecedor e vínculo de safra antes de liberar — depois de aprovado, ele segue para recebimento.
- **Rejeitar sem escrever o motivo.** Sem a observação, o comprador não sabe o que corrigir e o pedido volta e volta. Diga o que precisa mudar.
- **Procurar um pedido já decidido na fila.** A fila mostra só os pendentes; um pedido já aprovado ou rejeitado não aparece aqui — consulte-o na tela de Pedidos.

## Antes e depois
Antes: O comprador enviar o pedido para aprovação. Depois: Receber a compra no estoque quando o material chegar.

## Roteiro do vídeo
- Abrir Aprovações com pedidos pendentes.
- Abrir um pedido e conferir valores e fornecedor.
- Aprovar um e mostrar que ele sai da fila liberado.
- Rejeitar outro com observação e mostrar que voltou a rascunho.
