---
slug: bloquear-lote-e-rastrear-validade
titulo: "Bloquear um lote por qualidade e rastrear sua origem"
tipo: FAZER
modulo: estoque
objetivo: controlar
jornada: [continuo]
nivel: intermediario
duracao_seg: 150
rotas:
  - rota: /estoque/lotes.php
    principal: true
permissoes: [estoque.lotes_validade.editar]
papeis:
  nucleo: [estoquista, almoxarife, rt_gerente]
  consulta: [gestor, encarregado, dono]
relacoes:
  relacionado: [fazer-inventario-do-almoxarifado, tratar-alertas-de-estoque-critico]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A tela de Lotes é a base do FEFO: mostra saldo, validade, dias para vencer, origem e custo de cada lote. Aqui você bloqueia manualmente um lote com problema de qualidade (para ele não sair no consumo) e rastreia de onde ele veio — colheita, compra ou transferência — e para onde foi.

## Como fazer
1. Abra **Lotes e Validade** e use os filtros de situação para achar o lote (por exemplo, "a vencer" ou "bloqueado").
2. Para tirar um lote da fila de consumo, clique em **Bloquear** — ele deixa de ser escolhido pelo FEFO.
3. Resolvida a pendência de qualidade, clique em **Desbloquear** para devolvê-lo ao consumo.
4. Para investigar, use o **Rastreio**: para trás mostra a origem do lote; para frente, os consumos que ele abasteceu.

## Erros comuns
- **Tentar mudar um status que é do sistema.** Só a transição manual disponível↔bloqueado é permitida; "em classificação", "consumido" e "estornado" são controlados pelo sistema (colheita, consumo, estorno) e o app recusa a troca.
- **Bloquear e esquecer.** Lote bloqueado não sai no consumo — se a colheita ou a aplicação "não encontram saldo", confira se não há lote bloqueado indevidamente.
- **Confundir bloqueio com baixa.** Bloquear não tira o produto do estoque; só o congela. Para reduzir de fato, use ajuste ou inventário.

## Antes e depois
Antes: Detectar o problema de qualidade ou a validade curta (alerta de estoque). Depois: Desbloquear quando resolver, ou ajustar/descartar o saldo se o lote for perdido.

## Roteiro do vídeo
- Abrir Lotes, filtrar "a vencer" e escolher um lote suspeito.
- Bloquear o lote; narrar "agora o FEFO não o usa mais".
- Abrir o rastreio para trás mostrando a origem do lote.
- Desbloquear para devolver ao consumo.
