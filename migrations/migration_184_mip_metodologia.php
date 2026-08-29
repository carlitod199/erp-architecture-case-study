<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_184_mip_metodologia.php
   MIP parametrizável (reunião 23/07, X-02/Y-03). A metodologia de
   amostragem passa a ser PARÂMETRO (planta / folha / caixa / severidade)
   com multiplicador (unidades por planta) — espaço amostral =
   unidades_por_planta × plantas_amostradas; índice = encontradas ÷
   espaço_amostral × 100. Encerra o vaivém de recodificação.

   - agro_fazendas: default por fazenda (mip_metodologia, mip_unidades_por_planta)
   - mip_monitoramentos: registra o método usado + unidades + flag 'arquivado'
   - Legado (Y-03): monitoramentos com índice > 100 (metodologias antigas:
     Cigarrinha 225%, Oídio 180%) são ARQUIVADOS (arquivado=1) — preserva o
     histórico, some da visão ativa.
   Idempotente (checa information_schema). Rodar:
       php migrations/migration_184_mip_metodologia.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 184: MIP metodologia parametrizável ==\n";

$temCol = function (string $tabela, string $col) use ($pdo): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $st->execute([':t' => $tabela, ':c' => $col]);
    return (int)$st->fetchColumn() > 0;
};
$add = function (string $tabela, string $col, string $ddl) use ($pdo, $temCol): void {
    if (!$temCol($tabela, $col)) {
        $pdo->exec("ALTER TABLE {$tabela} ADD COLUMN {$ddl}");
        echo "  + {$tabela}.{$col}\n";
    } else { echo "  = {$tabela}.{$col} já existe\n"; }
};

$add('agro_fazendas', 'mip_metodologia',      "mip_metodologia VARCHAR(16) NOT NULL DEFAULT 'planta' AFTER nome");
$add('agro_fazendas', 'mip_unidades_por_planta', "mip_unidades_por_planta INT NULL AFTER mip_metodologia");

$add('mip_monitoramentos', 'metodologia',        "metodologia VARCHAR(16) NOT NULL DEFAULT 'planta' AFTER plantas_amostradas");
$add('mip_monitoramentos', 'unidades_por_planta', "unidades_por_planta INT NULL AFTER metodologia");
$add('mip_monitoramentos', 'arquivado',          "arquivado TINYINT(1) NOT NULL DEFAULT 0 AFTER unidades_por_planta");

/* Y-03: arquiva os legados anômalos (índice > 100 herdado das metodologias
   antigas). Uma vez só — se já houver algum arquivado, não re-executa a marca. */
$jaArquivou = (int)$pdo->query("SELECT COUNT(*) FROM mip_monitoramentos WHERE arquivado = 1")->fetchColumn();
if ($jaArquivou === 0) {
    $n = $pdo->exec("UPDATE mip_monitoramentos SET arquivado = 1 WHERE nivel_infestacao > 100");
    echo "  ~ {$n} monitoramento(s) legado(s) (>100%) arquivado(s)\n";
} else {
    echo "  = já há monitoramentos arquivados — marca de legado não reexecutada\n";
}

echo "== 184 concluída ==\n";
