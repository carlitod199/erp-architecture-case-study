<?php
declare(strict_types=1);
/* ============================================================
   VERO CRM — tela de login PRÓPRIA dos sistemas (fase protótipo)
   Renderizada por crm/{revenda|corretor}/login.php, que define
   $CRM_MODULO antes de incluir este arquivo.

   A autenticação é 100% a do VERO: o form POSTa para /index
   (mesmo CSRF da sessão, mesmo throttle/auditoria) com redirect
   de volta ao dashboard do sistema. Aqui só existe a APRESENTAÇÃO
   de sistema independente — nenhuma lógica de senha é duplicada.
   ============================================================ */

require_once __DIR__ . '/../includes/functions.php';   /* bootstrap + BIOS_BASE + h() */
require_once __DIR__ . '/_lib.php';

$CRM_MODULO = $CRM_MODULO ?? 'revenda';
$produto    = crm_produto($CRM_MODULO);

/* mesma sessão do VERO (parâmetros de cookie idênticos ao auth.php) */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $isHttps,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* já logado → direto ao sistema */
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . crm_url($CRM_MODULO, 'dashboard'));
    exit;
}

/* destino pós-login: o dashboard deste sistema (path relativo ao host) */
$redirect = BIOS_BASE . '/crm/' . $CRM_MODULO . '/dashboard';
$cssCrm   = BIOS_BASE . '/assets/css/crm.css?v=' . @filemtime(__DIR__ . '/../assets/css/crm.css');
$cssFonts = BIOS_BASE . '/assets/vendor/fonts/vero-fonts.css';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar — VERO CRM · <?= h($produto) ?></title>
<link rel="stylesheet" href="<?= $cssFonts ?>">
<link rel="stylesheet" href="<?= $cssCrm ?>">
<link rel="icon" href="<?= BIOS_BASE ?>/assets/img/favicon-vero-32.png">
</head>
<body class="crm-login-body">
  <form class="crm-login" method="post" action="<?= BIOS_BASE ?>/index" autocomplete="on">
    <div class="crm-login__brand">
      <img src="<?= BIOS_BASE ?>/assets/img/brand/vero-stacked-white-notech.svg" alt="VERO"
           onerror="this.outerHTML='<div style=&quot;font-size:30px;font-weight:700;color:#fff&quot;>VERO</div>'">
      <span class="crm-login__prod">CRM · <?= h($produto) ?></span>
    </div>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
    <label for="crm-email">E-mail</label>
    <input id="crm-email" type="email" name="email" required autofocus>
    <label for="crm-senha">Senha</label>
    <input id="crm-senha" type="password" name="senha" required>
    <button type="submit">Entrar →</button>
    <div class="crm-login__foot">Acesso seguro · VERO CRM</div>
  </form>
</body>
</html>
