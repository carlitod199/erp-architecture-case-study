<?php
/* VERO — B5 (auditoria Go-Live): esta tela era um PROTÓTIPO com dados
   fictícios e um botão "Emitir e transmitir" que exibia sucesso FALSO
   ("NF-e autorizada pela SEFAZ"). Removida do ar. Redireciona para a
   tela fiscal real. O menu já aponta direto para fiscal/documentos.php. */
require_once __DIR__ . '/../includes/auth.php';
header('Location: ' . BIOS_BASE . '/fiscal/documentos');
exit;
