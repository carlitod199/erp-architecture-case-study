<?php
declare(strict_types=1);
/* ============================================================
   VERO — Universidade — Validação PÚBLICA de certificado
   Link compartilhável, SEM login. Recebe ?codigo=VERO-XXXX-XXXX
   e mostra se o certificado é válido, revogado ou inexistente.
   ============================================================ */
require_once __DIR__ . '/../includes/functions.php';       // BIOS_BASE + h()  (SEM auth)
require_once __DIR__ . '/../includes/db.php';              // PDO do sistema p/ o throttle
require_once __DIR__ . '/../includes/login_throttle.php';  // reuso do throttle do login (bios_login_throttle_*)
require_once __DIR__ . '/../includes/uni_certificacao.php'; // uni_cert_por_codigo + uni_h

$base = BIOS_BASE;

/* Sanitiza o código (formato VERO-XXXX-XXXX; a própria função revalida). */
$codigoIn = strtoupper(trim((string)($_GET['codigo'] ?? '')));

/* Throttle leve por IP (reuso do throttle do login) contra brute-force do espaço
   VERO-XXXX-XXXX. Excesso de tentativas → degrada para "não encontrado". */
$cert = null;
if ($codigoIn !== '') {
    $sysPdo = Database::getConnection();
    $ip     = bios_login_ip();
    if (!bios_login_throttle_bloqueado($sysPdo, $codigoIn, $ip)) {
        bios_login_throttle_log($sysPdo, $codigoIn, $ip, false); // alimenta o contador por IP
        $cert = uni_cert_por_codigo($codigoIn);
    }
}

/** Data amigável dd/mm/AAAA. */
function cert_data(?string $v): string
{
    if (!$v) return '';
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : (string)$v;
}

$existe   = $cert !== null;
$valido   = $existe && !empty($cert['valido']);
$revogado = $existe && (int)($cert['revogado'] ?? 0) === 1;
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Validação de certificado · Universidade VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-portal.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/uni-portal.css') ?: '1' ?>">
<style>
  /* ---- Validação pública ---- */
  .val-wrap{ max-width:640px; margin:0 auto; padding:44px 22px 70px; }
  .val-card{
    background:var(--up-card); border:1px solid var(--up-line); border-radius:18px;
    overflow:hidden; box-shadow:0 10px 34px rgba(0,54,61,.10);
  }
  .val-strip{ height:8px; }
  .val-strip.ok{ background:linear-gradient(90deg,#1c8a53,#0f6e40); }
  .val-strip.bad{ background:linear-gradient(90deg,#c0392b,#8a1f14); }
  .val-strip.neutral{ background:linear-gradient(90deg,var(--up-accent),var(--up-deep)); }
  .val-in{ padding:34px 40px 38px; }
  .val-badge{
    display:inline-flex; align-items:center; gap:8px; font-size:12.5px; font-weight:800;
    text-transform:uppercase; letter-spacing:.08em; padding:7px 16px; border-radius:999px; margin-bottom:20px;
  }
  .val-badge.ok{ background:#e3f3ea; color:#0f6e40; }
  .val-badge.bad{ background:#f7e2df; color:#8a1f14; }
  .val-eyebrow{ font-size:11px; text-transform:uppercase; letter-spacing:.14em; color:var(--up-mut); font-weight:700; margin:0 0 8px; }
  .val-titulo{ margin:0 0 22px; font-size:26px; line-height:1.2; font-weight:800; color:var(--up-deep); }
  .val-rows{ display:flex; flex-direction:column; gap:0; border-top:1px solid var(--up-line); }
  .val-row{ display:flex; justify-content:space-between; gap:18px; padding:13px 0; border-bottom:1px solid var(--up-line); font-size:14.5px; }
  .val-row .k{ color:var(--up-mut); }
  .val-row .v{ font-weight:700; color:var(--up-ink); text-align:right; }
  .val-codigo{ font-family:'IBM Plex Mono','SFMono-Regular',ui-monospace,monospace; letter-spacing:.08em; }
  .val-msg{ margin:16px 0 0; font-size:14px; color:var(--up-mut); }
  .val-nf{ text-align:center; }
  .val-nf .emoji{ font-size:46px; }
  .val-nf h1{ margin:14px 0 8px; font-size:24px; color:var(--up-deep); }
  .val-back{ display:inline-block; margin-top:26px; font-size:14px; font-weight:600; text-decoration:none; }
  .val-back:hover{ text-decoration:underline; }
  .val-foot{ text-align:center; font-size:12.5px; color:var(--up-mut); margin-top:26px; }

  @media print{
    .up-top, .val-back, .val-foot{ display:none !important; }
    body.uni-portal{ background:#fff; }
    .val-card{ box-shadow:none; border:2px solid var(--up-deep); }
  }
  @media (max-width:640px){ .val-in{ padding:26px 22px 30px; } .val-titulo{ font-size:22px; } }
</style>
</head>
<body class="uni-portal">
<header class="up-top">
  <div class="up-top-in">
    <a class="up-brand" href="<?= $base ?>/universidade/">
      <img src="<?= $base ?>/assets/img/brand/vero-lockup-white.svg" alt="VERO" class="up-logo-img"><span class="up-brand-sub">Universidade</span>
    </a>
    <a class="up-voltar" href="<?= $base ?>/universidade/">Conheça a Universidade VERO →</a>
  </div>
</header>

<main class="val-wrap">
  <?php if (!$existe): ?>
    <div class="val-card">
      <div class="val-strip neutral"></div>
      <div class="val-in val-nf">
        <div class="emoji" aria-hidden="true">🔎</div>
        <h1>Certificado não encontrado</h1>
        <p class="val-msg">
          <?php if ($codigoIn === ''): ?>
            Informe um código de verificação no formato <strong>VERO-XXXX-XXXX</strong> para validar um certificado.
          <?php else: ?>
            Nenhum certificado corresponde ao código <span class="val-codigo"><?= uni_h($codigoIn) ?></span>. Confira se digitou exatamente como consta no documento.
          <?php endif; ?>
        </p>
        <a class="val-back" href="<?= $base ?>/universidade/">Conheça a Universidade VERO →</a>
      </div>
    </div>
  <?php else: ?>
    <div class="val-card">
      <div class="val-strip <?= $valido ? 'ok' : 'bad' ?>"></div>
      <div class="val-in">
        <?php if ($valido): ?>
          <span class="val-badge ok">✓ Certificado válido</span>
        <?php else: ?>
          <span class="val-badge bad">✕ <?= $revogado ? 'Certificado revogado' : 'Certificado inválido' ?></span>
        <?php endif; ?>

        <p class="val-eyebrow">Certificado de conclusão · Universidade VERO</p>
        <h1 class="val-titulo"><?= uni_h($cert['trilha_titulo']) ?></h1>

        <div class="val-rows">
          <div class="val-row"><span class="k">Titular</span><span class="v"><?= uni_h($cert['nome_titular'] ?: '—') ?></span></div>
          <div class="val-row"><span class="k">Trilha</span><span class="v"><?= uni_h($cert['trilha_titulo']) ?></span></div>
          <div class="val-row"><span class="k">Emitido em</span><span class="v"><?= uni_h(cert_data($cert['emitido_em'] ?? null)) ?></span></div>
          <?php if (!empty($cert['valido_ate'])): ?>
          <div class="val-row"><span class="k">Válido até</span><span class="v"><?= uni_h(cert_data($cert['valido_ate'])) ?></span></div>
          <?php endif; ?>
          <div class="val-row"><span class="k">Código</span><span class="v val-codigo"><?= uni_h($cert['codigo_publico']) ?></span></div>
        </div>

        <?php if ($valido): ?>
          <p class="val-msg">Este certificado é autêntico e foi emitido pela Universidade VERO. A conclusão da trilha foi verificada no momento da emissão.</p>
        <?php elseif ($revogado): ?>
          <p class="val-msg">Este certificado foi <strong>revogado</strong> e não é mais reconhecido como válido pela Universidade VERO.</p>
        <?php else: ?>
          <p class="val-msg">Este certificado <strong>não está válido</strong> (expirado ou revogado). Procure a instituição emissora para mais informações.</p>
        <?php endif; ?>

        <a class="val-back" href="<?= $base ?>/universidade/">Conheça a Universidade VERO →</a>
      </div>
    </div>
  <?php endif; ?>

  <p class="val-foot">Validação pública · Universidade VERO</p>
</main>
</body>
</html>
