<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_151_venda_estrutura.php  (ONDA 3, P-111..116)
   Estrutura da venda estruturada (contrato A0; telas = A3). Prioridade
   F1 (despesas → margem real) + F2 (tabela de preços). Categóricos como
   VARCHAR (padrão VERO MySQL 5.7; validação em PHP). Aditivo/idempotente.
     - comercial_canais            (cadastro parametrizável + seed 6)
     - comercial_tipos_despesa     (cadastro parametrizável + seed 5)
     - comercial_venda_despesas    (F1: despesas individualizadas por venda)
     - comercial_tabela_precos     (F2: preço multidimensional + vigência)
     - comercial_venda_pesos       (controle de peso: 7 etapas colhido→pago)
     - ALTER comercial_vendas      (canal_id, fiscal_documento_id)
     - ALTER comercial_logistica   (tipo_frete, cte_numero)
   CSO: despesas entram no razão INSERT-only (as telas gravam; aqui só o schema).
   Rodar: php migrations/migration_151_venda_estrutura.php
   ============================================================ */

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$db = $config['dbname'];
echo "== migration 151: estrutura da venda (P-111..116) ==\n";

$colExiste = static function (PDO $pdo, string $db, string $tab, string $col): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute([':d' => $db, ':t' => $tab, ':c' => $col]); return (bool)$q->fetchColumn();
};

/* ── 1. comercial_canais (cadastro) ── */
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS comercial_canais (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  nome VARCHAR(80) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_canal (tenant_id, nome),
  KEY ix_canal_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok comercial_canais\n";

/* ── 2. comercial_tipos_despesa (cadastro) ── base: percentual|valor|por_unidade ── */
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS comercial_tipos_despesa (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  nome VARCHAR(80) NOT NULL,
  base VARCHAR(20) NOT NULL DEFAULT 'valor',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tipo_desp (tenant_id, nome),
  KEY ix_tipo_desp_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok comercial_tipos_despesa\n";

/* ── 3. comercial_venda_despesas (F1) ── INSERT-only no espírito do razão ── */
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS comercial_venda_despesas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  venda_id BIGINT UNSIGNED NOT NULL,
  tipo_despesa_id BIGINT UNSIGNED NULL,
  descricao VARCHAR(120) NULL,
  base VARCHAR(20) NOT NULL DEFAULT 'valor',
  percentual DECIMAL(9,4) NULL,
  valor DECIMAL(18,2) NOT NULL DEFAULT 0,
  observacao VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_vd_venda (tenant_id, venda_id),
  KEY ix_vd_tipo (tipo_despesa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok comercial_venda_despesas\n";

/* ── 4. comercial_tabela_precos (F2 multidimensional) ── dims nullable = "qualquer" ── */
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS comercial_tabela_precos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  cultura_id BIGINT UNSIGNED NULL,
  variedade_id BIGINT UNSIGNED NULL,
  calibre VARCHAR(40) NULL,
  embalagem VARCHAR(40) NULL,
  comprador_id BIGINT UNSIGNED NULL,
  canal_id BIGINT UNSIGNED NULL,
  safra_id BIGINT UNSIGNED NULL,
  preco DECIMAL(18,4) NOT NULL,
  moeda VARCHAR(3) NOT NULL DEFAULT 'BRL',
  vigencia_inicio DATE NOT NULL,
  vigencia_fim DATE NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_preco_lookup (tenant_id, cultura_id, variedade_id, canal_id, safra_id, ativo),
  KEY ix_preco_vigencia (tenant_id, vigencia_inicio, vigencia_fim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok comercial_tabela_precos\n";

/* ── 5. comercial_venda_pesos (controle de peso: 7 etapas) ── etapa validada em PHP ── */
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS comercial_venda_pesos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  venda_id BIGINT UNSIGNED NOT NULL,
  etapa VARCHAR(20) NOT NULL,
  peso_kg DECIMAL(18,3) NOT NULL DEFAULT 0,
  data_evento DATE NULL,
  observacao VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_peso_venda (tenant_id, venda_id, etapa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
echo "  ok comercial_venda_pesos\n";

/* ── 6. ALTER comercial_vendas: canal_id + fiscal_documento_id ── */
if (!$colExiste($pdo, $db, 'comercial_vendas', 'canal_id')) {
    $pdo->exec("ALTER TABLE comercial_vendas ADD COLUMN canal_id BIGINT UNSIGNED NULL AFTER comprador_id");
    echo "  + comercial_vendas.canal_id\n";
} else { echo "  = comercial_vendas.canal_id já existe\n"; }
if (!$colExiste($pdo, $db, 'comercial_vendas', 'fiscal_documento_id')) {
    $pdo->exec("ALTER TABLE comercial_vendas ADD COLUMN fiscal_documento_id BIGINT UNSIGNED NULL AFTER movimentacao_id");
    echo "  + comercial_vendas.fiscal_documento_id\n";
} else { echo "  = comercial_vendas.fiscal_documento_id já existe\n"; }

/* ── 7. ALTER comercial_logistica: tipo_frete + cte_numero ── */
if (!$colExiste($pdo, $db, 'comercial_logistica', 'tipo_frete')) {
    $pdo->exec("ALTER TABLE comercial_logistica ADD COLUMN tipo_frete VARCHAR(20) NULL AFTER venda_id");
    echo "  + comercial_logistica.tipo_frete\n";
} else { echo "  = comercial_logistica.tipo_frete já existe\n"; }
if (!$colExiste($pdo, $db, 'comercial_logistica', 'cte_numero')) {
    $pdo->exec("ALTER TABLE comercial_logistica ADD COLUMN cte_numero VARCHAR(50) NULL AFTER frete");
    echo "  + comercial_logistica.cte_numero\n";
} else { echo "  = comercial_logistica.cte_numero já existe\n"; }

/* ── Seeds dos cadastros (idempotentes) para o(s) tenant(s) existentes ── */
$tenants = array_map('intval', $pdo->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN));
$canais = ['Interno', 'Exportação', 'Cooperativa', 'Trading', 'Indústria', 'Venda Direta'];
$tiposDesp = [['Frete', 'valor'], ['Comissão', 'percentual'], ['Embalagem', 'por_unidade'], ['Imposto', 'percentual'], ['Taxa', 'valor']];
$insCanal = $pdo->prepare("INSERT IGNORE INTO comercial_canais (tenant_id, nome) VALUES (:t,:n)");
$insTipo  = $pdo->prepare("INSERT IGNORE INTO comercial_tipos_despesa (tenant_id, nome, base) VALUES (:t,:n,:b)");
$nc = 0; $nt = 0;
foreach ($tenants as $tid) {
    foreach ($canais as $c) { $insCanal->execute([':t' => $tid, ':n' => $c]); $nc += $insCanal->rowCount(); }
    foreach ($tiposDesp as [$n, $b]) { $insTipo->execute([':t' => $tid, ':n' => $n, ':b' => $b]); $nt += $insTipo->rowCount(); }
}
echo "  seeds: {$nc} canais, {$nt} tipos de despesa (para " . count($tenants) . " tenant(s))\n";
echo "== 151 concluída ==\n";
