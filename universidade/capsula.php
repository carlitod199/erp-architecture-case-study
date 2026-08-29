<?php
declare(strict_types=1);
/* ============================================================
   VERO — Portal da Universidade — Detalhe da cápsula
   /universidade/capsula/{slug}  (via .htaccess) ou ?slug=
   ============================================================ */
require_once __DIR__ . '/../includes/uni_auth.php'; uni_auth_boot(); uni_auth_require();
require_once __DIR__ . '/../includes/uni_portal.php';

$base = BIOS_BASE;
$ctx  = uni_ctx();
$slug = trim((string)($_GET['slug'] ?? ''));
$slug = preg_replace('/[^a-z0-9-]/', '', $slug);

$c = $slug !== '' ? uni_capsula($slug, $ctx) : null;

if (!$c) {
    http_response_code(404);
    $titulo = 'Cápsula não encontrada';
} else {
    $titulo = (string)$c['titulo'];
    /* vídeo (se houver) */
    $qv = uni_pdo()->prepare("SELECT url, duracao_seg FROM uni_ativo WHERE capsula_id=? AND tipo='video' AND estado='ok' ORDER BY ordem LIMIT 1");
    $qv->execute([(int)$c['id']]);
    $video = $qv->fetch();
    /* última publicação (data) para "atualizado em" */
    $qd = uni_pdo()->prepare("SELECT DATE(MAX(publicado_em)) FROM uni_publicacao WHERE capsula_id=?");
    $qd->execute([(int)$c['id']]);
    $atualizado = $c['revisado_em'] ?: ($qd->fetchColumn() ?: null);
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= uni_h($titulo) ?> · Universidade VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-portal.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/uni-portal.css') ?: '1' ?>">
</head>
<body class="uni-portal">
<?= uni_portal_header($ctx, 'catalogo') ?>

<?php if (!$c): ?>
  <main class="up-wrap up-detalhe">
    <div class="up-vazio">
      <p>Essa cápsula não existe, não está publicada, ou o seu perfil não tem acesso a ela.</p>
      <a href="<?= $base ?>/universidade/">Voltar ao catálogo</a>
    </div>
  </main>
<?php else: ?>
  <?php $rotaPrincipal = $c['rotas'][0]['rota'] ?? null; ?>
  <main class="up-wrap up-detalhe">
    <article class="up-artigo">
      <div class="up-art-head">
        <div class="up-art-badges">
          <span class="up-badge up-badge-<?= strtolower($c['tipo']) ?>"><?= uni_h(uni_tipo_label($c['tipo'])) ?></span>
          <?php if (!empty($c['modulo'])): ?><span class="up-modulo"><?= uni_h(uni_modulo_label($c['modulo'])) ?></span><?php endif; ?>
          <?php if ($c['duracao_seg']): ?><span class="up-dur"><?= uni_h(uni_duracao((int)$c['duracao_seg'])) ?></span><?php endif; ?>
          <span class="up-nivel up-nivel-<?= uni_h($c['nivel']) ?>"><?= uni_h(ucfirst($c['nivel'])) ?></span>
        </div>
        <h1><?= uni_h($c['titulo']) ?></h1>
        <?php if (!empty($c['resumo'])): ?><p class="up-lead"><?= uni_h($c['resumo']) ?></p><?php endif; ?>
        <?php if ($rotaPrincipal): ?>
          <a class="up-abrir-tela" href="<?= $base . uni_h($rotaPrincipal) ?>">Abrir a tela &nbsp;<code><?= uni_h($rotaPrincipal) ?></code></a>
        <?php endif; ?>
      </div>

      <?php if (!empty($video['url'])): ?>
        <a class="up-video" href="<?= uni_h($video['url']) ?>" target="_blank" rel="noopener">
          ▶ Assistir ao vídeo<?= $video['duracao_seg'] ? ' · ' . uni_h(uni_duracao((int)$video['duracao_seg'])) : '' ?>
        </a>
      <?php endif; ?>

      <?php $passos = uni_passos((int)$c['id']); if ($passos): ?>
      <section class="up-passos">
        <h2>Passo a passo, na tela</h2>
        <?php foreach ($passos as $p): ?>
          <div class="up-passo">
            <div class="up-passo-txt"><span class="up-passo-n"><?= (int)$p['ordem'] ?></span><span><?= uni_h($p['texto']) ?></span></div>
            <?php if (!empty($p['imagem_url'])): ?>
              <img class="up-passo-img" src="<?= $base . uni_h($p['imagem_url']) ?>" alt="Passo <?= (int)$p['ordem'] ?>: <?= uni_h($p['texto']) ?>" loading="lazy">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <div class="up-corpo">
        <?= uni_md_html((string)$c['corpo_md']) /* já escapado dentro do render */ ?>
      </div>

      <?php
        $antes = $c['relacoes']['prerequisito'] ?? [];
        $depois = $c['relacoes']['proximo'] ?? [];
        $rel = $c['relacoes']['relacionado'] ?? [];
        if ($antes || $depois || $rel):
      ?>
      <nav class="up-fluxo">
        <?php if ($antes): ?>
          <div class="up-fluxo-col"><span>Antes</span><?php foreach ($antes as $r): ?>
            <a href="<?= $base ?>/universidade/capsula/<?= uni_h($r['slug']) ?>"><?= uni_h($r['titulo']) ?></a>
          <?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($depois): ?>
          <div class="up-fluxo-col"><span>Depois</span><?php foreach ($depois as $r): ?>
            <a href="<?= $base ?>/universidade/capsula/<?= uni_h($r['slug']) ?>"><?= uni_h($r['titulo']) ?></a>
          <?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($rel): ?>
          <div class="up-fluxo-col"><span>Relacionado</span><?php foreach ($rel as $r): ?>
            <a href="<?= $base ?>/universidade/capsula/<?= uni_h($r['slug']) ?>"><?= uni_h($r['titulo']) ?></a>
          <?php endforeach; ?></div>
        <?php endif; ?>
      </nav>
      <?php endif; ?>
    </article>

    <footer class="up-art-foot">
      <?= $atualizado ? 'Revisado em ' . uni_h((string)$atualizado) : '' ?><?= !empty($c['versao']) ? ' · v' . uni_h($c['versao']) : '' ?>
    </footer>
  </main>
<?php endif; ?>
</body>
</html>
