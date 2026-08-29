---
slug: lancar-no-livro-caixa
titulo: "Lançar no livro caixa do produtor a partir do razão pago"
tipo: FAZER
modulo: fiscal
objetivo: registrar
jornada: [continuo]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /fiscal/livro.php
    principal: true
permissoes: [fiscal.livro_caixa_produtor.editar]
papeis:
  nucleo: [fiscal, contador, gestor]
  consulta: [dono]
relacoes:
  relacionado: [lancar-um-documento-fiscal]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O livro caixa do produtor reúne entradas e saídas com saldo corrente, base para a atividade rural. Além do lançamento manual, ele pode ser gerado a partir do razão já pago do período — sem duplicar o que já foi lançado. A apuração oficial (IRPF da atividade rural) continua sendo da contabilidade.

## Como fazer
1. Abra **Livro caixa**.
2. Para um lançamento avulso: informe **data**, **histórico**, **tipo** (entrada ou saída) e **valor** (maior que zero) e clique em **Salvar**.
3. Para trazer do financeiro: use **Gerar do razão** e informe o **período** (início e fim).
4. O sistema cria um lançamento para cada movimentação **paga** do período; o que já foi gerado antes é **pulado** (não duplica).
5. Confira o **saldo corrente** e imprima o livro quando precisar.

## Erros comuns
- **Gerar o mesmo período duas vezes.** Não há problema: a geração é idempotente e marca cada lançamento com a origem do razão. Ela avisa quantos foram criados e quantos já existiam.
- **Esperar movimentação não paga aparecer.** A geração só traz o que está **pago** no período. Baixe os títulos no financeiro antes de gerar.
- **Confundir o livro com a apuração fiscal.** Esta tela organiza entradas e saídas; a apuração oficial do IRPF rural é feita pela contabilidade a partir daqui.

## Antes e depois
Antes: ter as movimentações do período pagas no financeiro. Depois: conferir o saldo e entregar o livro à contabilidade.

## Roteiro do vídeo
- Abrir Livro caixa com saldo corrente à mostra.
- Narrar: "O livro reúne entradas e saídas — e boa parte dele vem pronto do razão que você já pagou."
- Usar Gerar do razão informando o mês.
- Mostrar a mensagem de quantos foram criados e quantos já existiam.
- Fechar no saldo atualizado.
