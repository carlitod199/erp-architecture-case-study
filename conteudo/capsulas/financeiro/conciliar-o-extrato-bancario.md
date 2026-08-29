---
slug: conciliar-o-extrato-bancario
titulo: "Conciliar o extrato bancário"
tipo: FAZER
modulo: financeiro
objetivo: conciliar
jornada: [continuo]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /financeiro/conciliacao_bancaria.php
    principal: true
tela_app: conciliacao_bancaria
permissoes: [financeiro.conciliacao_bancaria.editar]
papeis:
  nucleo: [financeiro, gestor]
  consulta: [dono]
relacoes:
  prerequisito: [cadastrar-uma-conta-bancaria]
  relacionado: [baixar-um-titulo, receber-um-titulo]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Conciliar é bater o saldo do extrato do banco contra o saldo que o sistema calcula a partir do que foi pago e recebido no período. Se os dois batem, o razão está fiel ao banco; se divergem, a conciliação **sinaliza** a diferença para você caçar o lançamento que falta ou sobra.

## Como fazer
1. Abra **Conciliação Bancária** e inicie uma nova conciliação.
2. Escolha a **conta bancária** e o **período** (início e fim).
3. Informe o **saldo do extrato** naquele período, copiado do banco.
4. Confirme — o VERO calcula sozinho o **saldo do sistema** (movimentações pagas no período) e compara.
5. Leia o resultado: **conciliada** quando bate, **divergente** quando não — e a diferença aparece em reais para você investigar.

## Erros comuns
- **Período do extrato diferente do período informado.** O saldo do sistema é calculado exatamente pelas datas que você digitou — use o mesmo intervalo do extrato, senão vai divergir por recorte, não por erro real.
- **Divergiu e parar por aí.** Divergente não é o fim: é o convite para achar o lançamento faltante. Confira baixas não registradas ou datas de pagamento erradas.
- **Digitar o saldo do extrato com sinal ou vírgula trocados.** Um saldo mal digitado gera divergência fantasma — confira o valor antes de confirmar.

## Antes e depois
Antes: Cadastrar a conta bancária e registrar as baixas do período. Depois: Corrigir o que divergiu e refazer a conciliação até bater.

## Roteiro do vídeo
- Abrir Conciliação Bancária e iniciar uma nova.
- Escolher conta e período, digitar o saldo do extrato.
- Confirmar e mostrar o resultado batendo (conciliada) e um caso divergente.
