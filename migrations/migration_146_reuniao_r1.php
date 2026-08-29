<?php
declare(strict_types=1);

// ============================================================================
// migration_146_reuniao_r1.php | VERO — Pacote A0-19 R1 (06/07/2026)
//   Fundação da reunião de alinhamento (P-90/91/94/97 aceitas):
//   DB-51 mip_alvo_produtos — produtos indicados POR ALVO, cadastrados pelo
//         RT com trilha (P-97; Regra 1: o sistema lista, nunca recomenda).
//         É o fio monitoramento → pulverização → estoque.
//   DB-52 agro_calc_parametros — parâmetros das calculadoras de mão de obra
//         por tipo de atividade (P-91: valores virão do documento do cliente;
//         a fórmula deixa de ser fixa no código).
//   DB-53 irrigacao_fase_parametros + agro_setores.tipo_irrigacao — volume/
//         tempo ideal por fase fenológica (sugestão, nunca trava).
//   DB-54 mip_monitoramentos: quantidade_encontrada (índice passa a ser
//         CALCULADO), status rascunho|enviado (fluxo enviar-p/-líder),
//         monitor_id. Severidade qualitativa mantida (P-94).
//   DB-55 tenant_parametros 'resultado.descontos' — descontos parametrizáveis
//         do Resultado Líquido (P-90: começa com depreciação).
// Idempotente. Backup: backup_pre_146_*.sql.
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

$log("== migration 146 — fundação R1 da reunião (A0-19) ==");

$log("[DB-51] mip_alvo_produtos");
if (!$tableExists('mip_alvo_produtos')) {
    $pdo->exec("CREATE TABLE mip_alvo_produtos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        alvo_id BIGINT UNSIGNED NOT NULL,
        produto_id BIGINT UNSIGNED NOT NULL,
        dose DECIMAL(18,4) NULL,
        dose_unidade VARCHAR(20) NULL COMMENT 'L\\ha, mL\\100L, kg\\ha... — PHP valida',
        volume_calda_ha DECIMAL(18,2) NULL COMMENT 'L de calda por ha (base do calculo da pulverizacao)',
        observacao VARCHAR(255) NULL,
        cadastrado_por BIGINT UNSIGNED NOT NULL COMMENT 'P-97: trilha do RT que INDICOU — Regra 1: o sistema lista, nunca recomenda',
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_alvo_produto (tenant_id, alvo_id, produto_id),
        KEY idx_map_produto (produto_id),
        CONSTRAINT fk_map_alvo FOREIGN KEY (alvo_id) REFERENCES mip_alvos (id) ON DELETE CASCADE,
        CONSTRAINT fk_map_produto FOREIGN KEY (produto_id) REFERENCES estoque_produtos (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + mip_alvo_produtos");
} else { $log("  = já existe"); }

$log("[DB-52] agro_calc_parametros");
if (!$tableExists('agro_calc_parametros')) {
    $pdo->exec("CREATE TABLE agro_calc_parametros (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        tipo_atividade_id BIGINT UNSIGNED NOT NULL,
        chave VARCHAR(40) NOT NULL COMMENT 'plantas_por_diaria|ha_por_diaria|custo_diaria_ref|... — PHP valida',
        valor DECIMAL(18,4) NOT NULL,
        vigencia_inicio DATE NOT NULL,
        vigencia_fim DATE NULL,
        observacao VARCHAR(255) NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        KEY idx_calc_tipo (tenant_id, tipo_atividade_id, chave),
        CONSTRAINT fk_calc_tipo FOREIGN KEY (tipo_atividade_id) REFERENCES agro_tipos_atividade (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + agro_calc_parametros (valores: documento P-91 do cliente)");
} else { $log("  = já existe"); }

$log("[DB-53] irrigação por fase fenológica");
if (!$tableExists('irrigacao_fase_parametros')) {
    $pdo->exec("CREATE TABLE irrigacao_fase_parametros (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        cultura_id BIGINT UNSIGNED NOT NULL,
        estagio_id BIGINT UNSIGNED NOT NULL,
        tipo_irrigacao VARCHAR(20) NOT NULL DEFAULT 'gotejo' COMMENT 'gotejo|microaspersao|pivo|outro — PHP valida',
        volume_ideal_m3_ha DECIMAL(18,2) NULL,
        tempo_ideal_h DECIMAL(8,2) NULL,
        observacao VARCHAR(255) NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        $AUDIT,
        PRIMARY KEY (id),
        UNIQUE KEY uq_irr_fase (tenant_id, cultura_id, estagio_id, tipo_irrigacao),
        CONSTRAINT fk_irrf_cultura FOREIGN KEY (cultura_id) REFERENCES agro_culturas (id),
        CONSTRAINT fk_irrf_estagio FOREIGN KEY (estagio_id) REFERENCES agro_fenologia_estagios (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("  + irrigacao_fase_parametros (sugestão no apontamento — nunca trava)");
} else { $log("  = já existe"); }
if (!$columnExists('agro_setores', 'tipo_irrigacao')) {
    $pdo->exec("ALTER TABLE agro_setores ADD COLUMN tipo_irrigacao VARCHAR(20) NULL COMMENT 'gotejo|microaspersao|pivo|outro — PHP valida (reuniao 06\\07)'");
    $log("  + agro_setores.tipo_irrigacao");
} else { $log("  = tipo_irrigacao já existe"); }

$log("[DB-54] monitoramento v2");
foreach ([
    'quantidade_encontrada' => "DECIMAL(18,4) NULL COMMENT 'contagem bruta do monitor — o INDICE e calculado dela (A1-47)'",
    'status' => "VARCHAR(10) NOT NULL DEFAULT 'enviado' COMMENT 'rascunho|enviado — fluxo enviar-p\\-lider; legados = enviado'",
    'monitor_id' => "BIGINT UNSIGNED NULL COMMENT 'quem monitorou (operador\\usuario)'",
] as $col => $def) {
    if (!$columnExists('mip_monitoramentos', $col)) {
        $pdo->exec("ALTER TABLE mip_monitoramentos ADD COLUMN $col $def");
        $log("  + mip_monitoramentos.$col");
    } else { $log("  = $col já existe"); }
}

$log("[DB-55] descontos do Resultado Líquido (tenant_parametros)");
$tenants = $pdo->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tenants as $tid) {
    $tem = $pdo->prepare("SELECT id FROM tenant_parametros WHERE tenant_id = ? AND chave = 'resultado.descontos'");
    $tem->execute([$tid]);
    if (!$tem->fetchColumn()) {
        $pdo->prepare("INSERT INTO tenant_parametros (tenant_id, chave, valor, descricao) VALUES (?, 'resultado.descontos', ?, ?)")
            ->execute([$tid,
                json_encode([['rotulo' => 'Depreciação', 'origem' => 'custeio_categoria', 'ref' => 'depreciacao']], JSON_UNESCAPED_UNICODE),
                'P-90: itens que saem do Resultado Bruto p/ formar o Liquido (lista configuravel — A3-T30)']);
        $log("  + tenant $tid: resultado.descontos (Depreciação — P-90)");
    } else { $log("  = tenant $tid: já parametrizado"); }
}

$log("== migration 146 concluída ==");
