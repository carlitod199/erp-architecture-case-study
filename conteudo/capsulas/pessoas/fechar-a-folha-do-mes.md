---
slug: fechar-a-folha-do-mes
titulo: "Fechar a folha do mês para o custo de mão de obra entrar no custeio"
tipo: FAZER
modulo: pessoas
objetivo: fechar
jornada: [continuo]
nivel: intermediario
duracao_seg: 180
rotas:
  - rota: /pessoas/folha.php
    principal: true
permissoes: [pessoas.folha.editar]
papeis:
  nucleo: [rh, financeiro, gestor]
  consulta: [dono]
relacoes:
  prerequisito: [cadastrar-um-colaborador]
  relacionado: [cadastrar-regra-de-premiacao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A folha calcula salário, encargos e descontos dos colaboradores CLT de uma competência. Fechar a folha é o passo que transforma salário + encargos em custo de mão de obra dentro do custeio — enquanto ela fica aberta, esse custo ainda não conta para a safra.

## Como fazer
1. Abra **Folha** e crie o **período** informando a competência (mês/ano).
2. Clique em **Gerar lançamentos** — o sistema calcula um lançamento por colaborador CLT com salário, encargos, descontos e as premiações do mês já somadas ao bruto.
3. Confira os valores por colaborador na lista do período.
4. Clique em **Fechar período** — este é o passo que **emite o custo de salário + encargos ao custeio**.
5. Se precisar corrigir depois, use **Reabrir**: o custeio da folha é removido e será reemitido no próximo fechamento.

## Erros comuns
- **Gerar sem encargos vigentes.** O sistema barra a geração se não houver configuração de Encargos CLT válida para a competência. Cadastre em Pessoas → Encargos CLT antes.
- **Gerar antes de lançar as premiações do mês.** As premiações entram no bruto no momento da geração. Se apontar depois, gere de novo para incluí-las (regerar recalcula o período aberto).
- **Tentar excluir um período fechado.** Não é permitido — reabra o período primeiro. E lembre: só entram na folha colaboradores CLT com salário mensal informado.

## Antes e depois
Antes: cadastrar colaboradores CLT com salário e configurar os encargos vigentes. Depois: conferir o custo de mão de obra no custeio da safra.

## Roteiro do vídeo
- Abrir Folha e criar o período da competência atual.
- Narrar: "A folha vira custo da fazenda só quando a gente fecha o período."
- Gerar os lançamentos e percorrer bruto, encargos e líquido de um colaborador.
- Destacar o botão Fechar período antes de clicar; clicar.
- Cortar para o custeio mostrando o custo de mão de obra lançado.
