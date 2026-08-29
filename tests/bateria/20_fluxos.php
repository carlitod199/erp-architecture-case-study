<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/20_fluxos.php  (A5-QA)
   Fluxos E2E de negócio dirigidos por HTTP REAL (login + CSRF +
   POST nas telas), com asserts de banco contra o GABARITO.md.
   Ordem de execução (dependências de datas/custeio):
     F1 cadastro · F2 compras/estoque · F3 apontamento 2 estágios
     F4 MIP · F5 nutrição · F10 máquinas/patrimônio/irrigação
     F6 colheita→venda · F7 financeiro/hash · F9 folha
     F8 custeio (matriz/resultado/sem-safra/trava) · F11 fiscal
   Requer 00_massa_canonica. Uso: php 20_fluxos.php
   ============================================================ */

require __DIR__ . '/_lib.php';
qa_boot_app();
$env = qa_env();
$D   = $env['datas'];
$T   = qa_tenant_id();
$pdo = qa_pdo();

/* ── ids da massa canônica ── */
function qa_id(string $sql, array $p = []): int { return (int)qa_val($sql, $p); }
$faz   = qa_id("SELECT id FROM agro_fazendas WHERE tenant_id=? AND nome='QA Fazenda Bateria'", [$T]);
$t1    = qa_id("SELECT id FROM agro_talhoes WHERE tenant_id=? AND codigo='QA-1A'", [$T]);
$t2    = qa_id("SELECT id FROM agro_talhoes WHERE tenant_id=? AND codigo='QA-2B'", [$T]);
$set1  = qa_id("SELECT id FROM agro_setores WHERE tenant_id=? AND talhao_id=? AND is_espelho=1", [$T, $t1]);
$cult  = qa_id("SELECT id FROM agro_culturas WHERE tenant_id=? AND nome='QA Uva'", [$T]);
$var   = qa_id("SELECT id FROM agro_variedades WHERE tenant_id=? AND nome='QA Vitória'", [$T]);
$safra = qa_id("SELECT id FROM agro_safras WHERE tenant_id=? AND identificacao='QA 2026/2'", [$T]);
$st1   = qa_id("SELECT id FROM agro_safra_talhoes WHERE tenant_id=? AND safra_id=? AND talhao_id=?", [$T, $safra, $t1]);
$st2   = qa_id("SELECT id FROM agro_safra_talhoes WHERE tenant_id=? AND safra_id=? AND talhao_id=?", [$T, $safra, $t2]);
$clt1  = qa_id("SELECT id FROM agro_operadores WHERE tenant_id=? AND nome='QA Colaborador CLT'", [$T]);
$clt2  = qa_id("SELECT id FROM agro_operadores WHERE tenant_id=? AND nome='QA Colaborador Teto'", [$T]);
$terc  = qa_id("SELECT id FROM rh_terceirizados WHERE tenant_id=? AND nome='QA Produção'", [$T]);
$diar  = qa_id("SELECT id FROM rh_terceirizados WHERE tenant_id=? AND nome='QA Diarista'", [$T]);
$tAtv  = qa_id("SELECT id FROM agro_tipos_atividade WHERE tenant_id=? AND nome='QA Poda'", [$T]);
$pFert = qa_id("SELECT id FROM estoque_produtos WHERE tenant_id=? AND codigo='990001'", [$T]);
$pDef  = qa_id("SELECT id FROM estoque_produtos WHERE tenant_id=? AND codigo='990002'", [$T]);
$almox = qa_id("SELECT id FROM almoxarifados WHERE tenant_id=? ORDER BY id LIMIT 1", [$T]);
$compr = qa_id("SELECT id FROM comercial_compradores WHERE tenant_id=? AND razao_social='QA Comprador LTDA'", [$T]);
$maq1  = qa_id("SELECT id FROM maquinas WHERE tenant_id=? AND codigo='QA-TR1'", [$T]);
$maq2  = qa_id("SELECT id FROM maquinas WHERE tenant_id=? AND codigo='QA-PV1'", [$T]);
$ativoP = qa_id("SELECT id FROM patrimonio_ativos WHERE tenant_id=? AND descricao='QA Pulverizador Patrimônio'", [$T]);
$alvo1 = qa_id("SELECT id FROM mip_alvos WHERE tenant_id=? AND nome='QA Traça'", [$T]);
$alvo2 = qa_id("SELECT id FROM mip_alvos WHERE tenant_id=? AND nome='QA Míldio'", [$T]);
$nutN  = qa_id("SELECT id FROM analise_nutrientes WHERE tenant_id=? AND simbolo='N'", [$T]);
$nutK  = qa_id("SELECT id FROM analise_nutrientes WHERE tenant_id=? AND simbolo='K'", [$T]);

qa_section('Pré-condições');
qa_check('massa canônica presente', $faz && $t1 && $t2 && $set1 && $cult && $var && $safra && $st1 && $st2
    && $clt1 && $terc && $diar && $tAtv && $pFert && $pDef && $compr && $maq1 && $ativoP,
    compact('faz', 't1', 't2', 'set1', 'cult', 'var', 'safra', 'st1', 'clt1', 'tAtv', 'pFert'));
if (!qa_http_login('super')) {
    qa_check('login HTTP qa.super', false, 'base_url inacessível — fluxos HTTP não podem rodar');
    qa_finish('20_fluxos');
}
qa_check('login HTTP qa.super', true);

/* ════════ F1 — Cadastros via tela (CRUD PRG) ════════ */
qa_section('F1 Cadastros via tela');
qa_http_post('super', '/fazendas/index.php', ['acao' => 'salvar', 'nome' => 'QA Fazenda HTTP']);
$fazHttp = qa_id("SELECT id FROM agro_fazendas WHERE tenant_id=? AND nome='QA Fazenda HTTP'", [$T]);
qa_check('fazenda criada via POST', $fazHttp > 0);
qa_http_post('super', '/fazendas/index.php', ['acao' => 'excluir', 'id' => (string)$fazHttp]);
qa_eq('fazenda inativada (soft delete)', 0,
    (int)qa_val("SELECT ativo FROM agro_fazendas WHERE id=?", [$fazHttp]));

/* whitelist/ENUM inválido: categoria inexistente é recusada pela tela */
qa_http_post('super', '/agro/tipos_atividade.php', ['acao' => 'salvar', 'nome' => 'QA Categoria Inválida',
    'categoria' => 'categoria_que_nao_existe']);
qa_eq('categoria fora do domínio recusada (nada gravado)', 0,
    (int)qa_val("SELECT COUNT(*) FROM agro_tipos_atividade WHERE tenant_id=? AND nome='QA Categoria Inválida'", [$T]));

/* ════════ F2 — Compras → estoque → contas a pagar ════════ */
qa_section('F2 Compras/estoque (CMP, FEFO, alçada)');
$pdo->prepare("INSERT INTO fornecedores (tenant_id, nome, ativo) VALUES (?, 'QA Fornecedor LTDA', 1)")->execute([$T]);
$forn = qa_id("SELECT id FROM fornecedores WHERE tenant_id=? AND nome='QA Fornecedor LTDA'", [$T]);

qa_http_post('super', '/compras/pedidos.php', ['acao' => 'salvar', 'fornecedor_id' => (string)$forn,
    'data_pedido' => $D['recebimento'],
    'i_produto' => [(string)$pFert], 'i_descricao' => ['QA-FERT'], 'i_qtd' => ['200'], 'i_valor' => ['3,80']]);
$ped = qa_row("SELECT * FROM compras_pedidos WHERE tenant_id=? AND fornecedor_id=? ORDER BY id DESC LIMIT 1", [$T, $forn]);
qa_check('pedido direto criado (rascunho)', $ped && $ped['status'] === 'rascunho', $ped['status'] ?? null);
qa_eqf('pedido valor 760,00', 760.00, $ped['valor_total'] ?? 0);

qa_http_post('super', '/compras/pedidos.php', ['acao' => 'enviar_aprovacao', 'id' => (string)$ped['id']]);
qa_eq('760 ≤ alçada 1000 → auto-aprovado', 'aprovado',
    (string)qa_val("SELECT status FROM compras_pedidos WHERE id=?", [$ped['id']]));

/* pedido 2 acima da alçada: aprovação manual e cancelamento */
qa_http_post('super', '/compras/pedidos.php', ['acao' => 'salvar', 'fornecedor_id' => (string)$forn,
    'data_pedido' => $D['recebimento'],
    'i_produto' => [(string)$pFert], 'i_descricao' => ['QA-FERT'], 'i_qtd' => ['500'], 'i_valor' => ['3,80']]);
$ped2 = qa_row("SELECT * FROM compras_pedidos WHERE tenant_id=? AND fornecedor_id=? ORDER BY id DESC LIMIT 1", [$T, $forn]);
qa_http_post('super', '/compras/pedidos.php', ['acao' => 'enviar_aprovacao', 'id' => (string)$ped2['id']]);
qa_eq('1.900 > alçada → aguarda aprovação', 'aprovacao',
    (string)qa_val("SELECT status FROM compras_pedidos WHERE id=?", [$ped2['id']]));
$aprId = qa_id("SELECT id FROM compras_aprovacoes WHERE tenant_id=? AND pedido_id=? AND status='pendente'", [$T, $ped2['id']]);
qa_http_post('super', '/compras/aprovacoes.php', ['acao' => 'aprovar', 'aprovacao_id' => (string)$aprId, 'observacao' => 'QA']);
qa_eq('aprovação manual aplicada', 'aprovado',
    (string)qa_val("SELECT status FROM compras_pedidos WHERE id=?", [$ped2['id']]));
qa_http_post('super', '/compras/pedidos.php', ['acao' => 'cancelar', 'id' => (string)$ped2['id']]);
qa_eq('pedido 2 cancelado (sem estoque/financeiro)', 'cancelado',
    (string)qa_val("SELECT status FROM compras_pedidos WHERE id=?", [$ped2['id']]));

/* recebimento do pedido 1 → entrada estoque + conta a pagar */
$pedItem = qa_id("SELECT id FROM compras_pedido_itens WHERE tenant_id=? AND pedido_id=?", [$T, $ped['id']]);
qa_http_post('super', '/compras/recebimentos.php', ['acao' => 'receber', 'pedido_id' => (string)$ped['id'],
    'data_recebimento' => $D['recebimento'], 'data_vencimento' => $D['vencimento'],
    'i_item' => [(string)$pedItem], 'i_qtd' => ['200'], 'i_custo' => ['3,80'], 'i_validade' => ['2026-10-31']]);
$s = qa_row("SELECT quantidade, custo_medio, valor_total FROM estoque_saldos WHERE tenant_id=? AND produto_id=?", [$T, $pFert]);
qa_eqf('saldo QA-FERT 290 kg', 290.0, $s['quantidade'] ?? -1);
qa_eqf('custo médio ponderado 3,706897', 3.706897, $s['custo_medio'] ?? -1, 0.000002);
qa_eqf('valor em estoque 1.075,00', 1075.00, $s['valor_total'] ?? -1);
qa_eq('2 lotes de QA-FERT (validades 2026-10-31 e 2026-12-31)', 2,
    (int)qa_val("SELECT COUNT(*) FROM estoque_lotes WHERE tenant_id=? AND produto_id=?", [$T, $pFert]));
qa_eq('pedido totalmente recebido', 'recebido',
    (string)qa_val("SELECT status FROM compras_pedidos WHERE id=?", [$ped['id']]));
$cp = qa_rows("SELECT * FROM movimentacoes_financeiras WHERE tenant_id=? AND origem_tipo='compras_recebimento' AND origem_ativa=1", [$T]);
qa_eq('conta a pagar única (idempotente por origem)', 1, count($cp));
qa_eqf('conta a pagar 760,00 em aberto', 760.00, $cp[0]['valor'] ?? 0);
$movPagarId = (int)($cp[0]['id'] ?? 0);

/* ════════ F3 — Apontamento dois estágios + premiação + idempotência ════════ */
qa_section('F3 Apontamento dois estágios');
$cabApont = ['data_apontamento' => $D['apontamento'], 'talhao_id' => (string)$t1,
    'safra_talhao_id' => (string)$st1, 'tipo_atividade_id' => (string)$tAtv,
    'responsavel_id' => (string)$clt1, 'hectares' => '4,00', 'fase_ref' => ''];
qa_http_post('super', '/agro/apontamentos.php', ['acao' => 'iniciar'] + $cabApont);
$ap = qa_row("SELECT * FROM agro_apontamentos WHERE tenant_id=? AND talhao_id=? ORDER BY id DESC LIMIT 1", [$T, $t1]);
qa_check('estágio 1: apontamento iniciado + OS', $ap && $ap['status'] === 'iniciado' && $ap['ordem_servico_id'],
    ['status' => $ap['status'] ?? null, 'os' => $ap['ordem_servico_id'] ?? null]);
qa_eq('iniciado ainda sem custeio', 0, (int)qa_val(
    "SELECT COUNT(*) FROM custeio_lancamentos WHERE tenant_id=? AND origem_tipo='rh_producao_item'", [$T]));

$realizado = [
    'l_origem' => ['colaborador', 'terceirizado', 'terceirizado'],
    'l_pessoa' => [(string)$clt1, (string)$terc, (string)$diar],
    'l_modalidade' => ['', 'producao', 'diaria'],
    'l_qtd' => ['130', '120', '1'],
    'l_peso' => ['', '', ''],
    'l_meta' => ['100', '', ''],
    'l_valor' => ['1,20', '2,00', '90,00'],
    'i_produto' => [(string)$pFert], 'i_qtd' => ['10'], 'i_dose' => [''],
];
qa_http_post('super', '/agro/apontamentos.php', ['acao' => 'finalizar', 'id' => (string)$ap['id']] + $cabApont + $realizado);

$itens = qa_rows("SELECT * FROM rh_producao_itens WHERE tenant_id=? AND apontamento_id=? ORDER BY id", [$T, $ap['id']]);
qa_eq('3 pessoas no realizado', 3, count($itens));
qa_eqf('premiação CLT 130cx meta 100 × 1,20 = 36,00', 36.00, $itens[0]['valor_total'] ?? -1);
qa_eqf('  snapshot meta_aplicada = 100', 100.0, $itens[0]['meta_aplicada'] ?? -1);
qa_eqf('  qtd_acima_meta = 30', 30.0, $itens[0]['qtd_acima_meta'] ?? -1);
qa_eqf('produção terceirizado 120 × 2,00 = 240,00', 240.00, $itens[1]['valor_total'] ?? -1);
qa_eqf('diária 1 × 90,00 = 90,00', 90.00, $itens[2]['valor_total'] ?? -1);

$mdo = (float)qa_val("SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos
    WHERE tenant_id=? AND origem_tipo='rh_producao_item'", [$T]);
qa_eqf('custeio mão de obra 366,00', 366.00, $mdo);
$ins = qa_row("SELECT COALESCE(SUM(valor),0) v, COUNT(*) n FROM custeio_lancamentos
    WHERE tenant_id=? AND origem_tipo='apontamento_insumo'", [$T]);
qa_eqf('custeio insumos 37,07 (baixa ao CM)', 37.07, $ins['v']);
qa_eq('  em 1 lançamento', 1, (int)$ins['n']);

/* FEFO: a saída consumiu o lote de validade MAIS PRÓXIMA (2026-10-31) */
$fefo = qa_row(
    "SELECT l.validade, l.quantidade FROM estoque_movimentacao_lotes ml
       JOIN estoque_lotes l ON l.id = ml.lote_id
       JOIN estoque_movimentacoes m ON m.id = ml.movimentacao_id
      WHERE ml.tenant_id=? AND m.origem_tipo='apontamento_insumo' AND m.estornado_em IS NULL", [$T]);
qa_eq('FEFO consumiu o lote 2026-10-31', '2026-10-31', $fefo['validade'] ?? '?');
qa_eqf('  lote próximo ficou com 190 kg', 190.0, $fefo['quantidade'] ?? -1);
qa_eqf('  lote 2026-12-31 intacto (90 kg)', 90.0,
    qa_val("SELECT quantidade FROM estoque_lotes WHERE tenant_id=? AND produto_id=? AND validade='2026-12-31'", [$T, $pFert]));
qa_eqf('saldo pós-saída 280 kg', 280.0,
    qa_val("SELECT quantidade FROM estoque_saldos WHERE tenant_id=? AND produto_id=?", [$T, $pFert]));

/* Idempotência: reedição (salvar) com os MESMOS valores não duplica nada */
qa_http_post('super', '/agro/apontamentos.php', ['acao' => 'salvar', 'id' => (string)$ap['id']] + $cabApont + $realizado);
qa_eqf('reedição: custeio MDO segue 366,00', 366.00, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND origem_tipo='rh_producao_item'", [$T]));
qa_eqf('reedição: custeio insumos segue 37,07 (1 linha)', 37.07, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND origem_tipo='apontamento_insumo'", [$T]));
qa_eqf('reedição: saldo segue 280 kg', 280.0,
    qa_val("SELECT quantidade FROM estoque_saldos WHERE tenant_id=? AND produto_id=?", [$T, $pFert]));
qa_eq('reedição: exatamente 1 saída ATIVA + estorno lógico auditável', 1, (int)qa_val(
    "SELECT COUNT(*) FROM estoque_movimentacoes
      WHERE tenant_id=? AND origem_tipo='apontamento_insumo' AND tipo='saida' AND estornado_em IS NULL", [$T]));

/* ════════ F4 — MIP: monitoramento multialvo → aplicação DF ════════ */
qa_section('F4 MIP');
qa_http_post('super', '/mip/monitoramento.php', ['acao' => 'salvar',
    'data_monitoramento' => $D['monitoramento'], 'talhao_id' => (string)$t2, 'safra_talhao_id' => (string)$st2,
    'alvo_id' => [(string)$alvo1, (string)$alvo2],
    'quantidade_encontrada' => ['8', '2'], 'nivel_infestacao' => ['', ''],
    'local_infestacao' => ['folha', 'folha'], 'severidade_qualitativa' => ['', ''],
    'enviar' => '1']);
$mon = qa_row("SELECT * FROM mip_monitoramentos WHERE tenant_id=? ORDER BY id DESC LIMIT 1", [$T]);
qa_check('monitoramento enviado', $mon && (string)$mon['status'] === 'enviado', $mon['status'] ?? null);
qa_eq('2 alvos na junção multialvo', 2, (int)qa_val(
    "SELECT COUNT(*) FROM mip_monitoramento_alvos WHERE tenant_id=? AND monitoramento_id=?", [$T, $mon['id'] ?? 0]));
qa_eq('1 alerta MIP (só o alvo ≥ nível de ação 5)', 1, (int)qa_val(
    "SELECT COUNT(*) FROM agro_alertas WHERE tenant_id=? AND categoria='mip' AND origem_id=?", [$T, $mon['id'] ?? 0]));

qa_http_post('super', '/mip/aplicacoes.php', ['acao' => 'salvar', 'modo' => 'direto',
    'tipo' => 'pulverizacao', 'talhao_id' => (string)$t2, 'data' => $D['aplicacao'],
    'safra_id' => (string)$safra, 'maquina_ids' => [(string)$maq1, (string)$maq2],
    'condicao_ceu' => 'sol', 'volume_calda_ha_l' => '500', 'fase_ref' => '',
    'i_produto' => [(string)$pDef], 'i_qtd' => ['2'], 'i_dose' => ['0,5'], 'i_dose_un' => ['L']]);
$apl = qa_row("SELECT * FROM agro_aplicacoes WHERE tenant_id=? ORDER BY id DESC LIMIT 1", [$T]);
qa_check('aplicação registrada com DF numerada', $apl && $apl['status'] === 'registrada'
    && (string)$apl['doc_serie'] === 'DF' && (int)$apl['doc_numero'] >= 1,
    ['status' => $apl['status'] ?? null, 'doc' => ($apl['doc_serie'] ?? '') . '-' . ($apl['doc_numero'] ?? '')]);
qa_eq('2 maquinários vinculados', 2, (int)qa_val(
    "SELECT COUNT(*) FROM agro_aplicacao_maquinas WHERE tenant_id=? AND aplicacao_id=?", [$T, $apl['id'] ?? 0]));
qa_eq('condição do céu = sol', 'sol', (string)($apl['condicao_ceu'] ?? '?'));
qa_eqf('calda 500 L/ha', 500.0, $apl['volume_calda_ha_l'] ?? -1);
qa_eq('fase por variedade: 40 dias desde a poda (Brotação)', 40, (int)($apl['dias_desde_poda'] ?? -1));
qa_eqf('custeio insumos da aplicação 24,00', 24.00, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND origem_tipo='aplicacao' AND origem_id=?",
    [$T, $apl['id'] ?? 0]));
qa_eqf('QA-DEF saldo 8 L após baixa FEFO', 8.0,
    qa_val("SELECT quantidade FROM estoque_saldos WHERE tenant_id=? AND produto_id=?", [$T, $pDef]));

/* ════════ F5 — Nutrição ════════ */
qa_section('F5 Nutrição');
qa_http_post('super', '/nutricao/analise_foliar.php', ['acao' => 'salvar',
    'data_amostra' => $D['analise'], 'talhao_id' => (string)$t1, 'safra_id' => (string)$safra,
    'variedade_id' => (string)$var,
    'r_nutriente' => [(string)$nutN, (string)$nutK], 'r_valor' => ['1,8', '3,0'], 'r_unidade' => ['g/kg', 'g/kg']]);
$anl = qa_row("SELECT * FROM analise_foliar WHERE tenant_id=? ORDER BY id DESC LIMIT 1", [$T]);
qa_check('análise foliar criada', (bool)$anl);
qa_eq('N=1,8 < mín 2,0 → muito_baixo', 'muito_baixo', (string)qa_val(
    "SELECT classificacao FROM analise_foliar_resultados WHERE tenant_id=? AND analise_id=? AND nutriente_id=?",
    [$T, $anl['id'] ?? 0, $nutN]));
qa_eq('K=3,0 dentro do ideal → adequado', 'adequado', (string)qa_val(
    "SELECT classificacao FROM analise_foliar_resultados WHERE tenant_id=? AND analise_id=? AND nutriente_id=?",
    [$T, $anl['id'] ?? 0, $nutK]));
$al = qa_row("SELECT severidade, requer_validacao_tecnica FROM agro_alertas
    WHERE tenant_id=? AND categoria='nutricao' AND origem_id=?", [$T, $anl['id'] ?? 0]);
qa_check('alerta de nutrição crítico com validação técnica', $al
    && $al['severidade'] === 'critico' && (int)$al['requer_validacao_tecnica'] === 1, $al);

/* ════════ F10 — Máquinas, patrimônio, irrigação (antes da colheita p/ custo provisório) ════════ */
qa_section('F10 Máquinas/patrimônio/irrigação');
qa_http_post('super', '/maquinas/abastecimento.php', ['acao' => 'salvar', 'maquina_id' => (string)$maq1,
    'litros' => '50', 'valor_total' => '300,00', 'horimetro' => '100', 'data_abastecimento' => $D['abastecimento']]);
qa_eq('abastecimento gravado', 1, (int)qa_val(
    "SELECT COUNT(*) FROM maquina_abastecimentos WHERE tenant_id=? AND maquina_id=?", [$T, $maq1]));
qa_eqf('horímetro atualizado p/ 100', 100.0, qa_val("SELECT horimetro_atual FROM maquinas WHERE id=?", [$maq1]));
qa_eqf('custeio máquinas 300,00', 300.00, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND origem_tipo='maquina_abastecimento'", [$T]));

/* NEGATIVO: horímetro regressivo é rejeitado */
qa_http_post('super', '/maquinas/abastecimento.php', ['acao' => 'salvar', 'maquina_id' => (string)$maq1,
    'litros' => '10', 'valor_total' => '60,00', 'horimetro' => '90', 'data_abastecimento' => $D['abastecimento']]);
qa_eq('horímetro regressivo REJEITADO (segue 1 abastecimento)', 1, (int)qa_val(
    "SELECT COUNT(*) FROM maquina_abastecimentos WHERE tenant_id=? AND maquina_id=?", [$T, $maq1]));
qa_eqf('horímetro não regrediu (100)', 100.0, qa_val("SELECT horimetro_atual FROM maquinas WHERE id=?", [$maq1]));

/* depreciação linear idempotente */
qa_http_post('super', '/patrimonio/depreciacao_gerencial.php', ['acao' => 'gerar', 'competencia' => '2026-07']);
$dep = qa_row("SELECT * FROM patrimonio_depreciacoes WHERE tenant_id=? AND ativo_id=?", [$T, $ativoP]);
qa_eqf('depreciação (250.000−50.000)/120 = 1.666,67', 1666.67, $dep['valor'] ?? -1, 0.01);
qa_http_post('super', '/patrimonio/depreciacao_gerencial.php', ['acao' => 'gerar', 'competencia' => '2026-07']);
qa_eq('gerar 2× → segue 1 linha na competência (idempotente)', 1, (int)qa_val(
    "SELECT COUNT(*) FROM patrimonio_depreciacoes WHERE tenant_id=? AND ativo_id=?", [$T, $ativoP]));
qa_eqf('custeio depreciação 1.666,67', 1666.67, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND origem_tipo='patrimonio_depreciacao'", [$T]), 0.01);

/* irrigação com consumos → custeio (e reedição idempotente) */
$postIrr = ['acao' => 'salvar', 'talhao_id' => (string)$t1, 'safra_talhao_id' => (string)$st1,
    'data_apontamento' => $D['irrigacao'], 'horas' => '2', 'lamina_mm' => '10',
    'agua_qtd' => '100', 'agua_custo' => '80,00', 'energia_qtd' => '200', 'energia_custo' => '120,00'];
qa_http_post('super', '/irrigacao/apontamentos_irrigacao.php', $postIrr);
$irr = qa_row("SELECT * FROM irrigacao_apontamentos WHERE tenant_id=? ORDER BY id DESC LIMIT 1", [$T]);
qa_eqf('custeio irrigação 200,00 (água 80 + energia 120)', 200.00, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND origem_tipo='irrigacao_consumo'", [$T]));
qa_http_post('super', '/irrigacao/apontamentos_irrigacao.php', $postIrr + ['id' => (string)($irr['id'] ?? 0)]);
qa_eqf('reedição da irrigação não duplica (segue 200,00)', 200.00, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND origem_tipo='irrigacao_consumo'", [$T]));

qa_eqf('valor patrimonial = 248.333,33', 248333.33,
    250000.00 - (float)qa_val("SELECT COALESCE(SUM(valor),0) FROM patrimonio_depreciacoes WHERE tenant_id=?", [$T]), 0.01);

/* ════════ F6 — Colheita → venda → receber ════════ */
qa_section('F6 Colheita → venda → receber');
qa_http_post('super', '/colheita/index.php', ['acao' => 'salvar',
    'data_colheita' => $D['colheita'], 'setor_id' => (string)$set1, 'safra_talhao_id' => (string)$st1,
    'variedade_id' => (string)$var, 'colheita_unidade_entrada' => 'kg',
    'producao_prevista_kg_ha' => '25.000', 'producao_realizada_kg_ha' => '23.937,5',
    'c_pct' => ['previsto' => ['cat1' => '100'], 'realizado' => ['premium' => '40', 'cat1' => '40', 'cat2' => '20']],
    'c_preco' => ['previsto' => ['cat1' => '5,20'], 'realizado' => ['premium' => '6,50', 'cat1' => '5,00', 'cat2' => '4,15']]]);
$col = qa_row("SELECT * FROM colheita_registros WHERE tenant_id=? ORDER BY id DESC LIMIT 1", [$T]);
qa_eqf('kg previsto 25.000 × 4 ha = 100.000', 100000.0, $col['kg_total_previsto'] ?? -1);
qa_eqf('faturamento previsto 520.000,00', 520000.00, $col['faturamento_previsto'] ?? -1);
qa_eqf('kg realizado 95.750', 95750.0, $col['kg_total_realizado'] ?? -1);
qa_eqf('faturamento realizado 519.922,50', 519922.50, $col['faturamento_realizado'] ?? -1);

/* entrada no estoque (lote COLH-) com custo provisório P-85 + idempotência */
qa_http_post('super', '/colheita/index.php', ['acao' => 'entrada_confirmar', 'id' => (string)($col['id'] ?? 0)]);
$lote = qa_row("SELECT * FROM estoque_lotes WHERE tenant_id=? AND codigo_lote LIKE 'COLH-%'", [$T]);
qa_check('lote COLH- criado e disponível', $lote && (string)$lote['status'] === 'disponivel', $lote['status'] ?? null);
qa_eqf('lote com 95.750 kg', 95750.0, $lote['quantidade'] ?? -1);
qa_eqf('custo provisório 603,07 ÷ 95.750 = 0,006298/kg', 0.006298, $lote['custo_unitario'] ?? -1, 0.000005);
qa_http_post('super', '/colheita/index.php', ['acao' => 'entrada_confirmar', 'id' => (string)($col['id'] ?? 0)]);
qa_eq('confirmar 2× → segue 1 lote COLH (idempotente)', 1, (int)qa_val(
    "SELECT COUNT(*) FROM estoque_lotes WHERE tenant_id=? AND codigo_lote LIKE 'COLH-%'", [$T]));

/* NEGATIVO: venda sem lote/colheita */
$antes = (int)qa_val("SELECT COUNT(*) FROM comercial_vendas WHERE tenant_id=?", [$T]);
qa_http_post('super', '/comercial/vendas.php', ['acao' => 'salvar', 'comprador_id' => (string)$compr,
    'data_venda' => $D['venda'], 'kg_total' => '100', 'q_pct' => ['cat1' => '100'], 'q_preco' => ['cat1' => '1,00']]);
qa_eq('venda sem lote recusada', $antes, (int)qa_val("SELECT COUNT(*) FROM comercial_vendas WHERE tenant_id=?", [$T]));

/* venda real */
$postVenda = ['acao' => 'salvar', 'comprador_id' => (string)$compr, 'lote_id' => (string)($lote['id'] ?? 0),
    'data_venda' => $D['venda'], 'data_vencimento' => $D['vencimento'], 'kg_total' => '95.750', 'parcelas' => '1',
    'q_pct' => ['premium' => '40', 'cat1' => '40', 'cat2' => '20'],
    'q_preco' => ['premium' => '6,50', 'cat1' => '5,00', 'cat2' => '4,15']];
qa_http_post('super', '/comercial/vendas.php', $postVenda);
$vd = qa_row("SELECT * FROM comercial_vendas WHERE tenant_id=? ORDER BY id DESC LIMIT 1", [$T]);
qa_check('venda confirmada', $vd && $vd['status'] === 'confirmada', $vd['status'] ?? null);
qa_eqf('venda 95.750 kg', 95750.0, $vd['kg_total'] ?? -1);
qa_eqf('faturamento 519.922,50', 519922.50, $vd['valor_total'] ?? -1);
$rec = qa_rows("SELECT * FROM movimentacoes_financeiras
    WHERE tenant_id=? AND origem_tipo='comercial_venda' AND origem_ativa=1", [$T]);
qa_eq('conta a RECEBER única', 1, count($rec));
qa_eqf('título 519.922,50 em aberto', 519922.50, $rec[0]['valor'] ?? 0);
$cpv = (float)qa_val("SELECT COALESCE(SUM(valor_total),0) FROM estoque_movimentacoes
    WHERE tenant_id=? AND origem_tipo='comercial_venda' AND tipo='saida' AND estornado_em IS NULL", [$T]);
qa_eqf('CPV = 95.750 × custo do lote ≈ 603,03', 603.03, $cpv, 0.60);

/* reedição muda campo SELADO (vencimento) → cancela + reemite (DB-23), segue 1 ativa.
   array_merge (não '+'): o operador de união preserva a chave antiga e o vencimento
   nunca mudaria — o teste ficaria falso-verde. */
qa_http_post('super', '/comercial/vendas.php', array_merge($postVenda, ['id' => (string)$vd['id'], 'data_vencimento' => '2026-08-20']));
qa_eq('reedição: segue exatamente 1 título ATIVO', 1, (int)qa_val(
    "SELECT COUNT(*) FROM movimentacoes_financeiras WHERE tenant_id=? AND origem_tipo='comercial_venda' AND origem_ativa=1", [$T]));
$cancelado = qa_row("SELECT * FROM movimentacoes_financeiras
    WHERE tenant_id=? AND origem_tipo='comercial_venda' AND status='cancelado' ORDER BY id DESC LIMIT 1", [$T]);
qa_check('título antigo cancelado com substituida_por_id (razão INSERT-only)',
    $cancelado && $cancelado['substituida_por_id'] !== null, $cancelado['substituida_por_id'] ?? null);
$movReceberId = qa_id("SELECT id FROM movimentacoes_financeiras
    WHERE tenant_id=? AND origem_tipo='comercial_venda' AND origem_ativa=1", [$T]);

/* ════════ F7 — Financeiro: baixa, estorno, fluxo, hash ════════ */
qa_section('F7 Financeiro');
qa_http_post('super', '/financeiro/contas_pagar.php', ['acao' => 'baixar', 'id' => (string)$movPagarId,
    'data_pagamento' => $D['baixa_pagar'], 'forma_pagamento' => 'pix']);
qa_eq('760 baixada (pago)', 'pago', (string)qa_val("SELECT status FROM movimentacoes_financeiras WHERE id=?", [$movPagarId]));
qa_http_post('super', '/financeiro/contas_receber.php', ['acao' => 'baixar', 'id' => (string)$movReceberId,
    'data_pagamento' => $D['baixa_receber'], 'forma_pagamento' => 'ted']);
qa_eq('venda baixada (pago)', 'pago', (string)qa_val("SELECT status FROM movimentacoes_financeiras WHERE id=?", [$movReceberId]));
qa_eq('  propagou p/ venda', 'pago', (string)qa_val("SELECT status_pagamento FROM comercial_vendas WHERE id=?", [$vd['id']]));

$fluxo = qa_row("SELECT
      COALESCE(SUM(CASE WHEN tipo='receber' THEN valor END),0) ent,
      COALESCE(SUM(CASE WHEN tipo='pagar' THEN valor END),0) sai
    FROM movimentacoes_financeiras
    WHERE tenant_id=? AND status='pago' AND data_pagamento BETWEEN '2026-07-01' AND '2026-07-31'", [$T]);
qa_eqf('fluxo de caixa jul/26: entradas 519.922,50', 519922.50, $fluxo['ent']);
qa_eqf('fluxo de caixa jul/26: saídas 760,00', 760.00, $fluxo['sai']);

/* CSRF NEGATIVO: estorno sem token não pode ter efeito */
qa_http_post('super', '/financeiro/contas_pagar.php', ['acao' => 'estornar', 'id' => (string)$movPagarId], false);
qa_eq('POST sem CSRF rejeitado (760 segue paga)', 'pago',
    (string)qa_val("SELECT status FROM movimentacoes_financeiras WHERE id=?", [$movPagarId]));

qa_http_post('super', '/financeiro/contas_pagar.php', ['acao' => 'estornar', 'id' => (string)$movPagarId]);
$m = qa_row("SELECT status, data_pagamento, forma_pagamento FROM movimentacoes_financeiras WHERE id=?", [$movPagarId]);
qa_check('estorno reabre e LIMPA forma de pagamento (A3-T34)',
    $m && $m['status'] === 'aberto' && $m['data_pagamento'] === null && $m['forma_pagamento'] === null, $m);
qa_http_post('super', '/financeiro/contas_receber.php', ['acao' => 'estornar', 'id' => (string)$movReceberId]);
qa_eq('venda reaberta', 'pendente', (string)qa_val("SELECT status_pagamento FROM comercial_vendas WHERE id=?", [$vd['id']]));
$fluxo2 = qa_val("SELECT COUNT(*) FROM movimentacoes_financeiras
    WHERE tenant_id=? AND status='pago' AND data_pagamento IS NOT NULL", [$T]);
qa_eq('fluxo realizado zerado após estornos', 0, (int)$fluxo2);

/* NEGATIVO: cancelar título com origem é recusado */
qa_http_post('super', '/financeiro/contas_pagar.php', ['acao' => 'excluir', 'id' => (string)$movPagarId]);
qa_eq('título com origem não cancela pela tela', 'aberto',
    (string)qa_val("SELECT status FROM movimentacoes_financeiras WHERE id=?", [$movPagarId]));

/* hash-chain íntegro (mesma verificação do verificador_razao) */
$movs = qa_rows("SELECT * FROM movimentacoes_financeiras WHERE tenant_id=? ORDER BY id", [$T]);
$erros = 0;
$prev = null;
foreach ($movs as $mv) {
    if ((string)($mv['hash_anterior'] ?? '') !== (string)($prev ?? '')) $erros++;
    $re = vero_srv_fin_hash([
        'tipo' => $mv['tipo'], 'valor' => $mv['valor'],
        'data_competencia' => $mv['data_competencia'], 'data_vencimento' => $mv['data_vencimento'],
        'descricao' => $mv['descricao'], 'origem_tipo' => $mv['origem_tipo'], 'origem_id' => $mv['origem_id'],
    ], $prev);
    if ($re !== (string)$mv['hash_atual']) $erros++;
    $prev = (string)$mv['hash_atual'];
}
qa_check('hash-chain do tenant QA íntegro (' . count($movs) . ' elos, 0 divergências)', $erros === 0, $erros);

/* ════════ F9 — Folha (INSS/IRRF persistidos) ════════ */
qa_section('F9 Folha');
qa_http_post('super', '/pessoas/folha.php', ['acao' => 'criar_periodo', 'competencia' => '2026-07']);
$per = qa_row("SELECT * FROM rh_folha_periodos WHERE tenant_id=? AND competencia='2026-07-01'", [$T]);
qa_check('período jul/26 criado', (bool)$per);
qa_http_post('super', '/pessoas/folha.php', ['acao' => 'gerar', 'periodo_id' => (string)($per['id'] ?? 0)]);
$lan = qa_rows("SELECT l.*, o.nome FROM rh_folha_lancamentos l
    JOIN agro_operadores o ON o.id = l.operador_id
    WHERE l.tenant_id=? AND l.periodo_id=? ORDER BY o.nome", [$T, $per['id'] ?? 0]);
qa_eq('2 lançamentos (CLT1 e CLT2)', 2, count($lan));
$l1 = $lan[0] ?? [];  /* QA Colaborador CLT */
$l2 = $lan[1] ?? [];  /* QA Colaborador Teto */
qa_eqf('CLT1 INSS 130,23 (bruto 1.700 = 1.664 + prem. 36)', 130.23, $l1['desc_inss'] ?? -1);
qa_eqf('CLT1 IRRF 0,00', 0.00, $l1['desc_irrf'] ?? -1);
qa_eqf('CLT1 líquido 1.569,77', 1569.77, $l1['liquido'] ?? -1);
qa_eqf('CLT2 INSS teto 2026 = 951,63', 951.63, $l2['desc_inss'] ?? -1);
qa_eqf('CLT2 IRRF 1.304,57', 1304.57, $l2['desc_irrf'] ?? -1);
qa_eqf('CLT2 líquido 6.743,80', 6743.80, $l2['liquido'] ?? -1);

qa_http_post('super', '/pessoas/folha.php', ['acao' => 'gerar', 'periodo_id' => (string)($per['id'] ?? 0)]);
qa_eq('regerar não duplica (segue 2 lançamentos)', 2, (int)qa_val(
    "SELECT COUNT(*) FROM rh_folha_lancamentos WHERE tenant_id=? AND periodo_id=?", [$T, $per['id'] ?? 0]));

qa_http_post('super', '/pessoas/folha.php', ['acao' => 'status', 'periodo_id' => (string)($per['id'] ?? 0), 'novo_status' => 'fechado']);
qa_eqf('fechar → custeio MDO da folha 16.574,68 (custo − premiações)', 16574.68, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND origem_tipo='rh_folha_lancamento'", [$T]), 0.05);

/* ════════ F8 — Custeio: matriz, resultado, sem-safra, trava ════════ */
qa_section('F8 Custeio');
$mat = [];
foreach (qa_rows("SELECT talhao_id, categoria, SUM(valor) v FROM custeio_lancamentos
    WHERE tenant_id=? AND talhao_id IS NOT NULL GROUP BY talhao_id, categoria", [$T]) as $r) {
    $mat[(int)$r['talhao_id']][(string)$r['categoria']] = (float)$r['v'];
}
qa_eqf('matriz QA-1A insumos 37,07', 37.07, $mat[$t1]['insumos'] ?? 0);
qa_eqf('matriz QA-1A mão de obra 366,00', 366.00, $mat[$t1]['mao_de_obra'] ?? 0);
qa_eqf('matriz QA-1A irrigação 200,00', 200.00, $mat[$t1]['irrigacao'] ?? 0);
qa_eqf('matriz QA-2B insumos 24,00', 24.00, $mat[$t2]['insumos'] ?? 0);
qa_eqf('custo/ha QA-1A = 603,07 ÷ 4,00 = 150,77', 150.77, array_sum($mat[$t1] ?? []) / 4.0, 0.01);

$custoSafra = (float)qa_val("SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND safra_id=?", [$T, $safra]);
$vendasSafra = (float)qa_val("SELECT COALESCE(SUM(valor_total),0) FROM comercial_vendas
    WHERE tenant_id=? AND safra_id=? AND status<>'cancelada'", [$T, $safra]);
qa_eqf('custeio da safra 627,07', 627.07, $custoSafra);
qa_eqf('vendas da safra 519.922,50', 519922.50, $vendasSafra);
qa_eqf('resultado da safra 519.295,43', 519295.43, $vendasSafra - $custoSafra);
qa_eqf('margem 99,88%', 99.88, $vendasSafra > 0 ? ($vendasSafra - $custoSafra) / $vendasSafra * 100 : 0, 0.01);

/* atribuição sem-safra manual (P-98): aplicar, conferir e desfazer */
$semSafra = (float)qa_val("SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND safra_id IS NULL", [$T]);
qa_eqf('sem-safra antes: 300 + 1.666,67 + 16.574,68 = 18.541,35', 18541.35, $semSafra, 0.05);
qa_http_post('super', '/custeio/rateios.php', ['acao' => 'atribuir_sem_safra', 'competencia' => '2026-07']);
$semSafraDepois = (float)qa_val("SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND safra_id IS NULL", [$T]);
$atribuiu = abs($semSafraDepois) < 0.05 || $semSafraDepois < $semSafra - 0.05;
qa_check('atribuição sem-safra executada (líquido sem-safra reduzido/zerado)', $atribuiu,
    ['antes' => $semSafra, 'depois' => $semSafraDepois]);
qa_http_post('super', '/custeio/rateios.php', ['acao' => 'desfazer_sem_safra', 'competencia' => '2026-07']);
qa_eqf('desfazer devolve o sem-safra a 18.541,35', 18541.35, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND safra_id IS NULL", [$T]), 0.05);
qa_eqf('  e o custeio da safra volta a 627,07', 627.07, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND safra_id=?", [$T, $safra]));

/* trava de fechamento (P-06): safra fechada bloqueia lançamentos */
qa_http_post('super', '/custeio/fechamento.php', ['acao' => 'fechar', 'safra_id' => (string)$safra]);
qa_eq('safra fechada', 'fechado', (string)qa_val(
    "SELECT status FROM custeio_fechamentos WHERE tenant_id=? AND safra_id=?", [$T, $safra]));
$apAntes = (int)qa_val("SELECT COUNT(*) FROM agro_apontamentos WHERE tenant_id=?", [$T]);
qa_http_post('super', '/agro/apontamentos.php', ['acao' => 'iniciar'] + $cabApont);
qa_eq('apontamento em safra FECHADA bloqueado', $apAntes, (int)qa_val(
    "SELECT COUNT(*) FROM agro_apontamentos WHERE tenant_id=?", [$T]));
$fechId = (int)qa_val("SELECT id FROM custeio_fechamentos WHERE tenant_id=? AND safra_id=?", [$T, $safra]);
qa_http_post('super', '/custeio/fechamento.php', ['acao' => 'reabrir', 'id' => (string)$fechId]);
qa_eq('safra reaberta', 'reaberto', (string)qa_val(
    "SELECT status FROM custeio_fechamentos WHERE tenant_id=? AND safra_id=?", [$T, $safra]));

/* reabrir a folha remove o custeio dela (idempotência do emissor) */
qa_http_post('super', '/pessoas/folha.php', ['acao' => 'status', 'periodo_id' => (string)($per['id'] ?? 0), 'novo_status' => 'aberto']);
qa_eqf('reabrir folha remove custeio da folha', 0.0, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND origem_tipo='rh_folha_lancamento'", [$T]));
qa_http_post('super', '/pessoas/folha.php', ['acao' => 'status', 'periodo_id' => (string)($per['id'] ?? 0), 'novo_status' => 'fechado']);
qa_eqf('fechar de novo reemite 16.574,68 (sem duplicar)', 16574.68, qa_val(
    "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos WHERE tenant_id=? AND origem_tipo='rh_folha_lancamento'", [$T]), 0.05);

/* ════════ F11 — Fiscal: XML NF-e sintético idempotente ════════ */
qa_section('F11 Fiscal');
$chave = '35260799999999000191550010000009991000009991';
$xml = '<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
 <NFe xmlns="http://www.portalfiscal.inf.br/nfe">
  <infNFe Id="NFe' . $chave . '" versao="4.00">
   <ide><nNF>999</nNF><dhEmi>2026-07-12T10:00:00-03:00</dhEmi></ide>
   <emit><CNPJ>99999999000191</CNPJ><xNome>QA Emitente Sintetico LTDA</xNome></emit>
   <det nItem="1"><prod><cProd>QAX1</cProd><xProd>QA Item Sintetico</xProd>
     <qCom>10.0000</qCom><vUnCom>123.4560</vUnCom><vProd>1234.56</vProd></prod></det>
   <total><ICMSTot><vNF>1234.56</vNF></ICMSTot></total>
  </infNFe>
 </NFe>
</nfeProc>';
$xmlPath = QA_OUT . '/qa_nfe_sintetica.xml';
file_put_contents($xmlPath, $xml);
if (!function_exists('curl_file_create')) {
    qa_skip('import XML NF-e', 'curl sem suporte a upload');
} else {
    qa_http_post('super', '/fiscal/importacao_nfe.php', ['acao' => 'importar'], true,
        ['xml' => curl_file_create($xmlPath, 'text/xml', 'qa_nfe_sintetica.xml')]);
    $doc = qa_row("SELECT * FROM fiscal_documentos WHERE tenant_id=? AND chave=?", [$T, $chave]);
    qa_check('NF-e sintética importada', (bool)$doc, 'sem documento');
    qa_eqf('valor 1.234,56', 1234.56, $doc['valor_total'] ?? -1);
    qa_check('fornecedor criado por CNPJ (get-or-create)', (int)qa_val(
        "SELECT COUNT(*) FROM fornecedores WHERE tenant_id=? AND nome LIKE 'QA Emitente%'", [$T]) === 1);
    qa_http_post('super', '/fiscal/importacao_nfe.php', ['acao' => 'importar'], true,
        ['xml' => curl_file_create($xmlPath, 'text/xml', 'qa_nfe_sintetica.xml')]);
    qa_eq('reimportação da MESMA chave não duplica', 1, (int)qa_val(
        "SELECT COUNT(*) FROM fiscal_documentos WHERE tenant_id=? AND chave=?", [$T, $chave]));
}

/* NEGATIVO: upload de mapa com DOCTYPE (XXE) recusado */
$kml = "<?xml version=\"1.0\"?>\n<!DOCTYPE kml [<!ENTITY x \"y\">]>\n<kml xmlns=\"http://www.opengis.net/kml/2.2\"><Document><Placemark><name>QA</name></Placemark></Document></kml>";
$kmlPath = QA_OUT . '/qa_doctype.kml';
file_put_contents($kmlPath, $kml);
$r = qa_http_post('super', '/agro/mapa.php', ['acao' => 'importar_mapa', 'fazenda_id' => (string)$faz], true,
    ['arquivo' => curl_file_create($kmlPath, 'application/vnd.google-earth.kml+xml', 'qa_doctype.kml')]);
qa_check('KML com DOCTYPE recusado sem 500', in_array($r['code'], [302, 200], true)
    && (int)qa_val("SELECT COUNT(*) FROM agro_talhoes WHERE tenant_id=? AND nome='QA'", [$T]) === 0, $r['code']);

/* NEGATIVO: vigência de premiação sobreposta */
$regrasAntes = (int)qa_val("SELECT COUNT(*) FROM rh_regras_premiacao WHERE tenant_id=? AND ativo=1", [$T]);
qa_http_post('super', '/pessoas/premiacao.php', ['acao' => 'salvar', 'tipo_atividade_id' => (string)$tAtv,
    'cultura_id' => (string)$cult, 'unidade' => 'caixa', 'vigencia_inicio' => '2026-06-01']);
qa_eq('regra de premiação sobreposta recusada', $regrasAntes, (int)qa_val(
    "SELECT COUNT(*) FROM rh_regras_premiacao WHERE tenant_id=? AND ativo=1", [$T]));

qa_finish('20_fluxos');
