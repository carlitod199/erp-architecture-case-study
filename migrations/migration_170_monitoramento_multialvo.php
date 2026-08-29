<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_170_monitoramento_multialvo.php  (gestor 17/07 · reunião 8.x)
   MÚLTIPLOS alvos por monitoramento: os dados POR ALVO (nível, quantidade, local,
   severidade) migram para a junção mip_monitoramento_alvos. O cabeçalho
   (talhão/safra/ponto/data/observação) fica em mip_monitoramentos. Mantém
   mip_monitoramentos.alvo_id = 1º alvo (compat: alertas, apontamento, aplicações
   leem essa coluna). Aditivo/idempotente, NO DROP.
   Rodar: php migrations/migration_170_monitoramento_multialvo.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 170: monitoramento multi-alvo ==\n";

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mip_monitoramento_alvos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  monitoramento_id BIGINT UNSIGNED NOT NULL,
  alvo_id BIGINT UNSIGNED NOT NULL,
  nivel_infestacao DECIMAL(10,2) NULL,
  quantidade_encontrada DECIMAL(18,4) NULL,
  local_infestacao VARCHAR(20) NULL,
  severidade_qualitativa VARCHAR(10) NULL,
  observacao VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mon_alvo (tenant_id, monitoramento_id, alvo_id),
  KEY idx_mon (tenant_id, monitoramento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok mip_monitoramento_alvos\n";

/* migra o alvo único existente -> junção (idempotente pela UNIQUE) */
$n = $pdo->exec("INSERT IGNORE INTO mip_monitoramento_alvos
        (tenant_id, monitoramento_id, alvo_id, nivel_infestacao, quantidade_encontrada,
         local_infestacao, severidade_qualitativa, created_by)
    SELECT tenant_id, id, alvo_id, nivel_infestacao, quantidade_encontrada,
           local_infestacao, severidade_qualitativa, created_by
      FROM mip_monitoramentos");
echo "  ok migrou {$n} alvo(s) único(s) existente(s) para a junção\n";

echo "== 170 concluída ==\n";
