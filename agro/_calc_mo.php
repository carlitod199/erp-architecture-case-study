<?php
/* ============================================================
   VERO — A1-48c: motor da calculadora de mão de obra (casca)
   Lê agro_calc_parametros (DB-52, chave→valor POR tipo de atividade).
   Fórmula: "rendimento por diária" (VERO_PESQUISA_CALCULADORA_MO §3,
   método A recomendado pelo A0).

   MIOLO = os VALORES (rendimento/diária, custo da diária) vêm da P-91
   (cliente/RT) e são semeados em agro_calc_parametros pelo A0. Enquanto
   não houver parâmetro cadastrado, o motor devolve estado 'sem_parametro'
   — o sistema NUNCA inventa rendimento (D5/Regra 1: orienta, não decide).

   Funções PURAS/de leitura; nenhuma escrita. Reusável pela tela do
   apontamento (fatia de UI, próxima) e por um utilitário standalone.
   ============================================================ */
declare(strict_types=1);

/** Chaves reconhecidas em agro_calc_parametros (valores decimais; a unidade da
 *  atividade vem de agro_tipos_atividade.unidade_padrao, não daqui). */
const VERO_CALC_MO_CHAVES = [
    'rendimento_por_diaria',      // quanto 1 pessoa faz por diária, na unidade da atividade
    'jornada_horas',              // horas/dia (informativo por ora)
    'fator_ajuste',               // multiplicador de dificuldade (default 1,0)
    'custo_diaria_propria',       // R$/diária CLT
    'custo_diaria_terceirizada',  // R$/diária terceirizada
    'dias_uteis_mes',             // capacidade (§9 "15 dias/mês") — informativo
];

/**
 * Parâmetros VIGENTES (chave=>valor float) de um tipo de atividade na data.
 * Vigência mais recente vence (o assoc sobrescreve na ordem de vigência).
 */
function vero_calc_mo_parametros(int $tipoId, ?string $data = null): array
{
    if ($tipoId <= 0) return [];
    $data = $data ?: date('Y-m-d');
    $rows = vero_rows(
        "SELECT chave, valor FROM agro_calc_parametros
          WHERE tenant_id = :t AND tipo_atividade_id = :a AND ativo = 1
            AND vigencia_inicio <= :d1 AND (vigencia_fim IS NULL OR vigencia_fim >= :d2)
          ORDER BY vigencia_inicio",
        [':t' => vero_tenant(), ':a' => $tipoId, ':d1' => $data, ':d2' => $data]); /* :d1/:d2 distintos — QA-011/HY093 */
    $p = [];
    foreach ($rows as $r) $p[(string)$r['chave']] = (float)$r['valor'];
    return $p;
}

/**
 * Dimensiona a mão de obra pela fórmula do rendimento por diária.
 *   diárias = trabalho_total ÷ rendimento_por_diaria × fator_ajuste
 *   prazo (dias) informado  → pessoas = ⌈diárias ÷ prazo⌉   (P-02: ceil)
 *   pessoas informadas       → dias    = ⌈diárias ÷ pessoas⌉
 *   custo = diárias × custo_diaria (própria e/ou terceirizada, quando houver)
 *
 * V-03/D-1: quando o gestor informa a MÉDIA de produtividade
 * por pessoa (>0), a EQUIPE é dimensionada pela MÉDIA (base = média); sem média,
 * cai no rendimento/meta (comportamento atual). A META em si é o número que vai
 * na OS do encarregado — isso é responsabilidade da UI, não deste motor.
 *
 * @param float      $trabalhoTotal quantidade do talhão na unidade da atividade
 * @param array      $p             parâmetros de vero_calc_mo_parametros()
 * @param float|null $prazo         dias disponíveis (informe prazo OU pessoas)
 * @param float|null $pessoas       pessoas disponíveis
 * @param float|null $media         média de produtividade por pessoa/dia (V-03): se >0, dimensiona por ela
 * @return array estado: 'sem_parametro' | 'sem_trabalho' | 'ok' (+ números;
 *               'dimensionado_por' = 'media'|'meta', 'base_dimensionamento' = base usada)
 */
function vero_calc_mo_dimensionar(float $trabalhoTotal, array $p, ?float $prazo = null, ?float $pessoas = null, ?float $media = null): array
{
    $rend = (float)($p['rendimento_por_diaria'] ?? 0.0);
    if ($rend <= 0)          return ['estado' => 'sem_parametro'];
    if ($trabalhoTotal <= 0) return ['estado' => 'sem_trabalho'];

    $fator = (float)($p['fator_ajuste'] ?? 1.0);
    if ($fator <= 0) $fator = 1.0;

    /* V-03/D-1: média informada (>0) dimensiona a equipe; senão usa o rendimento (meta). */
    $usaMedia = ($media !== null && $media > 0);
    $baseDim  = $usaMedia ? (float)$media : $rend;

    $diarias = $trabalhoTotal / $baseDim * $fator;

    $out = [
        'estado'             => 'ok',
        'trabalho_total'     => $trabalhoTotal,
        'rendimento'         => $rend,
        'base_dimensionamento' => $baseDim,
        'dimensionado_por'   => $usaMedia ? 'media' : 'meta',
        'fator'              => $fator,
        'diarias'            => $diarias,
        'custo_propria'      => isset($p['custo_diaria_propria'])      ? $diarias * (float)$p['custo_diaria_propria']      : null,
        'custo_terceirizada' => isset($p['custo_diaria_terceirizada']) ? $diarias * (float)$p['custo_diaria_terceirizada'] : null,
    ];
    if ($prazo !== null && $prazo > 0) {
        $out['modo']    = 'prazo';
        $out['prazo']   = $prazo;
        $out['pessoas'] = (int)ceil($diarias / $prazo);
    } elseif ($pessoas !== null && $pessoas > 0) {
        $out['modo']    = 'pessoas';
        $out['pessoas'] = $pessoas;
        $out['dias']    = (int)ceil($diarias / $pessoas);
    }
    return $out;
}

/**
 * Trabalho total do talhão na unidade da atividade (para pré-preencher a calculadora).
 * planta → nº de plantas do talhão; ha → área_ha; cacho → nº de plantas ×
 * cachos_por_planta da VARIEDADE (WP-CALC Z-06); caixa/kg/contentor → colheita
 * por peso (retorna null: o operador informa a produção prevista). Degradação honesta.
 */
function vero_calc_mo_trabalho_do_talhao(?array $talhao, string $unidade): ?float
{
    if (!$talhao) return null;
    return match ($unidade) {
        'planta' => isset($talhao['num_plantas']) && $talhao['num_plantas'] !== null ? (float)$talhao['num_plantas'] : null,
        'ha'     => isset($talhao['area_ha'])     && $talhao['area_ha']     !== null ? (float)$talhao['area_ha']     : null,
        /* raleio (WP-CALC Z-06/W-02): cachos = nº de plantas × cachos_por_planta da variedade */
        'cacho'  => (isset($talhao['num_plantas'], $talhao['cachos_por_planta'])
                     && $talhao['num_plantas'] !== null && $talhao['cachos_por_planta'] !== null
                     && (float)$talhao['cachos_por_planta'] > 0)
                     ? (float)$talhao['num_plantas'] * (float)$talhao['cachos_por_planta'] : null,
        default  => null, // caixa/kg/contentor: colheita por peso — entrada manual
    };
}

/**
 * COLHEITA (ERP-CALC 22/07, pedido do gestor por áudio): colheita se dimensiona
 * por QUILO/CAIXA, nunca por nº de plantas. Entrada = PRODUÇÃO PREVISTA TOTAL
 * da área em kg (digitada — previsão do gestor/RT), meta POR PESSOA/DIA na
 * unidade do parâmetro (caixas/dia ou kg/dia) e nº de dias.
 *
 *   meta_kg/pessoa/dia = meta × peso_caixa_kg   (se a meta é em CAIXAS)
 *                      = meta                    (se a meta já é em KG → peso ≤ 0)
 *   kg/dia             = produção_total_kg ÷ dias
 *   diárias/dia        = ⌈ kg/dia ÷ meta_kg ⌉    (P-02: sempre arredonda p/ CIMA)
 *   diárias_total      = diárias/dia × dias
 *
 * Função PURA (sem DB) — contrato canônico p/ a tela agro/calculadora.php e p/
 * o APP de campo replicar no fluxo "Iniciar apontamento" (Onda 2).
 *
 * @param float $producaoKg   produção prevista total da área (kg)
 * @param float $metaPessoaDia meta por pessoa/dia (caixas/dia OU kg/dia)
 * @param float $pesoCaixaKg  kg por caixa (>0 converte caixa→kg; ≤0 = meta já em kg)
 * @param int   $dias         dias disponíveis para colher
 * @return array estado: 'invalido' | 'ok' (+ meta_kg_dia, kg_dia, diarias_dia, diarias_total)
 */
function vero_calc_mo_colheita(float $producaoKg, float $metaPessoaDia, float $pesoCaixaKg, int $dias): array
{
    if ($producaoKg <= 0 || $metaPessoaDia <= 0 || $dias <= 0) return ['estado' => 'invalido'];
    $metaKg = $pesoCaixaKg > 0 ? $metaPessoaDia * $pesoCaixaKg : $metaPessoaDia;
    if ($metaKg <= 0) return ['estado' => 'invalido'];
    $kgDia      = $producaoKg / $dias;
    $diariasDia = (int)ceil($kgDia / $metaKg);
    return [
        'estado'        => 'ok',
        'producao_kg'   => $producaoKg,
        'meta_kg_dia'   => $metaKg,
        'kg_dia'        => $kgDia,
        'diarias_dia'   => $diariasDia,
        'diarias_total' => $diariasDia * $dias,
    ];
}

/** Mínimo de diárias p/ o rendimento observado ser considerado confiável. */
const VERO_CALC_MO_MIN_DIARIAS = 10;

/**
 * Rendimento OBSERVADO a partir dos apontamentos já registrados (rh_producao_itens):
 *   rendimento = Σ quantidade ÷ Σ diárias, por tipo de atividade, na UNIDADE CANÔNICA
 *   do tipo (agro_tipos_atividade.unidade_padrao). Conta só linhas com quantidade > 0
 *   (quem produziu algo medível). Abaixo do mínimo de diárias → fonte = null (amostra
 *   insuficiente, não confiável). Read-only. Regra 1: MEDE o real, não inventa.
 *
 * @return array ['rendimento'=>?float,'n_diarias'=>int,'n_apontamentos'=>int,'unidade'=>?string,'fonte'=>'observado'|null]
 */
function vero_calc_mo_rendimento_observado(int $tipoId, int $minDiarias = VERO_CALC_MO_MIN_DIARIAS): array
{
    $vazio = ['rendimento' => null, 'n_diarias' => 0, 'n_apontamentos' => 0, 'unidade' => null, 'fonte' => null];
    if ($tipoId <= 0) return $vazio;

    $unid = function_exists('vero_val')
        ? vero_val("SELECT unidade_padrao FROM agro_tipos_atividade WHERE id = :a AND tenant_id = :t",
            [':a' => $tipoId, ':t' => vero_tenant()])
        : null;

    // filtra pela unidade canônica quando o tipo a define (evita misturar caixa/kg/outro)
    $sqlUnid = $unid ? " AND pi.unidade = :u" : "";
    $params  = [':t' => vero_tenant(), ':a' => $tipoId];
    if ($unid) $params[':u'] = (string)$unid;

    $row = vero_row(
        "SELECT COUNT(*) AS diarias, COUNT(DISTINCT pi.apontamento_id) AS apts, COALESCE(SUM(pi.quantidade),0) AS total
           FROM rh_producao_itens pi
           JOIN agro_apontamentos ap ON ap.id = pi.apontamento_id
          WHERE pi.tenant_id = :t AND ap.tipo_atividade_id = :a AND pi.quantidade > 0" . $sqlUnid,
        $params);

    $diarias = (int)($row['diarias'] ?? 0);
    $total   = (float)($row['total'] ?? 0);
    $rend    = $diarias > 0 ? $total / $diarias : null;

    return [
        'rendimento'     => $rend,
        'n_diarias'      => $diarias,
        'n_apontamentos' => (int)($row['apts'] ?? 0),
        'unidade'        => $unid !== null ? (string)$unid : null,
        'fonte'          => ($rend !== null && $diarias >= $minDiarias) ? 'observado' : null,
    ];
}

/**
 * Mapa tipo_id => ['rendimento'=>float,'n_diarias'=>int] SÓ dos tipos com rendimento
 * observado CONFIÁVEL (>= mínimo de diárias), na unidade canônica. Uma consulta única
 * para o painel injetar no front (cadeia de prioridade: observado → parâmetro → referência).
 */
function vero_calc_mo_rendimento_observado_mapa(int $minDiarias = VERO_CALC_MO_MIN_DIARIAS): array
{
    $rows = vero_rows(
        "SELECT ap.tipo_atividade_id AS tipo, COUNT(*) AS diarias, COALESCE(SUM(pi.quantidade),0) AS total
           FROM rh_producao_itens pi
           JOIN agro_apontamentos ap ON ap.id = pi.apontamento_id
           JOIN agro_tipos_atividade ta ON ta.id = ap.tipo_atividade_id
          WHERE pi.tenant_id = :t AND pi.quantidade > 0
            AND (ta.unidade_padrao IS NULL OR pi.unidade = ta.unidade_padrao)
          GROUP BY ap.tipo_atividade_id",
        [':t' => vero_tenant()]);

    $map = [];
    foreach ($rows as $r) {
        $d = (int)$r['diarias'];
        $t = (float)$r['total'];
        if ($d >= $minDiarias && $t > 0) {
            $map[(int)$r['tipo']] = ['rendimento' => $t / $d, 'n_diarias' => $d];
        }
    }
    return $map;
}
