---
slug: abrir-uma-solicitacao-de-compra
titulo: "Abrir uma solicitação de compra"
tipo: FAZER
modulo: compras
objetivo: solicitar
jornada: [continuo]
nivel: iniciante
duracao_seg: 140
rotas:
  - rota: /compras/solicitacoes.php
    principal: true
permissoes: [compras.solicitacoes_compra.editar]
papeis:
  nucleo: [encarregado, almoxarife, comprador]
  consulta: [gestor, dono]
relacoes:
  proximo: [cotar-fornecedores-e-escolher-a-vencedora, emitir-um-pedido-de-compra]
  relacionado: [tratar-alertas-de-estoque-critico]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A solicitação de compra é o pedido interno: alguém da operação registra o que precisa comprar, sem ainda escolher fornecedor nem preço. Ela abre o fluxo — pode virar cotação para comparar fornecedores ou ir direto para um pedido de compra.

## Como fazer
1. Abra **Solicitações de Compra** e clique em **Nova solicitação**.
2. Informe a **data** e adicione os **itens**: produto do cadastro (ou uma descrição livre) e a **quantidade**.
3. Clique em **Salvar** — a solicitação nasce no status "aberta".
4. Encaminhe: gere as **cotações** para comparar fornecedores ou converta direto em **pedido**.

## Erros comuns
- **Salvar sem itens ou sem data.** A data é obrigatória e uma solicitação vazia não serve para nada — liste pelo menos um item com quantidade.
- **Descrever tudo em texto livre quando o produto já existe no cadastro.** Aponte o produto cadastrado sempre que possível: isso liga a compra ao estoque e ao custo mais tarde.
- **Deixar a solicitação "aberta" e esquecer.** Ela não compra sozinha — precisa virar cotação ou pedido. Acompanhe a lista de abertas.

## Antes e depois
Antes: Perceber a necessidade (alerta de estoque mínimo, pedido da operação). Depois: Cotar fornecedores ou emitir o pedido de compra a partir dela.

## Roteiro do vídeo
- Abrir Solicitações e criar uma nova.
- Adicionar dois itens com quantidade.
- Salvar e mostrar o status "aberta".
- Apontar os dois caminhos: gerar cotação ou converter em pedido.
