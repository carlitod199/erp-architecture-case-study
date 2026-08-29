<?php
/* ============================================================
   VERO — shell visual das telas de erro (403 / 404 / 500).
   Branded (teal + banner + logo), 100% self-host (sem CDN).
   Variáveis OPCIONAIS definidas antes do require:
     $ERR_CODE    (int)    — 403 | 404 | 500 (default 404)
     $ERR_TITLE   (string) — sobrescreve o título padrão
     $ERR_MSG     (string) — sobrescreve a mensagem padrão
     $ERR_ACTIONS (string) — HTML dos botões (senão: "Ir para o início")
   Uso: $ERR_CODE = 404; require __DIR__ . '/includes/_error_page.php';
   ============================================================ */
if (!defined('BIOS_BASE')) require_once __DIR__ . '/functions.php';

$__code = (int)($ERR_CODE ?? 404);
$__icos = [
    403 => '<rect x="4.5" y="11" width="15" height="9.5" rx="2.2"/><path d="M8 11V7.5a4 4 0 0 1 8 0V11"/><circle cx="12" cy="15.4" r="1.15"/>',
    404 => '<circle cx="10.5" cy="10.5" r="7"/><path d="m21 21-5.4-5.4"/><path d="M8 10.5h5M10.5 8v5"/>',
    500 => '<path d="M10.3 4.2 1.9 18a2 2 0 0 0 1.7 3h16.8a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0Z"/><path d="M12 9.5v4.2M12 17.3h.01"/>',
];
$__icon = $__icos[$__code] ?? '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>';
$__def = [
    403 => ['Acesso negado', 'Você não tem permissão para acessar este recurso.'],
    404 => ['Página não encontrada', 'O recurso que você procurou não existe ou foi movido de lugar.'],
    500 => ['Erro interno', 'Algo deu errado no servidor. O erro foi registrado e nossa equipe vai verificar.'],
];
$__title = $ERR_TITLE ?? ($__def[$__code][0] ?? 'Erro');
$__msg   = $ERR_MSG   ?? ($__def[$__code][1] ?? '');
$B = rtrim((string)(defined('BIOS_BASE') ? BIOS_BASE : ''), '/');
$__actions = $ERR_ACTIONS ?? ('<a class="ep-btn ep-btn--p" href="' . h($B) . '/dashboard">'
    . '<svg viewBox="0 0 24 24"><path d="m3 11 9-8 9 8"/><path d="M9 21V12h6v9"/></svg> Ir para o início</a>');

http_response_code($__code);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#005059">
<title>VERO — <?= $__code ?></title>
<link rel="icon" type="image/svg+xml" href="<?= h($B) ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= h($B) ?>/assets/img/favicon-vero.png">
<link rel="apple-touch-icon" href="<?= h($B) ?>/assets/img/favicon-vero-180.png">
<link rel="stylesheet" href="<?= h($B) ?>/assets/vendor/fonts/vero-fonts.css">
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{min-height:100vh;display:grid;place-items:center;padding:24px;overflow:hidden;
    font-family:'Hanken Grotesk',system-ui,-apple-system,sans-serif;color:#F3EFE6;
    background:#005059 url('<?= h($B) ?>/assets/img/vero_login_bg.svg') center/cover no-repeat}
  .ep{position:relative;text-align:center;width:100%;max-width:468px;padding:38px 34px 30px;
    background:rgba(17,59,65,.74);border:1px solid rgba(78,156,161,.22);border-radius:20px;
    backdrop-filter:blur(16px) saturate(115%);
    box-shadow:0 1px 0 rgba(255,255,255,.04) inset,0 30px 60px -28px rgba(0,0,0,.85)}
  .ep__logo{width:130px;height:auto;margin:0 auto 20px;display:block;filter:drop-shadow(0 0 22px rgba(78,156,161,.22))}
  .ep__ico{width:58px;height:58px;border-radius:16px;display:grid;place-items:center;margin:0 auto 14px;
    background:radial-gradient(120% 120% at 30% 20%,rgba(226,194,117,.16),rgba(226,194,117,.03));
    border:1px solid rgba(226,194,117,.22)}
  .ep__ico svg{width:29px;height:29px;color:#E2C275;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
  .ep__code{font:800 3.4rem/1 'IBM Plex Sans','Hanken Grotesk',sans-serif;letter-spacing:-.02em;color:#E2C275}
  .ep__title{font-size:1.18rem;font-weight:700;color:#F3EFE6;margin:6px 0 8px}
  .ep__msg{font-size:13.5px;line-height:1.6;color:#DBD1C1;margin:0 auto 26px;max-width:360px}
  .ep__actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
  .ep-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:12px;
    font:600 14px/1 'Hanken Grotesk',sans-serif;text-decoration:none;cursor:pointer;border:0;transition:.2s ease}
  .ep-btn svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .ep-btn--p{background:linear-gradient(180deg,#4E9CA1,#005059);color:#EDEAE0;box-shadow:0 10px 26px -12px rgba(0,80,89,.55)}
  .ep-btn--p:hover{filter:brightness(1.06)}
  .ep-btn--g{background:rgba(255,255,255,.06);color:#DBD1C1;border:1px solid rgba(78,156,161,.25)}
  .ep-btn--g:hover{color:#F3EFE6;border-color:rgba(78,156,161,.42)}
  .ep__foot{margin-top:22px;font:400 10.5px/1 'IBM Plex Mono',ui-monospace,monospace;letter-spacing:.1em;color:#AEA08B}
  @media (max-width:480px){.ep{padding:30px 22px 24px}.ep__code{font-size:2.9rem}}
</style>
</head>
<body>
  <div class="ep">
    <img class="ep__logo" src="<?= h($B) ?>/assets/img/logo_vero.png" alt="VERO">
    <div class="ep__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $__icon ?></svg></div>
    <div class="ep__code"><?= $__code ?></div>
    <div class="ep__title"><?= h($__title) ?></div>
    <div class="ep__msg"><?= h($__msg) ?></div>
    <div class="ep__actions"><?= $__actions /* HTML já montado/escapado pelo chamador */ ?></div>
    <div class="ep__foot">VERO · ACESSO SEGURO</div>
  </div>
</body>
</html>
