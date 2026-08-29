<?php
/* VERO — redirect (UX-05/G-23, 19/07/2026): esta era uma tela-PROTÓTIPO de
   viticultura com dados fictícios (vit_demo_labs), órfã — fora do menu e sem
   nenhuma referência no sistema. Não existe cadastro de laboratórios no MVP;
   o trabalho real com laudos de laboratório vive no módulo Nutrição
   (análises de solo/foliar). Redirect 302, não 301 — Chrome cacheia 301
   para sempre (lição do encerramento F). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$base = defined('BIOS_BASE') ? BIOS_BASE : '';
header('Location: ' . $base . '/nutricao/analise_solo', true, 302);
exit;
