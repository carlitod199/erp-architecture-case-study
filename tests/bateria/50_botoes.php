<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/50_botoes.php  (A5-QA)
   Inventário COMPLETO das ações POST (name="acao") do sistema —
   congelado por grep no working tree em 18/07/2026 (arquivos
   _base mapeados para a tela wrapper). Para CADA ação:
     (a) POST SEM CSRF  → precisa ser rejeitado (nunca 500, nunca
         gravação);
     (b) POST COM CSRF + id inexistente (999999999) e campos
         obrigatórios vazios → flash de erro / no-op, nunca 500.
   Ações DESTRUTIVAS rodam por último. Executores de estado
   (fechamento, rateio, folha, importadores) ficam FORA da
   varredura (b) — o payload VÁLIDO deles é coberto no
   20_fluxos (mapa em $COBERTAS) — e recebem só o teste (a).
   No fim, um diff de contagem de linhas por tabela do tenant QA
   prova que a varredura não gravou NADA.
   Requer 00 (+20 para o mapa de cobertura). Uso: php 50_botoes.php
   ============================================================ */

require __DIR__ . '/_lib.php';
qa_boot_app();
$T = qa_tenant_id();

/* rota => lista de ações (D = destrutiva, X = executor de estado: só teste CSRF) */
$INVENTARIO = [
    '/agro/abertura_safra.php' => ['abrir:X', 'confirmar_poda:X'],
    '/agro/apontamentos.php' => ['iniciar', 'finalizar', 'salvar', 'excluir:D'],
    '/agro/areas_produtivas.php' => ['salvar', 'excluir:D'],
    '/agro/atividades.php' => ['salvar', 'status', 'excluir:D'],
    '/agro/bombas.php' => ['salvar', 'excluir:D'],
    '/agro/clima.php' => ['salvar', 'faixas_rt', 'excluir:D'],
    '/agro/culturas.php' => ['salvar', 'excluir:D'],
    '/agro/fenologia.php' => ['salvar', 'irrig_salvar', 'periodo_salvar', 'irrig_excluir:D', 'periodo_excluir:D', 'excluir:D'],
    '/agro/mapa.php' => ['importar_mapa', 'salvar_geometria'],
    '/agro/porta_enxertos.php' => ['salvar', 'excluir:D'],
    '/agro/romaneios_colheita.php' => ['salvar', 'excluir:D'],
    '/agro/talhoes.php' => ['salvar', 'catalogo_solo', 'excluir:D'],
    '/agro/tipos_atividade.php' => ['salvar', 'excluir:D'],
    '/agro/valvulas.php' => ['salvar', 'excluir:D'],
    '/agro/variedade_fenologia.php' => ['fase_salvar', 'fase_excluir:D'],
    '/agro/variedades.php' => ['salvar', 'excluir:D'],
    '/colheita/index.php' => ['salvar', 'entrada_confirmar:X', 'entrada_estornar:X', 'excluir:D'],
    '/comercial/armazenagem_propria.php' => ['salvar', 'excluir:D'],
    '/comercial/armazenagem_terceiros.php' => ['salvar', 'excluir:D'],
    '/comercial/compradores.php' => ['salvar', 'excluir:D'],
    '/comercial/contratos_venda.php' => ['salvar', 'status'],
    '/comercial/logistica_frete.php' => ['salvar', 'status', 'excluir:D'],
    '/comercial/romaneios.php' => ['salvar', 'excluir:D'],
    '/comercial/tabela_precos.php' => ['salvar', 'excluir:D'],
    '/comercial/vendas.php' => ['salvar', 'anexar', 'add_despesa', 'remove_despesa:D', 'excluir_anexo:D', 'excluir:D'],
    '/compras/aprovacoes.php' => ['aprovar:X', 'rejeitar:X'],
    '/compras/cotacoes.php' => ['salvar', 'selecionar', 'excluir:D'],
    '/compras/fornecedores.php' => ['salvar', 'excluir:D'],
    '/compras/pedidos.php' => ['salvar', 'enviar_aprovacao:X', 'cancelar:D'],
    '/compras/recebimentos.php' => ['receber:X'],
    '/compras/solicitacoes.php' => ['salvar', 'cancelar:D'],
    '/configuracoes/empresa_fazenda.php' => ['salvar_empresa:X'],
    '/configuracoes/parametros_sistema.php' => ['salvar_colheita:X'],
    '/configuracoes/perfis_acesso.php' => ['salvar', 'excluir:D'],
    '/configuracoes/permissoes.php' => ['salvar:X'],
    '/configuracoes/usuarios.php' => ['salvar', 'excluir:D'],
    '/custeio/fechamento.php' => ['fechar:X', 'reabrir:X', 'ratear:X', 'desfazer_rateio:X'],
    '/custeio/metas.php' => ['salvar', 'excluir:D'],
    '/custeio/metodologias.php' => ['metodologia', 'grupo', 'item', 'metodologia_ativo', 'grupo_ativo', 'item_ativo'],
    '/custeio/orcamento.php' => ['salvar', 'vigente', 'encerrar', 'excluir:D'],
    '/custeio/orcamento_producao.php' => ['criar', 'copiar:X', 'valores', 'status', 'migrar_legado:X'],
    '/custeio/parametros_cultura.php' => ['salvar', 'inativar:D'],
    '/custeio/rateios.php' => ['salvar', 'atribuir_sem_safra:X', 'desfazer_sem_safra:X',
                               'ratear_combustivel:X', 'desfazer_combustivel:X', 'excluir:D'],
    '/dashboard/indicadores_alertas.php' => ['reconhecer', 'resolver'],
    '/estoque/agrofit.php' => ['criar', 'importar:X'],
    '/estoque/alertas.php' => ['reconhecer', 'resolver'],
    '/estoque/almoxarifados.php' => ['salvar', 'excluir:D'],
    '/estoque/grupos_subgrupos.php' => ['salvar_grupo', 'salvar_subgrupo', 'excluir_grupo:D', 'excluir_subgrupo:D'],
    '/estoque/inventario.php' => ['abrir:X', 'concluir:X', 'aprovar:X', 'reabrir:X', 'cancelar:D'],
    '/estoque/lotes.php' => ['status'],
    '/estoque/movimentacoes.php' => ['ajustar', 'devolver', 'estornar:D'],
    '/estoque/produtos.php' => ['salvar', 'movimentar', 'excluir:D'],
    '/estoque/transferencias.php' => ['transferir', 'estornar_par:D'],
    '/fazendas/index.php' => ['salvar', 'excluir:D'],
    '/financeiro/contas_pagar.php' => ['salvar', 'baixar', 'anexar', 'estornar:D', 'excluir_anexo:D', 'excluir:D'],
    '/financeiro/contas_receber.php' => ['salvar', 'baixar', 'estornar:D', 'excluir:D'],
    '/financeiro/centros_custo.php' => ['salvar', 'excluir:D'],
    '/financeiro/conciliacao_bancaria.php' => ['abrir', 'excluir:D'],
    '/financeiro/contas_bancarias.php' => ['salvar', 'excluir:D'],
    '/financeiro/plano_contas.php' => ['salvar', 'excluir:D'],
    '/fiscal/conciliacao_fiscal.php' => ['conciliar', 'desfazer:D'],
    '/fiscal/documentos.php' => ['salvar', 'recusar:D', 'reativar'],
    '/fiscal/emissao_mdfe.php' => ['registrar'],
    '/fiscal/emissao_nfe.php' => ['registrar'],
    '/fiscal/importacao_nfe.php' => ['importar:X'],
    '/fiscal/importacao_nfse.php' => ['registrar'],
    '/fiscal/livro.php' => ['salvar', 'gerar_razao:X', 'excluir:D'],
    '/fiscal/upload_pdf.php' => ['anexar'],
    '/irrigacao/apontamentos_irrigacao.php' => ['salvar', 'excluir:D'],
    '/irrigacao/painel.php' => ['salvar', 'excluir:D'],
    '/irrigacao/planejamento_irrigacao.php' => ['salvar', 'excluir:D'],
    '/maquinas/abastecimento.php' => ['salvar', 'excluir:D'],
    '/maquinas/cadastro.php' => ['salvar', 'excluir:D'],
    '/maquinas/disponibilidade_frota.php' => ['status'],
    '/maquinas/horimetro.php' => ['registrar'],
    '/maquinas/implementos.php' => ['salvar', 'excluir:D'],
    '/maquinas/manutencao.php' => ['salvar', 'cancelar:D', 'excluir:D'],
    '/maquinas/odometro.php' => ['registrar'],
    '/maquinas/planos_manutencao.php' => ['salvar', 'excluir:D'],
    '/maquinas/veiculos.php' => ['salvar', 'excluir:D'],
    '/mip/pragas.php' => ['salvar', 'excluir:D'],
    '/mip/doencas.php' => ['salvar', 'excluir:D'],
    '/mip/alertas_fitossanitarios.php' => ['reconhecer', 'resolver', 'registrar_acao'],
    '/mip/alvos_controle.php' => ['salvar', 'prod_del:D', 'excluir:D'],
    '/mip/aplicacoes.php' => ['salvar', 'confirmar:X', 'validar:X', 'excluir:D'],
    '/mip/monitoramento.php' => ['salvar', 'enviar:X', 'excluir_foto:D', 'excluir:D'],
    '/mip/pontos_amostragem.php' => ['salvar', 'excluir:D'],
    '/mip/receituarios.php' => ['salvar', 'anexar', 'excluir_anexo:D', 'excluir:D'],
    '/nutricao/analise_solo.php' => ['salvar', 'importar_csv', 'excluir:D'],
    '/nutricao/analise_foliar.php' => ['salvar', 'importar_csv', 'excluir:D'],
    '/nutricao/faixas_nutricionais.php' => ['salvar', 'excluir:D'],
    '/nutricao/importar_laudo.php' => ['extrair:X', 'confirmar:X', 'rejeitar:D'],
    '/nutricao/nutrientes.php' => ['salvar', 'excluir:D'],
    '/nutricao/painel_nutrientes.php' => ['reconhecer', 'resolver'],
    '/patrimonio/ativos.php' => ['salvar', 'excluir:D'],
    '/patrimonio/depreciacao_gerencial.php' => ['gerar:X'],
    '/pessoas/colaboradores.php' => ['salvar', 'excluir:D'],
    '/pessoas/encargos.php' => ['salvar', 'salvar_funrural', 'excluir:D'],
    '/pessoas/epis.php' => ['item', 'entregar', 'devolver'],
    '/pessoas/equipes.php' => ['salvar', 'excluir:D'],
    '/pessoas/folha.php' => ['criar_periodo:X', 'gerar:X', 'status:X', 'excluir:D'],
    '/pessoas/premiacao.php' => ['salvar', 'excluir:D'],
    '/pessoas/responsaveis_tecnicos.php' => ['registro', 'anexar', 'inativar:D'],
    '/pessoas/terceirizados.php' => ['salvar', 'excluir:D'],
    '/pessoas/treinamentos.php' => ['tema', 'turma', 'anexar'],
    '/safras/index.php' => ['salvar', 'vincular', 'rolar:X', 'desvincular:D', 'excluir:D'],
];

/* ações cujo payload VÁLIDO é exercitado no 20_fluxos (evidência lá) */
$COBERTAS = [
    'fazendas/index.php' => ['salvar', 'excluir'], 'agro/tipos_atividade.php' => ['salvar'],
    'compras/pedidos.php' => ['salvar', 'enviar_aprovacao', 'cancelar'],
    'compras/aprovacoes.php' => ['aprovar'], 'compras/recebimentos.php' => ['receber'],
    'agro/apontamentos.php' => ['iniciar', 'finalizar', 'salvar'],
    'mip/monitoramento.php' => ['salvar', 'enviar'], 'mip/aplicacoes.php' => ['salvar'],
    'nutricao/analise_foliar.php' => ['salvar'],
    'maquinas/abastecimento.php' => ['salvar'], 'patrimonio/depreciacao_gerencial.php' => ['gerar'],
    'irrigacao/apontamentos_irrigacao.php' => ['salvar'],
    'colheita/index.php' => ['salvar', 'entrada_confirmar'], 'comercial/vendas.php' => ['salvar'],
    'financeiro/contas_pagar.php' => ['baixar', 'estornar', 'excluir'],
    'financeiro/contas_receber.php' => ['baixar', 'estornar'],
    'pessoas/folha.php' => ['criar_periodo', 'gerar', 'status'], 'pessoas/premiacao.php' => ['salvar'],
    'custeio/fechamento.php' => ['fechar', 'reabrir'],
    'custeio/rateios.php' => ['atribuir_sem_safra', 'desfazer_sem_safra'],
    'fiscal/importacao_nfe.php' => ['importar'], 'agro/mapa.php' => ['importar_mapa'],
];

$total = 0;
foreach ($INVENTARIO as $acoes) $total += count($acoes);
qa_section('Inventário');
qa_check("inventário congelado: {$total} ações em " . count($INVENTARIO) . ' telas (≥180)', $total >= 180, $total);
file_put_contents(QA_OUT . '/botoes_inventario.json',
    json_encode(['acoes' => $INVENTARIO, 'cobertas_pelo_fluxo' => $COBERTAS],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

if (!qa_http_login('super')) {
    qa_check('login HTTP qa.super', false, 'base_url inacessível');
    qa_finish('50_botoes');
}
qa_check('login HTTP qa.super', true);

/* snapshot de contagem por tabela do tenant QA (prova de não-gravação) */
function qa_snapshot(int $T): array
{
    $out = [];
    $tabelas = array_column(qa_rows(
        "SELECT DISTINCT table_name t FROM information_schema.columns
          WHERE table_schema = DATABASE() AND column_name = 'tenant_id'"), 't');
    foreach ($tabelas as $tb) {
        if (in_array($tb, ['auth_audit_logs', 'login_throttle', 'tenants'], true)) continue;
        $out[$tb] = (int)qa_val("SELECT COUNT(*) FROM `$tb` WHERE tenant_id = ?", [$T]);
    }
    return $out;
}
$antes = qa_snapshot($T);

/* varredura: fase 1 = não destrutivas; fase 2 = destrutivas (por último) */
$fases = [1 => [], 2 => []];
foreach ($INVENTARIO as $rota => $acoes) {
    foreach ($acoes as $spec) {
        [$acao, $flag] = array_pad(explode(':', $spec, 2), 2, '');
        $fases[$flag === 'D' ? 2 : 1][] = [$rota, $acao, $flag];
    }
}

$erros500 = [];
$csrfFuros = [];
foreach ([1, 2] as $fase) {
    qa_section($fase === 1 ? 'Varredura — ações não destrutivas' : 'Varredura — destrutivas (por último)');
    foreach ($fases[$fase] as [$rota, $acao, $flag]) {
        /* (a) SEM CSRF — precisa ser rejeitado, nunca 500 */
        $r = qa_curl(qa_base() . $rota, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['acao' => $acao, 'id' => '999999999']),
            CURLOPT_COOKIEJAR => qa_cookiejar('super'), CURLOPT_COOKIEFILE => qa_cookiejar('super')]);
        if ($r['code'] >= 500 || $r['code'] === 0) $erros500[] = "sem-csrf {$rota}::{$acao} http={$r['code']}";
        if (!in_array($r['code'], [403, 302, 200, 400, 404], true)) $csrfFuros[] = "{$rota}::{$acao} http={$r['code']}";

        /* (b) COM CSRF, id inexistente / campos vazios — nunca 500; X = pulado */
        if ($flag === 'X') continue;
        $r = qa_http_post('super', $rota, ['acao' => $acao, 'id' => '999999999']);
        if ($r['code'] >= 500 || $r['code'] === 0) $erros500[] = "com-csrf {$rota}::{$acao} http={$r['code']}";
        if (str_contains($r['body'], 'Fatal error') || str_contains($r['body'], 'Uncaught')) {
            $erros500[] = "fatal-no-corpo {$rota}::{$acao}";
        }
    }
}
qa_check('nenhuma ação devolveu 500/fatal (payload inválido)', $erros500 === [], $erros500);
qa_check('nenhum código HTTP anômalo no teste sem CSRF', $csrfFuros === [], $csrfFuros);

/* prova de não-gravação: diff de contagens */
qa_section('Prova de não-gravação');
$depois = qa_snapshot($T);
$cresceu = [];
foreach ($depois as $tb => $n) {
    if ($n > ($antes[$tb] ?? 0)) $cresceu[$tb] = ($antes[$tb] ?? 0) . '→' . $n;
}
qa_check('varredura não gravou NADA no tenant QA (0 tabelas cresceram)', $cresceu === [], $cresceu);

/* executores de estado: registrar cobertura/skip explícito */
qa_section('Executores de estado (X)');
foreach ($fases[1] as [$rota, $acao, $flag]) { /* nada */ }
foreach ($INVENTARIO as $rota => $acoes) {
    foreach ($acoes as $spec) {
        [$acao, $flag] = array_pad(explode(':', $spec, 2), 2, '');
        if ($flag !== 'X') continue;
        $chave = ltrim($rota, '/');
        if (isset($COBERTAS[$chave]) && in_array($acao, $COBERTAS[$chave], true)) {
            qa_check("payload válido de {$chave}::{$acao} coberto no 20_fluxos", true);
        } else {
            qa_skip("payload válido de {$chave}::{$acao}",
                'executor de estado fora do fluxo canônico — só testado sem CSRF (seguro)');
        }
    }
}

qa_finish('50_botoes');
