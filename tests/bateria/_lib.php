<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/_lib.php
   Harness da bateria A5-QA: guard de host, PDO, bootstrap de
   sessão CLI, asserts PASS/FAIL, cliente HTTP (curl + cookie),
   log JSON em _out/. Todos os 0..99 usam este arquivo.
   ============================================================ */

if (defined('QA_LIB_LOADED')) return;
define('QA_LIB_LOADED', true);

define('QA_DIR', __DIR__);
define('QA_ROOT', dirname(__DIR__, 2));           /* raiz do projeto (.) */
define('QA_OUT', QA_DIR . DIRECTORY_SEPARATOR . '_out');
if (!is_dir(QA_OUT)) @mkdir(QA_OUT, 0777, true);

/* ───────── Config ───────── */
function qa_env(): array
{
    static $env = null;
    if ($env === null) $env = require QA_DIR . '/_env.php';
    return $env;
}

/* ───────── Guard de host + PDO cru (asserts SQL diretos) ───────── */
function qa_db_config(): array
{
    return require QA_ROOT . '/config/database.php';
}

function qa_guard_host(): void
{
    $cfg = qa_db_config();
    $ok  = in_array($cfg['host'], qa_env()['db_hosts_permitidos'], true);
    if (!$ok) {
        fwrite(STDERR, "ABORTADO: host do banco '{$cfg['host']}' não está na lista de homologação permitida.\n");
        exit(90);
    }
}

$GLOBALS['qa_pdo'] = null;

function qa_pdo(bool $reconectar = false): PDO
{
    if ($reconectar) $GLOBALS['qa_pdo'] = null;
    if ($GLOBALS['qa_pdo'] === null) {
        qa_guard_host();
        $c = qa_db_config();
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['dbname']};charset={$c['charset']}",
            $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec("SET SESSION sql_mode = TRIM(BOTH ',' FROM REPLACE(REPLACE(REPLACE(@@SESSION.sql_mode,
            'ONLY_FULL_GROUP_BY,', ''), ',ONLY_FULL_GROUP_BY', ''), 'ONLY_FULL_GROUP_BY', ''))");
        $pdo->exec("SET time_zone = '-03:00'");
        $GLOBALS['qa_pdo'] = $pdo;
    }
    return $GLOBALS['qa_pdo'];
}

/** Executa uma query com UMA reconexão automática se o MySQL remoto tiver
 *  derrubado a conexão ociosa ("gone away"/"Lost connection") — o smoke passa
 *  minutos só em HTTP e a conexão de asserts pode expirar por wait_timeout. */
function qa_stmt(string $sql, array $p)
{
    try {
        $st = qa_pdo()->prepare($sql);
        $st->execute($p);
        return $st;
    } catch (PDOException $e) {
        $m = $e->getMessage();
        if (str_contains($m, 'gone away') || str_contains($m, 'Lost connection')
            || str_contains($m, 'server has gone') || (int)$e->getCode() === 2006) {
            $st = qa_pdo(true)->prepare($sql);
            $st->execute($p);
            return $st;
        }
        throw $e;
    }
}

function qa_val(string $sql, array $p = [])
{
    return qa_stmt($sql, $p)->fetchColumn();
}
function qa_row(string $sql, array $p = []): ?array
{
    $r = qa_stmt($sql, $p)->fetch();
    return $r === false ? null : $r;
}
function qa_rows(string $sql, array $p = []): array
{
    return qa_stmt($sql, $p)->fetchAll();
}

/* ───────── Tenants/usuários QA ───────── */
function qa_tenant_id(bool $obrigatorio = true): int
{
    $id = (int)qa_val("SELECT id FROM tenants WHERE nome = ?", [qa_env()['tenant_nome']]);
    if (!$id && $obrigatorio) {
        fwrite(STDERR, "Tenant QA não existe — rode 00_massa_canonica.php antes.\n");
        exit(91);
    }
    return $id;
}
function qa_tenant2_id(): int
{
    return (int)qa_val("SELECT id FROM tenants WHERE nome = ?", [qa_env()['tenant2_nome']]);
}
/** TODOS os tenants da bateria por padrão de nome — robusto contra duplicatas
 *  de nome deixadas por execuções interrompidas (o lookup por nome único pega
 *  só uma). Nunca casa tenants de outros agentes. */
function qa_tenant_ids_all(): array
{
    return array_map('intval', array_column(
        qa_rows("SELECT id FROM tenants WHERE nome LIKE 'QA BATERIA%'"), 'id'));
}
function qa_user_id(string $papel): int
{
    $u = qa_env()['usuarios'][$papel] ?? null;
    if (!$u) return 0;
    return (int)qa_val("SELECT id FROM usuarios WHERE tenant_id = ? AND email = ?",
        [qa_tenant_id(), $u['email']]);
}

/* ───────── Bootstrap do APP em CLI (sessão manual, padrão dos agentes) ─────────
   Carrega vero_services (→ vero_crud → db.php) e força a sessão do tenant QA.
   Os services movimentam estoque/razão/custeio exatamente como as telas. */
function qa_boot_app(?int $tenantId = null, ?int $userId = null): void
{
    qa_guard_host();
    if (!defined('QA_APP_LOADED')) {
        define('QA_APP_LOADED', true);
        require_once QA_ROOT . '/includes/vero_services.php';
        require_once QA_ROOT . '/agro/_fenologia_helper.php';
        require_once QA_ROOT . '/agro/_setor_espelho.php';
    }
    $_SESSION['tenant_id']   = $tenantId ?? qa_tenant_id();
    $_SESSION['user_id']     = $userId ?? (qa_user_id('super') ?: 1);
    $_SESSION['user_role']   = 'super_admin';
    $_SESSION['permissions'] = ['*'];
    $_SESSION['csrf_token']  = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(16));
}

/* ───────── Contadores / asserts ───────── */
$GLOBALS['qa'] = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'itens' => [], 'secao' => ''];

function qa_section(string $nome): void
{
    $GLOBALS['qa']['secao'] = $nome;
    echo "\n== {$nome} ==\n";
}
function qa_check(string $desc, bool $ok, $ctx = null): bool
{
    $q = &$GLOBALS['qa'];
    $ok ? $q['pass']++ : $q['fail']++;
    $linha = ($ok ? '[PASS] ' : '[FAIL] ') . $q['secao'] . ' :: ' . $desc;
    if (!$ok && $ctx !== null) {
        $linha .= ' | ctx=' . (is_scalar($ctx) ? (string)$ctx : json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
    echo $linha . "\n";
    $q['itens'][] = ['secao' => $q['secao'], 'desc' => $desc, 'ok' => $ok,
        'ctx' => $ok ? null : $ctx, 'skip' => false];
    return $ok;
}
function qa_eq(string $desc, $esperado, $obtido): bool
{
    return qa_check($desc, (string)$esperado === (string)$obtido,
        ['esperado' => $esperado, 'obtido' => $obtido]);
}
function qa_eqf(string $desc, float $esperado, $obtido, float $tol = 0.005): bool
{
    $o = (float)$obtido;
    return qa_check($desc, abs($esperado - $o) <= $tol,
        ['esperado' => $esperado, 'obtido' => $obtido]);
}
function qa_skip(string $desc, string $motivo): void
{
    $q = &$GLOBALS['qa'];
    $q['skip']++;
    echo "[SKIP] {$q['secao']} :: {$desc} | {$motivo}\n";
    $q['itens'][] = ['secao' => $q['secao'], 'desc' => $desc, 'ok' => null,
        'ctx' => $motivo, 'skip' => true];
}

/** Encerra o script: grava _out/<script>.json e exit != 0 se houver FAIL. */
function qa_finish(string $script): void
{
    $q = $GLOBALS['qa'];
    $res = ['script' => $script, 'quando' => date('c'),
        'pass' => $q['pass'], 'fail' => $q['fail'], 'skip' => $q['skip'], 'itens' => $q['itens']];
    file_put_contents(QA_OUT . '/' . $script . '.json',
        json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\n>>> {$script}: PASS={$q['pass']} FAIL={$q['fail']} SKIP={$q['skip']}\n";
    exit($q['fail'] > 0 ? 1 : 0);
}

/* ───────── Cliente HTTP (curl + cookie jar + CSRF) ───────── */
function qa_base(): string
{
    return rtrim(qa_env()['base_url'], '/');
}

function qa_curl(string $url, array $opts = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADER => true,
    ] + $opts);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $urlFinal = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if ($raw === false) return ['code' => 0, 'headers' => '', 'body' => '', 'url' => '', 'err' => $err];
    return ['code' => $code, 'headers' => substr($raw, 0, $hsize),
            'body' => substr($raw, $hsize), 'url' => $urlFinal, 'err' => ''];
}

/** GET seguindo a cadeia de redirects (para o guard de PÁGINA, que nega via
 *  302→landing, e para wrappers canônicos como /mip/doencas.php→alvos_controle).
 *  Retorna a resposta final + 'url' = URL efetiva onde parou. */
function qa_http_get_follow(string $papel, string $rota): array
{
    return qa_curl(qa_base() . $rota, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_COOKIEJAR => qa_cookiejar($papel), CURLOPT_COOKIEFILE => qa_cookiejar($papel)]);
}

function qa_cookiejar(string $papel): string
{
    return QA_OUT . "/cookies_{$papel}.txt";
}

function qa_http_get(string $papel, string $rota): array
{
    return qa_curl(qa_base() . $rota, [
        CURLOPT_COOKIEJAR => qa_cookiejar($papel), CURLOPT_COOKIEFILE => qa_cookiejar($papel)]);
}

function qa_http_post(string $papel, string $rota, array $campos, bool $comCsrf = true, array $arquivos = []): array
{
    if ($comCsrf && !isset($campos['csrf_token'])) {
        $campos['csrf_token'] = qa_http_csrf($papel);
    }
    $post = $arquivos ? array_merge($campos, $arquivos) : http_build_query($campos);
    return qa_curl(qa_base() . $rota, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
        CURLOPT_COOKIEJAR => qa_cookiejar($papel), CURLOPT_COOKIEFILE => qa_cookiejar($papel)]);
}

/** Token CSRF da sessão HTTP do papel. O token é carimbado no form de LOGIN e
 *  SOBREVIVE ao login (a sessão regenera o id mas preserva os dados, incl.
 *  csrf_token) — então basta capturá-lo no login e reusá-lo (validado no diag
 *  A5-QA: o mesmo token renderiza depois nas telas). Fonte universal a todos os
 *  perfis (não depende de a tela ter um form acessível). */
function qa_http_csrf(string $papel, bool $renovar = false): string
{
    return (string)($GLOBALS['qa_csrf'][$papel] ?? '');
}

/** Login HTTP real: GET pega CSRF do form, POST autentica. true = logado. */
function qa_http_login(string $papel): bool
{
    @unlink(qa_cookiejar($papel));
    unset($GLOBALS['qa_csrf'][$papel]);
    $env = qa_env();
    $u = $papel === 'tenant2' ? $env['usuario_tenant2'] : ($env['usuarios'][$papel] ?? null);
    if (!$u) return false;
    $r = qa_curl(qa_base() . '/index.php', [
        CURLOPT_COOKIEJAR => qa_cookiejar($papel), CURLOPT_COOKIEFILE => qa_cookiejar($papel)]);
    if ($r['code'] !== 200 || !preg_match('/name="csrf_token"\s+value="([0-9a-f]+)"/', $r['body'], $m)) {
        return false;
    }
    $token = $m[1];
    $r = qa_curl(qa_base() . '/index.php', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'csrf_token' => $token, 'email' => $u['email'], 'senha' => $env['senha']]),
        CURLOPT_COOKIEJAR => qa_cookiejar($papel), CURLOPT_COOKIEFILE => qa_cookiejar($papel)]);
    /* login OK = redirect para dentro do app (nunca de volta ao form com erro) */
    $ok = $r['code'] === 302 && !str_contains($r['headers'], 'index.php?erro');
    if ($ok) $GLOBALS['qa_csrf'][$papel] = $token;   /* token da sessão, estável */
    return $ok;
}

/** Heurística de saúde de uma página renderizada (smoke).
 *  200 = renderizou; 302 para uma rota INTERNA (stub de redirecionamento, ex.:
 *  /dashboard.php → /dashboard/dashboard_executivo.php) é saudável; 302 para o
 *  login = sessão caiu/sem acesso; qualquer outro código é problema. */
function qa_pagina_saudavel(array $resp): array
{
    $b = $resp['body'];
    $problemas = [];
    $redirLogin = str_contains($resp['headers'], 'Location:')
        && preg_match('#Location:\s*\S*/(index\.php|login)#i', $resp['headers']);
    if ($resp['code'] === 302) {
        if ($redirLogin) $problemas[] = 'redirect_login';
        return $problemas;                 /* redirect interno = ok */
    }
    if ($resp['code'] !== 200) $problemas[] = 'http_' . $resp['code'];
    foreach (['Fatal error', 'Parse error', 'Warning:', 'Deprecated:', 'Uncaught',
              'Parte desta página não pôde ser carregada'] as $marca) {
        if (str_contains($b, $marca)) $problemas[] = $marca;
    }
    if ($redirLogin) $problemas[] = 'redirect_login';
    return $problemas;
}

/* ───────── Limpeza compartilhada (00 e 99 usam) ─────────
   Apaga TODAS as linhas dos tenants QA em TODA tabela com tenant_id
   (descoberta dinâmica via information_schema), depois roles/usuários e
   os próprios tenants. FK checks off durante a varredura — o escopo é
   100% restrito aos tenants QA. Idempotente por construção. */
function qa_limpar_tenants(array $tenantIds, bool $dry = false): array
{
    $tenantIds = array_values(array_filter(array_map('intval', $tenantIds)));
    if (!$tenantIds) return ['tabelas' => 0, 'linhas' => 0];
    $in = implode(',', $tenantIds);
    $pdo = qa_pdo();

    $tabelas = array_column($pdo->query(
        "SELECT DISTINCT table_name AS t FROM information_schema.columns
          WHERE table_schema = DATABASE() AND column_name = 'tenant_id'
          ORDER BY table_name")->fetchAll(), 't');

    $linhas = 0;
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    try {
        /* role_permissions não tem tenant_id — via roles dos tenants QA */
        $roleIds = array_column($pdo->query(
            "SELECT id FROM roles WHERE tenant_id IN ($in)")->fetchAll(), 'id');
        if ($roleIds && !$dry) {
            $rin = implode(',', array_map('intval', $roleIds));
            $linhas += $pdo->exec("DELETE FROM role_permissions WHERE role_id IN ($rin)");
        }
        foreach ($tabelas as $t) {
            if ($t === 'tenants') continue;
            if ($dry) {
                $linhas += (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE tenant_id IN ($in)")->fetchColumn();
            } else {
                $linhas += (int)$pdo->exec("DELETE FROM `$t` WHERE tenant_id IN ($in)");
            }
        }
        if (!$dry) $linhas += (int)$pdo->exec("DELETE FROM tenants WHERE id IN ($in)");
    } finally {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    }
    return ['tabelas' => count($tabelas), 'linhas' => (int)$linhas];
}
