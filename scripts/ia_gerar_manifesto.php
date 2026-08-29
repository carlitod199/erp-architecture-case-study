<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/ia_gerar_manifesto.php
   Gerador de MANIFESTOS-BASE do Agente Operacional de IA.

   Objetivo
   --------
   Varre as FONTES DE VERDADE das ações do sistema e emite um
   arquivo .json "esqueleto" para cada capacidade que ainda NÃO
   tem manifesto curado em api/v1/ia/capabilities/. Serve de
   ponto de partida — o humano depois revisa params/regras e
   remove o marcador "_gerado".

   Fontes varridas (mesma lista do _SCHEMA.md §"Fontes de verdade")
     1) api/v1/index.php ......... tabela de rotas (POST=escrita, GET/sync=leitura)
     2) api/v1/rotas/sync.php .... casos `case 'modulo':` do sync delta
     3) includes/vero_services.php  processos vero_srv_* que MUDAM estado (web-only)
     4) includes/permissions.php / includes/menu_agro.php
                                    catálogo de slugs (usado p/ VALIDAR/derivar a
                                    permissão dos stubs, não para gerar arquivo por slug)

   Garantias (pedido do enunciado)
     - IDEMPOTENTE: rodar N vezes não muda nada além da 1ª (só cria o que falta).
     - NUNCA sobrescreve arquivo já existente.
     - Só cria o que NÃO está coberto por um manifesto (curado ou gerado).
     - Todo arquivo emitido leva "_gerado": true.

   "Coberto" = já existe um .json cujo handler.funcao (rotas) OU
   handler.rota (sync) OU id bate com o candidato.

   Uso
     php scripts/ia_gerar_manifesto.php            # cria os que faltam
     php scripts/ia_gerar_manifesto.php --dry-run  # só lista, não escreve
   ============================================================ */

$RAIZ    = dirname(__DIR__);
$CAP_DIR = $RAIZ . '/api/v1/ia/capabilities';
$INDEX   = $RAIZ . '/api/v1/index.php';
$SYNC    = $RAIZ . '/api/v1/rotas/sync.php';
$SERVICES = $RAIZ . '/includes/vero_services.php';

$dryRun = in_array('--dry-run', $argv, true);

if (!is_dir($CAP_DIR)) {
    fwrite(STDERR, "Diretório de capabilities não encontrado: {$CAP_DIR}\n");
    exit(1);
}

/* ---------- 1) índice do que JÁ está coberto ---------- */
$coveredFuncao = [];   // handler.funcao => id
$coveredRota   = [];   // handler.rota   => id
$coveredId     = [];   // id             => 1
foreach (glob($CAP_DIR . '/*.json') ?: [] as $arq) {
    $j = json_decode((string)file_get_contents($arq), true);
    if (!is_array($j)) {
        continue;
    }
    if (isset($j['id'])) {
        $coveredId[(string)$j['id']] = 1;
    }
    $f = $j['handler']['funcao'] ?? null;
    $r = $j['handler']['rota']   ?? null;
    if (is_string($f) && $f !== '') {
        $coveredFuncao[$f] = $j['id'] ?? basename($arq);
    }
    if (is_string($r) && $r !== '') {
        $coveredRota[$r] = $j['id'] ?? basename($arq);
    }
}

/* ---------- helpers ---------- */

/** Cache do conteúdo dos arquivos de rota (p/ extrair api_exigir). */
function fonte(string $arq): string
{
    static $cache = [];
    if (!isset($cache[$arq])) {
        $cache[$arq] = is_file($arq) ? (string)file_get_contents($arq) : '';
    }
    return $cache[$arq];
}

/**
 * Extrai o PRIMEIRO api_exigir('slug') dentro do corpo de uma função PHP.
 * Devolve o slug exato (fidelidade ao handler) ou null.
 */
function permissao_da_funcao(string $arquivoFonte, string $funcao): ?string
{
    $src = fonte($arquivoFonte);
    if ($src === '') {
        return null;
    }
    $pos = strpos($src, "function {$funcao}(");
    if ($pos === false) {
        return null;
    }
    // corpo = do início da função até a próxima declaração de função
    $prox = strpos($src, "\nfunction ", $pos + 1);
    $corpo = substr($src, $pos, $prox === false ? PHP_INT_MAX : $prox - $pos);
    if (preg_match("/api_exigir\\(\\s*'([^']+)'/", $corpo, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Extrai o api_exigir de UM case de sync (case 'modulo': ... até o próximo case/default).
 */
function permissao_do_sync(string $modulo): ?string
{
    global $SYNC;
    $src = fonte($SYNC);
    $pos = strpos($src, "case '{$modulo}':");
    if ($pos === false) {
        return null;
    }
    $prox = strpos($src, "\n        case ", $pos + 1);
    $default = strpos($src, "\n        default:", $pos + 1);
    $fim = min(array_filter([$prox, $default], fn($v) => $v !== false) ?: [PHP_INT_MAX]);
    $corpo = substr($src, $pos, (int)$fim - $pos);
    if (preg_match("/api_exigir\\(\\s*'([^']+)'/", $corpo, $m)) {
        return $m[1];
    }
    return null;
}

/** Rótulo legível a partir da regex de rota do index.php. */
function rota_legivel(string $regex): string
{
    $r = preg_replace('/^#\^/', '', $regex);
    $r = preg_replace('/\$#$/', '', (string)$r);
    // grupos capturados viram {n}/{decisao}
    $r = preg_replace('/\([^)]*aprovar[^)]*\)/', '{decisao}', (string)$r);
    $r = preg_replace('/\([^)]+\)/', '{id}', (string)$r);
    return '/' . ltrim((string)$r, '/');
}

/** Módulo tentado a partir do slug de permissão (base antes do 1º ponto). */
function modulo_do_slug(?string $slug): string
{
    if ($slug === null || $slug === '') {
        return 'geral';
    }
    return explode('.', $slug)[0];
}

/** Escreve o stub se não existir; devolve o status ('criado'|'existe'|'dry'). */
function emitir(string $capDir, string $id, array $manifesto, bool $dryRun): string
{
    $destino = $capDir . '/' . $id . '.json';
    if (file_exists($destino)) {
        return 'existe';
    }
    if ($dryRun) {
        return 'dry';
    }
    file_put_contents(
        $destino,
        json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
    );
    return 'criado';
}

$novos = [];
$pulados = 0;

/* ---------- 2) candidatos da TABELA DE ROTAS (index.php) ---------- */
$srcIndex = fonte($INDEX);
$reRota = "/\\[\\s*'(GET|POST)'\\s*,\\s*'([^']*)'\\s*,\\s*'([^']*)'\\s*,\\s*'([^']*)'\\s*,\\s*(true|false)\\s*\\]/";
preg_match_all($reRota, $srcIndex, $rotasMatch, PREG_SET_ORDER);

foreach ($rotasMatch as $r) {
    [, $metodo, $regex, $arquivo, $funcao, $publica] = $r;
    if ($publica === 'true') {
        continue; // login etc. — não é capacidade operacional
    }
    // sync tem tratamento próprio (por módulo) abaixo
    if ($funcao === 'rota_sync' || $funcao === 'rota_mip_alvo_produtos') {
        continue;
    }
    // rotas de INFRA (não são ações operacionais do agente): sessão, IA, push
    if (preg_match('/^rota_(auth|ia|push)_/', $funcao)) {
        continue;
    }
    if (isset($coveredFuncao[$funcao])) {
        $pulados++;
        continue; // já há manifesto curado apontando p/ este handler
    }
    $rotaLegivel = rota_legivel($regex);
    if (isset($coveredRota[$rotaLegivel])) {
        $pulados++;
        continue;
    }
    $id = 'auto_' . $funcao; // nunca colide com ids curados (dot vs underscore)
    if (isset($coveredId[$id])) {
        $pulados++;
        continue;
    }
    $slug = permissao_da_funcao($RAIZ . '/api/v1/rotas/' . $arquivo, $funcao);
    $tipo = $metodo === 'POST' ? 'escrita' : 'leitura';
    $manifesto = [
        '_gerado'   => true,
        'id'        => $id,
        'titulo'    => 'REVISAR — ' . $funcao,
        'modulo'    => modulo_do_slug($slug),
        'permissao' => $slug,               // slug exato do api_exigir (null se não achou)
        'tipo'      => $tipo,
        'confirmar' => $tipo !== 'leitura', // escrita/destrutiva => true
        'handler'   => ['metodo' => $metodo, 'rota' => $rotaLegivel, 'funcao' => $funcao],
        'params'    => new stdClass(),       // preencher com os campos reais do handler
        'resumo'    => 'REVISAR: ' . $funcao,
        'regras'    => ['stub gerado automaticamente — revisar params/regras contra o handler'],
        'inverso'   => null,
    ];
    if (emitir($CAP_DIR, $id, $manifesto, $dryRun) === 'criado' || $dryRun) {
        $novos[] = "{$id}  ({$metodo} {$rotaLegivel} → {$funcao}, perm=" . ($slug ?? '?') . ')';
    }
}

/* ---------- 3) candidatos dos MÓDULOS DE SYNC (sync.php) ---------- */
preg_match_all("/case '([a-z_]+)':/", fonte($SYNC), $syncMatch);
foreach (array_unique($syncMatch[1] ?? []) as $modulo) {
    $rotaLegivel = '/sync/' . $modulo;
    if (isset($coveredRota[$rotaLegivel])) {
        $pulados++;
        continue;
    }
    $id = 'auto_sync_' . $modulo;
    if (isset($coveredId[$id])) {
        $pulados++;
        continue;
    }
    $slug = permissao_do_sync($modulo); // null quando o case não tem api_exigir
    $manifesto = [
        '_gerado'   => true,
        'id'        => $id,
        'titulo'    => 'REVISAR — sync ' . $modulo,
        'modulo'    => modulo_do_slug($slug),
        'permissao' => $slug,   // null = handler sem api_exigir (só autenticação + tenant)
        'tipo'      => 'leitura',
        'confirmar' => false,
        'handler'   => ['metodo' => 'GET', 'rota' => $rotaLegivel, 'funcao' => 'rota_sync'],
        'params'    => [
            'desde' => ['tipo' => 'data', 'obrigatorio' => false, 'resolver' => null, 'desc' => 'cursor de delta', 'de_contexto' => true],
        ],
        'resumo'    => 'REVISAR: leitura delta de ' . $modulo,
        'regras'    => ['stub gerado automaticamente — revisar contra o case do sync.php'],
        'inverso'   => null,
    ];
    if (emitir($CAP_DIR, $id, $manifesto, $dryRun) === 'criado' || $dryRun) {
        $novos[] = "{$id}  (GET {$rotaLegivel}, perm=" . ($slug ?? 'autenticado') . ')';
    }
}

/* ---------- 4) processos vero_srv_* que MUDAM estado (web-only) ----------
   Só emitimos stub para serviços cujo nome sugere ESCRITA/estado (allowlist de
   verbos) — evita poluir com getters/calculadoras. Servem de candidatos a
   capacidades sem rota de app (ex.: estorno feito hoje só no web). */
$verbosEstado = ['estornar', 'confirmar', 'lancar', 'baixar', 'ajuste', 'devolucao', 'entrada', 'saida', 'reemitir'];
preg_match_all('/function (vero_srv_[a-z0-9_]+)\s*\(/', fonte($SERVICES), $srvMatch);
foreach (array_unique($srvMatch[1] ?? []) as $fn) {
    $ehEstado = false;
    foreach ($verbosEstado as $v) {
        if (str_contains($fn, $v)) {
            $ehEstado = true;
            break;
        }
    }
    if (!$ehEstado) {
        continue;
    }
    if (isset($coveredFuncao[$fn])) {
        $pulados++;
        continue;
    }
    $id = 'auto_srv_' . preg_replace('/^vero_srv_/', '', $fn);
    if (isset($coveredId[$id])) {
        $pulados++;
        continue;
    }
    $destruiva = str_contains($fn, 'estornar') || str_contains($fn, 'devolucao');
    $manifesto = [
        '_gerado'   => true,
        'id'        => $id,
        'titulo'    => 'REVISAR — ' . $fn,
        'modulo'    => 'geral',
        'permissao' => null,     // definir o slug ao expor este processo à IA
        'tipo'      => $destruiva ? 'destrutiva' : 'escrita',
        'confirmar' => true,
        'handler'   => ['metodo' => 'POST', 'rota' => null, 'funcao' => $fn],
        'params'    => new stdClass(),
        'resumo'    => 'REVISAR: processo ' . $fn . ' (sem rota de app — chamar serviço direto)',
        'regras'    => ['stub de processo do web (vero_srv_*) — só expor à IA após criar rota/guard'],
        'inverso'   => null,
    ];
    if (emitir($CAP_DIR, $id, $manifesto, $dryRun) === 'criado' || $dryRun) {
        $novos[] = "{$id}  (serviço {$fn})";
    }
}

/* ---------- relatório ---------- */
$modo = $dryRun ? '[DRY-RUN] ' : '';
echo "{$modo}Manifestos-base — capacidades ainda não cobertas\n";
echo str_repeat('-', 60) . "\n";
if ($novos === []) {
    echo "Nada a gerar: tudo já coberto por manifestos existentes.\n";
} else {
    foreach ($novos as $n) {
        echo ($dryRun ? '  (geraria) ' : '  + ') . $n . "\n";
    }
}
echo str_repeat('-', 60) . "\n";
printf("%s%d novo(s), %d já coberto(s)/pulado(s).\n",
    $modo, count($novos), $pulados);
