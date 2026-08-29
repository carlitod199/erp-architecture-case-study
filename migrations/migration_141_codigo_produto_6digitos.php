<?php
declare(strict_types=1);

// ============================================================================
// migration_141_codigo_produto_6digitos.php | VERO — Pacote A0-14 (05/07/2026)
//   Decisão do usuário (DECISIONS 05/07): código de produto = numérico de
//   6 dígitos, SEMPRE. Coluna permanece VARCHAR (zeros à esquerda), estreitada
//   40→6; formato é validação PHP ^[0-9]{6}$ nas telas (regra permanente).
//   1. Renumera os produtos fora do padrão (sequencial por tenant, ordem de id)
//   2. Atualiza cópias textuais em agro_alertas.titulo (único achado da
//      varredura de todas as colunas texto/JSON do banco)
//   3. ALTER codigo VARCHAR(6) (UNIQUE uq_produtos_codigo preservada)
//   Geração automática: vero_srv_produto_proximo_codigo() (vero_services).
// Idempotente. Backup: backup_pre_141_*.sql.
// ============================================================================

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$log = fn(string $m) => print($m . "\n");

$log("== migration 141 — código de produto 6 dígitos (A0-14) ==");

$log("[1] renumeração dos produtos fora do padrão");
$tenants = $pdo->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tenants as $tid) {
    $fora = $pdo->prepare("SELECT id, codigo FROM estoque_produtos
        WHERE tenant_id = ? AND codigo NOT REGEXP '^[0-9]{6}$' ORDER BY id");
    $fora->execute([$tid]);
    $fora = $fora->fetchAll(PDO::FETCH_ASSOC);
    if (!$fora) { $log("  = tenant $tid: todos já no padrão"); continue; }
    $max = (int)$pdo->query("SELECT COALESCE(MAX(CAST(codigo AS UNSIGNED)),0) FROM estoque_produtos
        WHERE tenant_id = $tid AND codigo REGEXP '^[0-9]{6}$'")->fetchColumn();
    $upd = $pdo->prepare("UPDATE estoque_produtos SET codigo = ? WHERE id = ?");
    $updAlerta = $pdo->prepare("UPDATE agro_alertas SET titulo = REPLACE(titulo, ?, ?) WHERE tenant_id = ? AND titulo LIKE ?");
    $pdo->beginTransaction();
    try {
        foreach ($fora as $prod) {
            $max++;
            if ($max > 999999) throw new RuntimeException('Faixa de 6 dígitos esgotada.');
            $novo = str_pad((string)$max, 6, '0', STR_PAD_LEFT);
            $upd->execute([$novo, (int)$prod['id']]);
            $updAlerta->execute([(string)$prod['codigo'], $novo, $tid, '%' . $prod['codigo'] . '%']);
            $log("  ~ tenant $tid: '{$prod['codigo']}' → $novo (id {$prod['id']})");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$log("[2] ALTER codigo VARCHAR(6)");
$tipo = $pdo->query("SELECT column_type FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'estoque_produtos' AND column_name = 'codigo'")->fetchColumn();
if ($tipo !== 'varchar(6)') {
    $maisLongo = (int)$pdo->query("SELECT COALESCE(MAX(CHAR_LENGTH(codigo)),0) FROM estoque_produtos")->fetchColumn();
    if ($maisLongo > 6) throw new RuntimeException("Ainda há código com $maisLongo caracteres — renumeração incompleta.");
    $pdo->exec("ALTER TABLE estoque_produtos MODIFY codigo VARCHAR(6) NOT NULL COMMENT 'numerico 6 digitos — PHP valida ^[0-9]{6}$ (DECISIONS 05/07)'");
    $log("  ~ codigo: $tipo → varchar(6)");
} else { $log("  = já é varchar(6)"); }

$log("== migration 141 concluída ==");
