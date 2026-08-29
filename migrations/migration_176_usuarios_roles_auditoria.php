<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_176_usuarios_roles_auditoria.php  (BUG1 QA 18/07)
   Configurações → Usuários: "+ Novo usuário" caía em "Erro interno":
   vero_insert()/vero_update() (includes/vero_crud.php) SEMPRE gravam
   created_by/updated_by, mas `usuarios` e `roles` (tabelas de auth
   anteriores à camada CRUD) não tinham essas colunas →
   SQLSTATE[42S22] Unknown column 'created_by'. Afetava também
   editar/inativar usuário e criar/editar perfil (perfis_acesso.php).
   Adiciona as colunas de auditoria no padrão das demais 133 tabelas
   (BIGINT UNSIGNED NULL). Aditivo, idempotente.
   Rodar: php migrations/migration_176_usuarios_roles_auditoria.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 176: auditoria (created_by/updated_by) em usuarios e roles ==\n";
foreach (['usuarios', 'roles'] as $tabela) {
    foreach (['created_by', 'updated_by'] as $coluna) {
        $existe = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$tabela}' AND COLUMN_NAME='{$coluna}'")->fetchColumn();
        if (!$existe) {
            $pdo->exec("ALTER TABLE {$tabela} ADD COLUMN {$coluna} BIGINT UNSIGNED NULL DEFAULT NULL");
            echo "  ok {$tabela}.{$coluna}\n";
        } else {
            echo "  - {$tabela}.{$coluna} já existe\n";
        }
    }
}
echo "== 176 concluída ==\n";
