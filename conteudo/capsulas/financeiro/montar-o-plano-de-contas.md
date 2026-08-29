---
slug: montar-o-plano-de-contas
titulo: "Montar o plano de contas"
tipo: FAZER
modulo: financeiro
objetivo: estruturar
jornada: [continuo]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /financeiro/plano_contas.php
    principal: true
tela_app: plano_contas
permissoes: [financeiro.plano_contas.editar]
papeis:
  nucleo: [financeiro, gestor]
  consulta: [dono]
relacoes:
  relacionado: [cadastrar-um-centro-de-custo]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O plano de contas é a árvore que classifica cada valor como receita ou despesa e o organiza em grupos e subgrupos. É ele que dá nome ao dinheiro nos relatórios financeiros e no DRE. Contas-pai agrupam; as contas-folha (as pontas da árvore) são as que **aceitam lançamento**.

## Como fazer
1. Abra **Plano de Contas** e clique em **+ Nova conta**.
2. Informe **código** e **nome** e escolha o **tipo** (receita ou despesa).
3. Para criar uma subconta, escolha a **conta pai** — o nível é calculado sozinho e o **tipo é herdado do pai**.
4. Marque **aceita lançamento** nas contas-folha; deixe desmarcado nas que só agrupam.
5. Clique em **Salvar**. O código precisa ser **único** em todo o plano.

## Erros comuns
- **Lançar em conta que só agrupa.** Contas-pai servem para somar; quem recebe valor é a folha. Deixe "aceita lançamento" só nas pontas.
- **Repetir código.** O código é a chave da conta — se já existe, o sistema recusa.
- **Tentar excluir uma conta com filhas ou lançamentos.** O sistema não apaga: ele **inativa**, para não quebrar o histórico nem os relatórios.

## Antes e depois
Antes: Definir com o cliente a estrutura de receitas e despesas. Depois: Classificar despesas e recebíveis usando as contas-folha.

## Roteiro do vídeo
- Abrir Plano de Contas e criar uma conta-pai "Despesas operacionais".
- Criar uma subconta-folha e mostrar o tipo herdado e o nível calculado.
- Tentar excluir a conta-pai e mostrar que ela é inativada, não apagada.
