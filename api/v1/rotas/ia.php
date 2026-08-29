<?php
declare(strict_types=1);
/* ============================================================
   VERO Campo — api/v1/rotas/ia.php
   Assistente de IA: proxy servidor -> Anthropic (Claude) OU provedor
   OpenAI-compatível (OpenAI/Groq) — o gestor pode não ter conta Anthropic.
   - POST /ia/chat        -> Messages API (Anthropic) ou Chat Completions
                             (OpenAI-compatível), conforme a chave disponível
   - POST /ia/transcrever -> Audio Transcriptions (comando de voz, pt-BR)
   As chaves ficam no .env da raiz — nunca no app. Seleção do CHAT
   (ver ia_chat_provedor):
   - ANTHROPIC_API_KEY começando com "sk-ant-" -> Anthropic (opcionais:
     ANTHROPIC_MODELO_CHAT, ANTHROPIC_BASE_URL);
   - senão, caminho OpenAI-compatível com a primeira chave não-vazia entre
     IA_CHAT_API_KEY, ANTHROPIC_API_KEY e OPENAI_API_KEY; base =
     IA_CHAT_BASE_URL ou (chave sk-proj-*) api.openai.com ou IA_BASE_URL;
     modelo = IA_MODELO_CHAT_OPENAI (padrão gpt-4o na OpenAI oficial,
     llama-3.3-70b-versatile na Groq).
   - OPENAI_API_KEY + IA_BASE_URL seguem para /ia/transcrever: a
     Anthropic não tem API de transcrição de áudio, então o STT continua
     no provedor OpenAI-compatível (Groq/OpenAI), com OPENAI_MODELO_STT.

   NOTA: o projeto não usa Composer; chamadas em cURL nativo.
   ============================================================ */

const IA_MODELO_CHAT_PADRAO = 'claude-opus-4-8';
const IA_MODELO_STT_PADRAO = 'gpt-4o-mini-transcribe';
// teto de saída por rodada (pensamento adaptativo conta aqui — folga proposital)
const IA_MAX_TOKENS = 4096;

/** Lê uma variável do ambiente ou do .env da raiz. */
function ia_env(string $nome, string $padrao = ''): string
{
    $valor = getenv($nome) ?: '';
    if ($valor === '') {
        $env = dirname(__DIR__, 3) . '/.env';
        if (is_file($env)) {
            foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
                $linha = trim($linha);
                if (str_starts_with($linha, $nome . '=')) {
                    $valor = trim(substr($linha, strlen($nome) + 1), " \t\"'");
                    break;
                }
            }
        }
    }
    return $valor !== '' ? $valor : $padrao;
}

/** Aplica o CA bundle no cURL (env BIOS_CURL_CAINFO do VirtualHost; fallback
 *  para o cacert.pem que acompanha o PHP — necessário no Windows/CLI). */
function ia_aplicar_ca(CurlHandle $ch): void
{
    $caInfo = getenv('BIOS_CURL_CAINFO') ?: '';
    if ($caInfo === '' || !is_file($caInfo)) {
        $caInfo = dirname(PHP_BINARY) . '/extras/ssl/cacert.pem';
    }
    if (is_file($caInfo)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caInfo);
    }
}

function ia_chave(): string
{
    $chave = ia_env('OPENAI_API_KEY');
    if ($chave === '') {
        api_erro('ia_nao_configurada', 'Assistente indisponível: chave da IA não configurada no servidor.', 503);
    }
    return $chave;
}

function ia_chave_anthropic(): string
{
    $chave = ia_env('ANTHROPIC_API_KEY');
    if ($chave === '') {
        api_erro('ia_nao_configurada', 'Assistente indisponível: chave da IA não configurada no servidor.', 503);
    }
    return $chave;
}

/** POST na Messages API da Anthropic (chat do assistente); devolve o JSON
 *  decodificado. Com $tolerante=true devolve null em falha (o chamador decide). */
function ia_anthropic(array $json, bool $tolerante = false): ?array
{
    $base = rtrim(ia_env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/');
    $ch = curl_init($base . '/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true, // P-8
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 120, // Claude pensa antes de responder — folga p/ rodadas com ferramenta
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . ia_chave_anthropic(),
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($json, JSON_UNESCAPED_UNICODE),
    ]);
    ia_aplicar_ca($ch);
    $bruto = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($bruto === false) {
        error_log('[api/v1 ia] curl anthropic: ' . $erroCurl);
        if ($tolerante) return null;
        api_erro('ia_indisponivel', 'Assistente indisponível no momento. Tente de novo.', 502);
    }
    $resp = json_decode((string)$bruto, true);
    if ($http !== 200 || !is_array($resp)) {
        error_log('[api/v1 ia] anthropic http ' . $http . ': ' . substr((string)$bruto, 0, 500));
        if ($tolerante) return null;
        if ($http === 429 || $http === 529) { // rate limit / sobrecarga
            api_erro('ia_ocupada', 'Assistente ocupado agora — espere alguns segundos e tente de novo.', 429);
        }
        api_erro('ia_indisponivel', 'Assistente indisponível no momento. Tente de novo.', 502);
    }
    return $resp;
}

/** POST JSON ou multipart para a API da OpenAI (hoje só o STT usa);
 *  devolve o JSON decodificado.
 *  Com $tolerante=true devolve null em falha (o chamador decide o fallback). */
function ia_openai(string $endpoint, array|CURLFile|null $json, ?array $multipart = null, bool $tolerante = false): ?array
{
    $base = rtrim(ia_env('IA_BASE_URL', 'https://api.openai.com/v1'), '/');
    $ch = curl_init($base . '/' . $endpoint);
    $headers = ['Authorization: Bearer ' . ia_chave()];
    if ($multipart !== null) {
        $corpo = $multipart; // cURL monta o multipart/form-data sozinho
    } else {
        $headers[] = 'Content-Type: application/json';
        $corpo = json_encode($json, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true, // P-8
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $corpo,
    ]);
    ia_aplicar_ca($ch);
    $bruto = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($bruto === false) {
        error_log('[api/v1 ia] curl: ' . $erroCurl);
        if ($tolerante) return null;
        api_erro('ia_indisponivel', 'Assistente indisponível no momento. Tente de novo.', 502);
    }
    $resp = json_decode((string)$bruto, true);
    if ($http !== 200 || !is_array($resp)) {
        error_log('[api/v1 ia] http ' . $http . ': ' . substr((string)$bruto, 0, 500));
        if ($tolerante) return null;
        if ($http === 429) {
            api_erro('ia_ocupada', 'Assistente ocupado agora — espere alguns segundos e tente de novo.', 429);
        }
        api_erro('ia_indisponivel', 'Assistente indisponível no momento. Tente de novo.', 502);
    }
    return $resp;
}

function ia_modelo_chat(): string
{
    return ia_env('ANTHROPIC_MODELO_CHAT', IA_MODELO_CHAT_PADRAO);
}

/* ───────────── seleção de provedor do CHAT (Anthropic OU OpenAI-compatível) ─────────────
   O gestor só tem chave OpenAI (sk-proj-…) e a produção roda com chave Groq
   (gsk_…) no OPENAI_API_KEY — então o chat precisa dos dois caminhos.
   Transcrição (STT) e laudo NÃO passam por aqui. */

/** Decide o provedor do chat. Anthropic só com chave que É da Anthropic
 *  (sk-ant-*); senão cai no caminho OpenAI-compatível reaproveitando a
 *  primeira chave disponível. Sem chave nenhuma -> ia_nao_configurada. */
function ia_chat_provedor(): array
{
    static $prov = null;
    if ($prov !== null) {
        return $prov;
    }
    $ant = ia_env('ANTHROPIC_API_KEY');
    if (str_starts_with($ant, 'sk-ant-')) {
        return $prov = ['tipo' => 'anthropic'];
    }
    // primeira chave não-vazia: dedicada > ANTHROPIC_API_KEY com chave de
    // outro provedor (operador colocou uma sk-proj lá) > OPENAI_API_KEY
    $chave = ia_env('IA_CHAT_API_KEY') ?: ($ant ?: ia_env('OPENAI_API_KEY'));
    if ($chave === '') {
        api_erro('ia_nao_configurada', 'Assistente indisponível: chave da IA não configurada no servidor.', 503);
    }
    $oficial = str_starts_with($chave, 'sk-proj-'); // chave da OpenAI oficial
    $base = ia_env('IA_CHAT_BASE_URL');
    if ($base === '') {
        // sk-proj só funciona na OpenAI; as demais (gsk_…) seguem a base do STT
        $base = $oficial ? 'https://api.openai.com/v1' : ia_env('IA_BASE_URL', 'https://api.openai.com/v1');
    }
    $modelo = ia_env('IA_MODELO_CHAT_OPENAI', $oficial ? 'gpt-4o' : 'llama-3.3-70b-versatile');
    return $prov = ['tipo' => 'openai', 'chave' => $chave, 'base' => rtrim($base, '/'), 'modelo' => $modelo];
}

/** Corrente de modelos do caminho OpenAI-compatível: na Groq o rate limit é
 *  POR MODELO, então quando o principal está ocupado (429) tentamos os
 *  reservas antes de desistir (mesma estratégia da implementação pré-Anthropic).
 *  Na OpenAI oficial não há reserva — é um provedor só. */
function ia_modelos_openai(): array
{
    $prov = ia_chat_provedor();
    if (str_starts_with($prov['chave'], 'sk-proj-')) {
        return [$prov['modelo']];
    }
    return array_values(array_unique([$prov['modelo'], 'openai/gpt-oss-20b', 'llama-3.3-70b-versatile']));
}

/** POST chat/completions no provedor OpenAI-compatível do CHAT (base e chave
 *  próprias — não confundir com ia_openai(), que é o caminho do STT).
 *  Com $tolerante=true devolve null em falha (o chamador decide). */
function ia_openai_chat(array $json, bool $tolerante = false): ?array
{
    $prov = ia_chat_provedor();
    $ch = curl_init($prov['base'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true, // P-8
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $prov['chave'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($json, JSON_UNESCAPED_UNICODE),
    ]);
    ia_aplicar_ca($ch);
    $bruto = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($bruto === false) {
        error_log('[api/v1 ia] curl chat openai-compat: ' . $erroCurl);
        if ($tolerante) return null;
        api_erro('ia_indisponivel', 'Assistente indisponível no momento. Tente de novo.', 502);
    }
    $resp = json_decode((string)$bruto, true);
    if ($http !== 200 || !is_array($resp)) {
        error_log('[api/v1 ia] chat openai-compat http ' . $http . ': ' . substr((string)$bruto, 0, 500));
        if ($tolerante) return null;
        if ($http === 429) {
            api_erro('ia_ocupada', 'Assistente ocupado agora — espere alguns segundos e tente de novo.', 429);
        }
        api_erro('ia_indisponivel', 'Assistente indisponível no momento. Tente de novo.', 502);
    }
    return $resp;
}

/** Tools no formato interno JÁ SÃO o formato OpenAI ({type:function,
 *  function:{name,description,parameters}}); só garante que properties
 *  vazio vire {} no JSON, não []. */
function ia_tools_openai(array $ferramentas): array
{
    return array_map(function (array $t) {
        if (is_array($t['function']['parameters'] ?? null)
            && ($t['function']['parameters']['properties'] ?? null) === []) {
            $t['function']['parameters']['properties'] = (object)[];
        }
        return $t;
    }, $ferramentas);
}

/** Conversa no formato interno do loop (estilo Messages API: system separado,
 *  turnos do assistente em blocos, tool_result agrupados numa mensagem de
 *  usuário) → formato Chat Completions (system na lista, tool_calls,
 *  mensagens role=tool). Blocos thinking não têm equivalente e ficam de fora. */
function ia_conversa_openai(string $sistema, array $mensagens): array
{
    $out = [['role' => 'system', 'content' => $sistema]];
    foreach ($mensagens as $m) {
        if (!is_array($m['content'] ?? null)) {
            $out[] = ['role' => (string)$m['role'], 'content' => (string)$m['content']];
            continue;
        }
        if (($m['role'] ?? '') === 'assistant') {
            $texto = '';
            $calls = [];
            foreach ($m['content'] as $b) {
                if (($b['type'] ?? '') === 'text') {
                    $texto .= $b['text'];
                } elseif (($b['type'] ?? '') === 'tool_use') {
                    $calls[] = ['id' => (string)($b['id'] ?? ''), 'type' => 'function', 'function' => [
                        'name' => (string)($b['name'] ?? ''),
                        // arguments é STRING JSON no formato OpenAI; objeto no topo ({}, não [])
                        'arguments' => json_encode((object)(is_array($b['input'] ?? null) ? $b['input'] : []), JSON_UNESCAPED_UNICODE),
                    ]];
                }
            }
            $msg = ['role' => 'assistant', 'content' => $texto !== '' ? $texto : null];
            if ($calls !== []) {
                $msg['tool_calls'] = $calls;
            }
            $out[] = $msg;
        } else { // user carregando tool_result -> uma mensagem role=tool por resultado
            foreach ($m['content'] as $b) {
                if (($b['type'] ?? '') === 'tool_result') {
                    $out[] = ['role' => 'tool', 'tool_call_id' => (string)($b['tool_use_id'] ?? ''), 'content' => (string)($b['content'] ?? '')];
                }
            }
        }
    }
    return $out;
}

/** Resposta do Chat Completions → formato interno (o mesmo da Messages API:
 *  content em blocos text/tool_use + usage input/output). Assim o loop do
 *  rota_ia_chat é UM só para os dois provedores. */
function ia_openai_normalizar(array $resp): array
{
    $msg = $resp['choices'][0]['message'] ?? [];
    $content = [];
    $texto = trim((string)($msg['content'] ?? ''));
    if ($texto !== '') {
        $content[] = ['type' => 'text', 'text' => $texto];
    }
    foreach (($msg['tool_calls'] ?? []) as $c) {
        $arg = json_decode((string)($c['function']['arguments'] ?? '{}'), true);
        $content[] = ['type' => 'tool_use',
            'id' => (string)($c['id'] ?? ''),
            'name' => (string)($c['function']['name'] ?? ''),
            'input' => is_array($arg) ? $arg : []];
    }
    return [
        'content' => $content,
        'stop_reason' => (string)($resp['choices'][0]['finish_reason'] ?? ''),
        'usage' => [
            'input_tokens' => (int)($resp['usage']['prompt_tokens'] ?? 0),
            'output_tokens' => (int)($resp['usage']['completion_tokens'] ?? 0),
        ],
    ];
}

/** Tools no formato interno (estilo OpenAI, herdado do manifesto) →
 *  formato da Anthropic: {name, description, input_schema}. */
function ia_tools_anthropic(array $ferramentas): array
{
    return array_map(function (array $t) {
        $schema = $t['function']['parameters'] ?? ['type' => 'object', 'properties' => (object)[]];
        // properties vazio precisa virar {} no JSON, não []
        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = (object)[];
        }
        return [
            'name' => $t['function']['name'],
            'description' => $t['function']['description'] ?? '',
            'input_schema' => $schema,
        ];
    }, $ferramentas);
}

/** Uma rodada de chat no provedor selecionado ($base leva system/messages/
 *  max_tokens no formato interno). Devolve a resposta NORMALIZADA (formato da
 *  Messages API) nos dois caminhos; com $tolerante=true devolve null em falha. */
function ia_chat_rodada(array $base, ?array $ferramentas, bool $tolerante): ?array
{
    if (ia_chat_provedor()['tipo'] === 'anthropic') {
        $payload = $base + [
            'model' => ia_modelo_chat(),
            'thinking' => ['type' => 'adaptive'],
        ];
        if ($ferramentas !== null && $ferramentas !== []) {
            $payload['tools'] = ia_tools_anthropic($ferramentas);
            $payload['tool_choice'] = ['type' => 'auto'];
        }
        return ia_anthropic($payload, $tolerante);
    }
    // OpenAI-compatível (OpenAI oficial ou Groq)
    $payload = [
        'max_completion_tokens' => (int)($base['max_tokens'] ?? IA_MAX_TOKENS),
        'messages' => ia_conversa_openai((string)($base['system'] ?? ''), $base['messages'] ?? []),
    ];
    if ($ferramentas !== null && $ferramentas !== []) {
        $payload['tools'] = ia_tools_openai($ferramentas);
        $payload['tool_choice'] = 'auto';
    }
    $modelos = ia_modelos_openai();
    $resp = null;
    foreach ($modelos as $m) { // corrente: 429/erro num modelo tenta o reserva
        $resp = ia_openai_chat($payload + ['model' => $m], true);
        if ($resp !== null) {
            break;
        }
    }
    if ($resp === null && !$tolerante) {
        // repete o principal SEM tolerância p/ o erro mapeado (ia_ocupada/…) subir
        $resp = ia_openai_chat($payload + ['model' => $modelos[0]], false);
    }
    return $resp !== null ? ia_openai_normalizar($resp) : null;
}

/** Uma rodada de chat com retry: a primeira tentativa é tolerante (blip de
 *  rede/429 transitório); a segunda deixa o erro mapeado subir para o app. */
function ia_chat_completar(array $base, ?array $ferramentas): array
{
    return ia_chat_rodada($base, $ferramentas, true) ?? ia_chat_rodada($base, $ferramentas, false);
}

/** Extrai das respostas da Messages API o texto (blocos text concatenados)
 *  e as chamadas de ferramenta (blocos tool_use). */
function ia_resposta_partes(array $resp): array
{
    $texto = '';
    $chamadas = [];
    foreach (($resp['content'] ?? []) as $bloco) {
        if (($bloco['type'] ?? '') === 'text') {
            $texto .= $bloco['text'];
        } elseif (($bloco['type'] ?? '') === 'tool_use') {
            $chamadas[] = $bloco; // {id, name, input}
        }
    }
    return [trim($texto), $chamadas];
}

/* ───────────── contexto real da fazenda (injeção por requisição) ─────────────
   O "treino" do assistente: a cada pergunta montamos um retrato compacto e
   atual do tenant (alertas, tarefas, estoque, válvulas, máquinas, clima) e
   instruímos o modelo a responder SÓ com base nele. Regra de negócio e dados
   ficam no servidor; o app nunca vê a chave nem monta contexto. */

function ia_clima_resumo(PDO $pdo, int $tenant): string
{
    // coordenada: primeiro talhão com lat/lng; senão, sede da fazenda
    $lat = -9.39; $lng = -40.5;
    try {
        $q = $pdo->prepare('SELECT latitude, longitude FROM agro_talhoes
                             WHERE tenant_id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL LIMIT 1');
        $q->execute([$tenant]);
        if ($t = $q->fetch()) { $lat = (float)$t['latitude']; $lng = (float)$t['longitude']; }
    } catch (Throwable $e) { /* usa padrão */ }

    // cache de 15 min em arquivo (Open-Meteo é grátis, mas não precisamos bater sempre)
    $cache = dirname(__DIR__, 3) . '/storage_private/ia_clima_cache.json';
    if (is_file($cache) && (time() - (int)filemtime($cache)) < 900) {
        $c = json_decode((string)file_get_contents($cache), true);
        if (is_array($c) && isset($c['resumo'])) { return (string)$c['resumo']; }
    }
    $ch = curl_init(sprintf(
        'https://api.open-meteo.com/v1/forecast?latitude=%.4f&longitude=%.4f'
        . '&current=temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m'
        . '&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=auto&forecast_days=2',
        $lat, $lng
    ));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]); // P-8
    ia_aplicar_ca($ch);
    $bruto = curl_exec($ch);
    curl_close($ch);
    $d = is_string($bruto) ? json_decode($bruto, true) : null;
    if (!is_array($d) || !isset($d['current'])) { return ''; }
    $resumo = sprintf(
        'agora %d°C, umidade %d%%, vento %d km/h; hoje máx %d°C mín %d°C, chance de chuva %d%%; amanhã máx %d°C, chance de chuva %d%%',
        round($d['current']['temperature_2m']), $d['current']['relative_humidity_2m'],
        round($d['current']['wind_speed_10m']),
        round($d['daily']['temperature_2m_max'][0]), round($d['daily']['temperature_2m_min'][0]),
        $d['daily']['precipitation_probability_max'][0],
        round($d['daily']['temperature_2m_max'][1]), $d['daily']['precipitation_probability_max'][1]
    );
    @mkdir(dirname($cache), 0750, true);
    @file_put_contents($cache, json_encode(['resumo' => $resumo]));
    return $resumo;
}

function ia_contexto(array $usuario): string
{
    $pdo = vero_pdo();
    $tenant = (int)$usuario['tenant_id'];
    $linhas = [];
    $linhas[] = 'DATA/HORA ATUAL: ' . date('d/m/Y H:i') . ' (fuso America/Recife)';

    try {
        $q = $pdo->prepare(
            "SELECT s.nome, s.codigo, s.area_ha, s.talhao_id, t.nome AS talhao FROM agro_setores s
              LEFT JOIN agro_talhoes t ON t.id = s.talhao_id
             WHERE s.tenant_id = ? AND s.ativo = 1 ORDER BY s.nome LIMIT 20"
        );
        $q->execute([$tenant]);
        $vs = array_map(fn($v) => sprintf('%s (%s, %s ha, área %s #%d)', $v['nome'], $v['codigo'], $v['area_ha'], $v['talhao'] ?? '-', $v['talhao_id']), $q->fetchAll());
        $linhas[] = 'VÁLVULAS/SETORES: ' . ($vs ? implode('; ', $vs) : 'nenhum cadastrado');
    } catch (Throwable $e) { /* segue sem o bloco */ }

    try {
        $q = $pdo->prepare(
            "SELECT a.id, a.severidade, a.titulo, a.data, t.nome AS talhao FROM agro_alertas a
              LEFT JOIN agro_talhoes t ON t.id = a.talhao_id
             WHERE a.tenant_id = ? AND a.status = 'aberto' ORDER BY FIELD(a.severidade,'critico','atencao','info'), a.data DESC LIMIT 10"
        );
        $q->execute([$tenant]);
        $as = array_map(fn($a) => sprintf('#%d [%s] %s (%s, %s)', $a['id'], $a['severidade'], $a['titulo'], $a['talhao'] ?? '-', $a['data']), $q->fetchAll());
        $linhas[] = 'ALERTAS ABERTOS: ' . ($as ? implode('; ', $as) : 'nenhum');
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare(
            "SELECT a.id, a.descricao, a.tipo, a.status, a.data_planejada, t.nome AS talhao FROM agro_atividades a
              LEFT JOIN agro_talhoes t ON t.id = a.talhao_id
             WHERE a.tenant_id = ? AND a.status IN ('planejada','em_execucao')
             ORDER BY a.data_planejada ASC LIMIT 15"
        );
        $q->execute([$tenant]);
        $hoje = date('Y-m-d');
        $ts = array_map(function ($t) use ($hoje) {
            $marca = ($t['data_planejada'] && $t['data_planejada'] < $hoje) ? ' ATRASADA' : '';
            return sprintf('#%d %s (%s, %s, %s, prevista %s%s)', $t['id'], $t['descricao'], $t['tipo'], $t['status'], $t['talhao'] ?? '-', $t['data_planejada'] ?? 'sem data', $marca);
        }, $q->fetchAll());
        $linhas[] = 'TAREFAS EM ABERTO: ' . ($ts ? implode('; ', $ts) : 'nenhuma');
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare(
            "SELECT p.nome, p.unidade, p.estoque_minimo, COALESCE(m.saldo,0) AS saldo
               FROM estoque_produtos p
               LEFT JOIN (SELECT produto_id, SUM(CASE WHEN tipo='entrada' THEN quantidade WHEN tipo='saida' THEN -quantidade WHEN tipo='ajuste' THEN quantidade ELSE 0 END) AS saldo
                            FROM estoque_movimentacoes WHERE tenant_id = ? GROUP BY produto_id) m ON m.produto_id = p.id
              WHERE p.tenant_id = ? AND p.ativo = 1 ORDER BY (COALESCE(m.saldo,0) < p.estoque_minimo) DESC, p.nome LIMIT 15"
        );
        $q->execute([$tenant, $tenant]);
        $es = array_map(function ($e) {
            $flag = ((float)$e['saldo'] < (float)$e['estoque_minimo']) ? ' ABAIXO DO MÍNIMO' : '';
            return sprintf('%s: %s %s (mín %s)%s', $e['nome'], $e['saldo'], $e['unidade'], $e['estoque_minimo'], $flag);
        }, $q->fetchAll());
        $linhas[] = 'ESTOQUE: ' . ($es ? implode('; ', $es) : 'nenhum produto');
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare(
            "SELECT id, nome, tipo, horimetro_atual, status FROM maquinas
              WHERE tenant_id = ? AND ativo = 1 ORDER BY nome LIMIT 10"
        );
        $q->execute([$tenant]);
        $ms = array_map(fn($m) => sprintf('#%d %s (%s, horímetro %s h, %s)', $m['id'], $m['nome'], $m['tipo'], $m['horimetro_atual'], $m['status']), $q->fetchAll());
        $linhas[] = 'MÁQUINAS: ' . ($ms ? implode('; ', $ms) : 'nenhuma');
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare("SELECT identificacao, data_inicio, status FROM agro_safras
                             WHERE tenant_id = ? AND status = 'ativa' ORDER BY data_inicio DESC LIMIT 3");
        $q->execute([$tenant]);
        $ss = array_map(fn($s) => sprintf('%s (desde %s)', $s['identificacao'], $s['data_inicio']), $q->fetchAll());
        $linhas[] = 'SAFRAS ATIVAS: ' . ($ss ? implode('; ', $ss) : 'nenhuma');
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare(
            "SELECT m.data_monitoramento, m.nivel_infestacao, a.nome AS alvo, a.nivel_acao, t.nome AS talhao
               FROM mip_monitoramentos m
               JOIN mip_alvos a ON a.id = m.alvo_id
               LEFT JOIN agro_talhoes t ON t.id = m.talhao_id
              WHERE m.tenant_id = ? AND m.data_monitoramento >= CURDATE() - INTERVAL 7 DAY
              ORDER BY m.data_monitoramento DESC LIMIT 12"
        );
        $q->execute([$tenant]);
        $mo = array_map(function ($m) {
            $flag = ($m['nivel_acao'] !== null && (float)$m['nivel_infestacao'] >= (float)$m['nivel_acao']) ? ' ACIMA DO NÍVEL DE AÇÃO' : '';
            return sprintf('%s: %s %s em %s%s', $m['data_monitoramento'], $m['alvo'], $m['nivel_infestacao'], $m['talhao'] ?? '-', $flag);
        }, $q->fetchAll());
        $linhas[] = 'MONITORAMENTOS MIP (últimos 7 dias): ' . ($mo ? implode('; ', $mo) : 'nenhum');
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare(
            "SELECT i.data_apontamento, i.horas, i.lamina_mm, t.nome AS talhao FROM irrigacao_apontamentos i
               LEFT JOIN agro_talhoes t ON t.id = i.talhao_id
              WHERE i.tenant_id = ? AND i.data_apontamento >= NOW() - INTERVAL 3 DAY
              ORDER BY i.data_apontamento DESC LIMIT 10"
        );
        $q->execute([$tenant]);
        $ir = array_map(fn($i) => sprintf('%s: %sh%s em %s', $i['data_apontamento'], $i['horas'], $i['lamina_mm'] ? ', ' . $i['lamina_mm'] . 'mm' : '', $i['talhao'] ?? '-'), $q->fetchAll());
        $linhas[] = 'IRRIGAÇÃO (últimos 3 dias): ' . ($ir ? implode('; ', $ir) : 'nenhuma registrada');
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare(
            "SELECT ap.tipo, ap.data, ap.status, t.nome AS talhao, MAX(COALESCE(it.carencia_dias,0)) AS carencia
               FROM agro_aplicacoes ap
               LEFT JOIN agro_talhoes t ON t.id = ap.talhao_id
               LEFT JOIN agro_aplicacao_itens it ON it.aplicacao_id = ap.id
              WHERE ap.tenant_id = ? AND ap.status IN ('planejada','registrada','validada')
                AND ap.data >= CURDATE() - INTERVAL 30 DAY
              GROUP BY ap.id ORDER BY ap.data DESC LIMIT 8"
        );
        $q->execute([$tenant]);
        $aps = array_map(function ($a) {
            $car = '';
            if ((int)$a['carencia'] > 0 && $a['data']) {
                $livre = date('d/m', strtotime($a['data'] . ' + ' . (int)$a['carencia'] . ' days'));
                $car = sprintf(' (carência %dd — livre p/ colheita em %s)', (int)$a['carencia'], $livre);
            }
            return sprintf('%s em %s, %s, %s%s', $a['tipo'], $a['talhao'] ?? '-', $a['data'], $a['status'], $car);
        }, $q->fetchAll());
        $linhas[] = 'APLICAÇÕES (últimos 30 dias): ' . ($aps ? implode('; ', $aps) : 'nenhuma');
    } catch (Throwable $e) {}

    try {
        $q = $pdo->prepare(
            "SELECT SUM(status IN ('pedido','aprovacao')) AS aguardando, SUM(status='aprovado') AS aprovados,
                    SUM(status IN ('recebido_parcial')) AS parciais, COUNT(*) AS total
               FROM compras_pedidos WHERE tenant_id = ? AND status NOT IN ('cancelado')
                AND data_pedido >= CURDATE() - INTERVAL 90 DAY"
        );
        $q->execute([$tenant]);
        $c = $q->fetch();
        $qs = $pdo->prepare("SELECT COUNT(*) FROM compras_solicitacoes WHERE tenant_id=? AND status IN ('aberta','em_cotacao')");
        $qs->execute([$tenant]);
        $sol = (int)$qs->fetchColumn();
        $linhas[] = sprintf(
            'COMPRAS (90 dias): %d pedidos (%d aguardando aprovação, %d aprovados, %d recebidos parciais); %d solicitações abertas — detalhes via ferramenta consultar_compras',
            (int)$c['total'], (int)$c['aguardando'], (int)$c['aprovados'], (int)$c['parciais'], $sol
        );
    } catch (Throwable $e) {}

    $clima = ia_clima_resumo($pdo, $tenant);
    if ($clima !== '') {
        $linhas[] = 'CLIMA NA FAZENDA: ' . $clima;
    }

    return implode("\n", $linhas);
}

/* ─────────────── auto-ensino: código-fonte + memória de aprendizados ───────────────
   Quando o manual não cobre, o assistente pode BUSCAR no código-fonte do VERO,
   LER trechos e SALVAR o que entendeu em ia_aprendizados — que é reinjetado
   nas próximas perguntas. Leitura estritamente read-only e sem segredos. */

const IA_CODIGO_EXT = ['php', 'md', 'js', 'sql'];
const IA_CODIGO_EXCLUIR_DIR = ['node_modules', '.git', 'vendor', 'storage', 'storage_private', 'logs', 'backups', '.expo'];
// A-6 (P-6): diretórios cujo conteúdo NUNCA é exposto pela IA (segredos/keys).
const IA_CODIGO_DIR_BLOQUEADO = ['config/', 'storage_private/', 'vendor/', '.git/'];

function ia_codigo_raiz(): string
{
    return dirname(__DIR__, 3); // raiz do projeto (/var/www/html no container)
}

function ia_codigo_arquivo_permitido(string $caminho): bool
{
    // Normaliza para caminho relativo à raiz (com '/'), independente do SO.
    $raiz = ia_codigo_raiz();
    $rel  = ltrim(str_replace('\\', '/', substr($caminho, strlen($raiz))), '/');
    $rel  = strtolower($rel);
    $nome = strtolower(basename($caminho));

    // 1) diretórios inteiros vedados (nunca sai nada de config/, etc.)
    foreach (IA_CODIGO_DIR_BLOQUEADO as $dir) {
        if (str_starts_with($rel, $dir) || str_contains($rel, '/' . $dir)) return false;
    }
    // 2) nomes/padrões sensíveis, onde quer que estejam
    if (str_starts_with($nome, '.env')
        || $nome === 'database.php'
        || $nome === 'fiscal_secrets.php'
        || str_contains($nome, 'secret')
        || str_ends_with($nome, '.key')
        || str_ends_with($nome, '.pem')
        || str_ends_with($nome, '.pfx')) {
        return false;
    }
    // 3) allowlist de extensão (código-fonte legível)
    $ext = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
    return in_array($ext, IA_CODIGO_EXT, true);
}

function ia_codigo_buscar(string $termo): string
{
    $termo = trim($termo);
    if (mb_strlen($termo) < 3) {
        return 'ERRO: informe um termo com pelo menos 3 caracteres.';
    }
    $raiz = ia_codigo_raiz();
    $achados = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
            function ($atual) {
                if ($atual->isDir()) {
                    return !in_array($atual->getFilename(), IA_CODIGO_EXCLUIR_DIR, true);
                }
                return ia_codigo_arquivo_permitido($atual->getPathname());
            }
        )
    );
    foreach ($it as $arquivo) {
        if (count($achados) >= 10) {
            break;
        }
        $conteudo = @file_get_contents($arquivo->getPathname(), false, null, 0, 400000);
        if ($conteudo === false || stripos($conteudo, $termo) === false) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($arquivo->getPathname(), strlen($raiz) + 1));
        foreach (explode("\n", $conteudo) as $n => $linha) {
            if (stripos($linha, $termo) !== false) {
                $achados[] = $rel . ':' . ($n + 1) . ': ' . mb_substr(trim($linha), 0, 140);
                if (count($achados) >= 10) {
                    break;
                }
            }
        }
    }
    return $achados ? implode("\n", $achados) : 'Nada encontrado para "' . $termo . '".';
}

function ia_codigo_ler(string $arquivo, int $inicio, int $fim): string
{
    $raiz = ia_codigo_raiz();
    $caminho = realpath($raiz . '/' . str_replace(['..', '\\'], ['', '/'], $arquivo));
    if ($caminho === false || !str_starts_with($caminho, $raiz) || !ia_codigo_arquivo_permitido($caminho)) {
        return 'ERRO: arquivo não permitido ou inexistente.';
    }
    foreach (IA_CODIGO_EXCLUIR_DIR as $dir) {
        if (str_contains(str_replace('\\', '/', $caminho), '/' . $dir . '/')) {
            return 'ERRO: arquivo não permitido.';
        }
    }
    $linhas = @file($caminho);
    if ($linhas === false) {
        return 'ERRO: não consegui ler o arquivo.';
    }
    $inicio = max(1, $inicio);
    $fim = min(count($linhas), max($fim, $inicio), $inicio + 40); // no máx. 40 linhas por leitura (economia de tokens)
    $saida = [];
    for ($i = $inicio; $i <= $fim; $i++) {
        $saida[] = $i . ': ' . rtrim($linhas[$i - 1]);
    }
    return implode("\n", $saida);
}

function ia_aprendizados_relevantes(PDO $pdo, int $tenant, string $pergunta): string
{
    try {
        $palavras = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($pergunta)) ?: [],
            fn($p) => mb_strlen($p) >= 5
        ));
        if (!$palavras) {
            return '';
        }
        $like = [];
        $par = [$tenant];
        foreach (array_slice($palavras, 0, 6) as $p) {
            $like[] = '(tema LIKE ? OR conteudo LIKE ?)';
            $par[] = "%{$p}%";
            $par[] = "%{$p}%";
        }
        $q = $pdo->prepare(
            'SELECT id, tema, conteudo FROM ia_aprendizados
              WHERE tenant_id = ? AND (' . implode(' OR ', $like) . ')
              ORDER BY updated_at DESC LIMIT 5'
        );
        $q->execute($par);
        $itens = $q->fetchAll();
        if (!$itens) {
            return '';
        }
        $ids = array_column($itens, 'id');
        $pdo->exec('UPDATE ia_aprendizados SET usos = usos + 1 WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        return implode("\n", array_map(
            fn($a) => '- ' . $a['tema'] . ': ' . mb_substr($a['conteudo'], 0, 600),
            $itens
        ));
    } catch (Throwable $e) {
        return '';
    }
}

/* ───────────────────── ferramentas (function calling) ─────────────────────
   Ações que o assistente pode EXECUTAR, sempre no tenant do usuário e com a
   mesma checagem de permissão das rotas normais. Escritas registram autoria. */

function ia_ferramentas(): array
{
    $f = fn(string $nome, string $desc, array $props, array $req) => [
        'type' => 'function',
        'function' => ['name' => $nome, 'description' => $desc,
            'parameters' => ['type' => 'object', 'properties' => $props, 'required' => $req]],
    ];
    return [
        $f('reconhecer_alerta', 'Marca um alerta aberto como reconhecido. Use o id numérico do alerta presente nos dados.',
            ['alerta_id' => ['type' => 'string', 'description' => 'id numérico']], ['alerta_id']),
        $f('mudar_status_tarefa', 'Inicia ou conclui uma atividade/tarefa. status: andamento|concluida.',
            ['atividade_id' => ['type' => 'string', 'description' => 'id numérico'], 'status' => ['type' => 'string', 'enum' => ['andamento', 'concluida']]], ['atividade_id', 'status']),
        $f('registrar_horimetro', 'Registra leitura de horímetro de uma máquina (não pode ser menor que a atual).',
            ['maquina_id' => ['type' => 'string', 'description' => 'id numérico'], 'horimetro' => ['type' => 'string', 'description' => 'valor numérico (ponto como decimal)']], ['maquina_id', 'horimetro']),
        $f('registrar_irrigacao', 'Registra apontamento de irrigação num talhão (horas de bombeamento; lâmina opcional em mm).',
            ['talhao_id' => ['type' => 'string', 'description' => 'id numérico'], 'horas' => ['type' => 'string', 'description' => 'valor numérico (ponto como decimal)'], 'lamina_mm' => ['type' => 'string', 'description' => 'valor numérico (ponto como decimal)']], ['talhao_id', 'horas']),
        $f('criar_apontamento', 'Cria apontamento de campo. tipo: aplicacao|nutricao|tratos_culturais|colheita|abastecimento|outro. Irrigação NÃO: usar registrar_irrigacao.',
            ['tipo' => ['type' => 'string'], 'talhao_id' => ['type' => 'string', 'description' => 'id numérico'], 'observacao' => ['type' => 'string']], ['tipo', 'talhao_id']),
        $f('consultar_monitoramentos', 'Consulta leituras MIP dos últimos N dias (padrão 30), opcionalmente por talhão.',
            ['talhao_id' => ['type' => 'string', 'description' => 'id numérico'], 'dias' => ['type' => 'string', 'description' => 'id numérico']], []),
        $f('consultar_estoque_movimentacoes', 'Consulta movimentações de estoque dos últimos N dias (padrão 30), opcionalmente filtrando pelo nome do produto.',
            ['produto_nome' => ['type' => 'string'], 'dias' => ['type' => 'string', 'description' => 'número de dias']], []),
        $f('consultar_compras', 'Lista pedidos de compra ou solicitações de compra dos últimos N dias (padrão 90). tipo: pedidos|solicitacoes. status opcional (pedidos: rascunho|pedido|aprovacao|aprovado|recebido_parcial|recebido|cancelado; solicitações: aberta|em_cotacao|convertida|cancelada).',
            ['tipo' => ['type' => 'string', 'enum' => ['pedidos', 'solicitacoes']], 'status' => ['type' => 'string'], 'dias' => ['type' => 'string', 'description' => 'número de dias']], ['tipo']),
        $f('consultar_financeiro', 'Consulta títulos financeiros (contas a pagar ou a receber) do módulo Financeiro. tipo: pagar|receber. status opcional (aberto|vencido|pago|cancelado). dias = janela de vencimento em dias (padrão 30): traz títulos vencendo dos últimos N dias em diante (mais os futuros e os sem vencimento). Devolve totais e a lista com valor, vencimento e situação.',
            ['tipo' => ['type' => 'string', 'enum' => ['pagar', 'receber']], 'status' => ['type' => 'string', 'enum' => ['aberto', 'vencido', 'pago', 'cancelado']], 'dias' => ['type' => 'string', 'description' => 'número de dias (janela de vencimento)']], ['tipo']),
        $f('consultar_pessoas', 'Lista colaboradores/operadores ATIVOS do módulo Pessoas (RH), opcionalmente filtrando pelo nome. Devolve função, tipo de vínculo, salário e custo/hora.',
            ['nome' => ['type' => 'string', 'description' => 'parte do nome do colaborador (opcional)']], []),
        $f('buscar_no_codigo', 'Busca um termo no código-fonte do VERO (telas PHP, services, migrations, docs). Use para entender COMO um processo funciona quando o manual não cobrir. Devolve arquivo:linha:trecho.',
            ['termo' => ['type' => 'string', 'description' => 'termo exato a procurar, ex.: nome de função, tabela ou texto de tela']], ['termo']),
        $f('ler_arquivo', 'Lê um trecho de um arquivo do código-fonte (máx. 60 linhas por chamada). Use após buscar_no_codigo para estudar o processo.',
            ['arquivo' => ['type' => 'string', 'description' => 'caminho relativo, ex.: includes/vero_services.php'], 'linha_inicio' => ['type' => 'string', 'description' => 'número da linha inicial'], 'linha_fim' => ['type' => 'string', 'description' => 'número da linha final']], ['arquivo', 'linha_inicio', 'linha_fim']),
        $f('salvar_aprendizado', 'Salva na sua memória permanente um processo/regra que você acabou de entender (será reutilizado em perguntas futuras). Salve APENAS conclusões confirmadas no código, nunca suposições.',
            ['tema' => ['type' => 'string', 'description' => 'título curto, ex.: "como o recebimento baixa o estoque"'], 'conteudo' => ['type' => 'string', 'description' => 'explicação objetiva do processo (até ~600 caracteres)']], ['tema', 'conteudo']),
    ];
}

function ia_executar_ferramenta(string $nome, array $arg, array $usuario): string
{
    $pdo = vero_pdo();
    $tenant = (int)$usuario['tenant_id'];
    $uid = (int)$usuario['id'];
    try {
        switch ($nome) {
            case 'reconhecer_alerta': {
                if (!api_pode('agro.ver')) return 'ERRO: usuário sem permissão.';
                $q = $pdo->prepare("UPDATE agro_alertas SET status='reconhecido', reconhecido_por=?, reconhecido_em=NOW()
                                     WHERE id=? AND tenant_id=? AND status='aberto'");
                $q->execute([$uid, (int)$arg['alerta_id'], $tenant]);
                return $q->rowCount() ? 'OK: alerta reconhecido.' : 'ERRO: alerta não encontrado ou já reconhecido.';
            }
            case 'mudar_status_tarefa': {
                if (!api_pode('agro.ver')) return 'ERRO: usuário sem permissão.';
                $st = $arg['status'] === 'concluida' ? 'concluida' : 'em_execucao';
                $q = $pdo->prepare('UPDATE agro_atividades SET status=?, updated_by=? WHERE id=? AND tenant_id=?');
                $q->execute([$st, $uid, (int)$arg['atividade_id'], $tenant]);
                return $q->rowCount() ? "OK: tarefa marcada como {$st}." : 'ERRO: tarefa não encontrada (confira o id).';
            }
            case 'registrar_horimetro': {
                if (!api_pode('maquinas.ver')) return 'ERRO: usuário sem permissão.';
                $q = $pdo->prepare('SELECT horimetro_atual FROM maquinas WHERE id=? AND tenant_id=?');
                $q->execute([(int)$arg['maquina_id'], $tenant]);
                $atual = $q->fetchColumn();
                if ($atual === false) return 'ERRO: máquina não encontrada.';
                $novo = (float)$arg['horimetro'];
                if ($novo < (float)$atual) return sprintf('ERRO: leitura %.1f menor que a atual (%.1f).', $novo, (float)$atual);
                $pdo->prepare('INSERT INTO maquina_horimetros (tenant_id, maquina_id, data_leitura, horimetro) VALUES (?,?,CURDATE(),?)')
                    ->execute([$tenant, (int)$arg['maquina_id'], $novo]);
                $pdo->prepare('UPDATE maquinas SET horimetro_atual=? WHERE id=? AND tenant_id=?')
                    ->execute([$novo, (int)$arg['maquina_id'], $tenant]);
                return sprintf('OK: horímetro registrado (%.1f h, +%.1f desde a última leitura).', $novo, $novo - (float)$atual);
            }
            case 'registrar_irrigacao': {
                if (!api_pode('irrigacao.ver')) return 'ERRO: usuário sem permissão.';
                $pdo->prepare('INSERT INTO irrigacao_apontamentos (tenant_id, talhao_id, horas, lamina_mm, data_apontamento) VALUES (?,?,?,?,NOW())')
                    ->execute([$tenant, (int)$arg['talhao_id'], (float)$arg['horas'], isset($arg['lamina_mm']) ? (float)$arg['lamina_mm'] : null]);
                return 'OK: irrigação registrada (id ' . (int)$pdo->lastInsertId() . ').';
            }
            case 'criar_apontamento': {
                if (!api_pode('agro.ver')) return 'ERRO: usuário sem permissão.';
                // Sem 'irrigacao' (guard do web 15/07 — a IA tem registrar_irrigacao p/ isso)
                $tipos = ['aplicacao', 'nutricao', 'tratos_culturais', 'colheita', 'abastecimento', 'outro'];
                $tipo = in_array($arg['tipo'] ?? '', $tipos, true) ? $arg['tipo'] : 'outro';
                $pdo->prepare("INSERT INTO agro_apontamentos (tenant_id, talhao_id, tipo, data_apontamento, origem, status, observacao, created_by)
                               VALUES (?,?,?,NOW(),'app','pendente',?,?)")
                    ->execute([$tenant, (int)$arg['talhao_id'], $tipo, mb_substr((string)($arg['observacao'] ?? 'via assistente'), 0, 255), $uid]);
                return 'OK: apontamento criado (id ' . (int)$pdo->lastInsertId() . '), status pendente de validação.';
            }
            case 'consultar_monitoramentos': {
                $dias = max(1, min(120, (int)($arg['dias'] ?? 30)));
                $sql = 'SELECT m.data_monitoramento, m.nivel_infestacao, a.nome AS alvo, a.nivel_acao, t.nome AS talhao
                          FROM mip_monitoramentos m JOIN mip_alvos a ON a.id=m.alvo_id
                          LEFT JOIN agro_talhoes t ON t.id=m.talhao_id
                         WHERE m.tenant_id=? AND m.data_monitoramento >= CURDATE() - INTERVAL ' . $dias . ' DAY';
                $par = [$tenant];
                if (!empty($arg['talhao_id'])) { $sql .= ' AND m.talhao_id=?'; $par[] = (int)$arg['talhao_id']; }
                $sql .= ' ORDER BY m.data_monitoramento DESC LIMIT 30';
                $q = $pdo->prepare($sql);
                $q->execute($par);
                $ls = array_map(fn($m) => sprintf('%s %s=%s (nível ação %s) em %s', $m['data_monitoramento'], $m['alvo'], $m['nivel_infestacao'], $m['nivel_acao'] ?? '-', $m['talhao'] ?? '-'), $q->fetchAll());
                return $ls ? implode('; ', $ls) : 'Nenhuma leitura no período.';
            }
            case 'consultar_estoque_movimentacoes': {
                $dias = max(1, min(120, (int)($arg['dias'] ?? 30)));
                $sql = 'SELECT mv.data_movimento, mv.tipo, mv.quantidade, p.nome, p.unidade
                          FROM estoque_movimentacoes mv JOIN estoque_produtos p ON p.id=mv.produto_id
                         WHERE mv.tenant_id=? AND mv.data_movimento >= NOW() - INTERVAL ' . $dias . ' DAY';
                $par = [$tenant];
                if (!empty($arg['produto_nome'])) { $sql .= ' AND p.nome LIKE ?'; $par[] = '%' . $arg['produto_nome'] . '%'; }
                $sql .= ' ORDER BY mv.data_movimento DESC LIMIT 30';
                $q = $pdo->prepare($sql);
                $q->execute($par);
                $ls = array_map(fn($m) => sprintf('%s %s %s %s de %s', $m['data_movimento'], $m['tipo'], $m['quantidade'], $m['unidade'], $m['nome']), $q->fetchAll());
                return $ls ? implode('; ', $ls) : 'Nenhuma movimentação no período.';
            }
            case 'consultar_compras': {
                if (!api_pode('compras.ver')) return 'ERRO: usuário sem permissão para o módulo Compras.';
                $dias = max(1, min(365, (int)($arg['dias'] ?? 90)));
                if (($arg['tipo'] ?? '') === 'solicitacoes') {
                    $sql = 'SELECT s.numero, s.status, s.data_solicitacao, s.justificativa, u.nome AS solicitante
                              FROM compras_solicitacoes s LEFT JOIN usuarios u ON u.id = s.solicitante_id
                             WHERE s.tenant_id=? AND s.data_solicitacao >= CURDATE() - INTERVAL ' . $dias . ' DAY';
                    $par = [$tenant];
                    if (!empty($arg['status'])) { $sql .= ' AND s.status=?'; $par[] = (string)$arg['status']; }
                    $sql .= ' ORDER BY s.data_solicitacao DESC LIMIT 20';
                    $q = $pdo->prepare($sql);
                    $q->execute($par);
                    $ls = array_map(fn($s) => sprintf('%s (%s, %s, por %s: %s)', $s['numero'], $s['status'], $s['data_solicitacao'], $s['solicitante'] ?? '-', $s['justificativa'] ?? '-'), $q->fetchAll());
                    return $ls ? implode('; ', $ls) : 'Nenhuma solicitação no período.';
                }
                $sql = 'SELECT p.numero, p.status, p.data_pedido, p.valor_total, p.data_entrega_prevista, p.acima_orcamento, f.nome AS fornecedor
                          FROM compras_pedidos p LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
                         WHERE p.tenant_id=? AND p.data_pedido >= CURDATE() - INTERVAL ' . $dias . ' DAY';
                $par = [$tenant];
                if (!empty($arg['status'])) { $sql .= ' AND p.status=?'; $par[] = (string)$arg['status']; }
                $sql .= ' ORDER BY p.data_pedido DESC LIMIT 20';
                $q = $pdo->prepare($sql);
                $q->execute($par);
                $ls = array_map(fn($p) => sprintf('%s (%s, %s, R$ %s, fornecedor %s%s%s)',
                    $p['numero'], $p['status'], $p['data_pedido'], number_format((float)$p['valor_total'], 2, ',', '.'),
                    $p['fornecedor'] ?? '-',
                    $p['data_entrega_prevista'] ? ', entrega prevista ' . $p['data_entrega_prevista'] : '',
                    ((int)$p['acima_orcamento'] === 1) ? ', ACIMA DO ORÇAMENTO' : ''), $q->fetchAll());
                return $ls ? implode('; ', $ls) : 'Nenhum pedido no período.';
            }
            case 'consultar_financeiro': {
                $tipo = ($arg['tipo'] ?? '') === 'receber' ? 'receber' : 'pagar';
                $slug = 'financeiro.contas_' . ($tipo === 'receber' ? 'receber' : 'pagar') . '.ver';
                if (!api_pode($slug)) return 'ERRO: usuário sem permissão para o módulo Financeiro (contas a ' . $tipo . ').';
                $dias = max(1, min(365, (int)($arg['dias'] ?? 30)));
                $st = (string)($arg['status'] ?? '');
                $sql = 'SELECT m.descricao, m.documento, m.valor, m.status, m.data_vencimento, m.data_pagamento, m.origem_tipo,
                               f.nome AS fornecedor
                          FROM movimentacoes_financeiras m
                          LEFT JOIN fornecedores f ON f.id = m.fornecedor_id AND f.tenant_id = m.tenant_id
                         WHERE m.tenant_id = ? AND m.tipo = ?';
                $par = [$tenant, $tipo];
                if ($st === 'vencido') {
                    $sql .= " AND m.status = 'aberto' AND m.data_vencimento IS NOT NULL AND m.data_vencimento < CURDATE()";
                } elseif (in_array($st, ['aberto', 'pago', 'cancelado'], true)) {
                    $sql .= ' AND m.status = ?';
                    $par[] = $st;
                } else {
                    $sql .= " AND m.status <> 'cancelado'";
                }
                /* janela de vencimento: dos últimos N dias em diante + futuros + sem vencimento */
                $sql .= ' AND (m.data_vencimento IS NULL OR m.data_vencimento >= CURDATE() - INTERVAL ' . $dias . ' DAY)';
                $sql .= ' ORDER BY m.data_vencimento IS NULL, m.data_vencimento, m.id DESC LIMIT 30';
                $q = $pdo->prepare($sql);
                $q->execute($par);
                $rows = $q->fetchAll();
                if (!$rows) return 'Nenhum título de contas a ' . $tipo . ' no período/situação.';
                $hoje = date('Y-m-d');
                $somaAberto = 0.0;
                $ls = array_map(function ($m) use ($hoje, &$somaAberto) {
                    $venc = $m['data_vencimento'] ? date('d/m/Y', strtotime((string)$m['data_vencimento'])) : 'sem vencimento';
                    $sit = $m['status'];
                    if ($m['status'] === 'aberto') {
                        $somaAberto += (float)$m['valor'];
                        if ($m['data_vencimento'] !== null && $m['data_vencimento'] < $hoje) {
                            $sit = 'VENCIDO';
                        }
                    }
                    $orig = $m['origem_tipo'] !== null ? str_replace('_', ' ', (string)$m['origem_tipo']) : ($m['fornecedor'] ?? 'manual');
                    return sprintf('%s: R$ %s, venc %s, %s (%s)',
                        $m['descricao'], number_format((float)$m['valor'], 2, ',', '.'), $venc, $sit, $orig ?: 'manual');
                }, $rows);
                $rotulo = $tipo === 'receber' ? 'Contas a receber' : 'Contas a pagar';
                return $rotulo . ' (' . count($rows) . ' título(s); em aberto: R$ ' . number_format($somaAberto, 2, ',', '.') . '): ' . implode('; ', $ls);
            }
            case 'consultar_pessoas': {
                if (!api_pode('pessoas.operadores.ver')) return 'ERRO: usuário sem permissão para o módulo Pessoas (RH).';
                $sql = 'SELECT nome, funcao, tipo_vinculo, salario_mensal, custo_hora, data_admissao
                          FROM agro_operadores WHERE tenant_id = ? AND ativo = 1';
                $par = [$tenant];
                if (!empty($arg['nome'])) { $sql .= ' AND nome LIKE ?'; $par[] = '%' . $arg['nome'] . '%'; }
                $sql .= ' ORDER BY nome LIMIT 30';
                $q = $pdo->prepare($sql);
                $q->execute($par);
                $rows = $q->fetchAll();
                if (!$rows) return 'Nenhum colaborador ativo encontrado.';
                $vinc = ['clt' => 'CLT', 'diarista' => 'Diarista', 'terceirizado' => 'Terceirizado', 'outro' => 'Outro'];
                $ls = array_map(function ($o) use ($vinc) {
                    $adm = $o['data_admissao'] ? ', admissão ' . date('d/m/Y', strtotime((string)$o['data_admissao'])) : '';
                    $sal = $o['salario_mensal'] !== null ? ', salário R$ ' . number_format((float)$o['salario_mensal'], 2, ',', '.') : '';
                    $ch  = ($o['custo_hora'] !== null && (float)$o['custo_hora'] > 0) ? ', custo/hora R$ ' . number_format((float)$o['custo_hora'], 2, ',', '.') : '';
                    $v   = $vinc[$o['tipo_vinculo']] ?? (string)$o['tipo_vinculo'];
                    return sprintf('%s (%s, %s)%s%s%s', $o['nome'], $o['funcao'] ?? 'sem função', $v, $sal, $ch, $adm);
                }, $rows);
                return count($rows) . ' colaborador(es) ativo(s): ' . implode('; ', $ls);
            }
            case 'buscar_no_codigo':
                return ia_codigo_buscar((string)($arg['termo'] ?? ''));
            case 'ler_arquivo':
                return ia_codigo_ler((string)($arg['arquivo'] ?? ''), (int)($arg['linha_inicio'] ?? 1), (int)($arg['linha_fim'] ?? 1));
            case 'salvar_aprendizado': {
                $tema = mb_substr(trim((string)($arg['tema'] ?? '')), 0, 120);
                $conteudo = mb_substr(trim((string)($arg['conteudo'] ?? '')), 0, 2000);
                if ($tema === '' || $conteudo === '') return 'ERRO: informe tema e conteúdo.';
                $pdo->prepare('INSERT INTO ia_aprendizados (tenant_id, tema, conteudo, criado_por) VALUES (?,?,?,?)
                               ON DUPLICATE KEY UPDATE conteudo = VALUES(conteudo), criado_por = VALUES(criado_por)')
                    ->execute([$tenant, $tema, $conteudo, $uid]);
                return 'OK: aprendizado salvo ("' . $tema . '").';
            }
        }
        return 'ERRO: ferramenta desconhecida.';
    } catch (Throwable $e) {
        error_log('[api/v1 ia ferramenta ' . $nome . '] ' . $e->getMessage());
        return 'ERRO: falha ao executar (' . $nome . ').';
    }
}

/* ============================================================
   PREVISIBILIDADE DE CUSTO (20/07) — cota diária por usuário +
   medidor de uso (tabela ia_uso) + alerta de orçamento mensal.
   Parametrizável por tenant (tenant_parametros):
     ia.cota_chats_dia      (padrão 50 conversas/usuário/dia)
     ia.cota_audio_min_dia  (padrão 20 min de áudio/usuário/dia)
     ia.orcamento_tokens_mes (padrão 15.000.000 tokens/mês — alerta aos 80%)
   Fail-open: sem a tabela/parâmetro, nada bloqueia (a cota é teto, não trava
   de funcionamento). O teto ABSOLUTO continua sendo o limite da conta OpenAI.
   ============================================================ */

function ia_param_int(string $chave, int $padrao): int
{
    if (!function_exists('vero_srv_param')) {
        return $padrao;
    }
    $v = vero_srv_param($chave);
    return $v !== null && is_numeric($v) ? (int)$v : $padrao;
}

/** Barra a chamada se a cota diária do usuário estourou (429 amigável). */
function ia_cota_verificar(array $usuario, string $tipo): void
{
    try {
        if (!vero_has_column('ia_uso', 'tipo')) {
            return; // medidor ainda não migrado — sem cota
        }
        if ($tipo === 'chat') {
            $cota = ia_param_int('ia.cota_chats_dia', 50);
            $q = vero_pdo()->prepare(
                "SELECT COUNT(*) FROM ia_uso
                  WHERE tenant_id = ? AND usuario_id = ? AND tipo = 'chat'
                    AND created_at >= CURDATE()"
            );
            $q->execute([vero_tenant(), (int)$usuario['id']]);
            if ((int)$q->fetchColumn() >= $cota) {
                api_erro('cota_excedida',
                    "Você atingiu o limite diário do assistente ({$cota} conversas). Ele volta amanhã. 😉", 429);
            }
        } else { // transcricao
            $cotaMin = ia_param_int('ia.cota_audio_min_dia', 20);
            $q = vero_pdo()->prepare(
                "SELECT COALESCE(SUM(audio_segundos),0) FROM ia_uso
                  WHERE tenant_id = ? AND usuario_id = ? AND tipo = 'transcricao'
                    AND created_at >= CURDATE()"
            );
            $q->execute([vero_tenant(), (int)$usuario['id']]);
            if ((int)$q->fetchColumn() >= $cotaMin * 60) {
                api_erro('cota_excedida',
                    "Você atingiu o limite diário de áudio ({$cotaMin} min). Digite a mensagem ou volte amanhã. 😉", 429);
            }
        }
    } catch (Throwable $e) {
        // erro de infra no medidor NUNCA derruba o assistente (fail-open);
        // api_erro() não passa por aqui — ele responde e encerra (exit)
    }
}

/** Registra o consumo da chamada + dispara o alerta de orçamento (80%/mês). */
function ia_uso_registrar(array $usuario, string $tipo, int $tokensIn, int $tokensOut, int $audioSeg = 0): void
{
    try {
        if (!vero_has_column('ia_uso', 'tipo')) {
            return;
        }
        vero_pdo()->prepare(
            'INSERT INTO ia_uso (tenant_id, usuario_id, tipo, tokens_entrada, tokens_saida, audio_segundos)
             VALUES (?,?,?,?,?,?)'
        )->execute([vero_tenant(), (int)$usuario['id'], $tipo, $tokensIn, $tokensOut, $audioSeg]);

        // Alerta de orçamento: cruzou 80% do teto mensal de tokens → 1 alerta/mês
        $orcamento = ia_param_int('ia.orcamento_tokens_mes', 15_000_000);
        if ($orcamento <= 0) {
            return;
        }
        $q = vero_pdo()->prepare(
            "SELECT COALESCE(SUM(tokens_entrada + tokens_saida),0) FROM ia_uso
              WHERE tenant_id = ? AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        );
        $q->execute([vero_tenant()]);
        $usadoMes = (int)$q->fetchColumn();
        if ($usadoMes < (int)($orcamento * 0.8)) {
            return;
        }
        $mesRef = (int)date('Ym');
        $jaTem = vero_pdo()->prepare(
            "SELECT id FROM agro_alertas
              WHERE tenant_id = ? AND origem_tipo = 'ia_orcamento' AND origem_id = ? LIMIT 1"
        );
        $jaTem->execute([vero_tenant(), $mesRef]);
        if ($jaTem->fetchColumn()) {
            return;
        }
        $pct = min(100, (int)round($usadoMes / $orcamento * 100));
        vero_insert('agro_alertas', [
            'categoria'   => 'sistema',
            'origem_tipo' => 'ia_orcamento',
            'origem_id'   => $mesRef,
            'severidade'  => 'atencao',
            'titulo'      => "Assistente de IA: {$pct}% do orçamento do mês",
            'mensagem'    => 'O consumo do assistente atingiu ' . number_format($usadoMes / 1_000_000, 1, ',', '.')
                           . ' de ' . number_format($orcamento / 1_000_000, 0, ',', '.')
                           . ' milhões de tokens do mês. Ajuste as cotas (ia.cota_chats_dia / ia.cota_audio_min_dia) ou o orçamento (ia.orcamento_tokens_mes) se necessário.',
            'status'      => 'aberto',
            'data'        => date('Y-m-d'),
        ]);
        require_once __DIR__ . '/../nucleo/push.php';
        push_notificar_tenant('Orçamento do assistente de IA',
            "O assistente atingiu {$pct}% do orçamento de tokens do mês.", ['tela' => 'Avisos']);
    } catch (Throwable $e) {
        // medidor é observabilidade — nunca quebra a resposta ao usuário
    }
}

function rota_ia_chat(array $usuario): never
{
    ia_cota_verificar($usuario, 'chat');

    $corpo = api_corpo();
    $mensagens = api_exigir_campo($corpo, 'mensagens');
    if (!is_array($mensagens) || $mensagens === []) {
        api_erro('mensagens_invalidas', 'Envie o histórico em `mensagens`.', 422);
    }

    $manualArq = dirname(__DIR__) . '/nucleo/ia_manual.md';
    $manual = is_file($manualArq) ? (string)file_get_contents($manualArq) : '';

    $sistema = 'Você é o assistente do VERO — suporte nível 1 do sistema e ajudante de campo da '
        . 'fazenda. Usuário: ' . $usuario['nome'] . ' (perfil ' . $usuario['perfil'] . ").\n\n"
        . "REGRAS:\n"
        . "- Responda em português do Brasil, curto e prático (pessoa no campo, pelo celular).\n"
        . "- FORMATO: use **negrito** apenas para destacar o essencial (números, nomes, avisos). Nenhum outro markdown (nada de ##, tabelas, links, itálico); listas com • e emojis pontuais.\n"
        . "- Dados: use APENAS o que está em DADOS ATUAIS ou o que vier das ferramentas. Nunca invente números, nomes ou datas.\n"
        . "- Dúvidas sobre o sistema (como fazer, onde fica, por que algo não funcionou): explique com suas próprias palavras usando as informações da seção MANUAL DO SISTEMA abaixo. Se o problema for de nível 2 (erro 500, dados sumidos, instalação), oriente a acionar o suporte técnico.\n"
        . "- AÇÕES: você PODE EXECUTAR ações do sistema (criar, registrar, confirmar, aprovar, receber, solicitar…) chamando as ferramentas. FLUXO OBRIGATÓRIO das escritas: (1) chame a ferramenta SEM 'confirmado' — se faltar dado ela responde 'PRECISA_DADOS' (pergunte só o que falta, agrupe quando fizer sentido) e se estiver completa responde 'CONFIRME_COM_USUARIO' com um resumo; (2) mostre esse RESUMO ao usuário e aguarde o 'sim'; (3) só então chame a ferramenta DE NOVO com confirmado:true para executar de fato. Nunca invente dados nem id — use os ids dos DADOS/consultas.\n"
        . "- Depois de executar, informe o resultado real devolvido pela ferramenta.\n"
        . "- AUTO-ENSINO: se não souber como um processo funciona e o manual não cobrir, use buscar_no_codigo e ler_arquivo para estudar o código-fonte do sistema (comece pelos includes/vero_services.php e pela tela do módulo). Ao entender, responda ao usuário E salve um resumo com salvar_aprendizado para responder na hora da próxima vez. Nunca salve suposições — só o que confirmou no código.\n"
        . "- Alertas críticos e itens ABAIXO DO MÍNIMO merecem ⚠️.\n\n"
        . "MANUAL DO SISTEMA:\n" . $manual . "\n\n"
        . "DADOS ATUAIS DA FAZENDA:\n" . ia_contexto($usuario);

    // memória de auto-ensino: aprendizados relevantes à pergunta atual
    $ultimaPergunta = '';
    foreach (array_reverse($mensagens) as $m) {
        if (($m['papel'] ?? '') !== 'assistente' && trim((string)($m['texto'] ?? '')) !== '') {
            $ultimaPergunta = (string)$m['texto'];
            break;
        }
    }
    $aprendizados = ia_aprendizados_relevantes(vero_pdo(), (int)$usuario['tenant_id'], $ultimaPergunta);
    if ($aprendizados !== '') {
        $sistema .= "\n\nAPRENDIZADOS SALVOS (você mesmo confirmou no código em conversas anteriores):\n" . $aprendizados;
    }

    // histórico do app ({papel, texto}) -> Messages API (system vai separado)
    $conversa = [];
    foreach (array_slice($mensagens, -10) as $m) {
        $texto = trim((string)($m['texto'] ?? ''));
        if ($texto === '') {
            continue;
        }
        $conversa[] = [
            'role' => ($m['papel'] ?? '') === 'assistente' ? 'assistant' : 'user',
            'content' => mb_substr($texto, 0, 1500),
        ];
    }
    if ($conversa === [] || end($conversa)['role'] !== 'user') {
        api_erro('mensagens_invalidas', 'A última mensagem deve ser do usuário.', 422);
    }

    // loop de ferramentas: consultas/código do legado + CAPACIDADES do manifesto
    // (agente operacional). Escrita das capacidades passa por handler real +
    // auditoria (ia_agente.php). As consultas em texto do legado seguem úteis.
    require_once __DIR__ . '/../nucleo/ia_agente.php';
    $caps = ia_agente_capabilities();
    $tokenBruto = api_token_bruto() ?? '';
    $sessaoIa = mb_substr((string)($corpo['session_id'] ?? ('chat-' . ($usuario['id'] ?? 0) . '-' . date('Ymd'))), 0, 64);
    $manterLegado = ['buscar_no_codigo', 'ler_arquivo', 'salvar_aprendizado',
                     'consultar_monitoramentos', 'consultar_estoque_movimentacoes', 'consultar_compras',
                     'consultar_financeiro', 'consultar_pessoas'];
    $legado = array_values(array_filter(ia_ferramentas(), fn($t) => in_array($t['function']['name'], $manterLegado, true)));
    $ferramentas = array_merge($legado, ia_agente_tools(true));
    $acoes = [];
    $confirmarResumo = null; // resumo da escrita aguardando "sim" (cartão no app)
    $estudouCodigo = false;
    $salvouAprendizado = false;
    $tokensIn = 0;  // medidor: soma o usage de todas as rodadas
    $tokensOut = 0;
    for ($rodada = 0; $rodada < 5; $rodada++) { // estudo de código pode precisar de mais rodadas
        $resp = ia_chat_completar([
            'max_tokens' => IA_MAX_TOKENS,
            'system' => $sistema,
            'messages' => $conversa,
        ], $ferramentas);
        $tokensIn  += (int)($resp['usage']['input_tokens'] ?? 0);
        $tokensOut += (int)($resp['usage']['output_tokens'] ?? 0);
        [$texto, $chamadas] = ia_resposta_partes($resp);
        if ($chamadas === []) {
            if ($texto === '') {
                error_log('[api/v1 ia] resposta vazia: ' . json_encode($resp['stop_reason'] ?? null));
                api_erro('ia_indisponivel', 'O assistente não devolveu resposta. Tente de novo.', 502);
            }
            // auto-ensino garantido: estudou código e não salvou? cobra o registro
            // numa rodada extra (o texto final ao usuário é o já produzido)
            if ($estudouCodigo && !$salvouAprendizado) {
                $conversa[] = ['role' => 'assistant', 'content' => $texto];
                $conversa[] = ['role' => 'user', 'content' => '[sistema] Registre agora a conclusão que você confirmou no código usando a ferramenta salvar_aprendizado (tema curto + explicação objetiva). Não responda texto.'];
                $extra = ia_chat_rodada([
                    'max_tokens' => IA_MAX_TOKENS,
                    'system' => $sistema,
                    'messages' => $conversa,
                ], $ferramentas, true);
                [, $extras] = $extra !== null ? ia_resposta_partes($extra) : ['', []];
                foreach ($extras as $c) {
                    if (($c['name'] ?? '') === 'salvar_aprendizado') {
                        $arg = is_array($c['input'] ?? null) ? $c['input'] : [];
                        $acoes[] = ['ferramenta' => 'salvar_aprendizado', 'resultado' => ia_executar_ferramenta('salvar_aprendizado', $arg, $usuario)];
                    }
                }
            }
            ia_uso_registrar($usuario, 'chat', $tokensIn, $tokensOut);
            api_ok(['resposta' => $texto, 'acoes' => $acoes,
                    'pendente' => $confirmarResumo !== null ? ['resumo' => $confirmarResumo] : null]);
        }
        // turno do assistente devolvido INTEIRO (blocos thinking/text/tool_use):
        // a Messages API exige os blocos de volta intactos na rodada seguinte
        $conversa[] = ['role' => 'assistant', 'content' => $resp['content']];
        $resultados = []; // todos os tool_result vão numa ÚNICA mensagem de usuário
        foreach ($chamadas as $c) {
            $nome = (string)($c['name'] ?? '');
            $arg = is_array($c['input'] ?? null) ? $c['input'] : [];
            $capId = ia_agente_id_de_tool($nome);
            if (isset($caps[$capId])) {
                // CAPACIDADE do manifesto: slot-filling → handler real → auditoria
                $cap = $caps[$capId];
                $faltam = ia_agente_faltando($cap, $arg);
                if ($faltam !== []) {
                    $itens = [];
                    foreach ($faltam as $k => $d) { $itens[] = $k . ' (' . $d . ')'; }
                    $resultado = 'PRECISA_DADOS: pergunte ao usuário — ' . implode('; ', $itens);
                } elseif (ia_agente_precisa_confirmar($cap, $arg)) {
                    // GATE ESTRUTURAL: não executa escrita sem confirmação explícita
                    $confirmarResumo = ia_agente_resumo($cap, $arg); // vira cartão no app
                    $resultado = 'CONFIRME_COM_USUARIO: ' . $confirmarResumo
                        . ' — mostre este resumo e, SÓ APÓS o "sim" do usuário, chame de novo com confirmado:true.';
                } else {
                    $confirmarResumo = null; // executou nesta rodada → não há pendência
                    unset($arg['confirmado']); // flag de gate, não vai ao handler
                    $r = ia_agente_executar($cap, $arg, $usuario, $tokenBruto, $sessaoIa);
                    $resultado = ($r['ok'] ? 'OK: ' : 'ERRO: ') . $r['mensagem']
                        . (isset($r['data']['id']) ? ' (id ' . $r['data']['id'] . ')' : '');
                }
            } else {
                $resultado = ia_executar_ferramenta($nome, $arg, $usuario);
                if ($nome === 'buscar_no_codigo' || $nome === 'ler_arquivo') $estudouCodigo = true;
                if ($nome === 'salvar_aprendizado') $salvouAprendizado = true;
            }
            $acoes[] = ['ferramenta' => $nome, 'resultado' => $resultado];
            $resultados[] = ['type' => 'tool_result', 'tool_use_id' => (string)($c['id'] ?? ''), 'content' => $resultado];
        }
        $conversa[] = ['role' => 'user', 'content' => $resultados];
    }
    api_erro('ia_indisponivel', 'O assistente não concluiu a resposta. Tente de novo.', 502);
}

function rota_ia_transcrever(array $usuario): never
{
    ia_cota_verificar($usuario, 'transcricao');

    $arquivo = $_FILES['audio'] ?? null;
    if (!$arquivo || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        api_erro('audio_ausente', 'Envie o áudio no campo `audio` (multipart).', 422);
    }
    if ((int)$arquivo['size'] > 15 * 1024 * 1024) {
        api_erro('audio_grande', 'Áudio acima de 15 MB.', 422);
    }
    $ext = strtolower(pathinfo((string)$arquivo['name'], PATHINFO_EXTENSION) ?: 'm4a');
    if (!in_array($ext, ['m4a', 'mp3', 'mp4', 'wav', 'webm', 'ogg', 'flac'], true)) {
        api_erro('audio_formato', 'Formato de áudio não suportado.', 422);
    }

    $resp = ia_openai('audio/transcriptions', null, [
        'model' => ia_env('OPENAI_MODELO_STT', IA_MODELO_STT_PADRAO),
        'language' => 'pt',
        'file' => new CURLFile((string)$arquivo['tmp_name'], (string)($arquivo['type'] ?: 'audio/m4a'), 'comando.' . $ext),
    ]);

    $texto = trim((string)($resp['text'] ?? ''));
    if ($texto === '') {
        api_erro('transcricao_vazia', 'Não entendi o áudio. Tente falar de novo.', 422);
    }
    // medidor: duração estimada pelo tamanho (AAC ~32 kbps ≈ 4 KB/s) — o
    // suficiente para a cota diária; a fatura oficial é a da OpenAI
    $segundos = max(1, (int)round(((int)$arquivo['size']) / 4000));
    ia_uso_registrar($usuario, 'transcricao', 0, 0, $segundos);
    api_ok(['texto' => $texto]);
}
