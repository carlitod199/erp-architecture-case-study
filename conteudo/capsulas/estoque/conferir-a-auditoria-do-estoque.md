---
slug: conferir-a-auditoria-do-estoque
titulo: "Conferir a auditoria de consistência do estoque"
tipo: CONSULTAR
modulo: estoque
objetivo: conferir
jornada: [continuo]
nivel: intermediario
duracao_seg: 120
rotas:
  - rota: /estoque/auditoria.php
    principal: true
permissoes: [estoque.auditoria.ver]
papeis:
  nucleo: [gestor, almoxarife, rt_gerente]
  consulta: [estoquista, dono]
relacoes:
  relacionado: [ajustar-ou-estornar-movimentacao-de-estoque, fazer-inventario-do-almoxarifado]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A Auditoria de Estoque é uma tela só de leitura que roda um conjunto de checagens de consistência com severidade (crítico, atenção, baixa) — por exemplo, saldo consolidado que não bate com a soma das movimentações. Ela não grava nada: aponta os achados para você corrigir nas telas próprias.

## Como fazer
1. Abra **Auditoria de Estoque** e leia o placar geral no topo.
2. Priorize as checagens **críticas** — são as que indicam saldo inconsistente de verdade.
3. Abra cada checagem para ver os itens afetados (produto, almoxarifado, diferença).
4. Corrija na tela certa: ajuste ou estorno no Histórico, contagem no Inventário, ou a tela de origem do documento.

## Antes e depois
Antes: Rodar a auditoria periodicamente (pré e pós-homologação). Depois: Corrigir os achados nas telas de origem e rodar de novo até o placar zerar as críticas.

## Roteiro do vídeo
- Abrir a Auditoria e mostrar o placar por severidade.
- Expandir uma checagem crítica e ler os itens.
- Narrar "a correção acontece na tela própria, não aqui".
- Mostrar o caminho para o Histórico/Inventário e o placar melhorando depois.
