<?php
/* ============================================================
   VERO — Irrigação / faixa de abas dos consumos (C-42, reunião 18/07)
   "Consumo de Água, Consumo de Energia e Custo de Irrigação são a
   mesma informação em 3 lugares" → o menu passou a ter UM item
   ("Consumos e Custo de Irrigação") e as 3 telas se ligam por estas
   abas. Telas/slugs/guards preservados (ocultar ≠ excluir).
   Uso: vero_consumos_abas('agua'|'energia'|'custo')
   ============================================================ */
function vero_consumos_abas(string $ativa): string
{
    $abas = [
        'agua'    => ['Água',    '/irrigacao/consumo_agua'],
        'energia' => ['Energia', '/irrigacao/consumo_energia'],
        'custo'   => ['Custo',   '/irrigacao/custo_irrigacao'],
    ];
    $out = '<div style="display:flex;gap:8px;margin-bottom:14px">';
    foreach ($abas as $k => [$label, $rota]) {
        $cls = $k === $ativa ? 'vbtn vbtn-primary vbtn-sm' : 'vbtn vbtn-ghost vbtn-sm';
        $out .= '<a class="' . $cls . '" href="' . rtrim(BIOS_BASE, '/') . $rota . '">' . h($label) . '</a>';
    }
    return $out . '</div>';
}
