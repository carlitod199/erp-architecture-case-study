---
slug: planejar-atividade
titulo: "Planejar uma atividade no talhão"
tipo: FAZER
modulo: operacao-agricola
objetivo: planejar
jornada: [safra]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /agro/atividades.php
    principal: true
permissoes: [agro.planejamento_atividades.editar]
papeis:
  nucleo: [gestor, rt_gerente, encarregado]
  consulta: [operador, dono]
relacoes:
  prerequisito: [cadastrar-tipo-de-atividade]
  proximo: [emitir-ordem-de-servico, finalizar-apontamento]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A atividade planejada é o que organiza o trabalho por talhão e safra e gera automaticamente a Ordem de Serviço numerada. O realizado (área e custo) vem sozinho dos apontamentos que citam a atividade — aqui você define o previsto.

## Como fazer
1. Abra **Planejamento de Atividades** e clique em **+ Nova atividade**.
2. Escreva a **descrição** e escolha o **talhão** — obrigatórios; selecione o tipo de atividade e a safra.
3. Informe data planejada, responsável, área prevista e custo previsto (informativos).
4. Adicione os **insumos planejados** (o custo médio atual é sugerido, editável) — eles alimentam a reserva orientativa do estoque.
5. Clique em **Salvar** — a atividade nasce "planejada" e a OS-espelho é sincronizada.

## Erros comuns
- **Salvar sem descrição ou sem talhão.** Ambos são obrigatórios; sem eles a atividade não é criada.
- **Esperar o realizado aparecer sem apontamento.** Área e custo realizados derivam dos apontamentos vinculados à atividade — se ninguém apontou citando ela, o realizado fica zerado.
- **Achar que o custo previsto trava o gasto.** Ele é informativo: não bloqueia apontamentos; serve só para o comparativo previsto × realizado.
- **Área ou custo previsto negativos.** São bloqueados — não podem ser menores que zero.

## Antes e depois
Antes: Cadastrar o tipo de atividade. Depois: Imprimir a Ordem de Serviço e, depois da execução, finalizar os apontamentos.

## Roteiro do vídeo
- Abrir Planejamento de Atividades e criar uma nova.
- Preencher descrição, talhão, tipo e data; adicionar um insumo planejado.
- Salvar e mostrar a OS sincronizada e a linha com previsto × realizado.
