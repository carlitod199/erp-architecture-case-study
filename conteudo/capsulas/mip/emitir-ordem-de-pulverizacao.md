---
slug: emitir-ordem-de-pulverizacao
titulo: "Emitir a Ordem de Serviço de pulverização ou fertirrigação (DF/IF)"
tipo: FAZER
modulo: mip
objetivo: emitir
jornada: [safra]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /mip/aplicacoes.php
    principal: true
tela_app: aplicacao_nova
permissoes: [mip.aplicacoes_defensivos.editar]
papeis:
  nucleo: [rt_gerente, gestor]
  consulta: [encarregado, operador]
relacoes:
  prerequisito: [tratar-alerta-fitossanitario]
  proximo: [preparar-calda-e-atribuir-aplicador]
  relacionado: [confirmar-execucao-da-aplicacao, arquivar-receituario-agronomico]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A Ordem de Serviço (DF de pulverização, IF de fertirrigação) é a prescrição numerada do responsável técnico: define válvula, produtos e doses ANTES de ir a campo. Ela nasce só como instrução — não baixa estoque nem lança custo. Isso só acontece quando a execução é confirmada. O sistema nunca recomenda produto, dose ou carência: a receita é sempre do RT.

## Como fazer
1. Abra **Pulverização** e clique em **+ Nova aplicação**.
2. Marque o modo **Emitir OS (DF/IF)** — este é o passo que transforma o registro em instrução numerada, sem baixa de estoque.
3. Escolha a válvula, a safra, o tipo e informe a **Data prevista** (a data realizada só existe na confirmação, então ela some do formulário).
4. Lance os produtos e doses da receita do RT e o bico/volume de calda quando for pulverização.
5. Clique em **Registrar aplicação** — a OS entra na fila em status "planejada", aguardando o preparo e a execução.

## Erros comuns
- **Emitir OS sem a Data prevista.** O sistema trava: na emissão a data realizada não existe, então a prevista é obrigatória. Informe a data em que o serviço deve acontecer.
- **Confundir emitir OS com registro direto.** Se você quer instruir um serviço que ainda vai acontecer, use Emitir OS. Se a aplicação já foi feita, use o registro direto — só ele baixa estoque na hora.
- **Esperar que a OS baixe estoque.** A emissão não toca no estoque nem no custeio; isso é intencional. A baixa por FEFO e o custo entram só na confirmação da execução.

## Antes e depois
Antes: Tratar o alerta fitossanitário que motivou a decisão de controle. Depois: Preparar a calda e atribuir o aplicador e o trator/pulverizador à OS.

## Roteiro do vídeo
- Abrir Pulverização com a fila de aplicações visível.
- Clicar em + Nova aplicação e destacar as duas opções de modo.
- Marcar **Emitir OS (DF/IF)** e narrar: "Aqui é só a instrução do RT. Nada de estoque ainda."
- Preencher válvula, safra, data prevista, produtos e doses.
- Destacar **Registrar aplicação**; clicar e mostrar a OS entrando na fila como planejada.
