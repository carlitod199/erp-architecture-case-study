---
slug: planejar-irrigacao
titulo: "Planejar a lâmina de irrigação de uma válvula"
tipo: FAZER
modulo: irrigacao
objetivo: planejar
jornada: [safra]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /irrigacao/planejamento_irrigacao.php
    principal: true
permissoes: [irrigacao.planejamento_irrigacao.editar]
papeis:
  nucleo: [rt_gerente, gestor, encarregado]
  consulta: [operador, dono]
relacoes:
  prerequisito: [cadastrar-pivo]
  proximo: [registrar-apontamento-de-irrigacao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O planejamento define a lâmina-alvo (mm) que uma válvula deve receber em um período. É a meta contra a qual o realizado será confrontado em Planejado vs Realizado. A lâmina é decisão do responsável — o sistema não recomenda valores, só guarda o que você definir.

## Como fazer
1. Escolha a **válvula** e informe a **lâmina-alvo (mm)** — precisa ser maior que zero.
2. Defina o **início** (obrigatório) e, se houver, o **fim** do período.
3. Se quiser controlar a captação, informe as **horas de irrigação** e a **vazão (m³/h)** — ao escolher a válvula, a vazão da bomba é sugerida, e você pode ajustar.
4. Escolha o **pivô** vinculado, se aplicável.
5. Clique em **Salvar**. O planejamento passa a ser a régua do confronto planejado × realizado.

## Erros comuns
- **Lâmina zero ou início em branco.** O salvamento é recusado — válvula, lâmina maior que zero e início são obrigatórios.
- **Fim antes do início.** O sistema não aceita período invertido; confira as datas.
- **Vazão ou horas negativas.** Não são aceitas. A vazão sugerida vem da bomba da válvula — só ajuste se souber o valor real.

## Antes e depois
Antes: Ter as válvulas e o pivô cadastrados. Depois: Registrar os apontamentos e acompanhar o desvio em Planejado vs Realizado.

## Roteiro do vídeo
- Abrir Planejamento de Irrigação e criar um plano.
- Escolher a válvula e mostrar a vazão da bomba sendo sugerida.
- Preencher lâmina-alvo e período; salvar.
- Apontar onde o realizado vai ser confrontado.
