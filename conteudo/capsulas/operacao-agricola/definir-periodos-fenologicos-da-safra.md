---
slug: definir-periodos-fenologicos-da-safra
titulo: "Definir os períodos fenológicos de uma safra"
tipo: FAZER
modulo: operacao-agricola
objetivo: planejar
jornada: [safra]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /agro/fenologia.php
    principal: true
permissoes: [agro.fenologia.editar]
papeis:
  nucleo: [rt_gerente, gestor]
  consulta: [encarregado]
relacoes:
  prerequisito: [cadastrar-estagio-fenologico]
  relacionado: [abrir-safra-e-confirmar-poda]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Os períodos ligam cada estágio a datas reais da safra (ex.: poda 01–10/07, brotação 11–25/07). Com eles, a fase da planta passa a ser resolvida automaticamente pela data no apontamento, na DF e na IF — sem escolher a fase na mão.

## Como fazer
1. Abra **Fases Fenológicas** e, no bloco **Períodos por safra**, escolha a **safra**.
2. Selecione o **estágio** e o **escopo** — safra inteira ou um talhão específico.
3. Informe a **data de início** e a **data de fim** do período.
4. Clique em **Adicionar período** — a fase daquela data passa a ser resolvida automaticamente.
5. Repita para cobrir o ciclo; períodos de talhão vencem os de safra inteira.

## Erros comuns
- **Início depois do fim.** O sistema recusa período com data inicial maior que a final.
- **Escolher um talhão que não é da safra.** O talhão precisa estar vinculado à safra escolhida; caso contrário aparece "o talhão selecionado não é desta safra".
- **Ignorar o aviso de sobreposição.** Sobrepor períodos no mesmo escopo não trava, mas na resolução automática vale o mais específico e o mais recente — confira se é isso que você quer.
- **Deixar datas sem cobertura.** Sem período para uma data, a fase volta a ser manual naquele dia.

## Antes e depois
Antes: Cadastrar os estágios fenológicos da cultura. Depois: Apontar em campo com a fase já resolvida pela data.

## Roteiro do vídeo
- Abrir Fases Fenológicas e selecionar a safra no bloco de períodos.
- Adicionar poda 01–10/07 (safra inteira) e brotação 11–25/07.
- Mostrar o aviso de sobreposição e explicar a regra do mais específico/recente.
