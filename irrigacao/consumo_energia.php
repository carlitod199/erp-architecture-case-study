<?php
/* VERO — Irrigação / Consumo de Energia (tela real, base compartilhada)
   Guard: irrigacao.consumo_energia */
$CON_TIPO   = 'energia';
$CON_MICRO  = 'consumo_energia';
$CON_VIEW   = 'irrigacao_consumo_energia';
$CON_TITULO = 'Consumo de Energia';
$CON_SUB    = 'Energia consumida nos apontamentos de irrigação — por talhão e lançamento, com custo por kWh';
$CON_UN     = 'kWh';
require __DIR__ . '/_consumo_base.php';
