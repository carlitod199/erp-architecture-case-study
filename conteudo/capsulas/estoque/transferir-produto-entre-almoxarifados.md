---
slug: transferir-produto-entre-almoxarifados
titulo: "Transferir um produto entre almoxarifados"
tipo: FAZER
modulo: estoque
objetivo: movimentar
jornada: [continuo]
nivel: intermediario
duracao_seg: 150
rotas:
  - rota: /estoque/transferencias.php
    principal: true
permissoes: [estoque.transferencias.editar]
papeis:
  nucleo: [estoquista, almoxarife, encarregado]
  consulta: [gestor, dono]
relacoes:
  prerequisito: [cadastrar-um-almoxarifado]
  relacionado: [ajustar-ou-estornar-movimentacao-de-estoque]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A transferência move produto de um almoxarifado para outro sem mudar o custo: gera uma saída na origem e uma entrada no destino ao mesmo custo unitário, numa única operação. O custo médio global do produto não muda — só o lugar onde ele está guardado.

## Como fazer
1. Abra **Transferências** e clique em **Nova transferência**.
2. Escolha o **produto**, o **almoxarifado de origem**, o **de destino** e a **quantidade**.
3. Confirme a **data** e clique em **Transferir** — origem e destino são atualizados juntos.
4. Confira o saldo dos dois almoxarifados na tela de Produtos.

## Erros comuns
- **Origem igual ao destino.** O sistema recusa — escolha almoxarifados diferentes.
- **Transferir mais do que há na origem.** A saída usa o lote mais próximo do vencimento (FEFO); se faltar saldo, a operação inteira é desfeita e nada muda.
- **Ignorar o aviso de lote vencido.** Se o FEFO cair num lote vencido, é preciso marcar a confirmação explícita e reenviar — não force sem entender por que o lote está vencido.

## Antes e depois
Antes: Ter os dois almoxarifados cadastrados e saldo na origem. Depois: Conferir os saldos ou, se errou, estornar o par pela própria tela (alçada de exclusão).

## Roteiro do vídeo
- Abrir Transferências e iniciar uma nova.
- Selecionar produto, origem, destino e quantidade.
- Narrar "o custo não muda, só o lugar"; clicar em Transferir.
- Mostrar o saldo caindo na origem e subindo no destino.
