---
slug: cadastrar-um-produto-no-estoque
titulo: "Cadastrar um produto no estoque"
tipo: FAZER
modulo: estoque
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 150
rotas:
  - rota: /estoque/produtos.php
    principal: true
permissoes: [estoque.produtos_insumos.editar]
papeis:
  nucleo: [estoquista, almoxarife, gestor]
  consulta: [encarregado, comprador, dono]
relacoes:
  proximo: [fazer-saldo-inicial, dar-entrada-de-nota-no-estoque]
  relacionado: [organizar-grupos-e-subgrupos-de-produtos]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O produto é a ficha de tudo que entra e sai do estoque: código, nome, unidade, tipo de insumo e grupo. Sem ele cadastrado, não há como dar entrada de nota, apontar consumo no campo nem calcular o custo médio. É o primeiro passo de qualquer item novo.

## Como fazer
1. Abra **Produtos e Insumos** e clique em **Novo produto**.
2. Preencha o **código** (6 números — o campo já traz o próximo livre) e o **nome**.
3. Escolha o **tipo de insumo** (defensivo, fertilizante, semente etc.), a **unidade** e o **grupo/subgrupo**.
4. Clique em **Salvar** — o produto passa a aparecer nas telas de entrada, saída e apontamento.

## Erros comuns
- **Sobrescrever o código sugerido por um número que já existe.** O código é único no tenant e o cadastro é recusado; só troque o número se precisar de um específico e livre.
- **Errar a unidade de medida.** Ela alimenta o custo médio e as doses; um produto em litros cadastrado como quilo distorce todo o consumo depois. Confira antes de salvar.
- **Cadastrar dados de bula (dose, carência) que só o gestor/RT deveria editar.** Deixe esses campos para quem tem a alçada — o cadastro básico você faz normalmente.

## Antes e depois
Antes: Organizar os grupos e subgrupos para classificar o produto. Depois: Fazer o saldo inicial ou dar a primeira entrada de nota.

## Roteiro do vídeo
- Abrir Produtos e Insumos e clicar em Novo produto.
- Mostrar o código de 6 dígitos já preenchido; narrar "o sistema sugere o próximo livre".
- Preencher nome, tipo, unidade e grupo; destacar a unidade como campo crítico.
- Salvar e mostrar o produto novo aparecendo na lista.
