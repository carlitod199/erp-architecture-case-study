<?php
/* ============================================================
   VERO — tests/permissoes_matriz.php   (#49)
   Testa a matriz RBAC inteira: catálogo × role_permissions × engine
   de permissão (vero_dbn_perm) × guardas das telas. NÃO grava nada.

   Checa:
   1) Catálogo íntegro: todo slug da matriz de navegação existe em `permissions`.
   2) Órfãos: role_permissions apontando p/ role/permissão inexistente.
   3) Coerência por perfil: toda `X.editar`/`X.excluir` exige a `X.ver`
      correspondente (senão o perfil pode POSTar mas o guard da tela — que é a
      `.ver` — barra a abertura → permissão "morta").
   4) Exposição indevida: perfis NÃO-privilegiados com acesso de LEITURA a telas
      SENSÍVEIS (folha/salário, DRE, fluxo de caixa, custos, valuation).
   5) Resumo: nº de telas acessíveis por perfil (para revisão humana).

   Uso:  php tests/permissoes_matriz.php   (exit!=0 se houver FALHA)
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';       // vero_dbn_perm
require_once __DIR__ . '/../includes/permissions.php';     // perm_catalogo_matriz

$pdo = Database::getConnection();
$falhas = []; $avisos = [];

/* ── catálogo da matriz + tabela permissions ── */
$catalogo = perm_catalogo_matriz();
$slugsCatalogo = [];
foreach ($catalogo as $base => $grupo) {
    foreach ($grupo['itens'] as $it) $slugsCatalogo[(string)$it['slug']] = (string)($it['label'] ?? $it['slug']);
}
$permsTbl = [];
foreach ($pdo->query("SELECT id, slug FROM permissions")->fetchAll(PDO::FETCH_ASSOC) as $p) $permsTbl[(string)$p['slug']] = (int)$p['id'];

/* 1) catálogo íntegro */
$faltando = array_diff(array_keys($slugsCatalogo), array_keys($permsTbl));
if ($faltando) $falhas[] = '1) ' . count($faltando) . ' slug(s) da matriz NÃO estão em `permissions` (rode a tela de Permissões p/ sincronizar): ' . implode(', ', array_slice($faltando, 0, 10)) . (count($faltando) > 10 ? '…' : '');

/* 2) órfãos em role_permissions */
$orfaosPerm = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions rp LEFT JOIN permissions p ON p.id=rp.permission_id WHERE p.id IS NULL")->fetchColumn();
$orfaosRole = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions rp LEFT JOIN roles r ON r.id=rp.role_id WHERE r.id IS NULL")->fetchColumn();
if ($orfaosPerm) $falhas[] = "2) $orfaosPerm role_permissions apontam p/ permissão inexistente.";
if ($orfaosRole) $falhas[] = "2) $orfaosRole role_permissions apontam p/ perfil inexistente.";

/* ── perfis e suas permissões ── */
$roles = $pdo->query("SELECT id, slug, nome FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$permsDoRole = [];
foreach ($roles as $r) {
    $permsDoRole[(int)$r['id']] = array_map('strval', array_column($pdo->query(
        "SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=" . (int)$r['id'])->fetchAll(PDO::FETCH_ASSOC), 'slug'));
}

/* perfis privilegiados (podem ver telas sensíveis por desenho) */
/* rt_gerente incluído: é gerente e vê custos de máquina/irrigação;
   só NÃO vê folha/custo de MO (revogado na migration 181). */
$PRIVILEGIADOS = ['super_admin', 'administrador', 'dono', 'gestor', 'financeiro', 'contador', 'consulta', 'rt_gerente'];
/* telas sensíveis: fragmentos de slug */
$SENSIVEIS = ['folha', 'salario', 'custo_mao_obra', 'dre', 'fluxo_caixa', 'resultado_safra', 'custo_', 'valor_patrimonial', 'valuation', 'depreciacao'];

$telasVer = array_values(array_filter(array_keys($slugsCatalogo), static fn($s) => str_ends_with($s, '.ver')));
$resumo = [];

foreach ($roles as $r) {
    $slug = (string)$r['slug'];
    $perms = $permsDoRole[(int)$r['id']];

    /* 3) coerência: editar/excluir sem ver */
    foreach ($perms as $p) {
        if (preg_match('/\.(editar|excluir)$/', $p)) {
            $ver = preg_replace('/\.(editar|excluir)$/', '.ver', $p);
            if (!vero_dbn_perm($ver, $slug, $perms)) {
                $avisos[] = "3) perfil '$slug' tem '$p' mas NÃO '$ver' (permissão morta — o guard da tela exige .ver).";
            }
        }
    }

    /* 4) exposição de telas sensíveis a perfil não-privilegiado + resumo de acesso */
    $acessiveis = 0; $sensivelExposto = [];
    foreach ($telasVer as $pv) {
        if (vero_dbn_perm($pv, $slug, $perms)) {
            $acessiveis++;
            $ehSensivel = false;
            foreach ($SENSIVEIS as $frag) if (str_contains($pv, $frag)) { $ehSensivel = true; break; }
            if ($ehSensivel && !in_array($slug, $PRIVILEGIADOS, true)) $sensivelExposto[] = $pv;
        }
    }
    $resumo[] = ['slug' => $slug, 'nome' => $r['nome'], 'perms' => count($perms), 'telas' => $acessiveis, 'de' => count($telasVer)];
    if ($sensivelExposto) {
        /* política (não falha estrutural): decisão de negócio sobre quem vê custos/folha */
        $avisos[] = "4) REVISAR política — perfil '$slug' LÊ tela(s) sensível(is): " . implode(', ', array_slice($sensivelExposto, 0, 8)) . (count($sensivelExposto) > 8 ? '…' : '');
    }
}

/* ── saída ── */
echo "== VERO · Teste da matriz de permissões ==\n";
echo "Perfis: " . count($roles) . " | Permissões no catálogo: " . count($slugsCatalogo) . " | Telas (.ver): " . count($telasVer) . "\n\n";
echo "Acesso por perfil (telas .ver acessíveis / total):\n";
usort($resumo, static fn($a, $b) => $b['telas'] <=> $a['telas']);
foreach ($resumo as $x) echo sprintf("  %-16s %4d perms  %3d/%d telas\n", $x['slug'], $x['perms'], $x['telas'], $x['de']);

if ($avisos) { echo "\nAVISOS (" . count($avisos) . "):\n"; foreach (array_slice($avisos, 0, 30) as $a) echo "  ~ $a\n"; if (count($avisos) > 30) echo "  … +" . (count($avisos) - 30) . "\n"; }

if ($falhas) {
    echo "\nFALHAS (" . count($falhas) . "):\n";
    foreach ($falhas as $f) echo "  ✗ $f\n";
    exit(1);
}
echo "\nOK estrutural: catálogo íntegro e sem órfãos."
   . ($avisos ? " Há " . count($avisos) . " aviso(s) de coerência/política acima p/ revisão humana." : "") . "\n";
exit(0);
