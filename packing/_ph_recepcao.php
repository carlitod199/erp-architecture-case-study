<?php
declare(strict_types=1);
/* ============================================================
   VERO — Packing House / Serviço da Recepção (ph_recepcao)
   Onda 1 · Recepção: os 5 GATES + o RELÓGIO de frio/SO2.
   Funções PURAS de decisão + gravação transacional da recepção.
   Nada de HTML — as telas (packing/recepcao.php, painel do relógio)
   dependem EXATAMENTE das assinaturas abaixo.

   Regras firmadas:
   - Unidade de packing = almoxarifado tipo='packing' (Decisão 1).
   - tenant_parametros é o ÚNICO mecanismo de parâmetro (vero_srv_param):
       packing.gate_carencia.bloqueia = '1' (bloqueia) | '0' (só avisa).
   - Categóricos são VARCHAR + whitelist em PHP (NUNCA ENUM novo).
   - colheita_cargas NÃO tem variedade/colheita direto: liga a
     colheita_registros por registro_id; o lote COLH- vive em
     estoque_lotes (colheita_registro_id = colheita_registros.id).
   ============================================================ */

require_once __DIR__ . '/../includes/vero_services.php'; /* vero_srv_*, vero_crud (vero_pdo/insert/tenant…) */

/* Janela do relógio de frio/SO2, em horas (verde<8, amarelo 8–12, vermelho>12). */
const PH_SO2_JANELA_H = 12.0;

/* Métodos de rastreabilidade aceitos (whitelist). */
const PH_METODOS_RASTREAB = ['segregacao', 'identidade_preservada'];

/* ───────────────────────── Helpers internos (normalização) ───────────────────────── */

/** ?int limpo (>0) ou null. */
function ph_recep_int($v): ?int
{
    if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) return null;
    if (!is_numeric($v)) return null;
    $i = (int)$v;
    return $i > 0 ? $i : null;
}

/** ?float ou null (aceita string numérica). */
function ph_recep_num($v): ?float
{
    if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) return null;
    return is_numeric($v) ? (float)$v : null;
}

/** ?string aparada ou null. */
function ph_recep_str($v): ?string
{
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

/** id => nome, escopado no tenant, para uma tabela com coluna `nome`. */
function ph_recep_nomes(string $tabela, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([vero_tenant()], $ids);
    $out = [];
    foreach (vero_rows(
        "SELECT id, nome FROM {$tabela} WHERE tenant_id = ? AND id IN ({$ph})", $params) as $r) {
        $out[(int)$r['id']] = (string)$r['nome'];
    }
    return $out;
}

/* ───────────────────────── Numeração ───────────────────────── */

/**
 * Próximo número 'REC-AAAA-SEQ' da unidade (sequência por ano+unidade dentro do
 * tenant). O prefixo é sempre gerado por código — a substring/like é segura.
 */
function ph_recepcao_numero(int $unidadeId): string
{
    $prefixo = sprintf('REC-%04d-', (int)date('Y'));
    $pos = strlen($prefixo) + 1; /* posição (1-based) logo após o prefixo */
    $max = vero_val(
        "SELECT MAX(CAST(SUBSTRING(numero, {$pos}) AS UNSIGNED))
           FROM ph_recepcoes
          WHERE tenant_id = :t AND unidade_id = :u AND numero LIKE :pfx",
        [':t' => vero_tenant(), ':u' => $unidadeId, ':pfx' => $prefixo . '%']);
    $seq = (int)($max ?? 0) + 1;
    return $prefixo . sprintf('%04d', $seq);
}

/* ───────────────────────── Cargas pendentes de recepção ───────────────────────── */

/**
 * colheita_cargas destino='packing' do tenant que AINDA NÃO foram recebidas
 * (não existem em ph_recepcao_itens). Enriquecidas com talhão/variedade/lote.
 * colhido_em = data_carga (datetime) — melhor proxy com hora para o relógio;
 * cai para a data de colheita do registro quando a carga não tem data.
 *
 * @return array<int, array{carga_id:int,romaneio:?string,data_carga:?string,
 *   talhao_id:?int,talhao_nome:?string,safra_talhao_id:?int,variedade_id:?int,
 *   variedade_nome:?string,peso_kg:?float,colhido_em:?string,lote_estoque_id:?int}>
 */
function ph_recepcao_cargas_pendentes(int $unidadeId): array
{
    $rows = vero_rows(
        "SELECT cc.id                         AS carga_id,
                cc.romaneio                   AS romaneio,
                cc.data_carga                 AS data_carga,
                cc.talhao_id                  AS talhao_id,
                t.nome                        AS talhao_nome,
                cc.safra_talhao_id            AS safra_talhao_id,
                cr.variedade_id               AS variedade_id,
                v.nome                        AS variedade_nome,
                cc.peso_kg                    AS peso_kg,
                COALESCE(cc.data_carga, cr.data_colheita) AS colhido_em,
                (SELECT el.id FROM estoque_lotes el
                  WHERE el.tenant_id = cc.tenant_id
                    AND el.colheita_registro_id = cr.id
                    AND el.codigo_lote LIKE 'COLH-%'
                  ORDER BY (el.status = 'estornado') ASC, el.id DESC
                  LIMIT 1)                     AS lote_estoque_id
           FROM colheita_cargas cc
           LEFT JOIN colheita_registros cr ON cr.id = cc.registro_id AND cr.tenant_id = cc.tenant_id
           LEFT JOIN agro_talhoes t         ON t.id = cc.talhao_id AND t.tenant_id = cc.tenant_id
           LEFT JOIN agro_variedades v      ON v.id = cr.variedade_id AND v.tenant_id = cc.tenant_id
          WHERE cc.tenant_id = :t
            AND cc.destino = 'packing'
            AND NOT EXISTS (
                SELECT 1 FROM ph_recepcao_itens ri
                 WHERE ri.tenant_id = cc.tenant_id AND ri.colheita_carga_id = cc.id)
          ORDER BY COALESCE(cc.data_carga, cr.data_colheita) ASC, cc.id ASC",
        [':t' => vero_tenant()]);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'carga_id'        => (int)$r['carga_id'],
            'romaneio'        => $r['romaneio'] !== null ? (string)$r['romaneio'] : null,
            'data_carga'      => $r['data_carga'] !== null ? (string)$r['data_carga'] : null,
            'talhao_id'       => ph_recep_int($r['talhao_id']),
            'talhao_nome'     => $r['talhao_nome'] !== null ? (string)$r['talhao_nome'] : null,
            'safra_talhao_id' => ph_recep_int($r['safra_talhao_id']),
            'variedade_id'    => ph_recep_int($r['variedade_id']),
            'variedade_nome'  => $r['variedade_nome'] !== null ? (string)$r['variedade_nome'] : null,
            'peso_kg'         => ph_recep_num($r['peso_kg']),
            'colhido_em'      => $r['colhido_em'] !== null ? (string)$r['colhido_em'] : null,
            'lote_estoque_id' => ph_recep_int($r['lote_estoque_id']),
        ];
    }
    return $out;
}

/* ───────────────────────── Relógio de frio / SO2 ───────────────────────── */

/**
 * Estado do relógio a partir da hora de colheita.
 * verde<8h, amarelo 8–12h, vermelho>12h; janela SO2 = 12h.
 * @return array{horas:?float,cor:string,restante_so2_h:?float}
 */
function ph_relogio_status(?string $colhidoEm): array
{
    $c = ph_recep_str($colhidoEm);
    if ($c === null) {
        return ['horas' => null, 'cor' => 'sem_dado', 'restante_so2_h' => null];
    }
    $ts = strtotime($c);
    if ($ts === false) {
        return ['horas' => null, 'cor' => 'sem_dado', 'restante_so2_h' => null];
    }
    $horas = round((time() - $ts) / 3600, 2);
    if ($horas < 8.0) {
        $cor = 'verde';
    } elseif ($horas <= PH_SO2_JANELA_H) {
        $cor = 'amarelo';
    } else {
        $cor = 'vermelho';
    }
    $restante = round(max(0.0, PH_SO2_JANELA_H - $horas), 2);
    return ['horas' => $horas, 'cor' => $cor, 'restante_so2_h' => $restante];
}

/* ───────────────────────── Os 5 gates ───────────────────────── */

/**
 * Avalia os 5 gates da recepção sobre um conjunto de itens.
 * Cada item: ['talhao_id','variedade_id','produtor_id','colhido_em','metodo_rastreabilidade'].
 * @return array{
 *   carencia:array{status:string,detalhe:string},
 *   certificacao:array{status:string,detalhe:string},
 *   rastreabilidade:array{status:string,detalhe:string},
 *   licenca:array{status:string,detalhe:string},
 *   so2:array{status:string,detalhe:string},
 *   bloqueia:bool}
 */
function ph_recepcao_gates(array $itens, ?int $mercadoId = null): array
{
    $hoje = date('Y-m-d');

    /* Coletas comuns */
    $talhaoIds = [];
    $variedadeIds = [];
    $produtorIds = [];
    $temIP = false;
    $piorColhido = null; /* menor (mais antigo) colhido_em */
    foreach ($itens as $it) {
        $tid = ph_recep_int($it['talhao_id'] ?? null);
        $vid = ph_recep_int($it['variedade_id'] ?? null);
        $pid = ph_recep_int($it['produtor_id'] ?? null);
        if ($tid) $talhaoIds[$tid] = $tid;
        if ($vid) $variedadeIds[$vid] = $vid;
        if ($pid) $produtorIds[$pid] = $pid;
        if (($it['metodo_rastreabilidade'] ?? '') === 'identidade_preservada') $temIP = true;
        $c = ph_recep_str($it['colhido_em'] ?? null);
        if ($c !== null) {
            $ts = strtotime($c);
            if ($ts !== false && ($piorColhido === null || $ts < $piorColhido)) $piorColhido = $ts;
        }
    }
    $talhaoNomes = ph_recep_nomes('agro_talhoes', $talhaoIds);
    $variedadeNomes = ph_recep_nomes('agro_variedades', $variedadeIds);

    /* ── Gate 1: carência (bloqueio CONFIGURÁVEL — Decisão 5) ── */
    $carenciaBloqueia = vero_srv_param('packing.gate_carencia.bloqueia', '1') !== '0';
    $carenciaHits = [];
    foreach ($talhaoIds as $tid) {
        $cs = vero_srv_talhao_carencias((int)$tid, $hoje);
        if ($cs) {
            $nomeT = $talhaoNomes[$tid] ?? ('Talhão #' . $tid);
            $prods = [];
            foreach ($cs as $c) {
                $prods[] = (string)$c['produto'] . ' (libera ' . (string)$c['liberado_em'] . ')';
            }
            $carenciaHits[] = $nomeT . ': ' . implode(', ', $prods);
        }
    }
    if ($carenciaHits) {
        $carencia = [
            'status'  => $carenciaBloqueia ? 'bloqueio' : 'aviso',
            'detalhe' => ($carenciaBloqueia ? 'Carência ativa (bloqueio): ' : 'Carência ativa (aviso): ')
                . implode('; ', $carenciaHits),
        ];
    } else {
        $carencia = ['status' => 'ok', 'detalhe' => 'Sem carência ativa nos talhões da carga.'];
    }

    /* ── Gate 2: certificação do produtor (ou da unidade) ── */
    $temCertUnidade = ((int)vero_val(
        "SELECT COUNT(*) FROM ph_certificacoes
          WHERE tenant_id = :t AND ativo = 1 AND escopo = 'unidade'
            AND (validade IS NULL OR validade >= CURDATE())",
        [':t' => vero_tenant()])) > 0;

    $semCert = [];
    if ($produtorIds) {
        foreach ($produtorIds as $pid) {
            $ok = $temCertUnidade;
            if (!$ok) {
                $ok = ((int)vero_val(
                    "SELECT COUNT(*) FROM ph_certificacoes
                      WHERE tenant_id = :t AND ativo = 1 AND escopo = 'produtor' AND escopo_id = :p
                        AND (validade IS NULL OR validade >= CURDATE())",
                    [':t' => vero_tenant(), ':p' => $pid])) > 0;
            }
            if (!$ok) $semCert[] = 'produtor #' . $pid;
        }
    } elseif (!$temCertUnidade) {
        /* Sem produtor identificado e sem certificação de unidade válida. */
        $semCert[] = 'unidade';
    }
    if ($semCert) {
        $certificacao = ['status' => 'aviso',
            'detalhe' => 'Sem certificação válida para: ' . implode(', ', $semCert) . '.'];
    } else {
        $certificacao = ['status' => 'ok', 'detalhe' => 'Certificação válida.'];
    }

    /* ── Gate 3: rastreabilidade (identidade preservada não mistura produtores) ── */
    $nProdutores = count($produtorIds);
    if ($temIP && $nProdutores > 1) {
        $rastreabilidade = ['status' => 'bloqueio',
            'detalhe' => 'Identidade preservada exige produtor único; há ' . $nProdutores
                . ' produtores distintos nesta recepção.'];
    } else {
        $rastreabilidade = ['status' => 'ok',
            'detalhe' => $temIP ? 'Identidade preservada com produtor único.' : 'Sem mistura proibida.'];
    }

    /* ── Gate 4: licença varietal por mercado ── */
    $mercadoCod = null;
    if ($mercadoId !== null) {
        $mc = vero_val("SELECT codigo FROM ph_mercados WHERE id = :i AND tenant_id = :t",
            [':i' => $mercadoId, ':t' => vero_tenant()]);
        $mercadoCod = $mc !== false && $mc !== null ? (string)$mc : null;
    }
    $licencaBloqueios = [];
    $temLicenciada = false;
    foreach ($variedadeIds as $vid) {
        $lics = vero_rows(
            "SELECT mercados_autorizados FROM ph_licencas_varietais
              WHERE tenant_id = :t AND variedade_id = :v AND status = 'ativo'",
            [':t' => vero_tenant(), ':v' => $vid]);
        if (!$lics) continue; /* variedade não-licenciada = ok (não restringe) */
        $temLicenciada = true;
        if ($mercadoId === null) continue; /* sem mercado não há como reprovar */
        $autorizado = false;
        foreach ($lics as $l) {
            $arr = json_decode((string)($l['mercados_autorizados'] ?? ''), true);
            if (!is_array($arr)) continue;
            $set = array_map(static fn($x) => (string)$x, $arr);
            if (in_array((string)$mercadoId, $set, true)
                || ($mercadoCod !== null && in_array($mercadoCod, $set, true))) {
                $autorizado = true;
                break;
            }
        }
        if (!$autorizado) {
            $licencaBloqueios[] = $variedadeNomes[$vid] ?? ('variedade #' . $vid);
        }
    }
    if ($licencaBloqueios) {
        $alvo = $mercadoCod ?? ('mercado #' . (string)$mercadoId);
        $licenca = ['status' => 'bloqueio',
            'detalhe' => 'Não licenciada(s) para ' . $alvo . ': ' . implode(', ', $licencaBloqueios) . '.'];
    } elseif ($mercadoId === null) {
        $licenca = ['status' => 'ok',
            'detalhe' => $temLicenciada ? 'Mercado não informado; licença não avaliada.'
                : 'Nenhuma variedade licenciada nesta carga.'];
    } else {
        $licenca = ['status' => 'ok', 'detalhe' => 'Variedades licenciadas para o mercado.'];
    }

    /* ── Gate 5: SO2 / relógio de frio (prioridade, não ilegal) ── */
    if ($piorColhido === null) {
        $so2 = ['status' => 'ok', 'detalhe' => 'sem hora de colheita'];
    } else {
        $rel = ph_relogio_status(date('Y-m-d H:i:s', $piorColhido));
        $h = $rel['horas'];
        if ($h !== null && $h > PH_SO2_JANELA_H) {
            $so2 = ['status' => 'aviso',
                'detalhe' => 'Fora da janela de SO2: ' . number_format((float)$h, 1, ',', '.')
                    . 'h desde a colheita (prioridade de descarga).'];
        } else {
            $so2 = ['status' => 'ok',
                'detalhe' => 'Dentro da janela de SO2 ('
                    . ($h !== null ? number_format((float)$h, 1, ',', '.') . 'h' : '—') . ').'];
        }
    }

    $bloqueia = in_array('bloqueio', [
        $carencia['status'], $certificacao['status'], $rastreabilidade['status'],
        $licenca['status'], $so2['status'],
    ], true);

    return [
        'carencia'        => $carencia,
        'certificacao'    => $certificacao,
        'rastreabilidade' => $rastreabilidade,
        'licenca'         => $licenca,
        'so2'             => $so2,
        'bloqueia'        => $bloqueia,
    ];
}

/* ───────────────────── Lote COLH- no aceite (get-or-create) ───────────────────── */

/**
 * Resolve o lote COLH- de RASTREABILIDADE do item no ACEITE da recepção
 * (gestor 19/08: item aceito sem lote → nunca aparece na etiqueta de caixa).
 * A carga pode chegar ao packing ANTES da entrada da colheita no estoque
 * (A1-42) e até SEM registro de colheita (app/web toleram registro_id NULL)
 * — mas a caixa PRECISA de lote (GlobalG.A.P.). Ordem:
 *   1) GET    — carga.registro_id → lote COLH- ATIVO (não-estornado) mais novo;
 *   2) CREATE — cria o lote de rastreabilidade (quantidade 0, custo 0,
 *      'disponivel') amarrado ao registro; quando o escritório confirmar a
 *      entrada, vero_srv_colheita_confirmar_entrada ADOTA este lote (mesmo
 *      código nas caixas e no estoque — uma identidade só por batch);
 *   3) carga SEM registro → deriva produto/almoxarifado da cultura do
 *      safra_talhao e cria com colheita_registro_id NULL (origem física
 *      preservada em talhão+data via carga/recepção).
 * Quantidade 0 não entra no FEFO nem vira saldo vendável — o lote aqui é
 * IDENTIDADE do batch; o saldo continua nascendo só na entrada oficial.
 * Devolve null quando não há como derivar produto/almoxarifado (não inventa
 * dado). Chamar dentro da transação do aceite. Sem mudança de schema.
 */
function ph_recepcao_lote_colh(?int $cargaId): ?int
{
    if ($cargaId === null) return null;
    $t = vero_tenant();
    $cc = vero_row(
        "SELECT registro_id, talhao_id, safra_talhao_id, data_carga
           FROM colheita_cargas WHERE id = :i AND tenant_id = :t",
        [':i' => $cargaId, ':t' => $t]);
    if (!$cc) return null;
    $registroId = ph_recep_int($cc['registro_id']);

    /* 1) GET — lote COLH- ativo da colheita vinculada à carga */
    if ($registroId !== null) {
        $id = vero_val(
            "SELECT id FROM estoque_lotes
              WHERE tenant_id = :t AND colheita_registro_id = :r
                AND codigo_lote LIKE 'COLH-%' AND status <> 'estornado'
              ORDER BY id DESC LIMIT 1", [':t' => $t, ':r' => $registroId]);
        if ($id) return (int)$id;
    }

    /* 2/3) CREATE — cadeia produto/almox pela colheita OU pelo safra_talhao */
    if ($registroId !== null) {
        $ch = vero_row(
            "SELECT cr.data_colheita AS data, cr.safra_talhao_id, cr.variedade_id,
                    cr.talhao_id, c.produto_estoque_colheita_id AS prod,
                    c.almoxarifado_colheita_id AS almox
               FROM colheita_registros cr
               JOIN agro_culturas c ON c.id = cr.cultura_id
              WHERE cr.id = :i AND cr.tenant_id = :t", [':i' => $registroId, ':t' => $t]);
    } else {
        $stId = ph_recep_int($cc['safra_talhao_id']);
        if ($stId === null) return null; /* sem registro E sem safra_talhao: sem cadeia */
        $ch = vero_row(
            "SELECT NULL AS data, st.id AS safra_talhao_id, NULL AS variedade_id,
                    st.talhao_id, c.produto_estoque_colheita_id AS prod,
                    c.almoxarifado_colheita_id AS almox
               FROM agro_safra_talhoes st
               JOIN agro_culturas c ON c.id = st.cultura_id
              WHERE st.id = :i AND st.tenant_id = :t", [':i' => $stId, ':t' => $t]);
    }
    if (!$ch) return null;
    $prod  = ph_recep_int($ch['prod']);
    $almox = ph_recep_int($ch['almox']);
    if ($prod === null || $almox === null) return null; /* cultura sem produto/almox de colheita */

    $data      = ph_recep_str($ch['data']) ?? ph_recep_str($cc['data_carga']) ?? date('Y-m-d');
    $talhaoId  = ph_recep_int($ch['talhao_id']) ?? ph_recep_int($cc['talhao_id']);
    $talhaoCod = 'SL';
    if ($talhaoId !== null) {
        $talhaoCod = (string)(vero_val("SELECT codigo FROM agro_talhoes WHERE id = :i AND tenant_id = :t",
            [':i' => $talhaoId, ':t' => $t]) ?: $talhaoId);
    }

    /* código COLH-AAAA-TALHAO-SEQ (mesmo formato da entrada oficial); a
       sequência anda até achar código livre — uq_lotes cobre a corrida */
    $seq = (int)vero_val("SELECT COUNT(*) FROM estoque_lotes
                           WHERE tenant_id = :t AND codigo_lote LIKE 'COLH-%'", [':t' => $t]) + 1;
    do {
        $codigo = 'COLH-' . substr($data, 0, 4) . '-' . strtoupper($talhaoCod)
                . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        $existe = vero_val("SELECT id FROM estoque_lotes WHERE tenant_id = :t AND codigo_lote = :c",
            [':t' => $t, ':c' => $codigo]);
        $seq++;
    } while ($existe);

    vero_pdo()->prepare(
        "INSERT INTO estoque_lotes (tenant_id, produto_id, almoxarifado_id, codigo_lote, validade,
                                    fornecedor_id, custo_unitario, quantidade, colheita_registro_id,
                                    safra_talhao_id, variedade_id, status, created_by, updated_by)
         VALUES (?,?,?,?,NULL,NULL,0,0,?,?,?, 'disponivel', ?, ?)")
        ->execute([$t, $prod, $almox, $codigo, $registroId,
                   ph_recep_int($ch['safra_talhao_id']), ph_recep_int($ch['variedade_id']),
                   vero_uid(), vero_uid()]);
    return (int)vero_pdo()->lastInsertId();
}

/* ───────────────────────── Gravação da recepção ───────────────────────── */

/**
 * Cria a recepção (cabeçalho + itens) numa transação. Grava gates_resultado
 * (JSON de ph_recepcao_gates). status = 'aceita' se !bloqueia, senão 'rejeitada'.
 * $header aceita: produtor_id, contrato_id, veiculo_placa, motorista,
 *   transportadora, chegou_em, iniciou_descarga_em, finalizou_descarga_em,
 *   peso_bruto_kg, peso_tara_kg, peso_liquido_kg, observacao, mercado_id.
 * @return int recepcao_id
 */
function ph_recepcao_criar(int $unidadeId, array $header, array $itens): int
{
    $mercadoId = ph_recep_int($header['mercado_id'] ?? null);
    $gates = ph_recepcao_gates($itens, $mercadoId);
    $status = !empty($gates['bloqueia']) ? 'rejeitada' : 'aceita';

    $bruto = ph_recep_num($header['peso_bruto_kg'] ?? null);
    $tara  = ph_recep_num($header['peso_tara_kg'] ?? null);
    $liq   = ph_recep_num($header['peso_liquido_kg'] ?? null);
    if ($liq === null && $bruto !== null && $tara !== null) $liq = $bruto - $tara;

    $pdo = vero_pdo();
    $jaEmTx = $pdo->inTransaction();
    if (!$jaEmTx) $pdo->beginTransaction();
    try {
        $recId = vero_insert('ph_recepcoes', [
            'unidade_id'            => $unidadeId,
            'numero'                => ph_recepcao_numero($unidadeId),
            'produtor_id'           => ph_recep_int($header['produtor_id'] ?? null),
            'contrato_id'           => ph_recep_int($header['contrato_id'] ?? null),
            'veiculo_placa'         => ph_recep_str($header['veiculo_placa'] ?? null),
            'motorista'             => ph_recep_str($header['motorista'] ?? null),
            'transportadora'        => ph_recep_str($header['transportadora'] ?? null),
            'chegou_em'             => ph_recep_str($header['chegou_em'] ?? null) ?? date('Y-m-d H:i:s'),
            'iniciou_descarga_em'   => ph_recep_str($header['iniciou_descarga_em'] ?? null),
            'finalizou_descarga_em' => ph_recep_str($header['finalizou_descarga_em'] ?? null),
            'peso_bruto_kg'         => $bruto,
            'peso_tara_kg'          => $tara,
            'peso_liquido_kg'       => $liq,
            'status'                => $status,
            'gates_resultado'       => json_encode($gates, JSON_UNESCAPED_UNICODE),
            'observacao'            => ph_recep_str($header['observacao'] ?? null),
        ]);

        foreach ($itens as $it) {
            $metodo = ($it['metodo_rastreabilidade'] ?? '') === 'identidade_preservada'
                ? 'identidade_preservada' : 'segregacao';
            $cargaId = ph_recep_int($it['colheita_carga_id'] ?? null);
            /* lote COLH- garantido no aceite (gestor 19/08): sem lote na carga
               → get-or-create de rastreabilidade; a etiqueta de caixa depende dele */
            $loteId = ph_recep_int($it['lote_estoque_id'] ?? null)
                ?? ph_recepcao_lote_colh($cargaId);
            vero_insert('ph_recepcao_itens', [
                'recepcao_id'           => $recId,
                'colheita_carga_id'     => $cargaId,
                'lote_estoque_id'       => $loteId,
                'talhao_id'             => ph_recep_int($it['talhao_id'] ?? null),
                'safra_talhao_id'       => ph_recep_int($it['safra_talhao_id'] ?? null),
                'variedade_id'          => ph_recep_int($it['variedade_id'] ?? null),
                'produtor_id'           => ph_recep_int($it['produtor_id'] ?? null),
                'colhido_em'            => ph_recep_str($it['colhido_em'] ?? null),
                'n_contentores'         => ph_recep_int($it['n_contentores'] ?? null),
                'peso_kg'               => ph_recep_num($it['peso_kg'] ?? null),
                'temperatura_chegada_c' => ph_recep_num($it['temperatura_chegada_c'] ?? null),
                'turma_colheita'        => ph_recep_str($it['turma_colheita'] ?? null),
                'metodo_rastreabilidade' => $metodo,
                'status'                => 'recebido',
            ]);
        }

        if (!$jaEmTx) $pdo->commit();
        return $recId;
    } catch (Throwable $e) {
        if (!$jaEmTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Elo embalamento → recepção (gestor 19/08): o posto de EMBALAMENTO passa a
 * conferir se existe recepção ACEITA no tenant cobrindo a data do posto —
 * sem isso a recepção vira burocracia pulável. Parâmetro (tenant_parametros):
 *   packing.embalamento_exige_recepcao = 'avisa' (padrão) | 'bloqueia' | '0'
 * Colheita fica FORA da exigência (a fruta ainda não chegou ao packing).
 * @return array{ok:bool, modo:string, msg:?string} ok=false só no bloqueio
 */
function ph_embalamento_recepcao_check(string $data): array
{
    $modo = (string)vero_srv_param('packing.embalamento_exige_recepcao', 'avisa');
    if ($modo !== 'avisa' && $modo !== 'bloqueia') {
        return ['ok' => true, 'modo' => $modo, 'msg' => null];
    }
    $tem = (int)vero_val(
        "SELECT COUNT(*) FROM ph_recepcoes
          WHERE tenant_id = :t AND status = 'aceita' AND DATE(chegou_em) = :d",
        [':t' => vero_tenant(), ':d' => $data]);
    if ($tem > 0) {
        return ['ok' => true, 'modo' => $modo, 'msg' => null];
    }
    $msg = 'Nenhuma recepção ACEITA em ' . date('d/m/Y', strtotime($data))
        . ' — receba a carga em Packing → Recepção antes de embalar.';
    return ['ok' => $modo !== 'bloqueia', 'modo' => $modo, 'msg' => $msg];
}

/* ───────────────────────── Fila do relógio ───────────────────────── */

/**
 * Itens recebidos da unidade, ordenados pelo MAIOR tempo desde a colheita
 * (mais urgente primeiro; sem hora de colheita vai para o fim). Cada item vem
 * com o estado do relógio em ['relogio'].
 * @return array<int, array<string, mixed>>
 */
function ph_relogio_fila(int $unidadeId): array
{
    $rows = vero_rows(
        "SELECT ri.*, r.numero AS recepcao_numero,
                t.nome AS talhao_nome, v.nome AS variedade_nome
           FROM ph_recepcao_itens ri
           JOIN ph_recepcoes r        ON r.id = ri.recepcao_id AND r.tenant_id = ri.tenant_id
           LEFT JOIN agro_talhoes t   ON t.id = ri.talhao_id AND t.tenant_id = ri.tenant_id
           LEFT JOIN agro_variedades v ON v.id = ri.variedade_id AND v.tenant_id = ri.tenant_id
          WHERE ri.tenant_id = :t AND r.unidade_id = :u AND ri.status = 'recebido'
          ORDER BY (ri.colhido_em IS NULL) ASC, ri.colhido_em ASC, ri.id ASC",
        [':t' => vero_tenant(), ':u' => $unidadeId]);

    foreach ($rows as &$r) {
        $r['relogio'] = ph_relogio_status($r['colhido_em'] ?? null);
    }
    unset($r);
    return $rows;
}
