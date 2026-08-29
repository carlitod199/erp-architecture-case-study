<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/g16_prova.php  (A2-G16, 19/07/2026)
   Prova do GAP G-16 (auditoria F&C 19/07) via HTTP real:
     (a) Σ% de qualidades/classificações ≤ 100 — colheita e venda
     (b) consistência saldo × lote no seletor da venda
   Sequência: 00_massa_canonica → ESTE script → 99_limpeza.
   A limpeza fina aqui dentro usa o CANCELAMENTO LÓGICO do próprio
   sistema (cancelar venda, estornar entrada); o 99 remove o tenant.
   ============================================================ */

require __DIR__ . '/_lib.php';
qa_boot_app();
$env = qa_env();
$D   = $env['datas'];
$T   = qa_tenant_id();

function qa_id(string $sql, array $p = []): int { return (int)qa_val($sql, $p); }

/* ── ids da massa canônica ── */
$t1    = qa_id("SELECT id FROM agro_talhoes WHERE tenant_id=? AND codigo='QA-1A'", [$T]);
$set1  = qa_id("SELECT id FROM agro_setores WHERE tenant_id=? AND talhao_id=? AND is_espelho=1", [$T, $t1]);
$var   = qa_id("SELECT id FROM agro_variedades WHERE tenant_id=? AND nome='QA Vitória'", [$T]);
$safra = qa_id("SELECT id FROM agro_safras WHERE tenant_id=? AND identificacao='QA 2026/2'", [$T]);
$st1   = qa_id("SELECT id FROM agro_safra_talhoes WHERE tenant_id=? AND safra_id=? AND talhao_id=?", [$T, $safra, $t1]);
$compr = qa_id("SELECT id FROM comercial_compradores WHERE tenant_id=? AND razao_social='QA Comprador LTDA'", [$T]);

qa_section('Pré-condições');
qa_check('massa canônica presente (setor, safra, comprador)', $t1 && $set1 && $st1 && $compr,
    compact('t1', 'set1', 'st1', 'compr'));
if (!qa_http_login('super')) {
    qa_check('login HTTP qa.super', false, 'base_url inacessível');
    qa_finish('g16_prova');
}
qa_check('login HTTP qa.super', true);

/* ════════ P1 — colheita com Σ% = 170 REJEITADA ════════ */
qa_section('P1 Colheita Σ%=170 rejeitada');
$antes = (int)qa_val("SELECT COUNT(*) FROM colheita_registros WHERE tenant_id=?", [$T]);
qa_http_post('super', '/colheita/index.php', ['acao' => 'salvar',
    'data_colheita' => $D['colheita'], 'setor_id' => (string)$set1, 'safra_talhao_id' => (string)$st1,
    'variedade_id' => (string)$var, 'colheita_unidade_entrada' => 'kg',
    'producao_realizada_kg_ha' => '1.000',
    'c_pct'   => ['realizado' => ['premium' => '85', 'cat1' => '85']],   /* Σ = 170% */
    'c_preco' => ['realizado' => ['premium' => '6,00', 'cat1' => '5,00']]]);
qa_eq('nenhum registro de colheita gravado', $antes,
    (int)qa_val("SELECT COUNT(*) FROM colheita_registros WHERE tenant_id=?", [$T]));
qa_eq('nenhuma classificação gravada', 0,
    (int)qa_val("SELECT COUNT(*) FROM colheita_classificacoes WHERE tenant_id=?", [$T]));
$resp = qa_http_get('super', '/colheita/index.php');
qa_check('mensagem clara "passa de 100%" exibida', str_contains($resp['body'], 'passa de 100%'));

/* ════════ P2 — colheita com Σ% = 100 ACEITA ════════ */
qa_section('P2 Colheita Σ%=100 aceita');
qa_http_post('super', '/colheita/index.php', ['acao' => 'salvar',
    'data_colheita' => $D['colheita'], 'setor_id' => (string)$set1, 'safra_talhao_id' => (string)$st1,
    'variedade_id' => (string)$var, 'colheita_unidade_entrada' => 'kg',
    'producao_prevista_kg_ha' => '1.000', 'producao_realizada_kg_ha' => '1.000',
    'c_pct'   => ['previsto' => ['cat1' => '100'], 'realizado' => ['premium' => '60', 'cat1' => '40']],
    'c_preco' => ['previsto' => ['cat1' => '5,20'], 'realizado' => ['premium' => '6,00', 'cat1' => '5,00']]]);
$col = qa_row("SELECT * FROM colheita_registros WHERE tenant_id=? ORDER BY id DESC LIMIT 1", [$T]);
qa_check('registro de colheita criado', $col !== null);
qa_eqf('kg realizado 1.000 kg/ha × 4 ha = 4.000', 4000.0, $col['kg_total_realizado'] ?? -1);
qa_eq('3 classificações gravadas (1 prev + 2 real)', 3,
    (int)qa_val("SELECT COUNT(*) FROM colheita_classificacoes WHERE tenant_id=? AND registro_id=?", [$T, $col['id'] ?? 0]));

/* entrada no estoque → lote COLH- */
qa_http_post('super', '/colheita/index.php', ['acao' => 'entrada_confirmar', 'id' => (string)($col['id'] ?? 0)]);
$lote = qa_row("SELECT * FROM estoque_lotes WHERE tenant_id=? AND codigo_lote LIKE 'COLH-%' AND status='disponivel'", [$T]);
qa_check('lote COLH- disponível', $lote !== null);
qa_eqf('lote com 4.000 kg', 4000.0, $lote['quantidade'] ?? -1);

/* ════════ P3 — venda com Σ% = 170 REJEITADA ════════ */
qa_section('P3 Venda Σ%=170 rejeitada');
$antesV = (int)qa_val("SELECT COUNT(*) FROM comercial_vendas WHERE tenant_id=?", [$T]);
qa_http_post('super', '/comercial/vendas.php', ['acao' => 'salvar',
    'comprador_id' => (string)$compr, 'lote_id' => (string)($lote['id'] ?? 0),
    'data_venda' => $D['venda'], 'data_vencimento' => $D['vencimento'], 'kg_total' => '1.000', 'parcelas' => '1',
    'q_pct'   => ['premium' => '85', 'cat1' => '85'],                    /* Σ = 170% */
    'q_preco' => ['premium' => '6,00', 'cat1' => '5,00']]);
qa_eq('nenhuma venda gravada com Σ%=170', $antesV,
    (int)qa_val("SELECT COUNT(*) FROM comercial_vendas WHERE tenant_id=?", [$T]));
$resp = qa_http_get('super', '/comercial/vendas.php');
qa_check('mensagem clara "passa de 100%" exibida', str_contains($resp['body'], 'passa de 100%'));

/* ════════ P4 — venda com kg ACIMA do saldo do lote REJEITADA ════════ */
qa_section('P4 Venda kg>saldo rejeitada');
qa_http_post('super', '/comercial/vendas.php', ['acao' => 'salvar',
    'comprador_id' => (string)$compr, 'lote_id' => (string)($lote['id'] ?? 0),
    'data_venda' => $D['venda'], 'data_vencimento' => $D['vencimento'], 'kg_total' => '5.000', 'parcelas' => '1',
    'q_pct'   => ['premium' => '100'],
    'q_preco' => ['premium' => '6,00']]);                                /* 5.000 > 4.000 */
qa_eq('nenhuma venda gravada acima do saldo', $antesV,
    (int)qa_val("SELECT COUNT(*) FROM comercial_vendas WHERE tenant_id=?", [$T]));
qa_eq('nenhuma saída ativa emitida', 0, (int)qa_val(
    "SELECT COUNT(*) FROM estoque_movimentacoes
      WHERE tenant_id=? AND origem_tipo='comercial_venda' AND tipo='saida' AND estornado_em IS NULL", [$T]));
$resp = qa_http_get('super', '/comercial/vendas.php');
qa_check('mensagem clara "ACIMA do saldo do lote" exibida', str_contains($resp['body'], 'ACIMA do saldo do lote'));
qa_eqf('saldo do lote intacto (4.000)', 4000.0,
    qa_val("SELECT quantidade FROM estoque_lotes WHERE id=?", [$lote['id'] ?? 0]));

/* ════════ P5 — venda Σ%=100 e kg = saldo ACEITA ════════ */
qa_section('P5 Venda Σ%=100 e kg=saldo aceita');
$postVenda = ['acao' => 'salvar', 'comprador_id' => (string)$compr, 'lote_id' => (string)($lote['id'] ?? 0),
    'data_venda' => $D['venda'], 'data_vencimento' => $D['vencimento'], 'kg_total' => '4.000', 'parcelas' => '1',
    'q_pct'   => ['premium' => '60', 'cat1' => '40'],
    'q_preco' => ['premium' => '6,00', 'cat1' => '5,00']];
qa_http_post('super', '/comercial/vendas.php', $postVenda);
$vd = qa_row("SELECT * FROM comercial_vendas WHERE tenant_id=? ORDER BY id DESC LIMIT 1", [$T]);
qa_check('venda confirmada', $vd !== null && $vd['status'] === 'confirmada', $vd['status'] ?? null);
qa_eqf('venda 4.000 kg', 4000.0, $vd['kg_total'] ?? -1);
qa_eqf('valor 22.400 (2.400×6 + 1.600×5)', 22400.00, $vd['valor_total'] ?? -1);
qa_eqf('lote zerado após a baixa', 0.0,
    qa_val("SELECT quantidade FROM estoque_lotes WHERE id=?", [$lote['id'] ?? 0]));

/* ════════ P6 — reedição com o MESMO lote no saldo cheio ACEITA
   (o saldo REAL devolve o kg da própria venda — G-16 b) ════════ */
qa_section('P6 Reedição kg=saldo real aceita');
qa_http_post('super', '/comercial/vendas.php',
    array_merge($postVenda, ['id' => (string)($vd['id'] ?? 0)]));
qa_eq('reedição no saldo cheio NÃO foi bloqueada (kg segue 4.000)', '4000.000',
    sprintf('%.3f', (float)qa_val("SELECT kg_total FROM comercial_vendas WHERE id=?", [$vd['id'] ?? 0])));
qa_eq('exatamente 1 saída ativa após reedição', 1, (int)qa_val(
    "SELECT COUNT(*) FROM estoque_movimentacoes
      WHERE tenant_id=? AND origem_tipo='comercial_venda' AND origem_id=? AND tipo='saida' AND estornado_em IS NULL",
    [$T, $vd['id'] ?? 0]));

/* ════════ P7 — reedição com kg ACIMA do saldo real REJEITADA ════════ */
qa_section('P7 Reedição kg>saldo real rejeitada');
qa_http_post('super', '/comercial/vendas.php',
    array_merge($postVenda, ['id' => (string)($vd['id'] ?? 0), 'kg_total' => '5.000']));
qa_eq('kg da venda NÃO mudou (segue 4.000)', '4000.000',
    sprintf('%.3f', (float)qa_val("SELECT kg_total FROM comercial_vendas WHERE id=?", [$vd['id'] ?? 0])));
$resp = qa_http_get('super', '/comercial/vendas.php');
qa_check('mensagem clara "ACIMA do saldo do lote" exibida', str_contains($resp['body'], 'ACIMA do saldo do lote'));

/* ════════ P8 — limpeza LÓGICA pelo próprio sistema ════════ */
qa_section('P8 Limpeza lógica (cancelamentos do sistema)');
qa_http_post('super', '/comercial/vendas.php', ['acao' => 'excluir', 'id' => (string)($vd['id'] ?? 0)]);
qa_eq('venda CANCELADA (lógico)', 'cancelada',
    (string)qa_val("SELECT status FROM comercial_vendas WHERE id=?", [$vd['id'] ?? 0]));
qa_eq('saída estornada (0 ativas)', 0, (int)qa_val(
    "SELECT COUNT(*) FROM estoque_movimentacoes
      WHERE tenant_id=? AND origem_tipo='comercial_venda' AND tipo='saida' AND estornado_em IS NULL", [$T]));
qa_eqf('kg devolvido ao lote (4.000)', 4000.0,
    qa_val("SELECT quantidade FROM estoque_lotes WHERE id=?", [$lote['id'] ?? 0]));
qa_http_post('super', '/colheita/index.php', ['acao' => 'entrada_estornar', 'id' => (string)($col['id'] ?? 0)]);
qa_eq('entrada da colheita estornada (lote estornado)', 'estornado',
    (string)qa_val("SELECT status FROM estoque_lotes WHERE id=?", [$lote['id'] ?? 0]));
/* o registro de colheita permanece (a venda cancelada referencia — FK);
   o resíduo vive só no tenant QA e o 99_limpeza remove o tenant inteiro */

qa_finish('g16_prova');
