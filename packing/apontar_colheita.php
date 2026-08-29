<?php
declare(strict_types=1);
/* ============================================================
   VERO — Packing House / (rota legada)
   O posto "Colheita por Caixa" foi MESCLADO no posto unificado
   "Colheita e Embalamento por Caixa" (/packing/apontar), que roteia a
   leitura pela Função da pessoa (colhedor/embalador). Esta rota
   permanece viva só para bookmarks/links antigos e redireciona.
   (Obs.: o posto unificado usa Data+Válvula configuradas no posto; a
   herança de data/válvula pelo ROMANEIO pode ser reincorporada se o
   fluxo de colheita exigir gravar na data exata da colheita.)
   ============================================================ */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
vero_redirect(BIOS_BASE . '/packing/apontar');
