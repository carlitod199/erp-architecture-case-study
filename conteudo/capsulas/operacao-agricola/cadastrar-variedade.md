---
slug: cadastrar-variedade
titulo: "Cadastrar uma variedade"
tipo: FAZER
modulo: operacao-agricola
objetivo: cadastrar
jornada: [implantacao]
nivel: iniciante
duracao_seg: 130
rotas:
  - rota: /agro/variedades.php
    principal: true
permissoes: [agro.variedades.editar]
papeis:
  nucleo: [gestor, rt_gerente]
  consulta: [encarregado, operador]
relacoes:
  prerequisito: [cadastrar-cultura]
  proximo: [montar-fenologia-da-variedade]
  relacionado: [cadastrar-porta-enxerto]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A variedade é o catálogo por cultura que alimenta as faixas nutricionais, a colheita e a premiação. A produtividade esperada informada aqui vira a referência que pré-preenche a meta no vínculo safra × talhão.

## Como fazer
1. Abra **Variedades** e clique em **+ Nova variedade**.
2. Escolha a **cultura** e informe o **nome** (ex.: Thompson Seedless, Vitória) — obrigatórios.
3. Preencha uso (mesa, vinho, suco, mista), cor da baga, se é apirênica e o ciclo em dias, conforme tiver.
4. Informe a **produtividade esperada em kg/ha** — ela é referência e pré-preenche a meta da safra (editável).
5. Clique em **Salvar**.

## Erros comuns
- **Repetir a variedade na mesma cultura.** O nome é único por cultura, e a checagem inclui variedades inativas — se aparecer duplicidade, reative a existente em vez de recriar.
- **Achar que a produtividade esperada é imposta.** Ela é só referência: pré-preenche a meta no vínculo safra × talhão, mas você pode ajustar lá.
- **Digitar produtividade negativa.** O sistema bloqueia — produtividade nunca é negativa.
- **Cadastrar variedade sem a cultura existir.** A variedade exige uma cultura; cadastre a cultura primeiro.

## Antes e depois
Antes: Cadastrar a cultura. Depois: Montar a curva fenológica da variedade (fases desde a poda).

## Roteiro do vídeo
- Abrir Variedades e clicar em + Nova variedade.
- Escolher a cultura, nomear a variedade, marcar uso/cor/apirênica.
- Preencher a produtividade esperada em kg/ha e salvar; apontar o ícone de fenologia na linha.
