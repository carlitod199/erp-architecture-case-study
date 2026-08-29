<?php
/* ============================================================
   VERO — includes/auth.php  v2.0
   Sessão segura, timeouts, RBAC com wildcards, auditoria
   ============================================================
   Inclua este arquivo NO TOPO de qualquer página protegida.
   Antes de incluir, defina opcionalmente $requiredPermission:

       $requiredPermission = 'financeiro.*';
       require_once __DIR__ . '/../includes/auth.php';
   ============================================================ */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/permissions.php';

/* ── Constantes de timeout de sessão ──────────────────────── */
/* SESS-01 (auditoria de sessão): sessão caía no MEIO do uso nas 3 rodadas.
   Causa dupla: (a) gc_maxlifetime default do PHP (1440s = 24 min) é MENOR
   que o timeout de inatividade — o GC apagava o arquivo da sessão antes do
   nosso próprio limite; (b) 30 min de inatividade é curto p/ tarefas longas
   de ERP (renovação por atividade já existe — last_activity é deslizante).
   Agora: inatividade 60 min e GC alinhado ao timeout ABSOLUTO (quem manda
   nos limites são as checagens abaixo, não o GC). */
if (!defined('SESSION_INACTIVITY_TIMEOUT')) define('SESSION_INACTIVITY_TIMEOUT', 3600);  // 60 min de inatividade
if (!defined('SESSION_ABSOLUTE_TIMEOUT'))   define('SESSION_ABSOLUTE_TIMEOUT',   28800); // 8 h máximo absoluto
if (!defined('SESSION_REGEN_INTERVAL'))     define('SESSION_REGEN_INTERVAL',     600);   // regenera ID a cada 10 min

/* ── Configuração e início de sessão ──────────────────────── */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string)SESSION_ABSOLUTE_TIMEOUT); /* SESS-01 */
    /* B4: HTTPS atrás do proxy TLS de borda — mesmo critério de
       includes/security_headers.php (HTTPS on / X-Forwarded-Proto / porta 443). */
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ── CSRF token ────────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ── Verifica se está autenticado ─────────────────────────── */
if (empty($_SESSION['user_id'])) {
    _auth_redirectLogin('sessao_ausente');
}

/* ── Timeout por inatividade ───────────────────────────────── */
$_auth_now      = time();
$_auth_lastAct  = (int) ($_SESSION['last_activity'] ?? 0);
if ($_auth_lastAct > 0 && ($_auth_now - $_auth_lastAct) > SESSION_INACTIVITY_TIMEOUT) {
    _auth_expireSession('inatividade');
}

/* ── Timeout absoluto de sessão ────────────────────────────── */
$_auth_loginAt = (int) ($_SESSION['login_at'] ?? $_auth_now);
if (($_auth_now - $_auth_loginAt) > SESSION_ABSOLUTE_TIMEOUT) {
    _auth_expireSession('timeout_absoluto');
}

/* ── Regeneração periódica do ID da sessão ──────────────────────────────────
   BUG-CSRF (QA 19/07): session_regenerate_id(true) apagava a sessão antiga NA
   HORA. Com várias abas navegando em paralelo, um request em voo (ou abortado)
   ainda com o cookie antigo caía num arquivo de sessão inexistente — com
   use_strict_mode=0 o PHP recriava uma sessão VAZIA sob o mesmo id, o auth
   cunhava um token CSRF novo e o usuário sofria logout fantasma/token trocado,
   invalidando forms abertos em outras abas. Padrão recomendado no manual do
   PHP (session_create_id): a sessão antiga NÃO é apagada — recebe um carimbo
   _destroyed_at e continua legível por uma janela curta (requests em voo das
   outras abas seguem funcionando); fora da janela, expira como sessão normal. */
if (!defined('SESSION_REGEN_GRACE')) define('SESSION_REGEN_GRACE', 60); // s de vida útil do id antigo
$_auth_regenAt     = (int) ($_SESSION['_regen_at'] ?? 0);
$_auth_destroyedAt = (int) ($_SESSION['_destroyed_at'] ?? 0);
if ($_auth_destroyedAt > 0) {
    /* Chegamos com um id antigo já regenerado: dentro da janela, segue normal
       (sem regenerar de novo); fora dela, o id antigo não vale mais. */
    if (($_auth_now - $_auth_destroyedAt) > SESSION_REGEN_GRACE) {
        _auth_expireSession('sessao_regenerada');
    }
} elseif (($_auth_now - $_auth_regenAt) > SESSION_REGEN_INTERVAL) {
    $_auth_dados = $_SESSION;                       /* snapshot dos dados atuais */
    $_auth_novoId = session_create_id();
    $_SESSION['_destroyed_at'] = $_auth_now;        /* carimbo só na sessão antiga */
    session_commit();                               /* grava e fecha a sessão antiga */
    session_id($_auth_novoId);
    ini_set('session.use_strict_mode', '0');        /* aceita o id recém-criado */
    session_start();                                /* abre a sessão nova (Set-Cookie) */
    $_SESSION = $_auth_dados;                       /* migra os dados, sem o carimbo */
    $_SESSION['_regen_at'] = $_auth_now;
    unset($_auth_dados, $_auth_novoId);
}

/* Atualiza timestamp de última atividade */
$_SESSION['last_activity'] = $_auth_now;

/* ── RBAC: verificação de permissão do módulo ──────────────── */
if (!empty($requiredPermission)) {
    if (!_auth_hasPermission($requiredPermission)) {
        _auth_denyAccess($requiredPermission);
    }
}

/* ─────────────────────────────────────────────────────────────
   Funções privadas do módulo de autenticação
   ───────────────────────────────────────────────────────────── */

function _auth_redirectLogin(string $reason = ''): never {
    if (function_exists('bios_is_api_request') && bios_is_api_request()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'unauthenticated',
            'message' => 'Autenticacao necessaria.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? (BIOS_BASE . '/dashboard'));
    /* Sistemas satélite (ex.: VERO CRM) têm tela de login PRÓPRIA: definem
       BIOS_LOGIN_URL antes de incluir auth.php e o não-autenticado cai lá. */
    $loginUrl = defined('BIOS_LOGIN_URL') ? BIOS_LOGIN_URL : (BIOS_BASE . '/index');
    header('Location: ' . $loginUrl . "?redirect={$redirect}");
    exit;
}

function _auth_expireSession(string $motivo): never {
    $userId   = $_SESSION['user_id']   ?? null;
    $tenantId = $_SESSION['tenant_id'] ?? null;
    $email    = $_SESSION['user_email'] ?? null;
    $ip       = _auth_clientIp();

    _auth_tryAuditLog([
        'tenant_id'  => $tenantId,
        'user_id'    => $userId,
        'email'      => $email,
        'acao'       => 'sessao_expirada',
        'ip'         => $ip,
        'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        'status'     => 'aviso',
        'detalhes'   => "Motivo: {$motivo}",
    ]);

    session_unset();
    session_destroy();

    if (function_exists('bios_is_api_request') && bios_is_api_request()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'session_expired',
            'message' => 'Sessao expirada.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: ' . BIOS_BASE . '/index?expired=1');
    exit;
}

function _auth_denyAccess(string $permission): never {
    $userId   = $_SESSION['user_id']   ?? null;
    $tenantId = $_SESSION['tenant_id'] ?? null;
    $role     = $_SESSION['user_role'] ?? '?';
    $ip       = _auth_clientIp();

    _auth_tryAuditLog([
        'tenant_id'  => $tenantId,
        'user_id'    => $userId,
        'email'      => $_SESSION['user_email'] ?? null,
        'acao'       => 'acesso_negado',
        'ip'         => $ip,
        'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        'status'     => 'falha',
        'detalhes'   => "Permissão requerida: {$permission} | Perfil: {$role} | URL: " .
                        substr($_SERVER['REQUEST_URI'] ?? '', 0, 100),
    ]);

    if (function_exists('bios_is_api_request') && bios_is_api_request()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'forbidden',
            'message' => 'Sem permissão para esta ação.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Negação de PÁGINA: em vez de 403 seco, leva o usuário ao 1º módulo que ele
    // tem acesso (ex.: sem dashboard.view → vai ao módulo dele). Guarda anti-loop.
    $landing = function_exists('bios_landing_url')
        ? bios_landing_url((string)$role, (array)($_SESSION['permissions'] ?? []))
        : '/403';
    $curPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($landing !== '/403' && rtrim($curPath, '/') !== rtrim(BIOS_BASE . $landing, '/')) {
        header('Location: ' . BIOS_BASE . $landing);
    } else {
        header('Location: ' . BIOS_BASE . '/403');
    }
    exit;
}

/**
 * Verificação de permissão com suporte a wildcards:
 *   '*'          → acesso total
 *   'modulo.*'   → acesso a todas as ações do módulo
 *   '*.view'     → acesso de leitura em qualquer módulo
 *   'modulo.acao'→ acesso específico
 */
function _auth_hasPermission(string $permission): bool {
    $role  = $_SESSION['user_role']   ?? '';
    $perms = $_SESSION['permissions'] ?? [];

    /* Perfis com acesso irrestrito */
    if (in_array($role, ['super_admin', 'club_admin'], true)) return true;
    if (in_array('*', $perms, true)) return true;

    /* Match exato */
    if (in_array($permission, $perms, true)) return true;

    /* Wildcard de namespace: 'modulo.*' cobre 'modulo.qualquer_coisa' */
    foreach ($perms as $granted) {
        if (str_ends_with($granted, '.*')) {
            $prefix = substr($granted, 0, -1);
            if (str_starts_with($permission, $prefix)) return true;
        }
    }

    /* Wildcard de ação: '*.view' cobre 'qualquer_modulo.view' */
    $lastDot = strrpos($permission, '.');
    if ($lastDot !== false) {
        $action = substr($permission, $lastDot);
        if (in_array('*' . $action, $perms, true)) return true;
    }

    if (str_ends_with($permission, '.view')) {
        $parts = explode('.', $permission);
        while (count($parts) > 1) {
            array_pop($parts);
            if (in_array(implode('.', $parts) . '.view', $perms, true)) return true;
        }
    }

    return false;
}

function _auth_clientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/** Registra no auth_audit_logs sem quebrar o fluxo se a tabela não existir */
function _auth_tryAuditLog(array $data): void {
    try {
        if (!class_exists('Database')) return;
        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO auth_audit_logs
             (tenant_id, user_id, email, acao, ip, user_agent, status, detalhes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $data['tenant_id'] ?? null,
            $data['user_id']   ?? null,
            $data['email']     ?? null,
            $data['acao'],
            $data['ip']        ?? null,
            $data['user_agent'] ?? null,
            $data['status'],
            $data['detalhes']  ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[VERO:auth_audit] ' . $e->getMessage());
    }
}

/* ─────────────────────────────────────────────────────────────
   API pública — funções acessíveis às páginas do sistema
   ───────────────────────────────────────────────────────────── */

/** Retorna dados do usuário logado */
if (!function_exists('currentUser')) {
    function currentUser(): array {
        return [
            'id'        => (int) ($_SESSION['user_id']    ?? 0),
            'name'      => $_SESSION['user_name']   ?? 'Usuário',
            'email'     => $_SESSION['user_email']  ?? '',
            'role'      => $_SESSION['user_role']   ?? 'viewer',
            'initials'  => initials($_SESSION['user_name'] ?? 'U'),
            'tenant_id' => (int) ($_SESSION['tenant_id']  ?? 0),
            'tenant'    => $_SESSION['tenant_name'] ?? 'Clube',
        ];
    }
}

/** Verifica se o usuário logado tem determinada permissão */
if (!function_exists('hasPermission')) {
    function hasPermission(string $permission): bool {
        return _auth_hasPermission($permission);
    }
}

/** Requer autenticação — aborta com redirect ao login se ausente */
if (!function_exists('requireAuth')) {
    function requireAuth(): void {
        if (empty($_SESSION['user_id'])) {
            _auth_redirectLogin('requireAuth');
        }
    }
}

/** Requer permissão específica — aborta com 403 se insuficiente */
if (!function_exists('requirePermission')) {
    function requirePermission(string $permission): void {
        if (!_auth_hasPermission($permission)) {
            _auth_denyAccess($permission);
        }
    }
}

/** Rótulo legível para o perfil/role */
if (!function_exists('api_require_permission')) {
    function api_require_permission(string $permission): void {
        if (!_auth_hasPermission($permission)) {
            _auth_denyAccess($permission);
        }
    }
}

if (!function_exists('api_require_action_permission')) {
    function api_require_action_permission(string $action, array $map, string $defaultPermission): void {
        api_require_permission($map[$action] ?? $defaultPermission);
    }
}

if (!function_exists('roleLabel')) {
    function roleLabel(?string $role = null): string {
        $role = $role ?? ($_SESSION['user_role'] ?? '');
        /* rótulos dos perfis agro (legados de clube removidos no A0-04) */
        return match ($role) {
            'super_admin'  => 'Super Admin',
            'gestor'       => 'Gestor',
            'operador'     => 'Operador',
            'financeiro'   => 'Financeiro',
            'visualizador' => 'Visualizador',
            default        => 'Usuário',
        };
    }
}
