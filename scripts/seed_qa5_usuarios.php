<?php
/**
 * seed_qa5_usuarios.php — cria/atualiza usuários de TESTE (qa5.*) para validar o app.
 *
 * Uso (no VPS/servidor01, a partir da raiz do projeto):
 *     php scripts/seed_qa5_usuarios.php            # tenant único ativo → automático
 *     php scripts/seed_qa5_usuarios.php <tenant_id> # escolher o tenant explicitamente
 *
 * Características:
 *   - Reusa a conexão do sistema (includes/db.php) → conecta no banco que ESTE ambiente
 *     estiver configurado (não cravamos host/DB).
 *   - Gera o hash da senha em runtime (bcrypt do PHP do próprio ambiente).
 *   - Idempotente: se o e-mail já existe, ATUALIZA senha/perfil/ativo; senão INSERE.
 *     (a coluna email NÃO é única, por isso checamos manualmente antes.)
 *   - Valida que o perfil pedido existe como role ATIVO no tenant (ou global);
 *     sem isso o usuário logaria com permissões de fallback.
 *   - Segurança: se houver MAIS DE UM tenant ativo e nenhum id for passado, ABORTA
 *     e lista as opções, para não semear na fazenda errada.
 *
 * Senha de todos: change_me
 */

require __DIR__ . '/../includes/db.php';

const SENHA = 'change_me';

$USUARIOS = [
    ['nome' => 'QA5-Gestor',     'email' => 'qa5.gestor@vero.test',     'perfil' => 'gestor'],
    ['nome' => 'QA5-Operador',   'email' => 'qa5.operador@vero.test',   'perfil' => 'operador'],
    ['nome' => 'QA5-RT Gerente', 'email' => 'qa5.rt@vero.test',         'perfil' => 'rt_gerente'],
    ['nome' => 'QA5-Financeiro', 'email' => 'qa5.financeiro@vero.test', 'perfil' => 'financeiro'],
];

$pdo = Database::getConnection();

/* ---- 1) escolher o tenant ---------------------------------------------- */
$tenants = $pdo->query("SELECT id, nome, ativo FROM tenants ORDER BY id")->fetchAll();
if (!$tenants) {
    fwrite(STDERR, "ERRO: nenhum tenant encontrado neste banco.\n");
    exit(1);
}
$ativos = array_values(array_filter($tenants, fn($t) => (int)$t['ativo'] === 1));

$argTenant = isset($argv[1]) ? (int)$argv[1] : null;

echo "Tenants neste ambiente:\n";
foreach ($tenants as $t) {
    echo "  [{$t['id']}] {$t['nome']}  (ativo={$t['ativo']})\n";
}
echo "\n";

if ($argTenant !== null) {
    $tenantId = $argTenant;
} elseif (count($ativos) === 1) {
    $tenantId = (int)$ativos[0]['id'];
} else {
    fwrite(STDERR, "ERRO: há " . count($ativos) . " tenants ativos. Escolha explicitamente:\n");
    fwrite(STDERR, "      php scripts/seed_qa5_usuarios.php <tenant_id>\n");
    exit(2);
}

$mapaTenant = [];
foreach ($tenants as $t) { $mapaTenant[(int)$t['id']] = $t['nome']; }
if (!isset($mapaTenant[$tenantId])) {
    fwrite(STDERR, "ERRO: tenant_id $tenantId não existe.\n");
    exit(2);
}
echo "==> Semeando no tenant_id=$tenantId (\"{$mapaTenant[$tenantId]}\").\n\n";

/* ---- 2) validar que os perfis existem como role ativo ------------------ */
$roleChk = $pdo->prepare(
    "SELECT COUNT(*) FROM roles
      WHERE slug = ? AND (tenant_id = ? OR tenant_id IS NULL) AND ativo = 1"
);
$perfisFaltando = [];
foreach ($USUARIOS as $u) {
    $roleChk->execute([$u['perfil'], $tenantId]);
    if ((int)$roleChk->fetchColumn() === 0) {
        $perfisFaltando[] = $u['perfil'];
    }
}
if ($perfisFaltando) {
    echo "AVISO: sem role ativo para: " . implode(', ', array_unique($perfisFaltando)) . "\n";
    echo "       Esses usuários entrariam com permissões de fallback. Considere rodar\n";
    echo "       scripts/seed_perfis_padrao.php antes. Prosseguindo mesmo assim.\n\n";
}

/* ---- 3) upsert idempotente --------------------------------------------- */
$hash = password_hash(SENHA, PASSWORD_DEFAULT);

$sel = $pdo->prepare("SELECT id, tenant_id FROM usuarios WHERE email = ? LIMIT 1");
$ins = $pdo->prepare(
    "INSERT INTO usuarios (tenant_id, nome, email, senha_hash, perfil, ativo, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())"
);
$upd = $pdo->prepare(
    "UPDATE usuarios SET senha_hash = ?, perfil = ?, nome = ?, ativo = 1, updated_at = NOW() WHERE id = ?"
);

$pdo->beginTransaction();
try {
    foreach ($USUARIOS as $u) {
        $sel->execute([$u['email']]);
        $row = $sel->fetch();
        if ($row) {
            $upd->execute([$hash, $u['perfil'], $u['nome'], $row['id']]);
            echo "  ~ atualizado  {$u['email']}  (id={$row['id']}, tenant={$row['tenant_id']}, perfil={$u['perfil']})\n";
        } else {
            $ins->execute([$tenantId, $u['nome'], $u['email'], $hash, $u['perfil']]);
            echo "  + criado      {$u['email']}  (id={$pdo->lastInsertId()}, tenant=$tenantId, perfil={$u['perfil']})\n";
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nERRO, rollback: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nOK. Senha de todos os usuários acima: " . SENHA . "\n";
echo "Login no app: e-mail + senha (o app resolve o tenant pelo usuário).\n";
