<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_157_fenologia_variedade.php  (ajustes 15/07 · itens 3c/3d/4)
   Fenologia POR VARIEDADE, versionada (decisões do gestor 15/07):
     - dia 0 = PODA; duração por fase (dia_inicio/dia_fim); volume em MM/DIA.
     - fases contíguas (validação em PHP na tela).
     - APROVAÇÃO por variedade → congela; alteração = NOVA VERSÃO (histórico).
     - hierarquia de uso: variedade > cultura (fallback) > bloqueia safra.
   Duas tabelas:
     agro_variedade_fenologia  (cabeçalho/versão: rascunho|aprovada)
     agro_variedade_fases      (fases da versão)
   NÃO dropa agro_fenologia_estagios / irrigacao_fase_parametros (fallback).
   Aditivo, idempotente. Rodar: php migrations/migration_157_fenologia_variedade.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 157: fenologia por variedade (versionada) ==\n";

/* cabeçalho de versão da fenologia da variedade */
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agro_variedade_fenologia (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  variedade_id BIGINT UNSIGNED NOT NULL,
  versao INT NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'rascunho',   -- rascunho | aprovada  (validado em PHP)
  aprovado_por BIGINT UNSIGNED NULL,
  aprovado_em DATETIME NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vfen_tenant_var_versao (tenant_id, variedade_id, versao),
  KEY idx_vfen_var_status (tenant_id, variedade_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok agro_variedade_fenologia (cabecalho/versao)\n";

/* fases da versão (dia 0 = poda; volume mm/dia) */
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agro_variedade_fases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  fenologia_id BIGINT UNSIGNED NOT NULL,            -- FK -> agro_variedade_fenologia.id
  ordem INT NOT NULL DEFAULT 1,
  nome VARCHAR(80) NOT NULL,
  dia_inicio INT NOT NULL,                          -- dias desde a PODA (dia 0)
  dia_fim INT NOT NULL,
  volume_mm_dia DECIMAL(8,2) NULL,                  -- lâmina diária em mm/dia (canônica)
  observacao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vfases_fen (tenant_id, fenologia_id, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok agro_variedade_fases (fases da versao)\n";

echo "  (fallback preservado: agro_fenologia_estagios e irrigacao_fase_parametros intactas)\n";
echo "== 157 concluída ==\n";
