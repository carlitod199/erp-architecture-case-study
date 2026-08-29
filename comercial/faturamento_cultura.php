<?php
/* ============================================================
   VERO — Comercial / Faturamento por Cultura  (rota fina)
   Rota: /comercial/faturamento_cultura.php
   Guard: comercial.faturamento_cultura (preservado)
   Auditoria UX 19/07: o relatório foi UNIFICADO com o de comprador
   em _faturamento_base.php — esta rota renderiza a base com a
   dimensão "cultura" pré-selecionada. Menu e permissões intactos.
   ============================================================ */
declare(strict_types=1);

$FAT_DIM = 'cultura';
require __DIR__ . '/_faturamento_base.php';
