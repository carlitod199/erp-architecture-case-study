<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_148_ia_aprendizados.php
   Memória do assistente de IA: aprendizados que ele salva ao
   estudar o código-fonte/processos, reinjetados nas próximas
   perguntas (auto-ensino). Idempotente.
   Rodar: php migrations/migration_148_ia_aprendizados.php
   ============================================================ */

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'],
    $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "== migration 148: memória de aprendizados da IA ==\n";

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ia_aprendizados (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    tema VARCHAR(120) NOT NULL,
    conteudo TEXT NOT NULL,
    criado_por BIGINT UNSIGNED NULL,
    usos INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ia_apr_tenant_tema (tenant_id, tema)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok ia_aprendizados\n";
echo "== migration 148 concluída ==\n";
