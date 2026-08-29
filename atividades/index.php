<?php
/* VERO — B5 (auditoria Go-Live): tela-PROTÓTIPO com dados fictícios e
   botões que exibiam sucesso FALSO ("Apontamento registrado"). Removida
   do ar. Redireciona para a tela real de apontamentos de campo.
   O menu já aponta direto para agro/apontamentos.php. */
require_once __DIR__ . '/../includes/auth.php';
header('Location: ' . BIOS_BASE . '/agro/apontamentos');
exit;
