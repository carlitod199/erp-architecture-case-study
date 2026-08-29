---
slug: tratar-alertas-de-estoque-critico
titulo: "Tratar os alertas de estoque crítico"
tipo: FAZER
modulo: estoque
objetivo: acompanhar
jornada: [continuo]
nivel: iniciante
duracao_seg: 130
rotas:
  - rota: /estoque/alertas.php
    principal: true
permissoes: [estoque.estoque_critico.editar]
papeis:
  nucleo: [estoquista, almoxarife, encarregado]
  consulta: [gestor, comprador, dono]
relacoes:
  relacionado: [bloquear-lote-e-rastrear-validade, abrir-uma-solicitacao-de-compra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A tela de Alertas junta numa fila os avisos de estoque: item abaixo do mínimo e produto perto do vencimento. Eles são reemitidos automaticamente a cada movimentação enquanto a condição existir — então "sumir da tela" só acontece de verdade quando o problema é resolvido no estoque.

## Como fazer
1. Abra **Alertas de Estoque** e leia a fila (mínimo e vencimento).
2. Para sinalizar que já viu e vai cuidar, clique em **Reconhecer**.
3. Resolvida a causa (comprou, transferiu, deu baixa do vencido), clique em **Resolver**.
4. Confira: se a condição persistir, o alerta volta na próxima movimentação — isso é esperado.

## Erros comuns
- **Resolver o alerta sem resolver a causa.** Marcar "resolvido" não repõe o estoque nem descarta o vencido; na primeira movimentação seguinte o alerta reaparece. Trate o estoque, não só o aviso.
- **Confundir reconhecer com resolver.** Reconhecer é "estou ciente"; resolver é "a condição acabou". Use reconhecer quando ainda vai agir e resolver só depois de agir.
- **Ignorar o alerta de vencimento até o produto vencer.** O aviso é justamente para você consumir, transferir ou bloquear a tempo — não deixe o lote passar do prazo.

## Antes e depois
Antes: Definir os mínimos dos produtos para os alertas fazerem sentido. Depois: Abrir uma solicitação de compra (mínimo) ou bloquear/descartar o lote (vencimento).

## Roteiro do vídeo
- Abrir Alertas com itens abaixo do mínimo e a vencer.
- Reconhecer um alerta de mínimo; narrar "estou ciente e vou comprar".
- Mostrar a ligação com a solicitação de compra.
- Resolver um alerta e explicar por que ele pode voltar se a causa persistir.
