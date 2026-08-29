<?php
/* VERO — redirect: o cadastro real de safras é safras/index.php
   (o menu já aponta para lá; este arquivo só cobre URLs antigas). */
declare(strict_types=1);
header('Location: ../safras/index.php', true, 302);
exit;
