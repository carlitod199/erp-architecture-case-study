<?php
declare(strict_types=1);
/* ============================================================
   Universidade VERO — api/uni/v1/nucleo.php
   Helpers compartilhados da API de conteúdo: resposta JSON,
   contexto de auth (sessão do ERP ou Bearer do app) e checagem
   de permissão com os wildcards do ERP. Sem efeito colateral ao
   ser incluído (o roteamento fica em index.php).
   ============================================================ */

require_once __DIR__ . '/../../../includes/uni_db.php'; // uni_pdo() — conteúdo

/** Resposta JSON e encerra. 204 = sem corpo. */
function uni_json(int $http, mixed $data): never
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($data !== null) {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

/** Contexto (perfil, permissões, tenant). Web: sessão do ERP. App: Bearer. */
function uni_contexto(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['user_id'])) {
        return [
            'uid'    => (int)$_SESSION['user_id'],
            'role'   => (string)($_SESSION['user_role'] ?? ''),
            'perms'  => (array)($_SESSION['permissions'] ?? []),
            'tenant' => isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null,
        ];
    }

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+[a-f0-9]{64}$/i', trim((string)$auth))) {
        require_once __DIR__ . '/../../../includes/vero_crud.php';
        require_once __DIR__ . '/../../v1/nucleo/api.php';
        require_once __DIR__ . '/../../v1/nucleo/contexto.php';
        $u = api_autenticar(); // popula $_SESSION ou aborta 401 (envelope do app)
        return [
            'uid'    => (int)$u['id'],
            'role'   => (string)$u['perfil'],
            'perms'  => (array)$u['permissoes'],
            'tenant' => (int)$u['tenant_id'],
        ];
    }

    uni_json(401, ['erro' => 'nao_autenticado', 'message' => 'Sessão necessária.']);
}

/** Permissão com os wildcards do ERP ('*', 'base.*', 'base.micro.*', '*.acao'). */
function uni_pode(string $slug, array $ctx): bool
{
    $role = $ctx['role'];
    $perms = $ctx['perms'];
    if (in_array($role, ['super_admin', 'club_admin'], true)) return true;
    if ($slug === '') return false;
    if (in_array('*', $perms, true) || in_array($slug, $perms, true)) return true;
    $partes = explode('.', $slug);
    $acao = end($partes);
    $prefixo = '';
    foreach ($partes as $p) {
        $prefixo = $prefixo === '' ? $p : "{$prefixo}.{$p}";
        if (in_array("{$prefixo}.*", $perms, true)) return true;
    }
    return count($partes) > 1 && in_array("*.{$acao}", $perms, true);
}
