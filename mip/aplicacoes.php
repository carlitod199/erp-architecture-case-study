<?php
/* ============================================================
   VERO — MIP / Aplicações  (tela real — núcleo de agro_aplicacoes)
   Substitui o mock. Rota: /mip/aplicacoes.php
   Guard: mip.aplicacoes_defensivos
   Registro de aplicações (pulverização, fertirrigação, foliar,
   indutor, tratamento): itens consomem estoque por FEFO e emitem
   custeio (categoria insumos) idempotente por origem.
   REGRA 1: o sistema NÃO recomenda produto/dose/carência — a
   receita é do responsável técnico; validação registra o aceite.
   Os recortes (Fertirrigação, Aplicações Nutricionais) leem daqui.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/../custeio/_plano_map.php';       /* A3-T10: plano de contas no custeio */
require_once __DIR__ . '/../agro/_fenologia_helper.php';   /* A1-29: fase automática pela data */
require_once __DIR__ . '/../agro/_setor_espelho.php';      /* A1-36: modo unificado + rótulo (P-57) */
require_once __DIR__ . '/../agro/_faixas_clima_rt.php';    /* A1-34: faixas do RT — aviso (P-32) */
require_once __DIR__ . '/../pessoas/_ifa_helper.php';      /* A3-T22: avisos NR-31/RT (P-63: nunca trava) */

const TIPOS_APLIC = [
    'pulverizacao'     => 'Pulverização', /* C-18: sem "defensivo" no rótulo */
    'fertirrigacao'    => 'Fertirrigação',
    'foliar'           => 'Adubação foliar',
    'indutor_brotacao' => 'Indutor de brotação',
    'tratamento'       => 'Tratamento',
    'outro'            => 'Outro',
];

/* P-101 (A1-DF-refino, cliente 08/07): tipo de bico da ficha de pulverização —
   cores ISO 10625 (a cor indica a VAZÃO nominal). Whitelist p/ o select. */
const BICO_TIPOS = [
    'laranja'  => 'Laranja (01)',
    'verde'    => 'Verde (015)',
    'amarelo'  => 'Amarelo (02)',
    'lilas'    => 'Lilás (025)',
    'azul'     => 'Azul (03)',
    'vermelho' => 'Vermelho (04)',
    'marrom'   => 'Marrom (05)',
    'cinza'    => 'Cinza (06)',
    'branco'   => 'Branco (08)',
    'outro'    => 'Outro',
];
const FICHA_FILAS = ['A' => 'Fila A', 'B' => 'Fila B', 'AB' => 'Filas A e B'];

/* item 6.8 (mig 169): condição climática categórica do céu — whitelist p/ o select e leitura */
const CEU_CONDICOES = ['sol' => 'Sol', 'noite' => 'Noite', 'nublado' => 'Nublado', 'chuva' => 'Chuva'];

$t = vero_tenant();

function aplic_limpar_efeitos(int $aplicacaoId): void
{
    /* estorna saídas de estoque e remove custeio — usado em reedição/cancelamento */
    $movs = vero_rows(
        "SELECT * FROM estoque_movimentacoes
          WHERE tenant_id = :t AND origem_tipo = 'aplicacao' AND origem_id = :o",
        [':t' => vero_tenant(), ':o' => $aplicacaoId]);
    foreach ($movs as $mov) vero_srv_estoque_estornar_mov($mov);
    vero_pdo()->prepare(
        "DELETE FROM custeio_lancamentos WHERE tenant_id = ? AND origem_tipo = 'aplicacao' AND origem_id = ?")
        ->execute([vero_tenant(), $aplicacaoId]);
    /* multi-válvula: cada linha DB-29 do documento é origem própria no custeio
       ('aplicacao_valvula', origem_id = agro_aplicacao_valvulas.id). Este DELETE
       roda ANTES do delete+reinsert das linhas na reedição — a ordem importa. */
    vero_pdo()->prepare(
        "DELETE cl FROM custeio_lancamentos cl
           JOIN agro_aplicacao_valvulas av ON av.id = cl.origem_id AND av.tenant_id = cl.tenant_id
          WHERE cl.tenant_id = ? AND cl.origem_tipo = 'aplicacao_valvula' AND av.aplicacao_id = ?")
        ->execute([vero_tenant(), $aplicacaoId]);
}

/* Multi-válvula (aprovado 18/08): custeio RATEADO por área entre as válvulas do
   documento. A UNIQUE uq_lanc_origem (idempotência por origem) fica INTACTA:
   cada linha DB-29 vira origem própria — origem_tipo='aplicacao_valvula',
   origem_id = agro_aplicacao_valvulas.id (mesmo padrão do custeio_rateio_exec,
   que cria 1 execução por cota). Documento de 1 válvula não tem linhas DB-29 e
   segue no lançamento único origem 'aplicacao' de sempre. A soma dos rateios
   fecha com o total (diferença de arredondamento vai para a última válvula). */
function aplic_custeio_lancar(int $aplicacaoId, ?int $safraId, int $talhaoPrincipal, string $tipo, float $custoTotal, string $dataComp): void
{
    $t = vero_tenant();
    $base = [
        'safra_id'         => $safraId,
        'centro_custo_id'  => vero_srv_centro_custo('INS', 'Insumos'),
        'plano_conta_id'   => custeio_plano_conta_id('aplicacao', $tipo),
        'categoria'        => 'insumos',
        'valor'            => round($custoTotal, 2),
        'data_competencia' => $dataComp,
    ];
    $stDe = static function (int $talhaoId) use ($t, $safraId): ?int {
        if (!$safraId) return null;
        $st = vero_val("SELECT id FROM agro_safra_talhoes WHERE tenant_id=:t AND safra_id=:s AND talhao_id=:ta",
            [':t' => $t, ':s' => $safraId, ':ta' => $talhaoId]);
        return $st ? (int)$st : null;
    };
    $linhas = vero_rows(
        "SELECT av.id, s.talhao_id, av.area_ha FROM agro_aplicacao_valvulas av
           JOIN agro_setores s ON s.id = av.setor_id
          WHERE av.tenant_id = :t AND av.aplicacao_id = :a AND s.talhao_id IS NOT NULL
          ORDER BY av.id", [':t' => $t, ':a' => $aplicacaoId]);
    if (count($linhas) < 2) {
        vero_insert('custeio_lancamentos', $base + [
            'safra_talhao_id' => $stDe($talhaoPrincipal),
            'talhao_id'       => $talhaoPrincipal,
            'origem_tipo'     => 'aplicacao',
            'origem_id'       => $aplicacaoId,
        ]);
        return;
    }
    $areaSoma = 0.0;
    foreach ($linhas as $ln) $areaSoma += (float)($ln['area_ha'] ?? 0);
    $restante = round($custoTotal, 2);
    $ultima   = count($linhas) - 1;
    foreach ($linhas as $i => $ln) {
        $peso  = $areaSoma > 0 ? (float)($ln['area_ha'] ?? 0) / $areaSoma : 1 / count($linhas);
        $valor = $i === $ultima ? $restante : round($custoTotal * $peso, 2);
        $restante = round($restante - $valor, 2);
        if ($valor == 0.0) continue; /* válvula sem área num rateio por área */
        vero_insert('custeio_lancamentos', array_merge($base, [
            'safra_talhao_id' => $stDe((int)$ln['talhao_id']),
            'talhao_id'       => (int)$ln['talhao_id'],
            'origem_tipo'     => 'aplicacao_valvula',
            'origem_id'       => (int)$ln['id'],
            'valor'           => $valor,
        ]));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    /* V-08 (tela do preparador de calda): quando a confirmação é disparada de
       OUTRA tela (ex.: mip/preparo_calda.php), ela envia _retorno com o caminho
       interno da fila para o PRG voltar para lá. Whitelist: caminho interno,
       sem host e sem "..". Ausente => comportamento padrão (volta p/ esta tela). */
    $retorno = null;
    $rq = trim((string)($_POST['_retorno'] ?? ''));
    if ($rq !== '' && $rq[0] === '/' && !str_contains($rq, '..') && preg_match('#^/[A-Za-z0-9_./?=&%-]*$#', $rq)) {
        $retorno = $rq;
    }

    if ($acao === 'salvar') {
        vero_require('mip.aplicacoes_defensivos.editar');
        $id     = vero_int('id');
        $tipo   = (string)($_POST['tipo'] ?? 'pulverizacao');
        if (!isset(TIPOS_APLIC[$tipo])) $tipo = 'pulverizacao';
        $talhao = vero_int('talhao_id');
        /* ── A1-26 / C-11 (dois estágios, mig 167): o MODO é lido ANTES das datas.
           Na EMISSÃO de OS (DF/IF) a "Data realizada" NÃO existe — ela só é gravada
           na CONFIRMAÇÃO da execução (acao=confirmar); aqui manda a "Data prevista".
           No REGISTRO DIRETO (aplicação já executada/retroativa) a realizada segue
           obrigatória. Na edição, o STATUS do documento decide (planejada = OS). */
        $modo = ($_POST['modo'] ?? 'direto') === 'emitir' ? 'emitir' : 'direto';
        $ehEmissao = $modo === 'emitir';
        if ($id) {
            $stAtual = vero_val("SELECT status FROM agro_aplicacoes WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => $t]);
            $ehEmissao = $stAtual === 'planejada';
        }
        $dataPrevista = vero_date('data_prevista');
        if ($ehEmissao) {
            if ($dataPrevista === null) {
                vero_flash('erro', 'Informe a Data prevista para emitir a OS — a data realizada será registrada na confirmação da execução.');
                vero_redirect();
            }
            $data     = null;           /* realizada nasce na confirmação */
            $dataBase = $dataPrevista;  /* base p/ fenologia/monitoramento/bula */
        } else {
            $data     = vero_date('data') ?? date('Y-m-d');
            $dataBase = $data;
        }
        if (!$talhao) {
            vero_flash('erro', 'Válvula é obrigatório.');
            vero_redirect();
        }
        $talhaoRow = vero_row("SELECT * FROM agro_talhoes WHERE id=:i AND tenant_id=:t", [':i' => $talhao, ':t' => $t]);
        if (!$talhaoRow) {
            vero_flash('erro', 'Válvula inválido.');
            vero_redirect();
        }
        /* Multi-válvula: a MESMA calda pode cobrir outras válvulas.
           valvulas_extra[] traz os talhões adicionais; o PRINCIPAL segue sozinho no
           cabeçalho (fenologia, monitoramento e clima continuam dele). Todas as
           válvulas na MESMA fazenda — o documento DF/IF é numerado por fazenda. */
        $valvulasExtra = [];
        foreach (array_map('intval', (array)($_POST['valvulas_extra'] ?? [])) as $vx) {
            if ($vx <= 0 || $vx === $talhao || isset($valvulasExtra[$vx])) continue;
            $vxRow = vero_row("SELECT id, fazenda_id, area_ha FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
                [':i' => $vx, ':t' => $t]);
            if (!$vxRow) continue;
            if ((int)$vxRow['fazenda_id'] !== (int)$talhaoRow['fazenda_id']) {
                vero_flash('erro', 'Todas as válvulas da mesma calda precisam ser da MESMA fazenda — o documento DF/IF é numerado por fazenda. Emita outro documento para a outra fazenda.');
                vero_redirect();
            }
            $valvulasExtra[$vx] = $vxRow;
        }
        $safraId = vero_int('safra_id') ?: null;
        $guardSafra = vero_srv_custeio_pode_lancar($safraId); /* A3-T6 (P-06) */
        if (!$guardSafra['pode']) {
            vero_flash('erro', $guardSafra['motivo']);
            vero_redirect();
        }
        $safraTalhaoId = $safraId ? vero_val(
            "SELECT id FROM agro_safra_talhoes WHERE tenant_id=:t AND safra_id=:s AND talhao_id=:tal",
            [':t' => $t, ':s' => $safraId, ':tal' => $talhao]) : null;

        /* A1-17 / item 6.5 (gestor 17/07): MÚLTIPLAS máquinas por aplicação (uva usa
           trator + pulverizador juntos). Cada maquina_id é validada no tenant; a 1ª
           fica em agro_aplicacoes.maquina_id (compat) e TODAS vão para a junção
           agro_aplicacao_maquinas (mig 162). Dedupe preservando a ordem informada. */
        $maquinaIds = [];
        foreach (array_map('intval', (array)($_POST['maquina_ids'] ?? [])) as $mid) {
            if ($mid <= 0 || in_array($mid, $maquinaIds, true)) continue;
            if (!vero_val("SELECT id FROM maquinas WHERE id=:i AND tenant_id=:t", [':i' => $mid, ':t' => $t])) continue;
            $maquinaIds[] = $mid;
        }
        $maquinaId = $maquinaIds[0] ?? null;
        $rtId = vero_int('responsavel_tecnico_id');
        if ($rtId && !vero_val("SELECT id FROM agro_operadores WHERE id=:i AND tenant_id=:t", [':i' => $rtId, ':t' => $t])) $rtId = null;
        /* Opção B / gestor 17/07 (mig 165): o campo "Fase fenológica" vem PREFIXADO —
           'v:<id>' fase por VARIEDADE (autoritativa), 'c:<id>' estágio por cultura
           (fallback), '' automático pela data. Espelha agro/apontamentos.php (item 1.1).
           Por padrão a fase é AUTOMÁTICA da variedade da válvula (dias desde a poda);
           o usuário pode ajustar. fenologia_id (por cultura) segue como compat/fallback. */
        $faseRef     = vero_str('fase_ref', 20);
        $fenologiaId = null;
        $varFaseSel  = null;
        if (preg_match('/^c:(\d+)$/', (string)$faseRef, $mFase))      $fenologiaId = (int)$mFase[1];
        elseif (preg_match('/^v:(\d+)$/', (string)$faseRef, $mFase))  $varFaseSel  = (int)$mFase[1];
        if ($fenologiaId && !vero_val("SELECT id FROM agro_fenologia_estagios WHERE id=:i AND tenant_id=:t", [':i' => $fenologiaId, ':t' => $t])) $fenologiaId = null;
        if ($varFaseSel && !vero_val(
            "SELECT fa.id FROM agro_variedade_fases fa
               JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
              WHERE fa.id = :i AND fa.tenant_id = :t
                AND fe.status = 'aprovada' AND fe.ativo = 1 AND fa.ativo = 1",
            [':i' => $varFaseSel, ':t' => $t])) $varFaseSel = null;
        /* fase por variedade AUTORITATIVA: escolha manual (v:) ou o resolver pela data
           (dias desde a poda). O snapshot de dias congela o contexto (reaprovar a
           fenologia não reescreve o histórico). */
        $varFase   = vero_a1_fenologia_variedade_resolver(
            $safraTalhaoId ? (int)$safraTalhaoId : null, $safraId, $dataBase);
        $varFaseId = $varFaseSel ?: ($varFase ? (int)$varFase['id'] : null);
        $diasPoda  = $varFase ? (int)$varFase['dias'] : null;
        /* A1-29: fenologia_id (compat por cultura) automática pela data só quando NÃO
           há fase por variedade nem escolha manual de estágio */
        $fenoAuto = false;
        if (!$fenologiaId && !$varFaseId) {
            $fenologiaId = vero_a1_fenologia_por_data(
                $safraTalhaoId ? (int)$safraTalhaoId : null, $safraId, $dataBase);
            $fenoAuto = $fenologiaId !== null;
        }

        /* A1-28 (IF): bomba da fazenda + tempo/horas de irrigação */
        $bombaId = null; $horaIni = null; $horaFim = null; $tempoIrr = null;
        if ($tipo === 'fertirrigacao') {
            $bombaId = vero_int('bomba_id');
            if ($bombaId) {
                $okBomba = vero_val(
                    "SELECT b.id FROM agro_bombas b
                      WHERE b.id=:i AND b.tenant_id=:t AND b.ativo=1 AND b.fazenda_id=:f",
                    [':i' => $bombaId, ':t' => $t, ':f' => (int)$talhaoRow['fazenda_id']]);
                if (!$okBomba) {
                    vero_flash('erro', 'Bomba inválida ou de outra fazenda.');
                    vero_redirect();
                }
                /* compatibilidade bomba×válvulas da válvula: AVISO, não trava
                   (linhas de válvula formais chegam com a A1-26/DB-29) */
                $temVinculo = (int)vero_val(
                    "SELECT COUNT(*) FROM agro_bomba_valvulas bv
                       JOIN agro_setores s ON s.id = bv.setor_id
                      WHERE bv.tenant_id=:t AND bv.bomba_id=:b AND s.talhao_id=:ta",
                    [':t' => $t, ':b' => $bombaId, ':ta' => (int)$talhao]);
                $temQualquer = (int)vero_val(
                    "SELECT COUNT(*) FROM agro_bomba_valvulas WHERE tenant_id=:t AND bomba_id=:b",
                    [':t' => $t, ':b' => $bombaId]);
                if ($temQualquer > 0 && $temVinculo === 0) {
                    vero_flash('aviso', 'A bomba selecionada não está vinculada a nenhuma válvula desta válvula (confira em Irrigação → Bombas).');
                }
            }
            $hIni = vero_str('hora_inicio', 5);
            $hFim = vero_str('hora_fim', 5);
            if ($hIni !== null && preg_match('/^\d{2}:\d{2}$/', $hIni)) $horaIni = $dataBase . ' ' . $hIni . ':00';
            if ($hFim !== null && preg_match('/^\d{2}:\d{2}$/', $hFim)) $horaFim = $dataBase . ' ' . $hFim . ':00';
            if ($horaIni !== null && $horaFim !== null && $horaFim < $horaIni) {
                vero_flash('erro', 'Hora de fim da irrigação anterior à hora de início.');
                vero_redirect();
            }
            $tempoIrr = vero_dec('tempo_irrigacao_h');
        }

        /* A1-30: forma de aplicação (complemento DB-28) — IF é sempre fertirrigação */
        $forma = vero_str('forma_aplicacao', 20);
        if ($tipo === 'fertirrigacao') $forma = 'fertirrigacao';
        elseif ($forma !== null && !in_array($forma, ['drone', 'trator_pulverizador', 'costal'], true)) $forma = null;

        /* A1-30: justificativa MIP automática — ÚLTIMO monitoramento da válvula até
           a data (referência de registro; o detalhe/impresso exibem alvo+índice) */
        $monRefId = vero_val(
            "SELECT id FROM mip_monitoramentos
              WHERE tenant_id=:t AND talhao_id=:ta AND data_monitoramento <= :d
              ORDER BY data_monitoramento DESC, id DESC LIMIT 1",
            [':t' => $t, ':ta' => (int)$talhao, ':d' => $dataBase]) ?: null;

        /* A1-30: operadores/EPI (DB-33) */
        $opIds   = array_map('intval', (array)($_POST['op_operador'] ?? []));
        $opEpi   = (array)($_POST['op_epi'] ?? []);
        $opLav   = (array)($_POST['op_lavagem'] ?? []);
        $opCond  = (array)($_POST['op_condicao'] ?? []);
        $operadoresDoc = [];
        foreach ($opIds as $ix => $oid) {
            if ($oid <= 0 || isset($operadoresDoc[$oid])) continue;
            if (!vero_val("SELECT id FROM agro_operadores WHERE id=:i AND tenant_id=:t", [':i' => $oid, ':t' => $t])) continue;
            $operadoresDoc[$oid] = [
                'operador_id'  => $oid,
                'epi_codigo'   => trim((string)($opEpi[$ix] ?? '')) ?: null,
                'epi_lavagem'  => isset($opLav[$ix]) && $opLav[$ix] !== '' ? ((int)$opLav[$ix] === 1 ? 1 : 0) : null,
                'epi_condicao' => trim((string)($opCond[$ix] ?? '')) ?: null,
            ];
        }

        /* condição climática REGISTRADA (JSON) — nenhuma faixa é sugerida (Regra 1 / V-07).
           item 6.3 (gestor 17/07): vento/temp/umidade são AUTO-preenchidos no browser via
           Open-Meteo pela lat/lon do talhão; aqui apenas registramos o que veio (ajustável). */
        $vento = vero_dec('clima_vento_kmh');
        $temp  = vero_dec('clima_temperatura_c');
        $umid  = vero_dec('clima_umidade_pct');
        $climaJson = ($vento !== null || $temp !== null || $umid !== null)
            ? json_encode(['vento_kmh' => $vento, 'temperatura_c' => $temp, 'umidade_pct' => $umid], JSON_UNESCAPED_UNICODE)
            : null;

        /* item 6.8 (mig 169): condição climática CATEGÓRICA do céu — whitelist em PHP */
        $condicaoCeu = vero_str('condicao_ceu', 20);
        if ($condicaoCeu !== null && !in_array($condicaoCeu, ['sol', 'noite', 'nublado', 'chuva'], true)) $condicaoCeu = null;

        /* A1-34 (P-32): confronto com as faixas de referência REGISTRADAS pelo
           RT (Clima e Chuvas) — AVISO não bloqueante; a decisão é do RT */
        foreach (vero_a1_avisos_clima_rt($vento, $temp, $umid) as $ac) {
            vero_flash('aviso', '⚠ ' . $ac);
        }

        $prodIds    = array_map('intval', (array)($_POST['i_produto'] ?? []));
        $doses      = (array)($_POST['i_dose'] ?? []);
        $doseUn     = (array)($_POST['i_dose_un'] ?? []);
        $qtds       = (array)($_POST['i_qtd'] ?? []);
        $carencias  = (array)($_POST['i_carencia'] ?? []);
        $reentradas = (array)($_POST['i_reentrada'] ?? []);

        /* A1-26: $modo (OS emitida × registro direto) já foi lido no topo do
           handler junto com as datas — C-11: emissão não tem data realizada. */

        /* item 6.2 (gestor 17/07): o repeater "Válvulas da calda" (v_setor/v_area/v_volume)
           foi REMOVIDO — era redundante com a válvula do campo principal. Área e volume de
           calda passam a vir dos campos únicos (area_aplicada_ha / volume_calda_l). */

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            /* parametros_aplicacao (JSON DB-28): merge preservando chaves de
               outras vias — A1-28 só gerencia tempo_irrigacao_h (IF) */
            $paramsJson = null;
            $paramsAtuais = [];
            if ($id) {
                $atual = vero_val("SELECT parametros_aplicacao FROM agro_aplicacoes WHERE id=:i AND tenant_id=:t",
                    [':i' => (int)$id, ':t' => $t]);
                if ($atual) $paramsAtuais = json_decode((string)$atual, true) ?: [];
            }
            if ($tipo === 'fertirrigacao' && $tempoIrr !== null) $paramsAtuais['tempo_irrigacao_h'] = $tempoIrr;
            elseif (array_key_exists('tempo_irrigacao_h', $paramsAtuais) && ($tipo !== 'fertirrigacao' || $tempoIrr === null)) unset($paramsAtuais['tempo_irrigacao_h']);

            /* A1-30: parâmetros da via (chaves whitelisted por forma — DB-28) */
            $chavesDrone  = ['faixa_m', 'velocidade_ms', 'gota_micras', 'altura_m'];
            $chavesTrator = ['mancha', 'velocidade', 'bico', 'horimetro_inicial', 'horimetro_final'];
            foreach (array_merge($chavesDrone, $chavesTrator) as $ch) unset($paramsAtuais[$ch]);
            if ($forma === 'drone') {
                foreach ($chavesDrone as $ch) {
                    $v = vero_dec('p_' . $ch);
                    if ($v !== null) $paramsAtuais[$ch] = $v;
                }
            } elseif ($forma === 'trator_pulverizador') {
                foreach (['mancha', 'bico'] as $ch) {
                    $v = vero_str('p_' . $ch, 60);
                    if ($v !== null) $paramsAtuais[$ch] = $v;
                }
                foreach (['velocidade', 'horimetro_inicial', 'horimetro_final'] as $ch) {
                    $v = vero_dec('p_' . $ch);
                    if ($v !== null) $paramsAtuais[$ch] = $v;
                }
            }
            /* P-101 (A1-DF-refino): ficha de pulverização (cabeçalho) — tipo de bico
               e filas A/B. Registro do RT (Regra 1: o sistema guarda o que o RT
               informou, nunca recomenda). Chaves whitelisted.
               C-04: dose_ha/dose_100l saíram do FORM (dose é por
               produto) — valores legados em params são PRESERVADOS (fora do unset). */
            foreach (['bico_tipo', 'filas'] as $ch) unset($paramsAtuais[$ch]);
            $fBico = vero_str('f_bico_tipo', 20);
            if ($fBico !== null && isset(BICO_TIPOS[$fBico]))   $paramsAtuais['bico_tipo'] = $fBico;
            /* W-11 (Wallace 21/07): "Filas" vira NÚMERO de filas por válvula
               (informativo, ~30-50), não mais o select A/B/AB. Aceita valor livre
               curto; legados A/B/AB ainda EXIBEM pelo fallback em FICHA_FILAS. */
            $fFilas = vero_str('f_filas', 10);
            if ($fFilas !== null && $fFilas !== '') $paramsAtuais['filas'] = $fFilas;

            if ($paramsAtuais) $paramsJson = json_encode($paramsAtuais, JSON_UNESCAPED_UNICODE);

            $dados = [
                'tipo'          => $tipo,
                'fazenda_id'    => (int)$talhaoRow['fazenda_id'],
                'talhao_id'     => (int)$talhao,
                'safra_id'      => $safraId,
                'variedade_id'  => $talhaoRow['variedade_id'] !== null ? (int)$talhaoRow['variedade_id'] : null,
                'fenologia_id'  => $fenologiaId,
                'variedade_fase_id' => $varFaseId,
                'dias_desde_poda'   => $diasPoda,
                /* C-11: na emissão a realizada é NULL — preenchida na confirmação.
                   TRANSITÓRIO até a mig 177 rodar no ambiente (coluna `data` ainda
                   NOT NULL): grava a PREVISTA como placeholder; a exibição/impresso
                   tratam documentos 'planejada' como sem data realizada. */
                'data'          => $data ?? (
                    (string)vero_val("SELECT IS_NULLABLE FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agro_aplicacoes'
                          AND COLUMN_NAME='data'") === 'YES' ? null : $dataPrevista),
                'data_prevista' => $dataPrevista,
                'maquina_id'    => $maquinaId,
                'responsavel_tecnico_id' => $rtId,
                'condicao_climatica'     => $climaJson,
                'condicao_ceu'           => $condicaoCeu,
                'area_aplicada_ha'       => vero_dec('area_aplicada_ha'),
                'volume_calda_l'         => vero_dec('volume_calda_l'),
                'volume_calda_ha_l'      => vero_dec('volume_calda_ha_l'),
                'bomba_id'               => $bombaId,
                'executada_inicio'       => $horaIni,
                'executada_fim'          => $horaFim,
                'parametros_aplicacao'   => $paramsJson,
                'forma_aplicacao'        => $forma,
                'monitoramento_id'       => $monRefId ? (int)$monRefId : null,
                'observacao' => vero_str('observacao', 255),
                'status'     => 'registrada',
            ];

            /* A1-26: status-alvo e efeitos — emissão (planejada) não mexe em
               estoque/custeio; edição preserva o status atual do documento */
            if ($id) {
                $ap = vero_row("SELECT * FROM agro_aplicacoes WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]);
                if (!$ap) throw new RuntimeException('Aplicação inválida.');
                if ($ap['status'] === 'cancelada') throw new RuntimeException('Aplicação cancelada não pode ser editada.');
                if ($ap['status'] === 'validada') throw new RuntimeException('Documento validado pelo RT não pode ser editado.');
                if ($ap['doc_serie'] !== null
                    && vero_srv_doc_serie_por_tipo($tipo) !== (string)$ap['doc_serie']) {
                    throw new RuntimeException('Não é possível trocar entre DF e IF num documento já numerado — cancele e emita outro.');
                }
                $statusAlvo = (string)$ap['status']; // planejada continua emitida; registrada reprocessa
                $dados['status'] = $statusAlvo;
                aplic_limpar_efeitos((int)$id);
                $pdo->prepare("DELETE FROM agro_aplicacao_itens WHERE tenant_id=? AND aplicacao_id=?")
                    ->execute([$t, (int)$id]);
                vero_update('agro_aplicacoes', (int)$id, $dados);
            } else {
                $statusAlvo = $modo === 'emitir' ? 'planejada' : 'registrada';
                $dados['status'] = $statusAlvo;
                /* numeração DF/IF por fazenda NA EMISSÃO (P-46 — helper atômico A0) */
                $serie = vero_srv_doc_serie_por_tipo($tipo);
                $dados['doc_serie']  = $serie;
                $dados['doc_numero'] = vero_srv_doc_numero((int)$talhaoRow['fazenda_id'], $serie);
                $id = vero_insert('agro_aplicacoes', $dados);
            }
            $comEfeitos = $statusAlvo === 'registrada';

            /* item 6.5: múltiplas máquinas — delete+reinsert na junção (tenant-safe;
               maquina_id do cabeçalho = 1ª máquina p/ compat) */
            $pdo->prepare("DELETE FROM agro_aplicacao_maquinas WHERE tenant_id=? AND aplicacao_id=?")
                ->execute([$t, (int)$id]);
            foreach ($maquinaIds as $mid) {
                vero_insert('agro_aplicacao_maquinas', ['aplicacao_id' => (int)$id, 'maquina_id' => $mid]);
            }

            /* Multi-válvula → linhas DB-29 (mesmo padrão delete+reinsert das máquinas).
               Só grava linhas quando o documento tem MAIS de uma válvula — documento
               simples fica sem linhas, como sempre (item 6.2). Cada linha aponta o
               setor-ESPELHO do talhão (A1-35); área = cadastral; volume da linha =
               calda L/ha × área (ou rateio por área do total digitado à mão). */
            $pdo->prepare("DELETE FROM agro_aplicacao_valvulas WHERE tenant_id=? AND aplicacao_id=?")
                ->execute([$t, (int)$id]);
            $valvulasDoc = [(int)$talhao => $talhaoRow] + $valvulasExtra;
            if (count($valvulasDoc) > 1) {
                $rateHa   = vero_dec('volume_calda_ha_l');
                $volTotal = vero_dec('volume_calda_l');
                $areaSoma = 0.0;
                foreach ($valvulasDoc as $vd) $areaSoma += (float)($vd['area_ha'] ?? 0);
                foreach ($valvulasDoc as $vtId => $vd) {
                    $setorId = vero_a1_setor_espelho_id((int)$vtId);
                    if (!$setorId) continue; /* talhão sem setor — linha fica de fora, cabeçalho segue */
                    $aHa = $vd['area_ha'] !== null ? (float)$vd['area_ha'] : null;
                    $vol = null;
                    if ($rateHa !== null && $aHa !== null)                          $vol = round($rateHa * $aHa, 2);
                    elseif ($volTotal !== null && $aHa !== null && $areaSoma > 0)   $vol = round($volTotal * $aHa / $areaSoma, 2);
                    vero_insert('agro_aplicacao_valvulas', [
                        'aplicacao_id'   => (int)$id,
                        'setor_id'       => (int)$setorId,
                        'area_ha'        => $aHa,
                        'volume_calda_l' => $vol,
                    ]);
                }
            }

            /* A1-30: operadores/EPI (delete+reinsert na edição — assinatura fica p/ o app, P-48) */
            $pdo->prepare("DELETE FROM agro_aplicacao_operadores WHERE tenant_id=? AND aplicacao_id=?")
                ->execute([$t, (int)$id]);
            foreach ($operadoresDoc as $od) {
                $od['aplicacao_id'] = (int)$id;
                vero_insert('agro_aplicacao_operadores', $od);
            }

            $almox = $comEfeitos ? vero_srv_almox_padrao() : null;
            $custoTotal = 0.0;
            $itensGravados = 0;
            $avisosBula = [];
            foreach ($prodIds as $ix => $prodId) {
                if ($prodId <= 0) continue;
                $qtd = vero_dec_valor((string)($qtds[$ix] ?? ''));
                if ($qtd === null || $qtd <= 0) {
                    throw new RuntimeException($comEfeitos
                        ? 'Informe a quantidade consumida de todos os produtos.'
                        : 'Informe a quantidade PREVISTA de todos os produtos (base do cálculo por tanque).');
                }
                $prod = vero_row("SELECT * FROM estoque_produtos WHERE id=:i AND tenant_id=:t", [':i' => $prodId, ':t' => $t]);
                if (!$prod) continue;

                $saida = null;
                if ($comEfeitos) {
                    $saida = vero_srv_estoque_saida($prodId, $almox, $qtd, $dataBase, 'aplicacao', (int)$id,
                        'Aplicação ' . TIPOS_APLIC[$tipo], $safraTalhaoId ? (int)$safraTalhaoId : null);
                }

                /* carência/reentrada INFORMADAS (Regra 1); vazias = CÓPIA da bula
                   registrada pelo RT no produto (auto-preenchimento é registro) */
                $car = trim((string)($carencias[$ix] ?? ''));
                $ree = trim((string)($reentradas[$ix] ?? ''));
                $carencia = ($car !== '' && ctype_digit($car)) ? (int)$car
                    : ($prod['carencia_dias'] !== null ? (int)$prod['carencia_dias'] : null);

                /* snapshot de bula (DB-30) — imutabilidade documental na emissão */
                $nutrientes = vero_rows(
                    "SELECT nutriente, percentual FROM estoque_produto_nutrientes
                      WHERE tenant_id = :t AND produto_id = :p ORDER BY nutriente",
                    [':t' => $t, ':p' => $prodId]);
                $nutriJson = $nutrientes
                    ? json_encode(array_column($nutrientes, 'percentual', 'nutriente'), JSON_UNESCAPED_UNICODE)
                    : null;

                vero_insert('agro_aplicacao_itens', [
                    'aplicacao_id'         => (int)$id,
                    'produto_id'           => $prodId,
                    'dose_valor'           => vero_dec_valor((string)($doses[$ix] ?? '')),
                    'dose_unidade'         => trim((string)($doseUn[$ix] ?? '')) ?: null,
                    'quantidade_consumida' => $qtd,
                    'quantidade_unidade'   => $prod['unidade'] ?? null,
                    'custo_unitario'       => $saida['custo_unitario'] ?? null,
                    'custo_total'          => $saida['custo_total'] ?? null,
                    'carencia_dias'              => $carencia,
                    'intervalo_reentrada_horas'  => ($ree !== '' && ctype_digit($ree)) ? (int)$ree : null,
                    'intervalo_aplicacoes_dias'  => $prod['intervalo_aplicacoes_dias'] !== null ? (int)$prod['intervalo_aplicacoes_dias'] : null,
                    'num_max_aplicacoes'         => $prod['num_max_aplicacoes'] !== null ? (int)$prod['num_max_aplicacoes'] : null,
                    'lmr_dias'                   => $prod['lmr_dias'] !== null ? (int)$prod['lmr_dias'] : null,
                    'nutrientes_snapshot'        => $nutriJson,
                ]);
                if ($saida) $custoTotal += (float)$saida['custo_total'];
                $itensGravados++;

                /* avisos de bula (não travam — decisão do RT, P-50) */
                if ($safraId && $prod['num_max_aplicacoes'] !== null) {
                    $usoSafra = (int)vero_val(
                        "SELECT COUNT(DISTINCT a2.id) FROM agro_aplicacoes a2
                           JOIN agro_aplicacao_itens i2 ON i2.aplicacao_id = a2.id AND i2.tenant_id = a2.tenant_id
                          WHERE a2.tenant_id = :t AND a2.safra_id = :s AND i2.produto_id = :p
                            AND a2.status <> 'cancelada'",
                        [':t' => $t, ':s' => $safraId, ':p' => $prodId]);
                    if ($usoSafra > (int)$prod['num_max_aplicacoes']) {
                        $avisosBula[] = $prod['nome'] . ': ' . $usoSafra . 'ª aplicação na safra (bula registra máx. '
                            . (int)$prod['num_max_aplicacoes'] . ')';
                    }
                }
                if ($prod['intervalo_aplicacoes_dias'] !== null) {
                    $ultima = vero_val(
                        "SELECT MAX(a3.data) FROM agro_aplicacoes a3
                           JOIN agro_aplicacao_itens i3 ON i3.aplicacao_id = a3.id AND i3.tenant_id = a3.tenant_id
                          WHERE a3.tenant_id = :t AND a3.talhao_id = :ta AND i3.produto_id = :p
                            AND a3.status <> 'cancelada' AND a3.id <> :id",
                        [':t' => $t, ':ta' => (int)$talhao, ':p' => $prodId, ':id' => (int)$id]);
                    if ($ultima) {
                        $diff = (int)floor((strtotime($dataBase) - strtotime((string)$ultima)) / 86400);
                        if ($diff >= 0 && $diff < (int)$prod['intervalo_aplicacoes_dias']) {
                            $avisosBula[] = $prod['nome'] . ': última aplicação na válvula há ' . $diff
                                . ' dia(s) (bula registra intervalo de ' . (int)$prod['intervalo_aplicacoes_dias'] . ')';
                        }
                    }
                }
            }

            if ($comEfeitos && $custoTotal > 0) {
                /* multi-válvula: rateia por área entre as válvulas do documento */
                aplic_custeio_lancar((int)$id, $safraId, (int)$talhao, $tipo, $custoTotal, $dataBase);
            }
            vero_update('agro_aplicacoes', (int)$id, [
                'custo_total' => $comEfeitos ? round($custoTotal, 2) : null,
                'estoque_baixado' => $comEfeitos && $itensGravados > 0 ? 1 : 0,
                'custeio_lancado' => $comEfeitos && $custoTotal > 0 ? 1 : 0,
            ]);
            $pdo->commit();

            $docRef = vero_row("SELECT doc_serie, doc_numero FROM agro_aplicacoes WHERE id=:i AND tenant_id=:t",
                [':i' => (int)$id, ':t' => $t]);
            $docTxt = ($docRef && $docRef['doc_serie']) ? $docRef['doc_serie'] . $docRef['doc_numero'] : '#' . $id;
            if ($comEfeitos) {
                vero_flash('ok', "Documento {$docTxt} registrado com {$itensGravados} produto(s) — custo R$ "
                    . numFmt($custoTotal, 2) . ' (estoque baixado por FEFO). Validação do RT pendente.'
                    . ($fenoAuto ? ' Fase fenológica preenchida automaticamente pela data.' : ''));
            } else {
                vero_flash('ok', "OS {$docTxt} EMITIDA com {$itensGravados} produto(s) — sem baixa de estoque. "
                    . 'Após a execução no campo, use "Confirmar execução" para registrar quantidades reais, datas/horas e clima.'
                    . ($fenoAuto ? ' Fase fenológica preenchida automaticamente pela data.' : ''));
            }
            foreach ($avisosBula as $ab) vero_flash('aviso', '⚠ ' . $ab . ' — avaliação do RT.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    /* ── A1-26: CONFIRMAÇÃO pós-aplicação — quantidades REAIS + datas/horas +
       clima real + tríplice lavagem; aqui acontecem a baixa FEFO e o custeio ── */
    if ($acao === 'confirmar') {
        vero_require('mip.aplicacoes_defensivos.editar');
        $id = vero_int('id');
        $ap = $id ? vero_row("SELECT * FROM agro_aplicacoes WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if (!$ap || $ap['status'] !== 'planejada') {
            vero_flash('erro', 'Só documentos EMITIDOS (aguardando execução) podem ser confirmados.');
            vero_redirect();
        }
        $guardSafra = vero_srv_custeio_pode_lancar($ap['safra_id'] !== null ? (int)$ap['safra_id'] : null); /* A3-T6 */
        if (!$guardSafra['pode']) {
            vero_flash('erro', $guardSafra['motivo']);
            vero_redirect();
        }
        $dataReal = vero_date('data') ?? date('Y-m-d');
        $hIni = vero_str('hora_inicio', 5);
        $hFim = vero_str('hora_fim', 5);
        $horaIni = ($hIni !== null && preg_match('/^\d{2}:\d{2}$/', $hIni)) ? $dataReal . ' ' . $hIni . ':00' : null;
        $horaFim = ($hFim !== null && preg_match('/^\d{2}:\d{2}$/', $hFim)) ? $dataReal . ' ' . $hFim . ':00' : null;
        if ($horaIni !== null && $horaFim !== null && $horaFim < $horaIni) {
            vero_flash('erro', 'Hora de término anterior à hora de início.');
            vero_redirect('?confirmar=' . (int)$id);
        }
        $ceu = vero_str('conf_ceu', 10);
        if ($ceu !== null && !in_array($ceu, ['noite', 'sol', 'nublado', 'chuva'], true)) $ceu = null;
        $ventoCl = vero_str('conf_vento_class', 10);
        if ($ventoCl !== null && !in_array($ventoCl, ['brisa', 'moderado', 'forte'], true)) $ventoCl = null;

        $itens = vero_rows("SELECT * FROM agro_aplicacao_itens WHERE tenant_id=:t AND aplicacao_id=:a ORDER BY id",
            [':t' => $t, ':a' => (int)$id]);
        if (!$itens) {
            vero_flash('erro', 'Documento sem produtos — edite a emissão antes de confirmar.');
            vero_redirect();
        }
        $cQtd = (array)($_POST['c_qtd'] ?? []);

        /* A1-30: operadores da execução (podem vir da emissão ou serem informados aqui) */
        $opIds  = array_map('intval', (array)($_POST['op_operador'] ?? []));
        $opEpi  = (array)($_POST['op_epi'] ?? []);
        $opLav  = (array)($_POST['op_lavagem'] ?? []);
        $opCond = (array)($_POST['op_condicao'] ?? []);
        $operadoresConf = [];
        foreach ($opIds as $ix => $oid) {
            if ($oid <= 0 || isset($operadoresConf[$oid])) continue;
            if (!vero_val("SELECT id FROM agro_operadores WHERE id=:i AND tenant_id=:t", [':i' => $oid, ':t' => $t])) continue;
            $operadoresConf[$oid] = [
                'aplicacao_id' => (int)$id,
                'operador_id'  => $oid,
                'epi_codigo'   => trim((string)($opEpi[$ix] ?? '')) ?: null,
                'epi_lavagem'  => isset($opLav[$ix]) && $opLav[$ix] !== '' ? ((int)$opLav[$ix] === 1 ? 1 : 0) : null,
                'epi_condicao' => trim((string)($opCond[$ix] ?? '')) ?: null,
            ];
        }

        /* V-08 (preparador de calda): trator/pulverizador NUMERADOS atribuídos na
           hora do preparo. A OS (DF/IF) foi emitida pelo RT SEM máquina; o preparador
           define aqui. Só toca a junção se o POST trouxer maquina_ids[] (fluxo do
           preparador) — a confirmação pela tela de detalhe NÃO envia e preserva o
           que já houver. Cada id é validado no tenant; dedupe preservando a ordem. */
        $maquinaIdsConf = [];
        $temMaquinasPost = array_key_exists('maquina_ids', $_POST);
        if ($temMaquinasPost) {
            foreach (array_map('intval', (array)$_POST['maquina_ids']) as $mid) {
                if ($mid <= 0 || in_array($mid, $maquinaIdsConf, true)) continue;
                if (!vero_val("SELECT id FROM maquinas WHERE id=:i AND tenant_id=:t", [':i' => $mid, ':t' => $t])) continue;
                $maquinaIdsConf[] = $mid;
            }
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            /* regra da análise §3.5.2 (nota da auditoria A1-26): confirmação exige ≥1 operador */
            if ($operadoresConf) {
                $pdo->prepare("DELETE FROM agro_aplicacao_operadores WHERE tenant_id=? AND aplicacao_id=?")
                    ->execute([$t, (int)$id]);
                foreach ($operadoresConf as $od) vero_insert('agro_aplicacao_operadores', $od);
            }
            /* V-08: grava as máquinas atribuídas pelo preparador (delete+reinsert na
               junção mig 162; maquina_id do cabeçalho = 1ª máquina p/ compat). Só
               quando o POST trouxe o campo — preserva o cabeçalho nos demais fluxos. */
            if ($temMaquinasPost) {
                $pdo->prepare("DELETE FROM agro_aplicacao_maquinas WHERE tenant_id=? AND aplicacao_id=?")
                    ->execute([$t, (int)$id]);
                foreach ($maquinaIdsConf as $mid) {
                    vero_insert('agro_aplicacao_maquinas', ['aplicacao_id' => (int)$id, 'maquina_id' => $mid]);
                }
            }
            $totalOps = (int)vero_val(
                "SELECT COUNT(*) FROM agro_aplicacao_operadores WHERE tenant_id=:t AND aplicacao_id=:a",
                [':t' => $t, ':a' => (int)$id]);
            if ($totalOps === 0) {
                throw new RuntimeException('A confirmação exige pelo menos 1 operador identificado (exigência de certificação — informe no bloco Operadores/EPI).');
            }

            $almox = vero_srv_almox_padrao();
            $custoTotal = 0.0;
            foreach ($itens as $item) {
                $qtdReal = vero_dec_valor((string)($cQtd[(int)$item['id']] ?? ''));
                if ($qtdReal === null) $qtdReal = (float)$item['quantidade_consumida']; // default: prevista
                if ($qtdReal <= 0) throw new RuntimeException('Quantidade real inválida em um dos produtos.');
                $saida = vero_srv_estoque_saida((int)$item['produto_id'], $almox, $qtdReal, $dataReal,
                    'aplicacao', (int)$id, 'Aplicação ' . (TIPOS_APLIC[(string)$ap['tipo']] ?? $ap['tipo']),
                    $ap['safra_id'] !== null ? (vero_val(
                        "SELECT id FROM agro_safra_talhoes WHERE tenant_id=:t AND safra_id=:s AND talhao_id=:ta",
                        [':t' => $t, ':s' => (int)$ap['safra_id'], ':ta' => (int)$ap['talhao_id']]) ?: null) : null);
                vero_update('agro_aplicacao_itens', (int)$item['id'], [
                    'quantidade_consumida' => $qtdReal,
                    'custo_unitario'       => $saida['custo_unitario'],
                    'custo_total'          => $saida['custo_total'],
                ]);
                $custoTotal += (float)$saida['custo_total'];
            }
            if ($custoTotal > 0) {
                /* multi-válvula: rateia por área entre as válvulas do documento */
                aplic_custeio_lancar((int)$id, $ap['safra_id'] !== null ? (int)$ap['safra_id'] : null,
                    (int)$ap['talhao_id'], (string)$ap['tipo'], $custoTotal, $dataReal);
            }
            /* confirmação (JSON DB-28) — inclui destino da sobra de calda (CB 7.5) */
            $conf = [
                'vento_kmh_real'      => vero_dec('conf_vento_kmh'),
                'pluviosidade_mm'     => vero_dec('conf_pluviosidade_mm'),
                'ceu'                 => $ceu,
                'vento_class'         => $ventoCl,
                'destino_sobra_calda' => vero_str('conf_destino_sobra', 160),
                'obs'                 => vero_str('conf_obs', 255),
            ];
            vero_update('agro_aplicacoes', (int)$id, [
                'data'             => $dataReal,
                'maquina_id'       => $temMaquinasPost ? ($maquinaIdsConf[0] ?? null) : $ap['maquina_id'],
                'executada_inicio' => $horaIni ?? $ap['executada_inicio'],
                'executada_fim'    => $horaFim ?? $ap['executada_fim'],
                'confirmacao'      => json_encode($conf, JSON_UNESCAPED_UNICODE),
                'triplice_lavagem' => vero_int('triplice_lavagem') ? 1 : 0,
                'custo_total'      => round($custoTotal, 2),
                'estoque_baixado'  => 1,
                'custeio_lancado'  => $custoTotal > 0 ? 1 : 0,
                'status'           => 'registrada',
            ]);
            $pdo->commit();
            $docTxt = $ap['doc_serie'] ? $ap['doc_serie'] . $ap['doc_numero'] : '#' . $id;
            /* A3-T22 (P-63: AVISAR, nunca travar): NR-31 dos operadores da execução */
            foreach (array_unique(array_filter($opIds)) as $avOpId) {
                $stNr = ifa_treinamento_status((int)$avOpId);
                if (in_array($stNr['status'], ['vencido', 'nunca'], true)) {
                    $avNome = (string)(vero_val("SELECT nome FROM agro_operadores WHERE id=:i AND tenant_id=:t",
                        [':i' => (int)$avOpId, ':t' => $t]) ?? ('#' . $avOpId));
                    vero_flash('aviso', '⚠ NR-31 de ' . $avNome
                        . ($stNr['status'] === 'nunca' ? ' NÃO CONSTA' : ' VENCIDA em ' . date('d/m/Y', strtotime((string)$stNr['vence_em'])))
                        . ' — programe o treinamento (registro segue válido; P-63).');
                }
            }
            vero_flash('ok', "Execução do documento {$docTxt} CONFIRMADA — estoque baixado por FEFO pelas quantidades reais (custo R$ "
                . numFmt($custoTotal, 2) . '). Validação do RT pendente.');
            /* A1-34 (P-32): vento REAL confrontado com a faixa do RT — aviso */
            foreach (vero_a1_avisos_clima_rt($conf['vento_kmh_real'], null, null) as $ac) {
                vero_flash('aviso', '⚠ ' . $ac);
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect($retorno); /* V-08: volta p/ a fila do preparador quando veio de lá */
    }

    if ($acao === 'validar') {
        vero_require('mip.aplicacoes_defensivos.editar');
        $id = vero_int('id');
        $ap = $id ? vero_row("SELECT * FROM agro_aplicacoes WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        /* A1-53: falha de validação nunca é silenciosa — orienta o caminho */
        if ($ap && $ap['status'] === 'planejada') {
            vero_flash('erro', 'Este documento ainda está EMITIDO — antes de validar, use "Confirmar execução" (a confirmação exige as quantidades reais e PELO MENOS 1 OPERADOR identificado no bloco Operadores/EPI — exigência de certificação).');
            vero_redirect();
        }
        if ($ap && !in_array($ap['status'], ['registrada'], true)) {
            vero_flash('erro', 'Documento em status "' . $ap['status'] . '" não pode ser validado (só aplicações REGISTRADAS aguardam validação do RT).');
            vero_redirect();
        }
        if ($ap && $ap['status'] === 'registrada') {
            vero_update('agro_aplicacoes', (int)$id, [
                'status' => 'validada', 'validado_por' => vero_uid(), 'validado_em' => date('Y-m-d H:i:s'),
            ]);
            vero_flash('ok', 'Aplicação validada pelo responsável.');
            /* A3-T22: registro formal do RT da aplicação (aviso-não-trava — P-63/IFA v6) */
            if (!empty($ap['responsavel_tecnico_id'])) {
                $stRt = ifa_rt_status((int)$ap['responsavel_tecnico_id']);
                if ($stRt['status'] === 'sem_registro') {
                    vero_flash('aviso', '⚠ O RT desta aplicação não tem REGISTRO FORMAL cadastrado (Pessoas → Responsáveis Técnicos) — exigência IFA v6.');
                } elseif ($stRt['status'] === 'vencido') {
                    vero_flash('aviso', '⚠ Registro ' . $stRt['rotulo'] . ' do RT está VENCIDO desde '
                        . date('d/m/Y', strtotime((string)$stRt['validade'])) . ' — renove (validação segue registrada).');
                }
            }
        }
        vero_redirect();
    }

    if ($acao === 'excluir') { /* cancelamento com estorno */
        vero_require('mip.aplicacoes_defensivos.excluir');
        $id = vero_int('id');
        $ap = $id ? vero_row("SELECT * FROM agro_aplicacoes WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if ($ap && $ap['status'] !== 'cancelada') {
            $pdo = vero_pdo();
            $pdo->beginTransaction();
            try {
                aplic_limpar_efeitos((int)$id);
                vero_update('agro_aplicacoes', (int)$id, ['status' => 'cancelada', 'estoque_baixado' => 0, 'custeio_lancado' => 0]);
                $pdo->commit();
                vero_flash('ok', 'Aplicação cancelada — estoque estornado e custeio removido.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                vero_flash('erro', $e->getMessage());
            }
        }
        vero_redirect();
    }
}

/* helper local: decimal pt-BR (reusa a heurística de vero_dec sem $_POST) */
function vero_dec_valor(string $s): ?float
{
    $s = trim($s);
    if ($s === '') return null;
    if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    return is_numeric($s) ? (float)$s : null;
}

$fTipo  = (string)($_GET['tipo'] ?? '');
$fSerie = (string)($_GET['serie'] ?? '');
$where  = "ap.tenant_id = :t";
$params = [':t' => $t];
if (isset(TIPOS_APLIC[$fTipo])) { $where .= " AND ap.tipo = :tp"; $params[':tp'] = $fTipo; }
if (in_array($fSerie, ['DF', 'IF'], true)) { $where .= " AND ap.doc_serie = :sr"; $params[':sr'] = $fSerie; }

$rows = vero_rows(
    "SELECT ap.*, tl.codigo AS talhao, fz.nome AS fazenda, sa.identificacao AS safra, u.nome AS validador,
            (SELECT COUNT(*) FROM agro_aplicacao_itens i WHERE i.tenant_id = ap.tenant_id AND i.aplicacao_id = ap.id) AS itens
       FROM agro_aplicacoes ap
       LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = COALESCE(ap.fazenda_id, tl.fazenda_id)
       LEFT JOIN agro_safras sa ON sa.id = ap.safra_id
       LEFT JOIN usuarios u ON u.id = ap.validado_por
      WHERE {$where}
      ORDER BY COALESCE(ap.data, ap.data_prevista) DESC, ap.id DESC LIMIT 100", $params);

$talhoes = vero_rows(
    "SELECT t.id, t.codigo, t.area_ha, t.latitude, t.longitude, t.geometria, t.num_plantas, f.nome AS fazenda FROM agro_talhoes t
      LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
     WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo", [':t' => $t]);
/* D-3: dose de fertirrigação pode ser g/planta — nº de plantas
   por válvula p/ o cálculo (dose × nº plantas). */
$numPlantasMap = [];
foreach ($talhoes as $tl2) $numPlantasMap[(int)$tl2['id']] = $tl2['num_plantas'] !== null ? (int)$tl2['num_plantas'] : null;
/* Ponto do talhão p/ o clima (Open-Meteo): usa latitude/longitude do cadastro;
   se vazias mas há polígono desenhado, deriva o CENTRO do polígono (média do
   anel externo, GeoJSON [lng,lat]) — assim o clima puxa mesmo sem ponto. */
$talhaoPonto = [];
foreach ($talhoes as $tl) {
    $lat = $tl['latitude'] !== null ? (float)$tl['latitude'] : null;
    $lon = $tl['longitude'] !== null ? (float)$tl['longitude'] : null;
    if (($lat === null || $lon === null) && !empty($tl['geometria'])) {
        $g = json_decode((string)$tl['geometria'], true);
        $ring = null;
        if (isset($g['type'], $g['coordinates'])) {
            if ($g['type'] === 'Polygon')           $ring = $g['coordinates'][0] ?? null;
            elseif ($g['type'] === 'MultiPolygon')  $ring = $g['coordinates'][0][0] ?? null;
        }
        if (is_array($ring) && count($ring) >= 3) {
            $sx = 0.0; $sy = 0.0; $n = 0;
            foreach ($ring as $c) { if (isset($c[0], $c[1])) { $sx += (float)$c[0]; $sy += (float)$c[1]; $n++; } }
            if ($n > 0) { $lon = $sx / $n; $lat = $sy / $n; }
        }
    }
    $talhaoPonto[(int)$tl['id']] = ['lat' => $lat, 'lon' => $lon];
}
$safras = vero_options('agro_safras', 'identificacao');
/* item 6.1: vínculos válvula→safra p/ auto-selecionar a safra ao escolher a válvula
   (mais recente primeiro; a fase já é automática pela data — A1-29).
   Opção B (Parte 1): carrega também variedade + data-poda (dia 0) + safra_talhao_id
   p/ o select de fase POR VARIEDADE resolver a fase pela data (dias desde a poda). */
$vinculosDF = [];
foreach (vero_rows(
    "SELECT st.id AS safra_talhao_id, st.talhao_id, st.safra_id, st.data_poda,
            s.identificacao, s.data_inicio, tl.variedade_id
       FROM agro_safra_talhoes st
       JOIN agro_safras s ON s.id = st.safra_id AND s.tenant_id = st.tenant_id
       LEFT JOIN agro_talhoes tl ON tl.id = st.talhao_id AND tl.tenant_id = st.tenant_id
      WHERE st.tenant_id = :t ORDER BY s.data_inicio DESC, s.id DESC", [':t' => $t]) as $vr) {
    $vinculosDF[(int)$vr['talhao_id']][] = [
        'safra'     => (int)$vr['safra_id'],
        'label'     => (string)$vr['identificacao'],
        'st'        => (int)$vr['safra_talhao_id'],
        'variedade' => $vr['variedade_id'] !== null ? (int)$vr['variedade_id'] : null,
        'poda'      => $vr['data_poda'] ?: ($vr['data_inicio'] ?: null), /* dia 0 = poda; fallback início da safra */
    ];
}
/* Opção B (Parte 1): fases da fenologia POR VARIEDADE (versão aprovada vigente),
   agrupadas por variedade_id, p/ pré-preencher o campo Fase por dias-desde-poda.
   { variedade_id: [{id, ini, fim, nome}] } — espelha agro/apontamentos.php. */
$varFasesDFMap = [];
foreach (vero_rows(
    "SELECT fa.id, fe.variedade_id, fa.dia_inicio, fa.dia_fim, fa.nome, fa.volume_calda_ha_l
       FROM agro_variedade_fases fa
       JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
      WHERE fa.tenant_id = :t AND fe.status = 'aprovada' AND fe.ativo = 1 AND fa.ativo = 1
        AND fe.versao = (SELECT MAX(versao) FROM agro_variedade_fenologia fe2
                          WHERE fe2.tenant_id = fa.tenant_id AND fe2.variedade_id = fe.variedade_id
                            AND fe2.status = 'aprovada' AND fe2.ativo = 1)
      ORDER BY fe.variedade_id, fa.dia_inicio", [':t' => $t]) as $vf) {
    /* item 6.1 (gestor 17/07): volume de calda (L/ha) POR FASE p/ auto-sugerir na DF */
    $varFasesDFMap[(int)$vf['variedade_id']][] = [
        'id' => (int)$vf['id'], 'ini' => (int)$vf['dia_inicio'],
        'fim' => (int)$vf['dia_fim'], 'nome' => (string)$vf['nome'],
        'vol' => $vf['volume_calda_ha_l'] !== null ? (float)$vf['volume_calda_ha_l'] : null];
}
/* item 6.16 + C-03: último monitoramento MIP por válvula com
   TODOS os alvos (junção multialvo, mig 170) — antes lia só o 1º alvo do
   cabeçalho. HY093: :t / :t2 distintos (prepares nativos). Mesma fonte da
   justificativa (A1-30). Uma linha por alvo do último monitoramento. */
$aplicUltMon = vero_rows(
    "SELECT m.talhao_id, m.data_monitoramento, ma.nivel_infestacao, m.unidade,
            ma.local_infestacao, a.nome AS alvo, a.nivel_acao
       FROM mip_monitoramentos m
       JOIN mip_monitoramento_alvos ma ON ma.monitoramento_id = m.id AND ma.tenant_id = m.tenant_id
       JOIN mip_alvos a ON a.id = ma.alvo_id
       JOIN (SELECT talhao_id, MAX(CONCAT(data_monitoramento, LPAD(id,10,'0'))) AS chave
               FROM mip_monitoramentos WHERE tenant_id = :t GROUP BY talhao_id) ult
         ON ult.talhao_id = m.talhao_id
        AND CONCAT(m.data_monitoramento, LPAD(m.id,10,'0')) = ult.chave
      WHERE m.tenant_id = :t2
      ORDER BY m.talhao_id, ma.id", [':t' => $t, ':t2' => $t]);
/* agrupa por válvula: data + lista de alvos (texto pronto, escapado no render JS) */
$aplicUltMonMap = [];
foreach ($aplicUltMon as $m) {
    $tal = (int)$m['talhao_id'];
    if (!isset($aplicUltMonMap[$tal])) {
        $aplicUltMonMap[$tal] = [
            'data'  => date('d/m/Y', strtotime((string)$m['data_monitoramento'])),
            'alvos' => [],
        ];
    }
    /* pulveriz. 23/07: estrutura (praga, nível, unidade, nível de ação) para o
       render COLORIR a % conforme o nível de ação e destacar a praga. */
    $aplicUltMonMap[$tal]['alvos'][] = [
        'praga' => (string)$m['alvo'],
        'nivel' => (float)$m['nivel_infestacao'],
        'un'    => (string)($m['unidade'] ?? '%'),
        'acao'  => $m['nivel_acao'] !== null ? (float)$m['nivel_acao'] : null,
        'local' => (string)($m['local_infestacao'] ?? ''),
    ];
}
$produtos = vero_rows(
    "SELECT p.id, p.codigo, p.nome, p.unidade, p.tipo_insumo,
            p.dose_referencia, p.dose_referencia_unidade, p.carencia_dias, p.lmr_dias,
            COALESCE((SELECT SUM(s.quantidade) FROM estoque_saldos s
                       WHERE s.tenant_id = p.tenant_id AND s.produto_id = p.id),0) AS saldo
       FROM estoque_produtos p WHERE p.tenant_id = :t AND p.ativo = 1 ORDER BY p.nome", [':t' => $t]);
$maquinas = vero_options('maquinas', 'nome', "ativo = 1 AND status <> 'inativa'");
$operadores = vero_options('agro_operadores', 'nome');

/* A1-48b (DB-51): alvos MIP + produtos INDICADOS pelo RT por alvo — Regra 1:
   o sistema LISTA o cadastro do responsável (com trilha de quem/quando);
   a escolha e a dose continuam sendo decisão registrada do RT */
$alvosMip = vero_options('mip_alvos', 'nome', 'ativo = 1');
$alvoProdutos = vero_rows(
    "SELECT ap.alvo_id, ap.produto_id, ap.dose, ap.dose_unidade, ap.volume_calda_ha,
            ap.observacao, u.nome AS cadastrado_por_nome, DATE(ap.created_at) AS cadastrado_em,
            p.nome AS produto_nome
       FROM mip_alvo_produtos ap
       JOIN estoque_produtos p ON p.id = ap.produto_id
       LEFT JOIN usuarios u ON u.id = ap.cadastrado_por
      WHERE ap.tenant_id = :t AND ap.ativo = 1
      ORDER BY p.nome", [':t' => $t]);
/* bombas ativas (IF) com a fazenda no rótulo — validação dura no POST */
$bombasOpt = [];
foreach (vero_rows(
    "SELECT b.id, CONCAT(f.nome, ' — ', b.nome) AS label
       FROM agro_bombas b JOIN agro_fazendas f ON f.id = b.fazenda_id
      WHERE b.tenant_id = :t AND b.ativo = 1 ORDER BY f.nome, b.nome", [':t' => $t]
) as $bb) { $bombasOpt[(int)$bb['id']] = (string)$bb['label']; }
$fenologias = [];
foreach (vero_rows(
    "SELECT fe.id, CONCAT(c.nome, ' — ', fe.codigo, ' ', fe.nome) AS label
       FROM agro_fenologia_estagios fe JOIN agro_culturas c ON c.id = fe.cultura_id
      WHERE fe.tenant_id = :t AND fe.ativo = 1 ORDER BY c.nome, fe.ordem", [':t' => $t]
) as $fe) { $fenologias[(int)$fe['id']] = (string)$fe['label']; }

/* ── Detalhe real (?ver=ID) — substitui o mock agro/aplicacao_detalhe ── */
$ver = null; $verItens = []; $verReceituarios = []; $verChuva = null; $verMaquinas = [];
if (!empty($_GET['ver'])) {
    $ver = vero_row(
        "SELECT ap.*, tl.codigo AS talhao, fz.nome AS fazenda, sa.identificacao AS safra,
                u.nome AS validador, mq.nome AS maquina, rt.nome AS rt_nome,
                vr.nome AS variedade, fe.nome AS fenologia, fe.codigo AS fenologia_cod,
                vf.nome AS var_fase_nome,
                bm.nome AS bomba_nome
           FROM agro_aplicacoes ap
           LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
           LEFT JOIN agro_fazendas fz ON fz.id = COALESCE(ap.fazenda_id, tl.fazenda_id)
           LEFT JOIN agro_safras sa ON sa.id = ap.safra_id
           LEFT JOIN usuarios u ON u.id = ap.validado_por
           LEFT JOIN maquinas mq ON mq.id = ap.maquina_id
           LEFT JOIN agro_operadores rt ON rt.id = ap.responsavel_tecnico_id
           LEFT JOIN agro_variedades vr ON vr.id = ap.variedade_id
           LEFT JOIN agro_fenologia_estagios fe ON fe.id = ap.fenologia_id
           LEFT JOIN agro_variedade_fases vf ON vf.id = ap.variedade_fase_id AND vf.tenant_id = ap.tenant_id
           LEFT JOIN agro_bombas bm ON bm.id = ap.bomba_id
          WHERE ap.id = :i AND ap.tenant_id = :t",
        [':i' => (int)$_GET['ver'], ':t' => $t]);
    if ($ver) {
        $verItens = vero_rows(
            "SELECT i.*, p.nome AS produto_nome, p.codigo AS produto_codigo, p.registro_mapa, p.nao_registrado, p.tipo_insumo
               FROM agro_aplicacao_itens i
               LEFT JOIN estoque_produtos p ON p.id = i.produto_id
              WHERE i.tenant_id = :t AND i.aplicacao_id = :a ORDER BY i.id",
            [':t' => $t, ':a' => (int)$ver['id']]);
        /* ── LMR como TETO de DIA DO CICLO (dia 0 = poda) ─────────────────────
           Regra confirmada c/ o cliente (24/07): estoque_produtos.lmr_dias NÃO é
           carência relativa à colheita; é o MÁXIMO dia-desde-a-poda em que o
           produto pode ser aplicado. Alerta (NÃO bloqueia) quando
           dias_desde_poda > lmr_dias (o próprio dia do LMR ainda é permitido).
           Fonte da poda: agro_safra_talhoes.data_poda ESTRITO — sem fallback p/
           início da safra: sem poda ⇒ sem alerta (degradação honesta, sem falso
           positivo). Independe do snapshot dias_desde_poda (que usa data_inicio
           como fallback quando não há poda), justamente p/ não gerar falso
           positivo contando de um dia 0 que não é a poda. */
        $verLmrDataPoda = ($ver['safra_id'] && $ver['talhao_id']) ? vero_val(
            "SELECT data_poda FROM agro_safra_talhoes
              WHERE tenant_id = :t AND safra_id = :s AND talhao_id = :ta
                AND data_poda IS NOT NULL ORDER BY id DESC LIMIT 1",
            [':t' => $t, ':s' => (int)$ver['safra_id'], ':ta' => (int)$ver['talhao_id']]) : null;
        /* data efetiva da aplicação: realizada (exceto planejada) ou prevista */
        $verLmrDataApl = ((string)$ver['status'] !== 'planejada' && $ver['data'])
            ? (string)$ver['data'] : (string)($ver['data_prevista'] ?? $ver['data'] ?? '');
        $verLmrDias = null;
        if ($verLmrDataPoda && $verLmrDataApl !== '') {
            $d = (int)floor((strtotime($verLmrDataApl) - strtotime((string)$verLmrDataPoda)) / 86400);
            if ($d >= 0) $verLmrDias = $d;
        }
        $verReceituarios = vero_rows(
            "SELECT r.* FROM agro_receituarios r
              WHERE r.tenant_id = :t AND r.aplicacao_id = :a ORDER BY r.id",
            [':t' => $t, ':a' => (int)$ver['id']]);
        /* item 6.5: todas as máquinas da aplicação (junção); fallback = maquina_id (compat) */
        $verMaquinas = array_column(vero_rows(
            "SELECT m.nome FROM agro_aplicacao_maquinas am
               JOIN maquinas m ON m.id = am.maquina_id AND m.tenant_id = am.tenant_id
              WHERE am.tenant_id = :t AND am.aplicacao_id = :a ORDER BY m.nome",
            [':t' => $t, ':a' => (int)$ver['id']]), 'nome');
        if ($ver['data']) {
            $verChuva = vero_val(
                "SELECT SUM(c.chuva_mm) FROM clima_registros c
                  WHERE c.tenant_id = :t AND c.data = :d
                    AND (c.talhao_id = :tl OR (c.talhao_id IS NULL AND c.fazenda_id = :f))",
                [':t' => $t, ':d' => (string)$ver['data'],
                 ':tl' => (int)$ver['talhao_id'], ':f' => (int)$ver['fazenda_id']]);
        }
    }
}

$badgeStatus = static fn(string $s): string => match ($s) {
    'validada'   => '<span class="vbadge vb-ok">Validada</span>',
    'registrada' => '<span class="vbadge vb-warn">Aguardando RT</span>',
    'planejada'  => '<span class="vbadge vb-info">Emitida — aguarda execução</span>',
    'cancelada'  => '<span class="vbadge vb-off">Cancelada</span>',
    default      => '<span class="vbadge vb-info">' . h(ucfirst($s)) . '</span>',
};
$docLabel = static fn(array $r): string => $r['doc_serie']
    ? '<strong class="vnum">' . h((string)$r['doc_serie']) . (int)$r['doc_numero'] . '</strong>'
    : '<span class="vhint">—</span>';

$GUARD      = ['macro' => 'mip', 'micro' => 'aplicacoes_defensivos'];
$PAGE_VIEW  = 'mip_aplicacoes_defensivos';
$PAGE_TITLE = 'Aplicações';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('mip.aplicacoes_defensivos.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Aplicações', 'Defensivos, fertirrigação e nutricionais — estoque por FEFO, custeio automático, validação do RT',
        $podeEditar ? '+ Nova aplicação' : null) ?>

  <?php if ($ver): ?>
  <!-- Detalhe da aplicação (tela real — substitui o mock aplicacao_detalhe) -->
  <div class="vcard" style="margin-bottom:16px">
    <div class="vtoolbar" style="justify-content:space-between">
      <strong><?= $ver['doc_serie'] ? h((string)$ver['doc_serie']) . (int)$ver['doc_numero'] : 'Aplicação #' . (int)$ver['id'] ?>
        — <?= h(TIPOS_APLIC[(string)$ver['tipo']] ?? ucfirst((string)$ver['tipo'])) ?>
        <?= $badgeStatus((string)$ver['status']) ?></strong>
      <span>
        <?php if ($podeEditar && (string)$ver['status'] === 'planejada'): ?>
          <a class="vbtn vbtn-primary vbtn-sm" href="?confirmar=<?= (int)$ver['id'] ?>">Confirmar execução</a>
        <?php endif; ?>
        <?php if ($ver['doc_serie']): ?>
          <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/mip/aplicacao_impressao?id=<?= (int)$ver['id'] ?>" target="_blank">Imprimir DF/IF</a>
        <?php endif; ?>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h(strtok((string)$_SERVER['REQUEST_URI'], '?')) ?>">Fechar detalhe</a>
      </span>
    </div>
    <?php
      $verValvulas = vero_rows(
          "SELECT av.*, s.codigo AS setor_codigo FROM agro_aplicacao_valvulas av
            LEFT JOIN agro_setores s ON s.id = av.setor_id
           WHERE av.tenant_id=:t AND av.aplicacao_id=:a ORDER BY av.id",
          [':t' => $t, ':a' => (int)$ver['id']]);
    ?>
    <?php if ($verValvulas): ?>
    <div class="vhint" style="padding:8px 18px 0">
      Válvulas da calda:
      <?php foreach ($verValvulas as $vv): ?>
        <span class="vbadge vb-info"><?= h((string)($vv['setor_codigo'] ?? ('#' . $vv['setor_id']))) ?>
          · <?= $vv['area_ha'] !== null ? numFmt((float)$vv['area_ha'], 2) . ' ha' : '' ?><?=
              $vv['volume_calda_l'] !== null ? ' · ' . numFmt((float)$vv['volume_calda_l'], 0) . ' L' : '' ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px 16px;padding:14px 18px;font-size:13px">
      <div><div class="vhint">Válvula</div><strong><?= h(trim(($ver['fazenda'] ?? '') . ' — ' . ($ver['talhao'] ?? ''), ' —') ?: '—') ?></strong></div>
      <div><div class="vhint">Safra</div><strong><?= h($ver['safra'] ?? '—') ?></strong></div>
      <div><div class="vhint">Data prevista / realizada</div>
        <?php /* C-11: OS emitida (planejada) NÃO tem data realizada — mesmo com o
                 placeholder transitório da coluna NOT NULL (pré-mig 177) exibe '—' */ ?>
        <strong class="vnum"><?= $ver['data_prevista'] ? dateBR((string)$ver['data_prevista']) : '—' ?> / <?= ($ver['data'] && $ver['status'] !== 'planejada') ? dateBR((string)$ver['data']) : '—' ?></strong></div>
      <div><div class="vhint">Variedade / fenologia</div>
        <strong><?php
          /* fase POR VARIEDADE (mig 165) tem prioridade; estágio por cultura como fallback */
          $verFase = $ver['var_fase_nome'] ?? null;
          if ($verFase === null && $ver['fenologia']) $verFase = $ver['fenologia_cod'] . ' ' . $ver['fenologia'];
          echo h(trim(($ver['variedade'] ?? '') . ($verFase ? ' · ' . $verFase : '')
                . (($ver['dias_desde_poda'] ?? null) !== null ? ' (' . (int)$ver['dias_desde_poda'] . 'd)' : ''), ' ·') ?: '—');
        ?></strong></div>
      <div><div class="vhint">Maquinário e equipamento</div><strong><?= h($verMaquinas ? implode(', ', $verMaquinas) : ($ver['maquina'] ?? '')) ?: '—' ?></strong></div>
      <?php if ((string)$ver['tipo'] === 'fertirrigacao'):
          $paramsVer = $ver['parametros_aplicacao'] ? (json_decode((string)$ver['parametros_aplicacao'], true) ?: []) : []; ?>
      <div><div class="vhint">Bomba (IF)</div><strong><?= h($ver['bomba_nome'] ?? '') ?: '—' ?></strong></div>
      <div><div class="vhint">Irrigação — início / fim / tempo</div>
        <strong class="vnum"><?= $ver['executada_inicio'] ? date('H:i', strtotime((string)$ver['executada_inicio'])) : '—' ?> /
          <?= $ver['executada_fim'] ? date('H:i', strtotime((string)$ver['executada_fim'])) : '—' ?> /
          <?= isset($paramsVer['tempo_irrigacao_h']) ? numFmt((float)$paramsVer['tempo_irrigacao_h'], 1) . ' h' : '—' ?></strong></div>
      <?php endif; ?>
      <div><div class="vhint">Resp. técnico (receita)</div><strong><?= h($ver['rt_nome'] ?? '') ?: '—' ?></strong></div>
      <div><div class="vhint">Área aplicada</div><strong class="vnum"><?= $ver['area_aplicada_ha'] !== null ? numFmt((float)$ver['area_aplicada_ha'], 2) . ' ha' : '—' ?></strong></div>
      <div><div class="vhint">Volume de calda</div><strong class="vnum"><?= $ver['volume_calda_l'] !== null ? numFmt((float)$ver['volume_calda_l'], 0) . ' L' : '—' ?></strong></div>
      <?php
        /* P-101: ficha de pulverização (bico/filas/dose) do JSON de parâmetros */
        $pFicha = $ver['parametros_aplicacao'] ? (json_decode((string)$ver['parametros_aplicacao'], true) ?: []) : [];
        if (!empty($pFicha['bico_tipo']) || !empty($pFicha['filas']) || !empty($pFicha['dose_ha']) || !empty($pFicha['dose_100l'])): ?>
      <div><div class="vhint">Tipo de bico</div><strong><?= !empty($pFicha['bico_tipo']) ? h(BICO_TIPOS[$pFicha['bico_tipo']] ?? (string)$pFicha['bico_tipo']) : '—' ?></strong></div>
      <div><div class="vhint">Filas</div><strong><?= !empty($pFicha['filas']) ? h(FICHA_FILAS[$pFicha['filas']] ?? (string)$pFicha['filas']) : '—' ?></strong></div>
      <div><div class="vhint">Dose por hectare / por 100 L</div><strong><?= h(($pFicha['dose_ha'] ?? '—') ?: '—') ?> <span class="vhint">/</span> <?= h(($pFicha['dose_100l'] ?? '—') ?: '—') ?></strong></div>
      <?php endif; ?>
      <?php
        $clima = $ver['condicao_climatica'] ? json_decode((string)$ver['condicao_climatica'], true) : null;
        $climaTxt = [];
        if (is_array($clima)) {
            if (isset($clima['vento_kmh']) && $clima['vento_kmh'] !== null)         $climaTxt[] = 'vento ' . numFmt((float)$clima['vento_kmh'], 1) . ' km/h';
            if (isset($clima['temperatura_c']) && $clima['temperatura_c'] !== null) $climaTxt[] = numFmt((float)$clima['temperatura_c'], 1) . ' °C';
            if (isset($clima['umidade_pct']) && $clima['umidade_pct'] !== null)     $climaTxt[] = 'UR ' . numFmt((float)$clima['umidade_pct'], 0) . '%';
        }
      ?>
      <div><div class="vhint">Condição climática registrada</div><strong><?= $climaTxt ? h(implode(' · ', $climaTxt)) : '—' ?></strong></div>
      <div><div class="vhint">Condição do céu</div><strong><?= !empty($ver['condicao_ceu']) ? h(CEU_CONDICOES[$ver['condicao_ceu']] ?? (string)$ver['condicao_ceu']) : '—' ?></strong></div>
      <div><div class="vhint">Chuva registrada no dia</div>
        <strong class="vnum"><?= $verChuva !== null && $verChuva !== false ? numFmt((float)$verChuva, 1) . ' mm' : 'sem registro' ?></strong></div>
      <div><div class="vhint">Custo total</div><strong class="vnum" style="color:#005059">R$ <?= numFmt((float)($ver['custo_total'] ?? 0), 2) ?><?=
        $ver['area_aplicada_ha'] !== null && (float)$ver['area_aplicada_ha'] > 0
          ? ' <span class="vhint">(' . numFmt((float)($ver['custo_total'] ?? 0) / (float)$ver['area_aplicada_ha'], 2) . '/ha)</span>' : '' ?></strong></div>
      <div><div class="vhint">Validação (RT)</div><strong><?= $ver['validador']
        ? h((string)$ver['validador']) . ' · ' . date('d/m/Y H:i', strtotime((string)$ver['validado_em'])) : 'pendente' ?></strong></div>
      <?php if ($ver['observacao']): ?>
        <div style="grid-column:1/-1"><div class="vhint">Observação</div><strong><?= h((string)$ver['observacao']) ?></strong></div>
      <?php endif; ?>
    </div>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Produto</th><th class="num">Dose</th>
        <th class="num">Qtd consumida</th>
        <th class="num">Carência (dias)</th>
        <th class="num">Reentrada (h)</th>
        <th class="num">Liberação p/ colheita</th>
        <th class="num">Custo (R$)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($verItens as $vi):
          $lib = ($vi['carencia_dias'] !== null && (int)$vi['carencia_dias'] > 0 && $ver['data'])
              ? date('Y-m-d', strtotime((string)$ver['data'] . ' +' . (int)$vi['carencia_dias'] . ' days')) : null;
          /* LMR = teto de dia-do-ciclo: alerta (não bloqueia) quando o dia da
             aplicação no ciclo (dias desde a poda) passa do limite do produto.
             Só aparece com poda conhecida (=> $verLmrDias) E lmr_dias definido. */
          $lmrExcede = ($verLmrDias !== null && $vi['lmr_dias'] !== null
              && (int)$vi['lmr_dias'] > 0 && $verLmrDias > (int)$vi['lmr_dias']); ?>
        <tr>
          <td><strong><?= h(trim(($vi['produto_codigo'] ? $vi['produto_codigo'] . ' — ' : '') . ($vi['produto_nome'] ?? $vi['ingrediente_ativo'] ?? '—'))) ?></strong>
            <?php if ((string)($vi['tipo_insumo'] ?? '') === 'defensivo'
                && (trim((string)($vi['registro_mapa'] ?? '')) === '' || (int)($vi['nao_registrado'] ?? 0) === 1)): /* N-01 / D-6: relação do aplicador mostra TUDO; marca DEFENSIVO sem registro MAPA — registro_mapa vazio OU flag nao_registrado=1 (mig 171); omitido do documento oficial (P-22: aviso, não bloqueio). Fertilizante/corretivo: regime próprio (Lei 6.894/1980), nunca omitido */ ?>
              <span class="vbadge vb-warn" style="margin-left:6px">sem registro</span>
            <?php endif; ?>
            <?php if ($lmrExcede): ?>
              <div class="vbadge vb-off" style="margin-top:4px;white-space:normal"
                   title="LMR (limite máximo de resíduo) informado na bula como dia máximo do ciclo. Alerta apenas — o registro não é bloqueado.">LMR: aplicação no dia <?= (int)$verLmrDias ?> do ciclo excede o limite de <?= (int)$vi['lmr_dias'] ?> dias</div>
            <?php endif; ?></td>
          <td class="num"><?= $vi['dose_valor'] !== null ? numFmt((float)$vi['dose_valor'], 2) . ' ' . h((string)($vi['dose_unidade'] ?? '')) : '—' ?></td>
          <td class="num"><?= $vi['quantidade_consumida'] !== null ? numFmt((float)$vi['quantidade_consumida'], 2) . ' ' . h((string)($vi['quantidade_unidade'] ?? '')) : '—' ?></td>
          <td class="num"><?= $vi['carencia_dias'] !== null ? (int)$vi['carencia_dias'] : '—' ?></td>
          <td class="num"><?= $vi['intervalo_reentrada_horas'] !== null ? (int)$vi['intervalo_reentrada_horas'] : '—' ?></td>
          <td class="vnum" style="text-align:right;<?= $lib && $lib >= date('Y-m-d') ? 'color:#b3261e' : '' ?>"><?= $lib ? dateBR($lib) : '—' ?></td>
          <td class="num"><?= $vi['custo_total'] !== null ? numFmt((float)$vi['custo_total'], 2) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      Receituários vinculados:
      <?php if ($verReceituarios): foreach ($verReceituarios as $rc): ?>
        <a href="<?= BIOS_BASE ?>/mip/receituarios?aplicacao=<?= (int)$ver['id'] ?>"><strong><?= h((string)($rc['numero'] ?? ('#' . $rc['id']))) ?></strong></a>
      <?php endforeach; else: ?>
        nenhum — <a href="<?= BIOS_BASE ?>/mip/receituarios?nova_aplicacao=<?= (int)$ver['id'] ?>">registrar receituário</a>.
      <?php endif; ?>
      Carência e reentrada são informadas pelo RT conforme a bula — o sistema apenas confronta datas e sinaliza.
      O LMR (dias) é lido como dia máximo do CICLO (dia 0 = poda); quando a aplicação cai depois desse dia o sistema avisa (não bloqueia). Sem data de poda registrada na válvula, o aviso não é calculado.
    </div>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="tipo" onchange="this.form.submit()">
          <option value="">Todos os tipos</option>
          <?php foreach (TIPOS_APLIC as $k => $rotulo): ?>
            <option value="<?= $k ?>"<?= $fTipo === $k ? ' selected' : '' ?>><?= h($rotulo) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="serie" onchange="this.form.submit()">
          <option value="">DF + IF</option>
          <option value="DF"<?= $fSerie === 'DF' ? ' selected' : '' ?>>Só DF (pulverização)</option>
          <option value="IF"<?= $fSerie === 'IF' ? ' selected' : '' ?>>Só IF (fertirrigação)</option>
        </select>
      </form>
      <a class="vbtn vbtn-ghost vbtn-sm" target="_blank" href="<?= BIOS_BASE ?>/mip/boletim_pulverizacao"
         title="Boletim consolidado para impressão — na tela do boletim você escolhe quantos dias entram (últimos N dias até uma data)">🖨 Imprimir boletim</a>
      <span class="vsub"><?= count($rows) ?> documento(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma aplicação registrada.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Documento</th><th>Data</th><th>Tipo</th><th><?= h(vero_a1_rotulo_area()) ?></th><th>Safra</th>
        <th class="num">Produtos</th>
        <th class="num">Custo (R$)</th>
        <th>Status</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'cancelada' ? ' style="opacity:.55"' : '' ?>>
          <td><?= $docLabel($r) ?></td>
          <td class="vnum"><strong><?= ($d = $r['data'] ?? $r['data_prevista']) ? date('d/m/Y', strtotime((string)$d)) : '—' ?></strong></td>
          <td><span class="vbadge vb-info"><?= h(TIPOS_APLIC[(string)$r['tipo']] ?? ucfirst((string)$r['tipo'])) ?></span></td>
          <td><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td><?= h($r['safra'] ?? '—') ?></td>
          <td class="num"><?= (int)$r['itens'] ?></td>
          <td class="num"><?= $r['custo_total'] !== null ? numFmt((float)$r['custo_total'], 2) : '—' ?></td>
          <td><?= $badgeStatus((string)$r['status']) ?>
            <?= $r['validador'] ? '<div class="vhint">' . h((string)$r['validador']) . '</div>' : '' ?></td>
          <td><div class="vactions">
            <?= vero_btn_icone(vero_ico_olho(), 'Ver detalhes', '', '?ver=' . (int)$r['id']) ?>
            <?php if ($r['doc_serie']): ?>
              <?= vero_btn_icone(vero_ico_imprimir(), 'Imprimir documento', "window.open('" . BIOS_BASE . "/mip/aplicacao_impressao?id=" . (int)$r['id'] . "','_blank')") ?>
            <?php endif; ?>
            <?php if ($podeEditar && $r['status'] === 'planejada'): ?>
              <?= vero_btn_icone(vero_ico_check(), 'Confirmar execução', '', '?confirmar=' . (int)$r['id']) ?>
              <?= vero_btn_editar((int)$r['id']) ?>
            <?php endif; ?>
            <?php if ($podeEditar && $r['status'] === 'registrada'): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="validar">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="vicon vicon-acao" type="submit" title="Validar (RT)" aria-label="Validar (RT)"><?= vero_ico_check() ?></button>
              </form>
              <?= vero_btn_editar((int)$r['id']) ?>
            <?php endif; ?>
            <?php if (vero_can('mip.aplicacoes_defensivos.excluir') && $r['status'] !== 'cancelada'): ?>
              <?= vero_btn_excluir((int)$r['id'], $r['status'] === 'planejada'
                    ? 'Cancelar esta OS emitida? O número fica reservado (trilha de auditoria).'
                    : 'Cancelar esta aplicação? O estoque será estornado.') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$edit = null;
$editItens = [];
if ($podeEditar && !empty($_GET['editar'])) {
    /* A1-26: emitida (planejada) também é editável — validada não */
    $edit = vero_row("SELECT * FROM agro_aplicacoes WHERE id=:i AND tenant_id=:t AND status IN ('registrada','planejada')",
        [':i' => (int)$_GET['editar'], ':t' => $t]);
    if ($edit) {
        $editItens = vero_rows("SELECT * FROM agro_aplicacao_itens WHERE tenant_id=:t AND aplicacao_id=:a ORDER BY id",
            [':t' => $t, ':a' => (int)$edit['id']]);
        $editOperadores = vero_rows(
            "SELECT * FROM agro_aplicacao_operadores WHERE tenant_id=:t AND aplicacao_id=:a ORDER BY id",
            [':t' => $t, ':a' => (int)$edit['id']]);
        /* item 6.5: máquinas já vinculadas p/ pré-preencher o repeater */
        $editMaquinas = array_column(vero_rows(
            "SELECT maquina_id FROM agro_aplicacao_maquinas WHERE tenant_id=:t AND aplicacao_id=:a ORDER BY id",
            [':t' => $t, ':a' => (int)$edit['id']]), 'maquina_id');
        /* multi-válvula: linhas DB-29 → talhões (via setor-espelho) p/ re-marcar
           as válvulas EXTRAS (a principal já vem no select do cabeçalho) */
        $editValvulasExtra = array_values(array_diff(array_map('intval', array_column(vero_rows(
            "SELECT s.talhao_id FROM agro_aplicacao_valvulas av
               JOIN agro_setores s ON s.id = av.setor_id
              WHERE av.tenant_id=:t AND av.aplicacao_id=:a AND s.talhao_id IS NOT NULL ORDER BY av.id",
            [':t' => $t, ':a' => (int)$edit['id']]), 'talhao_id')), [(int)$edit['talhao_id']]));
    }
}
$editOperadores = $editOperadores ?? [];
$editMaquinas = $editMaquinas ?? [];
$editValvulasExtra = $editValvulasExtra ?? [];

/* A1-26: formulário de CONFIRMAÇÃO (?confirmar=ID — só documentos emitidos) */
$conf = null; $confItens = [];
if ($podeEditar && !empty($_GET['confirmar'])) {
    $conf = vero_row(
        "SELECT ap.*, tl.codigo AS talhao, fz.nome AS fazenda
           FROM agro_aplicacoes ap
           LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
           LEFT JOIN agro_fazendas fz ON fz.id = ap.fazenda_id
          WHERE ap.id=:i AND ap.tenant_id=:t AND ap.status='planejada'",
        [':i' => (int)$_GET['confirmar'], ':t' => $t]);
    if ($conf) {
        $confItens = vero_rows(
            "SELECT i.*, p.nome AS produto_nome, p.codigo AS produto_codigo
               FROM agro_aplicacao_itens i
               LEFT JOIN estoque_produtos p ON p.id = i.produto_id
              WHERE i.tenant_id=:t AND i.aplicacao_id=:a ORDER BY i.id",
            [':t' => $t, ':a' => (int)$conf['id']]);
        $confOperadores = vero_rows(
            "SELECT * FROM agro_aplicacao_operadores WHERE tenant_id=:t AND aplicacao_id=:a ORDER BY id",
            [':t' => $t, ':a' => (int)$conf['id']]);
    }
}
$confOperadores = $confOperadores ?? [];
/* item 6.2: o repeater "Válvulas da calda" foi removido — a query $valvulasDoc que o
   alimentava saiu junto (área/volume agora vêm dos campos únicos do documento). */
?>
<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox" style="max-width:900px">
    <header>
      <h2><?= $edit ? 'Editar aplicação (reprocessa estoque)' : 'Nova aplicação' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <?php if (!$edit): ?>
      <div class="vfield" style="margin-bottom:8px">
        <label>Modo (A1-26 — DF/IF)</label>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
          <label style="font-weight:400;display:flex;gap:6px;align-items:center">
            <input type="radio" name="modo" value="direto" checked>
            Registro direto <span class="vhint">(já executada — baixa o estoque agora)</span>
          </label>
          <label style="font-weight:400;display:flex;gap:6px;align-items:center">
            <input type="radio" name="modo" value="emitir">
            <strong>Emitir OS (DF/IF)</strong> <span class="vhint">(instrução numerada — SEM baixa; confirma após a execução)</span>
          </label>
        </div>
      </div>
      <?php elseif ($edit['doc_serie']): ?>
      <div class="vhint" style="margin-bottom:8px">Editando o documento
        <strong><?= h((string)$edit['doc_serie']) . (int)$edit['doc_numero'] ?></strong>
        (<?= $edit['status'] === 'planejada' ? 'emitido — sem efeitos até a confirmação' : 'registrado — reprocessa estoque' ?>).
        Não é possível trocar entre DF e IF.</div>
      <?php endif; ?>
      <div class="vgrid">
        <?= vero_f_select('tipo', 'Tipo', TIPOS_APLIC, $edit['tipo'] ?? 'pulverizacao', true, '') ?>
        <div class="vfield">
          <label><?= h(vero_a1_rotulo_area()) ?> *</label>
          <select name="talhao_id" id="aplic-talhao" required>
            <option value="">Selecione…</option>
            <?php foreach ($talhoes as $tl): ?>
              <?php $pt = $talhaoPonto[(int)$tl['id']] ?? ['lat' => null, 'lon' => null]; ?>
              <option value="<?= (int)$tl['id'] ?>" data-area="<?= h((string)$tl['area_ha']) ?>"
                      data-lat="<?= $pt['lat'] !== null ? h((string)round((float)$pt['lat'], 6)) : '' ?>"
                      data-lon="<?= $pt['lon'] !== null ? h((string)round((float)$pt['lon'], 6)) : '' ?>"<?= $edit && (int)$edit['talhao_id'] === (int)$tl['id'] ? ' selected' : '' ?>>
                <?= h(($tl['fazenda'] ? $tl['fazenda'] . ' — ' : '') . $tl['codigo']) ?></option>
            <?php endforeach; ?>
          </select>
          <!-- item 6.16: último monitoramento MIP da válvula (contexto p/ o RT — só leitura) -->
          <div class="vhint" id="aplic-ult-mon" style="display:none;margin-top:6px;border-left:3px solid #005059;padding-left:8px"></div>
          <!-- Multi-válvula (18/08): a MESMA calda pode cobrir outras válvulas da MESMA
               fazenda. O documento continua um só (DF31/DB-29); fenologia, monitoramento
               e clima seguem da válvula principal acima. -->
          <details id="aplic-valvulas-extra" style="margin-top:6px"<?= $editValvulasExtra ? ' open' : '' ?>>
            <summary class="vhint" style="cursor:pointer">Aplicar a mesma calda em mais <?= h(mb_strtolower(vero_a1_rotulo_area(true))) ?></summary>
            <div style="max-height:150px;overflow:auto;border:1px solid var(--v-borda,#ddd);border-radius:6px;padding:6px 10px;margin-top:4px">
              <?php foreach ($talhoes as $tl): ?>
                <label style="display:flex;gap:8px;align-items:center;font-weight:normal;margin:3px 0">
                  <?php /* width/flex explícitos: o CSS global dá width:100% a input e
                           esticava o checkbox, espremendo o rótulo numa coluna à direita */ ?>
                  <input type="checkbox" name="valvulas_extra[]" value="<?= (int)$tl['id'] ?>"
                         style="width:auto;flex:0 0 auto;margin:0"
                         data-area="<?= h((string)$tl['area_ha']) ?>"<?= in_array((int)$tl['id'], $editValvulasExtra, true) ? ' checked' : '' ?>>
                  <span style="flex:1;text-align:left"><?= h(($tl['fazenda'] ? $tl['fazenda'] . ' — ' : '') . $tl['codigo']) ?><?=
                    $tl['area_ha'] !== null ? ' <span class="vhint">· ' . numFmt((float)$tl['area_ha'], 2) . ' ha</span>' : '' ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
        </div>
        <?php /* C-11 (dois estágios, mig 167): na EMISSÃO de OS a "Data realizada" não
                 aparece nem é exigida — ela é registrada na CONFIRMAÇÃO da execução;
                 a "Data prevista" passa a mandar (obrigatória). No registro direto
                 (aplicação já executada) a realizada segue obrigatória. Na edição,
                 o status do documento decide (planejada = OS emitida). */
              $formEmissao = $edit && ($edit['status'] ?? '') === 'planejada'; ?>
        <div class="vfield">
          <label>Data prevista<span id="aplic-prev-req"<?= $formEmissao ? '' : ' style="display:none"' ?>> *</span></label>
          <?php /* pulveriz. 23/07: Data PREVISTA vem automática (hoje); a realizada
                   deixa de ser automática (preenchida à mão / na confirmação). */ ?>
          <input type="date" name="data_prevista" id="aplic-data-prev"
                 value="<?= h($edit['data_prevista'] ?? date('Y-m-d')) ?>"<?= $formEmissao ? ' required' : '' ?>>
        </div>
        <div class="vfield" id="aplic-data-real-field"<?= $formEmissao ? ' style="display:none"' : '' ?>>
          <label>Data realizada *</label>
          <input type="date" name="data" value="<?= h($edit['data'] ?? '') ?>"<?= $formEmissao ? ' disabled' : ' required' ?>>
        </div>
        <?= vero_f_select('safra_id', 'Safra', ['' => 'Sem safra'] + $safras, $edit['safra_id'] ?? '', false, '') ?>
        <div class="vfield">
          <label>Fase fenológica</label>
          <select name="fase_ref" id="aplic-feno">
            <option value="">— Automática pela data —</option>
          </select>
          <!-- Opção B / gestor 17/07: o campo puxa AUTOMATICAMENTE a fase da VARIEDADE
               da válvula (dias desde a poda). Hint textual removido a pedido do gestor
               (17/07); o #aplic-feno-hint some, a JS que o preenchia é null-safe. -->
        </div>
      </div>

      <!-- C-02/C-37: seção de PRODUTOS logo abaixo de Safra, acima
           de Operadores; "Alvo do manejo" renomeada p/ "Produtos ou Insumos".
           C-38: colunas Carência/Reentrada saíram do layout — o POST sem os campos
           cai no fallback da bula do produto (Regra 1) e a DF impressa exibe ambas
           + a data de colheita permitida. -->
      <!-- Fertirrigação (IF): bomba + tempo/horas da injeção — ACIMA de Produtos. Só aparece no modo IF (toggle por forma de aplicação). -->
      <?php $paramsEdit = $edit && $edit['parametros_aplicacao'] ? (json_decode((string)$edit['parametros_aplicacao'], true) ?: []) : []; ?>
      <div id="aplic-if" class="vgrid" style="margin-top:10px;display:none">
        <div class="full"><strong>Fertirrigação (IF)</strong>
          <span class="vhint">bomba da fazenda + tempo/horas da injeção</span></div>
        <?= vero_f_select('bomba_id', 'Bomba', $bombasOpt, $edit['bomba_id'] ?? null, false, '— Não informada —') ?>
        <div class="vfield">
          <label>Hora início</label>
          <input type="time" name="hora_inicio" value="<?= $edit && $edit['executada_inicio'] ? date('H:i', strtotime((string)$edit['executada_inicio'])) : '' ?>">
        </div>
        <div class="vfield">
          <label>Hora fim</label>
          <input type="time" name="hora_fim" value="<?= $edit && $edit['executada_fim'] ? date('H:i', strtotime((string)$edit['executada_fim'])) : '' ?>">
        </div>
        <?= vero_f_text('tempo_irrigacao_h', 'Tempo de irrigação (h)', isset($paramsEdit['tempo_irrigacao_h']) ? numFmt((float)$paramsEdit['tempo_irrigacao_h'], 1) : '') ?>
      </div>

      <div style="margin-top:14px;padding-top:12px;border-top:1px solid #EEE8DB">
        <strong style="font-size:14px">Produtos ou Insumos</strong>
        <span class="vhint">receita do RT — dose conforme a bula; carência/reentrada saem do cadastro do produto e aparecem na DF; consumo baixa o estoque</span>
      </div>
      <!-- A1-48b (DB-51): guia por ALVO — produtos indicados pelo RT (Regra 1: registro, não recomendação).
           Volume de calda ao LADO do Alvo: preenche cedo e a dose vira /100L. -->
      <div class="vgrid" style="margin-top:10px">
        <div class="vfield">
          <label>Alvo (opcional — mostra os produtos INDICADOS pelo RT para o alvo)</label>
          <select id="aplic-alvo">
            <option value="">— Sem alvo / manejo geral —</option>
            <?php foreach ($alvosMip as $aid => $anome): ?>
              <option value="<?= (int)$aid ?>"><?= h($anome) ?></option>
            <?php endforeach; ?>
          </select>
          <div id="aplic-indicados" style="display:none;margin-top:6px;border:1px solid var(--v-borda,#ddd);border-radius:6px;padding:8px 10px">
            <div class="vhint" style="margin-bottom:4px"><strong>Produtos indicados pelo RT para este alvo</strong> — cadastro em MIP → Alvos (a dose é cópia editável; decisão do RT):</div>
            <div id="aplic-indicados-lista"></div>
          </div>
        </div>
        <div id="aplic-volcalda"><!-- V-07: Volume de calda é resíduo de pulverização — some na fertirrigação -->
          <div class="vfield">
            <label>Volume de calda (L/ha)</label>
            <input type="text" name="volume_calda_ha_l" id="aplic-vol-ha" placeholder="por hectare"
                   value="<?= $edit && $edit['volume_calda_ha_l'] !== null ? h((string)(float)$edit['volume_calda_ha_l']) : '' ?>">
            <div class="vhint">L por hectare. Preencha para dose por 100 L; o total sai abaixo pela área.</div>
          </div>
          <div class="vfield" style="margin-top:8px">
            <label>Volume total (L) <span class="vhint">— sugerido pela calda × área; pode ajustar</span></label>
            <?php /* pedido 18/08: campo DESTRAVADO — o cálculo continua sugerindo
                     (calda L/ha × área), mas valor digitado à mão prevalece e é o
                     que alimenta a dose por 100 L. */ ?>
            <input type="text" name="volume_calda_l" id="aplic-vol-total"
                   value="<?= $edit && $edit['volume_calda_l'] !== null ? h((string)(int)round((float)$edit['volume_calda_l'])) : '' ?>">
          </div>
        </div>
      </div>
      <div class="vfield" style="margin-top:10px">
        <div class="vdata-wrap">
        <table class="vdata">
          <thead><tr>
            <th style="width:38%">Produto</th>
            <th style="width:15%">Dose</th>
            <th style="width:15%">Un. dose</th>
            <th class="num" style="width:20%">Qtd consumida *</th>
            <th style="width:40px"></th>
          </tr></thead>
          <tbody id="aplic-itens"></tbody>
        </table>
        </div>
        <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="aplicAddItem()">+ Produto</button>
      </div>

      <!-- $paramsEdit já definido acima (antes do bloco IF). -->
      <!-- pulveriz. 23/07: Maquinário e Operadores/EPI foram movidos para o FINAL do
           form (o cliente prefere preencher à mão por último). Bico/Filas seguem aqui. -->
      <div class="vgrid" style="margin-top:10px">
        <div id="aplic-bico-wrap"><!-- V-07: Tipo de bico é resíduo de pulverização — some na fertirrigação (Filas fica) -->
          <?= vero_f_select('f_bico_tipo', 'Tipo de bico', BICO_TIPOS, $paramsEdit['bico_tipo'] ?? null, false, '— Não informado —') ?>
        </div>
        <?= vero_f_text('f_filas', 'Filas (nº)', (string)($paramsEdit['filas'] ?? ''), false, 'Número de filas da válvula (ex.: 30 a 50)', 'number') ?>
      </div>

      <div class="vgrid" style="margin-top:10px">
        <?= vero_f_select('responsavel_tecnico_id', 'Responsável técnico (receita)', $operadores, $edit['responsavel_tecnico_id'] ?? null, false, '— Não informado —') ?>
        <?= vero_f_text('area_aplicada_ha', 'Área aplicada (ha)', $edit && $edit['area_aplicada_ha'] !== null ? numFmt((float)$edit['area_aplicada_ha'], 2) : '', false, 'Vazio = não informada') ?>
        <?php /* Volume de calda (L) foi movido p/ ao lado do Alvo. */ ?>
        <?php /* volume_calda_ha_l agora é o campo visível "Volume de calda (L/ha)" ao lado do Alvo. */ ?>
        <?php $paramsEditVia = $edit && $edit['parametros_aplicacao'] ? (json_decode((string)$edit['parametros_aplicacao'], true) ?: []) : []; ?>
        <?= vero_f_select('forma_aplicacao', 'Forma de aplicação', [
              'drone' => 'Drone', 'trator_pulverizador' => 'Trator com pulverizador', 'costal' => 'Costal',
            ], $edit['forma_aplicacao'] ?? null, false, '— Não informada — (IF = fertirrigação automática)') ?>
        <?php $climaEdit = $edit && $edit['condicao_climatica'] ? (json_decode((string)$edit['condicao_climatica'], true) ?: []) : []; ?>
        <?= vero_f_select('condicao_ceu', 'Condição do céu', CEU_CONDICOES, $edit['condicao_ceu'] ?? null, false, '— Não informada —') ?>
        <?= vero_f_text('clima_vento_kmh', 'Vento (km/h)', isset($climaEdit['vento_kmh']) && $climaEdit['vento_kmh'] !== null ? numFmt((float)$climaEdit['vento_kmh'], 1) : '', false, vero_a1_hint_faixa('vento', 'Auto do talhão (Open-Meteo) — ajustável')) ?>
        <?= vero_f_text('clima_temperatura_c', 'Temperatura (°C)', isset($climaEdit['temperatura_c']) && $climaEdit['temperatura_c'] !== null ? numFmt((float)$climaEdit['temperatura_c'], 1) : '', false, vero_a1_hint_faixa('temp', 'Auto do talhão (Open-Meteo)')) ?>
        <?= vero_f_text('clima_umidade_pct', 'Umidade relativa (%)', isset($climaEdit['umidade_pct']) && $climaEdit['umidade_pct'] !== null ? numFmt((float)$climaEdit['umidade_pct'], 0) : '', false, vero_a1_hint_faixa('ur', 'Auto do talhão (Open-Meteo)')) ?>
        <div class="full"><?= vero_f_text('observacao', 'Observação', $edit['observacao'] ?? '') ?></div>
      </div>

      <!-- C-04: campos GERAIS "Dose por hectare"/"Dose por 100 L"
           removidos do form — duplicavam a dose que já é POR PRODUTO na tabela
           de itens. Valores legados seguem visíveis no detalhe (?ver). -->

      <!-- Fertirrigação (IF): movida p/ ACIMA de Produtos. -->

      <!-- A1-30: parâmetros da via (JSON DB-28, chaves whitelisted) -->
      <div id="aplic-drone" class="vgrid" style="margin-top:10px;display:none">
        <div class="full"><strong>Parâmetros — Drone</strong></div>
        <?= vero_f_text('p_faixa_m', 'Faixa (m)', isset($paramsEditVia['faixa_m']) ? numFmt((float)$paramsEditVia['faixa_m'], 1) : '') ?>
        <?= vero_f_text('p_velocidade_ms', 'Velocidade (m/s)', isset($paramsEditVia['velocidade_ms']) ? numFmt((float)$paramsEditVia['velocidade_ms'], 1) : '') ?>
        <?= vero_f_text('p_gota_micras', 'Gota (µ)', isset($paramsEditVia['gota_micras']) ? numFmt((float)$paramsEditVia['gota_micras'], 0) : '') ?>
        <?= vero_f_text('p_altura_m', 'Altura (m)', isset($paramsEditVia['altura_m']) ? numFmt((float)$paramsEditVia['altura_m'], 1) : '') ?>
      </div>
      <div id="aplic-trator" class="vgrid" style="margin-top:10px;display:none">
        <div class="full"><strong>Parâmetros — Trator com pulverizador</strong></div>
        <?= vero_f_text('p_mancha', 'Mancha', $paramsEditVia['mancha'] ?? '') ?>
        <?= vero_f_text('p_velocidade', 'Velocidade', isset($paramsEditVia['velocidade']) ? numFmt((float)$paramsEditVia['velocidade'], 1) : '') ?>
        <?= vero_f_text('p_bico', 'Bico', $paramsEditVia['bico'] ?? '') ?>
        <?= vero_f_text('p_horimetro_inicial', 'Horímetro inicial', isset($paramsEditVia['horimetro_inicial']) ? numFmt((float)$paramsEditVia['horimetro_inicial'], 1) : '', false, 'Auto: último registro da máquina (editável)') ?>
        <?= vero_f_text('p_horimetro_final', 'Horímetro final', isset($paramsEditVia['horimetro_final']) ? numFmt((float)$paramsEditVia['horimetro_final'], 1) : '') ?>
      </div>

      <!-- item 6.2 (gestor 17/07): o repeater "Válvulas da calda" foi REMOVIDO (redundante
           com a válvula do campo principal). Área/volume vêm dos campos únicos acima. -->
      <!-- C-02/C-37/C-38: a seção de PRODUTOS subiu para logo abaixo
           de Safra (topo do form). As colunas Carência/Reentrada saíram do layout —
           o servidor grava da bula do produto e a DF impressa as exibe. -->

      <!-- pulveriz. 23/07: Maquinário e Operadores/EPI no FINAL do form (preenchimento
           manual pelo cliente por último). Lógica inalterada (W-07 esconde na fertirrigação;
           a CONFIRMAÇÃO segue exigindo ≥1 operador). -->
      <div class="vfield" style="margin-top:10px" id="aplic-bloco-maquinas"><!-- W-07: ocultado na fertirrigação (gotejo) -->
        <label>Maquinário e Equipamento (adicione todas as máquinas usadas — ex.: trator + pulverizador)</label>
        <div class="vdata-wrap">
        <table class="vdata">
          <thead><tr>
            <th>Máquina / equipamento</th>
            <th style="width:40px"></th>
          </tr></thead>
          <tbody id="aplic-maquinas"></tbody>
        </table>
        </div>
        <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="aplicAddMaquina()">+ Máquina</button>
      </div>

      <div class="vfield" style="margin-top:10px">
        <label>Operadores / EPI (a CONFIRMAÇÃO exige pelo menos 1 operador identificado)</label>
        <div class="vdata-wrap">
        <table class="vdata">
          <thead><tr>
            <th style="width:34%">Operador</th>
            <th style="width:20%">Código EPI</th>
            <th style="width:16%">EPI lavado</th>
            <th style="width:24%">Condição do EPI</th>
            <th style="width:40px"></th>
          </tr></thead>
          <tbody id="aplic-operadores"></tbody>
        </table>
        </div>
        <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="aplicAddOperador()">+ Operador</button>
      </div>

      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Registrar aplicação</button>
      </div>
    </form>
  </div>
</div>
<style>
/* Combobox de produto (11/08): o <select> real fica oculto; o input e a lista
   ficam por cima. A lista é position:FIXED (19/08): com absolute ela era
   CORTADA pelo overflow do .vdata-wrap/.vbox do modal e o usuário tinha que
   rolar para enxergar; fixed escapa do clipping (nenhum ancestral tem
   transform) e o JS cola a posição no input, recalculando em scroll/resize. */
.vcb { position: relative; }
.vcb .vcb-sel { display: none; }
.vcb .vcb-inp { width: 100%; }
.vcb .vcb-lista {
  display: none; position: fixed; z-index: 210; /* acima do .vmodal (60) */
  max-height: 240px; overflow-y: auto; background: #fff;
  border: 1px solid #c9d2c9; border-radius: 6px; box-shadow: 0 6px 18px rgba(0,0,0,.14);
}
.vcb .vcb-item { padding: .38rem .6rem; cursor: pointer; font-size: .92em; white-space: nowrap;
                 overflow: hidden; text-overflow: ellipsis; }
.vcb .vcb-item:hover { background: #eef4ee; }
.vcb .vcb-item.vcb-sel { background: #e2ede2; font-weight: 600; }
.vcb .vcb-vazio { padding: .38rem .6rem; color: #8a8a8a; font-size: .9em; }
</style>
<script>
const APLIC_PRODUTOS = <?= jsvar(array_map(static fn($p) => [
    'id' => (int)$p['id'],
    'nome' => $p['codigo'] . ' — ' . $p['nome'] . ' (' . $p['unidade'] . ', saldo ' . numFmt((float)$p['saldo'], 0) . ')',
    'saldo' => (float)$p['saldo'],          /* aviso de estoque antes de salvar */
    'unidade' => (string)($p['unidade'] ?? ''),
    'tipo' => (string)($p['tipo_insumo'] ?? ''), /* fertirrigação (24/07): filtra p/ adubos e defensivos */
    /* bula registrada pelo RT (DB-27) — pré-preenchimento é CÓPIA editável, Regra 1 */
    'dose_ref' => $p['dose_referencia'] !== null ? (float)$p['dose_referencia'] : null,
    'dose_ref_un' => $p['dose_referencia_unidade'],
    'carencia_ref' => $p['carencia_dias'] !== null ? (int)$p['carencia_dias'] : null,
    'lmr' => $p['lmr_dias'] !== null ? (int)$p['lmr_dias'] : null, /* C-36: limite de dias p/ aplicação antes da colheita (P-49) */
], $produtos)) ?>;
/* item 6.5: catálogo de máquinas p/ o repeater + máquinas já vinculadas (edição) */
const APLIC_MAQUINAS = <?= jsvar(array_map(static fn($id2, $nome) => ['id' => (int)$id2, 'nome' => $nome],
    array_keys($maquinas), array_values($maquinas))) ?>;
const EDIT_MAQUINAS = <?= jsvar(array_map('intval', $editMaquinas)) ?>;
const APLIC_OPERADORES = <?= jsvar(array_map(static fn($id2, $nome) => ['id' => (int)$id2, 'nome' => $nome],
    array_keys($operadores), array_values($operadores))) ?>;
const EDIT_OPERADORES = <?= jsvar(array_map(static fn($o) => [
    'operador' => (int)$o['operador_id'], 'epi' => $o['epi_codigo'],
    'lavagem' => $o['epi_lavagem'] !== null ? (int)$o['epi_lavagem'] : null,
    'condicao' => $o['epi_condicao'],
], $editOperadores)) ?>;
const APLIC_EDIT = <?= jsvar(array_map(static fn($i) => [
    'produto' => (int)$i['produto_id'],
    'dose' => $i['dose_valor'] !== null ? (float)$i['dose_valor'] : null,
    'dose_un' => $i['dose_unidade'],
    'qtd' => $i['quantidade_consumida'] !== null ? (float)$i['quantidade_consumida'] : null,
    'carencia' => $i['carencia_dias'] !== null ? (int)$i['carencia_dias'] : null,
    'reentrada' => $i['intervalo_reentrada_horas'] !== null ? (int)$i['intervalo_reentrada_horas'] : null,
], $editItens)) ?>;

/* W-12 (Wallace 21/07): a unidade da dose vira SELETOR com as bases canônicas —
   assim o auto-cálculo sabe se a dose é por HECTARE (× área) ou por 100 L de calda
   (× volume/100). Legado fora da lista é injetado como opção p/ não perder o valor. */
/* P-11: a dose de LÍQUIDO padroniza por 100 L de calda (mL/100L,
   L/100L) — mL/ha e mL/planta saíram por gerarem consumo errado quando não há
   válvula/volume definido. Bases por ha/planta seguem só p/ sólidos (kg/g). Ordem
   com /100L primeiro reforça o padrão. Legado fora da lista é injetado ao editar. */
const APLIC_DOSE_UN = ['mL/100L','L/100L','g/100L','kg/100L','L/ha','kg/ha','g/ha','g/planta','kg/planta'];
const APLIC_NUM_PLANTAS = <?= jsvar($numPlantasMap) ?>;  /* D-3: nº de plantas por válvula (fertirrigação g/planta) */
const APLIC_DOSE_UN_OPTS = '<option value="">un…</option>' + APLIC_DOSE_UN.map(u => `<option value="${u}">${u}</option>`).join('');
function aplicSetDoseUn(sel, val) {
  if (!sel) return;
  val = (val || '').toString();
  if (val !== '' && !Array.from(sel.options).some(o => o.value === val)) {
    sel.insertAdjacentHTML('beforeend', `<option value="${val}">${val} (legado)</option>`);
  }
  sel.value = val;
}
/* C-12/W-12: qtd consumida AUTOMÁTICA. Por hectare: dose × área (ex.: 150 mL/ha ×
   4 ha = 600 mL). Por 100 L: dose × volume_calda_l/100 (ex.: 200 mL/100L × 800 L =
   1600 mL). Digitação manual prevalece (só preenche vazio ou o que ele calculou). */
function aplicDecBR(v) {
  v = String(v || '').trim();
  if (v === '') return null;
  if (v.includes(',')) v = v.replace(/\./g, '').replace(',', '.');
  const n = parseFloat(v);
  return isNaN(n) ? null : n;
}
/* unidade da dose PADRÃO quando o usuário não escolhe: base do produto + "/ha"
   (assume dose por HECTARE — o caso comum; o usuário troca p/ …/100L se precisar). */
/* VIA preferida da dose no formulário (/ha ou /100L). A última ação do usuário
   define: mexeu na Área → /ha; preencheu o Volume de calda → /100L. Assim, uma
   dose digitada DEPOIS já herda a via certa (resolve o caso "preenchi o volume
   antes do produto"). Default /ha (área é auto-preenchida da válvula). */
let aplicViaPref = '/ha';
/* P-11: a via padrão de LÍQUIDO (mL/L) é sempre por 100 L de calda; sólido (g/kg)
   segue a via preferida (/ha por padrão). Evita recriar mL/ha automaticamente. */
function aplicViaDe(base) {
  return (base === 'mL' || base === 'L') ? '/100L' : aplicViaPref;
}
function aplicUnPadrao(tr) {
  const base = aplicUnBase(tr);
  return base + aplicViaDe(base);
}
/* normaliza a unidade da dose: se vier "crua" (ml/l/g/kg — caso dos produtos
   INDICADOS pelo RT, cuja dose_unidade não tem /ha nem /100L), acrescenta a via
   preferida para o cálculo saber a base. Se já tem via, mantém. */
function aplicNormalizaUn(un) {
  un = String(un || '').trim();
  if (un === '') return '';
  if (/\/(ha|100l|planta)/i.test(un.replace(/\s+/g, ''))) return un;
  const base = ({ ml: 'mL', l: 'L', g: 'g', kg: 'kg' })[un.toLowerCase()] || un;
  return base + aplicViaDe(base); /* P-11: líquido → /100L */
}
/* Volume total de calda (L) = Volume de calda (L/ha) × área. Era travado; DESTRAVADO em 18/08: o cálculo vira SUGESTÃO e valor digitado
   à mão prevalece (dataset.auto, mesmo padrão da qtd consumida). É o total que
   alimenta a dose por 100 L. */
function aplicCalcVolTotal() {
  const rateEl = document.getElementById('aplic-vol-ha');
  const totEl  = document.getElementById('aplic-vol-total');
  const areaEl = document.querySelector('#vm-form input[name="area_aplicada_ha"]');
  if (!totEl) return;
  if (totEl.value !== '' && totEl.dataset.auto !== '1') return; /* manual (ou carregado da edição) prevalece */
  const rate = rateEl ? aplicDecBR(rateEl.value) : null;
  const area = areaEl ? aplicDecBR(areaEl.value) : null;
  if (rate !== null && area !== null && area > 0) {
    totEl.value = String(Math.round(rate * area));
    totEl.dataset.auto = '1';
  }
}
/* base do produto SEM o sufixo (kg/g/L/mL) — p/ montar a unidade por via */
function aplicUnBase(tr) {
  const sel = tr.querySelector('select[name="i_produto[]"]');
  const p = sel ? APLIC_PRODUTOS.find(x => x.id === parseInt(sel.value || '0', 10)) : null;
  const base = (p && p.unidade ? String(p.unidade).toLowerCase().trim() : '');
  return ({ kg: 'kg', g: 'g', l: 'L', ml: 'mL' })[base] || 'L';
}
/* pulveriz. 24/07: a ação mais recente do usuário define a VIA da dose (/ha ou
   /100L) nas linhas com unidade AUTOMÁTICA. Preencheu o Volume de calda → passa
   as auto p/ …/100L (usa o volume); mexeu na Área → volta p/ …/ha. Unidade
   escolhida à mão (dataset.autoun='0') é respeitada. Só em ação real do usuário. */
function aplicTrocaViaAuto(via) {
  document.querySelectorAll('#aplic-itens tr').forEach(function (tr) {
    const unSel = tr.querySelector('[name="i_dose_un[]"]');
    const dose = tr.querySelector('input[name="i_dose[]"]');
    if (!unSel || !dose || dose.value.trim() === '') return;
    if (unSel.value !== '' && unSel.dataset.autoun !== '1') return; /* respeita a escolha manual */
    /* preserva a BASE atual da unidade (mL/L/g/kg) — só troca a via; se vazia, usa a base do produto */
    const atual = aplicNumUnidade(unSel.value);
    const base = atual ? ({ ml: 'mL', l: 'L', g: 'g', kg: 'kg' })[atual] : aplicUnBase(tr);
    /* P-11: líquido fica sempre em /100L (não volta p/ mL/ha ao mexer na Área) */
    const viaEfetiva = (base === 'mL' || base === 'L') ? '/100L' : via;
    aplicSetDoseUn(unSel, base + viaEfetiva);
    unSel.dataset.autoun = '1';
  });
}
/* Conversão da unidade da DOSE para a unidade-base do PRODUTO (pulveriz. 23/07).
   A dose pode ser mL/ha e o estoque em L; sem converter, 868 mL viravam "868 L"
   e o sistema barrava por falta de estoque tendo saldo. Só converte dentro da
   mesma grandeza (volume↔volume, massa↔massa); grandezas incompatíveis mantêm. */
function aplicNumUnidade(un) {
  const m = (un || '').toLowerCase().replace(/\s+/g, '').match(/^(ml|kg|l|g)/);
  return m ? m[1] : '';
}
const APLIC_CONV = { ml: { ml: 1, l: 0.001 }, l: { ml: 1000, l: 1 }, g: { g: 1, kg: 0.001 }, kg: { g: 1000, kg: 1 } };
function aplicConvUn(valor, de, para) {
  de = (de || '').toLowerCase(); para = (para || '').toLowerCase();
  if (!de || !para || de === para) return valor;
  const f = APLIC_CONV[de] && APLIC_CONV[de][para];
  return f !== undefined ? valor * f : valor;
}
function aplicAutoQtd(tr) {
  const qtd = tr.querySelector('input[name="i_qtd[]"]');
  if (!qtd || (qtd.value !== '' && qtd.dataset.auto !== '1')) return; /* manual prevalece */
  const dose = aplicDecBR(tr.querySelector('input[name="i_dose[]"]').value);
  if (dose === null) return;
  const unSel = tr.querySelector('[name="i_dose_un[]"]');
  let un = (unSel.value || '').toLowerCase().replace(/\s+/g, '');
  if (un === '') { aplicSetDoseUn(unSel, aplicUnPadrao(tr)); unSel.dataset.autoun = '1'; un = (unSel.value || '').toLowerCase().replace(/\s+/g, ''); } /* auto-ajusta a unidade */
  let q = null;
  if (un.includes('/100l')) {                 /* por 100 L de calda × volume/100 */
    const volEl = document.querySelector('#vm-form input[name="volume_calda_l"]');
    const vol = aplicDecBR(volEl ? volEl.value : '');
    if (vol !== null) q = dose * vol / 100;
  } else if (un.includes('/planta')) {        /* D-3: por planta × nº de plantas da válvula */
    const talEl = document.getElementById('aplic-talhao');
    const np = talEl ? APLIC_NUM_PLANTAS[talEl.value] : null;
    if (np) q = dose * np;
  } else if (un.includes('/ha')) {            /* por hectare × área */
    const areaEl = document.querySelector('#vm-form input[name="area_aplicada_ha"]');
    const area = aplicDecBR(areaEl ? areaEl.value : '');
    if (area !== null) q = dose * area;
  }
  if (q === null) return;
  /* q está na unidade da DOSE (ex.: mL); o estoque/baixa usa a unidade-base do
     PRODUTO (ex.: L). Converte antes de gravar na qtd consumida. */
  const selP = tr.querySelector('select[name="i_produto[]"]');
  const pRow = selP ? APLIC_PRODUTOS.find(x => x.id === parseInt(selP.value || '0', 10)) : null;
  const baseUn = pRow && pRow.unidade ? String(pRow.unidade).toLowerCase().trim() : '';
  q = aplicConvUn(q, aplicNumUnidade(un), baseUn);
  qtd.value = String(Math.round(q * 1000) / 1000).replace('.', ',');
  qtd.dataset.auto = '1';
  aplicEstoqueTodos();
}
/* C-36: alerta "Produto não indicado para a fase fenológica —
   risco de LMR". Régua: dias até a colheita = fim do ciclo da variedade (maior
   dia_fim das fases) − dias desde a poda na data da aplicação; se o LMR do
   produto (P-49: limite de dias p/ aplicar antes da colheita) for MAIOR que o
   que falta, avisa com ícone + mensagem visível (sem som — decisão da reunião).
   Contexto (window.APLIC_LMR_CTX) é atualizado pelo refresh da fase. */
function aplicLmrRow(tr) {
  let box = tr.querySelector('.aplic-lmr-aviso');
  const sel = tr.querySelector('select[name="i_produto[]"]');
  const p = sel ? APLIC_PRODUTOS.find(x => x.id === parseInt(sel.value || '0', 10)) : null;
  const ctx = window.APLIC_LMR_CTX;
  /* pulveriz. 23/07: sem produto OU produto sem LMR cadastrado → nada a avaliar.
     Antes, quando faltava fenologia/poda o alerta sumia em silêncio e parecia
     "não funcionar"; agora, se o produto TEM LMR mas falta contexto, mostra um
     aviso informativo dizendo o que cadastrar. */
  if (!p || p.lmr === null || p.lmr === undefined) { if (box) box.remove(); return; }
  const temCtx = !!(ctx && ctx.fim > 0);
  const faltam = temCtx ? (ctx.fim - ctx.dias) : null;
  const risco  = temCtx && faltam < p.lmr;
  if (!risco && temCtx) { if (box) box.remove(); return; } /* tem contexto e está ok */
  if (!box) {
    box = document.createElement('div');
    box.className = 'aplic-lmr-aviso';
    box.setAttribute('role', 'alert');
    tr.querySelector('td').appendChild(box);
  }
  if (risco) {
    box.style.cssText = 'margin-top:4px;padding:5px 8px;border-radius:7px;background:#FBEDE9;color:#B3402A;font-size:12px;font-weight:600';
    box.textContent = '⚠ Risco de LMR — este produto exige ' + p.lmr
      + ' dia(s) de carência antes da colheita e faltam só ~' + Math.max(0, faltam)
      + ' dia(s) no ciclo da variedade. Reveja a indicação.';
  } else { /* tem LMR, mas sem fenologia/poda p/ avaliar → informativo (âmbar suave) */
    box.style.cssText = 'margin-top:4px;padding:5px 8px;border-radius:7px;background:#FBF4E6;color:#8A6D1F;font-size:11.5px;font-weight:600';
    box.textContent = 'ℹ LMR deste produto: ' + p.lmr + ' dia(s) antes da colheita. '
      + 'Risco não avaliado automaticamente — cadastre a fenologia aprovada da variedade e a data de poda da válvula.';
  }
}
function aplicLmrTodos() { document.querySelectorAll('#aplic-itens tr').forEach(aplicLmrRow); }
/* Aviso de FALTA DE ESTOQUE antes de salvar (não perder o progresso): compara a
   quantidade (somada por produto, caso apareça em +1 linha) com o saldo atual.
   É aviso — o servidor segue como trava final (A-04). */
function aplicNfBR(n) { return Number(n).toLocaleString('pt-BR', {maximumFractionDigits: 2}); }
function aplicEstoqueRow(tr, somaPorProd) {
  let box = tr.querySelector('.aplic-estoque-aviso');
  const sel = tr.querySelector('select[name="i_produto[]"]');
  const p = sel ? APLIC_PRODUTOS.find(x => x.id === parseInt(sel.value || '0', 10)) : null;
  const q = aplicDecBR(tr.querySelector('input[name="i_qtd[]"]').value) || 0;
  const total = (p && somaPorProd) ? (somaPorProd[p.id] || q) : q;
  const falta = !!(p && p.saldo !== null && p.saldo !== undefined && total > p.saldo + 1e-9);
  if (!falta) { if (box) box.remove(); return; }
  if (!box) {
    box = document.createElement('div');
    box.className = 'aplic-estoque-aviso';
    box.setAttribute('role', 'alert');
    box.style.cssText = 'margin-top:3px;font-size:11px;line-height:1.3;color:#B57C1A';
    tr.querySelector('td').appendChild(box);
  }
  const somando = (somaPorProd && somaPorProd[p.id] > q + 1e-9) ? ' (somando as linhas)' : '';
  box.textContent = '⚠ Acima do saldo — disponível ' + aplicNfBR(p.saldo)
    + (p.unidade ? ' ' + p.unidade : '') + somando + '.';
}
function aplicEstoqueTodos() {
  const linhas = Array.prototype.slice.call(document.querySelectorAll('#aplic-itens tr'));
  const soma = {};
  linhas.forEach(function (tr) {
    const sel = tr.querySelector('select[name="i_produto[]"]');
    const pid = sel ? parseInt(sel.value || '0', 10) : 0;
    if (!pid) return;
    soma[pid] = (soma[pid] || 0) + (aplicDecBR(tr.querySelector('input[name="i_qtd[]"]').value) || 0);
  });
  linhas.forEach(function (tr) { aplicEstoqueRow(tr, soma); });
}
/* Fertirrigação: no modo IF a lista de PRODUTOS mostra apenas
   ADUBOS/FERTILIZANTES e DEFENSIVOS (tipo_insumo). Nos demais tipos o catálogo é
   completo. O produto JÁ selecionado é sempre preservado (não some ao trocar o
   tipo nem ao editar um lançamento antigo com outro tipo de insumo). */
function aplicIsFerti() {
  const t = document.querySelector('#vm-form select[name="tipo"]');
  return !!t && t.value === 'fertirrigacao';
}
/* termo = busca digitada na propria linha. O item ja escolhido
   NUNCA e filtrado para fora, senao a linha perderia o valor ao digitar. */
function aplicProdOptions(selectedId, termo) {
  const ferti = aplicIsFerti();
  const sel = parseInt(selectedId || '0', 10);
  const q = (termo || '').trim().toLowerCase();
  const lista = APLIC_PRODUTOS.filter(p =>
    (!ferti || p.tipo === 'fertilizante' || p.tipo === 'defensivo' || p.id === sel)
    && (q === '' || p.id === sel || p.nome.toLowerCase().includes(q)));
  return ['<option value="">Selecione…</option>']
    .concat(lista.map(p => `<option value="${p.id}"${p.id === sel ? ' selected' : ''}>${esc(p.nome)}</option>`)).join('');
}
/* ── Combobox de produto (pedido 11/08: busca DENTRO do seletor) ───────────
   O <select name="i_produto[]"> continua no DOM, oculto, e continua sendo o
   PRIMEIRO select da linha — varios trechos deste arquivo dependem disso
   (ex.: tr.querySelector('select') no handler da bula, logo abaixo). O
   combobox apenas escreve nele e dispara 'change'; nenhum outro handler mudou. */
function aplicCbTexto(sel) {
  const o = sel.options[sel.selectedIndex];
  return (o && o.value) ? o.textContent : '';
}
function aplicCbFecha(cb) {
  cb.querySelector('.vcb-lista').style.display = 'none';
  cb.querySelector('.vcb-inp').setAttribute('aria-expanded', 'false');
}
/* Cola a lista (fixed) no input; abre para CIMA quando falta espaço embaixo. */
function aplicCbPosiciona(cb) {
  const lista = cb.querySelector('.vcb-lista');
  if (lista.style.display === 'none') return;
  const r = cb.querySelector('.vcb-inp').getBoundingClientRect();
  const alvo = Math.min(240, lista.scrollHeight || 240);
  const baixo = window.innerHeight - r.bottom;
  const paraCima = baixo < alvo + 8 && r.top > baixo;
  const espaco = paraCima ? r.top - 8 : baixo - 8;
  lista.style.maxHeight = Math.min(240, Math.max(120, espaco)) + 'px';
  const alt = Math.min(alvo, Math.min(240, Math.max(120, espaco)));
  lista.style.left  = r.left + 'px';
  lista.style.width = r.width + 'px';
  lista.style.top   = (paraCima ? Math.max(4, r.top - alt - 4) : r.bottom + 2) + 'px';
}
/* Rolagem/resize com lista aberta (o input se move na tela) → recola. Capture:
   pega também o scroll interno do .vbox do modal e do .vdata-wrap. */
['scroll', 'resize'].forEach(function (ev) {
  window.addEventListener(ev, function () {
    document.querySelectorAll('.vcb .vcb-lista').forEach(function (l) {
      if (l.style.display !== 'none') aplicCbPosiciona(l.closest('.vcb'));
    });
  }, true);
});
function aplicCbEscolhe(cb, id) {
  const sel = cb.querySelector('.vcb-sel');
  sel.innerHTML = aplicProdOptions(String(id), '');  /* garante a opcao presente */
  sel.value = String(id);
  cb.querySelector('.vcb-inp').value = aplicCbTexto(sel);
  aplicCbFecha(cb);
  sel.dispatchEvent(new Event('change', { bubbles: true }));
}
function aplicCbAbre(cb, termo) {
  const sel = cb.querySelector('.vcb-sel');
  const lista = cb.querySelector('.vcb-lista');
  const selId = parseInt(sel.value || '0', 10);
  const ferti = aplicIsFerti();
  const q = (termo || '').trim().toLowerCase();
  const itens = APLIC_PRODUTOS.filter(p =>
    (!ferti || p.tipo === 'fertilizante' || p.tipo === 'defensivo' || p.id === selId)
    && (q === '' || p.nome.toLowerCase().includes(q)));
  lista.innerHTML = '';
  if (!itens.length) {
    const v = document.createElement('div');
    v.className = 'vcb-vazio';
    v.textContent = 'nenhum produto encontrado';
    lista.appendChild(v);
  }
  itens.slice(0, 300).forEach(p => {
    const it = document.createElement('div');
    it.className = 'vcb-item' + (p.id === selId ? ' vcb-sel' : '');
    it.setAttribute('role', 'option');
    it.textContent = p.nome;                    /* textContent: nao interpreta HTML */
    /* mousedown, nao click: dispara ANTES do blur do input fechar a lista */
    it.addEventListener('mousedown', function (ev) { ev.preventDefault(); aplicCbEscolhe(cb, p.id); });
    lista.appendChild(it);
  });
  lista.style.display = 'block';
  aplicCbPosiciona(cb); /* fixed: posição calculada a cada abertura */
  cb.querySelector('.vcb-inp').setAttribute('aria-expanded', 'true');
}
function aplicCbInit(cb) {
  if (!cb || cb.dataset.pronto === '1') return;
  cb.dataset.pronto = '1';
  const sel = cb.querySelector('.vcb-sel');
  const inp = cb.querySelector('.vcb-inp');
  inp.value = aplicCbTexto(sel);
  inp.addEventListener('focus', function () { inp.select(); aplicCbAbre(cb, ''); });
  inp.addEventListener('input', function () { aplicCbAbre(cb, inp.value); });
  inp.addEventListener('blur', function () {
    /* atraso: deixa o mousedown do item rodar antes de fechar/restaurar */
    setTimeout(function () { aplicCbFecha(cb); inp.value = aplicCbTexto(sel); }, 150);
  });
  inp.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { aplicCbFecha(cb); inp.blur(); return; }
    if (ev.key === 'Enter') {
      const lista = cb.querySelector('.vcb-lista');
      const primeiro = lista.querySelector('.vcb-item');
      if (primeiro && lista.style.display !== 'none') {
        ev.preventDefault();
        primeiro.dispatchEvent(new MouseEvent('mousedown'));
      }
    }
  });
}
function aplicRefiltraProdutos() {
  document.querySelectorAll('#aplic-itens select[name="i_produto[]"]').forEach(sel => {
    const cur = sel.value;
    sel.innerHTML = aplicProdOptions(cur, '');
    sel.value = cur;
    const cb = sel.closest('.vcb');
    if (cb) cb.querySelector('.vcb-inp').value = aplicCbTexto(sel);
  });
}
function aplicAddItem(preset) {
  const tb = document.getElementById('aplic-itens');
  const tr = document.createElement('tr');
  const opts = aplicProdOptions(preset && preset.produto ? preset.produto : '');
  /* C-38: carência/reentrada saíram do layout — viram hidden (preservam o valor
     de aplicações antigas na edição; vazio = servidor copia da bula do produto) */
  tr.innerHTML = `
    <td><div class="vcb">
          <select name="i_produto[]" class="vcb-sel">${opts}</select>
          <input type="text" class="vcb-inp" placeholder="Selecione…" autocomplete="off" role="combobox" aria-expanded="false">
          <div class="vcb-lista" role="listbox"></div>
        </div>
        <input type="hidden" name="i_carencia[]"><input type="hidden" name="i_reentrada[]"></td>
    <td><input type="text" name="i_dose[]" placeholder="0,00" style="text-align:right"></td>
    <td><select name="i_dose_un[]">${APLIC_DOSE_UN_OPTS}</select></td>
    <td><input type="text" name="i_qtd[]" placeholder="0,00" style="text-align:right"></td>
    <td><button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="this.closest('tr').remove(); aplicEstoqueTodos();">×</button></td>`;
  tb.appendChild(tr);
  aplicCbInit(tr.querySelector('.vcb'));   /* busca dentro do seletor (11/08) */
  /* C-12/W-12: dose/unidade recalculam a qtd; digitar a qtd manualmente vira manual */
  tr.querySelector('input[name="i_dose[]"]').addEventListener('input', () => aplicAutoQtd(tr));
  tr.querySelector('[name="i_dose_un[]"]').addEventListener('change', function () { this.dataset.autoun = '0'; aplicAutoQtd(tr); });
  tr.querySelector('input[name="i_qtd[]"]').addEventListener('input', function () {
    /* pulveriz. 24/07: digitar = MANUAL (prevalece). Esvaziar volta ao modo
       automático, mas SEM reencher na hora — senão não dá p/ limpar e digitar o
       próprio valor (snap-back). O recálculo automático acontece ao mudar
       dose/unidade/área/volume/produto/válvula, ou ao sair do campo vazio. */
    this.dataset.auto = this.value.trim() === '' ? '1' : '0';
    aplicEstoqueTodos();
  });
  tr.querySelector('input[name="i_qtd[]"]').addEventListener('change', function () {
    /* saiu do campo deixando-o vazio → recalcula automático a partir da dose */
    if (this.value.trim() === '') { this.dataset.auto = '1'; aplicAutoQtd(tr); }
  });
  /* bula do produto → pré-preenche dose/unidade/carência VAZIAS (cópia editável — Regra 1) */
  tr.querySelector('select').addEventListener('change', function () {
    const p = APLIC_PRODUTOS.find(x => x.id === parseInt(this.value || '0', 10));
    if (!p) return;
    const dose = tr.querySelector('input[name="i_dose[]"]');
    const doseUn = tr.querySelector('[name="i_dose_un[]"]');
    const car = tr.querySelector('input[name="i_carencia[]"]');
    if (p.dose_ref !== null && dose && dose.value === '') {
      dose.value = String(p.dose_ref).replace('.', ',');
      if (p.dose_ref_un && doseUn && doseUn.value === '') { aplicSetDoseUn(doseUn, p.dose_ref_un); doseUn.dataset.autoun = '0'; } /* unidade da bula = base do RT, respeitada */
    }
    if (p.carencia_ref !== null && car && car.value === '') car.value = String(p.carencia_ref);
    aplicAutoQtd(tr); /* C-12: dose da bula preenchida → calcula a qtd */
    aplicLmrRow(tr);  /* C-36: avalia risco de LMR do produto escolhido */
    aplicEstoqueTodos(); /* produto trocado → reavalia saldo (mesmo qtd inalterada) */
  });
  if (preset) {
    tr.querySelector('select').value = String(preset.produto);
    /* espelha no combobox o produto que veio pronto (edicao / indicados) */
    const cbP = tr.querySelector('.vcb');
    if (cbP) cbP.querySelector('.vcb-inp').value = aplicCbTexto(cbP.querySelector('.vcb-sel'));
    if (preset.dose !== null) tr.querySelector('input[name="i_dose[]"]').value = String(preset.dose).replace('.', ',');
    if (preset.dose_un) {
      /* produtos indicados: unidade crua (ml/L) ganha a via preferida → calcula.
         crua (via inferida) = auto; já com via = respeita a escolha do RT. */
      const unEl = tr.querySelector('[name="i_dose_un[]"]');
      const norm = aplicNormalizaUn(preset.dose_un);
      aplicSetDoseUn(unEl, norm);
      unEl.dataset.autoun = (norm !== String(preset.dose_un).trim()) ? '1' : '0';
    }
    if (preset.qtd !== null) tr.querySelector('input[name="i_qtd[]"]').value = String(preset.qtd).replace('.', ',');
    if (preset.carencia !== null) tr.querySelector('input[name="i_carencia[]"]').value = String(preset.carencia);
    if (preset.reentrada !== null) tr.querySelector('input[name="i_reentrada[]"]').value = String(preset.reentrada);
    /* qtd não veio pronta → calcula agora a partir da dose/unidade/volume/área */
    if (preset.qtd === null || preset.qtd === undefined) aplicAutoQtd(tr);
    aplicEstoqueTodos();
    aplicLmrRow(tr);
  }
}
if (APLIC_EDIT.length) APLIC_EDIT.forEach(i => aplicAddItem(i));
else aplicAddItem();
aplicEstoqueTodos(); /* avalia estoque das linhas pré-preenchidas (edição/preset) */
/* C-12: área aplicada mudou → recalcula a qtd automática de todas as linhas */
(function () {
  const areaEl = document.querySelector('#vm-form input[name="area_aplicada_ha"]');
  if (areaEl) areaEl.addEventListener('input', (e) => {
    if (e.isTrusted) areaEl.dataset.auto = '0'; /* área digitada à mão — a soma das válvulas para de sobrescrever */
    aplicCalcVolTotal(); /* área mudou → recalcula o Volume total sugerido */
    /* usuário mexeu na Área → dose por HECTARE nas linhas automáticas */
    if (e.isTrusted) { aplicViaPref = '/ha'; aplicTrocaViaAuto('/ha'); }
    document.querySelectorAll('#aplic-itens tr').forEach(aplicAutoQtd);
    aplicEstoqueTodos();
  });
  /* W-12/24-07: o usuário preenche o "Volume de calda (L/ha)"; o Volume total
     (travado) = calda × área, e é ele que a dose por 100 L usa. Digitar a calda
     → dose por 100 L nas linhas automáticas. */
  const rateEl = document.getElementById('aplic-vol-ha');
  if (rateEl) rateEl.addEventListener('input', (e) => {
    aplicCalcVolTotal();
    if (e.isTrusted) { aplicViaPref = '/100L'; aplicTrocaViaAuto('/100L'); }
    document.querySelectorAll('#aplic-itens tr').forEach(aplicAutoQtd);
    aplicEstoqueTodos();
  });
  /* pedido 18/08: Volume total destravado — digitou à mão, o valor passa a
     mandar (auto=0) e as doses por 100 L recalculam pelo total informado */
  const totEl = document.getElementById('aplic-vol-total');
  if (totEl) totEl.addEventListener('input', (e) => {
    if (e.isTrusted) { totEl.dataset.auto = '0'; aplicViaPref = '/100L'; aplicTrocaViaAuto('/100L'); }
    document.querySelectorAll('#aplic-itens tr').forEach(aplicAutoQtd);
    aplicEstoqueTodos();
  });
  aplicCalcVolTotal(); /* estado inicial (edição) */
})();

/* item 6.5 (gestor 17/07): múltiplas máquinas — repeater de selects. Cada linha é
   um <select name="maquina_ids[]">; o POST valida cada uma no tenant e grava na junção. */
function aplicAddMaquina(preset) {
  const tb = document.getElementById('aplic-maquinas');
  const tr = document.createElement('tr');
  const opts = ['<option value="">— Selecione a máquina —</option>']
    .concat(APLIC_MAQUINAS.map(m => `<option value="${m.id}">${esc(m.nome)}</option>`)).join('');
  tr.innerHTML = `
    <td><select name="maquina_ids[]">${opts}</select></td>
    <td><button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="this.closest('tr').remove()">×</button></td>`;
  tb.appendChild(tr);
  if (preset) tr.querySelector('select').value = String(preset);
}
if (EDIT_MAQUINAS.length) EDIT_MAQUINAS.forEach(m => aplicAddMaquina(m));
else aplicAddMaquina();

/* A1-48b (DB-51): produtos indicados pelo RT por alvo — cada linha tem
   "usar na calda" que cria o item com dose PRÉ-COPIADA (editável, Regra 1) */
const APLIC_ALVO_PRODUTOS = <?= jsvar(array_map(static fn($x) => [
    'alvo' => (int)$x['alvo_id'], 'produto' => (int)$x['produto_id'],
    'nome' => (string)$x['produto_nome'],
    'dose' => $x['dose'] !== null ? (float)$x['dose'] : null,
    'dose_un' => $x['dose_unidade'], 'volume' => $x['volume_calda_ha'] !== null ? (float)$x['volume_calda_ha'] : null,
    'obs' => $x['observacao'],
    'quem' => trim(($x['cadastrado_por_nome'] ?? '') . ($x['cadastrado_em'] ? ' em ' . date('d/m/Y', strtotime((string)$x['cadastrado_em'])) : '')),
], $alvoProdutos)) ?>;

function aplicMostraIndicados() {
  const alvo = parseInt(document.getElementById('aplic-alvo').value || '0', 10);
  const box = document.getElementById('aplic-indicados');
  const lista = document.getElementById('aplic-indicados-lista');
  const itens = APLIC_ALVO_PRODUTOS.filter(x => x.alvo === alvo);
  if (!alvo || !itens.length) {
    box.style.display = alvo ? '' : 'none';
    lista.innerHTML = alvo ? '<span class="vhint">Nenhum produto indicado pelo RT para este alvo ainda — cadastre em MIP → Alvos ou escolha livremente abaixo.</span>' : '';
    return;
  }
  box.style.display = '';
  lista.innerHTML = itens.map((x, i) => `
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:3px 0;flex-wrap:wrap">
      <span><strong>${esc(x.nome)}</strong>
        ${x.dose !== null ? ' · dose ' + String(x.dose).replace('.', ',') + ' ' + (x.dose_un || '') : ''}
        ${x.volume !== null ? ' · calda ' + String(x.volume).replace('.', ',') + ' L/ha' : ''}
        ${x.obs ? ' · ' + esc(x.obs) : ''}
        <span class="vhint">${x.quem ? '(indicado por ' + x.quem + ')' : ''}</span></span>
      <button class="vbtn vbtn-ghost vbtn-sm" type="button" data-idx="${i}" onclick="aplicUsarIndicado(${x.alvo},${x.produto})">usar na calda</button>
    </div>`).join('');
}
function aplicUsarIndicado(alvo, produto) {
  const x = APLIC_ALVO_PRODUTOS.find(v => v.alvo === alvo && v.produto === produto);
  if (!x) return;
  aplicAddItem({ produto: x.produto, dose: x.dose, dose_un: x.dose_un, qtd: null, carencia: null, reentrada: null });
}
document.getElementById('aplic-alvo').addEventListener('change', aplicMostraIndicados);

/* item 6.1: ao escolher a válvula, auto-seleciona a safra do vínculo mais recente
   (a fase fenológica já é automática pela data — A1-29). Registrado ANTES do
   bloco pre_talhao para que o change disparado por ele passe por aqui, e o
   pre_safra (linha abaixo) continue tendo a palavra final. */
const APLIC_VINC_SAFRA = <?= jsvar($vinculosDF) ?>;
/* item 6.16 + C-03: último monitoramento MIP por válvula (talhao → {data, alvos[]}) —
   TODOS os alvos da última leitura, não só o primeiro */
const APLIC_ULT_MON = <?= jsvar($aplicUltMonMap) ?>;
/* item 6.16: exibe o último monitoramento da válvula (só leitura). Valor via
   textContent (alvo vem do cadastro) → sem risco de XSS (lição A1-57). */
function aplicUltMonBox() {
  const box = document.getElementById('aplic-ult-mon');
  const tal = document.getElementById('aplic-talhao');
  if (!box || !tal) return;
  box.textContent = '';
  const m = APLIC_ULT_MON[tal.value];
  if (m) {
    const b = document.createElement('strong');
    b.textContent = 'Último monitoramento MIP (' + m.data + '): ';
    box.appendChild(b);
    /* pulveriz. 23/07: praga em destaque + % colorida pelo nível de ação
       (>= ação = vermelho "acima"; perto = âmbar; abaixo = verde). */
    (m.alvos || []).forEach(function (a, i) {
      if (i > 0) box.appendChild(document.createTextNode('  ·  '));
      const praga = document.createElement('span');
      praga.textContent = a.praga;
      praga.style.cssText = 'font-weight:700;color:#7A2E12';
      box.appendChild(praga);
      box.appendChild(document.createTextNode(' '));
      const pct = document.createElement('span');
      const val = Number(a.nivel);
      let cor = '#1E6B34', bg = '#E7F1E9'; /* verde: abaixo do nível de ação */
      if (a.acao !== null && a.acao !== undefined) {
        if (val >= a.acao) { cor = '#B3402A'; bg = '#FBEDE9'; }              /* acima da ação */
        else if (val >= a.acao * 0.7) { cor = '#8A6D1F'; bg = '#FBF4E6'; }   /* chegando perto */
      } else { cor = '#005059'; bg = '#E4EEEF'; }                            /* sem nível de ação cadastrado */
      pct.textContent = aplicNfBR(val) + (a.un || '%')
        + (a.acao !== null && a.acao !== undefined ? ' / ação ' + aplicNfBR(a.acao) + (a.un || '%') : '');
      pct.style.cssText = 'font-weight:700;color:' + cor + ';background:' + bg
        + ';padding:1px 6px;border-radius:6px';
      box.appendChild(pct);
      if (a.local) {
        const loc = document.createElement('span');
        loc.textContent = ' (' + a.local + ')';
        loc.style.cssText = 'color:#7A6A55';
        box.appendChild(loc);
      }
    });
    box.style.display = '';
  } else { box.style.display = 'none'; }
}
(function () {
  const tal = document.getElementById('aplic-talhao');
  const saf = document.querySelector('#vm-form select[name="safra_id"]');
  if (!tal) return;
  tal.addEventListener('change', () => {
    if (saf) {
      const list = APLIC_VINC_SAFRA[tal.value] || [];
      if (list.length) saf.value = String(list[0].safra); /* vínculo mais recente (6.1) */
    }
    aplicUltMonBox(); /* 6.16 */
    /* D-3: mudou a válvula → recalcula doses por planta (nº de plantas muda) */
    document.querySelectorAll('#aplic-itens tr').forEach(aplicAutoQtd);
  });
  aplicUltMonBox(); /* estado inicial (edição/pre_talhao) */
})();

/* Opção B / gestor 17/07: campo "Fase fenológica" AUTOMÁTICO pela válvula —
   resolve a fase da VARIEDADE da válvula pela data (dias desde a poda), como no
   apontamento. value 'v:<id>' = fase por variedade (autoritativa) auto-selecionada;
   'c:<id>' = estágio por cultura (fallback); '' = automático pela data (POST resolve).
   Registrado APÓS o auto-safra p/ ler a safra já escolhida. */
const APLIC_VARFASES = <?= jsvar($varFasesDFMap) ?>;
const APLIC_FENOLOGIAS = <?= jsvar(array_map(static fn($id, $lb) => ['id' => (int)$id, 'label' => (string)$lb],
    array_keys($fenologias), array_values($fenologias))) ?>;
const APLIC_EDIT_FASE = <?= jsvar($edit
    ? (($edit['variedade_fase_id'] ?? null) !== null ? 'v:' . (int)$edit['variedade_fase_id']
       : (($edit['fenologia_id'] ?? null) !== null ? 'c:' . (int)$edit['fenologia_id'] : ''))
    : '') ?>;
(function () {
  const tal = document.getElementById('aplic-talhao');
  const sel = document.getElementById('aplic-feno');
  if (!tal || !sel) return;
  const saf  = document.querySelector('#vm-form select[name="safra_id"]');
  const dt   = document.querySelector('#vm-form input[name="data"]');
  const dtPrev = document.getElementById('aplic-data-prev');
  /* C-11: data-base p/ fenologia/LMR — realizada (registro direto) ou prevista (OS) */
  const dtBase = () => (dt && !dt.disabled && dt.value) ? dt.value : ((dtPrev && dtPrev.value) || '');
  const hint = document.getElementById('aplic-feno-hint');
  function vinculoAtual() {
    const list = APLIC_VINC_SAFRA[tal.value] || [];
    if (!list.length) return null;
    const sid = parseInt((saf && saf.value) || '0', 10);
    return list.find(v => v.safra === sid) || list[0];
  }
  function faseAuto(v, fases) {
    if (!v || !v.poda || !dtBase()) return null;
    const d0 = String(v.poda).slice(0, 10), d1 = String(dtBase()).slice(0, 10);
    const dias = Math.floor((Date.parse(d1 + 'T00:00:00') - Date.parse(d0 + 'T00:00:00')) / 86400000);
    if (isNaN(dias) || dias < 0) return null;
    return fases.find(f => f.ini <= dias && dias < f.fim) || null;
  }
  /* item 6.1: volume de calda (L/ha) da fase da variedade → sugere o total (L/ha × área).
     Filosofia da Regra 1: é SUGESTÃO editável — só preenche o total se estiver vazio. */
  let curFases = null;
  function faseSelecionada() {
    const val = sel.value || '';
    if (!curFases || !val.startsWith('v:')) return null;
    const id = parseInt(val.slice(2), 10);
    return curFases.find(f => f.id === id) || null;
  }
  function sugereCalda() {
    /* sugere a taxa de calda (L/ha) da variedade — só se o campo estiver vazio
       (não sobrescreve o que o usuário digitou); o Volume total recalcula. */
    const volHa = document.getElementById('aplic-vol-ha');
    const f = faseSelecionada();
    if (f && f.vol !== null && f.vol !== undefined && volHa && volHa.value.trim() === '') {
      volHa.value = String(f.vol);
    }
    aplicCalcVolTotal();
  }
  function refresh(keep) {
    const prev = keep || sel.value || '';
    const v = vinculoAtual();
    sel.innerHTML = '<option value="">— Automática pela data —</option>';
    const fases = (v && v.variedade) ? (APLIC_VARFASES[v.variedade] || null) : null;
    curFases = (fases && fases.length) ? fases : null;
    /* C-36: contexto do risco de LMR — dias desde a poda na data da aplicação
       e fim do ciclo da variedade (maior dia_fim). Sem fenologia/poda → null
       (sem dado, sem alerta — o sistema não inventa). */
    window.APLIC_LMR_CTX = null;
    if (v && v.poda && dtBase() && fases && fases.length) {
      const dias = Math.floor((Date.parse(String(dtBase()).slice(0, 10) + 'T00:00:00')
                             - Date.parse(String(v.poda).slice(0, 10) + 'T00:00:00')) / 86400000);
      const fim = Math.max(...fases.map(f => f.fim));
      if (!isNaN(dias) && dias >= 0 && fim > 0) window.APLIC_LMR_CTX = { dias: dias, fim: fim };
    }
    aplicLmrTodos();
    if (fases && fases.length) {
      fases.forEach(f => sel.add(new Option(f.nome, 'v:' + f.id)));
      let target = prev;
      if (!target) { const a = faseAuto(v, fases); if (a) target = 'v:' + a.id; }
      if (target && [...sel.options].some(o => o.value === target)) sel.value = target;
      if (hint) hint.textContent = 'Puxada automaticamente da variedade da válvula (dias desde a poda); ajustável.';
      sugereCalda();
      return;
    }
    /* fallback: catálogo por cultura (A1-29) */
    APLIC_FENOLOGIAS.forEach(f => sel.add(new Option(f.label, 'c:' + f.id)));
    if (prev && [...sel.options].some(o => o.value === prev)) sel.value = prev;
    if (hint) hint.textContent = 'Sem fenologia por variedade — selecione o estágio por cultura ou deixe automático pela data.';
  }
  tal.addEventListener('change', () => refresh(null));
  if (saf) saf.addEventListener('change', () => refresh(null));
  if (dt)  dt.addEventListener('change', () => refresh(null));
  if (dtPrev) dtPrev.addEventListener('change', () => refresh(null)); /* C-11 */
  sel.addEventListener('change', sugereCalda);
  /* área é auto-preenchida por outro listener no change da válvula (roda depois deste);
     ao ter a área, re-sugere o total de calda se ainda estiver vazio */
  const areaEl = document.querySelector('#vm-form input[name="area_aplicada_ha"]');
  if (areaEl) { areaEl.addEventListener('input', sugereCalda); areaEl.addEventListener('change', sugereCalda); }
  refresh(APLIC_EDIT_FASE || '');
})();

/* C-11 (dois estágios, mig 167): "Data realizada" só existe no REGISTRO DIRETO
   (aplicação já executada). Ao "Emitir OS (DF/IF)" o campo some e deixa de ser
   exigido — a data realizada é registrada na CONFIRMAÇÃO da execução; a "Data
   prevista" vira obrigatória (o servidor espelha a regra). */
(function () {
  const radios = document.querySelectorAll('#vm-form input[name="modo"]');
  const field  = document.getElementById('aplic-data-real-field');
  if (!radios.length || !field) return; /* edição: o status do documento decide no PHP */
  const real    = field.querySelector('input[name="data"]');
  const prev    = document.getElementById('aplic-data-prev');
  const prevReq = document.getElementById('aplic-prev-req');
  function aplicModoData() {
    const emitir = [...radios].some(r => r.checked && r.value === 'emitir');
    field.style.display = emitir ? 'none' : '';
    if (real) { real.disabled = emitir; real.required = !emitir; }
    if (prev) {
      prev.required = emitir;
      if (emitir && !prev.value) prev.value = new Date().toISOString().slice(0, 10);
      prev.dispatchEvent(new Event('change')); /* re-resolve fenologia/LMR pela nova data-base */
    }
    if (prevReq) prevReq.style.display = emitir ? '' : 'none';
  }
  radios.forEach(r => r.addEventListener('change', aplicModoData));
  aplicModoData();
})();

/* A1-48a: chegada do Fluxo de Campo com contexto (?novo=1&pre_talhao=&pre_safra=&pre_alvo=) —
   abre o modal já posicionado; o restante do fluxo DF/IF é o de sempre */
(function () {
  const p = new URLSearchParams(location.search);
  if (!p.get('pre_talhao')) return;
  if (typeof vModalOpen === 'function') vModalOpen('vm-form');
  const tal = document.getElementById('aplic-talhao');
  if (tal) { tal.value = p.get('pre_talhao'); tal.dispatchEvent(new Event('change')); }
  const saf = document.querySelector('#vm-form select[name="safra_id"]');
  if (saf && p.get('pre_safra')) saf.value = p.get('pre_safra');
  const alvo = document.getElementById('aplic-alvo');
  if (alvo && p.get('pre_alvo')) { alvo.value = p.get('pre_alvo'); aplicMostraIndicados(); }
})();

/* operadores/EPI (A1-30/DB-33) */
function aplicAddOperador(preset) {
  const tb = document.getElementById('aplic-operadores');
  const tr = document.createElement('tr');
  const opts = ['<option value="">Selecione…</option>']
    .concat(APLIC_OPERADORES.map(o => `<option value="${o.id}">${esc(o.nome)}</option>`)).join('');
  tr.innerHTML = `
    <td><select name="op_operador[]">${opts}</select></td>
    <td><input type="text" name="op_epi[]" placeholder="ex.: EPI-07"></td>
    <td><select name="op_lavagem[]">
      <option value="">—</option><option value="1">Sim</option><option value="0">Não</option></select></td>
    <td><input type="text" name="op_condicao[]" placeholder="bom / desgastado…"></td>
    <td><button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="this.closest('tr').remove()">×</button></td>`;
  tb.appendChild(tr);
  if (preset) {
    tr.querySelector('select[name="op_operador[]"]').value = String(preset.operador);
    if (preset.epi) tr.querySelector('input[name="op_epi[]"]').value = preset.epi;
    if (preset.lavagem !== null) tr.querySelector('select[name="op_lavagem[]"]').value = String(preset.lavagem);
    if (preset.condicao) tr.querySelector('input[name="op_condicao[]"]').value = preset.condicao;
  }
}
EDIT_OPERADORES.forEach(o => aplicAddOperador(o));

/* blocos por forma de aplicação (A1-30) */
(function () {
  var forma = document.querySelector('#vm-form select[name="forma_aplicacao"]');
  if (!forma) return;
  function toggleForma() {
    document.getElementById('aplic-drone').style.display  = forma.value === 'drone' ? '' : 'none';
    document.getElementById('aplic-trator').style.display = forma.value === 'trator_pulverizador' ? '' : 'none';
  }
  forma.addEventListener('change', toggleForma);
  toggleForma();
})();

/* pré-preenche a área aplicada com a área cadastral da válvula (editável).
   Multi-válvula (18/08): a sugestão vira a SOMA principal + extras marcadas;
   área digitada à mão (dataset.auto='0') não é sobrescrita. O checkbox da
   válvula principal fica desabilitado (ela já está no cabeçalho). */
(function () {
  var sel = document.getElementById('aplic-talhao');
  var box = document.getElementById('aplic-valvulas-extra');
  if (!sel) return;
  var campo = document.querySelector('input[name="area_aplicada_ha"]');
  function extras() {
    return box ? Array.prototype.slice.call(box.querySelectorAll('input[type="checkbox"]')) : [];
  }
  function syncPrincipal() {
    extras().forEach(function (c) {
      var ehPrincipal = c.value === sel.value;
      if (ehPrincipal) c.checked = false;
      c.disabled = ehPrincipal;
      c.closest('label').style.opacity = ehPrincipal ? '.45' : '';
    });
  }
  function areaSugerida() {
    if (!campo) return;
    if (campo.value !== '' && campo.dataset.auto !== '1') return; /* manual prevalece */
    var o = sel.options[sel.selectedIndex];
    var soma = o && o.dataset.area ? parseFloat(o.dataset.area) : NaN;
    if (isNaN(soma)) return;
    extras().forEach(function (c) {
      if (c.checked && c.dataset.area) soma += parseFloat(c.dataset.area);
    });
    campo.value = soma.toFixed(2).replace('.', ',');
    campo.dataset.auto = '1';
    /* item 6.1: avisa o sugeridor de calda (que resolve o total L/ha × área);
       evento sintético (isTrusted=false) não mexe na via da dose nem no auto */
    campo.dispatchEvent(new Event('input'));
  }
  sel.addEventListener('change', function () { syncPrincipal(); areaSugerida(); });
  if (box) box.addEventListener('change', areaSugerida);
  syncPrincipal();
})();

/* item 6.3 (gestor 17/07): CLIMA automático via Open-Meteo pela lat/lon do talhão —
   mesmo padrão de agro/mapa.php (fetch nativo, sem libs). Ao selecionar a válvula,
   preenche vento/temperatura/umidade (ajustável; o usuário pode sobrescrever). Se o
   talhão não tem coordenadas, não faz nada (silencioso). */
(function () {
  var sel = document.getElementById('aplic-talhao');
  if (!sel) return;
  var reqId = 0;
  sel.addEventListener('change', function () {
    var o = this.options[this.selectedIndex];
    var la = o ? parseFloat(o.dataset.lat || '') : NaN;
    var ln = o ? parseFloat(o.dataset.lon || '') : NaN;
    if (isNaN(la) || isNaN(ln)) return;
    var vento = document.querySelector('#vm-form input[name="clima_vento_kmh"]');
    var temp  = document.querySelector('#vm-form input[name="clima_temperatura_c"]');
    var umid  = document.querySelector('#vm-form input[name="clima_umidade_pct"]');
    var req = ++reqId;
    var u = 'https://api.open-meteo.com/v1/forecast?latitude=' + la.toFixed(4) + '&longitude=' + ln.toFixed(4)
      + '&current=temperature_2m,relative_humidity_2m,wind_speed_10m';
    fetch(u).then(function (r) { return r.json(); }).then(function (d) {
      if (req !== reqId || !d || !d.current) return;
      var c = d.current;
      if (vento && c.wind_speed_10m != null) vento.value = (Math.round(c.wind_speed_10m * 10) / 10).toString().replace('.', ',');
      if (temp && c.temperature_2m != null)  temp.value  = (Math.round(c.temperature_2m * 10) / 10).toString().replace('.', ',');
      if (umid && c.relative_humidity_2m != null) umid.value = String(Math.round(c.relative_humidity_2m));
      if (typeof aplicVentoBadge === 'function') aplicVentoBadge(); /* C-10 */
    }).catch(function () { /* clima indisponível (offline) — silencioso, campos ficam manuais */ });
  });
})();

/* C-10: classificação do VENTO em Fraco/Moderado/Forte por
   faixas PARAMETRIZÁVEIS (tenant_parametros 'clima.vento_faixas' — defaults
   fraco < 8, moderado 8–15, forte > 15 km/h; faixas VALIDADAS por Wallace 19/07).
   Badge ao lado do campo; recalcula ao digitar e no auto do Open-Meteo. */
const APLIC_VENTO_FAIXAS = <?= jsvar(
    json_decode((string)(vero_srv_param('clima.vento_faixas') ?? ''), true)
        ?: ['moderado_min' => 8, 'forte_min' => 15]) ?>;
function aplicVentoBadge() {
  const el = document.querySelector('#vm-form input[name="clima_vento_kmh"]');
  if (!el) return;
  let badge = document.getElementById('aplic-vento-badge');
  const v = parseFloat(String(el.value || '').replace(/\./g, '').replace(',', '.'));
  if (isNaN(v)) { if (badge) badge.remove(); return; }
  if (!badge) {
    badge = document.createElement('span');
    badge.id = 'aplic-vento-badge';
    badge.style.cssText = 'display:inline-block;margin-top:4px;padding:2px 8px;border-radius:99px;font-size:11.5px;font-weight:700';
    el.insertAdjacentElement('afterend', badge);
  }
  const fx = APLIC_VENTO_FAIXAS;
  if (v >= (fx.forte_min ?? 15)) {
    badge.textContent = 'Vento FORTE'; badge.style.background = '#FBEDE9'; badge.style.color = '#B3402A';
  } else if (v >= (fx.moderado_min ?? 8)) {
    badge.textContent = 'Vento moderado'; badge.style.background = '#FDF3E0'; badge.style.color = '#8A6D1A';
  } else {
    badge.textContent = 'Vento fraco'; badge.style.background = '#EDF5EC'; badge.style.color = '#2F5D33';
  }
}
(function () {
  const el = document.querySelector('#vm-form input[name="clima_vento_kmh"]');
  if (el) { el.addEventListener('input', aplicVentoBadge); aplicVentoBadge(); }
})();

/* bloco IF só aparece para fertirrigação (A1-28); Maquinário some nela (W-07 —
   gotejo não usa trator/pulverizador; o operador continua nos demais campos). */
(function () {
  /* P-13 (reincidência do V-07): pega o campo tipo seja SELECT ou input travado/
     hidden (recortes que fixam a série IF renderizam tipo como hidden) — antes o
     seletor só casava <select> e o toggle não rodava, deixando o "Tipo de bico"
     visível na fertirrigação. */
  var tipo = document.querySelector('#vm-form [name="tipo"]');
  var blocoIf  = document.getElementById('aplic-if');
  var blocoMaq = document.getElementById('aplic-bloco-maquinas');
  /* V-07: Tipo de bico e Volume de calda são resíduos de
     pulverização — somem na fertirrigação. Filas, Área aplicada e clima ficam. */
  var blocoBico = document.getElementById('aplic-bico-wrap');
  var blocoVol  = document.getElementById('aplic-volcalda');
  if (!tipo) return;
  function toggle() {
    var isFerti = tipo.value === 'fertirrigacao';
    if (blocoIf)  blocoIf.style.display  = isFerti ? '' : 'none';
    if (blocoMaq) blocoMaq.style.display = isFerti ? 'none' : '';
    if (blocoBico) blocoBico.style.display = isFerti ? 'none' : '';
    if (blocoVol)  blocoVol.style.display  = isFerti ? 'none' : '';
    aplicRefiltraProdutos(); /* fertirrigação: só adubos e defensivos na lista de produtos */
  }
  if (tipo.tagName === 'SELECT') tipo.addEventListener('change', toggle);
  toggle();
})();
</script>
<?php endif; ?>

<?php if ($podeEditar && $conf): /* ── A1-26: confirmação pós-aplicação ── */ ?>
<div class="vmodal open" id="vm-confirmar">
  <div class="vbox" style="max-width:760px">
    <header>
      <h2>Confirmar execução — <?= $conf['doc_serie'] ? h((string)$conf['doc_serie']) . (int)$conf['doc_numero'] : '#' . (int)$conf['id'] ?>
        <span class="vhint"><?= h(trim(($conf['fazenda'] ?? '') . ' — ' . ($conf['talhao'] ?? ''), ' —')) ?></span></h2>
      <a class="vclose" href="<?= h(strtok((string)$_SERVER['REQUEST_URI'], '?')) ?>">×</a>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="confirmar">
      <input type="hidden" name="id" value="<?= (int)$conf['id'] ?>">
      <div class="vgrid">
        <div class="vfield">
          <label>Data da execução *</label>
          <?php /* C-11: OS emitida não tem realizada — sugere HOJE (o valor em
                   `data` de doc planejada é só o placeholder pré-mig 177) */ ?>
          <input type="date" name="data" value="<?= h(date('Y-m-d')) ?>" required>
        </div>
        <div class="vfield">
          <label>Hora início</label>
          <input type="time" name="hora_inicio" value="<?= $conf['executada_inicio'] ? date('H:i', strtotime((string)$conf['executada_inicio'])) : '' ?>">
        </div>
        <div class="vfield">
          <label>Hora término</label>
          <input type="time" name="hora_fim" value="<?= $conf['executada_fim'] ? date('H:i', strtotime((string)$conf['executada_fim'])) : '' ?>">
        </div>
        <?= vero_f_select('conf_ceu', 'Céu', ['noite' => 'Noite', 'sol' => 'Sol', 'nublado' => 'Nublado', 'chuva' => 'Chuva'], null, false, '— Não informado —') ?>
        <?= vero_f_select('conf_vento_class', 'Vento', ['brisa' => 'Brisa', 'moderado' => 'Moderado', 'forte' => 'Forte'], null, false, '— Não informado —') ?>
        <?= vero_f_text('conf_vento_kmh', 'Vento (km/h)', '', false, vero_a1_hint_faixa('vento', 'Medição real, se houver')) ?>
        <?= vero_f_text('conf_pluviosidade_mm', 'Pluviosidade (mm)', '') ?>
        <?= vero_f_text('conf_destino_sobra', 'Destino da sobra de calda / água de lavagem', '', false, 'Exigência de certificação (CB 7.5)') ?>
        <div class="vfield">
          <label style="display:flex;gap:6px;align-items:center;font-weight:400">
            <input type="checkbox" name="triplice_lavagem" value="1"> Tríplice lavagem das embalagens realizada
          </label>
        </div>
        <div class="full"><?= vero_f_text('conf_obs', 'Observações da execução', '') ?></div>
      </div>
      <div class="vfield" style="margin-top:10px">
        <label>Quantidades REAIS consumidas (pré-preenchidas com as previstas da emissão)</label>
        <div class="vdata-wrap">
        <table class="vdata">
          <thead><tr>
            <th>Produto</th>
            <th class="num">Prevista</th>
            <th class="num" style="width:30%">Real consumida *</th>
          </tr></thead>
          <tbody>
          <?php foreach ($confItens as $ci): ?>
            <tr>
              <td><strong><?= h(trim(($ci['produto_codigo'] ? $ci['produto_codigo'] . ' — ' : '') . ($ci['produto_nome'] ?? '—'))) ?></strong></td>
              <td class="num"><?= numFmt((float)$ci['quantidade_consumida'], 2) ?> <?= h((string)($ci['quantidade_unidade'] ?? '')) ?></td>
              <td><input type="text" name="c_qtd[<?= (int)$ci['id'] ?>]"
                         value="<?= numFmt((float)$ci['quantidade_consumida'], 2) ?>" style="text-align:right"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
      <div class="vfield" style="margin-top:10px">
        <label>Operadores / EPI da execução * (mínimo 1 — exigência de certificação)</label>
        <div class="vdata-wrap">
        <table class="vdata">
          <thead><tr>
            <th style="width:34%">Operador</th><th style="width:20%">Código EPI</th>
            <th style="width:16%">EPI lavado</th><th style="width:26%">Condição do EPI</th>
          </tr></thead>
          <tbody>
          <?php
            $linhasOp = $confOperadores;
            while (count($linhasOp) < max(2, count($confOperadores) + 1)) $linhasOp[] = null;
            foreach ($linhasOp as $lo): ?>
            <tr>
              <td><select name="op_operador[]">
                <option value="">—</option>
                <?php foreach ($operadores as $oid => $onome): ?>
                  <option value="<?= $oid ?>"<?= $lo && (int)$lo['operador_id'] === (int)$oid ? ' selected' : '' ?>><?= h($onome) ?></option>
                <?php endforeach; ?>
              </select></td>
              <td><input type="text" name="op_epi[]" value="<?= h((string)($lo['epi_codigo'] ?? '')) ?>" placeholder="ex.: EPI-07"></td>
              <td><select name="op_lavagem[]">
                <option value="">—</option>
                <option value="1"<?= $lo && $lo['epi_lavagem'] !== null && (int)$lo['epi_lavagem'] === 1 ? ' selected' : '' ?>>Sim</option>
                <option value="0"<?= $lo && $lo['epi_lavagem'] !== null && (int)$lo['epi_lavagem'] === 0 ? ' selected' : '' ?>>Não</option>
              </select></td>
              <td><input type="text" name="op_condicao[]" value="<?= h((string)($lo['epi_condicao'] ?? '')) ?>" placeholder="bom / desgastado…"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
      <div class="vhint" style="margin-top:8px">
        Ao confirmar: baixa FEFO do estoque pelas quantidades REAIS + custeio. A validação nominal do RT continua sendo a etapa seguinte.
      </div>
      <div class="vform-actions">
        <a class="vbtn vbtn-ghost" href="<?= h(strtok((string)$_SERVER['REQUEST_URI'], '?')) ?>">Cancelar</a>
        <button class="vbtn vbtn-primary" type="submit">Confirmar execução</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
