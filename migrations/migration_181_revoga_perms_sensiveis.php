<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_181_revoga_perms_sensiveis.php   (#49)
   Revoga exposições sensíveis achadas por tests/permissoes_matriz.php
   (decisão do usuário 22/07):
   - rt_gerente NÃO vê folha nem custo de mão de obra (dado de salário);
   - operador e encarregado NÃO veem custo de irrigação (perfil de campo).
   Custos de máquina/irrigação do rt_gerente PERMANECEM (é gerente).
   Idempotente (DELETE por slug). Rodar: php migrations/migration_181_revoga_perms_sensiveis.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 181: revoga permissoes sensiveis ==\n";

/* role_slug => [perm bases a revogar (todas as acoes .ver/.editar/.excluir)] */
$revogar = [
    'rt_gerente'  => ['pessoas.folha', 'pessoas.custo_mao_obra'],
    'operador'    => ['irrigacao.custo_irrigacao'],
    'encarregado' => ['irrigacao.custo_irrigacao'],
];

$del = $pdo->prepare(
    "DELETE rp FROM role_permissions rp
       JOIN roles r ON r.id = rp.role_id
       JOIN permissions p ON p.id = rp.permission_id
      WHERE r.slug = :role AND p.slug = :perm");

$total = 0;
foreach ($revogar as $role => $bases) {
    foreach ($bases as $base) {
        foreach (['.ver', '.editar', '.excluir'] as $acao) {
            $del->execute([':role' => $role, ':perm' => $base . $acao]);
            $n = $del->rowCount();
            if ($n > 0) { echo "  - {$role}: revogado {$base}{$acao}\n"; $total += $n; }
        }
    }
}
echo "== 181 concluida ({$total} revogacao(oes)) ==\n";
