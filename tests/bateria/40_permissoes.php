<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/40_permissoes.php  (A5-QA)
   Matriz de acesso em nível de AÇÃO (POST): cada perfil faz o
   que pode (positivo) e é barrado no que não pode (negativo),
   sempre com prova no banco. + Cross-tenant e CSRF.
   A matriz de ROTA (GET, 200×403 por perfil) vive no
   10_smoke_rotas. Requer 00 + 20. Uso: php 40_permissoes.php
   ============================================================ */

require __DIR__ . '/_lib.php';
qa_boot_app();
$T   = qa_tenant_id();
$env = qa_env();
$fazQA  = (int)qa_val("SELECT id FROM agro_fazendas WHERE tenant_id=? AND nome='QA Fazenda Bateria'", [$T]);
$movPagar = (int)qa_val("SELECT id FROM movimentacoes_financeiras
    WHERE tenant_id=? AND origem_tipo='compras_recebimento' AND origem_ativa=1", [$T]);
$t2id = (int)qa_val("SELECT id FROM agro_talhoes WHERE tenant_id=? AND codigo='QA-2B'", [$T]);
$alvo = (int)qa_val("SELECT id FROM mip_alvos WHERE tenant_id=? AND nome='QA Traça'", [$T]);
$pFert = (int)qa_val("SELECT id FROM estoque_produtos WHERE tenant_id=? AND codigo='990001'", [$T]);

qa_section('Pré-condições');
qa_check('massa + fluxos presentes', $fazQA && $movPagar && $t2id && $alvo && $pFert,
    compact('fazQA', 'movPagar'));

/* ── GESTOR: opera agro, financeiro só leitura, sem gestão de acessos ── */
qa_section('Perfil gestor');
if (!qa_http_login('gestor')) {
    qa_check('login gestor', false);
} else {
    qa_http_post('gestor', '/fazendas/index.php', ['acao' => 'salvar', 'nome' => 'QA Fazenda Gestor']);
    $fg = (int)qa_val("SELECT id FROM agro_fazendas WHERE tenant_id=? AND nome='QA Fazenda Gestor'", [$T]);
    qa_check('POSITIVO: gestor cria fazenda (agro.fazendas.editar)', $fg > 0);
    qa_http_post('gestor', '/fazendas/index.php', ['acao' => 'excluir', 'id' => (string)$fg]);
    qa_eq('  e inativa a própria fazenda', 0, (int)qa_val("SELECT ativo FROM agro_fazendas WHERE id=?", [$fg]));

    qa_http_post('gestor', '/financeiro/contas_pagar.php', ['acao' => 'baixar', 'id' => (string)$movPagar,
        'data_pagamento' => '2026-07-21', 'forma_pagamento' => 'pix']);
    qa_eq('NEGATIVO: gestor NÃO baixa conta (financeiro só leitura)', 'aberto',
        (string)qa_val("SELECT status FROM movimentacoes_financeiras WHERE id=?", [$movPagar]));

    $usAntes = (int)qa_val("SELECT COUNT(*) FROM usuarios WHERE tenant_id=?", [$T]);
    qa_http_post('gestor', '/configuracoes/usuarios.php', ['acao' => 'salvar',
        'nome' => 'QA Invasor', 'email' => 'qa.invasor@vero.test', 'senha' => 'SenhaForte#1', 'perfil' => 'gestor']);
    qa_eq('NEGATIVO: gestor NÃO cria usuário (config restrita)', $usAntes,
        (int)qa_val("SELECT COUNT(*) FROM usuarios WHERE tenant_id=?", [$T]));
}

/* ── OPERADOR: monitoramento sim; estoque e exclusões não ── */
qa_section('Perfil operador');
if (!qa_http_login('operador')) {
    qa_check('login operador', false);
} else {
    qa_http_post('operador', '/mip/monitoramento.php', ['acao' => 'salvar',
        'data_monitoramento' => '2026-07-12', 'talhao_id' => (string)$t2id,
        'alvo_id' => [(string)$alvo], 'quantidade_encontrada' => ['1'],
        'nivel_infestacao' => [''], 'local_infestacao' => ['folha'], 'severidade_qualitativa' => ['']]);
    $monOp = qa_row("SELECT id, status FROM mip_monitoramentos WHERE tenant_id=? AND data_monitoramento='2026-07-12'", [$T]);
    qa_check('POSITIVO: operador registra monitoramento (rascunho)', (bool)$monOp);

    qa_http_post('operador', '/mip/monitoramento.php', ['acao' => 'excluir', 'id' => (string)($monOp['id'] ?? 0)]);
    qa_check('NEGATIVO: operador NÃO exclui (só .editar, sem .excluir)', (bool)qa_val(
        "SELECT id FROM mip_monitoramentos WHERE id=?", [$monOp['id'] ?? 0]));

    $movAntes = (int)qa_val("SELECT COUNT(*) FROM estoque_movimentacoes WHERE tenant_id=?", [$T]);
    qa_http_post('operador', '/estoque/produtos.php', ['acao' => 'movimentar', 'produto_id' => (string)$pFert,
        'tipo_mov' => 'entrada', 'quantidade' => '5', 'custo_unitario' => '1,00',
        'data_mov' => '2026-07-12', 'validade' => '2026-12-31']);
    qa_eq('NEGATIVO: operador NÃO movimenta estoque (só leitura)', $movAntes,
        (int)qa_val("SELECT COUNT(*) FROM estoque_movimentacoes WHERE tenant_id=?", [$T]));
}

/* ── FINANCEIRO: baixa/estorna sim; agro não ── */
qa_section('Perfil financeiro');
if (!qa_http_login('financeiro')) {
    qa_check('login financeiro', false);
} else {
    qa_http_post('financeiro', '/financeiro/contas_pagar.php', ['acao' => 'baixar', 'id' => (string)$movPagar,
        'data_pagamento' => '2026-07-21', 'forma_pagamento' => 'pix']);
    qa_eq('POSITIVO: financeiro baixa a conta 760', 'pago',
        (string)qa_val("SELECT status FROM movimentacoes_financeiras WHERE id=?", [$movPagar]));
    qa_http_post('financeiro', '/financeiro/contas_pagar.php', ['acao' => 'estornar', 'id' => (string)$movPagar]);
    qa_eq('POSITIVO: financeiro estorna a baixa', 'aberto',
        (string)qa_val("SELECT status FROM movimentacoes_financeiras WHERE id=?", [$movPagar]));

    $fazAntes = (int)qa_val("SELECT COUNT(*) FROM agro_fazendas WHERE tenant_id=?", [$T]);
    qa_http_post('financeiro', '/fazendas/index.php', ['acao' => 'salvar', 'nome' => 'QA Fazenda Financeiro']);
    qa_eq('NEGATIVO: financeiro NÃO cria fazenda', $fazAntes,
        (int)qa_val("SELECT COUNT(*) FROM agro_fazendas WHERE tenant_id=?", [$T]));
}

/* ── CONSULTA: nada de escrita ── */
qa_section('Perfil consulta');
if (!qa_http_login('consulta')) {
    qa_check('login consulta', false);
} else {
    $fazAntes = (int)qa_val("SELECT COUNT(*) FROM agro_fazendas WHERE tenant_id=?", [$T]);
    qa_http_post('consulta', '/fazendas/index.php', ['acao' => 'salvar', 'nome' => 'QA Fazenda Consulta']);
    qa_eq('NEGATIVO: consulta não cria fazenda', $fazAntes,
        (int)qa_val("SELECT COUNT(*) FROM agro_fazendas WHERE tenant_id=?", [$T]));
    qa_http_post('consulta', '/financeiro/contas_pagar.php', ['acao' => 'baixar', 'id' => (string)$movPagar,
        'data_pagamento' => '2026-07-21', 'forma_pagamento' => 'pix']);
    qa_eq('NEGATIVO: consulta não baixa conta', 'aberto',
        (string)qa_val("SELECT status FROM movimentacoes_financeiras WHERE id=?", [$movPagar]));
}

/* ── CROSS-TENANT: tenant QA2 não vê nem edita registros do tenant QA ── */
qa_section('Cross-tenant (tenant QA2)');
if (!qa_http_login('tenant2')) {
    qa_check('login qa2.super', false);
} else {
    $r = qa_http_get('tenant2', '/fazendas/index.php');
    qa_check('lista do tenant 2 NÃO exibe a fazenda do tenant QA',
        !str_contains($r['body'], 'QA Fazenda Bateria'), qa_pagina_saudavel($r));

    qa_http_post('tenant2', '/fazendas/index.php', ['acao' => 'salvar', 'id' => (string)$fazQA,
        'nome' => 'QA HACKEADA CROSS-TENANT']);
    qa_eq('UPDATE cross-tenant sem efeito (escopo vero_update)', 'QA Fazenda Bateria',
        (string)qa_val("SELECT nome FROM agro_fazendas WHERE id=?", [$fazQA]));

    qa_http_post('tenant2', '/fazendas/index.php', ['acao' => 'excluir', 'id' => (string)$fazQA]);
    qa_eq('EXCLUIR cross-tenant sem efeito', 1,
        (int)qa_val("SELECT ativo FROM agro_fazendas WHERE id=?", [$fazQA]));
}

/* ── CSRF em ação de escrita, por um perfil COM permissão ── */
qa_section('CSRF');
if (qa_http_login('financeiro')) {
    $r = qa_http_post('financeiro', '/financeiro/contas_pagar.php',
        ['acao' => 'baixar', 'id' => (string)$movPagar, 'data_pagamento' => '2026-07-21'], false);
    qa_check('POST sem token → rejeitado (HTTP ' . $r['code'] . ', sem efeito)',
        $r['code'] !== 500 && (string)qa_val(
            "SELECT status FROM movimentacoes_financeiras WHERE id=?", [$movPagar]) === 'aberto', $r['code']);
}

qa_finish('40_permissoes');
