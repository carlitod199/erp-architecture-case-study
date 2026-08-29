---
slug: dar-entrada-de-nota-no-estoque
titulo: "Dar entrada de nota no estoque"
tipo: FAZER
modulo: estoque
objetivo: lancar
jornada: [safra]
nivel: iniciante
duracao_seg: 150
rotas:
  - rota: /estoque/entradas.php
    principal: true
tela_app: estoque_entrada
permissoes: [estoque.produtos_insumos.editar]
papeis:
  nucleo: [almoxarifado, encarregado]
  consulta: [gestor, financeiro]
relacoes:
  prerequisito: [receber-uma-compra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A entrada é o que coloca o produto dentro do estoque com o custo real da nota. É esse custo que forma o custo médio ponderado dos insumos — se a entrada está certa, o custo por hectare da safra fica certo.

## Como fazer
1. Abra **Entradas** e veja as entradas que já chegaram das compras confirmadas.
2. Confira produto, quantidade e **custo unitário** de cada entrada contra a nota — é esse custo que alimenta o custo médio.
3. Para uma nota que **não** veio de pedido de compra, vá em **Produtos**, ache o item e clique em **Movimentar**.
4. Escolha o tipo **Entrada**, informe a quantidade, o custo real da nota e a validade (quando o produto controla lote) — este passo **cria o saldo e o custo do insumo**.
5. Volte a Entradas e confirme que a movimentação apareceu com o custo certo.

## Erros comuns
- **Lançar entrada sem o custo da nota.** Sem o custo real, o custo médio do insumo fica errado e todo apontamento que usar esse produto sai barato demais.
- **Esquecer a validade em produto que controla lote.** O sistema não deixa criar o saldo sem o lote — informe a validade na entrada, senão a movimentação nem grava.
- **Lançar de novo uma entrada que já veio do recebimento.** A confirmação da compra já dá entrada sozinha; conferir em Entradas evita dobrar a quantidade.

## Antes e depois
Antes: Receber a compra que chegou (a confirmação já gera a entrada). Depois: O custo do insumo entra no custo médio e alimenta a conta da safra.

## Roteiro do vídeo
- Abrir Entradas mostrando uma movimentação recém-chegada de um recebimento.
- Narrar: "Toda entrada carrega o custo da nota. É esse custo que forma o custo médio do insumo."
- Apontar produto, quantidade e custo unitário na linha.
- Ir a Produtos, clicar em Movimentar, escolher Entrada e preencher quantidade, custo e validade.
- Voltar a Entradas e mostrar a nova movimentação com o custo certo.
