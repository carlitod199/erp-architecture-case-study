<?php
declare(strict_types=1);

// ============================================================================
// migration_143_colheita_estoque.php | VERO — Pacote A0-18 (05/07/2026)
//   Colheita → Estoque F1 (A0-17 aprovada; P-82..86 "recomendações aceitas").
//   DB-48 ALTER agro_culturas: produto_estoque_colheita_id, almoxarifado_
//         colheita_id, exige_classificacao (produto por CULTURA — P-83)
//   DB-49 ALTER estoque_lotes: colheita_registro_id, safra_talhao_id,
//         variedade_id, status (base também do EST-016)
//   Seed: almoxarifado "Packing" (P-82) + produto "Uva in natura"
//         (tipo produto_agricola, kg) vinculado à cultura Uva.
// Idempotente. Backup: backup_pre_143_*.sql.
// ============================================================================

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$log = fn(string $m) => print($m . "\n");
$columnExists = function (string $t, string $c) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$t, $c]);
    return (bool)$st->fetchColumn();
};
$fkExists = function (string $nome) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE table_schema = DATABASE() AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'");
    $st->execute([$nome]);
    return (bool)$st->fetchColumn();
};

$log("== migration 143 — colheita → estoque F1 (A0-18) ==");

$log("[DB-48] agro_culturas — produto/local da colheita");
foreach ([
    'produto_estoque_colheita_id' => "BIGINT UNSIGNED NULL COMMENT 'produto acabado gerado pela colheita (P-83: por cultura)'",
    'almoxarifado_colheita_id' => "BIGINT UNSIGNED NULL COMMENT 'local padrao de entrada da colheita (P-82)'",
    'exige_classificacao' => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'P-84: so kg aprovado vira saldo'",
] as $col => $def) {
    if (!$columnExists('agro_culturas', $col)) {
        $pdo->exec("ALTER TABLE agro_culturas ADD COLUMN $col $def");
        $log("  + agro_culturas.$col");
    } else { $log("  = $col já existe"); }
}
foreach ([
    'fk_cult_prod_colheita' => "ALTER TABLE agro_culturas ADD CONSTRAINT fk_cult_prod_colheita FOREIGN KEY (produto_estoque_colheita_id) REFERENCES estoque_produtos (id)",
    'fk_cult_almox_colheita' => "ALTER TABLE agro_culturas ADD CONSTRAINT fk_cult_almox_colheita FOREIGN KEY (almoxarifado_colheita_id) REFERENCES almoxarifados (id)",
] as $nome => $sql) {
    if (!$fkExists($nome)) { $pdo->exec($sql); $log("  + FK $nome"); } else { $log("  = FK $nome já existe"); }
}

$log("[DB-49] estoque_lotes — rastreio agrícola + status");
foreach ([
    'colheita_registro_id' => "BIGINT UNSIGNED NULL COMMENT 'lote agricola: colheita de origem'",
    'safra_talhao_id' => "BIGINT UNSIGNED NULL",
    'variedade_id' => "BIGINT UNSIGNED NULL",
    'status' => "VARCHAR(15) NOT NULL DEFAULT 'disponivel' COMMENT 'disponivel|em_classificacao|bloqueado|consumido|estornado — PHP valida (EST-016 base)'",
] as $col => $def) {
    if (!$columnExists('estoque_lotes', $col)) {
        $pdo->exec("ALTER TABLE estoque_lotes ADD COLUMN $col $def");
        $log("  + estoque_lotes.$col");
    } else { $log("  = $col já existe"); }
}
if (!$fkExists('fk_lote_colheita')) {
    $pdo->exec("ALTER TABLE estoque_lotes ADD CONSTRAINT fk_lote_colheita FOREIGN KEY (colheita_registro_id) REFERENCES colheita_registros (id)");
    $log("  + FK fk_lote_colheita");
} else { $log("  = FK fk_lote_colheita já existe"); }

$log("[seed] almoxarifado Packing + produto agrícola da Uva");
$tenants = $pdo->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tenants as $tid) {
    $alm = $pdo->prepare("SELECT id FROM almoxarifados WHERE tenant_id = ? AND nome = 'Packing'");
    $alm->execute([$tid]);
    $almId = $alm->fetchColumn();
    if (!$almId) {
        $pdo->prepare("INSERT INTO almoxarifados (tenant_id, nome, tipo, ativo, created_by, updated_by) VALUES (?, 'Packing', 'packing', 1, 1, 1)")->execute([$tid]);
        $almId = (int)$pdo->lastInsertId();
        $log("  + tenant $tid: almoxarifado Packing (id $almId)");
    } else { $log("  = tenant $tid: Packing já existe"); }

    /* produto "Uva in natura" só se a cultura Uva existir e ainda não tiver produto */
    $cult = $pdo->prepare("SELECT id, nome, produto_estoque_colheita_id FROM agro_culturas WHERE tenant_id = ? AND nome = 'Uva' LIMIT 1");
    $cult->execute([$tid]);
    $cult = $cult->fetch(PDO::FETCH_ASSOC);
    if ($cult && !$cult['produto_estoque_colheita_id']) {
        $prod = $pdo->prepare("SELECT id FROM estoque_produtos WHERE tenant_id = ? AND nome = 'Uva in natura'");
        $prod->execute([$tid]);
        $prodId = $prod->fetchColumn();
        if (!$prodId) {
            $grupoId = $pdo->query("SELECT id FROM estoque_grupos WHERE tenant_id = $tid ORDER BY id LIMIT 1")->fetchColumn();
            $max = (int)$pdo->query("SELECT COALESCE(MAX(CAST(codigo AS UNSIGNED)),0) FROM estoque_produtos
                WHERE tenant_id = $tid AND codigo REGEXP '^[0-9]{6}\$'")->fetchColumn();
            $codigo = str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO estoque_produtos (tenant_id, grupo_id, codigo, nome, unidade, tipo_insumo, controla_lote, controla_validade, ativo, created_by, updated_by)
                           VALUES (?, ?, ?, 'Uva in natura', 'kg', 'produto_agricola', 1, 0, 1, 1, 1)")
                ->execute([$tid, $grupoId, $codigo]);
            $prodId = (int)$pdo->lastInsertId();
            $log("  + tenant $tid: produto Uva in natura ($codigo, id $prodId)");
        } else { $log("  = tenant $tid: produto Uva in natura já existe"); }
        $pdo->prepare("UPDATE agro_culturas SET produto_estoque_colheita_id = ?, almoxarifado_colheita_id = ? WHERE id = ?")
            ->execute([(int)$prodId, (int)$almId, (int)$cult['id']]);
        $log("  ~ cultura Uva vinculada (produto $prodId, local Packing)");
    } else { $log("  = tenant $tid: cultura Uva " . ($cult ? 'já vinculada' : 'não existe')); }
}

$log("== migration 143 concluída ==");
