<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/00_massa_canonica.php  (A5-QA)
   Cria os TENANTS DE TESTE ('QA BATERIA — NÃO USAR' + auxiliar)
   e a massa canônica do GABARITO.md. Idempotente: se o tenant já
   existe, a massa anterior é removida e recriada do zero (a
   limpeza é 100% escopada aos tenants QA — ver _lib.php).
   Fluxos de negócio (compra, apontamento, colheita, venda…)
   rodam no 20_fluxos.php; aqui vive só o CADASTRO + estoque
   inicial. Uso: php 00_massa_canonica.php
   ============================================================ */

require __DIR__ . '/_lib.php';
$env = qa_env();
$pdo = qa_pdo();

qa_section('Tenants e usuários');

/* recria do zero (idempotência da massa) — sweep por NOME pega duplicatas
   de execuções interrompidas, não só o id do lookup único. */
$idsPrev = qa_tenant_ids_all();
if ($idsPrev) {
    $r = qa_limpar_tenants($idsPrev);
    echo "(massa anterior removida: {$r['linhas']} linhas em " . count($idsPrev) . " tenant(s))\n";
}

$pdo->prepare("INSERT INTO tenants (nome, ativo) VALUES (?,1)")->execute([$env['tenant_nome']]);
$T = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO tenants (nome, ativo) VALUES (?,1)")->execute([$env['tenant2_nome']]);
$T2 = (int)$pdo->lastInsertId();
qa_check('tenants QA criados', $T > 0 && $T2 > 0, ['T' => $T, 'T2' => $T2]);

/* usuários (bcrypt como configuracoes/usuarios.php) */
$hash = password_hash($env['senha'], PASSWORD_BCRYPT, ['cost' => 10]);
$insU = $pdo->prepare("INSERT INTO usuarios (tenant_id, nome, email, senha_hash, perfil, ativo) VALUES (?,?,?,?,?,1)");
foreach ($env['usuarios'] as $u) $insU->execute([$T, $u['nome'], $u['email'], $hash, $u['perfil']]);
$insU->execute([$T2, $env['usuario_tenant2']['nome'], $env['usuario_tenant2']['email'], $hash, $env['usuario_tenant2']['perfil']]);
$uidSuper = (int)qa_val("SELECT id FROM usuarios WHERE tenant_id=? AND email=?", [$T, $env['usuarios']['super']['email']]);
qa_check('5 usuários QA + 1 no tenant 2', (int)qa_val("SELECT COUNT(*) FROM usuarios WHERE tenant_id IN (?,?)", [$T, $T2]) === 6);

/* ── Roles do tenant QA (matriz = scripts/seed_perfis_padrao.php) ── */
qa_section('Perfis e permissões (matriz P-05)');
$slugs = [];
foreach ($pdo->query("SELECT id, slug FROM permissions")->fetchAll() as $p) $slugs[(string)$p['slug']] = (int)$p['id'];
qa_check('catálogo permissions populado (≥400 slugs)', count($slugs) >= 400, count($slugs));

$mod  = static fn(string $s): string => explode('.', $s)[0];
$acao = static fn(string $s): string => substr($s, (int)strrpos($s, '.') + 1);
$PROD = ['agro', 'nutricao', 'mip', 'irrigacao'];
$OPER = array_merge($PROD, ['estoque', 'compras', 'maquinas', 'pessoas', 'custeio', 'comercial']);
$CONF_RESTRITO = ['usuarios', 'perfis_acesso', 'permissoes'];

$regras = [
    'gestor' => ['Gestor', static function ($s) use ($mod, $acao, $OPER, $CONF_RESTRITO) {
        $m = $mod($s); $a = $acao($s);
        if ($m === 'configuracoes') { $micro = explode('.', $s)[1] ?? '';
            return !in_array($micro, $CONF_RESTRITO, true) && $a === 'ver'; }
        if ($a === 'ver') return true;
        return in_array($m, $OPER, true);
    }],
    'financeiro' => ['Financeiro', static function ($s) use ($mod, $acao) {
        $m = $mod($s); $a = $acao($s);
        if (in_array($m, ['financeiro', 'custeio', 'fiscal'], true)) return true;
        if (in_array($m, ['compras', 'comercial', 'relatorios', 'patrimonio'], true)) return $a === 'ver';
        if ($m === 'dashboard') return $a === 'ver';
        return false;
    }],
    'operador' => ['Operador de Campo', static function ($s) use ($mod, $acao, $PROD) {
        $m = $mod($s); $a = $acao($s);
        if ($s === 'dashboard.ver' || str_starts_with($s, 'dashboard.visao_geral.')) return $a === 'ver';
        if (in_array($m, $PROD, true)) {
            if ($a === 'ver') return true;
            foreach (['apontamentos', 'monitoramento', 'colheita', 'clima'] as $mic)
                if (str_contains($s, '.' . $mic)) return $a === 'editar';
            return false;
        }
        if ($m === 'estoque') return $a === 'ver';
        return false;
    }],
    'consulta' => ['Consulta', static function ($s) use ($mod, $acao) {
        $m = $mod($s); $a = $acao($s);
        if ($a !== 'ver') return false;
        return !in_array($m, ['financeiro', 'fiscal', 'configuracoes'], true);
    }],
];
$insRP = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)");
foreach ($regras as $slug => [$nome, $pred]) {
    $pdo->prepare("INSERT INTO roles (tenant_id, slug, nome, descricao, ativo) VALUES (?,?,?,?,1)")
        ->execute([$T, $slug, $nome, 'Perfil da bateria QA', ]);
    $roleId = (int)$pdo->lastInsertId();
    $n = 0;
    foreach ($slugs as $s => $pid) if ($pred((string)$s)) { $insRP->execute([$roleId, $pid]); $n++; }
    qa_check("role {$slug} com permissões", $n > 10, $n);
}

/* ── Bootstrap do app na sessão do tenant QA ── */
qa_boot_app($T, $uidSuper);

qa_section('Parâmetros do tenant');
vero_srv_param_set('agro.valvula_igual_talhao', '1', 'QA: modo unificado válvula=talhão');
vero_srv_param_set('compras.alcada_valor', '1000', 'QA: alçada de aprovação');
qa_eq('param válvula unificada', '1', vero_srv_param('agro.valvula_igual_talhao'));
qa_eq('param alçada', '1000', vero_srv_param('compras.alcada_valor'));

qa_section('Estrutura física (fazenda, válvulas)');
$fazId = vero_insert('agro_fazendas', ['nome' => 'QA Fazenda Bateria', 'ativo' => 1]);
$t1 = vero_insert('agro_talhoes', ['fazenda_id' => $fazId, 'codigo' => 'QA-1A', 'nome' => 'QA Válvula 1A',
    'area_ha' => 4.00, 'num_plantas' => 1000, 'ativo' => 1]);
$t2 = vero_insert('agro_talhoes', ['fazenda_id' => $fazId, 'codigo' => 'QA-2B', 'nome' => 'QA Válvula 2B',
    'area_ha' => 2.50, 'ativo' => 1]);
foreach ([$t1, $t2] as $tid) {
    $talhao = qa_row("SELECT * FROM agro_talhoes WHERE id=?", [$tid]);
    vero_a1_sync_espelho($talhao);           /* espelho 1:1 em agro_setores (modo unificado) */
}
$set1 = (int)qa_val("SELECT id FROM agro_setores WHERE tenant_id=? AND talhao_id=? AND is_espelho=1", [$T, $t1]);
$set2 = (int)qa_val("SELECT id FROM agro_setores WHERE tenant_id=? AND talhao_id=? AND is_espelho=1", [$T, $t2]);
qa_check('fazenda + 2 válvulas + espelhos', $fazId && $t1 && $t2 && $set1 && $set2,
    ['faz' => $fazId, 't1' => $t1, 't2' => $t2, 'set1' => $set1, 'set2' => $set2]);

qa_section('Estoque base (grupo, almox, produtos)');
$almoxId = vero_srv_almox_padrao();
$grupoId = vero_srv_grupo_estoque_padrao();
$pFert = vero_insert('estoque_produtos', ['grupo_id' => $grupoId, 'codigo' => '990001', 'nome' => 'QA-FERT Fertilizante',
    'tipo_insumo' => 'fertilizante', 'unidade' => 'kg', 'controla_validade' => 1, 'controla_lote' => 1, 'ativo' => 1]);
$pDef = vero_insert('estoque_produtos', ['grupo_id' => $grupoId, 'codigo' => '990002', 'nome' => 'QA-DEF Defensivo',
    'tipo_insumo' => 'defensivo', 'unidade' => 'L', 'controla_validade' => 1, 'controla_lote' => 1,
    'carencia_dias' => 7, 'ativo' => 1]);
$pUva = vero_insert('estoque_produtos', ['grupo_id' => $grupoId, 'codigo' => '990003', 'nome' => 'QA-UVA Produção',
    'tipo_insumo' => 'outro', 'unidade' => 'kg', 'controla_validade' => 0, 'controla_lote' => 1, 'ativo' => 1]);
qa_check('3 produtos QA', $pFert && $pDef && $pUva);

qa_section('Cultura, variedade e fenologia por variedade');
$cultId = vero_insert('agro_culturas', ['nome' => 'QA Uva', 'unidade_produtividade' => 'kg_ha',
    'produto_estoque_colheita_id' => $pUva, 'almoxarifado_colheita_id' => $almoxId,
    'exige_classificacao' => 1, 'ativo' => 1]);
$varId = vero_insert('agro_variedades', ['cultura_id' => $cultId, 'nome' => 'QA Vitória',
    'tipo_uso' => 'mesa', 'ativo' => 1]);
vero_update('agro_talhoes', $t1, ['variedade_id' => $varId]);
vero_update('agro_talhoes', $t2, ['variedade_id' => $varId]);

$fenId = vero_insert('agro_variedade_fenologia', ['variedade_id' => $varId, 'versao' => 1,
    'status' => 'aprovada', 'aprovado_por' => $uidSuper, 'aprovado_em' => date('Y-m-d H:i:s'), 'ativo' => 1]);
$temCalda = (bool)qa_val("SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name='agro_variedade_fases' AND column_name='volume_calda_ha_l'");
$fases = [[1, 'Brotação', 0, 45, 300], [2, 'Floração', 46, 90, 500], [3, 'Maturação', 91, 200, 400]];
foreach ($fases as [$ord, $nome, $ini, $fim, $calda]) {
    $d = ['fenologia_id' => $fenId, 'ordem' => $ord, 'nome' => $nome,
        'dia_inicio' => $ini, 'dia_fim' => $fim, 'ativo' => 1];
    if ($temCalda) $d['volume_calda_ha_l'] = $calda;
    vero_insert('agro_variedade_fases', $d);
}
qa_check('fenologia aprovada com 3 fases (dia0=poda)', (int)qa_val(
    "SELECT COUNT(*) FROM agro_variedade_fases WHERE tenant_id=? AND fenologia_id=?", [$T, $fenId]) === 3);

qa_section('Safra + vínculos + poda');
$D = $env['datas'];
$safraId = vero_insert('agro_safras', ['identificacao' => 'QA 2026/2', 'fazenda_id' => $fazId,
    'data_inicio' => $D['poda'], 'status' => 'ativa']);
$st1 = vero_insert('agro_safra_talhoes', ['safra_id' => $safraId, 'talhao_id' => $t1, 'cultura_id' => $cultId,
    'area_plantada_ha' => 4.00, 'produtividade_planejada' => 25000, 'unidade_produtividade' => 'kg_ha',
    'data_poda' => $D['poda'], 'poda_status' => 'finalizada',
    'poda_confirmada_em' => $D['poda'] . ' 08:00:00', 'poda_confirmada_por' => $uidSuper]);
$st2 = vero_insert('agro_safra_talhoes', ['safra_id' => $safraId, 'talhao_id' => $t2, 'cultura_id' => $cultId,
    'area_plantada_ha' => 2.50, 'data_poda' => $D['poda'], 'poda_status' => 'finalizada',
    'poda_confirmada_em' => $D['poda'] . ' 08:00:00', 'poda_confirmada_por' => $uidSuper]);
qa_check('safra ativa + 2 vínculos com poda', $safraId && $st1 && $st2);

qa_section('Pessoas e RH');
$clt1 = vero_insert('agro_operadores', ['nome' => 'QA Colaborador CLT', 'funcao' => 'Campo',
    'tipo_vinculo' => 'clt', 'salario_mensal' => 1664.00, 'dependentes' => 0, 'ativo' => 1]);
$clt2 = vero_insert('agro_operadores', ['nome' => 'QA Colaborador Teto', 'funcao' => 'Gerência',
    'tipo_vinculo' => 'clt', 'salario_mensal' => 9000.00, 'dependentes' => 0, 'ativo' => 1]);
$terc = vero_insert('rh_terceirizados', ['nome' => 'QA Produção', 'modalidade_padrao' => 'producao', 'ativo' => 1]);
$diar = vero_insert('rh_terceirizados', ['nome' => 'QA Diarista', 'modalidade_padrao' => 'diaria',
    'valor_diaria' => 90.00, 'ativo' => 1]);
vero_insert('rh_encargos_config', ['vigencia_inicio' => $D['vig_encargos'],
    'fgts_pct' => 8.000, 'inss_patronal_pct' => 20.000, 'rat_pct' => 2.000, 'terceiros_pct' => 5.800,
    'ferias_pct' => 11.110, 'decimo_pct' => 8.330, 'outros_pct' => 0.000, 'ativo' => 1]);
qa_check('2 CLT + 2 terceirizados + encargos', $clt1 && $clt2 && $terc && $diar);

/* aceite do legado — encargos 1.664,00 → 919,19 (função pura, direto no gabarito) */
$cfg = vero_srv_encargos_vigente($D['competencia']);
$enc = vero_srv_encargos_calc(1664.00, $cfg);
qa_eqf('encargos_calc(1664) total = 919,19', 919.19, $enc['total']);
qa_eqf('encargos_calc(1664) custo_total = 2.583,19', 2583.19, $enc['custo_total']);

qa_section('Atividade e regra de premiação');
$tipoAtvId = vero_insert('agro_tipos_atividade', ['nome' => 'QA Poda', 'categoria' => 'trato_cultural',
    'unidade_padrao' => 'caixa', 'exige_producao' => 1, 'ativo' => 1]);
$pdo->prepare("INSERT INTO agro_tipo_atividade_culturas (tenant_id, tipo_atividade_id, cultura_id) VALUES (?,?,?)")
    ->execute([$T, $tipoAtvId, $cultId]);
$regraId = vero_insert('rh_regras_premiacao', ['tipo_atividade_id' => $tipoAtvId, 'cultura_id' => $cultId,
    'unidade' => 'caixa', 'vigencia_inicio' => $D['vig_encargos'], 'vigencia_fim' => null, 'ativo' => 1]);
qa_check('tipo QA Poda + regra de premiação vigente', $tipoAtvId && $regraId);

/* gabarito puro da premiação e das diárias */
$c = vero_srv_premiacao_calc(130, 100, 1.20);
qa_eqf('premiacao_calc(130,100,1.20) = 36,00', 36.00, $c['valor_total']);
$dd = vero_srv_diarias_necessarias(1905, 1, 100);
qa_eq('diárias ceil(1905/1/100) = 20', 20, $dd['diarias_total']);

qa_section('Estoque inicial (lotes p/ FEFO)');
$pdo->beginTransaction();
vero_srv_estoque_entrada($pFert, $almoxId, 90, 3.50, $D['estoque_ini'], 'manual', null,
    'QA estoque inicial', '2026-12-31', null);
vero_srv_estoque_entrada($pDef, $almoxId, 10, 12.00, $D['estoque_ini'], 'manual', null,
    'QA estoque inicial', '2027-03-31', null);
$pdo->commit();
$s = qa_row("SELECT quantidade, custo_medio, valor_total FROM estoque_saldos WHERE tenant_id=? AND produto_id=?", [$T, $pFert]);
qa_eqf('QA-FERT saldo inicial 90 kg', 90.0, $s['quantidade']);
qa_eqf('QA-FERT CM inicial 3,50', 3.50, $s['custo_medio'], 0.0001);
qa_eqf('QA-FERT valor inicial 315,00', 315.00, $s['valor_total']);

qa_section('MIP, nutrição, comercial, máquinas, patrimônio');
$alvo1 = vero_insert('mip_alvos', ['nome' => 'QA Traça', 'tipo' => 'praga', 'cultura_id' => $cultId,
    'nivel_acao' => 5.00, 'ativo' => 1]);
$alvo2 = vero_insert('mip_alvos', ['nome' => 'QA Míldio', 'tipo' => 'doenca', 'cultura_id' => $cultId,
    'nivel_acao' => 5.00, 'ativo' => 1]);
$nutN = vero_insert('analise_nutrientes', ['nome' => 'QA Nitrogênio', 'simbolo' => 'N',
    'aplicacao' => 'ambos', 'unidade_padrao' => 'g/kg', 'ordem' => 1, 'ativo' => 1]);
$nutK = vero_insert('analise_nutrientes', ['nome' => 'QA Potássio', 'simbolo' => 'K',
    'aplicacao' => 'ambos', 'unidade_padrao' => 'g/kg', 'ordem' => 2, 'ativo' => 1]);
foreach ([$nutN, $nutK] as $nid) {
    vero_insert('analise_faixas', ['tipo' => 'foliar', 'nutriente_id' => $nid, 'unidade' => 'g/kg',
        'variedade_id' => $varId, 'minimo' => 2.0, 'ideal_min' => 2.5, 'ideal_max' => 3.5, 'maximo' => 4.0, 'ativo' => 1]);
}
$compradorId = vero_insert('comercial_compradores', ['razao_social' => 'QA Comprador LTDA', 'ativo' => 1]);
$maq1 = vero_insert('maquinas', ['codigo' => 'QA-TR1', 'nome' => 'QA Trator', 'tipo' => 'trator', 'status' => 'ativa', 'ativo' => 1]);
$maq2 = vero_insert('maquinas', ['codigo' => 'QA-PV1', 'nome' => 'QA Pulverizador', 'tipo' => 'pulverizador', 'status' => 'ativa', 'ativo' => 1]);
$catPat = vero_insert('patrimonio_categorias', ['nome' => 'QA Equipamentos', 'vida_util_meses' => 120,
    'taxa_depreciacao' => 10.0, 'ativo' => 1]);
$ativoId = vero_insert('patrimonio_ativos', ['categoria_id' => $catPat, 'descricao' => 'QA Pulverizador Patrimônio',
    'valor_aquisicao' => 250000.00, 'data_aquisicao' => $D['aquisicao'], 'vida_util_meses' => 120,
    'valor_residual' => 50000.00, 'ativo' => 1]);
qa_check('alvos MIP + nutrientes/faixas + comprador + 2 máquinas + ativo',
    $alvo1 && $alvo2 && $nutN && $nutK && $compradorId && $maq1 && $maq2 && $ativoId);

qa_section('Sanidade final da massa');
qa_eq('cross-tenant: tenant 2 sem fazendas', 0,
    (int)qa_val("SELECT COUNT(*) FROM agro_fazendas WHERE tenant_id=?", [$T2]));
qa_check('nada criado fora dos tenants QA (fazenda QA única)', (int)qa_val(
    "SELECT COUNT(*) FROM agro_fazendas WHERE nome='QA Fazenda Bateria' AND tenant_id NOT IN (?,?)", [$T, $T2]) === 0);

qa_finish('00_massa_canonica');
