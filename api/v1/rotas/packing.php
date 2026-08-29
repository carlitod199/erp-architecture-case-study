<?php
declare(strict_types=1);
/* ============================================================
   VERO Campo — api/v1/rotas/packing.php
   Packing House no app (pedido gestor 19/08): Recepção de cargas e
   Posto de produção (bipagem de crachá) — espelham packing/recepcao.php
   e packing/apontar.php. A REGRA vive nos serviços compartilhados
   (packing/_ph_recepcao.php, includes/vero_cracha.php); aqui só se
   coleta, valida contra o tenant e responde JSON.

   Diferenças em relação ao web:
   - Sem ph_ctx de sessão: a UNIDADE vem por parâmetro em cada chamada
     (o app guarda a escolha localmente).
   - Escritas idempotentes por client_uuid (offline-first): a recepção
     ('ph_recepcao') e CADA bipe ('ph_beep') — o bipe INCREMENTA, então
     sem o uuid um reenvio da fila contaria caixa dobrada.
   - O debounce do leitor é do app (tiro duplo gera uuids diferentes;
     o app descarta leituras idênticas em <400ms como o web faz).
   ============================================================ */

require_once __DIR__ . '/escrita.php';                            /* api_c_* (helpers de corpo) */
require_once dirname(__DIR__, 3) . '/packing/_ph_services.php';   /* PH_TURNOS */
require_once dirname(__DIR__, 3) . '/packing/_ph_recepcao.php';   /* gates/criar/pendentes/relógio */
require_once dirname(__DIR__, 3) . '/includes/vero_cracha.php';   /* resolver + incrementar + romaneio */

/* ───────────────────────── Helpers locais ───────────────────────── */

/** Unidade de packing (almoxarifado tipo='packing') validada no tenant. */
function api_ph_unidade(int $unidadeId): int
{
    /* mesmo critério do ph_ctx_unidades (almoxarifados tipo='packing') */
    $ok = vero_val(
        "SELECT id FROM almoxarifados WHERE id=:i AND tenant_id=:t AND tipo='packing'",
        [':i' => $unidadeId, ':t' => vero_tenant()]);
    if (!$ok) {
        api_erro('unidade_invalida', 'Unidade de packing inválida.', 422);
    }
    return (int)$ok;
}

/** Atividades auto-resolvidas do posto (mesma regra do packing/apontar.php). */
function api_ph_atividades(): array
{
    $t = vero_tenant();
    $colh = vero_row(
        "SELECT id, nome FROM agro_tipos_atividade
          WHERE tenant_id=:t AND categoria='colheita' AND exige_producao=1 AND ativo=1 ORDER BY id LIMIT 1", [':t' => $t]);
    $emb = vero_row(
        "SELECT id, nome FROM agro_tipos_atividade
          WHERE tenant_id=:t AND categoria='packing' AND exige_producao=1 AND ativo=1 ORDER BY id LIMIT 1", [':t' => $t]);
    return [
        'colheita'    => $colh ? ['id' => (int)$colh['id'], 'nome' => (string)$colh['nome']] : null,
        'embalamento' => $emb  ? ['id' => (int)$emb['id'],  'nome' => (string)$emb['nome']]  : null,
    ];
}

/** vínculo safra×válvula ativo (colheita) — mesma resolução do web. */
function api_ph_safra_talhao(?int $talhaoId): ?int
{
    if (!$talhaoId) return null;
    return (int)(vero_val(
        "SELECT st.id FROM agro_safra_talhoes st JOIN agro_safras s ON s.id = st.safra_id
          WHERE st.tenant_id = :t AND st.talhao_id = :ta ORDER BY s.data_inicio DESC, st.id DESC LIMIT 1",
        [':t' => vero_tenant(), ':ta' => $talhaoId]) ?? 0) ?: null;
}

/** talhao_id a partir do corpo: aceita setor_id (válvula do app) OU talhao_id. */
function api_ph_talhao(array $corpo): ?int
{
    $setorId = api_c_int($corpo, 'setor_id');
    if ($setorId !== null) {
        $tal = vero_val("SELECT talhao_id FROM agro_setores WHERE id=:i AND tenant_id=:t",
            [':i' => $setorId, ':t' => vero_tenant()]);
        return $tal !== null ? (int)$tal : null;
    }
    $talhaoId = api_c_int($corpo, 'talhao_id');
    if ($talhaoId !== null && !vero_val("SELECT id FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
            [':i' => $talhaoId, ':t' => vero_tenant()])) {
        return null;
    }
    return $talhaoId;
}

/** id do apontamento (0 se não existe) para (data, atividade, válvula). */
function api_ph_apont_id(string $data, int $atividadeId, ?int $talhaoId): int
{
    if (!$atividadeId) return 0;
    return (int)(vero_val(
        "SELECT id FROM agro_apontamentos
          WHERE tenant_id=:t AND DATE(data_apontamento)=:d AND tipo_atividade_id=:ta AND (talhao_id <=> :tal)
          ORDER BY id DESC LIMIT 1",
        [':t' => vero_tenant(), ':d' => $data, ':ta' => $atividadeId, ':tal' => $talhaoId]) ?? 0);
}

/** Tally combinado (colheita + embalamento) — espelho do apu_tally do web. */
function api_ph_tally(string $data, ?int $talhaoId): array
{
    $atv = api_ph_atividades();
    $ids = [];
    if ($atv['colheita'] && ($i = api_ph_apont_id($data, (int)$atv['colheita']['id'], $talhaoId))) $ids[$i] = 'colheita';
    if ($atv['embalamento'] && ($i = api_ph_apont_id($data, (int)$atv['embalamento']['id'], null))) $ids[$i] = 'embalamento';
    if (!$ids) return [];
    $in = implode(',', array_map('intval', array_keys($ids)));
    $rows = vero_rows(
        "SELECT ri.apontamento_id, COALESCE(o.nome, tc.nome) AS pessoa, ri.origem_pessoa,
                ri.quantidade, ri.valor_total
           FROM rh_producao_itens ri
           LEFT JOIN agro_operadores  o  ON o.id  = ri.operador_id
           LEFT JOIN rh_terceirizados tc ON tc.id = ri.terceirizado_id
          WHERE ri.tenant_id = :t AND ri.apontamento_id IN ($in)
          ORDER BY ri.quantidade DESC, ri.id DESC",
        [':t' => vero_tenant()]);
    return array_map(static fn($p) => [
        'pessoa'  => (string)($p['pessoa'] ?? '—'),
        'modo'    => (string)($ids[(int)$p['apontamento_id']] ?? ''),
        'vinculo' => (string)$p['origem_pessoa'],
        'caixas'  => (float)$p['quantidade'],
        'premio'  => (float)$p['valor_total'],
    ], $rows);
}

/**
 * Itens da recepção a partir do corpo, restritos às cargas PENDENTES da
 * unidade (espelho do rec_coletar do web — o que não está pendente no
 * tenant/unidade é descartado). Devolve [gates, criar].
 */
function api_ph_rec_coletar(int $unidadeId, array $corpo, ?int $produtorId): array
{
    $porId = [];
    foreach (ph_recepcao_cargas_pendentes($unidadeId) as $c) {
        $porId[(int)$c['carga_id']] = $c;
    }
    $gates = [];
    $criar = [];
    foreach ((array)($corpo['itens'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $cid = (int)($it['carga_id'] ?? 0);
        if (!isset($porId[$cid])) continue;
        $c = $porId[$cid];

        $met = (string)($it['metodo'] ?? '');
        if (!in_array($met, PH_METODOS_RASTREAB, true)) $met = 'segregacao';

        $peso = api_c_dec($it, 'peso_kg');
        if ($peso === null) $peso = $c['peso_kg'] !== null ? (float)$c['peso_kg'] : null;

        $base = [
            'talhao_id'              => $c['talhao_id'] !== null ? (int)$c['talhao_id'] : null,
            'variedade_id'           => $c['variedade_id'] !== null ? (int)$c['variedade_id'] : null,
            'produtor_id'            => $produtorId,
            'colhido_em'             => $c['colhido_em'] !== null ? (string)$c['colhido_em'] : null,
            'metodo_rastreabilidade' => $met,
        ];
        $gates[] = $base;
        $criar[] = $base + [
            'colheita_carga_id'     => $cid,
            'lote_estoque_id'       => $c['lote_estoque_id'] !== null ? (int)$c['lote_estoque_id'] : null,
            'safra_talhao_id'       => $c['safra_talhao_id'] !== null ? (int)$c['safra_talhao_id'] : null,
            'peso_kg'               => $peso,
            'n_contentores'         => api_c_int($it, 'n_contentores'),
            'temperatura_chegada_c' => api_c_dec($it, 'temperatura_chegada_c'),
            'turma_colheita'        => api_c_str($it, 'turma_colheita', 60),
        ];
    }
    return [$gates, $criar];
}

/** mercado_id do corpo, validado no tenant (inválido → null, como no web). */
function api_ph_mercado(array $corpo): ?int
{
    $mercadoId = api_c_int($corpo, 'mercado_id');
    if (!$mercadoId) return null;
    $ok = vero_val("SELECT id FROM ph_mercados WHERE id=:i AND tenant_id=:t",
        [':i' => $mercadoId, ':t' => vero_tenant()]);
    return $ok ? (int)$ok : null;
}

/* ───────────────────────── Rotas ───────────────────────── */

/** GET packing/contexto — unidades, turnos, mercados, atividades do posto. */
function rota_packing_contexto(array $usuario): never
{
    if (!api_pode('packing.recepcao.ver') && !api_pode('packing.apontar.ver')) {
        api_erro('sem_permissao', 'Você não tem permissão para o packing.', 403);
    }
    $unidades = [];
    foreach (ph_ctx_unidades() as $id => $nome) {
        $unidades[] = ['id' => (int)$id, 'nome' => (string)$nome];
    }
    $mercados = [];
    foreach (vero_options('ph_mercados', 'nome') as $id => $nome) {
        $mercados[] = ['id' => (int)$id, 'nome' => (string)$nome];
    }
    api_ok([
        'unidades'      => $unidades,
        'turnos'        => PH_TURNOS,
        'mercados'      => $mercados,
        'atividades'    => api_ph_atividades(),
        'peso_caixa_kg' => (float)vero_srv_param('colheita.peso_caixa_kg', '0'),
        'metodos_rastreabilidade' => PH_METODOS_RASTREAB,
    ]);
}

/** GET packing/recepcao/pendentes?unidade_id=N — cargas aguardando recepção. */
function rota_packing_pendentes(array $usuario): never
{
    api_exigir('packing.recepcao.ver');
    $unidadeId = api_ph_unidade((int)($_GET['unidade_id'] ?? 0));
    $cargas = [];
    foreach (ph_recepcao_cargas_pendentes($unidadeId) as $c) {
        $rel = ph_relogio_status($c['colhido_em'] ?? null);
        $cargas[] = [
            'carga_id'       => (int)$c['carga_id'],
            'romaneio'       => (string)($c['romaneio'] ?? ''),
            'data_carga'     => (string)($c['data_carga'] ?? ''),
            'talhao_nome'    => (string)($c['talhao_nome'] ?? ''),
            'variedade_nome' => (string)($c['variedade_nome'] ?? ''),
            'peso_kg'        => $c['peso_kg'] !== null ? (float)$c['peso_kg'] : null,
            'colhido_em'     => $c['colhido_em'] !== null ? (string)$c['colhido_em'] : null,
            'relogio'        => ['cor' => (string)($rel['cor'] ?? 'sem_dado'),
                                 'horas' => isset($rel['horas']) && $rel['horas'] !== null ? (float)$rel['horas'] : null],
        ];
    }
    api_ok([
        'proximo_numero' => ph_recepcao_numero($unidadeId),
        'cargas'         => $cargas,
    ]);
}

/** POST packing/recepcao/avaliar — 5 gates da seleção (somente leitura). */
function rota_packing_avaliar(array $usuario): never
{
    api_exigir('packing.recepcao.ver');
    $corpo = api_corpo();
    $unidadeId  = api_ph_unidade((int)($corpo['unidade_id'] ?? 0));
    $produtorId = api_c_int($corpo, 'produtor_id');
    [$gates, ] = api_ph_rec_coletar($unidadeId, $corpo, $produtorId);
    if (!$gates) {
        api_erro('sem_itens', 'Selecione ao menos uma carga pendente para avaliar.', 422);
    }
    api_ok(ph_recepcao_gates($gates, api_ph_mercado($corpo)));
}

/** POST packing/recepcao — cria a recepção (idempotente por client_uuid). */
function rota_packing_recepcao_criar(array $usuario): never
{
    api_exigir('packing.recepcao.editar');
    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');
    $unidadeId  = api_ph_unidade((int)($corpo['unidade_id'] ?? 0));
    $produtorId = api_c_int($corpo, 'produtor_id');
    $header = [
        'produtor_id'    => $produtorId,
        'contrato_id'    => null,
        'veiculo_placa'  => api_c_str($corpo, 'veiculo_placa', 10),
        'motorista'      => api_c_str($corpo, 'motorista', 120),
        'transportadora' => api_c_str($corpo, 'transportadora', 120),
        'chegou_em'      => api_c_datahora($corpo, 'chegou_em', date('Y-m-d H:i:s')),
        'peso_bruto_kg'  => api_c_dec($corpo, 'peso_bruto_kg'),
        'peso_tara_kg'   => api_c_dec($corpo, 'peso_tara_kg'),
        'observacao'     => api_c_str($corpo, 'observacao', 255),
        'mercado_id'     => api_ph_mercado($corpo), // p/ os gates — não é coluna
    ];
    /* A coleta/validação de itens roda DENTRO do callable: no REENVIO da fila
       offline a carga já não está pendente (foi recebida na 1ª tentativa) e a
       validação antecipada devolveria 422 — o api_idempotente consulta o
       armazenado ANTES de executar, então o replay recebe a resposta gravada. */
    api_idempotente($clientUuid, 'ph_recepcao', function () use ($unidadeId, $header, $corpo, $produtorId) {
        [, $criar] = api_ph_rec_coletar($unidadeId, $corpo, $produtorId);
        if (!$criar) {
            api_erro('sem_itens', 'Selecione ao menos uma carga pendente para criar a recepção.', 422);
        }
        $recId = ph_recepcao_criar($unidadeId, $header, $criar);
        $rec = vero_row("SELECT numero, status FROM ph_recepcoes WHERE id=:i AND tenant_id=:t",
            [':i' => $recId, ':t' => vero_tenant()]);
        return [$recId, [
            'id'     => $recId,
            'numero' => (string)($rec['numero'] ?? ('#' . $recId)),
            'status' => (string)($rec['status'] ?? ''),
            'itens'  => count($criar),
        ]];
    });
}

/** GET packing/apontar/tally?data=Y-m-d[&talhao_id|setor_id] — apontado do posto. */
function rota_packing_tally(array $usuario): never
{
    api_exigir('packing.apontar.ver');
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['data'] ?? '')) ? (string)$_GET['data'] : date('Y-m-d');
    $talhaoId = api_ph_talhao(['setor_id' => $_GET['setor_id'] ?? null, 'talhao_id' => $_GET['talhao_id'] ?? null]);
    api_ok(['data' => $data, 'talhao_id' => $talhaoId, 'tally' => api_ph_tally($data, $talhaoId)]);
}

/** GET packing/romaneio?numero=X — alvo da colheita p/ configurar o posto. */
function rota_packing_romaneio(array $usuario): never
{
    api_exigir('packing.apontar.ver');
    $rom = trim((string)($_GET['numero'] ?? ''));
    if ($rom === '') {
        api_erro('campo_obrigatorio', 'Informe o número do romaneio.', 422);
    }
    $alvo = vero_srv_romaneio_alvo_colheita($rom);
    if (!$alvo) {
        api_erro('romaneio_nao_encontrado', 'Romaneio não encontrado.', 404);
    }
    $talNome = $alvo['talhao_id']
        ? (string)(vero_val("SELECT codigo FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
            [':i' => $alvo['talhao_id'], ':t' => vero_tenant()]) ?? '')
        : '';
    api_ok([
        'data'        => (string)$alvo['data_colheita'],
        'talhao_id'   => $alvo['talhao_id'] !== null ? (int)$alvo['talhao_id'] : null,
        'talhao_nome' => $talNome,
    ]);
}

/**
 * POST packing/apontar/beep — +1 caixa pela etiqueta da pessoa.
 * IDEMPOTENTE por client_uuid: o incremento não é naturalmente idempotente,
 * então cada bipe leva um uuid próprio — reenvio da fila offline devolve a
 * resposta gravada em vez de contar caixa dobrada.
 */
function rota_packing_beep(array $usuario): never
{
    api_exigir('packing.apontar.editar');
    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');
    $cracha = api_c_str($corpo, 'cracha', 40);
    if ($cracha === null) {
        api_erro('campo_obrigatorio', 'Leia a etiqueta da pessoa.', 422);
    }
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($corpo['data'] ?? '')) ? (string)$corpo['data'] : date('Y-m-d');
    $modoAmbos = in_array((string)($corpo['modo_ambos'] ?? ''), ['colheita', 'embalamento'], true)
        ? (string)$corpo['modo_ambos'] : 'colheita';
    $talhaoId = api_ph_talhao($corpo);

    $pessoa = vero_srv_cracha_resolver($cracha);
    if (!$pessoa) {
        api_erro('cracha_invalido', 'Etiqueta não reconhecida — gere/atribua o QR em Packing → QR Codes.', 422);
    }
    $efet = match ($pessoa['papel'] ?? null) {
        'colhedor'  => 'colheita',
        'embalador' => 'embalamento',
        'ambos'     => $modoAmbos,
        default     => null,
    };
    if ($efet === null) {
        api_erro('funcao_indefinida', 'Defina a função de ' . $pessoa['nome'] . ' em Packing → QR Codes (colhedor/embalador).', 422);
    }
    $atv = api_ph_atividades();
    $atvId = $efet === 'colheita' ? ($atv['colheita']['id'] ?? 0) : ($atv['embalamento']['id'] ?? 0);
    if (!$atvId) {
        api_erro('posto_sem_atividade', 'Cadastre a atividade de ' . $efet . ' (com produção) em Tipos de Atividade.', 422);
    }
    /* elo recepção→embalamento (gestor 19/08): sem recepção aceita no dia,
       avisa (padrão) ou bloqueia — parâmetro packing.embalamento_exige_recepcao */
    $avisoRecepcao = null;
    if ($efet === 'embalamento') {
        $chk = ph_embalamento_recepcao_check($data);
        if (!$chk['ok']) {
            api_erro('sem_recepcao', (string)$chk['msg'], 422);
        }
        $avisoRecepcao = $chk['msg'];
    }
    $talho = $efet === 'colheita' ? $talhaoId : null;
    $safra = $efet === 'colheita' ? api_ph_safra_talhao($talhaoId) : null;
    $pesoCx = (float)vero_srv_param('colheita.peso_caixa_kg', '0');

    api_idempotente($clientUuid, 'ph_beep', function () use ($data, $atvId, $talho, $safra, $cracha, $pesoCx, $efet, $avisoRecepcao) {
        try {
            $r = vero_srv_producao_incrementar_cracha(
                $data, (int)$atvId, $talho, $safra, $cracha, 1.0, $pesoCx > 0 ? $pesoCx : null);
        } catch (Throwable $e) {
            $m = $e->getMessage();
            api_erro('beep_recusado', str_starts_with($m, 'CRACHA_INVALIDO:') ? mb_substr($m, 16) : $m, 422);
        }
        return [(int)($r['item_id'] ?? 0) ?: null, [
            'pessoa'       => (string)$r['pessoa']['nome'],
            'vinculo'      => (string)$r['pessoa']['origem_pessoa'],
            'modo'         => $efet,
            'caixas_total' => (float)$r['quantidade_total'],
            'premio_total' => (float)$r['valor_total'],
            'aviso'        => $avisoRecepcao,
        ]];
    });
}
