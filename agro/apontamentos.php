<?php
/* ============================================================
   VERO — Gestão Agrícola / Apontamentos de Campo  (tela real)
   Substitui o mock. Rota da matriz: /agro/apontamentos.php
   Guard: agricola.apontamentos_campo
   Escrita: agro.apontamentos_campo.editar/excluir
   Tabelas: agro_apontamentos + rh_producao_itens (mig. 130)
            → custeio_lancamentos (origem_tipo='rh_producao_item')
   Cálculos: includes/vero_services.php (server é a fonte da verdade;
   o JS só dá preview em tempo real).
   Insumos (baixa de estoque): entra no Bloco B junto com estoque/produtos.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_fenologia_helper.php'; /* A1-29: fase automática pela data */
require_once __DIR__ . '/_setor_espelho.php';    /* A1-36: rótulo P-57 (válvula = válvula) */
require_once __DIR__ . '/_os_espelho.php';       /* A1-38: apontamento carrega a OS da atividade */
require_once __DIR__ . '/_calc_mo_painel.php';    /* A1-48c: calculadora de MO (sugestão, não grava) */

const T = 'agro_apontamentos';

/** categoria do tipo de atividade → enum `tipo` legado do apontamento */
function apont_tipo_enum(string $categoria): string
{
    return match ($categoria) {
        'trato_cultural' => 'tratos_culturais',
        'colheita'       => 'colheita',
        'aplicacao'      => 'aplicacao',
        'irrigacao'      => 'irrigacao',
        default          => 'outro',
    };
}

/* ── Poda: ids dos tipos de atividade "Poda" do tenant (mesma detecção de
   agro/abertura_safra.php::abertura_poda_tipo_ids — poda é trato_cultural cujo
   nome casa %poda%). Replicado aqui (não incluir abertura_safra.php: ele redefine
   const T e roda a página inteira). Ints → seguros inline em qualquer query. */
function apont_poda_tipo_ids(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = array_map('intval', array_column(vero_rows(
        "SELECT id FROM agro_tipos_atividade
          WHERE tenant_id = :t AND categoria = 'trato_cultural' AND nome LIKE :nm",
        [':t' => vero_tenant(), ':nm' => '%poda%']), 'id'));
    return $cache;
}

/**
 * W-13: cobertura da poda de UMA válvula =
 *   plantas podadas ÷ nº de plantas cadastrado da válvula.
 * "plantas podadas" = SUM(rh_producao_itens.quantidade) dos itens em unidade
 * 'planta' de apontamentos de PODA já concluídos (status validado/pendente) da
 * válvula, restrito ao CICLO ATUAL (após a data_poda da última safra já
 * finalizada nesta válvula, se houver — evita somar ciclos anteriores).
 * Tolerância: o gestor decide o corte (≥95%) fora daqui.
 * dia_zero = data da última planta podada (MAX data_apontamento das podas do
 * ciclo) — MESMA regra do dia 0 de agro/abertura_safra.php::confirmar_poda.
 * Retorna cobertura=null quando não mensurável (válvula sem nº de plantas):
 * nesse caso o chamador mantém a oferta (não trava por falta de cadastro).
 * Ints de tipo-poda vão inline (seguros); placeholders únicos por query (HY093).
 */
function apont_poda_cobertura(int $talhaoId): array
{
    $ids = apont_poda_tipo_ids();
    $out = ['cobertura' => null, 'num_plantas' => 0, 'plantas' => 0.0, 'dia_zero' => null];
    if (!$ids) return $out;
    $in = implode(',', $ids);              // apenas inteiros
    $t  = vero_tenant();
    $numPlantas = (int)(vero_val("SELECT num_plantas FROM agro_talhoes WHERE id = :i AND tenant_id = :t",
        [':i' => $talhaoId, ':t' => $t]) ?? 0);
    /* âncora do ciclo: última poda JÁ finalizada (confirmada em safra) da válvula */
    $anchor = vero_val(
        "SELECT MAX(st.data_poda) FROM agro_safra_talhoes st
          WHERE st.tenant_id = :t AND st.talhao_id = :tl AND st.poda_status = 'finalizada'",
        [':t' => $t, ':tl' => $talhaoId]);
    $condAnc = $anchor ? " AND DATE(ap.data_apontamento) > :anc" : "";
    $par = [':t' => $t, ':tl' => $talhaoId];
    if ($anchor) $par[':anc'] = (string)$anchor;
    $plantas = (float)(vero_val(
        "SELECT COALESCE(SUM(pi.quantidade), 0)
           FROM rh_producao_itens pi
           JOIN agro_apontamentos ap ON ap.id = pi.apontamento_id AND ap.tenant_id = pi.tenant_id
          WHERE pi.tenant_id = :t AND ap.talhao_id = :tl
            AND ap.tipo_atividade_id IN ($in) AND ap.status IN ('validado','pendente')
            AND pi.unidade = 'planta'" . $condAnc, $par) ?? 0);
    $diaZero = vero_val(
        "SELECT MAX(DATE(ap.data_apontamento)) FROM agro_apontamentos ap
          WHERE ap.tenant_id = :t AND ap.talhao_id = :tl
            AND ap.tipo_atividade_id IN ($in) AND ap.status IN ('validado','pendente')" . $condAnc, $par);
    $out['num_plantas'] = $numPlantas;
    $out['plantas']     = $plantas;
    $out['dia_zero']    = $diaZero ? (string)$diaZero : null;
    $out['cobertura']   = $numPlantas > 0 ? ($plantas / $numPlantas) : null;
    return $out;
}

/**
 * Gera a OS-espelho do apontamento (numeração OS{ano}-{NNNN}, mesma sequência de
 * agro/_os_espelho.php). atividade_id = NULL (a OS nasce do apontamento, não de
 * uma atividade planejada), status = 'em_execucao', data_abertura = hoje.
 * Numeração atômica via GET_LOCK (padrão do projeto). Deve rodar dentro da
 * transação do "Iniciar" (usa a mesma conexão singleton). Retorna o id da OS.
 */
function apont_gerar_os(int $talhaoId): int
{
    $t   = vero_tenant();
    $pdo = vero_pdo();
    $lock = 'vero_os_num_' . $t;
    $pdo->prepare("SELECT GET_LOCK(?, 5)")->execute([$lock]);
    try {
        $seq = (int)vero_val(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(numero, 8) AS UNSIGNED)), 0)
               FROM agro_ordens_servico WHERE tenant_id = :t AND numero LIKE :p",
            [':t' => $t, ':p' => 'OS' . date('Y') . '-%']) + 1;
        return vero_insert('agro_ordens_servico', [
            'atividade_id'  => null,
            'numero'        => 'OS' . date('Y') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT),
            'talhao_id'     => $talhaoId,
            'status'        => 'em_execucao',
            'data_abertura' => date('Y-m-d'),
        ]);
    } finally {
        $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lock]);
    }
}

/**
 * Valida o cabeçalho do apontamento a partir do POST — mesmas regras que o fluxo
 * antigo aplicava (data/válvula/tipo obrigatórios; vínculo safra×válvula + guard
 * de custeio P-06; guards A1-55 de aplicação=DF e de irrigação; guard
 * cultura×atividade; fenologia POR VARIEDADE + fallback por cultura; responsável
 * obrigatório). Em erro: vero_flash + vero_redirect (encerra a requisição).
 * Retorna ['cab' => <colunas base do cabeçalho, SEM status/timestamps/OS>,
 *          'ctx' => <contexto p/ a persistência do realizado>].
 */
/** V-01/V-02: snapshot da calculadora de MO (JSON) vindo do form — sanitizado
 *  (whitelist de unidade/base, números limpos). null se ausente/vazio. Guarda o
 *  planejamento p/ não se perder ao Iniciar e semear as linhas na finalização. */
function apont_planejamento_mo_json(): ?string
{
    $raw = (string)($_POST['planejamento_mo'] ?? '');
    if ($raw === '') return null;
    $d = json_decode($raw, true);
    if (!is_array($d)) return null;
    $num = static fn($v): string => is_scalar($v)
        ? mb_substr((string)preg_replace('/[^0-9.,\-]/', '', (string)$v), 0, 20) : '';
    $out = [];
    foreach (['total', 'dias', 'pessoas', 'meta', 'media', 'premio'] as $k) {
        $n = $num($d[$k] ?? ''); if ($n !== '') $out[$k] = $n;
    }
    if (isset($d['unidade']) && in_array($d['unidade'], ['planta', 'fila', 'ha', 'cacho', 'caixa', 'hora', 'contentor'], true)) $out['unidade'] = $d['unidade'];
    if (isset($d['base']) && in_array($d['base'], ['prazo', 'pessoas'], true)) $out['base'] = $d['base'];
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
}

function apont_validar_cabecalho(): array
{
    $data     = vero_date('data_apontamento');
    $talhaoId = vero_int('talhao_id');
    $stId     = vero_int('safra_talhao_id');
    $tipoId   = vero_int('tipo_atividade_id');
    /* o campo Fase vem prefixado — 'v:<id>' fase por variedade, 'c:<id>' estágio
       por cultura (fallback), '' automático. */
    $faseRef    = vero_str('fase_ref', 20);
    $fenoId     = null;
    $varFaseSel = null;
    if (preg_match('/^c:(\d+)$/', (string)$faseRef, $mFase))      $fenoId     = (int)$mFase[1];
    elseif (preg_match('/^v:(\d+)$/', (string)$faseRef, $mFase))  $varFaseSel = (int)$mFase[1];

    /* Sprint Zero packing #3: talhao_id é nullable no schema (migration 194) para
       o apontamento de packing (sem válvula). Enquanto a categoria 'packing' não
       existe, todo apontamento é agrícola e exige válvula. Quando existir, tornar
       a exigência de $talhaoId condicional à categoria (carregar $tipoAtv antes
       desta guarda e dispensar válvula para 'packing'; guardar o SELECT de L178). */
    if ($data === null || !$talhaoId || !$tipoId) {
        vero_flash('erro', 'Data, válvula e tipo de atividade são obrigatórios.');
        vero_redirect();
    }
    $talhao = vero_row("SELECT * FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
        [':i' => $talhaoId, ':t' => vero_tenant()]);
    if (!$talhao) {
        vero_flash('erro', 'Válvula inválido.');
        vero_redirect();
    }
    $culturaId = null;
    $vinculo   = null;
    if ($stId) {
        $vinculo = vero_row(
            "SELECT * FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t AND talhao_id=:ta",
            [':i' => $stId, ':t' => vero_tenant(), ':ta' => $talhaoId]);
        if (!$vinculo) {
            vero_flash('erro', 'Vínculo safra × válvula inválido para esta válvula.');
            vero_redirect();
        }
        $guardSafra = vero_srv_custeio_pode_lancar((int)$vinculo['safra_id']); /* A3-T6 (P-06) */
        if (!$guardSafra['pode']) {
            vero_flash('erro', $guardSafra['motivo']);
            vero_redirect();
        }
        $culturaId = (int)$vinculo['cultura_id'];
    }
    $tipoAtv = vero_row("SELECT * FROM agro_tipos_atividade WHERE id=:i AND tenant_id=:t AND ativo=1",
        [':i' => $tipoId, ':t' => vero_tenant()]);
    if (!$tipoAtv) {
        vero_flash('erro', 'Tipo de atividade inválido.');
        vero_redirect();
    }
    /* A1-55 (INVARIANTE): aplicação de defensivo é DF NUMERADA, não apontamento. */
    if ((string)$tipoAtv['categoria'] === 'aplicacao') {
        vero_flash('erro', 'Pulverização/aplicação não é apontamento genérico — emita a OS de aplicação (DF numerada, com bula, operadores/EPI e validação do RT). Use o botão "Nova pulverização (DF)" ou MIP → Aplicações de Defensivos.');
        vero_redirect();
    }
    /* Irrigação tem tela própria (lâmina, água, energia, custeio hídrico). */
    if ((string)$tipoAtv['categoria'] === 'irrigacao') {
        vero_flash('erro', 'Irrigação não é apontamento de campo — registre horas, lâmina (mm) e consumo de água/energia em Irrigação → Apontamentos de Irrigação.');
        vero_redirect();
    }
    /* atividade restrita a culturas? precisa casar com a cultura do vínculo */
    $culturasTipo = array_map('intval', array_column(vero_rows(
        "SELECT cultura_id FROM agro_tipo_atividade_culturas WHERE tenant_id=:t AND tipo_atividade_id=:a",
        [':t' => vero_tenant(), ':a' => $tipoId]), 'cultura_id'));
    if ($culturasTipo && $culturaId !== null && !in_array($culturaId, $culturasTipo, true)) {
        vero_flash('erro', "A atividade \"{$tipoAtv['nome']}\" não está habilitada para a cultura da safra selecionada.");
        vero_redirect();
    }
    if ($fenoId) {
        $okFeno = vero_val("SELECT id FROM agro_fenologia_estagios WHERE id=:i AND tenant_id=:t",
            [':i' => $fenoId, ':t' => vero_tenant()]);
        if (!$okFeno) $fenoId = null;
    }
    /* fase pela fenologia POR VARIEDADE (dias desde a poda); a variedade
       (versão aprovada) é AUTORITATIVA; fenologia_id por cultura = fallback/compat. */
    if ($varFaseSel) {
        $okVar = vero_val(
            "SELECT fa.id FROM agro_variedade_fases fa
               JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
              WHERE fa.id = :i AND fa.tenant_id = :t
                AND fe.status = 'aprovada' AND fe.ativo = 1 AND fa.ativo = 1",
            [':i' => $varFaseSel, ':t' => vero_tenant()]);
        if (!$okVar) $varFaseSel = null;
    }
    $varFase   = vero_a1_fenologia_variedade_resolver($stId ?: null, null, $data);
    $varFaseId = $varFaseSel ?: ($varFase ? (int)$varFase['id'] : null);
    $diasPoda  = $varFase ? (int)$varFase['dias'] : null;
    if (!$fenoId && !$varFaseId) {
        $fenoId = vero_a1_fenologia_por_data($stId ?: null, null, $data);
    }

    /* #15 (P-105): RESPONSÁVEL pela frente — SEMPRE OBRIGATÓRIO. */
    $responsavelId = vero_int('responsavel_id');
    $okResp = $responsavelId ? vero_val("SELECT id FROM agro_operadores WHERE id=:i AND tenant_id=:t",
        [':i' => $responsavelId, ':t' => vero_tenant()]) : null;
    if (!$okResp) {
        vero_flash('erro', 'Informe o responsável pela frente (obrigatório) — o encarregado que responde pela operação.');
        vero_redirect();
    }

    /* A11: área trabalhada (hectares) nunca é negativa */
    $hectares = vero_dec('hectares');
    if ($hectares !== null && $hectares < 0) {
        vero_flash('erro', 'A área (hectares) não pode ser negativa.');
        vero_redirect();
    }

    /* Cabeçalho base — SEM status, iniciado_em, finalizado_em/por nem
       ordem_servico_id: cada estágio (iniciar/finalizar/salvar) define esses. */
    $cab = [
        'data_apontamento'  => $data . ' 00:00:00',
        'talhao_id'         => $talhaoId,
        'responsavel_id'    => $responsavelId,
        'safra_talhao_id'   => $stId,
        'atividade_id'      => null,
        'tipo_atividade_id' => $tipoId,
        'fenologia_id'      => $fenoId,
        'variedade_fase_id' => $varFaseId,
        'dias_desde_poda'   => $diasPoda,
        'hectares'          => $hectares,
        'tipo'              => apont_tipo_enum((string)$tipoAtv['categoria']),
        'origem'            => 'web',
        'observacao'        => vero_str('observacao', 255),
        'planejamento_mo'   => apont_planejamento_mo_json(), /* V-01/V-02: snapshot da calc */
    ];
    $ctx = [
        'data'      => $data,
        'talhaoId'  => $talhaoId,
        'stId'      => $stId,
        'vinculo'   => $vinculo,
        'culturaId' => $culturaId,
        'tipoId'    => $tipoId,
        'tipoAtv'   => $tipoAtv,
    ];
    return ['cab' => $cab, 'ctx' => $ctx];
}

/**
 * Limpa o "realizado" de um apontamento (itens de pessoas, insumos com estorno de
 * estoque, máquinas e abastecimentos DIRETOS + o custeio deles) para regravar a
 * partir do form. NUNCA toca abastecimentos avulsos (apontamento_id NULL). Deve
 * rodar dentro de transação.
 */
function apont_limpar_realizado(PDO $pdo, int $apontId): void
{
    /* produção via packing: NÃO apaga as pessoas (a leitura do posto é a fonte) */
    $viaPacking = (int)(vero_val("SELECT producao_via_packing FROM agro_apontamentos WHERE id=:i AND tenant_id=:t",
        [':i' => $apontId, ':t' => vero_tenant()]) ?? 0) === 1;
    if (!$viaPacking) vero_srv_apontamento_limpar_itens($apontId);
    vero_srv_apontamento_limpar_insumos($apontId);
    vero_srv_apontamento_limpar_maquinas($apontId);
    $pdo->prepare("DELETE cl FROM custeio_lancamentos cl
                     JOIN maquina_abastecimentos ma
                       ON ma.id = cl.origem_id AND cl.origem_tipo = 'maquina_abastecimento'
                    WHERE cl.tenant_id = ? AND ma.tenant_id = ? AND ma.apontamento_id = ?")
        ->execute([vero_tenant(), vero_tenant(), $apontId]);
    $pdo->prepare("DELETE FROM maquina_abastecimentos WHERE tenant_id = ? AND apontamento_id = ?")
        ->execute([vero_tenant(), $apontId]);
}

/**
 * ADOTA a produção do posto de packing (gestor 19/08): quem bipa ANTES de criar
 * o apontamento manual deixa as caixas num apontamento AUTO-criado pelo posto
 * (mesmo trio data+atividade+válvula). Ao salvar um documento com "produção via
 * packing" marcado, as linhas rh_producao_itens dos OUTROS apontamentos
 * via-packing do trio MUDAM para este documento — o id da linha não muda, então
 * o custeio (origem 'rh_producao_item' pelo id) segue válido — e o apontamento
 * automático esvaziado é apagado (só se não sobrou NADA nele; o auto do posto
 * nunca tem insumos/máquinas). Termina o dia com UM documento, em qualquer
 * ordem de uso (bipou antes ou apontou antes). Rodar dentro de transação.
 * @return int linhas de produção adotadas
 */
function apont_adotar_producao_packing(int $apontId): int
{
    $t = vero_tenant();
    $doc = vero_row(
        "SELECT id, DATE(data_apontamento) AS dia, tipo_atividade_id, talhao_id
           FROM " . T . " WHERE id = :i AND tenant_id = :t", [':i' => $apontId, ':t' => $t]);
    if (!$doc) return 0;
    $outros = vero_rows(
        "SELECT id FROM " . T . "
          WHERE tenant_id = :t AND id <> :i AND producao_via_packing = 1
            AND DATE(data_apontamento) = :d AND tipo_atividade_id = :ta AND (talhao_id <=> :tal)",
        [':t' => $t, ':i' => $apontId, ':d' => (string)$doc['dia'],
         ':ta' => (int)$doc['tipo_atividade_id'],
         ':tal' => $doc['talhao_id'] !== null ? (int)$doc['talhao_id'] : null]);
    $movidas = 0;
    $pdo = vero_pdo();
    foreach ($outros as $o) {
        $oid = (int)$o['id'];
        $st = $pdo->prepare("UPDATE rh_producao_itens SET apontamento_id = ? WHERE tenant_id = ? AND apontamento_id = ?");
        $st->execute([$apontId, $t, $oid]);
        $movidas += $st->rowCount();
        $resta = (int)vero_val(
            "SELECT (SELECT COUNT(*) FROM rh_producao_itens        WHERE tenant_id = :t1 AND apontamento_id = :a1)
                  + (SELECT COUNT(*) FROM agro_apontamento_insumos  WHERE tenant_id = :t2 AND apontamento_id = :a2)
                  + (SELECT COUNT(*) FROM agro_apontamento_maquinas WHERE tenant_id = :t3 AND apontamento_id = :a3)
                  + (SELECT COUNT(*) FROM maquina_abastecimentos    WHERE tenant_id = :t4 AND apontamento_id = :a4)",
            [':t1' => $t, ':a1' => $oid, ':t2' => $t, ':a2' => $oid,
             ':t3' => $t, ':a3' => $oid, ':t4' => $t, ':a4' => $oid]);
        if ($resta === 0) {
            $pdo->prepare("DELETE FROM " . T . " WHERE id = ? AND tenant_id = ?")->execute([$oid, $t]);
        }
    }
    return $movidas;
}

/**
 * Processa o "realizado" do POST (pessoas → rh_producao_itens, insumos com baixa
 * FEFO, máquinas horas×custo, abastecimento direto) e EMITE o custeio. É a MESMA
 * lógica do save antigo — apenas movida para cá para ser reusada por
 * salvar/finalizar (a lógica de estoque/FEFO/custeio interna não muda). Deve rodar
 * dentro de transação, após gravar o cabeçalho e limpar o realizado antigo.
 * Retorna resumo p/ o flash. $ctx = saída de apont_validar_cabecalho.
 */
function apont_gravar_realizado(int $apontId, array $ctx): array
{
    $data      = $ctx['data'];
    $talhaoId  = $ctx['talhaoId'];
    $stId      = $ctx['stId'];
    $vinculo   = $ctx['vinculo'];
    $culturaId = $ctx['culturaId'];
    $tipoId    = $ctx['tipoId'];
    $tipoAtv   = $ctx['tipoAtv'];

    $parseDec = static function ($v): ?float {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
        elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
        return is_numeric($v) ? (float)$v : null;
    };

    /* linhas de pessoas — produção via packing vem da leitura no posto (ignora a grade) */
    $viaPacking = (int)(vero_val("SELECT producao_via_packing FROM agro_apontamentos WHERE id=:i AND tenant_id=:t",
        [':i' => $apontId, ':t' => vero_tenant()]) ?? 0) === 1;
    $lOrigem = $viaPacking ? [] : (array)($_POST['l_origem'] ?? []);
    $lPessoa = (array)($_POST['l_pessoa'] ?? []);
    $lModal  = (array)($_POST['l_modalidade'] ?? []);
    $lQtd    = (array)($_POST['l_qtd'] ?? []);
    $lPeso   = (array)($_POST['l_peso'] ?? []);
    $lValor  = (array)($_POST['l_valor'] ?? []);
    $lMeta   = (array)($_POST['l_meta'] ?? []); /* 5.1: meta por linha (saiu do cadastro) */

    $itens = [];
    $avisoSemRegra = false;
    foreach ($lOrigem as $i => $origem) {
        $pessoaId = (int)($lPessoa[$i] ?? 0);
        $qtd      = $parseDec($lQtd[$i] ?? '') ?? 0.0;
        if (!$pessoaId) continue;

        if ($origem === 'colaborador') {
            $ok = vero_val("SELECT id FROM agro_operadores WHERE id=:i AND tenant_id=:t",
                [':i' => $pessoaId, ':t' => vero_tenant()]);
            if (!$ok) continue;
            /* 5.1 (premiação-na-OS): META e VALOR vêm da LINHA (informados no
               apontamento, mudam dia a dia). A regra por atividade serve só p/ a
               UNIDADE (rótulo) e o link opcional — meta/valor saíram do cadastro. */
            $regra   = vero_srv_regra_premiacao($tipoId, $culturaId, $data);
            $metaL   = $parseDec($lMeta[$i] ?? '');            /* meta da linha (null = sem meta) */
            $valorUn = $parseDec($lValor[$i] ?? '') ?? 0.0;    /* R$/unid. acima, da linha */
            $calc    = vero_srv_premiacao_calc($qtd, $metaL ?? 0.0, $valorUn);
            $itens[] = [
                'origem_pessoa' => 'colaborador', 'operador_id' => $pessoaId, 'terceirizado_id' => null,
                'modalidade' => 'premiacao', 'regra_premiacao_id' => $regra ? (int)$regra['id'] : null,
                'unidade' => $regra['unidade'] ?? ($tipoAtv['unidade_padrao'] ?? 'outro'), 'quantidade' => $qtd,
                'peso_kg' => $parseDec($lPeso[$i] ?? ''),
                'meta_aplicada' => $metaL,
                'valor_unitario' => $valorUn,
                'qtd_acima_meta' => $calc['qtd_acima'], 'valor_total' => $calc['valor_total'],
                'data_trabalho' => $data,
            ];
        } elseif ($origem === 'terceirizado') {
            $ok = vero_val("SELECT id FROM rh_terceirizados WHERE id=:i AND tenant_id=:t",
                [':i' => $pessoaId, ':t' => vero_tenant()]);
            if (!$ok) continue;
            $modal = ($lModal[$i] ?? '') === 'diaria' ? 'diaria' : 'producao';
            $valorUn = $parseDec($lValor[$i] ?? '') ?? 0.0;
            $itens[] = [
                'origem_pessoa' => 'terceirizado', 'operador_id' => null, 'terceirizado_id' => $pessoaId,
                'modalidade' => $modal, 'regra_premiacao_id' => null,
                'unidade' => $modal === 'diaria' ? 'outro' : ($tipoAtv['unidade_padrao'] ?? 'outro'),
                'quantidade' => $qtd, 'peso_kg' => $parseDec($lPeso[$i] ?? ''),
                'meta_aplicada' => null, 'valor_unitario' => $valorUn,
                'qtd_acima_meta' => null, 'valor_total' => vero_srv_valor_producao($qtd, $valorUn),
                'data_trabalho' => $data,
            ];
        }
    }

    /* insumos / máquinas / abastecimento direto (mesmos campos do form) */
    $iProduto = (array)($_POST['i_produto'] ?? []);
    $iQtd     = (array)($_POST['i_qtd'] ?? []);
    $iDose    = (array)($_POST['i_dose'] ?? []);
    $mMaquina = (array)($_POST['m_maquina'] ?? []);
    $mHoras   = (array)($_POST['m_horas'] ?? []);
    $mCusto   = (array)($_POST['m_custo_hora'] ?? []);
    $mHorimFim = (array)($_POST['m_horimetro_fim'] ?? []); /* P-10: horímetro final (obrigatório em trator) */
    $mAbLitros = (array)($_POST['m_ab_litros'] ?? []);
    $mAbValor  = (array)($_POST['m_ab_valor'] ?? []);

    foreach ($itens as $item) {
        $item['apontamento_id'] = $apontId;
        vero_insert('rh_producao_itens', $item);
    }
    vero_srv_apontamento_reemitir_custeio($apontId);

    $apontCtx = [
        'id' => $apontId, 'data_apontamento' => $data . ' 00:00:00',
        'talhao_id' => $talhaoId, 'safra_talhao_id' => $stId,
        'safra_id' => $vinculo ? (int)$vinculo['safra_id'] : null,
        'cultura_id' => $culturaId,
    ];
    foreach ($iProduto as $ix => $prodId) {
        $prodId = (int)$prodId;
        $qtdIns = $parseDec($iQtd[$ix] ?? '') ?? 0.0;
        if (!$prodId || $qtdIns <= 0) continue;
        $okProd = vero_val("SELECT id FROM estoque_produtos WHERE id=:i AND tenant_id=:t",
            [':i' => $prodId, ':t' => vero_tenant()]);
        if (!$okProd) continue;
        vero_srv_apontamento_gravar_insumo($apontCtx, $prodId, $qtdIns, $parseDec($iDose[$ix] ?? ''));
    }
    $maquinasGravadas = 0;
    $abGravados = 0;
    $abParcial  = false;
    foreach ($mMaquina as $mx => $maqId) {
        $maqId    = (int)$maqId;
        $horas    = $parseDec($mHoras[$mx] ?? '') ?? 0.0;
        $abLitros = $parseDec($mAbLitros[$mx] ?? '') ?? 0.0;
        $abValor  = $parseDec($mAbValor[$mx] ?? '') ?? 0.0;
        if (!$maqId || $horas <= 0) {
            if ($maqId && ($abLitros > 0 || $abValor > 0)) $abParcial = true;
            continue;
        }
        $maqRow = vero_row("SELECT id, tipo FROM maquinas WHERE id=:i AND tenant_id=:t",
            [':i' => $maqId, ':t' => vero_tenant()]);
        if (!$maqRow) continue;
        /* P-10: só TRATOR tem horímetro → final obrigatório na conclusão do
           apontamento. O valor vira o novo horímetro atual da máquina (pré-preenche
           o próximo apontamento). Demais tipos ignoram o campo. */
        $horimFim = $parseDec($mHorimFim[$mx] ?? '');
        if (($maqRow['tipo'] ?? '') === 'trator' && ($horimFim === null || $horimFim <= 0)) {
            throw new RuntimeException('Informe o horímetro final do trator (obrigatório na conclusão do apontamento).');
        }
        $custoHora = $parseDec($mCusto[$mx] ?? '');
        vero_srv_apontamento_gravar_maquina($apontCtx, $maqId, $horas, $custoHora);
        if (($maqRow['tipo'] ?? '') === 'trator' && $horimFim !== null && $horimFim > 0) {
            vero_pdo()->prepare("UPDATE maquinas SET horimetro_atual = ? WHERE id = ? AND tenant_id = ?")
                ->execute([$horimFim, $maqId, vero_tenant()]);
        }
        $maquinasGravadas++;
        if ($abLitros > 0 && $abValor > 0) {
            $abId = vero_insert('maquina_abastecimentos', [
                'maquina_id'         => $maqId,
                'litros'             => $abLitros,
                'valor_total'        => round($abValor, 2),
                'data_abastecimento' => $data . ' 00:00:00',
                'apontamento_id'     => $apontId,
            ]);
            vero_insert('custeio_lancamentos', [
                'centro_custo_id' => vero_srv_centro_custo('MAQ', 'Máquinas'),
                'plano_conta_id'  => custeio_plano_conta_id('maquina_abastecimento'),
                'categoria'       => 'maquinas',
                'origem_tipo'     => 'maquina_abastecimento',
                'origem_id'       => $abId,
                'valor'           => round($abValor, 2),
                'quantidade'      => $abLitros,
                'data_competencia'=> $data,
            ]);
            $abGravados++;
        } elseif (($abLitros > 0) !== ($abValor > 0)) {
            $abParcial = true;
        }
    }

    return [
        'itens'    => count($itens),
        'total'    => array_sum(array_column($itens, 'valor_total')),
        'maquinas' => $maquinasGravadas,
        'abast'    => $abGravados,
        'abParcial'=> $abParcial,
        'semRegra' => $avisoSemRegra,
    ];
}

/** Flashes de aviso comuns (sem regra de premiação / abastecimento parcial). */
function apont_flash_avisos(array $res): void
{
    if (!empty($res['semRegra'])) {
        vero_flash('aviso', 'Colaborador(es) sem regra de premiação vigente para esta atividade/cultura: prêmio calculado como R$ 0,00. Cadastre a regra em Pessoas → Regras de Premiação.');
    }
    if (!empty($res['abParcial'])) {
        vero_flash('aviso', 'Abastecimento não registrado em uma ou mais máquinas: exige a máquina COM horas e os dois campos (litros E valor) preenchidos.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    /* ── acao='iniciar' (Estágio 1): grava SÓ o cabeçalho + gera a OS.
       Sem pessoas/insumos/máquinas/custeio (Decisão 1/2 — Iniciar não gera custo). */
    if ($acao === 'iniciar') {
        vero_require('agro.apontamentos_campo.editar');
        ['cab' => $cab, 'ctx' => $ctx] = apont_validar_cabecalho();

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $osId = apont_gerar_os((int)$ctx['talhaoId']);
            $cab['status']           = 'iniciado';
            $cab['iniciado_em']      = date('Y-m-d H:i:s');
            $cab['ordem_servico_id'] = $osId;
            $cab['producao_via_packing'] = isset($_POST['producao_via_packing']) ? 1 : 0;
            $apontId = vero_insert(T, $cab);
            /* 19/08: puxa as caixas já bipadas no posto p/ este documento */
            $adotadas = $cab['producao_via_packing'] === 1 ? apont_adotar_producao_packing((int)$apontId) : 0;
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao iniciar o apontamento: ' . h($e->getMessage()));
            vero_redirect();
        }
        $osNum = (string)vero_val("SELECT numero FROM agro_ordens_servico WHERE id=:i AND tenant_id=:t",
            [':i' => $osId, ':t' => vero_tenant()]);
        vero_flash('ok', 'Apontamento iniciado — OS ' . h($osNum) . ' gerada (em execução). Registre o realizado e finalize.'
            . ($adotadas > 0 ? " {$adotadas} linha(s) de produção já bipadas no packing foram adotadas por este documento." : ''));
        vero_redirect(BIOS_BASE . '/agro/apontamentos?editar=' . $apontId);
    }

    /* ── acao='finalizar' (Estágio 2): registra o realizado + EMITE custeio e
       conclui a OS. Só para apontamentos 'iniciado'. */
    if ($acao === 'finalizar') {
        vero_require('agro.apontamentos_campo.editar');
        $id = vero_int('id');
        if (!$id) {
            vero_flash('erro', 'Apontamento inválido.');
            vero_redirect();
        }
        $atual = vero_row("SELECT id, status, ordem_servico_id FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]);
        if (!$atual) {
            vero_flash('erro', 'Apontamento inválido.');
            vero_redirect();
        }
        if ((string)$atual['status'] !== 'iniciado') {
            vero_flash('erro', (string)$atual['status'] === 'validado'
                ? 'Este apontamento já foi finalizado.'
                : 'Só é possível finalizar apontamentos iniciados.');
            vero_redirect();
        }
        ['cab' => $cab, 'ctx' => $ctx] = apont_validar_cabecalho();
        $cab['status']         = 'validado';
        $cab['finalizado_em']  = date('Y-m-d H:i:s');
        $cab['finalizado_por'] = vero_uid();
        $cab['producao_via_packing'] = isset($_POST['producao_via_packing']) ? 1 : 0;
        $osId = (int)($atual['ordem_servico_id'] ?? 0);

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            vero_update(T, $id, $cab);           /* NÃO toca ordem_servico_id (preserva a OS) */
            apont_limpar_realizado($pdo, $id);   /* idempotente (iniciado não tem realizado) */
            /* 19/08: adota caixas bipadas ANTES no posto (documento duplicado do trio) */
            if ($cab['producao_via_packing'] === 1) apont_adotar_producao_packing((int)$id);
            $res = apont_gravar_realizado($id, $ctx);
            if ($osId) {
                vero_update('agro_ordens_servico', $osId,
                    ['status' => 'concluida', 'data_conclusao' => date('Y-m-d')]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao finalizar o apontamento: ' . h($e->getMessage()));
            vero_redirect();
        }
        vero_flash('ok', 'Apontamento finalizado — ' . $res['itens'] . ' pessoa(s)'
            . ($res['maquinas'] > 0 ? ', ' . $res['maquinas'] . ' máquina(s)' : '')
            . ($res['abast'] > 0 ? ', ' . $res['abast'] . ' abastecimento(s)' : '')
            . ', total mão de obra R$ ' . numFmt((float)$res['total'], 2) . '.'
            . ($osId ? ' OS concluída.' : ''));
        apont_flash_avisos($res);

        /* EXTRA (W-13) — poda finalizada → oferecer INICIAR NOVA SAFRA da válvula,
           mas SÓ quando a válvula está ≥95% podada (tolerância a replantio/falhas:
           não trava a safra por plantas faltantes). dia 0 = data da última planta
           podada. Sem nº de plantas cadastrado → não mensurável: mantém a oferta. */
        if (in_array((int)$ctx['tipoId'], apont_poda_tipo_ids(), true)) {
            $cob = apont_poda_cobertura((int)$ctx['talhaoId']);
            if ($cob['cobertura'] === null || $cob['cobertura'] >= 0.95) {
                $qs = '?finalizado_poda=' . (int)$ctx['talhaoId']
                    . ($cob['dia_zero'] ? '&dia_zero=' . $cob['dia_zero'] : '');
                vero_redirect(BIOS_BASE . '/agro/apontamentos' . $qs);
            }
            vero_flash('aviso', 'Poda registrada — cobertura ' . numFmt((float)$cob['cobertura'] * 100, 1)
                . '% da válvula (' . numFmt($cob['plantas'], 0) . ' de ' . (int)$cob['num_plantas']
                . ' plantas). A oferta de nova safra aparece ao atingir 95%.');
        }
        vero_redirect(BIOS_BASE . '/agro/apontamentos');
    }

    /* ── acao='salvar' (existente): correção de apontamento JÁ 'validado'
       (fluxo antigo, reemite custeio). O NOVO cadastro entra por 'iniciar' e o
       registro do realizado por 'finalizar'. O caminho da API/app não usa esta tela. */
    if ($acao === 'salvar') {
        vero_require('agro.apontamentos_campo.editar');
        $id = vero_int('id');
        if (!$id) {
            vero_flash('erro', 'Apontamento inválido — use "Iniciar apontamento" para um novo registro.');
            vero_redirect();
        }
        $atual = vero_row("SELECT id, status FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]);
        if (!$atual) {
            vero_flash('erro', 'Apontamento inválido.');
            vero_redirect();
        }
        /* 'iniciado' vai pelo Finalizar (Estágio 2); 'salvar' é só correção de
           apontamentos já concretizados (validado/pendente/recusado legados). */
        if ((string)$atual['status'] === 'iniciado') {
            vero_flash('erro', 'Este apontamento ainda não foi finalizado — use "Finalizar apontamento".');
            vero_redirect();
        }
        ['cab' => $cab, 'ctx' => $ctx] = apont_validar_cabecalho();
        $cab['status'] = 'validado';
        $cab['producao_via_packing'] = isset($_POST['producao_via_packing']) ? 1 : 0;

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            vero_update(T, $id, $cab);           /* NÃO toca ordem_servico_id (preserva a OS) */
            apont_limpar_realizado($pdo, $id);
            /* 19/08: adota caixas bipadas ANTES no posto (documento duplicado do trio) */
            if ($cab['producao_via_packing'] === 1) apont_adotar_producao_packing((int)$id);
            $res = apont_gravar_realizado($id, $ctx);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao salvar o apontamento: ' . h($e->getMessage()));
            vero_redirect();
        }
        vero_flash('ok', 'Apontamento atualizado — ' . $res['itens'] . ' pessoa(s)'
            . ($res['maquinas'] > 0 ? ', ' . $res['maquinas'] . ' máquina(s)' : '')
            . ($res['abast'] > 0 ? ', ' . $res['abast'] . ' abastecimento(s)' : '')
            . ', total mão de obra R$ ' . numFmt((float)$res['total'], 2) . '.');
        apont_flash_avisos($res);
        vero_redirect(BIOS_BASE . '/agro/apontamentos');
    }

    if ($acao === 'excluir') {
        vero_require('agro.apontamentos_campo.excluir');
        $id = vero_int('id');
        if ($id) {
            $apont = vero_row("SELECT id, ordem_servico_id FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => vero_tenant()]);
            if ($apont) {
                $pdo = vero_pdo();
                $pdo->beginTransaction();
                $osCancelada = null;
                try {
                    vero_srv_apontamento_limpar_itens($id);
                    vero_srv_apontamento_limpar_insumos($id); // estorna estoque + custeio
                    vero_srv_apontamento_limpar_maquinas($id); // remove usos de máquina + custeio
                    /* A1-56: remove os abastecimentos DIRETOS deste apontamento + custeio
                       (sem isto, o ON DELETE SET NULL da FK deixaria a linha como avulsa) */
                    $pdo->prepare("DELETE cl FROM custeio_lancamentos cl
                                     JOIN maquina_abastecimentos ma
                                       ON ma.id = cl.origem_id AND cl.origem_tipo = 'maquina_abastecimento'
                                    WHERE cl.tenant_id = ? AND ma.tenant_id = ? AND ma.apontamento_id = ?")
                        ->execute([vero_tenant(), vero_tenant(), $id]);
                    $pdo->prepare("DELETE FROM maquina_abastecimentos WHERE tenant_id = ? AND apontamento_id = ?")
                        ->execute([vero_tenant(), $id]);
                    /* R12-B4: a OS-espelho do apontamento (C-15, 1:1) não pode ficar órfã
                       "em execução". CANCELA (cancelamento lógico — padrão do ENUM da tela
                       de OS; sem DELETE físico) SOMENTE se a OS nasceu deste apontamento
                       (atividade_id NULL — OS de atividade planejada segue com a atividade,
                       espelho A1-33) e nenhum OUTRO apontamento a referencia (aí só se
                       desvincula, via o próprio DELETE da linha abaixo — a FK fica no
                       apontamento). Idempotente: já cancelada não re-atualiza. A trilha
                       visível fica na tela de OS (o schema não tem coluna observacao);
                       updated_by/updated_at registram quem/quando cancelou. */
                    $osId = (int)($apont['ordem_servico_id'] ?? 0);
                    if ($osId) {
                        $os = vero_row(
                            "SELECT id, numero, atividade_id, status FROM agro_ordens_servico
                              WHERE id=:i AND tenant_id=:t", [':i' => $osId, ':t' => vero_tenant()]);
                        $outros = (int)vero_val(
                            "SELECT COUNT(*) FROM " . T . "
                              WHERE tenant_id=:t AND ordem_servico_id=:o AND id<>:i",
                            [':t' => vero_tenant(), ':o' => $osId, ':i' => $id]);
                        if ($os && empty($os['atividade_id']) && $outros === 0
                            && (string)$os['status'] !== 'cancelada') {
                            vero_update('agro_ordens_servico', $osId,
                                ['status' => 'cancelada', 'data_conclusao' => null]);
                            $osCancelada = (string)$os['numero'];
                        }
                    }
                    $pdo->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")
                        ->execute([vero_tenant(), $id]);
                    $pdo->commit();
                    vero_flash('ok', 'Apontamento excluído (itens, insumos, máquinas e custeio removidos; estoque estornado).'
                        . ($osCancelada ? ' OS ' . h($osCancelada) . ' cancelada (espelho do apontamento excluído).' : ''));
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    vero_flash('erro', 'Não foi possível excluir: ' . h($e->getMessage()));
                }
            }
        }
        vero_redirect(BIOS_BASE . '/agro/apontamentos');
    }
}

/* ── Dados compartilhados (form + listagem) ─────────────────── */
$modoForm = isset($_GET['novo']) || !empty($_GET['editar']);

$edit = null;
$editItens = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        $editItens = vero_rows(
            "SELECT * FROM rh_producao_itens WHERE tenant_id=:t AND apontamento_id=:a ORDER BY id",
            [':t' => vero_tenant(), ':a' => (int)$edit['id']]);
        $editInsumos = vero_rows(
            "SELECT * FROM agro_apontamento_insumos WHERE tenant_id=:t AND apontamento_id=:a ORDER BY id",
            [':t' => vero_tenant(), ':a' => (int)$edit['id']]);
        $editMaquinas = vero_rows(
            "SELECT * FROM agro_apontamento_maquinas WHERE tenant_id=:t AND apontamento_id=:a ORDER BY id",
            [':t' => vero_tenant(), ':a' => (int)$edit['id']]);
        /* A1-56: abastecimentos DIRETOS deste apontamento (para pré-preencher o form) */
        $editAbast = vero_rows(
            "SELECT maquina_id, litros, valor_total FROM maquina_abastecimentos
              WHERE tenant_id=:t AND apontamento_id=:a ORDER BY id",
            [':t' => vero_tenant(), ':a' => (int)$edit['id']]);
    } else {
        $modoForm = false;
    }
}
$editInsumos  = $editInsumos ?? [];
$editMaquinas = $editMaquinas ?? [];
$editAbast    = $editAbast ?? [];
/* mapa maquina_id → abastecimento direto (1 por máquina no fluxo do apontamento) */
$abByMaq = [];
foreach ($editAbast as $ab) { $abByMaq[(int)$ab['maquina_id']] = $ab; }

if ($modoForm) {
    $talhoes = vero_rows(
        "SELECT t.id, t.codigo, t.area_ha, t.num_plantas, f.nome AS fazenda,
                vr.nome AS variedade, pe.nome AS porta_enxerto
           FROM agro_talhoes t JOIN agro_fazendas f ON f.id = t.fazenda_id
           LEFT JOIN agro_variedades vr ON vr.id = t.variedade_id AND vr.tenant_id = t.tenant_id
           LEFT JOIN agro_porta_enxertos pe ON pe.id = t.porta_enxerto_id AND pe.tenant_id = t.tenant_id
          WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo",
        [':t' => vero_tenant()]);
    $vinculos = vero_rows(
        "SELECT st.id, st.talhao_id, st.cultura_id, st.area_plantada_ha, st.data_poda,
                t.variedade_id, s.data_inicio AS safra_inicio,
                s.identificacao AS safra, c.nome AS cultura
           FROM agro_safra_talhoes st
           JOIN agro_safras s ON s.id = st.safra_id
           JOIN agro_culturas c ON c.id = st.cultura_id
           LEFT JOIN agro_talhoes t ON t.id = st.talhao_id AND t.tenant_id = st.tenant_id
          WHERE st.tenant_id = :t ORDER BY s.identificacao DESC",
        [':t' => vero_tenant()]);
    $tipos = vero_rows(
        "SELECT a.id, a.nome, a.categoria, a.unidade_padrao, a.exige_producao,
                (SELECT GROUP_CONCAT(tc.cultura_id) FROM agro_tipo_atividade_culturas tc
                  WHERE tc.tenant_id = a.tenant_id AND tc.tipo_atividade_id = a.id) AS culturas
           FROM agro_tipos_atividade a WHERE a.tenant_id = :t AND a.ativo = 1 ORDER BY a.nome",
        [':t' => vero_tenant()]);
    $fenologias = vero_rows(
        "SELECT id, cultura_id, escala, codigo, nome FROM agro_fenologia_estagios
          WHERE tenant_id = :t AND ativo = 1 ORDER BY cultura_id, ordem, codigo",
        [':t' => vero_tenant()]);
    /* item 1.1: fases da fenologia POR VARIEDADE (versão aprovada vigente) para
       pré-preencher a fase na tela por dias-desde-poda — só leitura, NÃO altera o
       POST (fenologia_id continua no modelo por-cultura/A1-29 até o remap). */
    $varFases = vero_rows(
        "SELECT fa.id, fe.variedade_id, fa.dia_inicio, fa.dia_fim, fa.nome
           FROM agro_variedade_fases fa
           JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
          WHERE fa.tenant_id = :t AND fe.status = 'aprovada' AND fe.ativo = 1 AND fa.ativo = 1
            AND fe.versao = (SELECT MAX(versao) FROM agro_variedade_fenologia fe2
                              WHERE fe2.tenant_id = fa.tenant_id AND fe2.variedade_id = fe.variedade_id
                                AND fe2.status = 'aprovada' AND fe2.ativo = 1)
          ORDER BY fe.variedade_id, fa.dia_inicio",
        [':t' => vero_tenant()]);
    $varFasesMap = [];
    foreach ($varFases as $vf) {
        $varFasesMap[(int)$vf['variedade_id']][] = [
            'id' => (int)$vf['id'], 'ini' => (int)$vf['dia_inicio'],
            'fim' => (int)$vf['dia_fim'], 'nome' => (string)$vf['nome']];
    }
    $regras = vero_rows(
        "SELECT id, tipo_atividade_id, cultura_id, unidade, meta_qtd, valor_acima_meta,
                vigencia_inicio, vigencia_fim
           FROM rh_regras_premiacao WHERE tenant_id = :t AND ativo = 1",
        [':t' => vero_tenant()]);
    $colaboradores = vero_rows(
        "SELECT id, nome, documento FROM agro_operadores WHERE tenant_id = :t AND ativo = 1 ORDER BY nome",
        [':t' => vero_tenant()]);
    $terceirizados = vero_rows(
        "SELECT id, nome, modalidade_padrao, valor_diaria FROM rh_terceirizados
          WHERE tenant_id = :t AND ativo = 1 ORDER BY nome",
        [':t' => vero_tenant()]);
    $produtos = vero_rows(
        "SELECT p.id, p.codigo, p.nome, p.unidade,
                COALESCE((SELECT SUM(s.quantidade) FROM estoque_saldos s
                  WHERE s.tenant_id = p.tenant_id AND s.produto_id = p.id), 0) AS saldo,
                COALESCE((SELECT SUM(s.valor_total) FROM estoque_saldos s
                  WHERE s.tenant_id = p.tenant_id AND s.produto_id = p.id), 0) AS valor,
                (SELECT MIN(l.validade) FROM estoque_lotes l
                  WHERE l.tenant_id = p.tenant_id AND l.produto_id = p.id
                    AND l.quantidade > 0 AND l.validade IS NOT NULL) AS prox_validade
           FROM estoque_produtos p
          WHERE p.tenant_id = :t AND p.ativo = 1 ORDER BY p.nome",
        [':t' => vero_tenant()]);
    /* A1-30: último monitoramento MIP por válvula (contexto na tela — a
       justificativa da operação é o monitoramento, requisito DF) */
    $ultMon = vero_rows(
        "SELECT m.talhao_id, m.data_monitoramento, m.nivel_infestacao, m.unidade, a.nome AS alvo
           FROM mip_monitoramentos m
           JOIN mip_alvos a ON a.id = m.alvo_id
           JOIN (SELECT talhao_id, MAX(CONCAT(data_monitoramento, LPAD(id,10,'0'))) AS chave
                   FROM mip_monitoramentos WHERE tenant_id = :t GROUP BY talhao_id) ult
             ON ult.talhao_id = m.talhao_id
            AND CONCAT(m.data_monitoramento, LPAD(m.id,10,'0')) = ult.chave
          WHERE m.tenant_id = :t2",
        [':t' => vero_tenant(), ':t2' => vero_tenant()]);

    /* máquinas com custo-hora do cadastro (A1-23). P-10: tipo (só 'trator' tem
       horímetro) + horímetro atual (último conhecido → pré-preenche o final). */
    $maquinasOpt = vero_rows(
        "SELECT m.id, m.nome, m.custo_hora, m.tipo, m.horimetro_atual FROM maquinas m
          WHERE m.tenant_id = :t AND m.ativo = 1 AND m.status <> 'inativa' ORDER BY m.nome",
        [':t' => vero_tenant()]);
} else {
    /* listagem — C-22: filtros combináveis p/ localizar registros
       (atividade, responsável/pessoa, período, estágio, safra) além da válvula.
       Tudo por EXISTS/colunas de `a` (o COUNT reusa o mesmo WHERE sem joins). */
    $fTalhao = (int)($_GET['talhao'] ?? 0);
    $fAtiv   = (int)($_GET['atividade'] ?? 0);
    $fSafra  = (int)($_GET['safra'] ?? 0);
    $fStatus = in_array((string)($_GET['estagio'] ?? ''), ['iniciado', 'pendente', 'validado', 'recusado'], true) ? (string)$_GET['estagio'] : '';
    $fIni    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
    $fFim    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';
    $fPessoa = (string)($_GET['pessoa'] ?? '');            /* "o:ID" (colaborador) | "t:ID" (terceirizado) */
    $page    = max(1, (int)($_GET['pg'] ?? 1));
    $perPage = 15;

    $where  = "a.tenant_id = :t";
    $params = [':t' => vero_tenant()];
    if ($fTalhao > 0) { $where .= " AND a.talhao_id = :ta";          $params[':ta'] = $fTalhao; }
    if ($fAtiv > 0)   { $where .= " AND a.tipo_atividade_id = :fa";  $params[':fa'] = $fAtiv; }
    if ($fStatus !== '') { $where .= " AND a.status = :fs";          $params[':fs'] = $fStatus; }
    if ($fIni !== '') { $where .= " AND a.data_apontamento >= :fi";  $params[':fi'] = $fIni; }
    if ($fFim !== '') { $where .= " AND a.data_apontamento <= :ff";  $params[':ff'] = $fFim; }
    if ($fSafra > 0) {
        $where .= " AND EXISTS (SELECT 1 FROM agro_safra_talhoes stf
                     WHERE stf.id = a.safra_talhao_id AND stf.tenant_id = a.tenant_id AND stf.safra_id = :fsa)";
        $params[':fsa'] = $fSafra;
    }
    if (preg_match('/^([ot]):(\d+)$/', $fPessoa, $mPes)) {
        $colPes = $mPes[1] === 'o' ? 'operador_id' : 'terceirizado_id';
        $where .= " AND EXISTS (SELECT 1 FROM rh_producao_itens pif
                     WHERE pif.tenant_id = a.tenant_id AND pif.apontamento_id = a.id AND pif.{$colPes} = :fpe)";
        $params[':fpe'] = (int)$mPes[2];
    } else {
        $fPessoa = '';
    }
    $total = (int)vero_val("SELECT COUNT(*) FROM " . T . " a WHERE {$where}", $params);
    $rows  = vero_rows(
        "SELECT a.id, a.data_apontamento, a.hectares, a.observacao,
                a.talhao_id, a.tipo_atividade_id,
                a.status, a.ordem_servico_id, os.numero AS os_numero,
                t.codigo AS talhao, f.nome AS fazenda,
                ta.nome AS atividade, s.identificacao AS safra,
                resp.nome AS responsavel,
                vf.nome AS fase_variedade, a.dias_desde_poda,
                (SELECT COUNT(*) FROM rh_producao_itens pi
                  WHERE pi.tenant_id = a.tenant_id AND pi.apontamento_id = a.id) AS pessoas,
                (SELECT COALESCE(SUM(pi.valor_total),0) FROM rh_producao_itens pi
                  WHERE pi.tenant_id = a.tenant_id AND pi.apontamento_id = a.id) AS total
           FROM " . T . " a
           JOIN agro_talhoes t ON t.id = a.talhao_id
           JOIN agro_fazendas f ON f.id = t.fazenda_id
           LEFT JOIN agro_operadores resp ON resp.id = a.responsavel_id
           LEFT JOIN agro_tipos_atividade ta ON ta.id = a.tipo_atividade_id
           LEFT JOIN agro_safra_talhoes st ON st.id = a.safra_talhao_id
           LEFT JOIN agro_safras s ON s.id = st.safra_id
           LEFT JOIN agro_ordens_servico os ON os.id = a.ordem_servico_id AND os.tenant_id = a.tenant_id
           LEFT JOIN agro_variedade_fases vf ON vf.id = a.variedade_fase_id AND vf.tenant_id = a.tenant_id
          WHERE {$where}
          ORDER BY a.data_apontamento DESC, a.id DESC
          LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
        $params);
    $talhoesFiltro = vero_rows(
        "SELECT t.id, CONCAT(f.nome, ' — ', t.codigo) AS label
           FROM agro_talhoes t JOIN agro_fazendas f ON f.id = t.fazenda_id
          WHERE t.tenant_id = :t ORDER BY f.nome, t.codigo",
        [':t' => vero_tenant()]);
    /* C-22: opções dos filtros novos */
    $ativsFiltro  = vero_rows("SELECT id, nome FROM agro_tipos_atividade WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => vero_tenant()]);
    $safrasFiltro = vero_rows("SELECT id, identificacao FROM agro_safras WHERE tenant_id = :t ORDER BY id DESC", [':t' => vero_tenant()]);
    $pessoasFiltro = array_merge(
        array_map(static fn($r) => ['v' => 'o:' . (int)$r['id'], 'n' => $r['nome'] . ' (colab.)'],
            vero_rows("SELECT id, nome FROM agro_operadores WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => vero_tenant()])),
        array_map(static fn($r) => ['v' => 't:' . (int)$r['id'], 'n' => $r['nome'] . ' (terc.)'],
            vero_rows("SELECT id, nome FROM rh_terceirizados WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => vero_tenant()])));
}

$GUARD      = ['macro' => 'agricola', 'micro' => 'apontamentos_campo'];
$PAGE_VIEW  = 'agricola_apontamentos_campo';
$PAGE_TITLE = 'Apontamentos de Campo';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.apontamentos_campo.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>

<?php if (!$modoForm): ?>
  <div class="vhead">
    <div>
      <h1>Apontamentos de Campo</h1>
      <div class="vsub">Operações por válvula com mão de obra (premiação, produção, diária) — cada linha emite custo no custeio</div>
    </div>
    <?php if ($podeEditar): ?>
      <a class="vbtn vbtn-primary" href="?novo=1">+ Novo apontamento</a>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <select name="talhao" onchange="this.form.submit()">
          <option value="">Todas as válvulas</option>
          <?php foreach ($talhoesFiltro as $tf): ?>
            <option value="<?= (int)$tf['id'] ?>"<?= $fTalhao === (int)$tf['id'] ? ' selected' : '' ?>><?= h($tf['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php /* C-22: filtros combináveis */ ?>
        <select name="atividade" onchange="this.form.submit()">
          <option value="">Todas as atividades</option>
          <?php foreach ($ativsFiltro as $af): ?>
            <option value="<?= (int)$af['id'] ?>"<?= $fAtiv === (int)$af['id'] ? ' selected' : '' ?>><?= h($af['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="pessoa" onchange="this.form.submit()">
          <option value="">Todas as pessoas</option>
          <?php foreach ($pessoasFiltro as $pf): ?>
            <option value="<?= h($pf['v']) ?>"<?= $fPessoa === $pf['v'] ? ' selected' : '' ?>><?= h($pf['n']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="estagio" onchange="this.form.submit()">
          <option value="">Todos os estágios</option>
          <option value="iniciado"<?= $fStatus === 'iniciado' ? ' selected' : '' ?>>Iniciado</option>
          <option value="pendente"<?= $fStatus === 'pendente' ? ' selected' : '' ?>>Finalizado (pendente)</option>
          <option value="validado"<?= $fStatus === 'validado' ? ' selected' : '' ?>>Validado</option>
          <option value="recusado"<?= $fStatus === 'recusado' ? ' selected' : '' ?>>Recusado</option>
        </select>
        <select name="safra" onchange="this.form.submit()">
          <option value="">Todas as safras</option>
          <?php foreach ($safrasFiltro as $sf): ?>
            <option value="<?= (int)$sf['id'] ?>"<?= $fSafra === (int)$sf['id'] ? ' selected' : '' ?>><?= h($sf['identificacao']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="ini" value="<?= h($fIni) ?>" title="De" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" title="Até" onchange="this.form.submit()">
        <?php if ($fTalhao || $fAtiv || $fPessoa || $fStatus || $fSafra || $fIni || $fFim): ?>
          <a class="vbtn vbtn-ghost vbtn-sm" href="?">Limpar filtros</a>
        <?php endif; ?>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum apontamento registrado. Clique em “+ Novo apontamento”.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Fazenda / Válvula</th><th>Estágio / OS</th><th>Safra</th><th>Atividade</th>
        <th>Fase</th>
        <th>Responsável</th>
        <th style="text-align:right">ha</th>
        <th style="text-align:right">Pessoas</th>
        <th style="text-align:right">Total (R$)</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php
        /* W-13: ícone para REABRIR a oferta de nova safra caso o modal tenha sido
           fechado sem querer. Só em apontamento de PODA cuja válvula está com a
           oferta ATIVA (cobertura ≥95% ou não mensurável). Cobertura memoizada por válvula. */
        $podaTipoIds    = apont_poda_tipo_ids();
        $podeAbrirSafra = vero_can('agro.safra.abrir') && vero_can('agro.safra.confirmar_poda');
        $cobMemo        = [];
        $icoSafra = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 20h10"/><path d="M10 20c5.5-2.5.8-6.4 3-10"/><path d="M9.5 9.4c1.1.8 1.8 2.2 2.3 3.7-2 .4-3.5.4-4.8-.3-1.2-.6-2.3-1.9-3-4.2 2.8-.5 4.4 0 5.5.8z"/><path d="M14.1 6a7 7 0 0 0-1.1 4c1.9-.1 3.3-.6 4.3-1.4 1-1 1.6-2.3 1.7-4.6-2.7.1-4 1-4.9 2z"/></svg>';
      ?>
      <?php foreach ($rows as $r): $iniciado = (string)$r['status'] === 'iniciado'; ?>
        <tr>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_apontamento'])) ?></td>
          <td><strong><?= h($r['fazenda']) ?> — <?= h($r['talhao']) ?></strong></td>
          <td>
            <?php if ($iniciado): ?>
              <span class="vbadge vb-warn">Iniciado</span>
            <?php elseif ((string)$r['status'] === 'validado'): ?>
              <span class="vbadge vb-ok">Validado</span>
            <?php elseif ((string)$r['status'] === 'recusado'): ?>
              <span class="vbadge vb-off">Recusado</span>
            <?php else: /* pendente = finalizado aguardando validação (C-22) */ ?>
              <span class="vbadge vb-warn">Finalizado (pendente)</span>
            <?php endif; ?>
            <?php if ($r['os_numero']): ?><div class="vhint vnum" style="white-space:nowrap"><?= h((string)$r['os_numero']) ?></div><?php endif; ?>
          </td>
          <td><?= h($r['safra'] ?? '') ?: '—' ?></td>
          <td><?= h($r['atividade'] ?? '') ?: '—' ?></td>
          <td><?php if ($r['fase_variedade'] !== null): ?><?= h($r['fase_variedade']) ?><?php if ($r['dias_desde_poda'] !== null): ?> <span class="vhint" style="white-space:nowrap">· <?= (int)$r['dias_desde_poda'] ?>d</span><?php endif; ?><?php else: ?>—<?php endif; ?></td>
          <td><?= h($r['responsavel'] ?? '') ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $r['hectares'] !== null ? numFmt((float)$r['hectares'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['pessoas'] ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['total'], 2) ?></strong></td>
          <td><div class="vactions">
            <?php if ($podeEditar && $iniciado): ?>
              <?= vero_btn_icone(vero_ico_check(), 'Finalizar (registrar o realizado)', '', '?editar=' . (int)$r['id']) ?>
            <?php elseif ($podeEditar): ?>
              <?= vero_btn_editar((int)$r['id']) ?>
            <?php endif; ?>
            <?php if (!empty($r['ordem_servico_id'])): ?>
              <?= vero_btn_icone(vero_ico_imprimir(), 'Imprimir OS' . ($r['os_numero'] ? ' ' . $r['os_numero'] : ''), '', BIOS_BASE . '/agro/os_impressao?id=' . (int)$r['ordem_servico_id']) ?>
            <?php endif; ?>
            <?php if (vero_can('agro.apontamentos_campo.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este apontamento? Itens e lançamentos de custeio serão removidos.') ?>
            <?php endif; ?>
            <?php
              if ($podeAbrirSafra && !$iniciado && in_array((int)$r['tipo_atividade_id'], $podaTipoIds, true)) {
                  $tid = (int)$r['talhao_id'];
                  if (!array_key_exists($tid, $cobMemo)) { $cobMemo[$tid] = apont_poda_cobertura($tid); }
                  $cb = $cobMemo[$tid];
                  if ($cb['cobertura'] === null || $cb['cobertura'] >= 0.95) {
                      $qsSafra = '?finalizado_poda=' . $tid . ($cb['dia_zero'] ? '&dia_zero=' . $cb['dia_zero'] : '');
                      echo vero_btn_icone($icoSafra, 'Reabrir oferta de nova safra (poda ≥95%)', '', $qsSafra);
                  }
              }
            ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>

  <?php /* EXTRA (W-13) — poda concluída (≥95%) → oferecer INICIAR NOVA SAFRA.
     Server-side: o "finalizar" de uma poda ≥95% redireciona com
     ?finalizado_poda=<talhao_id>&dia_zero=<Y-m-d>, disparando este modal.
     "Iniciar nova safra" POSTa em abertura_safra.php (acao=nova_safra_poda): abre
     a safra do semestre + confirma a poda da válvula (dia 0 = última planta podada)
     numa transação. "Salvar" apenas fecha — NÃO cria. Gatilho e dia 0 são
     recalculados/validados no servidor (o dia_zero da URL é só exibição). */
    $podaTid = (int)($_GET['finalizado_poda'] ?? 0);
    if ($podaTid > 0):
        $podaTal = vero_row(
            "SELECT t.codigo, f.nome AS fazenda FROM agro_talhoes t
               JOIN agro_fazendas f ON f.id = t.fazenda_id
              WHERE t.id = :i AND t.tenant_id = :t", [':i' => $podaTid, ':t' => vero_tenant()]);
        $podaLabel = $podaTal ? ($podaTal['fazenda'] . ' — ' . $podaTal['codigo']) : ('válvula #' . $podaTid);
        $podaDiaZero = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['dia_zero'] ?? '')) ? (string)$_GET['dia_zero'] : '';
        $podePoda = vero_can('agro.safra.abrir') && vero_can('agro.safra.confirmar_poda');
    ?>
  <div class="vmodal open" id="poda-safra-modal">
    <div class="vbox" style="max-width:470px">
      <header>
        <h2>🌱 Poda realizada</h2>
        <button class="vclose" type="button" onclick="vModalClose('poda-safra-modal')">×</button>
      </header>
      <div style="padding:10px 24px 20px;line-height:1.6">
        <p style="margin:0 0 12px;font-size:14px">Poda concluída na válvula <strong><?= h($podaLabel) ?></strong> — <strong>≥&nbsp;95%</strong> das plantas podadas.</p>
        <div style="background:#F4F1E9;border-radius:12px;padding:12px 14px;font-size:13px;color:#5A4F43">
          Deseja iniciar uma <strong>nova safra</strong> desta válvula? O <strong>dia&nbsp;0</strong> será
          <strong style="color:var(--accent,#005059)"><?= $podaDiaZero ? h(dateBR($podaDiaZero)) : 'a data da última planta podada' ?></strong>.
          <div style="margin-top:6px;color:#8A7C68">Escolha <em>Salvar</em> para não criar agora — o sistema não abre a safra sozinho.</div>
        </div>
        <?php /* P-09: a poda abre o ciclo — seu custo pertence à
                 nova safra, não à anterior. */ ?>
        <div style="margin-top:12px;background:#EAF3F0;border:1px solid #CFE0DD;border-radius:12px;padding:10px 14px;font-size:12.5px;color:#00363D">
          A poda é o <strong>início do novo ciclo</strong>: seus custos passam a contar na <strong>safra que se inicia</strong>, não na anterior.
        </div>
        <?php if (!$podePoda): ?>
          <p style="margin:12px 0 0;font-size:13px;color:#B57C1A">Você não tem permissão para abrir safra/confirmar poda — solicite ao gestor.</p>
        <?php endif; ?>
      </div>
      <div class="vform-actions" style="padding:14px 24px 20px;border-top:1px solid #EFE9DC">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('poda-safra-modal')">Salvar</button>
        <?php if ($podePoda): ?>
        <form method="post" action="<?= BIOS_BASE ?>/agro/abertura_safra" style="display:inline"
              data-confirm="Iniciar uma nova safra do semestre para esta válvula? O dia 0 será a data da última planta podada. Ação auditável."
              data-confirm-ok="Iniciar nova safra" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
          <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
          <input type="hidden" name="acao" value="nova_safra_poda">
          <input type="hidden" name="talhao_id" value="<?= $podaTid ?>">
          <input type="hidden" name="dia_zero" value="<?= h($podaDiaZero) ?>">
          <button class="vbtn vbtn-primary" type="submit">Iniciar nova safra</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>
  <?php if (!$podeEditar): ?>
    <div class="vflash vflash-erro">Sem permissão para registrar apontamentos.</div>
  <?php else:
    /* Estágio do form (2 estágios — mig. 167):
       - novo (!$edit): botão "Iniciar apontamento" (acao=iniciar), realizado OCULTO;
       - edição 'iniciado': botão "Finalizar apontamento" (acao=finalizar), realizado visível;
       - edição 'validado': botão "Salvar apontamento" (acao=salvar), correção (fluxo antigo). */
    $estagio = $edit ? (string)$edit['status'] : 'novo';
    $acaoForm = $edit ? ($estagio === 'iniciado' ? 'finalizar' : 'salvar') : 'iniciar';
    $btnLabel = $edit ? ($estagio === 'iniciado' ? 'Finalizar apontamento' : 'Salvar apontamento') : 'Iniciar apontamento';
    $mostraRealizado = $edit !== null; /* Pessoas só aparece ao editar (iniciado/validado); no Iniciar, só cabeçalho + calculadora */
    $tituloForm = !$edit ? 'Novo apontamento' : ($estagio === 'iniciado' ? 'Finalizar apontamento' : 'Editar apontamento');
    $osNumeroEdit = ($edit && $edit['ordem_servico_id'])
        ? (string)vero_val("SELECT numero FROM agro_ordens_servico WHERE id=:i AND tenant_id=:t",
            [':i' => (int)$edit['ordem_servico_id'], ':t' => vero_tenant()])
        : '';
  ?>
  <div class="vhead">
    <div>
      <h1><?= h($tituloForm) ?></h1>
      <div class="vsub"><?= !$edit
          ? 'Estágio 1 — grava o cabeçalho e gera a OS de campo. Pessoas e produção entram na finalização.'
          : ($estagio === 'iniciado'
              ? 'Estágio 2 — registre pessoas/produção reais; o custeio é emitido e a OS é concluída.'
              : 'Premiação do colaborador calculada pela regra vigente; terceirizado por produção ou diária. O servidor revalida todos os valores.') ?></div>
    </div>
    <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/agro/apontamentos">← Voltar à lista</a>
  </div>

  <?php if ($edit && $estagio === 'iniciado'): ?>
    <div class="vflash vflash-aviso">Apontamento iniciado<?= $osNumeroEdit ? ' — OS ' . h($osNumeroEdit) : '' ?>. Registre o realizado e finalize.</div>
  <?php endif; ?>

  <style>
    /* campos de contexto da válvula (só leitura, derivados do cadastro) */
    input.vro { background: var(--surface-2, #f3f4f6); color: var(--text-2, #4b5563);
                cursor: default; }
    input.vro::placeholder { color: var(--text-3, #9ca3af); }
  </style>
  <form method="post" id="apont-form">
    <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
    <input type="hidden" name="acao" value="<?= h($acaoForm) ?>">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
    <input type="hidden" name="planejamento_mo" id="planejamento_mo_json"><!-- V-01/V-02: snapshot da calc -->

    <div class="vcard" style="padding:18px 22px;margin-bottom:16px">
      <div class="vgrid" style="grid-template-columns:repeat(3,1fr)">
        <div class="vfield">
          <label>Data *</label>
          <input type="date" name="data_apontamento" required
                 value="<?= h($edit ? substr((string)$edit['data_apontamento'], 0, 10) : date('Y-m-d')) ?>">
        </div>
        <div class="vfield">
          <label><?= h(vero_a1_rotulo_area()) ?> *</label>
          <select name="talhao_id" id="f-talhao" required>
            <option value="">— Selecione —</option>
            <?php foreach ($talhoes as $t): ?>
              <option value="<?= (int)$t['id'] ?>" data-area="<?= h((string)$t['area_ha']) ?>"
                data-plantas="<?= h((string)($t['num_plantas'] ?? '')) ?>"
                data-variedade="<?= h((string)($t['variedade'] ?? '')) ?>"
                data-porta="<?= h((string)($t['porta_enxerto'] ?? '')) ?>"
                <?= $edit && (int)$edit['talhao_id'] === (int)$t['id'] ? ' selected' : '' ?>>
                <?= h($t['fazenda']) ?> — <?= h($t['codigo']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- item 1.2: contexto da válvula em CAMPOS próprios (plantas/variedade/porta-enxerto),
             só leitura, preenchidos pela válvula — NÃO entram no POST (sem name). -->
        <div class="vfield">
          <label>Nº de plantas</label>
          <input type="text" id="ctx-plantas" class="vro" readonly placeholder="—">
        </div>
        <div class="vfield">
          <label>Variedade</label>
          <input type="text" id="ctx-variedade" class="vro" readonly placeholder="—">
        </div>
        <div class="vfield">
          <label>Porta-enxerto</label>
          <input type="text" id="ctx-porta" class="vro" readonly placeholder="—">
        </div>
        <div class="vfield">
          <label>Safra (vínculo da válvula)</label>
          <select name="safra_talhao_id" id="f-safra">
            <option value="">— Sem safra —</option>
          </select>
        </div>
        <div class="vfield">
          <label>Tipo de atividade *</label>
          <select name="tipo_atividade_id" id="f-tipo" required>
            <option value="">— Selecione —</option>
          </select>
          <!-- A1-55: guard de desvio — aplicação é DF, não apontamento -->
          <div id="apont-df-aviso" class="vflash vflash-aviso" style="display:none;margin-top:6px">
            <strong>Aplicação de defensivo não é apontamento genérico.</strong>
            A pulverização sai como <strong>DF numerada</strong> (bula, operadores/EPI, validação do RT) —
            os efeitos no estoque/custeio acontecem na confirmação dela.
            <a class="vbtn vbtn-primary vbtn-sm" id="apont-df-link" href="#">Emitir DF de pulverização</a>
          </div>
        </div>
        <div class="vfield">
          <label>Fase fenológica</label>
          <select name="fase_ref" id="f-feno">
            <option value="">— Automática pela data —</option>
          </select>
          <!-- item 1.1: o campo é alimentado pela fenologia POR VARIEDADE (dias desde a
               poda), auto-selecionando a fase; catálogo por cultura só como fallback -->
          <div class="vhint" id="feno-hint">Pré-selecionada pela fenologia da variedade (dias desde a poda); ajustável.</div>
        </div>
        <div class="vfield">
          <label>Hectares trabalhados</label>
          <input type="text" name="hectares" id="f-ha" value="<?= $edit && $edit['hectares'] !== null ? numFmt((float)$edit['hectares'], 2) : '' ?>">
        </div>
        <?php $viaPack = $edit && (int)($edit['producao_via_packing'] ?? 0) === 1; ?>
        <label id="card-viapack" style="grid-column:1/-1;margin:0 0 6px;display:none;align-items:center;gap:10px;padding:6px 0;cursor:pointer">
          <input type="checkbox" name="producao_via_packing" id="chk-viapack" <?= $viaPack ? 'checked' : '' ?> onchange="toggleViaPack(this.checked)">
          <span>Produção das pessoas apurada no Packing</span>
        </label>
        <!-- (B) campos de PLANEJAMENTO da calculadora — logo após Hectares (largura total);
             o resto da calc (modo/contexto/resultado) fica no card abaixo -->
        <div style="grid-column:1/-1"><?= vero_calc_mo_campos_planejamento_html() ?></div>
        <div class="vfield">
          <label>Responsável pela frente *</label>
          <select name="responsavel_id" required>
            <option value="">— Selecione —</option>
            <?php foreach ($colaboradores as $co): ?>
              <option value="<?= (int)$co['id'] ?>"<?= $edit && (int)($edit['responsavel_id'] ?? 0) === (int)$co['id'] ? ' selected' : '' ?>><?= h($co['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield" style="grid-column:span 2">
          <label>Observação</label>
          <input type="text" name="observacao" value="<?= h($edit['observacao'] ?? '') ?>">
        </div>
      </div>
    </div>

    <?= vero_calc_mo_painel_html() /* seletor de modo + contexto + resultado; campos de planejamento subiram p/ após Hectares */ ?>
    <?php /* V-01/V-02: o planejamento da calc não pode se perder ao Iniciar — serializa no submit e restaura na edição */
    $planejMoSafe = ($edit && !empty($edit['planejamento_mo']))
        ? json_encode(json_decode((string)$edit['planejamento_mo'], true) ?: new stdClass(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
        : 'null'; ?>
    <script>
    (function () {
      var IDS = { total:'calc-mo-trab', unidade:'calc-mo-unid', base:'calc-mo-modo',
                  dias:'calc-mo-prazo', pessoas:'calc-mo-pessoas', meta:'calc-mo-meta', media:'calc-mo-media', premio:'calc-mo-premio' };
      var g = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
      /* serializa a calc no hidden ANTES de enviar (Iniciar/Finalizar) */
      var form = document.getElementById('apont-form');
      if (form) form.addEventListener('submit', function () {
        var pj = {}; Object.keys(IDS).forEach(function (k) { pj[k] = g(IDS[k]); });
        var h = document.getElementById('planejamento_mo_json'); if (h) h.value = JSON.stringify(pj);
      });
      /* restaura o planejamento salvo ao reabrir (edição/finalização) — roda após o init do painel */
      var PJ = <?= $planejMoSafe ?>;
      if (PJ && typeof PJ === 'object') document.addEventListener('DOMContentLoaded', function () {
        Object.keys(IDS).forEach(function (k) {
          var e = document.getElementById(IDS[k]);
          if (e && PJ[k] !== undefined && PJ[k] !== null && PJ[k] !== '') e.value = PJ[k];
        });
        var modo = document.getElementById('calc-mo-modo'); if (modo) modo.dispatchEvent(new Event('change', { bubbles: true }));
        var trab = document.getElementById('calc-mo-trab'); if (trab) trab.dispatchEvent(new Event('input', { bubbles: true }));
      });
    })();
    </script>

    <?php
    $viaPack = $edit && (int)($edit['producao_via_packing'] ?? 0) === 1;
    $prodPack = $viaPack ? vero_rows(
        "SELECT COALESCE(o.nome, tc.nome) AS pessoa, ri.origem_pessoa, ri.quantidade, ri.unidade, ri.valor_total
           FROM rh_producao_itens ri
           LEFT JOIN agro_operadores o  ON o.id = ri.operador_id
           LEFT JOIN rh_terceirizados tc ON tc.id = ri.terceirizado_id
          WHERE ri.tenant_id = :t AND ri.apontamento_id = :a ORDER BY ri.quantidade DESC, ri.id DESC",
        [':t' => vero_tenant(), ':a' => (int)$edit['id']]) : [];
    ?>
    <div class="vcard" id="card-pessoas-ro" style="margin-bottom:16px<?= $viaPack ? '' : ';display:none' ?>">
      <div class="vtoolbar"><strong style="font-size:14px">Pessoas (apurado no packing)</strong>
        <div style="flex:1"></div>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h(BIOS_BASE) ?>/packing/apontar_colheita">Abrir posto</a></div>
      <?php if (!$prodPack): ?>
        <div class="vempty">Nenhuma caixa lida ainda no packing para esta colheita.</div>
      <?php else: ?>
        <table class="vtable">
          <thead><tr><th>Colhedor</th><th>Vínculo</th><th style="text-align:right">Qtd</th><th style="text-align:right">Prêmio / Total (R$)</th></tr></thead>
          <tbody>
          <?php foreach ($prodPack as $pp): ?>
            <tr><td><strong><?= h((string)$pp['pessoa']) ?: '—' ?></strong></td>
              <td><span class="vbadge <?= $pp['origem_pessoa'] === 'terceirizado' ? 'vb-warn' : 'vb-info' ?>"><?= $pp['origem_pessoa'] === 'terceirizado' ? 'Terceirizado' : 'Colaborador' ?></span></td>
              <td class="vnum" style="text-align:right"><?= numFmt((float)$pp['quantidade'], 0) ?> <?= h((string)$pp['unidade']) ?></td>
              <td class="vnum" style="text-align:right"><?= numFmt((float)$pp['valor_total'], 2) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="vcard" id="card-pessoas" style="margin-bottom:16px<?= ($mostraRealizado && !$viaPack) ? '' : ';display:none' ?>">
      <div class="vtoolbar">
        <strong style="font-size:14px">Pessoas do apontamento</strong>
        <div class="vhint">Colaborador → premiação pela regra vigente · Terceirizado → produção ou diária</div>
        <div style="flex:1"></div>
        <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="addLinha('colaborador')">+ Colaborador</button>
        <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="addLinha('terceirizado')">+ Terceirizado</button>
      </div>
      <table class="vtable" id="linhas">
        <thead><tr>
          <th style="width:110px">Origem</th><th>Pessoa</th><th style="width:120px">Modalidade</th>
          <th style="width:80px;text-align:right" id="th-meta" title="Meta por pessoa (quantidade acima da meta gera prêmio) — informada aqui">Meta</th>
          <th style="width:110px;text-align:right" id="th-qtd">Quantidade</th>
          <th style="width:100px;text-align:right" title="Peso colhido por pessoa — só para atividades de colheita">Peso (kg)</th>
          <th style="width:130px;text-align:right">R$ unit. / diária</th>
          <th style="width:170px;text-align:right">Prêmio / Total (R$)</th>
          <th style="width:40px"></th>
        </tr></thead>
        <tbody id="linhas-body"></tbody>
        <tfoot><tr>
          <td colspan="7" style="text-align:right;font-weight:600">Total do apontamento</td>
          <td class="vnum" style="text-align:right;font-weight:700" id="total-geral">0,00</td>
          <td></td>
        </tr></tfoot>
      </table>
      <div class="vempty" id="linhas-vazio">Nenhuma pessoa lançada — use os botões acima.</div>
    </div>
    <script>
      function toggleViaPack(on){
        var ed=document.getElementById('card-pessoas'), ro=document.getElementById('card-pessoas-ro');
        if(ed) ed.style.display = on ? 'none' : '';
        if(ro) ro.style.display = on ? '' : 'none';
      }
    </script>

    <div class="vcard" id="card-insumos" style="margin-bottom:16px">
      <div class="vtoolbar">
        <strong style="font-size:14px">Insumos aplicados</strong>
        <div class="vhint">Baixa ao custo médio; perecíveis saem automaticamente do lote com vencimento mais próximo (FEFO)</div>
        <div style="flex:1"></div>
        <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="addInsumo()">+ Insumo</button>
      </div>
      <table class="vtable">
        <thead><tr>
          <th>Produto</th>
          <th style="width:130px;text-align:right">Quantidade</th>
          <th style="width:130px;text-align:right">Dose (opcional)</th>
          <th style="width:130px;text-align:right">Saldo disp.</th>
          <th style="width:150px;text-align:right">Custo estimado (R$)</th>
          <th style="width:40px"></th>
        </tr></thead>
        <tbody id="insumos-body"></tbody>
      </table>
      <div class="vempty" id="insumos-vazio">
        Nenhum insumo lançado<?= empty($produtos) ? ' — cadastre produtos em Estoque → Produtos e Insumos' : '' ?>.
      </div>
    </div>

    <div class="vcard" id="card-maquinas" style="margin-bottom:16px">
      <div class="vtoolbar">
        <strong style="font-size:14px">Máquinas e implementos</strong>
        <div class="vhint">Horas × custo-hora (snapshot do cadastro, editável) → custeio categoria máquinas; sem custo-hora grava só as horas</div>
        <div style="flex:1"></div>
        <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="addMaquina()">+ Máquina</button>
      </div>
      <table class="vtable">
        <thead><tr>
          <th>Máquina</th>
          <th style="width:110px;text-align:right">Horas</th>
          <th style="width:130px;text-align:right">Horímetro final</th>
          <th style="width:140px;text-align:right">Custo-hora (R$)</th>
          <th style="width:140px;text-align:right">Custo estimado (R$)</th>
          <th style="width:40px"></th>
        </tr></thead>
        <tbody id="maquinas-body"></tbody>
      </table>
      <div class="vempty" id="maquinas-vazio">
        Nenhuma máquina lançada<?= empty($maquinasOpt) ? ' — cadastre em Máquinas → Cadastro' : '' ?>.
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px">
      <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/agro/apontamentos">Cancelar</a>
      <button class="vbtn vbtn-primary" type="submit"><?= h($btnLabel) ?></button>
    </div>
  </form>

  <script>
  /* ── dados do servidor ─────────────────────────────────────── */
  const VINCULOS   = <?= jsvar(array_map(static fn($v) => [
      'id' => (int)$v['id'], 'talhao' => (int)$v['talhao_id'], 'cultura' => (int)$v['cultura_id'],
      'label' => $v['safra'] . ' · ' . $v['cultura'], 'area' => (float)$v['area_plantada_ha'],
      'variedade' => $v['variedade_id'] !== null ? (int)$v['variedade_id'] : null,
      'poda' => $v['data_poda'] ?: ($v['safra_inicio'] ?: null), /* dia 0 = poda; fallback início da safra */
  ], $vinculos)) ?>;
  <?php
  /* peso automático na COLHEITA: peso (kg) = qtd × peso da caixa/contentor.
     peso por válvula (cultura da variedade), com fallback ao default do tenant. */
  $pesoCaixaTenant = (float)(vero_val(
      "SELECT valor FROM tenant_parametros WHERE tenant_id=:t AND chave='colheita.peso_caixa_kg'",
      [':t' => vero_tenant()]) ?: 0);
  $pesoPorTalhao = [];
  foreach (vero_rows(
      "SELECT tl.id, cu.peso_unidade_kg AS caixa, cu.peso_contentor_kg AS contentor
         FROM agro_talhoes tl
         LEFT JOIN agro_variedades vr ON vr.id = tl.variedade_id AND vr.tenant_id = tl.tenant_id
         LEFT JOIN agro_culturas   cu ON cu.id = vr.cultura_id   AND cu.tenant_id = tl.tenant_id
        WHERE tl.tenant_id = :t", [':t' => vero_tenant()]) as $r) {
      $pesoPorTalhao[(int)$r['id']] = [
          'caixa'     => ($r['caixa']     !== null && $r['caixa']     !== '') ? (float)$r['caixa']     : null,
          'contentor' => ($r['contentor'] !== null && $r['contentor'] !== '') ? (float)$r['contentor'] : null,
      ];
  }
  ?>
  const PESO_CAIXA_TENANT = <?= jsvar($pesoCaixaTenant) ?>;
  const PESO_TALHAO       = <?= jsvar($pesoPorTalhao) ?>;
  const TIPOS      = <?= jsvar(array_map(static fn($t) => [
      'id' => (int)$t['id'], 'nome' => $t['nome'], 'unidade' => $t['unidade_padrao'],
      'categoria' => (string)$t['categoria'],
      'culturas' => $t['culturas'] ? array_map('intval', explode(',', $t['culturas'])) : [],
  ], $tipos)) ?>;
  /* A1-55: alvo do alerta MIP aberto por válvula (leva pre_alvo à DF) */
  const ALVO_ALERTA = <?= jsvar(array_column(vero_rows(
      "SELECT al.talhao_id, m.alvo_id FROM agro_alertas al
         JOIN mip_monitoramentos m ON m.id = al.origem_id
        WHERE al.tenant_id = :t AND al.categoria = 'mip' AND al.status = 'aberto'
          AND al.origem_tipo = 'mip_monitoramento' AND al.talhao_id IS NOT NULL
        ORDER BY al.id", [':t' => vero_tenant()]), 'alvo_id', 'talhao_id')) ?>;
  const APLIC_NOVO_URL = <?= jsvar(BIOS_BASE . '/mip/aplicacoes?novo=1') ?>;
  const FENOLOGIAS = <?= jsvar(array_map(static fn($f) => [
      'id' => (int)$f['id'], 'cultura' => (int)$f['cultura_id'],
      'label' => $f['codigo'] . ' — ' . $f['nome'] . ' (' . $f['escala'] . ')',
  ], $fenologias)) ?>;
  /* item 1.1: fases por variedade (dia 0 = poda), agrupadas por variedade_id —
     pré-preenchem a fase na tela pela data; { variedade_id: [{ini,fim,nome}] } */
  const VAR_FASES  = <?= jsvar($varFasesMap) ?>;
  const REGRAS     = <?= jsvar(array_map(static fn($r) => [
      'tipo' => (int)$r['tipo_atividade_id'],
      'cultura' => $r['cultura_id'] !== null ? (int)$r['cultura_id'] : null,
      'meta' => (float)$r['meta_qtd'], 'valor' => (float)$r['valor_acima_meta'],
      'ini' => $r['vigencia_inicio'], 'fim' => $r['vigencia_fim'],
  ], $regras)) ?>;
  const COLABS     = <?= jsvar(array_map(static fn($c) => ['id' => (int)$c['id'], 'nome' => $c['nome'], 'documento' => $c['documento'] ?? null], $colaboradores)) ?>;
  const TERCS      = <?= jsvar(array_map(static fn($c) => [
      'id' => (int)$c['id'], 'nome' => $c['nome'], 'modalidade' => $c['modalidade_padrao'],
      'diaria' => $c['valor_diaria'] !== null ? (float)$c['valor_diaria'] : null,
  ], $terceirizados)) ?>;
  const PRODUTOS   = <?= jsvar(array_map(static fn($p) => [
      'id' => (int)$p['id'],
      'nome' => $p['codigo'] . ' — ' . $p['nome']
                . ($p['prox_validade'] !== null ? ' · vence ' . date('d/m/Y', strtotime((string)$p['prox_validade'])) : ''),
      'unidade' => $p['unidade'],
      'saldo' => (float)$p['saldo'],
      'custo' => (float)$p['saldo'] > 0 ? round((float)$p['valor'] / (float)$p['saldo'], 4) : 0,
  ], $produtos)) ?>;
  const EDIT_INSUMOS = <?= jsvar(array_map(static fn($i) => [
      'produto' => (int)$i['produto_id'], 'qtd' => (float)$i['quantidade'],
      'dose' => $i['dose'] !== null ? (float)$i['dose'] : null,
  ], $editInsumos)) ?>;
  const EDIT_ITENS = <?= jsvar(array_map(static fn($i) => [
      'origem' => $i['origem_pessoa'],
      'pessoa' => $i['origem_pessoa'] === 'colaborador' ? (int)$i['operador_id'] : (int)$i['terceirizado_id'],
      'modalidade' => $i['modalidade'], 'qtd' => (float)$i['quantidade'],
      'peso' => $i['peso_kg'] !== null ? (float)$i['peso_kg'] : null,
      'valor' => (float)$i['valor_unitario'],
      'meta' => $i['meta_aplicada'] !== null ? (float)$i['meta_aplicada'] : null,
  ], $editItens)) ?>;
  const EDIT_SAFRA = <?= $edit && $edit['safra_talhao_id'] !== null ? (int)$edit['safra_talhao_id'] : 'null' ?>;
  const EDIT_TIPO  = <?= $edit && $edit['tipo_atividade_id'] !== null ? (int)$edit['tipo_atividade_id'] : 'null' ?>;
  const EDIT_FENO  = <?= $edit && $edit['fenologia_id'] !== null ? (int)$edit['fenologia_id'] : 'null' ?>;
  const EDIT_VARFASE = <?= $edit && ($edit['variedade_fase_id'] ?? null) !== null ? (int)$edit['variedade_fase_id'] : 'null' ?>;
  /* valor pré-selecionado do campo Fase: fase por variedade (v:) tem prioridade;
     senão o estágio por cultura (c:); senão vazio (— Automática pela data —) */
  const EDIT_FASE_REF = EDIT_VARFASE ? ('v:' + EDIT_VARFASE) : (EDIT_FENO ? ('c:' + EDIT_FENO) : '');
  const MAQUINAS   = <?= jsvar(array_map(static fn($m) => [
      'id' => (int)$m['id'], 'nome' => $m['nome'],
      'custo_hora' => $m['custo_hora'] !== null ? (float)$m['custo_hora'] : null,
      /* P-10: só 'trator' tem horímetro; horímetro atual pré-preenche o final */
      'tipo'      => (string)($m['tipo'] ?? ''),
      'horimetro' => $m['horimetro_atual'] !== null ? (float)$m['horimetro_atual'] : null,
  ], $maquinasOpt)) ?>;
  const EDIT_MAQUINAS = <?= jsvar(array_map(static function ($m) use ($abByMaq) {
      $ab = $abByMaq[(int)$m['maquina_id']] ?? null;
      return [
          'maquina' => (int)$m['maquina_id'], 'horas' => (float)$m['horas'],
          'custo_hora' => $m['custo_hora'] !== null ? (float)$m['custo_hora'] : null,
          'ab_litros' => $ab ? (float)$ab['litros'] : null,
          'ab_valor'  => $ab ? (float)$ab['valor_total'] : null,
      ];
  }, $editMaquinas)) ?>;
  const ULT_MON = <?= jsvar(array_column(array_map(static fn($m) => [
      'talhao' => (int)$m['talhao_id'],
      'txt' => $m['alvo'] . ' — índice ' . numFmt((float)$m['nivel_infestacao'], 2)
             . ($m['unidade'] ?? '%') . ' em ' . date('d/m/Y', strtotime((string)$m['data_monitoramento'])),
  ], $ultMon), null, 'talhao')) ?>;

  /* ── helpers ───────────────────────────────────────────────── */
  const $id = s => document.getElementById(s);
  const fmt = n => n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const dec = v => {
    v = String(v || '').trim();
    if (!v) return 0;
    if (v.includes(',')) v = v.replaceAll('.', '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(v)) v = v.replaceAll('.', '');
    const n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  };

  function culturaAtual() {
    const st = parseInt($id('f-safra').value || '0', 10);
    const v = VINCULOS.find(x => x.id === st);
    return v ? v.cultura : null;
  }
  function regraAtual() {
    const tipo = parseInt($id('f-tipo').value || '0', 10);
    const cult = culturaAtual();
    const data = document.querySelector('[name=data_apontamento]').value || '9999-12-31';
    const vig = r => (!r.ini || r.ini <= data) && (!r.fim || r.fim >= data);
    return REGRAS.find(r => r.tipo === tipo && r.cultura === cult && vig(r))
        || REGRAS.find(r => r.tipo === tipo && r.cultura === null && vig(r))
        || null;
  }

  /* ── selects encadeados do cabeçalho ───────────────────────── */
  function refreshSafras(keep) {
    const talhao = parseInt($id('f-talhao').value || '0', 10);
    const sel = $id('f-safra');
    sel.innerHTML = '<option value="">— Sem safra —</option>';
    const lista = VINCULOS.filter(v => v.talhao === talhao); /* já vem da MAIS RECENTE p/ a mais antiga */
    lista.forEach(v => sel.add(new Option(v.label, v.id)));
    sel.disabled = false;
    if (keep) sel.value = String(keep);
    else if (lista.length === 1) {
      /* A1-52: vínculo ÚNICO = seleciona e trava (hidden espelha p/ o POST) */
      sel.value = String(lista[0].id);
      sel.disabled = true;
    } else if (lista.length > 1) {
      sel.value = String(lista[0].id); /* pré-seleciona a vigente (mais recente) — editável */
    }
    let hid = $id('f-safra-hid');
    if (sel.disabled) {
      if (!hid) {
        hid = document.createElement('input');
        hid.type = 'hidden'; hid.name = 'safra_talhao_id'; hid.id = 'f-safra-hid';
        sel.name = 'safra_talhao_id_view';
        sel.parentNode.appendChild(hid);
      }
      hid.value = sel.value;
    } else if (hid) { hid.remove(); sel.name = 'safra_talhao_id'; }
    refreshTipos(EDIT_TIPO);
    refreshFenologias(EDIT_FASE_REF);
  }
  function refreshTipos(keep) {
    const cult = culturaAtual();
    const sel = $id('f-tipo');
    const atual = keep || parseInt(sel.value || '0', 10);
    sel.innerHTML = '<option value="">— Selecione —</option>';
    /* irrigação tem tela própria (módulo de Irrigação) — não aparece como opção aqui */
    TIPOS.filter(t => t.categoria !== 'irrigacao' && (!t.culturas.length || cult === null || t.culturas.includes(cult)))
         .forEach(t => sel.add(new Option(t.nome, t.id)));
    if (atual && [...sel.options].some(o => +o.value === atual)) sel.value = String(atual);
    recalcAll();
    toggleBlocosCategoria();
    if (typeof viaPackVisivel === 'function') viaPackVisivel();
  }
  /* item 1.1: o campo "Fase fenológica" é alimentado pela fenologia POR VARIEDADE
     (opções = fases da variedade; value 'v:<id>'), auto-selecionando a fase pelos
     DIAS DESDE A PODA. Sem fenologia por variedade → cai no catálogo por cultura
     (value 'c:<id>', comportamento A1-29). keep = value prefixado a preservar. */
  function refreshFenologias(keep) {
    const sel = $id('f-feno');
    const prev = keep || sel.value || '';
    const st = parseInt($id('f-safra').value || '0', 10);
    const v = VINCULOS.find(x => x.id === st);
    const hint = $id('feno-hint');
    sel.innerHTML = '<option value="">— Automática pela data —</option>';
    const fases = (v && v.variedade) ? VAR_FASES[v.variedade] : null;
    if (fases && fases.length) {
      fases.forEach(f => sel.add(new Option(f.nome, 'v:' + f.id)));
      /* preserva escolha explícita; senão auto-seleciona pela data (dias desde a poda) */
      let target = prev, msg;
      if (!target) {
        const r = faseVariedadeDiag(v, fases);
        if (r.fase) { target = 'v:' + r.fase.id; msg = 'Puxada da variedade: ' + r.fase.nome + ' · ' + r.dias + ' dias desde a poda (ajustável).'; }
        else msg = r.motivo;
      }
      if (target && [...sel.options].some(o => o.value === target)) sel.value = target;
      if (hint) hint.textContent = msg || 'Fase da variedade (ajustável).';
      return;
    }
    /* fallback: catálogo por cultura (A1-29) */
    const cult = culturaAtual();
    FENOLOGIAS.filter(f => cult === null || f.cultura === cult)
              .forEach(f => sel.add(new Option(f.label, 'c:' + f.id)));
    if (prev && [...sel.options].some(o => o.value === prev)) sel.value = prev;
    if (hint) hint.textContent = (v && v.variedade)
      ? 'A variedade desta válvula ainda não tem fenologia cadastrada — usando o catálogo por cultura.'
      : 'Selecione a válvula. Vazio = resolvida pelos períodos da safra.';
  }
  /* fase da variedade pela data (dias desde a poda) + MOTIVO quando não resolve —
     torna o "não puxou" diagnosticável no hint. */
  function faseVariedadeDiag(v, fases) {
    const dataEl = document.querySelector('[name=data_apontamento]');
    const data = dataEl ? dataEl.value : '';
    if (!v || !v.poda) return { fase: null, motivo: 'Válvula sem data de poda — confirme a poda em Abertura de Safra para puxar a fase.' };
    if (!data) return { fase: null, motivo: 'Informe a data do apontamento para puxar a fase.' };
    const d0 = String(v.poda).slice(0, 10), d1 = String(data).slice(0, 10);
    const dias = Math.floor((Date.parse(d1 + 'T00:00:00') - Date.parse(d0 + 'T00:00:00')) / 86400000);
    if (isNaN(dias)) return { fase: null, motivo: 'Datas inválidas.' };
    if (dias < 0) return { fase: null, dias, motivo: '' };
    const fase = fases.find(f => f.ini <= dias && dias < f.fim);
    if (!fase) return { fase: null, dias, motivo: dias + ' dias desde a poda — fora das fases cadastradas (0–' + fases[fases.length - 1].fim + ' dias).' };
    return { fase, dias, motivo: '' };
  }

  $id('f-talhao').addEventListener('change', () => {
    const opt = $id('f-talhao').selectedOptions[0];
    if (opt && opt.dataset.area && !$id('f-ha').value) {
      $id('f-ha').value = fmt(parseFloat(opt.dataset.area));
    }
    refreshSafras(null);
    ultMonBox();
    plantasBox();
  });
  /* item 1.2: contexto da válvula em CAMPOS próprios (Nº de plantas / Variedade /
     Porta-enxerto), só leitura, preenchidos pela válvula selecionada. Valores via
     .value (inputs) — sem HTML injetado, sem risco de XSS (lição A1-57). Não têm
     name → não entram no POST (derivados do cadastro da válvula). */
  function plantasBox() {
    const opt = $id('f-talhao').selectedOptions[0];
    const n = (opt && opt.dataset.plantas !== '' && opt.dataset.plantas !== undefined)
      ? parseInt(opt.dataset.plantas, 10) : null;
    $id('ctx-plantas').value   = (n !== null && !isNaN(n)) ? n.toLocaleString('pt-BR') : '';
    $id('ctx-variedade').value = (opt && opt.dataset.variedade) ? opt.dataset.variedade : '';
    $id('ctx-porta').value     = (opt && opt.dataset.porta) ? opt.dataset.porta : '';
  }
  /* T-09: Insumos e Máquinas só aparecem quando a categoria da atividade é
     irrigação (pulverização já vai pela DF); ocultos em tratos culturais,
     colheita e packing. Ocultar é só VISUAL — sem linhas adicionadas o POST
     desses blocos fica vazio naturalmente (o save já tolera linhas vazias). */
  function toggleBlocosCategoria() {
    const tipo = TIPOS.find(t => t.id === parseInt($id('f-tipo').value || '0', 10));
    /* irrigação (única categoria que exibia máquina/insumo) agora é bloqueada
       aqui e vai pro módulo de Irrigação; pulverização vai pela DF. Logo, no
       apontamento de campo os blocos de máquina/insumo não têm lugar. */
    const mostra = false;
    const ci = $id('card-insumos'), cm = $id('card-maquinas');
    if (ci) ci.style.display = mostra ? '' : 'none';
    if (cm) cm.style.display = mostra ? '' : 'none';
    rotuloQtdCat(tipo);
  }
  /* 5e: em tratos culturais e embalamento a "Quantidade" é o Nº feito
     (plantas / caixas conforme a unidade da atividade). Só rótulo — o dado
     e o cálculo não mudam. */
  function rotuloQtdCat(tipo) {
    const th = $id('th-qtd');
    if (!th) return;
    const cat = tipo ? tipo.categoria : '';
    const uni = tipo && tipo.unidade ? tipo.unidade : '';
    if (cat === 'trato_cultural' || cat === 'packing') {
      th.textContent = 'Nº feito';
      th.title = 'Nº feito' + (uni ? ' (' + uni + ')' : '');
    } else {
      th.textContent = 'Quantidade';
      th.title = '';
    }
  }
  /* A1-30: contexto do último monitoramento MIP da válvula */
  function ultMonBox() {
    const box = $id('ult-mon');
    if (!box) return; /* box do último monitoramento MIP removido da tela */
  }
  $id('f-safra').addEventListener('change', () => { refreshTipos(null); refreshFenologias(null); });
  $id('f-tipo').addEventListener('change', recalcAll);

  /* A1-55: categoria 'aplicacao' escolhida → troca o submit pelo caminho da DF */
  function apontGuardaDF() {
    const tipo = TIPOS.find(t => t.id === parseInt($id('f-tipo').value || '0', 10));
    const ehAplic = !!(tipo && tipo.categoria === 'aplicacao');
    const aviso = $id('apont-df-aviso');
    const btn = document.querySelector('#apont-form button[type="submit"], form.vform button[type="submit"]');
    aviso.style.display = ehAplic ? '' : 'none';
    if (btn) { btn.disabled = ehAplic; btn.title = ehAplic ? 'Aplicação sai como DF — use o botão do aviso' : ''; }
    if (ehAplic) {
      const tal = $id('f-talhao').value;
      /* leva válvula (posiciona o modal da DF) e alvo do alerta; a safra o
         destino resolve pelo próprio vínculo da válvula */
      let url = APLIC_NOVO_URL + (tal ? '&pre_talhao=' + tal : '');
      if (tal && ALVO_ALERTA[tal]) url += '&pre_alvo=' + ALVO_ALERTA[tal];
      $id('apont-df-link').href = url;
    }
  }
  /* C-09: coluna "Peso (kg)" é parâmetro de COLHEITA — na Poda e
     demais atividades ela some (o campo continua no POST das linhas já gravadas). */
  function pesoColuna() {
    const t = TIPOS.find(x => x.id === parseInt($id('f-tipo').value || '0', 10));
    const mostrar = !!(t && t.categoria === 'colheita');
    const tabela = $id('linhas');
    if (!tabela) return;
    tabela.querySelectorAll('thead tr, tbody tr').forEach(tr => {
      const cel = tr.children[5];                    /* 6ª coluna = Peso (kg) */
      if (cel) cel.style.display = mostrar ? '' : 'none';
    });
    const foot = tabela.querySelector('tfoot td');   /* mantém o total alinhado */
    if (foot) foot.colSpan = mostrar ? 7 : 6;
  }
  $id('f-tipo').addEventListener('change', pesoColuna);
  pesoColuna();

  /* Peso automático (colheita): peso (kg) = qtd × peso da caixa/contentor da válvula
     (fallback ao default do tenant). Editável — se o usuário digitar o peso, para de
     auto-calcular naquela linha. */
  function fatorPeso() {
    const t = TIPOS.find(x => x.id === parseInt($id('f-tipo').value || '0', 10));
    if (!t || t.categoria !== 'colheita') return 0;
    const u = t.unidade;
    if (u === 'kg') return 1;
    const pt = PESO_TALHAO[parseInt($id('f-talhao').value || '0', 10)] || {};
    if (u === 'contentor') return (pt && pt.contentor) || 0;
    return (pt && pt.caixa) || PESO_CAIXA_TENANT || 0;   /* caixa (padrão) */
  }
  function autoPeso(tr) {
    const pe = tr.querySelector('.l-peso');
    if (!pe || pe.dataset.manual === '1') return;
    const f = fatorPeso();
    if (f <= 0) return;
    const q = dec(tr.querySelector('.l-qtd').value);
    pe.value = q > 0 ? fmt(q * f) : '';
  }
  function recalcPesos() { document.querySelectorAll('#linhas-body tr').forEach(autoPeso); }
  $id('f-tipo').addEventListener('change', recalcPesos);
  $id('f-talhao').addEventListener('change', recalcPesos);

  /* flag "produção via packing" só aparece em atividade de COLHEITA */
  function viaPackVisivel() {
    const t = TIPOS.find(x => x.id === parseInt($id('f-tipo').value || '0', 10));
    const card = $id('card-viapack');
    if (card) card.style.display = (t && t.categoria === 'colheita') ? 'flex' : 'none';
  }
  $id('f-tipo').addEventListener('change', viaPackVisivel);
  /* trocar p/ NÃO-colheita (ação do usuário) desmarca o flag — não grava em outra atividade */
  $id('f-tipo').addEventListener('change', function () {
    const t = TIPOS.find(x => x.id === parseInt($id('f-tipo').value || '0', 10));
    if (!(t && t.categoria === 'colheita')) {
      const c = $id('chk-viapack'); if (c && c.checked) { c.checked = false; if (window.toggleViaPack) toggleViaPack(false); }
    }
  });
  viaPackVisivel();
  $id('f-tipo').addEventListener('change', apontGuardaDF);
  $id('f-tipo').addEventListener('change', toggleBlocosCategoria);
  $id('f-talhao').addEventListener('change', apontGuardaDF);
  document.querySelector('[name=data_apontamento]').addEventListener('change', recalcAll);
  /* mudar a data re-resolve a fase da variedade (dias desde a poda) no campo */
  document.querySelector('[name=data_apontamento]').addEventListener('change', () => refreshFenologias(null));

  /* ── linhas dinâmicas ──────────────────────────────────────── */
  function addLinha(origem, preset) {
    const tb = $id('linhas-body');
    const tr = document.createElement('tr');
    const pessoas = origem === 'colaborador' ? COLABS : TERCS;
    const optsPessoa = ['<option value="">— Selecione —</option>']
      .concat(pessoas.map(p => `<option value="${p.id}">${esc(p.nome)}</option>`)).join('');
    const modalHtml = origem === 'colaborador'
      ? '<span class="vbadge vb-info">Premiação</span><input type="hidden" name="l_modalidade[]" value="premiacao">'
      : `<select name="l_modalidade[]" class="l-modal">
           <option value="producao">Produção</option>
           <option value="diaria">Diária</option>
         </select>`;
    tr.innerHTML = `
      <td><span class="vbadge ${origem === 'colaborador' ? 'vb-ok' : 'vb-warn'}">${origem === 'colaborador' ? 'Colaborador' : 'Terceirizado'}</span>
          <input type="hidden" name="l_origem[]" value="${origem}"></td>
      <td><select name="l_pessoa[]" class="l-pessoa" required>${optsPessoa}</select>
          <div class="l-cpf vhint" style="font-size:11px;margin-top:2px"></div></td>
      <td>${modalHtml}</td>
      <td style="text-align:right">${origem === 'colaborador'
          ? '<input type="text" name="l_meta[]" class="l-meta" style="text-align:right;width:70px" placeholder="meta">'
          : '<span class="vhint">—</span><input type="hidden" name="l_meta[]" value="">'}</td>
      <td><input type="text" name="l_qtd[]" class="l-qtd" style="text-align:right" placeholder="0"></td>
      <td><input type="text" name="l_peso[]" class="l-peso" style="text-align:right" placeholder="—"></td>
      <td><input type="text" name="l_valor[]" class="l-valor" style="text-align:right" placeholder="${origem === 'colaborador' ? 'R$/unid' : '0,00'}"></td>
      <td class="vnum l-total" style="text-align:right">0,00</td>
      <td><button type="button" class="vclose" title="Remover" onclick="this.closest('tr').remove(); recalcAll()">×</button></td>`;
    tb.appendChild(tr);
    tr.querySelectorAll('input,select').forEach(el => el.addEventListener('input', () => { recalcLinha(tr); mostraCPF(tr); }));
    /* peso automático: recalcula ao mudar a qtd; se o usuário digitar o peso, vira manual */
    const _lq = tr.querySelector('.l-qtd'), _lpe = tr.querySelector('.l-peso');
    if (_lq)  _lq.addEventListener('input', () => autoPeso(tr));
    if (_lpe) _lpe.addEventListener('input', () => { _lpe.dataset.manual = '1'; });
    pesoColuna(); /* C-09: linha nova respeita a visibilidade do Peso p/ o tipo atual */
    /* V-02: nova linha de colaborador herda meta/premiação da calculadora (default editável) */
    if (origem === 'colaborador') {
      const _m = $id('calc-mo-meta'), _v = $id('calc-mo-premio');
      const _lm = tr.querySelector('.l-meta'), _lv = tr.querySelector('.l-valor');
      if (_m && _lm && !_lm.value && _m.value) _lm.value = _m.value;
      if (_v && _lv && !_lv.value && _v.value) _lv.value = _v.value;
    }
    if (preset) {
      tr.querySelector('.l-pessoa').value = String(preset.pessoa);
      if (origem === 'terceirizado') {
        tr.querySelector('.l-modal').value = preset.modalidade;
        tr.querySelector('.l-valor').value = fmt(preset.valor);
      } else { /* colaborador: meta + valor por linha (5.1) */
        if (preset.meta !== null && preset.meta !== undefined) tr.querySelector('.l-meta').value = String(preset.meta).replace('.', ',');
        if (preset.valor) tr.querySelector('.l-valor').value = fmt(preset.valor);
      }
      tr.querySelector('.l-qtd').value = String(preset.qtd).replace('.', ',');
      if (preset.peso !== null) { const _p = tr.querySelector('.l-peso'); _p.value = String(preset.peso).replace('.', ','); _p.dataset.manual = '1'; }
    } else if (origem === 'terceirizado') {
      tr.querySelector('.l-pessoa').addEventListener('change', () => {
        const p = TERCS.find(x => x.id === parseInt(tr.querySelector('.l-pessoa').value || '0', 10));
        if (p) {
          tr.querySelector('.l-modal').value = p.modalidade;
          if (p.modalidade === 'diaria' && p.diaria !== null) {
            tr.querySelector('.l-valor').value = fmt(p.diaria);
            if (!tr.querySelector('.l-qtd').value) tr.querySelector('.l-qtd').value = '1';
          }
          recalcLinha(tr);
        }
      });
    }
    recalcLinha(tr);
    mostraCPF(tr);
    autoPeso(tr);   /* preenche o peso se já houver qtd (e não for manual) */
  }

  /* 5c: CPF do colaborador na linha (discreto, só leitura — sem name, não posta).
     Terceirizado não tem esse campo → box vazio. */
  function fmtCPF(doc) {
    const d = String(doc || '').replace(/\D/g, '');
    if (d.length === 11) return d.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    return String(doc || '');
  }
  function mostraCPF(tr) {
    const box = tr.querySelector('.l-cpf');
    if (!box) return;
    const origem = tr.querySelector('[name="l_origem[]"]').value;
    if (origem !== 'colaborador') { box.textContent = ''; return; }
    const id = parseInt(tr.querySelector('.l-pessoa').value || '0', 10);
    const c = COLABS.find(x => x.id === id);
    box.textContent = (c && c.documento) ? 'CPF ' + fmtCPF(c.documento) : '';
  }

  function recalcLinha(tr) {
    const origem = tr.querySelector('[name="l_origem[]"]').value;
    const qtd = dec(tr.querySelector('.l-qtd').value);
    let total = 0;
    if (origem === 'colaborador') {
      /* 5.1: meta e valor vêm da LINHA (informados no apontamento, editáveis). */
      const meta  = dec(tr.querySelector('.l-meta') ? tr.querySelector('.l-meta').value : '');
      const valor = dec(tr.querySelector('.l-valor').value);
      total = Math.max(0, qtd - meta) * valor;
    } else {
      total = qtd * dec(tr.querySelector('.l-valor').value);
    }
    tr.querySelector('.l-total').textContent = fmt(total);
    recalcTotal();
  }
  function recalcAll() {
    document.querySelectorAll('#linhas-body tr').forEach(recalcLinha);
    recalcTotal();
  }
  function recalcTotal() {
    let soma = 0;
    document.querySelectorAll('#linhas-body .l-total').forEach(td => soma += dec(td.textContent));
    $id('total-geral').textContent = fmt(soma);
    $id('linhas-vazio').style.display = document.querySelectorAll('#linhas-body tr').length ? 'none' : '';
  }

  /* ── insumos ───────────────────────────────────────────────── */
  function addInsumo(preset) {
    const tb = document.getElementById('insumos-body');
    const tr = document.createElement('tr');
    const opts = ['<option value="">— Selecione —</option>']
      .concat(PRODUTOS.map(p => `<option value="${p.id}">${esc(p.nome)}</option>`)).join('');
    tr.innerHTML = `
      <td><select name="i_produto[]" class="i-prod" required>${opts}</select></td>
      <td><input type="text" name="i_qtd[]" class="i-qtd" style="text-align:right" placeholder="0"></td>
      <td><input type="text" name="i_dose[]" class="i-dose" style="text-align:right" placeholder="—"></td>
      <td class="vnum i-saldo" style="text-align:right">—</td>
      <td class="vnum i-custo" style="text-align:right">0,00</td>
      <td><button type="button" class="vclose" title="Remover" onclick="this.closest('tr').remove(); insumosVazio()">×</button></td>`;
    tb.appendChild(tr);
    const upd = () => {
      const p = PRODUTOS.find(x => x.id === parseInt(tr.querySelector('.i-prod').value || '0', 10));
      const q = dec(tr.querySelector('.i-qtd').value);
      tr.querySelector('.i-saldo').textContent = p ? fmt(p.saldo) + ' ' + p.unidade : '—';
      tr.querySelector('.i-custo').textContent = p ? fmt(q * p.custo) : '0,00';
      if (p && q > p.saldo) tr.querySelector('.i-saldo').style.color = '#9A3B2A';
      else tr.querySelector('.i-saldo').style.color = '';
    };
    tr.querySelectorAll('input,select').forEach(el => el.addEventListener('input', upd));
    if (preset) {
      tr.querySelector('.i-prod').value = String(preset.produto);
      tr.querySelector('.i-qtd').value = String(preset.qtd).replace('.', ',');
      if (preset.dose !== null) tr.querySelector('.i-dose').value = String(preset.dose).replace('.', ',');
    }
    upd();
    insumosVazio();
  }
  function insumosVazio() {
    document.getElementById('insumos-vazio').style.display =
      document.querySelectorAll('#insumos-body tr').length ? 'none' : '';
  }

  /* ── máquinas (A1-23) ──────────────────────────────────────── */
  function addMaquina(preset) {
    const tb = document.getElementById('maquinas-body');
    const tr = document.createElement('tr');
    const opts = ['<option value="">— Selecione —</option>']
      .concat(MAQUINAS.map(m => `<option value="${m.id}" data-custo="${m.custo_hora !== null ? m.custo_hora : ''}">${esc(m.nome)}</option>`)).join('');
    tr.innerHTML = `
      <td>
        <select name="m_maquina[]" class="m-maq" required>${opts}</select>
        <div style="margin-top:4px"><a href="#" class="m-fuel-link" style="font-size:12px;text-decoration:none">⛽ abastecimento desta operação</a></div>
        <div class="m-fuel" style="display:none;margin-top:6px;padding:6px 8px;border:1px dashed var(--v-border,#cbd5e1);border-radius:6px">
          <label style="font-size:12px">Litros <input type="text" name="m_ab_litros[]" class="m-ab-litros" style="width:68px;text-align:right" placeholder="0,0"></label>
          <label style="font-size:12px;margin-left:8px">Valor R$ <input type="text" name="m_ab_valor[]" class="m-ab-valor" style="width:80px;text-align:right" placeholder="0,00"></label>
          <div class="vhint" style="margin-top:4px">Atribui este abastecimento <strong>inteiro</strong> a esta operação (sai do rateio por horas). Abastecimento normal do dia → <strong>Máquinas → Abastecimentos</strong>.</div>
        </div>
      </td>
      <td><input type="text" name="m_horas[]" class="m-horas" style="text-align:right" placeholder="0,0"></td>
      <td><input type="text" name="m_horimetro_fim[]" class="m-horim" style="text-align:right" placeholder="—" title="Horímetro final — obrigatório em trator na conclusão"></td>
      <td><input type="text" name="m_custo_hora[]" class="m-custo" style="text-align:right" placeholder="sem custo"></td>
      <td class="vnum m-total" style="text-align:right">0,00</td>
      <td><button type="button" class="vclose" title="Remover" onclick="this.closest('tr').remove(); maquinasVazio()">×</button></td>`;
    tb.appendChild(tr);
    const upd = () => {
      const h = dec(tr.querySelector('.m-horas').value);
      const c = dec(tr.querySelector('.m-custo').value);
      tr.querySelector('.m-total').textContent = fmt(h * c);
    };
    /* P-10: horímetro final só p/ TRATOR; pré-preenche com o último conhecido
       (editável) e vira obrigatório. Outros tipos → campo travado/vazio (não é
       exigido nem gravado). Sempre submete (mesmo readonly) p/ alinhar os índices. */
    const updHorim = () => {
      const o = tr.querySelector('.m-maq').selectedOptions[0];
      const m = o ? MAQUINAS.find(x => x.id === parseInt(o.value || '0', 10)) : null;
      const hin = tr.querySelector('.m-horim');
      const isTrator = !!(m && m.tipo === 'trator');
      hin.readOnly = !isTrator;
      /* sem HTML5 required (o card de máquinas pode estar oculto por categoria →
         quebraria o submit); a obrigatoriedade é validada no servidor (P-10). */
      if (isTrator) {
        hin.placeholder = m.horimetro != null ? fmt(m.horimetro) : 'obrigatório';
        if (hin.value.trim() === '' && m.horimetro != null) hin.value = fmt(m.horimetro); /* último conhecido (ajustável) */
        hin.style.background = '';
      } else {
        hin.value = '';
        hin.placeholder = 'só trator';
        hin.style.background = 'var(--warm,#f6f3ee)';
      }
    };
    tr.querySelector('.m-maq').addEventListener('change', () => {
      const o = tr.querySelector('.m-maq').selectedOptions[0];
      const campo = tr.querySelector('.m-custo');
      if (o && o.dataset.custo !== '' && !campo.value) campo.value = fmt(parseFloat(o.dataset.custo));
      updHorim();
      upd();
    });
    tr.querySelector('.m-fuel-link').addEventListener('click', (e) => {
      e.preventDefault();
      const box = tr.querySelector('.m-fuel');
      box.style.display = box.style.display === 'none' ? '' : 'none';
    });
    tr.querySelectorAll('input').forEach(el => el.addEventListener('input', upd));
    if (preset) {
      tr.querySelector('.m-maq').value = String(preset.maquina);
      tr.querySelector('.m-horas').value = String(preset.horas).replace('.', ',');
      if (preset.custo_hora !== null) tr.querySelector('.m-custo').value = fmt(preset.custo_hora);
      if (preset.ab_litros != null) {
        tr.querySelector('.m-ab-litros').value = String(preset.ab_litros).replace('.', ',');
        tr.querySelector('.m-ab-valor').value = preset.ab_valor != null ? fmt(preset.ab_valor) : '';
        tr.querySelector('.m-fuel').style.display = '';
      }
    }
    updHorim();
    upd();
    maquinasVazio();
  }
  function maquinasVazio() {
    document.getElementById('maquinas-vazio').style.display =
      document.querySelectorAll('#maquinas-body tr').length ? 'none' : '';
  }

  /* ── bootstrap ─────────────────────────────────────────────── */
  refreshSafras(EDIT_SAFRA);
  EDIT_ITENS.forEach(i => addLinha(i.origem, i));
  EDIT_INSUMOS.forEach(i => addInsumo(i));
  EDIT_MAQUINAS.forEach(m => addMaquina(m));
  recalcTotal();
  insumosVazio();
  maquinasVazio();
  ultMonBox();
  plantasBox();
  toggleBlocosCategoria();

  /* A1-48a: chegada do Fluxo de Campo (?novo=1&pre_talhao=&pre_tipo=&pre_atividade=) —
     apontamento pré-preenchido do contexto emitido */
  (function () {
    const p = new URLSearchParams(location.search);
    if (!p.get('pre_talhao')) return;
    $id('f-talhao').value = p.get('pre_talhao');
    $id('f-talhao').dispatchEvent(new Event('change'));
    if (p.get('pre_tipo')) {
      $id('f-tipo').value = p.get('pre_tipo');
      $id('f-tipo').dispatchEvent(new Event('change'));
    }
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
