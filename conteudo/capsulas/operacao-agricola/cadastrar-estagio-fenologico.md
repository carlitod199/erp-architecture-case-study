---
slug: cadastrar-estagio-fenologico
titulo: "Cadastrar um estágio fenológico da cultura"
tipo: FAZER
modulo: operacao-agricola
objetivo: cadastrar
jornada: [implantacao]
nivel: intermediario
duracao_seg: 130
rotas:
  - rota: /agro/fenologia.php
    principal: true
permissoes: [agro.fenologia.editar]
papeis:
  nucleo: [rt_gerente, gestor]
  consulta: [encarregado]
relacoes:
  prerequisito: [cadastrar-cultura]
  proximo: [definir-periodos-fenologicos-da-safra]
  relacionado: [montar-fenologia-da-variedade]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Os estágios fenológicos são o vocabulário de fases por cultura e escala (BBCH, E-L ou própria) usado no apontamento e nas faixas nutricionais. Cadastre-os antes de definir os períodos por safra.

## Como fazer
1. Abra **Fases Fenológicas** e clique em **+ Novo estágio**.
2. Escolha a **cultura** e a **escala** (BBCH, E-L ou Própria).
3. Informe o **código** (ex.: EL-12, BBCH-65) e o **nome** do estágio (ex.: Floração plena) — obrigatórios.
4. Preencha a **ordem** de exibição para os estágios saírem na sequência certa.
5. Clique em **Salvar**.

## Erros comuns
- **Repetir o código na mesma cultura e escala.** A combinação cultura × escala × código é única (inclui inativos) — o sistema recusa duplicidade.
- **Misturar escalas sem querer.** Um código BBCH e um E-L podem coexistir; o que não pode é o mesmo código repetido dentro da mesma escala e cultura.
- **Esquecer a ordem.** Sem o número de ordem os estágios saem fora de sequência nas listas e nos selects do apontamento.
- **Confundir com a curva da variedade.** Aqui é o catálogo de estágios da cultura; a curva de dias por variedade é montada na fenologia da variedade.

## Antes e depois
Antes: Cadastrar a cultura. Depois: Definir os períodos por safra para a fase ser resolvida automaticamente pela data.

## Roteiro do vídeo
- Abrir Fases Fenológicas e clicar em + Novo estágio.
- Escolher cultura e escala, informar código, nome e ordem.
- Salvar e mostrar o estágio ordenado na tabela.
