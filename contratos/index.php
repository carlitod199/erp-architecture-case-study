<?php
/* VERO — B5 (auditoria Go-Live): tela-PROTÓTIPO com dados fictícios,
   removida do ar. Redireciona para a tela real de contratos de venda.
   O menu já aponta direto para comercial/contratos_venda.php. */
require_once __DIR__ . '/../includes/auth.php';
header('Location: ' . BIOS_BASE . '/comercial/contratos_venda');
exit;
