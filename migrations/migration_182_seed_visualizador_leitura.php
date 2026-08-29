<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_182_seed_visualizador_leitura.php   (auditoria A5)
   O perfil "Visualizador" nascia com 0 permissões → qualquer usuário
   atribuído a ele caía em /403?motivo=sem_telas (perfil inutilizável).
   Decisão do usuário (22/07): tornar o Visualizador um perfil SOMENTE
   LEITURA funcional, semeando as permissões '.ver' dos módulos.

   Escopo de leitura: TODAS as permissões que terminam em '.ver', EXCETO
   um conjunto sensível (folha/salário, custo de mão de obra, DRE, fluxo
   de caixa, resultado de safra, custo de irrigação, e as telas admin de
   usuários/perfis) — mesma classificação de sensibilidade da migration
   181. Um "visualizador" genérico não deve ver folha/financeiro/admin.

   Aplica a TODOS os tenants que tenham a role slug='visualizador'.
   Idempotente (NOT EXISTS). Rodar: php migrations/migration_182_seed_visualizador_leitura.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 182: semeia leitura do Visualizador ==\n";

/* '.ver' sensíveis que o Visualizador NÃO recebe (dado de folha/financeiro/admin). */
$excluir = [
    'pessoas.folha.ver',
    'pessoas.custo_mao_obra.ver',
    'financeiro.dre_agro.ver',
    'financeiro.fluxo_caixa.ver',
    'custeio.resultado_safra.ver',
    'custos.resultado_safra.ver',
    'irrigacao.custo_irrigacao.ver',
    'configuracoes.usuarios.ver',
    'configuracoes.perfis_acesso.ver',
];
$ph = implode(',', array_fill(0, count($excluir), '?'));

$sql =
    "INSERT INTO role_permissions (role_id, permission_id)
     SELECT r.id, p.id
       FROM roles r
       JOIN permissions p
      WHERE r.slug = 'visualizador'
        AND p.slug LIKE '%.ver'
        AND p.slug NOT IN ($ph)
        AND NOT EXISTS (SELECT 1 FROM role_permissions rp
                         WHERE rp.role_id = r.id AND rp.permission_id = p.id)";
$stmt = $pdo->prepare($sql);
$stmt->execute($excluir);
$n = $stmt->rowCount();

echo "  - permissoes de leitura concedidas ao Visualizador: {$n}\n";
echo "  - '.ver' sensiveis mantidas de fora: " . count($excluir) . "\n";
echo "== 182 concluida ==\n";
