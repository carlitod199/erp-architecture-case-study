<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_204_funcao_packing.php
   "Função" no packing: classifica a pessoa como colhedor, embalador ou ambos,
   para o posto unificado (Colheita + Embalamento na mesma tela) rotear a leitura
   pelo papel — colhedor conta colheita (com válvula), embalador conta embalamento
   (talhao_id NULL → custo de packing). Campo estruturado porque agro_operadores.funcao
   é texto livre (Podador, Diarista de Colheita, Tratorista…) e não distingue com
   segurança. Semeia colhedor a partir da função atual (contém "colh"); os demais
   ficam NULL para o gestor atribuir. Idempotente.
   Rodar: php migrations/migration_204_funcao_packing.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 204: funcao_packing (colhedor/embalador/ambos) ==\n";

$temColuna = static function (PDO $pdo, string $tab, string $col): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $st->execute([':t' => $tab, ':c' => $col]);
    return (int)$st->fetchColumn() > 0;
};

foreach (['agro_operadores' => 'funcao', 'rh_terceirizados' => null] as $tab => $depois) {
    if ($temColuna($pdo, $tab, 'funcao_packing')) {
        echo "  = {$tab}.funcao_packing já existe\n";
        continue;
    }
    $after = ($depois && $temColuna($pdo, $tab, $depois)) ? " AFTER {$depois}" : '';
    $pdo->exec("ALTER TABLE {$tab}
        ADD COLUMN funcao_packing ENUM('colhedor','embalador','ambos') NULL{$after}");
    echo "  + {$tab}.funcao_packing criada\n";

    // Semeia colhedor onde a função atual indica colheita (só agro_operadores tem funcao).
    if ($temColuna($pdo, $tab, 'funcao')) {
        $n = $pdo->exec("UPDATE {$tab}
            SET funcao_packing = 'colhedor'
            WHERE funcao_packing IS NULL AND funcao IS NOT NULL AND LOWER(funcao) LIKE '%colh%'");
        echo "    ~ {$tab}: {$n} pessoa(s) semeada(s) como colhedor pela função\n";
    }
}

echo "== 204 concluída ==\n";
