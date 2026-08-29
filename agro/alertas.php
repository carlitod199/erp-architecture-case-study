<?php
/* VERO — redirect (UX-05/G-23, 19/07/2026): esta era uma tela-PROTÓTIPO de
   viticultura com dados fictícios (vit_demo_alertas), órfã — fora do menu e
   sem nenhuma referência no sistema. A central REAL de alertas é
   dashboard/indicadores_alertas.php (alertas de estoque ficam em
   estoque/alertas.php). Redirect 302, não 301 — Chrome cacheia 301 para
   sempre (lição do encerramento F). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$base = defined('BIOS_BASE') ? BIOS_BASE : '';
header('Location: ' . $base . '/dashboard/indicadores_alertas', true, 302);
exit;
