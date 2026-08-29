---
slug: ler-o-fluxo-de-caixa
titulo: "Ler o fluxo de caixa"
tipo: CONSULTAR
modulo: financeiro
objetivo: ler
jornada: [continuo]
nivel: iniciante
duracao_seg: 110
rotas:
  - rota: /financeiro/fluxo_caixa.php
    principal: true
tela_app: fluxo_caixa
permissoes: [financeiro.fluxo_caixa.ver]
papeis:
  nucleo: [financeiro, gestor]
  consulta: [dono, encarregado]
relacoes:
  relacionado: [baixar-um-titulo, receber-um-titulo, lancar-uma-conta-a-receber]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O fluxo de caixa mostra o dinheiro entrando e saindo ao longo do ano em duas camadas: o **realizado** (o que já foi pago e recebido, por data de pagamento) e o **previsto** (títulos em aberto, por data de vencimento). É a leitura que responde "vou ter caixa nos próximos meses?".

## Como fazer
1. Abra **Fluxo de Caixa** e escolha o **ano**.
2. Leia os KPIs no topo e os gráficos de entradas e saídas por mês.
3. Distinga as duas camadas: **realizado** vem das baixas; **previsto** vem dos títulos em aberto pelo vencimento.
4. Confira o bucket **Sem vencimento** — títulos abertos sem data caem ali e ficam de fora da projeção mensal até ganharem vencimento.

## Erros comuns
- **Achar que previsto é dinheiro garantido.** Previsto é só título em aberto pelo vencimento — só vira caixa quando é baixado. Trate como projeção, não como saldo.
- **Ignorar o "Sem vencimento".** Título aberto sem vencimento não entra na projeção do mês; dê vencimento a ele na tela de origem para que apareça no fluxo.

## Antes e depois
Antes: Lançar e baixar os títulos que alimentam o fluxo. Depois: Decidir compras e pagamentos com base na projeção de caixa.

## Roteiro do vídeo
- Abrir Fluxo de Caixa e trocar o ano.
- Narrar a diferença entre realizado e previsto nos gráficos.
- Apontar o bucket Sem vencimento e explicar por que ele fica fora da projeção.
