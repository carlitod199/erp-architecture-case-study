<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/run_all.php  (A5-QA)
   Orquestrador da bateria:
     1) php -l em TODOS os arquivos da bateria (regra 6);
     2) roda 00→10→20→30→40→50→99 em subprocessos isolados;
     3) com --2x, roda a suíte inteira DUAS vezes seguidas
        (a 2ª execução prova a idempotência de massa e limpeza);
     4) consolida _out/*.json em RELATORIO_EXECUCAO.md.
   Exit code != 0 se houver qualquer FAIL.
   Uso: php run_all.php [--2x] [--sem-limpeza] [--so=20]
   ============================================================ */

require __DIR__ . '/_lib.php';
qa_guard_host();
$env = qa_env();

$duasVezes  = in_array('--2x', $argv ?? [], true);
$semLimpeza = in_array('--sem-limpeza', $argv ?? [], true);
$so = null;
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--so=')) $so = substr($a, 5);

$phpBin = PHP_SAPI === 'cli' && PHP_BINARY ? PHP_BINARY : $env['php_bin'];
if (!is_file($phpBin)) $phpBin = 'php';

$scripts = ['00_massa_canonica', '10_smoke_rotas', '20_fluxos', '30_relatorios', '40_permissoes', '50_botoes'];
if (!$semLimpeza) $scripts[] = '99_limpeza';
if ($so !== null) $scripts = array_values(array_filter($scripts, fn($s) => str_starts_with($s, $so)));

/* ── 1) lint ── */
echo "== php -l (bateria) ==\n";
$lintFail = 0;
foreach (glob(__DIR__ . '/*.php') as $f) {
    $out = [];
    $rc = 0;
    exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    echo basename($f) . ': ' . ($rc === 0 ? 'OK' : 'ERRO — ' . implode(' ', $out)) . "\n";
    if ($rc !== 0) $lintFail++;
}
if ($lintFail > 0) {
    fwrite(STDERR, "ABORTADO: {$lintFail} arquivo(s) com erro de sintaxe.\n");
    exit(2);
}

/* ── 2/3) execução ── */
$rodadas = $duasVezes ? 2 : 1;
$consolidado = [];
$exitFinal = 0;
for ($rod = 1; $rod <= $rodadas; $rod++) {
    echo "\n######## RODADA {$rod}/{$rodadas} ########\n";
    foreach ($scripts as $s) {
        echo "\n---- {$s}.php ----\n";
        $rc = 0;
        passthru(escapeshellarg($phpBin) . ' ' . escapeshellarg(__DIR__ . "/{$s}.php"), $rc);
        $json = @json_decode((string)@file_get_contents(QA_OUT . "/{$s}.json"), true);
        $consolidado["r{$rod}"][$s] = [
            'exit' => $rc,
            'pass' => $json['pass'] ?? null, 'fail' => $json['fail'] ?? null, 'skip' => $json['skip'] ?? null,
            'falhas' => array_values(array_filter($json['itens'] ?? [], fn($i) => $i['ok'] === false)),
        ];
        if ($rc !== 0) $exitFinal = 1;
    }
}

/* ── 4) RELATORIO_EXECUCAO.md ── */
$md = "# VERO — Bateria de Testes: RELATÓRIO DE EXECUÇÃO\n\n";
$md .= "- Executado em: " . date('d/m/Y H:i:s') . "\n";
$md .= "- Host DB: " . qa_db_config()['host'] . " (homologação)\n";
$md .= "- Base HTTP: " . $env['base_url'] . "\n";
$md .= "- Rodadas: {$rodadas}" . ($duasVezes ? ' (2ª rodada = prova de idempotência da massa e da limpeza)' : '') . "\n\n";
$totP = $totF = $totS = 0;
foreach ($consolidado as $rot => $scriptsRes) {
    $md .= "## Rodada " . substr($rot, 1) . "\n\n";
    $md .= "| Script | PASS | FAIL | SKIP | exit |\n| --- | --- | --- | --- | --- |\n";
    foreach ($scriptsRes as $s => $r) {
        $md .= "| {$s} | " . ($r['pass'] ?? '—') . " | " . ($r['fail'] ?? '—') . " | "
            . ($r['skip'] ?? '—') . " | {$r['exit']} |\n";
        $totP += (int)($r['pass'] ?? 0);
        $totF += (int)($r['fail'] ?? 0);
        $totS += (int)($r['skip'] ?? 0);
    }
    $md .= "\n";
    foreach ($scriptsRes as $s => $r) {
        if (!$r['falhas']) continue;
        $md .= "### Falhas — {$s}\n\n";
        foreach ($r['falhas'] as $f) {
            $md .= "- **[FAIL]** {$f['secao']} :: {$f['desc']}\n"
                . "  - contexto: `" . json_encode($f['ctx'], JSON_UNESCAPED_UNICODE) . "`\n";
        }
        $md .= "\n";
    }
}
$md .= "## Total geral\n\n**PASS {$totP} · FAIL {$totF} · SKIP {$totS}** — "
    . ($totF === 0 ? "✅ bateria verde" : "❌ há falhas: triagem do A0 (bugs reais NÃO foram 'consertados no teste')") . "\n\n";
$md .= "> Gabarito dos valores: `GABARITO.md`. Evidência bruta por item: `_out/*.json`.\n";
$md .= "> SKIPs declarados: SEFAZ/e-mail/IA não são testados (mock/pulo explícito, regra da especificação);\n";
$md .= "> executores de estado fora do fluxo canônico recebem só o teste de CSRF (ver 50_botoes).\n";
file_put_contents(__DIR__ . '/RELATORIO_EXECUCAO.md', $md);
file_put_contents(QA_OUT . '/bateria_resumo.json', json_encode($consolidado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n== RELATORIO_EXECUCAO.md gravado — PASS {$totP} FAIL {$totF} SKIP {$totS} ==\n";
exit($exitFinal);
