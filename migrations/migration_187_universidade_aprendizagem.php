<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_187_universidade_aprendizagem.php
   Universidade · Fase 1 — tabelas de APRENDIZAGEM e PRÁTICA no
   banco SEPARADO (config/database_uni.php). Sem FK para o banco
   do sistema (usuario_id/tenant_id são refs lógicas); FKs internas
   entre uni_* mantidas. Idempotente (CREATE ... IF NOT EXISTS).
   Rodar: php migrations/migration_187_universidade_aprendizagem.php
   ============================================================ */

$cfg = require __DIR__ . '/../config/database_uni.php';
echo "== migration 187: Universidade — aprendizagem + prática ==\n";
echo "   alvo: {$cfg['user']}@{$cfg['host']} / {$cfg['dbname']}\n";

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['dbname'], $cfg['charset']),
    $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tabelas = [];

$tabelas['uni_curso'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_curso (
  id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id  bigint(20) unsigned DEFAULT NULL,
  slug       varchar(160) NOT NULL,
  titulo     varchar(200) NOT NULL,
  resumo     varchar(400) DEFAULT NULL,
  modulo     varchar(64)  DEFAULT NULL,
  ordem      smallint unsigned NOT NULL DEFAULT 0,
  ativo      tinyint(1) NOT NULL DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_curso_slug (slug),
  KEY idx_uni_curso_modulo (modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_curso_capsula'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_curso_capsula (
  id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  curso_id    bigint(20) unsigned NOT NULL,
  capsula_id  bigint(20) unsigned NOT NULL,
  ordem       smallint unsigned NOT NULL DEFAULT 0,
  obrigatorio tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_cc (curso_id, capsula_id),
  KEY idx_uni_cc_capsula (capsula_id),
  CONSTRAINT fk_uni_cc_curso   FOREIGN KEY (curso_id)   REFERENCES uni_curso (id)   ON DELETE CASCADE,
  CONSTRAINT fk_uni_cc_capsula FOREIGN KEY (capsula_id) REFERENCES uni_capsula (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_trilha'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_trilha (
  id                 bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id          bigint(20) unsigned DEFAULT NULL,
  slug               varchar(32) NOT NULL,
  titulo             varchar(200) NOT NULL,
  publico            varchar(160) DEFAULT NULL,
  fora_do_escopo_md  text DEFAULT NULL,
  tempo_estimado_min int unsigned DEFAULT NULL,
  ordem              smallint unsigned NOT NULL DEFAULT 0,
  ativo              tinyint(1) NOT NULL DEFAULT 1,
  created_at         timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_trilha_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_trilha_curso'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_trilha_curso (
  id        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  trilha_id bigint(20) unsigned NOT NULL,
  curso_id  bigint(20) unsigned NOT NULL,
  ordem     smallint unsigned NOT NULL DEFAULT 0,
  nivel     enum('iniciante','intermediario','expert') DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_tc (trilha_id, curso_id),
  KEY idx_uni_tc_curso (curso_id),
  CONSTRAINT fk_uni_tc_trilha FOREIGN KEY (trilha_id) REFERENCES uni_trilha (id) ON DELETE CASCADE,
  CONSTRAINT fk_uni_tc_curso  FOREIGN KEY (curso_id)  REFERENCES uni_curso (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_trilha_perfil'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_trilha_perfil (
  id        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  trilha_id bigint(20) unsigned NOT NULL,
  perfil    varchar(40) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_tp (trilha_id, perfil),
  KEY idx_uni_tp_perfil (perfil),
  CONSTRAINT fk_uni_tp_trilha FOREIGN KEY (trilha_id) REFERENCES uni_trilha (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_matricula'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_matricula (
  id           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id    bigint(20) unsigned DEFAULT NULL,
  usuario_id   bigint(20) unsigned NOT NULL,
  trilha_id    bigint(20) unsigned NOT NULL,
  origem       enum('automatica','manual','autoinscricao') NOT NULL DEFAULT 'autoinscricao',
  status       enum('ativa','concluida','pausada') NOT NULL DEFAULT 'ativa',
  iniciado_em  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  concluido_em timestamp NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_matricula (usuario_id, trilha_id),
  KEY idx_uni_matricula_trilha (trilha_id),
  CONSTRAINT fk_uni_matricula_trilha FOREIGN KEY (trilha_id) REFERENCES uni_trilha (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_progresso'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_progresso (
  id                 bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id          bigint(20) unsigned DEFAULT NULL,
  usuario_id         bigint(20) unsigned NOT NULL,
  capsula_id         bigint(20) unsigned NOT NULL,
  estado             enum('nao_iniciada','em_andamento','concluida') NOT NULL DEFAULT 'nao_iniciada',
  percentual         tinyint unsigned NOT NULL DEFAULT 0,
  ultima_posicao_seg int unsigned DEFAULT NULL,
  origem             enum('web','app') NOT NULL DEFAULT 'web',
  atualizado_em      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_prog (usuario_id, capsula_id),
  KEY idx_uni_prog_capsula (capsula_id),
  CONSTRAINT fk_uni_prog_capsula FOREIGN KEY (capsula_id) REFERENCES uni_capsula (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_tarefa_pratica'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_tarefa_pratica (
  id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  capsula_id     bigint(20) unsigned NOT NULL,
  slug           varchar(160) NOT NULL,
  titulo         varchar(200) NOT NULL,
  enunciado_md   text NOT NULL,
  verificacao_sql text NOT NULL COMMENT 'SELECT que roda no banco do SISTEMA (tenant da escola); >0 linhas = ok',
  params_json    varchar(1000) DEFAULT NULL,
  mensagem_ok    varchar(300) DEFAULT NULL,
  mensagem_falha varchar(300) DEFAULT NULL,
  ordem          smallint unsigned NOT NULL DEFAULT 0,
  ativo          tinyint(1) NOT NULL DEFAULT 1,
  created_at     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_pratica_slug (slug),
  KEY idx_uni_pratica_capsula (capsula_id),
  CONSTRAINT fk_uni_pratica_capsula FOREIGN KEY (capsula_id) REFERENCES uni_capsula (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_tentativa'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_tentativa (
  id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id  bigint(20) unsigned DEFAULT NULL,
  usuario_id bigint(20) unsigned NOT NULL,
  tarefa_id  bigint(20) unsigned NOT NULL,
  sucesso    tinyint(1) NOT NULL DEFAULT 0,
  detalhe    varchar(300) DEFAULT NULL,
  criado_em  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_uni_tent_tarefa (tarefa_id, usuario_id),
  CONSTRAINT fk_uni_tent_tarefa FOREIGN KEY (tarefa_id) REFERENCES uni_tarefa_pratica (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

foreach ($tabelas as $nome => $ddl) {
    $existia = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($nome)
    )->fetchColumn() > 0;
    $pdo->exec($ddl);
    echo $existia ? "  = {$nome} já existia\n" : "  + {$nome} criada\n";
}

echo "== 187 concluída ==\n";
