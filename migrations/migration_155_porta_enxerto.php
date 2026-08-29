<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_155_porta_enxerto.php  (batch 13/07 #1)
   Porta-enxerto vira CADASTRO PRÓPRIO (decisão do gestor: atribuído no
   Talhão/Válvula). Substitui o texto livre que existia em agro_variedades
   e analise_faixas.
     (a) CREATE agro_porta_enxertos (cadastro + auditoria padrão);
     (b) agro_talhoes  + porta_enxerto_id (FK lógica, NULL);
     (c) analise_faixas + porta_enxerto_id (FK lógica, NULL) e DROP do texto;
     (d) agro_variedades DROP do texto porta_enxerto (o "campo aberto").
   SEM backfill (0 valores hoje — confirmado). Aditivo/idempotente.
   Seed inicial de porta-enxertos comuns de videira (EDITÁVEL — o gestor ajusta).
   Rodar: php migrations/migration_155_porta_enxerto.php
   ============================================================ */

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 155: porta-enxerto (cadastro) ==\n";

/** coluna existe? */
$colExiste = function (string $tabela, string $coluna) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $st->execute([':t' => $tabela, ':c' => $coluna]);
    return (int)$st->fetchColumn() > 0;
};

/* (a) cadastro */
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agro_porta_enxertos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  codigo VARCHAR(20) NULL,
  nome VARCHAR(80) NOT NULL,
  descricao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pe_tenant_ativo (tenant_id, ativo),
  UNIQUE KEY uq_pe_tenant_nome (tenant_id, nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok agro_porta_enxertos\n";

/* (b) talhão referencia o porta-enxerto */
if (!$colExiste('agro_talhoes', 'porta_enxerto_id')) {
    $pdo->exec("ALTER TABLE agro_talhoes ADD COLUMN porta_enxerto_id BIGINT UNSIGNED NULL AFTER variedade_id");
    echo "  ok agro_talhoes.porta_enxerto_id\n";
} else echo "  - agro_talhoes.porta_enxerto_id já existe\n";

/* (c) faixa nutricional: FK + remove o texto */
if (!$colExiste('analise_faixas', 'porta_enxerto_id')) {
    $pdo->exec("ALTER TABLE analise_faixas ADD COLUMN porta_enxerto_id BIGINT UNSIGNED NULL AFTER variedade_id");
    echo "  ok analise_faixas.porta_enxerto_id\n";
} else echo "  - analise_faixas.porta_enxerto_id já existe\n";
if ($colExiste('analise_faixas', 'porta_enxerto')) {
    /* segurança: só dropa se não houver valor não-vazio (confirmado 0 hoje) */
    $tem = (int)$pdo->query("SELECT COUNT(*) FROM analise_faixas WHERE porta_enxerto IS NOT NULL AND porta_enxerto <> ''")->fetchColumn();
    if ($tem === 0) { $pdo->exec("ALTER TABLE analise_faixas DROP COLUMN porta_enxerto"); echo "  ok DROP analise_faixas.porta_enxerto (texto)\n"; }
    else echo "  !! analise_faixas.porta_enxerto tem {$tem} valor(es) — NÃO dropado (revisar/migrar antes)\n";
} else echo "  - analise_faixas.porta_enxerto (texto) já removido\n";

/* (d) variedade: remove o campo aberto */
if ($colExiste('agro_variedades', 'porta_enxerto')) {
    $tem = (int)$pdo->query("SELECT COUNT(*) FROM agro_variedades WHERE porta_enxerto IS NOT NULL AND porta_enxerto <> ''")->fetchColumn();
    if ($tem === 0) { $pdo->exec("ALTER TABLE agro_variedades DROP COLUMN porta_enxerto"); echo "  ok DROP agro_variedades.porta_enxerto (texto)\n"; }
    else echo "  !! agro_variedades.porta_enxerto tem {$tem} valor(es) — NÃO dropado (revisar/migrar antes)\n";
} else echo "  - agro_variedades.porta_enxerto (texto) já removido\n";

/* seed inicial (porta-enxertos comuns de videira — EDITÁVEL). get-or-create por tenant+nome. */
$tenants = array_map('intval', $pdo->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN));
$comuns = ['IAC 572 (Campinas)', 'IAC 766 (Campinas)', 'Paulsen 1103', 'SO4', 'Freedom', 'Harmony'];
$has = $pdo->prepare("SELECT 1 FROM agro_porta_enxertos WHERE tenant_id = :t AND nome = :n");
$ins = $pdo->prepare("INSERT INTO agro_porta_enxertos (tenant_id, nome, ativo) VALUES (:t, :n, 1)");
$n = 0;
foreach ($tenants as $tid) {
    foreach ($comuns as $nome) {
        $has->execute([':t' => $tid, ':n' => $nome]);
        if (!$has->fetchColumn()) { $ins->execute([':t' => $tid, ':n' => $nome]); $n++; }
    }
}
echo "  seed porta-enxertos comuns: {$n} novo(s) — EDITÁVEL pelo gestor\n";
echo "== 155 concluída ==\n";
