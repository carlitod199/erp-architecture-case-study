<?php
/* ============================================================
   VERO — tests/guard_coverage.php
   Rede de seguranca do gate de LEITURA (achado de auditoria A1).
   O VERO protege a VISUALIZACAO de cada tela por um destes meios:
     (1) $GUARD = ['macro'=>..,'micro'=>..]  + agro_header.php
         -> bios_guard() -> requirePermission() (176+ telas)
     (2) vero_require('<modulo>.<slug>.ver') ANTES do render
         (impressoes e bases compartilhadas: _mov_base, _recorte_base...)
     (3) redirecionador puro: header('Location: ..') + exit, sem render
         (o alvo do redirect e' quem aplica o gate)
   O gate e' OPT-IN por pagina. Este teste percorre TODA pagina
   roteavel e FALHA se alguma renderizar dados sem nenhum desses
   meios — pegando uma tela nova que esqueceu o guard (a fraqueza
   real que a auditoria A1 apontou), SEM tocar nas 274 existentes.

   Uso:  php tests/guard_coverage.php
   Sai com codigo !=0 se houver pagina desprotegida (bom p/ CI).
   ============================================================ */
declare(strict_types=1);

$ROOT = dirname(__DIR__);

/* Telas roteaveis SEM dados sensiveis — nao exigem gate. */
$WHITELIST = [
    'keepalive.php',                 // ping de sessao, sem dados
    '403.php', '404.php', '500.php', // paginas de erro
    'pecuaria/index.php',            // redirect 403 (modulo fora de escopo)
];

/* Convencao VERO: arquivo cujo basename comeca com '_' e' PARTIAL/HELPER
   (nunca linkado no menu; incluido por uma tela que ja passou pelo gate).
   Nao exige gate proprio, MAS e' escaneado como possivel provedor de gate
   para as telas que o incluem (transitividade de 1 nivel). */

$dirIter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS)
);
$IGNORAR = ['/includes/', '/vendor/', '/config/', '/migrations/', '/database/',
            '/scripts/', '/_backups_implementacao/', '/.git/', '/tests/', '/api/', '/docs/'];

$paginas = [];
foreach ($dirIter as $f) {
    if ($f->getExtension() !== 'php') continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($ROOT) + 1));
    $skip = false;
    foreach ($IGNORAR as $ig) { if (str_contains('/' . $rel, $ig)) { $skip = true; break; } }
    if ($skip) continue;
    $paginas[] = $rel;
}
sort($paginas);

/* Detecta gate DIRETO no fonte de um arquivo. Retorna o meio ou null. */
$detectaGate = static function (string $src): ?string {
    if (preg_match('/\$GUARD\s*=/', $src)) return 'GUARD';
    if (preg_match('/vero_require\(\s*[\'"][^\'"]+\.ver[\'"]/', $src)
        || preg_match('/requirePermission\(\s*[\'"][^\'"]+\.ver[\'"]/', $src)
        || preg_match('/\bbios_guard\s*\(/', $src)) return 'vero_require.ver';
    return null;
};

/* Resolve os require/include de arquivos LOCAIS (mesmo modulo, __DIR__)
   citados no fonte — para seguir o gate ate' a base compartilhada. */
$includesLocais = static function (string $src, string $rel) use ($ROOT): array {
    $dir = dirname($rel);
    $out = [];
    if (preg_match_all('/(?:require|include)(?:_once)?\s*(?:__DIR__\s*\.\s*)?[\'"]([^\'"]+\.php)[\'"]/', $src, $m)) {
        foreach ($m[1] as $inc) {
            $inc = ltrim($inc, '/.');
            $cand = $dir === '.' ? $inc : $dir . '/' . $inc;
            $cand = str_replace('\\', '/', $cand);
            if (is_file($ROOT . '/' . $cand)) $out[] = $cand;
        }
    }
    return $out;
};

$guardada = []; $desprotegida = []; $orfaos = [];
$porMeio = ['GUARD' => 0, 'vero_require.ver' => 0, 'redirect' => 0, 'base(transitivo)' => 0, 'whitelist' => 0, 'partial' => 0];

foreach ($paginas as $rel) {
    $base = basename($rel);
    if (in_array($rel, $WHITELIST, true)) { $porMeio['whitelist']++; $guardada[$rel] = 'whitelist'; continue; }
    // strips orfaos (artefatos _vr_strip_*): reporta como limpeza, nao como falha de gate
    if (str_starts_with($base, '_vr_strip_')) { $orfaos[] = $rel; continue; }
    // partial/helper (convencao '_'): nao exige gate proprio
    if (str_starts_with($base, '_')) { $porMeio['partial']++; $guardada[$rel] = 'partial'; continue; }

    $src = (string) file_get_contents($ROOT . '/' . $rel);

    // gate direto
    if ($meio = $detectaGate($src)) { $porMeio[$meio]++; $guardada[$rel] = $meio; continue; }

    // gate transitivo: alguma base/include local aplica o gate?
    $viaBase = false;
    foreach ($includesLocais($src, $rel) as $inc) {
        if ($detectaGate((string) file_get_contents($ROOT . '/' . $inc))) { $viaBase = true; break; }
    }
    if ($viaBase) { $porMeio['base(transitivo)']++; $guardada[$rel] = 'base'; continue; }

    // redirecionador puro: header(Location) e NAO renderiza
    $ehRedirect = preg_match('/header\(\s*[\'"]?Location/i', $src) === 1;
    $renderiza  = preg_match('/vero_page_header|<table|class=["\']vtable|agro_header\.php/', $src) === 1;
    if ($ehRedirect && !$renderiza) { $porMeio['redirect']++; $guardada[$rel] = 'redirect'; continue; }

    $desprotegida[] = $rel;
}

echo "== VERO · Cobertura do gate de leitura ==\n";
echo "Paginas roteaveis analisadas: " . count($paginas) . "\n";
foreach ($porMeio as $meio => $n) echo sprintf("  %-18s %d\n", $meio, $n);
echo "  " . str_pad('TOTAL guardadas', 18) . count($guardada) . "\n";
if ($orfaos) {
    echo "\nAviso (nao falha): " . count($orfaos) . " artefato(s) orfao(s) _vr_strip_* — candidatos a remocao:\n";
    foreach ($orfaos as $o) echo "  ~ $o\n";
}
echo "\n";

if ($desprotegida) {
    echo "FALHA: " . count($desprotegida) . " pagina(s) renderizam sem gate de leitura:\n";
    foreach ($desprotegida as $p) echo "  - $p\n";
    echo "\nAdicione \$GUARD=['macro'=>..,'micro'=>..] (com agro_header) ou\n";
    echo "vero_require('<modulo>.<slug>.ver') antes do render. Se for include/helper\n";
    echo "nao-roteavel, acrescente ao \$WHITELIST deste teste.\n";
    exit(1);
}
echo "OK: toda pagina roteavel declara um gate de leitura.\n";
exit(0);
