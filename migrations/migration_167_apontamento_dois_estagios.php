<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_167_apontamento_dois_estagios.php  (gestor 17/07)
   Apontamento em DOIS ESTÁGIOS: "Iniciar" (grava cabeçalho + gera OS) e
   "Finalizar" (registra pessoas/produção reais + custeio). Schema:
     - status: novo valor 'iniciado' (enum) — estágio 1 aguardando finalização.
     - iniciado_em / finalizado_em (DATETIME) + finalizado_por (auditoria).
   ordem_servico_id (já existe, nullable) recebe a OS gerada no "Iniciar".
   Aditivo/idempotente, NO DROP. Rodar: php migrations/migration_167_apontamento_dois_estagios.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 167: apontamento em 2 estágios ==\n";

/* 1) status: adiciona 'iniciado' ao enum (preserva os existentes) */
$tipo = (string)$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_apontamentos' AND COLUMN_NAME='status'")->fetchColumn();
if (strpos($tipo, "'iniciado'") === false) {
    $pdo->exec("ALTER TABLE agro_apontamentos
                MODIFY COLUMN status ENUM('iniciado','pendente','validado','recusado') NOT NULL DEFAULT 'pendente'");
    echo "  ok status += 'iniciado'\n";
} else {
    echo "  - status já tem 'iniciado'\n";
}

/* 2) colunas de auditoria dos estágios */
$col = static function (string $name): string {
    return "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_apontamentos' AND COLUMN_NAME='$name'";
};
foreach ([
    'iniciado_em'    => "ADD COLUMN iniciado_em DATETIME NULL AFTER status",
    'finalizado_em'  => "ADD COLUMN finalizado_em DATETIME NULL AFTER iniciado_em",
    'finalizado_por' => "ADD COLUMN finalizado_por BIGINT UNSIGNED NULL AFTER finalizado_em",
] as $name => $ddl) {
    if (!(int)$pdo->query($col($name))->fetchColumn()) {
        $pdo->exec("ALTER TABLE agro_apontamentos $ddl");
        echo "  ok $name\n";
    } else {
        echo "  - $name já existe\n";
    }
}

echo "== 167 concluída ==\n";
