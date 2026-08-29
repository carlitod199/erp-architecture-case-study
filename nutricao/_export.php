<?php
/* ============================================================
   VERO — Nutrição / exportação CSV (sweep A2/A4)
   Baixa a lista JÁ FILTRADA em CSV (UTF-8 com BOM, ';' , decimal com
   vírgula — abre direto no Excel pt-BR). Mesma convenção de
   estoque/_export.php e compras/_export.php. Chamado ANTES de qualquer
   HTML pela tela dona do filtro. Fica no módulo (não toca includes/ — C-07).
   `vero_csv_stream` é guardada por function_exists (mesma função reusada
   pelas telas do sweep, sem colisão de redefinição).
   ============================================================ */
declare(strict_types=1);

if (!function_exists('vero_csv_stream')) {
    /* $colunas: [campo => rótulo] (a ordem define as colunas)
       $formato: [campo => 'dec2'|'dec4'|'dec0'|'data'|'texto'] (default texto) */
    function vero_csv_stream(string $modulo, string $slug, array $rows, array $colunas, array $formato = []): void
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="vero_' . $modulo . '_' . $slug . '_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values($colunas), ';');
        foreach ($rows as $r) {
            $linha = [];
            foreach (array_keys($colunas) as $campo) {
                $v = $r[$campo] ?? '';
                $fmt = $formato[$campo] ?? 'texto';
                if ($v === '' || $v === null) {
                    $v = '';
                } elseif (in_array($fmt, ['dec2', 'dec4', 'dec0'], true)) {
                    $dec = $fmt === 'dec0' ? 0 : ($fmt === 'dec4' ? 4 : 2);
                    $v = number_format((float)$v, $dec, ',', '');
                } elseif ($fmt === 'data') {
                    $v = date('d/m/Y', strtotime((string)$v));
                }
                $linha[] = $v;
            }
            fputcsv($out, $linha, ';');
        }
        fclose($out);
        exit;
    }
}
