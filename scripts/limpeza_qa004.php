<?php
declare(strict_types=1);
/* ============================================================
   Limpeza QA-004 (A0, 05/07/2026) — escopo REGISTRADO no
   VERO_QA_FUNCIONAL.md + massa QA5. Uso: php limpeza_qa004.php
   [--executar]  (sem flag = dry-run, só conta).
   NUNCA toca movimentacoes_financeiras (hash-chain — as 17 de
   teste já estão em cancel lógico). Exceções deferidas p/ P-04:
   colheita 1 (vendas confirmadas dependem), produto QA5-001
   (itens de compra com financeiro selado → inativa).
   ============================================================ */
$exec = in_array('--executar', $argv, true);
$c = require __DIR__ . '/../config/database.php';
$p = new PDO("mysql:host={$c['host']};dbname={$c['dbname']};charset=utf8mb4", $c['user'], $c['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$n = fn(string $sql) => (int)$p->query($sql)->fetchColumn();

/* Salvaguardas de identidade: cada alvo é conferido por CONTEÚDO, não só por id. */
$guards = [
    "vendas VTESTE canceladas" => $n("SELECT COUNT(*) FROM comercial_vendas WHERE id IN (2,6) AND numero LIKE 'VTESTE%' AND status='cancelada'") === 2,
    "colheita 4 é QA5" => $n("SELECT COUNT(*) FROM colheita_registros WHERE id=4 AND observacao LIKE '%QA5%'") === 1,
    "cultura 2 é QA5" => $n("SELECT COUNT(*) FROM agro_culturas WHERE id=2 AND nome='QA5-CULTURA-UVA'") === 1,
    "produto 2 é DEF-TESTE-01" => $n("SELECT COUNT(*) FROM estoque_produtos WHERE id=2 AND codigo='DEF-TESTE-01'") === 1,
    "produto 3 é AGF-TESTE" => $n("SELECT COUNT(*) FROM estoque_produtos WHERE id=3 AND codigo='AGF-TESTE'") === 1,
    "produto 6 é QA5-001" => $n("SELECT COUNT(*) FROM estoque_produtos WHERE id=6 AND codigo='QA5-001'") === 1,
    "fornecedor 2 é Oficina Teste sem refs" => $n("SELECT COUNT(*) FROM fornecedores WHERE id=2 AND nome='Oficina Teste F26'") === 1,
    "usuário 5 é qa5.operador" => $n("SELECT COUNT(*) FROM usuarios WHERE id=5 AND email='qa5.operador@vero.test'") === 1,
    "romaneio 2 é QA5-ROM-001" => $n("SELECT COUNT(*) FROM comercial_romaneios WHERE id=2 AND romaneio='QA5-ROM-001'") === 1,
    "apontamento 5 criado pelo QA5" => $n("SELECT COUNT(*) FROM agro_apontamentos WHERE id=5 AND created_by=5") === 1,
    "nenhuma venda aponta p/ colheita 4" => $n("SELECT COUNT(*) FROM comercial_vendas WHERE colheita_registro_id=4") === 0,
];
$falhou = false;
foreach ($guards as $g => $ok) { echo ($ok ? '[guard OK] ' : '[GUARD FALHOU] ') . $g . "\n"; if (!$ok) $falhou = true; }
if ($falhou) exit("ABORTADO: um alvo não é o que o registro diz — nada foi tocado.\n");

$passos = [
    /* VTESTE (vendas canceladas) */
    ["comercial_venda_qualidades VTESTE", "DELETE FROM comercial_venda_qualidades WHERE venda_id IN (2,6)"],
    ["comercial_venda_itens VTESTE", "DELETE FROM comercial_venda_itens WHERE venda_id IN (2,6)"],
    ["comercial_logistica VTESTE", "DELETE FROM comercial_logistica WHERE venda_id IN (2,6)"],
    ["comercial_romaneios VTESTE", "DELETE FROM comercial_romaneios WHERE venda_id IN (2,6)"],
    ["comercial_vendas VTESTE", "DELETE FROM comercial_vendas WHERE id IN (2,6)"],
    /* romaneio QA5 da venda real 7 */
    ["romaneio QA5-ROM-001", "DELETE FROM comercial_romaneios WHERE id=2"],
    /* colheita QA5 (4) e filhos */
    ["colheita_cargas (4)", "DELETE FROM colheita_cargas WHERE registro_id=4"],
    ["colheita_classificacoes (4)", "DELETE FROM colheita_classificacoes WHERE registro_id=4"],
    ["colheita_registros (4)", "DELETE FROM colheita_registros WHERE id=4"],
    /* apontamento QA5 (5) + filhos + custeio derivado */
    ["agro_apontamento_insumos (apont 5)", "DELETE FROM agro_apontamento_insumos WHERE apontamento_id=5"],
    ["agro_apontamento_maquinas (apont 5)", "DELETE FROM agro_apontamento_maquinas WHERE apontamento_id=5"],
    ["agro_apontamentos_pessoa (apont 5)", "DELETE FROM agro_apontamentos_pessoa WHERE apontamento_id=5"],
    ["rh_producao_itens 9", "DELETE FROM rh_producao_itens WHERE id=9"],
    ["custeio_lancamentos 33/34 (origens do apont 5)", "DELETE FROM custeio_lancamentos WHERE id IN (33,34) AND ((origem_tipo='apontamento_insumo' AND origem_id=3) OR (origem_tipo='apontamento_maquina' AND origem_id=4))"],
    ["agro_apontamentos 5", "DELETE FROM agro_apontamentos WHERE id=5"],
    /* clima QA5 */
    ["clima QA5 (12,5mm 05/07 talhão 1)", "DELETE FROM clima_registros WHERE talhao_id=1 AND data='2026-07-05' AND chuva_mm=12.5 AND created_by=5"],
    /* cultura QA5 (zero refs conferidas no inventário) */
    ["agro_culturas QA5", "DELETE FROM agro_culturas WHERE id=2"],
    /* produto DEF-TESTE-01 (autocontido) */
    ["mov_lotes DEF-TESTE", "DELETE ml FROM estoque_movimentacao_lotes ml JOIN estoque_movimentacoes m ON m.id=ml.movimentacao_id WHERE m.produto_id=2"],
    ["movs DEF-TESTE", "DELETE FROM estoque_movimentacoes WHERE produto_id=2"],
    ["lotes DEF-TESTE", "DELETE FROM estoque_lotes WHERE produto_id=2"],
    ["saldos DEF-TESTE", "DELETE FROM estoque_saldos WHERE produto_id=2"],
    ["produto DEF-TESTE-01", "DELETE FROM estoque_produtos WHERE id=2"],
    /* produto AGF-TESTE (zero refs) */
    ["produto AGF-TESTE", "DELETE FROM estoque_produtos WHERE id=3"],
    /* catálogo agrofit de teste (importado no teste F2-13) */
    ["agrofit_catalogo (teste)", "DELETE FROM agrofit_catalogo WHERE tenant_id=1"],
    /* fornecedor de teste (zero refs) */
    ["fornecedor Oficina Teste F26", "DELETE FROM fornecedores WHERE id=2"],
    /* usuários QA5 MANTIDOS até a A5-03 (relogin por perfil) — saem na P-04 */
    /* QA5-001: INATIVA (itens de compra com financeiro selado impedem DELETE) */
    ["produto QA5-001 → inativo", "UPDATE estoque_produtos SET ativo=0 WHERE id=6 AND ativo=1"],
];
if (!$exec) echo "\n-- DRY-RUN (contagens do que seria afetado) --\n";
$p->beginTransaction();
try {
    $total = 0;
    foreach ($passos as [$rotulo, $sql]) {
        $rc = $p->exec($sql);
        echo str_pad($rotulo, 42) . " → $rc linha(s)\n";
        $total += (int)$rc;
    }
    if ($exec) { $p->commit(); echo "\nCOMMIT — $total linhas afetadas.\n"; }
    else { $p->rollBack(); echo "\nROLLBACK (dry-run) — $total linhas seriam afetadas.\n"; }
} catch (Throwable $e) {
    $p->rollBack();
    echo "\nERRO — ROLLBACK TOTAL: " . $e->getMessage() . "\n";
    exit(1);
}
/* resíduo: nada de teste visível deve sobrar (fora as exceções registradas) */
echo "\n-- resíduo pós-operação --\n";
echo "vendas VTESTE: " . $n("SELECT COUNT(*) FROM comercial_vendas WHERE numero LIKE 'VTESTE%'") . "\n";
echo "produtos de teste ativos: " . $n("SELECT COUNT(*) FROM estoque_produtos WHERE (codigo LIKE '%TESTE%' OR codigo LIKE 'QA5%') AND ativo=1") . "\n";
echo "fornecedor teste: " . $n("SELECT COUNT(*) FROM fornecedores WHERE nome LIKE '%Teste%'") . "\n";
echo "cultura QA5: " . $n("SELECT COUNT(*) FROM agro_culturas WHERE nome LIKE 'QA5%'") . "\n";
echo "usuário QA5: " . $n("SELECT COUNT(*) FROM usuarios WHERE email LIKE '%qa5%'") . "\n";
echo "colheitas: " . $n("SELECT COUNT(*) FROM colheita_registros") . " (esperado 1 — a nº1 fica p/ P-04)\n";
