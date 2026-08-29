<?php
declare(strict_types=1);
/* ============================================================================
   VERO — scripts/uni_escola_seed.php
   Semeadura da FAZENDA ESCOLA (Universidade VERO): um tenant de DEMONSTRAÇÃO
   ISOLADO, com dados coerentes de uma fazenda de UVA para treinamento e
   gravação de vídeo. Roda no banco do SISTEMA VERO.

   GUARDA-CORPOS (banco DBaaS remoto e COMPARTILHADO de homologação):
     - O script SÓ INSERE linhas de um tenant NOVO ("Fazenda Escola").
     - NUNCA faz UPDATE/DELETE/TRUNCATE em dados de outros tenants. Todo write
       passa por vero_insert() (que sela tenant_id = sessão) ou por INSERT com
       tenant_id explícito = tenant novo; UPDATEs só atingem linhas do tenant novo.
     - Idempotente: se a "Fazenda Escola" já existir, reaproveita o tenant e só
       insere o que faltar (lookup por chave lógica antes de cada INSERT).
     - Tudo dentro de UMA transação; qualquer erro faz rollback.

   ISOLAMENTO: sem fiscal_config, sem e-mail/integração. Não há coluna/flag de
   "tipo/demo" na tabela tenants (só nome/ativo + cadastro fiscal), então o
   ambiente é marcado por tenant_parametros (ambiente=demonstracao,
   uni.fazenda_escola=1) e pelo próprio nome do tenant.

   Rodar:  php scripts/uni_escola_seed.php
   ============================================================================ */

require_once __DIR__ . '/../includes/db.php';         // Database + bootstrap (h(), etc.)
require_once __DIR__ . '/../includes/vero_crud.php';   // vero_insert/update/row/rows/val (usa $_SESSION)
require_once __DIR__ . '/../includes/vero_services.php'; // vero_srv_fin_lancar (razão hash-chain)

$pdo = Database::getConnection();

/* ── helpers de leitura idempotente ─────────────────────────────────────── */
$firstId = static function (string $sql, array $p) use ($pdo): ?int {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $v = $st->fetchColumn();
    return $v === false ? null : (int)$v;
};
$counts = [];
$bump = static function (string $k, bool $created) use (&$counts): void {
    $counts[$k] ??= ['criados' => 0, 'existentes' => 0];
    $counts[$k][$created ? 'criados' : 'existentes']++;
};

$TNOME = 'Fazenda Escola';

echo "== VERO · Seed Fazenda Escola (Universidade) ==\n";

$pdo->beginTransaction();
try {
    /* ── 1) TENANT (raw: tabela tenants não tem tenant_id/created_by) ─────── */
    $tid = $firstId("SELECT id FROM tenants WHERE nome = ? LIMIT 1", [$TNOME]);
    $tenantCriado = false;
    if ($tid === null) {
        $st = $pdo->prepare(
            "INSERT INTO tenants (nome, ativo, razao_social, municipio, uf)
             VALUES (?, 1, ?, ?, ?)");
        $st->execute([$TNOME, 'Fazenda Escola — Universidade VERO (Demonstração)', 'Petrolina', 'PE']);
        $tid = (int)$pdo->lastInsertId();
        $tenantCriado = true;
    }
    /* trava de segurança: jamais operar sobre o tenant real (id 1) */
    if ($tid === 1) {
        throw new RuntimeException('ABORT: resolvi tenant_id=1 (Fazenda Boa Vista) — o seed só pode operar num tenant NOVO.');
    }
    $bump('tenants', $tenantCriado);
    echo "  tenant_id = {$tid} (" . ($tenantCriado ? 'CRIADO' : 'já existia') . ")\n";

    /* Marca de ambiente/isolamento via tenant_parametros (idempotente) */
    foreach ([
        'ambiente'            => 'demonstracao',
        'uni.fazenda_escola'  => '1',
    ] as $chave => $valor) {
        $pid = $firstId("SELECT id FROM tenant_parametros WHERE tenant_id=? AND chave=? LIMIT 1", [$tid, $chave]);
        if ($pid === null) {
            $st = $pdo->prepare(
                "INSERT INTO tenant_parametros (tenant_id, chave, valor, descricao)
                 VALUES (?,?,?,?)");
            $st->execute([$tid, $chave, $valor, 'Fazenda Escola (Universidade) — dados de treinamento']);
            $bump('tenant_parametros', true);
        } else {
            $bump('tenant_parametros', false);
        }
    }

    /* ── Bootstrap do "contexto de sessão" para reutilizar vero_insert() ──── */
    $_SESSION['tenant_id']   = $tid;
    $_SESSION['user_id']     = 0;              // ajustado após criar o usuário demo
    $_SESSION['user_role']   = 'super_admin';  // vero_insert não checa permissão; só evita ruído
    $_SESSION['permissions'] = ['*'];

    /* ── 2) USUÁRIO DEMO (raw: precisa de created_by nulo antes de existir uid) */
    $demoEmail = 'escola@vero.local';
    $demoSenha = getenv('UNI_DEMO_SENHA') ?: bin2hex(random_bytes(8)); // defina UNI_DEMO_SENHA ou use a senha gerada impressa no fim
    $uid = $firstId("SELECT id FROM usuarios WHERE tenant_id=? AND email=? LIMIT 1", [$tid, $demoEmail]);
    $usuarioCriado = false;
    if ($uid === null) {
        $st = $pdo->prepare(
            "INSERT INTO usuarios (tenant_id, nome, email, senha_hash, perfil, ativo)
             VALUES (?,?,?,?,?,1)");
        $st->execute([$tid, 'Instrutor Fazenda Escola', $demoEmail,
            password_hash($demoSenha, PASSWORD_BCRYPT), 'gestor']);
        $uid = (int)$pdo->lastInsertId();
        $usuarioCriado = true;
    }
    $bump('usuarios', $usuarioCriado);
    $_SESSION['user_id'] = $uid;   // a partir daqui vero_insert grava created_by/updated_by reais
    echo "  usuario demo id={$uid} email={$demoEmail} perfil=gestor (" . ($usuarioCriado ? 'CRIADO' : 'já existia') . ")\n";

    /* ── 3) CULTURA Uva ──────────────────────────────────────────────────── */
    $culturaId = $firstId("SELECT id FROM agro_culturas WHERE tenant_id=? AND nome=? LIMIT 1", [$tid, 'Uva']);
    if ($culturaId === null) {
        $culturaId = vero_insert('agro_culturas', [
            'nome' => 'Uva', 'unidade_produtividade' => 'kg_ha',
            'unidade_comercial' => 'cx', 'peso_unidade_kg' => 8.200,
            'ciclo_dias' => 120, 'exige_classificacao' => 1,
        ]);
        $bump('agro_culturas', true);
    } else { $bump('agro_culturas', false); }

    /* ── 4) VARIEDADES de uva de mesa ────────────────────────────────────── */
    $variedadesDef = [
        ['BRS Vitória',       'mesa', 'preta', 1, 115],
        ['Thompson Seedless', 'mesa', 'verde', 1, 120],
        ['Arra 15',           'mesa', 'verde', 1, 108],
        ['Sugar Crisp',       'mesa', 'verde', 1, 110],
        ['Crimson Seedless',  'mesa', 'vermelha', 1, 125],
        ['BRS Núbia',         'mesa', 'preta', 0, 118],
    ];
    $varIds = [];
    foreach ($variedadesDef as [$nome, $uso, $cor, $apir, $ciclo]) {
        $vid = $firstId("SELECT id FROM agro_variedades WHERE tenant_id=? AND cultura_id=? AND nome=? LIMIT 1",
            [$tid, $culturaId, $nome]);
        if ($vid === null) {
            $vid = vero_insert('agro_variedades', [
                'cultura_id' => $culturaId, 'nome' => $nome, 'tipo_uso' => $uso,
                'cor_baga' => $cor, 'apirenica' => $apir, 'ciclo_dias' => $ciclo,
                'produtividade_esperada' => 30.0000, 'unidade_produtividade' => 'kg_ha',
                'ativo' => 1,
            ]);
            $bump('agro_variedades', true);
        } else { $bump('agro_variedades', false); }
        $varIds[] = $vid;
    }

    /* ── 5) FAZENDA ──────────────────────────────────────────────────────── */
    $fazNome = 'Fazenda Escola — Núcleo Videira';
    $fazId = $firstId("SELECT id FROM agro_fazendas WHERE tenant_id=? AND nome=? LIMIT 1", [$tid, $fazNome]);
    if ($fazId === null) {
        $fazId = vero_insert('agro_fazendas', [
            'nome' => $fazNome, 'mip_metodologia' => 'planta',
            'municipio' => 'Petrolina', 'uf' => 'PE', 'area_total_ha' => 30.0000,
            'latitude' => -9.3891000, 'longitude' => -40.5030000,
            'proprietario' => 'Universidade VERO', 'tipo_exploracao' => 'propria',
            'observacao' => 'Fazenda de demonstração para treinamento (Universidade VERO).',
            'ativo' => 1,
        ]);
        $bump('agro_fazendas', true);
    } else { $bump('agro_fazendas', false); }

    /* ── 6) ÁREA (gleba) ─────────────────────────────────────────────────── */
    $areaId = $firstId("SELECT id FROM agro_areas WHERE tenant_id=? AND fazenda_id=? AND nome=? LIMIT 1",
        [$tid, $fazId, 'Núcleo de Videiras']);
    if ($areaId === null) {
        $areaId = vero_insert('agro_areas', [
            'fazenda_id' => $fazId, 'nome' => 'Núcleo de Videiras', 'area_ha' => 27.0000,
        ]);
        $bump('agro_areas', true);
    } else { $bump('agro_areas', false); }

    /* ── 7) ALMOXARIFADO (para saldos de estoque) ────────────────────────── */
    $almoxId = $firstId("SELECT id FROM almoxarifados WHERE tenant_id=? AND nome=? LIMIT 1", [$tid, 'Almoxarifado Central']);
    if ($almoxId === null) {
        $almoxId = vero_insert('almoxarifados', [
            'fazenda_id' => $fazId, 'nome' => 'Almoxarifado Central', 'tipo' => 'insumos', 'ativo' => 1,
        ]);
        $bump('almoxarifados', true);
    } else { $bump('almoxarifados', false); }

    /* ── 8) TALHÕES / VÁLVULAS (com variedade definida) + espelho em setores ─ */
    $talhoesDef = [
        // codigo, area_ha, tipo_solo, estrutura, idx_variedade
        ['V01', 4.0, 'Argiloso',        'latada',     0],
        ['V02', 3.5, 'Arenoso',         'latada',     1],
        ['V03', 5.2, 'Argilo-arenoso',  'latada',     2],
        ['V04', 4.8, 'Arenoso',         'espaldeira', 3],
        ['V05', 3.0, 'Argiloso',        'latada',     4],
        ['V06', 6.5, 'Argilo-arenoso',  'espaldeira', 5],
    ];
    $talhoes = [];
    foreach ($talhoesDef as [$cod, $area, $solo, $estr, $vi]) {
        $espLinha = 3.00; $espPlanta = 2.00;
        $numPlantas = (int)round(($area * 10000) / ($espLinha * $espPlanta));
        $tlId = $firstId("SELECT id FROM agro_talhoes WHERE tenant_id=? AND fazenda_id=? AND codigo=? LIMIT 1",
            [$tid, $fazId, $cod]);
        if ($tlId === null) {
            $tlId = vero_insert('agro_talhoes', [
                'fazenda_id' => $fazId, 'area_id' => $areaId, 'codigo' => $cod,
                'nome' => 'Válvula ' . $cod, 'area_ha' => $area,
                'tipo_solo' => $solo, 'estrutura_sistema' => $estr,
                'espacamento_linha_m' => $espLinha, 'espacamento_planta_m' => $espPlanta,
                'num_plantas' => $numPlantas, 'variedade_id' => $varIds[$vi],
                'data_plantio' => '2022-03-01', 'ativo' => 1,
                'observacao' => 'Válvula de demonstração — variedade ' . $variedadesDef[$vi][0] . '.',
            ]);
            $bump('agro_talhoes', true);
        } else {
            $bump('agro_talhoes', false);
        }
        $talhoes[] = ['id' => $tlId, 'area' => $area, 'cod' => $cod];

        /* espelho 1:1 do talhão em agro_setores (tipo=valvula, is_espelho=1) */
        $setId = $firstId(
            "SELECT id FROM agro_setores WHERE tenant_id=? AND talhao_id=? AND is_espelho=1 LIMIT 1",
            [$tid, $tlId]);
        if ($setId === null) {
            vero_insert('agro_setores', [
                'fazenda_id' => $fazId, 'talhao_id' => $tlId, 'nome' => 'Válvula ' . $cod,
                'codigo' => $cod, 'tipo' => 'valvula', 'area_ha' => $area,
                'is_espelho' => 1, 'tipo_irrigacao' => 'gotejo', 'ativo' => 1,
            ]);
            $bump('agro_setores', true);
        } else { $bump('agro_setores', false); }
    }

    /* ── 9) SAFRA 2027.1 (ativa) + vínculos por válvula com poda finalizada ── */
    $ident = '2027.1-01';
    $dataPoda = '2027-01-20';           // dia 0 (poda) — coerente com abertura de safra
    $safraId = $firstId("SELECT id FROM agro_safras WHERE tenant_id=? AND identificacao=? LIMIT 1", [$tid, $ident]);
    $safraCriada = false;
    if ($safraId === null) {
        $safraId = vero_insert('agro_safras', [
            'identificacao' => $ident, 'fazenda_id' => $fazId,
            'data_inicio' => $dataPoda, 'data_fim_prevista' => null, 'data_fim' => null,
            'status' => 'ativa',
        ]);
        $safraCriada = true;
        $bump('agro_safras', true);
    } else { $bump('agro_safras', false); }

    /* vínculos safra↔válvula (agro_safra_talhoes) — poda já confirmada */
    foreach ($talhoes as $tl) {
        $stId = $firstId("SELECT id FROM agro_safra_talhoes WHERE tenant_id=? AND safra_id=? AND talhao_id=? LIMIT 1",
            [$tid, $safraId, $tl['id']]);
        if ($stId === null) {
            vero_insert('agro_safra_talhoes', [
                'safra_id' => $safraId, 'talhao_id' => $tl['id'], 'cultura_id' => $culturaId,
                'area_plantada_ha' => $tl['area'], 'produtividade_planejada' => 30.0000,
                'unidade_produtividade' => 'kg_ha',
                'data_poda' => $dataPoda, 'poda_status' => 'finalizada',
                'poda_confirmada_em' => date('Y-m-d H:i:s'), 'poda_confirmada_por' => $uid,
            ]);
            $bump('agro_safra_talhoes', true);
        } else { $bump('agro_safra_talhoes', false); }
    }
    /* fim previsto = data_inicio + maior ciclo entre as variedades vinculadas (regra T-04) */
    $maxCiclo = (int)($firstId(
        "SELECT MAX(COALESCE(vr.ciclo_dias, vr.ciclo_poda_colheita_dias))
           FROM agro_safra_talhoes st
           JOIN agro_talhoes tl    ON tl.id = st.talhao_id    AND tl.tenant_id = st.tenant_id
           JOIN agro_variedades vr ON vr.id = tl.variedade_id AND vr.tenant_id = st.tenant_id
          WHERE st.tenant_id=? AND st.safra_id=?", [$tid, $safraId]) ?? 0);
    if ($maxCiclo > 0) {
        $fim = date('Y-m-d', strtotime($dataPoda . ' +' . $maxCiclo . ' days'));
        vero_update('agro_safras', $safraId, ['data_fim_prevista' => $fim]);
    }

    /* ── 10) ESTOQUE: grupos, produtos e saldos ──────────────────────────── */
    $grupoIns = $firstId("SELECT id FROM estoque_grupos WHERE tenant_id=? AND nome=? LIMIT 1", [$tid, 'Insumos Agrícolas']);
    if ($grupoIns === null) {
        $grupoIns = vero_insert('estoque_grupos', ['nome' => 'Insumos Agrícolas', 'tipo' => 'insumo', 'ativo' => 1]);
        $bump('estoque_grupos', true);
    } else { $bump('estoque_grupos', false); }
    $grupoDef = $firstId("SELECT id FROM estoque_grupos WHERE tenant_id=? AND nome=? LIMIT 1", [$tid, 'Defensivos']);
    if ($grupoDef === null) {
        $grupoDef = vero_insert('estoque_grupos', ['nome' => 'Defensivos', 'tipo' => 'insumo', 'ativo' => 1]);
        $bump('estoque_grupos', true);
    } else { $bump('estoque_grupos', false); }

    $produtosDef = [
        // codigo, nome, unidade, grupo, tipo_insumo, ing_ativo, qtd_saldo, custo_unit
        ['100001', 'Ureia 45% N',              'kg', $grupoIns, 'fertilizante', null,                 2000, 3.20],
        ['100002', 'MAP (Fosfato Monoamônico)', 'kg', $grupoIns, 'fertilizante', null,                 1200, 4.80],
        ['100003', 'Cloreto de Potássio 60%',  'kg', $grupoIns, 'fertilizante', null,                 1500, 3.90],
        ['100004', 'Mancozeb 800 WP',          'kg', $grupoDef, 'defensivo',    'Mancozebe',            120, 28.50],
        ['100005', 'Enxofre 800 WG',           'kg', $grupoDef, 'defensivo',    'Enxofre',              300, 12.00],
        ['100006', 'Óleo Mineral Adjuvante',   'L',  $grupoDef, 'defensivo',    'Óleo mineral',         180, 15.40],
    ];
    foreach ($produtosDef as [$cod, $nome, $un, $grp, $tipoIns, $ia, $qtd, $custo]) {
        $prodId = $firstId("SELECT id FROM estoque_produtos WHERE tenant_id=? AND codigo=? LIMIT 1", [$tid, $cod]);
        if ($prodId === null) {
            $prodId = vero_insert('estoque_produtos', [
                'grupo_id' => $grp, 'codigo' => $cod, 'nome' => $nome,
                'ingrediente_ativo' => $ia, 'unidade' => $un, 'tipo_insumo' => $tipoIns,
                'estoque_minimo' => 0, 'estoque_maximo' => 0, 'ativo' => 1,
            ]);
            $bump('estoque_produtos', true);
        } else { $bump('estoque_produtos', false); }

        /* saldo inicial (raw: estoque_saldos não tem created_by/updated_by) */
        $saldoId = $firstId("SELECT id FROM estoque_saldos WHERE tenant_id=? AND produto_id=? AND almoxarifado_id=? LIMIT 1",
            [$tid, $prodId, $almoxId]);
        if ($saldoId === null) {
            $valorTotal = round($qtd * $custo, 2);
            $st = $pdo->prepare(
                "INSERT INTO estoque_saldos (tenant_id, produto_id, almoxarifado_id, quantidade, custo_medio, valor_total)
                 VALUES (?,?,?,?,?,?)");
            $st->execute([$tid, $prodId, $almoxId, $qtd, $custo, $valorTotal]);
            $bump('estoque_saldos', true);
        } else { $bump('estoque_saldos', false); }
    }

    /* ── 11) COLABORADORES (agro_operadores) ─────────────────────────────── */
    $colabDef = [
        // nome, funcao, vinculo, salario, custo_hora
        ['João da Silva',        'Encarregado de Campo',   'clt',      2800.00, null],
        ['Maria Souza',          'Auxiliar Agrícola',      'clt',      1600.00, null],
        ['Pedro Santos',         'Tratorista',             'clt',      2200.00, null],
        ['Ana Oliveira',         'Monitor de Pragas (MIP)','clt',      1900.00, null],
        ['Carlos Lima',          'Irrigador',              'clt',      1800.00, null],
        ['José Pereira',         'Podador',                'diarista', null,    13.50],
        ['Antônio Costa',        'Diarista de Colheita',   'diarista', null,    12.00],
        ['Francisca Alves',      'Auxiliar de Colheita',   'diarista', null,    12.00],
    ];
    foreach ($colabDef as [$nome, $funcao, $vinc, $sal, $ch]) {
        $cId = $firstId("SELECT id FROM agro_operadores WHERE tenant_id=? AND nome=? LIMIT 1", [$tid, $nome]);
        if ($cId === null) {
            $custoHora = $ch ?? ($sal !== null ? round($sal / 176.0, 6) : 0);
            vero_insert('agro_operadores', [
                'nome' => $nome, 'funcao' => $funcao, 'tipo_vinculo' => $vinc,
                'salario_mensal' => $sal, 'custo_hora' => $custoHora,
                'data_admissao' => '2024-06-01', 'ativo' => 1,
            ]);
            $bump('agro_operadores', true);
        } else { $bump('agro_operadores', false); }
    }

    /* ── 12) FINANCEIRO: centros de custo + títulos em aberto ────────────── */
    $ccDef = [['CC-VID', 'Videira'], ['CC-ADM', 'Administrativo']];
    $ccIds = [];
    foreach ($ccDef as [$cod, $nome]) {
        $ccId = $firstId("SELECT id FROM centros_custo WHERE tenant_id=? AND codigo=? LIMIT 1", [$tid, $cod]);
        if ($ccId === null) {
            $ccId = vero_insert('centros_custo', ['codigo' => $cod, 'nome' => $nome, 'ativo' => 1]);
            $bump('centros_custo', true);
        } else { $bump('centros_custo', false); }
        $ccIds[$cod] = $ccId;
    }

    /* Títulos manuais em aberto. Idempotência por 'documento' (verificação
       prévia); lançados pelo razão hash-chain (vero_srv_fin_lancar) para
       manter a cadeia do tenant íntegra desde o início. origem NULL = manual
       (editável na tela). */
    $titulosDef = [
        // tipo, documento, descricao, valor, competencia, vencimento, forma, cc
        ['pagar',   'ESC-P-001', 'Compra de fertilizantes (Ureia + MAP)',            8500.00, '2026-07-20', '2026-08-15', 'boleto',        'CC-VID'],
        ['pagar',   'ESC-P-002', 'Energia elétrica — bombeamento de irrigação',      3200.00, '2026-07-25', '2026-08-05', 'pix',           'CC-VID'],
        ['pagar',   'ESC-P-003', 'Diaristas — colheita (adiantamento)',              6400.00, '2026-07-26', '2026-08-30', 'dinheiro',      'CC-ADM'],
        ['receber', 'ESC-R-001', 'Venda de uva de mesa — Lote A (BRS Vitória)',     24000.00, '2026-07-15', '2026-09-10', 'pix',           'CC-VID'],
        ['receber', 'ESC-R-002', 'Venda de uva de mesa — Lote B (Thompson)',        18500.00, '2026-07-18', '2026-09-25', 'boleto',        'CC-VID'],
        ['receber', 'ESC-R-003', 'Adiantamento de cliente — contrato exportação',   12000.00, '2026-07-22', '2026-08-20', 'transferencia', 'CC-ADM'],
    ];
    foreach ($titulosDef as [$tipo, $doc, $desc, $val, $comp, $venc, $forma, $cc]) {
        $existe = $firstId("SELECT id FROM movimentacoes_financeiras WHERE tenant_id=? AND documento=? LIMIT 1", [$tid, $doc]);
        if ($existe === null) {
            vero_srv_fin_lancar([
                'tipo' => $tipo, 'descricao' => $desc, 'valor' => $val,
                'data_competencia' => $comp, 'data_vencimento' => $venc,
                'documento' => $doc, 'forma_pagamento' => $forma,
                'centro_custo_id' => $ccIds[$cc], 'safra_id' => $safraId,
                'status' => 'aberto',
            ]);
            $bump('movimentacoes_financeiras', true);
        } else { $bump('movimentacoes_financeiras', false); }
    }

    $pdo->commit();
    echo "\n== Resumo (tenant_id={$tid}) ==\n";
    ksort($counts);
    foreach ($counts as $tab => $c) {
        printf("  %-28s criados=%-3d existentes=%-3d\n", $tab, $c['criados'], $c['existentes']);
    }
    echo "\nUsuário demo: {$demoEmail} / senha: {$demoSenha} (perfil gestor)\n";
    echo "OK — seed concluído.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERRO (rollback): " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
