<?php
/* ============================================================
   VERO — Estoque / exportação CSV (A2-F2-23)
   Baixa a lista JÁ FILTRADA (as mesmas cláusulas do filtro ativo) em CSV.
   Mesma convenção do motor de relatórios (relatorios/_rel_base.php):
   UTF-8 com BOM, separador ';', decimal com vírgula — abre direto no
   Excel pt-BR. Chamado ANTES de qualquer HTML pela tela dona do filtro
   (produtos, movimentações, lotes), que passa as linhas já consultadas.
   Fica em estoque/ (domínio A2) — não toca includes/ (C-07).
   ============================================================ */
declare(strict_types=1);

/* $colunas: [campo => rótulo na planilha] (a ordem define as colunas)
   $formato: [campo => 'dec2'|'dec4'|'dec0'|'data'|'texto'] (default texto) */
function estoque_csv_stream(string $slug, array $rows, array $colunas, array $formato = []): void
{
    /* nenhum byte antes do cabeçalho de download */
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="vero_estoque_' . $slug . '_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); /* BOM p/ o Excel reconhecer UTF-8 */
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
                $v = number_format((float)$v, $dec, ',', ''); /* sem separador de milhar — Excel soma */
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
