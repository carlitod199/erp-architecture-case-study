<?php
declare(strict_types=1);
/* ============================================================
   VERO — packing_recepcao_demo_seed.php
   CENÁRIO DE TESTE da recepção do Packing House (Onda 1). Prepara, para o
   TENANT 1, o mínimo necessário para exercitar a tela de recepção e o gate
   de certificação:

     1) uma colheita_cargas com destino='packing' apontando um
        talhao / safra_talhao / variedade REAIS (faz SELECT dos que já
        existem; só cria o mínimo se o banco estiver vazio), com data_carga
        recente e romaneio único 'PKDEMO-...';
     2) ph_certificacoes válidas (escopo 'unidade' e 'produtor', ativas e
        dentro da validade) para o gate de certificação PASSAR.

   NÃO é migration: só INSERTs de dados de teste. Não altera nada existente.
   Idempotente (identifica pelo romaneio 'PKDEMO-...' e por escopo+norma+número
   das certificações — reexecutar não duplica). PDO direto via
   config/database.php (padrão das migrations).

   Uso:  php scripts/packing_recepcao_demo_seed.php
   ============================================================ */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Somente CLI.\n"); exit(1); }

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

const T          = 1;                 // tenant do cenário
const ROMANEIO   = 'PKDEMO-2026-0001'; // chave idempotente da carga demo

/* pequenos helpers ------------------------------------------------------- */
$val = static function (string $sql, array $p = []) use ($pdo) {
    $st = $pdo->prepare($sql); $st->execute($p);
    $v = $st->fetchColumn(); return $v === false ? null : $v;
};
$row = static function (string $sql, array $p = []) use ($pdo) {
    $st = $pdo->prepare($sql); $st->execute($p);
    $r = $st->fetch(); return $r ?: null;
};

echo "== packing_recepcao_demo_seed (tenant " . T . ") ==\n";

/* 0) tenant existe? -------------------------------------------------------- */
if (!$val("SELECT id FROM tenants WHERE id = ?", [T])) {
    fwrite(STDERR, "Tenant " . T . " inexistente — abortando.\n"); exit(1);
}

/* 1) UNIDADE DE PACKING (almoxarifado tipo='packing') --------------------- */
$unidade = $row(
    "SELECT id, nome FROM almoxarifados
      WHERE tenant_id = ? AND tipo = 'packing'
      ORDER BY ativo DESC, id ASC LIMIT 1", [T]);
if (!$unidade) {
    $pdo->prepare(
        "INSERT INTO almoxarifados (tenant_id, nome, tipo, ativo, created_at, updated_at)
         VALUES (?, 'Packing (demo)', 'packing', 1, NOW(), NOW())")->execute([T]);
    $uid = (int)$pdo->lastInsertId();
    $unidade = ['id' => $uid, 'nome' => 'Packing (demo)'];
    echo "  + almoxarifado packing CRIADO (id {$uid})\n";
} else {
    echo "  = unidade packing ENCONTRADA (id {$unidade['id']} · {$unidade['nome']})\n";
}
$unidadeId = (int)$unidade['id'];

/* 2) CHAIN REAL talhao / safra_talhao / variedade / colheita_registro ------
   Preferimos um colheita_registro que já tenha safra_talhao e variedade
   (direta ou herdada do talhão) — é o insumo natural da carga p/ packing. */
$chain = $row(
    "SELECT cr.id            AS registro_id,
            cr.talhao_id     AS talhao_id,
            cr.safra_talhao_id AS safra_talhao_id,
            cr.safra_id      AS safra_id,
            cr.data_colheita AS data_colheita,
            COALESCE(cr.variedade_id, t.variedade_id) AS variedade_id
       FROM colheita_registros cr
       JOIN agro_talhoes t ON t.id = cr.talhao_id AND t.tenant_id = cr.tenant_id
      WHERE cr.tenant_id = ?
        AND cr.safra_talhao_id IS NOT NULL
        AND COALESCE(cr.variedade_id, t.variedade_id) IS NOT NULL
      ORDER BY (cr.variedade_id IS NOT NULL) DESC, cr.data_colheita DESC, cr.id DESC
      LIMIT 1", [T]);

if ($chain) {
    echo "  = chain REAL: registro {$chain['registro_id']} · talhão {$chain['talhao_id']} · "
       . "safra_talhao {$chain['safra_talhao_id']} · variedade {$chain['variedade_id']}\n";
} else {
    /* ---- fallback: cria o MÍNIMO (só roda em banco vazio de colheita) ---- */
    echo "  ! nenhuma chain de colheita com variedade — criando o mínimo\n";

    $cultura = $val("SELECT id FROM agro_culturas WHERE tenant_id = ? ORDER BY id LIMIT 1", [T]);
    if (!$cultura) {
        $pdo->prepare("INSERT INTO agro_culturas (tenant_id, nome, ativo, created_at, updated_at)
                       VALUES (?, 'Uva (demo)', 1, NOW(), NOW())")->execute([T]);
        $cultura = (int)$pdo->lastInsertId();
        echo "    + cultura demo (id {$cultura})\n";
    }
    $safra = $val("SELECT id FROM agro_safras WHERE tenant_id = ? ORDER BY (status='ativa') DESC, id DESC LIMIT 1", [T]);
    if (!$safra) {
        $pdo->prepare("INSERT INTO agro_safras (tenant_id, identificacao, data_inicio, status, created_at, updated_at)
                       VALUES (?, 'DEMO-2026', CURDATE(), 'ativa', NOW(), NOW())")->execute([T]);
        $safra = (int)$pdo->lastInsertId();
        echo "    + safra demo (id {$safra})\n";
    }
    $variedade = $val("SELECT id FROM agro_variedades WHERE tenant_id = ? AND ativo = 1 ORDER BY id LIMIT 1", [T]);
    if (!$variedade) {
        $pdo->prepare("INSERT INTO agro_variedades (tenant_id, cultura_id, nome, ativo, created_at, updated_at)
                       VALUES (?, ?, 'Sugar Crisp (demo)', 1, NOW(), NOW())")->execute([T, $cultura]);
        $variedade = (int)$pdo->lastInsertId();
        echo "    + variedade demo (id {$variedade})\n";
    }
    $fazenda = $val("SELECT id FROM agro_fazendas WHERE tenant_id = ? ORDER BY id LIMIT 1", [T]);
    $talhao = $val("SELECT id FROM agro_talhoes WHERE tenant_id = ? AND codigo = 'PKDEMO'", [T]);
    if (!$talhao) {
        $pdo->prepare("INSERT INTO agro_talhoes (tenant_id, fazenda_id, codigo, nome, area_ha, ativo, variedade_id, created_at, updated_at)
                       VALUES (?, ?, 'PKDEMO', 'Talhão demo packing', 1.0000, 1, ?, NOW(), NOW())")
            ->execute([T, $fazenda, $variedade]);
        $talhao = (int)$pdo->lastInsertId();
        echo "    + talhão demo (id {$talhao})\n";
    }
    $safraTalhao = $val("SELECT id FROM agro_safra_talhoes WHERE tenant_id = ? AND safra_id = ? AND talhao_id = ?",
        [T, $safra, $talhao]);
    if (!$safraTalhao) {
        $pdo->prepare("INSERT INTO agro_safra_talhoes (tenant_id, safra_id, talhao_id, cultura_id, area_plantada_ha, created_at, updated_at)
                       VALUES (?, ?, ?, ?, 1.0000, NOW(), NOW())")->execute([T, $safra, $talhao, $cultura]);
        $safraTalhao = (int)$pdo->lastInsertId();
        echo "    + safra_talhao demo (id {$safraTalhao})\n";
    }
    $registro = $val("SELECT id FROM colheita_registros WHERE tenant_id = ? AND safra_talhao_id = ? AND talhao_id = ? ORDER BY id DESC LIMIT 1",
        [T, $safraTalhao, $talhao]);
    if (!$registro) {
        $pdo->prepare("INSERT INTO colheita_registros
                        (tenant_id, safra_id, safra_talhao_id, talhao_id, cultura_id, variedade_id, data_colheita, status, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'finalizada', NOW(), NOW())")
            ->execute([T, $safra, $safraTalhao, $talhao, $cultura, $variedade]);
        $registro = (int)$pdo->lastInsertId();
        echo "    + colheita_registro demo (id {$registro})\n";
    }
    $chain = [
        'registro_id'     => (int)$registro,
        'talhao_id'       => (int)$talhao,
        'safra_talhao_id' => (int)$safraTalhao,
        'safra_id'        => (int)$safra,
        'data_colheita'   => date('Y-m-d'),
        'variedade_id'    => (int)$variedade,
    ];
}

$variedadeNome = $val("SELECT nome FROM agro_variedades WHERE id = ? AND tenant_id = ?",
    [$chain['variedade_id'], T]) ?? '(sem nome)';

/* 3) COLHEITA_CARGA destino='packing' (idempotente pelo romaneio) ---------- */
$carga = $row("SELECT id, destino FROM colheita_cargas WHERE tenant_id = ? AND romaneio = ?", [T, ROMANEIO]);
if ($carga) {
    $cargaId = (int)$carga['id'];
    echo "  = carga demo JÁ EXISTE (id {$cargaId} · romaneio " . ROMANEIO . " · destino {$carga['destino']})\n";
} else {
    $pdo->prepare(
        "INSERT INTO colheita_cargas
            (tenant_id, registro_id, talhao_id, safra_talhao_id, romaneio, data_carga,
             peso_kg, classificacao, unidade_produtividade, origem, destino, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), 1200.000, 'exportacao', 't_ha', 'web', 'packing', NOW(), NOW())")
        ->execute([T, $chain['registro_id'], $chain['talhao_id'], $chain['safra_talhao_id'], ROMANEIO]);
    $cargaId = (int)$pdo->lastInsertId();
    echo "  + carga demo CRIADA (id {$cargaId} · romaneio " . ROMANEIO . " · destino packing · 1200,000 kg)\n";
}

/* lote COLH- de origem (ilustrativo p/ o testador — não obrigatório) ------ */
$loteColh = $row(
    "SELECT id, codigo_lote FROM estoque_lotes
      WHERE tenant_id = ? AND codigo_lote LIKE 'COLH%' AND colheita_registro_id = ?
      ORDER BY id DESC LIMIT 1", [T, $chain['registro_id']]);

/* 4) CERTIFICAÇÕES válidas p/ o gate passar (escopo unidade + produtor) ---- */
$validade = date('Y-m-d', strtotime('+1 year'));

$ensureCert = static function (string $escopo, ?int $escopoId, string $norma, string $numero) use ($pdo, $val, $validade): array {
    $id = $val(
        "SELECT id FROM ph_certificacoes
          WHERE tenant_id = ? AND escopo = ? AND norma = ? AND numero = ?",
        [T, $escopo, $norma, $numero]);
    if ($id) return [(int)$id, false];
    $pdo->prepare(
        "INSERT INTO ph_certificacoes
            (tenant_id, escopo, escopo_id, norma, edicao, numero, validade, organismo, ativo, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'v6', ?, ?, 'SGS (demo)', 1, NOW(), NOW())")
        ->execute([T, $escopo, $escopoId, $norma, $numero, $validade]);
    return [(int)$pdo->lastInsertId(), true];
};

[$certUniId, $certUniNew] = $ensureCert('unidade', $unidadeId, 'GLOBALGAP', 'PKDEMO-GGN-UNIDADE');
echo ($certUniNew ? '  + ' : '  = ') . "cert UNIDADE GLOBALG.A.P. (id {$certUniId}, escopo_id {$unidadeId}, valida até {$validade})\n";

[$certProdId, $certProdNew] = $ensureCert('produtor', null, 'GRASP', 'PKDEMO-GRASP-PRODUTOR');
echo ($certProdNew ? '  + ' : '  = ') . "cert PRODUTOR GRASP (id {$certProdId}, valida até {$validade})\n";

/* ---- RESUMO p/ o testador ---------------------------------------------- */
echo "\n== CENÁRIO PRONTO (tenant " . T . ") ==\n";
echo "  Unidade packing ......... id {$unidadeId} ({$unidade['nome']})\n";
echo "  Carga p/ recepção ....... id {$cargaId} · romaneio " . ROMANEIO . " · destino=packing\n";
echo "    talhão .............. id {$chain['talhao_id']}\n";
echo "    safra_talhao ........ id {$chain['safra_talhao_id']} (safra {$chain['safra_id']})\n";
echo "    variedade ........... id {$chain['variedade_id']} ({$variedadeNome})\n";
echo "    colheita_registro ... id {$chain['registro_id']} (colhido em {$chain['data_colheita']})\n";
echo "    lote COLH- .......... " . ($loteColh ? "id {$loteColh['id']} ({$loteColh['codigo_lote']})" : "(nenhum vinculado)") . "\n";
echo "  Gate de certificação .... cert unidade id {$certUniId} + cert produtor id {$certProdId} (ambas ativas, validade {$validade})\n";
echo "\nReexecute à vontade: idempotente por romaneio e por escopo+norma+número.\n";
