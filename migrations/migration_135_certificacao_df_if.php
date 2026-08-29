<?php
declare(strict_types=1);

// ============================================================================
// migration_135_certificacao_df_if.php | VERO
// Pacote A0-07 (Rodada 4 — Certificação GlobalG.A.P. IFA v6, análises
// A1-25/A2-05 auditadas em 04/07/2026):
//   DB-27  estoque_produtos (bula: dose ref., LMR, intervalo, nº máx,
//          estoque_ideal) + estoque_produto_nutrientes + maquinas.capacidade_tanque_l
//   DB-28  agro_aplicacoes: documento DF/IF (série/número por fazenda,
//          forma de aplicação, parâmetros por via, bomba, monitoramento-
//          justificativa, execução real, confirmação JSON, tríplice lavagem)
//   DB-29  agro_aplicacao_valvulas (1..N válvulas por documento — DF31)
//   DB-30  agro_aplicacao_itens: snapshot de bula (imutabilidade documental)
//   DB-31  agro_bombas + agro_bomba_valvulas (IF)
//   DB-32  agro_fenologia_periodos (fase AUTO pela data)
//   DB-33  agro_aplicacao_operadores (EPI + assinatura P-48-ready)
// Idempotente (checa information_schema). Backup: backup_pre_135_*.sql.
// Executar: php migrations/migration_135_certificacao_df_if.php
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
$indexExists = function (string $t, string $idx) use ($pdo): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
    $st->execute([$t, $idx]);
    return (bool)$st->fetchColumn();
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
$addIndex = function (string $t, string $idx, string $colsSql, bool $unique = false) use ($pdo, $indexExists, $log): void {
    if ($indexExists($t, $idx)) { $log("  = índice $t.$idx já existe"); return; }
    $pdo->exec("ALTER TABLE `$t` ADD " . ($unique ? 'UNIQUE ' : '') . "INDEX `$idx` ($colsSql)");
    $log("  + índice " . ($unique ? 'UNIQUE ' : '') . "$t.$idx");
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

$log("== migration 135 — certificação DF/IF (pacote A0-07) ==");

// --- DB-27: bula do produto + nutrientes + tanque -----------------------------
$log("[DB-27] estoque_produtos (bula) + estoque_produto_nutrientes + maquinas.capacidade_tanque_l");
$addColumn('estoque_produtos', 'dose_referencia',           "DECIMAL(18,6) NULL COMMENT 'registro de bula pelo RT - Regra 1'");
$addColumn('estoque_produtos', 'dose_referencia_unidade',   "VARCHAR(12) NULL");
$addColumn('estoque_produtos', 'lmr_dias',                  "SMALLINT NULL COMMENT 'conceito do cliente: limite de dias p/ aplicacao (P-49)'");
$addColumn('estoque_produtos', 'intervalo_aplicacoes_dias', "SMALLINT NULL");
$addColumn('estoque_produtos', 'num_max_aplicacoes',        "SMALLINT NULL");
$addColumn('estoque_produtos', 'estoque_ideal',             "DECIMAL(18,4) NOT NULL DEFAULT 0");
if (!$tableExists('estoque_produto_nutrientes')) {
    $pdo->exec("CREATE TABLE estoque_produto_nutrientes (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id  BIGINT UNSIGNED NOT NULL,
        produto_id BIGINT UNSIGNED NOT NULL,
        nutriente  VARCHAR(6) NOT NULL COMMENT 'N,P,K,Mg,Ca,S,B,Zn,Fe,Cu,Mn,Mo,Co,Mo1,C,Si - validado em PHP',
        percentual DECIMAL(8,4) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_prod_nutriente (tenant_id, produto_id, nutriente),
        CONSTRAINT fk_epn_produto FOREIGN KEY (produto_id) REFERENCES estoque_produtos (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela estoque_produto_nutrientes (sem auditoria — INSERT direto)");
} else { $log("  = estoque_produto_nutrientes já existe"); }
$addColumn('maquinas', 'capacidade_tanque_l', "DECIMAL(10,2) NULL COMMENT 'calculo por tanque do impresso DF (ex.: drone 70L)'");

// --- DB-31: bombas × válvulas (criar ANTES da FK da DB-28) ---------------------
$log("[DB-31] agro_bombas + agro_bomba_valvulas");
if (!$tableExists('agro_bombas')) {
    $pdo->exec("CREATE TABLE agro_bombas (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id  BIGINT UNSIGNED NOT NULL,
        fazenda_id BIGINT UNSIGNED NOT NULL,
        nome       VARCHAR(80) NOT NULL,
        codigo     VARCHAR(20) NULL,
        vazao_m3h  DECIMAL(10,2) NULL,
        ativo      TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        KEY idx_bomba_tenant (tenant_id),
        KEY idx_bomba_fazenda (fazenda_id),
        CONSTRAINT fk_bomba_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id),
        CONSTRAINT fk_bomba_fazenda FOREIGN KEY (fazenda_id) REFERENCES agro_fazendas (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela agro_bombas");
} else { $log("  = agro_bombas já existe"); }
if (!$tableExists('agro_bomba_valvulas')) {
    $pdo->exec("CREATE TABLE agro_bomba_valvulas (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id  BIGINT UNSIGNED NOT NULL,
        bomba_id   BIGINT UNSIGNED NOT NULL,
        setor_id   BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_bomba_valvula (tenant_id, bomba_id, setor_id),
        KEY idx_bv_setor (setor_id),
        CONSTRAINT fk_bv_bomba FOREIGN KEY (bomba_id) REFERENCES agro_bombas (id) ON DELETE CASCADE,
        CONSTRAINT fk_bv_setor FOREIGN KEY (setor_id) REFERENCES agro_setores (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela agro_bomba_valvulas (sem auditoria — INSERT direto)");
} else { $log("  = agro_bomba_valvulas já existe"); }

// --- DB-28: documento DF/IF no cabeçalho ---------------------------------------
$log("[DB-28] agro_aplicacoes (documento DF/IF)");
$addColumn('agro_aplicacoes', 'doc_serie',             "VARCHAR(2) NULL COMMENT 'DF | IF - validacao PHP'");
$addColumn('agro_aplicacoes', 'doc_numero',            "INT UNSIGNED NULL");
$addColumn('agro_aplicacoes', 'forma_aplicacao',       "VARCHAR(20) NULL COMMENT 'drone, trator_pulverizador, costal, fertirrigacao - validacao PHP'");
$addColumn('agro_aplicacoes', 'parametros_aplicacao',  "JSON NULL COMMENT 'drone: faixa_m, velocidade_ms, gota_micras, altura_m; trator: mancha, velocidade, bico, horimetro_inicial, horimetro_final'");
$addColumn('agro_aplicacoes', 'bomba_id',              "BIGINT UNSIGNED NULL");
$addColumn('agro_aplicacoes', 'monitoramento_id',      "BIGINT UNSIGNED NULL COMMENT 'justificativa MIP (IFA v6 Major Must)'");
$addColumn('agro_aplicacoes', 'executada_inicio',      "DATETIME NULL");
$addColumn('agro_aplicacoes', 'executada_fim',         "DATETIME NULL COMMENT 'REI/carencia contam do fim (IFA v6)'");
$addColumn('agro_aplicacoes', 'confirmacao',           "JSON NULL COMMENT 'vento_kmh_real, pluviosidade_mm, ceu, vento_class, destino_sobra_calda (CB 7.5), obs'");
$addColumn('agro_aplicacoes', 'triplice_lavagem',      "TINYINT(1) NULL");
$addIndex('agro_aplicacoes', 'uq_aplic_doc', "tenant_id, fazenda_id, doc_serie, doc_numero", true);
$addIndex('agro_aplicacoes', 'idx_aplic_monitoramento', "monitoramento_id");
$addFk('agro_aplicacoes', 'fk_aplic_bomba', "FOREIGN KEY (bomba_id) REFERENCES agro_bombas (id)");
$addFk('agro_aplicacoes', 'fk_aplic_monit', "FOREIGN KEY (monitoramento_id) REFERENCES mip_monitoramentos (id)");

// --- DB-29: linhas de válvula -----------------------------------------------------
$log("[DB-29] agro_aplicacao_valvulas");
if (!$tableExists('agro_aplicacao_valvulas')) {
    $pdo->exec("CREATE TABLE agro_aplicacao_valvulas (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id      BIGINT UNSIGNED NOT NULL,
        aplicacao_id   BIGINT UNSIGNED NOT NULL,
        setor_id       BIGINT UNSIGNED NOT NULL,
        area_ha        DECIMAL(12,4) NULL,
        volume_calda_l DECIMAL(12,2) NULL,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aplic_valvula (tenant_id, aplicacao_id, setor_id),
        KEY idx_av_setor (setor_id),
        CONSTRAINT fk_av_aplicacao FOREIGN KEY (aplicacao_id) REFERENCES agro_aplicacoes (id) ON DELETE CASCADE,
        CONSTRAINT fk_av_setor     FOREIGN KEY (setor_id)     REFERENCES agro_setores (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela agro_aplicacao_valvulas");
} else { $log("  = agro_aplicacao_valvulas já existe"); }

// --- DB-30: snapshot de bula no item ------------------------------------------------
$log("[DB-30] agro_aplicacao_itens (snapshot de bula)");
$addColumn('agro_aplicacao_itens', 'intervalo_aplicacoes_dias', "SMALLINT NULL COMMENT 'snapshot da bula na emissao'");
$addColumn('agro_aplicacao_itens', 'num_max_aplicacoes',        "SMALLINT NULL");
$addColumn('agro_aplicacao_itens', 'lmr_dias',                  "SMALLINT NULL");
$addColumn('agro_aplicacao_itens', 'nutrientes_snapshot',       "JSON NULL COMMENT 'copia de estoque_produto_nutrientes na emissao'");

// --- DB-32: períodos fenológicos ------------------------------------------------------
$log("[DB-32] agro_fenologia_periodos");
if (!$tableExists('agro_fenologia_periodos')) {
    $pdo->exec("CREATE TABLE agro_fenologia_periodos (
        id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id            BIGINT UNSIGNED NOT NULL,
        safra_id             BIGINT UNSIGNED NOT NULL,
        safra_talhao_id      BIGINT UNSIGNED NULL COMMENT 'NULL = vale para toda a safra',
        fenologia_estagio_id BIGINT UNSIGNED NOT NULL,
        data_inicio          DATE NOT NULL,
        data_fim             DATE NOT NULL,
        $AUDIT,
        PRIMARY KEY (id),
        KEY idx_fp_tenant (tenant_id),
        KEY idx_fp_safra (safra_id, data_inicio),
        KEY idx_fp_st (safra_talhao_id),
        CONSTRAINT fk_fp_safra   FOREIGN KEY (safra_id)             REFERENCES agro_safras (id),
        CONSTRAINT fk_fp_st      FOREIGN KEY (safra_talhao_id)      REFERENCES agro_safra_talhoes (id),
        CONSTRAINT fk_fp_estagio FOREIGN KEY (fenologia_estagio_id) REFERENCES agro_fenologia_estagios (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela agro_fenologia_periodos");
} else { $log("  = agro_fenologia_periodos já existe"); }

// --- DB-33: operadores/EPI/assinatura -----------------------------------------------------
$log("[DB-33] agro_aplicacao_operadores");
if (!$tableExists('agro_aplicacao_operadores')) {
    $pdo->exec("CREATE TABLE agro_aplicacao_operadores (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id       BIGINT UNSIGNED NOT NULL,
        aplicacao_id    BIGINT UNSIGNED NOT NULL,
        operador_id     BIGINT UNSIGNED NOT NULL,
        epi_codigo      VARCHAR(40) NULL,
        epi_lavagem     TINYINT(1) NULL,
        epi_condicao    VARCHAR(60) NULL COMMENT 'otimo, bom, ruim - validacao PHP (P-53)',
        assinado_em     DATETIME NULL COMMENT 'assinatura digital do app (P-48) - NULL na fase web',
        assinatura_hash CHAR(64) NULL,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aplic_operador (tenant_id, aplicacao_id, operador_id),
        KEY idx_ao_operador (operador_id),
        CONSTRAINT fk_ao_aplicacao FOREIGN KEY (aplicacao_id) REFERENCES agro_aplicacoes (id) ON DELETE CASCADE,
        CONSTRAINT fk_ao_operador  FOREIGN KEY (operador_id)  REFERENCES agro_operadores (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + tabela agro_aplicacao_operadores (P-48-ready)");
} else { $log("  = agro_aplicacao_operadores já existe"); }

$log("== migration 135 concluída ==");
