<?php
declare(strict_types=1);
/* ============================================================
   VERO — api/v1/nucleo/ia_agente.php
   ORQUESTRADOR do Agente Operacional: transforma o manifesto
   declarativo (ia/capabilities/*.json) em capacidades executáveis.

   Princípios:
   - a IA NUNCA monta SQL. Executar uma capability = fazer uma
     chamada HTTP INTERNA autenticada ao próprio handler (a mesma
     rota que o app usa) → reúso total de RBAC + tenant +
     idempotência + regra de negócio; o api_ok/exit do handler
     fica contido na resposta HTTP.
   - toda escrita passa por gate de confirmação e é auditada em
     ia_acoes (hash-chain) via ia_agente_infra.php.
   ============================================================ */

require_once __DIR__ . '/ia_agente_infra.php';

/** Diretório dos manifestos de capacidade. */
function ia_agente_dir(): string
{
    return dirname(__DIR__) . '/ia/capabilities';
}

/** Carrega todas as capabilities válidas, indexadas por id. (cacheado) */
function ia_agente_capabilities(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (glob(ia_agente_dir() . '/*.json') ?: [] as $arq) {
        $c = json_decode((string)file_get_contents($arq), true);
        if (!is_array($c) || empty($c['id']) || empty($c['handler']['rota'])) {
            continue; // ignora manifesto malformado (nunca derruba o agente)
        }
        $cache[$c['id']] = $c;
    }
    return $cache;
}

/** id da capability → nome de tool válido p/ OpenAI ([a-zA-Z0-9_-]). */
function ia_agente_tool_nome(string $id): string
{
    return str_replace('.', '__', $id);
}
function ia_agente_id_de_tool(string $nome): string
{
    return str_replace('__', '.', $nome);
}

/** Mapeia o manifesto → definição de tools (function calling).
 *  $apenasEscrita=true expõe só capacidades de escrita/destrutivas (o chat usa
 *  as consultas em texto do legado; capacidades de leitura crua ficam de fora). */
function ia_agente_tools(bool $apenasEscrita = false): array
{
    $tipoJson = ['int' => 'integer', 'decimal' => 'number', 'bool' => 'boolean',
                 'lista' => 'array', 'texto' => 'string', 'data' => 'string',
                 'hora' => 'string', 'enum' => 'string'];
    $tools = [];
    foreach (ia_agente_capabilities() as $cap) {
        if ($apenasEscrita && ($cap['tipo'] ?? '') === 'leitura') {
            continue;
        }
        $props = [];
        $req = [];
        foreach (($cap['params'] ?? []) as $nome => $p) {
            // client_uuid é gerado pelo sistema — não vira parâmetro do modelo
            if ($nome === 'client_uuid') {
                continue;
            }
            $obrig = !empty($p['obrigatorio']) && empty($p['de_contexto']) && !array_key_exists('default', $p);
            $desc = (string)($p['desc'] ?? '');
            // sinaliza opcionais para o modelo NÃO ficar pedindo campo dispensável
            $desc = ($obrig ? $desc : trim($desc . ' (opcional — pode omitir)'));
            $prop = ['type' => $tipoJson[$p['tipo'] ?? 'texto'] ?? 'string'];
            if ($desc !== '')       $prop['description'] = $desc;
            if (!empty($p['enum']))  $prop['enum'] = $p['enum'];
            if (($p['tipo'] ?? '') === 'lista') $prop['items'] = ['type' => 'object'];
            $props[$nome] = $prop;
            if ($obrig) {
                $req[] = $nome;
            }
        }
        $desc = ($cap['titulo'] ?? $cap['id']);
        if (!empty($cap['regras'])) {
            $desc .= ' — regras: ' . implode('; ', $cap['regras']);
        }
        if (!empty($cap['confirmar'])) {
            $desc .= ' [AÇÃO DE ESCRITA: confirme com o usuário antes de executar]';
            // gate estrutural: a ação só executa com confirmado=true
            $props['confirmado'] = ['type' => 'boolean',
                'description' => 'defina TRUE somente APÓS o usuário confirmar explicitamente o resumo — sem isso a ação NÃO executa'];
        }
        $tools[] = ['type' => 'function', 'function' => [
            'name' => ia_agente_tool_nome($cap['id']),
            'description' => mb_substr($desc, 0, 1000),
            'parameters' => ['type' => 'object', 'properties' => (object)$props, 'required' => $req],
        ]];
    }
    return $tools;
}

/** Params obrigatórios ainda faltando (para o slot-filling perguntar). */
function ia_agente_faltando(array $cap, array $args): array
{
    $faltam = [];
    foreach (($cap['params'] ?? []) as $nome => $p) {
        if ($nome === 'client_uuid') continue;
        $tem = isset($args[$nome]) && $args[$nome] !== '' && $args[$nome] !== null;
        if (!empty($p['obrigatorio']) && !$tem
            && empty($p['de_contexto']) && !array_key_exists('default', $p)) {
            $faltam[$nome] = $p['desc'] ?? $nome;
        }
    }
    return $faltam;
}

/** Gate estrutural: escrita/destrutiva só executa com confirmado=true. */
function ia_agente_precisa_confirmar(array $cap, array $args): bool
{
    return !empty($cap['confirmar']) && empty($args['confirmado']);
}

/** Preenche o template de resumo ({campo}) para o cartão de confirmação. */
function ia_agente_resumo(array $cap, array $args): string
{
    $txt = (string)($cap['resumo'] ?? $cap['titulo'] ?? $cap['id']);
    return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($args) {
        $v = $args[$m[1]] ?? '—';
        return is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }, $txt);
}

/** Base URL da própria API (para a chamada interna). Override por env. */
function ia_agente_base_url(): string
{
    $env = getenv('IA_AGENTE_BASE_URL');
    if ($env) {
        return rtrim($env, '/');
    }
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // recorta até /api/v1 a partir do script atual
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/vero/api/v1/');
    $pos = strpos($uri, '/api/v1/');
    $prefixo = $pos !== false ? substr($uri, 0, $pos + strlen('/api/v1')) : '/vero/api/v1';
    return ($https ? 'https' : 'http') . '://' . $host . $prefixo;
}

/**
 * EXECUTA uma capability via chamada HTTP interna autenticada.
 * Substitui {id}/{param} na rota pelos args; envia o resto no corpo (POST).
 * Audita em ia_acoes. Retorna ['ok'=>bool, 'http'=>int, 'data'=>..., 'mensagem'=>...].
 */
function ia_agente_executar(array $cap, array $args, array $usuario, string $token, string $sessionId): array
{
    // resolve a rota (path params) e monta o corpo com o restante
    $rota = (string)$cap['handler']['rota'];
    $metodo = strtoupper((string)($cap['handler']['metodo'] ?? 'POST'));
    $corpo = $args;
    $rota = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$corpo) {
        $k = $m[1];
        $v = $corpo[$k] ?? '';
        unset($corpo[$k]); // param de caminho não vai no corpo
        return rawurlencode((string)$v);
    }, $rota);

    // escrita idempotente: o sistema gera o client_uuid (nunca o usuário/modelo)
    if ($metodo === 'POST' && ($cap['tipo'] ?? '') !== 'leitura' && empty($corpo['client_uuid'])) {
        $corpo['client_uuid'] = 'ia-' . bin2hex(random_bytes(12));
    }

    $url = ia_agente_base_url() . $rota;
    if ($metodo === 'GET' && $corpo) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($corpo);
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 25,
    ];
    if ($metodo === 'POST') {
        $opts[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    if (function_exists('ia_aplicar_ca')) {
        ia_aplicar_ca($ch); // CA p/ HTTPS (mesma do proxy de IA)
    }
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $resp = $raw !== false ? json_decode((string)$raw, true) : null;
    $ok = is_array($resp) ? (bool)($resp['ok'] ?? false) : false;
    $mensagem = is_array($resp) ? (string)($resp['message'] ?? $resp['error'] ?? '') : ($err ?: 'sem resposta');
    $data = is_array($resp) ? ($resp['data'] ?? null) : null;

    // recurso afetado (quando o handler devolve um id) — trilha de auditoria
    $recId = is_array($data) ? ($data['id'] ?? null) : null;

    // AUDITORIA (só escrita; leitura não precisa de trilha imutável)
    if (($cap['tipo'] ?? '') !== 'leitura') {
        ia_auditar_acao(
            $usuario, $sessionId, (string)$cap['id'], $args,
            ($ok ? 'OK: ' : 'ERRO: ') . $mensagem . ' (http ' . $http . ')',
            $cap['modulo'] ?? null, $recId
        );
    }

    return ['ok' => $ok, 'http' => $http, 'data' => $data, 'mensagem' => $mensagem];
}
