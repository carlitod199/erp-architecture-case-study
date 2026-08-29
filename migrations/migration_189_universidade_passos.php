<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_189_universidade_passos.php
   Universidade · passos VISUAIS do "Como fazer": cada passo pode
   ter um print da tela com marcação (número + caixa/seta apontando
   o elemento). Banco SEPARADO. Idempotente.
   Rodar: php migrations/migration_189_universidade_passos.php
   ============================================================ */

$cfg = require __DIR__ . '/../config/database_uni.php';
echo "== migration 189: Universidade — passos visuais ==\n";

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['dbname'], $cfg['charset']),
    $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$ddl = <<<SQL
CREATE TABLE IF NOT EXISTS uni_passo (
  id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  capsula_id    bigint(20) unsigned NOT NULL,
  ordem         smallint unsigned NOT NULL DEFAULT 0,
  texto         varchar(400) NOT NULL,
  rota          varchar(200) DEFAULT NULL COMMENT 'tela do passo',
  seletor       varchar(300) DEFAULT NULL COMMENT 'CSS/texto do elemento a marcar',
  marca_tipo    enum('caixa','seta','destaque','numero') NOT NULL DEFAULT 'caixa',
  marca_label   varchar(120) DEFAULT NULL COMMENT 'legenda da marcação',
  imagem_url    varchar(500) DEFAULT NULL,
  imagem_hash   char(64) DEFAULT NULL,
  estado        enum('pendente','ok','desatualizado') NOT NULL DEFAULT 'pendente',
  created_at    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_passo (capsula_id, ordem),
  KEY idx_uni_passo_capsula (capsula_id),
  CONSTRAINT fk_uni_passo_capsula FOREIGN KEY (capsula_id) REFERENCES uni_capsula (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$existia = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='uni_passo'")->fetchColumn() > 0;
$pdo->exec($ddl);
echo $existia ? "  = uni_passo já existia\n" : "  + uni_passo criada\n";
echo "== 189 concluída ==\n";
