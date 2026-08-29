<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_201_ph_recepcoes.php  (Packing House Onda 1 · tarefa 4)
   Recepção do packing: cabeçalho (ph_recepcoes) + itens (ph_recepcao_itens)
   consumindo colheita_cargas destino='packing' e o lote COLH-. Guarda o
   resultado dos 5 gates e os marcos do relógio de frio (colhido/recebido).
   Categóricos = VARCHAR + whitelist em PHP. Idempotente.
   Rodar: php migrations/migration_201_ph_recepcoes.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 201: ph_recepcoes + ph_recepcao_itens ==\n";

$existe = static function (PDO $pdo, string $t): bool {
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn() > 0;
};

if (!$existe($pdo, 'ph_recepcoes')) {
    $pdo->exec(<<<SQL
CREATE TABLE ph_recepcoes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unidade_id BIGINT UNSIGNED NOT NULL,              -- almoxarifado tipo='packing'
  numero VARCHAR(20) NOT NULL,
  produtor_id BIGINT UNSIGNED NULL,
  contrato_id BIGINT UNSIGNED NULL,
  veiculo_placa VARCHAR(10) NULL,
  motorista VARCHAR(120) NULL,
  transportadora VARCHAR(120) NULL,
  chegou_em DATETIME NULL,
  iniciou_descarga_em DATETIME NULL,
  finalizou_descarga_em DATETIME NULL,
  peso_bruto_kg DECIMAL(14,3) NULL,
  peso_tara_kg DECIMAL(14,3) NULL,
  peso_liquido_kg DECIMAL(14,3) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'chegou',      -- agendada|chegou|pesando|conferindo|aceita|rejeitada
  gates_resultado JSON NULL,
  observacao TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ph_recep (tenant_id, unidade_id, numero),
  KEY idx_ph_recep_tenant (tenant_id, status),
  CONSTRAINT fk_ph_recep_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "  + ph_recepcoes criada\n";
} else { echo "  = ph_recepcoes já existe\n"; }

if (!$existe($pdo, 'ph_recepcao_itens')) {
    $pdo->exec(<<<SQL
CREATE TABLE ph_recepcao_itens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  recepcao_id BIGINT UNSIGNED NOT NULL,
  colheita_carga_id BIGINT UNSIGNED NULL,            -- consome colheita_cargas destino='packing'
  lote_estoque_id BIGINT UNSIGNED NULL,              -- lote COLH- de origem
  talhao_id BIGINT UNSIGNED NULL,
  safra_talhao_id BIGINT UNSIGNED NULL,
  variedade_id BIGINT UNSIGNED NULL,
  produtor_id BIGINT UNSIGNED NULL,
  colhido_em DATETIME NULL,                          -- dispara relógio de SO2/frio
  n_contentores INT NULL,
  peso_kg DECIMAL(14,3) NULL,
  temperatura_chegada_c DECIMAL(5,2) NULL,
  turma_colheita VARCHAR(80) NULL,
  metodo_rastreabilidade VARCHAR(20) NULL,           -- segregacao|identidade_preservada
  status VARCHAR(20) NOT NULL DEFAULT 'recebido',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ph_recepit (tenant_id, recepcao_id),
  KEY idx_ph_recepit_carga (tenant_id, colheita_carga_id),
  CONSTRAINT fk_ph_recepit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "  + ph_recepcao_itens criada\n";
} else { echo "  = ph_recepcao_itens já existe\n"; }

echo "== 201 concluída ==\n";
