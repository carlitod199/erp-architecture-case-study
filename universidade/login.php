<?php
declare(strict_types=1);
/* Universidade VERO — Login do LMS (autenticação própria, sem ERP). */
require_once __DIR__ . '/../includes/uni_auth.php';
uni_auth_boot();
$base = BIOS_BASE;

/* destino interno seguro */
$redirect = (string)($_GET['redirect'] ?? $_POST['redirect'] ?? '');
if ($redirect === '' || $redirect[0] !== '/' || str_starts_with($redirect, '//') || str_contains($redirect, "\n")) {
    $redirect = $base . '/universidade/';
}

if (uni_auth_logado() && uni_auth_user()) { header('Location: ' . $redirect); exit; }

$erro = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!uni_csrf_check($_POST['csrf'] ?? '')) {
        $erro = 'Sessão expirada. Tente novamente.';
    } else {
        $r = uni_auth_login((string)($_POST['email'] ?? ''), (string)($_POST['senha'] ?? ''));
        if ($r['ok']) { header('Location: ' . $redirect); exit; }
        $erro = $r['erro'];
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · Universidade VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-portal.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/uni-portal.css') ?: '1' ?>">
<style>
  /* Banner de fundo (assets/img/uni_login_bg.png) com o CARD de login grudado à
     esquerda (centralizado na vertical). Scrim mais forte à esquerda p/ o card
     ficar legível sobre a arte, mantendo o notebook/pessoa nítidos à direita. */
  /* SPLIT: card de login colado à DIREITA (altura cheia) + BANNER ENCAIXADO
     (inteiro, sem cortar) no espaço que sobra à esquerda -> background contain. */
  body.up-auth{
    margin:0; padding:0; min-height:100vh;
    display:flex; align-items:stretch; justify-content:center;
    /* banner removido a pedido — fundo em gradiente VERO */
    background:linear-gradient(135deg,#00363D 0%,#005059 55%,#0a6b73 100%);
  }
  body.up-auth::before{ content:none; display:none; }
  .up-auth-box{
    flex:0 0 min(440px, 100vw); max-width:none; min-height:100vh;
    display:flex; flex-direction:column; justify-content:center;
    padding:0 clamp(28px, 4vw, 52px);
    /* card de login com TRANSPARÊNCIA (vidro fosco) — deixa o banner aparecer */
    background:rgba(255,255,255,.76);
    -webkit-backdrop-filter:blur(12px) saturate(115%); backdrop-filter:blur(12px) saturate(115%);
    overflow-y:auto;
    box-shadow:-24px 0 60px -20px rgba(0,0,0,.4);
  }
  .up-auth-box .up-auth-card{ background:transparent; box-shadow:none; border-radius:0; padding:0; }
  .up-auth-box .up-auth-logo{ display:none; }
  /* logo VERO CENTRALIZADA e SEM fundo: a logo é branca, então recoloro p/ teal
     usando o próprio desenho como máscara (aparece no painel branco). */
  .up-auth-card .uni-brand{
    width:240px; height:66px; margin:0 auto 24px;
    background:#005059;
    -webkit-mask:url('<?= $base ?>/assets/img/brand/vero-lockup-white.svg') center/contain no-repeat;
            mask:url('<?= $base ?>/assets/img/brand/vero-lockup-white.svg') center/contain no-repeat;
  }

  /* ── inputs refinados ─────────────────────────────────────── */
  .up-auth-box .sub{ text-align:center; margin-bottom:22px; }
  .up-auth-box .up-campo{ gap:7px; margin:0 0 16px; }
  .up-auth-box .up-campo label{
    font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#456; }
  .up-auth-box .up-campo input{
    padding:13px 15px; border-radius:12px; font-size:14.5px;
    border:1px solid rgba(0,80,89,.18); background:rgba(255,255,255,.72); color:#16302c;
    transition:border-color .18s ease, box-shadow .18s ease, background .18s ease;
  }
  .up-auth-box .up-campo input::placeholder{ color:#8aa39d; }
  .up-auth-box .up-campo input:focus{
    outline:none; border-color:#005059; background:#fff;
    box-shadow:0 0 0 3px rgba(0,80,89,.16);
  }
  /* autofill: mantém o visual dos campos (sem o amarelo do navegador) */
  .up-auth-box .up-campo input:-webkit-autofill,
  .up-auth-box .up-campo input:-webkit-autofill:focus{
    -webkit-text-fill-color:#16302c; caret-color:#16302c;
    -webkit-box-shadow:0 0 0 40px #f2f8f7 inset; transition:background-color 9999s ease-out;
  }
  /* ── botão refinado ───────────────────────────────────────── */
  .up-auth-box .up-auth-btn{
    margin-top:8px; padding:14px 18px; border-radius:12px; font-size:15px; font-weight:700; letter-spacing:.01em;
    display:inline-flex; align-items:center; justify-content:center; gap:9px;
    color:#fff; background:linear-gradient(180deg,#0a6b73,#004a52);
    box-shadow:0 10px 24px -10px rgba(0,80,89,.6), 0 1px 0 rgba(255,255,255,.18) inset;
    transition:transform .15s ease, box-shadow .25s ease, filter .2s ease;
  }
  .up-auth-box .up-auth-btn:hover{ filter:brightness(1.07); box-shadow:0 14px 30px -10px rgba(0,80,89,.65); }
  .up-auth-box .up-auth-btn:active{ transform:translateY(1px); }
  .up-auth-box .up-auth-btn svg{ width:17px; height:17px; }
  /* botão secundário: voltar para o sistema (ERP) */
  .up-auth-box .up-back-btn{
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
    width:100%; margin-top:12px; padding:12px 18px; border-radius:12px;
    font-size:14px; font-weight:600; text-decoration:none; cursor:pointer;
    color:#005059; background:rgba(255,255,255,.5); border:1px solid rgba(0,80,89,.28);
    transition:background .2s ease, border-color .2s ease, color .2s ease;
  }
  .up-auth-box .up-back-btn:hover{ background:rgba(255,255,255,.85); border-color:#005059; color:#00363d; }
  .up-auth-box .up-back-btn svg{ width:16px; height:16px; }

  @media (max-width:640px){ .up-auth-box{ flex:0 0 100vw; } }
</style>
</head>
<body class="uni-portal up-auth">
  <div class="up-auth-box">
    <div class="up-auth-logo"><img src="<?= $base ?>/assets/img/brand/vero-lockup-white.svg" alt="VERO"></div>
    <div class="up-auth-card">
      <div class="uni-brand" role="img" aria-label="VERO"></div>
      <p class="sub">Entre para aprender a usar o sistema no seu ritmo.</p>
      <?php if ($erro): ?><div class="up-erro"><?= uni_h($erro) ?></div><?php endif; ?>
      <form method="post" action="<?= $base ?>/universidade/login.php">
        <input type="hidden" name="csrf" value="<?= uni_h(uni_csrf()) ?>">
        <input type="hidden" name="redirect" value="<?= uni_h($redirect) ?>">
        <div class="up-campo">
          <label for="email">E-mail</label>
          <input type="email" id="email" name="email" required autofocus autocomplete="email" value="<?= uni_h((string)($_POST['email'] ?? '')) ?>">
        </div>
        <div class="up-campo">
          <label for="senha">Senha</label>
          <input type="password" id="senha" name="senha" required autocomplete="current-password">
        </div>
        <button type="submit" class="up-auth-btn">Entrar
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13M12 5l7 7-7 7"/></svg>
        </button>
      </form>
      <a class="up-back-btn" href="<?= $base ?>/dashboard">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Voltar para o sistema
      </a>
    </div>
  </div>
</body>
</html>
