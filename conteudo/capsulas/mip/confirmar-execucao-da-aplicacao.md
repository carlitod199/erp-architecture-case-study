---
slug: confirmar-execucao-da-aplicacao
titulo: "Confirmar a execução de uma OS emitida (baixar estoque e custo)"
tipo: FAZER
modulo: mip
objetivo: confirmar
jornada: [safra]
nivel: intermediario
duracao_seg: 175
rotas:
  - rota: /mip/aplicacoes.php
    principal: true
tela_app: aplicacao_confirmar
permissoes: [mip.aplicacoes_defensivos.editar]
papeis:
  nucleo: [encarregado, operador, rt_gerente]
  consulta: [gestor]
relacoes:
  prerequisito: [emitir-ordem-de-pulverizacao]
  proximo: [validar-aplicacao-como-rt]
  relacionado: [preparar-calda-e-atribuir-aplicador]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A OS emitida é só uma instrução. Confirmar a execução é o passo que torna a aplicação real: você informa as quantidades efetivamente consumidas, a data, as horas e o clima. É nessa confirmação que o estoque é baixado por FEFO e o custo entra na safra. Sem confirmar, a DF/IF fica parada como planejada e nada disso acontece.

## Como fazer
1. Na fila de **Pulverização**, ache a OS em status planejada e clique em **Confirmar execução**.
2. Informe a **Data realizada** e, se houver, as horas de início e término (o término não pode ser antes do início).
3. Ajuste as **quantidades reais** de cada produto — elas vêm pré-preenchidas com as previstas, mas o que vale é o que foi consumido.
4. Identifique **pelo menos um operador** no bloco Operadores/EPI — é exigência de certificação e a confirmação não passa sem isso.
5. Clique em **Confirmar execução** — este é o passo que baixa o estoque por FEFO e lança o custeio; a OS passa a "registrada".

## Erros comuns
- **Confirmar sem nenhum operador.** A confirmação exige pelo menos um operador identificado no bloco Operadores/EPI. Sem isso ela é recusada — informe quem aplicou.
- **Hora de término antes da de início.** O sistema bloqueia horários invertidos. Revise as horas antes de confirmar.
- **Confirmar uma OS sem produtos.** Documento sem itens não pode ser confirmado. Volte à emissão, lance os produtos e só então confirme.
- **Tentar confirmar algo que não está planejado.** Só documentos emitidos (aguardando execução) podem ser confirmados. Registro direto e aplicações já registradas seguem outro caminho.

## Antes e depois
Antes: Emitir a OS de pulverização/fertirrigação. Depois: Deixar o RT validar a aplicação registrada.

## Roteiro do vídeo
- Abrir Pulverização com uma OS planejada na fila.
- Clicar em Confirmar execução e mostrar o formulário.
- Ajustar as quantidades reais e preencher data, horas e clima.
- Destacar o bloco Operadores/EPI e narrar: "Sem pelo menos um operador aqui, não confirma."
- Clicar em **Confirmar execução**; cortar para o estoque baixando e o custo na safra.
