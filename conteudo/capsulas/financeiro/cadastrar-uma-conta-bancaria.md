---
slug: cadastrar-uma-conta-bancaria
titulo: "Cadastrar uma conta bancária"
tipo: FAZER
modulo: financeiro
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 100
rotas:
  - rota: /financeiro/contas_bancarias.php
    principal: true
tela_app: contas_bancarias
permissoes: [financeiro.contas_bancarias.editar]
papeis:
  nucleo: [financeiro, gestor]
  consulta: [dono]
relacoes:
  relacionado: [conciliar-o-extrato-bancario]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Aqui ficam as contas e caixas por onde o dinheiro da operação passa: conta corrente, poupança, caixa e aplicação. Cadastrá-las é o que dá base para a conciliação bancária — é contra essas contas que você vai bater o extrato do banco com o razão do sistema.

## Como fazer
1. Abra **Contas Bancárias** e clique em **+ Nova conta**.
2. Informe o **nome** (obrigatório) e escolha o **tipo** (corrente, poupança, caixa ou aplicação).
3. Preencha **banco, agência e conta** quando fizer sentido.
4. Informe o **saldo inicial**, se for começar a controlar a partir de um saldo já existente.
5. Clique em **Salvar**. A conta fica disponível na conciliação bancária.

## Erros comuns
- **Deixar o nome vazio.** O nome é o único campo obrigatório — é como a conta aparece na conciliação. Sem ele, o sistema recusa.
- **Errar o saldo inicial.** Ele é o ponto de partida do controle; um saldo inicial errado empurra toda a conciliação. Confira com o extrato antes.
- **Tentar excluir uma conta já conciliada.** O sistema apenas a **inativa** para preservar as conciliações feitas — não some com o histórico.

## Antes e depois
Antes: Levantar as contas e caixas reais da operação. Depois: Conciliar o extrato de cada conta contra o razão pago.

## Roteiro do vídeo
- Abrir Contas Bancárias e clicar em + Nova conta.
- Cadastrar uma conta corrente com saldo inicial.
- Mostrar a conta aparecendo na lista da conciliação.
