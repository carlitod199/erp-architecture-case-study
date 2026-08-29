<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_180_tenants_cadastro_completo.php
   #48: cadastro da empresa/fazenda mais completo (configuracoes/
   empresa_fazenda.php). A tela ja grava schema-aware — estas colunas
   fazem os campos fiscais/endereco/sede PERSISTIREM.
   Aditivo/idempotente, NO DROP. Rodar: php migrations/migration_180_tenants_cadastro_completo.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 180: colunas de cadastro completo em tenants ==\n";

$cols = [
    'razao_social'       => "VARCHAR(200) NULL",
    'cnpj'               => "VARCHAR(18) NULL COMMENT 'so digitos (14)'",
    'inscricao_estadual' => "VARCHAR(20) NULL",
    'endereco'           => "VARCHAR(240) NULL",
    'municipio'          => "VARCHAR(120) NULL",
    'uf'                 => "CHAR(2) NULL",
    'cep'                => "VARCHAR(9) NULL",
    'fazenda_sede_id'    => "BIGINT UNSIGNED NULL COMMENT 'fazenda-sede do grupo'",
];
foreach ($cols as $nome => $def) {
    $existe = (string)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='{$nome}'")->fetchColumn();
    if ($existe === '0') {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN `{$nome}` {$def}");
        echo "  ok coluna {$nome} criada\n";
    } else {
        echo "  - coluna {$nome} ja existe\n";
    }
}
echo "== 180 concluida ==\n";
