<?php
declare(strict_types=1);
/* ============================================================
   VERO — Crachá do colaborador → pessoa (resolver compartilhado)
   Usado pela captura de produção POR LEITURA: crachá do colhedor na
   colheita e do embalador no embalamento, ambos caindo em rh_producao_itens.
   O código lido (QR/barras) mapeia para agro_operadores (colaborador) ou
   rh_terceirizados (terceirizado), sempre no escopo do tenant.
   ============================================================ */
require_once __DIR__ . '/vero_services.php'; /* premiação/custeio p/ vero_srv_producao_por_cracha */

/**
 * Resolve um código de crachá lido para o colaborador ATIVO do tenant.
 * Procura primeiro em agro_operadores, depois em rh_terceirizados.
 * @return array{origem_pessoa:string,id:int,nome:string,funcao:?string}|null
 */
function vero_srv_cracha_resolver(?string $codigo): ?array
{
    $c = trim((string)$codigo);
    if ($c === '') return null;

    $op = vero_row(
        "SELECT id, nome, funcao, funcao_packing FROM agro_operadores
          WHERE tenant_id = :t AND cracha = :c AND ativo = 1 LIMIT 1",
        [':t' => vero_tenant(), ':c' => $c]);
    if ($op) {
        return ['origem_pessoa' => 'colaborador', 'id' => (int)$op['id'],
                'nome' => (string)$op['nome'], 'funcao' => $op['funcao'] ?? null,
                'papel' => $op['funcao_packing'] ?? null];
    }

    $tc = vero_row(
        "SELECT id, nome, funcao_packing FROM rh_terceirizados
          WHERE tenant_id = :t AND cracha = :c AND ativo = 1 LIMIT 1",
        [':t' => vero_tenant(), ':c' => $c]);
    if ($tc) {
        return ['origem_pessoa' => 'terceirizado', 'id' => (int)$tc['id'],
                'nome' => (string)$tc['nome'], 'funcao' => null,
                'papel' => $tc['funcao_packing'] ?? null];
    }

    return null;
}

/** Código de crachá sugerido para um colaborador (determinístico e legível). */
function vero_srv_cracha_sugerir(string $origemPessoa, int $id): string
{
    $pref = $origemPessoa === 'terceirizado' ? 'CRT' : 'CRC';
    return $pref . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

/** enum `tipo` do agro_apontamentos a partir da categoria da atividade
 *  (mesma regra do handler agro/apontamentos.php, replicada p/ o serviço). */
function vero_srv_apont_tipo_enum(string $categoria): string
{
    return match ($categoria) {
        'trato_cultural' => 'tratos_culturais',
        'colheita'       => 'colheita',
        'aplicacao'      => 'aplicacao',
        'irrigacao'      => 'irrigacao',
        'nutricao'       => 'nutricao',
        default          => 'outro',
    };
}

/**
 * Aponta PRODUÇÃO POR CRACHÁ: lê o crachá → pessoa, acha/cria o apontamento do
 * dia para (atividade, válvula) e grava um rh_producao_itens com premiação, e
 * emite o custeio. É o "posto de captura" — colhedor na colheita (com válvula)
 * e embalador no embalamento (sem válvula, talhao_id NULL → custo de packing,
 * D6). Deve rodar dentro de transação (abre uma se não houver).
 * @return array{pessoa:array,apontamento_id:int,item_id:int,valor_total:float}
 */
function vero_srv_producao_por_cracha(
    string $data, int $tipoAtividadeId, ?int $talhaoId, ?int $safraTalhaoId,
    string $cracha, float $quantidade,
    ?float $meta = null, ?float $valorUnitario = null, ?float $pesoKg = null
): array {
    $pessoa = vero_srv_cracha_resolver($cracha);
    if (!$pessoa) throw new RuntimeException('CRACHA_INVALIDO: crachá não reconhecido ou colaborador inativo.');
    if ($quantidade <= 0) throw new RuntimeException('Quantidade produzida deve ser maior que zero.');

    $tipo = vero_row("SELECT * FROM agro_tipos_atividade WHERE id=:i AND tenant_id=:t AND ativo=1",
        [':i' => $tipoAtividadeId, ':t' => vero_tenant()]);
    if (!$tipo) throw new RuntimeException('Tipo de atividade inválido.');
    $tipoEnum = vero_srv_apont_tipo_enum((string)$tipo['categoria']);

    /* FK validadas contra o tenant */
    if ($talhaoId && !vero_val("SELECT id FROM agro_talhoes WHERE id=:i AND tenant_id=:t", [':i' => $talhaoId, ':t' => vero_tenant()])) {
        throw new RuntimeException('Válvula inválida.');
    }
    $culturaId = null;
    if ($safraTalhaoId) {
        $st = vero_row("SELECT cultura_id FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t", [':i' => $safraTalhaoId, ':t' => vero_tenant()]);
        if (!$st) throw new RuntimeException('Vínculo safra × válvula inválido.');
        $culturaId = $st['cultura_id'] !== null ? (int)$st['cultura_id'] : null;
    }

    $pdo   = vero_pdo();
    $jaTx  = $pdo->inTransaction();
    if (!$jaTx) $pdo->beginTransaction();
    try {
        /* acha o apontamento do dia p/ (atividade, válvula); talhao_id NULL-safe (<=>) */
        $apontId = (int)(vero_val(
            "SELECT id FROM agro_apontamentos
              WHERE tenant_id = :t AND DATE(data_apontamento) = :d
                AND tipo_atividade_id = :ta AND (talhao_id <=> :tal)
              ORDER BY id DESC LIMIT 1",
            [':t' => vero_tenant(), ':d' => $data, ':ta' => $tipoAtividadeId, ':tal' => $talhaoId]) ?? 0);
        if (!$apontId) {
            $apontId = vero_insert('agro_apontamentos', [
                'data_apontamento'  => $data . ' 00:00:00',
                'talhao_id'         => $talhaoId,
                'safra_talhao_id'   => $safraTalhaoId,
                'tipo_atividade_id' => $tipoAtividadeId,
                'tipo'              => $tipoEnum,
                'origem'            => 'web',
                'status'            => 'validado',
                'observacao'        => 'Apontamento por crachá',
            ]);
        }

        /* meta/valor: usa o informado; senão herda da REGRA vigente (não digitados no posto) */
        $regra   = vero_srv_regra_premiacao($tipoAtividadeId, $culturaId, $data);
        $metaAp  = $meta ?? ($regra ? (float)($regra['meta_qtd'] ?? 0) : 0.0);
        $valorUn = $valorUnitario ?? ($regra ? (float)($regra['valor_acima_meta'] ?? 0) : 0.0);
        if ($pessoa['origem_pessoa'] === 'colaborador') {
            $calc = vero_srv_premiacao_calc($quantidade, $metaAp, $valorUn);
            $item = [
                'apontamento_id' => $apontId, 'origem_pessoa' => 'colaborador',
                'operador_id' => $pessoa['id'], 'terceirizado_id' => null,
                'modalidade' => 'premiacao', 'regra_premiacao_id' => $regra ? (int)$regra['id'] : null,
                'unidade' => $regra['unidade'] ?? ($tipo['unidade_padrao'] ?? 'outro'),
                'quantidade' => $quantidade, 'peso_kg' => $pesoKg,
                'meta_aplicada' => $metaAp, 'valor_unitario' => $valorUn,
                'qtd_acima_meta' => $calc['qtd_acima'], 'valor_total' => $calc['valor_total'],
                'data_trabalho' => $data,
            ];
        } else {
            $item = [
                'apontamento_id' => $apontId, 'origem_pessoa' => 'terceirizado',
                'operador_id' => null, 'terceirizado_id' => $pessoa['id'],
                'modalidade' => 'producao', 'regra_premiacao_id' => null,
                'unidade' => $tipo['unidade_padrao'] ?? 'outro',
                'quantidade' => $quantidade, 'peso_kg' => $pesoKg,
                'meta_aplicada' => null, 'valor_unitario' => $valorUn,
                'qtd_acima_meta' => null, 'valor_total' => vero_srv_valor_producao($quantidade, $valorUn),
                'data_trabalho' => $data,
            ];
        }
        $itemId = vero_insert('rh_producao_itens', $item);
        vero_srv_apontamento_reemitir_custeio($apontId);

        if (!$jaTx) $pdo->commit();
        return ['pessoa' => $pessoa, 'apontamento_id' => $apontId, 'item_id' => $itemId,
                'valor_total' => (float)$item['valor_total']];
    } catch (Throwable $e) {
        if (!$jaTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Resolve um ROMANEIO de carga para o alvo do apontamento de colheita
 * (produção via packing). A carga liga colheita_registros → herda data/válvula.
 * A atividade é a de colheita com produção do tenant (param
 * 'colheita.atividade_produtividade_id' desambigua quando há mais de uma).
 * @return array{registro_id:int,talhao_id:?int,safra_talhao_id:?int,
 *               data_colheita:string,tipo_atividade_id:int}|null
 */
function vero_srv_romaneio_alvo_colheita(string $romaneio): ?array
{
    $rom = trim($romaneio);
    if ($rom === '') return null;

    $carga = vero_row(
        "SELECT cr.id AS registro_id, cr.talhao_id, cr.safra_talhao_id, cr.data_colheita
           FROM colheita_cargas cc
           JOIN colheita_registros cr ON cr.id = cc.registro_id AND cr.tenant_id = cc.tenant_id
          WHERE cc.tenant_id = :t AND cc.romaneio = :r
          ORDER BY cc.id DESC LIMIT 1",
        [':t' => vero_tenant(), ':r' => $rom]);
    if (!$carga) {
        /* Gestor 19/08: carga SEM registro de colheita vinculado (o caminho comum
           do app) fazia o lookup devolver "não encontrado" para romaneio legítimo.
           Fallback: resolve pela PRÓPRIA carga — ela carrega válvula/safra/data. */
        $cc = vero_row(
            "SELECT cc.talhao_id, cc.safra_talhao_id, cc.data_carga
               FROM colheita_cargas cc
              WHERE cc.tenant_id = :t AND cc.romaneio = :r
              ORDER BY cc.id DESC LIMIT 1",
            [':t' => vero_tenant(), ':r' => $rom]);
        if (!$cc) return null;
        $carga = [
            'registro_id'     => null,
            'talhao_id'       => $cc['talhao_id'],
            'safra_talhao_id' => $cc['safra_talhao_id'],
            'data_colheita'   => $cc['data_carga'],
        ];
    }

    $paramId = (int)vero_srv_param('colheita.atividade_produtividade_id', '0');
    if (!$paramId) {
        $atv = vero_row(
            "SELECT id FROM agro_tipos_atividade
              WHERE tenant_id = :t AND categoria = 'colheita' AND exige_producao = 1 AND ativo = 1
              ORDER BY id LIMIT 1", [':t' => vero_tenant()]);
        $paramId = $atv ? (int)$atv['id'] : 0;
    }
    if (!$paramId) return null; // tenant sem atividade de colheita com produção

    return [
        'registro_id'       => $carga['registro_id'] !== null ? (int)$carga['registro_id'] : null,
        'talhao_id'         => $carga['talhao_id'] !== null ? (int)$carga['talhao_id'] : null,
        'safra_talhao_id'   => $carga['safra_talhao_id'] !== null ? (int)$carga['safra_talhao_id'] : null,
        'data_colheita'     => substr((string)$carga['data_colheita'], 0, 10),
        'tipo_atividade_id' => $paramId,
    ];
}

/**
 * INCREMENTA a produção de UMA pessoa (beep de caixa) no apontamento de colheita
 * — upsert: acumula na MESMA linha rh_producao_itens (1 por pessoa/apontamento),
 * recalcula a premiação sobre o total. Grava na DATA DA COLHEITA e marca o
 * apontamento producao_via_packing=1 (a grade manual fica read-only).
 * Difere de vero_srv_producao_por_cracha (que insere 1 item por chamada).
 * Colaborador: modalidade premiação (meta/valor da regra vigente). Terceirizado:
 * modalidade produção (valor 0 no automático — tarifa definida à parte).
 * @return array{pessoa:array,apontamento_id:int,item_id:int,quantidade_total:float,valor_total:float}
 */
function vero_srv_producao_incrementar_cracha(
    string $dataColheita, int $tipoAtividadeId, ?int $talhaoId, ?int $safraTalhaoId,
    string $cracha, float $qtd = 1.0, ?float $pesoCaixaKg = null
): array {
    $pessoa = vero_srv_cracha_resolver($cracha);
    if (!$pessoa) throw new RuntimeException('CRACHA_INVALIDO: crachá não reconhecido ou colaborador inativo.');
    if ($qtd <= 0) throw new RuntimeException('Quantidade deve ser maior que zero.');

    $tipo = vero_row("SELECT * FROM agro_tipos_atividade WHERE id=:i AND tenant_id=:t AND ativo=1",
        [':i' => $tipoAtividadeId, ':t' => vero_tenant()]);
    if (!$tipo) throw new RuntimeException('Tipo de atividade inválido.');
    $tipoEnum = vero_srv_apont_tipo_enum((string)$tipo['categoria']);

    if ($talhaoId && !vero_val("SELECT id FROM agro_talhoes WHERE id=:i AND tenant_id=:t", [':i' => $talhaoId, ':t' => vero_tenant()])) {
        throw new RuntimeException('Válvula inválida.');
    }
    $culturaId = null;
    if ($safraTalhaoId) {
        $st = vero_row("SELECT cultura_id FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t", [':i' => $safraTalhaoId, ':t' => vero_tenant()]);
        if (!$st) throw new RuntimeException('Vínculo safra × válvula inválido.');
        $culturaId = $st['cultura_id'] !== null ? (int)$st['cultura_id'] : null;
    }

    $pdo  = vero_pdo();
    $jaTx = $pdo->inTransaction();
    if (!$jaTx) $pdo->beginTransaction();
    try {
        /* find-or-create apontamento na DATA DA COLHEITA + marca packing */
        $apont = vero_row(
            "SELECT id FROM agro_apontamentos
              WHERE tenant_id = :t AND DATE(data_apontamento) = :d
                AND tipo_atividade_id = :ta AND (talhao_id <=> :tal)
              ORDER BY id DESC LIMIT 1",
            [':t' => vero_tenant(), ':d' => $dataColheita, ':ta' => $tipoAtividadeId, ':tal' => $talhaoId]);
        if ($apont) {
            $apontId = (int)$apont['id'];
            $pdo->prepare("UPDATE agro_apontamentos SET producao_via_packing = 1 WHERE id = :i AND tenant_id = :t")
                ->execute([':i' => $apontId, ':t' => vero_tenant()]);
        } else {
            $apontId = vero_insert('agro_apontamentos', [
                'data_apontamento'     => $dataColheita . ' 00:00:00',
                'talhao_id'            => $talhaoId,
                'safra_talhao_id'      => $safraTalhaoId,
                'tipo_atividade_id'    => $tipoAtividadeId,
                'tipo'                 => $tipoEnum,
                'origem'               => 'web',
                'status'               => 'validado',
                'producao_via_packing' => 1,
                'observacao'           => 'Produção apurada no packing (leitura de caixa)',
            ]);
        }

        $ehColab = $pessoa['origem_pessoa'] === 'colaborador';
        $colPessoa = $ehColab ? 'operador_id' : 'terceirizado_id';
        $item = vero_row(
            "SELECT * FROM rh_producao_itens
              WHERE tenant_id = :t AND apontamento_id = :a AND origem_pessoa = :op AND {$colPessoa} = :pid
              ORDER BY id DESC LIMIT 1",
            [':t' => vero_tenant(), ':a' => $apontId, ':op' => $pessoa['origem_pessoa'], ':pid' => $pessoa['id']]);

        if ($item) {
            /* INCREMENTA — reusa o snapshot de meta/valor já gravado */
            $novaQtd  = (float)$item['quantidade'] + $qtd;
            $novoPeso = $pesoCaixaKg !== null
                ? round((float)($item['peso_kg'] ?? 0) + $pesoCaixaKg, 3)
                : ($item['peso_kg'] !== null ? (float)$item['peso_kg'] : null);
            $valorUn  = (float)($item['valor_unitario'] ?? 0);
            if ($ehColab) {
                $meta = $item['meta_aplicada'] !== null ? (float)$item['meta_aplicada'] : 0.0;
                $calc = vero_srv_premiacao_calc($novaQtd, $meta, $valorUn);
                vero_update('rh_producao_itens', (int)$item['id'], [
                    'quantidade' => $novaQtd, 'peso_kg' => $novoPeso,
                    'qtd_acima_meta' => $calc['qtd_acima'], 'valor_total' => $calc['valor_total'],
                ]);
                $valorTotal = (float)$calc['valor_total'];
            } else {
                $valorTotal = vero_srv_valor_producao($novaQtd, $valorUn);
                vero_update('rh_producao_itens', (int)$item['id'], [
                    'quantidade' => $novaQtd, 'peso_kg' => $novoPeso, 'valor_total' => $valorTotal,
                ]);
            }
            $itemId = (int)$item['id'];
            $qtdTotal = $novaQtd;
        } else {
            /* CRIA — meta/valor da regra vigente (colaborador) */
            $regra = vero_srv_regra_premiacao($tipoAtividadeId, $culturaId, $dataColheita);
            if ($ehColab) {
                $meta    = $regra ? (float)($regra['meta_qtd'] ?? 0) : 0.0;
                $valorUn = $regra ? (float)($regra['valor_acima_meta'] ?? 0) : 0.0;
                $calc    = vero_srv_premiacao_calc($qtd, $meta, $valorUn);
                $novo = [
                    'apontamento_id' => $apontId, 'origem_pessoa' => 'colaborador',
                    'operador_id' => $pessoa['id'], 'terceirizado_id' => null,
                    'modalidade' => 'premiacao', 'regra_premiacao_id' => $regra ? (int)$regra['id'] : null,
                    'unidade' => $regra['unidade'] ?? ($tipo['unidade_padrao'] ?? 'caixa'),
                    'quantidade' => $qtd, 'peso_kg' => $pesoCaixaKg,
                    'meta_aplicada' => $meta, 'valor_unitario' => $valorUn,
                    'qtd_acima_meta' => $calc['qtd_acima'], 'valor_total' => $calc['valor_total'],
                    'data_trabalho' => $dataColheita,
                ];
                $valorTotal = (float)$calc['valor_total'];
            } else {
                $novo = [
                    'apontamento_id' => $apontId, 'origem_pessoa' => 'terceirizado',
                    'operador_id' => null, 'terceirizado_id' => $pessoa['id'],
                    'modalidade' => 'producao', 'regra_premiacao_id' => null,
                    'unidade' => $tipo['unidade_padrao'] ?? 'caixa',
                    'quantidade' => $qtd, 'peso_kg' => $pesoCaixaKg,
                    'meta_aplicada' => null, 'valor_unitario' => 0.0,
                    'qtd_acima_meta' => null, 'valor_total' => 0.0,
                    'data_trabalho' => $dataColheita,
                ];
                $valorTotal = 0.0;
            }
            $itemId = vero_insert('rh_producao_itens', $novo);
            $qtdTotal = $qtd;
        }

        vero_srv_apontamento_reemitir_custeio($apontId);

        if (!$jaTx) $pdo->commit();
        return ['pessoa' => $pessoa, 'apontamento_id' => $apontId, 'item_id' => $itemId,
                'quantidade_total' => $qtdTotal, 'valor_total' => $valorTotal];
    } catch (Throwable $e) {
        if (!$jaTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
