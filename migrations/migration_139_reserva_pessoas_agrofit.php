<?php
declare(strict_types=1);

// ============================================================================
// migration_139_reserva_pessoas_agrofit.php | VERO — Pacote A0-11 (04/07/2026)
//   DB-35 agro_atividade_insumos (P-30: insumos planejados; reserva DERIVADA)
//   DB-36 agrofit_catalogo (catálogo local do CSV oficial — criar produto
//         pelo nº de registro; A2-F2-13)
//   DB-37 rh_treinamento_temas/turmas/presencas (NR-31 / IFA v6)
//   DB-38 rh_epi_itens/entregas + agro_aplicacao_operadores.epi_entrega_id
//         (proposta CONJUNTA A3+A1 — aditiva, texto livre preservado)
//   DB-39 rt_registros (RT formal — absorve DB-03/P-10)
// Idempotente. Backup: backup_pre_139_*.sql.
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

$log("== migration 139 — reserva + Pessoas-IFA + Agrofit (A0-11) ==");

$log("[DB-35] agro_atividade_insumos");
if (!$tableExists('agro_atividade_insumos')) {
    $pdo->exec("CREATE TABLE agro_atividade_insumos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        atividade_id BIGINT UNSIGNED NOT NULL,
        produto_id BIGINT UNSIGNED NOT NULL,
        quantidade_prevista DECIMAL(18,4) NOT NULL DEFAULT 0,
        custo_unitario_previsto DECIMAL(18,6) NULL,
        observacao VARCHAR(255) NULL,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ativ_insumo (tenant_id, atividade_id, produto_id),
        KEY idx_ai_produto (produto_id),
        CONSTRAINT fk_ai_atividade FOREIGN KEY (atividade_id) REFERENCES agro_atividades (id) ON DELETE CASCADE,
        CONSTRAINT fk_ai_produto FOREIGN KEY (produto_id) REFERENCES estoque_produtos (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + agro_atividade_insumos (reserva DERIVADA — sem coluna de saldo)");
} else { $log("  = já existe"); }

$log("[DB-36] agrofit_catalogo");
if (!$tableExists('agrofit_catalogo')) {
    $pdo->exec("CREATE TABLE agrofit_catalogo (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        nr_registro VARCHAR(20) NOT NULL,
        marca_comercial VARCHAR(150) NULL,
        ingrediente_ativo VARCHAR(255) NULL,
        titular VARCHAR(150) NULL,
        classe VARCHAR(100) NULL,
        classe_toxicologica VARCHAR(80) NULL,
        classe_ambiental VARCHAR(80) NULL,
        culturas TEXT NULL,
        pragas TEXT NULL,
        importado_em DATETIME NULL,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_agrofit_reg (tenant_id, nr_registro),
        KEY idx_agrofit_marca (marca_comercial)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + agrofit_catalogo (upsert por nr_registro — CSV oficial, A2-F2-13)");
} else { $log("  = já existe"); }

$log("[DB-37] treinamentos NR-31");
if (!$tableExists('rh_treinamento_temas')) {
    $pdo->exec("CREATE TABLE rh_treinamento_temas (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        nome VARCHAR(150) NOT NULL,
        norma VARCHAR(20) NULL,
        validade_meses SMALLINT NULL COMMENT 'NULL = nao vence; conteudo definido pelo cliente/RT (P-61) - nada semeado',
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tema (tenant_id, nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + rh_treinamento_temas");
} else { $log("  = temas já existe"); }
if (!$tableExists('rh_treinamento_turmas')) {
    $pdo->exec("CREATE TABLE rh_treinamento_turmas (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        tema_id BIGINT UNSIGNED NOT NULL,
        data DATE NOT NULL,
        instrutor_operador_id BIGINT UNSIGNED NULL,
        instrutor_externo VARCHAR(120) NULL,
        carga_horas DECIMAL(5,1) NULL,
        observacao VARCHAR(255) NULL,
        $AUDIT,
        PRIMARY KEY (id),
        KEY idx_turma_tema (tema_id),
        CONSTRAINT fk_turma_tema FOREIGN KEY (tema_id) REFERENCES rh_treinamento_temas (id),
        CONSTRAINT fk_turma_instrutor FOREIGN KEY (instrutor_operador_id) REFERENCES agro_operadores (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + rh_treinamento_turmas");
} else { $log("  = turmas já existe"); }
if (!$tableExists('rh_treinamento_presencas')) {
    $pdo->exec("CREATE TABLE rh_treinamento_presencas (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        turma_id BIGINT UNSIGNED NOT NULL,
        operador_id BIGINT UNSIGNED NOT NULL,
        assinado_em DATETIME NULL,
        assinatura_hash CHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_presenca (turma_id, operador_id),
        KEY idx_pres_operador (operador_id),
        CONSTRAINT fk_pres_turma FOREIGN KEY (turma_id) REFERENCES rh_treinamento_turmas (id) ON DELETE CASCADE,
        CONSTRAINT fk_pres_operador FOREIGN KEY (operador_id) REFERENCES agro_operadores (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + rh_treinamento_presencas (sem auditoria — INSERT direto)");
} else { $log("  = presenças já existe"); }

$log("[DB-38] gestão de EPI");
if (!$tableExists('rh_epi_itens')) {
    $pdo->exec("CREATE TABLE rh_epi_itens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        nome VARCHAR(120) NOT NULL,
        ca VARCHAR(20) NULL COMMENT 'certificado de aprovacao',
        validade_ca DATE NULL,
        vida_util_meses SMALLINT NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_epi_item (tenant_id, nome, ca)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + rh_epi_itens");
} else { $log("  = itens já existe"); }
if (!$tableExists('rh_epi_entregas')) {
    $pdo->exec("CREATE TABLE rh_epi_entregas (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        operador_id BIGINT UNSIGNED NOT NULL,
        item_id BIGINT UNSIGNED NOT NULL,
        data_entrega DATE NOT NULL,
        quantidade DECIMAL(10,2) NOT NULL DEFAULT 1,
        assinado_em DATETIME NULL,
        assinatura_hash CHAR(64) NULL,
        devolvido_em DATE NULL,
        motivo_devolucao VARCHAR(30) NULL COMMENT 'desgaste, dano, desligamento, outro - validacao PHP',
        $AUDIT,
        PRIMARY KEY (id),
        KEY idx_ee_operador (operador_id),
        KEY idx_ee_item (item_id),
        CONSTRAINT fk_ee_operador FOREIGN KEY (operador_id) REFERENCES agro_operadores (id),
        CONSTRAINT fk_ee_item FOREIGN KEY (item_id) REFERENCES rh_epi_itens (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + rh_epi_entregas");
} else { $log("  = entregas já existe"); }
if (!$columnExists('agro_aplicacao_operadores', 'epi_entrega_id')) {
    $pdo->exec("ALTER TABLE agro_aplicacao_operadores ADD COLUMN epi_entrega_id BIGINT UNSIGNED NULL
                COMMENT 'DF referencia a entrega vigente (DB-38, proposta conjunta A3+A1); texto livre epi_codigo preservado'");
    $pdo->exec("ALTER TABLE agro_aplicacao_operadores ADD CONSTRAINT fk_ao_epi_entrega FOREIGN KEY (epi_entrega_id) REFERENCES rh_epi_entregas (id)");
    $log("  + agro_aplicacao_operadores.epi_entrega_id (+FK)");
} else { $log("  = epi_entrega_id já existe"); }

$log("[DB-39] rt_registros");
if (!$tableExists('rt_registros')) {
    $pdo->exec("CREATE TABLE rt_registros (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        operador_id BIGINT UNSIGNED NOT NULL,
        conselho VARCHAR(10) NOT NULL COMMENT 'crea, cfta, outro - validacao PHP',
        numero VARCHAR(30) NOT NULL,
        uf CHAR(2) NULL,
        validade DATE NULL,
        culturas VARCHAR(255) NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_rt (tenant_id, operador_id, conselho, numero),
        CONSTRAINT fk_rt_operador FOREIGN KEY (operador_id) REFERENCES agro_operadores (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + rt_registros (absorve DB-03/P-10)");
} else { $log("  = já existe"); }

$log("== migration 139 concluída ==");
