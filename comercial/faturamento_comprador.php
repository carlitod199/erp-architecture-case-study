<?php
/* ============================================================
   VERO — Comercial / Faturamento por Comprador  (rota fina)
   Rota: /comercial/faturamento_comprador.php
   Guard: comercial.faturamento_comprador (preservado)
   Auditoria UX 19/07: o relatório foi UNIFICADO com o de cultura
   em _faturamento_base.php — esta rota renderiza a base com a
   dimensão "comprador" pré-selecionada. Menu e permissões intactos.
   ============================================================ */
declare(strict_types=1);

$FAT_DIM = 'comprador';
require __DIR__ . '/_faturamento_base.php';
