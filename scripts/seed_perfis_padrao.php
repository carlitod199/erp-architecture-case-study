<?php
declare(strict_types=1);
/* ============================================================
   VERO — Seed dos 7 perfis padrão (A3-T9 — P-05 APROVADA)
   Matriz: VERO_A3_ANALISE.md §9. Idempotente: limpar/upsert por
   role (as 52 linhas legadas do Gestor são substituídas — ajuste
   da auditoria A3-01). Uso: php scripts/seed_perfis_padrao.php
   [--dry-run]. Roles tenant_id=1; catálogo = permissions (sync
   da matriz, ~570 slugs ver/editar/excluir).
   ============================================================ */
if (PHP_SAPI !== 'cli') exit("Somente CLI.\n");
$dry = in_array('--dry-run', $argv, true);
require_once __DIR__ . '/../includes/db.php';
$pdo = Database::getConnection();
$T = 1;

/* C-25/C-15: sincroniza o CATÁLOGO da matriz antes de semear —
   garante os slugs novos (ex.: planejamento.*) sem depender de abrir a tela
   de permissões. Mesmo mecanismo da tela (perm_sincronizar_catalogo). */
$_SESSION['tenant_id'] = $T; $_SESSION['user_id'] = $_SESSION['user_id'] ?? 1;
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/menu_agro.php';
require_once __DIR__ . '/../includes/permissions.php';
if (!$dry && function_exists('perm_catalogo_matriz')) {
    $ins = $pdo->prepare("INSERT IGNORE INTO permissions (slug, label, modulo) VALUES (?,?,?)");
    $novosCat = 0;
    foreach (perm_catalogo_matriz() as $grupo) {
        foreach ($grupo['itens'] as $item) {
            $ins->execute([(string)$item['slug'], (string)$item['label'], (string)$grupo['label']]);
            $novosCat += $ins->rowCount();
        }
    }
    echo "catálogo sincronizado da matriz (+{$novosCat} slug(s) novos)\n";
}

$slugs = array_column($pdo->query("SELECT id, slug FROM permissions")->fetchAll(PDO::FETCH_ASSOC), 'id', 'slug');
echo count($slugs) . " slugs no catálogo\n";

/* helpers de seleção sobre o catálogo */
$sel = static function (callable $pred) use ($slugs): array {
    $out = [];
    foreach ($slugs as $slug => $id) if ($pred((string)$slug)) $out[(string)$slug] = (int)$id;
    return $out;
};
$mod = static fn(string $s): string => explode('.', $s)[0];
$acao = static fn(string $s): string => substr($s, (int)strrpos($s, '.') + 1);

/* A3-T23 (QA-001): prefixos = PERMBASE do catálogo, não o slug do macro —
   'agro' (não 'agricola') e 'custeio' (não 'custos'). Conferido no banco:
   SELECT DISTINCT SUBSTRING_INDEX(slug,'.',1) FROM permissions. */
$PROD = ['agro', 'nutricao', 'mip', 'irrigacao'];                  // produção agrícola
$OPER = array_merge($PROD, ['estoque', 'compras', 'maquinas', 'pessoas', 'custeio', 'comercial']);
$CONF_RESTRITO = ['usuarios', 'perfis_acesso', 'permissoes'];      // só administrador

$regras = [
    'administrador' => ['nome' => 'Administrador', 'desc' => 'Acesso total ao sistema',
        'pred' => static fn($s) => true],
    'gestor' => ['nome' => 'Gestor', 'desc' => 'Gestão operacional; financeiro somente leitura; sem gestão de acessos',
        'pred' => static function ($s) use ($mod, $acao, $OPER, $CONF_RESTRITO) {
            $m = $mod($s); $a = $acao($s);
            if ($m === 'configuracoes') { $micro = explode('.', $s)[1] ?? '';
                return !in_array($micro, $CONF_RESTRITO, true) && $a === 'ver'; }
            if ($a === 'ver') return true;                                    // vê tudo (exceto config restrita)
            return in_array($m, $OPER, true);                                  // edita/exclui só operação
        }],
    'financeiro' => ['nome' => 'Financeiro', 'desc' => 'Financeiro, custos e fiscal; compras/comercial leitura',
        'pred' => static function ($s) use ($mod, $acao) {
            $m = $mod($s); $a = $acao($s);
            if (in_array($m, ['financeiro', 'custeio', 'fiscal'], true)) return true;
            if (in_array($m, ['compras', 'comercial', 'relatorios', 'patrimonio'], true)) return $a === 'ver';
            if ($m === 'dashboard') return $a === 'ver';
            return false;
        }],
    'operador' => ['nome' => 'Operador de Campo', 'desc' => 'Apontamentos, monitoramento e colheita; sem valores financeiros',
        'pred' => static function ($s) use ($mod, $acao, $PROD) {
            $m = $mod($s); $a = $acao($s);
            if ($s === 'dashboard.ver' || str_starts_with($s, 'dashboard.visao_geral.')) return $a === 'ver';
            if (in_array($m, $PROD, true)) {
                if ($a === 'ver') return true;
                foreach (['apontamentos', 'monitoramento', 'colheita', 'clima'] as $mic)
                    if (str_contains($s, '.' . $mic)) return $a === 'editar';
                return false;
            }
            if ($m === 'estoque') return $a === 'ver';
            return false;
        }],
    'almoxarifado' => ['nome' => 'Almoxarifado', 'desc' => 'Estoque completo; solicitações e recebimentos de compra',
        'pred' => static function ($s) use ($mod, $acao) {
            $m = $mod($s); $a = $acao($s);
            if ($m === 'estoque') return true;
            if ($m === 'compras') { if ($a === 'ver') return true;
                foreach (['solicitacoes', 'recebimentos'] as $mic) if (str_contains($s, '.' . $mic)) return true;
                return false; }
            if ($m === 'relatorios') return $a === 'ver' && str_contains($s, 'estoque');
            if ($s === 'dashboard.ver' || str_starts_with($s, 'dashboard.visao_geral.')) return $a === 'ver';
            return false;
        }],
    'consulta' => ['nome' => 'Consulta', 'desc' => 'Somente leitura operacional e de custos; sem razão financeiro',
        'pred' => static function ($s) use ($mod, $acao) {
            $m = $mod($s); $a = $acao($s);
            if ($a !== 'ver') return false;
            return !in_array($m, ['financeiro', 'fiscal', 'configuracoes'], true);
        }],
    'contador' => ['nome' => 'Contador', 'desc' => 'Leitura de financeiro, fiscal, custos, patrimônio e relatórios',
        'pred' => static function ($s) use ($mod, $acao) {
            $m = $mod($s); $a = $acao($s);
            return $a === 'ver' && in_array($m, ['financeiro', 'fiscal', 'custeio', 'patrimonio', 'relatorios', 'dashboard'], true);
        }],

    /* ── C-25: 5 PERSONAS DO CLIENTE ──────────────────────
       Regras faladas: relatórios/custos SÓ o dono (RT ganha se o dono
       liberar depois, na tela de perfis); vendas/comercial restritas até
       para o RT; mão de obra NÃO vê dashboards; Planejamento (C-15) é de
       dono/RT — quem só aponta não recebe planejamento.* e não vê o menu. */
    'dono' => ['nome' => 'Dono da Fazenda', 'desc' => 'Acesso total — inclusive custos, vendas e relatórios',
        'pred' => static fn($s) => true],
    'rt_gerente' => ['nome' => 'RT / Gerente', 'desc' => 'Planeja e conduz a produção; sem custos e sem vendas (o dono libera se quiser)',
        'pred' => static function ($s) use ($mod, $acao, $PROD) {
            $m = $mod($s); $a = $acao($s);
            if ($m === 'planejamento') return true;                              /* C-15: planejador */
            if (in_array($m, $PROD, true)) return true;                          /* produção completa */
            if (in_array($m, ['estoque', 'maquinas'], true)) return true;        /* insumos/frota da operação */
            if (in_array($m, ['compras', 'pessoas'], true)) return $a === 'ver';
            if ($m === 'dashboard') { $micro = explode('.', $s)[1] ?? '';
                return $a === 'ver' && in_array($micro, ['', 'ver', 'visao_geral', 'dashboard_operacional', 'apontamentos'], true); }
            if ($m === 'relatorios') { $micro = explode('.', $s)[1] ?? '';
                return $a === 'ver' && !str_contains($micro, 'financeiro'); }     /* técnicos sim; financeiros não */
            return false;                                                        /* custeio/comercial/financeiro: NÃO */
        }],
    'encarregado' => ['nome' => 'Encarregado', 'desc' => 'Executa e aponta o campo da equipe; sem planejamento, custos e dashboards',
        'pred' => static function ($s) use ($mod, $acao, $PROD) {
            $m = $mod($s); $a = $acao($s);
            if (!in_array($m, array_merge($PROD, ['estoque']), true)) return false;
            if ($a === 'ver') return true;
            foreach (['apontamentos_campo', 'apontamentos_irrigacao', 'colheita', 'romaneios_colheita', 'monitoramentos'] as $mic)
                if (str_contains($s, '.' . $mic . '.')) return $a === 'editar';
            return false;
        }],
    'mao_de_obra' => ['nome' => 'Mão de Obra', 'desc' => 'Aponta o próprio trabalho; sem dashboards, valores ou planejamento',
        'pred' => static function ($s) use ($acao) {
            $a = $acao($s);
            if ($s === 'agro.ver') return true;
            foreach (['agro.apontamentos_campo.', 'agro.colheita.'] as $pfx)
                if (str_starts_with($s, $pfx)) return $a !== 'excluir';
            return false;
        }],
    'monitor' => ['nome' => 'Monitor (MIP)', 'desc' => 'Registra monitoramentos de pragas; leitura do contexto de campo',
        'pred' => static function ($s) use ($acao) {
            $a = $acao($s);
            if (in_array($s, ['mip.ver', 'agro.ver'], true)) return true;
            if (str_starts_with($s, 'mip.monitoramentos.')) return $a !== 'excluir';
            foreach (['mip.alvos_controle.', 'agro.valvulas.', 'agro.mapa_fazenda.'] as $pfx)
                if (str_starts_with($s, $pfx)) return $a === 'ver';
            return false;
        }],
];

foreach ($regras as $slug => $r) {
    $perms = $sel($r['pred']);
    $role = $pdo->prepare("SELECT id FROM roles WHERE tenant_id = ? AND slug = ?");
    $role->execute([$T, $slug]);
    $roleId = $role->fetchColumn();
    echo str_pad($slug, 15) . count($perms) . " permissão(ões)" . ($dry ? " [dry-run]\n" : "");
    if ($dry) continue;
    if (!$roleId) {
        $pdo->prepare("INSERT INTO roles (tenant_id, slug, nome, descricao, ativo) VALUES (?,?,?,?,1)")
            ->execute([$T, $slug, $r['nome'], $r['desc']]);
        $roleId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE roles SET nome=?, descricao=?, ativo=1 WHERE id=?")
            ->execute([$r['nome'], $r['desc'], (int)$roleId]);
    }
    $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([(int)$roleId]); /* limpar/upsert */
    $ins = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)");
    foreach ($perms as $pid) $ins->execute([(int)$roleId, $pid]);
    echo " → gravadas (role {$roleId})\n";
}
echo "OK. Permissões aplicam no PRÓXIMO LOGIN de cada usuário. super_admin intocado (irrestrito).\n";
