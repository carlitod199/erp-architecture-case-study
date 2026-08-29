<?php
/* ============================================================
   VERO — Compras / helpers compartilhados (A2-F2-3)
   Incluído por solicitacoes.php, pedidos.php e recebimentos.php.
   ============================================================ */
declare(strict_types=1);

if (!function_exists('compras_next_numero')) {
    /**
     * Próximo número sequencial ATÔMICO por tenant: serializa com GET_LOCK
     * (o COUNT(*)+1 antigo colidia sob concorrência) e usa MAX do sufixo
     * do ano corrente (robusto a cancelamentos).
     */
    function compras_next_numero(string $tabela, string $prefixo): string
    {
        $pdo = vero_pdo();
        $chave = 'vero_seq_' . $tabela . '_' . vero_tenant();
        $pdo->prepare("SELECT GET_LOCK(?, 5)")->execute([$chave]);
        try {
            $pre = $prefixo . date('Y') . '-';
            $max = (int)vero_val(
                "SELECT COALESCE(MAX(CAST(SUBSTRING(numero, :len) AS UNSIGNED)), 0)
                   FROM {$tabela} WHERE tenant_id = :t AND numero LIKE :pre",
                [':len' => strlen($pre) + 1, ':t' => vero_tenant(), ':pre' => $pre . '%']);
            return $pre . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
        } finally {
            $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$chave]);
        }
    }
}
