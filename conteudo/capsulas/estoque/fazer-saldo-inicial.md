---
slug: fazer-saldo-inicial
titulo: "Fazer o saldo inicial do estoque"
tipo: FAZER
modulo: estoque
objetivo: implantar
jornada: [safra]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /estoque/implantacao_saldo.php
    principal: true
tela_app: saldo_inicial
permissoes: [estoque.produtos_insumos.editar]
papeis:
  nucleo: [almoxarifado, gestor]
  consulta: [encarregado]
relacoes:
  proximo: [dar-entrada-de-nota-no-estoque]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O saldo inicial é a foto do que já existe no almoxarifado no dia em que você começa a usar o sistema. Ele vira a base de tudo: o custo médio e todos os custos da safra saem desse número, então ele precisa nascer certo.

## Como fazer
1. Abra **Saldo Inicial** (a rota abre o modal na tela de Produtos).
2. Informe a **data da implantação** e digite o **código do produto** — o sistema mostra o saldo atual que está no sistema.
3. Vá até a prateleira e **conte o físico** antes de digitar qualquer coisa.
4. Digite o **saldo correto** (o que existe de verdade) e, se for entrada, o custo unitário e a validade do lote quando o produto controla lote.
5. Clique em **Corrigir saldo** — este passo **grava o saldo inicial pela diferença no estoque oficial**.

## Erros comuns
- **Confirmar sem contar o físico.** O saldo inicial é a base do custo médio de tudo; um número errado aqui contamina todo custo futuro e não tem um "desfazer" limpo — só um novo ajuste por cima, que deixa rastro. Confira antes de confirmar.
- **Deixar o custo unitário em branco na entrada.** Quando o saldo correto é maior que o atual, o sistema faz uma entrada e exige o custo — sem ele o custo médio já nasce torto.
- **Refazer o saldo várias vezes para "acertar".** Cada correção grava uma movimentação nova de implantação e embaralha o histórico. Conte uma vez, confira e confirme uma vez só.

## Antes e depois
Antes: Contar fisicamente o que há no almoxarifado no dia da virada. Depois: Dar entrada das notas novas, agora que a base já está montada.

## Roteiro do vídeo
- Abrir o modal Saldo Inicial pela rota de implantação.
- Narrar: "Este é o número que vira a base de tudo. Não tem desfazer limpo — então conta antes."
- Digitar a data e o código; mostrar o saldo atual aparecendo.
- Mostrar a contagem na prateleira, digitar o saldo correto e o custo.
- Destacar o botão **Corrigir saldo** antes de clicar; mostrar a mensagem de saldo corrigido e custo médio atualizado.
