<?php
declare(strict_types=1);
/* ============================================================
   VERO — Portal da Universidade — Catálogo (/universidade/)
   Camada 3. Auth pela sessão do ERP; conteúdo do banco separado.
   ============================================================ */
require_once __DIR__ . '/../includes/uni_auth.php'; uni_auth_boot(); uni_auth_require();       // exige login (redireciona se ausente)
require_once __DIR__ . '/../includes/uni_portal.php';

$base = BIOS_BASE;
$ctx  = uni_ctx();

$filtros = [
    'q'      => trim((string)($_GET['q'] ?? '')),
    'modulo' => trim((string)($_GET['modulo'] ?? '')),
    'tipo'   => trim((string)($_GET['tipo'] ?? '')),
    'nivel'  => trim((string)($_GET['nivel'] ?? '')),
];
$capsulas = uni_catalogo($ctx, $filtros);
$modulos  = uni_modulos($ctx);
$temFiltro = $filtros['q'] !== '' || $filtros['modulo'] !== '' || $filtros['tipo'] !== '' || $filtros['nivel'] !== '';
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Universidade VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-portal.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/uni-portal.css') ?: '1' ?>">
</head>
<body class="uni-portal">
<?= uni_portal_header($ctx, 'catalogo') ?>

<section class="up-hero">
  <div class="up-hero-in">
    <span class="up-hero-eyebrow">Universidade VERO</span>
    <h1>O que você precisa aprender para usar o VERO</h1>
    <p>Cada cápsula é uma tarefa objetiva — do apontamento ao custo da safra. Você vê só o que o seu perfil pode fazer.</p>
  </div>
</section>

<main class="up-wrap">
  <form class="up-filtros" method="get" action="<?= $base ?>/universidade/">
    <label class="up-f-busca">Buscar
      <input type="search" name="q" value="<?= uni_h($filtros['q']) ?>" placeholder="tarefa, módulo…" autocomplete="off">
    </label>
    <label>Módulo
      <select name="modulo" onchange="this.form.submit()">
        <option value="">Todos</option>
        <?php foreach ($modulos as $m): ?>
          <option value="<?= uni_h($m) ?>" <?= $filtros['modulo'] === $m ? 'selected' : '' ?>><?= uni_h(uni_modulo_label($m)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Tipo
      <select name="tipo" onchange="this.form.submit()">
        <option value="">Todos</option>
        <?php foreach (['FAZER','ENTENDER','CONSULTAR','PRATICAR','VERIFICAR'] as $t): ?>
          <option value="<?= $t ?>" <?= $filtros['tipo'] === $t ? 'selected' : '' ?>><?= uni_h(uni_tipo_label($t)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Nível
      <select name="nivel" onchange="this.form.submit()">
        <option value="">Todos</option>
        <?php foreach (['iniciante'=>'Iniciante','intermediario'=>'Intermediário','expert'=>'Expert'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $filtros['nivel'] === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="up-f-go" type="submit">Buscar</button>
    <?php if ($temFiltro): ?><a class="up-limpar" href="<?= $base ?>/universidade/">Limpar filtros</a><?php endif; ?>
    <span class="up-conta"><?= count($capsulas) ?> cápsula<?= count($capsulas) === 1 ? '' : 's' ?></span>
  </form>

  <?php if (!$capsulas): ?>
    <div class="up-vazio">
      <p>Nenhuma cápsula encontrada<?= $temFiltro ? ' com esses filtros' : '' ?>.</p>
      <?php if ($temFiltro): ?><a href="<?= $base ?>/universidade/">Ver todas</a><?php endif; ?>
    </div>
  <?php else:
    /* Agrupa por módulo, na ordem definida (produção → suprimentos → financeiro). */
    $porModulo = [];
    foreach ($capsulas as $c) { $porModulo[(string)$c['modulo']][] = $c; }
    $ordem = array_flip(uni_modulo_ordem());
    uksort($porModulo, fn($a, $b) => ($ordem[$a] ?? 99) <=> ($ordem[$b] ?? 99));
    $verTiles = !$temFiltro; // landing sem filtro → cards quadrados dos módulos
  ?>
    <?php if ($verTiles): ?>
      <div class="up-mods">
        <?php foreach ($porModulo as $mod => $caps): ?>
          <a class="up-mod" href="<?= $base ?>/universidade/?modulo=<?= urlencode($mod) ?>">
            <img class="up-mod-thumb" src="<?= $base ?>/assets/uni/modulos/<?= urlencode($mod) ?>.svg" alt="" width="60" height="60" loading="lazy" onerror="this.onerror=null;this.src='<?= $base ?>/assets/uni/modulos/_fallback.svg'">
            <span class="up-mod-count"><?= count($caps) ?></span>
            <h3><?= uni_h(uni_modulo_label($mod)) ?></h3>
            <span class="up-mod-tag">cápsula<?= count($caps) === 1 ? '' : 's' ?> · abrir →</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <a class="up-back" href="<?= $base ?>/universidade/">← Todos os módulos</a>
      <?php foreach ($porModulo as $mod => $caps): ?>
      <section class="up-sec">
        <div class="up-sec-h">
          <h2><?= uni_h(uni_modulo_label($mod)) ?></h2>
          <span class="up-sec-count"><?= count($caps) ?> cápsula<?= count($caps) === 1 ? '' : 's' ?></span>
        </div>
        <div class="up-grid">
          <?php foreach ($caps as $c): ?>
            <a class="up-card" href="<?= $base ?>/universidade/capsula/<?= uni_h($c['slug']) ?>">
              <div class="up-card-top">
                <span class="up-badge up-badge-<?= strtolower($c['tipo']) ?>"><?= uni_h(uni_tipo_label($c['tipo'])) ?></span>
                <?php if ($c['duracao_seg']): ?><span class="up-dur"><?= uni_h(uni_duracao((int)$c['duracao_seg'])) ?></span><?php endif; ?>
              </div>
              <h3><?= uni_h($c['titulo']) ?></h3>
              <?php if (!empty($c['resumo'])): ?><p class="up-resumo"><?= uni_h($c['resumo']) ?></p><?php endif; ?>
              <div class="up-card-foot">
                <span class="up-nivel up-nivel-<?= uni_h($c['nivel']) ?>"><?= uni_h(ucfirst($c['nivel'])) ?></span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</main>

<footer class="up-rodape">Universidade VERO · <?= uni_h($ctx['tenant_nome']) ?> · escolha um módulo para ver as cápsulas</footer>
</body>
</html>
