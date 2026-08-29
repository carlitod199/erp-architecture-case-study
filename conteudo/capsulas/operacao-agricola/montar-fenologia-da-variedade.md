---
slug: montar-fenologia-da-variedade
titulo: "Montar a curva fenológica de uma variedade"
tipo: FAZER
modulo: operacao-agricola
objetivo: cadastrar
jornada: [implantacao]
nivel: intermediario
duracao_seg: 180
rotas:
  - rota: /agro/variedade_fenologia.php
    principal: true
permissoes: [agro.variedades.editar]
papeis:
  nucleo: [rt_gerente, gestor]
  consulta: [encarregado]
relacoes:
  prerequisito: [cadastrar-variedade]
  relacionado: [cadastrar-estagio-fenologico, definir-periodos-fenologicos-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Aqui você define as fases da variedade contando os dias desde a poda (dia 0), com o volume de irrigação e a calda de cada fase. É a curva que a fertirrigação (IF) e a defesa (DF) usam para saber em que fase a planta está.

## Como fazer
1. No catálogo de **Variedades**, clique no ícone de folha (Fenologia) da variedade.
2. Clique em **+ Adicionar fase** e comece pela **poda (dia inicial 0)**.
3. Dê o nome da fase, o dia inicial e o **dia final** (maior que o inicial), e o volume em mm/dia e a calda em L/ha, se tiver.
4. Clique em **Salvar fase** — cada fase já fica gravada; o dia inicial sugerido da próxima é o dia final da anterior.
5. Repita até cobrir o ciclo; a faixa verde **"Fases contíguas desde a poda"** confirma que a curva está fechada.

## Erros comuns
- **Não começar no dia 0.** A primeira fase precisa começar na poda (dia 0); se começar depois, aparece o aviso de contiguidade (mas não trava).
- **Deixar lacuna ou sobreposição entre fases.** O sistema avisa "há uma LACUNA" ou "há SOBREPOSIÇÃO" quando o dia inicial de uma fase não bate com o dia final da anterior — use o dia sugerido para encaixar.
- **Dia final menor ou igual ao inicial.** É bloqueado: o dia final tem de ser maior que o inicial.
- **Confundir mm/dia com m³/ha.** A conversão é informativa: m³/ha/dia = mm/dia × 10; L/planta/dia depende da densidade e é calculado no uso, não aqui.

## Antes e depois
Antes: Cadastrar a variedade. Depois: Definir os períodos fenológicos da safra (datas reais que resolvem a fase automaticamente).

## Roteiro do vídeo
- Abrir a fenologia de uma variedade pelo ícone de folha.
- Adicionar a fase Poda (0 → X), depois Brotação encaixando no dia final anterior.
- Mostrar o aviso de lacuna e depois a faixa verde de contiguidade.
