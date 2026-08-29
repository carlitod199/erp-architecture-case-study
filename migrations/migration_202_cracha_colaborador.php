<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_202_cracha_colaborador.php  (Packing House · produtividade)
   Crachá (QR/código de barras) do colaborador, para apontar produção por
   LEITURA em vez de dropdown: crachá do COLHEDOR na colheita e do EMBALADOR
   no embalamento (ambos caem em rh_producao_itens). Adiciona `cracha` em
   agro_operadores e rh_terceirizados, único por tenant (NULLs não conflitam).
   Aditivo, idempotente. Rodar: php migrations/migration_202_cracha_colaborador.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 202: crachá do colaborador ==\n";

$temColuna = static function (PDO $pdo, string $tab, string $col): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $st->execute([':t' => $tab, ':c' => $col]);
    return (int)$st->fetchColumn() > 0;
};
$temIndice = static function (PDO $pdo, string $tab, string $idx): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i");
    $st->execute([':t' => $tab, ':i' => $idx]);
    return (int)$st->fetchColumn() > 0;
};

foreach ([
    'agro_operadores' => 'uq_oper_cracha',
    'rh_terceirizados' => 'uq_terc_cracha',
] as $tab => $idx) {
    if (!$temColuna($pdo, $tab, 'cracha')) {
        $pdo->exec("ALTER TABLE {$tab} ADD COLUMN cracha VARCHAR(40) NULL COMMENT 'código do crachá (QR/barras) — leitura no apontamento'");
        echo "  + {$tab}.cracha\n";
    } else { echo "  = {$tab}.cracha já existe\n"; }
    if (!$temIndice($pdo, $tab, $idx)) {
        $pdo->exec("ALTER TABLE {$tab} ADD UNIQUE KEY {$idx} (tenant_id, cracha)");
        echo "  + {$tab} UNIQUE ({$idx})\n";
    } else { echo "  = {$tab}.{$idx} já existe\n"; }
}
echo "== 202 concluída ==\n";
