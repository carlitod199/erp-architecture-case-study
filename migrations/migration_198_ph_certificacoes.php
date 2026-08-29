<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_198_ph_certificacoes.php  (Packing House)
   Cria ph_certificacoes: certificações do packing house com escopo
   polimórfico (unidade | fazenda | produtor) — o alvo concreto vai em
   escopo_id (referência solta, sem FK rígida, pois o escopo varia).
   Categóricos = VARCHAR + whitelist em PHP (packing/certificacoes.php),
   NUNCA ENUM. Aditivo/idempotente (CREATE TABLE IF NOT EXISTS + checagem
   em information_schema). NO DROP.
   Rodar: php migrations/migration_198_ph_certificacoes.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 198: ph_certificacoes ==\n";

$existe = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ph_certificacoes'")
    ->fetchColumn();

if ($existe === 0) {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ph_certificacoes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  escopo VARCHAR(20) NOT NULL DEFAULT 'unidade',   -- whitelist: unidade | fazenda | produtor
  escopo_id BIGINT UNSIGNED NULL,                  -- alvo do escopo (referência solta, sem FK)
  norma VARCHAR(40) NOT NULL,                       -- whitelist: GLOBALGAP | GRASP | RAINFOREST | BRCGS | IFS
  edicao VARCHAR(20) NULL,
  numero VARCHAR(60) NOT NULL,
  validade DATE NULL,
  organismo VARCHAR(120) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tenant_escopo (tenant_id, escopo, escopo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "  + tabela ph_certificacoes criada\n";
} else {
    echo "  = ph_certificacoes já existe\n";
}

echo "== 198 concluída ==\n";
