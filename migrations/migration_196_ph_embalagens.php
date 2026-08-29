<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_196_ph_embalagens.php  (Packing House)
   Cria a tabela ph_embalagens: cadastro de embalagens do Packing House
   (caixa, cumbuca, punnet, saco, liner, pad SO2, absorvedor, cantoneira,
   pallet), com dados de tara/dimensões, geração de SO2, ISPM-15 (madeira)
   e elegibilidade a drawback.

   Categóricos = VARCHAR + whitelist em PHP (NUNCA ENUM novo):
     tipo     : caixa|cumbuca|punnet|saco|liner|pad_so2|absorvedor|cantoneira|pallet
     so2_fase : unica|dupla|ultra_fast
   FK rígida só p/ tabelas consolidadas: tenants, estoque_produtos.
   InnoDB utf8mb4_unicode_ci, índice liderando por tenant_id, UNIQUE(tenant_id, codigo).

   Idempotente (CREATE TABLE IF NOT EXISTS + checa information_schema).
   NÃO fia menu/permissões (feito depois, centralizado).
   Rodar: php migrations/migration_196_ph_embalagens.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 196: ph_embalagens (Packing House) ==\n";

$existe = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ph_embalagens'")
    ->fetchColumn();

if ($existe === 0) {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ph_embalagens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  produto_estoque_id BIGINT UNSIGNED NULL,
  codigo VARCHAR(40) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  tipo VARCHAR(30) NOT NULL COMMENT 'whitelist PHP: caixa|cumbuca|punnet|saco|liner|pad_so2|absorvedor|cantoneira|pallet',
  tara_g DECIMAL(10,3) NULL,
  comprimento_mm INT NULL,
  largura_mm INT NULL,
  altura_mm INT NULL,
  so2_fase VARCHAR(20) NULL COMMENT 'whitelist PHP: unica|dupla|ultra_fast',
  so2_dose_ppm DECIMAL(8,3) NULL,
  so2_duracao_h INT NULL,
  ispm15_credenciamento VARCHAR(40) NULL,
  ispm15_tratamento VARCHAR(10) NULL,
  elegivel_drawback TINYINT(1) NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ph_emb_codigo (tenant_id, codigo),
  KEY idx_ph_emb_tenant (tenant_id, ativo, nome),
  KEY idx_ph_emb_produto (tenant_id, produto_estoque_id),
  CONSTRAINT fk_ph_emb_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_ph_emb_produto FOREIGN KEY (produto_estoque_id) REFERENCES estoque_produtos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "  + tabela ph_embalagens criada\n";
} else {
    echo "  = ph_embalagens já existe\n";
}

echo "== 196 concluída ==\n";
