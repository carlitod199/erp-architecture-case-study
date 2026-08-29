<?php
declare(strict_types=1);
/* ============================================================
   VERO Campo — api/v1/rotas/escrita.php
   Rotas de ESCRITA do app de campo (offline-first, D6):
   toda criação passa por api_idempotente(client_uuid, ...) —
   o app reenvia a fila quando volta o sinal e nunca duplica.

   Convenções deste arquivo:
   - tenant_id/created_by/updated_by: vero_insert() já injeta;
     tabelas SEM colunas de auditoria usam INSERT direto.
   - Prepared statements sempre; placeholders posicionais
     (EMULATE_PREPARES=false — nomeado não pode repetir).
   - Permissões: alinhadas ao fallback do OPERADOR em
     contexto.php ('agro.ver', 'agro.apontamentos.*', 'mip.*',
     'irrigacao.*', 'maquinas.ver', 'estoque.ver') — nas rotas
     que o operador usa nunca exigimos além disso.
   ============================================================ */

/* ───────────────────────── Helpers locais ───────────────────────── */

/** String do corpo JSON (trim + limite), ou NULL se vazia/ausente. */
function api_c_str(array $corpo, string $k, int $max = 255): ?string
{
    $v = trim((string)($corpo[$k] ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/** Inteiro do corpo JSON, ou NULL. */
function api_c_int(array $corpo, string $k): ?int
{
    $v = $corpo[$k] ?? null;
    if ($v === null || $v === '' || !is_numeric((string)$v)) {
        return null;
    }
    return (int)$v;
}

/** Decimal do corpo JSON (app manda número ou "12.5"), ou NULL. */
function api_c_dec(array $corpo, string $k): ?float
{
    $v = $corpo[$k] ?? null;
    if ($v === null || $v === '') {
        return null;
    }
    $v = str_replace(',', '.', (string)$v);
    return is_numeric($v) ? (float)$v : null;
}

/** Datetime 'Y-m-d H:i:s' a partir do corpo (aceita 'Y-m-d'), ou $padrao. */
function api_c_datahora(array $corpo, string $k, ?string $padrao = null): ?string
{
    $v = trim((string)($corpo[$k] ?? ''));
    if ($v === '') {
        return $padrao;
    }
    $ts = strtotime($v);
    return $ts === false ? $padrao : date('Y-m-d H:i:s', $ts);
}

/** Tabela existe no schema atual (espelho de vero_has_column p/ tabela). */
function api_tabela_existe(string $tabela): bool
{
    static $cache = [];
    if (!isset($cache[$tabela])) {
        $q = vero_pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $q->execute([$tabela]);
        $cache[$tabela] = (bool)$q->fetchColumn();
    }
    return $cache[$tabela];
}

/** Resolve o recurso_id gravado pela idempotência (tenant + client_uuid + tipo). */
function api_recurso_por_uuid(string $clientUuid, string $recursoTipo): ?int
{
    $q = vero_pdo()->prepare(
        'SELECT recurso_id FROM app_idempotencia
          WHERE tenant_id = ? AND client_uuid = ? AND recurso_tipo = ? LIMIT 1'
    );
    $q->execute([vero_tenant(), trim($clientUuid), $recursoTipo]);
    $id = $q->fetchColumn();
    return $id === false || $id === null ? null : (int)$id;
}

/** Garante que o registro pertence ao tenant; devolve a linha ou NULL. */
function api_linha_tenant(string $tabela, int $id): ?array
{
    $q = vero_pdo()->prepare("SELECT * FROM {$tabela} WHERE id = ? AND tenant_id = ? LIMIT 1");
    $q->execute([$id, vero_tenant()]);
    $r = $q->fetch();
    return $r === false ? null : $r;
}

/** Vínculo safra×talhão da safra em andamento mais recente (auto-safra 16/07). */
function api_safra_talhao_atual(int $talhaoId): ?int
{
    if (!api_tabela_existe('agro_safra_talhoes')) {
        return null;
    }
    $r = vero_row(
        "SELECT st.id
           FROM agro_safra_talhoes st
           JOIN agro_safras s ON s.id = st.safra_id AND s.tenant_id = st.tenant_id
          WHERE st.tenant_id = :t AND st.talhao_id = :ta
            AND (s.status IS NULL OR s.status NOT IN ('encerrada', 'cancelada'))
          ORDER BY s.data_inicio DESC LIMIT 1",
        [':t' => vero_tenant(), ':ta' => $talhaoId]
    );
    return $r ? (int)$r['id'] : null;
}

/** Carrega o resolver de fenologia por variedade do web (agro/_fenologia_helper.php). */
function api_fenologia_helper(): bool
{
    $arq = dirname(__DIR__, 3) . '/agro/_fenologia_helper.php';
    if (is_file($arq)) {
        require_once $arq;
    }
    return function_exists('vero_a1_fenologia_variedade_resolver');
}

/* Local de infestação — mesma whitelist do web (mig 163 + C-44 19/07). */
const API_MIP_LOCAIS = ['folha', 'ramo', 'cacho', 'ponteiros', 'casca', 'mato'];

/**
 * F3 estágio 2 — produção POR COLABORADOR no CONCLUIR do apontamento.
 * Replica o caminho do web (agro/apontamentos.php → apont_gravar_realizado):
 * linha em rh_producao_itens + custeio via vero_srv_apontamento_reemitir_custeio.
 * Regra (motor 5.4): CLT = premiação sobre o EXCEDENTE da meta
 * (vero_srv_premiacao_calc); tipo_vinculo 'terceirizado' = produção INTEGRAL
 * (vero_srv_valor_producao). Meta/valor podem vir do corpo (linha, como no web);
 * ausentes, caem na regra vigente (rh_regras_premiacao) — o app NUNCA calcula.
 * Devolve resumo ou NULL quando o corpo não traz colaboradores.
 */
function api_apontamento_gravar_producao(int $apontId, array $apont, array $corpo): ?array
{
    $cols = $corpo['colaboradores'] ?? null;
    if (!is_array($cols) || $cols === []) {
        return null;
    }
    // Banco defasado sem a mig 130: não quebra a conclusão — só não grava produção.
    if (!api_tabela_existe('rh_producao_itens') || !api_tabela_existe('agro_operadores')) {
        return ['itens' => 0, 'aviso' => 'servidor sem tabelas de produção — colaboradores ignorados'];
    }

    $data = substr((string)$apont['data_apontamento'], 0, 10);

    // contexto safra×cultura do apontamento (base da regra e do custeio)
    $stId = isset($apont['safra_talhao_id']) && $apont['safra_talhao_id'] !== null
        ? (int)$apont['safra_talhao_id'] : null;
    $safraId = null;
    $culturaId = null;
    if ($stId !== null) {
        $v = vero_row(
            "SELECT safra_id, cultura_id FROM agro_safra_talhoes WHERE id = :i AND tenant_id = :t",
            [':i' => $stId, ':t' => vero_tenant()]
        );
        if ($v) {
            $safraId = (int)$v['safra_id'];
            $culturaId = $v['cultura_id'] !== null ? (int)$v['cultura_id'] : null;
        }
    }
    // A3-T6 (P-06): safra com custeio fechado não recebe produção nova (igual web)
    if ($safraId !== null && function_exists('vero_srv_custeio_pode_lancar')) {
        $g = vero_srv_custeio_pode_lancar($safraId);
        if (!($g['pode'] ?? true)) {
            api_erro('safra_fechada', (string)($g['motivo'] ?? 'Custeio fechado para esta safra.'), 422);
        }
    }

    // regra de premiação vigente (unidade + meta/valor de fallback) — 5.1: no web
    // meta/valor vêm da LINHA; a regra é a sugestão do servidor p/ o app
    $tipoId = isset($apont['tipo_atividade_id']) && $apont['tipo_atividade_id'] !== null
        ? (int)$apont['tipo_atividade_id'] : null;
    $regra = ($tipoId !== null && function_exists('vero_srv_regra_premiacao'))
        ? vero_srv_regra_premiacao($tipoId, $culturaId, $data) : null;
    $unidade = (string)($regra['unidade'] ?? '');
    if ($unidade === '' && $tipoId !== null) {
        $u = vero_val(
            "SELECT unidade_padrao FROM agro_tipos_atividade WHERE id = :i AND tenant_id = :t",
            [':i' => $tipoId, ':t' => vero_tenant()]
        );
        $unidade = $u !== false && $u !== null ? (string)$u : '';
    }
    // enum de rh_producao_itens não tem 'cacho'/'fila' (do tipo de atividade)
    if (!in_array($unidade, ['planta', 'caixa', 'kg', 'ha', 'metro_linear', 'hora', 'outro'], true)) {
        $unidade = 'outro';
    }

    $itens = [];
    $total = 0.0;
    $vistos = [];
    foreach ($cols as $c) {
        if (!is_array($c)) {
            continue;
        }
        $qtd = api_c_dec($c, 'quantidade');

        // Pessoas do apontamento (gestor 22/07 — espelho do web): TERCEIRIZADO
        // vem de rh_terceirizados (tabela própria) com modalidade produção OU
        // diária; o valor da diária sai do CADASTRO (o app não envia valores).
        $terId = api_c_int($c, 'terceirizado_id');
        if ($terId !== null || (string)($c['origem'] ?? '') === 'terceirizado') {
            if ($terId === null || $qtd === null || $qtd < 0) {
                api_erro('colaborador_invalido', "Item de terceirizado precisa de terceirizado_id e quantidade (>= 0).", 422);
            }
            $chave = 'ter:' . $terId;
            if (isset($vistos[$chave])) {
                api_erro('colaborador_repetido', "Terceirizado {$terId} repetido na lista.", 422);
            }
            $vistos[$chave] = true;
            $ter = api_tabela_existe('rh_terceirizados')
                ? vero_row(
                    "SELECT id, nome, modalidade_padrao, valor_diaria FROM rh_terceirizados
                      WHERE id = :i AND tenant_id = :t AND ativo = 1",
                    [':i' => $terId, ':t' => vero_tenant()]
                ) : null;
            if (!$ter) {
                api_erro('colaborador_invalido', "Terceirizado {$terId} não encontrado (ou inativo).", 422);
            }
            $modal = (string)($c['modalidade'] ?? '') === 'diaria' ? 'diaria' : 'producao';
            // diária: valor do cadastro; produção: valor fica p/ o escritório na validação
            $valorUnF = $modal === 'diaria'
                ? (float)($ter['valor_diaria'] ?? 0)
                : (api_c_dec($c, 'valor_unitario') ?? 0.0);
            $valorTotal = function_exists('vero_srv_valor_producao')
                ? vero_srv_valor_producao($qtd, $valorUnF) : round($qtd * $valorUnF, 2);
            $itens[] = [
                'origem_pessoa' => 'terceirizado', 'operador_id' => null, 'terceirizado_id' => $terId,
                'modalidade' => $modal, 'regra_premiacao_id' => null,
                'unidade' => $modal === 'diaria' ? 'outro' : $unidade,
                'quantidade' => $qtd, 'peso_kg' => api_c_dec($c, 'peso_kg'),
                'meta_aplicada' => null, 'valor_unitario' => $valorUnF,
                'qtd_acima_meta' => null, 'valor_total' => $valorTotal,
                'data_trabalho' => $data,
            ];
            $total += (float)end($itens)['valor_total'];
            continue;
        }

        $colabId = api_c_int($c, 'colaborador_id');
        if ($colabId === null || $qtd === null || $qtd < 0) {
            api_erro('colaborador_invalido', "Cada item de 'colaboradores' precisa de colaborador_id e quantidade (>= 0).", 422);
        }
        if (isset($vistos['op:' . $colabId])) {
            api_erro('colaborador_repetido', "Colaborador {$colabId} repetido na lista.", 422);
        }
        $vistos['op:' . $colabId] = true;
        $op = vero_row(
            "SELECT id, nome, tipo_vinculo FROM agro_operadores WHERE id = :i AND tenant_id = :t AND ativo = 1",
            [':i' => $colabId, ':t' => vero_tenant()]
        );
        if (!$op) {
            api_erro('colaborador_invalido', "Colaborador {$colabId} não encontrado (ou inativo).", 422);
        }

        $meta    = api_c_dec($c, 'meta');
        $valorUn = api_c_dec($c, 'valor_unitario');
        $pesoKg  = api_c_dec($c, 'peso_kg');

        if ((string)$op['tipo_vinculo'] === 'terceirizado') {
            // terceirizado = produção INTEGRAL (qtd × valor), sem meta
            $valorUnF = $valorUn ?? 0.0;
            $valorTotal = function_exists('vero_srv_valor_producao')
                ? vero_srv_valor_producao($qtd, $valorUnF) : round($qtd * $valorUnF, 2);
            $itens[] = [
                'origem_pessoa' => 'colaborador', 'operador_id' => $colabId, 'terceirizado_id' => null,
                'modalidade' => 'producao', 'regra_premiacao_id' => null,
                'unidade' => $unidade, 'quantidade' => $qtd, 'peso_kg' => $pesoKg,
                'meta_aplicada' => null, 'valor_unitario' => $valorUnF,
                'qtd_acima_meta' => null, 'valor_total' => $valorTotal,
                'data_trabalho' => $data,
            ];
        } else {
            // CLT (e demais vínculos) = premiação sobre o EXCEDENTE da meta
            $metaF    = $meta ?? (isset($regra['meta_qtd']) && $regra['meta_qtd'] !== null ? (float)$regra['meta_qtd'] : null);
            $valorUnF = $valorUn ?? (isset($regra['valor_acima_meta']) && $regra['valor_acima_meta'] !== null ? (float)$regra['valor_acima_meta'] : 0.0);
            $calc = function_exists('vero_srv_premiacao_calc')
                ? vero_srv_premiacao_calc($qtd, $metaF ?? 0.0, $valorUnF)
                : ['qtd_acima' => max(0.0, $qtd - ($metaF ?? 0.0)), 'valor_total' => round(max(0.0, $qtd - ($metaF ?? 0.0)) * $valorUnF, 2)];
            $itens[] = [
                'origem_pessoa' => 'colaborador', 'operador_id' => $colabId, 'terceirizado_id' => null,
                'modalidade' => 'premiacao',
                'regra_premiacao_id' => $regra ? (int)$regra['id'] : null,
                'unidade' => $unidade, 'quantidade' => $qtd, 'peso_kg' => $pesoKg,
                'meta_aplicada' => $metaF, 'valor_unitario' => $valorUnF,
                'qtd_acima_meta' => $calc['qtd_acima'], 'valor_total' => $calc['valor_total'],
                'data_trabalho' => $data,
            ];
        }
        $total += (float)end($itens)['valor_total'];
    }
    if ($itens === []) {
        return null;
    }

    // Delete+reinsert (mesmo padrão do web ao regravar o realizado) — deixa o
    // replay da fila offline idempotente sem duplicar linhas nem custeio.
    $pdo = vero_pdo();
    $pdo->beginTransaction();
    try {
        if (function_exists('vero_srv_apontamento_limpar_itens')) {
            vero_srv_apontamento_limpar_itens($apontId);
        } else {
            $pdo->prepare('DELETE FROM rh_producao_itens WHERE tenant_id = ? AND apontamento_id = ?')
                ->execute([vero_tenant(), $apontId]);
        }
        foreach ($itens as $item) {
            $item['apontamento_id'] = $apontId;
            vero_insert('rh_producao_itens', $item);
        }
        if (function_exists('vero_srv_apontamento_reemitir_custeio')) {
            vero_srv_apontamento_reemitir_custeio($apontId); // custeio MDO (motor do web)
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Resumo p/ o app exibir na conclusão (desenho Onda 9 §3: o prêmio calculado
    // volta na RESPOSTA da escrita; nenhum salário/custo desce no sync — P-75).
    return ['itens' => count($itens), 'valor_premiacao_total' => round($total, 2)];
}

/* ============================================================
   1) POST /apontamentos — cria apontamento de campo
   ============================================================ */
function rota_apontamento_criar(array $usuario): never
{
    // Operador tem 'agro.apontamentos.*' no fallback → cobre .editar.
    api_exigir('agro.apontamentos_campo.editar');

    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');
    $tipo = (string)api_exigir_campo($corpo, 'tipo');

    // Irrigação NÃO entra pelo apontamento de campo (decisão do gestor 15/07;
    // mesmo guard do web) — usar POST /irrigacao/apontamentos.
    $tiposValidos = ['aplicacao', 'nutricao', 'tratos_culturais', 'colheita', 'abastecimento', 'outro'];
    if (!in_array($tipo, $tiposValidos, true)) {
        api_erro('tipo_invalido', 'Tipo de apontamento inválido.', 422);
    }

    // talhao_id é NOT NULL no banco (FK agro_talhoes) — obrigatório mesmo p/ abastecimento.
    $talhaoId = api_c_int($corpo, 'talhao_id');
    if ($talhaoId === null) {
        api_erro('campo_obrigatorio', "Informe o campo 'talhao_id'.", 422);
    }
    if (api_linha_tenant('agro_talhoes', $talhaoId) === null) {
        api_erro('talhao_invalido', 'Válvula não encontrado.', 422);
    }

    $atividadeId = api_c_int($corpo, 'atividade_id');
    if ($atividadeId !== null && api_linha_tenant('agro_atividades', $atividadeId) === null) {
        api_erro('atividade_invalida', 'Atividade não encontrada.', 422);
    }

    $maquinaId = api_c_int($corpo, 'maquina_id');
    if ($maquinaId !== null && api_linha_tenant('maquinas', $maquinaId) === null) {
        api_erro('maquina_invalida', 'Máquina não encontrada.', 422);
    }

    $dataApont = api_c_datahora($corpo, 'data', date('Y-m-d H:i:s'));

    // Responsável (mig 152, obrigatório no web): no campo, quem registra É o
    // responsável — resolve o operador vinculado ao usuário autenticado.
    $respId = null;
    if (vero_has_column('agro_apontamentos', 'responsavel_id')
        && vero_has_column('agro_operadores', 'usuario_id')) {
        $r = vero_val(
            "SELECT id FROM agro_operadores
              WHERE tenant_id = :t AND usuario_id = :u AND ativo = 1
              ORDER BY id LIMIT 1",
            [':t' => vero_tenant(), ':u' => vero_uid()]
        );
        // vero_val devolve FALSE sem linha — (int)false seria 0 e violaria a FK
        $respId = ((int)$r) > 0 ? (int)$r : null;
    }

    // Vínculo safra×válvula da safra em andamento (auto-safra, padrão web 16/07)
    $stId = api_safra_talhao_atual($talhaoId);

    // Fase fenológica POR VARIEDADE (migs 157/159/164): dias desde a poda
    $faseId = null;
    $diasPoda = null;
    if ($stId !== null && api_fenologia_helper()) {
        $fase = vero_a1_fenologia_variedade_resolver($stId, null, substr((string)$dataApont, 0, 10));
        if ($fase !== null) {
            $faseId = (int)$fase['id'];
            $diasPoda = (int)$fase['dias'];
        }
    }

    // 8.2 (auto-safra pós-poda): a Abertura de Safra identifica poda pelo
    // tipo_atividade_id (agro_tipos_atividade, categoria trato_cultural,
    // nome %poda%). O app manda atividade_rotulo='poda' → resolvemos o id
    // para o apontamento CONTAR como pré-condição/dia0 na tela do web.
    $tipoAtividadeId = null;
    if (api_c_str($corpo, 'atividade_rotulo', 30) === 'poda'
        && vero_has_column('agro_tipos_atividade', 'categoria')) {
        $r = vero_val(
            "SELECT id FROM agro_tipos_atividade
              WHERE tenant_id = :t AND categoria = 'trato_cultural' AND nome LIKE :nm
              ORDER BY id LIMIT 1",
            [':t' => vero_tenant(), ':nm' => '%poda%']
        );
        $tipoAtividadeId = ((int)$r) > 0 ? (int)$r : null;
    }

    $dados = [
        'atividade_id'     => $atividadeId,
        'talhao_id'        => $talhaoId,
        'tipo'             => $tipo,
        // operador executante = o próprio responsável resolvido (ou NULL se o
        // usuário não tem cadastro de operador; a autoria vai em created_by).
        'operador_id'      => $respId,
        'data_apontamento' => $dataApont,
        'origem'           => 'app',
        'status'           => 'pendente', // app entra pendente; validação é no web
        'latitude'         => api_c_dec($corpo, 'latitude'),
        'longitude'        => api_c_dec($corpo, 'longitude'),
        'observacao'       => api_c_str($corpo, 'observacao', 255),
        'hectares'         => api_c_dec($corpo, 'hectares'),
    ];

    // Colheita por CLASSIFICAÇÃO (gestor 23/07 — espelho de colheita/index.php):
    // o app manda {classificacoes: {premium, cat1, cat2, cat3, perdidos}} na
    // unidade quantidade_unidade; vira nota estruturada + total somado (a
    // colheita OFICIAL com estoque/faturamento segue sendo lançada no web).
    $classifs = is_array($corpo['classificacoes'] ?? null) ? $corpo['classificacoes'] : null;
    $qtd = api_c_dec($corpo, 'quantidade');
    $qtdUn = api_c_str($corpo, 'quantidade_unidade', 10);
    if ($classifs !== null) {
        $rotulos = ['premium' => 'Premium', 'cat1' => 'CAT 1', 'cat2' => 'CAT 2', 'cat3' => 'CAT 3', 'perdidos' => 'Perdidos'];
        $fmtC = static fn(float $n): string => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
        $unC = in_array($qtdUn, ['kg', 'caixa'], true) ? $qtdUn : 'kg';
        $partes = [];
        $soma = 0.0;
        foreach ($rotulos as $chave => $rotulo) {
            $v = isset($classifs[$chave]) && is_numeric($classifs[$chave]) ? (float)$classifs[$chave] : 0.0;
            if ($v > 0) {
                $partes[] = $rotulo . ' ' . $fmtC($v);
                $soma += $v;
            }
        }
        if ($partes !== []) {
            $notaC = '[app] classificação (' . ($unC === 'caixa' ? 'caixas' : 'kg') . '): ' . implode(' · ', $partes);
            $dados['observacao'] = $dados['observacao'] !== null
                ? mb_substr($dados['observacao'] . ' — ' . $notaC, 0, 255)
                : $notaC;
            // total vira a quantidade C-09 (nota + conversão caixa→kg abaixo)
            if ($qtd === null) {
                $qtd = $soma;
                $qtdUn = $unC;
            }
        }
    }

    // C-09/T-13: quantidade da finalização por tipo (Poda→plantas; Colheita→
    // peso). Caixa converte p/ kg pela config do tenant (backbone é sempre kg).
    // Sem coluna própria no cabeçalho, a nota estruturada vai na observação —
    // a apuração oficial por colaborador segue no web (rh_producao_itens).
    if ($qtd !== null && $qtd > 0 && in_array($qtdUn, ['plantas', 'kg', 'caixa'], true)) {
        $fmt = static fn(float $n): string => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
        if ($qtdUn === 'caixa') {
            $pesoCaixa = function_exists('vero_srv_param')
                ? (float)vero_srv_param('colheita.peso_caixa_kg', '0') : 0.0;
            $nota = '[app] colheita: ' . $fmt($qtd) . ' caixas'
                  . ($pesoCaixa > 0 ? ' (= ' . $fmt($qtd * $pesoCaixa) . ' kg)' : '');
        } elseif ($qtdUn === 'kg') {
            $nota = '[app] colheita: ' . $fmt($qtd) . ' kg';
        } else {
            $nota = '[app] poda: ' . $fmt($qtd) . ' plantas';
        }
        $dados['observacao'] = $dados['observacao'] !== null
            ? mb_substr($dados['observacao'] . ' — ' . $nota, 0, 255)
            : $nota;
    }
    if ($respId !== null) {
        $dados['responsavel_id'] = $respId;
    }
    if (vero_has_column('agro_apontamentos', 'safra_talhao_id')) {
        $dados['safra_talhao_id'] = $stId;
    }
    if (vero_has_column('agro_apontamentos', 'variedade_fase_id')) {
        $dados['variedade_fase_id'] = $faseId;
        $dados['dias_desde_poda']   = $diasPoda;
    }
    if ($tipoAtividadeId !== null) {
        $dados['tipo_atividade_id'] = $tipoAtividadeId; // poda visível à Abertura de Safra
    }

    // Dois estágios (mig 167 — pedido do gestor 20/07): iniciar a tarefa no
    // app cria o apontamento espelho JÁ ABERTO no sistema (status 'iniciado');
    // o /concluir promove para 'pendente' (aguardando validação do web).
    if (api_c_str($corpo, 'estagio', 20) === 'iniciado'
        && vero_has_column('agro_apontamentos', 'iniciado_em')) {
        $dados['status']      = 'iniciado';
        $dados['iniciado_em'] = date('Y-m-d H:i:s');
    }

    api_idempotente($clientUuid, 'apontamento', function () use ($dados, $maquinaId, $tipoAtividadeId, $talhaoId) {
        $id = vero_insert('agro_apontamentos', $dados);

        // Vínculo com máquina: tabela sem created_by/updated_by → INSERT direto.
        if ($maquinaId !== null && api_tabela_existe('agro_apontamento_maquinas')) {
            vero_pdo()->prepare(
                'INSERT INTO agro_apontamento_maquinas (tenant_id, apontamento_id, maquina_id, horas)
                 VALUES (?,?,?,0)'
            )->execute([vero_tenant(), $id, $maquinaId]);
        }

        // 8.2: poda registrada → avisa o escritório (fail-safe; ativa em build EAS)
        if ($tipoAtividadeId !== null) {
            require_once __DIR__ . '/../nucleo/push.php';
            $valvula = (string)(vero_val(
                'SELECT nome FROM agro_talhoes WHERE id = :i AND tenant_id = :t',
                [':i' => $talhaoId, ':t' => vero_tenant()]) ?? '');
            push_notificar_tenant(
                'Poda registrada no campo',
                'Poda apontada na válvula ' . ($valvula ?: $talhaoId) . ' — confirme na Abertura de Safra.',
                ['tela' => 'Tarefas']
            );
        }
        return [$id, ['id' => $id]];
    });
}

/* ============================================================
   2) POST /apontamentos/{client_uuid}/concluir
   ============================================================ */
function rota_apontamento_concluir(array $usuario, string $clientUuid): never
{
    api_exigir('agro.apontamentos_campo.editar');

    $id = api_recurso_por_uuid($clientUuid, 'apontamento');
    $apont = $id !== null ? api_linha_tenant('agro_apontamentos', $id) : null;
    if ($id === null || $apont === null) {
        api_erro('nao_encontrado', 'Apontamento não encontrado para este dispositivo.', 404);
    }

    $corpo = api_corpo();

    // F3 estágio 2: produção por colaborador (premiação) ANTES de concluir —
    // se a gravação falhar, o apontamento continua aberto p/ reenvio.
    $producao = api_apontamento_gravar_producao($id, $apont, $corpo);

    $notas = [];
    if (($horaFim = api_c_str($corpo, 'hora_fim', 20)) !== null) {
        $notas[] = "[app] concluído às {$horaFim}";
    }
    if (($obs = api_c_str($corpo, 'observacao', 255)) !== null) {
        $notas[] = $obs;
    }
    $nota = $notas ? implode(' — ', $notas) : null;

    // Dois estágios (mig 167): a conclusão carimba finalizado_em/por e promove
    // 'iniciado' → 'pendente' (fica em aberto p/ validação no web).
    $setEstagio = '';
    if (vero_has_column('agro_apontamentos', 'finalizado_em')) {
        $setEstagio = ", finalizado_em = NOW(), finalizado_por = " . (int)vero_uid()
                    . ", status = IF(status = 'iniciado', 'pendente', status)";
    }

    // observacao é VARCHAR(255): SUBSTRING evita estouro em modo estrito.
    vero_pdo()->prepare(
        "UPDATE agro_apontamentos
            SET observacao = SUBSTRING(CONCAT_WS(CHAR(10), observacao, ?), 1, 255),
                updated_by = ?{$setEstagio}
          WHERE id = ? AND tenant_id = ?"
    )->execute([$nota, vero_uid(), $id, vero_tenant()]);

    api_ok(['id' => $id] + ($producao !== null ? ['producao' => $producao] : []), 'Apontamento concluído.');
}

/* ============================================================
   2b) POST /apontamentos/{id}/concluir — conclui por ID numérico
   (a lista do app agora vem de /sync/apontamentos_abertos, que
   inclui apontamentos iniciados em QUALQUER aparelho/no web)
   ============================================================ */
/* POST /colheitas/{id}/realizado — o escritório LANÇOU a colheita (prevista)
   na tela Colheita; o campo PREENCHE o realizado pelo app (gestor 23/07:
   "colheitas pendentes aparecem em Tarefas para preenchimento").
   Atualiza o registro existente: kg/ha + kg_total realizado, classificações
   do momento 'realizado' (replace — replay idempotente) e o mesmo alerta de
   carência A1-19. Preços/entrada de estoque seguem com o escritório. */
function rota_colheita_realizado_id(array $usuario, string $id): never
{
    api_exigir('agro.colheita.editar');

    $id = (int)$id;
    $reg = api_linha_tenant('colheita_registros', $id);
    if ($reg === null) {
        api_erro('nao_encontrado', 'Registro de colheita não encontrado.', 404);
    }

    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');

    // classificações do corpo — MESMA leitura do criar (unidade caixa→kg)
    $classifs = is_array($corpo['classificacoes'] ?? null) ? $corpo['classificacoes'] : [];
    $unidade = api_c_str($corpo, 'unidade', 10) === 'caixa' ? 'caixa' : 'kg';
    $pesoCaixa = 0.0;
    if ($unidade === 'caixa') {
        $pesoCaixa = function_exists('vero_srv_param')
            ? (float)vero_srv_param('colheita.peso_caixa_kg', '0') : 0.0;
        if ($pesoCaixa <= 0) {
            api_erro('sem_peso_caixa', 'Grave o peso da caixa (colheita.peso_caixa_kg) nos Parâmetros do Sistema para apontar em caixas.', 422);
        }
    }
    $rotulos = ['premium' => 'Premium', 'cat1' => 'CAT 1', 'cat2' => 'CAT 2', 'cat3' => 'CAT 3', 'perdidos' => 'Perdidos'];
    $porCat = [];
    $kgReal = 0.0;
    foreach ($rotulos as $chave => $rotulo) {
        $v = isset($classifs[$chave]) && is_numeric($classifs[$chave]) ? (float)$classifs[$chave] : 0.0;
        if ($v < 0) {
            api_erro('quantidade_invalida', 'Quantidade de classificação não pode ser negativa.', 422);
        }
        if ($v > 0) {
            $kg = $unidade === 'caixa' ? round($v * $pesoCaixa, 3) : round($v, 3);
            $porCat[$chave] = ['bruto' => $v, 'kg' => $kg];
            $kgReal += $kg;
        }
    }
    if ($porCat === []) {
        api_erro('campo_obrigatorio', 'Informe a quantidade colhida de ao menos uma classificação.', 422);
    }
    $kgReal = round($kgReal, 3);

    // área: válvula do registro (fallback: área plantada do vínculo)
    $area = (float)(vero_val("SELECT area_ha FROM agro_setores WHERE id = :i AND tenant_id = :t",
        [':i' => (int)$reg['setor_id'], ':t' => vero_tenant()]) ?: 0);
    if ($area <= 0 && $reg['safra_talhao_id'] !== null) {
        $area = (float)(vero_val("SELECT area_plantada_ha FROM agro_safra_talhoes WHERE id = :i AND tenant_id = :t",
            [':i' => (int)$reg['safra_talhao_id'], ':t' => vero_tenant()]) ?: 0);
    }
    $realKgHa = $area > 0 ? round($kgReal / $area, 3) : null;

    $fmt = static fn(float $n): string => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    $partesNota = [];
    foreach ($porCat as $chave => $q) {
        $partesNota[] = $rotulos[$chave] . ' ' . $fmt($q['bruto']);
    }
    $obs = api_c_str($corpo, 'observacao', 120);
    $nota = '[app] realizado (' . ($unidade === 'caixa' ? 'caixas' : 'kg') . '): ' . implode(' · ', $partesNota)
          . ($obs !== null ? ' — ' . $obs : '');

    api_idempotente($clientUuid, 'colheita_realizado', function () use ($reg, $id, $porCat, $kgReal, $realKgHa, $nota) {
        $pdo = vero_pdo();
        vero_update('colheita_registros', $id, [
            'producao_realizada_kg_ha' => $realKgHa,
            'kg_total_realizado'       => $kgReal,
            'observacao'               => mb_substr(
                trim((string)($reg['observacao'] ?? '')) !== ''
                    ? $reg['observacao'] . ' — ' . $nota : $nota, 0, 255),
        ]);
        // replace SÓ do momento 'realizado' (o previsto do escritório fica)
        $pdo->prepare("DELETE FROM colheita_classificacoes WHERE tenant_id=? AND registro_id=? AND momento='realizado'")
            ->execute([vero_tenant(), $id]);
        foreach ($porCat as $chave => $q) {
            vero_insert('colheita_classificacoes', [
                'registro_id'  => $id,
                'momento'      => 'realizado',
                'categoria'    => $chave,
                'percentual'   => $kgReal > 0 ? round($q['kg'] / $kgReal * 100, 2) : 0,
                'preco_kg'     => 0,
                'kg_calculado' => $q['kg'],
                'faturamento'  => 0,
                'causa_perda'  => $chave === 'perdidos' ? 'não informada (apontada no app)' : null,
            ]);
        }
        // alerta de carência (A1-19) — mesmo bloco do criar, idempotente
        $data = substr((string)$reg['data_colheita'], 0, 10);
        $avisoCarencia = 0;
        if (function_exists('vero_srv_talhao_carencias')) {
            $carencias = vero_srv_talhao_carencias((int)$reg['talhao_id'], $data);
            $pdo->prepare("DELETE FROM agro_alertas WHERE tenant_id=? AND origem_tipo='colheita_carencia' AND origem_id=?")
                ->execute([vero_tenant(), $id]);
            if ($carencias) {
                $avisoCarencia = count($carencias);
                $itens = array_map(static fn($c) =>
                    $c['produto'] . ' (aplicação #' . $c['aplicacao_id'] . ', liberação '
                    . date('d/m/Y', strtotime((string)$c['liberado_em'])) . ')',
                    array_slice($carencias, 0, 5));
                $fazendaId = (int)(vero_val("SELECT fazenda_id FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
                    [':i' => (int)$reg['talhao_id'], ':t' => vero_tenant()]) ?? 0);
                vero_insert('agro_alertas', [
                    'categoria'    => 'residuo',
                    'origem_tipo'  => 'colheita_carencia',
                    'origem_id'    => $id,
                    'fazenda_id'   => $fazendaId ?: null,
                    'talhao_id'    => (int)$reg['talhao_id'],
                    'safra_id'     => (int)$reg['safra_id'],
                    'severidade'   => 'critico',
                    'titulo'       => 'Colheita dentro do período de carência',
                    'mensagem'     => 'Colheita de ' . date('d/m/Y', strtotime($data)) . ' (produção preenchida pelo app) com carência ativa: '
                                      . implode('; ', $itens)
                                      . (count($carencias) > 5 ? ' e mais ' . (count($carencias) - 5) . ' item(ns)' : '')
                                      . '. Avaliação do responsável técnico pendente.',
                    'requer_validacao_tecnica' => 1,
                    'status'       => 'aberto',
                    'data'         => $data,
                ]);
            }
        }
        return [$id, ['id' => $id, 'kg_total' => $kgReal, 'carencia_ativa' => $avisoCarencia]];
    });
}

/* ============================================================
   POST /colheitas — REGISTRO OFICIAL de colheita (gestor 23/07:
   "tem que ir para colheita"). Espelho de colheita/index.php:
   grava colheita_registros + colheita_classificacoes (momento
   'realizado', percentuais derivados das quantidades do campo,
   preços zerados p/ o escritório completar) e emite o alerta de
   CARÊNCIA (categoria resíduo) igual ao web. A ENTRADA NO ESTOQUE
   continua sendo confirmada pelo escritório na listagem (A1-42).
   ============================================================ */
/* ============================================================
   ROMANEIO DE CARGA (colheita_cargas) — espelho de campo da tela
   agro/romaneios_colheita. O app REGISTRA a carga que saiu do campo
   (romaneio, válvula, peso, classificação, apontamento por unidade,
   destino); postar no estoque (pack) e excluir seguem no web.
   - válvula(setor) deriva talhão e safra (mesmo padrão de colheita.criar);
   - idempotente por client_uuid (tabela app_idempotencia, recurso 'carga_colheita');
   - romaneio é UNIQUE(tenant, romaneio): duplicado vira erro DEFINITIVO
     'romaneio_duplicado' (o app não fica em loop de retry).
   ============================================================ */
function rota_carga_registrar(array $usuario): never
{
    api_exigir('agro.romaneios_colheita.editar');

    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');

    // romaneio (obrigatório)
    $romaneio = api_c_str($corpo, 'romaneio', 40);
    if ($romaneio === null || trim($romaneio) === '') {
        api_erro('campo_obrigatorio', 'Informe o número do romaneio.', 422);
    }
    $romaneio = trim($romaneio);

    // válvula/setor → deriva talhão (mesma resolução do colheita.criar)
    $setorId = api_c_int($corpo, 'setor_id');
    if ($setorId === null) {
        api_erro('campo_obrigatorio', "Informe o campo 'setor_id' (válvula).", 422);
    }
    $setor = vero_row(
        'SELECT * FROM agro_setores WHERE id = :i AND tenant_id = :t',
        [':i' => $setorId, ':t' => vero_tenant()]
    );
    if (!$setor || $setor['talhao_id'] === null) {
        api_erro('talhao_invalido', 'Válvula inválida ou sem talhão vinculado.', 422);
    }
    $talhaoId = (int)$setor['talhao_id'];
    $safraTalhaoId = api_safra_talhao_atual($talhaoId); // pode ser null (carga sem safra é aceita)

    // peso (> 0)
    $peso = api_c_dec($corpo, 'peso_kg');
    if ($peso === null || $peso <= 0) {
        api_erro('quantidade_invalida', 'Peso (kg) deve ser maior que zero.', 422);
    }

    // classificação (whitelist; aceita null)
    $classif = api_c_str($corpo, 'classificacao', 40);
    if ($classif !== null && !in_array($classif, ['Premium', 'CAT 1', 'CAT 2', 'CAT 3', 'Perdidos'], true)) {
        $classif = null;
    }

    // apontamento por unidade (whitelist; qtd > 0; fator caixa→palete)
    $unApont = api_c_str($corpo, 'unidade_apont', 20);
    if ($unApont !== null && !in_array($unApont, ['caixa', 'palete', 'cumbuca'], true)) {
        $unApont = null;
    }
    $qtdApont = api_c_dec($corpo, 'qtd_apont');
    if ($qtdApont !== null && $qtdApont <= 0) { $qtdApont = null; }
    $cxPalete = api_c_int($corpo, 'caixas_por_palete');
    if ($cxPalete !== null && $cxPalete <= 0) { $cxPalete = null; }
    if ($unApont === null) { $qtdApont = null; $cxPalete = null; }

    // destino (whitelist)
    $destino = api_c_str($corpo, 'destino', 20);
    if ($destino !== null && !in_array($destino, ['venda', 'packing', 'armazenagem', 'descarte', 'doacao'], true)) {
        $destino = null;
    }

    // vínculo opcional ao registro de colheita (revalida FK; herda a safra dele)
    $registroId = api_c_int($corpo, 'registro_id');
    if ($registroId !== null && api_linha_tenant('colheita_registros', $registroId) === null) {
        $registroId = null; // vínculo inválido não bloqueia — grava sem vínculo (o web tolera igual)
    }
    if ($registroId !== null) {
        $rst = vero_val('SELECT safra_talhao_id FROM colheita_registros WHERE id=:i AND tenant_id=:t',
            [':i' => $registroId, ':t' => vero_tenant()]);
        if ($rst) { $safraTalhaoId = (int)$rst; }
    }

    $data = substr((string)api_c_datahora($corpo, 'data_carga', date('Y-m-d H:i:s')), 0, 10);

    // pré-checagem do romaneio (UNIQUE por tenant) → erro DEFINITIVO, sem retry
    if (vero_val('SELECT id FROM colheita_cargas WHERE tenant_id=:t AND romaneio=:r',
            [':t' => vero_tenant(), ':r' => $romaneio])) {
        api_erro('romaneio_duplicado', "Já existe uma carga com o romaneio {$romaneio}. Use outro número.", 409);
    }

    // colunas mais novas (destino/unidade_apont/...) protegidas p/ banco defasado
    $carga = [
        'romaneio'        => $romaneio,
        'talhao_id'       => $talhaoId,
        'registro_id'     => $registroId,
        'safra_talhao_id' => $safraTalhaoId,
        'data_carga'      => $data,
        'peso_kg'         => $peso,
        'classificacao'   => $classif,
        'origem'          => 'app',
    ];
    foreach (['destino' => $destino, 'unidade_apont' => $unApont,
              'qtd_apont' => $qtdApont, 'caixas_por_palete' => $cxPalete] as $col => $val) {
        if (vero_has_column('colheita_cargas', $col)) { $carga[$col] = $val; }
    }

    $id = api_idempotente($clientUuid, 'carga_colheita', function () use ($carga, $romaneio) {
        try {
            return vero_insert('colheita_cargas', $carga);
        } catch (Throwable $e) {
            // corrida: outro envio gravou o mesmo romaneio entre a checagem e o insert
            if (stripos($e->getMessage(), 'uq_carga_romaneio') !== false
                || stripos($e->getMessage(), 'Duplicate') !== false) {
                api_erro('romaneio_duplicado', "Já existe uma carga com o romaneio {$romaneio}. Use outro número.", 409);
            }
            throw $e;
        }
    });

    api_ok(['id' => $id, 'romaneio' => $romaneio], "Carga {$romaneio} registrada.");
}

function rota_colheita_criar(array $usuario): never
{
    api_exigir('agro.colheita.editar');

    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');

    $setorId = api_c_int($corpo, 'setor_id');
    if ($setorId === null) {
        api_erro('campo_obrigatorio', "Informe o campo 'setor_id' (válvula).", 422);
    }
    $setor = vero_row(
        "SELECT * FROM agro_setores WHERE id = :i AND tenant_id = :t",
        [':i' => $setorId, ':t' => vero_tenant()]
    );
    if (!$setor || $setor['talhao_id'] === null) {
        api_erro('talhao_invalido', 'Válvula inválida ou sem válvula vinculada.', 422);
    }
    $talhaoId = (int)$setor['talhao_id'];

    // vínculo de safra VIGENTE da válvula (mesma resolução do resto do app)
    $stId = api_safra_talhao_atual($talhaoId);
    $vinculo = $stId !== null
        ? vero_row("SELECT * FROM agro_safra_talhoes WHERE id = :i AND tenant_id = :t",
            [':i' => $stId, ':t' => vero_tenant()])
        : null;
    if (!$vinculo) {
        api_erro('sem_safra', 'Não há safra em andamento vinculada a esta válvula — abra a safra no VERO web.', 422);
    }

    $variedadeId = api_c_int($corpo, 'variedade_id');
    if ($variedadeId !== null) {
        $okVar = vero_val(
            "SELECT id FROM agro_variedades WHERE id = :i AND tenant_id = :t AND cultura_id = :c",
            [':i' => $variedadeId, ':t' => vero_tenant(), ':c' => (int)$vinculo['cultura_id']]
        );
        $variedadeId = ((int)$okVar) > 0 ? $variedadeId : null;
    }

    $data = substr((string)api_c_datahora($corpo, 'data', date('Y-m-d H:i:s')), 0, 10);

    // quantidades por classificação (mesmas categorias do web) na unidade do app
    $classifs = is_array($corpo['classificacoes'] ?? null) ? $corpo['classificacoes'] : [];
    $unidade = api_c_str($corpo, 'unidade', 10) === 'caixa' ? 'caixa' : 'kg';
    $pesoCaixa = 0.0;
    if ($unidade === 'caixa') {
        $pesoCaixa = function_exists('vero_srv_param')
            ? (float)vero_srv_param('colheita.peso_caixa_kg', '0') : 0.0;
        if ($pesoCaixa <= 0) {
            api_erro('sem_peso_caixa', 'Grave o peso da caixa (colheita.peso_caixa_kg) nos Parâmetros do Sistema para apontar em caixas.', 422);
        }
    }
    $rotulos = ['premium' => 'Premium', 'cat1' => 'CAT 1', 'cat2' => 'CAT 2', 'cat3' => 'CAT 3', 'perdidos' => 'Perdidos'];
    $porCat = [];
    $kgReal = 0.0;
    foreach ($rotulos as $chave => $rotulo) {
        $v = isset($classifs[$chave]) && is_numeric($classifs[$chave]) ? (float)$classifs[$chave] : 0.0;
        if ($v < 0) {
            api_erro('quantidade_invalida', 'Quantidade de classificação não pode ser negativa.', 422);
        }
        if ($v > 0) {
            $kg = $unidade === 'caixa' ? round($v * $pesoCaixa, 3) : round($v, 3);
            $porCat[$chave] = ['bruto' => $v, 'kg' => $kg];
            $kgReal += $kg;
        }
    }
    if ($porCat === []) {
        api_erro('campo_obrigatorio', 'Informe a quantidade colhida de ao menos uma classificação.', 422);
    }
    $kgReal = round($kgReal, 3);

    $area = (float)($setor['area_ha'] ?? 0);
    if ($area <= 0) {
        $area = (float)($vinculo['area_plantada_ha'] ?? 0);
    }
    $realKgHa = $area > 0 ? round($kgReal / $area, 3) : null;

    // nota de origem preservando a unidade digitada no campo
    $fmt = static fn(float $n): string => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    $partesNota = [];
    foreach ($porCat as $chave => $q) {
        $partesNota[] = $rotulos[$chave] . ' ' . $fmt($q['bruto']);
    }
    $obs = api_c_str($corpo, 'observacao', 160);
    $nota = '[app] campo (' . ($unidade === 'caixa' ? 'caixas' : 'kg') . '): ' . implode(' · ', $partesNota)
          . ($obs !== null ? ' — ' . $obs : '');

    $cab = [
        'safra_id'                 => (int)$vinculo['safra_id'],
        'safra_talhao_id'          => (int)$stId,
        'talhao_id'                => $talhaoId,
        'setor_id'                 => $setorId,
        'cultura_id'               => (int)$vinculo['cultura_id'],
        'variedade_id'             => $variedadeId,
        'data_colheita'            => $data,
        'producao_prevista_kg_ha'  => null,
        'producao_realizada_kg_ha' => $realKgHa,
        'kg_total_previsto'        => 0,
        'kg_total_realizado'       => $kgReal,
        'faturamento_previsto'     => 0,
        'faturamento_realizado'    => 0,
        'observacao'               => mb_substr($nota, 0, 255),
        /* estágios (23/07): o app LANÇA como pendente; o escritório finaliza no web */
        'origem'                   => 'app',
        'status'                   => 'pendente',
    ];

    api_idempotente($clientUuid, 'colheita', function () use ($cab, $porCat, $kgReal, $rotulos, $talhaoId, $vinculo, $data) {
        $pdo = vero_pdo();
        $regId = vero_insert('colheita_registros', $cab);
        foreach ($porCat as $chave => $q) {
            vero_insert('colheita_classificacoes', [
                'registro_id'  => $regId,
                'momento'      => 'realizado',
                'categoria'    => $chave,
                'percentual'   => $kgReal > 0 ? round($q['kg'] / $kgReal * 100, 2) : 0,
                'preco_kg'     => 0,      // escritório completa os preços na edição
                'kg_calculado' => $q['kg'],
                'faturamento'  => 0,
                'causa_perda'  => $chave === 'perdidos' ? 'não informada (apontada no app)' : null,
            ]);
        }

        // A1-19 (espelho do web): colheita dentro da carência → alerta resíduo
        $avisoCarencia = 0;
        if (function_exists('vero_srv_talhao_carencias')) {
            $carencias = vero_srv_talhao_carencias($talhaoId, $data);
            $pdo->prepare("DELETE FROM agro_alertas WHERE tenant_id=? AND origem_tipo='colheita_carencia' AND origem_id=?")
                ->execute([vero_tenant(), (int)$regId]);
            if ($carencias) {
                $avisoCarencia = count($carencias);
                $itens = array_map(static fn($c) =>
                    $c['produto'] . ' (aplicação #' . $c['aplicacao_id'] . ', liberação '
                    . date('d/m/Y', strtotime((string)$c['liberado_em'])) . ')',
                    array_slice($carencias, 0, 5));
                $fazendaId = (int)(vero_val("SELECT fazenda_id FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
                    [':i' => $talhaoId, ':t' => vero_tenant()]) ?? 0);
                vero_insert('agro_alertas', [
                    'categoria'    => 'residuo',
                    'origem_tipo'  => 'colheita_carencia',
                    'origem_id'    => (int)$regId,
                    'fazenda_id'   => $fazendaId ?: null,
                    'talhao_id'    => $talhaoId,
                    'safra_id'     => (int)$vinculo['safra_id'],
                    'severidade'   => 'critico',
                    'titulo'       => 'Colheita dentro do período de carência',
                    'mensagem'     => 'Colheita de ' . date('d/m/Y', strtotime($data)) . ' (registrada pelo app) com carência ativa: '
                                      . implode('; ', $itens)
                                      . (count($carencias) > 5 ? ' e mais ' . (count($carencias) - 5) . ' item(ns)' : '')
                                      . '. Avaliação do responsável técnico pendente.',
                    'requer_validacao_tecnica' => 1,
                    'status'       => 'aberto',
                    'data'         => $data,
                ]);
            }
        }
        return [$regId, ['id' => $regId, 'kg_total' => $kgReal, 'carencia_ativa' => $avisoCarencia]];
    });
}

/* Pessoas do apontamento SEM finalizar (gestor 22/07): o operador vai
   adicionando e SALVANDO ao longo do serviço; o status continua 'iniciado'.
   Semântica de replace: o app manda a lista COMPLETA atual a cada save
   (delete+reinsert no helper) — replay da fila nunca duplica. */
function rota_apontamento_producao_id(array $usuario, string $id): never
{
    api_exigir('agro.apontamentos_campo.editar');

    $id = (int)$id;
    $apont = api_linha_tenant('agro_apontamentos', $id);
    if ($apont === null) {
        api_erro('nao_encontrado', 'Apontamento não encontrado.', 404);
    }
    $corpo = api_corpo();
    $producao = api_apontamento_gravar_producao($id, $apont, $corpo);
    if ($producao === null) {
        api_erro('campo_obrigatorio', "Informe ao menos uma pessoa em 'colaboradores'.", 422);
    }
    api_ok(['id' => $id, 'producao' => $producao], 'Pessoas do apontamento salvas.');
}

function rota_apontamento_concluir_id(array $usuario, string $id): never
{
    api_exigir('agro.apontamentos_campo.editar');

    $id = (int)$id;
    $apont = api_linha_tenant('agro_apontamentos', $id);
    if ($apont === null) {
        api_erro('nao_encontrado', 'Apontamento não encontrado.', 404);
    }

    $corpo = api_corpo();

    // F3 estágio 2: produção por colaborador (premiação) ANTES de concluir
    $producao = api_apontamento_gravar_producao($id, $apont, $corpo);

    $nota = api_c_str($corpo, 'observacao', 255);

    // Inversão do gestor (22/07): o app NÃO cria apontamento — conclui o que o
    // escritório iniciou, preenchendo a execução. A quantidade entra AQUI
    // (mesma nota estruturada C-09 do criar; caixa→kg pela config do tenant).
    $qtd = api_c_dec($corpo, 'quantidade');
    $qtdUn = api_c_str($corpo, 'quantidade_unidade', 10);
    if ($qtd !== null && $qtd > 0 && in_array($qtdUn, ['plantas', 'kg', 'caixa'], true)) {
        $fmt = static fn(float $n): string => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
        if ($qtdUn === 'caixa') {
            $pesoCaixa = function_exists('vero_srv_param')
                ? (float)vero_srv_param('colheita.peso_caixa_kg', '0') : 0.0;
            $qtdNota = '[app] colheita: ' . $fmt($qtd) . ' caixas'
                     . ($pesoCaixa > 0 ? ' (= ' . $fmt($qtd * $pesoCaixa) . ' kg)' : '');
        } elseif ($qtdUn === 'kg') {
            $qtdNota = '[app] colheita: ' . $fmt($qtd) . ' kg';
        } else {
            $qtdNota = '[app] poda: ' . $fmt($qtd) . ' plantas';
        }
        $nota = $nota !== null ? mb_substr($qtdNota . ' — ' . $nota, 0, 255) : $qtdNota;
    }

    $setEstagio = '';
    if (vero_has_column('agro_apontamentos', 'finalizado_em')) {
        $setEstagio = ", finalizado_em = NOW(), finalizado_por = " . (int)vero_uid()
                    . ", status = IF(status = 'iniciado', 'pendente', status)";
    }
    vero_pdo()->prepare(
        "UPDATE agro_apontamentos
            SET observacao = SUBSTRING(CONCAT_WS(CHAR(10), observacao, ?), 1, 255),
                updated_by = ?{$setEstagio}
          WHERE id = ? AND tenant_id = ?"
    )->execute([$nota, vero_uid(), $id, vero_tenant()]);

    api_ok(['id' => $id] + ($producao !== null ? ['producao' => $producao] : []), 'Apontamento concluído.');
}

/* ============================================================
   3) POST /monitoramentos — registro MIP (rascunho no servidor)
   ============================================================ */
function rota_monitoramento_criar(array $usuario): never
{
    // PT-02 (CSO): escrita exige slug de AÇÃO (consulta tem só *.ver → 403).
    api_exigir('mip.monitoramentos.editar');

    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');
    $talhaoId = api_c_int($corpo, 'talhao_id');
    if ($talhaoId === null) {
        api_erro('campo_obrigatorio', "Informe o campo 'talhao_id'.", 422);
    }
    if (api_linha_tenant('agro_talhoes', $talhaoId) === null) {
        api_erro('talhao_invalido', 'Válvula não encontrada.', 422);
    }

    // C-28 (mig 175): nº de plantas amostradas — com ele, quantidade vira
    // ÍNDICE pela regra de 3 (encontradas ÷ amostradas × 100), igual ao web.
    $amostradas = api_c_int($corpo, 'plantas_amostradas');
    if ($amostradas !== null && $amostradas <= 0) {
        $amostradas = null;
    }

    // Alvos: lista 'alvos' (multialvo, migs 170/173) OU campos soltos no corpo
    // (compat com o app até a Onda 2 — um alvo por chamada).
    $brutos = is_array($corpo['alvos'] ?? null) && $corpo['alvos'] !== []
        ? $corpo['alvos']
        : [[
            'alvo_id'               => $corpo['alvo_id'] ?? null,
            'quantidade_encontrada' => $corpo['quantidade_encontrada'] ?? null,
            'nivel_infestacao'      => $corpo['nivel_infestacao'] ?? null,
            'local_infestacao'      => $corpo['local_infestacao'] ?? null,
            'severidade_qualitativa' => $corpo['severidade_qualitativa'] ?? null,
        ]];

    $alvos = [];
    $vistos = []; // dedupe por alvo+LOCAL (C-27: mesmo alvo em locais ≠ é permitido)
    foreach ($brutos as $b) {
        if (!is_array($b)) {
            continue;
        }
        $aid = api_c_int($b, 'alvo_id');
        if ($aid === null) {
            api_erro('campo_obrigatorio', "Informe o campo 'alvo_id'.", 422);
        }
        if (api_linha_tenant('mip_alvos', $aid) === null) {
            api_erro('alvo_invalido', 'Alvo não encontrado.', 422);
        }
        $qtd   = api_c_dec($b, 'quantidade_encontrada');
        $nivel = api_c_dec($b, 'nivel_infestacao');
        // índice digitado vence; senão regra de 3 (C-28); senão qtd bruta; senão 0
        if ($nivel === null) {
            $nivel = $qtd !== null
                ? ($amostradas !== null ? round($qtd / $amostradas * 100, 2) : $qtd)
                : 0.0;
        }
        $loc = api_c_str($b, 'local_infestacao', 20);
        if ($loc !== null && !in_array($loc, API_MIP_LOCAIS, true)) {
            $loc = null; // whitelist (mig 163)
        }
        $sev = api_c_str($b, 'severidade_qualitativa', 10);
        if ($sev !== null && !in_array($sev, ['baixa', 'media', 'alta'], true)) {
            $sev = null;
        }
        $chave = $aid . '|' . ($loc ?? '');
        if (isset($vistos[$chave])) {
            api_erro('alvo_repetido', 'Alvo repetido no mesmo local — para repetir o alvo, escolha um local diferente.', 422);
        }
        $vistos[$chave] = true;
        $alvos[] = ['alvo_id' => $aid, 'nivel' => $nivel, 'qtd' => $qtd, 'local' => $loc, 'sev' => $sev];
    }
    if ($alvos === []) {
        api_erro('campo_obrigatorio', 'Informe ao menos um alvo.', 422);
    }

    // COMPAT: cabeçalho guarda o 1º alvo (mesma convenção do web pós-multialvo)
    $primeiro = $alvos[0];
    $dados = [
        'talhao_id'          => $talhaoId,
        'ponto_id'           => api_c_int($corpo, 'ponto_id'),
        'alvo_id'            => $primeiro['alvo_id'],
        // coluna é DATE — corta a parte de hora se o app mandar datetime
        'data_monitoramento' => substr((string)api_c_datahora($corpo, 'data', date('Y-m-d H:i:s')), 0, 10),
        'nivel_infestacao'   => $primeiro['nivel'],
        'unidade'            => api_c_str($corpo, 'unidade', 30),
        'observacao'         => api_c_str($corpo, 'observacao', 255),
    ];
    // Colunas por migração: só entram se o banco já tiver migrado.
    if (vero_has_column('mip_monitoramentos', 'quantidade_encontrada')) {
        $dados['quantidade_encontrada'] = $primeiro['qtd'];
    }
    if (vero_has_column('mip_monitoramentos', 'local_infestacao')) {
        $dados['local_infestacao'] = $primeiro['local'];        // mig 163
    }
    if (vero_has_column('mip_monitoramentos', 'severidade_qualitativa')) {
        $dados['severidade_qualitativa'] = $primeiro['sev'];
    }
    if (vero_has_column('mip_monitoramentos', 'plantas_amostradas')) {
        $dados['plantas_amostradas'] = $amostradas;             // mig 175
    }
    if (vero_has_column('mip_monitoramentos', 'safra_talhao_id')) {
        $dados['safra_talhao_id'] = api_safra_talhao_atual($talhaoId); // auto-safra
    }
    if (vero_has_column('mip_monitoramentos', 'status')) {
        $dados['status'] = 'rascunho'; // fluxo enviar-para-líder (DB-54)
    }
    if (vero_has_column('mip_monitoramentos', 'monitor_id')) {
        $dados['monitor_id'] = vero_uid();
    }

    api_idempotente($clientUuid, 'monitoramento', function () use ($dados, $alvos) {
        $id = vero_insert('mip_monitoramentos', $dados);
        // Junção multialvo (migs 170/173) — dados POR alvo+local
        if (api_tabela_existe('mip_monitoramento_alvos')) {
            foreach ($alvos as $a) {
                vero_insert('mip_monitoramento_alvos', [
                    'monitoramento_id'       => $id,
                    'alvo_id'                => $a['alvo_id'],
                    'nivel_infestacao'       => $a['nivel'],
                    'quantidade_encontrada'  => $a['qtd'],
                    'local_infestacao'       => $a['local'],
                    'severidade_qualitativa' => $a['sev'],
                ]);
            }
        }
        return [$id, ['id' => $id]];
    });
}

/* ============================================================
   4) POST /monitoramentos/enviar — rascunho→enviado + motor de
      alertas NO SERVIDOR (nível de ação do alvo, sem duplicar)
   ============================================================ */
function rota_monitoramentos_enviar(array $usuario): never
{
    api_exigir('mip.monitoramentos.editar'); // PT-02: escrita = slug de ação

    $corpo = api_corpo();
    $uuids = $corpo['uuids'] ?? null;
    if (!is_array($uuids) || $uuids === []) {
        api_erro('campo_obrigatorio', "Informe o campo 'uuids' (lista de client_uuid).", 422);
    }

    $pdo = vero_pdo();
    $tenant = vero_tenant();

    // Resolve os client_uuid → ids de mip_monitoramentos gravados na idempotência.
    $uuids = array_values(array_unique(array_filter(array_map(
        fn($u) => mb_substr(trim((string)$u), 0, 64),
        $uuids
    ), fn($u) => $u !== '')));
    if ($uuids === []) {
        api_erro('uuids_invalidos', 'Nenhum client_uuid válido informado.', 422);
    }
    $marc = implode(',', array_fill(0, count($uuids), '?'));
    $q = $pdo->prepare(
        "SELECT recurso_id FROM app_idempotencia
          WHERE tenant_id = ? AND recurso_tipo = 'monitoramento'
            AND recurso_id IS NOT NULL AND client_uuid IN ({$marc})"
    );
    $q->execute(array_merge([$tenant], $uuids));
    $ids = array_values(array_unique(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN))));

    if ($ids === []) {
        api_ok(['enviados' => 0, 'alertas_criados' => 0], 'Nenhum monitoramento localizado.');
    }
    $marcIds = implode(',', array_fill(0, count($ids), '?'));

    // 1) marca como enviado (se a coluna da migration 146 existir)
    $enviados = count($ids);
    if (vero_has_column('mip_monitoramentos', 'status')) {
        $up = $pdo->prepare(
            "UPDATE mip_monitoramentos SET status = 'enviado', updated_by = ?
              WHERE tenant_id = ? AND id IN ({$marcIds})"
        );
        $up->execute(array_merge([vero_uid(), $tenant], $ids));
    }

    // 2) MOTOR DO SERVIDOR: um alerta POR ALVO que atingiu o nível de ação
    //    (multialvo, migs 170/173) — mesma identidade do web (título por alvo em
    //    mip_reemitir_alertas), então a reedição no web reconcilia a trilha do RT.
    //    Regra 1 do produto: o sistema ALERTA, nunca recomenda produto.
    $temJuncao = api_tabela_existe('mip_monitoramento_alvos');
    $sqlAlvos = $temJuncao
        ? "SELECT m.id, m.talhao_id, m.unidade, m.data_monitoramento,
                  ma.nivel_infestacao, a.nome AS alvo_nome, a.nivel_acao,
                  t.fazenda_id
             FROM mip_monitoramentos m
             JOIN mip_monitoramento_alvos ma ON ma.monitoramento_id = m.id AND ma.tenant_id = m.tenant_id
             JOIN mip_alvos a ON a.id = ma.alvo_id
        LEFT JOIN agro_talhoes t ON t.id = m.talhao_id
            WHERE m.tenant_id = ? AND m.id IN ({$marcIds})
            ORDER BY m.id, ma.id"
        : "SELECT m.id, m.talhao_id, m.unidade, m.data_monitoramento,
                  m.nivel_infestacao, a.nome AS alvo_nome, a.nivel_acao,
                  t.fazenda_id
             FROM mip_monitoramentos m
             JOIN mip_alvos a ON a.id = m.alvo_id
        LEFT JOIN agro_talhoes t ON t.id = m.talhao_id
            WHERE m.tenant_id = ? AND m.id IN ({$marcIds})";
    $q = $pdo->prepare($sqlAlvos);
    $q->execute(array_merge([$tenant], $ids));

    $temValidacao = vero_has_column('agro_alertas', 'requer_validacao_tecnica');
    $temSafra     = vero_has_column('agro_alertas', 'safra_id')
                 && vero_has_column('mip_monitoramentos', 'safra_talhao_id');
    $alertasCriados = 0;
    $existe = $pdo->prepare(
        "SELECT id FROM agro_alertas
          WHERE tenant_id = ? AND origem_tipo = 'mip_monitoramento'
            AND origem_id = ? AND titulo = ? AND status = 'aberto' LIMIT 1"
    );
    foreach ($q->fetchAll() as $m) {
        if ($m['nivel_acao'] === null) {
            continue; // alvo sem nível de ação parametrizado — nada a disparar
        }
        $nivel = (float)$m['nivel_infestacao'];
        $acao = (float)$m['nivel_acao'];
        if ($nivel < $acao) {
            continue;
        }
        // identidade por alvo = TÍTULO (mesma convenção do web)
        $titulo = mb_substr($m['alvo_nome'] . ' atingiu o nível de ação', 0, 160);
        $existe->execute([$tenant, (int)$m['id'], $titulo]);
        if ($existe->fetchColumn()) {
            continue; // já existe alerta ABERTO deste alvo neste monitoramento
        }
        $unidade = $m['unidade'] !== null && $m['unidade'] !== '' ? ' ' . $m['unidade'] : '%';
        $alerta = [
            'categoria'   => 'mip',
            'origem_tipo' => 'mip_monitoramento',
            'origem_id'   => (int)$m['id'],
            'fazenda_id'  => $m['fazenda_id'] !== null ? (int)$m['fazenda_id'] : null,
            'talhao_id'   => (int)$m['talhao_id'],
            // 2× o nível de ação = crítico; senão atenção (mesma régua do web)
            'severidade'  => $nivel >= 2 * $acao ? 'critico' : 'atencao',
            'titulo'      => $titulo,
            'mensagem'    => 'Índice ' . number_format($nivel, 2, ',', '.') . $unidade
                           . ' (nível de ação: ' . number_format($acao, 2, ',', '.') . '%).'
                           . ' Manejo pendente de validação do responsável técnico.',
            'status'      => 'aberto',
            'data'        => (string)($m['data_monitoramento'] ?: date('Y-m-d')),
        ];
        if ($temValidacao) {
            $alerta['requer_validacao_tecnica'] = 1;
        }
        if ($temSafra) {
            $st = vero_row(
                "SELECT st.safra_id
                   FROM mip_monitoramentos mm
                   JOIN agro_safra_talhoes st ON st.id = mm.safra_talhao_id AND st.tenant_id = mm.tenant_id
                  WHERE mm.id = :i AND mm.tenant_id = :t",
                [':i' => (int)$m['id'], ':t' => $tenant]
            );
            $alerta['safra_id'] = $st ? (int)$st['safra_id'] : null;
        }
        vero_insert('agro_alertas', $alerta);
        $alertasCriados++;
    }

    // Onda 7.5: alerta novo → push nos aparelhos do tenant (fail-safe; só
    // chega em build EAS — Expo Go não recebe push remoto).
    if ($alertasCriados > 0) {
        require_once __DIR__ . '/../nucleo/push.php';
        push_notificar_tenant(
            'Alerta fitossanitário',
            $alertasCriados === 1
                ? 'Um alvo atingiu o nível de ação no monitoramento enviado.'
                : "{$alertasCriados} alvos atingiram o nível de ação no monitoramento enviado.",
            ['tela' => 'Avisos']
        );
    }

    api_ok(['enviados' => $enviados, 'alertas_criados' => $alertasCriados], 'Monitoramentos enviados.');
}

/* ============================================================
   5) POST /irrigacao/apontamentos
   ============================================================ */
function rota_irrigacao_criar(array $usuario): never
{
    // Mesmo slug do guard da tela web; operador tem 'irrigacao.*' no fallback.
    api_exigir('irrigacao.apontamentos_irrigacao.editar');

    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');
    $talhaoId = api_c_int($corpo, 'talhao_id');
    if ($talhaoId === null) {
        api_erro('campo_obrigatorio', "Informe o campo 'talhao_id'.", 422);
    }
    if (api_linha_tenant('agro_talhoes', $talhaoId) === null) {
        api_erro('talhao_invalido', 'Válvula não encontrada.', 422);
    }

    $horas = api_c_dec($corpo, 'horas') ?? 0;
    $stId  = api_safra_talhao_atual($talhaoId);

    $dados = [
        'talhao_id'        => $talhaoId,
        'horas'            => $horas,
        'lamina_mm'        => api_c_dec($corpo, 'lamina_mm') ?? 0,
        'data_apontamento' => api_c_datahora($corpo, 'data', date('Y-m-d H:i:s')),
    ];
    if (vero_has_column('irrigacao_apontamentos', 'safra_talhao_id')) {
        $dados['safra_talhao_id'] = $stId;
    }

    // CONSUMOS AUTOMÁTICOS pela bomba da válvula (mig 160 + C-21, mesma regra
    // do web): água = vazão×horas (m³), energia = potência×horas (kWh).
    // Valores do app prevalecem; custo = qtd × tarifa do tenant (C-21).
    $setorId = api_c_int($corpo, 'setor_id'); // id da válvula (agro_setores)
    $aguaQtd    = api_c_dec($corpo, 'agua_qtd');
    $energiaQtd = api_c_dec($corpo, 'energia_qtd');
    if (($aguaQtd === null || $energiaQtd === null)
        && $setorId !== null && $horas > 0
        && vero_has_column('agro_bomba_valvulas', 'setor_id')) {
        $bomba = vero_row(
            "SELECT b.vazao_m3h, b.potencia_kw
               FROM agro_bomba_valvulas bv
               JOIN agro_bombas b ON b.id = bv.bomba_id AND b.ativo = 1
              WHERE bv.tenant_id = :t AND bv.setor_id = :s
              ORDER BY bv.bomba_id LIMIT 1",
            [':t' => vero_tenant(), ':s' => $setorId]
        );
        if ($bomba) {
            if ($aguaQtd === null && $bomba['vazao_m3h'] !== null) {
                $aguaQtd = round((float)$bomba['vazao_m3h'] * $horas, 2);
            }
            if ($energiaQtd === null && $bomba['potencia_kw'] !== null) {
                $energiaQtd = round((float)$bomba['potencia_kw'] * $horas, 2);
            }
        }
    }

    // Tarifas do tenant (C-21): R$/m³ e R$/kWh — custo automático dos consumos.
    $tarifas = [];
    if (function_exists('vero_srv_param')) {
        $tarifas = json_decode((string)(vero_srv_param('irrigacao.tarifas') ?? ''), true) ?: [];
    }
    $consumos = [];
    foreach ([['tipo' => 'agua', 'qtd' => $aguaQtd, 'unidade' => 'm3', 'tarifa' => (float)($tarifas['agua_m3'] ?? 0)],
              ['tipo' => 'energia', 'qtd' => $energiaQtd, 'unidade' => 'kWh', 'tarifa' => (float)($tarifas['energia_kwh'] ?? 0)]] as $c) {
        if ($c['qtd'] !== null && $c['qtd'] > 0) {
            $consumos[] = [
                'tipo'       => $c['tipo'],
                'quantidade' => $c['qtd'],
                'unidade'    => $c['unidade'],
                'custo'      => $c['tarifa'] > 0 ? round($c['qtd'] * $c['tarifa'], 2) : 0.0,
            ];
        }
    }

    api_idempotente($clientUuid, 'irrigacao_apontamento', function () use ($dados, $consumos, $stId, $talhaoId) {
        $id = vero_insert('irrigacao_apontamentos', $dados);

        // Consumos + custeio (espelho do web em irrigacao/apontamentos_irrigacao.php).
        // Serviços de custeio ausentes → grava só os consumos (nunca quebra a fila).
        if ($consumos !== [] && api_tabela_existe('irrigacao_consumos')) {
            $vinculo = $stId !== null
                ? vero_row("SELECT safra_id, cultura_id FROM agro_safra_talhoes WHERE id = :i AND tenant_id = :t",
                    [':i' => $stId, ':t' => vero_tenant()])
                : null;
            $podeCustear = function_exists('vero_srv_custeio_pode_lancar')
                && function_exists('vero_srv_centro_custo')
                && function_exists('custeio_plano_conta_id')
                && ($vinculo === null || (vero_srv_custeio_pode_lancar((int)$vinculo['safra_id'])['pode'] ?? false));
            $centro = $podeCustear ? vero_srv_centro_custo('IRR', 'Irrigação') : null;

            foreach ($consumos as $c) {
                vero_pdo()->prepare(
                    "INSERT INTO irrigacao_consumos (tenant_id, apontamento_id, tipo, quantidade, unidade, custo)
                     VALUES (?,?,?,?,?,?)"
                )->execute([vero_tenant(), $id, $c['tipo'], $c['quantidade'], $c['unidade'], $c['custo']]);
                $consumoId = (int)vero_pdo()->lastInsertId();
                if ($c['custo'] > 0 && $podeCustear) {
                    vero_insert('custeio_lancamentos', [
                        'safra_id'         => $vinculo ? (int)$vinculo['safra_id'] : null,
                        'safra_talhao_id'  => $stId,
                        'talhao_id'        => $talhaoId,
                        'cultura_id'       => $vinculo ? (int)$vinculo['cultura_id'] : null,
                        'centro_custo_id'  => $centro,
                        'plano_conta_id'   => custeio_plano_conta_id('irrigacao_consumo'),
                        'categoria'        => 'irrigacao',
                        'origem_tipo'      => 'irrigacao_consumo',
                        'origem_id'        => $consumoId,
                        'valor'            => $c['custo'],
                        'quantidade'       => $c['quantidade'],
                        'data_competencia' => substr((string)$dados['data_apontamento'], 0, 10),
                    ]);
                }
            }
        }
        return [$id, ['id' => $id, 'consumos' => count($consumos)]];
    });
}

/* ============================================================
   6) POST /atividades/{id}/status
   ============================================================ */
function rota_atividade_status(array $usuario, string $id): never
{
    // PT-02 (CSO): mudar o status de uma atividade é ESCRITA de campo →
    // exige a ação de apontamento (operador tem; consulta = só *.ver → 403).
    api_exigir('agro.apontamentos_campo.editar');

    $id = (int)$id;
    $corpo = api_corpo();
    $statusApp = (string)api_exigir_campo($corpo, 'status');

    // Mapa app → ENUM('planejada','em_execucao','concluida','cancelada').
    // Limitação: o ENUM não tem 'pausada'; pausada/pendente voltam a 'planejada'
    // (o estado fino de pausa vive só no app até o banco ganhar o valor).
    $mapa = [
        'pendente'  => 'planejada',
        'pausada'   => 'planejada',
        'andamento' => 'em_execucao',
        'concluida' => 'concluida',
    ];
    if (!isset($mapa[$statusApp])) {
        api_erro('status_invalido', "Status '{$statusApp}' não é aceito (pendente|andamento|pausada|concluida).", 422);
    }

    // SELECT antes do UPDATE: rowCount 0 também acontece quando o status
    // já era o mesmo — não pode virar falso 404.
    $linha = api_linha_tenant('agro_atividades', $id);
    if ($linha === null) {
        api_erro('nao_encontrado', 'Atividade não encontrada.', 404);
    }

    // Onda 6.2 — detecção de conflito: o app manda o updated_at que CONHECIA
    // (do cache offline). Se o escritório mexeu na tarefa depois disso e o
    // resultado seria DIFERENTE do que já está lá, não sobrescrevemos em
    // silêncio (fim do last-write-wins mudo).
    $conhecido = api_c_str($corpo, 'updated_at_conhecido', 19);
    if ($conhecido !== null
        && (string)$linha['updated_at'] > $conhecido
        && (string)$linha['status'] !== $mapa[$statusApp]) {
        api_erro(
            'conflito',
            'Esta tarefa foi alterada no escritório depois do seu registro (está "' . $linha['status'] . '"). Sincronize e confira antes de mudar.',
            409
        );
    }

    vero_pdo()->prepare(
        'UPDATE agro_atividades SET status = ?, updated_by = ? WHERE id = ? AND tenant_id = ?'
    )->execute([$mapa[$statusApp], vero_uid(), $id, vero_tenant()]);

    api_ok(['id' => $id, 'status' => $mapa[$statusApp]], 'Status atualizado.');
}

/* ============================================================
   7) POST /alertas/{id}/reconhecer
   ============================================================ */
function rota_alerta_reconhecer(array $usuario, string $id): never
{
    api_exigir('mip.alertas_fitossanitarios.editar'); // PT-02: reconhecer = escrita no alerta

    $id = (int)$id;
    $st = vero_pdo()->prepare(
        "UPDATE agro_alertas
            SET status = 'reconhecido', reconhecido_por = ?, reconhecido_em = NOW(), updated_by = ?
          WHERE id = ? AND tenant_id = ? AND status = 'aberto'"
    );
    $st->execute([vero_uid(), vero_uid(), $id, vero_tenant()]);

    if ($st->rowCount() === 0) {
        $linha = api_linha_tenant('agro_alertas', $id);
        if ($linha === null) {
            api_erro('nao_encontrado', 'Alerta não encontrado.', 404);
        }
        api_erro('ja_reconhecido', 'Este alerta já foi tratado (status: ' . $linha['status'] . ').', 409);
    }
    api_ok(['id' => $id], 'Alerta reconhecido.');
}

/* ============================================================
   7b) POST /aplicacoes — EMITIR a OS de aplicação (DF/IF) pelo app (F1)
   Espelho do modo 'emitir' de mip/aplicacoes.php: cabeçalho 'planejada'
   numerado (P-46) + itens com dose/carência do Auto de Controle
   (mip_alvo_produtos). SEM efeitos de estoque/custeio — a baixa
   acontece na CONFIRMAÇÃO (rota 8, já existente).
   ============================================================ */
function rota_aplicacao_emitir(array $usuario): never
{
    api_exigir('mip.aplicacoes_defensivos.editar'); // mesmo guard da tela web (acao=salvar/emitir)

    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');

    $talhaoId = api_c_int($corpo, 'talhao_id');
    if ($talhaoId === null) {
        api_erro('campo_obrigatorio', "Informe o campo 'talhao_id'.", 422);
    }
    $talhaoRow = api_linha_tenant('agro_talhoes', $talhaoId);
    if ($talhaoRow === null) {
        api_erro('talhao_invalido', 'Válvula não encontrada.', 422);
    }

    $alvoId = api_c_int($corpo, 'alvo_id');
    $alvoRow = null;
    if ($alvoId !== null) {
        $alvoRow = api_linha_tenant('mip_alvos', $alvoId);
        if ($alvoRow === null) {
            api_erro('alvo_invalido', 'Alvo não encontrado.', 422);
        }
    }

    $tipo = api_c_str($corpo, 'tipo', 30) ?? 'pulverizacao';
    if (!in_array($tipo, ['pulverizacao', 'fertirrigacao', 'indutor_brotacao', 'foliar', 'tratamento', 'outro'], true)) {
        $tipo = 'pulverizacao';
    }

    $brutos = is_array($corpo['itens'] ?? null) ? $corpo['itens'] : [];
    if ($brutos === []) {
        api_erro('campo_obrigatorio', 'Informe ao menos um item (produto) da aplicação.', 422);
    }

    // safra_id é NOT NULL no cabeçalho — resolve pela safra em andamento da válvula
    $stId = api_safra_talhao_atual($talhaoId);
    $safraId = null;
    if ($stId !== null) {
        $r = vero_val(
            "SELECT safra_id FROM agro_safra_talhoes WHERE id = :i AND tenant_id = :t",
            [':i' => $stId, ':t' => vero_tenant()]
        );
        $safraId = ((int)$r) > 0 ? (int)$r : null;
    }
    if ($safraId === null) {
        api_erro('sem_safra', 'Não há safra em andamento para esta válvula — abra a safra no sistema antes de emitir a OS.', 422);
    }
    // A3-T6 (P-06): safra fechada p/ custeio não emite documento novo (igual web)
    if (function_exists('vero_srv_custeio_pode_lancar')) {
        $g = vero_srv_custeio_pode_lancar($safraId);
        if (!($g['pode'] ?? true)) {
            api_erro('safra_fechada', (string)($g['motivo'] ?? 'Custeio fechado para esta safra.'), 422);
        }
    }

    // C-11: emissão só tem Data PREVISTA (a realizada nasce na confirmação)
    $dataPrevista = substr((string)api_c_datahora($corpo, 'data_prevista', date('Y-m-d H:i:s')), 0, 10);

    // fenologia POR VARIEDADE pela data prevista (mesmo resolver do web)
    $faseId = null;
    $diasPoda = null;
    if ($stId !== null && api_fenologia_helper()) {
        $fase = vero_a1_fenologia_variedade_resolver($stId, $safraId, $dataPrevista);
        if ($fase !== null) {
            $faseId = (int)$fase['id'];
            $diasPoda = (int)$fase['dias'];
        }
    }

    // A1-30: justificativa MIP automática — ÚLTIMO monitoramento da válvula até a data
    $monRefId = vero_val(
        "SELECT id FROM mip_monitoramentos
          WHERE tenant_id = :t AND talhao_id = :ta AND data_monitoramento <= :d
          ORDER BY data_monitoramento DESC, id DESC LIMIT 1",
        [':t' => vero_tenant(), ':ta' => $talhaoId, ':d' => $dataPrevista]
    );
    $monRefId = ((int)$monRefId) > 0 ? (int)$monRefId : null;

    // item 6.5 (mig 162): múltiplas máquinas — 1ª vai ao cabeçalho (compat)
    $maquinaIds = [];
    foreach ((array)($corpo['maquinas'] ?? []) as $mid) {
        $mid = (int)$mid;
        if ($mid <= 0 || in_array($mid, $maquinaIds, true)) {
            continue;
        }
        if (api_linha_tenant('maquinas', $mid) === null) {
            api_erro('maquina_invalida', "Máquina {$mid} não encontrada.", 422);
        }
        $maquinaIds[] = $mid;
    }

    // Itens: dose do corpo OU do Auto de Controle (mip_alvo_produtos, mig 146);
    // carência/bula copiadas do cadastro do produto (imutabilidade documental).
    $temCadastroAlvo = $alvoId !== null && vero_has_column('mip_alvo_produtos', 'alvo_id');
    $volumeHa = api_c_dec($corpo, 'volume_calda_ha_l');
    $temNutri = api_tabela_existe('estoque_produto_nutrientes');
    $itens = [];
    foreach ($brutos as $b) {
        if (!is_array($b)) {
            continue;
        }
        $prodId = api_c_int($b, 'produto_id');
        if ($prodId === null) {
            api_erro('campo_obrigatorio', "Cada item precisa de 'produto_id'.", 422);
        }
        $prod = api_linha_tenant('estoque_produtos', $prodId);
        if ($prod === null) {
            api_erro('produto_invalido', "Produto {$prodId} não encontrado.", 422);
        }
        $dose = api_c_dec($b, 'dose');
        $doseUn = api_c_str($b, 'dose_unidade', 20);
        if (($dose === null || $doseUn === null || $volumeHa === null) && $temCadastroAlvo) {
            $cad = vero_row(
                "SELECT dose, dose_unidade, volume_calda_ha FROM mip_alvo_produtos
                  WHERE tenant_id = :t AND alvo_id = :a AND produto_id = :p AND ativo = 1
                  ORDER BY id LIMIT 1",
                [':t' => vero_tenant(), ':a' => $alvoId, ':p' => $prodId]
            );
            if ($cad) {
                $dose   = $dose ?? ($cad['dose'] !== null ? (float)$cad['dose'] : null);
                $doseUn = $doseUn ?? ($cad['dose_unidade'] !== null ? (string)$cad['dose_unidade'] : null);
                if ($volumeHa === null && $cad['volume_calda_ha'] !== null) {
                    $volumeHa = (float)$cad['volume_calda_ha'];
                }
            }
        }
        // snapshot de bula (DB-30) — colunas guardadas (banco defasado não quebra)
        $nutriJson = null;
        if ($temNutri) {
            $nutrientes = vero_rows(
                "SELECT nutriente, percentual FROM estoque_produto_nutrientes
                  WHERE tenant_id = :t AND produto_id = :p ORDER BY nutriente",
                [':t' => vero_tenant(), ':p' => $prodId]
            );
            $nutriJson = $nutrientes
                ? json_encode(array_column($nutrientes, 'percentual', 'nutriente'), JSON_UNESCAPED_UNICODE)
                : null;
        }
        $itens[] = [
            'produto_id'           => $prodId,
            'dose_valor'           => $dose,
            'dose_unidade'         => $doseUn,
            // quantidade PREVISTA opcional (o app pode mandar; a consumida real
            // nasce na confirmação/validação — sem efeito de estoque aqui)
            'quantidade_consumida' => api_c_dec($b, 'quantidade'),
            'quantidade_unidade'   => $prod['unidade'] ?? null,
            'carencia_dias'        => array_key_exists('carencia_dias', $prod) && $prod['carencia_dias'] !== null
                ? (int)$prod['carencia_dias'] : null,
            'intervalo_aplicacoes_dias' => array_key_exists('intervalo_aplicacoes_dias', $prod) && $prod['intervalo_aplicacoes_dias'] !== null
                ? (int)$prod['intervalo_aplicacoes_dias'] : null,
            'num_max_aplicacoes'   => array_key_exists('num_max_aplicacoes', $prod) && $prod['num_max_aplicacoes'] !== null
                ? (int)$prod['num_max_aplicacoes'] : null,
            'lmr_dias'             => array_key_exists('lmr_dias', $prod) && $prod['lmr_dias'] !== null
                ? (int)$prod['lmr_dias'] : null,
            'nutrientes_snapshot'  => $nutriJson,
        ];
    }
    if ($itens === []) {
        api_erro('campo_obrigatorio', 'Informe ao menos um item (produto) da aplicação.', 422);
    }

    // alvo no documento: coluna dedicada só se existir; sempre fica na observação
    $obs = api_c_str($corpo, 'observacao', 255);
    $nota = '[app] OS emitida pelo campo' . ($alvoRow !== null ? ' — alvo: ' . $alvoRow['nome'] : '');
    $obs = $obs !== null ? mb_substr($obs . ' — ' . $nota, 0, 255) : $nota;

    $dados = [
        'tipo'          => $tipo,
        'fazenda_id'    => (int)$talhaoRow['fazenda_id'],
        'talhao_id'     => $talhaoId,
        'safra_id'      => $safraId,
        'variedade_id'  => $talhaoRow['variedade_id'] !== null ? (int)$talhaoRow['variedade_id'] : null,
        'variedade_fase_id' => $faseId,
        'dias_desde_poda'   => $diasPoda,
        'data_prevista' => $dataPrevista,
        'maquina_id'    => $maquinaIds[0] ?? null,
        'area_aplicada_ha'  => api_c_dec($corpo, 'area_aplicada_ha'),
        'volume_calda_ha_l' => $volumeHa,
        'monitoramento_id'  => $monRefId,
        'observacao'    => $obs,
        'status'        => 'planejada', // OS emitida — aguarda execução
    ];
    /* C-11/mig 177: realizada é NULL na emissão; banco pré-177 (coluna NOT NULL)
       recebe a prevista como placeholder — MESMA regra do web. */
    $dados['data'] = (string)vero_val(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_aplicacoes' AND COLUMN_NAME = 'data'"
    ) === 'YES' ? null : $dataPrevista;
    if ($alvoId !== null && vero_has_column('agro_aplicacoes', 'alvo_id')) {
        $dados['alvo_id'] = $alvoId; // hoje a coluna não existe — guard p/ schema futuro
    }

    api_idempotente($clientUuid, 'aplicacao', function () use ($dados, $itens, $maquinaIds) {
        // numeração DF/IF por fazenda NA EMISSÃO (P-46, lock atômico) — na transação
        if (function_exists('vero_srv_doc_serie_por_tipo') && function_exists('vero_srv_doc_numero')) {
            $serie = vero_srv_doc_serie_por_tipo((string)$dados['tipo']);
            $dados['doc_serie']  = $serie;
            $dados['doc_numero'] = vero_srv_doc_numero((int)$dados['fazenda_id'], $serie);
        }
        $id = vero_insert('agro_aplicacoes', $dados);

        if ($maquinaIds !== [] && api_tabela_existe('agro_aplicacao_maquinas')) {
            foreach ($maquinaIds as $mid) {
                vero_insert('agro_aplicacao_maquinas', ['aplicacao_id' => $id, 'maquina_id' => $mid]);
            }
        }
        foreach ($itens as $item) {
            $item['aplicacao_id'] = $id;
            vero_insert('agro_aplicacao_itens', $item);
        }
        $doc = isset($dados['doc_serie']) ? $dados['doc_serie'] . $dados['doc_numero'] : '#' . $id;
        return [$id, ['id' => $id, 'doc' => $doc, 'status' => 'planejada', 'itens' => count($itens)]];
    });
}

/* Lacuna 2 (gestor 23/07): DF emitida NO CAMPO ainda não tem id do servidor.
   Resolve o id da aplicação pela client_uuid da EMISSÃO (mapa em
   app_idempotencia). A fila envia a emissão ANTES (pai_uuid), então quando a
   confirmação/assinatura chega o id já existe; se ainda não (raro), 409 não é
   rejeição definitiva — a fila retenta. */
function api_aplicacao_id_por_emissao(string $uuid): int
{
    // a emissão grava recurso_tipo = 'aplicacao' (api_idempotente do emitir)
    $rid = (int)vero_val(
        "SELECT recurso_id FROM app_idempotencia
          WHERE tenant_id = :t AND client_uuid = :u AND recurso_tipo = 'aplicacao'
          ORDER BY id DESC LIMIT 1",
        [':t' => vero_tenant(), ':u' => $uuid]
    );
    if ($rid <= 0) {
        api_erro('emissao_pendente', 'A emissão desta aplicação ainda não subiu ao servidor — sincronize e tente de novo.', 409);
    }
    return $rid;
}

/* POST /aplicacoes/confirmar — confirma a DF emitida no campo (resolve por
   emissao_uuid e reusa a mesma lógica de /aplicacoes/{id}/confirmar). */
function rota_aplicacao_confirmar_campo(array $usuario): never
{
    $uuid = (string)api_exigir_campo(api_corpo(), 'emissao_uuid');
    rota_aplicacao_confirmar($usuario, (string)api_aplicacao_id_por_emissao($uuid));
}

/* POST /aplicacoes/assinar — assina a DF emitida no campo (idem). */
function rota_aplicacao_assinar_campo(array $usuario): never
{
    $uuid = (string)api_exigir_campo(api_corpo(), 'emissao_uuid');
    rota_aplicacao_assinar($usuario, (string)api_aplicacao_id_por_emissao($uuid));
}

/* ============================================================
   8) POST /aplicacoes/{id}/confirmar — operador confirma execução
   ============================================================ */
function rota_aplicacao_confirmar(array $usuario, string $id): never
{
    // PT-02 (CSO): confirmar a DF dispara FEFO+custeio+RT → escrita crítica.
    api_exigir('mip.aplicacoes_defensivos.editar');

    $id = (int)$id;
    $corpo = api_corpo();

    // FORMULÁRIO COMPLETO (gestor 23/07 — espelho de mip/aplicacoes.php acao
    // 'confirmar'): a confirmação faz a BAIXA FEFO pelas quantidades REAIS +
    // custeio, exige >=1 operador/EPI (certificação) e grava clima/certificação.
    // DF precisa estar EMITIDA (planejada): confirmar baixa o estoque UMA vez.
    $ap = api_linha_tenant('agro_aplicacoes', $id);
    if ($ap === null) {
        api_erro('nao_encontrado', 'Aplicação não encontrada.', 404);
    }
    if ((string)$ap['status'] !== 'planejada') {
        api_erro('status_invalido', 'Só aplicações EMITIDAS podem ser confirmadas (status: ' . $ap['status'] . ').', 409);
    }
    $safraId = $ap['safra_id'] !== null ? (int)$ap['safra_id'] : null;
    if ($safraId !== null && function_exists('vero_srv_custeio_pode_lancar')) {
        $g = vero_srv_custeio_pode_lancar($safraId);
        if (!($g['pode'] ?? true)) {
            api_erro('safra_fechada', (string)($g['motivo'] ?? 'Custeio fechado para esta safra.'), 422);
        }
    }

    // data real + horas de execução
    $dataReal = substr((string)api_c_datahora($corpo, 'data_execucao', date('Y-m-d')), 0, 10);
    $hIni = api_c_str($corpo, 'hora_inicio', 5);
    $hFim = api_c_str($corpo, 'hora_fim', 5);
    $horaIni = ($hIni !== null && preg_match('/^\d{2}:\d{2}$/', $hIni)) ? $dataReal . ' ' . $hIni . ':00' : null;
    $horaFim = ($hFim !== null && preg_match('/^\d{2}:\d{2}$/', $hFim)) ? $dataReal . ' ' . $hFim . ':00' : null;
    if ($horaIni !== null && $horaFim !== null && $horaFim < $horaIni) {
        api_erro('hora_invalida', 'Hora de término anterior à de início.', 422);
    }
    $ceu = api_c_str($corpo, 'ceu', 10);
    if ($ceu !== null && !in_array($ceu, ['noite', 'sol', 'nublado', 'chuva'], true)) $ceu = null;
    $ventoCl = api_c_str($corpo, 'vento_class', 10);
    if ($ventoCl !== null && !in_array($ventoCl, ['brisa', 'moderado', 'forte'], true)) $ventoCl = null;

    // itens do DF (a baixa é por eles)
    $itens = vero_rows(
        "SELECT * FROM agro_aplicacao_itens WHERE tenant_id = :t AND aplicacao_id = :a ORDER BY id",
        [':t' => vero_tenant(), ':a' => $id]
    );
    if ($itens === []) {
        api_erro('sem_itens', 'Documento sem produtos — não há o que confirmar.', 422);
    }
    // quantidades REAIS por produto (app envia produto_id -> quantidade_real)
    $reaisPorProd = [];
    if (is_array($corpo['itens_reais'] ?? null)) {
        foreach ($corpo['itens_reais'] as $ir) {
            if (!is_array($ir)) continue;
            $pid = api_c_int($ir, 'produto_id');
            $q = api_c_dec($ir, 'quantidade_real');
            if ($pid !== null && $q !== null && !isset($reaisPorProd[$pid])) $reaisPorProd[$pid] = $q;
        }
    }

    // operadores/EPI — MÍNIMO 1 (exigência de certificação)
    $ops = [];
    if (is_array($corpo['operadores'] ?? null)) {
        foreach ($corpo['operadores'] as $o) {
            if (!is_array($o)) continue;
            $oid = api_c_int($o, 'operador_id');
            if ($oid === null || isset($ops[$oid])) continue;
            if (api_linha_tenant('agro_operadores', $oid) === null) continue;
            $lav = $o['epi_lavagem'] ?? null;
            $ops[$oid] = [
                'aplicacao_id' => $id, 'operador_id' => $oid,
                'epi_codigo'   => api_c_str($o, 'epi_codigo', 40),
                'epi_lavagem'  => ($lav === null || $lav === '') ? null : (!empty($lav) ? 1 : 0),
                'epi_condicao' => api_c_str($o, 'epi_condicao', 60),
            ];
        }
    }
    if ($ops === []) {
        api_erro('sem_operador', 'A confirmação exige pelo menos 1 operador identificado (exigência de certificação — bloco Operadores/EPI).', 422);
    }

    // safra_talhao do DF (para a baixa e o custeio) — mesma resolução do web
    $stId = $safraId !== null ? (int)(vero_val(
        "SELECT id FROM agro_safra_talhoes WHERE tenant_id = :t AND safra_id = :s AND talhao_id = :ta",
        [':t' => vero_tenant(), ':s' => $safraId, ':ta' => (int)$ap['talhao_id']]
    ) ?: 0) : 0;
    $stId = $stId > 0 ? $stId : null;

    $pdo = vero_pdo();
    $pdo->beginTransaction();
    try {
        // operadores da execução (replace)
        $pdo->prepare("DELETE FROM agro_aplicacao_operadores WHERE tenant_id = ? AND aplicacao_id = ?")
            ->execute([vero_tenant(), $id]);
        foreach ($ops as $od) vero_insert('agro_aplicacao_operadores', $od);

        // baixa FEFO por quantidade REAL + custeio. TOLERANTE (gestor 23/07):
        // no campo a aplicação JÁ ocorreu — se o estoque do sistema está
        // insuficiente, NÃO trava a confirmação: registra a quantidade real,
        // DIFERE a baixa do produto e sinaliza o escritório p/ registrar a
        // entrada e reconciliar. Nunca deixa saldo negativo (trava A12).
        $almox = vero_srv_almox_padrao();
        $custoTotal = 0.0;
        $semEstoque = [];
        foreach ($itens as $item) {
            $pid = (int)$item['produto_id'];
            $qtdReal = $reaisPorProd[$pid] ?? (float)$item['quantidade_consumida'];
            if ($qtdReal <= 0) {
                throw new RuntimeException('Quantidade real inválida em um dos produtos.');
            }
            $saldo = (float)(vero_srv_estoque_saldo($pid, $almox)['quantidade'] ?? 0);
            $custoU = 0.0;
            $custoT = 0.0;
            if ($saldo >= $qtdReal) {
                $saida = vero_srv_estoque_saida($pid, $almox, $qtdReal, $dataReal,
                    'aplicacao', $id, 'Aplicação ' . (string)$ap['tipo'], $stId);
                $custoU = (float)$saida['custo_unitario'];
                $custoT = (float)$saida['custo_total'];
            } else {
                $semEstoque[] = (string)(vero_val(
                    "SELECT nome FROM estoque_produtos WHERE id = :i AND tenant_id = :t",
                    [':i' => $pid, ':t' => vero_tenant()]) ?? ('produto #' . $pid));
            }
            vero_update('agro_aplicacao_itens', (int)$item['id'], [
                'quantidade_consumida' => $qtdReal,
                'custo_unitario'       => $custoU,
                'custo_total'          => $custoT,
            ]);
            $custoTotal += $custoT;
        }
        if ($custoTotal > 0) {
            vero_insert('custeio_lancamentos', [
                'safra_id'         => $safraId,
                'safra_talhao_id'  => $stId,
                'talhao_id'        => (int)$ap['talhao_id'],
                'centro_custo_id'  => vero_srv_centro_custo('INS', 'Insumos'),
                'plano_conta_id'   => custeio_plano_conta_id('aplicacao', (string)$ap['tipo']),
                'categoria'        => 'insumos',
                'origem_tipo'      => 'aplicacao',
                'origem_id'        => $id,
                'valor'            => round($custoTotal, 2),
                'data_competencia' => $dataReal,
            ]);
        }

        // confirmação (JSON) + certificação
        $conf = [
            'vento_kmh_real'      => api_c_dec($corpo, 'vento_kmh'),
            'pluviosidade_mm'     => api_c_dec($corpo, 'pluviosidade_mm'),
            'ceu'                 => $ceu,
            'vento_class'         => $ventoCl,
            'destino_sobra_calda' => api_c_str($corpo, 'destino_sobra', 160),
            'obs'                 => api_c_str($corpo, 'observacao', 255),
            'fonte'               => 'app',
        ];
        $dados = [
            'data'             => $dataReal,
            'executada_inicio' => $horaIni ?? $ap['executada_inicio'],
            'executada_fim'    => $horaFim ?? $ap['executada_fim'],
            'confirmacao'      => json_encode($conf, JSON_UNESCAPED_UNICODE),
            'triplice_lavagem' => !empty($corpo['triplice_lavagem']) ? 1 : 0,
            'custo_total'      => round($custoTotal, 2),
            'estoque_baixado'  => $semEstoque === [] ? 1 : 0, // 0 = baixa pendente
            'custeio_lancado'  => $custoTotal > 0 ? 1 : 0,
            'status'           => 'registrada',
        ];
        if ($ceu !== null && vero_has_column('agro_aplicacoes', 'condicao_ceu')) {
            $dados['condicao_ceu'] = $ceu;
        }
        vero_update('agro_aplicacoes', $id, $dados);

        // estoque insuficiente → alerta o escritório p/ registrar a entrada e
        // reprocessar a baixa (a execução já ficou registrada)
        if ($semEstoque !== []) {
            vero_insert('agro_alertas', [
                'categoria'   => 'estoque',
                'origem_tipo' => 'aplicacao_estoque_pendente',
                'origem_id'   => $id,
                'fazenda_id'  => $ap['fazenda_id'] !== null ? (int)$ap['fazenda_id'] : null,
                'talhao_id'   => (int)$ap['talhao_id'],
                'severidade'  => 'atencao',
                'titulo'      => 'Baixa de estoque pendente — aplicação #' . $id,
                'mensagem'    => 'Confirmada no campo, mas SEM saldo no sistema para: '
                                . implode(', ', $semEstoque)
                                . '. Registre a entrada e reprocesse a baixa em Aplicações.',
                'status'      => 'aberto',
                'data'        => date('Y-m-d'),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        api_erro('confirmar_falhou', $e->getMessage(), 422);
    }

    // 8.4: alerta de CARÊNCIA/REENTRADA da aplicação confirmada — a janela de
    // colheita/entrada na área vira alerta visível (e push) em vez de memória.
    $carencia = 0;
    $reentrada = 0;
    if (vero_has_column('agro_aplicacao_itens', 'carencia_dias')) {
        $r = vero_row(
            "SELECT MAX(COALESCE(carencia_dias,0)) AS car,
                    MAX(COALESCE(intervalo_reentrada_horas,0)) AS re
               FROM agro_aplicacao_itens WHERE tenant_id = :t AND aplicacao_id = :a",
            [':t' => vero_tenant(), ':a' => $id]
        );
        $carencia = (int)($r['car'] ?? 0);
        $reentrada = (int)($r['re'] ?? 0);
    }
    if ($carencia > 0 || $reentrada > 0) {
        $jaTem = vero_val(
            "SELECT id FROM agro_alertas
              WHERE tenant_id = :t AND origem_tipo = 'aplicacao_carencia'
                AND origem_id = :o AND status = 'aberto' LIMIT 1",
            [':t' => vero_tenant(), ':o' => $id]
        );
        if (!$jaTem) {
            $apl = api_linha_tenant('agro_aplicacoes', $id);
            $fimCarencia = $carencia > 0 ? date('d/m/Y', strtotime("+{$carencia} days")) : null;
            $msg = array_filter([
                $carencia > 0 ? "Não colher antes de {$fimCarencia} (carência de {$carencia} dias)." : null,
                $reentrada > 0 ? "Reentrada na área liberada após {$reentrada}h da aplicação." : null,
            ]);
            vero_insert('agro_alertas', [
                'categoria'   => 'mip',
                'origem_tipo' => 'aplicacao_carencia',
                'origem_id'   => $id,
                'fazenda_id'  => $apl['fazenda_id'] !== null ? (int)$apl['fazenda_id'] : null,
                'talhao_id'   => $apl['talhao_id'] !== null ? (int)$apl['talhao_id'] : null,
                'severidade'  => 'atencao',
                'titulo'      => $carencia > 0
                    ? "Carência até {$fimCarencia} — aplicação #{$id}"
                    : "Reentrada {$reentrada}h — aplicação #{$id}",
                'mensagem'    => implode(' ', $msg),
                'status'      => 'aberto',
                'data'        => date('Y-m-d'),
            ]);
            require_once __DIR__ . '/../nucleo/push.php';
            push_notificar_tenant(
                'Janela de carência ativa',
                $carencia > 0
                    ? "Aplicação confirmada — não colher antes de {$fimCarencia}."
                    : "Aplicação confirmada — reentrada após {$reentrada}h.",
                ['tela' => 'Avisos']
            );
        }
    }

    $msgOk = ($semEstoque ?? []) === []
        ? 'Aplicação confirmada — estoque baixado por FEFO pelas quantidades reais.'
        : 'Aplicação confirmada. A baixa de estoque ficou PENDENTE (sem saldo: ' . implode(', ', $semEstoque) . ') — o escritório registra a entrada e reprocessa.';
    api_ok(['id' => $id, 'status' => 'registrada', 'estoque_pendente' => ($semEstoque ?? []) !== []], $msgOk);
}

/* ============================================================
   9) POST /aplicacoes/{id}/assinar — assinatura do operador
      (GlobalG.A.P.: trilha de quem executou)
   ============================================================ */
function rota_aplicacao_assinar(array $usuario, string $id): never
{
    api_exigir('mip.aplicacoes_defensivos.editar'); // PT-02: assinatura = escrita

    $id = (int)$id;
    $corpo = api_corpo();
    $operadorNome = mb_substr((string)api_exigir_campo($corpo, 'operador_nome'), 0, 150);
    $assinatura = (string)api_exigir_campo($corpo, 'assinatura_svg');

    if (strlen($assinatura) >= 500 * 1024) { // bytes; MEDIUMTEXT aguenta, o app não precisa de mais
        api_erro('assinatura_grande', 'Assinatura excede o limite de 500 KB.', 422);
    }
    if (api_linha_tenant('agro_aplicacoes', $id) === null) {
        api_erro('nao_encontrado', 'Aplicação não encontrada.', 404);
    }

    // Papel (23/07): operador (executou) | rt (receituário agronômico).
    // Coluna aditiva — só entra no INSERT se o banco já migrou.
    $papel = api_c_str($corpo, 'papel', 20) === 'rt' ? 'rt' : 'operador';
    $temPapel = vero_has_column('agro_aplicacao_assinaturas', 'papel');

    // agro_aplicacao_assinaturas não tem updated_by → INSERT direto (vero_insert não serve).
    $pdo = vero_pdo();
    if ($temPapel) {
        $pdo->prepare(
            'INSERT INTO agro_aplicacao_assinaturas
                    (tenant_id, aplicacao_id, operador_id, operador_nome, papel, assinatura_svg, assinado_em, created_by)
             VALUES (?,?,?,?,?,?,NOW(),?)'
        )->execute([
            vero_tenant(), $id, api_c_int($corpo, 'operador_id'),
            $operadorNome, $papel, $assinatura, vero_uid(),
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO agro_aplicacao_assinaturas
                    (tenant_id, aplicacao_id, operador_id, operador_nome, assinatura_svg, assinado_em, created_by)
             VALUES (?,?,?,?,?,NOW(),?)'
        )->execute([
            vero_tenant(), $id, api_c_int($corpo, 'operador_id'),
            $operadorNome, $assinatura, vero_uid(),
        ]);
    }
    $novoId = (int)$pdo->lastInsertId();

    // toca o updated_at do DF: o status de assinatura entra no delta de
    // /sync/aplicacoes (senão a caixa do RT não sabe que já assinaram).
    $pdo->prepare('UPDATE agro_aplicacoes SET updated_at = NOW() WHERE tenant_id = ? AND id = ?')
        ->execute([vero_tenant(), $id]);

    api_ok(['id' => $novoId, 'papel' => $papel], 'Assinatura registrada.', 201);
}

/* ============================================================
   10) POST /maquinas/{id}/horimetro
   ============================================================ */
function rota_maquina_horimetro(array $usuario, string $maquinaId): never
{
    // PT-02 (CSO): registrar leitura de horímetro é ESCRITA na máquina.
    api_exigir('maquinas.horimetro.editar');

    $maquinaId = (int)$maquinaId;
    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');

    $horimetro = api_c_dec($corpo, 'horimetro');
    if ($horimetro === null || $horimetro <= 0) {
        api_erro('horimetro_invalido', 'Informe um horímetro numérico maior que zero.', 422);
    }

    $maquina = api_linha_tenant('maquinas', $maquinaId);
    if ($maquina === null) {
        api_erro('nao_encontrado', 'Máquina não encontrada.', 404);
    }
    $atual = (float)$maquina['horimetro_atual'];
    if ($horimetro < $atual) {
        api_erro('leitura_menor', 'Leitura menor que a atual (' . number_format($atual, 2, ',', '.') . ').', 422);
    }

    $dataLeitura = substr((string)api_c_datahora($corpo, 'data', date('Y-m-d H:i:s')), 0, 10); // coluna é DATE

    api_idempotente($clientUuid, 'horimetro', function () use ($maquinaId, $horimetro, $dataLeitura) {
        $id = vero_insert('maquina_horimetros', [
            'maquina_id'  => $maquinaId,
            'data_leitura' => $dataLeitura,
            'horimetro'   => $horimetro,
        ]);
        vero_pdo()->prepare(
            'UPDATE maquinas SET horimetro_atual = ?, updated_by = ? WHERE id = ? AND tenant_id = ?'
        )->execute([$horimetro, vero_uid(), $maquinaId, vero_tenant()]);
        return [$id, ['id' => $id, 'horimetro_atual' => $horimetro]];
    });
}

/* ============================================================
   10b) POST /maquinas/{id}/abastecimento — combustível do campo
   (mig 149; P-122: sem estoque de diesel — litros aqui, valor
   fica p/ o escritório completar no web)
   ============================================================ */
function rota_maquina_abastecimento(array $usuario, string $maquinaId): never
{
    api_exigir('maquinas.horimetro.editar'); // PT-02: escrita na máquina (mesmo slug de ação)

    $maquinaId = (int)$maquinaId;
    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');

    $litros = api_c_dec($corpo, 'litros');
    if ($litros === null || $litros <= 0) {
        api_erro('litros_invalido', 'Informe os litros abastecidos (maior que zero).', 422);
    }

    $maquina = api_linha_tenant('maquinas', $maquinaId);
    if ($maquina === null) {
        api_erro('nao_encontrado', 'Máquina não encontrada.', 404);
    }

    // horímetro no abastecimento é opcional; se vier maior que o atual,
    // também vira leitura oficial (mesma regra da rota de horímetro)
    $horimetro = api_c_dec($corpo, 'horimetro');
    $atual = (float)$maquina['horimetro_atual'];
    if ($horimetro !== null && $horimetro < $atual) {
        api_erro('leitura_menor', 'Horímetro menor que o atual (' . number_format($atual, 2, ',', '.') . ').', 422);
    }

    // operador = usuário logado, quando tem cadastro de operador vinculado
    $operadorId = null;
    if (vero_has_column('agro_operadores', 'usuario_id')) {
        $r = vero_val(
            "SELECT id FROM agro_operadores
              WHERE tenant_id = :t AND usuario_id = :u AND ativo = 1 ORDER BY id LIMIT 1",
            [':t' => vero_tenant(), ':u' => vero_uid()]
        );
        // vero_val devolve FALSE sem linha — (int)false seria 0 e violaria a FK
        $operadorId = ((int)$r) > 0 ? (int)$r : null;
    }

    $data = api_c_datahora($corpo, 'data', date('Y-m-d H:i:s'));

    api_idempotente($clientUuid, 'abastecimento', function () use ($maquinaId, $litros, $horimetro, $operadorId, $data) {
        $id = vero_insert('maquina_abastecimentos', [
            'maquina_id'         => $maquinaId,
            'litros'             => $litros,
            'horimetro'          => $horimetro,
            'operador_id'        => $operadorId,
            'data_abastecimento' => $data,
        ]);
        if ($horimetro !== null) {
            vero_insert('maquina_horimetros', [
                'maquina_id'   => $maquinaId,
                'data_leitura' => substr((string)$data, 0, 10),
                'horimetro'    => $horimetro,
            ]);
            vero_pdo()->prepare(
                'UPDATE maquinas SET horimetro_atual = ?, updated_by = ? WHERE id = ? AND tenant_id = ?'
            )->execute([$horimetro, vero_uid(), $maquinaId, vero_tenant()]);
        }
        return [$id, ['id' => $id]];
    });
}

/* ============================================================
   10c) POST /push/registrar — token Expo do aparelho (Onda 7.5)
   ============================================================ */
function rota_push_registrar(array $usuario): never
{
    require_once __DIR__ . '/../nucleo/push.php';

    $corpo = api_corpo();
    $token = trim((string)api_exigir_campo($corpo, 'expo_token'));
    if ($token === '' || mb_strlen($token) > 255 || !str_starts_with($token, 'Expo')) {
        api_erro('token_invalido', 'Token Expo Push inválido.', 422);
    }
    push_registrar_token((int)$usuario['id'], $token, api_c_str($corpo, 'plataforma', 20));
    api_ok(null, 'Aparelho registrado para notificações.');
}

/* ============================================================
   11) POST /anexos — upload multipart (foto/PDF do campo)
   ============================================================ */
function rota_anexo_criar(array $usuario): never
{
    api_exigir('agro.apontamentos_campo.editar'); // PT-02: upload de anexo = escrita de campo

    $arq = $_FILES['arquivo'] ?? ($_FILES['foto'] ?? null);
    if (!is_array($arq) || (int)($arq['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        api_erro('arquivo_ausente', 'Envie o arquivo no campo multipart "arquivo" (ou "foto").', 422);
    }

    $origemTipo = mb_substr(trim((string)($_POST['origem_tipo'] ?? '')), 0, 40);
    $origemId = (int)($_POST['origem_id'] ?? 0);
    // Offline-first: o app não conhece o id do servidor — aceita o client_uuid
    // do registro-pai já enviado (a fila processa o pai antes da foto).
    // Onda 7.2: além do apontamento, foto em monitoramento/horímetro/abastecimento.
    if ($origemId <= 0 && ($uuid = trim((string)($_POST['origem_uuid'] ?? ''))) !== '') {
        $mapaUuid = [
            'apontamento'   => 'apontamento',
            'monitoramento' => 'monitoramento',
            'horimetro'     => 'horimetro',
            'abastecimento' => 'abastecimento',
            'irrigacao'     => 'irrigacao_apontamento',
        ];
        $tipoPai = $origemTipo !== '' ? $origemTipo : 'apontamento';
        if (!isset($mapaUuid[$tipoPai])) {
            api_erro('origem_invalida', 'origem_tipo não aceita foto por uuid.', 422);
        }
        $origemId = (int)(api_recurso_por_uuid($uuid, $mapaUuid[$tipoPai]) ?? 0);
        $origemTipo = $tipoPai;
        if ($origemId <= 0) {
            api_erro('origem_pendente', 'Registro da foto ainda não sincronizado — reenvie depois.', 409);
        }
    }
    if ($origemTipo === '' || $origemId <= 0) {
        api_erro('campo_obrigatorio', 'Informe origem_tipo e origem_id (ou origem_uuid).', 422);
    }
    // A-5 (F-TEN-4): quando o app manda origem_id DIRETO (fora do fluxo uuid,
    // que já é tenant-safe), valida que o registro-pai é deste tenant — evita
    // anexar foto ao id sequencial de outro tenant. Tipos não mapeados (horímetro/
    // abastecimento) seguem apenas pelo fluxo uuid, então não precisam de guarda aqui.
    $anexoOrigemTabela = [
        'apontamento'           => 'agro_apontamentos',
        'monitoramento'         => 'mip_monitoramentos',
        'mip_monitoramento'     => 'mip_monitoramentos',
        'irrigacao'             => 'irrigacao_apontamentos',
        'irrigacao_apontamento' => 'irrigacao_apontamentos',
        'comercial_venda'       => 'comercial_vendas',
    ];
    if (isset($anexoOrigemTabela[$origemTipo])
        && api_linha_tenant($anexoOrigemTabela[$origemTipo], $origemId) === null) {
        api_erro('origem_invalida', 'origem_id não pertence a este tenant.', 403);
    }
    $clientUuid = trim((string)($_POST['client_uuid'] ?? ''));
    $descricao = mb_substr(trim((string)($_POST['descricao'] ?? '')), 0, 255) ?: null;

    $tamanho = (int)$arq['size'];
    if ($tamanho <= 0 || $tamanho > 8 * 1024 * 1024) {
        api_erro('arquivo_grande', 'O arquivo deve ter no máximo 8 MB.', 422);
    }

    $nomeOriginal = mb_substr((string)$arq['name'], 0, 255);
    $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    $extsValidas = ['jpg', 'jpeg', 'png', 'pdf'];
    if (!in_array($ext, $extsValidas, true)) {
        api_erro('extensao_invalida', 'Apenas jpg, jpeg, png ou pdf.', 422);
    }
    // dupla checagem: extensão E conteúdo real (finfo)
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$arq['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
        api_erro('mime_invalido', 'Conteúdo do arquivo não é uma imagem JPG/PNG nem PDF.', 422);
    }

    $tenant = vero_tenant();
    $ano = date('Y');
    $raiz = dirname(__DIR__, 3); // raiz do projeto (/var/www/html no container)
    $dir = $raiz . DIRECTORY_SEPARATOR . 'storage_private' . DIRECTORY_SEPARATOR . 'app_anexos'
         . DIRECTORY_SEPARATOR . $tenant . DIRECTORY_SEPARATOR . $ano;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        api_erro('erro_storage', 'Não foi possível preparar o diretório de anexos.', 500);
    }
    $nomeArquivo = bin2hex(random_bytes(16)) . '.' . $ext;
    $destino = $dir . DIRECTORY_SEPARATOR . $nomeArquivo;
    $urlRelativa = "storage_private/app_anexos/{$tenant}/{$ano}/{$nomeArquivo}";

    $tmp = (string)$arq['tmp_name'];
    $hash = hash_file('sha256', $tmp);

    // move + insert juntos: no replay idempotente o callable nem roda
    // (o temp do PHP é descartado no fim da requisição).
    $gravar = function () use ($tmp, $destino, $origemTipo, $origemId, $ext, $nomeOriginal, $urlRelativa, $tamanho, $hash, $descricao) {
        if (!move_uploaded_file($tmp, $destino) && !rename($tmp, $destino)) {
            throw new RuntimeException('Falha ao salvar o arquivo no storage.');
        }
        $id = vero_insert('agro_anexos', [
            'origem_tipo'   => $origemTipo,
            'origem_id'     => $origemId,
            'tipo_arquivo'  => $ext,
            'nome_original' => $nomeOriginal,
            'url'           => $urlRelativa,
            'tamanho_bytes' => $tamanho,
            'hash_sha256'   => $hash,
            'descricao'     => $descricao,
        ]);
        return [$id, ['id' => $id, 'url' => $urlRelativa]];
    };

    if ($clientUuid !== '') {
        api_idempotente($clientUuid, 'anexo', $gravar);
    }
    [$id, $data] = $gravar();
    api_ok($data, 'Anexo registrado.', 201);
}

/* ============================================================
   12) POST /recebimentos/confirmar — F5: conferência FÍSICA do
   insumo recebido (QR na porta do almoxarifado). Vira ENTRADA
   real de estoque pelo motor do web (vero_srv_estoque_entrada:
   saldo + custo médio + lote com validade → FEFO/alertas).
   P-75: o app NÃO manda custo — a entrada usa o custo médio
   ATUAL do produto (não distorce o médio); o valor fiscal é
   conciliado depois pelo escritório (compras/NF).
   ============================================================ */
function rota_recebimento_confirmar(array $usuario): never
{
    // Slug REAL do módulo (permissions): estoque.entradas.editar — não existe
    // 'estoque.movimentacoes.*'. Gestor/almoxarife têm; operador só tem *.ver.
    api_exigir('estoque.entradas.editar');

    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');

    $prodId = api_c_int($corpo, 'produto_id');
    if ($prodId === null) {
        api_erro('campo_obrigatorio', "Informe o campo 'produto_id'.", 422);
    }
    if (api_linha_tenant('estoque_produtos', $prodId) === null) {
        api_erro('produto_invalido', 'Produto não encontrado.', 422);
    }
    $qtd = api_c_dec($corpo, 'quantidade');
    if ($qtd === null || $qtd <= 0) {
        api_erro('quantidade_invalida', 'Informe a quantidade recebida (maior que zero).', 422);
    }
    $validade = api_c_str($corpo, 'validade', 10);
    if ($validade !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validade)) {
        api_erro('validade_invalida', "Validade deve ser YYYY-MM-DD.", 422);
    }
    $lote  = api_c_str($corpo, 'lote', 60);
    $nf    = api_c_str($corpo, 'nf_numero', 30);
    $diverg = (bool)($corpo['divergencia'] ?? false);

    if (!function_exists('vero_srv_estoque_entrada') || !function_exists('vero_srv_almox_padrao')) {
        api_erro('servico_indisponivel', 'Motor de estoque indisponível no servidor.', 500);
    }
    $data = date('Y-m-d');
    // P-81 (EST-018): período fechado responde 422 legível (não 500 da exception)
    if (function_exists('vero_srv_estoque_pode_movimentar')) {
        $g = vero_srv_estoque_pode_movimentar($data);
        if (!($g['pode'] ?? true)) {
            api_erro('periodo_fechado', (string)($g['motivo'] ?? 'Período de estoque fechado.'), 422);
        }
    }
    $almox = vero_srv_almox_padrao();
    $saldoAtual = vero_srv_estoque_saldo($prodId, $almox);
    $custoUnit = (float)$saldoAtual['custo_medio']; // P-75: sem custo do app

    // Divergência/NF/lote registrados na observação da movimentação (trilha F5)
    $partes = ['[app] recebimento conferido por ' . $usuario['nome']];
    if ($nf !== null) {
        $partes[] = 'NF ' . $nf;
    }
    if ($lote !== null) {
        $partes[] = 'lote ' . $lote;
    }
    if ($diverg) {
        $partes[] = 'DIVERGENCIA apontada na conferência física';
    }
    if (($obs = api_c_str($corpo, 'observacao', 160)) !== null) {
        $partes[] = $obs;
    }
    $obsMov = mb_substr(implode(' — ', $partes), 0, 255);

    api_idempotente($clientUuid, 'recebimento', function () use ($prodId, $almox, $qtd, $custoUnit, $data, $obsMov, $validade, $lote, $diverg) {
        $movId = vero_srv_estoque_entrada(
            $prodId, $almox, $qtd, $custoUnit, $data,
            'recebimento_app', null, $obsMov, $validade
        );
        // o serviço gera código de lote próprio quando há validade — troca pelo
        // código FÍSICO lido no QR/etiqueta, se o app o informou
        $loteId = vero_val(
            'SELECT lote_id FROM estoque_movimentacoes WHERE id = :i AND tenant_id = :t',
            [':i' => $movId, ':t' => vero_tenant()]
        );
        $loteId = ((int)$loteId) > 0 ? (int)$loteId : null;
        if ($loteId !== null && $lote !== null) {
            vero_pdo()->prepare(
                'UPDATE estoque_lotes SET codigo_lote = ?, updated_by = ? WHERE id = ? AND tenant_id = ?'
            )->execute([$lote, vero_uid(), $loteId, vero_tenant()]);
        }
        $s = vero_row(
            'SELECT quantidade FROM estoque_saldos WHERE tenant_id = :t AND produto_id = :p AND almoxarifado_id = :a',
            [':t' => vero_tenant(), ':p' => $prodId, ':a' => $almox]
        );
        return [$movId, [
            'id'          => $movId,
            'lote_id'     => $loteId,
            'saldo'       => $s ? (float)$s['quantidade'] : null,
            'divergencia' => $diverg,
        ]];
    });
}

/* ============================================================
   COMPRAS — app de campo. Espelham o módulo web compras/*.php:
   - solicitação de compra (SC) — qualquer papel com permissão;
   - aprovar/rejeitar pedido — caixa do supervisor (alçada é do web);
   - receber contra pedido — reusa vero_srv_compra_confirmar_recebimento
     (entrada de estoque custo-médio/FEFO + conta a pagar). P-75: o app
     NÃO manda R$ — o custo vem do próprio pedido.
   ============================================================ */

/** Carrega compras_next_numero() do módulo web (compras/_helpers.php). */
function api_compras_helpers(): void
{
    $arq = dirname(__DIR__, 3) . '/compras/_helpers.php';
    if (is_file($arq)) {
        require_once $arq;
    }
}

/* ── 10) POST /compras/solicitacoes — cria uma solicitação (SC) ── */
function rota_compra_solicitar(array $usuario): never
{
    api_exigir('compras.solicitacoes_compra.editar');
    api_compras_helpers();
    if (!function_exists('compras_next_numero')) {
        api_erro('servico_indisponivel', 'Módulo de compras indisponível no servidor.', 500);
    }

    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');
    $data = substr((string)api_c_datahora($corpo, 'data', date('Y-m-d')), 0, 10);

    // itens: [{produto_id?, descricao?, quantidade}] — produto do estoque OU texto livre
    $itens = [];
    if (is_array($corpo['itens'] ?? null)) {
        foreach ($corpo['itens'] as $it) {
            if (!is_array($it)) continue;
            $q = api_c_dec($it, 'quantidade');
            if ($q === null || $q <= 0) continue;
            $pid = api_c_int($it, 'produto_id');
            if ($pid !== null && api_linha_tenant('estoque_produtos', $pid) === null) $pid = null;
            $desc = api_c_str($it, 'descricao', 180);
            if ($pid === null && ($desc === null || $desc === '')) continue;
            $itens[] = ['produto_id' => $pid, 'descricao' => $desc, 'quantidade' => $q];
        }
    }
    if ($itens === []) {
        api_erro('solicitacao_vazia', 'Inclua ao menos 1 item (produto do estoque ou descrição) com quantidade.', 422);
    }

    // vínculo de custo opcional (mesma validação do web solicitacoes.php)
    $stId = api_c_int($corpo, 'safra_talhao_id');
    if ($stId !== null && api_linha_tenant('agro_safra_talhoes', $stId) === null) $stId = null;
    $ccId = api_c_int($corpo, 'centro_custo_id');
    if ($ccId !== null && api_linha_tenant('centros_custo', $ccId) === null) $ccId = null;
    $justificativa = api_c_str($corpo, 'justificativa', 255);

    api_idempotente($clientUuid, 'solicitacao_compra', function () use ($itens, $data, $stId, $ccId, $justificativa) {
        $solId = vero_insert('compras_solicitacoes', [
            'numero'           => compras_next_numero('compras_solicitacoes', 'SC'),
            'solicitante_id'   => vero_uid(),
            'status'           => 'aberta',
            'justificativa'    => $justificativa,
            'data_solicitacao' => $data,
            'safra_talhao_id'  => $stId,
            'centro_custo_id'  => $ccId,
        ]);
        $ins = vero_pdo()->prepare(
            "INSERT INTO compras_solicitacao_itens (tenant_id, solicitacao_id, produto_id, descricao, quantidade)
             VALUES (?,?,?,?,?)"
        );
        foreach ($itens as $item) {
            $ins->execute([vero_tenant(), $solId, $item['produto_id'], $item['descricao'], $item['quantidade']]);
        }
        return [$solId, ['id' => $solId, 'itens' => count($itens)]];
    });
}

/* ── 11) POST /compras/pedidos/{id}/(aprovar|rejeitar) ── */
function rota_compra_decidir(array $usuario, string $id, string $decisao): never
{
    api_exigir('compras.aprovacoes.editar');

    $pedidoId = (int)$id;
    $aprovar = $decisao === 'aprovar';
    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');
    $obs = api_c_str($corpo, 'observacao', 255);

    // aprovação PENDENTE do pedido (mesma consistência do web aprovacoes.php)
    $apr = vero_row(
        "SELECT a.id AS aprovacao_id, p.numero, p.status AS pedido_status
           FROM compras_aprovacoes a
           JOIN compras_pedidos p ON p.id = a.pedido_id AND p.tenant_id = a.tenant_id
          WHERE a.tenant_id = :t AND a.pedido_id = :p AND a.status = 'pendente'
          ORDER BY a.id DESC LIMIT 1",
        [':t' => vero_tenant(), ':p' => $pedidoId]
    );
    if (!$apr || (string)$apr['pedido_status'] !== 'aprovacao') {
        api_erro('aprovacao_invalida', 'Pedido não está aguardando aprovação (ou já foi decidido).', 409);
    }

    api_idempotente($clientUuid, 'compra_aprovacao', function () use ($apr, $aprovar, $obs, $pedidoId) {
        vero_pdo()->prepare(
            "UPDATE compras_aprovacoes SET status = ?, aprovador_id = ?, observacao = ?, data_decisao = NOW()
              WHERE tenant_id = ? AND id = ?"
        )->execute([$aprovar ? 'aprovado' : 'rejeitado', vero_uid(), $obs, vero_tenant(), (int)$apr['aprovacao_id']]);
        vero_update('compras_pedidos', $pedidoId, ['status' => $aprovar ? 'aprovado' : 'rascunho']);
        return [$pedidoId, [
            'pedido_id' => $pedidoId,
            'numero'    => $apr['numero'],
            'decisao'   => $aprovar ? 'aprovado' : 'rejeitado',
        ]];
    });
}

/* ── 12) POST /compras/pedidos/{id}/receber — recebimento contra pedido ── */
function rota_compra_receber(array $usuario, string $id): never
{
    api_exigir('compras.recebimentos.editar');
    api_compras_helpers();
    if (!function_exists('compras_next_numero') || !function_exists('vero_srv_compra_confirmar_recebimento')) {
        api_erro('servico_indisponivel', 'Módulo de compras indisponível no servidor.', 500);
    }

    $pedidoId = (int)$id;
    $corpo = api_corpo();
    $clientUuid = (string)api_exigir_campo($corpo, 'client_uuid');

    $pedido = vero_row(
        "SELECT * FROM compras_pedidos
          WHERE id = :i AND tenant_id = :t AND status IN ('aprovado','recebido_parcial')",
        [':i' => $pedidoId, ':t' => vero_tenant()]
    );
    if (!$pedido) {
        api_erro('pedido_nao_recebivel', 'Pedido inválido ou não liberado para recebimento (precisa estar aprovado).', 422);
    }

    $dataReal = substr((string)api_c_datahora($corpo, 'data', date('Y-m-d')), 0, 10);
    if (function_exists('vero_srv_estoque_pode_movimentar')) {
        $g = vero_srv_estoque_pode_movimentar($dataReal);
        if (!($g['pode'] ?? true)) {
            api_erro('periodo_fechado', (string)($g['motivo'] ?? 'Período de estoque fechado.'), 422);
        }
    }

    // itens: [{pedido_item_id, quantidade, validade?}] — sem custo (vem do pedido)
    $recebidos = [];
    if (is_array($corpo['itens'] ?? null)) {
        foreach ($corpo['itens'] as $it) {
            if (!is_array($it)) continue;
            $piId = api_c_int($it, 'pedido_item_id');
            $q = api_c_dec($it, 'quantidade');
            if ($piId === null || $q === null || $q <= 0) continue;
            $val = api_c_str($it, 'validade', 10);
            if ($val !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) $val = null;
            $recebidos[$piId] = ['pedido_item_id' => $piId, 'quantidade' => $q, 'validade' => $val];
        }
    }
    if ($recebidos === []) {
        api_erro('sem_itens', 'Informe a quantidade recebida de ao menos um item.', 422);
    }

    api_idempotente($clientUuid, 'compra_recebimento', function () use ($pedido, $pedidoId, $recebidos, $dataReal) {
        $recId = vero_insert('compras_recebimentos', [
            'pedido_id'        => $pedidoId,
            'numero'           => compras_next_numero('compras_recebimentos', 'RC'),
            'tipo'             => 'parcial', // o service corrige p/ 'total' se zerar a pendência
            'almoxarifado_id'  => vero_srv_almox_padrao(),
            'status'           => 'rascunho',
            'data_recebimento' => $dataReal . ' 00:00:00',
        ]);
        $pdo = vero_pdo();
        $ins = $pdo->prepare(
            "INSERT INTO compras_recebimento_itens
                    (tenant_id, recebimento_id, pedido_item_id, produto_id, quantidade, custo_unitario, validade)
             VALUES (?,?,?,?,?,?,?)"
        );
        $valorReceb = 0.0;
        foreach ($recebidos as $r) {
            $item = vero_row(
                "SELECT * FROM compras_pedido_itens WHERE id = :i AND tenant_id = :t AND pedido_id = :p",
                [':i' => $r['pedido_item_id'], ':t' => vero_tenant(), ':p' => $pedidoId]
            );
            if (!$item) continue;
            $pendente = (float)$item['quantidade'] - (float)$item['quantidade_recebida'];
            if ($r['quantidade'] > $pendente + 0.0001) {
                throw new RuntimeException('Quantidade recebida maior que a pendente no item "'
                    . ($item['descricao'] ?? ('#' . $item['id'])) . '" (pendente: ' . number_format($pendente, 2, ',', '.') . ').');
            }
            $custo = (float)$item['valor_unitario']; // P-75: custo do pedido, app não envia R$
            $ins->execute([
                vero_tenant(), $recId, (int)$item['id'],
                $item['produto_id'] !== null ? (int)$item['produto_id'] : null,
                $r['quantidade'], $custo, $r['validade'],
            ]);
            $valorReceb += round($r['quantidade'] * $custo, 2);
        }
        if ($valorReceb <= 0) {
            throw new RuntimeException('Nenhuma quantidade recebida válida.');
        }

        // condição de pagamento vem do PEDIDO (o escritório definiu); título único
        // usa a entrega prevista do pedido, senão data + 30 dias (default do app).
        $condicao = $pedido['condicao_pagamento'] ?? null;
        $parcelasDef = function_exists('vero_srv_parcelas_de_condicao')
            ? vero_srv_parcelas_de_condicao($condicao, $valorReceb, $dataReal) : null;
        $vencimento = null;
        if ($parcelasDef === null) {
            $vencimento = ($pedido['data_entrega_prevista'] ?? null)
                ?: date('Y-m-d', strtotime($dataReal . ' +30 days'));
        }
        $res = vero_srv_compra_confirmar_recebimento($recId, $vencimento, $parcelasDef);
        return [$recId, [
            'id'         => $recId,
            'valor'      => $res['valor'],
            'no_estoque' => $res['no_estoque'],
            'parcelas'   => $parcelasDef !== null ? count($parcelasDef) : 1,
        ]];
    });
}
