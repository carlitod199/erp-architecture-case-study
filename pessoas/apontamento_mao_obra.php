<?php
/* VERO — C-22: esta tela DUPLICAVA os Apontamentos de Campo
   ("mesma coisa" — decisão da reunião: uma tela só, com filtros). O recorte
   por pessoa virou o filtro "pessoa" da tela unificada. Redirect 302
   (não 301 — Chrome cacheia 301 para sempre; lição do encerramento F). */
require_once __DIR__ . '/../includes/auth.php';
header('Location: ' . BIOS_BASE . '/agro/apontamentos', true, 302);
exit;
