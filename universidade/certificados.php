<?php
declare(strict_types=1);
/* ============================================================
   VERO — Portal da Universidade — Meus certificados
   Exige login. Auto-emite o certificado de cada trilha 100%
   concluída (idempotente) e lista os certificados do usuário.
   ============================================================ */
require_once __DIR__ . '/../includes/uni_auth.php'; uni_auth_boot(); uni_auth_require();            // exige login (redireciona se ausente)
require_once __DIR__ . '/../includes/uni_certificacao.php'; // emissão/validação + uni_trilhas/uni_portal

$base = BIOS_BASE;
$ctx  = uni_ctx();

/* 1) Auto-emissão: toda trilha 100% concluída vira certificado (idempotente). */
foreach (uni_trilhas_todas($ctx) as $trilha) {
    if ((int)($trilha['percentual'] ?? 0) === 100) {
        uni_cert_emitir($ctx, (int)$trilha['id']);
    }
}

/* 2) Lista os certificados do usuário. */
$certs = uni_cert_do_usuario($ctx);

/** Data amigável dd/mm/AAAA a partir de datetime/date. */
function certs_data(?string $v): string
{
    if (!$v) return '';
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : (string)$v;
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Meus certificados · Universidade VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-portal.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/uni-portal.css') ?: '1' ?>">
<style>
  /* ---- Certificados (grade de diplomas) ---- */
  .cert-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:22px; }
  .cert-card{
    position:relative; background:var(--up-card); border:1px solid var(--up-line);
    border-radius:16px; padding:0; overflow:hidden;
    box-shadow:0 6px 20px rgba(0,54,61,.06);
    display:flex; flex-direction:column;
  }
  .cert-card::before{ content:""; display:block; height:6px;
    background:linear-gradient(90deg,var(--up-accent),var(--up-deep)); }
  .cert-body{ padding:26px 26px 20px; display:flex; flex-direction:column; gap:14px; flex:1;
    border:1px solid transparent; border-top:0;
  }
  .cert-seal{ position:absolute; top:20px; right:22px; font-size:34px; line-height:1; opacity:.9; }
  .cert-eyebrow{ font-size:11px; text-transform:uppercase; letter-spacing:.14em; color:var(--up-mut); font-weight:700; }
  .cert-titulo{ margin:0; font-size:21px; line-height:1.25; font-weight:800; color:var(--up-deep); max-width:26ch; }
  .cert-linha{ font-size:13.5px; color:var(--up-mut); }
  .cert-linha strong{ color:var(--up-ink); font-weight:700; }
  .cert-codigo-wrap{ margin-top:2px; }
  .cert-codigo-lab{ font-size:10.5px; text-transform:uppercase; letter-spacing:.1em; color:var(--up-mut); display:block; margin-bottom:4px; }
  .cert-codigo{
    display:inline-block; font-family:'IBM Plex Mono','SFMono-Regular',ui-monospace,monospace;
    font-size:17px; font-weight:700; letter-spacing:.08em; color:var(--up-deep);
    background:var(--up-sand); border:1px dashed var(--up-accent); border-radius:8px; padding:7px 12px;
  }
  .cert-revogado{ display:inline-block; margin-top:6px; font-size:11px; font-weight:700;
    text-transform:uppercase; letter-spacing:.06em; color:#8a1f14; background:#f6e2df;
    border-radius:999px; padding:3px 10px; }
  .cert-acoes{ display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:auto;
    padding-top:14px; border-top:1px solid var(--up-line); }
  .cert-validar{ font-size:13.5px; font-weight:600; text-decoration:none; }
  .cert-validar:hover{ text-decoration:underline; }
  .cert-print{ margin-left:auto; padding:8px 15px; border-radius:9px; border:0; cursor:pointer;
    background:var(--up-accent); color:#fff; font-weight:600; font-size:13.5px; }
  .cert-print:hover{ background:var(--up-deep); }
  .cert-intro{ margin:0 0 22px; color:var(--up-mut); max-width:66ch; }
  @media (max-width:640px){ .cert-grid{ grid-template-columns:1fr; } }

  /* Impressão: só o cartão, em folha limpa. */
  @media print{
    .up-top, .up-hero, .up-rodape, .cert-acoes, .cert-intro{ display:none !important; }
    body.uni-portal{ background:#fff; }
    .up-wrap{ padding:0; }
    .cert-grid{ display:block; }
    .cert-card{ box-shadow:none; border:2px solid var(--up-deep); page-break-inside:avoid; margin:0 auto; max-width:640px; }
  }
</style>
</head>
<body class="uni-portal">
<?= uni_portal_header($ctx, 'certificados') ?>

<section class="up-hero">
  <div class="up-hero-in">
    <h1>Seus certificados</h1>
    <p>Cada certificado nasce quando você conclui 100% de uma trilha. Ele tem um código único que qualquer pessoa pode validar publicamente — sem login.</p>
  </div>
</section>

<main class="up-wrap">
  <?php if (!$certs): ?>
    <div class="up-vazio">
      <p style="font-size:16px;color:var(--up-ink);">Você ainda não tem certificados.</p>
      <p>O certificado é emitido automaticamente assim que você conclui <strong>todas as cápsulas</strong> de uma trilha (100%).</p>
      <a href="<?= $base ?>/universidade/minha-trilha.php">Continuar minha trilha →</a>
    </div>
  <?php else: ?>
    <p class="cert-intro"><?= count($certs) ?> certificado<?= count($certs) === 1 ? '' : 's' ?> em nome de <strong><?= uni_h($ctx['nome']) ?></strong>. Compartilhe o link de validação ou imprima o diploma.</p>
    <div class="cert-grid">
      <?php foreach ($certs as $c):
        $codigo   = (string)$c['codigo_publico'];
        $revogado = (int)($c['revogado'] ?? 0) === 1;
        $urlPub   = $base . '/universidade/certificado.php?codigo=' . rawurlencode($codigo);
      ?>
        <article class="cert-card">
          <div class="cert-body">
            <span class="cert-seal" aria-hidden="true">🎓</span>
            <span class="cert-eyebrow">Certificado de conclusão</span>
            <h2 class="cert-titulo"><?= uni_h($c['trilha_titulo']) ?></h2>
            <div class="cert-linha">Conferido a <strong><?= uni_h($c['nome_titular'] ?: $ctx['nome']) ?></strong></div>
            <div class="cert-linha">Emitido em <strong><?= uni_h(certs_data($c['emitido_em'] ?? null)) ?></strong><?php if (!empty($c['valido_ate'])): ?> · válido até <strong><?= uni_h(certs_data($c['valido_ate'])) ?></strong><?php endif; ?></div>
            <div class="cert-codigo-wrap">
              <span class="cert-codigo-lab">Código de verificação</span>
              <span class="cert-codigo"><?= uni_h($codigo) ?></span>
              <?php if ($revogado): ?><span class="cert-revogado">Revogado</span><?php endif; ?>
            </div>
            <div class="cert-acoes">
              <a class="cert-validar" href="<?= uni_h($urlPub) ?>" target="_blank" rel="noopener">Validar publicamente ↗</a>
              <button type="button" class="cert-print" onclick="window.print()">Imprimir</button>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<footer class="up-rodape">Universidade VERO · <?= uni_h($ctx['tenant_nome']) ?> · certificados verificáveis por código</footer>
</body>
</html>
