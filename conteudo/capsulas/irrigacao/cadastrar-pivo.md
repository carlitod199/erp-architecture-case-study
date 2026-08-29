---
slug: cadastrar-pivo
titulo: "Cadastrar um pivô ou sistema de irrigação"
tipo: FAZER
modulo: irrigacao
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 120
rotas:
  - rota: /irrigacao/painel.php
    principal: true
permissoes: [irrigacao.pivos.editar]
papeis:
  nucleo: [gestor, encarregado, rt_gerente]
  consulta: [operador, dono]
relacoes:
  proximo: [registrar-apontamento-de-irrigacao, planejar-irrigacao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O pivô (ou sistema de irrigação) é a máquina de água da fazenda. Cadastrá-lo dá ao VERO onde pendurar os apontamentos e planejamentos de rega e mostra o pulso dos últimos 30 dias (horas, lâmina, consumos). É o cadastro-base para acompanhar a irrigação por equipamento.

## Como fazer
1. Na tela de **Pivôs e Sistemas de Irrigação**, clique em **+ Novo pivô**.
2. Informe o **nome** do pivô/sistema — é o único campo obrigatório.
3. Escolha a **fazenda** e informe a **área (ha)** atendida.
4. Clique em **Salvar**. O pivô passa a aparecer no painel com seus KPIs de 30 dias.

## Erros comuns
- **Salvar sem nome.** O nome é obrigatório — sem ele o cadastro é recusado.
- **Tentar excluir um pivô em uso.** Se o pivô já tem apontamentos ou planejamentos, a exclusão é bloqueada. Deixe-o cadastrado ou remova os lançamentos primeiro.
- **Duplicar o mesmo sistema.** Antes de criar, confira a lista — pivôs repetidos dividem o histórico e bagunçam os KPIs.

## Antes e depois
Antes: Ter a fazenda cadastrada. Depois: Registrar apontamentos de irrigação e planejar a lâmina por esse pivô.

## Roteiro do vídeo
- Abrir o painel de Pivôs e clicar em + Novo pivô.
- Preencher nome, fazenda e área; salvar.
- Mostrar o pivô novo na lista com os KPIs de 30 dias zerados.
