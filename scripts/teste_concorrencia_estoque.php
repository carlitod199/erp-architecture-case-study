<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/teste_concorrencia_estoque.php
   Harness CLI do achado A12 (auditoria Rodada 3, 22/07/2026):
   lost update / oversell na baixa de estoque.

   Cenário da auditoria: saldo 5, DUAS saídas de 4 "simultâneas".
   Antes da correção, ambas passavam (saldo final 1 com 8 saídos).
   Depois: a baixa é UPDATE atômico condicional (quantidade >= q);
   a 2ª saída afeta 0 linhas e é negada.

   Duas conexões PDO reais:
     A = vero_pdo() (a mesma dos services) — trava a linha em transação;
     B = conexão crua — tenta a mesma baixa: 1º lock wait (serialização),
         depois do commit de A, guard nega (0 linhas).
   innodb_lock_wait_timeout=2 na B para o harness single-thread não travar.

   LIMPA TUDO ao final (produto QA-A12, saldos, lotes, movimentações,
   rateios, alertas) — banco compartilhado, zero sobras.
   ============================================================ */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../includes/vero_services.php';

$pdoA = vero_pdo();

/* sessão simulada (padrão dos harnesses da bateria) */
$tenantId = (int)$pdoA->query('SELECT id FROM tenants ORDER BY id LIMIT 1')->fetchColumn();
$userId   = (int)$pdoA->query("SELECT id FROM usuarios WHERE tenant_id = {$tenantId} ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION['tenant_id']   = $tenantId;
$_SESSION['user_id']     = $userId ?: 1;
$_SESSION['user_role']   = 'super_admin';
$_SESSION['permissions'] = ['*'];

echo "Tenant do teste: {$tenantId} | user: " . vero_uid() . "\n";

$pass = 0; $fail = 0;
function chk(string $desc, bool $ok, string $ctx = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $desc . ($ctx !== '' ? " | {$ctx}" : '') . "\n";
}

$hoje       = date('Y-m-d');
$produtoId  = 0;
$grupoCriado = false;
$almoxCriado = false;
$pdoB = null;

try {
    /* ── setup: produto QA-A12 com saldo 5 (entrada 5 un @ R$ 10,00, lote c/ validade) ── */
    $grupoExistia = (bool)vero_val(
        "SELECT id FROM estoque_grupos WHERE tenant_id=:t AND nome='Insumos Agrícolas' LIMIT 1",
        [':t' => $tenantId]);
    $grupoId = vero_srv_grupo_estoque_padrao();
    $grupoCriado = !$grupoExistia;

    $almoxExistia = (bool)vero_val(
        "SELECT id FROM almoxarifados WHERE tenant_id=:t AND ativo=1 LIMIT 1", [':t' => $tenantId]);
    $almoxId = vero_srv_almox_padrao();
    $almoxCriado = !$almoxExistia;

    $produtoId = vero_insert('estoque_produtos', [
        'grupo_id' => $grupoId, 'codigo' => 'QA-A12',
        'nome' => 'QA-A12 teste concorrência (apagar)', 'unidade' => 'un',
        'controla_lote' => 1, 'controla_validade' => 1, 'ativo' => 1,
    ]);
    echo "Produto de teste criado: #{$produtoId} (QA-A12) | almox #{$almoxId}\n";

    vero_srv_estoque_entrada($produtoId, $almoxId, 5.0, 10.00, $hoje,
        'manual', null, 'QA-A12 carga inicial do harness', date('Y-m-d', strtotime('+1 year')));

    $s = vero_row("SELECT * FROM estoque_saldos WHERE tenant_id=:t AND produto_id=:p AND almoxarifado_id=:a",
        [':t' => $tenantId, ':p' => $produtoId, ':a' => $almoxId]);
    chk('entrada atômica relativa: saldo 5, valor 50.00, custo médio 10',
        (float)$s['quantidade'] === 5.0 && (float)$s['valor_total'] === 50.0 && abs((float)$s['custo_medio'] - 10.0) < 1e-6,
        "qtd={$s['quantidade']} valor={$s['valor_total']} custo={$s['custo_medio']}");

    /* ── conexão B (segunda sessão MySQL, crua) ── */
    $cfg  = require __DIR__ . '/../config/database.php';
    $pdoB = new PDO("mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset={$cfg['charset']}",
        $cfg['user'], $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 2');

    /* réplica exata da baixa atômica do service (vero_srv_estoque_saida) */
    $sqlBaixaB = "UPDATE estoque_saldos
                     SET quantidade = quantidade - :q,
                         valor_total = GREATEST(0, valor_total - :ct),
                         atualizado_em = NOW()
                   WHERE id = :id AND tenant_id = :t AND quantidade >= :q2";

    /* ── cenário da auditoria: duas saídas de 4 intercaladas ──
       A: abre transação e faz a saída REAL pelo service (linha fica travada). */
    $pdoA->beginTransaction();
    $ret = vero_srv_estoque_saida($produtoId, $almoxId, 4.0, $hoje, 'manual', null, 'QA-A12 saída A');
    echo "A: saída de 4 aceita dentro da transação (mov #{$ret['mov_id']}, custo R$ {$ret['custo_total']}) — linha do saldo TRAVADA, sem commit\n";

    /* B: tenta a mesma baixa enquanto A segura o lock → deve esperar e estourar
       lock wait (prova de que a 2ª saída NÃO enxerga mais o snapshot antigo). */
    $pdoB->beginTransaction();
    $t0 = microtime(true);
    try {
        $stB = $pdoB->prepare($sqlBaixaB);
        $stB->execute([':q' => 4.0, ':ct' => 40.00, ':id' => (int)$s['id'], ':t' => $tenantId, ':q2' => 4.0]);
        chk('B bloqueada enquanto A não conclui (esperava lock wait timeout)', false,
            'UPDATE de B executou com A ainda aberta — rowCount=' . $stB->rowCount());
    } catch (PDOException $e) {
        $dt = round(microtime(true) - $t0, 2);
        chk('B ficou serializada atrás do lock de A (lock wait timeout ~2s)',
            str_contains($e->getMessage(), 'Lock wait timeout'),
            "após {$dt}s: " . $e->getMessage());
    }
    if ($pdoB->inTransaction()) $pdoB->rollBack();

    /* A conclui */
    $pdoA->commit();
    echo "A: COMMIT — saldo agora deve ser 1\n";

    /* B tenta de novo (agora sem lock): o guard quantidade >= 4 nega — 0 linhas */
    $stB = $pdoB->prepare($sqlBaixaB);
    $stB->execute([':q' => 4.0, ':ct' => 40.00, ':id' => (int)$s['id'], ':t' => $tenantId, ':q2' => 4.0]);
    chk('B negada após o commit de A: guard quantidade >= 4 → 0 linhas afetadas (sem oversell)',
        $stB->rowCount() === 0, 'rowCount=' . $stB->rowCount());

    /* mesma tentativa pela via do SERVICE (mensagem single-user intacta) */
    try {
        vero_srv_estoque_saida($produtoId, $almoxId, 4.0, $hoje, 'manual', null, 'QA-A12 saída B');
        chk('service nega a 2ª saída de 4 (saldo 1)', false, 'saída indevidamente aceita');
    } catch (RuntimeException $e) {
        chk('service nega a 2ª saída de 4 com a mensagem de sempre',
            str_starts_with($e->getMessage(), 'Saldo insuficiente em estoque'),
            'msg: ' . $e->getMessage());
    }

    /* ── conferência final: saldo 1, UMA saída de 4 (e não duas) ── */
    $sf = vero_row("SELECT * FROM estoque_saldos WHERE tenant_id=:t AND produto_id=:p AND almoxarifado_id=:a",
        [':t' => $tenantId, ':p' => $produtoId, ':a' => $almoxId]);
    $saidas = vero_row(
        "SELECT COUNT(*) n, COALESCE(SUM(quantidade),0) q FROM estoque_movimentacoes
          WHERE tenant_id=:t AND produto_id=:p AND tipo='saida' AND estornado_em IS NULL",
        [':t' => $tenantId, ':p' => $produtoId]);
    chk('saldo final 1.0000 com exatamente 1 saída somando 4 (auditoria: antes ficava 1 com 8 saídos)',
        (float)$sf['quantidade'] === 1.0 && (int)$saidas['n'] === 1 && (float)$saidas['q'] === 4.0,
        "saldo={$sf['quantidade']} saidas={$saidas['n']} qtd_saida={$saidas['q']}");

    $lote = vero_row("SELECT quantidade FROM estoque_lotes WHERE tenant_id=:t AND produto_id=:p",
        [':t' => $tenantId, ':p' => $produtoId]);
    chk('lote FEFO baixado com guard: restou 1.0000 no lote', (float)$lote['quantidade'] === 1.0,
        'lote qtd=' . $lote['quantidade']);
} catch (Throwable $e) {
    $fail++;
    echo '[FAIL] exceção inesperada: ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    if ($pdoA->inTransaction()) $pdoA->rollBack();
    if ($pdoB instanceof PDO && $pdoB->inTransaction()) $pdoB->rollBack();
} finally {
    /* ── LIMPEZA TOTAL (banco compartilhado — zero sobras) ── */
    echo "\n-- limpeza --\n";
    if ($produtoId > 0) {
        if ($pdoA->inTransaction()) $pdoA->rollBack();
        $del = function (string $sql, array $p) use ($pdoA): int {
            $st = $pdoA->prepare($sql); $st->execute($p); return $st->rowCount();
        };
        $n1 = $del("DELETE ml FROM estoque_movimentacao_lotes ml
                     JOIN estoque_movimentacoes m ON m.id = ml.movimentacao_id
                    WHERE m.tenant_id=? AND m.produto_id=?", [$tenantId, $produtoId]);
        $n2 = $del("DELETE FROM estoque_movimentacoes WHERE tenant_id=? AND produto_id=?", [$tenantId, $produtoId]);
        $n3 = $del("DELETE al FROM agro_alertas al
                    WHERE al.tenant_id=? AND al.categoria='estoque' AND al.origem_tipo='estoque_lote'
                      AND al.origem_id IN (SELECT id FROM estoque_lotes WHERE tenant_id=? AND produto_id=?)",
                   [$tenantId, $tenantId, $produtoId]);
        $n3 += $del("DELETE FROM agro_alertas WHERE tenant_id=? AND categoria='estoque'
                       AND origem_tipo='estoque_produto' AND origem_id=?", [$tenantId, $produtoId]);
        $n4 = $del("DELETE FROM estoque_lotes  WHERE tenant_id=? AND produto_id=?", [$tenantId, $produtoId]);
        $n5 = $del("DELETE FROM estoque_saldos WHERE tenant_id=? AND produto_id=?", [$tenantId, $produtoId]);
        $n6 = $del("DELETE FROM estoque_produtos WHERE tenant_id=? AND id=?", [$tenantId, $produtoId]);
        echo "apagados: {$n1} rateios, {$n2} movimentações, {$n3} alertas, {$n4} lotes, {$n5} saldos, {$n6} produto\n";
        if ($grupoCriado) echo 'grupo padrão criado pelo teste apagado: '
            . $del("DELETE FROM estoque_grupos WHERE tenant_id=? AND nome='Insumos Agrícolas'", [$tenantId]) . "\n";
        if ($almoxCriado) echo 'almoxarifado criado pelo teste apagado: '
            . $del("DELETE FROM almoxarifados WHERE tenant_id=? AND nome='Almoxarifado Central'", [$tenantId]) . "\n";
        $sobra = (int)vero_val(
            "SELECT (SELECT COUNT(*) FROM estoque_produtos WHERE tenant_id=:t AND codigo='QA-A12')
                  + (SELECT COUNT(*) FROM estoque_movimentacoes m JOIN estoque_produtos p ON p.id=m.produto_id
                      WHERE p.codigo='QA-A12' AND m.tenant_id=:t2)",
            [':t' => $tenantId, ':t2' => $tenantId]);
        echo 'sobras QA-A12 no banco: ' . $sobra . "\n";
    }
}

echo "\nRESULTADO: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
