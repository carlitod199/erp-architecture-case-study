<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_199_ph_licencas_varietais.php  (Packing House)
   Cria ph_licencas_varietais: licenças de variedade (proteção por
   MARCA registrada e/ou por OBTENTOR/cultivar), com vigência,
   mercados autorizados (JSON), alíquota de royalty e base de cálculo.
   Categóricos = VARCHAR + whitelist em PHP (NUNCA ENUM novo).
   FK rígida só p/ tabela consolidada (agro_variedades, ON DELETE SET NULL).
   Aditivo/idempotente (CREATE TABLE IF NOT EXISTS), NO DROP.
   Rodar: php migrations/migration_199_ph_licencas_varietais.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 199: ph_licencas_varietais ==\n";

$existe = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ph_licencas_varietais'")
    ->fetchColumn();

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ph_licencas_varietais (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  variedade_id BIGINT UNSIGNED NULL,
  denominacao_varietal VARCHAR(120) NULL,
  marca_comercial VARCHAR(120) NULL,
  obtentor VARCHAR(120) NULL,
  licenciante VARCHAR(120) NULL,
  tipo_protecao VARCHAR(30) NULL,
  vigencia_inicio DATE NULL,
  vigencia_fim DATE NULL,
  mercados_autorizados JSON NULL,
  aliquota_pct DECIMAL(6,3) NULL,
  base_calculo VARCHAR(40) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ativo',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lic_tenant_variedade (tenant_id, variedade_id),
  CONSTRAINT fk_ph_lic_variedade FOREIGN KEY (variedade_id)
    REFERENCES agro_variedades (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

echo $existe ? "  = ph_licencas_varietais já existia\n" : "  + ph_licencas_varietais criada\n";
echo "== 199 concluída ==\n";
