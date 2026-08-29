<?php
declare(strict_types=1);
/* ============================================================
   VERO — Portal da Universidade — Trilha (/universidade/trilha.php?slug=…)
   Detalhe de uma trilha: público-alvo, tempo, progresso, escopo,
   cursos e cápsulas com estado, matrícula e marcação de conclusão.
   ============================================================ */
require_once __DIR__ . '/../includes/uni_auth.php'; uni_auth_boot(); uni_auth_require();        // exige login + funções CSRF
require_once __DIR__ . '/../includes/uni_trilhas.php';

$base = BIOS_BASE;
$ctx  = uni_ctx();

$slug   = trim((string)($_GET['slug'] ?? ''));
$trilha = $slug !== '' ? uni_trilha_por_slug($slug, $ctx) : null;

/* URL atual (interna) para o campo `voltar` dos formulários (PRG). */
$voltar = (string)($_SERVER['REQUEST_URI'] ?? ($base . '/universidade/'));
$csrf   = uni_csrf();
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $trilha ? uni_h($trilha['titulo']) : 'Trilha' ?> · Universidade VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-portal.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/uni-portal.css') ?: '1' ?>">
<style>
/* Página Trilha — reaproveita tokens de uni-portal.css */
.tr-head{ max-width:1200px; }
.tr-meta{ display:flex; flex-wrap:wrap; gap:16px; align-items:center; margin:6px 0 0; font-size:13.5px; color:var(--up-mut); }
.tr-bar{ height:10px; border-radius:999px; background:#e6edee; overflow:hidden; margin:18px 0 6px; max-width:520px; }
.tr-bar>i{ display:block; height:100%; background:linear-gradient(90deg,var(--up-accent),var(--up-deep)); border-radius:999px; }
.tr-prog-txt{ font-size:12.5px; color:var(--up-mut); }
.tr-prog-txt b{ color:var(--up-deep); }
.tr-actions{ margin:20px 0 0; }
.tr-matricular{ display:inline-block; background:var(--up-accent); color:#fff; border:0; border-radius:10px; padding:12px 22px; font-weight:700; font-size:15px; cursor:pointer; }
.tr-matricular:hover{ background:var(--up-deep); }
.tr-matriculado{ display:inline-flex; align-items:center; gap:8px; color:#20603a; font-weight:600; font-size:14px; }
.tr-escopo{ background:#fdf7e7; border:1px solid #e7ddc8; color:#5a4a1e; border-radius:14px; padding:18px 24px; margin:26px 0; max-width:900px; }
.tr-escopo h2{ margin:0 0 8px; font-size:13px; text-transform:uppercase; letter-spacing:.06em; color:#8a5a00; }
.tr-escopo p{ margin:0 0 10px; font-size:14px; line-height:1.5; }
.tr-escopo ul, .tr-escopo ol{ margin:0 0 10px; padding-left:20px; }
.tr-escopo li{ margin:0 0 6px; font-size:14px; }
.tr-curso{ margin:28px 0 0; }
.tr-curso h2{ font-size:16px; font-weight:800; margin:0 0 4px; color:var(--up-deep); }
.tr-caps{ list-style:none; margin:12px 0 0; padding:0; display:flex; flex-direction:column; gap:10px; }
.tr-cap{ display:flex; align-items:center; gap:14px; background:var(--up-card); border:1px solid var(--up-line); border-radius:12px; padding:14px 18px; }
.tr-cap.done{ border-color:#bcdcc6; background:#f3faf5; }
.tr-check{ flex:0 0 auto; width:26px; height:26px; border-radius:50%; border:2px solid var(--up-line); display:flex; align-items:center; justify-content:center; font-size:15px; color:#fff; }
.tr-cap.done .tr-check{ background:#20603a; border-color:#20603a; }
.tr-cap-main{ flex:1; min-width:0; }
.tr-cap-main a{ text-decoration:none; color:var(--up-ink); font-weight:600; font-size:15px; }
.tr-cap-main a:hover{ text-decoration:underline; }
.tr-cap-sub{ display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:5px; }
.tr-cap-form{ flex:0 0 auto; }
.tr-cap-btn{ border:1px solid var(--up-accent); background:#fff; color:var(--up-accent); border-radius:8px; padding:7px 12px; font-size:12.5px; font-weight:600; cursor:pointer; white-space:nowrap; }
.tr-cap-btn:hover{ background:var(--up-accent); color:#fff; }
.tr-cap-btn.undo{ border-color:var(--up-line); color:var(--up-mut); }
.tr-cap-btn.undo:hover{ background:#eee; color:var(--up-ink); }
</style>
</head>
<body class="uni-portal">
<?= uni_portal_header($ctx, 'trilha') ?>

<?php if (!$trilha): ?>
  <main class="up-wrap">
    <div class="up-vazio">
      <p>Trilha não encontrada ou fora do seu acesso.</p>
      <a href="<?= $base ?>/universidade/">Ver catálogo de cápsulas</a>
    </div>
  </main>
<?php else: ?>
  <?php $pct = (int)$trilha['percentual']; ?>
  <section class="up-hero">
    <div class="up-hero-in tr-head">
      <h1><?= uni_h($trilha['titulo']) ?></h1>
      <?php if (!empty($trilha['publico'])): ?><p><?= uni_h($trilha['publico']) ?></p><?php endif; ?>
      <div class="tr-meta" style="color:#e6f0f1;opacity:.92;">
        <?php if (!empty($trilha['tempo_estimado_min'])): ?><span><?= (int)$trilha['tempo_estimado_min'] ?> min estimados</span><?php endif; ?>
        <span><?= (int)$trilha['total'] ?> cápsula<?= (int)$trilha['total'] === 1 ? '' : 's' ?></span>
      </div>
    </div>
  </section>

  <main class="up-wrap">
    <div class="tr-bar"><i style="width:<?= $pct ?>%"></i></div>
    <p class="tr-prog-txt"><b><?= (int)$trilha['concluidas'] ?></b> de <b><?= (int)$trilha['total'] ?></b> concluídas · <?= $pct ?>%</p>

    <div class="tr-actions">
      <?php if (!$trilha['matriculado']): ?>
        <form method="post" action="<?= $base ?>/universidade/progresso.php">
          <input type="hidden" name="csrf_token" value="<?= uni_h($csrf) ?>">
          <input type="hidden" name="acao" value="matricula">
          <input type="hidden" name="trilha_id" value="<?= (int)$trilha['id'] ?>">
          <input type="hidden" name="voltar" value="<?= uni_h($voltar) ?>">
          <button type="submit" class="tr-matricular">Matricular-me nesta trilha</button>
        </form>
      <?php else: ?>
        <span class="tr-matriculado">✓ Você está matriculado nesta trilha</span>
      <?php endif; ?>
    </div>

    <?php if (empty($trilha['cursos'])): ?>
      <div class="up-vazio"><p>Esta trilha ainda não tem cápsulas visíveis para o seu perfil.</p></div>
    <?php else: ?>
      <?php foreach ($trilha['cursos'] as $curso): ?>
        <section class="tr-curso">
          <h2><?= uni_h($curso['titulo']) ?></h2>
          <ul class="tr-caps">
            <?php foreach ($curso['capsulas'] as $cap): ?>
              <?php $done = ($cap['estado'] ?? 'nao_iniciada') === 'concluida'; ?>
              <li class="tr-cap<?= $done ? ' done' : '' ?>">
                <span class="tr-check"><?= $done ? '✓' : '' ?></span>
                <div class="tr-cap-main">
                  <a href="<?= $base ?>/universidade/capsula/<?= uni_h($cap['slug']) ?>"><?= uni_h($cap['titulo']) ?></a>
                  <div class="tr-cap-sub">
                    <span class="up-badge up-badge-<?= strtolower((string)$cap['tipo']) ?>"><?= uni_h(uni_tipo_label((string)$cap['tipo'])) ?></span>
                    <?php if (!empty($cap['duracao_seg'])): ?><span class="up-dur"><?= uni_h(uni_duracao((int)$cap['duracao_seg'])) ?></span><?php endif; ?>
                  </div>
                </div>
                <form class="tr-cap-form" method="post" action="<?= $base ?>/universidade/progresso.php">
                  <input type="hidden" name="csrf_token" value="<?= uni_h($csrf) ?>">
                  <input type="hidden" name="acao" value="progresso">
                  <input type="hidden" name="capsula_id" value="<?= (int)$cap['id'] ?>">
                  <input type="hidden" name="estado" value="<?= $done ? 'nao_iniciada' : 'concluida' ?>">
                  <input type="hidden" name="voltar" value="<?= uni_h($voltar) ?>">
                  <?php if ($done): ?>
                    <button type="submit" class="tr-cap-btn undo">Desmarcar</button>
                  <?php else: ?>
                    <button type="submit" class="tr-cap-btn">Marcar como concluída</button>
                  <?php endif; ?>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>

    <p style="margin-top:30px;font-size:14px;"><a href="<?= $base ?>/universidade/">← Ver catálogo completo</a></p>
  </main>
<?php endif; ?>

<footer class="up-rodape">Universidade VERO · <?= uni_h($ctx['tenant_nome']) ?></footer>
</body>
</html>
