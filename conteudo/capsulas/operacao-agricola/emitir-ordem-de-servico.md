---
slug: emitir-ordem-de-servico
titulo: "Emitir uma Ordem de Serviço para o campo executar"
tipo: FAZER
modulo: operacao-agricola
objetivo: planejar
jornada: [safra]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /agro/atividades.php
    principal: true
  - rota: /agro/ordens_servico.php
tela_app: planejamento_atividades
permissoes: [agro.planejamento_atividades.editar]
papeis:
  nucleo: [encarregado, gestor, rt_gerente]
  consulta: [operador]
relacoes:
  prerequisito: [abrir-safra-e-confirmar-poda]
  proximo: [finalizar-apontamento]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
No VERO a Ordem de Serviço nasce da atividade planejada — uma para cada. Você não cria a OS solta: você planeja o trabalho no **Planejamento de Atividades** e a OS aparece numerada sozinha na fila.

## Como fazer
1. Abra **Agrícola → Planejamento de Atividades**.
2. Clique em **Nova atividade** e descreva o trabalho: tipo (poda, raleio, trato…), válvula, data planejada e responsável.
3. **Salve a atividade** — este é o passo que **emite a Ordem de Serviço** numerada, 1:1 com a atividade.
4. Confira em **Ordens de Serviço** que a OS entrou na fila com o número gerado.
5. Se precisar, imprima a OS de campo pelo botão de impressão da fila.

## Erros comuns
- **Procurar um botão de criar OS na tela de Ordens de Serviço.** Não existe: aquela tela é só a fila de leitura. Toda criação, edição e mudança de status acontece na atividade, e a OS acompanha sozinha.
- **Mudar o status direto na OS.** O status vem da atividade. Para colocar em execução ou concluir, edite a atividade — a OS reflete na hora.
- **Planejar a atividade na válvula errada.** A OS herda a válvula da atividade e é ali que o custo vai cair depois. Confira a válvula e a data antes de salvar.

## Antes e depois
Antes: Abrir a safra e confirmar a poda das válvulas (o dia 0). Depois: Finalizar o apontamento que executa a OS e gera o custo.

## Roteiro do vídeo
- Abrir Ordens de Serviço e mostrar a fila; narrar: "Repare que aqui não tem botão de criar. A OS não nasce nesta tela."
- Cortar para Planejamento de Atividades e clicar em Nova atividade.
- Preencher tipo, válvula, data e responsável.
- Destacar o botão **Salvar** antes de clicar; clicar.
- Voltar para Ordens de Serviço e mostrar a OS nova, já numerada, no topo da fila.
