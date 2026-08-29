<?php
declare(strict_types=1);
/* Universidade VERO — Redefinição de senha (pedido + confirmação por token). */
require_once __DIR__ . '/../includes/uni_auth.php';
uni_auth_boot();
$base = BIOS_BASE;

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
$modo = $token !== '' ? 'confirmar' : 'solicitar';

$erro = null; $ok = null; $devLink = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!uni_csrf_check($_POST['csrf'] ?? '')) {
        $erro = 'Sessão expirada. Tente novamente.';
    } elseif ($modo === 'confirmar') {
        $r = uni_auth_reset_confirmar($token, (string)($_POST['senha'] ?? ''));
        if ($r['ok']) $ok = 'Senha redefinida! Agora é só entrar.'; else $erro = $r['erro'];
    } else {
        $r = uni_auth_reset_solicitar((string)($_POST['email'] ?? ''));
        $ok = 'Se existir uma conta com esse e-mail, enviamos o link de redefinição.';
        if (!empty($r['link']) && uni_auth_is_dev()) $devLink = $r['link']; // só em ambiente local
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Redefinir senha · Universidade VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-portal.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/uni-portal.css') ?: '1' ?>">
</head>
<body class="uni-portal up-auth">
  <div class="up-auth-box">
    <div class="up-auth-logo"><img src="<?= $base ?>/assets/img/brand/vero-lockup-white.svg" alt="VERO"></div>
    <div class="up-auth-card">
      <?php if ($modo === 'confirmar'): ?>
        <h1>Nova senha</h1>
        <p class="sub">Escolha uma senha nova para sua conta.</p>
        <?php if ($erro): ?><div class="up-erro"><?= uni_h($erro) ?></div><?php endif; ?>
        <?php if ($ok): ?>
          <div class="up-ok"><?= uni_h($ok) ?></div>
          <div class="up-auth-links"><a href="<?= $base ?>/universidade/login.php">Ir para o login</a></div>
        <?php else: ?>
          <form method="post" action="<?= $base ?>/universidade/senha.php">
            <input type="hidden" name="csrf" value="<?= uni_h(uni_csrf()) ?>">
            <input type="hidden" name="token" value="<?= uni_h($token) ?>">
            <div class="up-campo">
              <label for="senha">Nova senha (mín. 8 caracteres)</label>
              <input type="password" id="senha" name="senha" required minlength="8" autofocus autocomplete="new-password">
            </div>
            <button type="submit" class="up-auth-btn">Redefinir senha</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <h1>Esqueci a senha</h1>
        <p class="sub">Informe seu e-mail e enviaremos um link para redefinir.</p>
        <?php if ($ok): ?><div class="up-ok"><?= uni_h($ok) ?></div><?php endif; ?>
        <?php if ($devLink): ?><div class="up-devbox"><b>Ambiente local</b> — link de redefinição:<br><a href="<?= uni_h($devLink) ?>"><?= uni_h($devLink) ?></a></div><?php endif; ?>
        <?php if ($erro): ?><div class="up-erro"><?= uni_h($erro) ?></div><?php endif; ?>
        <form method="post" action="<?= $base ?>/universidade/senha.php">
          <input type="hidden" name="csrf" value="<?= uni_h(uni_csrf()) ?>">
          <div class="up-campo">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="email">
          </div>
          <button type="submit" class="up-auth-btn">Enviar link</button>
        </form>
        <div class="up-auth-links"><a href="<?= $base ?>/universidade/login.php">Voltar ao login</a></div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
