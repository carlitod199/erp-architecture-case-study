<?php
/* VERO — Financeiro / Despesas (RELATÓRIO read-only, base compartilhada)
   Guard: financeiro.despesas
   Só leitura: consolida o razão financeiro (movimentacoes_financeiras
   pagas) por mês e origem. As baixas/edições ficam em Contas a Pagar.
   Casca de relatório + Exportar CSV + Imprimir vêm de _recorte_base.php;
   o slug do CSV é este micro ('despesas'). */
$RC_TIPO   = 'pagar';
$RC_MICRO  = 'despesas';
$RC_VIEW   = 'financeiro_despesas';
$RC_TITULO = 'Despesas';
$RC_SUB    = 'Saídas de caixa efetivadas — recorte do razão financeiro por mês e origem';
require __DIR__ . '/_recorte_base.php';
