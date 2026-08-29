<?php
declare(strict_types=1);

// ============================================================================
// migration_140_motor_custo_producao.php | VERO — Pacote A0-13 (05/07/2026)
//   Motor Global de Custo de Produção por Cultura — Fase 1 (A0-12 aprovada,
//   P-66..79 respondidas: "recomendações aceitas").
//   DB-40 agro_custo_metodologias (+ seed "Padrão VERO" anual e perene)
//   DB-41 ALTER agro_culturas: unidade_comercial/fator_conversao/peso_unidade_kg
//   DB-42 agro_custo_grupos
//   DB-43 agro_custo_itens (mapa_realizado JSON — coração da derivação F2)
//   DB-44 agro_custo_parametros_cultura
//   DB-45 agro_custo_orcamentos + agro_custo_orcamento_itens
//   Realizado NÃO tem coluna (deriva de custeio_lancamentos) exceto origem
//   manual. Campos categóricos = VARCHAR + validação PHP (regra permanente).
// Idempotente. Backup: backup_pre_140_*.sql.
// ============================================================================

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$log = fn(string $m) => print($m . "\n");
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
$AUDIT = "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL";

$log("== migration 140 — motor global de custo de produção (A0-13 / Fase 1) ==");

$log("[DB-40] agro_custo_metodologias");
if (!$tableExists('agro_custo_metodologias')) {
    $pdo->exec("CREATE TABLE agro_custo_metodologias (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        nome VARCHAR(100) NOT NULL,
        descricao VARCHAR(255) NULL,
        tipo_ciclo VARCHAR(10) NOT NULL DEFAULT 'anual' COMMENT 'anual|perene — VARCHAR validado em PHP',
        formacao_rateio_safras SMALLINT NULL COMMENT 'perene: N safras para ratear o grupo Formacao',
        padrao TINYINT(1) NOT NULL DEFAULT 0,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_custo_met (tenant_id, nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + agro_custo_metodologias");
} else { $log("  = já existe"); }

$log("[DB-41] agro_culturas — unidade comercial");
foreach ([
    'unidade_comercial' => "VARCHAR(12) NULL COMMENT 'kg|cx|t|sc|@|L|un — livre, validado em PHP'",
    'fator_conversao' => "DECIMAL(18,6) NULL COMMENT 'unidade_produtividade -> unidade_comercial'",
    'peso_unidade_kg' => "DECIMAL(10,3) NULL",
] as $col => $def) {
    if (!$columnExists('agro_culturas', $col)) {
        $pdo->exec("ALTER TABLE agro_culturas ADD COLUMN $col $def");
        $log("  + agro_culturas.$col");
    } else { $log("  = $col já existe"); }
}

$log("[DB-42] agro_custo_grupos");
if (!$tableExists('agro_custo_grupos')) {
    $pdo->exec("CREATE TABLE agro_custo_grupos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        metodologia_id BIGINT UNSIGNED NOT NULL,
        nome VARCHAR(100) NOT NULL,
        tipo VARCHAR(12) NOT NULL DEFAULT 'variavel' COMMENT 'variavel|fixo|operacional — VARCHAR validado em PHP',
        descricao VARCHAR(255) NULL,
        ordem SMALLINT NOT NULL DEFAULT 0,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_custo_grupo (tenant_id, metodologia_id, nome),
        CONSTRAINT fk_cgrupo_met FOREIGN KEY (metodologia_id) REFERENCES agro_custo_metodologias (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + agro_custo_grupos");
} else { $log("  = já existe"); }

$log("[DB-43] agro_custo_itens");
if (!$tableExists('agro_custo_itens')) {
    $pdo->exec("CREATE TABLE agro_custo_itens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        grupo_id BIGINT UNSIGNED NOT NULL,
        nome VARCHAR(120) NOT NULL,
        descricao VARCHAR(255) NULL,
        unidade_calculo VARCHAR(20) NULL,
        metodo_calculo VARCHAR(30) NOT NULL DEFAULT 'manual_ha' COMMENT 'manual_ha|valor_total_area|quantidade_valor_unitario|maquina_hora|estoque_consumo|compra_recebida|folha_rateada|patrimonio_depreciacao|percentual — PHP valida',
        origem VARCHAR(15) NOT NULL DEFAULT 'custeio' COMMENT 'custeio|manual|compra — PHP valida',
        percentual_base VARCHAR(15) NULL COMMENT 'grupo|total (metodo percentual)',
        percentual DECIMAL(6,3) NULL,
        mapa_realizado JSON NULL COMMENT 'F2: {origens:[],categorias:[],planos:[]} — deriva de custeio_lancamentos; sobreposicao EXATA entre itens ativos = BLOQUEIO',
        ordem SMALLINT NOT NULL DEFAULT 0,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_custo_item (tenant_id, grupo_id, nome),
        CONSTRAINT fk_citem_grupo FOREIGN KEY (grupo_id) REFERENCES agro_custo_grupos (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + agro_custo_itens (mapa_realizado JSON)");
} else { $log("  = já existe"); }

$log("[DB-44] agro_custo_parametros_cultura");
if (!$tableExists('agro_custo_parametros_cultura')) {
    $pdo->exec("CREATE TABLE agro_custo_parametros_cultura (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        cultura_id BIGINT UNSIGNED NOT NULL,
        safra_id BIGINT UNSIGNED NOT NULL,
        metodologia_id BIGINT UNSIGNED NOT NULL,
        produtividade_prevista_ha DECIMAL(18,4) NULL COMMENT 'na unidade_produtividade da cultura',
        preco_previsto_unidade DECIMAL(18,6) NULL COMMENT 'R$ por unidade_comercial da cultura (DB-41 — unidades NUNCA duplicadas aqui)',
        area_prevista_ha DECIMAL(18,4) NULL,
        observacoes VARCHAR(255) NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_custo_param (tenant_id, cultura_id, safra_id),
        KEY idx_cparam_safra (safra_id),
        CONSTRAINT fk_cparam_cultura FOREIGN KEY (cultura_id) REFERENCES agro_culturas (id),
        CONSTRAINT fk_cparam_safra FOREIGN KEY (safra_id) REFERENCES agro_safras (id),
        CONSTRAINT fk_cparam_met FOREIGN KEY (metodologia_id) REFERENCES agro_custo_metodologias (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + agro_custo_parametros_cultura");
} else { $log("  = já existe"); }

$log("[DB-45] agro_custo_orcamentos + itens");
if (!$tableExists('agro_custo_orcamentos')) {
    $pdo->exec("CREATE TABLE agro_custo_orcamentos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        cultura_id BIGINT UNSIGNED NOT NULL,
        safra_id BIGINT UNSIGNED NOT NULL,
        fazenda_id BIGINT UNSIGNED NULL COMMENT 'NULL = todas',
        talhao_id BIGINT UNSIGNED NULL COMMENT 'NULL = fazenda inteira (P-69)',
        metodologia_id BIGINT UNSIGNED NOT NULL,
        area_ha DECIMAL(18,4) NOT NULL DEFAULT 0,
        produtividade_prevista_ha DECIMAL(18,4) NULL COMMENT 'snapshot dos parametros na criacao',
        preco_previsto_unidade DECIMAL(18,6) NULL COMMENT 'snapshot dos parametros na criacao',
        status VARCHAR(15) NOT NULL DEFAULT 'rascunho' COMMENT 'rascunho|aprovado|em_execucao|fechado|cancelado — PHP valida',
        aprovado_por BIGINT UNSIGNED NULL,
        aprovado_em DATETIME NULL,
        fechado_em DATETIME NULL,
        snapshot_resultados JSON NULL COMMENT 'gravado APENAS no fechamento — resto e view/service',
        observacoes VARCHAR(255) NULL,
        $AUDIT,
        PRIMARY KEY (id),
        KEY idx_corc_safra (tenant_id, safra_id, cultura_id),
        KEY idx_corc_talhao (talhao_id),
        CONSTRAINT fk_corc_cultura FOREIGN KEY (cultura_id) REFERENCES agro_culturas (id),
        CONSTRAINT fk_corc_safra FOREIGN KEY (safra_id) REFERENCES agro_safras (id),
        CONSTRAINT fk_corc_met FOREIGN KEY (metodologia_id) REFERENCES agro_custo_metodologias (id),
        CONSTRAINT fk_corc_talhao FOREIGN KEY (talhao_id) REFERENCES agro_talhoes (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + agro_custo_orcamentos");
} else { $log("  = já existe"); }
if (!$tableExists('agro_custo_orcamento_itens')) {
    $pdo->exec("CREATE TABLE agro_custo_orcamento_itens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        orcamento_id BIGINT UNSIGNED NOT NULL,
        item_id BIGINT UNSIGNED NOT NULL,
        grupo_id BIGINT UNSIGNED NOT NULL,
        quantidade_prevista DECIMAL(18,4) NULL,
        valor_unitario_previsto DECIMAL(18,6) NULL,
        valor_previsto_ha DECIMAL(18,4) NOT NULL DEFAULT 0,
        valor_previsto_total DECIMAL(18,2) NOT NULL DEFAULT 0,
        formula_registrada VARCHAR(255) NULL COMMENT 'rastreabilidade: metodo + bases usadas no previsto',
        valor_realizado_manual DECIMAL(18,2) NULL COMMENT 'APENAS itens origem=manual (P-74: gestor/financeiro com justificativa)',
        justificativa_manual VARCHAR(255) NULL,
        ordem SMALLINT NOT NULL DEFAULT 0,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_corc_item (tenant_id, orcamento_id, item_id),
        KEY idx_coi_grupo (grupo_id),
        CONSTRAINT fk_coi_orc FOREIGN KEY (orcamento_id) REFERENCES agro_custo_orcamentos (id) ON DELETE CASCADE,
        CONSTRAINT fk_coi_item FOREIGN KEY (item_id) REFERENCES agro_custo_itens (id),
        CONSTRAINT fk_coi_grupo FOREIGN KEY (grupo_id) REFERENCES agro_custo_grupos (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + agro_custo_orcamento_itens (realizado SEM coluna — deriva; exceto manual)");
} else { $log("  = já existe"); }

/* ---------------------------------------------------------------------------
   Seed metodologia "Padrão VERO" (P-71: simplificada — 3 grupos, ~13 itens;
   perene ganha grupo Formação). Cada origem do custeio aparece em EXATAMENTE
   um item (anti-duplicação por construção). Itens editáveis/inativáveis.
--------------------------------------------------------------------------- */
$log("[seed] metodologias Padrão VERO");
$gruposSeed = [
    ['Custos Variáveis', 'variavel', 1, [
        ['Defensivos (aplicações)', 'estoque_consumo', 'custeio', ['origens' => ['aplicacao'], 'categorias' => [], 'planos' => []]],
        ['Fertilizantes e corretivos', 'estoque_consumo', 'custeio', ['origens' => ['apontamento_insumo'], 'categorias' => [], 'planos' => []]],
        ['Fertirrigação (consumo)', 'estoque_consumo', 'custeio', ['origens' => ['irrigacao_consumo'], 'categorias' => [], 'planos' => []]],
        ['Mão de obra de campo', 'folha_rateada', 'custeio', ['origens' => ['rh_producao_item'], 'categorias' => [], 'planos' => []]],
        ['Operações mecanizadas (custo-hora)', 'maquina_hora', 'custeio', ['origens' => ['apontamento_maquina'], 'categorias' => [], 'planos' => []]],
        ['Combustível', 'quantidade_valor_unitario', 'custeio', ['origens' => ['maquina_abastecimento'], 'categorias' => [], 'planos' => []]],
        ['Manutenção de máquinas', 'valor_total_area', 'custeio', ['origens' => ['maquina_manutencao'], 'categorias' => [], 'planos' => []]],
        ['Serviços e fretes contratados', 'compra_recebida', 'compra', null], // não-estocáveis; planos definidos na F2
    ]],
    ['Custos Fixos', 'fixo', 2, [
        ['Depreciação', 'patrimonio_depreciacao', 'custeio', ['origens' => ['patrimonio_depreciacao'], 'categorias' => [], 'planos' => []]],
        ['Arrendamento', 'manual_ha', 'manual', null],
        ['Administração', 'manual_ha', 'manual', null],
    ]],
    ['Operacional / Econômico', 'operacional', 3, [
        ['Juros de custeio', 'manual_ha', 'manual', null],
        ['Outros custos', 'manual_ha', 'manual', null],
    ]],
];
$tenants = $pdo->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
$insMet = $pdo->prepare("INSERT INTO agro_custo_metodologias (tenant_id, nome, descricao, tipo_ciclo, formacao_rateio_safras, padrao, ativo) VALUES (?,?,?,?,?,1,1)");
$insGrp = $pdo->prepare("INSERT INTO agro_custo_grupos (tenant_id, metodologia_id, nome, tipo, ordem, ativo) VALUES (?,?,?,?,?,1)");
$insIt = $pdo->prepare("INSERT INTO agro_custo_itens (tenant_id, grupo_id, nome, metodo_calculo, origem, mapa_realizado, ordem, ativo) VALUES (?,?,?,?,?,?,?,1)");
foreach ($tenants as $tid) {
    foreach ([
        ['Padrão VERO — Anual', 'anual', null, 'Metodologia simplificada padrão para culturas anuais (soja, milho, feijão...). Editável e inativável.'],
        ['Padrão VERO — Perene', 'perene', 5, 'Metodologia simplificada padrão para perenes (uva, café, citros...) com grupo Formação rateado. Editável e inativável.'],
    ] as [$nome, $ciclo, $rateio, $desc]) {
        $tem = $pdo->prepare("SELECT id FROM agro_custo_metodologias WHERE tenant_id = ? AND nome = ?");
        $tem->execute([$tid, $nome]);
        if ($tem->fetchColumn()) { $log("  = tenant $tid: '$nome' já existe"); continue; }
        $insMet->execute([$tid, $nome, $desc, $ciclo, $rateio]);
        $metId = (int)$pdo->lastInsertId();
        $grupos = $gruposSeed;
        if ($ciclo === 'perene') {
            $grupos[] = ['Formação', 'fixo', 4, [
                ['Rateio de formação (implantação ÷ N safras)', 'valor_total_area', 'manual', null],
            ]];
        }
        $nItens = 0;
        foreach ($grupos as [$gNome, $gTipo, $gOrdem, $itens]) {
            $insGrp->execute([$tid, $metId, $gNome, $gTipo, $gOrdem]);
            $grpId = (int)$pdo->lastInsertId();
            foreach ($itens as $i => [$iNome, $metodo, $origem, $mapa]) {
                $insIt->execute([$tid, $grpId, $iNome, $metodo, $origem,
                    $mapa === null ? null : json_encode($mapa, JSON_UNESCAPED_UNICODE), $i + 1]);
                $nItens++;
            }
        }
        $log("  + tenant $tid: '$nome' (" . count($grupos) . " grupos, $nItens itens)");
    }
}

$log("== migration 140 concluída ==");
