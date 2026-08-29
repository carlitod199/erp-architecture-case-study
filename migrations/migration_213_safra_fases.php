<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_213_safra_fases.php  (módulo MANGA · M-01 da reunião 17/08)
   Fenologia "VIVA" da safra: instância EDITÁVEL das fases por vínculo
   safra×válvula, materializada do template da variedade (mig 157) e
   ajustável/adiantável pelo RT — porque a manga muda a cada florada
   (Tommer 240d · Palmer 270d · Keitt 260d · Kent 280d).
   NÃO afeta a uva: o resolver só usa esta camada quando existem linhas
   (data-driven, sem flag por cultura); safra sem instância = comportamento
   idêntico ao atual (template por dias desde a poda > períodos > manual).
     - agro_safra_fases: fases com DATAS reais (origem template|ajuste,
       motivo + trilha de quem ajustou). variedade_fase_id aponta o
       template (fonte de volume_mm_dia e do id gravado nos apontamentos).
     - agro_culturas.marco_dia_zero: rótulo do dia 0 por cultura
       (uva = poda de produção · manga = poda de PÓS-colheita) — só exibição.
   Aditivo, idempotente. Rodar: php migrations/migration_213_safra_fases.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 213: fases da safra (fenologia viva — manga) ==\n";

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS agro_safra_fases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  safra_id BIGINT UNSIGNED NOT NULL,
  safra_talhao_id BIGINT UNSIGNED NOT NULL,      -- vínculo safra×válvula (agro_safra_talhoes.id)
  variedade_fase_id BIGINT UNSIGNED NOT NULL,    -- fase do TEMPLATE (agro_variedade_fases.id)
  ordem INT NOT NULL DEFAULT 1,
  nome VARCHAR(80) NOT NULL,                     -- cópia do nome na materialização (exibição)
  data_inicio DATE NOT NULL,
  data_fim DATE NOT NULL,
  origem VARCHAR(20) NOT NULL DEFAULT 'template',-- template | ajuste  (validado em PHP)
  motivo VARCHAR(255) NULL,                      -- por que adiantou/alterou (trilha do RT)
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sfase_vinculo_ordem (tenant_id, safra_talhao_id, ordem),
  KEY idx_sfase_lookup (tenant_id, safra_talhao_id, data_inicio, data_fim),
  KEY idx_sfase_safra (tenant_id, safra_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok agro_safra_fases (instancia editavel por safra x valvula)\n";

/* agro_culturas.marco_dia_zero — rótulo do marco (idempotente via information_schema) */
$tem = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_culturas' AND COLUMN_NAME = 'marco_dia_zero'"
)->fetchColumn();
if ((int)$tem === 0) {
    $pdo->exec("ALTER TABLE agro_culturas ADD COLUMN marco_dia_zero VARCHAR(60) NULL AFTER nome");
    echo "  ok agro_culturas.marco_dia_zero (rotulo do dia 0 por cultura)\n";
} else {
    echo "  ja existia agro_culturas.marco_dia_zero\n";
}

echo "== 213 concluída ==\n";
