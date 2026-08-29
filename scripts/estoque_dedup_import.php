<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/estoque_dedup_import.php (one-shot, dry-run por padrão)
   Incidente 21/08: o import de produtos não re-hidratava os zeros à
   esquerda que o Excel come ("000123"→"123") e criava produto NOVO em vez
   de atualizar o existente. Este script acha os duplicados assim criados
   (código numérico < 6 dígitos cujo par com zero à esquerda existe no
   MESMO tenant) e:
     - sem --aplicar: só LISTA (código curto, par de 6, referências);
     - com --aplicar: apaga o duplicado que NÃO tem nenhuma referência
       (saldo, lote, movimentação, itens de compra/aplicação). Duplicado
       com referência NUNCA é apagado — é listado para decisão humana.
   Uso: php scripts/estoque_dedup_import.php <tenant_id> [--aplicar]
   ============================================================ */
if (PHP_SAPI !== 'cli') exit("CLI apenas.\n");
$tenantId = (int)($argv[1] ?? 0);
$aplicar  = in_array('--aplicar', $argv, true);
if ($tenantId <= 0) exit("Uso: php scripts/estoque_dedup_import.php <tenant_id> [--aplicar]\n");

$c = require __DIR__ . '/../config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['dbname'], $c['charset']),
    $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/* referências que tornam um produto "vivo" (não pode apagar às cegas) */
$refs = [
    'estoque_saldos'        => 'produto_id',
    'estoque_lotes'         => 'produto_id',
    'estoque_movimentacoes' => 'produto_id',
    'compras_pedido_itens'  => 'produto_id',
    'aplicacao_produtos'    => 'produto_id',
];
$temTabela = function (string $tab) use ($pdo): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $q->execute([$tab]);
    return (bool)$q->fetchColumn();
};
$refs = array_filter($refs, fn($col, $tab) => $temTabela($tab), ARRAY_FILTER_USE_BOTH);

$curto = $pdo->prepare(
    "SELECT id, codigo, nome FROM estoque_produtos
      WHERE tenant_id = ? AND codigo REGEXP '^[0-9]{1,5}$'
      ORDER BY codigo");
$curto->execute([$tenantId]);
$curtos = $curto->fetchAll(PDO::FETCH_ASSOC);
if (!$curtos) exit("tenant {$tenantId}: nenhum código curto — nada a fazer.\n");

$par = $pdo->prepare("SELECT id, nome FROM estoque_produtos WHERE tenant_id = ? AND codigo = ?");
$apagaveis = 0; $comRef = 0; $semPar = 0;
foreach ($curtos as $p) {
    $cod6 = str_pad($p['codigo'], 6, '0', STR_PAD_LEFT);
    $par->execute([$tenantId, $cod6]);
    $original = $par->fetch(PDO::FETCH_ASSOC);
    if (!$original) { $semPar++; echo "  ? #{$p['id']} cod={$p['codigo']} '{$p['nome']}' — SEM par {$cod6} (não é dupe do import; ignorado)\n"; continue; }

    $usos = [];
    foreach ($refs as $tab => $col) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM {$tab} WHERE tenant_id = ? AND {$col} = ?");
        $q->execute([$tenantId, (int)$p['id']]);
        $n = (int)$q->fetchColumn();
        if ($n > 0) $usos[] = "{$tab}={$n}";
    }
    if ($usos) {
        $comRef++;
        echo "  ! #{$p['id']} cod={$p['codigo']} '{$p['nome']}' duplica #{$original['id']} ({$cod6}) mas TEM referências: " . implode(', ', $usos) . " — decisão humana\n";
        continue;
    }
    $apagaveis++;
    if ($aplicar) {
        $pdo->prepare("DELETE FROM estoque_produtos WHERE tenant_id = ? AND id = ?")->execute([$tenantId, (int)$p['id']]);
        echo "  ✔ APAGADO #{$p['id']} cod={$p['codigo']} '{$p['nome']}' (duplicava #{$original['id']} {$cod6}, sem referências)\n";
    } else {
        echo "  - apagável: #{$p['id']} cod={$p['codigo']} '{$p['nome']}' duplica #{$original['id']} ({$cod6}), sem referências\n";
    }
}
echo "\ntenant {$tenantId}: " . count($curtos) . " código(s) curto(s) — {$apagaveis} apagáve(is)"
    . ($aplicar ? ' (APAGADOS)' : ' (dry-run; use --aplicar)') . ", {$comRef} com referência, {$semPar} sem par.\n";
