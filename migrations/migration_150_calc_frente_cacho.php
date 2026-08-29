<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_150_calc_frente_cacho.php  (ONDA 2 — calculadora de MO)
   Prepara o schema da calculadora de mão de obra (A1-48c):
     1) agro_tipos_atividade.frente enum('masculino','feminino','mista') NULL
        — a taxonomia da fazenda (§9 da pesquisa) organiza MO por FRENTE;
          o dimensionamento é por frente.
     2) unidade_padrao += 'cacho' — Raleio/Dedinho/Seleção (as mais intensivas)
        são medidas por cacho, que faltava no enum.
     3) SEED de frente + unidade_padrao das 18 atividades (mapeamento §9;
        valores AMBÍGUOS ficam no default e são refinados pela P-91/RT).
   Os PARÂMETROS NUMÉRICOS (rendimento/diária, custo) NÃO entram aqui — vêm
   da P-91 (cliente/RT) para agro_calc_parametros. Aditivo e idempotente.
   Rodar: php migrations/migration_150_calc_frente_cacho.php
   ============================================================ */

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$db = $config['dbname'];
echo "== migration 150: calculadora — frente + unidade 'cacho' ==\n";

$colTipo = static function (PDO $pdo, string $db, string $col): ?string {
    $q = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=:d AND TABLE_NAME='agro_tipos_atividade' AND COLUMN_NAME=:c");
    $q->execute([':d' => $db, ':c' => $col]);
    $v = $q->fetchColumn();
    return $v === false ? null : (string)$v;
};

/* 1) coluna frente */
if ($colTipo($pdo, $db, 'frente') === null) {
    $pdo->exec("ALTER TABLE agro_tipos_atividade
                ADD COLUMN frente ENUM('masculino','feminino','mista') NULL AFTER categoria");
    echo "  + coluna frente\n";
} else {
    echo "  = coluna frente já existe\n";
}

/* 2) unidade_padrao += 'cacho' (só se ainda não tiver) */
$tipoUnidade = $colTipo($pdo, $db, 'unidade_padrao');
if ($tipoUnidade !== null && !str_contains($tipoUnidade, "'cacho'")) {
    $pdo->exec("ALTER TABLE agro_tipos_atividade
                MODIFY COLUMN unidade_padrao
                ENUM('planta','caixa','kg','ha','metro_linear','hora','cacho','outro')");
    echo "  + valor 'cacho' em unidade_padrao\n";
} else {
    echo "  = unidade_padrao já tem 'cacho'\n";
}

/* 3) seed frente + unidade por atividade (§9). Só preenche se estiver NULL/vazio,
      para não sobrescrever ajuste manual do gestor/RT. */
$map = [
    // nome                    => [frente,      unidade]
    'Poda'                     => ['masculino', 'planta'],
    'Desbrota'                 => ['masculino', 'planta'],
    'Amarrio'                  => ['masculino', 'planta'],   // unidade 'ramo' em aberto (P-91) → planta por ora
    'Repasse de amarrio'       => ['masculino', 'planta'],
    'Pré-desfolha'             => ['feminino',  'planta'],
    'Desfolha'                 => ['feminino',  'planta'],
    'Livramento'               => ['feminino',  'cacho'],
    'Seleção'                  => ['feminino',  'cacho'],
    'Dedinho'                  => ['feminino',  'cacho'],
    'Repasse de dedinho'       => ['feminino',  'cacho'],
    'Raleio'                   => ['feminino',  'cacho'],
    'Repasse de raleio'        => ['feminino',  'cacho'],
    'Limpeza'                  => ['feminino',  'planta'],
    'Colheita'                 => ['feminino',  'caixa'],
];
$upd = $pdo->prepare(
    "UPDATE agro_tipos_atividade
        SET frente = COALESCE(frente, :f),
            unidade_padrao = CASE WHEN unidade_padrao IS NULL OR unidade_padrao IN ('outro') THEN :u ELSE unidade_padrao END
      WHERE tenant_id = 1 AND nome = :n");
$n = 0;
foreach ($map as $nome => [$f, $u]) {
    $upd->execute([':f' => $f, ':u' => $u, ':n' => $nome]);
    $n += $upd->rowCount();
}
echo "  ~ frente/unidade semeadas (linhas afetadas: {$n})\n";
echo "== 150 concluída ==\n";
