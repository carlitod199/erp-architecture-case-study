<?php
declare(strict_types=1);
/* ============================================================
   VERO — Premiação / Sugestão de default para a OS  (A3, rework 5.1–5.4)
   Reunião 16/07. Adiantamento MIGRATION-INDEPENDENTE do desenho
   VERO_A3_Premiacao_OS_Desenho.md: quando a OS ganhar os campos
   premia_* (migration 164, A0), o form da OS (A1) chama
   premiacao_sugestao() para PRÉ-PREENCHER meta/valor/unidade —
   editáveis por OS. Aqui só se SUGERE (leitura); nada grava, e o
   cálculo do valor segue em vero_srv_premiacao_calc (services).

   "Reduzir a 2 regras padrão" (5.3): a sugestão resolve por
   CATEGORIA da atividade (trato_cultural → template de poda;
   colheita → template de colheita), caindo da regra exata do tipo
   para o template da categoria. A UNIDADE vem da própria atividade
   (unidade_padrao) — respeita planta × cacho × caixa.

   🟠 Aguarda a call 18h: (a) confirmar os 2 templates e valores-
   semente; (b) se packing/embalamento premia (hoje NÃO — só as 2
   categorias confirmadas). Nada aqui depende dessas respostas para
   funcionar; elas só afinam a lista abaixo.
   ============================================================ */

/** Categorias que geram premiação por produção (as "2 regras padrão").
 *  aplicacao/irrigacao/outro não premiam; packing = 🟠 pendente da call. */
const PREMIACAO_CATEGORIAS = ['trato_cultural', 'colheita'];

/** Regra ativa mais específica de um tipo+cultura na data (self-contained;
 *  espelha vero_srv_regra_premiacao sem depender da ordem de include). */
function premiacao_regra_vigente(int $tipoAtividadeId, ?int $cultura, string $data): ?array
{
    $rows = vero_rows(
        "SELECT id, tipo_atividade_id, cultura_id, unidade, meta_qtd, valor_acima_meta
           FROM rh_regras_premiacao
          WHERE tenant_id = :t AND tipo_atividade_id = :a AND ativo = 1
            AND (vigencia_inicio IS NULL OR vigencia_inicio <= :d1)
            AND (vigencia_fim    IS NULL OR vigencia_fim    >= :d2)
            AND (cultura_id IS NULL OR cultura_id = :c)
          ORDER BY (cultura_id IS NULL), id DESC",              /* cultura específica vence o coringa */
        [':t' => vero_tenant(), ':a' => $tipoAtividadeId,
         ':d1' => $data, ':d2' => $data, ':c' => $cultura ?? 0]); /* :d1/:d2 distintos — QA-011/HY093 */
    return $rows[0] ?? null;
}

/** Template ativo por CATEGORIA (5.3): a regra ativa cujo tipo tem a mesma
 *  categoria; cultura específica vence coringa; mais recente desempata. */
function premiacao_template_por_categoria(string $categoria, ?int $cultura, string $data): ?array
{
    $rows = vero_rows(
        "SELECT r.id, r.tipo_atividade_id, r.cultura_id, r.unidade, r.meta_qtd, r.valor_acima_meta
           FROM rh_regras_premiacao r
           JOIN agro_tipos_atividade t ON t.id = r.tipo_atividade_id
          WHERE r.tenant_id = :t AND r.ativo = 1 AND t.categoria = :cat
            AND (r.vigencia_inicio IS NULL OR r.vigencia_inicio <= :d1)
            AND (r.vigencia_fim    IS NULL OR r.vigencia_fim    >= :d2)
            AND (r.cultura_id IS NULL OR r.cultura_id = :c)
          ORDER BY (r.cultura_id IS NULL), r.id DESC",
        [':t' => vero_tenant(), ':cat' => $categoria,
         ':d1' => $data, ':d2' => $data, ':c' => $cultura ?? 0]);
    return $rows[0] ?? null;
}

/**
 * SUGESTÃO de meta/valor/unidade para pré-preencher a OS de uma atividade.
 * Ordem: (1) regra exata do tipo; (2) template da categoria; (3) premiável
 * sem template (zera meta/valor, encarregado preenche); (4) null = não premia.
 *
 * @return null|array{unidade:string, meta:float, valor:float,
 *                    origem:string, regra_id:?int, categoria:string}
 */
function premiacao_sugestao(int $tipoAtividadeId, ?int $cultura = null, ?string $data = null): ?array
{
    if ($tipoAtividadeId <= 0) return null;
    $data = $data ?: date('Y-m-d');
    $tipo = vero_row(
        "SELECT id, categoria, unidade_padrao FROM agro_tipos_atividade
          WHERE tenant_id = :t AND id = :id",
        [':t' => vero_tenant(), ':id' => $tipoAtividadeId]);
    if (!$tipo) return null;
    $cat = (string)$tipo['categoria'];
    if (!in_array($cat, PREMIACAO_CATEGORIAS, true)) return null;   // atividade não premiável
    $unidadeAtiv = (string)($tipo['unidade_padrao'] ?: 'outro');

    $exata = premiacao_regra_vigente($tipoAtividadeId, $cultura, $data);
    if ($exata) {
        return ['unidade' => (string)$exata['unidade'], 'meta' => (float)$exata['meta_qtd'],
                'valor' => (float)$exata['valor_acima_meta'], 'origem' => 'regra_exata',
                'regra_id' => (int)$exata['id'], 'categoria' => $cat];
    }
    $tpl = premiacao_template_por_categoria($cat, $cultura, $data);
    if ($tpl) {
        return ['unidade' => $unidadeAtiv, /* respeita planta×cacho×caixa da atividade */
                'meta' => (float)$tpl['meta_qtd'], 'valor' => (float)$tpl['valor_acima_meta'],
                'origem' => 'template_categoria', 'regra_id' => (int)$tpl['id'], 'categoria' => $cat];
    }
    return ['unidade' => $unidadeAtiv, 'meta' => 0.0, 'valor' => 0.0,
            'origem' => 'sem_template', 'regra_id' => null, 'categoria' => $cat];
}

/** Rótulo humano da origem da sugestão (hint no form da OS). */
function premiacao_sugestao_rotulo(?array $sug): string
{
    return match ($sug['origem'] ?? '') {
        'regra_exata'        => 'sugerido pela regra da atividade — ajuste para esta OS se precisar',
        'template_categoria' => 'sugerido pelo template de ' . ($sug['categoria'] ?? '') . ' — confira meta/valor desta OS',
        'sem_template'       => 'sem template — defina meta e valor de premiação desta OS',
        default              => '',
    };
}

/* ============================================================
   ⛔ ENGAVETADO (decisão A0 5.1, 16/07 — DECISIONS): NÃO WIREAR.
   A decisão foi manter **meta POR LINHA** (rh_producao_itens já tem
   meta_aplicada/valor_unitario por linha; regra = só default), SEM
   colunas premia_* na OS e SEM finalização agregada. Logo o modelo
   OS-nível abaixo não é o adotado. Mantido apenas como REFERÊNCIA
   caso a granularidade "por OS" volte à mesa — não deve ser chamado
   em produção. A peça viva do rework é premiacao_sugestao() (acima),
   aprovada pelo A0.

   Núcleo (referência) do cálculo da premiação na FINALIZAÇÃO da OS (5.4)
   PURO e testável — recebe meta/valor/granularidade e a lista de
   apontamentos POR PARÂMETRO; devolve linhas p/ rh_producao_itens.
   ============================================================ */

/** Valor da premiação de um realizado (fonte única; delega ao service se
 *  carregado, senão computa a mesma fórmula max(0, realizado−meta) × valor). */
function premiacao_valor(float $realizado, float $meta, float $valor): array
{
    if (function_exists('vero_srv_premiacao_calc')) {
        return vero_srv_premiacao_calc($realizado, $meta, $valor);
    }
    $acima = max(0.0, $realizado - $meta);
    return ['qtd_acima' => $acima, 'valor_total' => round($acima * $valor, 2)];
}

/** Chave da pessoa (colaborador × terceirizado) para agrupar. */
function premiacao_chave_pessoa(array $ap): string
{
    $origem = ($ap['origem_pessoa'] ?? 'colaborador') === 'terceirizado' ? 'tc' : 'op';
    $id = (int)($origem === 'tc' ? ($ap['terceirizado_id'] ?? 0) : ($ap['operador_id'] ?? 0));
    return $origem . ':' . $id;
}

/**
 * Calcula as premiações de uma OS a partir da config e dos apontamentos.
 * Não toca banco — retorna as linhas a persistir (o caller grava/limpa).
 *
 * @param array $config  ['ativa'=>bool,'unidade'=>string,'meta'=>float,'valor'=>float,
 *                        'granularidade'=>'diaria'|'os','data_ref'=>?string]
 * @param array $apontamentos  cada item: ['apontamento_id'=>?int,'operador_id'=>?int,
 *                        'terceirizado_id'=>?int,'origem_pessoa'=>'colaborador'|'terceirizado',
 *                        'data'=>'Y-m-d','quantidade'=>float]
 * @return array linhas: ['origem_pessoa','operador_id','terceirizado_id','apontamento_id',
 *                        'apontamento_ids','data_trabalho','unidade','meta_aplicada',
 *                        'valor_unitario','qtd_acima_meta','valor_total']  (só valor_total>0)
 */
function premiacao_os_calcular(array $config, array $apontamentos): array
{
    if (empty($config['ativa'])) return [];                      // OS.premia_ativa = 0 → nada
    $meta  = (float)($config['meta'] ?? 0);
    $valor = (float)($config['valor'] ?? 0);
    $unid  = (string)($config['unidade'] ?? 'outro');
    $gran  = ($config['granularidade'] ?? 'diaria') === 'os' ? 'os' : 'diaria';
    if ($valor <= 0) return [];                                  // sem valor de prêmio → nada a pagar

    /* agrupa por pessoa (+dia, se granularidade diária) */
    $grupos = [];
    foreach ($apontamentos as $ap) {
        $qtd = (float)($ap['quantidade'] ?? 0);
        if ($qtd <= 0) continue;
        $pk  = premiacao_chave_pessoa($ap);
        $dia = (string)($ap['data'] ?? ($config['data_ref'] ?? ''));
        $gk  = $gran === 'diaria' ? $pk . '|' . $dia : $pk;
        if (!isset($grupos[$gk])) {
            $grupos[$gk] = [
                'origem_pessoa'   => ($ap['origem_pessoa'] ?? 'colaborador') === 'terceirizado' ? 'terceirizado' : 'colaborador',
                'operador_id'     => $ap['operador_id'] ?? null,
                'terceirizado_id' => $ap['terceirizado_id'] ?? null,
                'realizado'       => 0.0,
                'data_max'        => $dia,
                'apontamento_ids' => [],
            ];
        }
        $grupos[$gk]['realizado'] += $qtd;
        if ($dia > $grupos[$gk]['data_max']) $grupos[$gk]['data_max'] = $dia;
        if (!empty($ap['apontamento_id'])) $grupos[$gk]['apontamento_ids'][] = (int)$ap['apontamento_id'];
    }

    $linhas = [];
    foreach ($grupos as $g) {
        $c = premiacao_valor($g['realizado'], $meta, $valor);
        if ($c['valor_total'] <= 0) continue;                   // não escreve prêmio zero
        $linhas[] = [
            'origem_pessoa'   => $g['origem_pessoa'],
            'operador_id'     => $g['operador_id'],
            'terceirizado_id' => $g['terceirizado_id'],
            'apontamento_id'  => $g['apontamento_ids'][0] ?? null,   // representante (rastreio)
            'apontamento_ids' => $g['apontamento_ids'],              // grupo (o caller decide)
            'data_trabalho'   => $g['data_max'],
            'unidade'         => $unid,
            'meta_aplicada'   => $meta,
            'valor_unitario'  => $valor,
            'qtd_acima_meta'  => $c['qtd_acima'],
            'valor_total'     => $c['valor_total'],
        ];
    }
    return $linhas;
}

/* ------------------------------------------------------------
   STUB do wrapper de banco (AGUARDA migration 164 + A0/services):
   quando agro_ordens_servico tiver premia_ativa/unidade/meta/valor,
   e a mudança em vero_services.php for aprovada pelo A0 (C-02):

   function vero_srv_premiacao_os_finalizar(int $osId): int {
     $os = vero_row("SELECT premia_ativa,premia_unidade,premia_meta,
              premia_valor_unitario,data_conclusao FROM agro_ordens_servico
              WHERE tenant_id=:t AND id=:id", [...]);
     $aps = vero_rows("SELECT pi.apontamento_id, pi.operador_id, pi.terceirizado_id,
              pi.origem_pessoa, a.data_trabalho AS data, pi.quantidade
              FROM rh_producao_itens pi JOIN agro_apontamentos a ON a.id=pi.apontamento_id
              WHERE a.ordem_servico_id=:os AND pi.modalidade='producao'", [...]);
     $linhas = premiacao_os_calcular([...$os...], $aps);
     // idempotente: apaga só as premiações-OS anteriores desta OS, reinsere $linhas.
     return count($linhas);
   }
   ------------------------------------------------------------ */
