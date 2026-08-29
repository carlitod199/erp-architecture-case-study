<?php
declare(strict_types=1);
/* ============================================================
   VERO Campo — api/v1/nucleo/contexto.php
   Autenticação por token de dispositivo (P-APP-4: lembrar 30 dias)
   e resolução de contexto (tenant, usuário, permissões).

   O token é OPACO: 64 hex aleatórios entregues no login; o banco
   guarda só o SHA-256 (app_tokens). Revogável por linha.

   Depois de autenticar, populamos $_SESSION com o mesmo formato do
   login web — assim vero_crud.php (vero_tenant/vero_uid/vero_can) e
   os services vero_srv_* funcionam sem mudança.
   ============================================================ */

const API_TOKEN_DIAS = 30;
const API_TOKEN_MAX_DIAS = 90; // A-7: teto absoluto — refresh nunca ultrapassa isto desde created_at

/** Extrai o Bearer token do cabeçalho Authorization. */
function api_token_bruto(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('apache_request_headers')) {
        $todos = array_change_key_case(apache_request_headers(), CASE_LOWER);
        $h = $todos['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim((string)$h), $m)) {
        return strtolower($m[1]);
    }
    return null;
}

/** Autentica a requisição; popula $_SESSION e devolve o usuário. */
function api_autenticar(): array
{
    $bruto = api_token_bruto();
    if ($bruto === null) {
        api_erro('token_ausente', 'Sessão expirada. Entre novamente.', 401);
    }
    $pdo = vero_pdo();
    $q = $pdo->prepare(
        'SELECT t.id AS token_id, t.expira_em, t.revogado_em,
                u.id, u.tenant_id, u.nome, u.email, u.perfil, u.ativo
           FROM app_tokens t
           JOIN usuarios u ON u.id = t.usuario_id
          WHERE t.token_hash = ?
          LIMIT 1'
    );
    $q->execute([hash('sha256', $bruto)]);
    $linha = $q->fetch();

    if (!$linha || (int)$linha['ativo'] !== 1) {
        api_erro('token_invalido', 'Sessão inválida. Entre novamente.', 401);
    }
    if ($linha['revogado_em'] !== null) {
        api_erro('token_revogado', 'Sessão encerrada. Entre novamente.', 401);
    }
    if (strtotime((string)$linha['expira_em']) < time()) {
        api_erro('token_expirado', 'Sessão expirada. Entre novamente.', 401);
    }

    // marca uso (no máximo 1x/min para não gerar write em toda chamada)
    $pdo->prepare(
        'UPDATE app_tokens SET ultimo_uso_em = NOW()
          WHERE id = ? AND (ultimo_uso_em IS NULL OR ultimo_uso_em < NOW() - INTERVAL 1 MINUTE)'
    )->execute([(int)$linha['token_id']]);

    $permissoes = api_resolver_permissoes($pdo, (int)$linha['tenant_id'], (string)$linha['perfil']);

    // Mesmo formato do login web → vero_crud/vero_services funcionam sem mudança.
    $_SESSION['user_id'] = (int)$linha['id'];
    $_SESSION['user_name'] = (string)$linha['nome'];
    $_SESSION['user_email'] = (string)$linha['email'];
    $_SESSION['user_role'] = (string)$linha['perfil'];
    $_SESSION['tenant_id'] = (int)$linha['tenant_id'];
    $_SESSION['permissions'] = $permissoes;

    return [
        'id' => (int)$linha['id'],
        'nome' => (string)$linha['nome'],
        'email' => (string)$linha['email'],
        'perfil' => (string)$linha['perfil'],
        'tenant_id' => (int)$linha['tenant_id'],
        'permissoes' => $permissoes,
        'token_id' => (int)$linha['token_id'],
    ];
}

/** Permissões do perfil: roles/role_permissions; sem cadastro, fallback por perfil
 *  (mesma ideia de bios_default_role_permissions do login web). */
function api_resolver_permissoes(PDO $pdo, int $tenantId, string $perfil): array
{
    if (in_array($perfil, ['super_admin', 'club_admin'], true)) {
        return ['*'];
    }
    try {
        $q = $pdo->prepare(
            'SELECT p.slug
               FROM roles r
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.slug = ? AND (r.tenant_id = ? OR r.tenant_id IS NULL) AND r.ativo = 1'
        );
        $q->execute([$perfil, $tenantId]);
        $slugs = array_values(array_unique(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN))));
        if ($slugs) {
            return $slugs;
        }
    } catch (Throwable $e) {
        // tabela ausente/divergente: cai no fallback
    }
    return match ($perfil) {
        'gestor' => ['agro.*', 'estoque.*', 'mip.*', 'maquinas.*', 'irrigacao.*', 'colheita.*'],
        // maquinas.horimetro.* cobre horímetro E abastecimento (PT-02: os 3 slugs
        // de ação foram seedados ao role operador; o fallback acompanha).
        // 23/07: colheita registrada NO CAMPO vai direto p/ a tela Colheita
        'operador' => ['agro.ver', 'agro.apontamentos_campo.*', 'agro.apontar', 'agro.colheita.editar', 'mip.*', 'irrigacao.*', 'maquinas.ver', 'maquinas.horimetro.*', 'estoque.ver'],
        'financeiro' => ['financeiro.*', 'estoque.ver'],
        default => ['*.ver'], // consulta / visualizador
    };
}

/** Checagem de permissão com os mesmos wildcards do motor web:
 *  '*', 'base.*', 'base.micro.*', '*.acao' e match exato. */
function api_pode(string $slug): bool
{
    $perms = $_SESSION['permissions'] ?? [];
    if (!is_array($perms) || $slug === '') {
        return false;
    }
    if (in_array('*', $perms, true) || in_array($slug, $perms, true)) {
        return true;
    }
    $partes = explode('.', $slug);
    $acao = end($partes);
    $prefixo = '';
    foreach ($partes as $p) {
        $prefixo = $prefixo === '' ? $p : "{$prefixo}.{$p}";
        if (in_array("{$prefixo}.*", $perms, true)) {
            return true;
        }
    }
    return count($partes) > 1 && in_array("*.{$acao}", $perms, true);
}

/** Aborta com 403 se o usuário não tiver a permissão. */
function api_exigir(string $slug): void
{
    if (!api_pode($slug)) {
        api_erro('sem_permissao', 'Você não tem permissão para esta ação.', 403);
    }
}
