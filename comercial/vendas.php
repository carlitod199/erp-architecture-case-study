<?php
/* ============================================================
   VERO — Comercial / Vendas  (tela real)
   Substitui o mock. Rota da matriz: /comercial/vendas.php
   Guard: comercial.vendas | Escrita: comercial.vendas.editar/excluir
   Tabelas: comercial_vendas + comercial_venda_qualidades (mig. 132)
            + agro_anexos (NF/boleto) + movimentacoes_financeiras
   Fluxo (Seção 7): venda puxa a colheita realizada (qualidades e
   preços pré-carregados), usuário informa os kg comercializados e o
   KG por qualidade — a % é DERIVADA (kg ÷ kg total, pedido do gestor
   08/2026, espelho da colheita b411ecd; q_pct segue aceito como
   legado); salvar gera/atualiza conta a RECEBER (idempotente
   por origem). Excluir = cancelar (razão financeiro não é apagado).
   A3-T27b (contrato no board): quando a venda TEM lote COLH- (DB-50),
   BAIXA o saldo pelo lote apontado (não-FEFO, 1:1, via
   vero_srv_estoque_saida com $loteId — T27a); colheita é DERIVADA
   do lote (fonte única); reedição estorna a saída ativa e reemite;
   cancelar estorna; a saída NÃO emite custeio (o custo do lote JÁ é
   o custo da safra — anti-dupla-contagem da análise T27).
   Pedido do gestor (08/2026): o lote deixou de ser OBRIGATÓRIO na
   venda nova — sem lote a venda segue o caminho híbrido das legadas
   (P-87): colheita escolhida à mão e NENHUMA baixa de estoque.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_despesas.php'; /* F1 — despesas de venda (margem real) */
require_once __DIR__ . '/_precos.php';   /* F2 — sugestão de preço da tabela */

const T = 'comercial_vendas';

const QUALIDADES = ['premium' => 'Premium', 'cat1' => 'CAT 1', 'cat2' => 'CAT 2', 'cat3' => 'CAT 3'];
const ANEXO_EXT  = ['pdf', 'jpg', 'jpeg', 'png'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('comercial.vendas.editar');

        $id          = vero_int('id');
        /* UX (25/07): quando o SERVIDOR recusa a venda, preserva TODO o
           preenchimento. Guarda o POST na sessão e devolve ao FORM (não à
           lista) — o form repovoa os campos e as linhas de qualidade. */
        $vendaBackUrl = BIOS_BASE . '/comercial/vendas?' . ($id ? 'editar=' . (int)$id : 'novo=1');
        $_SESSION['vendas_old'] = $_POST;
        $compradorId = vero_int('comprador_id');
        $colheitaId  = vero_int('colheita_registro_id');
        $loteId      = vero_int('lote_id') ?: null;
        $dataVenda   = vero_date('data_venda');
        $vencimento  = vero_date('data_vencimento');
        $kgTotal     = vero_dec('kg_total');
        $parcelas    = max(1, min(12, (int)($_POST['parcelas'] ?? 1))); /* A3-T14 */

        /* Pedido do gestor (08/2026): lote OPCIONAL também na venda nova —
           sem lote, vale a COLHEITA selecionada à mão e nada baixa do estoque
           (mesmo caminho híbrido das legadas, P-87); G-16/saldo só valida
           quando HÁ lote. Com lote, o fluxo T27b segue intacto. */
        $vendaAtual = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if ($id && !$vendaAtual) { vero_flash('erro', 'Venda inválida.'); vero_redirect($vendaBackUrl); }
        $lote = null;
        if ($loteId !== null) {
            /* Sprint Zero packing #4: elegibilidade por SEMÂNTICA do produto
               (tipo_insumo), não pelo prefixo 'COLH-' do código do lote — assim o
               lote de packing (produto_embalado) também fica vendável sem depender
               de convenção de string. Reconciliação (31/07): todos os lotes COLH-
               disponíveis hoje têm tipo_insumo='produto_agricola', logo a troca
               preserva o comportamento atual. */
            $lote = vero_row(
                "SELECT l.* FROM estoque_lotes l
                   JOIN estoque_produtos p ON p.id = l.produto_id
                  WHERE l.id = :i AND l.tenant_id = :t
                    AND p.tipo_insumo IN ('produto_agricola','produto_embalado')
                    AND l.status = 'disponivel' AND l.colheita_registro_id IS NOT NULL",
                [':i' => $loteId, ':t' => vero_tenant()]);
            if (!$lote) {
                vero_flash('erro', 'Lote agrícola inválido (só lote de produção ATIVO com colheita vinculada).');
                vero_redirect($vendaBackUrl);
            }
            /* #8 (colheita_registro_id opcional p/ lote multi-origem de packing) fica
               PENDENTE: hoje todo lote vendável tem colheita única (recon: 0 sem
               colheita), e a forma correta depende do modelo de packing (Decisão 1
               da §0.4). Mantém-se colheita_registro_id IS NOT NULL até lá. */
            $colheitaId = (int)$lote['colheita_registro_id']; /* fonte única: colheita DERIVADA do lote */
        }

        /* Pedido do gestor (25/08): COLHEITA também vira opcional na venda —
           sem ela a venda não vincula safra/válvula (rastreabilidade menor) e
           o financeiro nasce sem classificação de safra. Com lote, a colheita
           segue DERIVADA dele (fonte única, acima). */
        if (!$compradorId || $dataVenda === null || $kgTotal === null || $kgTotal <= 0) {
            vero_flash('erro', 'Comprador, data e kg comercializados são obrigatórios.');
            vero_redirect($vendaBackUrl);
        }
        if ($parcelas > 1 && $vencimento === null) {
            vero_flash('erro', 'Venda parcelada exige o vencimento da 1ª parcela.');
            vero_redirect($vendaBackUrl);
        }
        $comprador = vero_row("SELECT * FROM comercial_compradores WHERE id=:i AND tenant_id=:t",
            [':i' => $compradorId, ':t' => vero_tenant()]);
        $colheita = $colheitaId ? vero_row("SELECT * FROM colheita_registros WHERE id=:i AND tenant_id=:t",
            [':i' => $colheitaId, ':t' => vero_tenant()]) : null;
        if (!$comprador || ($colheitaId && !$colheita)) {
            vero_flash('erro', 'Comprador ou colheita inválidos.');
            vero_redirect($vendaBackUrl);
        }
        /* Classificação da venda (26/08 — pedido do cliente):
           COM colheita, deriva DELA (fonte mais confiável — nada muda).
           SEM colheita, vale o que o usuário escolheu na cascata do CADASTRO:
           safra_id + setor_id (válvula); o talhão sai da válvula
           (agro_setores.talhao_id — derivado, não digitado). */
        $colSafraId  = $colheita && $colheita['safra_id']  !== null ? (int)$colheita['safra_id']  : null;
        $colTalhaoId = $colheita && $colheita['talhao_id'] !== null ? (int)$colheita['talhao_id'] : null;
        $colSetorId  = $colheita && $colheita['setor_id']  !== null ? (int)$colheita['setor_id']  : null;
        if ($colheita === null) {
            $safraSel = vero_int('safra_id') ?: null;
            $setorSel = vero_int('setor_id') ?: null;
            if ($safraSel !== null && !vero_val("SELECT id FROM agro_safras WHERE id=:i AND tenant_id=:t",
                    [':i' => $safraSel, ':t' => vero_tenant()])) {
                vero_flash('erro', 'Safra inválida.');
                vero_redirect($vendaBackUrl);
            }
            $setorRow = $setorSel !== null ? vero_row(
                "SELECT id, talhao_id FROM agro_setores WHERE id=:i AND tenant_id=:t",
                [':i' => $setorSel, ':t' => vero_tenant()]) : null;
            if ($setorSel !== null && !$setorRow) {
                vero_flash('erro', 'Válvula inválida.');
                vero_redirect($vendaBackUrl);
            }
            if ($safraSel !== null && $setorRow !== null && !vero_val(
                    "SELECT id FROM agro_safra_talhoes WHERE tenant_id=:t AND safra_id=:s AND talhao_id=:tl",
                    [':t' => vero_tenant(), ':s' => $safraSel, ':tl' => (int)$setorRow['talhao_id']])) {
                vero_flash('erro', 'A válvula escolhida não está vinculada a essa safra — confira a cascata.');
                vero_redirect($vendaBackUrl);
            }
            $colSafraId  = $safraSel;
            $colSetorId  = $setorRow !== null ? (int)$setorRow['id'] : null;
            $colTalhaoId = $setorRow !== null ? (int)$setorRow['talhao_id'] : null;
        }

        /* G-16 (b): consistência saldo × lote ANTES do service, com mensagem
           clara. Na REEDIÇÃO a saída ativa desta própria venda é estornada
           antes de reemitir, então o kg dela no MESMO lote ainda conta como
           disponível. A rede final segue sendo o service (A-04:
           vero_srv_estoque_saida bloqueia saldo insuficiente). */
        if ($lote !== null) {
            $saldoDisp = (float)$lote['quantidade'];
            if ($id) {
                $saldoDisp += (float)(vero_val(
                    "SELECT COALESCE(SUM(quantidade),0) FROM estoque_movimentacoes
                      WHERE tenant_id = :t AND origem_tipo = 'comercial_venda' AND origem_id = :v
                        AND tipo = 'saida' AND estornado_em IS NULL AND lote_id = :l",
                    [':t' => vero_tenant(), ':v' => $id, ':l' => $loteId]) ?? 0);
            }
            if ($kgTotal > $saldoDisp + 0.0005) {
                vero_flash('erro', 'Quantidade ACIMA do saldo do lote ' . $lote['codigo_lote']
                    . ': disponível ' . numFmt($saldoDisp, 0) . ' kg, informado ' . numFmt($kgTotal, 0)
                    . ' kg. Nada foi gravado — ajuste os kg comercializados.');
                vero_redirect($vendaBackUrl);
            }
        }

        /* qualidades DINÂMICAS (A4 19/07): linhas paralelas q_cat[]/q_kg[]/
           q_preco[] (padrão addLinha do apontamento) — cada linha escolhe a
           categoria (enum premium/cat1/cat2/cat3) + kg + preço.
           Fluxo invertido (pedido do gestor 08/2026, espelho da colheita
           b411ecd): o form envia o KG por categoria (q_kg[]) e a % é DERIVADA
           (kg ÷ kg comercializados × 100). q_pct[] segue aceito como LEGADO
           quando q_kg não vem (testes CLI/clientes antigos) — aí o kg deriva
           da % como sempre foi. Os DOIS campos (percentual + kg) são gravados
           coerentes nos dois modos, então os consumidores do percentual
           (impressão/DF, faturamento, pré-carga) seguem intactos.
           A persistência NÃO muda (comercial_venda_qualidades,
           uq_venda_categoria): categoria é única por venda, então linha
           repetida é recusada com mensagem clara. */
        $qCat   = (array)($_POST['q_cat'] ?? []);
        $qKg    = (array)($_POST['q_kg'] ?? []);
        $qPct   = (array)($_POST['q_pct'] ?? []);
        $qPreco = (array)($_POST['q_preco'] ?? []);
        $modoKg = array_key_exists('q_kg', $_POST); /* form novo = kg digitado */
        $parseDec = static function ($v): float {
            $v = trim((string)$v);
            if ($v === '') return 0.0;
            if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
            elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
            return is_numeric($v) ? (float)$v : 0.0;
        };

        $quals = [];
        $vistas = [];
        $somaPct = 0.0;
        $somaKg  = 0.0;
        $valorTotal = 0.0;
        foreach ($qCat as $ix => $catRaw) {
            $cat = (string)$catRaw;
            if (!isset(QUALIDADES[$cat])) continue; /* fora do enum → ignora a linha */
            $preco = $parseDec($qPreco[$ix] ?? '');
            if ($modoKg) {
                $kg = round($parseDec($qKg[$ix] ?? ''), 3);
                if ($kg <= 0) continue;
                /* % derivada do kg (2 casas, DECIMAL(6,2)) — kg é o dado-mestre;
                   $kgTotal já foi validado > 0 nos obrigatórios acima */
                $pct = round($kg / $kgTotal * 100, 2);
            } else {
                $pct = $parseDec($qPct[$ix] ?? '');
                if ($pct <= 0) continue;
                $kg = round($kgTotal * $pct / 100, 3);
            }
            if (isset($vistas[$cat])) {
                vero_flash('erro', 'Categoria "' . QUALIDADES[$cat] . '" repetida — cada qualidade entra em UMA linha só. Some os kg numa linha.');
                vero_redirect($vendaBackUrl);
            }
            $vistas[$cat] = true;
            $somaPct += $pct;
            $somaKg  += $kg;
            $valor = round($kg * $preco, 2);
            $valorTotal += $valor;
            $quals[] = ['categoria' => $cat, 'percentual' => $pct, 'preco_kg' => $preco, 'kg' => $kg, 'valor' => $valor];
        }
        if ($modoKg) {
            /* rede final do fluxo por kg — espelho da validação viva do form */
            if ($somaKg > $kgTotal + 0.0005) {
                vero_flash('erro', 'A soma dos kg por qualidade (' . numFmt($somaKg, 0)
                    . ' kg) passa dos kg comercializados (' . numFmt($kgTotal, 0)
                    . ' kg). Ajuste os kg por categoria.');
                vero_redirect($vendaBackUrl);
            }
        } elseif ($somaPct > 100.0001) {
            vero_flash('erro', 'A soma dos percentuais de qualidade passa de 100% (' . numFmt($somaPct, 2) . '%).');
            vero_redirect($vendaBackUrl);
        }
        if (!$quals) {
            vero_flash('erro', 'Adicione ao menos uma categoria de qualidade com kg.');
            vero_redirect($vendaBackUrl);
        }

        /* A3-T17 (P-09): vínculo opcional a contrato de pré-venda ATIVO do
           MESMO comprador — o saldo abate na tela de contratos */
        $contratoId = vero_int('contrato_id') ?: null;
        if ($contratoId !== null) {
            $ctOk = vero_row(
                "SELECT id, comprador_id FROM comercial_contratos
                  WHERE id = :i AND tenant_id = :t AND status = 'ativo'",
                [':i' => $contratoId, ':t' => vero_tenant()]);
            if (!$ctOk) {
                vero_flash('erro', 'Contrato de pré-venda inválido ou não está ATIVO.');
                vero_redirect($vendaBackUrl);
            }
            if ((int)$ctOk['comprador_id'] !== $compradorId) {
                vero_flash('erro', 'O contrato selecionado é de OUTRO comprador.');
                vero_redirect($vendaBackUrl);
            }
        }

        $cab = [
            'comprador_id'         => $compradorId,
            'contrato_id'          => $contratoId,
            'cliente'              => mb_substr((string)$comprador['razao_social'], 0, 150),
            'safra_id'             => $colSafraId,
            'talhao_id'            => $colTalhaoId,
            'setor_id'             => $colSetorId,
            'colheita_registro_id' => $colheitaId ?: null,
            'lote_id'              => $loteId, /* T27b (DB-50): NULL = venda legada híbrida */
            'data_venda'           => $dataVenda,
            'data_vencimento'      => $vencimento,
            'kg_total'             => $kgTotal,
            'valor_total'          => round($valorTotal, 2),
            'status'               => 'confirmada',
            'observacao'           => vero_str('observacao', 255),
        ];

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $vendaAtual = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
                    [':i' => $id, ':t' => vero_tenant()]);
                if (!$vendaAtual) throw new RuntimeException('Venda inválida.');
                if ($vendaAtual['status'] === 'cancelada') throw new RuntimeException('Venda cancelada não pode ser editada.');
                vero_update(T, $id, $cab);
                $pdo->prepare("DELETE FROM comercial_venda_qualidades WHERE tenant_id=? AND venda_id=?")
                    ->execute([vero_tenant(), $id]);
                $vendaId = $id;
                $numero  = (string)$vendaAtual['numero'];
            } else {
                /* numeração pela MAIOR sequência do ano (COUNT colide após cancelamentos —
                   defeito latente achado no E2E T27b); uq_venda_numero segue de guarda */
                $seq = (int)vero_val(
                    "SELECT COALESCE(MAX(CAST(SUBSTRING(numero, 7) AS UNSIGNED)), 0) FROM " . T . "
                      WHERE tenant_id = :t AND numero LIKE :p",
                    [':t' => vero_tenant(), ':p' => 'V' . date('Y') . '-%']) + 1;
                $numero = 'V' . date('Y') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                $cab['numero'] = $numero;
                $cab['status_pagamento'] = 'pendente';
                $vendaId = vero_insert(T, $cab);
            }
            foreach ($quals as $qq) {
                $qq['venda_id'] = $vendaId;
                vero_insert('comercial_venda_qualidades', $qq);
            }
            /* T27b: baixa do lote apontado (idempotente: estorna a saída ativa e reemite).
               A saída NÃO emite custeio — o custo do lote já é o custo da safra. */
            $saidaAtiva = vero_row(
                "SELECT * FROM estoque_movimentacoes
                  WHERE tenant_id = :t AND origem_tipo = 'comercial_venda' AND origem_id = :v
                    AND tipo = 'saida' AND estornado_em IS NULL",
                [':t' => vero_tenant(), ':v' => $vendaId]);
            if ($saidaAtiva) vero_srv_estoque_estornar_mov($saidaAtiva);
            if ($lote !== null) {
                vero_srv_estoque_saida(
                    (int)$lote['produto_id'], (int)$lote['almoxarifado_id'], $kgTotal, $dataVenda,
                    'comercial_venda', (int)$vendaId,
                    "Venda {$numero} — lote {$lote['codigo_lote']}",
                    $lote['safra_talhao_id'] !== null ? (int)$lote['safra_talhao_id'] : null,
                    null, false, (int)$loteId);
            }
            /* ── contas a receber (A3-T14: à vista OU parcelado) ──
               À vista (1×): 1 título com origem comercial_venda (idempotente
               pelo service). Parcelado: N títulos SEM origem, agrupados por
               grupo_id (cada parcela tem hash próprio); a venda aponta para a
               cabeça do grupo em movimentacao_id; a baixa de TODAS as parcelas
               marca a venda como paga (pós-baixa em financeiro/_contas_base). */

            /* limpa o parcelamento anterior desta venda (reedição) */
            $grupoAntigo = ($id && !empty($vendaAtual['movimentacao_id'])) ? (int)$vendaAtual['movimentacao_id'] : null;
            if ($grupoAntigo !== null) {
                $parcelasAntigas = vero_rows(
                    "SELECT * FROM movimentacoes_financeiras
                      WHERE tenant_id = :t AND grupo_id = :g AND origem_tipo IS NULL AND status <> 'cancelado'",
                    [':t' => vero_tenant(), ':g' => $grupoAntigo]);
                foreach ($parcelasAntigas as $pa) {
                    if ($pa['status'] === 'pago') {
                        throw new RuntimeException('Há parcela(s) já recebida(s) — estorne as baixas em Contas a Receber antes de editar a venda.');
                    }
                }
                foreach ($parcelasAntigas as $pa) {
                    vero_update('movimentacoes_financeiras', (int)$pa['id'], ['status' => 'cancelado']);
                }
            }

            if ($parcelas === 1) {
                $movId = vero_srv_fin_lancar([
                    'tipo'             => 'receber',
                    'valor'            => round($valorTotal, 2),
                    'data_competencia' => $dataVenda,
                    'data_vencimento'  => $vencimento,
                    'descricao'        => "Venda {$numero} — " . $comprador['razao_social'],
                    'origem_tipo'      => 'comercial_venda',
                    'origem_id'        => $vendaId,
                    'safra_id'         => $colSafraId,
                    'talhao_id'        => $colTalhaoId,
                ]);
            } else {
                /* venda estava à vista? cancela o título de origem (libera a chave) */
                $movOrigem = vero_row(
                    "SELECT * FROM movimentacoes_financeiras
                      WHERE tenant_id = :t AND origem_tipo = 'comercial_venda' AND origem_id = :v AND origem_ativa = 1",
                    [':t' => vero_tenant(), ':v' => $vendaId]);
                if ($movOrigem) {
                    if ($movOrigem['status'] === 'pago') {
                        throw new RuntimeException('A conta a receber desta venda já foi baixada — estorne a baixa antes de parcelar.');
                    }
                    vero_update('movimentacoes_financeiras', (int)$movOrigem['id'],
                        ['status' => 'cancelado', 'origem_ativa' => null]);
                }
                $centTotal = (int)round($valorTotal * 100);
                $centBase  = intdiv($centTotal, $parcelas);
                $grupoId = null;
                for ($i = 1; $i <= $parcelas; $i++) {
                    $cent = $i === $parcelas ? $centTotal - $centBase * ($parcelas - 1) : $centBase;
                    $venc = date('Y-m-d', strtotime($vencimento . ' +' . ($i - 1) . ' month'));
                    $pid = vero_srv_fin_lancar([
                        'tipo'             => 'receber',
                        'valor'            => $cent / 100,
                        'data_competencia' => $dataVenda,
                        'data_vencimento'  => $venc,
                        'descricao'        => "Venda {$numero} — " . $comprador['razao_social'] . " (parcela {$i}/{$parcelas})",
                        'safra_id'         => $colSafraId,
                        'talhao_id'        => $colTalhaoId,
                        'parcela_num'      => $i,
                        'parcela_total'    => $parcelas,
                        'grupo_id'         => $grupoId,
                    ]);
                    if ($grupoId === null) {
                        $grupoId = $pid;
                        vero_update('movimentacoes_financeiras', $pid, ['grupo_id' => $grupoId]);
                    }
                }
                $movId = $grupoId;
                /* reedição parcelada zera eventual "pago" herdado */
                vero_update(T, $vendaId, ['status_pagamento' => 'pendente', 'data_pagamento' => null]);
            }
            vero_update(T, $vendaId, ['movimentacao_id' => $movId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao salvar a venda: ' . h($e->getMessage()));
            vero_redirect($vendaBackUrl);
        }

        vero_flash('ok', "Venda {$numero} salva — " . numFmt($kgTotal, 0) . " kg, R$ " . numFmt($valorTotal, 2)
            . ($parcelas > 1
                ? ". Conta a receber em {$parcelas} parcelas mensais a partir de " . date('d/m/Y', strtotime((string)$vencimento)) . '.'
                : '. Conta a receber ' . ($vencimento ? 'com vencimento ' . date('d/m/Y', strtotime($vencimento)) : 'sem vencimento') . ' gerada.'));
        if ($lote !== null) {
            vero_flash('ok', "Estoque baixado: " . numFmt($kgTotal, 0) . " kg do lote {$lote['codigo_lote']}.");
        } else {
            vero_flash('aviso', '⚠ Venda SEM lote — o kg não baixa do estoque agrícola.');
        }
        unset($_SESSION['vendas_old']); /* sucesso: descarta o preenchimento preservado */
        vero_redirect(BIOS_BASE . '/comercial/vendas?editar=' . $vendaId);
    }

    if ($acao === 'anexar') {
        vero_require('comercial.vendas.editar');
        $vendaId = vero_int('id');
        $venda = $vendaId ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $vendaId, ':t' => vero_tenant()]) : null;
        $tipoDoc = vero_str('tipo_doc', 20) ?? 'documento';
        $file = $_FILES['arquivo'] ?? null;

        if (!$venda || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            vero_flash('erro', 'Selecione um arquivo válido para anexar.');
            vero_redirect(BIOS_BASE . '/comercial/vendas?editar=' . (int)$vendaId);
        }
        $maxBytes = (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 5242880);
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ANEXO_EXT, true) || (int)$file['size'] > $maxBytes) {
            vero_flash('erro', 'Arquivo inválido: aceite apenas PDF/JPG/PNG até ' . round($maxBytes / 1048576, 1) . ' MB.');
            vero_redirect(BIOS_BASE . '/comercial/vendas?editar=' . $vendaId);
        }
        if (!vero_upload_conteudo_ok((string)$file['tmp_name'], $ext)) {
            vero_flash('erro', 'O conteúdo do arquivo não corresponde a um PDF ou imagem válido. Envie o arquivo original.');
            vero_redirect(BIOS_BASE . '/comercial/vendas?editar=' . $vendaId);
        }
        $dir = dirname(__DIR__) . '/storage/uploads/vendas/' . vero_tenant();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nomeFisico = 'venda' . $vendaId . '_' . $tipoDoc . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destino = $dir . '/' . $nomeFisico;
        if (!move_uploaded_file((string)$file['tmp_name'], $destino)) {
            vero_flash('erro', 'Falha ao gravar o arquivo no servidor.');
            vero_redirect(BIOS_BASE . '/comercial/vendas?editar=' . $vendaId);
        }
        vero_insert('agro_anexos', [
            'origem_tipo'   => 'comercial_venda',
            'origem_id'     => $vendaId,
            'tipo_arquivo'  => $tipoDoc,
            'nome_original' => mb_substr((string)$file['name'], 0, 255),
            'url'           => '/storage/uploads/vendas/' . vero_tenant() . '/' . $nomeFisico,
            'tamanho_bytes' => (int)$file['size'],
            'hash_sha256'   => hash_file('sha256', $destino),
        ]);
        vero_flash('ok', 'Anexo "' . h((string)$file['name']) . '" gravado.');
        vero_redirect(BIOS_BASE . '/comercial/vendas?editar=' . $vendaId);
    }

    if ($acao === 'excluir_anexo') {
        vero_require('comercial.vendas.editar');
        $anexoId = vero_int('anexo_id');
        $vendaId = vero_int('id');
        $anexo = $anexoId ? vero_row(
            "SELECT * FROM agro_anexos WHERE id=:i AND tenant_id=:t AND origem_tipo='comercial_venda'",
            [':i' => $anexoId, ':t' => vero_tenant()]) : null;
        if ($anexo) {
            $arquivo = dirname(__DIR__) . $anexo['url'];
            if (is_file($arquivo)) unlink($arquivo);
            vero_pdo()->prepare("DELETE FROM agro_anexos WHERE tenant_id=? AND id=?")
                ->execute([vero_tenant(), $anexoId]);
            vero_flash('ok', 'Anexo removido.');
        }
        vero_redirect(BIOS_BASE . '/comercial/vendas?editar=' . (int)$vendaId);
    }

    if ($acao === 'excluir') {
        vero_require('comercial.vendas.excluir');
        $id = vero_int('id');
        $venda = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if ($venda) {
            $pdo = vero_pdo();
            $pdo->beginTransaction();
            try {
                vero_update(T, $id, ['status' => 'cancelada', 'status_pagamento' => 'cancelado']);
                /* T27b: devolve o kg ao lote (estorno da saída ativa desta venda) */
                $saidaAtiva = vero_row(
                    "SELECT * FROM estoque_movimentacoes
                      WHERE tenant_id = :t AND origem_tipo = 'comercial_venda' AND origem_id = :v
                        AND tipo = 'saida' AND estornado_em IS NULL",
                    [':t' => vero_tenant(), ':v' => (int)$id]);
                if ($saidaAtiva) vero_srv_estoque_estornar_mov($saidaAtiva);
                if ($venda['movimentacao_id'] !== null) {
                    vero_update('movimentacoes_financeiras', (int)$venda['movimentacao_id'], ['status' => 'cancelado']);
                    /* A3-T14: cancela também as demais parcelas do grupo (se parcelada) */
                    $pdo->prepare(
                        "UPDATE movimentacoes_financeiras
                            SET status = 'cancelado', updated_by = ?
                          WHERE tenant_id = ? AND grupo_id = ? AND origem_tipo IS NULL
                            AND status <> 'cancelado'")
                        ->execute([vero_uid(), vero_tenant(), (int)$venda['movimentacao_id']]);
                }
                $pdo->commit();
                vero_flash('ok', "Venda {$venda['numero']} cancelada (contas a receber canceladas; razão preservado).");
            } catch (Throwable $e) {
                $pdo->rollBack();
                vero_flash('erro', 'Erro ao cancelar: ' . h($e->getMessage()));
            }
        }
        vero_redirect(BIOS_BASE . '/comercial/vendas');
    }

    if ($acao === 'add_despesa') { /* F1 — despesa de comercialização na venda */
        vero_require('comercial.vendas.editar');
        $vendaId = vero_int('venda_id');
        $venda = $vendaId ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $vendaId, ':t' => vero_tenant()]) : null;
        if (!$venda || $venda['status'] === 'cancelada') {
            vero_flash('erro', 'Venda inválida ou cancelada.');
            vero_redirect(BIOS_BASE . '/comercial/vendas');
        }
        $base = (string)($_POST['base'] ?? 'valor');
        if (!isset(DESPESA_BASES[$base])) $base = 'valor';
        $tipoId = vero_int('tipo_despesa_id') ?: null;
        $input = vero_dec('input'); /* % (percentual) ou R$ (valor/por_unidade) */
        $receita = (float)$venda['valor_total'];
        $kg = (float)$venda['kg_total'];
        $valor = despesa_calc($base, $input, $receita, $kg);
        if ($valor <= 0) {
            vero_flash('erro', 'Informe um valor/percentual que resulte em despesa maior que zero.');
            vero_redirect(BIOS_BASE . '/comercial/vendas?editar=' . $vendaId);
        }
        despesa_add($vendaId, $tipoId, vero_str('descricao', 120), $base, $input, $valor);
        vero_flash('ok', 'Despesa adicionada (R$ ' . numFmt($valor, 2) . ') — margem líquida atualizada.');
        vero_redirect(BIOS_BASE . '/comercial/vendas?editar=' . $vendaId);
    }

    if ($acao === 'remove_despesa') {
        vero_require('comercial.vendas.editar');
        $vendaId = vero_int('venda_id');
        $despId  = vero_int('despesa_id');
        if ($vendaId && $despId) despesa_remove($despId, $vendaId);
        vero_flash('ok', 'Despesa removida.');
        vero_redirect(BIOS_BASE . '/comercial/vendas?editar=' . $vendaId);
    }
}

/* ── Dados ──────────────────────────────────────────────────── */
$modoForm = isset($_GET['novo']) || !empty($_GET['editar']);

/* UX (25/07): preenchimento preservado após recusa do servidor (consumido 1x).
   $ov(campo, fallback) devolve o valor digitado (ou o fallback do $edit/padrão);
   $osel(campo, opt, fallback) devolve ' selected' quando a opção casa. */
$OLD = $_SESSION['vendas_old'] ?? null;
unset($_SESSION['vendas_old']);
$ov = static function (string $k, $fallback = '') use ($OLD) {
    return $OLD !== null && array_key_exists($k, $OLD) ? (string)$OLD[$k] : (string)$fallback;
};
$osel = static function (string $k, $opt, $fallback = null) use ($OLD) {
    $cur = $OLD !== null && array_key_exists($k, $OLD) ? (string)$OLD[$k] : ($fallback === null ? '' : (string)$fallback);
    return (string)$opt === $cur && $cur !== '' ? ' selected' : '';
};
/* linhas de qualidade preservadas (valores crus digitados) para o JS repovoar
   — fluxo invertido (08/2026): o form posta q_kg[], não mais q_pct[] */
$oldQuals = [];
if ($OLD !== null && !empty($OLD['q_cat']) && is_array($OLD['q_cat'])) {
    $qcO = (array)$OLD['q_cat']; $qkO = (array)($OLD['q_kg'] ?? []); $qprO = (array)($OLD['q_preco'] ?? []);
    foreach ($qcO as $ix => $catO) {
        if (!isset(QUALIDADES[(string)$catO])) continue;
        $oldQuals[] = ['cat' => (string)$catO, 'kg' => (string)($qkO[$ix] ?? ''), 'preco' => (string)($qprO[$ix] ?? '')];
    }
}

$edit = null;
$editQuals = [];
$anexos = [];
$editParcelas = 1;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        if ($edit['movimentacao_id'] !== null) { /* A3-T14: parcelamento atual */
            $editParcelas = max(1, (int)vero_val(
                "SELECT COUNT(*) FROM movimentacoes_financeiras
                  WHERE tenant_id = :t AND grupo_id = :g AND origem_tipo IS NULL AND status <> 'cancelado'",
                [':t' => vero_tenant(), ':g' => (int)$edit['movimentacao_id']]));
        }
        $editQuals = vero_rows(
            "SELECT * FROM comercial_venda_qualidades WHERE tenant_id=:t AND venda_id=:v",
            [':t' => vero_tenant(), ':v' => (int)$edit['id']]);
        $anexos = vero_rows(
            "SELECT * FROM agro_anexos WHERE tenant_id=:t AND origem_tipo='comercial_venda' AND origem_id=:v ORDER BY id",
            [':t' => vero_tenant(), ':v' => (int)$edit['id']]);
    } else {
        $modoForm = false;
    }
}

/* F1 — despesas + margem líquida da venda em edição; F2 — preço de tabela (referência) */
$despesas = []; $despTotal = 0.0; $vendaCpv = 0.0; $margemLiq = null;
$tiposDespesa = []; $precoRef = null;
if ($edit) {
    $despesas  = despesas_venda((int)$edit['id']);
    $despTotal = despesas_total((int)$edit['id']);
    $vendaCpv  = venda_cpv((int)$edit['id']);
    $receita   = (float)$edit['valor_total'];
    $margemLiq = $receita - $vendaCpv - $despTotal;
    $tiposDespesa = vero_rows("SELECT id, nome, base FROM comercial_tipos_despesa WHERE tenant_id=:t AND ativo=1 ORDER BY nome",
        [':t' => vero_tenant()]);
    /* F2: regra de preço vigente mais específica p/ cliente+safra desta venda (referência editável) */
    $precoRef = preco_resolver([
        'comprador_id' => $edit['comprador_id'] !== null ? (int)$edit['comprador_id'] : null,
        'safra_id'     => $edit['safra_id'] !== null ? (int)$edit['safra_id'] : null,
    ], (string)$edit['data_venda']);
}

if ($modoForm) {
    $compradores = vero_options('comercial_compradores', 'razao_social', 'ativo = 1');
    /* A3-T17: contratos ATIVOS p/ vínculo opcional (validação de comprador no POST) */
    $contratosAtivos = vero_rows(
        "SELECT ct.id, ct.numero, ct.comprador_id, ct.kg_contratado, ct.preco_kg,
                COALESCE(NULLIF(cc.nome_fantasia,''), cc.razao_social) AS comprador,
                COALESCE((SELECT SUM(v.kg_total) FROM comercial_vendas v
                  WHERE v.tenant_id = ct.tenant_id AND v.contrato_id = ct.id AND v.status <> 'cancelada'), 0) AS kg_faturado
           FROM comercial_contratos ct
           JOIN comercial_compradores cc ON cc.id = ct.comprador_id
          WHERE ct.tenant_id = :t AND ct.status = 'ativo'
          ORDER BY ct.numero", [':t' => vero_tenant()]);
    $colheitas = vero_rows(
        "SELECT r.id, r.data_colheita, r.kg_total_realizado, se.codigo AS valvula,
                r.safra_id, r.setor_id,
                s.identificacao AS safra, f.nome AS fazenda, f.id AS fazenda_id
           FROM colheita_registros r
           LEFT JOIN agro_setores se ON se.id = r.setor_id
           JOIN agro_safras s ON s.id = r.safra_id
           JOIN agro_talhoes t ON t.id = r.talhao_id
           JOIN agro_fazendas f ON f.id = t.fazenda_id
          WHERE r.tenant_id = :t AND r.kg_total_realizado > 0
          ORDER BY r.data_colheita DESC",
        [':t' => vero_tenant()]);

    /* CATÁLOGO safra×válvula do CADASTRO: a
       cascata deixa de depender de colheita — cliente novo classifica a venda
       antes da primeira colheita. Fonte: vínculos agro_safra_talhoes; a
       válvula (setor) conhece o talhão dela (agro_setores.talhao_id). */
    $catalogoSV = vero_rows(
        "SELECT s.id  AS safra_id,  s.identificacao AS safra,
                se.id AS setor_id,  se.codigo       AS valvula,
                se.talhao_id,       f.nome          AS fazenda,
                f.id  AS fazenda_id
           FROM agro_safra_talhoes st
           JOIN agro_safras  s  ON s.id  = st.safra_id  AND s.tenant_id  = st.tenant_id
           JOIN agro_setores se ON se.talhao_id = st.talhao_id
                               AND se.tenant_id = st.tenant_id AND se.ativo = 1
           JOIN agro_talhoes t  ON t.id  = st.talhao_id AND t.tenant_id  = st.tenant_id
           LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
          WHERE st.tenant_id = :t
          ORDER BY s.identificacao, se.codigo",
        [':t' => vero_tenant()]);
    /* T27b: lotes agrícolas COLH- ativos com saldo (o lote da venda em edição entra mesmo zerado) */
    $lotesColh = vero_rows(
        "SELECT l.id, l.codigo_lote, l.quantidade, l.custo_unitario, l.colheita_registro_id,
                p.nome AS produto
           FROM estoque_lotes l
           JOIN estoque_produtos p ON p.id = l.produto_id
          WHERE l.tenant_id = :t AND l.codigo_lote LIKE 'COLH-%' AND l.status = 'disponivel'
            AND (l.quantidade > 0 OR l.id = :le)
          ORDER BY l.codigo_lote",
        [':t' => vero_tenant(), ':le' => $edit && $edit['lote_id'] !== null ? (int)$edit['lote_id'] : 0]);
    /* G-16 (b): kg desta venda (saída ativa) por lote — devolvido ao saldo
       EXIBIDO/validado no seletor, porque a reedição estorna a saída ativa
       antes de reemitir (o saldo REAL disponível inclui o kg da própria venda) */
    $saidaPropria = [];
    if ($edit) {
        foreach (vero_rows(
            "SELECT lote_id, SUM(quantidade) AS kg FROM estoque_movimentacoes
              WHERE tenant_id = :t AND origem_tipo = 'comercial_venda' AND origem_id = :v
                AND tipo = 'saida' AND estornado_em IS NULL AND lote_id IS NOT NULL
              GROUP BY lote_id",
            [':t' => vero_tenant(), ':v' => (int)$edit['id']]) as $sp) {
            $saidaPropria[(int)$sp['lote_id']] = (float)$sp['kg'];
        }
    }
    $classifs = vero_rows(
        "SELECT registro_id, categoria, percentual, preco_kg
           FROM colheita_classificacoes
          WHERE tenant_id = :t AND momento = 'realizado'",
        [':t' => vero_tenant()]);
    /* Pedido do gestor (08/2026): a lista de lotes/colheitas MISTURA fazendas —
       um select de Fazenda no topo da origem agrícola filtra as opções (padrão
       do contas a pagar/receber e do romaneio do app). Fazenda NÃO é campo da
       venda (deriva de colheita→talhão→fazenda), então o filtro é só UI: nada
       muda no POST/validações. O lote deriva a fazenda pela colheita vinculada
       (colheita_registro_id → $colFaz); só entram fazendas COM colheita vendável. */
    $colFaz = [];       /* colheita_registro_id => fazenda_id */
    $fazendasForm = []; /* fazenda_id => nome (deduplicado) */
    foreach ($colheitas as $c) {
        $colFaz[(int)$c['id']] = (int)$c['fazenda_id'];
        $fazendasForm[(int)$c['fazenda_id']] = (string)$c['fazenda'];
    }
    /* 26/08: fazendas do CADASTRO também entram no filtro (cliente sem colheita) */
    foreach ($catalogoSV as $cs) {
        if ($cs['fazenda_id'] !== null) $fazendasForm[(int)$cs['fazenda_id']] = (string)$cs['fazenda'];
    }
    asort($fazendasForm, SORT_NATURAL | SORT_FLAG_CASE);
    /* pré-seleção: a fazenda da colheita da venda em edição (ou do POST preservado) */
    $colPre = (int)($OLD['colheita_registro_id'] ?? ($edit['colheita_registro_id'] ?? 0));
    $fzForm = $colPre && isset($colFaz[$colPre]) ? $colFaz[$colPre] : 0;
} else {
    $page    = max(1, (int)($_GET['pg'] ?? 1));
    $perPage = 15;
    /* UX 19/07 (auditoria seção 4): canceladas FORA da lista por padrão —
       toggle ?canceladas=1 traz a trilha completa com badge (mesmo padrão
       das estornadas em estoque/_mov_base.php). */
    $verCanceladas = (string)($_GET['canceladas'] ?? '') === '1';
    $where  = "v.tenant_id = :t";
    $params = [':t' => vero_tenant()];
    /* Pedido do gestor (08/2026): filtro por fazenda (?fz=). A venda não tem
       fazenda própria — deriva de colheita→talhão→fazenda —, então o filtro
       entra por EXISTS (o mesmo $where serve os COUNTs, que não têm JOIN). */
    $fFaz = max(0, (int)($_GET['fz'] ?? 0));
    if ($fFaz > 0) {
        $where .= " AND EXISTS (SELECT 1 FROM colheita_registros cr
                      JOIN agro_talhoes tal ON tal.id = cr.talhao_id
                     WHERE cr.id = v.colheita_registro_id AND cr.tenant_id = v.tenant_id
                       AND tal.fazenda_id = :fz)";
        $params[':fz'] = $fFaz;
    }
    $fazendasFiltro = vero_rows(
        "SELECT id, nome, ativo FROM agro_fazendas WHERE tenant_id=:t ORDER BY nome",
        [':t' => vero_tenant()]);
    $totalCanceladas = (int)vero_val(
        "SELECT COUNT(*) FROM " . T . " v WHERE {$where} AND v.status = 'cancelada'", $params);
    if (!$verCanceladas) { $where .= " AND v.status <> 'cancelada'"; }
    $total = (int)vero_val("SELECT COUNT(*) FROM " . T . " v WHERE {$where}", $params);
    /* Chip do ciclo: estados REAIS do schema — romaneio (comercial_romaneios,
       vínculo por venda_id) e embarque (comercial_logistica.status: previsto/
       em_transito/entregue/cancelado); recebível = movimentacoes_financeiras
       com origem comercial_venda (à vista) ou grupo_id = movimentacao_id
       (parcelada, títulos sem origem — A3-T14). */
    $rows  = vero_rows(
        "SELECT v.*, c.razao_social, se.codigo AS valvula, s.identificacao AS safra,
                fz.nome AS fz_nome,
                (SELECT COUNT(*) FROM comercial_romaneios rm
                  WHERE rm.tenant_id = v.tenant_id AND rm.venda_id = v.id) AS romaneios,
                (SELECT lg.status FROM comercial_logistica lg
                  WHERE lg.tenant_id = v.tenant_id AND lg.venda_id = v.id
                  ORDER BY lg.id DESC LIMIT 1) AS logistica_status,
                (SELECT COUNT(*) FROM movimentacoes_financeiras mf
                  WHERE mf.tenant_id = v.tenant_id AND mf.status <> 'cancelado'
                    AND ((mf.origem_tipo = 'comercial_venda' AND mf.origem_id = v.id)
                      OR (mf.origem_tipo IS NULL AND mf.grupo_id = v.movimentacao_id))) AS titulos_ativos
           FROM " . T . " v
           LEFT JOIN comercial_compradores c ON c.id = v.comprador_id
           LEFT JOIN agro_setores se ON se.id = v.setor_id
           LEFT JOIN agro_safras s ON s.id = v.safra_id
           LEFT JOIN colheita_registros cr ON cr.id = v.colheita_registro_id AND cr.tenant_id = v.tenant_id
           LEFT JOIN agro_talhoes tal ON tal.id = cr.talhao_id
           LEFT JOIN agro_fazendas fz ON fz.id = tal.fazenda_id
          WHERE {$where}
          ORDER BY v.data_venda DESC, v.id DESC
          LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
        $params);
}

$GUARD      = ['macro' => 'comercial', 'micro' => 'vendas'];
$PAGE_VIEW  = 'comercial_vendas';
$PAGE_TITLE = 'Vendas';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('comercial.vendas.editar');

$badgePag = static function (?string $s): string {
    return match ($s) {
        'pago'      => '<span class="vbadge vb-ok">Pago</span>',
        'pendente'  => '<span class="vbadge vb-warn">Pendente</span>',
        'atrasado'  => '<span class="vbadge vb-off">Atrasado</span>',
        'cancelado' => '<span class="vbadge vb-off">Cancelado</span>',
        default     => '—',
    };
};

/* UX 19/07: chips do CICLO da venda — só estados que o schema TEM:
   1) comercial_vendas.status (enum rascunho/confirmada/faturada/cancelada);
   2) embarque: comercial_logistica.status (enum previsto/em_transito/
      entregue/cancelado) ou, sem frete lançado, a presença de romaneio
      (comercial_romaneios.venda_id); sem nenhum dos dois o elo não aparece
      (nada de estado inventado);
   3) recebimento: comercial_vendas.status_pagamento (badge $badgePag). */
$chipsCiclo = static function (array $r) use ($badgePag): string {
    if ($r['status'] === 'cancelada') {
        return '<span class="vbadge vb-off">Cancelada</span>';
    }
    $chips = [];
    $chips[] = match ((string)$r['status']) {
        'rascunho' => '<span class="vbadge vb-info">Rascunho</span>',
        'faturada' => '<span class="vbadge vb-ok">Faturada</span>',
        default    => '<span class="vbadge vb-ok">Confirmada</span>',
    };
    if ($r['logistica_status'] !== null) {
        $chips[] = match ((string)$r['logistica_status']) {
            'previsto'    => '<span class="vbadge vb-info">Frete previsto</span>',
            'em_transito' => '<span class="vbadge vb-warn">Em trânsito</span>',
            'entregue'    => '<span class="vbadge vb-ok">Entregue</span>',
            'cancelado'   => '<span class="vbadge vb-off">Frete cancelado</span>',
            default       => '<span class="vbadge vb-info">' . h((string)$r['logistica_status']) . '</span>',
        };
    } elseif ((int)$r['romaneios'] > 0) {
        $chips[] = '<span class="vbadge vb-info">Romaneio</span>';
    }
    $chips[] = $badgePag($r['status_pagamento']);
    return implode(' <span class="vhint">›</span> ', $chips);
};

/* UX 19/07: rastreabilidade venda → conta a receber. O título à vista tem
   origem comercial_venda; as parcelas (A3-T14) são SEM origem, agrupadas por
   grupo_id = movimentacao_id da venda. A descrição dos dois casos carrega o
   número da venda, e contas_receber.php filtra por ?q= (descricao LIKE) —
   filtro que a tela JÁ suporta (financeiro/_contas_base.php). */
$linkRecebivel = static function (array $r): string {
    if ($r['status'] === 'cancelada') {
        return '<span class="vhint" title="O cancelamento da venda cancela os títulos — o razão é preservado">título cancelado c/ a venda</span>';
    }
    if ($r['movimentacao_id'] === null || (int)$r['titulos_ativos'] === 0) {
        return '<span class="vhint" title="Nenhum título ativo em Contas a Receber para esta venda">sem título gerado</span>';
    }
    $urlCR = rtrim(BIOS_BASE, '/') . '/financeiro/contas_receber?q=' . urlencode((string)$r['numero']);
    return '<a href="' . h($urlCR) . '" title="Abrir Contas a Receber filtrado pelos títulos desta venda">ver recebível ('
        . (int)$r['titulos_ativos'] . ($r['titulos_ativos'] > 1 ? ' parcelas' : ' título') . ') →</a>';
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>

<?php if (!$modoForm): ?>
  <div class="vhead">
    <div>
      <h1>Vendas</h1>
      <div class="vsub">Comercialização amarrada à colheita — cada venda gera a conta a receber automaticamente</div>
    </div>
    <?php if ($podeEditar): ?>
      <a class="vbtn vbtn-primary" href="?novo=1">+ Nova venda</a>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar">
      <span class="vsub"><?= $total ?> registro(s)<?= !$verCanceladas && $totalCanceladas > 0
          ? ' — ' . $totalCanceladas . ' cancelada(s) oculta(s)' : '' ?></span>
      <span style="flex:1"></span>
      <!-- UX 19/07: canceladas fora por padrão (padrão das estornadas do estoque) -->
      <form method="get" style="display:inline-flex;align-items:center;gap:10px">
        <?php if ($fazendasFiltro): /* 08/2026: filtro por fazenda (mesmo form → compõe com o toggle) */ ?>
        <select name="fz" aria-label="Filtrar por fazenda" onchange="this.form.submit()">
          <option value="">Todas as fazendas</option>
          <?php foreach ($fazendasFiltro as $f): if ((int)$f['ativo'] !== 1 && (int)$f['id'] !== $fFaz) continue; ?>
            <option value="<?= (int)$f['id'] ?>"<?= (int)$f['id'] === $fFaz ? ' selected' : '' ?>><?= h((string)$f['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#6B5F53;white-space:nowrap;cursor:pointer">
          <input type="checkbox" name="canceladas" value="1" style="width:auto" onchange="this.form.submit()"<?= $verCanceladas ? ' checked' : '' ?>>
          Incluir canceladas
          <?php if ($totalCanceladas > 0): ?><span class="vbadge vb-off"><?= $totalCanceladas ?></span><?php endif; ?>
        </label>
        <?php if ($fFaz > 0): ?><a class="vbtn vbtn-ghost vbtn-sm" href="?" title="Limpar filtros">Limpar</a><?php endif; ?>
      </form>
    </div>
    <?php if (!$rows): ?>
      <div class="vempty"><?= $totalCanceladas > 0 && !$verCanceladas
          ? 'Nenhuma venda ativa — ' . $totalCanceladas . ' cancelada(s) oculta(s), marque "Incluir canceladas" para vê-la(s).'
          : ($fFaz > 0 ? 'Nenhuma venda desta fazenda.' : 'Nenhuma venda registrada.') ?></div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Nº</th><th>Data</th><th>Comprador</th><th>Válvula</th><th>Safra</th>
        <th style="text-align:right">kg</th>
        <th style="text-align:right">Valor (R$)</th>
        <th>Vencimento</th><th>Ciclo · Recebível</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'cancelada' ? ' style="opacity:.55"' : '' ?>>
          <td><strong class="vnum"><?= h($r['numero']) ?></strong></td>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_venda'])) ?></td>
          <td><?= h($r['razao_social'] ?? $r['cliente'] ?? '—') ?></td>
          <td class="vnum"><?= h($r['valvula'] ?? '—') ?>
            <?php /* 08/2026: fazenda da venda — linha discreta, sem coluna nova
                     (mesmo padrão do contas a pagar/receber) */ ?>
            <?php if ($r['fz_nome'] !== null): ?>
              <div class="vhint" title="Fazenda: <?= h((string)$r['fz_nome']) ?>"><?= h(mb_substr((string)$r['fz_nome'], 0, 16)) ?></div>
            <?php endif; ?></td>
          <td><?= h($r['safra'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['kg_total'], 0) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['valor_total'], 2) ?></strong></td>
          <td class="vnum"><?= $r['data_vencimento'] ? date('d/m/Y', strtotime((string)$r['data_vencimento'])) : '—' ?></td>
          <td style="white-space:nowrap"><?= $chipsCiclo($r) ?>
            <div style="margin-top:3px;font-size:11.5px"><?= $linkRecebivel($r) ?></div></td>
          <td><div class="vactions">
            <?php if ($podeEditar && $r['status'] !== 'cancelada'): ?>
              <?= vero_btn_editar((int)$r['id']) ?>
            <?php endif; ?>
            <?php if (vero_can('comercial.vendas.excluir') && $r['status'] !== 'cancelada'): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Cancelar esta venda? A conta a receber será cancelada (o razão é preservado).') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>

<?php else: ?>
  <?php if (!$podeEditar): ?>
    <div class="vflash vflash-erro">Sem permissão para registrar vendas.</div>
  <?php else: ?>
  <div class="vhead">
    <div>
      <h1><?= $edit ? 'Venda ' . h($edit['numero']) : 'Nova venda' ?></h1>
      <div class="vsub">Selecione a colheita: qualidades e preços são pré-carregados do realizado; digite o kg por categoria e a % é calculada</div>
    </div>
    <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/comercial/vendas">← Voltar à lista</a>
  </div>

  <form method="post" id="f-venda">
    <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">

    <div class="vcard" style="margin-bottom:16px">
      <div class="vtoolbar"><strong style="font-size:14px">Comprador &amp; contrato</strong></div>
      <div style="padding:16px 18px">
      <div class="vgrid" style="grid-template-columns:repeat(2,1fr)">
        <div class="vfield">
          <label>Comprador *</label>
          <select name="comprador_id" required>
            <option value="">— Selecione —</option>
            <?php foreach ($compradores as $cid => $cn): ?>
              <option value="<?= $cid ?>"<?= $osel('comprador_id', $cid, $edit ? (int)$edit['comprador_id'] : null) ?>><?= h($cn) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$compradores): ?><div class="vhint">Cadastre em Comercial → Compradores.</div><?php endif; ?>
        </div>
        <div class="vfield">
          <label>Contrato de pré-venda (opcional)</label>
          <select name="contrato_id">
            <option value="">— Sem contrato —</option>
            <?php foreach ($contratosAtivos as $ct): ?>
              <option value="<?= (int)$ct['id'] ?>"<?= $osel('contrato_id', (int)$ct['id'], $edit ? (int)($edit['contrato_id'] ?? 0) : null) ?>>
                <?= h((string)$ct['numero']) ?> — <?= h((string)$ct['comprador']) ?>
                (saldo <?= numFmt((float)$ct['kg_contratado'] - (float)$ct['kg_faturado'], 0) ?> kg
                a R$ <?= numFmt((float)$ct['preco_kg'], 2) ?>/kg)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      </div>

      <div class="vtoolbar" style="border-top:1px solid #EEE8DB"><strong style="font-size:14px">Origem agrícola</strong></div>
      <div style="padding:16px 18px">
      <?php /* 08/2026: a fazenda FILTRA lote/colheita (só UI —
               não é campo da venda, por isso o select não tem name e não entra
               no POST). Só aparece quando há mais de uma fazenda com colheita. */
      $temFiltroFz = count($fazendasForm) > 1; ?>
      <div class="vgrid" style="grid-template-columns:<?= $temFiltroFz ? 'minmax(150px,.9fr) 1.3fr 1.3fr .9fr' : 'repeat(3,1fr)' ?>">
        <?php if ($temFiltroFz): ?>
        <div class="vfield">
          <label>Fazenda</label>
          <select id="f-fazenda" aria-label="Filtrar lote e colheita por fazenda">
            <option value="">— Todas —</option>
            <?php foreach ($fazendasForm as $fid => $fnome): ?>
              <option value="<?= (int)$fid ?>"<?= (int)$fid === $fzForm ? ' selected' : '' ?>><?= h($fnome) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="vfield">
          <?php /* 08/2026: lote OPCIONAL na venda nova (e na legada). Só a
                   reedição de venda que JÁ tem lote mantém o required — trocar
                   é permitido, remover não (preserva a baixa de estoque). */ ?>
          <label>Lote agrícola (COLH-)<?= $edit && $edit['lote_id'] !== null ? ' *' : '' ?></label>
          <select name="lote_id" id="f-lote"<?= $edit && $edit['lote_id'] !== null ? ' required' : '' ?>>
            <?php if ($edit && $edit['lote_id'] !== null): ?>
              <option value="">— Selecione o lote —</option>
            <?php else: ?>
              <option value="">— Sem lote (não baixa estoque) —</option>
            <?php endif; ?>
            <?php foreach ($lotesColh as $lt):
                /* G-16 (b): saldo REAL = quantidade do lote + kg da própria
                   venda em edição (a reedição estorna antes de reemitir) */
                $kgProprio = $saidaPropria[(int)$lt['id']] ?? 0.0;
                $saldoReal = (float)$lt['quantidade'] + $kgProprio; ?>
              <option value="<?= (int)$lt['id'] ?>" data-colheita="<?= (int)$lt['colheita_registro_id'] ?>"
                data-saldo="<?= $saldoReal ?>"
                data-fazenda="<?= (int)($colFaz[(int)$lt['colheita_registro_id']] ?? 0) ?>"
                <?= $osel('lote_id', (int)$lt['id'], $edit ? (int)($edit['lote_id'] ?? 0) : null) ?>>
                <?= h($lt['codigo_lote']) ?> — <?= h($lt['produto']) ?> (saldo <?= numFmt($saldoReal, 0) ?> kg<?= $kgProprio > 0 ? ', inclui os ' . numFmt($kgProprio, 0) . ' kg desta venda' : '' ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$lotesColh && !($edit && $edit['lote_id'] === null)): /* A3-T33 (R2-01): CTA quando não há lote */ ?>
            <div class="vhint" style="margin-top:6px">
              Nenhum lote agrícola com saldo — dá para vender sem lote (não baixa estoque) ou
              <a class="vbtn vbtn-primary vbtn-sm" href="<?= rtrim(BIOS_BASE, '/') ?>/agro/colheita" style="margin-left:6px">Confirmar entrada da colheita →</a>
            </div>
          <?php endif; /* hints explicativos do lote retirados (gestor 26/08) */ ?>
        </div>
        <?php /* 26/08: cascata Safra → Válvula → Colheita. Fonte dos dois
                 primeiros = CADASTRO (vínculos safra×talhão) — cliente sem
                 colheita classifica a venda; COM colheita a classificação
                 segue derivada dela (os selects só posicionam). safra_id e
                 setor_id POSTam e valem QUANDO não há colheita. */
        $safraPre = (int)($OLD['safra_id'] ?? ($edit['safra_id'] ?? 0));
        $setorPre = (int)($OLD['setor_id'] ?? ($edit['setor_id'] ?? 0));
        $safrasCat = [];
        foreach ($catalogoSV as $cs) { $safrasCat[(int)$cs['safra_id']] = (string)$cs['safra']; }
        ?>
        <div class="vfield">
          <label>Safra</label>
          <select name="safra_id" id="f-col-safra" onchange="document.getElementById('f-col-valvula').value='';colCascata()">
            <option value="">— Sem safra —</option>
            <?php foreach ($safrasCat as $sid => $snome): ?>
              <option value="<?= $sid ?>"<?= $sid === $safraPre ? ' selected' : '' ?>><?= h($snome) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Válvula</label>
          <select name="setor_id" id="f-col-valvula" onchange="colCascata()">
            <option value="">— Sem válvula —</option>
            <?php foreach ($catalogoSV as $cs): ?>
              <option value="<?= (int)$cs['setor_id'] ?>"<?= (int)$cs['setor_id'] === $setorPre ? ' selected' : '' ?>>
                <?= h((string)$cs['fazenda']) ?> — <?= h((string)$cs['valvula']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Colheita (data)</label>
          <select name="colheita_registro_id" id="f-colheita">
            <option value="">— Sem colheita —</option>
            <?php foreach ($colheitas as $c): ?>
              <option value="<?= (int)$c['id'] ?>"
                <?= $osel('colheita_registro_id', (int)$c['id'], $edit ? (int)$edit['colheita_registro_id'] : null) ?>>
                <?= date('d/m/Y', strtotime((string)$c['data_colheita'])) ?> — <?= h($c['fazenda']) ?> · <?= h($c['valvula'] ?? '—') ?> (<?= numFmt((float)$c['kg_total_realizado'], 0) ?> kg)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>kg comercializados *</label>
          <input type="text" name="kg_total" id="f-kg" style="text-align:right" required
                 value="<?= h($ov('kg_total', $edit ? numFmt((float)$edit['kg_total'], 0) : '')) ?>">
          <?php /* h-kg: vazio por padrão (gestor 26/08); o JS só o preenche com
                   o dado DINÂMICO do lote ("Disponível no lote: X kg") */ ?>
          <div class="vhint" id="h-kg"></div>
        </div>
      </div>
      </div>

      <div class="vtoolbar" style="border-top:1px solid #EEE8DB"><strong style="font-size:14px">Financeiro</strong>
        
      </div>
      <div style="padding:16px 18px">
      <div class="vgrid" style="grid-template-columns:repeat(3,1fr)">
        <div class="vfield">
          <label>Data da venda *</label>
          <input type="date" name="data_venda" required
                 value="<?= h($ov('data_venda', $edit ? (string)$edit['data_venda'] : date('Y-m-d'))) ?>">
        </div>
        <div class="vfield">
          <label>Vencimento (conta a receber<?= $edit ? '' : ' / 1ª parcela' ?>)</label>
          <input type="date" name="data_vencimento"
                 value="<?= h($ov('data_vencimento', $edit['data_vencimento'] ?? '')) ?>">
        </div>
        <div class="vfield">
          <label>Parcelas</label>
          <select name="parcelas">
            <?php for ($pi = 1; $pi <= 12; $pi++): ?>
              <option value="<?= $pi ?>"<?= $osel('parcelas', $pi, $editParcelas) ?>><?= $pi ?>×</option>
            <?php endfor; ?>
          </select>
          
        </div>
        <div class="vfield" style="grid-column:1/-1">
          <label>Observação</label>
          <input type="text" name="observacao" value="<?= h($ov('observacao', $edit['observacao'] ?? '')) ?>">
        </div>
      </div>
      </div>
    </div>

    <div class="vcard" style="margin-bottom:16px">
      <div class="vtoolbar"><strong style="font-size:14px">Qualidades comercializadas</strong>
        
        <div style="flex:1"></div>
        <span class="vsub">Σ = <span id="soma-q">—</span></span>
        <button type="button" class="vbtn vbtn-ghost vbtn-sm" onclick="addQ()" style="margin-left:12px">+ Categoria</button>
      </div>
      <table class="vtable" id="q-table">
        <thead><tr>
          <th style="min-width:160px">Categoria</th>
          <th style="width:130px;text-align:right">kg</th>
          <th style="width:130px;text-align:right">Preço/kg (R$)</th>
          <th style="width:90px;text-align:right">%</th>
          <th style="width:150px;text-align:right">Valor (R$)</th>
          <th style="width:44px"></th>
        </tr></thead>
        <tbody id="q-body"></tbody>
        <tfoot><tr>
          <td style="text-align:right;font-weight:600">Total da venda</td>
          <td class="vnum q-kg-total" style="text-align:right" id="tot-kg">—</td>
          <td></td>
          <td class="vnum" style="text-align:right" id="tot-pct">—</td>
          <td class="vnum" style="text-align:right;font-weight:700" id="tot-valor">0,00</td>
          <td></td>
        </tr></tfoot>
      </table>
      <div class="vempty" id="q-vazio" style="display:none">Nenhuma categoria — escolha a colheita/lote para pré-carregar do realizado, ou clique <strong>+ Categoria</strong>.</div>
    </div>

    <!-- A-01 (G-16): erro de validação aparece AQUI, inline — o form não
         recarrega e nada do que foi digitado se perde; o servidor segue
         como rede final (Σ kg ≤ kg comercializados e kg ≤ saldo do lote). -->
    <div id="venda-erro" role="alert" style="display:none;margin-bottom:12px;padding:10px 12px;border-radius:8px;background:#FBEDE9;color:#B3402A;font-size:12.5px;font-weight:600"></div>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:16px">
      <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/comercial/vendas">Cancelar</a>
      <button class="vbtn vbtn-primary" type="submit"><?= $edit ? 'Salvar alterações' : 'Salvar venda e gerar conta a receber' ?></button>
    </div>
  </form>

  <?php if ($edit):
      $receita = (float)$edit['valor_total'];
      $margemPct = $receita > 0 && $margemLiq !== null ? $margemLiq / $receita * 100 : null; ?>
  <div class="vcard" style="margin-bottom:16px">
    <div class="vtoolbar"><strong style="font-size:14px">Despesas de comercialização &amp; margem real (F1)</strong>
      <span class="vhint">frete, comissão, embalagem, imposto… entram no resultado — margem líquida = receita − CPV do lote − despesas</span>
      <?php if ($precoRef !== null): ?>
        <span style="flex:1"></span>
        <span class="vbadge vb-info" title="<?= h(preco_rotulo_regra($precoRef)) ?>">Preço de tabela (F2): <?= h((string)$precoRef['moeda']) ?> <?= numFmt((float)$precoRef['preco'], 4) ?> — <?= h(preco_rotulo_regra($precoRef)) ?></span>
      <?php endif; ?>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><div class="vkpi-l">Receita</div><div class="vkpi-v vnum">R$ <?= numFmt($receita, 2) ?></div></div>
      <div class="vkpi"><div class="vkpi-l">(−) CPV do lote</div><div class="vkpi-v vnum">R$ <?= numFmt($vendaCpv, 2) ?></div></div>
      <div class="vkpi"><div class="vkpi-l">(−) Despesas</div><div class="vkpi-v vnum">R$ <?= numFmt($despTotal, 2) ?></div></div>
      <div class="vkpi"><div class="vkpi-l">= Margem líquida</div>
        <div class="vkpi-v vnum" style="color:<?= ($margemLiq ?? 0) >= 0 ? '#0E7E72' : '#B23A2E' ?>">
          R$ <?= $margemLiq !== null ? numFmt($margemLiq, 2) : '—' ?><?= $margemPct !== null ? ' <span class="vhint">(' . numFmt($margemPct, 1) . '%)</span>' : '' ?></div></div>
    </div>
    <?php if ($vendaCpv <= 0): ?>
      <div class="vhint" style="padding:0 14px 8px;color:#B57C1A">⚠ CPV zero — venda legada sem lote; a margem ignora o custo do produto.</div>
    <?php endif; ?>
    <?php if ($despesas): ?>
    <table class="vtable">
      <thead><tr><th>Tipo</th><th>Base</th><th>Descrição</th><th style="text-align:right">Valor (R$)</th><?php if ($podeEditar): ?><th style="text-align:right">Ação</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($despesas as $d): ?>
        <tr>
          <td><?= h((string)($d['tipo_nome'] ?? '—')) ?></td>
          <td><span class="vhint"><?= h(DESPESA_BASES[(string)$d['base']] ?? (string)$d['base']) ?><?= $d['base'] === 'percentual' && $d['percentual'] !== null ? ' (' . numFmt((float)$d['percentual'], 2) . '%)' : '' ?></span></td>
          <td><?= $d['descricao'] ? h((string)$d['descricao']) : '<span class="vhint">—</span>' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$d['valor'], 2) ?></td>
          <?php if ($podeEditar): ?>
          <td style="text-align:right">
            <form method="post" style="display:inline" onsubmit="return confirm('Remover esta despesa?')">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="remove_despesa">
              <input type="hidden" name="venda_id" value="<?= (int)$edit['id'] ?>">
              <input type="hidden" name="despesa_id" value="<?= (int)$d['id'] ?>">
              <button class="vicon vicon-del" type="submit" title="Remover despesa" aria-label="Remover despesa"><?= vero_ico_lixeira() ?></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <div class="vhint" style="padding:0 14px 10px">Nenhuma despesa lançada — a margem acima é só receita − CPV.</div>
    <?php endif; ?>
    <?php if ($podeEditar && $edit['status'] !== 'cancelada'): ?>
    <form method="post" class="vtoolbar" style="gap:8px;flex-wrap:wrap;align-items:end;padding:12px 14px;border-top:1px solid #E3D9C8">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="add_despesa">
      <input type="hidden" name="venda_id" value="<?= (int)$edit['id'] ?>">
      <div class="vfield" style="min-width:150px"><label>Tipo</label>
        <select name="tipo_despesa_id" onchange="var b=this.options[this.selectedIndex].dataset.base; if(b){this.form.base.value=b;}">
          <option value="">— livre</option>
          <?php foreach ($tiposDespesa as $tp): ?>
            <option value="<?= (int)$tp['id'] ?>" data-base="<?= h((string)$tp['base']) ?>"><?= h((string)$tp['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vfield" style="min-width:140px"><label>Base</label>
        <select name="base">
          <?php foreach (DESPESA_BASES as $k => $lab): ?><option value="<?= $k ?>"><?= h($lab) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="vfield" style="min-width:110px"><label>Valor / %</label><input type="text" name="input" placeholder="ex.: 5 ou 250"></div>
      <div class="vfield" style="min-width:160px"><label>Descrição (opcional)</label><input type="text" name="descricao" maxlength="120"></div>
      <button class="vbtn vbtn-primary vbtn-sm" type="submit">+ Adicionar despesa</button>
    </form>
    <div class="vhint" style="padding:0 14px 10px">% da receita (ex.: comissão 5%), valor fixo (ex.: frete R$ 250) ou R$ por kg (ex.: embalagem R$ 0,30/kg × <?= numFmt((float)$edit['kg_total'], 0) ?> kg). Insert-only; remover é correção.</div>
    <?php endif; ?>
  </div>

  <div class="vcard" style="margin-bottom:16px">
    <div class="vtoolbar"><strong style="font-size:14px">Anexos (NF, boleto…)</strong></div>
    <?php if ($anexos): ?>
    <table class="vtable">
      <thead><tr><th>Tipo</th><th>Arquivo</th><th style="text-align:right">Tamanho</th><th>Enviado em</th><th style="text-align:right">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($anexos as $a): ?>
        <tr>
          <td><span class="vbadge vb-info"><?= h(strtoupper((string)$a['tipo_arquivo'])) ?></span></td>
          <td><a href="<?= BIOS_BASE . h($a['url']) ?>" target="_blank"><?= h($a['nome_original']) ?></a></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$a['tamanho_bytes'] / 1024, 0) ?> KB</td>
          <td class="vhint"><?= date('d/m/Y H:i', strtotime((string)$a['created_at'])) ?></td>
          <td><div class="vactions">
            <form method="post" onsubmit="return confirm('Remover este anexo?')">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="excluir_anexo">
              <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <input type="hidden" name="anexo_id" value="<?= (int)$a['id'] ?>">
              <button class="vicon vicon-del" type="submit" title="Remover anexo" aria-label="Remover anexo"><?= vero_ico_lixeira() ?></button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <div class="vempty">Nenhum anexo — envie a NF e o boleto abaixo.</div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="vtoolbar" style="border-top:1px solid #EEE8DB;border-bottom:0">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="anexar">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <select name="tipo_doc">
        <option value="nf">Nota fiscal</option>
        <option value="boleto">Boleto</option>
        <option value="outro">Outro</option>
      </select>
      <input type="file" name="arquivo" accept=".pdf,.jpg,.jpeg,.png" required style="font-size:12.5px">
      <button class="vbtn vbtn-ghost" type="submit">Anexar arquivo</button>
      <span class="vhint">PDF/JPG/PNG até 5 MB</span>
    </form>
  </div>
  <?php endif; ?>

  <script>
  const COLHEITAS = <?= jsvar(array_map(static fn($c) => [
      'id' => (int)$c['id'], 'kg' => (float)$c['kg_total_realizado'],
      /* cascata Safra → Válvula → Colheita (26/08) — filtro por IDs do cadastro */
      'saId' => (int)$c['safra_id'], 'seId' => $c['setor_id'] !== null ? (int)$c['setor_id'] : 0,
      'vl' => (string)($c['valvula'] ?? '—'),
      'fz' => (string)$c['fazenda'], 'fzId' => (int)$c['fazenda_id'],
      'dt' => date('d/m/Y', strtotime((string)$c['data_colheita'])),
  ], $colheitas)) ?>;
  /* catálogo safra×válvula do CADASTRO (agro_safra_talhoes) — a cascata
     funciona sem nenhuma colheita lançada */
  const CATALOGO = <?= jsvar(array_map(static fn($cs) => [
      'saId' => (int)$cs['safra_id'], 'sa' => (string)$cs['safra'],
      'seId' => (int)$cs['setor_id'], 'vl' => (string)$cs['valvula'],
      'fz' => (string)$cs['fazenda'], 'fzId' => $cs['fazenda_id'] !== null ? (int)$cs['fazenda_id'] : 0,
  ], $catalogoSV)) ?>;
  const CLASSIFS = <?= jsvar(array_map(static fn($c) => [
      'registro' => (int)$c['registro_id'], 'cat' => $c['categoria'],
      'pct' => (float)$c['percentual'], 'preco' => (float)$c['preco_kg'],
  ], $classifs)) ?>;
  const QUALS = <?= jsvar(QUALIDADES) ?>;
  const EDIT_QUALS = <?= jsvar(array_map(static fn($q) => [
      /* kg gravado é o prefill (fluxo invertido); registros muito antigos com
         kg zerado caem no fallback pela % (mesmo número da tela antiga) */
      'cat' => (string)$q['categoria'], 'kg' => (float)$q['kg'],
      'pct' => (float)$q['percentual'], 'preco' => (float)$q['preco_kg'],
  ], $editQuals)) ?>;
  const IS_EDIT = <?= $edit ? 'true' : 'false' ?>;
  /* UX (25/07): linhas de qualidade preservadas após recusa do servidor (valores
     crus digitados pelo usuário — têm prioridade sobre o pré-carregado da colheita). */
  const OLD_QUALS = <?= jsvar($oldQuals ?? []) ?>;

  const $id = s => document.getElementById(s);
  const fmt = (n, d = 2) => n.toLocaleString('pt-BR', {minimumFractionDigits: d, maximumFractionDigits: d});
  const dec = v => {
    v = String(v || '').trim();
    if (!v) return 0;
    if (v.includes(',')) v = v.replaceAll('.', '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(v)) v = v.replaceAll('.', '');
    const n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  };

  /* ── qualidades DINÂMICAS (padrão addLinha do apontamento) ────── */
  function catOptions(sel) {
    return Object.entries(QUALS).map(([k, v]) =>
      `<option value="${k}"${k === sel ? ' selected' : ''}>${v}</option>`).join('');
  }
  function usedCats() {
    return [...document.querySelectorAll('#q-body .q-cat')].map(s => s.value);
  }
  function firstFreeCat() {
    const used = usedCats();
    return Object.keys(QUALS).find(k => !used.includes(k)) || Object.keys(QUALS)[0];
  }
  /* kg fracionado sem zeros à direita (padrão colheita b411ecd) */
  const fmtKg = n => fmt(n, 3).replace(/(,\d*?)0+$/, '$1').replace(/,$/, '');
  /* Fluxo invertido (08/2026): pré-carga que chega em % (classificação da
     colheita / registro antigo) vira kg = % × kg comercializados. Enquanto o
     usuário NÃO digitar o kg da linha (data-pct presente), o kg pré-carregado
     acompanha mudanças no total — digitou, a linha "desata" e o kg é dele. */
  function aplicaPctPendente(tr) {
    const kgTot = dec($id('f-kg').value);
    if (!tr.dataset.pct || kgTot <= 0) return;
    tr.querySelector('.q-kg-in').value = fmtKg(kgTot * parseFloat(tr.dataset.pct) / 100);
  }
  function addQ(preset) {
    const tb = $id('q-body');
    const cat = preset && preset.cat ? preset.cat : firstFreeCat();
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><select name="q_cat[]" class="q-cat">${catOptions(cat)}</select></td>
      <td><input type="text" name="q_kg[]" class="q-kg-in" style="text-align:right" placeholder="0"></td>
      <td><input type="text" name="q_preco[]" class="q-preco" style="text-align:right" placeholder="0,00"></td>
      <td class="vnum q-pct-out" style="text-align:right" title="Calculada: kg da categoria ÷ kg comercializados">—</td>
      <td class="vnum q-valor" style="text-align:right">—</td>
      <td style="text-align:center"><button type="button" class="vclose" title="Remover categoria" onclick="this.closest('tr').remove(); recalc()">×</button></td>`;
    tb.appendChild(tr);
    if (preset) {
      if (preset.kg > 0) tr.querySelector('.q-kg-in').value = fmtKg(preset.kg);
      else if (preset.pct > 0) { tr.dataset.pct = preset.pct; aplicaPctPendente(tr); }
      if (preset.preco) tr.querySelector('.q-preco').value = fmt(preset.preco, 2);
    }
    tr.querySelectorAll('input, select').forEach(el => el.addEventListener('input', recalc));
    /* digitar o kg à mão desata a linha da % pré-carregada */
    tr.querySelector('.q-kg-in').addEventListener('input', () => { delete tr.dataset.pct; });
    recalc();
  }
  function precarregar() {
    const cid = parseInt($id('f-colheita').value || '0', 10);
    const col = COLHEITAS.find(c => c.id === cid);
    if (!col) return;
    $id('f-kg').value = fmt(col.kg, 0);
    /* pré-carrega as categorias classificadas no realizado da colheita
       (substitui as linhas atuais); sem classificação mantém as linhas).
       A classificação vem em % — addQ converte p/ kg sobre o total recém-
       preenchido acima (e segue o total até o usuário digitar o kg). */
    const cls = CLASSIFS.filter(c => c.registro === cid);
    if (cls.length) {
      $id('q-body').innerHTML = '';
      cls.forEach(c => addQ({ cat: c.cat, pct: c.pct, preco: c.preco }));
    }
    recalc();
  }
  function recalc() {
    const kg = dec($id('f-kg').value);
    let somaPct = 0, somaKg = 0, somaValor = 0;
    document.querySelectorAll('#q-body tr').forEach(tr => {
      /* Fluxo invertido (08/2026): o kg é o digitado; a % é DERIVADA
         (kg ÷ kg comercializados) — espelho do handler PHP, sem valor legal */
      const kgCat = dec(tr.querySelector('.q-kg-in').value);
      const preco = dec(tr.querySelector('.q-preco').value);
      const pct = kg > 0 ? kgCat / kg * 100 : 0;
      const valor = kgCat * preco;
      somaPct += pct; somaKg += kgCat; somaValor += valor;
      tr.querySelector('.q-pct-out').textContent = kgCat > 0 ? (kg > 0 ? fmt(pct, 1) + '%' : '?') : '—';
      tr.querySelector('.q-valor').textContent = kgCat > 0 ? fmt(valor) : '—';
    });
    const sEl = $id('soma-q');
    /* A-01 (G-16): aviso VIVO além da cor — o submit também bloqueia (Σ kg ≤ kg total) */
    const excede = kg > 0 && somaKg > kg + 0.001;
    sEl.textContent = somaKg > 0
      ? fmt(somaKg, 0) + ' kg' + (kg > 0 ? ' (' + fmt(somaPct, 1) + '%)' : '')
        + (excede ? ' — PASSA DOS KG COMERCIALIZADOS, o salvamento será recusado' : '')
      : '—';
    sEl.style.color = excede ? '#9A3B2A' : '';
    sEl.style.fontWeight = excede ? '700' : '';
    $id('tot-kg').textContent = somaKg > 0 ? fmt(somaKg, 0) : '—';
    $id('tot-pct').textContent = somaKg > 0 && kg > 0 ? fmt(somaPct, 1) + '%' : '—';
    $id('tot-valor').textContent = fmt(somaValor);
    const vazio = $id('q-vazio');
    if (vazio) vazio.style.display = document.querySelectorAll('#q-body tr').length ? 'none' : '';
  }

  /* ── Cascata Safra → Válvula → Colheita (26/08): remonta os 3 selects a
     partir de COLHEITAS, respeitando o filtro de fazenda; preserva a seleção
     quando ela sobrevive ao filtro. Só #f-colheita POSTa. ── */
  function colMonta(sel, pares, vazioLabel) {
    const manter = sel.value;
    sel.innerHTML = '';
    const o0 = document.createElement('option');
    o0.value = ''; o0.textContent = vazioLabel; sel.appendChild(o0);
    pares.forEach(([val, txt]) => {
      const o = document.createElement('option');
      o.value = val; o.textContent = txt; sel.appendChild(o);
    });
    sel.value = [...sel.options].some(o => o.value === manter) ? manter : '';
  }
  function colCascata() {
    const sa = $id('f-col-safra'), vl = $id('f-col-valvula'), co = $id('f-colheita');
    if (!sa || !vl || !co) return;
    const fzSel = $id('f-fazenda');
    const fz = fzSel ? fzSel.value : '';
    const antes = co.value;
    /* safra e válvula vêm do CADASTRO (funciona sem colheita alguma) */
    const cat = CATALOGO.filter(c => fz === '' || String(c.fzId) === fz);
    const saUniq = [];
    cat.forEach(c => { if (!saUniq.some(s => s[0] === String(c.saId))) saUniq.push([String(c.saId), c.sa]); });
    colMonta(sa, saUniq, '— Sem safra —');
    const cat1 = cat.filter(c => sa.value === '' || String(c.saId) === sa.value);
    const vlUniq = [];
    cat1.forEach(c => { if (!vlUniq.some(v => v[0] === String(c.seId))) vlUniq.push([String(c.seId), c.fz + ' — ' + c.vl]); });
    colMonta(vl, vlUniq, '— Sem válvula —');
    /* colheitas filtradas pelos IDs escolhidos (e pela fazenda) */
    const f2 = COLHEITAS.filter(c =>
      (fz === '' || String(c.fzId) === fz)
      && (sa.value === '' || String(c.saId) === sa.value)
      && (vl.value === '' || String(c.seId) === vl.value));
    colMonta(co, f2.map(c => [String(c.id), c.dt + ' — ' + c.fz + ' · ' + c.vl + ' (' + fmt(c.kg, 0) + ' kg)']), '— Sem colheita —');
    /* filtrou até sobrar UMA colheita: seleciona sozinho */
    if (co.value === '' && f2.length === 1 && (sa.value !== '' || vl.value !== '')) co.value = String(f2[0].id);
    if (co.value !== antes) precarregar();
  }
  /* seleciona uma colheita específica (lote/edição) posicionando a cascata nela */
  function colSelecionar(cid) {
    const m = COLHEITAS.find(c => c.id === parseInt(cid, 10));
    if (!m) return;
    $id('f-col-safra').value = String(m.saId);
    colCascata();
    $id('f-col-valvula').value = m.seId ? String(m.seId) : '';
    colCascata();
    $id('f-colheita').value = String(cid);
    precarregar();
  }
  $id('f-colheita').addEventListener('change', precarregar);
  /* T27b: escolher o lote deriva a colheita (e dispara o pré-carregamento) */
  const fLote = $id('f-lote');
  if (fLote) fLote.addEventListener('change', () => {
    const opt = fLote.options[fLote.selectedIndex];
    const cid = opt ? opt.dataset.colheita : '';
    if (cid) { colSelecionar(cid); }
    /* T33 (R2-01): o balanço de kg segue o SALDO DO LOTE, não o realizado da colheita */
    const hkg = $id('h-kg');
    if (hkg) hkg.textContent = opt && opt.dataset.saldo
      ? 'Disponível no lote: ' + fmt(parseFloat(opt.dataset.saldo), 0) + ' kg (baixa do estoque ao salvar)'
      : '';
  });
  /* Pedido do gestor (08/2026): a fazenda FILTRA as opções de lote e de
     colheita (a lista mistura fazendas). Só UI — nada muda no POST: a
     fazenda da venda continua derivando de colheita→talhão→fazenda.
     Esconde E desabilita (teclado/mobile não escolhem opção oculta);
     se a seleção atual ficar fora do filtro, volta ao placeholder. */
  const fFaz = $id('f-fazenda');
  function filtraFazenda() {
    const fz = fFaz.value;
    /* lote: esconde/desabilita fora da fazenda (colheita agora é remontada
       pela CASCATA, que já respeita este filtro) */
    [fLote].forEach(sel => {
      if (!sel) return;
      [...sel.options].forEach(opt => {
        const fora = fz !== '' && opt.value !== '' && opt.dataset.fazenda !== fz;
        opt.hidden = fora;
        opt.disabled = fora;
      });
      const cur = sel.options[sel.selectedIndex];
      if (cur && cur.disabled) sel.value = '';
    });
    colCascata();
  }
  if (fFaz) {
    fFaz.addEventListener('change', filtraFazenda);
    filtraFazenda(); /* edição/POST preservado nascem já filtrados pela fazenda da colheita */
  }
  /* cascata nasce posicionada: colheita pré-selecionada (edição/POST) define
     safra e válvula; sem seleção, monta as listas completas */
  (function () {
    const cid = parseInt($id('f-colheita').value || '0', 10);
    if (cid) colSelecionar(cid); else colCascata();
  })();
  $id('f-kg').addEventListener('input', () => {
    /* linhas pré-carregadas em % seguem o novo total até o usuário digitar o kg */
    document.querySelectorAll('#q-body tr').forEach(aplicaPctPendente);
    recalc();
  });
  /* seed: após recusa do servidor, repovoa EXATAMENTE o que o usuário digitou
     (OLD_QUALS, valores crus). Senão, edição carrega as qualidades gravadas;
     nova venda começa vazia (escolhe lote/colheita p/ pré-carregar, ou adiciona). */
  if (OLD_QUALS.length) {
    $id('q-body').innerHTML = '';
    OLD_QUALS.forEach(q => {
      addQ({ cat: q.cat });
      const tr = $id('q-body').lastElementChild;
      if (tr) {
        if (q.kg !== '')    tr.querySelector('.q-kg-in').value = q.kg;
        if (q.preco !== '') tr.querySelector('.q-preco').value = q.preco;
      }
    });
    recalc();
  } else if (IS_EDIT && EDIT_QUALS.length) EDIT_QUALS.forEach(q => addQ(q));
  recalc();

  /* A-01 (G-16): validação ANTES do submit — erro inline em #venda-erro, o
     form não recarrega e a digitação é preservada. Espelha o servidor (que
     segue como rede final): (1) Σ kg das qualidades ≤ kg comercializados;
     (2) kg comercializados ≤ saldo REAL do lote (data-saldo já devolve o kg
     da própria venda em edição, pois a reedição estorna antes de reemitir). */
  document.getElementById('f-venda').addEventListener('submit', function (e) {
    const box = document.getElementById('venda-erro');
    let erro = '';
    const kgTot = dec($id('f-kg').value);
    let soma = 0;
    document.querySelectorAll('.q-kg-in').forEach(inp => { soma += dec(inp.value); });
    if (soma > 0 && kgTot <= 0) {
      erro = 'Você distribuiu ' + fmt(soma, 0) + ' kg nas qualidades, mas os kg comercializados estão vazios — informe o total.';
    } else if (kgTot > 0 && soma > kgTot + 0.001) {
      erro = 'A soma dos kg por qualidade (' + fmt(soma, 0) + ' kg) passa dos kg comercializados ('
           + fmt(kgTot, 0) + ' kg). Ajuste os kg por categoria.';
    } else {
      const opt = fLote && fLote.value ? fLote.options[fLote.selectedIndex] : null;
      if (opt && opt.dataset.saldo !== undefined) {
        const saldo = parseFloat(opt.dataset.saldo);
        const kg = dec($id('f-kg').value);
        if (!isNaN(saldo) && kg > saldo + 0.0005) {
          erro = 'kg comercializados (' + fmt(kg, 0) + ' kg) ACIMA do saldo do lote '
               + opt.text.split(' — ')[0].trim() + ' — disponível: ' + fmt(saldo, 0) + ' kg.';
        }
      }
    }
    if (erro) {
      e.preventDefault();
      box.textContent = erro;
      box.style.display = 'block';
      box.scrollIntoView({ block: 'nearest' });
    } else {
      box.style.display = 'none';
    }
  });
  </script>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
