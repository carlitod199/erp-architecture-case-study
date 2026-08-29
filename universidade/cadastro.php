<?php
declare(strict_types=1);
/* Universidade VERO — Auto-inscrição (cadastro de aluno). */
require_once __DIR__ . '/../includes/uni_auth.php';
require_once __DIR__ . '/../includes/db.php';             // PDO do sistema (auth_audit_logs) p/ o throttle
require_once __DIR__ . '/../includes/login_throttle.php'; // reuso do throttle do login (bios_login_throttle_*)
uni_auth_boot();
$base = BIOS_BASE;

if (uni_auth_logado() && uni_auth_user()) { header('Location: ' . $base . '/universidade/'); exit; }

$erro = null;
$dados = ['nome' => '', 'email' => '', 'perfil' => 'operador'];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $dados['nome']   = (string)($_POST['nome'] ?? '');
    $dados['email']  = (string)($_POST['email'] ?? '');
    $dados['perfil'] = (string)($_POST['perfil'] ?? 'operador');
    $ip    = bios_login_ip();
    $emKey = strtolower(trim($dados['email']));
    if (!uni_csrf_check($_POST['csrf'] ?? '')) {
        $erro = 'Sessão expirada. Tente novamente.';
    } elseif (bios_login_throttle_bloqueado(Database::getConnection(), $emKey, $ip)) {
        /* Anti-registro em massa: excesso de tentativas por IP → recusa. */
        $erro = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';
    } else {
        /* Alimenta o contador por IP do throttle antes de processar o registro. */
        bios_login_throttle_log(Database::getConnection(), $emKey, $ip, false);
        $r = uni_auth_cadastrar($dados['nome'], $dados['email'], (string)($_POST['senha'] ?? ''), $dados['perfil']);
        if ($r['ok']) { header('Location: ' . $base . '/universidade/'); exit; } // já loga
        $erro = $r['erro'];
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Criar conta · Universidade VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-portal.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/uni-portal.css') ?: '1' ?>">
</head>
<body class="uni-portal up-auth">
  <div class="up-auth-box">
    <div class="up-auth-logo"><img src="<?= $base ?>/assets/img/brand/vero-lockup-white.svg" alt="VERO"></div>
    <div class="up-auth-card">
      <h1>Criar conta</h1>
      <p class="sub">É rápido. Sua função ajuda a montar a trilha certa para você.</p>
      <?php if ($erro): ?><div class="up-erro"><?= uni_h($erro) ?></div><?php endif; ?>
      <form method="post" action="<?= $base ?>/universidade/cadastro.php">
        <input type="hidden" name="csrf" value="<?= uni_h(uni_csrf()) ?>">
        <div class="up-campo">
          <label for="nome">Nome</label>
          <input type="text" id="nome" name="nome" required autofocus value="<?= uni_h($dados['nome']) ?>">
        </div>
        <div class="up-campo">
          <label for="email">E-mail</label>
          <input type="email" id="email" name="email" required autocomplete="email" value="<?= uni_h($dados['email']) ?>">
        </div>
        <div class="up-campo">
          <label for="perfil">Sua função</label>
          <select id="perfil" name="perfil">
            <?php foreach (uni_auth_perfis_autoinscricao() as $k => $v): ?>
              <option value="<?= $k ?>" <?= $dados['perfil'] === $k ? 'selected' : '' ?>><?= uni_h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="up-campo">
          <label for="senha">Senha (mín. 8 caracteres)</label>
          <input type="password" id="senha" name="senha" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="up-auth-btn">Criar conta e entrar</button>
      </form>
      <div class="up-auth-links">
        Já tem conta? <a href="<?= $base ?>/universidade/login.php">Entrar</a>
      </div>
    </div>
  </div>
</body>
</html>
