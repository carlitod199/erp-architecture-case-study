<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>VERO</title>
  <link rel="icon" type="image/svg+xml" href="<?= h(BIOS_BASE) ?>/assets/img/brand/vero-symbol.svg">
  <link rel="icon" type="image/png" sizes="48x48" href="<?= h(BIOS_BASE) ?>/assets/img/favicon-vero.png">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= h(BIOS_BASE) ?>/assets/img/favicon-vero-32.png">
  <link rel="apple-touch-icon" href="<?= h(BIOS_BASE) ?>/assets/img/favicon-vero-180.png">

  <!-- PT-06 (CSO): fontes SELF-HOST (sem CDN em runtime) — mesma base do agro (QA-013) -->
  <link rel="stylesheet" href="<?= h(BIOS_BASE) ?>/assets/vendor/fonts/vero-fonts.css">

  <!-- Tabler Icons -->
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">

  <!-- CSS global VERO -->
  <link rel="stylesheet" href="<?= h(BIOS_BASE) ?>/assets/css/bios.css">
  <!-- CSS do sidebar (tema escuro do menu lateral) -->
  <link rel="stylesheet" href="<?= h(BIOS_BASE) ?>/assets/css/style_sidebar.css">
  <!-- W-06: layout de impressão global (@media print) — oculta menu/sidebar/topbar/botões -->
  <link rel="stylesheet" href="<?= h(BIOS_BASE) ?>/assets/css/print.css">

  <style>
    :root {
      --bg-primary: #FFFFFF;
      --bg-secondary: #FBF8F2;
      --bg-tertiary: #EDEAE0;
      --bg-info: #E0EFEF;
      --bg-success: #DDEDEB;
      --bg-warning: #F3E7C8;
      --bg-danger: #FCEBEB;
      --text-primary: #241B14;
      --text-secondary: #8A7C68;
      --text-tertiary: #9A8C78;
      --text-info: #005059;
      --text-success: #0E7E72;
      --text-warning: #7A5410;
      --text-danger: #B23A2E;
      --border-default: #E3D9C8;
      --border-hover: #DDD2BF;
      --border-strong: #005059;
    }
  </style>

  <!-- CSRF token global para JS -->
  <script>
    window.BIOS_BASE = <?= jsvar(BIOS_BASE) ?>;
    window.BIOS_CSRF = <?= jsvar(csrf()) ?>;
    /* esc(): escapa dado do banco antes de ir para innerHTML/template-literal
       (auditoria seg. 23/07, A-2/XSS raiz-b). Prefira textContent quando o alvo
       for nó de texto puro; use esc() dentro de `${...}` de HTML montado em JS. */
    window.esc = function (s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    };
  </script>
</head>
<body class="bios-layout">
