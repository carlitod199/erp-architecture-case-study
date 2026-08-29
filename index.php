<?php
require_once __DIR__ . '/includes/php_gate.php';   // B2: trava PHP>=8.1 com mensagem clara (antes de qualquer sintaxe 8.1+)
require_once __DIR__ . '/includes/bootstrap.php';
/* ============================================================
   VERO — index.php (Login)
   Tela nova integrada ao login PHP
   ============================================================ */

if (session_status() === PHP_SESSION_NONE) {
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

require_once __DIR__ . '/includes/security_headers.php';
bios_security_headers(); // HSTS + headers seguros; CSP em Report-Only (não bloqueia)

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/login_throttle.php'; // PT-03/PT-05

/* Se já logado, redireciona */
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . bios_with_base(bios_landing_url((string)($_SESSION['user_role'] ?? ''), (array)($_SESSION['permissions'] ?? []))));
    exit;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function bios_safe_redirect($value) {
    $value = trim((string)$value);
    $value = str_replace(["\r", "\n"], '', $value);

    if ($value === '') {
        return '/dashboard';
    }

    /* Bloqueia redirecionamento externo: só aceita caminhos internos */
    if ($value[0] !== '/' || substr($value, 0, 2) === '//') {
        return '/dashboard';
    }

    return $value;
}

/* Prefixa BIOS_BASE em caminhos internos quando ainda não está presente.
   Evita redirect para a raiz do host quando o app roda em subpasta (ex.: /bios_a). */
function bios_with_base(string $path): string {
    $b = defined('BIOS_BASE') ? BIOS_BASE : '';
    if ($b === '' || $path === '' || $path[0] !== '/') {
        return $path;
    }
    if ($path === $b || str_starts_with($path, $b . '/')) {
        return $path;
    }
    return $b . $path;
}

function bios_login_ident($name) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', (string)$name)) {
        throw new RuntimeException('Identificador inválido.');
    }
    return '`' . $name . '`';
}

function bios_login_table_exists(PDO $pdo, $table) {
    static $cache = [];
    $table = (string)$table;
    if (isset($cache[$table])) return $cache[$table];
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return $cache[$table] = false;

    try {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        return $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[VERO][login][table_exists] ' . $e->getMessage());
        return $cache[$table] = false;
    }
}

function bios_login_column_exists(PDO $pdo, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    if (!preg_match('/^[a-zA-Z0-9_]+$/', (string)$table) || !preg_match('/^[a-zA-Z0-9_]+$/', (string)$column)) {
        return $cache[$key] = false;
    }

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM ' . bios_login_ident($table) . ' LIKE ' . $pdo->quote($column));
        return $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[VERO][login][column_exists] ' . $e->getMessage());
        return $cache[$key] = false;
    }
}

function bios_login_first_column(PDO $pdo, $table, array $columns) {
    foreach ($columns as $column) {
        if (bios_login_column_exists($pdo, $table, $column)) return $column;
    }
    return null;
}

function bios_login_pick(array $row, array $keys, $default = '') {
    foreach ($keys as $key) {
        if ($key && array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return $default;
}

function bios_login_find_user(PDO $pdo, $login) {
    $login = trim((string)$login);
    if ($login === '') return null;

    foreach (['usuarios', 'users'] as $table) {
        if (!bios_login_table_exists($pdo, $table)) continue;

        $loginCols = [];
        foreach (['email', 'login', 'usuario'] as $col) {
            if (bios_login_column_exists($pdo, $table, $col)) $loginCols[] = $col;
        }
        if (!$loginCols) continue;

        $where = [];
        $params = [];
        foreach ($loginCols as $col) {
            $where[] = 'LOWER(' . bios_login_ident($col) . ') = LOWER(?)';
            $params[] = $login;
        }

        $sql = 'SELECT * FROM ' . bios_login_ident($table) . ' WHERE (' . implode(' OR ', $where) . ')';

        if (bios_login_column_exists($pdo, $table, 'deleted_at')) {
            $sql .= ' AND (`deleted_at` IS NULL OR `deleted_at` = "0000-00-00 00:00:00")';
        }

        $tenantCol = bios_login_first_column($pdo, $table, ['tenant_id', 'tenant']);
        if ($tenantCol) {
            $sql .= ' ORDER BY CASE WHEN ' . bios_login_ident($tenantCol) . ' = 1 THEN 0 ELSE 1 END, `id` ASC';
        } else {
            $sql .= ' ORDER BY `id` ASC';
        }
        $sql .= ' LIMIT 1';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $user['_bios_table'] = $table;
                return $user;
            }
        } catch (Throwable $e) {
            error_log('[VERO][login][find_user] ' . $e->getMessage());
        }
    }

    return null;
}

function bios_login_is_active(PDO $pdo, array $user) {
    $table = (string)($user['_bios_table'] ?? '');
    $statusCol = $table ? bios_login_first_column($pdo, $table, ['status', 'ativo', 'active']) : null;

    if (!$statusCol || !array_key_exists($statusCol, $user)) return false;

    $raw = trim((string)$user[$statusCol]);
    if ($raw === '') return false;

    $lower = strtolower($raw);
    if (in_array($lower, ['ativo', 'active', 'habilitado', 'sim', 's', 'a', '1'], true)) return true;
    if (in_array($lower, ['inativo', 'inactive', 'desativado', 'bloqueado', 'nao', 'não', 'n', 'i', '0'], true)) return false;

    return false;
}

function bios_login_verify_password($senha, $stored) {
    $stored = (string)$stored;
    if ($stored === '') return false;

    return password_get_info($stored)['algo'] !== 0 && password_verify($senha, $stored);
}

function bios_login_rehash_password(PDO $pdo, array $user, string $passCol, string $senha, string $stored): void {
    if (!password_needs_rehash($stored, PASSWORD_BCRYPT, ['cost' => 12])) return;

    $table = (string)($user['_bios_table'] ?? '');
    $id = (int)($user['id'] ?? 0);
    if ($table === '' || $id <= 0 || $passCol === '') return;

    try {
        $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare(
            'UPDATE ' . bios_login_ident($table)
            . ' SET ' . bios_login_ident($passCol) . ' = ? WHERE id = ? LIMIT 1'
        )->execute([$hash, $id]);
        error_log('[VERO][login] Hash de senha atualizado para bcrypt cost 12. user_id=' . $id);
    } catch (Throwable $e) {
        error_log('[VERO][login][rehash] ' . $e->getMessage());
    }
}

function bios_login_role_data(PDO $pdo, array $user) {
    $table = (string)($user['_bios_table'] ?? '');
    $roleCode = (string)bios_login_pick($user, ['role', 'perfil', 'tipo'], 'viewer');
    $roleId = (int)bios_login_pick($user, ['role_id', 'perfil_id'], 0);

    if ($roleId <= 0 && $roleCode !== '' && bios_login_table_exists($pdo, 'roles')) {
        $codeCol = bios_login_first_column($pdo, 'roles', ['slug', 'codigo', 'code', 'role', 'perfil']);
        $tenantCol = bios_login_first_column($pdo, 'roles', ['tenant_id', 'tenant']);
        $userTenantCol = $table ? bios_login_first_column($pdo, $table, ['tenant_id', 'tenant']) : null;
        $tenantId = (int)bios_login_pick($user, [$userTenantCol ?: 'tenant_id'], 0);

        if ($codeCol) {
            try {
                $sql = 'SELECT id FROM roles WHERE ' . bios_login_ident($codeCol) . ' = ?';
                $params = [$roleCode];
                if ($tenantCol && $tenantId > 0) {
                    $sql .= ' AND ' . bios_login_ident($tenantCol) . ' = ?';
                    $params[] = $tenantId;
                }
                $stmt = $pdo->prepare($sql . ' LIMIT 1');
                $stmt->execute($params);
                $roleId = (int)$stmt->fetchColumn();
            } catch (Throwable $e) {
                error_log('[VERO][login][role_lookup] ' . $e->getMessage());
            }
        }
    }

    if ($roleId > 0 && bios_login_table_exists($pdo, 'roles')) {
        $nameCol = bios_login_first_column($pdo, 'roles', ['nome', 'name', 'titulo']);
        $codeCol = bios_login_first_column($pdo, 'roles', ['slug', 'codigo', 'code', 'role', 'perfil']);
        try {
            $stmt = $pdo->prepare('SELECT * FROM roles WHERE id = ? LIMIT 1');
            $stmt->execute([$roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($role) {
                $roleCode = (string)bios_login_pick($role, [$codeCol, 'slug', 'codigo', 'code', 'role', 'perfil'], $roleCode);
            }
        } catch (Throwable $e) {
            error_log('[VERO][login][role_data] ' . $e->getMessage());
        }
    }

    if ($roleCode === '') $roleCode = 'viewer';
    return ['id' => $roleId, 'code' => $roleCode];
}

function bios_login_permissions(PDO $pdo, $roleCode, $roleId = 0) {
    $roleCode = (string)$roleCode;

    /* B4 (auditoria Go-Live): SOMENTE super_admin recebe acesso total por
       string mágica. 'club_admin'/'admin' foram removidos — não existem como
       role no banco e permitiam escalonamento (criar perfil de slug 'admin'
       → login concedia '*'). Qualquer outro perfil resolve por role_permissions. */
    if ($roleCode === 'super_admin') {
        return ['*'];
    }

    $permissions = [];

    if ($roleId > 0 && bios_login_table_exists($pdo, 'role_permissions') && bios_login_table_exists($pdo, 'permissions')) {
        $rpRoleCol = bios_login_first_column($pdo, 'role_permissions', ['role_id', 'perfil_id']);
        $rpPermCol = bios_login_first_column($pdo, 'role_permissions', ['permission_id', 'permissao_id']);
        $permCodeCol = bios_login_first_column($pdo, 'permissions', ['slug', 'codigo', 'code', 'permission', 'permissao']);

        if ($rpRoleCol && $rpPermCol && $permCodeCol) {
            try {
                $sql = 'SELECT p.' . bios_login_ident($permCodeCol) . ' AS permissao '
                     . 'FROM role_permissions rp '
                     . 'JOIN permissions p ON p.id = rp.' . bios_login_ident($rpPermCol) . ' '
                     . 'WHERE rp.' . bios_login_ident($rpRoleCol) . ' = ?';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$roleId]);
                $permissions = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
            } catch (Throwable $e) {
                error_log('[VERO][login][permissions] ' . $e->getMessage());
            }
        }
    }

    if ($permissions) return $permissions;

    require_once __DIR__ . '/includes/permissions.php';
    $map = bios_default_role_permissions();
    return $map[$roleCode] ?? [];
}

function bios_login_tenant_name(PDO $pdo, $tenantId) {
    $tenantId = (int)$tenantId;
    if ($tenantId <= 0 || !bios_login_table_exists($pdo, 'tenants')) return 'VERO';

    try {
        $stmt = $pdo->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tenant) return 'VERO';
        return (string)bios_login_pick($tenant, ['nome', 'name', 'tenant_name'], 'VERO');
    } catch (Throwable $e) {
        error_log('[VERO][login][tenant_name] ' . $e->getMessage());
        return 'VERO';
    }
}

function bios_login_touch_last_access(PDO $pdo, array $user) {
    $table = (string)($user['_bios_table'] ?? '');
    $id = (int)($user['id'] ?? 0);
    if ($table === '' || $id <= 0) return;

    $lastCol = bios_login_first_column($pdo, $table, ['ultimo_login', 'ultimo_acesso', 'last_login', 'last_activity']);
    if (!$lastCol) return;

    try {
        $value = $lastCol === 'last_activity' ? time() : date('Y-m-d H:i:s');
        $stmt = $pdo->prepare('UPDATE ' . bios_login_ident($table) . ' SET ' . bios_login_ident($lastCol) . ' = ? WHERE id = ? LIMIT 1');
        $stmt->execute([$value, $id]);
    } catch (Throwable $e) {
        error_log('[VERO][login][last_access] ' . $e->getMessage());
    }
}

$error = '';
$noticeType = '';
$noticeMessage = '';
$redirect = bios_safe_redirect($_POST['redirect'] ?? $_GET['redirect'] ?? '/dashboard');

if (isset($_GET['logout'])) {
    $noticeType = 'info';
    $noticeMessage = 'Sessão encerrada com segurança.';
}

/* Processamento do login */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* BUG-CSRF (QA 19/07): valida com a janela de tolerância (csrf_token_valido)
       p/ um form de login aberto noutra aba não morrer após rotação por falha. */
    if (!csrf_token_valido((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Sessão expirada ou requisição inválida. Atualize a página e tente novamente.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if ($email === '' || $senha === '') {
            $error = 'Preencha e-mail e senha para continuar.';
        } elseif (bios_login_throttle_bloqueado($pdo, $email, bios_login_ip())) {
            /* PT-03: excesso de tentativas → recusa sem validar (sem bcrypt). */
            bios_login_dummy_verify($senha);
            $error = 'Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.';
        } else {
            try {
                $user = bios_login_find_user($pdo, $email);

                if (!$user) {
                    /* PT-05: normaliza o tempo (roda bcrypt dummy) e mensagem única. */
                    bios_login_dummy_verify($senha);
                    bios_login_throttle_log($pdo, $email, bios_login_ip(), false);
                    $error = 'Credenciais inválidas. Verifique e tente novamente.';
                } elseif (!bios_login_is_active($pdo, $user)) {
                    /* PT-05: não revela que a conta existe mas está inativa. */
                    bios_login_dummy_verify($senha);
                    bios_login_throttle_log($pdo, $email, bios_login_ip(), false, (int)($user['tenant_id'] ?? 0) ?: null, (int)$user['id']);
                    $error = 'Credenciais inválidas. Verifique e tente novamente.';
                } else {
                    $table = (string)($user['_bios_table'] ?? '');
                    $passCol = $table ? bios_login_first_column($pdo, $table, ['senha_hash', 'password_hash', 'senha', 'password']) : null;
                    $storedPassword = $passCol ? (string)($user[$passCol] ?? '') : '';

                    if (!bios_login_verify_password($senha, $storedPassword)) {
                        /* PT-03: falha de senha conta para o throttle. */
                        bios_login_throttle_log($pdo, $email, bios_login_ip(), false, (int)($user['tenant_id'] ?? 0) ?: null, (int)$user['id']);
                        $error = 'Credenciais inválidas. Verifique e tente novamente.';
                    } else {
                        bios_login_throttle_log($pdo, $email, bios_login_ip(), true, (int)($user['tenant_id'] ?? 0) ?: null, (int)$user['id']);
                        bios_login_rehash_password($pdo, $user, (string)$passCol, $senha, $storedPassword);
                        $tenantCol = $table ? bios_login_first_column($pdo, $table, ['tenant_id', 'tenant']) : null;
                        $tenantId = (int)($tenantCol ? bios_login_pick($user, [$tenantCol], 0) : 0);
                        if ($tenantId <= 0) {
                            throw new RuntimeException('Usuário sem tenant válido.');
                        }

                        $roleData = bios_login_role_data($pdo, $user);
                        $roleCode = (string)$roleData['code'];
                        $roleId   = (int)$roleData['id'];

                        $userName  = (string)bios_login_pick($user, ['nome', 'name', 'usuario', 'login', 'email'], $email);
                        $userEmail = (string)bios_login_pick($user, ['email', 'login', 'usuario'], $email);

                        bios_login_touch_last_access($pdo, $user);

                        session_regenerate_id(true);
                        $_SESSION['user_id']       = (int)$user['id'];
                        $_SESSION['user_name']     = $userName;
                        $_SESSION['user_email']    = $userEmail;
                        $_SESSION['user_role']     = $roleCode;
                        $_SESSION['tenant_id']     = $tenantId;
                        $_SESSION['tenant_name']   = bios_login_tenant_name($pdo, $tenantId);
                        $_SESSION['permissions']   = bios_login_permissions($pdo, $roleCode, $roleId);
                        $_SESSION['last_activity'] = time();
                        // Timeout absoluto (auditoria seg. 23/07, A-6): sem login_at, auth.php:60
                        // caía em now-now=0 e o teto absoluto nunca disparava. Carimba a origem
                        // da sessão e da última regeneração de id.
                        $_SESSION['login_at']  = time();
                        $_SESSION['_regen_at'] = time();
                        // Troca de senha obrigatória (contas que estavam com senha fraca/texto puro).
                        $_SESSION['must_change_password'] = !empty($user['senha_trocar']);

                        // Sem redirect explícito: leva ao 1º módulo/tela que o usuário acessa
                        // (se não tiver acesso ao dashboard, não cai em 403).
                        if ($redirect === '/dashboard') {
                            $redirect = bios_landing_url((string)$roleCode, (array)$_SESSION['permissions']);
                        }
                        header('Location: ' . bios_with_base($redirect));
                        exit;
                    }
                }
            } catch (Throwable $e) {
                error_log('[VERO][login] ' . $e->getMessage());
                $error = 'Erro interno ao validar login. Verifique a conexão com o banco.';
            }
        }
    }

    if ($error !== '') {
        $noticeType = 'error';
        $noticeMessage = $error;
        /* BUG-CSRF (QA 19/07): rotação com janela de tolerância — forms de outras
           abas da mesma sessão continuam válidos (csrf_rotate em functions.php). */
        csrf_rotate();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#005059">
<title>VERO</title>
  <link rel="icon" type="image/svg+xml" href="<?= h(bios_with_base('/assets/img/brand/vero-symbol.svg')) ?>">
  <link rel="icon" type="image/png" sizes="48x48" href="<?= h(bios_with_base('/assets/img/favicon-vero.png')) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= h(bios_with_base('/assets/img/favicon-vero-32.png')) ?>">
  <link rel="apple-touch-icon" href="<?= h(bios_with_base('/assets/img/favicon-vero-180.png')) ?>">

<!-- =========================================================================
     FONTES
     Hanken Grotesk  -> interface / corpo
     IBM Plex Mono   -> microcopy técnica (status, versão, "acesso seguro")
     ========================================================================= -->
<link rel="stylesheet" href="<?= h(bios_with_base('/assets/vendor/fonts/vero-fonts.css')) ?>"><!-- QA-013: fontes self-host (sem CDN) -->

<style>
/* =========================================================================
   1. TOKENS / PALETA VERO
   Fundo escuro azulado, traços de circuito em azul-aço, brilho ciano/teal.
   ========================================================================= */
:root{
  --bg:           #005059;   /* fundo base                         */
  --bg-2:         #08262A;   /* fundo secundário                   */
  --surface:      rgb(17 59 65 / 76%);    /* painel (vidro fosco) */
  --surface-2:    rgba(0, 54, 61, .92);   /* campos               */
  --border:       rgba(78, 156, 161, .18);
  --border-hi:    rgba(78, 156, 161, .30);
  --line:         #2A767C;   /* linhas de circuito                 */

  --accent:       #005059;   /* teal/ciano VERO                    */
  --accent-deep:  #00363D;
  --accent-soft:  rgba(0, 80, 89, .14);
  --accent-glow:  rgba(0, 80, 89, .40);

  --text:         #F3EFE6;
  --text-2:       #DBD1C1;
  --muted:        #AEA08B;
  --gold:         #E2C275;   /* tom dourado — acentos de texto      */
  --gold-soft:    #C9A961;

  --danger:       #E2787C;
  --danger-soft:  rgba(226, 120, 124, .12);
  --success:      #0E7E72;
  --success-soft: rgba(14, 126, 114, .12);

  --r:            14px;      /* raio padrão                        */
  --ease:         cubic-bezier(.22, .61, .36, 1);
  --ease-soft:    cubic-bezier(.4, 0, .2, 1);
}

/* =========================================================================
   2. RESET / BASE
   ========================================================================= */
*{ margin:0; padding:0; box-sizing:border-box; }
html,body{ height:100%; }
body{
  font-family:'Hanken Grotesk', system-ui, -apple-system, sans-serif;
  background:var(--bg);
  color:var(--text);
  -webkit-font-smoothing:antialiased;
  text-rendering:optimizeLegibility;
  overflow:hidden;
}
.mono{ font-family:'IBM Plex Mono', ui-monospace, monospace; }

/* =========================================================================
   3. PALCO / ATMOSFERA (fundo de circuito + brilhos suaves)
   ========================================================================= */
.stage{
  position:fixed; inset:0;
  display:grid; place-items:center;
  /* banner de fundo VERO (assets/img/vero_login_bg.svg) sobre o teal da marca;
     caminho relativo = funciona em subpasta (/vero) e na raiz */
  background:#005059 url('assets/img/vero_login_bg.svg') center/cover no-repeat;
  overflow:hidden;
}
/* fundo sólido: camadas decorativas/animadas do palco desativadas */
.stage::before,
.stage::after{ display:none; }
/* respeita usuários que pedem menos movimento */
@media (prefers-reduced-motion:reduce){
  *{ animation-duration:.001ms !important; animation-iteration-count:1 !important; transition-duration:.06s !important; }
}

/* (Splash de abertura removido — sem animação ao abrir o login.) */

/* =========================================================================
   5. BLOCO DE AUTENTICAÇÃO (logo acima + painel)
   ========================================================================= */
.auth{
  position:relative; z-index:10;
  width:min(92vw, 388px);
  display:flex; flex-direction:column; align-items:center;
  padding:24px 4px;
  transition:opacity .4s var(--ease), transform .4s var(--ease);
}
/* ao entrar (loading), o formulário some deixando só o ícone girando */
.stage.is-leaving .auth{
  opacity:0; transform:scale(.97) translateY(-4px); pointer-events:none;
}

/* --- logo: acima do painel, centralizada, com destaque, sem moldura --- */
.brand{
  display:flex; flex-direction:column; align-items:center; gap:14px;
  margin-bottom:30px;
  opacity:0; transform:translateY(14px);
  animation:rise .9s var(--ease) .15s forwards;
}
.brand__logo{
  width:clamp(170px,52vw,215px); height:auto; display:block;
  filter:drop-shadow(0 0 26px rgba(78,156,161,.22));
  animation:float 7s var(--ease-soft) infinite alternate;
}
.brand__eyebrow{
  font-size:10.5px; letter-spacing:.42em; text-transform:uppercase;
  color:var(--gold);
}
.brand__wordmark{
  font:700 clamp(28px,10vw,44px) 'IBM Plex Sans',sans-serif;
  letter-spacing:.3em; color:#005059; text-align:center; line-height:1;
}

/* --- painel --- */
.panel{
  width:100%;
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:calc(var(--r) + 4px);
  padding:30px 28px 26px;
  backdrop-filter:blur(16px) saturate(115%);
  box-shadow:
    0 1px 0 rgba(255,255,255,.04) inset,
    0 30px 60px -28px rgba(0,0,0,.85);
  opacity:0; transform:translateY(18px);
  animation:rise .9s var(--ease) .34s forwards;
}
.panel.is-shake{ animation:shake .5s var(--ease-soft); }

/* fina linha superior de "energia" */
.panel__rule{
  height:2px; width:46px; margin:0 auto 22px;
  border-radius:2px;
  background:linear-gradient(90deg, transparent, var(--accent), transparent);
  opacity:.65;
}

/* =========================================================================
   6. CAMPOS DO FORMULÁRIO
   ========================================================================= */
.field{
  position:relative; margin-bottom:16px;
  opacity:0; transform:translateY(10px);
  animation:rise .7s var(--ease) forwards;
}
.field--1{ animation-delay:.5s; }
.field--2{ animation-delay:.58s; }

.field > label{
  display:block; font-size:11.5px; font-weight:500;
  letter-spacing:.02em; color:var(--text-2);
  margin:0 0 7px 2px; transition:color .2s var(--ease);
}
.input{
  display:flex; align-items:center;
  background:var(--surface-2);
  border:1px solid var(--border);
  border-radius:var(--r);
  transition:border-color .22s var(--ease), box-shadow .22s var(--ease), background .22s;
}
.input__icon{
  display:grid; place-items:center;
  width:42px; height:46px; flex:0 0 auto;
  color:var(--muted); transition:color .22s var(--ease);
}
.input__icon svg{ width:18px; height:18px; }
.input input{
  flex:1 1 auto; min-width:0;
  background:transparent; border:0; outline:0;
  color:var(--text); font:inherit; font-size:14.5px;
  padding:14px 14px 14px 8px;
}
.input input::placeholder{ color:var(--muted); opacity:.7; }
/* o container .input:focus-within já dá o anel arredondado — evita contorno retangular saindo dos cantos */
.input input:focus,
.input input:focus-visible{ outline:none; }
/* autofill: manter EXATAMENTE o mesmo padding do estado normal p/ o texto
   não "pular" na horizontal quando o autofill sai (clique/digitação) */
.input input:-webkit-autofill,
.input input:-webkit-autofill:hover,
.input input:-webkit-autofill:focus{
  padding:14px 14px 14px 8px;
  -webkit-text-fill-color:var(--text);
  -webkit-box-shadow:0 0 0 40px #0B3035 inset;
  caret-color:var(--text);
  /* trava o amarelo do autofill sem transição abrupta de cor */
  transition:background-color 9999s ease-out 0s;
}
/* botão mostrar/ocultar senha */
.input__toggle{
  display:grid; place-items:center;
  width:44px; height:46px; flex:0 0 auto;
  background:transparent; border:0; cursor:pointer;
  color:var(--muted); transition:color .2s var(--ease);
}
.input__toggle:hover{ color:var(--text-2); }
.input__toggle svg{ width:18px; height:18px; }

/* estado de foco — destaque sutil na cor VERO */
.input:focus-within{
  border-color:var(--accent);
  background:#0B3035;
  box-shadow:0 0 0 3px var(--accent-soft);
}
.input:focus-within ~ label,
.field:focus-within > label{ color:var(--accent); }
.input:focus-within .input__icon{ color:var(--accent); }

/* erro por campo */
.field.is-invalid .input{
  border-color:rgba(226,120,124,.55);
  box-shadow:0 0 0 3px var(--danger-soft);
}
.field.is-invalid .input__icon{ color:var(--danger); }

/* =========================================================================
   7. LINHA DE OPÇÕES + BOTÃO
   ========================================================================= */
.row{
  display:flex; justify-content:flex-end; align-items:center;
  margin:2px 2px 18px;
  opacity:0; animation:rise .7s var(--ease) .66s forwards;
}
.link{
  background:none; border:0; cursor:pointer;
  color:var(--text-2); font:inherit; font-size:12.5px;
  text-decoration:none; transition:color .2s var(--ease);
}
.link:hover{ color:var(--accent); }

.btn{
  position:relative; width:100%; height:48px;
  display:inline-flex; align-items:center; justify-content:center; gap:9px;
  border:0; border-radius:var(--r); cursor:pointer;
  font:inherit; font-size:14.5px; font-weight:600; letter-spacing:.01em;
  color:#EDEAE0;
  background:linear-gradient(180deg, #4E9CA1, var(--accent));
  box-shadow:0 10px 26px -12px var(--accent-glow), 0 1px 0 rgba(255,255,255,.25) inset;
  overflow:hidden;
  transition:transform .15s var(--ease), box-shadow .25s var(--ease), filter .2s, background .3s;
  opacity:0; animation:rise .7s var(--ease) .72s forwards;
}
.btn:hover{ filter:brightness(1.05); box-shadow:0 14px 30px -12px var(--accent-glow); }
.btn:active{ transform:translateY(1px); }
.btn:disabled{ cursor:default; }
.btn__label{ display:inline-flex; align-items:center; gap:8px; transition:opacity .2s; }
.btn__spinner{
  position:absolute; inset:0; margin:auto;
  width:20px; height:20px; border-radius:50%;
  border:2.4px solid rgba(237,234,224,.25); border-top-color:#EDEAE0;
  opacity:0; animation:spin .7s linear infinite;
}
.btn svg{ width:18px; height:18px; }

/* loading */
.btn.is-loading{ color:transparent; }
.btn.is-loading .btn__label{ opacity:0; }
.btn.is-loading .btn__spinner{ opacity:1; }
/* sucesso */
.btn.is-success{
  background:linear-gradient(180deg, #4E9CA1, var(--success));
  color:#EDEAE0;
  box-shadow:0 12px 28px -12px rgba(14,126,114,.5);
}
.btn.is-success .btn__spinner{ opacity:0; }
.btn.is-success .btn__label{ opacity:1; color:#EDEAE0; }

/* campos desabilitados durante o envio */
.panel.is-busy .input{ opacity:.6; pointer-events:none; }
.panel.is-busy .link{ opacity:.5; pointer-events:none; }

/* =========================================================================
   8. MENSAGENS (aria-live) — discretas
   ========================================================================= */
.notice{
  min-height:0; overflow:hidden;
  display:flex; align-items:center; gap:9px;
  margin:0 2px; padding:0 12px;
  border-radius:10px; font-size:12.8px;
  max-height:0; opacity:0;
  transition:max-height .3s var(--ease), opacity .3s var(--ease), margin .3s, padding .3s;
}
.notice svg{ width:15px; height:15px; flex:0 0 auto; }
.notice.show{ max-height:60px; opacity:1; padding:10px 12px; margin:0 2px 16px; }
.notice.is-error{ background:var(--danger-soft); color:var(--danger);
  border:1px solid rgba(226,120,124,.25); }
.notice.is-ok{ background:var(--success-soft); color:var(--success);
  border:1px solid rgba(14,126,114,.25); }
.notice.is-info{ background:rgba(0,80,89,.08); color:var(--text-2);
  border:1px solid var(--border); }

/* =========================================================================
   9. RODAPÉ MÍNIMO
   ========================================================================= */
.foot{
  margin-top:24px; display:flex; align-items:center; gap:10px;
  color:var(--muted); font-size:10.5px; letter-spacing:.06em;
  opacity:0; animation:rise .8s var(--ease) .9s forwards;
}
.foot__dot{ width:5px; height:5px; border-radius:50%; background:var(--accent);
  box-shadow:0 0 8px var(--accent-glow); }

/* =========================================================================
   10. TRANSIÇÃO DE SUCESSO (prepara o redirecionamento)
   ========================================================================= */
.success{
  position:fixed; inset:0; z-index:50;
  display:grid; place-items:center;
  background:transparent;          /* fundo removido: mostra a tela de login por trás */
  opacity:0; visibility:hidden;
  transition:opacity .6s var(--ease), visibility .6s;
}
.success.show{ opacity:1; visibility:visible; }
.success__inner{
  display:flex; flex-direction:column; align-items:center; gap:22px;
  transform:translateY(8px) scale(.98);
  transition:transform .7s var(--ease);
}
.success.show .success__inner{ transform:none; }
/* logo VERO (animação 3D com alpha): mostra a marca inteira, sem máscara circular */
.success__spin{
  width:300px; height:300px;
  display:flex; align-items:center; justify-content:center;
  filter:drop-shadow(0 0 30px rgba(0,80,89,.30));
}
.success__vid{
  width:100%; height:100%; object-fit:contain; display:block;
}

/* =========================================================================
   11. KEYFRAMES
   ========================================================================= */
@keyframes rise{ to{ opacity:1; transform:none; } }
@keyframes float{ from{ transform:translateY(-3px); } to{ transform:translateY(3px); } }
@keyframes spin{ to{ transform:rotate(360deg); } }
@keyframes pulse{ 0%,100%{ filter:drop-shadow(0 0 24px rgba(0,80,89,.28)); }
  50%{ filter:drop-shadow(0 0 40px rgba(0,80,89,.5)); } }
@keyframes fill{ to{ width:100%; } }
@keyframes shake{
  10%,90%{ transform:translateX(-1px); }
  20%,80%{ transform:translateX(2px); }
  30%,50%,70%{ transform:translateX(-5px); }
  40%,60%{ transform:translateX(5px); }
}

/* =========================================================================
   12. RESPONSIVO
   ========================================================================= */
@media (max-width:480px){
  .auth{ width:100%; padding:20px 18px; }
  .brand{ margin-bottom:24px; }
  .brand__logo{ width:128px; }
  .panel{ padding:26px 20px 22px; }
}
@media (max-height:620px){
  .brand{ margin-bottom:18px; }
  .brand__logo{ width:118px; }
  .panel{ padding:22px 22px 18px; }
  .foot{ margin-top:14px; }
}

/* foco visível acessível em qualquer controle */
:focus-visible{ outline:2px solid var(--accent); outline-offset:3px; border-radius:6px; }
</style>
</head>

<body>



<!-- =====================================================================
     PALCO DE LOGIN
     ===================================================================== -->
<main class="stage">
  <div class="auth">

    <!-- Arte final VERO (P-17/D10) enviada pelo cliente 04/07 -->
    <div class="brand">
      <img src="<?= h(bios_with_base('/assets/img/brand/vero-stacked-white-notech.svg')) ?>" alt="VERO" class="brand__logo">
      <p class="brand__eyebrow">Gestão de Dentro e Fora da Porteira</p>
    </div>

    <!-- PAINEL DE AUTENTICAÇÃO -->
    <div class="panel" id="panel">
      <div class="panel__rule"></div>

      <!-- aviso (erro / sucesso / sessão encerrada) — aria-live -->
      <?php if ($noticeMessage !== ''): ?>
      <div class="notice show <?= $noticeType === 'error' ? 'is-error' : ($noticeType === 'ok' ? 'is-ok' : 'is-info') ?>" id="notice" role="status" aria-live="polite">
        <?php if ($noticeType === 'error'): ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
        <?php elseif ($noticeType === 'ok'): ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>
        <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
        <?php endif; ?>
        <p style="margin:0"><?= h($noticeMessage) ?></p>
      </div>
      <?php else: ?>
      <div class="notice" id="notice" role="status" aria-live="polite"></div>
      <?php endif; ?>

      <!-- FORMULÁRIO real (mantido para integração futura com o PHP) -->
      <form id="loginForm" action="" method="post" novalidate autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="redirect" value="<?= h($redirect) ?>">

        <div class="field field--1">
          <label for="email">E-mail ou usuário</label>
          <div class="input">
            <div class="input__icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
            </div>
            <input id="email" name="email" type="text" inputmode="email"
                   autocomplete="username" placeholder="seu.email@fazenda.com.br"
                   value="<?= h($_POST['email'] ?? '') ?>"
                   aria-required="true" required>
          </div>
        </div>

        <div class="field field--2">
          <label for="senha">Senha</label>
          <div class="input">
            <div class="input__icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
            </div>
            <input id="senha" name="senha" type="password"
                   autocomplete="current-password" placeholder="••••••••"
                   aria-required="true" required>
            <button class="input__toggle" id="togglePwd" type="button"
                    aria-label="Mostrar senha" aria-pressed="false">
              <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div class="row">
          <button class="link" type="button" id="forgot">Esqueci minha senha</button>
        </div>

        <button class="btn" id="submitBtn" type="submit">
          <i class="btn__spinner" aria-hidden="true"></i>
          <div class="btn__label">
            <p style="margin:0">Entrar</p>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13M12 5l7 7-7 7"/></svg>
          </div>
        </button>
      </form>
    </div>

    <!-- RODAPÉ mínimo -->
    <footer class="foot">
      <div class="foot__dot" aria-hidden="true"></div>
      <p class="mono" style="margin:0">ACESSO SEGURO</p>
      <p style="margin:0; color:rgba(139,124,104,.5)">·</p>
      <p class="mono" style="margin:0">VERO v1.0</p>
    </footer>

  </div>
</main>

<!-- =====================================================================
     TRANSIÇÃO DE SUCESSO (prepara o redirecionamento ao painel)
     ===================================================================== -->
<div class="success" id="success" role="status" aria-live="polite">
  <div class="success__inner">
    <div class="success__spin">
      <video id="successVid" class="success__vid" muted loop playsinline preload="auto" aria-hidden="true">
        <source src="<?= h(bios_with_base('/assets/img/vero-splash-3d.webm')) ?>" type="video/webm">
        <source src="<?= h(bios_with_base('/assets/img/vero-splash-3d.mp4')) ?>" type="video/mp4">
      </video>
    </div>
  </div>
</div>

<script>
/* =========================================================================
   JAVASCRIPT — animações, validação visual e envio real para o PHP.
   ========================================================================= */
(function () {
  'use strict';

  const $ = (sel) => document.querySelector(sel);

  const panel      = $('#panel');
  const form       = $('#loginForm');
  const email      = $('#email');
  const senha      = $('#senha');
  const submitBtn  = $('#submitBtn');
  const notice     = $('#notice');
  const togglePwd  = $('#togglePwd');
  const eyeIcon    = $('#eyeIcon');
  const forgot     = $('#forgot');
  const success    = $('#success');

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------------------------------------------------------------
     1. ABERTURA — vídeo da logo, depois revela o login.
        Em caso de ?logout, pula o splash e mostra a mensagem de sessão.
     --------------------------------------------------------------------- */
  const params   = new URLSearchParams(location.search);
  const isLogout = params.has('logout');
  const hasServerNotice = <?= $noticeMessage !== '' ? 'true' : 'false' ?>;

  // Splash de abertura removido: revela o login direto e foca o e-mail.
  if (isLogout && !hasServerNotice) {
    showNotice('info', 'Sessão encerrada com segurança.');
  }
  window.setTimeout(() => { if (email) email.focus({ preventScroll: true }); }, isLogout ? 400 : 300);

  /* ---------------------------------------------------------------------
     2. MOSTRAR / OCULTAR SENHA
     --------------------------------------------------------------------- */
  const EYE_OPEN  = '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>';
  const EYE_OFF   = '<path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.4 5.2A9.7 9.7 0 0 1 12 5c6.4 0 10 7 10 7a16 16 0 0 1-3 3.6M6.1 6.1A16 16 0 0 0 2 12s3.6 7 10 7a9.6 9.6 0 0 0 3-.5"/>';

  togglePwd.addEventListener('click', () => {
    const show = senha.type === 'password';
    senha.type = show ? 'text' : 'password';
    eyeIcon.innerHTML = show ? EYE_OFF : EYE_OPEN;
    togglePwd.setAttribute('aria-pressed', String(show));
    togglePwd.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
    senha.focus();
  });

  /* ---------------------------------------------------------------------
     3. MENSAGENS (aria-live) — erro / sucesso / informação
     --------------------------------------------------------------------- */
  const ICON = {
    error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>',
    ok:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>',
    info:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>'
  };
  function showNotice(type, text) {
    const map = { error: 'is-error', ok: 'is-ok', info: 'is-info' };
    notice.className = 'notice show ' + map[type];
    notice.innerHTML = ICON[type] + '<p style="margin:0">' + text + '</p>';
  }
  function clearNotice() { notice.className = 'notice'; notice.innerHTML = ''; }

  /* ---------------------------------------------------------------------
     4. VALIDAÇÃO VISUAL + ANIMAÇÃO DE ATENÇÃO (sem alert)
     --------------------------------------------------------------------- */
  function markInvalid(input, on) {
    input.closest('.field').classList.toggle('is-invalid', on);
    input.setAttribute('aria-invalid', String(on));
  }
  [email, senha].forEach((inp) => {
    inp.addEventListener('input', () => { markInvalid(inp, false); clearNotice(); });
  });

  function attention(message) {
    showNotice('error', message);
    panel.classList.remove('is-shake');
    void panel.offsetWidth;          // reinicia a animação
    panel.classList.add('is-shake');
  }

  /* ---------------------------------------------------------------------
     5. ENVIO REAL — valida, mostra loading e envia para o PHP.
     --------------------------------------------------------------------- */
  let busy = false;

  form.addEventListener('submit', function (e) {
    if (busy) {
      e.preventDefault();
      return;
    }

    const emailVal = email.value.trim();
    const senhaVal = senha.value;

    let invalid = false;
    if (!emailVal) { markInvalid(email, true); invalid = true; }
    if (!senhaVal) { markInvalid(senha, true); invalid = true; }

    if (invalid) {
      e.preventDefault();
      attention('Preencha e-mail e senha para continuar.');
      return;
    }

    e.preventDefault();
    setLoading(true);

    // mostra o carregamento (ícone VERO girando) e então envia
    // o formulário some (fade) deixando só o ícone no centro
    document.querySelector('.stage')?.classList.add('is-leaving');
    if (success) {
      success.classList.add('show');
      const vid = document.getElementById('successVid');
      if (vid) { try { vid.currentTime = 0; vid.play(); } catch (err) {} }
    }

    // pequeno intervalo para a animação aparecer antes do POST real
    window.setTimeout(() => {
      form.submit();
    }, 650);
  });

  function setLoading(on) {
    busy = on;
    submitBtn.classList.toggle('is-loading', on);
    submitBtn.disabled = on;
    submitBtn.setAttribute('aria-busy', String(on));
    panel.classList.toggle('is-busy', on);
    if (on) clearNotice();
  }

  /* ---------------------------------------------------------------------
     6. "Esqueci minha senha" — placeholder visual
     --------------------------------------------------------------------- */
  forgot.addEventListener('click', () => {
    showNotice('info', 'Enviaremos as instruções para o e-mail informado.');
  });

})();

</script>
</body>
</html>
