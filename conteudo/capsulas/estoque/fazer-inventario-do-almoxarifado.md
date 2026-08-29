---
slug: fazer-inventario-do-almoxarifado
titulo: "Fazer o inventário (contagem) de um almoxarifado"
tipo: FAZER
modulo: estoque
objetivo: conferir
jornada: [continuo]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /estoque/inventario.php
    principal: true
permissoes: [estoque.inventario.editar]
papeis:
  nucleo: [estoquista, almoxarife, encarregado]
  consulta: [gestor, dono]
relacoes:
  relacionado: [ajustar-ou-estornar-movimentacao-de-estoque, conferir-a-auditoria-do-estoque]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O inventário confronta o saldo do sistema com a contagem física do almoxarifado. Ele abre congelando o saldo item por item (por lote, quando o produto tem lotes), recebe a contagem real e, ao ser aprovado, gera os acertos automáticos pela diferença — com trilha completa no histórico.

## Como fazer
1. Abra **Inventário**, selecione o **almoxarifado** e clique em **Abrir inventário**.
2. Percorra a lista e digite a **quantidade contada** de cada item (vem pré-preenchido com o saldo do sistema).
3. Clique em **Fechar contagem** — o inventário fica no status "contado", ainda sem mexer no estoque.
4. Peça a quem tem alçada para **Aprovar**: só então os acertos entram e o saldo passa a bater com a contagem.

## Erros comuns
- **Abrir um segundo inventário no mesmo almoxarifado.** O sistema recusa enquanto houver um em andamento — conclua ou cancele o aberto primeiro.
- **Achar que fechar a contagem já ajusta o estoque.** Não ajusta: fechar só grava a contagem. O saldo só muda na aprovação (alçada `estoque.inventario.excluir`).
- **Contar produto com lote somando tudo numa linha.** Quando o produto tem lotes, cada lote é uma linha própria — conte lote a lote para o FEFO continuar correto.

## Antes e depois
Antes: Ter os produtos e o almoxarifado cadastrados. Depois: Conferir os acertos no histórico de movimentações e, se preciso, rodar a auditoria de consistência.

## Roteiro do vídeo
- Abrir Inventário, escolher o almoxarifado e abrir a contagem.
- Digitar contagens diferentes do saldo em alguns itens.
- Fechar a contagem; mostrar o status "contado" sem mexer no estoque.
- Aprovar e mostrar o acerto entrando no histórico e o saldo passando a bater.
