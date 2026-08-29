<?php
/* ============================================================
   VERO — Financeiro / base compartilhada de Contas a Pagar/Receber
   Incluída por contas_pagar.php e contas_receber.php, que definem:
     $FIN_TIPO  = 'pagar' | 'receber'
     $FIN_MICRO = 'contas_pagar' | 'contas_receber'
     $FIN_TITULO, $FIN_SUB, $FIN_VIEW
   Regras: lançamentos manuais são criados aqui (hash-chain via
   vero_srv_fin_lancar); lançamentos com origem (ex.: venda) só têm
   baixa/estorno aqui — valor/cancelamento na tela de origem.
   A3-T1 (rodada 2): documento, forma de pagamento, PARCELAMENTO
   (cada parcela = movimentação própria, agrupada por grupo_id) e
   ANEXOS (agro_anexos, origem 'movimentacao_financeira' — DB-22).
   Edição de manual com campo SELADO alterado = cancelamento lógico
   + nova linha encadeada (razão INSERT-only, coerente com DB-23);
   só campos fora do hash mudam via UPDATE.
   G-10 (auditoria F&C 19/07): título manual agora carrega centro de
   custo, conta do plano (folha) e safra — colunas que JÁ existiam em
   movimentacoes_financeiras e ficam FORA do hash (vero_srv_fin_hash
   sela tipo/valor/datas/descrição/origem), logo gravá-las/alterá-las
   não quebra a cadeia. Rateio percentual opcional entre centros =
   2+ títulos filhos agrupados por grupo_id (sem migration).
   A3-UXO1B (auditoria UX 19/07, seções 5/8 — cockpit LITE, só-tela;
   a versão completa com fila de aprovação/alçadas espera G-09/G-06):
   1) filas no topo (cards .vkpi clicáveis: Vencidos / Vencem hoje /
      Próximos 7 dias / Sem vencimento (F-04) / Sem classificação) —
      clicar aplica o filtro via querystring (?status=vencido,
      ?venc=hoje|prox7|sem, ?semcc=1);
   2) badge de situação derivada ("Vencido há N dias") e CANCELADOS
      OCULTOS por padrão (toggle ?canc=1 ou filtro status=cancelado);
   3) rastreabilidade: título com origem ganha link "ver origem"
      (FIN_ORIGEM_ROTAS); tipos sem rota mostram o tipo cru em hint;
   4) ?novo=1 agora abre o modal também no SERVIDOR (class open) —
      antes dependia só do JS pós-load (vero_crud), único caminho
      plausível do relato "modal Novo não abriu" do auditor.
   08/2026: FAZENDA no título (migration 212) —
   select opcional no lançamento manual, filtro ?fz= na lista, nome
   na célula de classificação e no CSV. fazenda_id fica FORA do hash
   (mesma família de safra/válvula/centro) e, como conta_bancaria_id,
   fora do contrato do vero_srv_fin_lancar → grava via UPDATE
   pós-insert (fin_g10_set_conta_bancaria) na mesma transação.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_fin_alertas.php';

$permBase = 'financeiro.' . $FIN_MICRO;

/* Formas de pagamento (lista provisória — P-38 valida com o cliente) */
const FIN_FORMAS = [
    'pix'           => 'PIX',
    'boleto'        => 'Boleto',
    'transferencia' => 'Transferência',
    'dinheiro'      => 'Dinheiro',
    'cheque'        => 'Cheque',
    'cartao'        => 'Cartão',
    'outro'         => 'Outro',
];
const FIN_ANEXO_EXT = ['pdf', 'jpg', 'jpeg', 'png'];
const FIN_MAX_PARCELAS = 36;

/* Cockpit LITE — rastreabilidade: rota da tela de origem por origem_tipo
   (%d = origem_id). Tipos reais hoje no banco: comercial_venda (receber)
   e compras_recebimento (pagar). Recebimento não tem visão por id — cai
   na lista da tela. Tipo sem rota mapeada: badge com o tipo cru + hint. */
const FIN_ORIGEM_ROTAS = [
    'comercial_venda'     => '/comercial/vendas?editar=%d',
    'compras_recebimento' => '/compras/recebimentos',
];

/**
 * A3-T14: venda PARCELADA (títulos sem origem, agrupados por grupo_id
 * apontado em comercial_vendas.movimentacao_id) — o status_pagamento da
 * venda acompanha as parcelas: todas pagas → pago (data da última);
 * qualquer aberta → pendente. Vendas à vista seguem pelo service
 * (origem comercial_venda). Chamar após baixa/estorno.
 */
function fin_sync_venda_parcelada(int $movId): void
{
    $t = vero_tenant();
    $mov = vero_row("SELECT grupo_id, origem_tipo FROM movimentacoes_financeiras WHERE id=:i AND tenant_id=:t",
        [':i' => $movId, ':t' => $t]);
    if (!$mov || $mov['grupo_id'] === null || $mov['origem_tipo'] !== null) return;

    $venda = vero_row(
        "SELECT id, status, status_pagamento FROM comercial_vendas
          WHERE tenant_id = :t AND movimentacao_id = :g",
        [':t' => $t, ':g' => (int)$mov['grupo_id']]);
    if (!$venda || $venda['status'] === 'cancelada') return;

    $resumo = vero_row(
        "SELECT SUM(status = 'aberto') AS abertas,
                SUM(status = 'pago')   AS pagas,
                MAX(CASE WHEN status = 'pago' THEN data_pagamento END) AS ultima_baixa
           FROM movimentacoes_financeiras
          WHERE tenant_id = :t AND grupo_id = :g AND origem_tipo IS NULL AND status <> 'cancelado'",
        [':t' => $t, ':g' => (int)$mov['grupo_id']]);

    if ((int)$resumo['abertas'] === 0 && (int)$resumo['pagas'] > 0) {
        vero_update('comercial_vendas', (int)$venda['id'],
            ['status_pagamento' => 'pago', 'data_pagamento' => $resumo['ultima_baixa']]);
    } elseif ($venda['status_pagamento'] === 'pago') {
        vero_update('comercial_vendas', (int)$venda['id'],
            ['status_pagamento' => 'pendente', 'data_pagamento' => null]);
    }
}

/**
 * G-10 + FD-05: lê e valida a classificação OPCIONAL do título manual.
 * Retorna [centro_custo_id, plano_conta_id, safra_id, conta_bancaria_id,
 * talhao_id (válvula), fazenda_id] (null = sem valor). Campos fora do hash —
 * a validação não trava o fluxo simples: vazio passa.
 * @throws RuntimeException se um id informado for inválido para o tenant.
 */
function fin_g10_classificacao(string $finTipo): array
{
    $t = vero_tenant();
    $centroId = vero_int('centro_custo_id');
    $planoId  = vero_int('plano_conta_id');
    $safraId  = vero_int('safra_id');
    $ctaBanc  = vero_int('conta_bancaria_id');
    $valvulaId = vero_int('talhao_id');   /* Z-07: válvula = agro_talhoes.id */
    $fazendaId = vero_int('fazenda_id');  /* pedido do gestor 08/2026: fazenda no título */
    if ($centroId !== null && !vero_val(
            "SELECT id FROM centros_custo WHERE id=:i AND tenant_id=:t AND ativo=1",
            [':i' => $centroId, ':t' => $t])) {
        throw new RuntimeException('Centro de custo inválido ou inativo.');
    }
    if ($planoId !== null) {
        $tipoConta = $finTipo === 'receber' ? 'receita' : 'despesa';
        $pc = vero_row("SELECT tipo, aceita_lancamento FROM plano_contas WHERE id=:i AND tenant_id=:t AND ativo=1",
            [':i' => $planoId, ':t' => $t]);
        if (!$pc || (int)$pc['aceita_lancamento'] !== 1 || (string)$pc['tipo'] !== $tipoConta) {
            throw new RuntimeException("Conta do plano inválida — selecione uma conta analítica (folha) de {$tipoConta}, ativa.");
        }
    }
    if ($safraId !== null && !vero_val("SELECT id FROM agro_safras WHERE id=:i AND tenant_id=:t",
            [':i' => $safraId, ':t' => $t])) {
        throw new RuntimeException('Safra inválida.');
    }
    if ($ctaBanc !== null && !vero_val(
            "SELECT id FROM contas_bancarias WHERE id=:i AND tenant_id=:t AND ativo=1",
            [':i' => $ctaBanc, ':t' => $t])) {
        throw new RuntimeException('Conta bancária inválida ou inativa.');
    }
    if ($valvulaId !== null && !vero_val(
            "SELECT id FROM agro_talhoes WHERE id=:i AND tenant_id=:t AND ativo=1",
            [':i' => $valvulaId, ':t' => $t])) {
        throw new RuntimeException('Válvula inválida ou inativa.');
    }
    if ($fazendaId !== null && !vero_val(
            "SELECT id FROM agro_fazendas WHERE id=:i AND tenant_id=:t AND ativo=1",
            [':i' => $fazendaId, ':t' => $t])) {
        throw new RuntimeException('Fazenda inválida ou inativa.');
    }
    return [$centroId, $planoId, $safraId, $ctaBanc, $valvulaId, $fazendaId];
}

/**
 * FD-05 (LCDPR 1.3): conta_bancaria_id está FORA do hash, mas também fora
 * do contrato de INSERT do vero_srv_fin_lancar (contrato A0 — não editamos
 * o service). Gravação via UPDATE pós-lançamento, na MESMA transação —
 * legítimo: campos fora do hash mudam por UPDATE sem recálculo (DB-21).
 * fazenda_id (08/2026) segue a mesma regra: fora do hash e fora do
 * contrato do service, então entra por aqui em todos os caminhos de INSERT.
 */
function fin_g10_set_conta_bancaria(int $movId, ?int $ctaBanc, ?int $fazendaId = null): void
{
    $extras = [];
    if ($ctaBanc !== null)   $extras['conta_bancaria_id'] = $ctaBanc;
    if ($fazendaId !== null) $extras['fazenda_id'] = $fazendaId;
    if ($extras) {
        vero_update('movimentacoes_financeiras', $movId, $extras);
    }
}

/**
 * G-10: rateio percentual entre centros de custo (opcional, só criação).
 * Linhas rateio_centro[]/rateio_perc[]; vazio => []. Com 2+ linhas válidas
 * somando 100%, cada centro vira um TÍTULO próprio (grupo_id em comum) —
 * sem migration; a soma em centavos fecha exata (resto na última linha).
 * @return array<int, array{centro_id:int, perc:float, codigo:string}>
 * @throws RuntimeException em rateio malformado.
 */
function fin_g10_rateio(): array
{
    $ids   = $_POST['rateio_centro'] ?? [];
    $percs = $_POST['rateio_perc']   ?? [];
    if (!is_array($ids) || !is_array($percs)) return [];
    $t = vero_tenant();
    $linhas = [];
    $vistos = [];
    foreach (array_values($ids) as $i => $cid) {
        $cid  = (int)$cid;
        $perc = (float)str_replace(',', '.', trim((string)($percs[$i] ?? '')));
        if ($cid <= 0 && $perc <= 0) continue;                     /* linha em branco */
        if ($cid <= 0 || $perc <= 0) {
            throw new RuntimeException('Rateio: informe centro E percentual em todas as linhas preenchidas.');
        }
        if (isset($vistos[$cid])) throw new RuntimeException('Rateio: centro de custo repetido.');
        $cc = vero_row("SELECT codigo FROM centros_custo WHERE id=:i AND tenant_id=:t AND ativo=1",
            [':i' => $cid, ':t' => $t]);
        if (!$cc) throw new RuntimeException('Rateio: centro de custo inválido ou inativo.');
        $vistos[$cid] = true;
        $linhas[] = ['centro_id' => $cid, 'perc' => $perc, 'codigo' => (string)$cc['codigo']];
    }
    if (!$linhas) return [];
    if (count($linhas) < 2) {
        throw new RuntimeException('Rateio exige 2 ou mais centros — para um único centro use o campo "Centro de custo".');
    }
    $soma = array_sum(array_column($linhas, 'perc'));
    if (abs($soma - 100.0) > 0.01) {
        throw new RuntimeException('Rateio: os percentuais devem somar 100% (soma atual: ' . numFmt($soma, 2) . '%).');
    }
    return $linhas;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require($permBase . '.editar');

        $id        = vero_int('id');
        $descricao = vero_str('descricao', 255);
        $valor     = vero_dec('valor');
        $documento = vero_str('documento', 40);
        $forma     = (string)($_POST['forma_pagamento'] ?? '');
        if (!isset(FIN_FORMAS[$forma])) $forma = null;

        if ($descricao === null || $valor === null || $valor <= 0) {
            vero_flash('erro', 'Descrição e valor (maior que zero) são obrigatórios.');
            vero_redirect();
        }
        $competencia = vero_date('data_competencia');
        $vencimento  = vero_date('data_vencimento');
        /* F-04 (auditoria 19/07): título manual NOVO exige vencimento — sem
           ele o valor cai no bucket "Sem vencimento" do fluxo de caixa e não
           entra na projeção mensal. Legado sem vencimento continua válido. */
        if (!$id && $vencimento === null) {
            vero_flash('erro', 'Data de vencimento é obrigatória para novo lançamento (sem ela o título fica fora da projeção mensal do fluxo de caixa).');
            vero_redirect();
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            /* G-10/FD-05: classificação opcional (fora do hash) + rateio (só criação) */
            [$centroId, $planoId, $safraId, $ctaBanc, $valvulaId, $fazendaId] = fin_g10_classificacao($FIN_TIPO);
            $rateio = $id ? [] : fin_g10_rateio();

            if ($id) {
                $mov = vero_row("SELECT * FROM movimentacoes_financeiras WHERE id=:i AND tenant_id=:t AND tipo=:tp",
                    [':i' => $id, ':t' => vero_tenant(), ':tp' => $FIN_TIPO]);
                if (!$mov) throw new RuntimeException('Lançamento inválido.');
                if ($mov['origem_tipo'] !== null) {
                    throw new RuntimeException('Lançamento gerado por ' . $mov['origem_tipo'] . ' — edite na tela de origem.');
                }
                if ($mov['status'] !== 'aberto') throw new RuntimeException('Só lançamentos em aberto podem ser editados.');

                $seladoIgual =
                    number_format((float)$mov['valor'], 2, '.', '') === number_format((float)$valor, 2, '.', '')
                    && (string)($mov['data_competencia'] ?? '') === (string)($competencia ?? '')
                    && (string)($mov['data_vencimento'] ?? '')  === (string)($vencimento ?? '')
                    && (string)($mov['descricao'] ?? '')        === (string)$descricao;

                if ($seladoIgual) {
                    /* só campos fora do hash mudaram (G-10: classificação incluída) */
                    vero_update('movimentacoes_financeiras', $id,
                        ['documento' => $documento, 'forma_pagamento' => $forma,
                         'centro_custo_id' => $centroId, 'plano_conta_id' => $planoId,
                         'safra_id' => $safraId, 'talhao_id' => $valvulaId,
                         'fazenda_id' => $fazendaId, 'conta_bancaria_id' => $ctaBanc]);
                    $msg = 'Lançamento atualizado.';
                } else {
                    /* campo selado mudou: cancela e reemite (razão INSERT-only — DB-23) */
                    vero_update('movimentacoes_financeiras', $id, ['status' => 'cancelado']);
                    $novaId = vero_srv_fin_lancar([
                        'tipo' => $FIN_TIPO, 'descricao' => $descricao, 'valor' => $valor,
                        'data_competencia' => $competencia, 'data_vencimento' => $vencimento,
                        'documento' => $documento, 'forma_pagamento' => $forma,
                        'parcela_num' => $mov['parcela_num'], 'parcela_total' => $mov['parcela_total'],
                        'grupo_id' => $mov['grupo_id'],
                        'centro_custo_id' => $centroId, 'plano_conta_id' => $planoId,
                        'safra_id' => $safraId, 'talhao_id' => $valvulaId,
                    ]);
                    fin_g10_set_conta_bancaria($novaId, $ctaBanc, $fazendaId);
                    vero_update('movimentacoes_financeiras', $id, ['substituida_por_id' => $novaId]);
                    $msg = 'Lançamento reemitido (linha anterior cancelada — o razão não sofre UPDATE de valor).';
                }
            } else {
                $parcelas = max(1, min(FIN_MAX_PARCELAS, (int)($_POST['parcelas'] ?? 1)));
                if ($rateio && $parcelas > 1) {
                    throw new RuntimeException('Rateio e parcelamento juntos não são suportados — lance parcelas OU rateio.');
                }
                $base = [
                    'tipo' => $FIN_TIPO, 'documento' => $documento, 'forma_pagamento' => $forma,
                    'data_competencia' => $competencia,
                    'centro_custo_id' => $centroId, 'plano_conta_id' => $planoId,
                    'safra_id' => $safraId, 'talhao_id' => $valvulaId,
                ];
                if ($rateio) {
                    /* G-10: um título por centro (soma exata em centavos; resto na última) */
                    $centTotal = (int)round($valor * 100);
                    $acum = 0;
                    $grupoId = null;
                    $n = count($rateio);
                    foreach ($rateio as $i => $r) {
                        $cent = $i === $n - 1
                            ? $centTotal - $acum
                            : (int)round($centTotal * $r['perc'] / 100);
                        if ($cent < 1) throw new RuntimeException("Rateio: o percentual de {$r['codigo']} resulta em valor zero.");
                        $acum += $cent;
                        $pid = vero_srv_fin_lancar(array_merge($base, [
                            'descricao' => $descricao . ' (rateio ' . numFmt($r['perc'], 2) . '% ' . $r['codigo'] . ')',
                            'valor' => $cent / 100, 'data_vencimento' => $vencimento,
                            'centro_custo_id' => $r['centro_id'], 'grupo_id' => $grupoId,
                        ]));
                        fin_g10_set_conta_bancaria($pid, $ctaBanc, $fazendaId);
                        if ($grupoId === null) {
                            $grupoId = $pid;
                            vero_update('movimentacoes_financeiras', $pid, ['grupo_id' => $grupoId]);
                        }
                    }
                    $msg = "Lançamento criado com rateio em {$n} centro(s) de custo — um título por centro.";
                } elseif ($parcelas === 1) {
                    $novaId = vero_srv_fin_lancar($base + [
                        'descricao' => $descricao, 'valor' => $valor, 'data_vencimento' => $vencimento,
                    ]);
                    fin_g10_set_conta_bancaria($novaId, $ctaBanc, $fazendaId);
                    $msg = 'Lançamento criado.';
                } else {
                    /* parcelamento: cada parcela é uma movimentação própria (hash
                       por linha); valores em centavos p/ fechar a soma exata;
                       vencimentos mensais a partir do 1º; grupo_id = id da 1ª */
                    if ($vencimento === null) throw new RuntimeException('Informe o vencimento da 1ª parcela.');
                    $centTotal = (int)round($valor * 100);
                    $centBase  = intdiv($centTotal, $parcelas);
                    $grupoId = null;
                    for ($i = 1; $i <= $parcelas; $i++) {
                        $cent = $i === $parcelas ? $centTotal - $centBase * ($parcelas - 1) : $centBase;
                        $venc = date('Y-m-d', strtotime($vencimento . ' +' . ($i - 1) . ' month'));
                        $pid = vero_srv_fin_lancar($base + [
                            'descricao' => $descricao . ' (parcela ' . $i . '/' . $parcelas . ')',
                            'valor' => $cent / 100, 'data_vencimento' => $venc,
                            'parcela_num' => $i, 'parcela_total' => $parcelas,
                            'grupo_id' => $grupoId,
                        ]);
                        fin_g10_set_conta_bancaria($pid, $ctaBanc, $fazendaId);
                        if ($grupoId === null) {
                            $grupoId = $pid;
                            vero_update('movimentacoes_financeiras', $pid, ['grupo_id' => $grupoId]);
                        }
                    }
                    $msg = "Lançamento criado em {$parcelas} parcela(s).";
                }
            }
            $pdo->commit();
            vero_flash('ok', $msg);
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'baixar') {
        vero_require($permBase . '.editar');
        $id = vero_int('id');
        $dataPg = vero_date('data_pagamento') ?? date('Y-m-d');
        $forma = (string)($_POST['forma_pagamento'] ?? '');
        try {
            $ok = vero_val("SELECT id FROM movimentacoes_financeiras WHERE id=:i AND tenant_id=:t AND tipo=:tp",
                [':i' => (int)$id, ':t' => vero_tenant(), ':tp' => $FIN_TIPO]);
            if (!$ok) throw new RuntimeException('Lançamento inválido.');
            if (isset(FIN_FORMAS[$forma])) { /* forma da baixa (fora do hash) */
                vero_update('movimentacoes_financeiras', (int)$id, ['forma_pagamento' => $forma]);
            }
            vero_srv_fin_baixar((int)$id, $dataPg);
            fin_sync_venda_parcelada((int)$id);
            vero_flash('ok', 'Baixa registrada em ' . date('d/m/Y', strtotime($dataPg)) . '.');
        } catch (Throwable $e) {
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'estornar') {
        vero_require($permBase . '.editar');
        $id = vero_int('id');
        try {
            $ok = vero_val("SELECT id FROM movimentacoes_financeiras WHERE id=:i AND tenant_id=:t AND tipo=:tp",
                [':i' => (int)$id, ':t' => vero_tenant(), ':tp' => $FIN_TIPO]);
            if (!$ok) throw new RuntimeException('Lançamento inválido.');
            vero_srv_fin_estornar_baixa((int)$id);
            fin_sync_venda_parcelada((int)$id);
            vero_flash('ok', 'Baixa estornada — lançamento reaberto.');
        } catch (Throwable $e) {
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'anexar') { /* DB-22: anexos em contas */
        vero_require($permBase . '.editar');
        $id = vero_int('id');
        $mov = $id ? vero_row("SELECT * FROM movimentacoes_financeiras WHERE id=:i AND tenant_id=:t AND tipo=:tp",
            [':i' => $id, ':t' => vero_tenant(), ':tp' => $FIN_TIPO]) : null;
        $tipoDoc = vero_str('tipo_doc', 20) ?? 'documento';
        $file = $_FILES['arquivo'] ?? null;
        $volta = '?anexos=' . (int)$id;

        if (!$mov || $mov['status'] === 'cancelado' || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            vero_flash('erro', 'Selecione um arquivo válido para anexar (lançamento não pode estar cancelado).');
            vero_redirect(BIOS_BASE . '/financeiro/' . $FIN_MICRO . '.php' . $volta);
        }
        $maxBytes = (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 5242880);
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, FIN_ANEXO_EXT, true) || (int)$file['size'] > $maxBytes) {
            vero_flash('erro', 'Arquivo inválido: aceite apenas PDF/JPG/PNG até ' . round($maxBytes / 1048576, 1) . ' MB.');
            vero_redirect(BIOS_BASE . '/financeiro/' . $FIN_MICRO . '.php' . $volta);
        }
        if (!vero_upload_conteudo_ok((string)$file['tmp_name'], $ext)) {
            vero_flash('erro', 'O conteúdo do arquivo não corresponde a um PDF ou imagem válido. Envie o arquivo original.');
            vero_redirect(BIOS_BASE . '/financeiro/' . $FIN_MICRO . '.php' . $volta);
        }
        $dir = dirname(__DIR__) . '/storage/uploads/financeiro/' . vero_tenant();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nomeFisico = 'mov' . $id . '_' . $tipoDoc . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destino = $dir . '/' . $nomeFisico;
        if (!move_uploaded_file((string)$file['tmp_name'], $destino)) {
            vero_flash('erro', 'Falha ao gravar o arquivo no servidor.');
            vero_redirect(BIOS_BASE . '/financeiro/' . $FIN_MICRO . '.php' . $volta);
        }
        vero_insert('agro_anexos', [
            'origem_tipo'   => 'movimentacao_financeira',
            'origem_id'     => (int)$id,
            'tipo_arquivo'  => $tipoDoc,
            'nome_original' => mb_substr((string)$file['name'], 0, 255),
            'url'           => '/storage/uploads/financeiro/' . vero_tenant() . '/' . $nomeFisico,
            'tamanho_bytes' => (int)$file['size'],
            'hash_sha256'   => hash_file('sha256', $destino),
        ]);
        vero_flash('ok', 'Anexo "' . h((string)$file['name']) . '" gravado.');
        vero_redirect(BIOS_BASE . '/financeiro/' . $FIN_MICRO . '.php' . $volta);
    }

    if ($acao === 'excluir_anexo') {
        vero_require($permBase . '.editar');
        $anexoId = vero_int('anexo_id');
        $id = vero_int('id');
        $anexo = $anexoId ? vero_row(
            "SELECT * FROM agro_anexos WHERE id=:i AND tenant_id=:t AND origem_tipo='movimentacao_financeira'",
            [':i' => $anexoId, ':t' => vero_tenant()]) : null;
        if ($anexo) {
            $arquivo = dirname(__DIR__) . $anexo['url'];
            if (is_file($arquivo)) unlink($arquivo);
            vero_pdo()->prepare("DELETE FROM agro_anexos WHERE tenant_id=? AND id=?")
                ->execute([vero_tenant(), $anexoId]);
            vero_flash('ok', 'Anexo removido.');
        }
        vero_redirect(BIOS_BASE . '/financeiro/' . $FIN_MICRO . '.php?anexos=' . (int)$id);
    }

    if ($acao === 'excluir') { /* cancelamento lógico — o razão nunca apaga linhas */
        vero_require($permBase . '.excluir');
        $id = vero_int('id');
        $mov = $id ? vero_row("SELECT * FROM movimentacoes_financeiras WHERE id=:i AND tenant_id=:t AND tipo=:tp",
            [':i' => $id, ':t' => vero_tenant(), ':tp' => $FIN_TIPO]) : null;
        if ($mov) {
            if ($mov['origem_tipo'] !== null) {
                vero_flash('erro', 'Lançamento gerado por ' . $mov['origem_tipo'] . ' — cancele na tela de origem.');
            } elseif ($mov['status'] === 'pago') {
                vero_flash('erro', 'Estorne a baixa antes de cancelar.');
            } else {
                vero_update('movimentacoes_financeiras', (int)$id, ['status' => 'cancelado']);
                vero_flash('ok', 'Lançamento cancelado (linha preservada no razão).');
            }
        }
        vero_redirect();
    }
}

/* Alertas de vencimento (categoria financeiro): "vencido" muda com o
   tempo, então a reemissão idempotente roda na carga da tela (os POSTs
   redirecionam para cá — PRG — e ficam cobertos). */
fin_reemitir_alertas_vencimento();

/* ── Listagem ───────────────────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$fStatus = (string)($_GET['status'] ?? '');
$fCC     = max(0, (int)($_GET['cc'] ?? 0));   /* G-10: filtro por centro de custo */
$fCB     = max(0, (int)($_GET['cb'] ?? 0));   /* FD-05: filtro por conta bancária */
$fFaz    = max(0, (int)($_GET['fz'] ?? 0));   /* 08/2026: filtro por fazenda */
/* Cockpit LITE: filtros das filas (?venc=hoje|prox7|sem, ?semcc=1) e
   toggle de cancelados (?canc=1 — ocultos por padrão) */
$fVenc = (string)($_GET['venc'] ?? '');
if (!in_array($fVenc, ['hoje', 'prox7', 'sem'], true)) $fVenc = '';
$fSemCC  = (string)($_GET['semcc'] ?? '') === '1';
$verCanc = (string)($_GET['canc'] ?? '') === '1';
/* 25/08: recorte por PERÍODO do VENCIMENTO — dia, semana
   (seg–dom) ou mês da data de referência (?ref=, padrão hoje). Convive com as
   filas do cockpit (?venc=) por AND; título sem vencimento fica fora. */
$fPer = (string)($_GET['per'] ?? '');
if (!in_array($fPer, ['dia', 'semana', 'mes'], true)) $fPer = '';
$fRef = (string)($_GET['ref'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fRef) || strtotime($fRef) === false) $fRef = date('Y-m-d');
$perIni = $perFim = null;
if ($fPer === 'dia') {
    $perIni = $perFim = $fRef;
} elseif ($fPer === 'semana') {
    $perIni = date('Y-m-d', strtotime($fRef . ' -' . ((int)date('N', strtotime($fRef)) - 1) . ' day'));
    $perFim = date('Y-m-d', strtotime($perIni . ' +6 day'));
} elseif ($fPer === 'mes') {
    $perIni = date('Y-m-01', strtotime($fRef));
    $perFim = date('Y-m-t', strtotime($fRef));
}
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

$where  = "m.tenant_id = :t AND m.tipo = :tp";
$params = [':t' => vero_tenant(), ':tp' => $FIN_TIPO];
if ($q !== '') {
    $where .= " AND (m.descricao LIKE :q OR m.documento LIKE :q2)";
    $params[':q'] = "%{$q}%";
    $params[':q2'] = "%{$q}%";
}
if (in_array($fStatus, ['aberto', 'pago', 'cancelado'], true)) {
    $where .= " AND m.status = :st";
    $params[':st'] = $fStatus;
} elseif ($fStatus === 'vencido') {
    $where .= " AND m.status = 'aberto' AND m.data_vencimento IS NOT NULL AND m.data_vencimento < CURDATE()";
}
/* Cockpit LITE: filas por vencimento e por falta de classificação —
   todas são recortes do ABERTO (o clique no card vem sem ?status=) */
if ($fVenc === 'hoje') {
    $where .= " AND m.status = 'aberto' AND m.data_vencimento = CURDATE()";
} elseif ($fVenc === 'prox7') {
    $where .= " AND m.status = 'aberto' AND m.data_vencimento > CURDATE()
                AND m.data_vencimento <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($fVenc === 'sem') {
    $where .= " AND m.status = 'aberto' AND m.data_vencimento IS NULL";
}
if ($fSemCC) {
    $where .= " AND m.status = 'aberto' AND m.centro_custo_id IS NULL";
}
/* Cancelados OCULTOS por padrão (antes se misturavam à lista — achado da
   auditoria): só aparecem via filtro status=cancelado ou toggle ?canc=1. */
if ($fStatus !== 'cancelado' && !$verCanc) {
    $where .= " AND m.status <> 'cancelado'";
}
if ($fCC > 0) {
    $where .= " AND m.centro_custo_id = :cc";
    $params[':cc'] = $fCC;
}
if ($fCB > 0) {
    $where .= " AND m.conta_bancaria_id = :cb";
    $params[':cb'] = $fCB;
}
if ($fFaz > 0) {
    $where .= " AND m.fazenda_id = :fz";
    $params[':fz'] = $fFaz;
}
if ($perIni !== null) { /* 25/08: período do vencimento — CSV e soma usam o mesmo WHERE */
    $where .= " AND m.data_vencimento IS NOT NULL AND m.data_vencimento BETWEEN :pini AND :pfim";
    $params[':pini'] = $perIni;
    $params[':pfim'] = $perFim;
}

/* ── Exportar CSV (antes de qualquer HTML) ────────────────────────
   Baixa a MESMA lista filtrada da tela (fonte idêntica — mesmo WHERE e
   mesma ordenação, sem a paginação). Reusa o helper compartilhado
   vero_csv_stream (compras/_export.php); como o download roda antes do
   agro_header, o guard canônico é chamado à mão (mesma proteção da tela).
   O slug do arquivo é o próprio micro (contas_pagar/contas_receber). */
if (($_GET['csv'] ?? '') === '1') {
    require_once __DIR__ . '/../includes/menu_agro.php';
    bios_guard('financeiro', $FIN_MICRO);
    require_once __DIR__ . '/../compras/_export.php';
    $rowsCsv = vero_rows(
        "SELECT m.descricao, m.documento, m.origem_tipo,
                cc.codigo AS cc_codigo, pc.codigo AS pc_codigo, cb.nome AS cb_nome,
                fz.nome AS fz_nome,
                m.valor, m.forma_pagamento, m.data_vencimento, m.data_pagamento, m.status
           FROM movimentacoes_financeiras m
           LEFT JOIN centros_custo    cc ON cc.id = m.centro_custo_id   AND cc.tenant_id = m.tenant_id
           LEFT JOIN plano_contas     pc ON pc.id = m.plano_conta_id    AND pc.tenant_id = m.tenant_id
           LEFT JOIN contas_bancarias cb ON cb.id = m.conta_bancaria_id AND cb.tenant_id = m.tenant_id
           LEFT JOIN agro_fazendas    fz ON fz.id = m.fazenda_id        AND fz.tenant_id = m.tenant_id
          WHERE {$where}
          ORDER BY (m.status = 'aberto') DESC, m.data_vencimento IS NULL, m.data_vencimento, m.id DESC",
        $params);
    $hojeCsv = date('Y-m-d');
    foreach ($rowsCsv as &$rCsv) {
        $rCsv['origem_tipo'] = $rCsv['origem_tipo'] !== null
            ? str_replace('_', ' ', (string)$rCsv['origem_tipo']) : 'manual';
        $rCsv['forma_pagamento'] = $rCsv['forma_pagamento'] !== null
            ? (FIN_FORMAS[$rCsv['forma_pagamento']] ?? (string)$rCsv['forma_pagamento']) : '';
        $st = (string)$rCsv['status'];
        if ($st === 'aberto' && $rCsv['data_vencimento'] !== null && $rCsv['data_vencimento'] < $hojeCsv) {
            $st = 'vencido';
        }
        $rCsv['status'] = ['aberto' => 'Aberto', 'pago' => 'Pago', 'cancelado' => 'Cancelado', 'vencido' => 'Vencido'][$st] ?? $st;
    }
    unset($rCsv);
    $colunas = [
        'descricao'       => 'Descrição',
        'documento'       => 'Documento',
        'origem_tipo'     => 'Origem',
        'cc_codigo'       => 'Centro de custo',
        'pc_codigo'       => 'Conta do plano',
        'fz_nome'         => 'Fazenda',
        'valor'           => 'Valor (R$)',
        'forma_pagamento' => 'Forma de pagamento',
        'data_vencimento' => 'Vencimento',
        'data_pagamento'  => 'Pagamento',
        'cb_nome'         => 'Conta bancária',
        'status'          => 'Status',
    ];
    $formato = ['valor' => 'dec2', 'data_vencimento' => 'data', 'data_pagamento' => 'data'];
    vero_csv_stream('financeiro', $FIN_MICRO, $rowsCsv, $colunas, $formato);
}

$total = (int)vero_val("SELECT COUNT(*) FROM movimentacoes_financeiras m WHERE {$where}", $params);
$rows = vero_rows(
    "SELECT m.*,
            cc.codigo AS cc_codigo, cc.nome AS cc_nome,
            pc.codigo AS pc_codigo, pc.nome AS pc_nome,
            cb.nome AS cb_nome, fz.nome AS fz_nome,
            (SELECT COUNT(*) FROM agro_anexos ax
              WHERE ax.tenant_id = m.tenant_id AND ax.origem_tipo = 'movimentacao_financeira'
                AND ax.origem_id = m.id) AS anexos
       FROM movimentacoes_financeiras m
       LEFT JOIN centros_custo    cc ON cc.id = m.centro_custo_id   AND cc.tenant_id = m.tenant_id
       LEFT JOIN plano_contas     pc ON pc.id = m.plano_conta_id    AND pc.tenant_id = m.tenant_id
       LEFT JOIN contas_bancarias cb ON cb.id = m.conta_bancaria_id AND cb.tenant_id = m.tenant_id
       LEFT JOIN agro_fazendas    fz ON fz.id = m.fazenda_id        AND fz.tenant_id = m.tenant_id
      WHERE {$where}
      ORDER BY (m.status = 'aberto') DESC, m.data_vencimento IS NULL, m.data_vencimento, m.id DESC
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params);

/* ── Cockpit LITE: contadores das filas (sempre do universo inteiro do
   tipo no tenant — independem dos filtros ativos na lista) ─────────── */
$filas = vero_row(
    "SELECT
        SUM(status='aberto' AND data_vencimento IS NOT NULL AND data_vencimento < CURDATE()) AS n_vencidos,
        COALESCE(SUM(CASE WHEN status='aberto' AND data_vencimento IS NOT NULL AND data_vencimento < CURDATE() THEN valor END),0) AS v_vencidos,
        SUM(status='aberto' AND data_vencimento = CURDATE()) AS n_hoje,
        COALESCE(SUM(CASE WHEN status='aberto' AND data_vencimento = CURDATE() THEN valor END),0) AS v_hoje,
        SUM(status='aberto' AND data_vencimento > CURDATE()
            AND data_vencimento <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS n_prox7,
        COALESCE(SUM(CASE WHEN status='aberto' AND data_vencimento > CURDATE()
            AND data_vencimento <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN valor END),0) AS v_prox7,
        SUM(status='aberto' AND data_vencimento IS NULL) AS n_semv,
        COALESCE(SUM(CASE WHEN status='aberto' AND data_vencimento IS NULL THEN valor END),0) AS v_semv,
        SUM(status='aberto' AND centro_custo_id IS NULL) AS n_semcc,
        COALESCE(SUM(CASE WHEN status='aberto' AND centro_custo_id IS NULL THEN valor END),0) AS v_semcc
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t AND tipo = :tp",
    [':t' => vero_tenant(), ':tp' => $FIN_TIPO]) ?: [];

/* fila ativa (realce do card + clique repetido limpa o filtro) */
$filaAtiva = '';
if ($fSemCC)                    $filaAtiva = 'semcc';
elseif ($fVenc === 'hoje')      $filaAtiva = 'hoje';
elseif ($fVenc === 'prox7')     $filaAtiva = 'prox7';
elseif ($fVenc === 'sem')       $filaAtiva = 'semv';
elseif ($fStatus === 'vencido') $filaAtiva = 'vencidos';

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row(
        "SELECT * FROM movimentacoes_financeiras WHERE id=:id AND tenant_id=:t AND tipo=:tp AND origem_tipo IS NULL",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant(), ':tp' => $FIN_TIPO]);
}

/* Modal de anexos (?anexos=ID) — qualquer título do tipo, inclusive de origem */
$anexosMov = null;
$anexosLista = [];
if (!empty($_GET['anexos'])) {
    $anexosMov = vero_row(
        "SELECT * FROM movimentacoes_financeiras WHERE id=:id AND tenant_id=:t AND tipo=:tp",
        [':id' => (int)$_GET['anexos'], ':t' => vero_tenant(), ':tp' => $FIN_TIPO]);
    if ($anexosMov) {
        $anexosLista = vero_rows(
            "SELECT * FROM agro_anexos
              WHERE tenant_id=:t AND origem_tipo='movimentacao_financeira' AND origem_id=:o
              ORDER BY id DESC", [':t' => vero_tenant(), ':o' => (int)$anexosMov['id']]);
    }
}

/* A3-T14 (aging por comprador): card removido a pedido do gestor 25/08 —
   a consulta saiu junto; se voltar, recuperar do git (commit 5e230a3). */

/* G-10: opções de classificação — filtro/coluna da lista e form manual */
$g10Centros = vero_rows("SELECT id, codigo, nome, ativo FROM centros_custo WHERE tenant_id=:t ORDER BY codigo",
    [':t' => vero_tenant()]);
$g10Contas = vero_rows(
    "SELECT id, codigo, nome, nivel, aceita_lancamento, ativo FROM plano_contas
      WHERE tenant_id=:t AND tipo=:tp ORDER BY codigo",
    [':t' => vero_tenant(), ':tp' => $FIN_TIPO === 'receber' ? 'receita' : 'despesa']);
$g10Safras = vero_rows("SELECT id, identificacao, status FROM agro_safras WHERE tenant_id=:t ORDER BY id DESC",
    [':t' => vero_tenant()]);
/* Z-07: válvulas (talhões ativos) para o lançamento manual */
$g10Valvulas = vero_rows(
    "SELECT t.id, CONCAT(f.nome,' — ',t.codigo) AS label
       FROM agro_talhoes t JOIN agro_fazendas f ON f.id=t.fazenda_id
      WHERE t.tenant_id=:t AND t.ativo=1 ORDER BY f.nome, t.codigo",
    [':t' => vero_tenant()]);
$g10ContasBanc = vero_rows(
    "SELECT id, nome, banco, ativo FROM contas_bancarias WHERE tenant_id=:t ORDER BY nome",
    [':t' => vero_tenant()]);
/* 08/2026: fazendas para o select do título e o filtro da lista */
$g10Fazendas = vero_rows(
    "SELECT id, nome, ativo FROM agro_fazendas WHERE tenant_id=:t ORDER BY nome",
    [':t' => vero_tenant()]);

$GUARD      = ['macro' => 'financeiro', 'micro' => $FIN_MICRO];
$PAGE_VIEW  = $FIN_VIEW;
$PAGE_TITLE = $FIN_TITULO;
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$podeEditar = vero_can($permBase . '.editar');
$hoje = date('Y-m-d');

$badgeStatus = static function (array $m) use ($hoje): string {
    if ($m['status'] === 'pago') return '<span class="vbadge vb-ok">Pago</span>';
    if ($m['status'] === 'cancelado') return '<span class="vbadge vb-off">Cancelado</span>';
    if ($m['data_vencimento'] !== null && $m['data_vencimento'] < $hoje) {
        /* Cockpit LITE: situação derivada com idade do atraso */
        $dias = (int)round((strtotime($hoje) - strtotime((string)$m['data_vencimento'])) / 86400);
        return '<span class="vbadge vb-off" title="Venceu em '
            . date('d/m/Y', strtotime((string)$m['data_vencimento'])) . '">Vencido há '
            . $dias . ($dias === 1 ? ' dia' : ' dias') . '</span>';
    }
    return '<span class="vbadge vb-warn">Aberto</span>';
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header($FIN_TITULO, $FIN_SUB, $podeEditar ? '+ Novo lançamento' : null) ?>

  <?php /* Tela enxuta de lista: os cards de totais (cockpit) saíram do topo —
           os recortes por vencimento/status seguem disponíveis pelos filtros
           da barra abaixo (status, centro, conta, busca) e pela querystring. */ ?>

  <?php /* 25/08: saiu o card "Aging por comprador" (A3-T14,
           04/07) — a tela fica só com a lista de títulos. */ ?>
  <div class="vcard">
    <div class="vtoolbar">
      <?php /* 25/08: filtros numa linha FLUIDA — cada controle
               tem base pequena e encolhe (min-width:0) em vez de quebrar; o form
               ocupa a largura toda e o resumo/export desce para a linha de baixo. */ ?>
      <form method="get" style="display:flex;gap:6px;flex:1 1 100%;flex-wrap:wrap;align-items:center;min-width:0">
        <?php /* Cockpit LITE: a busca/filtros preservam a fila ativa */ ?>
        <?php if ($fVenc !== ''): ?><input type="hidden" name="venc" value="<?= h($fVenc) ?>"><?php endif; ?>
        <?php if ($fSemCC): ?><input type="hidden" name="semcc" value="1"><?php endif; ?>
        <select name="status" aria-label="Filtrar por status" onchange="this.form.submit()" style="flex:1 1 110px;min-width:0">
          <option value="">Todos<?= $verCanc ? ' os status' : ' (cancelados ocultos)' ?></option>
          <option value="aberto"<?= $fStatus === 'aberto' ? ' selected' : '' ?>>Abertos</option>
          <option value="vencido"<?= $fStatus === 'vencido' ? ' selected' : '' ?>>Vencidos</option>
          <option value="pago"<?= $fStatus === 'pago' ? ' selected' : '' ?>>Pagos</option>
          <option value="cancelado"<?= $fStatus === 'cancelado' ? ' selected' : '' ?>>Cancelados</option>
        </select>
        <?php if ($g10Centros): /* G-10: filtro por centro de custo */ ?>
        <select name="cc" aria-label="Filtrar por centro de custo" onchange="this.form.submit()" style="flex:1 1 120px;min-width:0">
          <option value="">Todos os centros</option>
          <?php foreach ($g10Centros as $c): if ((int)$c['ativo'] !== 1 && (int)$c['id'] !== $fCC) continue; ?>
            <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $fCC ? ' selected' : '' ?>><?= h($c['codigo'] . ' — ' . $c['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($g10ContasBanc): /* FD-05: filtro por conta bancária */ ?>
        <select name="cb" aria-label="Filtrar por conta bancária" onchange="this.form.submit()" style="flex:1 1 120px;min-width:0">
          <option value="">Todas as contas banc.</option>
          <?php foreach ($g10ContasBanc as $c): if ((int)$c['ativo'] !== 1 && (int)$c['id'] !== $fCB) continue; ?>
            <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $fCB ? ' selected' : '' ?>><?= h($c['nome'] . ($c['banco'] !== null && $c['banco'] !== '' ? ' (' . $c['banco'] . ')' : '')) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($g10Fazendas): /* 08/2026: filtro por fazenda */ ?>
        <select name="fz" aria-label="Filtrar por fazenda" onchange="this.form.submit()" style="flex:1 1 110px;min-width:0">
          <option value="">Todas as fazendas</option>
          <?php foreach ($g10Fazendas as $f): if ((int)$f['ativo'] !== 1 && (int)$f['id'] !== $fFaz) continue; ?>
            <option value="<?= (int)$f['id'] ?>"<?= (int)$f['id'] === $fFaz ? ' selected' : '' ?>><?= h((string)$f['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php /* 25/08: período do vencimento (dia/semana/mês) + data de referência;
                 o intervalo resolvido vai no title do campo de data (linha única) */ ?>
        <select name="per" aria-label="Filtrar por período do vencimento" onchange="this.form.submit()" style="flex:1 1 110px;min-width:0">
          <option value="">Todo o período</option>
          <option value="dia"<?= $fPer === 'dia' ? ' selected' : '' ?>>Vencimento: dia</option>
          <option value="semana"<?= $fPer === 'semana' ? ' selected' : '' ?>>Vencimento: semana</option>
          <option value="mes"<?= $fPer === 'mes' ? ' selected' : '' ?>>Vencimento: mês</option>
        </select>
        <?php if ($fPer !== ''): ?>
          <input type="date" name="ref" value="<?= h($fRef) ?>" onchange="this.form.submit()"
                 aria-label="Data de referência do período" style="flex:0 1 130px;min-width:0"
                 title="Vencimento de <?= $perIni === $perFim
                    ? date('d/m/Y', strtotime($perIni))
                    : date('d/m/Y', strtotime($perIni)) . ' a ' . date('d/m/Y', strtotime($perFim)) ?>">
        <?php endif; ?>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar descrição/documento…" style="flex:2 1 140px;min-width:0">
        <?php if ($fStatus !== 'cancelado'): /* cancelados ocultos por padrão (cockpit LITE) */ ?>
        <label style="display:inline-flex;align-items:center;gap:5px;font-size:12px;color:#6B5F53;cursor:pointer;white-space:nowrap;flex:0 0 auto">
          <input type="checkbox" name="canc" value="1"<?= $verCanc ? ' checked' : '' ?>
                 onchange="this.form.submit()" style="width:auto;margin:0">
          cancelados
        </label>
        <?php endif; ?>
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
        <?php if ($fStatus !== '' || $q !== '' || $fCC > 0 || $fCB > 0 || $fFaz > 0 || $fVenc !== '' || $fSemCC || $verCanc || $fPer !== ''): ?><a class="vbtn vbtn-ghost" href="?" title="Limpar filtros">Limpar</a><?php endif; ?>
      </form>
      <?php /* 25/08: saiu o resumo "N registro(s) · em aberto"
               com os botões Exportar CSV/Imprimir — a barra fica só com os
               filtros. A rota ?csv=1 segue viva (mesmo WHERE da tela) para
               quem exporta por URL/integração. */ ?>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum lançamento encontrado.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th>Descrição</th><th>Documento</th><th>Origem</th><th>Centro / conta</th>
        <th style="text-align:right">Valor (R$)</th>
        <th>Forma</th>
        <th>Vencimento</th><th>Pagamento</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $m): ?>
        <tr<?= $m['status'] === 'cancelado' ? ' style="opacity:.55"' : '' ?>>
          <td><strong><?= h($m['descricao'] ?? '—') ?></strong>
            <?php if ($m['parcela_num'] !== null): ?>
              <span class="vbadge vb-info"><?= (int)$m['parcela_num'] ?>/<?= (int)$m['parcela_total'] ?></span>
            <?php endif; ?></td>
          <td class="vnum"><?= h($m['documento'] ?? '—') ?></td>
          <td><?php /* Cockpit LITE: rastreabilidade clicável até a origem */
            if ($m['origem_tipo'] !== null):
                $oRota = FIN_ORIGEM_ROTAS[(string)$m['origem_tipo']] ?? null; ?>
              <span class="vbadge vb-info" title="<?= h((string)$m['origem_tipo'] . ' #' . (int)$m['origem_id']) ?>"><?=
                h(str_replace('_', ' ', (string)$m['origem_tipo'])) ?></span>
              <?php if ($oRota !== null): ?>
                <div><a class="vhint" style="text-decoration:underline"
                        href="<?= h($base . sprintf($oRota, (int)$m['origem_id'])) ?>"
                        title="Abrir <?= h(str_replace('_', ' ', (string)$m['origem_tipo'])) ?> #<?= (int)$m['origem_id'] ?>">ver origem</a></div>
              <?php endif; ?>
            <?php else: ?><span class="vhint">manual</span><?php endif; ?></td>
          <td><?php /* G-10/FD-05: centro de custo, conta do plano e conta bancária */ ?>
            <?= $m['cc_codigo'] !== null
                ? '<span class="vbadge vb-info" title="' . h((string)$m['cc_nome']) . '">' . h((string)$m['cc_codigo']) . '</span>'
                : '<span class="vhint">—</span>' ?>
            <?php if ($m['pc_codigo'] !== null): ?>
              <div class="vhint" title="<?= h((string)$m['pc_nome']) ?>"><?= h((string)$m['pc_codigo']) ?></div>
            <?php endif; ?>
            <?php if ($m['cb_nome'] !== null): ?>
              <div class="vhint" title="Conta bancária: <?= h((string)$m['cb_nome']) ?>"><?= h(mb_substr((string)$m['cb_nome'], 0, 16)) ?></div>
            <?php endif; ?>
            <?php /* 08/2026: fazenda do título — linha discreta, sem coluna nova */ ?>
            <?php if ($m['fz_nome'] !== null): ?>
              <div class="vhint" title="Fazenda: <?= h((string)$m['fz_nome']) ?>"><?= h(mb_substr((string)$m['fz_nome'], 0, 16)) ?></div>
            <?php endif; ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$m['valor'], 2) ?></strong></td>
          <td><?= $m['forma_pagamento'] !== null ? h(FIN_FORMAS[$m['forma_pagamento']] ?? (string)$m['forma_pagamento']) : '<span class="vhint">—</span>' ?></td>
          <td class="vnum"><?= $m['data_vencimento'] ? date('d/m/Y', strtotime((string)$m['data_vencimento'])) : '—' ?></td>
          <td class="vnum"><?= $m['data_pagamento'] ? date('d/m/Y', strtotime((string)$m['data_pagamento'])) : '—' ?></td>
          <td><?= $badgeStatus($m) ?></td>
          <td><div class="vactions">
            <?php /* Ações da tabela como ÍCONES com tooltip (padrão do sistema) */
            $nAnx = (int)$m['anexos'];
            $icoClip = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a5 5 0 0 1-7.07-7.07l9.19-9.19a3.5 3.5 0 0 1 4.95 4.95l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>'; ?>
            <?= vero_btn_icone($icoClip, 'Anexos' . ($nAnx > 0 ? ' (' . $nAnx . ')' : ''), '', '?anexos=' . (int)$m['id']) ?>
            <?php if ($podeEditar && $m['status'] === 'aberto'): ?>
              <?= vero_btn_icone(vero_ico_check(),
                    $FIN_TIPO === 'receber' ? 'Registrar recebimento' : 'Registrar pagamento',
                    'baixaAbrir(' . (int)$m['id'] . ', \'' . h(addslashes((string)$m['descricao'])) . '\')') ?>
              <?php if ($m['origem_tipo'] === null): ?>
                <?= vero_btn_editar((int)$m['id']) ?>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($podeEditar && $m['status'] === 'pago'): ?>
              <?= vero_btn_icone_post(vero_ico_voltar(), 'Estornar baixa', 'estornar', (int)$m['id'], 'Estornar a baixa deste lançamento?') ?>
            <?php endif; ?>
            <?php if (vero_can($permBase . '.excluir') && $m['status'] === 'aberto' && $m['origem_tipo'] === null): ?>
              <?= vero_btn_excluir((int)$m['id'], 'Cancelar este lançamento manual?', 'Cancelar') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<?php /* ?novo=1 (C-43/vModalNovo) abre o modal já no SERVIDOR — antes a
         abertura dependia só do listener DOMContentLoaded do vero_crud;
         qualquer erro/atraso de JS deixava o clique em "+ Novo" mudo
         (relato do auditor 19/07). O JS continua idempotente por cima. */ ?>
<div class="vmodal<?= ($edit || isset($_GET['novo'])) ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar lançamento' : 'Novo lançamento manual' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('descricao', 'Descrição', $edit['descricao'] ?? '', true) ?></div>
        <?= vero_f_text('valor', $edit ? 'Valor (R$)' : 'Valor total (R$)', $edit ? numFmt((float)$edit['valor'], 2) : '', true) ?>
        <?= vero_f_text('documento', 'Documento (NF/boleto)', $edit['documento'] ?? '') ?>
        <div class="vfield">
          <label>Forma de pagamento</label>
          <select name="forma_pagamento">
            <option value="">—</option>
            <?php foreach (FIN_FORMAS as $fk => $fl): ?>
              <option value="<?= $fk ?>"<?= ($edit['forma_pagamento'] ?? '') === $fk ? ' selected' : '' ?>><?= h($fl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Competência</label>
          <input type="date" name="data_competencia" value="<?= h($edit['data_competencia'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="vfield">
          <label>Vencimento<?= $edit ? '' : ' (1ª parcela) *' ?></label>
          <input type="date" name="data_vencimento" value="<?= h($edit['data_vencimento'] ?? '') ?>"<?= $edit ? '' : ' required' ?>>
        </div>
        <?php if (!$edit): ?>
        <div class="vfield">
          <label>Parcelas</label>
          <select name="parcelas">
            <?php for ($i = 1; $i <= FIN_MAX_PARCELAS; $i++): ?>
              <option value="<?= $i ?>"><?= $i ?>×</option>
            <?php endfor; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php /* G-10: classificação opcional — fora do hash do razão */ ?>
        <div class="vfield">
          <label>Centro de custo</label>
          <select name="centro_custo_id">
            <option value="">— (opcional)</option>
            <?php $g10CcSel = (int)($edit['centro_custo_id'] ?? 0);
            foreach ($g10Centros as $c):
                if ((int)$c['ativo'] !== 1 && (int)$c['id'] !== $g10CcSel) continue; ?>
              <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $g10CcSel ? ' selected' : '' ?>><?= h($c['codigo'] . ' — ' . $c['nome']) . ((int)$c['ativo'] !== 1 ? ' (inativo)' : '') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Conta do plano (<?= $FIN_TIPO === 'receber' ? 'receita' : 'despesa' ?>)</label>
          <select name="plano_conta_id">
            <option value="">— (opcional)</option>
            <?php $g10PcSel = (int)($edit['plano_conta_id'] ?? 0);
            foreach ($g10Contas as $c):
                $folha = (int)$c['aceita_lancamento'] === 1 && (int)$c['ativo'] === 1;
                if ((int)$c['ativo'] !== 1 && (int)$c['id'] !== $g10PcSel) continue; ?>
              <option value="<?= (int)$c['id'] ?>"<?= $folha ? '' : ' disabled' ?><?= (int)$c['id'] === $g10PcSel ? ' selected' : '' ?>><?=
                str_repeat('&nbsp;&nbsp;&nbsp;', max(0, (int)$c['nivel'] - 1)) . h($c['codigo'] . ' — ' . $c['nome'])
                . ($folha ? '' : ((int)$c['ativo'] !== 1 ? ' (inativa)' : ' (sintética)')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php /* 08/2026: fazenda do título — opcional, fora do hash */ ?>
        <div class="vfield">
          <label>Fazenda</label>
          <select name="fazenda_id">
            <option value="">— Sem fazenda —</option>
            <?php $g10FzSel = (int)($edit['fazenda_id'] ?? 0);
            foreach ($g10Fazendas as $f):
                if ((int)$f['ativo'] !== 1 && (int)$f['id'] !== $g10FzSel) continue; ?>
              <option value="<?= (int)$f['id'] ?>"<?= (int)$f['id'] === $g10FzSel ? ' selected' : '' ?>><?= h((string)$f['nome']) . ((int)$f['ativo'] !== 1 ? ' (inativa)' : '') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Safra</label>
          <select name="safra_id">
            <option value="">— (opcional)</option>
            <?php $g10SfSel = (int)($edit['safra_id'] ?? 0);
            foreach ($g10Safras as $s): ?>
              <option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === $g10SfSel ? ' selected' : '' ?>><?= h((string)$s['identificacao']) . ((string)$s['status'] !== 'ativa' ? ' — ' . h((string)$s['status']) : '') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php /* Z-07: válvula (talhão) — opcional, fora do hash do razão */ ?>
        <div class="vfield">
          <label>Válvula</label>
          <select name="talhao_id">
            <option value="">— Válvula —</option>
            <?php $g10VvSel = (int)($edit['talhao_id'] ?? 0);
            foreach ($g10Valvulas as $v): ?>
              <option value="<?= (int)$v['id'] ?>"<?= (int)$v['id'] === $g10VvSel ? ' selected' : '' ?>><?= h((string)$v['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Conta bancária <span class="vhint">(LCDPR)</span></label>
          <select name="conta_bancaria_id">
            <option value="">— (opcional)</option>
            <?php $g10CbSel = (int)($edit['conta_bancaria_id'] ?? 0);
            foreach ($g10ContasBanc as $c):
                if ((int)$c['ativo'] !== 1 && (int)$c['id'] !== $g10CbSel) continue; ?>
              <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $g10CbSel ? ' selected' : '' ?>><?= h($c['nome'] . ($c['banco'] !== null && $c['banco'] !== '' ? ' (' . $c['banco'] . ')' : '')) . ((int)$c['ativo'] !== 1 ? ' (inativa)' : '') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (!$edit): ?>
        <div class="vfield full">
          <label>Rateio entre centros de custo <span class="vhint">(opcional — percentuais somando 100%; cria um título por centro; não combina com parcelas)</span></label>
          <div id="g10-rateio-linhas"></div>
          <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="g10RateioAdd()">+ Adicionar centro ao rateio</button>
        </div>
        <?php endif; ?>
      </div>
      <div class="vhint" style="margin-top:8px">
        Lançamentos de vendas/compras nascem nas telas de origem — aqui entram apenas os manuais.
        <?= $edit
            ? 'Alterar valor/datas/descrição cancela esta linha e emite uma nova (o razão não sofre UPDATE de valor).'
            : 'Parcelado: cada parcela vira um lançamento próprio com vencimento mensal (valor dividido; ajuste de centavos na última).' ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>

<div class="vmodal" id="vm-baixa">
  <div class="vbox">
    <header>
      <h2 id="baixa-titulo">Registrar baixa</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-baixa')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="baixar">
      <input type="hidden" name="id" id="baixa-id">
      <div class="vgrid">
        <div class="vfield">
          <label>Data do <?= $FIN_TIPO === 'receber' ? 'recebimento' : 'pagamento' ?> *</label>
          <input type="date" name="data_pagamento" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="vfield">
          <label>Forma de pagamento</label>
          <select name="forma_pagamento">
            <option value="">—</option>
            <?php foreach (FIN_FORMAS as $fk => $fl): ?>
              <option value="<?= $fk ?>"><?= h($fl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-baixa')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Confirmar baixa</button>
      </div>
    </form>
  </div>
</div>
<script>
function baixaAbrir(id, descricao) {
  document.getElementById('baixa-id').value = id;
  document.getElementById('baixa-titulo').textContent = 'Registrar baixa — ' + descricao;
  vModalOpen('vm-baixa');
}
/* G-10: linhas dinâmicas do rateio (labels pré-escapados no PHP) */
var G10_CENTROS = <?= jsvar(array_values(array_map(
    static fn(array $c) => ['id' => (int)$c['id'], 'l' => h($c['codigo'] . ' — ' . $c['nome'])],
    array_filter($g10Centros, static fn(array $c) => (int)$c['ativo'] === 1)))) ?>;
function g10RateioAdd() {
  var box = document.getElementById('g10-rateio-linhas');
  if (!box) return;
  var row = document.createElement('div');
  row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px';
  var sel = '<select name="rateio_centro[]" style="flex:1"><option value="">— centro —</option>';
  G10_CENTROS.forEach(function (c) { sel += '<option value="' + c.id + '">' + c.l + '</option>'; });
  sel += '</select>';
  row.innerHTML = sel
    + '<input type="number" name="rateio_perc[]" min="0.01" max="100" step="0.01" placeholder="%" style="width:100px" aria-label="Percentual do rateio">'
    + '<button class="vbtn vbtn-ghost vbtn-sm" type="button" aria-label="Remover linha do rateio" onclick="this.parentNode.remove()">×</button>';
  box.appendChild(row);
}
</script>
<?php endif; ?>

<?php if ($anexosMov): ?>
<div class="vmodal open" id="vm-anexos">
  <div class="vbox">
    <header>
      <h2>Anexos — <?= h(mb_substr((string)$anexosMov['descricao'], 0, 60)) ?></h2>
      <a class="vclose" href="<?= $base ?>/financeiro/<?= h($FIN_MICRO) ?>.php">×</a>
    </header>
    <?php if (!$anexosLista): ?>
      <div class="vempty">Nenhum anexo neste lançamento.</div>
    <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Arquivo</th><th>Tipo</th><th style="text-align:right">Tamanho</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($anexosLista as $ax): ?>
          <tr>
            <td><a href="<?= $base . h((string)$ax['url']) ?>" target="_blank"><?= h((string)$ax['nome_original']) ?></a></td>
            <td><?= h((string)($ax['tipo_arquivo'] ?? '—')) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$ax['tamanho_bytes'] / 1024, 0) ?> KB</td>
            <td style="text-align:right">
              <?php if ($podeEditar): ?>
              <form method="post" onsubmit="return confirm('Remover este anexo?')">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="excluir_anexo">
                <input type="hidden" name="anexo_id" value="<?= (int)$ax['id'] ?>">
                <input type="hidden" name="id" value="<?= (int)$anexosMov['id'] ?>">
                <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Remover</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php if ($podeEditar && $anexosMov['status'] !== 'cancelado'): ?>
    <form class="vform" method="post" enctype="multipart/form-data" style="padding:12px 14px">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="anexar">
      <input type="hidden" name="id" value="<?= (int)$anexosMov['id'] ?>">
      <div class="vgrid">
        <div class="vfield">
          <label>Tipo</label>
          <select name="tipo_doc">
            <option value="boleto">Boleto</option>
            <option value="nf">Nota fiscal</option>
            <option value="comprovante">Comprovante</option>
            <option value="documento">Outro documento</option>
          </select>
        </div>
        <div class="vfield">
          <label>Arquivo (PDF/JPG/PNG)</label>
          <input type="file" name="arquivo" accept=".pdf,.jpg,.jpeg,.png" required>
        </div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-primary" type="submit">Anexar</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
