<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_153_folha_onda4.php  (ONDA 4, spec A3 + fix da 151)
   (A) FIX da migration 151 (comercial): tabelas nasceram sem
       created_by/updated_by/updated_at → vero_insert/vero_update QUEBRAM
       (sempre injetam created_by+updated_by). Adiciona as colunas faltantes.
   (B) M1 — campos eSocial em agro_operadores (NIS/CBO/admissão/categoria/
       jornada/dependentes) → export eSocial + IRRF com dependentes reais.
   (C) M2 — descontos do empregado + líquido em rh_folha_lancamentos
       (INSS/IRRF/faltas/adiantamento/EPI/total/líquido) → congela no fechamento.
   M3 (rubricas flexíveis) fica p/ quando o cliente pedir (não bloqueia).
   Compliance: alíquotas/tabelas seguem como REFERÊNCIA EDITÁVEL (tenant_parametros),
   nunca fixas no código (Regra 1). Aditivo, idempotente.
   Rodar: php migrations/migration_153_folha_onda4.php
   ============================================================ */

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$db = $config['dbname'];
echo "== migration 153: folha ONDA 4 + fix auditoria da 151 ==\n";

$col = static function (PDO $pdo, string $db, string $tab, string $c): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:d AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute([':d' => $db, ':t' => $tab, ':c' => $c]); return (bool)$q->fetchColumn();
};
$add = static function (PDO $pdo, string $db, string $tab, string $c, string $ddl) use ($col): void {
    if (!$col($pdo, $db, $tab, $c)) { $pdo->exec("ALTER TABLE {$tab} ADD COLUMN {$ddl}"); echo "  + {$tab}.{$c}\n"; }
    else { echo "  = {$tab}.{$c} já existe\n"; }
};

/* (A) FIX 151 — colunas de auditoria faltantes */
$AUD_BY = "BIGINT UNSIGNED NULL";
$AUD_AT = "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
$add($pdo, $db, 'comercial_canais',         'created_by', "created_by {$AUD_BY}");
$add($pdo, $db, 'comercial_canais',         'updated_by', "updated_by {$AUD_BY}");
$add($pdo, $db, 'comercial_tipos_despesa',  'created_by', "created_by {$AUD_BY}");
$add($pdo, $db, 'comercial_tipos_despesa',  'updated_by', "updated_by {$AUD_BY}");
$add($pdo, $db, 'comercial_venda_despesas', 'updated_by', "updated_by {$AUD_BY}");
$add($pdo, $db, 'comercial_venda_despesas', 'updated_at', "updated_at {$AUD_AT}");
$add($pdo, $db, 'comercial_tabela_precos',  'updated_by', "updated_by {$AUD_BY}");
$add($pdo, $db, 'comercial_venda_pesos',    'updated_by', "updated_by {$AUD_BY}");
$add($pdo, $db, 'comercial_venda_pesos',    'updated_at', "updated_at {$AUD_AT}");

/* (B) M1 — eSocial no trabalhador */
$add($pdo, $db, 'agro_operadores', 'nis_pis',           "nis_pis VARCHAR(20) NULL");
$add($pdo, $db, 'agro_operadores', 'cbo',               "cbo VARCHAR(10) NULL");
$add($pdo, $db, 'agro_operadores', 'data_admissao',     "data_admissao DATE NULL");
$add($pdo, $db, 'agro_operadores', 'categoria_esocial', "categoria_esocial VARCHAR(10) NULL");
$add($pdo, $db, 'agro_operadores', 'jornada_mensal_h',  "jornada_mensal_h DECIMAL(6,2) NULL");
$add($pdo, $db, 'agro_operadores', 'dependentes',       "dependentes TINYINT UNSIGNED NOT NULL DEFAULT 0");

/* (C) M2 — descontos + líquido na folha */
foreach (['desc_inss', 'desc_irrf', 'desc_faltas', 'desc_adiantamento', 'desc_epi', 'total_descontos', 'liquido'] as $c) {
    $add($pdo, $db, 'rh_folha_lancamentos', $c, "{$c} DECIMAL(18,2) NOT NULL DEFAULT 0");
}

echo "== 153 concluída ==\n";
