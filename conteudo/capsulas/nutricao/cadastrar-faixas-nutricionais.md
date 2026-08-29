---
slug: cadastrar-faixas-nutricionais
titulo: "Cadastrar as faixas nutricionais que classificam os laudos"
tipo: FAZER
modulo: nutricao
objetivo: cadastrar
jornada: [continuo]
nivel: avancado
duracao_seg: 170
rotas:
  - rota: /nutricao/faixas_nutricionais.php
    principal: true
permissoes: [nutricao.faixas_nutricionais.editar]
papeis:
  nucleo: [rt_gerente, gestor]
  consulta: [agronomo, dono]
relacoes:
  proximo: [registrar-analise-de-solo, registrar-analise-foliar]
  relacionado: [cadastrar-nutrientes]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A faixa é a régua do responsável técnico: é ela que diz se um resultado de laudo está "baixo", "adequado" ou "excessivo". Sem faixa cadastrada, o VERO não inventa referência — o laudo entra sem classificação e sem alerta. Cadastrar as faixas é o que dá vida às análises de solo e foliares.

## Como fazer
1. Escolha o **tipo** (solo ou foliar) e o **nutriente** — cada faixa vale para um dos dois.
2. Preencha a **faixa ideal** (ideal mín e ideal máx) — os dois são obrigatórios.
3. Se quiser, informe **mínimo** e **máximo** para as bordas "muito baixo" e "excessivo". Vale a regra: mínimo ≤ ideal mín ≤ ideal máx ≤ máximo.
4. Se a referência muda por variedade ou fase fenológica, escolha a **variedade/fase** — a faixa passa a valer só naquele contexto.
5. Clique em **Salvar**. A partir daí, todo laudo novo é classificado contra essa faixa.

## Erros comuns
- **Faixa inconsistente.** O sistema recusa se o ideal mínimo for maior que o ideal máximo, ou se o mínimo/máximo invadir a faixa ideal. Confira a ordem dos quatro números antes de salvar.
- **Faixa duplicada para o mesmo contexto.** Já existe uma faixa ativa para aquele nutriente/variedade/fase — inative a anterior antes de cadastrar a nova, senão o salvamento é bloqueado.
- **Unidade em branco.** A faixa sem unidade classifica, mas gera confusão na leitura — informe a unidade (ppm, cmolc/dm³, g/kg...) igual à do laudo.

## Antes e depois
Antes: Ter os nutrientes cadastrados que serão medidos. Depois: Registrar as análises de solo e foliares — que passam a sair classificadas e a emitir alertas.

## Roteiro do vídeo
- Abrir Faixas Nutricionais com a lista vazia para um nutriente.
- Narrar: "A faixa é a régua do RT. Sem ela, o laudo entra sem cor."
- Cadastrar uma faixa (tipo, nutriente, ideal mín/máx), mostrar a regra dos quatro números.
- Cortar para uma Análise onde o mesmo nutriente agora aparece classificado.
