<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_197_ph_mercados.php  (Packing House · cadastro de Mercados)
   Cria ph_mercados: mercados de destino da fruta (mercado interno/exportação),
   cada um com suas regras de qualidade/aceitação em JSON (brix_min,
   peso_cacho_min_g, classes, tolerancias, janela_sazonal, docs_exigidos,
   mrl_ref, so2_permitido…). Categóricos ficam como VARCHAR + whitelist no PHP
   (nunca ENUM novo). Sem FK rígida — só índice liderando por tenant_id.
   Idempotente (checa information_schema + CREATE TABLE IF NOT EXISTS), NO DROP.
   Rodar: php migrations/migration_197_ph_mercados.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 197: ph_mercados ==\n";

$existe = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ph_mercados'")
    ->fetchColumn();

if ($existe === 0) {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ph_mercados (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  pais_iso3 VARCHAR(3) NULL,
  regras JSON NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ph_mercados_codigo (tenant_id, codigo),
  KEY idx_ph_mercados_tenant (tenant_id, ativo, nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "  + tabela ph_mercados criada\n";
} else {
    echo "  = ph_mercados já existe\n";
}

echo "== 197 concluída ==\n";
