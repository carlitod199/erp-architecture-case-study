---
slug: consultar-o-historico-de-compras
titulo: "Consultar o histórico de compras"
tipo: CONSULTAR
modulo: compras
objetivo: consultar
jornada: [continuo]
nivel: iniciante
duracao_seg: 110
rotas:
  - rota: /compras/historico_compras.php
    principal: true
permissoes: [compras.historico_compras.ver]
papeis:
  nucleo: [comprador, gestor, financeiro]
  consulta: [encarregado, dono]
relacoes:
  relacionado: [cadastrar-um-fornecedor, receber-uma-compra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O Histórico de Compras é a leitura consolidada dos recebimentos já confirmados, item por item. Serve para responder rápido "quanto compramos deste produto?", "quanto pagamos por unidade da última vez?" e "quanto esse fornecedor entregou no período?" — com filtros de fornecedor, produto e datas, mais os totais.

## Como fazer
1. Abra **Histórico de Compras**.
2. Filtre por **fornecedor**, **produto** e **período** conforme a pergunta.
3. Leia os itens recebidos e o **total** do filtro.
4. Use o resultado para negociar preço na próxima cotação ou conferir a ficha do fornecedor.

## Antes e depois
Antes: Ter compras recebidas e confirmadas nos Recebimentos. Depois: Levar o histórico de preços para a próxima cotação de fornecedores.

## Roteiro do vídeo
- Abrir Histórico de Compras.
- Filtrar um produto num período e ler os itens e o total.
- Trocar o filtro para um fornecedor e comparar.
- Narrar como usar o preço passado para negociar a próxima compra.
