<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_208_paletizacao.php
   Paletização: a uva é comercializada por PALETE
   (~110 caixas), e paletes não se misturam (um palete por categoria).

   P-07: unidade de comercialização (caixa/palete/cumbuca) + fator
   configurável "caixas por palete" no PRODUTO ACABADO (ph_skus).

   P-07(b)/P-08: apontamento MANUAL de quantidade por classificação no
   ROMANEIO de colheita (colheita_cargas): unidade + quantidade, com o
   fator de conversão por carga, mantendo o peso (kg) como está.

   Idempotente. Rodar: php migrations/migration_208_paletizacao.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 208: paletização ==\n";

/** Adiciona a coluna se ela ainda não existir. */
function col_add(PDO $pdo, string $tabela, string $col, string $ddl): void
{
    $existe = false;
    foreach ($pdo->query("SHOW COLUMNS FROM `{$tabela}`") as $r) {
        if ($r['Field'] === $col) { $existe = true; break; }
    }
    if ($existe) {
        echo "  = {$tabela}.{$col} já existe\n";
    } else {
        $pdo->exec("ALTER TABLE `{$tabela}` ADD COLUMN {$ddl}");
        echo "  + {$tabela}.{$col} adicionada\n";
    }
}

/* ── ph_skus (produto acabado) ──────────────────────────────── */
/* unidade de comercialização: caixa | palete | cumbuca (VARCHAR + whitelist em PHP) */
col_add($pdo, 'ph_skus', 'unidade_comercial',
    "unidade_comercial VARCHAR(20) NULL DEFAULT NULL AFTER embalagem_id");
/* fator configurável de conversão caixa→palete (default 110) */
col_add($pdo, 'ph_skus', 'caixas_por_palete',
    "caixas_por_palete INT NULL DEFAULT 110 AFTER camadas_por_pallet");

/* ── colheita_cargas (romaneio) ─────────────────────────────── */
/* unidade do apontamento manual por classificação: caixa | palete | cumbuca */
col_add($pdo, 'colheita_cargas', 'unidade_apont',
    "unidade_apont VARCHAR(20) NULL DEFAULT NULL AFTER classificacao");
/* quantidade apontada nessa unidade (por classificação) */
col_add($pdo, 'colheita_cargas', 'qtd_apont',
    "qtd_apont DECIMAL(14,3) NULL DEFAULT NULL AFTER unidade_apont");
/* fator caixas→palete usado na conversão desta carga (default 110) */
col_add($pdo, 'colheita_cargas', 'caixas_por_palete',
    "caixas_por_palete INT NULL DEFAULT 110 AFTER qtd_apont");

echo "== 208 concluída ==\n";
