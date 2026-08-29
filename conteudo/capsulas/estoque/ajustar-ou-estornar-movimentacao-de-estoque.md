---
slug: ajustar-ou-estornar-movimentacao-de-estoque
titulo: "Ajustar o saldo ou estornar uma movimentação de estoque"
tipo: FAZER
modulo: estoque
objetivo: corrigir
jornada: [continuo]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /estoque/movimentacoes.php
    principal: true
permissoes: [estoque.historico_movimentacoes.editar]
papeis:
  nucleo: [estoquista, almoxarife, gestor]
  consulta: [encarregado, dono]
relacoes:
  relacionado: [fazer-inventario-do-almoxarifado, transferir-produto-entre-almoxarifados]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O Histórico de Movimentações é a trilha de tudo que entrou e saiu — e é também onde você corrige. Dá para fazer um ajuste tipado (com motivo obrigatório, por produto ou por lote), registrar uma devolução de campo (sobra de apontamento que volta ao custo original) e estornar um lançamento manual errado.

## Como fazer
1. Abra **Histórico de Movimentações** e localize o produto ou o lançamento.
2. Para acertar saldo, use **Ajustar**: escolha o motivo, a direção (aumentar/reduzir), a quantidade e a data.
3. Para desfazer um lançamento manual errado, use **Estornar** e informe o **motivo** — o sistema gera o contra-movimento.
4. Confira o novo saldo na tela de Produtos.

## Erros comuns
- **Ajustar sem escolher o motivo.** O motivo é obrigatório e é o que dá rastreabilidade ao acerto — sem ele o sistema recusa.
- **Tentar estornar uma compra ou apontamento por aqui.** Documentos (compra, apontamento, aplicação, inventário) estornam pela tela de origem; aqui só saem lançamentos `manual` e `devolução de campo`. Transferência tem o fluxo do par na tela própria.
- **Estornar sem alçada.** O estorno usa a permissão `estoque.historico_movimentacoes.excluir`; se você só tem a de edição, faz ajuste, mas não estorno.

## Antes e depois
Antes: Identificar a divergência (pela auditoria ou pelo inventário). Depois: Conferir o saldo corrigido e o contra-movimento registrado no histórico.

## Roteiro do vídeo
- Abrir o Histórico e filtrar um produto com divergência.
- Fazer um ajuste de redução com motivo; mostrar o saldo mudando.
- Localizar um lançamento manual errado e estorná-lo com motivo.
- Mostrar o contra-movimento na trilha.
