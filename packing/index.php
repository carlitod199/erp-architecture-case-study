<?php
declare(strict_types=1);
/* ============================================================
   VERO — Packing House / (rota legada)
   O Painel (seletor de contexto unidade + turno) foi MESCLADO na Recepção.
   Esta rota /packing/index permanece viva só para bookmarks/links antigos:
   redireciona para a Recepção, que agora é a porta de entrada do módulo.
   ============================================================ */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
vero_redirect(BIOS_BASE . '/packing/recepcao');
