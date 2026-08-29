<?php
declare(strict_types=1);
/* ============================================================
   VERO Campo — api/v1/rotas/auth.php
   Login por e-mail/senha contra a tabela `usuarios` do VERO web
   (bcrypt em senha_hash). Token opaco de dispositivo (P-APP-4).
   ============================================================ */

function rota_auth_login(?array $usuario): never
{
    require_once __DIR__ . '/../../../includes/login_throttle.php'; // PT-03/PT-05

    $corpo = api_corpo();
    $email = strtolower(trim((string)api_exigir_campo($corpo, 'email')));
    $senha = (string)api_exigir_campo($corpo, 'senha');
    $device = is_array($corpo['device'] ?? null) ? $corpo['device'] : [];

    $pdo = vero_pdo();
    $ip = bios_login_ip();

    // PT-03: throttle por conta+IP / IP — recusa sem validar (sem bcrypt).
    if (bios_login_throttle_bloqueado($pdo, $email, $ip)) {
        bios_login_dummy_verify($senha);
        api_erro('muitas_tentativas', 'Muitas tentativas de acesso. Aguarde alguns minutos.', 429);
    }

    $q = $pdo->prepare(
        'SELECT id, tenant_id, nome, email, senha_hash, perfil, ativo
           FROM usuarios
          WHERE LOWER(email) = ?
          ORDER BY (tenant_id = 1) DESC, id ASC
          LIMIT 1'
    );
    $q->execute([$email]);
    $u = $q->fetch();

    // PT-05: normaliza o tempo quando a conta não existe (bcrypt dummy) para
    // não vazar a existência por timing; mensagem já é única.
    if (!$u) {
        bios_login_dummy_verify($senha);
    }
    // mensagem única para não revelar se o e-mail existe
    if (!$u || (int)$u['ativo'] !== 1 || !password_verify($senha, (string)$u['senha_hash'])) {
        bios_login_throttle_log($pdo, $email, $ip, false, $u ? (int)$u['tenant_id'] : null, $u ? (int)$u['id'] : null);
        api_erro('credenciais_invalidas', 'E-mail ou senha inválidos.', 401);
    }
    bios_login_throttle_log($pdo, $email, $ip, true, (int)$u['tenant_id'], (int)$u['id']);

    // re-hash automático se o custo mudou (mesmo comportamento do login web)
    if (password_needs_rehash((string)$u['senha_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
            ->execute([password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]), (int)$u['id']]);
    }

    $lembrar = (bool)($device['lembrar'] ?? true);
    $dias = $lembrar ? API_TOKEN_DIAS : 1;
    $token = bin2hex(random_bytes(32)); // opaco; só o hash vai ao banco

    $pdo->prepare(
        'INSERT INTO app_tokens (tenant_id, usuario_id, token_hash, dispositivo, expira_em)
         VALUES (?,?,?,?, NOW() + INTERVAL ? DAY)'
    )->execute([
        (int)$u['tenant_id'], (int)$u['id'], hash('sha256', $token),
        mb_substr((string)($device['nome'] ?? 'app'), 0, 190), $dias,
    ]);

    $pdo->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?')->execute([(int)$u['id']]);

    $permissoes = api_resolver_permissoes($pdo, (int)$u['tenant_id'], (string)$u['perfil']);

    api_ok([
        'token' => $token,
        'usuario' => [
            'id' => (int)$u['id'],
            'nome' => (string)$u['nome'],
            'perfil' => (string)$u['perfil'],
            'permissoes' => $permissoes,
        ],
    ], 'Login realizado.');
}

function rota_auth_refresh(array $usuario): never
{
    // A-7: renova por API_TOKEN_DIAS, mas NUNCA além de created_at + API_TOKEN_MAX_DIAS.
    // O WHERE recusa (rowCount=0) tokens que já passaram do teto absoluto → obriga novo login.
    $st = vero_pdo()->prepare(
        'UPDATE app_tokens
            SET expira_em = LEAST(NOW() + INTERVAL ? DAY, created_at + INTERVAL ? DAY)
          WHERE id = ? AND revogado_em IS NULL AND created_at + INTERVAL ? DAY > NOW()'
    );
    $st->execute([API_TOKEN_DIAS, API_TOKEN_MAX_DIAS, (int)$usuario['token_id'], API_TOKEN_MAX_DIAS]);
    if ($st->rowCount() === 0) {
        api_erro('token_expirado', 'Sessão expirada (limite absoluto) — faça login novamente.', 401);
    }
    api_ok(['renovado_ate_dias' => API_TOKEN_DIAS], 'Sessão renovada.');
}

function rota_auth_logout(array $usuario): never
{
    vero_pdo()->prepare('UPDATE app_tokens SET revogado_em = NOW() WHERE id = ?')
        ->execute([(int)$usuario['token_id']]);
    api_ok(null, 'Sessão encerrada.');
}
