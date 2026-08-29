<?php
declare(strict_types=1);
/* ============================================================
   VERO — Portal da Universidade — PRÁTICA (/universidade/praticar.php)
   Lista os exercícios verificados contra dados reais do tenant
   (Fazenda Escola). Auth pela sessão do ERP; conteúdo do banco
   separado. A verificação roda em verificar.php (POST).
   ============================================================ */
require_once __DIR__ . '/../includes/uni_auth.php'; uni_auth_boot(); uni_auth_require();       // exige login (redireciona se ausente)
require_once __DIR__ . '/../includes/uni_pratica.php';

$base = BIOS_BASE;
$ctx  = uni_ctx();

$exercicios = uni_pratica_todas($ctx);

/* Resultado de uma verificação recém-feita (flash one-shot da sessão). */
$flash = $_SESSION['uni_pratica_flash'] ?? null;
unset($_SESSION['uni_pratica_flash']);
$flashSlug = is_array($flash) ? (string)($flash['slug'] ?? '') : '';
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Praticar — Universidade VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-portal.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/uni-portal.css') ?: '1' ?>">
<style>
  .pr-intro{ background:#fff; border:1px solid var(--up-bd,#e2e2da); border-radius:14px; padding:16px 18px; margin:0 0 22px; }
  .pr-intro h2{ margin:0 0 6px; font-size:16px; font-weight:800; color:var(--up-deep,#00363D); }
  .pr-intro p{ margin:0; font-size:13.5px; color:var(--up-mut,#5a5a52); line-height:1.5; }
  .pr-card{ cursor:default; }
  .pr-card:hover{ transform:none; box-shadow:0 6px 18px rgba(0,54,61,.06); border-color:var(--up-bd,#e2e2da); }
  .pr-enun{ margin:2px 0 4px; font-size:13.5px; color:var(--up-mut,#5a5a52); line-height:1.5; }
  .pr-enun strong{ color:var(--up-deep,#00363D); }
  .pr-links{ display:flex; flex-wrap:wrap; gap:14px; font-size:12.5px; margin-top:2px; }
  .pr-links a{ color:var(--up-accent,#005059); font-weight:600; text-decoration:none; }
  .pr-links a:hover{ text-decoration:underline; }
  .pr-actions{ display:flex; align-items:center; gap:12px; margin-top:auto; padding-top:6px; }
  .pr-btn{ appearance:none; border:0; cursor:pointer; background:#8a5a00; color:#fff; font-weight:700;
           font-size:13.5px; padding:9px 18px; border-radius:999px; }
  .pr-btn:hover{ background:#754c00; }
  .pr-res{ font-size:13px; font-weight:700; padding:6px 12px; border-radius:999px; }
  .pr-res-ok{ background:#dff3e4; color:#1c6b39; }
  .pr-res-fail{ background:#fbe4de; color:#8a2f1e; }
  .pr-msg{ font-size:12.5px; color:var(--up-mut,#5a5a52); margin-top:6px; line-height:1.45; }
</style>
</head>
<body class="uni-portal">
<?= uni_portal_header($ctx, 'praticar') ?>

<section class="up-hero">
  <div class="up-hero-in">
    <h1>Praticar de verdade</h1>
    <p>Cada exercício abaixo é conferido contra os dados reais da sua fazenda. Faça a tarefa no sistema e volte para verificar.</p>
  </div>
</section>

<main class="up-wrap">
  <div class="pr-intro">
    <h2>Você está praticando na <?= uni_h($ctx['tenant_nome']) ?> (Fazenda Escola)</h2>
    <p>Nada aqui é simulação: quando você finaliza um apontamento, abre uma safra ou dá entrada no estoque, o VERO
       confere direto no banco da sua fazenda. Faça a tarefa na tela do sistema e clique em <strong>Verificar</strong>.</p>
  </div>

  <?php if (!$exercicios): ?>
    <div class="up-vazio">
      <p>Nenhum exercício de prática disponível para o seu perfil no momento.</p>
    </div>
  <?php else: ?>
    <div class="up-grid">
      <?php foreach ($exercicios as $e): ?>
        <?php
          $slug     = (string)$e['slug'];
          $capSlug  = (string)$e['capsula_slug'];
          $rota     = (string)($e['rota'] ?? '');
          $temFlash = $flashSlug !== '' && $flashSlug === $slug && is_array($flash);
        ?>
        <div class="up-card pr-card">
          <div class="up-card-top">
            <span class="up-badge up-badge-praticar"><?= uni_h(uni_tipo_label('PRATICAR')) ?></span>
            <?php if (!empty($e['modulo'])): ?><span class="up-modulo"><?= uni_h(uni_modulo_label((string)$e['modulo'])) ?></span><?php endif; ?>
          </div>
          <h3><?= uni_h((string)$e['titulo']) ?></h3>
          <div class="pr-enun"><?= uni_md_html((string)$e['enunciado_md']) ?></div>
          <div class="pr-links">
            <a href="<?= $base ?>/universidade/capsula/<?= uni_h($capSlug) ?>">Ver a cápsula</a>
            <?php if ($rota !== ''): ?>
              <a href="<?= $base . uni_h($rota) ?>">Abrir a tela ↗</a>
            <?php endif; ?>
          </div>
          <form class="pr-actions" method="post" action="<?= $base ?>/universidade/verificar.php">
            <input type="hidden" name="csrf_token" value="<?= uni_h(uni_csrf()) ?>">
            <input type="hidden" name="slug" value="<?= uni_h($slug) ?>">
            <button type="submit" class="pr-btn">Verificar</button>
            <?php if ($temFlash): ?>
              <?php $ok = !empty($flash['ok']); ?>
              <span class="pr-res <?= $ok ? 'pr-res-ok' : 'pr-res-fail' ?>"><?= $ok ? 'Concluído' : 'Ainda não' ?></span>
            <?php endif; ?>
          </form>
          <?php if ($temFlash && $flashSlug === $slug): ?>
            <p class="pr-msg"><?= uni_h((string)($flash['msg'] ?? '')) ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<footer class="up-rodape">Universidade VERO · <?= uni_h($ctx['tenant_nome']) ?> · prática conferida contra os dados reais da fazenda</footer>
</body>
</html>
