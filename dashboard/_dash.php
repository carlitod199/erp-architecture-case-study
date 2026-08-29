<?php
/* ============================================================
   VERO — dashboard/_dash.php  (helper de estados de dados, A4, Lote R1)
   REGRA PERMANENTE do design system (arbitragem A0 R1):
   um gráfico exige ≥2 pontos de comparação.
     • ≥2 itens  → gráfico
     • 1 item    → KPI numérico (nunca "barra de um valor único")
     • 0 item    → estado vazio COM CTA acionável (nunca eixo nu)
   Série temporal com 1 ponto → coluna única (tratado no vero-dash.js).
   Bullet só com meta>0 (sem meta → "realizado + cadastrar previsão").
   ============================================================ */
declare(strict_types=1);

/** Modo de um gráfico de ranking/comparação pelo nº de itens comparáveis. */
if (!function_exists('dash_mode')) {
    function dash_mode(int $n): string { return $n >= 2 ? 'chart' : ($n === 1 ? 'kpi' : 'empty'); }
}

/** Bloco de estado vazio com CTA acionável (para usar DENTRO de um .vcard). */
if (!function_exists('dash_empty')) {
    function dash_empty(string $msg, string $cta = '', string $href = ''): string
    {
        $link = ($cta !== '' && $href !== '')
            ? '<div style="margin-top:10px"><a class="vbtn vbtn-ghost vbtn-sm" href="' . h($href) . '">' . h($cta) . '</a></div>'
            : '';
        return '<div class="vempty" style="padding:26px 22px">' . h($msg) . $link . '</div>';
    }
}

/** R2 — chip de ESCOPO do dado: deixa explícito o que cada número cobre.
 *  safra = filtrado pela safra selecionada · mes = snapshot do mês corrente ·
 *  geral = tenant inteiro (não filtra safra/mês). */
if (!function_exists('dash_scope')) {
    function dash_scope(string $tipo): string
    {
        $map = [
            'safra' => ['SAFRA', '#005059', '#E4F0EF'],
            'mes'   => ['MÊS',   '#8A6D1A', '#F6ECD9'],
            'geral' => ['GERAL', '#6B6257', '#ECE7DD'],
        ];
        [$txt, $fg, $bg] = $map[$tipo] ?? $map['geral'];
        return '<span class="vscope" data-scope="' . h($tipo) . '" title="Escopo do dado: ' . h($txt) . '" '
            . 'style="font:600 9.5px/1.5 \'IBM Plex Sans\',sans-serif;letter-spacing:.06em;color:' . $fg
            . ';background:' . $bg . ';padding:2px 7px;border-radius:10px;white-space:nowrap">' . h($txt) . '</span>';
    }
}

/** R2/D5 — badge de custo PARCIAL: sinaliza número que ainda NÃO fecha porque
 *  há custo indireto não rateado (rateio/fechamento pendente). O $motivo (com o
 *  valor) vai no title; o $label é o texto visível. NÃO usar como enfeite —
 *  só quando o dado prova que o custo está incompleto. */
if (!function_exists('dash_parcial')) {
    function dash_parcial(string $motivo, string $label = '◐ parcial', string $tipo = 'custo-parcial'): string
    {
        return '<span class="vparcial" data-badge="' . h($tipo) . '" title="' . h($motivo) . '" '
            . 'style="font:600 9.5px/1.5 \'IBM Plex Sans\',sans-serif;letter-spacing:.04em;color:#8A6D1A;'
            . 'background:#F6ECD9;padding:2px 7px;border-radius:10px;white-space:nowrap">' . h($label) . '</span>';
    }
}
