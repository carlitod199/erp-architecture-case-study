<?php
declare(strict_types=1);

// ============================================================================
// migration_134_refinamento_fase2.php | VERO
// Pacote A0-04 (gate A0-02 aberto em 04/07/2026) — propostas do DB_CONTRACT:
//   DB-04  clima_registros                     DB-14 agro_fazendas (dossiê)
//   DB-06  estoque_produtos (tipagem)          DB-15 agro_talhoes (técnicos)
//   DB-07  estoque_movimentacoes (estorno      DB-16 agro_variedades (referência)
//          auditável) + estoque_movimentacao_lotes
//   DB-08  compras_pedidos + tenant_parametros DB-17 agro_atividades (plan×real)
//   DB-09  fornecedores (+backfill CNPJ)       DB-18 agro_aplicacoes/itens
//   DB-10  maquinas + maquina_odometros +      DB-19 mip_monitoramentos +
//          implementos.maquina_id                     mip_alerta_acoes
//   DB-11  planos/itens de manutenção          DB-20 colheita (destino/causa)
//   DB-12  maquina_abastecimentos.operador_id  DB-21 movimentacoes_financeiras
//   DB-13  agro_apontamento_maquinas.custo_hora       (forma pgto/parcelas)
//                                              DB-23 reemissão sem furo no hash
//                                                     (origem_ativa + uq recomposto)
// FORA (aguardam cliente): DB-24 (P-07), DB-25 (P-41/42), DB-26 (P-44).
// Idempotente: cada passo checa information_schema antes de alterar.
// MySQL 5.7 (sem ADD COLUMN IF NOT EXISTS). Backup obrigatório antes:
//   storage/backups/backup_pre_134_*.sql
// Executar: php migrations/migration_134_refinamento_fase2.php
// Rollback: DROPs documentados em cada proposta do DB_CONTRACT + backup.
// ============================================================================

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$log = function (string $msg): void { echo $msg . "\n"; };

$tableExists = function (string $t) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$t]);
    return (bool)$st->fetchColumn();
};
$columnExists = function (string $t, string $c) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$t, $c]);
    return (bool)$st->fetchColumn();
};
$indexCols = function (string $t, string $idx) use ($pdo): array {
    $st = $pdo->prepare("SELECT column_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? ORDER BY seq_in_index");
    $st->execute([$t, $idx]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
$fkExists = function (string $name) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY' AND constraint_name = ?");
    $st->execute([$name]);
    return (bool)$st->fetchColumn();
};
$addColumn = function (string $t, string $c, string $ddl) use ($pdo, $columnExists, $log): void {
    if ($columnExists($t, $c)) { $log("  = $t.$c já existe"); return; }
    $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $ddl");
    $log("  + $t.$c");
};
$addIndex = function (string $t, string $idx, string $colsSql) use ($pdo, $indexCols, $log): void {
    if ($indexCols($t, $idx)) { $log("  = índice $t.$idx já existe"); return; }
    $pdo->exec("ALTER TABLE `$t` ADD INDEX `$idx` ($colsSql)");
    $log("  + índice $t.$idx");
};
$addFk = function (string $t, string $name, string $ddl) use ($pdo, $fkExists, $log): void {
    if ($fkExists($name)) { $log("  = FK $name já existe"); return; }
    $pdo->exec("ALTER TABLE `$t` ADD CONSTRAINT `$name` $ddl");
    $log("  + FK $name");
};

$AUDIT = "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL";

// ---------------------------------------------------------------------------
$log("== migration 134 — refinamento fase 2 (pacote A0-04) ==");

// --- DB-04: clima_registros -------------------------------------------------
$log("[DB-04] clima_registros");
if (!$tableExists('clima_registros')) {
    $pdo->exec("CREATE TABLE clima_registros (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id  BIGINT UNSIGNED NOT NULL,
        fazenda_id BIGINT UNSIGNED NOT NULL,
        talhao_id  BIGINT UNSIGNED NULL,
        data       DATE NOT NULL,
        chuva_mm   DECIMAL(6,1) NULL,
        temp_min   DECIMAL(4,1) NULL,
        temp_max   DECIMAL(4,1) NULL,
        observacao VARCHAR(255) NULL,
        $AUDIT,
        PRIMARY KEY (id),
        KEY idx_clima_tenant (tenant_id),
        KEY idx_clima_faz_data (fazenda_id, data),
        KEY idx_clima_talhao (talhao_id),
        CONSTRAINT fk_clima_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id),
        CONSTRAINT fk_clima_fazenda FOREIGN KEY (fazenda_id) REFERENCES agro_fazendas (id),
        CONSTRAINT fk_clima_talhao  FOREIGN KEY (talhao_id)  REFERENCES agro_talhoes (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela clima_registros");
} else { $log("  = tabela já existe"); }
// duplicidade fazenda(+talhão)×data é validada NA TELA (UNIQUE com NULL não é confiável no 5.7)

// --- DB-08b: tenant_parametros (infra A0) -----------------------------------
$log("[DB-08] tenant_parametros (infra A0)");
if (!$tableExists('tenant_parametros')) {
    $pdo->exec("CREATE TABLE tenant_parametros (
        id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        chave     VARCHAR(60) NOT NULL,
        valor     VARCHAR(255) NOT NULL,
        descricao VARCHAR(255) NULL,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tenant_param (tenant_id, chave),
        CONSTRAINT fk_tparam_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela tenant_parametros (sem seed: chave ausente = comportamento atual)");
} else { $log("  = tabela já existe"); }

// --- DB-07: estorno auditável + rateio FEFO persistido ----------------------
$log("[DB-07] estoque_movimentacoes + estoque_movimentacao_lotes");
$addColumn('estoque_movimentacoes', 'motivo',        "VARCHAR(30) NULL COMMENT 'perda, quebra, vencimento_descarte, roubo_extravio, acerto_inventario, devolucao_campo, outro'");
$addColumn('estoque_movimentacoes', 'mov_ref_id',    "BIGINT UNSIGNED NULL COMMENT 'par de transferencia/estorno/devolucao'");
$addColumn('estoque_movimentacoes', 'estornado_em',  "DATETIME NULL");
$addColumn('estoque_movimentacoes', 'estornado_por', "BIGINT UNSIGNED NULL");
$addIndex('estoque_movimentacoes', 'idx_em_ref', "mov_ref_id");
if (!$tableExists('estoque_movimentacao_lotes')) {
    $pdo->exec("CREATE TABLE estoque_movimentacao_lotes (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id       BIGINT UNSIGNED NOT NULL,
        movimentacao_id BIGINT UNSIGNED NOT NULL,
        lote_id         BIGINT UNSIGNED NOT NULL,
        quantidade      DECIMAL(18,4) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_eml_mov (movimentacao_id),
        KEY idx_eml_lote (lote_id),
        CONSTRAINT fk_eml_mov  FOREIGN KEY (movimentacao_id) REFERENCES estoque_movimentacoes (id) ON DELETE CASCADE,
        CONSTRAINT fk_eml_lote FOREIGN KEY (lote_id) REFERENCES estoque_lotes (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela estoque_movimentacao_lotes (sem auditoria — INSERT direto)");
} else { $log("  = estoque_movimentacao_lotes já existe"); }

// --- DB-06: tipagem do insumo ------------------------------------------------
$log("[DB-06] estoque_produtos");
$addColumn('estoque_produtos', 'tipo_insumo',          "VARCHAR(20) NULL COMMENT 'semente_muda, fertilizante, defensivo, corretivo, combustivel, lubrificante, peca, epi, material, outro'");
$addColumn('estoque_produtos', 'fabricante',           "VARCHAR(120) NULL");
$addColumn('estoque_produtos', 'registro_mapa',        "VARCHAR(40) NULL");
$addColumn('estoque_produtos', 'classe_toxicologica',  "VARCHAR(40) NULL");
$addColumn('estoque_produtos', 'carencia_dias',        "SMALLINT NULL COMMENT 'informativo - Regra 1'");
$addColumn('estoque_produtos', 'fornecedor_padrao_id', "BIGINT UNSIGNED NULL");
$addColumn('estoque_produtos', 'unidade_compra',       "VARCHAR(10) NULL");
$addColumn('estoque_produtos', 'fator_conversao',      "DECIMAL(18,6) NULL");
$addFk('estoque_produtos', 'fk_prod_forn_padrao', "FOREIGN KEY (fornecedor_padrao_id) REFERENCES fornecedores (id)");

// --- DB-08: compras_pedidos --------------------------------------------------
$log("[DB-08] compras_pedidos");
$addColumn('compras_pedidos', 'cotacao_id',            "BIGINT UNSIGNED NULL");
$addColumn('compras_pedidos', 'data_entrega_prevista', "DATE NULL");
$addColumn('compras_pedidos', 'frete_valor',           "DECIMAL(18,2) NULL");
$addColumn('compras_pedidos', 'condicao_pagamento',    "VARCHAR(60) NULL");
$addFk('compras_pedidos', 'fk_ped_cotacao', "FOREIGN KEY (cotacao_id) REFERENCES compras_cotacoes (id)");

// --- DB-09: fornecedores + backfill CNPJ --------------------------------------
$log("[DB-09] fornecedores");
$addColumn('fornecedores', 'categoria',          "VARCHAR(60) NULL");
$addColumn('fornecedores', 'cidade',             "VARCHAR(80) NULL");
$addColumn('fornecedores', 'uf',                 "CHAR(2) NULL");
$addColumn('fornecedores', 'condicao_pagamento', "VARCHAR(60) NULL");
$addColumn('fornecedores', 'observacoes',        "VARCHAR(255) NULL");
// backfill: normalizar cnpj_cpf (uq_fornecedores_doc(tenant_id,cnpj_cpf) — colisão => manter original e avisar)
$rows = $pdo->query("SELECT id, tenant_id, cnpj_cpf FROM fornecedores WHERE cnpj_cpf IS NOT NULL AND cnpj_cpf <> ''")->fetchAll();
$norm = 0; $skip = 0;
foreach ($rows as $r) {
    $limpo = preg_replace('/\D+/', '', (string)$r['cnpj_cpf']);
    if ($limpo === '' || $limpo === $r['cnpj_cpf']) { continue; }
    try {
        $st = $pdo->prepare("UPDATE fornecedores SET cnpj_cpf = ? WHERE id = ?");
        $st->execute([$limpo, (int)$r['id']]);
        $norm++;
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) { $skip++; $log("  ! colisão de CNPJ no fornecedor id={$r['id']} ({$r['cnpj_cpf']}) — mantido original, unificar manualmente"); }
        else { throw $e; }
    }
}
$log("  backfill CNPJ: $norm normalizados, $skip colisões");

// --- DB-10: maquinas / odômetro / implementos ---------------------------------
$log("[DB-10] maquinas + maquina_odometros + implementos");
$addColumn('maquinas', 'operador_padrao_id', "BIGINT UNSIGNED NULL");
$addColumn('maquinas', 'valor_aquisicao',    "DECIMAL(18,2) NULL");
$addColumn('maquinas', 'valor_residual',     "DECIMAL(18,2) NULL");
$addColumn('maquinas', 'vida_util_horas',    "DECIMAL(12,2) NULL");
$addFk('maquinas', 'fk_maq_oper_padrao', "FOREIGN KEY (operador_padrao_id) REFERENCES agro_operadores (id)");
if (!$tableExists('maquina_odometros')) {
    $pdo->exec("CREATE TABLE maquina_odometros (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id    BIGINT UNSIGNED NOT NULL,
        veiculo_id   BIGINT UNSIGNED NULL,
        maquina_id   BIGINT UNSIGNED NULL,
        data_leitura DATE NOT NULL,
        odometro     DECIMAL(12,2) NOT NULL,
        $AUDIT,
        PRIMARY KEY (id),
        KEY idx_odo_tenant (tenant_id),
        KEY idx_odo_veiculo (veiculo_id, data_leitura),
        KEY idx_odo_maquina (maquina_id, data_leitura),
        CONSTRAINT fk_odo_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela maquina_odometros (espelho de maquina_horimetros)");
} else { $log("  = maquina_odometros já existe"); }
$addColumn('implementos', 'maquina_id', "BIGINT UNSIGNED NULL COMMENT 'maquina atual'");
$addFk('implementos', 'fk_impl_maquina', "FOREIGN KEY (maquina_id) REFERENCES maquinas (id)");

// --- DB-11: manutenção preventiva ----------------------------------------------
$log("[DB-11] planos e itens de manutenção");
if (!$tableExists('maquina_planos_manutencao')) {
    $pdo->exec("CREATE TABLE maquina_planos_manutencao (
        id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id          BIGINT UNSIGNED NOT NULL,
        maquina_id         BIGINT UNSIGNED NOT NULL,
        descricao          VARCHAR(255) NOT NULL,
        intervalo_horas    DECIMAL(12,2) NULL,
        intervalo_dias     SMALLINT NULL,
        antecedencia_horas DECIMAL(12,2) NULL,
        antecedencia_dias  SMALLINT NULL,
        horimetro_ultima   DECIMAL(12,2) NULL,
        data_ultima        DATE NULL,
        ativo              TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        KEY idx_plano_tenant (tenant_id),
        KEY idx_plano_maquina (maquina_id),
        CONSTRAINT fk_plano_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id),
        CONSTRAINT fk_plano_maquina FOREIGN KEY (maquina_id) REFERENCES maquinas (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela maquina_planos_manutencao");
} else { $log("  = maquina_planos_manutencao já existe"); }
if (!$tableExists('maquina_manutencao_itens')) {
    $pdo->exec("CREATE TABLE maquina_manutencao_itens (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id      BIGINT UNSIGNED NOT NULL,
        manutencao_id  BIGINT UNSIGNED NOT NULL,
        tipo           VARCHAR(10) NOT NULL COMMENT 'peca | servico',
        produto_id     BIGINT UNSIGNED NULL,
        descricao      VARCHAR(255) NULL,
        quantidade     DECIMAL(18,4) NOT NULL DEFAULT 1,
        custo_unitario DECIMAL(18,6) NOT NULL DEFAULT 0,
        valor_total    DECIMAL(18,2) NOT NULL DEFAULT 0,
        mov_estoque_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_mmi_manut (manutencao_id),
        KEY idx_mmi_produto (produto_id),
        CONSTRAINT fk_mmi_manut FOREIGN KEY (manutencao_id) REFERENCES maquina_manutencoes (id) ON DELETE CASCADE,
        CONSTRAINT fk_mmi_produto FOREIGN KEY (produto_id) REFERENCES estoque_produtos (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela maquina_manutencao_itens (sem auditoria — INSERT direto)");
} else { $log("  = maquina_manutencao_itens já existe"); }
$addColumn('maquina_manutencoes', 'horimetro',     "DECIMAL(12,2) NULL");
$addColumn('maquina_manutencoes', 'plano_id',      "BIGINT UNSIGNED NULL");
$addColumn('maquina_manutencoes', 'fornecedor_id', "BIGINT UNSIGNED NULL");
$addFk('maquina_manutencoes', 'fk_manut_plano', "FOREIGN KEY (plano_id) REFERENCES maquina_planos_manutencao (id)");
$addFk('maquina_manutencoes', 'fk_manut_forn',  "FOREIGN KEY (fornecedor_id) REFERENCES fornecedores (id)");

// --- DB-12: abastecimento -------------------------------------------------------
$log("[DB-12] maquina_abastecimentos");
$addColumn('maquina_abastecimentos', 'operador_id', "BIGINT UNSIGNED NULL");
$addFk('maquina_abastecimentos', 'fk_abast_operador', "FOREIGN KEY (operador_id) REFERENCES agro_operadores (id)");

// --- DB-13: custo-hora snapshot --------------------------------------------------
$log("[DB-13] agro_apontamento_maquinas");
$addColumn('agro_apontamento_maquinas', 'custo_hora', "DECIMAL(18,6) NULL COMMENT 'snapshot no momento do apontamento'");

// --- DB-14: fazenda — dossiê -------------------------------------------------------
$log("[DB-14] agro_fazendas");
$addColumn('agro_fazendas', 'proprietario',    "VARCHAR(150) NULL");
$addColumn('agro_fazendas', 'cnpj_cpf',        "VARCHAR(18) NULL");
$addColumn('agro_fazendas', 'matricula',       "VARCHAR(60) NULL");
$addColumn('agro_fazendas', 'car',             "VARCHAR(60) NULL");
$addColumn('agro_fazendas', 'ccir',            "VARCHAR(60) NULL");
$addColumn('agro_fazendas', 'tipo_exploracao', "VARCHAR(20) NULL COMMENT 'propria, arrendada, parceria, comodato'");
$addColumn('agro_fazendas', 'responsavel_id',  "BIGINT UNSIGNED NULL");
$addColumn('agro_fazendas', 'observacao',      "VARCHAR(500) NULL");
$addIndex('agro_fazendas', 'idx_faz_responsavel', "responsavel_id");

// --- DB-15: talhão — dados técnicos --------------------------------------------------
$log("[DB-15] agro_talhoes");
$addColumn('agro_talhoes', 'tipo_solo',            "VARCHAR(60) NULL");
$addColumn('agro_talhoes', 'data_plantio',         "DATE NULL");
$addColumn('agro_talhoes', 'espacamento_linha_m',  "DECIMAL(5,2) NULL");
$addColumn('agro_talhoes', 'espacamento_planta_m', "DECIMAL(5,2) NULL");
$addColumn('agro_talhoes', 'num_plantas',          "INT NULL");
$addColumn('agro_talhoes', 'variedade_id',         "BIGINT UNSIGNED NULL");
$addColumn('agro_talhoes', 'observacao',           "VARCHAR(500) NULL");
$addIndex('agro_talhoes', 'idx_talhao_variedade', "variedade_id");

// --- DB-16: variedade — referência técnica ---------------------------------------------
$log("[DB-16] agro_variedades");
$addColumn('agro_variedades', 'produtividade_esperada',   "DECIMAL(12,4) NULL");
$addColumn('agro_variedades', 'unidade_produtividade',    "VARCHAR(12) NULL");
$addColumn('agro_variedades', 'ciclo_poda_colheita_dias', "INT NULL");
$addColumn('agro_variedades', 'observacao_tecnica',       "VARCHAR(1000) NULL");

// --- DB-17: atividade — planejado×realizado -----------------------------------------------
$log("[DB-17] agro_atividades");
$addColumn('agro_atividades', 'tipo_atividade_id', "BIGINT UNSIGNED NULL");
$addColumn('agro_atividades', 'data_realizada',    "DATE NULL");
$addColumn('agro_atividades', 'area_prevista_ha',  "DECIMAL(12,4) NULL");
$addColumn('agro_atividades', 'custo_previsto',    "DECIMAL(18,2) NULL COMMENT 'custo realizado NAO tem coluna - derivado do custeio'");
$addIndex('agro_atividades', 'idx_ativ_tipo', "tipo_atividade_id");
$addFk('agro_atividades', 'fk_ativ_tipo', "FOREIGN KEY (tipo_atividade_id) REFERENCES agro_tipos_atividade (id)");

// --- DB-18: aplicação — área, calda e reentrada ----------------------------------------------
$log("[DB-18] agro_aplicacoes + agro_aplicacao_itens");
$addColumn('agro_aplicacoes', 'area_aplicada_ha', "DECIMAL(12,4) NULL");
$addColumn('agro_aplicacoes', 'volume_calda_l',   "DECIMAL(12,2) NULL");
$addColumn('agro_aplicacao_itens', 'intervalo_reentrada_horas', "INT NULL COMMENT 'informado pelo RT - Regra 1'");

// --- DB-19: monitoramento — evidência e decisão do RT ------------------------------------------
$log("[DB-19] mip_monitoramentos + mip_alerta_acoes");
$addColumn('mip_monitoramentos', 'severidade_qualitativa', "VARCHAR(10) NULL COMMENT 'baixa, media, alta'");
if (!$tableExists('mip_alerta_acoes')) {
    $pdo->exec("CREATE TABLE mip_alerta_acoes (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id    BIGINT UNSIGNED NOT NULL,
        alerta_id    BIGINT UNSIGNED NOT NULL,
        acao         VARCHAR(500) NOT NULL,
        aplicacao_id BIGINT UNSIGNED NULL,
        $AUDIT,
        PRIMARY KEY (id),
        KEY idx_maa_tenant (tenant_id),
        KEY idx_maa_alerta (alerta_id),
        CONSTRAINT fk_maa_alerta FOREIGN KEY (alerta_id) REFERENCES agro_alertas (id),
        CONSTRAINT fk_maa_aplicacao FOREIGN KEY (aplicacao_id) REFERENCES agro_aplicacoes (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela mip_alerta_acoes (decisão humana do RT — agro_alertas preservada)");
} else { $log("  = mip_alerta_acoes já existe"); }

// --- DB-20: colheita — destino e causa de perda ---------------------------------------------------
$log("[DB-20] colheita_cargas + colheita_classificacoes");
$addColumn('colheita_cargas', 'destino', "VARCHAR(20) NULL COMMENT 'venda, packing, armazenagem, descarte, doacao'");
$addColumn('colheita_classificacoes', 'causa_perda', "VARCHAR(120) NULL COMMENT 'apenas categoria perdidos'");

// --- DB-21: razão — condição de pagamento (FORA do hash) -------------------------------------------
$log("[DB-21] movimentacoes_financeiras (campos fora da fórmula do hash)");
$addColumn('movimentacoes_financeiras', 'forma_pagamento', "VARCHAR(20) NULL COMMENT 'pix, boleto, transferencia, dinheiro, cheque, cartao, outro'");
$addColumn('movimentacoes_financeiras', 'documento',       "VARCHAR(40) NULL COMMENT 'nr NF/boleto'");
$addColumn('movimentacoes_financeiras', 'parcela_num',     "SMALLINT NULL");
$addColumn('movimentacoes_financeiras', 'parcela_total',   "SMALLINT NULL");
$addColumn('movimentacoes_financeiras', 'grupo_id',        "BIGINT UNSIGNED NULL COMMENT 'agrupa parcelas/recorrencia'");
$addIndex('movimentacoes_financeiras', 'idx_mf_grupo', "grupo_id");

// --- DB-23: reemissão sem furo no hash ---------------------------------------------------------------
$log("[DB-23] origem_ativa + substituida_por_id + uq_mf_origem recomposto");
$addColumn('movimentacoes_financeiras', 'substituida_por_id', "BIGINT UNSIGNED NULL COMMENT 'linha nova que substituiu esta na reemissao (DB-23)'");
$addColumn('movimentacoes_financeiras', 'origem_ativa',       "TINYINT(1) NULL DEFAULT 1 COMMENT 'NULL = cancelada/substituida (libera uq_mf_origem)'");
// backfill: canceladas liberam a chave de origem
$n = $pdo->exec("UPDATE movimentacoes_financeiras SET origem_ativa = NULL WHERE status = 'cancelado' AND origem_ativa = 1");
$log("  backfill origem_ativa=NULL em $n canceladas");
$cols = $indexCols('movimentacoes_financeiras', 'uq_mf_origem');
if ($cols && !in_array('origem_ativa', $cols, true)) {
    $pdo->exec("ALTER TABLE movimentacoes_financeiras DROP INDEX uq_mf_origem, ADD UNIQUE KEY uq_mf_origem (tenant_id, origem_tipo, origem_id, origem_ativa)");
    $log("  ~ uq_mf_origem recomposto: (tenant_id, origem_tipo, origem_id, origem_ativa)");
} else {
    $log("  = uq_mf_origem já recomposto");
}
$addFk('movimentacoes_financeiras', 'fk_mf_substituida', "FOREIGN KEY (substituida_por_id) REFERENCES movimentacoes_financeiras (id)");

$log("== migration 134 concluída ==");
