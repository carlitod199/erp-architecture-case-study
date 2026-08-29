<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/uni_auth.php
   Autenticação PRÓPRIA da Universidade (LMS): base de alunos
   (uni_usuario) no banco separado, independente do ERP/RBAC.
   Sessão própria (chaves uni_*), bcrypt, throttle, CSRF próprio,
   reset de senha por token. Nada aqui depende do login do ERP.
   ============================================================ */

require_once __DIR__ . '/uni_db.php';       // uni_pdo()
require_once __DIR__ . '/functions.php';    // BIOS_BASE, h()

/** Escapa saída HTML (disponível também para as telas de auth, sem uni_portal). */
if (!function_exists('uni_h')) {
    function uni_h(?string $s): string
    {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

const UNI_AUTH_MAX_TENT   = 5;    // tentativas antes de bloquear
const UNI_AUTH_BLOQUEIO_S = 900;  // 15 min de bloqueio
const UNI_AUTH_RESET_S    = 3600; // token de reset vale 1h
const UNI_AUTH_SENHA_MIN  = 8;

/* ─────────────── Sessão ─────────────── */
function uni_auth_boot(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        /* B4: HTTPS atrás do proxy TLS de borda — mesmo critério de
           includes/security_headers.php (HTTPS on / X-Forwarded-Proto / porta 443). */
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_start();
    }
    if (empty($_SESSION['uni_csrf'])) {
        $_SESSION['uni_csrf'] = bin2hex(random_bytes(32));
    }
}

/* ─────────────── CSRF ─────────────── */
function uni_csrf(): string { uni_auth_boot(); return (string)$_SESSION['uni_csrf']; }
function uni_csrf_check(?string $t): bool
{
    uni_auth_boot();
    return is_string($t) && $t !== '' && hash_equals((string)$_SESSION['uni_csrf'], $t);
}

/* ─────────────── Estado ─────────────── */
function uni_auth_logado(): bool { uni_auth_boot(); return !empty($_SESSION['uni_uid']); }

/** Aluno logado (revalida ativo no banco). Null se ausente/inativo. */
function uni_auth_user(): ?array
{
    uni_auth_boot();
    $uid = (int)($_SESSION['uni_uid'] ?? 0);
    if ($uid <= 0) return null;
    $st = uni_pdo()->prepare("SELECT id, tenant_id, nome, email, perfil, ativo FROM uni_usuario WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u || (int)$u['ativo'] !== 1) { uni_auth_logout(); return null; }
    return $u;
}

/** Contexto no formato que as libs do portal usam (uni_ctx). Aluno = vê todo conteúdo publicado. */
function uni_auth_ctx(): array
{
    $u = uni_auth_user();
    if (!$u) return ['uid' => 0, 'nome' => '', 'role' => '', 'perfil' => '', 'perms' => [], 'tenant' => null, 'aluno' => true, 'tenant_nome' => 'Universidade VERO'];
    return [
        'uid'    => (int)$u['id'],
        'nome'   => (string)$u['nome'],
        'email'  => (string)$u['email'],
        'role'   => (string)$u['perfil'],   // trilhas_do_perfil usa role
        'perfil' => (string)$u['perfil'],
        'perms'  => [],
        'tenant' => $u['tenant_id'] !== null ? (int)$u['tenant_id'] : null,
        'aluno'  => true,
        'tenant_nome' => 'Universidade VERO',
    ];
}

/** Exige aluno logado; senão redireciona ao login preservando o destino. */
function uni_auth_require(): void
{
    if (uni_auth_logado() && uni_auth_user()) return;
    $base = defined('BIOS_BASE') ? BIOS_BASE : '';
    $destino = rawurlencode((string)($_SERVER['REQUEST_URI'] ?? ($base . '/universidade/')));
    header('Location: ' . $base . '/universidade/login.php?redirect=' . $destino);
    exit;
}

/* ─────────────── Cadastro / login / logout ─────────────── */

function uni_auth_perfis(): array
{
    return [
        'operador'    => 'Operador de campo',
        'mao_de_obra' => 'Mão de obra',
        'monitor'     => 'Monitor (MIP)',
        'encarregado' => 'Encarregado',
        'almoxarifado'=> 'Almoxarifado',
        'rt_gerente'  => 'RT / Gerente',
        'financeiro'  => 'Financeiro',
        'gestor'      => 'Gestor',
        'dono'        => 'Dono / Produtor',
    ];
}

/** Perfis com privilégio de gestão — NUNCA ofertados no auto-registro (só por
 *  promoção administrativa). Alinha com uni_gestor_pode() (dono/gestor). */
function uni_auth_perfis_privilegiados(): array
{
    return ['gestor', 'dono'];
}

/** Perfis que o auto-registro pode oferecer (sem privilégio). */
function uni_auth_perfis_autoinscricao(): array
{
    $priv = uni_auth_perfis_privilegiados();
    return array_filter(uni_auth_perfis(), fn($k) => !in_array($k, $priv, true), ARRAY_FILTER_USE_KEY);
}

/** Auto-inscrição. Retorna ['ok'=>bool,'erro'=>?string,'id'=>?int]. */
function uni_auth_cadastrar(string $nome, string $email, string $senha, string $perfil): array
{
    $nome = trim($nome);
    $email = strtolower(trim($email));
    if (mb_strlen($nome) < 2)                          return ['ok' => false, 'erro' => 'Informe seu nome.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))    return ['ok' => false, 'erro' => 'E-mail inválido.'];
    if (strlen($senha) < UNI_AUTH_SENHA_MIN)           return ['ok' => false, 'erro' => 'A senha precisa de pelo menos ' . UNI_AUTH_SENHA_MIN . ' caracteres.'];
    /* SEGURANÇA: nunca confia no perfil postado para privilégio. Qualquer perfil
       privilegiado (gestor/dono) ou inválido cai para o perfil não-privilegiado
       padrão. Perfil de gestão só por promoção administrativa. */
    if (!array_key_exists($perfil, uni_auth_perfis_autoinscricao())) $perfil = 'operador';

    $pdo = uni_pdo();
    $ex = $pdo->prepare("SELECT id FROM uni_usuario WHERE email = ? LIMIT 1");
    $ex->execute([$email]);
    if ($ex->fetch()) return ['ok' => false, 'erro' => 'Já existe uma conta com esse e-mail.'];

    $cost = (int)($_ENV['BCRYPT_COST'] ?? 12);
    $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => max(10, min(13, $cost))]);
    $pdo->prepare(
        "INSERT INTO uni_usuario (nome, email, senha_hash, perfil, email_verificado_em) VALUES (?,?,?,?, NOW())"
    )->execute([mb_substr($nome, 0, 160), $email, $hash, $perfil]);
    $id = (int)$pdo->lastInsertId();
    uni_auth_iniciar_sessao($id, $nome, $email, $perfil, null); // login automático pós-cadastro
    return ['ok' => true, 'erro' => null, 'id' => $id];
}

/** Login. Retorna ['ok'=>bool,'erro'=>?string]. */
function uni_auth_login(string $email, string $senha): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') {
        return ['ok' => false, 'erro' => 'Informe e-mail e senha.'];
    }
    $pdo = uni_pdo();
    $st = $pdo->prepare("SELECT * FROM uni_usuario WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch();

    // Mensagem genérica p/ não revelar existência do e-mail.
    $generico = ['ok' => false, 'erro' => 'E-mail ou senha incorretos.'];

    if ($u && $u['bloqueado_ate'] !== null && strtotime((string)$u['bloqueado_ate']) > time()) {
        return ['ok' => false, 'erro' => 'Muitas tentativas. Tente de novo em alguns minutos.'];
    }
    if (!$u || (int)$u['ativo'] !== 1 || !password_verify($senha, (string)$u['senha_hash'])) {
        if ($u) uni_auth_registrar_falha((int)$u['id'], (int)$u['tentativas_falhas']);
        return $generico;
    }

    // sucesso
    $pdo->prepare("UPDATE uni_usuario SET tentativas_falhas = 0, bloqueado_ate = NULL, ultimo_login_em = NOW() WHERE id = ?")
        ->execute([(int)$u['id']]);
    uni_auth_iniciar_sessao((int)$u['id'], (string)$u['nome'], (string)$u['email'], (string)$u['perfil'], $u['tenant_id'] !== null ? (int)$u['tenant_id'] : null);
    return ['ok' => true, 'erro' => null];
}

function uni_auth_registrar_falha(int $id, int $tentativas): void
{
    $tentativas++;
    $bloqueio = $tentativas >= UNI_AUTH_MAX_TENT ? date('Y-m-d H:i:s', time() + UNI_AUTH_BLOQUEIO_S) : null;
    uni_pdo()->prepare("UPDATE uni_usuario SET tentativas_falhas = ?, bloqueado_ate = ? WHERE id = ?")
        ->execute([$tentativas, $bloqueio, $id]);
}

function uni_auth_iniciar_sessao(int $id, string $nome, string $email, string $perfil, ?int $tenant): void
{
    uni_auth_boot();
    session_regenerate_id(true); // anti-fixação
    $_SESSION['uni_uid']    = $id;
    $_SESSION['uni_nome']   = $nome;
    $_SESSION['uni_email']  = $email;
    $_SESSION['uni_perfil'] = $perfil;
    $_SESSION['uni_tenant'] = $tenant;
    $_SESSION['uni_csrf']   = bin2hex(random_bytes(32));
}

function uni_auth_logout(): void
{
    uni_auth_boot();
    foreach (['uni_uid', 'uni_nome', 'uni_email', 'uni_perfil', 'uni_tenant'] as $k) unset($_SESSION[$k]);
    session_regenerate_id(true);
}

/* ─────────────── Reset de senha ─────────────── */

/** Solicita reset. Nunca revela se o e-mail existe. Em dev, devolve o link. */
function uni_auth_reset_solicitar(string $email): array
{
    $email = strtolower(trim($email));
    $resp = ['ok' => true, 'link' => null]; // sempre ok (anti-enumeração)
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $resp;

    $pdo = uni_pdo();
    $st = $pdo->prepare("SELECT id FROM uni_usuario WHERE email = ? AND ativo = 1 LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) return $resp;

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE uni_usuario SET reset_token_hash = ?, reset_expira_em = ? WHERE id = ?")
        ->execute([hash('sha256', $token), date('Y-m-d H:i:s', time() + UNI_AUTH_RESET_S), (int)$u['id']]);

    $base = defined('BIOS_BASE') ? BIOS_BASE : '';
    $link = $base . '/universidade/senha.php?token=' . $token;
    uni_auth_enviar_reset($email, $link); // e-mail (produção) / log (dev)
    $resp['link'] = $link; // a página só exibe em ambiente local
    return $resp;
}

/** Confirma o reset com o token. Retorna ['ok'=>bool,'erro'=>?string]. */
function uni_auth_reset_confirmar(string $token, string $novaSenha): array
{
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/', $token))       return ['ok' => false, 'erro' => 'Link inválido.'];
    if (strlen($novaSenha) < UNI_AUTH_SENHA_MIN)       return ['ok' => false, 'erro' => 'A senha precisa de pelo menos ' . UNI_AUTH_SENHA_MIN . ' caracteres.'];

    $pdo = uni_pdo();
    $st = $pdo->prepare("SELECT id, reset_expira_em FROM uni_usuario WHERE reset_token_hash = ? AND ativo = 1 LIMIT 1");
    $st->execute([hash('sha256', $token)]);
    $u = $st->fetch();
    if (!$u || $u['reset_expira_em'] === null || strtotime((string)$u['reset_expira_em']) < time()) {
        return ['ok' => false, 'erro' => 'Link expirado. Peça um novo.'];
    }
    $cost = (int)($_ENV['BCRYPT_COST'] ?? 12);
    $hash = password_hash($novaSenha, PASSWORD_BCRYPT, ['cost' => max(10, min(13, $cost))]);
    $pdo->prepare("UPDATE uni_usuario SET senha_hash = ?, reset_token_hash = NULL, reset_expira_em = NULL, tentativas_falhas = 0, bloqueado_ate = NULL WHERE id = ?")
        ->execute([$hash, (int)$u['id']]);
    return ['ok' => true, 'erro' => null];
}

/** Envia o e-mail de reset. Usa o mailer do ERP se existir; senão, registra em log (dev). */
function uni_auth_enviar_reset(string $email, string $link): void
{
    try {
        if (function_exists('bios_send_mail')) {
            bios_send_mail($email, 'Universidade VERO — redefinir senha',
                "<p>Para redefinir sua senha, acesse:</p><p><a href=\"{$link}\">{$link}</a></p><p>O link vale 1 hora.</p>");
            return;
        }
    } catch (Throwable $e) { error_log('[uni_auth mail] ' . $e->getMessage()); }
    error_log('[uni_auth] reset link para ' . $email . ': ' . $link);
}

/** True em ambiente local/dev (mostra o link de reset na tela). */
function uni_auth_is_dev(): bool
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    return (bool)preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $host);
}
