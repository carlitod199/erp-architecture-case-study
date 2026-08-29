<?php
declare(strict_types=1);
/* ============================================================
   PT-10 (CSO): endpoint LEGADO (VERO clube/associados) descontinuado no VERO.
   A API oficial do VERO é /api/v1. Este arquivo era órfão: o require de
   includes/app_api.php (inexistente) falhava ANTES do bootstrap e vazava o
   caminho absoluto + include_path (path disclosure). Neutralizado: responde
   410 limpo, sem require de arquivo ausente → zero vazamento. (Defesa em
   profundidade com api/auth/.htaccess = Require all denied, quando honrado.)
   ============================================================ */
http_response_code(410);
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
echo json_encode(
    ['ok' => false, 'message' => 'Endpoint descontinuado. Use a API oficial em /api/v1.'],
    JSON_UNESCAPED_UNICODE
);
exit;
