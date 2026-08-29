<?php
declare(strict_types=1);
/* ============================================================
   VERO — Verificador da cadeia de hash do razão (A3-T2, CLI)
   READ-ONLY: nenhuma escrita. Uso:
     php scripts/verificar_hash_razao.php [tenant_id]
     (sem argumento: verifica todos os tenants com movimentações)

   Para cada tenant, percorre movimentacoes_financeiras em ordem
   de id e confere:
   1. ELO: hash_anterior da linha N == hash_atual da linha N-1
      (primeira linha do tenant deve ter hash_anterior NULL);
   2. CONTEÚDO: hash_atual == SHA-256 recomputado com os campos
      SELADOS atuais (tipo, valor, competência, vencimento,
      descrição, origem) + hash_anterior armazenado.

   Interpretação (comentário do contrato em vero_services.php):
   - conteúdo divergente = campo selado sofreu UPDATE após a
     criação. Linhas anteriores à DB-23 (migration 134) podem
     divergir por reemissões legadas — o script as separa pelo
     marcador substituida_por_id/origem_ativa quando possível;
   - elo quebrado = remoção física ou inserção fora de ordem.
   Baixa/estorno/cancelamento NÃO afetam o hash (status/datas de
   pagamento ficam fora da fórmula). Campos DB-21 idem.
   Exit code: 0 = cadeia íntegra; 1 = divergência encontrada.
   ============================================================ */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

session_start();
$_SESSION['user_id'] = 0;
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';

$pdo = Database::getConnection();

$soTenant = isset($argv[1]) ? (int)$argv[1] : null;
$tenants = $soTenant
    ? [$soTenant]
    : array_map('intval', array_column($pdo->query(
        "SELECT DISTINCT tenant_id FROM movimentacoes_financeiras ORDER BY tenant_id")
        ->fetchAll(PDO::FETCH_ASSOC), 'tenant_id'));

$problemas = 0;

foreach ($tenants as $t) {
    $_SESSION['tenant_id'] = $t; /* vero_srv_fin_hash usa o tenant da sessão */
    $st = $pdo->prepare(
        "SELECT id, tipo, status, valor, data_competencia, data_vencimento, descricao,
                origem_tipo, origem_id, origem_ativa, substituida_por_id,
                hash_anterior, hash_atual
           FROM movimentacoes_financeiras
          WHERE tenant_id = ?
          ORDER BY id");
    $st->execute([$t]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo "== tenant {$t}: " . count($rows) . " movimentação(ões) ==\n";
    $anterior = null; /* hash_atual da linha anterior */
    $ok = 0; $elos = 0; $conteudos = 0;

    foreach ($rows as $r) {
        $id = (int)$r['id'];

        /* 1) elo */
        if ((string)($r['hash_anterior'] ?? '') !== (string)($anterior ?? '')) {
            echo "  ELO QUEBRADO  id={$id}: hash_anterior="
                . substr((string)($r['hash_anterior'] ?? 'NULL'), 0, 12)
                . "… esperado=" . substr((string)($anterior ?? 'NULL'), 0, 12) . "…\n";
            $elos++;
        }

        /* 2) conteúdo (recomputa com os campos atuais) */
        $recalc = vero_srv_fin_hash([
            'tipo' => $r['tipo'], 'valor' => $r['valor'],
            'data_competencia' => $r['data_competencia'], 'data_vencimento' => $r['data_vencimento'],
            'descricao' => $r['descricao'], 'origem_tipo' => $r['origem_tipo'],
            'origem_id' => $r['origem_id'],
        ], $r['hash_anterior'] !== null ? (string)$r['hash_anterior'] : null);

        if ($recalc !== (string)$r['hash_atual']) {
            $legada = $r['status'] === 'cancelado' && $r['substituida_por_id'] === null
                && $r['origem_tipo'] !== null;
            echo "  CONTEÚDO DIVERGE id={$id}"
                . " (status={$r['status']}, origem=" . ($r['origem_tipo'] ?? 'manual')
                . ($legada ? ' — possível reemissão LEGADA pré-DB-23' : '') . ")\n";
            $conteudos++;
        } else {
            $ok++;
        }

        $anterior = (string)$r['hash_atual'];
    }

    echo "  resultado: {$ok} íntegra(s), {$elos} elo(s) quebrado(s), {$conteudos} divergência(s) de conteúdo\n";
    $problemas += $elos + $conteudos;
}

echo $problemas === 0
    ? "CADEIA ÍNTEGRA em " . count($tenants) . " tenant(s).\n"
    : "ATENÇÃO: {$problemas} problema(s) encontrado(s) — investigar antes de validar P-01.\n";
exit($problemas === 0 ? 0 : 1);
