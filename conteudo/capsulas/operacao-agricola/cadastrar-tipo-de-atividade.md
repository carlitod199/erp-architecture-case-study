---
slug: cadastrar-tipo-de-atividade
titulo: "Cadastrar um tipo de atividade"
tipo: FAZER
modulo: operacao-agricola
objetivo: cadastrar
jornada: [implantacao]
nivel: iniciante
duracao_seg: 140
rotas:
  - rota: /agro/tipos_atividade.php
    principal: true
permissoes: [agro.tipos_atividade.editar]
papeis:
  nucleo: [gestor, rt_gerente, encarregado]
  consulta: [operador]
relacoes:
  proximo: [definir-parametros-de-rendimento]
  relacionado: [planejar-atividade]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O tipo de atividade é o catálogo de operações do campo (Poda, Raleio, Colheita, Embalamento) que sustenta o apontamento e a premiação. A categoria e a marcação "exige produção" definem como a atividade alimenta colheita, metas e custo.

## Como fazer
1. Abra **Tipos de Atividade** e clique em **+ Novo tipo**.
2. Informe o **nome** e escolha a **categoria** (trato cultural, colheita, aplicação, irrigação, packing ou outro) — obrigatórios.
3. Escolha a **unidade padrão de produção** (planta, caixa, kg, ha, cacho, fila…) quando fizer sentido.
4. Defina se **exige quantidade produzida** — Sim faz a atividade alimentar colheita e metas.
5. Marque as **culturas** em que se aplica (nenhuma marcada = vale para todas) e clique em **Salvar**.

## Erros comuns
- **Repetir o nome do tipo.** O nome é único por tenant e a checagem inclui inativos — se der duplicidade, reative o tipo existente.
- **Marcar "exige produção" sem definir a unidade.** Atividades que exigem produção sem unidade padrão deixam o apontamento sem base de medida — defina a unidade.
- **Não marcar nenhuma cultura sem querer.** Sem cultura marcada, o tipo vale para todas — se você quis restringir, marque as culturas certas.
- **Esperar que colheita e metas apareçam de tipos "Não exige produção".** Só os tipos com exige produção = Sim alimentam colheita e metas; os demais alimentam apenas operação e custo.

## Antes e depois
Antes: Ter as culturas cadastradas. Depois: Definir os parâmetros de rendimento (mão de obra) deste tipo de atividade.

## Roteiro do vídeo
- Abrir Tipos de Atividade e clicar em + Novo tipo.
- Nomear (ex.: Poda), escolher categoria e unidade padrão, marcar exige produção.
- Marcar culturas e salvar; explicar "sem cultura = todas".
