<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration_209_crm_demo_permissao.php
   CRM Agro (fase protótipo/demo, decisão do gestor 13/08):
   os macros crm_revenda/crm_corretor usam UMA permissão de
   acesso (crm.demo.ver) para todos os micros. Semeia a permissão
   e concede aos papéis de gestão (gestor/administrador/dono) de
   todos os tenants. super_admin tem bypass (não precisa de row).
   Idempotente. Rodar: php migrations/migration_209_crm_demo_permissao.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 209: permissão crm.demo.ver (CRM Agro demo) ==\n";

/* 1) permissão */
$permId = $pdo->query("SELECT id FROM permissions WHERE slug = 'crm.demo.ver'")->fetchColumn();
if ($permId) {
    echo "  = permissions.crm.demo.ver já existe (#{$permId})\n";
} else {
    $pdo->prepare("INSERT INTO permissions (slug, label, modulo) VALUES ('crm.demo.ver', 'CRM Agro (demo) — acessar', 'CRM Agro')")
        ->execute();
    $permId = (int)$pdo->lastInsertId();
    echo "  + permissions.crm.demo.ver criada (#{$permId})\n";
}
$permId = (int)$permId;

/* 2) concessão aos papéis de gestão de todos os tenants */
$roles = $pdo->query("SELECT id, tenant_id, slug FROM roles WHERE slug IN ('gestor','administrador','dono')")->fetchAll(PDO::FETCH_ASSOC);
$ins = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (:r, :p)");
$tem = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id = :r AND permission_id = :p");
$novos = 0;
foreach ($roles as $r) {
    $tem->execute([':r' => $r['id'], ':p' => $permId]);
    if ($tem->fetchColumn()) continue;
    $ins->execute([':r' => $r['id'], ':p' => $permId]);
    $novos++;
    echo "  + grant tenant {$r['tenant_id']} · {$r['slug']}\n";
}
echo $novos === 0 ? "  = grants já existentes\n" : "  ✔ {$novos} grant(s) novos\n";
echo "OK.\n";
