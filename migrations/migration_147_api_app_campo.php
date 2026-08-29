<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_147_api_app_campo.php
   Infra da API do app de campo (api/v1):
     - app_tokens: tokens opacos de dispositivo (P-APP-4, 30 dias)
     - app_idempotencia: escrita idempotente por client_uuid (D6)
     - agro_aplicacao_assinaturas: assinaturas dos operadores (GlobalG.A.P.)
   Idempotente (CREATE TABLE IF NOT EXISTS) — só cria, não altera nada.
   Rodar: php migrations/migration_147_api_app_campo.php
   ============================================================ */

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'],
    $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "== migration 147: API do app de campo ==\n";

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    dispositivo VARCHAR(190) NULL,
    expira_em DATETIME NOT NULL,
    revogado_em DATETIME NULL,
    ultimo_uso_em DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_tokens_hash (token_hash),
    KEY ix_app_tokens_usuario (usuario_id),
    KEY ix_app_tokens_expira (expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok app_tokens\n";

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_idempotencia (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    client_uuid VARCHAR(64) NOT NULL,
    recurso_tipo VARCHAR(60) NOT NULL,
    recurso_id BIGINT UNSIGNED NULL,
    resposta_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_idem_tenant_uuid (tenant_id, client_uuid),
    KEY ix_app_idem_recurso (recurso_tipo, recurso_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok app_idempotencia\n";

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agro_aplicacao_assinaturas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    aplicacao_id BIGINT UNSIGNED NOT NULL,
    operador_id BIGINT UNSIGNED NULL,
    operador_nome VARCHAR(150) NOT NULL,
    assinatura_svg MEDIUMTEXT NOT NULL,
    assinado_em DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_apl_assin_aplicacao (tenant_id, aplicacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok agro_aplicacao_assinaturas\n";

echo "== migration 147 concluída ==\n";
