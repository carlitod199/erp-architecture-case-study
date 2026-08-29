<?php
declare(strict_types=1);

// ============================================================================
// migration_138_espelho_valvula.php | VERO
// Pacote A0-10 — ARBITRAGEM A1-32 (estratégia B, 04/07/2026):
//   DB-34: agro_setores.is_espelho + migração de dados do tenant 1
//          (marcar espelhos existentes; criar onde faltar; relatório de
//          divergências código/área — P-58: alinha pelo TALHÃO) +
//          parâmetro agro.valvula_igual_talhao = '1' (modo unificado).
// Idempotente. Backup: backup_pre_138_*.sql.
// Rollback: is_espelho=0 + parâmetro '0' (nenhuma FK muda).
// ============================================================================

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$log = function (string $m): void { echo $m . "\n"; };
$columnExists = function (string $t, string $c) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$t, $c]);
    return (bool)$st->fetchColumn();
};

$log("== migration 138 — válvula-espelho 1:1 (estratégia B) ==");

if (!$columnExists('agro_setores', 'is_espelho')) {
    $pdo->exec("ALTER TABLE agro_setores ADD COLUMN is_espelho TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'espelho 1:1 do talhao no modo unificado (DB-34/estrategia B)'");
    $log("  + agro_setores.is_espelho");
} else { $log("  = is_espelho já existe"); }

/* migração de dados por tenant que opera unificado (piloto: tenant 1) */
$TENANTS_UNIFICADOS = [1];
foreach ($TENANTS_UNIFICADOS as $t) {
    $log("[tenant $t] migração de dados");
    $talhoes = $pdo->query("SELECT id, codigo, nome, area_ha, fazenda_id FROM agro_talhoes
                             WHERE tenant_id = $t AND ativo = 1")->fetchAll();
    foreach ($talhoes as $tal) {
        $setores = $pdo->query("SELECT id, codigo, area_ha, is_espelho FROM agro_setores
                                 WHERE tenant_id = $t AND talhao_id = {$tal['id']} AND ativo = 1")->fetchAll();
        if (count($setores) === 1) {
            $s = $setores[0];
            /* P-58 (arbitrada): divergência alinha pelo TALHÃO, com relatório */
            if ($s['codigo'] !== $tal['codigo'] || abs((float)$s['area_ha'] - (float)$tal['area_ha']) > 0.0001) {
                $log("  ! divergência no setor {$s['id']}: codigo '{$s['codigo']}'→'{$tal['codigo']}', area {$s['area_ha']}→{$tal['area_ha']} (alinhado pelo talhão)");
                $pdo->prepare("UPDATE agro_setores SET codigo = ?, area_ha = ? WHERE tenant_id = ? AND id = ?")
                    ->execute([$tal['codigo'], $tal['area_ha'], $t, (int)$s['id']]);
            }
            if ((int)$s['is_espelho'] !== 1) {
                $pdo->prepare("UPDATE agro_setores SET is_espelho = 1 WHERE tenant_id = ? AND id = ?")
                    ->execute([$t, (int)$s['id']]);
                $log("  ~ setor {$s['id']} marcado como espelho do talhão {$tal['codigo']}");
            } else { $log("  = talhão {$tal['codigo']}: espelho já marcado"); }
        } elseif (count($setores) === 0) {
            $pdo->prepare("INSERT INTO agro_setores (tenant_id, fazenda_id, talhao_id, codigo, nome, tipo, area_ha, is_espelho, ativo)
                           VALUES (?,?,?,?,?,'valvula',?,1,1)")
                ->execute([$t, (int)$tal['fazenda_id'], (int)$tal['id'], $tal['codigo'], $tal['nome'], $tal['area_ha']]);
            $log("  + espelho criado para o talhão {$tal['codigo']}");
        } else {
            $log("  ! talhão {$tal['codigo']} tem " . count($setores) . " setores ativos — modo unificado exige 1; RESOLVER MANUALMENTE (nenhum marcado)");
        }
    }
    /* setores ativos sem talhão: relatório */
    $orfaos = $pdo->query("SELECT id, codigo FROM agro_setores WHERE tenant_id = $t AND ativo = 1 AND talhao_id IS NULL")->fetchAll();
    foreach ($orfaos as $o) $log("  ! setor {$o['id']} ('{$o['codigo']}') sem talhao_id — decisão manual");

    /* parâmetro do modo unificado (infra A0 — tenant_parametros) */
    $tem = $pdo->query("SELECT COUNT(*) FROM tenant_parametros WHERE tenant_id = $t AND chave = 'agro.valvula_igual_talhao'")->fetchColumn();
    if (!$tem) {
        $pdo->prepare("INSERT INTO tenant_parametros (tenant_id, chave, valor, descricao, created_by)
                       VALUES (?, 'agro.valvula_igual_talhao', '1', 'Modo unificado: válvula = talhão (estratégia B, arbitragem A0 04/07/2026)', 1)")
            ->execute([$t]);
        $log("  + parâmetro agro.valvula_igual_talhao = '1'");
    } else { $log("  = parâmetro já existe"); }
}

$log("== migration 138 concluída ==");
