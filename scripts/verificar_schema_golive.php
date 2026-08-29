<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/verificar_schema_golive.php   (B3 · auditoria Go-Live)
   Detector de DRIFT de schema: confere se todas as colunas/tabelas
   adicionadas pelas migrations recentes (156–171) existem no banco.
   Como as migrations são .php avulsos aplicados à mão (sem tabela de
   rastreio), este script é a rede de segurança pós-deploy: rode-o
   contra o banco de PRODUÇÃO antes do Go-Live.

   Uso:  php scripts/verificar_schema_golive.php
   Saída: lista OK/FALTA por item; exit code 1 se houver qualquer falta.
   ============================================================ */

require __DIR__ . '/../includes/db.php';
$pdo = Database::getConnection();

/* tabela => [colunas esperadas]. '' (chave vazia) = só verificar a tabela. */
$esperado = [
    // Onda fenologia/poda (m156-159)
    'agro_porta_enxertos'      => [''],
    'agro_variedade_fenologia' => [''],
    'agro_variedade_fases'     => ['volume_calda_ha_l'],
    'agro_talhoes'             => ['porta_enxerto_id', 'estrutura_sistema'],
    'analise_faixas'           => ['porta_enxerto_id', 'variedade_fase_id'],
    'agro_variedades'          => ['cor_baga'],
    'agro_safra_talhoes'       => ['data_poda', 'poda_status', 'poda_confirmada_em', 'poda_confirmada_por'],
    'agro_bombas'              => ['potencia_kw'],
    // Lote 16-17/07 (m162-171)
    'agro_aplicacao_maquinas'  => [''],
    'mip_monitoramento_alvos'  => [''],
    'agro_aplicacoes'          => ['volume_calda_ha_l', 'condicao_ceu', 'variedade_fase_id', 'dias_desde_poda'],
    'agro_apontamentos'        => ['variedade_fase_id', 'dias_desde_poda', 'iniciado_em', 'finalizado_em', 'finalizado_por'],
    'mip_monitoramentos'       => ['local_infestacao'],
    'analise_foliar'           => ['variedade_fase_id', 'dias_desde_poda'],
    'estoque_produtos'         => ['nao_registrado'],
];

/* enums/modificações que valem conferir explicitamente */
$enums = [
    // tabela.coluna => valor que precisa existir no enum
    'agro_apontamentos.status' => 'iniciado',   // m167 (dois estágios)
];

function col_existe(PDO $pdo, string $t, string $c): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$t, $c]);
    return (bool)$q->fetchColumn();
}
function tab_existe(PDO $pdo, string $t): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $q->execute([$t]);
    return (bool)$q->fetchColumn();
}

$faltas = 0; $ok = 0;
echo "== VERO :: verificação de schema (Go-Live) ==\n";
echo "banco: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n\n";

foreach ($esperado as $tabela => $colunas) {
    if (!tab_existe($pdo, $tabela)) {
        echo "  [FALTA] tabela ausente: {$tabela}\n";
        $faltas++;
        continue;
    }
    foreach ($colunas as $col) {
        if ($col === '') { $ok++; continue; } // tabela ok, sem coluna específica
        if (col_existe($pdo, $tabela, $col)) {
            $ok++;
        } else {
            echo "  [FALTA] {$tabela}.{$col}\n";
            $faltas++;
        }
    }
}

foreach ($enums as $alvo => $valor) {
    [$t, $c] = explode('.', $alvo, 2);
    $q = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$t, $c]);
    $tipo = (string)$q->fetchColumn();
    if ($tipo === '' || strpos($tipo, "'{$valor}'") === false) {
        echo "  [FALTA] {$alvo} não contém '{$valor}' (tipo atual: " . ($tipo ?: 'coluna ausente') . ")\n";
        $faltas++;
    } else {
        $ok++;
    }
}

echo "\n" . str_repeat('-', 48) . "\n";
if ($faltas === 0) {
    echo "OK — schema alinhado às migrations ({$ok} itens verificados).\n";
    exit(0);
}
echo "DRIFT — {$faltas} item(ns) ausente(s), {$ok} ok. Rode as migrations pendentes antes do Go-Live.\n";
exit(1);
