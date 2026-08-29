<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_154_fiscal_config.php  (SEFAZ F-SEFAZ-1)
   Config fiscal por tenant p/ a integração SEFAZ (sped-nfe). Guarda os
   parâmetros de emissão; o CERTIFICADO fica em storage/certs (fora do git)
   e a SENHA do certificado em config seguro (config/fiscal_secrets.php,
   gitignored) — NUNCA em texto no banco (CSO).
   Ambiente default = 2 (HOMOLOGAÇÃO). Aditivo, idempotente, auditoria completa.
   Rodar: php migrations/migration_154_fiscal_config.php
   ============================================================ */

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 154: fiscal_config (SEFAZ) ==\n";

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_config (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  ambiente TINYINT NOT NULL DEFAULT 2,            -- 1=produção, 2=homologação
  tipo_pessoa VARCHAR(2) NULL,                    -- PF | PJ
  razao_social VARCHAR(150) NULL,
  cnpj_cpf VARCHAR(14) NULL,
  ie VARCHAR(20) NULL,                            -- Inscrição Estadual
  im VARCHAR(20) NULL,                            -- Inscrição Municipal
  crt TINYINT NULL,                               -- Regime: 1=Simples,2=Simples excesso,3=Normal
  cnae VARCHAR(10) NULL,
  uf VARCHAR(2) NULL,
  cod_municipio VARCHAR(7) NULL,                  -- código IBGE
  serie_nfe SMALLINT UNSIGNED NULL DEFAULT 1,
  proximo_numero_nfe BIGINT UNSIGNED NOT NULL DEFAULT 1,
  csc VARCHAR(64) NULL,                           -- NFC-e (se aplicável)
  id_csc VARCHAR(10) NULL,
  cert_arquivo VARCHAR(190) NULL,                 -- nome do .pfx em storage/certs (NÃO o conteúdo)
  cert_validade DATE NULL,
  email_contingencia VARCHAR(150) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fiscal_config_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok fiscal_config\n";

/* linha default por tenant (ambiente homologação) — get-or-create idempotente */
$tenants = array_map('intval', $pdo->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN));
$has = $pdo->prepare("SELECT 1 FROM fiscal_config WHERE tenant_id=:t");
$ins = $pdo->prepare("INSERT INTO fiscal_config (tenant_id, ambiente, ativo) VALUES (:t, 2, 1)");
$n = 0;
foreach ($tenants as $tid) {
    $has->execute([':t' => $tid]);
    if (!$has->fetchColumn()) { $ins->execute([':t' => $tid]); $n++; }
}
echo "  linhas default criadas (homologação): {$n} (para " . count($tenants) . " tenant(s))\n";
echo "== 154 concluída ==\n";
