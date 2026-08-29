<?php
/* Layout compartilhado VERO Agro — gerado a partir do protótipo */
$PAGE_VIEW  = $PAGE_VIEW  ?? 'dashboard';
$PAGE_TITLE = $PAGE_TITLE ?? 'VERO';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/menu_agro.php';

/* Guard de acesso por URL (permissão + plano). As páginas definem
   $GUARD = ['macro'=>'...', 'micro'=>'...'] antes de incluir este header. */
if (isset($GUARD) && is_array($GUARD) && !empty($GUARD['macro']) && !empty($GUARD['micro'])) {
    bios_guard($GUARD['macro'], $GUARD['micro']);
}

$base = BIOS_BASE;
$__u  = function_exists('currentUser') ? currentUser() : ['name'=>'Usuário','role'=>'','initials'=>'U','tenant'=>'Fazenda'];
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" sizes="48x48" href="<?= $base ?>/assets/img/favicon-vero.png">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="apple-touch-icon" href="<?= $base ?>/assets/img/favicon-vero-180.png">
<?php /* cache-busting por filemtime: edição/deploy de asset reflete na hora (sem stale no navegador) */
$__ver = static fn(string $rel): string => '?v=' . (@filemtime(dirname(__DIR__) . $rel) ?: '1'); ?>
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css"><?php /* QA-013: fontes self-host (sem CDN em runtime) */ ?>
<link rel="stylesheet" href="<?= $base ?>/assets/css/agro.css<?= $__ver('/assets/css/agro.css') ?>">
<link rel="stylesheet" href="<?= $base ?>/assets/css/agro-nav.css<?= $__ver('/assets/css/agro-nav.css') ?>">
<link rel="stylesheet" href="<?= $base ?>/assets/css/vero-ui.css<?= $__ver('/assets/css/vero-ui.css') ?>"><?php /* A4 Lote 0 (UI-001/002/003) — wiring A0 */ ?>
<link rel="stylesheet" href="<?= $base ?>/assets/css/print.css<?= $__ver('/assets/css/print.css') ?>"><?php /* C-35/A-05: layout de impressão global */ ?>
<script>
/* esc(): escapa dado do banco antes de innerHTML/template-literal (auditoria seg.
   23/07, A-2/XSS raiz-b). Inline no <head> — disponível antes de qualquer script
   de página; as telas agro não carregam includes/header.php. */
window.esc = window.esc || function (s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
};
</script>
<script src="<?= $base ?>/assets/js/vero-ui.js<?= $__ver('/assets/js/vero-ui.js') ?>" defer></script>
<?php /* Universidade VERO — widget de ajuda (T4): 1 include global, 0 telas tocadas.
         Assíncrono e com falha silenciosa (Regra de Ouro nº 4). Some se a rota não tem conteúdo. */ ?>
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-ajuda.css<?= $__ver('/assets/css/uni-ajuda.css') ?>">
<script src="<?= $base ?>/assets/js/uni-ajuda.js<?= $__ver('/assets/js/uni-ajuda.js') ?>" defer></script>
<?= $EXTRA_HEAD ?? '' ?>
</head>
<body>
<?php /* C-35: cabeçalho que SÓ aparece na impressão (fazenda + tela + data) */ ?>
<div class="print-header">
  <div class="ph-titulo">VERO — <?= htmlspecialchars((string)($__u['tenant'] ?? 'Fazenda')) ?></div>
  <div class="ph-meta"><?= htmlspecialchars($PAGE_TITLE) ?> · impresso em <?= date('d/m/Y H:i') ?></div>
</div>
<div class="bios-shell" style="--accent:#005059; --accentDeep:#00363D; --sidebar:#08262A; --num:'IBM Plex Mono'; display:grid; grid-template-columns:320px 1fr; min-height:100vh; background:#EDEAE0; color:#2B2018; font-size:13px">
<?php require __DIR__ . '/sidebar.php'; ?>