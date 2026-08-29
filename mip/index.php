<?php
/* VERO — B5 (auditoria Go-Live): tela-PROTÓTIPO com dados fictícios,
   removida do ar. Redireciona para a tela real de monitoramento MIP.
   O menu já aponta direto para mip/monitoramento.php. */
require_once __DIR__ . '/../includes/auth.php';
header('Location: ' . BIOS_BASE . '/mip/monitoramento');
exit;
