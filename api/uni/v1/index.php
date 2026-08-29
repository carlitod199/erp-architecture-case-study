<?php
declare(strict_types=1);
/* ============================================================
   Universidade VERO — api/uni/v1/index.php (front controller)
   API de conteúdo/ajuda. Banco SEPARADO (uni_pdo). Auth dupla
   (sessão do ERP ou Bearer do app) — ver nucleo.php.
   Respostas seguem o shape do §8 (não o envelope do app), exceto erros.
   ============================================================ */

require_once __DIR__ . '/nucleo.php';

$uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$pos = strpos($uri, '/api/uni/v1/');
$caminho = $pos !== false ? trim(substr($uri, $pos + strlen('/api/uni/v1/')), '/') : '';
$metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($metodo === 'OPTIONS') { http_response_code(204); exit; }

$rotas = [
    ['GET',  'ajuda',  'ajuda.php', 'rota_uni_ajuda'],
    ['POST', 'evento', 'ajuda.php', 'rota_uni_evento'],
];

try {
    foreach ($rotas as [$m, $path, $arquivo, $funcao]) {
        if ($m !== $metodo || $caminho !== $path) continue;
        require_once __DIR__ . '/rotas/' . $arquivo;
        if (!function_exists($funcao)) {
            uni_json(500, ['erro' => 'rota_incompleta']);
        }
        $ctx = uni_contexto();
        $funcao($ctx);
        uni_json(500, ['erro' => 'sem_resposta']);
    }
    uni_json(404, ['erro' => 'rota_desconhecida']);
} catch (Throwable $e) {
    error_log('[api/uni/v1] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    uni_json(500, ['erro' => 'erro_interno']);
}
