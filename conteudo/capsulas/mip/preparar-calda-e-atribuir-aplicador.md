---
slug: preparar-calda-e-atribuir-aplicador
titulo: "Preparar a calda: atribuir aplicador e trator e confirmar a OS"
tipo: FAZER
modulo: mip
objetivo: preparar
jornada: [safra]
nivel: iniciante
duracao_seg: 160
rotas:
  - rota: /mip/preparo_calda.php
    principal: true
tela_app: preparo_calda
permissoes: [mip.aplicacoes_defensivos.editar]
papeis:
  nucleo: [encarregado, operador]
  consulta: [rt_gerente, gestor]
relacoes:
  prerequisito: [emitir-ordem-de-pulverizacao]
  proximo: [confirmar-execucao-da-aplicacao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Esta é a visão do preparador de calda. O RT emite a OS de pulverização/fertirrigação com produtos e doses, mas sem operador nem máquina. Na hora do preparo, você abre a fila do dia, escolhe a OS, atribui o aplicador (tratorista) e o trator/pulverizador numerado e confirma. Essa confirmação é a mesma da execução: baixa o estoque por FEFO e lança o custeio.

## Como fazer
1. Abra **Preparo de Calda** e ajuste a data em "OS previstas até" para ver a fila do dia (inclui as atrasadas e as sem data).
2. Na OS que vai preparar, clique em **Preparar** para abrir o painel de atribuição.
3. Escolha o **trator** (obrigatório) e, se usar, o pulverizador/implemento.
4. Escolha o **aplicador principal** — pelo menos um operador é exigência de certificação.
5. Ajuste as quantidades reais da calda, se o preparo consumiu diferente do previsto, e clique em **Atribuir e confirmar preparo** — este é o passo que tira a OS da fila, baixa o estoque e lança o custo.

## Erros comuns
- **Confirmar sem trator ou sem aplicador.** O painel exige o trator e pelo menos um aplicador. Sem eles a confirmação é recusada — a certificação depende de quem aplicou.
- **Procurar a OS na fila e não achar.** A fila só mostra OS emitidas (planejadas). Se a aplicação já foi confirmada ou é registro direto, ela não aparece aqui.
- **Deixar as quantidades previstas quando o preparo gastou outra coisa.** Os valores vêm pré-preenchidos com os previstos, mas é o número que você confirma que baixa do estoque. Ajuste para o consumo real.

## Antes e depois
Antes: Emitir a OS de pulverização/fertirrigação (o RT define produtos e doses). Depois: Detalhar clima, tríplice lavagem e EPI em Pulverização, na confirmação da execução, se ainda faltar.

## Roteiro do vídeo
- Abrir Preparo de Calda com algumas OS na fila, uma marcada como atrasada.
- Narrar: "O RT já mandou a receita. Aqui o preparador põe quem aplica e com qual máquina."
- Clicar em Preparar numa OS e abrir o painel.
- Escolher trator e aplicador; ajustar uma quantidade de calda.
- Destacar **Atribuir e confirmar preparo**; clicar e mostrar a OS saindo da fila.
