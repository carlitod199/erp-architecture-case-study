<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/uni_detectar_obsoletos.php  (T8)
   A cada release: calcula o sha256 do arquivo .php de cada tela
   ancorada a uma cápsula. Se o hash mudou desde a última verificação,
   todas as cápsulas daquela rota entram em status='revisao'
   (o gatilho mais barato e de cobertura total).

   1ª execução = baseline (grava hashes, não marca nada).
   Uso:
     php scripts/uni_detectar_obsoletos.php          # todas as rotas
     php scripts/uni_detectar_obsoletos.php --dry     # só mostra, não grava
   ============================================================ */

require_once __DIR__ . '/../includes/uni_db.php';

$dry = in_array('--dry', $argv, true);
$root = dirname(__DIR__);
$pdo = uni_pdo();

/* Rotas distintas que têm cápsula publicada. */
$rotas = $pdo->query(
    "SELECT DISTINCT r.rota
       FROM uni_capsula_rota r
       JOIN uni_capsula c ON c.id = r.capsula_id
      WHERE c.ativo = 1 AND r.rota LIKE '/%'"
)->fetchAll(PDO::FETCH_COLUMN);

if (!$rotas) { echo "Nenhuma rota ancorada.\n"; exit(0); }

echo "== Detecção de obsoletos (T8) — " . count($rotas) . " rota(s)" . ($dry ? " [dry-run]" : "") . " ==\n";

$selHash = $pdo->prepare("SELECT hash_arquivo FROM uni_tela_hash WHERE rota = ? LIMIT 1");
$upHash  = $pdo->prepare(
    "INSERT INTO uni_tela_hash (rota, hash_arquivo) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE hash_arquivo = VALUES(hash_arquivo), verificado_em = CURRENT_TIMESTAMP"
);
$marcar = $pdo->prepare(
    "UPDATE uni_capsula c
       JOIN uni_capsula_rota r ON r.capsula_id = c.id
        SET c.status = 'revisao'
      WHERE r.rota = ? AND c.status = 'publicado' AND c.ativo = 1"
);

$novas = 0; $mudou = 0; $iguais = 0; $semArquivo = 0;
foreach ($rotas as $rota) {
    /* rota é do nosso próprio banco; ainda assim, resolve com segurança dentro do projeto. */
    $rel = '/' . ltrim(str_replace('..', '', $rota), '/');
    $arq = $root . $rel;
    if (!is_file($arq)) {
        echo "  ? {$rota} — arquivo não encontrado (pulei)\n";
        $semArquivo++;
        continue;
    }
    $hash = hash_file('sha256', $arq);
    $selHash->execute([$rota]);
    $prev = $selHash->fetchColumn();

    if ($prev === false) {
        if (!$dry) $upHash->execute([$rota, $hash]);
        echo "  + {$rota} — baseline gravada\n";
        $novas++;
    } elseif ((string)$prev !== $hash) {
        if (!$dry) {
            $marcar->execute([$rota]);
            $n = $marcar->rowCount();
            $upHash->execute([$rota, $hash]);
        } else { $n = '?'; }
        echo "  ! {$rota} — MUDOU → {$n} cápsula(s) marcada(s) 'revisao'\n";
        $mudou++;
    } else {
        $iguais++;
    }
}

echo "== fim: {$novas} baseline, {$mudou} mudou, {$iguais} inalterada(s), {$semArquivo} sem arquivo ==\n";
