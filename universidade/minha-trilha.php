<?php
declare(strict_types=1);
/* ============================================================
   VERO — Portal da Universidade — Minha trilha (/universidade/minha-trilha.php)
   Trilhas recomendadas pelo perfil do usuário, com progresso e o
   "próximo passo" em destaque. Sem trilhas do perfil → mostra todas.
   ============================================================ */
require_once __DIR__ . '/../includes/uni_auth.php'; uni_auth_boot(); uni_auth_require();        // exige login
require_once __DIR__ . '/../includes/uni_trilhas.php';

$base = BIOS_BASE;
$ctx  = uni_ctx();

/* Minha trilha = recomendadas pelo perfil ∪ matriculadas. Sem nenhuma → mostra todas. */
$doPerfil   = uni_trilhas_do_perfil($ctx);
$matricul   = uni_trilhas_matriculadas($ctx);
$mapT = [];
foreach (array_merge($matricul, $doPerfil) as $t) $mapT[(string)$t['slug']] = $t;
$trilhas    = array_values($mapT);
$semPerfil  = empty($doPerfil) && empty($matricul);
if (!$trilhas) $trilhas = uni_trilhas_todas($ctx);

/* Próximo passo: 1ª cápsula não concluída da trilha de maior relevância.
   Relevância = 1ª trilha com progresso pendente (percentual < 100); senão a 1ª. */
$proximo = null;      // ['trilha'=>..., 'curso'=>..., 'capsula'=>...]
if ($trilhas) {
    $alvo = null;
    foreach ($trilhas as $t) {
        if ((int)$t['total'] > 0 && (int)$t['percentual'] < 100) { $alvo = $t; break; }
    }
    if ($alvo === null) $alvo = $trilhas[0];

    $det = uni_trilha_por_slug((string)$alvo['slug'], $ctx);
    if ($det) {
        foreach ($det['cursos'] as $curso) {
            foreach ($curso['capsulas'] as $cap) {
                if (($cap['estado'] ?? 'nao_iniciada') !== 'concluida') {
                    $proximo = ['trilha' => $det, 'curso' => $curso, 'capsula' => $cap];
                    break 2;
                }
            }
        }
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Minha trilha · Universidade VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-portal.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/uni-portal.css') ?: '1' ?>">
<style>
/* Página Minha trilha — reaproveita tokens de uni-portal.css */
.mt-aviso{ background:#fdf7e7; border:1px solid #e7ddc8; color:#5a4a1e; border-radius:12px; padding:14px 18px; margin:0 0 22px; font-size:14px; }
.mt-proximo{ background:linear-gradient(135deg,var(--up-accent),var(--up-deep)); color:#fff; border-radius:16px; padding:26px 30px; margin:0 0 28px; }
.mt-proximo .mt-eyebrow{ font-size:11px; text-transform:uppercase; letter-spacing:.12em; opacity:.82; margin:0 0 8px; }
.mt-proximo h2{ margin:0 0 6px; font-size:22px; line-height:1.25; font-weight:800; }
.mt-proximo .mt-ctx{ margin:0 0 18px; opacity:.9; font-size:14px; }
.mt-proximo .mt-cta{ display:inline-block; background:#0e1f22; color:#fff; text-decoration:none; font-weight:700; padding:12px 22px; border-radius:10px; }
.mt-proximo .mt-cta:hover{ background:#000; }
.mt-list{ display:flex; flex-direction:column; gap:16px; }
.mt-trilha{ display:block; text-decoration:none; color:var(--up-ink); background:var(--up-card); border:1px solid var(--up-line); border-radius:16px; padding:22px 26px; transition:transform .15s, box-shadow .15s, border-color .15s; }
.mt-trilha:hover{ transform:translateY(-2px); box-shadow:0 10px 26px rgba(0,54,61,.12); border-color:var(--up-accent); }
.mt-trilha-top{ display:flex; align-items:baseline; justify-content:space-between; gap:14px; flex-wrap:wrap; }
.mt-trilha h3{ margin:0; font-size:18px; font-weight:700; }
.mt-publico{ margin:6px 0 0; font-size:13.5px; color:var(--up-mut); }
.mt-meta{ font-size:12px; color:var(--up-mut); white-space:nowrap; }
.mt-bar{ height:10px; border-radius:999px; background:#e6edee; overflow:hidden; margin:16px 0 6px; }
.mt-bar>i{ display:block; height:100%; background:linear-gradient(90deg,var(--up-accent),var(--up-deep)); border-radius:999px; }
.mt-prog-txt{ font-size:12.5px; color:var(--up-mut); display:flex; justify-content:space-between; }
.mt-prog-txt b{ color:var(--up-deep); }
</style>
</head>
<body class="uni-portal">
<?= uni_portal_header($ctx, 'trilha') ?>

<section class="up-hero">
  <div class="up-hero-in">
    <h1>Minha trilha</h1>
    <p>O caminho de aprendizado do seu perfil, no ritmo do seu dia a dia. Continue de onde parou.</p>
  </div>
</section>

<main class="up-wrap">
  <?php if ($semPerfil && $trilhas): ?>
    <div class="mt-aviso">Ainda não há uma trilha específica para o seu perfil. Enquanto isso, veja todas as trilhas disponíveis abaixo.</div>
  <?php endif; ?>

  <?php if ($proximo): ?>
    <div class="mt-proximo">
      <p class="mt-eyebrow">Seu próximo passo</p>
      <h2><?= uni_h($proximo['capsula']['titulo']) ?></h2>
      <p class="mt-ctx"><?= uni_h($proximo['trilha']['titulo']) ?> · <?= uni_h($proximo['curso']['titulo']) ?></p>
      <a class="mt-cta" href="<?= $base ?>/universidade/capsula/<?= uni_h($proximo['capsula']['slug']) ?>">Começar agora →</a>
    </div>
  <?php endif; ?>

  <?php if (!$trilhas): ?>
    <div class="up-vazio">
      <p>Nenhuma trilha disponível ainda.</p>
      <a href="<?= $base ?>/universidade/">Ver catálogo de cápsulas</a>
    </div>
  <?php else: ?>
    <div class="mt-list">
      <?php foreach ($trilhas as $t): ?>
        <?php $pct = (int)$t['percentual']; ?>
        <a class="mt-trilha" href="<?= $base ?>/universidade/trilha.php?slug=<?= uni_h($t['slug']) ?>">
          <div class="mt-trilha-top">
            <div>
              <h3><?= uni_h($t['titulo']) ?></h3>
              <?php if (!empty($t['publico'])): ?><p class="mt-publico"><?= uni_h($t['publico']) ?></p><?php endif; ?>
            </div>
            <?php if (!empty($t['tempo_estimado_min'])): ?>
              <span class="mt-meta"><?= (int)$t['tempo_estimado_min'] ?> min estimados</span>
            <?php endif; ?>
          </div>
          <div class="mt-bar"><i style="width:<?= $pct ?>%"></i></div>
          <div class="mt-prog-txt">
            <span><b><?= (int)$t['concluidas'] ?></b> de <b><?= (int)$t['total'] ?></b> cápsulas concluídas</span>
            <span><?= $pct ?>%</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <p style="margin-top:26px;font-size:14px;"><a href="<?= $base ?>/universidade/">Ver catálogo completo →</a></p>
</main>

<footer class="up-rodape">Universidade VERO · <?= uni_h($ctx['tenant_nome']) ?> · sua trilha filtrada pelo seu perfil</footer>
</body>
</html>
