<?php
/* VERO — redirect: o cadastro real de colaboradores/operadores é
   pessoas/colaboradores.php (o menu já aponta para lá; este arquivo
   só cobre acessos diretos por URL antiga). */
declare(strict_types=1);
header('Location: colaboradores.php', true, 302);
exit;
