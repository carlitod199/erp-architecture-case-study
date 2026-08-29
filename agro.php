<?php
/* VERO Agro - redirecionador legado de micro modulos.
   As telas mockadas agora possuem arquivos PHP reais por modulo.
   Links antigos /agro.php?macro=...&micro=... apenas redirecionam
   para a rota cadastrada em includes/menu_agro.php. */
declare(strict_types=1);

require_once __DIR__ . '/includes/menu_agro.php';
require_once __DIR__ . '/includes/auth.php';

$macroSlug = isset($_GET['macro']) ? preg_replace('/[^a-z_]/', '', (string)$_GET['macro']) : '';
$microSlug = isset($_GET['micro']) ? preg_replace('/[^a-z_]/', '', (string)$_GET['micro']) : '';

$macro = $macroSlug !== '' ? bios_menu_macro($macroSlug) : null;
$micro = $macro ? bios_menu_micro($macroSlug, $microSlug) : null;

if ($macro && $micro && !empty($micro['rota'])) {
    header('Location: ' . BIOS_BASE . $micro['rota'], true, 302);
    exit;
}

http_response_code(404);
header('Location: ' . BIOS_BASE . '/404');
exit;
