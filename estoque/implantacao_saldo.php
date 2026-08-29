<?php
/* VERO — Saldo Inicial (C-07 v2, pedido do usuário 18/07): a tela em massa
   virou um MODAL na tela de Produtos (data + código → saldo atual → corrigir
   pela diferença via services). Esta rota redireciona e abre o modal.
   302 (não 301 — Chrome cacheia 301 para sempre). */
require_once __DIR__ . '/../includes/auth.php';
header('Location: ' . BIOS_BASE . '/estoque/produtos?saldo=1', true, 302);
exit;
