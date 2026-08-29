<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/packing_backfill_lotes.php (one-shot, idempotente)
   Backfill do bug do lote COLH- no aceite (fix de 19/08): recepções
   ACEITAS antes da correção ficaram com ph_recepcao_itens.lote_estoque_id
   NULL e nunca aparecem na etiqueta de caixa. Reamarra cada item usando
   o MESMO get-or-create do aceite (ph_recepcao_lote_colh) — itens sem
   cadeia derivável (carga sem registro E sem safra_talhao) são apenas
   listados, não inventamos dado.
   Rodar por TENANT: php scripts/packing_backfill_lotes.php <tenant_id>
   Usa uma sessão de serviço (padrão dos scripts de seed) e não mexe em
   quantidade/custo de nada.
   ============================================================ */
if (PHP_SAPI !== 'cli') { exit("CLI apenas.\n"); }
$tenantId = (int)($argv[1] ?? 0);
if ($tenantId <= 0) { exit("Uso: php scripts/packing_backfill_lotes.php <tenant_id>\n"); }

require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../packing/_ph_recepcao.php';

/* sessão de serviço mínima (mesmo padrão dos scripts de seed) */
$_SESSION['tenant_id'] = $tenantId;
$_SESSION['user_id']   = $_SESSION['user_id'] ?? 1;

$itens = vero_rows(
    "SELECT ri.id, ri.colheita_carga_id, r.numero
       FROM ph_recepcao_itens ri
       JOIN ph_recepcoes r ON r.id = ri.recepcao_id AND r.tenant_id = ri.tenant_id
      WHERE ri.tenant_id = :t AND ri.lote_estoque_id IS NULL
      ORDER BY ri.id", [':t' => $tenantId]);
echo "tenant {$tenantId}: " . count($itens) . " item(ns) recebidos sem lote\n";

$pdo = vero_pdo();
$ok = 0; $sem = 0;
foreach ($itens as $it) {
    $pdo->beginTransaction();
    try {
        $loteId = ph_recepcao_lote_colh($it['colheita_carga_id'] !== null ? (int)$it['colheita_carga_id'] : null);
        if ($loteId === null) {
            $pdo->rollBack();
            $sem++;
            echo "  - item {$it['id']} ({$it['numero']}): SEM cadeia derivável (carga sem registro e sem safra) — vincule a carga e rode de novo\n";
            continue;
        }
        $pdo->prepare("UPDATE ph_recepcao_itens SET lote_estoque_id = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$loteId, (int)$it['id'], $tenantId]);
        $pdo->commit();
        $ok++;
        $cod = (string)vero_val("SELECT codigo_lote FROM estoque_lotes WHERE id = :i AND tenant_id = :t",
            [':i' => $loteId, ':t' => $tenantId]);
        echo "  ✔ item {$it['id']} ({$it['numero']}) → lote {$cod} (#{$loteId})\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "  ✗ item {$it['id']}: {$e->getMessage()}\n";
    }
}
echo "Backfill: {$ok} reamarrado(s), {$sem} sem cadeia.\n";
