<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_188_universidade_certificacao_gestor.php
   Universidade · Fase 1 — CERTIFICAÇÃO, SELOS e CHECKLIST de
   implantação, no banco SEPARADO (config/database_uni.php).
   Sem FK para o sistema (usuario_id/tenant_id = refs lógicas).
   Idempotente. Rodar:
     php migrations/migration_188_universidade_certificacao_gestor.php
   ============================================================ */

$cfg = require __DIR__ . '/../config/database_uni.php';
echo "== migration 188: Universidade — certificação + gestor ==\n";
echo "   alvo: {$cfg['user']}@{$cfg['host']} / {$cfg['dbname']}\n";

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['dbname'], $cfg['charset']),
    $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tabelas = [];

$tabelas['uni_certificado'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_certificado (
  id               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id        bigint(20) unsigned DEFAULT NULL,
  usuario_id       bigint(20) unsigned NOT NULL,
  trilha_id        bigint(20) unsigned NOT NULL,
  codigo_publico   varchar(32) NOT NULL,
  nome_titular     varchar(160) DEFAULT NULL COMMENT 'snapshot do nome no momento da emissão',
  trilha_titulo    varchar(200) DEFAULT NULL COMMENT 'snapshot do título da trilha',
  versao_conteudo  json DEFAULT NULL COMMENT 'snapshot cápsulas+versões que valeram o certificado',
  emitido_em       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  valido_ate       date DEFAULT NULL,
  revogado         tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_cert_codigo (codigo_publico),
  UNIQUE KEY uq_uni_cert_user_trilha (usuario_id, trilha_id),
  KEY idx_uni_cert_trilha (trilha_id),
  CONSTRAINT fk_uni_cert_trilha FOREIGN KEY (trilha_id) REFERENCES uni_trilha (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_selo'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_selo (
  id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id     bigint(20) unsigned DEFAULT NULL,
  usuario_id    bigint(20) unsigned NOT NULL,
  slug          varchar(64) NOT NULL,
  titulo        varchar(120) NOT NULL,
  icone         varchar(16) DEFAULT NULL COMMENT 'emoji curto',
  conquistado_em timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_selo (usuario_id, slug),
  KEY idx_uni_selo_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_checklist_item'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_checklist_item (
  id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id      bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = item global de implantação',
  slug           varchar(120) NOT NULL,
  titulo         varchar(200) NOT NULL,
  descricao_md   text DEFAULT NULL,
  capsula_slug   varchar(160) DEFAULT NULL COMMENT 'cápsula que ensina o passo',
  verificacao_sql text DEFAULT NULL COMMENT 'SELECT no banco do sistema (:tenant); >0 = concluído automaticamente',
  perfil         varchar(40) DEFAULT NULL COMMENT 'para quem (NULL = todos)',
  ordem          smallint unsigned NOT NULL DEFAULT 0,
  ativo          tinyint(1) NOT NULL DEFAULT 1,
  created_at     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_chk_slug (slug),
  KEY idx_uni_chk_ordem (ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_checklist_estado'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_checklist_estado (
  id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id     bigint(20) unsigned NOT NULL,
  item_id       bigint(20) unsigned NOT NULL,
  concluido     tinyint(1) NOT NULL DEFAULT 0,
  concluido_em  timestamp NULL DEFAULT NULL,
  atualizado_em timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_chkest (tenant_id, item_id),
  CONSTRAINT fk_uni_chkest_item FOREIGN KEY (item_id) REFERENCES uni_checklist_item (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

foreach ($tabelas as $nome => $ddl) {
    $existia = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($nome)
    )->fetchColumn() > 0;
    $pdo->exec($ddl);
    echo $existia ? "  = {$nome} já existia\n" : "  + {$nome} criada\n";
}

echo "== 188 concluída ==\n";
