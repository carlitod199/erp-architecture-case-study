<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/uni_capturas_aplicar.php  (pipeline T7)
   Aplica o resultado do Playwright (scripts/capturas/resultado.json)
   no banco SEPARADO da Universidade:

     - para cada capsula (por slug) faz UPSERT de um uni_ativo
       (tipo='imagem', url = caminho relativo, hash_origem = sha256);
     - se a captura MUDOU: uni_ativo.estado='desatualizado' E a capsula
       vai para status='revisao' (mesmo gatilho do T8).

   Idempotente (rodar 2x nao duplica ativo nem re-marca a toa).

   Uso:
     php scripts/uni_capturas_aplicar.php
     php scripts/uni_capturas_aplicar.php --file=scripts/capturas/resultado.json
     php scripts/uni_capturas_aplicar.php --dry     # so mostra, nao grava
   ============================================================ */

require_once __DIR__ . '/../includes/uni_db.php';

$root = dirname(__DIR__);
$dry  = in_array('--dry', $argv, true);

$fileArg = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--file=')) { $fileArg = substr($a, 7); }
}
$resultadoPath = $fileArg
    ? (preg_match('#^[A-Za-z]:[\\\\/]#', $fileArg) || str_starts_with($fileArg, '/') ? $fileArg : $root . '/' . ltrim($fileArg, '/'))
    : $root . '/scripts/capturas/resultado.json';

if (!is_file($resultadoPath)) {
    fwrite(STDERR, "resultado.json nao encontrado: {$resultadoPath}\n");
    fwrite(STDERR, "Rode antes o pipeline: cd scripts/capturas && node capturas.mjs\n");
    exit(1);
}

$json = json_decode((string)file_get_contents($resultadoPath), true);
if (!is_array($json)) {
    fwrite(STDERR, "resultado.json invalido (JSON malformado).\n");
    exit(1);
}

$pdo = uni_pdo();

$selCapsula = $pdo->prepare("SELECT id, status FROM uni_capsula WHERE slug = ? AND ativo = 1 LIMIT 1");
$selAtivo   = $pdo->prepare(
    "SELECT id, url, hash_origem, estado FROM uni_ativo
      WHERE capsula_id = ? AND tipo = 'imagem'
      ORDER BY id ASC LIMIT 1"
);
$insAtivo = $pdo->prepare(
    "INSERT INTO uni_ativo (capsula_id, tipo, url, hash_origem, estado, ordem)
     VALUES (?, 'imagem', ?, ?, ?, 0)"
);
$updAtivo = $pdo->prepare(
    "UPDATE uni_ativo SET url = ?, hash_origem = ?, estado = ? WHERE id = ?"
);
$updCapsulaRevisao = $pdo->prepare(
    "UPDATE uni_capsula SET status = 'revisao'
      WHERE id = ? AND status = 'publicado' AND ativo = 1"
);

echo "== T7 aplicar capturas — " . count($json) . " item(ns)" . ($dry ? " [dry-run]" : "") . " ==\n";

$c = [
    'ins' => 0, 'upd' => 0, 'iguais' => 0,
    'revisao' => 0, 'semCapsula' => 0, 'semArquivo' => 0, 'erro' => 0,
];

if (!$dry) $pdo->beginTransaction();
try {
    foreach ($json as $row) {
        $slug = (string)($row['slug'] ?? '');
        $sha  = $row['sha256'] ?? null;
        $url  = $row['arquivo'] ?? null;
        $mudou = !empty($row['mudou']);

        if ($slug === '') { $c['erro']++; continue; }

        // Itens que falharam na captura (sem sha/arquivo) sao ignorados.
        if (empty($sha) || empty($url)) {
            echo "  ? {$slug} — sem captura valida (pulei)\n";
            $c['semArquivo']++;
            continue;
        }

        $selCapsula->execute([$slug]);
        $cap = $selCapsula->fetch();
        if (!$cap) {
            echo "  ? {$slug} — capsula nao encontrada/ativa (pulei)\n";
            $c['semCapsula']++;
            continue;
        }
        $capsulaId = (int)$cap['id'];
        $estado    = $mudou ? 'desatualizado' : 'ok';

        $selAtivo->execute([$capsulaId]);
        $ativo = $selAtivo->fetch();

        if (!$ativo) {
            if (!$dry) $insAtivo->execute([$capsulaId, $url, $sha, $estado]);
            echo "  + {$slug} — ativo criado" . ($mudou ? " [desatualizado]" : "") . "\n";
            $c['ins']++;
        } elseif ($ativo['hash_origem'] === $sha && $ativo['url'] === $url && $ativo['estado'] === $estado) {
            // nada mudou -> idempotente
            $c['iguais']++;
        } else {
            if (!$dry) $updAtivo->execute([$url, $sha, $estado, (int)$ativo['id']]);
            echo "  ~ {$slug} — ativo atualizado" . ($mudou ? " [desatualizado]" : "") . "\n";
            $c['upd']++;
        }

        // Gatilho de revisao (como o T8): so promove publicado -> revisao.
        if ($mudou) {
            if (!$dry) {
                $updCapsulaRevisao->execute([$capsulaId]);
                if ($updCapsulaRevisao->rowCount() > 0) {
                    echo "    ! {$slug} — capsula marcada 'revisao'\n";
                    $c['revisao']++;
                }
            } else {
                if ($cap['status'] === 'publicado') {
                    echo "    ! {$slug} — (dry) marcaria 'revisao'\n";
                    $c['revisao']++;
                }
            }
        }
    }

    if (!$dry) $pdo->commit();
} catch (Throwable $e) {
    if (!$dry && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERRO (rollback): " . $e->getMessage() . "\n");
    exit(2);
}

echo "== fim: {$c['ins']} criado(s), {$c['upd']} atualizado(s), {$c['iguais']} inalterado(s), "
   . "{$c['revisao']} p/ revisao, {$c['semCapsula']} sem capsula, {$c['semArquivo']} sem captura, {$c['erro']} erro(s)"
   . ($dry ? " [dry-run]" : "") . " ==\n";
exit(0);
