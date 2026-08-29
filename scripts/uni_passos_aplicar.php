<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/uni_passos_aplicar.php
   Aplica o resultado de scripts/capturas/passos.mjs no banco da
   Universidade: upsert de uni_passo (texto + imagem marcada por passo).
   Idempotente (chave capsula_id+ordem). Rodar após passos.mjs.
   ============================================================ */

require_once __DIR__ . '/../includes/uni_db.php';

$arq = __DIR__ . '/capturas/passos_resultado.json';
if (!is_file($arq)) { fwrite(STDERR, "resultado não encontrado: {$arq}\n"); exit(1); }
$res = json_decode((string)file_get_contents($arq), true);
if (!is_array($res)) { fwrite(STDERR, "JSON inválido\n"); exit(1); }

$pdo = uni_pdo();
$capId = $pdo->prepare("SELECT id FROM uni_capsula WHERE slug = ? LIMIT 1");
$up = $pdo->prepare(
    "INSERT INTO uni_passo (capsula_id, ordem, texto, rota, seletor, marca_tipo, marca_label, imagem_url, imagem_hash, estado)
     VALUES (:cap,:ord,:txt,:rota,:sel,:marca,:label,:img,:hash,:estado)
     ON DUPLICATE KEY UPDATE texto=VALUES(texto), rota=VALUES(rota), seletor=VALUES(seletor),
        marca_tipo=VALUES(marca_tipo), marca_label=VALUES(marca_label),
        imagem_url=VALUES(imagem_url), imagem_hash=VALUES(imagem_hash), estado=VALUES(estado)"
);

$ok = 0; $semCap = 0;
foreach ($res as $r) {
    $capId->execute([$r['slug']]);
    $cid = $capId->fetchColumn();
    if ($cid === false) { echo "  ? cápsula '{$r['slug']}' não encontrada — pulei passo {$r['ordem']}\n"; $semCap++; continue; }
    $up->execute([
        ':cap' => (int)$cid, ':ord' => (int)$r['ordem'], ':txt' => (string)$r['texto'],
        ':rota' => $r['rota'] ?? null, ':sel' => $r['seletor'] ?? null,
        ':marca' => in_array($r['marca'] ?? '', ['caixa','seta','destaque','numero'], true) ? $r['marca'] : 'caixa',
        ':label' => $r['label'] ?? null, ':img' => $r['imagem_url'] ?? null, ':hash' => $r['hash'] ?? null,
        ':estado' => !empty($r['ok']) ? 'ok' : 'pendente',
    ]);
    echo "  ✓ {$r['slug']} · passo {$r['ordem']} · " . (!empty($r['ok']) ? 'com marcação' : 'sem alvo') . "\n";
    $ok++;
}
echo "== {$ok} passo(s) aplicado(s), {$semCap} sem cápsula ==\n";
