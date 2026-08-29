<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_200_ph_skus.php  (Packing House · cadastro de SKUs)
   Cria ph_skus: o "produto acabado" do packing house (código comercial,
   cultura/variedade, calibre/categoria, embalagem, peso nominal + tolerâncias,
   paletização, GTIN, mercado e o vínculo com o item de estoque).

   Decisões de modelagem:
   - unidade_id  -> almoxarifados tipo='packing' (D1: sem ph_unidades no MVP)
   - categoria   = VARCHAR + whitelist em PHP (extra|cat1|cat2|interno|industria),
                   NUNCA ENUM novo.
   - NCM/CFOP/CEST NÃO vivem aqui: a fonte fiscal única é estoque_produtos
     (via produto_estoque_id).
   - FK rígida só para tabelas consolidadas (tenants, agro_culturas,
     agro_variedades, estoque_produtos). unidade_id/embalagem_id/mercado_id =
     índice sem FK (ph_embalagens/ph_mercados ainda em construção).

   Aditiva, idempotente (CREATE TABLE IF NOT EXISTS).
   Rodar: php migrations/migration_200_ph_skus.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 200: ph_skus (produto acabado do packing house) ==\n";

$existe = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ph_skus'")->fetchColumn();

if ($existe === 0) {
    $pdo->exec(<<<'SQL'
CREATE TABLE ph_skus (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unidade_id BIGINT UNSIGNED NULL,
  codigo VARCHAR(40) NOT NULL,
  descricao VARCHAR(160) NOT NULL,
  cultura_id BIGINT UNSIGNED NULL,
  variedade_id BIGINT UNSIGNED NULL,
  marca_comercial VARCHAR(80) NULL,
  calibre VARCHAR(40) NULL,
  categoria VARCHAR(20) NULL,
  embalagem_id BIGINT UNSIGNED NULL,
  peso_nominal_kg DECIMAL(10,3) NULL,
  tolerancia_min_kg DECIMAL(10,3) NULL,
  tolerancia_max_kg DECIMAL(10,3) NULL,
  unidades_por_caixa INT NULL,
  caixas_por_camada INT NULL,
  camadas_por_pallet INT NULL,
  gtin VARCHAR(14) NULL,
  mercado_id BIGINT UNSIGNED NULL,
  produto_estoque_id BIGINT UNSIGNED NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ph_skus_codigo (tenant_id, codigo),
  KEY idx_ph_skus_tenant (tenant_id, ativo),
  KEY idx_ph_skus_unidade (tenant_id, unidade_id),
  KEY idx_ph_skus_cultura (tenant_id, cultura_id),
  KEY idx_ph_skus_variedade (tenant_id, variedade_id),
  KEY idx_ph_skus_embalagem (tenant_id, embalagem_id),
  KEY idx_ph_skus_mercado (tenant_id, mercado_id),
  KEY idx_ph_skus_produto (tenant_id, produto_estoque_id),
  CONSTRAINT fk_ph_skus_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_ph_skus_cultura FOREIGN KEY (cultura_id) REFERENCES agro_culturas (id),
  CONSTRAINT fk_ph_skus_variedade FOREIGN KEY (variedade_id) REFERENCES agro_variedades (id),
  CONSTRAINT fk_ph_skus_produto FOREIGN KEY (produto_estoque_id) REFERENCES estoque_produtos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "  + tabela ph_skus criada (UNIQUE tenant_id+codigo; FK rígida p/ culturas/variedades/produtos)\n";
} else {
    echo "  = ph_skus já existe\n";
}

echo "== 200 concluída ==\n";
