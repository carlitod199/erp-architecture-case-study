<?php
declare(strict_types=1);
/* ============================================================
   VERO Campo — api/v1/index.php (front controller)
   Todas as rotas do app passam por aqui (rewrite no .htaccess).

   Contrato com o app (vero_campo/src/services/http.js):
   - envelope { ok, data, message, error, sync:{server_time} }
   - Authorization: Bearer <token opaco de 64 hex>
   - códigos de erro estáveis: token_expirado, sem_permissao, ...
   ============================================================ */

require_once __DIR__ . '/../../includes/db.php';        // PDO + bootstrap (erros de /api/ já saem em JSON)
require_once __DIR__ . '/../../includes/vero_crud.php'; // vero_pdo/vero_tenant/vero_uid/vero_insert...
require_once __DIR__ . '/../../includes/vero_services.php'; // motor vero_srv_* (regra fica no servidor)
require_once __DIR__ . '/nucleo/api.php';
require_once __DIR__ . '/nucleo/contexto.php';

/* CORS (A13): restrito a origens CONHECIDAS (domínios *.example.com +
   localhost/127.0.0.1 de dev). A API é Bearer (sem cookies), mas allowlist é a
   higiene recomendada. App nativo NÃO envia Origin → não recebe o header e segue
   funcionando (CORS é do navegador). Origem fora da lista não ganha ACAO. */
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && (
        preg_match('#^https://([a-z0-9-]+\.)*example\.com$#i', $origin)
        || preg_match('#^http://localhost(:\d+)?$#i', $origin)
        || preg_match('#^http://127\.0\.0\.1(:\d+)?$#i', $origin)
    )) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* Caminho relativo à raiz da API: /vero/api/v1/auth/login -> auth/login */
$uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$pos = strpos($uri, '/api/v1/');
$caminho = $pos !== false ? substr($uri, $pos + strlen('/api/v1/')) : '';
$caminho = trim($caminho, '/');
$metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/* Tabela de rotas: [método, regex, arquivo, função, pública?]
   Grupos capturados viram argumentos da função, na ordem. */
$rotas = [
    ['POST', '#^auth/login$#',                       'auth.php',    'rota_auth_login',            true],
    ['POST', '#^auth/refresh$#',                     'auth.php',    'rota_auth_refresh',          false],
    ['POST', '#^auth/logout$#',                      'auth.php',    'rota_auth_logout',           false],

    ['GET',  '#^sync/([a-z_]+)$#',                   'sync.php',    'rota_sync',                  false],
    ['GET',  '#^mip/alvos/(\d+)/produtos$#',         'sync.php',    'rota_mip_alvo_produtos',     false],

    ['POST', '#^apontamentos$#',                     'escrita.php', 'rota_apontamento_criar',     false],
    ['POST', '#^colheitas$#',                        'escrita.php', 'rota_colheita_criar',        false],
    ['POST', '#^cargas$#',                           'escrita.php', 'rota_carga_registrar',       false],
    ['POST', '#^colheitas/(\d{1,7})/realizado$#',    'escrita.php', 'rota_colheita_realizado_id', false],
    ['POST', '#^apontamentos/(\d{1,7})/producao$#',  'escrita.php', 'rota_apontamento_producao_id', false],
    ['POST', '#^apontamentos/(\d{1,7})/concluir$#',  'escrita.php', 'rota_apontamento_concluir_id', false],
    ['POST', '#^apontamentos/([0-9a-fA-F-]{8,64})/concluir$#', 'escrita.php', 'rota_apontamento_concluir', false],
    ['POST', '#^monitoramentos$#',                   'escrita.php', 'rota_monitoramento_criar',   false],
    ['POST', '#^monitoramentos/enviar$#',            'escrita.php', 'rota_monitoramentos_enviar', false],
    ['POST', '#^irrigacao/apontamentos$#',           'escrita.php', 'rota_irrigacao_criar',       false],
    ['POST', '#^atividades/(\d+)/status$#',          'escrita.php', 'rota_atividade_status',      false],
    ['POST', '#^alertas/(\d+)/reconhecer$#',         'escrita.php', 'rota_alerta_reconhecer',     false],
    ['POST', '#^aplicacoes$#',                       'escrita.php', 'rota_aplicacao_emitir',      false],
    ['POST', '#^aplicacoes/confirmar$#',             'escrita.php', 'rota_aplicacao_confirmar_campo', false],
    ['POST', '#^aplicacoes/assinar$#',               'escrita.php', 'rota_aplicacao_assinar_campo',   false],
    ['POST', '#^aplicacoes/(\d+)/confirmar$#',       'escrita.php', 'rota_aplicacao_confirmar',   false],
    ['POST', '#^aplicacoes/(\d+)/assinar$#',         'escrita.php', 'rota_aplicacao_assinar',     false],
    ['POST', '#^recebimentos/confirmar$#',           'escrita.php', 'rota_recebimento_confirmar', false],
    ['POST', '#^compras/solicitacoes$#',             'escrita.php', 'rota_compra_solicitar',      false],
    ['POST', '#^compras/pedidos/(\d+)/(aprovar|rejeitar)$#', 'escrita.php', 'rota_compra_decidir', false],
    ['POST', '#^compras/pedidos/(\d+)/receber$#',    'escrita.php', 'rota_compra_receber',        false],
    ['POST', '#^maquinas/(\d+)/horimetro$#',         'escrita.php', 'rota_maquina_horimetro',     false],
    ['POST', '#^maquinas/(\d+)/abastecimento$#',     'escrita.php', 'rota_maquina_abastecimento', false],
    ['POST', '#^anexos$#',                           'escrita.php', 'rota_anexo_criar',           false],

    /* Packing House (19/08): recepção de cargas + posto de bipagem */
    ['GET',  '#^packing/contexto$#',                 'packing.php', 'rota_packing_contexto',      false],
    ['GET',  '#^packing/recepcao/pendentes$#',       'packing.php', 'rota_packing_pendentes',     false],
    ['POST', '#^packing/recepcao/avaliar$#',         'packing.php', 'rota_packing_avaliar',       false],
    ['POST', '#^packing/recepcao$#',                 'packing.php', 'rota_packing_recepcao_criar', false],
    ['GET',  '#^packing/apontar/tally$#',            'packing.php', 'rota_packing_tally',         false],
    ['GET',  '#^packing/romaneio$#',                 'packing.php', 'rota_packing_romaneio',      false],
    ['POST', '#^packing/apontar/beep$#',             'packing.php', 'rota_packing_beep',          false],

    ['POST', '#^push/registrar$#',                   'escrita.php', 'rota_push_registrar',        false],

    ['POST', '#^ia/chat$#',                          'ia.php',      'rota_ia_chat',               false],
    ['POST', '#^ia/transcrever$#',                   'ia.php',      'rota_ia_transcrever',        false],
];

try {
    foreach ($rotas as [$m, $regex, $arquivo, $funcao, $publica]) {
        if ($m !== $metodo || !preg_match($regex, $caminho, $captura)) {
            continue;
        }
        require_once __DIR__ . '/rotas/' . $arquivo;
        if (!function_exists($funcao)) {
            api_erro('rota_incompleta', "Handler {$funcao} não implementado.", 500);
        }
        $usuario = $publica ? null : api_autenticar();
        array_shift($captura); // remove o match completo
        $funcao($usuario, ...$captura);
        api_erro('sem_resposta', 'A rota não produziu resposta.', 500); // handlers devem dar exit via api_ok/api_erro
    }
    api_erro('rota_desconhecida', 'Rota não encontrada.', 404);
} catch (Throwable $e) {
    error_log('[api/v1] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    api_erro('erro_interno', 'Erro interno no servidor.', 500);
}
