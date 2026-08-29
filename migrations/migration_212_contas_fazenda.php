<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_212_contas_fazenda.php
   Pedido do gestor: campo para selecionar a FAZENDA no contas a
   pagar/receber. Pagar e receber vivem na MESMA tabela
   (movimentacoes_financeiras, coluna tipo), então basta uma coluna:
     - movimentacoes_financeiras.fazenda_id (BIGINT UNSIGNED NULL —
       mesmo tipo dos irmãos safra_id/talhao_id; NULL = sem fazenda,
       campo opcional e FORA do hash do razão, como os demais de
       classificação G-10);
     - índice idx_mf_fazenda (o filtro da tela consulta por ela).
   SEM foreign key: o padrão da tabela é validar tenant/vínculo na
   aplicação (safra_id/talhao_id/centro_custo_id não têm FK; a única
   FK é a self-FK fk_mf_substituida).
   Idempotente. Rodar: php migrations/migration_212_contas_fazenda.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 212: fazenda no contas a pagar/receber ==\n";

/* 1) coluna fazenda_id */
$temCol = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimentacoes_financeiras'
        AND COLUMN_NAME = 'fazenda_id'")->fetchColumn();
if ($temCol) {
    echo "  = movimentacoes_financeiras.fazenda_id já existe\n";
} else {
    $pdo->exec("ALTER TABLE movimentacoes_financeiras
        ADD COLUMN fazenda_id BIGINT UNSIGNED NULL DEFAULT NULL
            COMMENT 'agro_fazendas.id — classificação opcional, fora do hash'
        AFTER talhao_id");
    echo "  + movimentacoes_financeiras.fazenda_id adicionada\n";
}

/* 2) índice para o filtro da listagem */
$temIdx = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimentacoes_financeiras'
        AND INDEX_NAME = 'idx_mf_fazenda'")->fetchColumn();
if ($temIdx) {
    echo "  = idx_mf_fazenda já existe\n";
} else {
    $pdo->exec("ALTER TABLE movimentacoes_financeiras ADD INDEX idx_mf_fazenda (fazenda_id)");
    echo "  + idx_mf_fazenda criado\n";
}

echo "== 212 concluída ==\n";
