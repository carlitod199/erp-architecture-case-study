<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration 211: permissões do Packing House COMPLETAS

   MODO PADRÃO (sem argumentos — é assim que o vero-deploy.sh roda):
     semeia APENAS O CATÁLOGO (permissions) com .ver/.editar de cada
     micro do macro packing do menu. NENHUM grant é concedido.

   MODO GRANT (explícito, por tenant — decisão de contrato/política):
     php migration_211_packing_permissoes_completas.php --grant=<tenant_id> [--gestor-editar]

     Política padrão do grant (registrada no bloqueio do deploy 20/08):
       - administrador, dono : .ver + .editar (+ packing.ver)
       - gestor              : SOMENTE .ver (+ packing.ver) — gestor NÃO é
                               promovido a escrita; quem escreve é o
                               administrador (política vigente em produção).
     --gestor-editar: gestor também recebe .editar — para DEV/HOMOLOG
       (servidor01), onde o gestor é o operador de teste. Em produção,
       usar apenas com decisão registrada do contratante.

   Idempotente nos dois modos.
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/* ---- argumentos ---- */
$grantTenant  = null;
$gestorEditar = false;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--grant=(\d+)$/', $arg, $m)) { $grantTenant = (int)$m[1]; continue; }
    if ($arg === '--gestor-editar') { $gestorEditar = true; continue; }
    exit("Argumento desconhecido: {$arg}\nUso: migration_211 [--grant=<tenant_id>] [--gestor-editar]\n");
}
if ($gestorEditar && $grantTenant === null) {
    exit("--gestor-editar só faz sentido com --grant=<tenant_id>.\n");
}

echo "== migration 211: permissões completas do Packing House ==\n";

/* micros do macro packing no menu (includes/menu_agro.php, permbase 'packing') */
$micros = [
    'recepcao'       => 'Recepção de cargas',
    'relogio_frio'   => 'Relógio de frio',
    'apontar'        => 'Posto de produção (colheita/embalamento)',
    'unidade'        => 'Unidade de packing',
    'crachas'        => 'QR Codes / crachás',
    'embalagens'     => 'Embalagens',
    'skus'           => 'SKUs',
    'etiqueta_caixa' => 'Etiqueta de caixa',
    'mercados'       => 'Mercados',
    'certificacoes'  => 'Certificações',
];
$perms = ['packing.ver' => 'Packing House — acessar'];
foreach ($micros as $slug => $rotulo) {
    $perms["packing.{$slug}.ver"]    = "Packing — {$rotulo} (ver)";
    $perms["packing.{$slug}.editar"] = "Packing — {$rotulo} (editar)";
}

/* ---- catálogo (sempre) ---- */
$permIds = [];
$sel = $pdo->prepare("SELECT id FROM permissions WHERE slug = :s");
$ins = $pdo->prepare("INSERT INTO permissions (slug, label, modulo) VALUES (:s, :l, 'Packing House')");
$novasPerms = 0;
foreach ($perms as $slug => $label) {
    $sel->execute([':s' => $slug]);
    $id = $sel->fetchColumn();
    if (!$id) {
        $ins->execute([':s' => $slug, ':l' => $label]);
        $id = (int)$pdo->lastInsertId();
        $novasPerms++;
        echo "  + {$slug}\n";
    }
    $permIds[$slug] = (int)$id;
}
echo $novasPerms === 0 ? "  = catálogo já completo\n" : "  ✔ {$novasPerms} permissão(ões) novas no catálogo\n";

if ($grantTenant === null) {
    echo "OK (catálogo apenas — nenhum grant; para conceder: --grant=<tenant_id>).\n";
    exit(0);
}

/* ---- grants (somente com --grant=<tenant_id>) ---- */
echo "-- grants para tenant {$grantTenant} (gestor " . ($gestorEditar ? 'COM' : 'sem') . " .editar) --\n";
$roles = $pdo->prepare("SELECT id, slug FROM roles WHERE tenant_id = :t AND slug IN ('gestor','administrador','dono')");
$roles->execute([':t' => $grantTenant]);
$roles = $roles->fetchAll(PDO::FETCH_ASSOC);
if (!$roles) exit("Nenhum papel gestor/administrador/dono no tenant {$grantTenant} — nada a fazer.\n");

$tem   = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id = :r AND permission_id = :p");
$grant = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (:r, :p)");
$novos = 0;
foreach ($roles as $r) {
    $podeEditar = in_array($r['slug'], ['administrador', 'dono'], true) || $gestorEditar;
    foreach ($permIds as $slug => $pid) {
        if (str_ends_with($slug, '.editar') && !$podeEditar) continue;
        $tem->execute([':r' => $r['id'], ':p' => $pid]);
        if ($tem->fetchColumn()) continue;
        $grant->execute([':r' => $r['id'], ':p' => $pid]);
        $novos++;
        echo "  + grant {$r['slug']} · {$slug}\n";
    }
}
echo $novos === 0 ? "  = grants já existentes\n" : "  ✔ {$novos} grant(s) novos\n";
echo "OK.\n";
