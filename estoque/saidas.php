<?php
/* VERO — Estoque / Saídas (tela real, base compartilhada)
   Guard: estoque.saidas */
$MOV_TIPO   = 'saida';
$MOV_MICRO  = 'saidas';
$MOV_VIEW   = 'estoque_saidas';
$MOV_TITULO = 'Movimentações de Estoque — Saídas';
$MOV_SUB    = 'Saídas por apontamento ou manuais, ao custo médio — perecíveis saem pelo lote mais próximo do vencimento (FEFO)';
require __DIR__ . '/_mov_base.php';
