<?php
/* ============================================================
   VERO — Plataforma de Gestão Agrícola
   includes/mail.php
   ─────────────────────────────────────────────────────────
   Módulo  : Core
   Título  : Mail
   Descrição: Envio de e-mails transacionais via PHPMailer ou SMTP nativo
   ============================================================ */

$pageTitle     = 'Mail';
$currentModule = 'core';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

/* ── TODO: Adicionar lógica de dados aqui ──────────────────
   Exemplo:
   require_once __DIR__ . '/../includes/db.php';
   $stmt = $pdo->prepare("SELECT ... WHERE tenant_id = ?");
   $stmt->execute([$_SESSION['tenant_id']]);
   $rows = $stmt->fetchAll();
   ─────────────────────────────────────────────────────────── */

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<main class="bios-main">

  <div class="bios-topbar">
    <div class="bios-breadcrumb">
      <span style="color:var(--text-tertiary);">Core</span>
      <i class="ti ti-chevron-right" aria-hidden="true"></i>
      <span class="current">Mail</span>
    </div>
  </div>

  <div class="bios-content">
    <div class="bios-card" style="text-align:center;padding:60px 20px;">
      <i class="ti ti-tools" style="font-size:36px;color:var(--text-tertiary);display:block;margin-bottom:12px;" aria-hidden="true"></i>
      <p style="font-size:14px;font-weight:500;color:var(--text-primary);margin-bottom:6px;">Mail</p>
      <p style="font-size:12px;color:var(--text-secondary);">Envio de e-mails transacionais via PHPMailer ou SMTP nativo</p>
      <p style="font-size:11px;color:var(--text-tertiary);margin-top:8px;">Em desenvolvimento · VERO v1.0</p>
    </div>
  </div>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
