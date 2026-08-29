<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_190_universidade_usuarios.php
   Universidade · autenticação PRÓPRIA (LMS independente do ERP).
   Base de alunos no banco SEPARADO. Idempotente.
   Rodar: php migrations/migration_190_universidade_usuarios.php
   ============================================================ */

$cfg = require __DIR__ . '/../config/database_uni.php';
echo "== migration 190: Universidade — usuários (LMS) ==\n";

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['dbname'], $cfg['charset']),
    $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$ddl = <<<SQL
CREATE TABLE IF NOT EXISTS uni_usuario (
  id                 bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id          bigint(20) unsigned DEFAULT NULL COMMENT 'organização (opcional) — refs lógica',
  nome               varchar(160) NOT NULL,
  email              varchar(190) NOT NULL,
  senha_hash         varchar(255) NOT NULL COMMENT 'password_hash bcrypt',
  perfil             varchar(40) NOT NULL DEFAULT 'operador' COMMENT 'papel p/ trilhas (não é RBAC do ERP)',
  ativo              tinyint(1) NOT NULL DEFAULT 1,
  email_verificado_em timestamp NULL DEFAULT NULL,
  tentativas_falhas  smallint unsigned NOT NULL DEFAULT 0,
  bloqueado_ate      datetime DEFAULT NULL,
  reset_token_hash   char(64) DEFAULT NULL,
  reset_expira_em    datetime DEFAULT NULL,
  ultimo_login_em    timestamp NULL DEFAULT NULL,
  created_at         timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uni_usuario_email (email),
  KEY idx_uni_usuario_tenant (tenant_id),
  KEY idx_uni_usuario_reset (reset_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$existia = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='uni_usuario'")->fetchColumn() > 0;
$pdo->exec($ddl);
echo $existia ? "  = uni_usuario já existia\n" : "  + uni_usuario criada\n";
echo "== 190 concluída ==\n";
