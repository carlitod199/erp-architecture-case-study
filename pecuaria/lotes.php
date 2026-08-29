<?php
/* VERO Agro - Pecuaria / Lotes.
   Pecuaria permanece fora do escopo ativo; rota preservada apenas para
   bloquear acesso direto de forma amigavel e consistente. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

header('Location: ' . BIOS_BASE . '/403?motivo=fora_escopo');
exit;
