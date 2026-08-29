<?php
/* VERO — MIP / Pragas — NAV-1 cluster 5 (A0/NAV-2):
   o recorte "Pragas" foi absorvido pela tela ÚNICA "Alvos de Controle"
   (filtro segmentado por tipo). Rota PRESERVADA (arquivo mantido, ocultar≠
   excluir) → redirect 302 para o destino unificado com o tipo pré-filtrado.
   Slug de permissão mip.pragas intacto (sem re-seed); o destino aplica o guard. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$base = defined('BIOS_BASE') ? BIOS_BASE : '';
header('Location: ' . $base . '/mip/alvos_controle?tipo=praga', true, 302);
exit;
