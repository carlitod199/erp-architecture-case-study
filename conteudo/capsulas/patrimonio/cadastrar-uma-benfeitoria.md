---
slug: cadastrar-uma-benfeitoria
titulo: "Cadastrar uma benfeitoria para incorporá-la ao patrimônio"
tipo: FAZER
modulo: patrimonio
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 140
rotas:
  - rota: /patrimonio/benfeitorias.php
    principal: true
permissoes: [patrimonio.benfeitorias.editar]
papeis:
  nucleo: [patrimonio, gestor]
  consulta: [dono]
relacoes:
  relacionado: [cadastrar-um-bem-patrimonial]
  proximo: [gerar-a-depreciacao-gerencial]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Benfeitorias são as construções e melhorias fixas da fazenda — galpões, cercas, sistemas de irrigação, casas. Cadastrá-las incorpora esse valor ao patrimônio e faz elas entrarem na depreciação gerencial com a vida útil padrão da categoria (mais longa que a de máquinas).

## Como fazer
1. Abra **Benfeitorias** — esta tela já traz a categoria Benfeitorias fixada.
2. Clique em **Nova benfeitoria** e informe a **descrição**.
3. Preencha o **valor de aquisição** (maior que zero) e a **data de aquisição**.
4. Ajuste a **vida útil** e o **valor residual** se o padrão não servir.
5. Clique em **Salvar** — a benfeitoria entra no patrimônio e passa a depreciar.

## Erros comuns
- **Deixar o valor de aquisição em branco.** É obrigatório e maior que zero; sem ele não há base para depreciar.
- **Colocar valor residual negativo.** O residual nunca é negativo — é o quanto o bem ainda valerá ao fim da vida útil.
- **Excluir uma benfeitoria já depreciada.** O sistema inativa (dá baixa) em vez de apagar, para não perder o histórico das depreciações lançadas.

## Antes e depois
Antes: ter o valor e a data da construção/melhoria. Depois: gerar a depreciação gerencial da competência para a benfeitoria virar custo.

## Roteiro do vídeo
- Abrir Benfeitorias com a categoria já fixada.
- Narrar: "Galpão, cerca, irrigação — tudo que é melhoria fixa entra no patrimônio por aqui."
- Cadastrar uma benfeitoria com valor e data de aquisição.
- Salvar e mostrar a vida útil padrão da categoria.
