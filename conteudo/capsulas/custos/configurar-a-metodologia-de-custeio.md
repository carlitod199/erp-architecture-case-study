---
slug: configurar-a-metodologia-de-custeio
titulo: "Configurar a metodologia de custeio"
tipo: FAZER
modulo: custos
objetivo: configurar
jornada: [continuo]
nivel: avancado
duracao_seg: 200
rotas:
  - rota: /custeio/metodologias.php
    principal: true
tela_app: metodologias
permissoes: [custeio.metodologias.editar]
papeis:
  nucleo: [gestor, rt_gerente, financeiro]
  consulta: [dono]
relacoes:
  relacionado: [montar-o-orcamento-de-producao, parametrizar-a-cultura-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A metodologia define como o custo de produção é organizado e de onde cada valor realizado é puxado. Ela tem grupos (variável, fixo, operacional) e, dentro deles, itens que apontam para uma origem de custo do sistema. É o esqueleto que o orçamento de produção usa para gerar itens e que a derivação do realizado segue para saber o que entra em cada linha.

## Como fazer
1. Abra **Metodologias** e crie uma **metodologia**: nome e **tipo de ciclo** (anual ou perene).
2. Dentro dela, adicione **grupos** — cada grupo tem um tipo: variável, fixo ou operacional.
3. Em cada grupo, adicione **itens** informando **nome, método de cálculo e origem** (de onde o realizado vem: aplicação, apontamento, folha, irrigação…).
4. Ao salvar o item, o VERO checa o **mapa do realizado**: se a origem/categoria já estiver coberta por outro item, ele avisa — e se a sobreposição for exata, **bloqueia** para não contar o mesmo custo duas vezes.
5. Deixe ativa a metodologia que vai ser usada. A seed "Padrão VERO" é editável e pode ser inativada — nunca é obrigatória.

## Erros comuns
- **Dois itens puxando a mesma origem.** O mesmo custo entraria duas vezes. Quando a sobreposição é exata o sistema bloqueia; quando é parcial, ele só avisa — leia o aviso e ajuste.
- **Inativar uma metodologia com orçamento aberto.** O sistema não deixa: feche ou cancele os orçamentos que a usam antes de inativá-la.
- **Confundir tipo de ciclo.** Anual e perene mudam como o custo de formação é tratado — escolha conforme a cultura, não no chute.

## Antes e depois
Antes: Definir com o cliente quais grupos e origens compõem o custo. Depois: Usar a metodologia para montar o orçamento de produção e derivar o realizado.

## Roteiro do vídeo
- Criar uma metodologia anual e um grupo variável.
- Adicionar um item com origem "aplicação" e salvar.
- Tentar um segundo item na mesma origem e mostrar o aviso/bloqueio do mapa.
