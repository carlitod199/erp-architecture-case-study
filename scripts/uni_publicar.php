<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/uni_publicar.php
   CLI da rotina de publicação da Universidade (T2). Varre
   conteudo/capsulas/**.md e publica cada cápsula no banco separado.

   Uso:
     php scripts/uni_publicar.php                 # publica tudo
     php scripts/uni_publicar.php agro            # só um módulo/subpasta
     php scripts/uni_publicar.php caminho/x.md    # um arquivo
   ============================================================ */

require_once __DIR__ . '/../includes/uni_db.php';
require_once __DIR__ . '/../includes/uni_conteudo.php';

$raiz = dirname(__DIR__) . '/conteudo/capsulas';
$alvo = $argv[1] ?? '';

/* Monta a lista de arquivos .md a publicar. */
$arquivos = [];
if ($alvo !== '' && is_file($alvo)) {
    $arquivos[] = $alvo;
} else {
    $base = $alvo !== '' ? $raiz . '/' . trim($alvo, '/') : $raiz;
    if (!is_dir($base)) {
        fwrite(STDERR, "Pasta não encontrada: {$base}\n");
        exit(1);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'md') {
            $arquivos[] = $f->getPathname();
        }
    }
    sort($arquivos);
}

if (!$arquivos) {
    echo "Nenhuma cápsula (.md) encontrada.\n";
    exit(0);
}

$pdo = uni_pdo();
echo "== Publicação Universidade — " . count($arquivos) . " arquivo(s) ==\n";

$ok = 0; $erros = 0;
foreach ($arquivos as $arq) {
    $rel = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $arq);
    try {
        $r = uni_publicar_arquivo($pdo, $arq);
        $pub = $r['publicacao'] ? ' [publicação registrada]' : '';
        echo "  ✓ {$r['slug']} — {$r['acao']} ({$r['rotas']} rota/s){$pub}\n";
        $ok++;
    } catch (Throwable $e) {
        echo "  ✗ {$rel} — ERRO: {$e->getMessage()}\n";
        $erros++;
    }
}

echo "== fim: {$ok} publicada(s), {$erros} erro(s) ==\n";
exit($erros > 0 ? 2 : 0);
