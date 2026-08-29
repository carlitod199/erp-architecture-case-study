<?php
/* VERO — Financeiro / Recebimentos (RELATÓRIO read-only, base compartilhada)
   Guard: financeiro.recebimentos
   Só leitura: consolida o razão financeiro (movimentacoes_financeiras
   pagas) por mês e origem. As baixas/edições ficam em Contas a Receber.
   Casca de relatório + Exportar CSV + Imprimir vêm de _recorte_base.php;
   o slug do CSV é este micro ('recebimentos'). */
$RC_TIPO   = 'receber';
$RC_MICRO  = 'recebimentos';
$RC_VIEW   = 'financeiro_recebimentos';
$RC_TITULO = 'Recebimentos';
$RC_SUB    = 'Entradas de caixa efetivadas — recorte do razão financeiro por mês e origem';
require __DIR__ . '/_recorte_base.php';
