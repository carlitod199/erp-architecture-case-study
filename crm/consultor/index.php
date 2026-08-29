<?php
declare(strict_types=1);
/* /crm/consultor(/) → app. Sem isto a URL da pasta cai no 403 do
   Apache (Options -Indexes); o dashboard cuida de login/permissão. */
require_once dirname(__DIR__, 2) . '/includes/functions.php';
header('Location: ' . BIOS_BASE . '/crm/consultor/dashboard');
exit;
