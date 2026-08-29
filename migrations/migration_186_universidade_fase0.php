<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_186_universidade_fase0.php
   Universidade VERO · Fase 0 — tabelas [F0] em BANCO SEPARADO.

   Decisão 26/07: a Universidade NÃO usa o banco do sistema. Este
   script conecta em config/database_uni.php (UNI_DB_* do .env),
   cria o schema se necessário (dev local) e cria as 9 tabelas [F0].

   Sem FK para tabelas do sistema (tenants/usuarios) — ficam em outro
   banco; tenant_id/*_by/usuario_id são referências LÓGICAS. As FKs
   internas entre uni_* são mantidas (mesmo banco).

   Idempotente (CREATE ... IF NOT EXISTS). Rodar:
       php migrations/migration_186_universidade_fase0.php
   ============================================================ */

$cfg = require __DIR__ . '/../config/database_uni.php';

echo "== migration 186: Universidade VERO — Fase 0 (banco separado) ==\n";
echo "   alvo: {$cfg['user']}@{$cfg['host']} / {$cfg['dbname']}\n";

/* 1) Garante o schema (dev local). Em DBaaS sem privilégio de CREATE
      DATABASE, o schema já existe e este passo é ignorado com aviso. */
try {
    $srv = new PDO(
        sprintf('mysql:host=%s;charset=%s', $cfg['host'], $cfg['charset']),
        $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $srv->exec(
        "CREATE DATABASE IF NOT EXISTS `{$cfg['dbname']}`
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    echo "  + schema `{$cfg['dbname']}` garantido\n";
} catch (Throwable $e) {
    echo "  ! CREATE DATABASE ignorado (sem privilégio ou já existe): {$e->getMessage()}\n";
}

/* 2) Conecta no schema da Universidade. */
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['dbname'], $cfg['charset']),
    $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/* 3) Tabelas [F0]. CREATE TABLE IF NOT EXISTS = idempotente. */
$tabelas = [];

$tabelas['uni_capsula'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_capsula (
  id                  bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id           bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = conteúdo global da plataforma (ref. lógica ao banco do sistema)',
  slug                varchar(160) NOT NULL,
  titulo              varchar(200) NOT NULL,
  tipo                enum('FAZER','ENTENDER','CONSULTAR','PRATICAR','VERIFICAR') NOT NULL,
  resumo              varchar(400) DEFAULT NULL,
  corpo_md            longtext NOT NULL,
  modulo              varchar(64)  DEFAULT NULL,
  objetivo            varchar(64)  DEFAULT NULL,
  jornada             varchar(160) DEFAULT NULL COMMENT 'csv curto; vira tabela se crescer',
  nivel               enum('iniciante','intermediario','expert') NOT NULL DEFAULT 'iniciante',
  duracao_seg         int unsigned DEFAULT NULL,
  status              enum('rascunho','revisao','publicado','obsoleto') NOT NULL DEFAULT 'rascunho',
  versao              varchar(20)  DEFAULT NULL,
  vero_versao_min     varchar(20)  DEFAULT NULL,
  vero_versao_max     varchar(20)  DEFAULT NULL,
  dono_email          varchar(190) DEFAULT NULL,
  revisado_em         date DEFAULT NULL,
  proxima_revisao_em  date DEFAULT NULL,
  ativo               tinyint(1) NOT NULL DEFAULT 1 COMMENT 'soft-delete (padrão vero_delete)',
  created_by          bigint(20) unsigned DEFAULT NULL,
  updated_by          bigint(20) unsigned DEFAULT NULL,
  created_at          timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_capsula_slug (slug),
  KEY idx_uni_capsula_tenant (tenant_id),
  KEY idx_uni_capsula_status (status),
  KEY idx_uni_capsula_modulo (modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_capsula_rota'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_capsula_rota (
  id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  capsula_id  bigint(20) unsigned NOT NULL,
  rota        varchar(200) NOT NULL COMMENT 'path puro, ex.: /agro/apontamentos.php',
  tela_app    varchar(80)  DEFAULT NULL,
  principal   tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_uni_rota_rota (rota, principal),
  KEY idx_uni_rota_capsula (capsula_id),
  KEY idx_uni_rota_app (tela_app),
  CONSTRAINT fk_uni_rota_capsula FOREIGN KEY (capsula_id) REFERENCES uni_capsula (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_capsula_permissao'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_capsula_permissao (
  id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  capsula_id      bigint(20) unsigned NOT NULL,
  permissao_chave varchar(120) NOT NULL COMMENT 'ex.: agro.apontamentos_campo.editar',
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_perm (capsula_id, permissao_chave),
  KEY idx_uni_perm_chave (permissao_chave),
  CONSTRAINT fk_uni_perm_capsula FOREIGN KEY (capsula_id) REFERENCES uni_capsula (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_capsula_papel'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_capsula_papel (
  id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  capsula_id  bigint(20) unsigned NOT NULL,
  perfil      varchar(40) NOT NULL,
  relevancia  enum('nucleo','consulta') NOT NULL DEFAULT 'nucleo',
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_papel (capsula_id, perfil),
  CONSTRAINT fk_uni_papel_capsula FOREIGN KEY (capsula_id) REFERENCES uni_capsula (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_capsula_relacao'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_capsula_relacao (
  id                bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  capsula_id        bigint(20) unsigned NOT NULL,
  relacionada_slug  varchar(160) NOT NULL COMMENT 'alvo por slug (pode não existir ainda)',
  relacionada_id    bigint(20) unsigned DEFAULT NULL COMMENT 'resolvido na publicação',
  tipo              enum('prerequisito','proximo','relacionado') NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_rel (capsula_id, tipo, relacionada_slug),
  KEY idx_uni_rel_alvo (relacionada_id),
  CONSTRAINT fk_uni_rel_capsula FOREIGN KEY (capsula_id)     REFERENCES uni_capsula (id) ON DELETE CASCADE,
  CONSTRAINT fk_uni_rel_alvo    FOREIGN KEY (relacionada_id) REFERENCES uni_capsula (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_ativo'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_ativo (
  id           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  capsula_id   bigint(20) unsigned NOT NULL,
  tipo         enum('video','imagem','pdf','checklist','fluxograma') NOT NULL,
  url          varchar(500) DEFAULT NULL,
  duracao_seg  int unsigned DEFAULT NULL,
  transcricao  mediumtext DEFAULT NULL,
  legenda_url  varchar(500) DEFAULT NULL,
  hash_origem  char(64) DEFAULT NULL COMMENT 'sha256 da captura/tela de origem (pipeline T7)',
  estado       enum('ok','desatualizado') NOT NULL DEFAULT 'ok',
  ordem        smallint unsigned NOT NULL DEFAULT 0,
  created_at   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_uni_ativo_capsula (capsula_id, estado),
  CONSTRAINT fk_uni_ativo_capsula FOREIGN KEY (capsula_id) REFERENCES uni_capsula (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_ajuda_evento'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_ajuda_evento (
  id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id   bigint(20) unsigned DEFAULT NULL,
  usuario_id  bigint(20) unsigned DEFAULT NULL,
  rota        varchar(200) NOT NULL,
  acao        enum('abriu','assistiu','perguntou','abriu_chamado') NOT NULL,
  capsula_id  bigint(20) unsigned DEFAULT NULL,
  ts          timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_uni_evt_rota (rota, acao),
  KEY idx_uni_evt_tenant (tenant_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_tela_hash'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_tela_hash (
  id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rota          varchar(200) NOT NULL,
  hash_arquivo  char(64) NOT NULL COMMENT 'sha256 do .php da tela',
  verificado_em timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_tela_hash_rota (rota)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$tabelas['uni_publicacao'] = <<<SQL
CREATE TABLE IF NOT EXISTS uni_publicacao (
  id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  capsula_id    bigint(20) unsigned NOT NULL,
  versao        varchar(20) NOT NULL,
  changelog     varchar(500) DEFAULT NULL,
  conteudo_hash char(64) DEFAULT NULL COMMENT 'sha256 do corpo_md publicado',
  publicado_por bigint(20) unsigned DEFAULT NULL,
  publicado_em  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_uni_pub_capsula (capsula_id, publicado_em),
  CONSTRAINT fk_uni_pub_capsula FOREIGN KEY (capsula_id) REFERENCES uni_capsula (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

/* Ordem importa: uni_capsula primeiro (as demais têm FK para ela). */
foreach ($tabelas as $nome => $ddl) {
    $existia = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($nome)
    )->fetchColumn() > 0;
    $pdo->exec($ddl);
    echo $existia ? "  = {$nome} já existia\n" : "  + {$nome} criada\n";
}

echo "== 186 concluída ==\n";
