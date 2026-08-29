<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/99_limpeza.php  (A5-QA)
   Remove TUDO que a bateria criou — e SOMENTE isso: todas as
   linhas cujos tenant_id pertencem aos tenants QA (descoberta
   dinâmica de tabelas via information_schema), roles/usuários
   dos tenants QA e os próprios tenants. Idempotente: sem tenant
   QA no banco, é no-op com PASS.
   Uso: php 99_limpeza.php [--dry-run]
   ============================================================ */

require __DIR__ . '/_lib.php';
$dry = in_array('--dry-run', $argv ?? [], true);

qa_section('Limpeza dos tenants QA');
/* TODOS os tenants 'QA BATERIA%' (robusto a duplicatas de nome). Nunca toca o
   tenant 1 nem massa de outros agentes (ex.: usuários qa5.* vivem no tenant 1). */
$ids = qa_tenant_ids_all();

if (!$ids) {
    qa_check('nada a limpar (tenants QA ausentes) — idempotente', true);
    qa_finish('99_limpeza');
}

/* salvaguarda de identidade: cada id é MESMO um tenant "QA BATERIA…" */
$nomes = qa_rows("SELECT id, nome FROM tenants WHERE id IN (" . implode(',', $ids) . ")");
$okIdent = true;
foreach ($nomes as $n) {
    if (!str_starts_with((string)$n['nome'], 'QA BATERIA')) $okIdent = false;
}
qa_check('guard de identidade dos tenants (nome começa com "QA BATERIA")', $okIdent, $nomes);
if (!$okIdent) qa_finish('99_limpeza');

$res = qa_limpar_tenants($ids, $dry);
qa_check(($dry ? '[dry-run] contagem' : 'remoção') . " em {$res['tabelas']} tabelas (" . count($ids) . ' tenant(s))', true, $res);

if (!$dry) {
    qa_eq('tenant QA removido', 0, (int)qa_val("SELECT COUNT(*) FROM tenants WHERE nome LIKE 'QA BATERIA%'"));
    /* SOMENTE os usuários da bateria (prefixo qa. / qa2.) — NÃO os qa5.* de
       outro agente que vivem no tenant 1 e devem permanecer intocados. */
    qa_eq('usuários da bateria removidos', 0, (int)qa_val(
        "SELECT COUNT(*) FROM usuarios WHERE email LIKE 'qa.%@vero.test' OR email LIKE 'qa2.%@vero.test'"));
    /* 2ª chamada prova a idempotência da própria limpeza */
    $res2 = qa_limpar_tenants($ids);
    qa_eq('limpeza idempotente (2ª passada remove 0 linhas)', 0, (int)$res2['linhas']);
}

qa_finish('99_limpeza');
