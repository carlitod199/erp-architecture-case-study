<?php
declare(strict_types=1);
/* ============================================================
   VERO — Limpeza INTELIGENTE de dados de teste (produção)
   ------------------------------------------------------------
   Preserva a "camada-cérebro" (regras, parâmetros, configurações,
   catálogos: culturas, variedades, fenologia, tipos de atividade,
   metodologias, plano de contas, etc.) e o Administrador.
   Remove:
     (1) TODOS os dados OPERACIONAIS/transacionais (apontamentos,
         aplicações, movimentações de estoque, safras, colheita,
         vendas, compras, custeio/financeiro, fiscal, folha, MIP,
         irrigação, máquinas-uso, análises, logs);
     (2) cadastros marcados como TESTE (fazenda "QA Teste" e tudo
         ligado a ela; produtos QA5*; usuários @vero.test; operadores/
         equipes QA*), MANTENDO os reais (ex.: fazenda "Vinícola do
         Vale", produtos Nitrato/ELESTAL, usuário Administrador).

   SEGURANÇA:
     • DRY-RUN por padrão: só CONTA e LISTA o que seria removido.
       Nada é apagado sem a flag --apply.
     • Com --apply: executa tudo dentro de UMA transação
       (FOREIGN_KEY_CHECKS=0) e faz COMMIT no fim; qualquer erro → ROLLBACK.
     • Roda no ambiente onde estiver (usa config/database.php → .env).
       No VPS, aponta para vero_db (produção).

   USO (no servidor de produção):
       php tools/limpar_dados_teste.php            # DRY-RUN (revisar)
       php tools/limpar_dados_teste.php --apply     # aplica de verdade
   ============================================================ */

/* B2 (defense-in-depth): script exclusivamente CLI. Bloqueia qualquer
   execução via SAPI web (o webroot já é bloqueado no nginx, mas não
   confiamos só nisso). */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$APPLY = in_array('--apply', $argv, true);
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/* Padrão de marcação de TESTE (nome/e-mail/descrição). */
$RE = 'QA5|QA6|QA[- ]?UI|QA Teste|registro de teste|TESTE|PROBE|AUDIT|PROVA';

/* ── (A) TABELAS OPERACIONAIS: esvaziadas por completo (100% teste) ── */
$OPERACIONAIS = [
    // Agro — operação
    'agro_apontamento_insumos','agro_apontamento_maquinas','agro_apontamentos_pessoa','agro_apontamentos',
    'agro_aplicacao_assinaturas','agro_aplicacao_itens','agro_aplicacao_maquinas','agro_aplicacao_operadores',
    'agro_aplicacao_valvulas','agro_aplicacoes',
    'agro_atividade_insumos','agro_atividades','agro_ordens_servico',
    'agro_safra_talhoes','agro_safras',
    'agro_alertas','agro_anexos','agro_pendencia_itens','agro_pendencias','agro_receituarios',
    'agro_fenologia_periodos','agro_ia_extracoes','agro_ponto',
    'agro_custo_orcamento_itens','agro_custo_orcamentos',
    // MIP
    'mip_monitoramento_alvos','mip_monitoramentos','mip_aplicacoes','mip_alerta_acoes','mip_pontos_amostragem',
    // Estoque — movimento/estado (o CATÁLOGO fica; ver preservadas)
    'estoque_movimentacao_lotes','estoque_movimentacoes','estoque_lotes','estoque_saldos',
    'estoque_inventario_itens','estoque_inventarios','estoque_produto_nutrientes',
    // Colheita / Comercial
    'colheita_classificacoes','colheita_cargas','colheita_registros',
    'comercial_venda_qualidades','comercial_venda_pesos','comercial_venda_itens','comercial_venda_despesas',
    'comercial_vendas','comercial_romaneios','comercial_logistica','comercial_contratos','comercial_tabela_precos',
    'armazenagem_estoques','armazenagem_contratos',
    // Compras
    'compras_recebimento_itens','compras_recebimentos','compras_pedido_itens','compras_pedidos',
    'compras_cotacao_itens','compras_cotacoes','compras_aprovacoes','compras_solicitacao_itens','compras_solicitacoes',
    // Custeio / Financeiro
    'custeio_rateio_execucoes','custeio_fechamentos','custeio_lancamentos','custeio_orcamento_itens','custeio_orcamentos',
    'movimentacoes_financeiras','conciliacao_itens','conciliacao_bancaria',
    // Fiscal (documentos; a CONFIG fica)
    'fiscal_documento_itens','fiscal_documentos','fiscal_livro_caixa','fiscal_conciliacoes',
    // RH — operação (regras/temas/EPI-catálogo ficam)
    'rh_producao_itens','rh_folha_lancamentos','rh_folha_periodos','rh_epi_entregas',
    'rh_treinamento_presencas','rh_treinamento_turmas',
    // Irrigação — operação
    'irrigacao_consumos','irrigacao_apontamentos','irrigacao_planejamentos',
    // Máquinas — uso (a FROTA cadastro fica)
    'maquina_manutencao_itens','maquina_manutencoes','maquina_abastecimentos','maquina_horimetros','maquina_odometros',
    // Patrimônio — movimento (categorias/ativos ficam p/ decisão de padrão)
    'patrimonio_depreciacoes',
    // Análises
    'analise_foliar_resultados','analise_foliar','analise_solo_resultados','analise_solo',
    // Pecuária (vazias hoje; incluídas por completude)
    'pec_pesagens','pec_sanidade','pec_consumos','pec_movimentacoes','pec_lotes','pec_vendas','pec_compras',
    // Logs / app / IA / clima
    'auth_audit_logs','app_idempotencia','app_push_tokens','app_tokens','clima_registros','ia_uso','ia_aprendizados',
    'rt_registros',
];

/* ── (C) REMOÇÕES POR PADRÃO (cadastros de TESTE; mantém os reais) ──
   Cada item: [rótulo, tabela, WHERE]. WHERE vazio = tabela inteira. */
$ft = $pdo->query("SELECT id FROM agro_fazendas WHERE nome REGEXP " . $pdo->quote($RE))->fetchAll(PDO::FETCH_COLUMN);
$inFt   = $ft ? '(' . implode(',', array_map('intval', $ft)) . ')' : '(0)';
$talFt  = $ft ? $pdo->query("SELECT id FROM agro_talhoes WHERE fazenda_id IN $inFt")->fetchAll(PDO::FETCH_COLUMN) : [];
$inTal  = $talFt ? '(' . implode(',', array_map('intval', $talFt)) . ')' : '(0)';

$ESTRUTURAIS = [
    // filhos de bombas/setores primeiro
    ['Bomba×válvula (fazenda teste)', 'agro_bomba_valvulas',
        "bomba_id IN (SELECT id FROM agro_bombas WHERE fazenda_id IN $inFt) OR setor_id IN (SELECT id FROM agro_setores WHERE fazenda_id IN $inFt OR talhao_id IN $inTal)"],
    ['Bombas (fazenda teste)',        'agro_bombas',        "fazenda_id IN $inFt"],
    ['Glebas (válvula teste)',        'agro_glebas',        "talhao_id IN $inTal"],
    ['Pontos produtivos (válvula teste)','agro_pontos_produtivos', "talhao_id IN $inTal"],
    ['Setores (fazenda/válvula teste)','agro_setores',      "fazenda_id IN $inFt OR talhao_id IN $inTal"],
    ['Pivôs (fazenda teste)',         'agro_pivos',         "fazenda_id IN $inFt"],
    ['Áreas (fazenda teste)',         'agro_areas',         "fazenda_id IN $inFt"],
    // ATIVOS MÓVEIS: só por NOME de teste (não pela fazenda — um bem real
    // alocado na fazenda de teste NÃO deve ser apagado; fica com fazenda_id a
    // reatribuir). Almoxarifados: PRESERVADO (config de estoque).
    ['Equipe-membros (equipe teste)', 'agro_equipe_membros', "equipe_id IN (SELECT id FROM agro_equipes WHERE nome REGEXP " . $pdo->quote($RE) . ")"],
    ['Equipes (nome teste)',          'agro_equipes',       "nome REGEXP " . $pdo->quote($RE)],
    ['Máquinas (nome teste)',         'maquinas',           "nome REGEXP " . $pdo->quote($RE)],
    ['Veículos (placa/modelo teste)', 'veiculos',           "modelo REGEXP " . $pdo->quote($RE) . " OR placa REGEXP " . $pdo->quote($RE)],
    ['Implementos (nome teste)',      'implementos',        "nome REGEXP " . $pdo->quote($RE)],
    ['Operadores (nome teste)',       'agro_operadores',    "nome REGEXP " . $pdo->quote($RE)],
    ['Terceirizados (nome teste)',    'rh_terceirizados',   "nome REGEXP " . $pdo->quote($RE)],
    ['Válvulas/talhões (fazenda teste)','agro_talhoes',     "fazenda_id IN $inFt"],
    ['Fazendas de teste',             'agro_fazendas',      "id IN $inFt"],
    ['Produtos de teste (QA5*)',      'estoque_produtos',   "nome REGEXP " . $pdo->quote($RE) . " OR codigo REGEXP " . $pdo->quote($RE)],
    ['Fornecedores de teste',         'fornecedores',       "nome REGEXP " . $pdo->quote($RE) . " OR (email IS NOT NULL AND email LIKE '%@vero.test')"],
    ['Compradores de teste',          'comercial_compradores', "email LIKE '%@vero.test'"],
    ['Patrimônio (descrição teste)',  'patrimonio_ativos',  "descricao REGEXP " . $pdo->quote($RE)],
    // usuários: só os @vero.test (mantém Administrador e reais)
    ['Usuários de teste (@vero.test)','usuarios',           "email LIKE '%@vero.test'"],
];

/* ── (B) PRESERVADAS (nunca tocadas) — para conferência no relatório ── */
$PRESERVAR = [
    'tenants','tenant_parametros','roles','permissions','role_permissions','fiscal_config','rh_encargos_config',
    'agro_culturas','agro_variedades','agro_porta_enxertos','agro_variedade_fases','agro_variedade_fenologia',
    'agro_fenologia_estagios','agro_tipos_atividade','agro_tipo_atividade_culturas','agro_calc_parametros',
    'rh_regras_premiacao','agro_custo_metodologias','agro_custo_grupos','agro_custo_itens','agro_custo_parametros_cultura',
    'plano_contas','centros_custo','contas_bancarias','patrimonio_categorias','comercial_canais','comercial_tipos_despesa',
    'estoque_grupos','estoque_subgrupos','agrofit_catalogo','mip_alvos','mip_alvo_produtos','analise_faixas',
    'analise_nutrientes','rh_treinamento_temas','rh_epi_itens','gestao_metas','custeio_rateios','almoxarifados',
];

/* ── Execução ──────────────────────────────────────────────── */
function n(int $x): string { return number_format($x, 0, ',', '.'); }
$cnt = fn(string $sql) => (int)$GLOBALS['pdo']->query($sql)->fetchColumn();

echo "==================================================================\n";
echo $APPLY ? "  LIMPEZA DE DADOS DE TESTE — MODO APLICAR (--apply)\n"
            : "  LIMPEZA DE DADOS DE TESTE — DRY-RUN (nada será apagado)\n";
echo "  Banco: {$config['dbname']} @ {$config['host']}\n";
echo "==================================================================\n\n";

/* Amostra do que será removido em cadastros-chave (transparência) */
echo "— Cadastros de teste identificados —\n";
foreach (['agro_fazendas'=>'id IN '.$inFt, 'estoque_produtos'=>'nome REGEXP '.$pdo->quote($RE).' OR codigo REGEXP '.$pdo->quote($RE), 'usuarios'=>"email LIKE '%@vero.test'"] as $tb=>$w) {
    $rows = $pdo->query("SELECT * FROM $tb WHERE $w")->fetchAll();
    echo "  $tb (".count($rows)."): ";
    echo implode('; ', array_map(fn($r)=> ($r['nome'] ?? $r['codigo'] ?? $r['id']) . (isset($r['email'])?' <'.$r['email'].'>':''), $rows)) . "\n";
}
echo "  Fazenda(s) PRESERVADA(s): ";
echo implode('; ', $pdo->query("SELECT nome FROM agro_fazendas WHERE nome NOT REGEXP ".$pdo->quote($RE))->fetchAll(PDO::FETCH_COLUMN)) . "\n\n";

$totalOper = 0; $totalEstr = 0;
try {
    if ($APPLY) { $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); $pdo->beginTransaction(); }

    echo "— (1) Tabelas operacionais (esvaziar) —\n";
    foreach ($OPERACIONAIS as $tb) {
        $c = $cnt("SELECT COUNT(*) FROM `$tb`");
        $totalOper += $c;
        if ($c > 0) printf("  %-34s %8s linha(s)%s\n", $tb, n($c), $APPLY ? ' → apagando' : '');
        if ($APPLY && $c > 0) $pdo->exec("DELETE FROM `$tb`");
    }
    printf("  %-34s %8s linha(s)\n\n", 'SUBTOTAL operacional', n($totalOper));

    echo "— (2) Cadastros de teste (por padrão) —\n";
    foreach ($ESTRUTURAIS as [$label, $tb, $where]) {
        $c = $cnt("SELECT COUNT(*) FROM `$tb`" . ($where ? " WHERE $where" : ''));
        $totalEstr += $c;
        if ($c > 0) printf("  %-40s %6s linha(s)%s\n", $label, n($c), $APPLY ? ' → apagando' : '');
        if ($APPLY && $c > 0) $pdo->exec("DELETE FROM `$tb`" . ($where ? " WHERE $where" : ''));
    }
    printf("  %-40s %6s linha(s)\n\n", 'SUBTOTAL cadastros teste', n($totalEstr));

    if ($APPLY) { $pdo->commit(); $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
} catch (Throwable $e) {
    if ($APPLY && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "\nERRO — nada foi apagado (rollback): " . $e->getMessage() . "\n");
    exit(1);
}

echo "— (3) Preservadas (NÃO tocadas) —\n  ";
echo implode(', ', $PRESERVAR) . "\n\n";

printf("TOTAL a remover: %s (operacional) + %s (cadastros teste) = %s linha(s)\n",
    n($totalOper), n($totalEstr), n($totalOper + $totalEstr));
echo $APPLY ? "\n✅ APLICADO com sucesso (commit).\n"
            : "\nℹ DRY-RUN — nada foi apagado. Revise acima e rode com --apply para efetivar.\n";
