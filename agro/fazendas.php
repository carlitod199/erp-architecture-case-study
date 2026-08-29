<?php
/* VERO — redirect: o cadastro real de fazendas é fazendas/index.php
   (o menu já aponta para lá; este arquivo só cobre URLs antigas). */
declare(strict_types=1);
header('Location: ../fazendas/index.php', true, 302);
exit;
