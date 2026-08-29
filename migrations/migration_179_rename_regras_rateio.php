<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_179_rename_regras_rateio.php
   Varredura de jargao: remove a tag interna
   (P-98)/(P-125) do NOME das regras de rateio — esse nome e' texto
   VISIVEL na tabela de regras em custeio/rateios.php. O codigo casa
   as regras por nome (get-or-create em _atribuicao_sem_safra.php e
   _rateio_combustivel.php); os literais no codigo foram atualizados
   na mesma mudanca, entao esta migration renomeia as linhas ja
   existentes para o codigo continuar encontrando-as (sem duplicar).
   Idempotente, NO DROP. Rodar: php migrations/migration_179_rename_regras_rateio.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 179: rename das regras de rateio (remove tag interna do nome) ==\n";

$renames = [
    'Atribuição sem safra (P-98)'             => 'Atribuição sem safra',
    'Rateio de combustível por horas (P-125)' => 'Rateio de combustível por horas',
];

$st = $pdo->prepare("UPDATE custeio_rateios SET nome = :novo WHERE nome = :velho");
foreach ($renames as $velho => $novo) {
    /* seguranca anti-colisao: se ja existe uma regra com o nome NOVO no mesmo
       tenant, nao renomeia (evitaria violar futura unicidade/duplicar rotulo). */
    $colide = $pdo->prepare(
        "SELECT COUNT(*) FROM custeio_rateios a
          WHERE a.nome = :velho
            AND EXISTS (SELECT 1 FROM custeio_rateios b
                         WHERE b.tenant_id = a.tenant_id AND b.nome = :novo)");
    $colide->execute([':velho' => $velho, ':novo' => $novo]);
    if ((int)$colide->fetchColumn() > 0) {
        echo "  ! '{$velho}' NAO renomeada — ja existe '{$novo}' no mesmo tenant\n";
        continue;
    }
    $st->execute([':novo' => $novo, ':velho' => $velho]);
    echo "  ok {$st->rowCount()} linha(s): '{$velho}' -> '{$novo}'\n";
}

echo "== 179 concluida ==\n";
