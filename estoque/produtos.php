<?php
/* ============================================================
   VERO — Estoque / Produtos e Insumos  (tela real)
   Substitui o mock. Rota da matriz: /estoque/produtos.php
   Guard: estoque.produtos_insumos
   Escrita: estoque.produtos_insumos.editar/excluir
   Tabelas: estoque_produtos + estoque_saldos + estoque_movimentacoes
   Movimentações usam os services (custo médio ponderado, saldo validado).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_reserva.php'; /* reserva derivada (A2-F2-14, P-60) */
require_once __DIR__ . '/_audit.php';   /* A2-F2-19: ações críticas → auth_audit_logs */
require_once __DIR__ . '/_export.php';  /* A2-F2-23: export CSV do filtro ativo */

const T = 'estoque_produtos';

/* DB-27 (A2-F2-11): concentrações nutricionais da bula — lista do documento
   do cliente, validada em PHP. "Mo1" REMOVIDO em 04/07 (decisão P-49: era
   erro de digitação do PDF — Mo duplicado); linhas antigas Mo1: zero no banco. */
const NUTRIENTES_BULA = ['N', 'P', 'K', 'Mg', 'Ca', 'S', 'B', 'Zn', 'Fe', 'Cu', 'Mn', 'Mo', 'Co', 'C', 'Si'];

/* A2-F2-15: código de PRODUTO com 6 dígitos. FEATURE-DETECTION:
   o padrão só é exigido quando o pacote A0-14 estiver aplicado (service
   `vero_srv_produto_proximo_codigo` presente + migration 141 renumerando os
   legados) — antes disso as telas mantêm o comportamento livre, sem quebrar
   a edição de códigos antigos. Override manual permitido: numérico de 6
   dígitos, único no tenant. */
function produtos_codigo6_ativo(): bool
{
    return function_exists('vero_srv_produto_proximo_codigo');
}

/* A2-F2-21 (DB-51): ALVOS CONTROLADOS por produto (mip_alvo_produtos) — o fio
   monitoramento→pulverização→estoque. FEATURE-DETECTION: a seção só existe
   quando a migration 146 (A0-19) criar a tabela; ativa sozinha depois.
   Regra 1: o sistema LISTA o que o RT cadastrou (trilha cadastrado_por) e
   NUNCA recomenda produto/dose. A1-48 consome por leitura direta (contrato). */
function produtos_alvos_ativo(): bool
{
    static $ativo = null;
    if ($ativo === null) {
        $ativo = vero_row("SHOW TABLES LIKE 'mip_alvo_produtos'") !== null;
    }
    return $ativo;
}

/* P-49 (A2-F2-12): edição dos DADOS DE BULA restrita a gestor+RT. Alçada = administração (administrador/dono, mesmo tier de
   PROD_ROLES_EXCLUIR), gestor e o RT — o role previsto aqui nasceu como
   rt_gerente (seed_perfis_padrao). super_admin/club_admin são legados de
   clube (A0-04) mantidos só onde ainda existem usuários com o slug vivo.
   Demais perfis mantêm a edição do restante do cadastro. */
function produtos_pode_editar_bula(): bool
{
    return in_array((string)($_SESSION['user_role'] ?? ''),
        ['super_admin', 'club_admin', 'administrador', 'dono', 'gestor', 'rt_gerente'], true);
}

/* P-15: excluir (inativar) produto restrito à alçada de DONO da
   fazenda — o slug de escrita não basta. Tier de dono/administração. */
const PROD_ROLES_EXCLUIR = ['super_admin', 'club_admin', 'administrador', 'dono'];

/* DB-06: tipo do insumo NO PRODUTO (VARCHAR + validação em PHP — sql_mode não é strict) */
const TIPOS_INSUMO = [
    'semente_muda' => 'Semente/Muda',   'fertilizante' => 'Fertilizante',
    'defensivo'    => 'Defensivo',      'corretivo'    => 'Corretivo',
    'combustivel'  => 'Combustível',    'lubrificante' => 'Lubrificante',
    'peca'         => 'Peça',           'epi'          => 'EPI',
    'material'     => 'Material',       'outro'        => 'Outro',
    /* saída de produção (não-insumo): fruta colhida — semeada pela migration 143
       ("Uva in natura"). Reconhecida aqui para não ser zerada ao editar (L116). */
    'produto_agricola' => 'Produto agrícola',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('estoque.produtos_insumos.editar');

        $id     = vero_int('id');
        $codigo = vero_str('codigo', 40);
        $nome   = vero_str('nome', 150);

        if ($codigo === null || $nome === null) {
            vero_flash('erro', 'Código e nome do produto são obrigatórios.');
            vero_redirect();
        }
        /* A2-F2-15: com o padrão de 6 dígitos ativo (pós-A0-14), o código deve
           ser exatamente 6 números — zeros à esquerda contam (VARCHAR) */
        if (produtos_codigo6_ativo() && !preg_match('/^[0-9]{6}$/', $codigo)) {
            vero_flash('erro', "Código \"{$codigo}\" fora do padrão: use exatamente 6 números (ex.: 000042). "
                . 'O campo já vem preenchido com o próximo código livre — sobrescreva apenas se precisar de um número específico.');
            vero_redirect();
        }
        $dup = vero_val(
            "SELECT id FROM " . T . " WHERE tenant_id=:t AND codigo=:c AND id<>:id",
            [':t' => vero_tenant(), ':c' => $codigo, ':id' => (int)$id]
        );
        if ($dup) {
            vero_flash('erro', "Já existe um produto com o código \"{$codigo}\".");
            vero_redirect();
        }
        $grupoId = vero_int('grupo_id');
        if ($grupoId) {
            $ok = vero_val("SELECT id FROM estoque_grupos WHERE id=:g AND tenant_id=:t",
                [':g' => $grupoId, ':t' => vero_tenant()]);
            if (!$ok) $grupoId = null;
        }
        $grupoId = $grupoId ?: vero_srv_grupo_estoque_padrao();

        /* subgrupo precisa pertencer ao grupo escolhido */
        $subgrupoId = vero_int('subgrupo_id');
        if ($subgrupoId) {
            $ok = vero_val("SELECT id FROM estoque_subgrupos WHERE id=:s AND tenant_id=:t AND grupo_id=:g",
                [':s' => $subgrupoId, ':t' => vero_tenant(), ':g' => $grupoId]);
            if (!$ok) $subgrupoId = null;
        }

        $tipoInsumo = vero_str('tipo_insumo', 20);
        if ($tipoInsumo !== null && !isset(TIPOS_INSUMO[$tipoInsumo])) $tipoInsumo = null;

        $fornPadraoId = vero_int('fornecedor_padrao_id');
        if ($fornPadraoId) {
            $ok = vero_val("SELECT id FROM fornecedores WHERE id=:f AND tenant_id=:t",
                [':f' => $fornPadraoId, ':t' => vero_tenant()]);
            if (!$ok) $fornPadraoId = null;
        }
        $planoContaId = vero_int('plano_conta_id');
        if ($planoContaId) {
            $ok = vero_val("SELECT id FROM plano_contas WHERE id=:p AND tenant_id=:t",
                [':p' => $planoContaId, ':t' => vero_tenant()]);
            if (!$ok) $planoContaId = null;
        }

        /* unidade de compra: só faz sentido em par com o fator (> 0) */
        $unCompra = vero_str('unidade_compra', 10);
        $fator    = vero_dec('fator_conversao');
        if ($unCompra !== null && ($fator === null || $fator <= 0)) {
            vero_flash('erro', 'Informe o fator de conversão (> 0) da unidade de compra para a unidade de uso.');
            vero_redirect();
        }
        if ($unCompra === null) $fator = null;

        $carencia = vero_int('carencia_dias');
        if ($carencia !== null && $carencia < 0) $carencia = null;

        $controlaValidade = vero_int('controla_validade') ? 1 : 0;
        if ($tipoInsumo === 'defensivo') $controlaValidade = 1; /* defensivo sempre controla validade (análise §2.1) */

        /* DB-27: dados de bula (REGISTRO do RT — Regra 1: o sistema nunca recomenda;
           a DF/IF copia estes valores como snapshot editável na emissão).
           P-49: edição restrita — sem a alçada, os valores ATUAIS são preservados
           (o restante do cadastro continua editável). */
        $podeBula = produtos_pode_editar_bula();
        $atualBula = $id ? vero_row("SELECT dose_referencia, dose_referencia_unidade, lmr_dias,
                intervalo_aplicacoes_dias, num_max_aplicacoes, estoque_ideal
              FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => (int)$id, ':t' => vero_tenant()]) : null;
        if ($podeBula) {
            $doseRef = vero_dec('dose_referencia');
            $doseRefUn = vero_str('dose_referencia_unidade', 12);
            if ($doseRef === null || $doseRef <= 0) { $doseRef = null; $doseRefUn = null; }
            $lmr = vero_int('lmr_dias');
            $intervaloApl = vero_int('intervalo_aplicacoes_dias');
            $numMaxApl = vero_int('num_max_aplicacoes');
            $estoqueIdeal = vero_dec('estoque_ideal') ?? 0;
            /* nutrientes: nutr[SIMBOLO] => % (só símbolos da lista; vazio/0 = remove) */
            $nutrientes = [];
            foreach ((array)($_POST['nutr'] ?? []) as $simb => $pctRaw) {
                if (!in_array((string)$simb, NUTRIENTES_BULA, true)) continue;
                $pctRaw = trim((string)$pctRaw);
                if ($pctRaw === '') continue;
                if (str_contains($pctRaw, ',')) $pctRaw = str_replace(['.', ','], ['', '.'], $pctRaw);
                if (!is_numeric($pctRaw)) continue;
                $pct = (float)$pctRaw;
                if ($pct > 0) $nutrientes[(string)$simb] = $pct;
            }
        } else {
            $doseRef      = $atualBula['dose_referencia'] ?? null;
            $doseRefUn    = $atualBula['dose_referencia_unidade'] ?? null;
            $lmr          = $atualBula !== null && $atualBula['lmr_dias'] !== null ? (int)$atualBula['lmr_dias'] : null;
            $intervaloApl = $atualBula !== null && $atualBula['intervalo_aplicacoes_dias'] !== null ? (int)$atualBula['intervalo_aplicacoes_dias'] : null;
            $numMaxApl    = $atualBula !== null && $atualBula['num_max_aplicacoes'] !== null ? (int)$atualBula['num_max_aplicacoes'] : null;
            $estoqueIdeal = $atualBula !== null ? (float)$atualBula['estoque_ideal'] : 0;
            $nutrientes   = null; /* null = não tocar na tabela filha */
        }

        /* A11: níveis de estoque são quantidades físicas — nunca negativas */
        $estMin = vero_dec('estoque_minimo') ?? 0;
        $estMax = vero_dec('estoque_maximo') ?? 0;
        if ($estMin < 0 || $estMax < 0 || (float)$estoqueIdeal < 0) {
            vero_flash('erro', 'Estoque mínimo, máximo e ideal não podem ser negativos.');
            vero_redirect();
        }

        $data = [
            'grupo_id'            => $grupoId,
            'subgrupo_id'         => $subgrupoId,
            'codigo'              => $codigo,
            'nome'                => $nome,
            'tipo_insumo'         => $tipoInsumo,
            'fabricante'          => vero_str('fabricante', 120),
            'registro_mapa'       => vero_str('registro_mapa', 40),
            'nao_registrado'      => vero_int('nao_registrado') ? 1 : 0,
            'classe_toxicologica' => vero_str('classe_toxicologica', 40),
            'carencia_dias'       => $carencia,
            'fornecedor_padrao_id'=> $fornPadraoId,
            'unidade_compra'      => $unCompra,
            'fator_conversao'     => $fator,
            'ingrediente_ativo'   => vero_str('ingrediente_ativo', 150),
            'unidade'             => vero_str('unidade', 10) ?? 'un',
            'controla_lote'       => vero_int('controla_lote') ? 1 : 0,
            'controla_validade'   => $controlaValidade,
            'estoque_minimo'      => $estMin,
            'estoque_maximo'      => $estMax,
            'estoque_ideal'       => $estoqueIdeal,
            'plano_conta_id'      => $planoContaId,
            'dose_referencia'     => $doseRef,
            'dose_referencia_unidade' => $doseRefUn,
            'lmr_dias'            => $lmr !== null && $lmr >= 0 ? $lmr : null,
            'intervalo_aplicacoes_dias' => $intervaloApl !== null && $intervaloApl >= 0 ? $intervaloApl : null,
            'num_max_aplicacoes'  => $numMaxApl !== null && $numMaxApl > 0 ? $numMaxApl : null,
            'ativo'               => vero_int('ativo') ?? 1,
        ];

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($id) {
                vero_update(T, $id, $data);
                $prodId = (int)$id;
            } else {
                $prodId = (int)vero_insert(T, $data);
            }
            /* sync das concentrações nutricionais (DB-27; tabela sem auditoria → PDO
               direto). $nutrientes === null: usuário sem alçada de bula (P-49) —
               tabela filha permanece intocada. */
            if ($nutrientes !== null) {
                $pdo->prepare("DELETE FROM estoque_produto_nutrientes WHERE tenant_id=? AND produto_id=?")
                    ->execute([vero_tenant(), $prodId]);
                $insNutr = $pdo->prepare(
                    "INSERT INTO estoque_produto_nutrientes (tenant_id, produto_id, nutriente, percentual, created_at, updated_at)
                     VALUES (?,?,?,?,NOW(),NOW())");
                foreach ($nutrientes as $simb => $pct) {
                    $insNutr->execute([vero_tenant(), $prodId, $simb, $pct]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao salvar: ' . h($e->getMessage()));
            vero_redirect();
        }
        vero_flash('ok', $id
            ? "Produto \"{$nome}\" atualizado."
            : "Produto \"{$nome}\" cadastrado. Lance uma entrada para criar o saldo inicial.");
        /* defensivo sem registro MAPA: AVISO, não bloqueio (P-22; Regra 1 — dado é registro, nunca recomendação) */
        if ($tipoInsumo === 'defensivo' && ($data['registro_mapa'] === null || $data['registro_mapa'] === '')) {
            vero_flash('aviso', 'Defensivo sem registro MAPA informado — complete o cadastro para rastreabilidade (GlobalGAP/exportação).');
        }
        vero_redirect();
    }

    /* C-07 v2 (pedido do usuário 18/07): SALDO INICIAL como modal — digita a
       data e o CÓDIGO do produto, o sistema mostra o saldo atual e o usuário
       informa o saldo CORRETO. O ajuste sai pela DIFERENÇA via services
       (entrada com custo informado / saída ao custo médio-FEFO), origem
       'implantacao' na trilha — nunca escrita direta em saldo. */
    if ($acao === 'saldo_inicial') {
        vero_require('estoque.produtos_insumos.editar');

        $data   = vero_date('si_data') ?: date('Y-m-d');
        $codigo = strtoupper(trim((string)vero_str('si_codigo', 30)));
        $qtdOk  = vero_dec('si_qtd');
        $custo  = vero_dec('si_custo');
        $validade = vero_date('si_validade');

        $prod = $codigo !== '' ? vero_row(
            "SELECT * FROM " . T . " WHERE tenant_id = :t AND UPPER(codigo) = :c AND ativo = 1",
            [':t' => vero_tenant(), ':c' => $codigo]) : null;
        if (!$prod) {
            vero_flash('erro', 'Produto não encontrado pelo código informado.');
            vero_redirect();
        }
        if ($qtdOk === null || $qtdOk < 0) {
            vero_flash('erro', 'Informe o saldo correto (0 ou mais).');
            vero_redirect();
        }
        /* A-06/D-3: almoxarifado do saldo inicial (selecionável; default = padrão).
           O saldo atual e a diferença são medidos NAQUELE almoxarifado — não no
           total do produto — para que a correção entre/saia do lugar certo. */
        $almoxSel = vero_int('si_almox');
        if ($almoxSel) {
            $okAlmox = vero_val("SELECT id FROM almoxarifados WHERE id = :a AND tenant_id = :t AND ativo = 1",
                [':a' => $almoxSel, ':t' => vero_tenant()]);
            if (!$okAlmox) $almoxSel = null;
        }
        $almox = $almoxSel ?: vero_srv_almox_padrao();
        $saldoAtual = (float)vero_val(
            "SELECT COALESCE(SUM(quantidade),0) FROM estoque_saldos WHERE tenant_id = :t AND produto_id = :p AND almoxarifado_id = :a",
            [':t' => vero_tenant(), ':p' => (int)$prod['id'], ':a' => $almox]);
        $delta = round($qtdOk - $saldoAtual, 4);

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if (abs($delta) < 0.0001) {
                $pdo->commit();
                vero_flash('ok', "\"{$prod['nome']}\": o saldo já confere (" . numFmt($saldoAtual, 2) . " {$prod['unidade']}). Nada a ajustar.");
                vero_redirect();
            }
            if ($delta > 0) {
                if ($custo === null || $custo < 0) {
                    throw new RuntimeException('Informe o custo unitário para a entrada da diferença (pode ser 0,00).');
                }
                if ($validade === null
                    && ((int)$prod['controla_validade'] === 1 || (int)($prod['controla_lote'] ?? 0) === 1)) {
                    throw new RuntimeException('Este produto controla validade/lote — informe a validade para a entrada.');
                }
                vero_srv_estoque_entrada((int)$prod['id'], $almox, $delta, (float)$custo, $data,
                    'implantacao', null, 'Saldo inicial (correção via modal)', $validade);
                $msg = "\"{$prod['nome']}\": entrada de " . numFmt($delta, 2) . " {$prod['unidade']} — saldo corrigido para " . numFmt($qtdOk, 2) . '.';
            } else {
                $s = vero_srv_estoque_saida((int)$prod['id'], $almox, -$delta, $data,
                    'implantacao', null, 'Saldo inicial (correção via modal)');
                $msg = "\"{$prod['nome']}\": saída de " . numFmt(-$delta, 2) . " {$prod['unidade']} (custo R$ " . numFmt((float)$s['custo_total'], 2) . ") — saldo corrigido para " . numFmt($qtdOk, 2) . '.';
            }
            $pdo->commit();
            vero_flash('ok', $msg . ' Custo médio e alertas atualizados.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (!estoque_flash_guarda($e)) vero_flash('erro', h($e->getMessage()));
        }
        vero_redirect();
    }

    if ($acao === 'movimentar') {
        vero_require('estoque.produtos_insumos.editar');

        $produtoId = vero_int('produto_id');
        $tipo      = vero_str('tipo_mov', 10);
        $qtd       = vero_dec('quantidade');
        $custo     = vero_dec('custo_unitario');
        $obs       = vero_str('observacao_mov', 255);
        $data      = vero_date('data_mov') ?? date('Y-m-d');

        $produto = $produtoId ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $produtoId, ':t' => vero_tenant()]) : null;
        if (!$produto || !in_array($tipo, ['entrada', 'saida'], true) || $qtd === null || $qtd <= 0) {
            vero_flash('erro', 'Produto, tipo e quantidade válida são obrigatórios na movimentação.');
            vero_redirect();
        }
        if ($tipo === 'entrada' && ($custo === null || $custo < 0)) {
            vero_flash('erro', 'Informe o custo unitário da entrada.');
            vero_redirect();
        }

        $validade = vero_date('validade');
        /* A2-F2-18: exigência DURA — controla_validade OU controla_lote exigem
           validade na entrada (o lote nasce da validade no service; produto
           rastreável não pode ganhar saldo "sem lote") */
        if ($tipo === 'entrada' && $validade === null
            && ((int)$produto['controla_validade'] === 1 || (int)($produto['controla_lote'] ?? 0) === 1)) {
            vero_flash('erro', (int)$produto['controla_validade'] === 1
                ? 'Este produto controla validade (perecível) — informe a validade do lote na entrada.'
                : 'Este produto CONTROLA LOTE (rastreabilidade) — informe a validade do lote na entrada para o lote ser criado.');
            vero_redirect();
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $almox = vero_srv_almox_padrao();
            if ($tipo === 'entrada') {
                vero_srv_estoque_entrada($produtoId, $almox, $qtd, (float)$custo, $data, 'manual', null, $obs, $validade);
                $msg = "Entrada de {$qtd} {$produto['unidade']} registrada para \"{$produto['nome']}\""
                     . ($validade !== null ? ' (lote com validade ' . date('d/m/Y', strtotime($validade)) . ')' : '') . '.';
            } else {
                /* reserva ORIENTATIVA (P-60): mede ANTES da saída p/ avisar se invadir */
                $reservadoAntes = estoque_reservas_por_produto()[$produtoId] ?? 0.0;
                $saldoAntes = (float)vero_val(
                    "SELECT COALESCE(SUM(quantidade),0) FROM estoque_saldos WHERE tenant_id=:t AND produto_id=:p",
                    [':t' => vero_tenant(), ':p' => $produtoId]);
                /* P-23/A0-10: lote vencido no FEFO exige confirmação explícita */
                $s = vero_srv_estoque_saida($produtoId, $almox, $qtd, $data, 'manual', null, $obs,
                    null, null, vero_int('permitir_vencido') === 1);
                $msg = "Saída de {$qtd} {$produto['unidade']} registrada para \"{$produto['nome']}\" (custo R$ " . numFmt($s['custo_total'], 2) . ").";
                if ($reservadoAntes > 0.0001 && $qtd > max(0.0, $saldoAntes - $reservadoAntes) + 0.0001) {
                    vero_flash('aviso', 'Atenção: esta saída INVADIU a reserva de atividades planejadas ('
                        . numFmt($reservadoAntes, 2) . ' ' . $produto['unidade']
                        . ' reservados) — a reserva é orientativa, nada foi bloqueado.');
                }
            }
            $pdo->commit();
            if ($tipo === 'saida' && vero_int('permitir_vencido') === 1) {
                /* A2-F2-19: conformidade — saída com lote VENCIDO confirmada fica na trilha */
                estoque_audit('estoque_saida_vencido_confirmada',
                    "Saída manual de {$qtd} do produto #{$produtoId} com confirmação de lote vencido (P-23)");
            }
            vero_flash('ok', $msg);
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (str_starts_with($e->getMessage(), 'LOTE_VENCIDO:')) {
                vero_flash('aviso', mb_substr($e->getMessage(), 13)
                    . ' Marque "Confirmo a saída de lote vencido" no formulário e reenvie.');
            } elseif (!estoque_flash_guarda($e)) { /* PERIODO_FECHADO: orientado (EST-018) */
                vero_flash('erro', 'Movimentação não realizada: ' . $e->getMessage());
            }
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('estoque.produtos_insumos.excluir');
        /* P-15: exclusão restrita ao DONO da fazenda (role), além do slug. */
        if (!in_array((string)($_SESSION['user_role'] ?? ''), PROD_ROLES_EXCLUIR, true)) {
            vero_flash('erro', 'Apenas o dono da fazenda pode excluir produtos.');
            vero_redirect();
        }
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }

    /* DB-51: a amarração alvo×produto foi movida para MIP → Alvos de Controle
       (mip/alvos_controle.php). Os handlers alvo_add/alvo_del saíram daqui. */
}

/* ── Listagem ───────────────────────────────────────────────── */
/* A2-F2-22: filtros robustos e combináveis (pedido do usuário 08/07) —
   além de busca/tipo/grupo/status já existentes: subgrupo, faixa de saldo,
   validade, com/sem lote, fabricante, ingrediente ativo e registro MAPA.
   Cada filtro vira um "chip" removível; "Limpar" zera tudo (padrão A4). */
$q       = trim((string)($_GET['q'] ?? ''));
$fAlerta = (string)($_GET['alerta'] ?? '');
$fTipo   = (string)($_GET['tipo'] ?? '');
if ($fTipo !== '' && !isset(TIPOS_INSUMO[$fTipo])) $fTipo = '';
/* aceita ?grupo= e o alias legado ?grupo_id= (link "Ver produtos" de grupos_subgrupos.php) */
$fGrupo  = (int)($_GET['grupo'] ?? ($_GET['grupo_id'] ?? 0));
$fSub    = (int)($_GET['subgrupo'] ?? 0);
$fStatus = (string)($_GET['status'] ?? '');
$fSaldo  = (string)($_GET['saldo'] ?? '');
$fVal    = (string)($_GET['validade'] ?? '');
$fLote   = (string)($_GET['lote'] ?? '');
$fFab    = trim((string)($_GET['fabricante'] ?? ''));
$fIng    = trim((string)($_GET['ingrediente'] ?? ''));
$fReg    = (string)($_GET['registro'] ?? '');
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

/* opções canônicas (rótulo dos selects e dos chips; valores fora da lista são ignorados) */
$SALDO_OPC = ['com' => 'Com saldo', 'sem' => 'Sem saldo', 'abaixo' => 'Abaixo do mínimo',
              'zerado' => 'Zerado', 'negativo' => 'Negativo', 'acima' => 'Acima do máximo'];
if ($fSaldo !== '' && !isset($SALDO_OPC[$fSaldo])) $fSaldo = '';
$VAL_OPC = ['vencido' => 'Vencidos', 'vence30' => 'Vence em 30 dias',
            'com' => 'Com validade', 'sem' => 'Sem validade'];
if ($fVal !== '' && !isset($VAL_OPC[$fVal])) $fVal = '';
$LOTE_OPC = ['com' => 'Com lote ativo', 'sem' => 'Sem lote'];
if ($fLote !== '' && !isset($LOTE_OPC[$fLote])) $fLote = '';
$REG_OPC = ['com' => 'Com registro MAPA', 'sem' => 'Sem registro MAPA'];
if ($fReg !== '' && !isset($REG_OPC[$fReg])) $fReg = '';

$filtroAtivo = $q !== '' || $fAlerta === '1' || $fTipo !== '' || $fGrupo || $fSub
    || $fStatus !== '' || $fSaldo !== '' || $fVal !== '' || $fLote !== ''
    || $fFab !== '' || $fIng !== '' || $fReg !== '';

$where  = "p.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    /* QA-011: placeholders DISTINTOS (:q1..:qN) — prepares nativos rejeitam repetição (HY093) */
    $where .= " AND (p.codigo LIKE :q1 OR p.nome LIKE :q2 OR p.ingrediente_ativo LIKE :q3 OR p.fabricante LIKE :q4 OR p.registro_mapa LIKE :q5)";
    foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $qk) $params[":{$qk}"] = "%{$q}%";
}
if ($fTipo !== '') { $where .= " AND p.tipo_insumo = :tipo"; $params[':tipo'] = $fTipo; }
if ($fGrupo)                 { $where .= " AND p.grupo_id = :g"; $params[':g'] = $fGrupo; }
if ($fSub)                   { $where .= " AND p.subgrupo_id = :sg"; $params[':sg'] = $fSub; }
/* P-15: por padrão a lista OCULTA os inativos (soft-delete).
   'inativo' mostra só inativos; 'todos' mostra ambos; qualquer outro (inclui o
   default vazio e 'ativo') = só ativos. */
if ($fStatus === 'inativo')   { $where .= " AND p.ativo = 0"; }
elseif ($fStatus === 'todos') { /* ativos e inativos */ }
else                          { $where .= " AND p.ativo = 1"; }
if ($fFab !== '') { $where .= " AND p.fabricante = :fab"; $params[':fab'] = $fFab; }
if ($fIng !== '') { $where .= " AND p.ingrediente_ativo = :ing"; $params[':ing'] = $fIng; }
if ($fReg === 'com')      { $where .= " AND p.registro_mapa IS NOT NULL AND p.registro_mapa <> ''"; }
elseif ($fReg === 'sem')  { $where .= " AND (p.registro_mapa IS NULL OR p.registro_mapa = '')"; }

/* P-75 (CSO 08/07): valores em R$ (custo médio / valor em estoque) gateados pelo
   proxy financeiro — operador/consulta não veem custo. Mesmo padrão da Auditoria
   de Estoque (F2-17). Aplica na LISTA, no KPI e no EXPORT. Quantidades/saldos
   continuam visíveis. */
$veCusto = function_exists('vero_can') ? vero_can('financeiro.dre_agro.ver') : true;

$havingParts = [];
if ($fAlerta === '1')  $havingParts[] = "saldo < p.estoque_minimo";
if ($fSaldo === 'com') $havingParts[] = "saldo > 0";
elseif ($fSaldo === 'sem') $havingParts[] = "saldo <= 0";
elseif ($fSaldo === 'abaixo')   $havingParts[] = "saldo < p.estoque_minimo";
elseif ($fSaldo === 'zerado')   $havingParts[] = "ROUND(saldo, 4) = 0";
elseif ($fSaldo === 'negativo') $havingParts[] = "saldo < 0";
elseif ($fSaldo === 'acima')    $havingParts[] = "p.estoque_maximo > 0 AND saldo > p.estoque_maximo";
if ($fVal === 'vencido')  $havingParts[] = "prox_validade IS NOT NULL AND prox_validade < CURDATE()";
elseif ($fVal === 'vence30') $havingParts[] = "prox_validade IS NOT NULL AND prox_validade >= CURDATE() AND prox_validade <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
elseif ($fVal === 'com')  $havingParts[] = "prox_validade IS NOT NULL";
elseif ($fVal === 'sem')  $havingParts[] = "prox_validade IS NULL";
if ($fLote === 'com')     $havingParts[] = "lotes_ativos > 0";
elseif ($fLote === 'sem') $havingParts[] = "lotes_ativos = 0";
$having = $havingParts ? 'HAVING ' . implode(' AND ', $havingParts) : '';

$sqlBase =
    "SELECT p.*, g.nome AS grupo_nome,
            COALESCE((SELECT SUM(s.quantidade) FROM estoque_saldos s
              WHERE s.tenant_id = p.tenant_id AND s.produto_id = p.id), 0) AS saldo,
            COALESCE((SELECT SUM(s.valor_total) FROM estoque_saldos s
              WHERE s.tenant_id = p.tenant_id AND s.produto_id = p.id), 0) AS valor,
            (SELECT MIN(l.validade) FROM estoque_lotes l
              WHERE l.tenant_id = p.tenant_id AND l.produto_id = p.id
                AND l.quantidade > 0 AND l.validade IS NOT NULL) AS prox_validade,
            (SELECT COUNT(*) FROM estoque_lotes l
              WHERE l.tenant_id = p.tenant_id AND l.produto_id = p.id AND l.quantidade > 0) AS lotes_ativos
       FROM " . T . " p
       LEFT JOIN estoque_grupos g ON g.id = p.grupo_id
      WHERE {$where} {$having}";

/* A2-F2-23: exportação CSV — MESMO filtro ativo, todos os registros (sem
   paginação). Roda antes de qualquer HTML; gate igual ao da tela. NB: aqui o
   header (menu_agro) ainda NÃO carregou, logo bios_guard() não existe e um
   `if (function_exists('bios_guard'))` seria silenciosamente pulado — o CSV
   escaparia do gate. Usa vero_require (carregado via vero_crud) que é o guard
   real pré-header. */
if (($_GET['csv'] ?? '') !== '') {
    vero_require('estoque.produtos_insumos.ver');
    $rowsCsv = vero_rows($sqlBase . " ORDER BY p.ativo DESC, p.nome", $params);
    foreach ($rowsCsv as &$rc) {
        $sd = (float)$rc['saldo'];
        $rc['custo_medio']  = $sd > 0 ? (float)$rc['valor'] / $sd : 0.0;
        $rc['tipo_label']   = ($rc['tipo_insumo'] !== null && isset(TIPOS_INSUMO[$rc['tipo_insumo']])) ? TIPOS_INSUMO[$rc['tipo_insumo']] : '';
        $rc['status_label'] = (int)$rc['ativo'] === 1 ? 'Ativo' : 'Inativo';
    }
    unset($rc);
    /* P-75: sem o proxy financeiro, o CSV OMITE as colunas de R$ (não mascara —
       some do arquivo), para não vazar custo por download. */
    $csvCols = [
        'codigo' => 'Código', 'nome' => 'Produto', 'tipo_label' => 'Tipo', 'grupo_nome' => 'Grupo',
        'unidade' => 'Unidade', 'saldo' => 'Saldo', 'estoque_minimo' => 'Estoque mínimo',
        'estoque_maximo' => 'Estoque máximo', 'custo_medio' => 'Custo médio (R$)', 'valor' => 'Valor em estoque (R$)',
        'fabricante' => 'Fabricante', 'ingrediente_ativo' => 'Ingrediente ativo', 'registro_mapa' => 'Registro MAPA',
        'prox_validade' => 'Próxima validade', 'lotes_ativos' => 'Lotes ativos', 'status_label' => 'Status',
    ];
    $csvFmt = [
        'saldo' => 'dec2', 'estoque_minimo' => 'dec2', 'estoque_maximo' => 'dec2',
        'custo_medio' => 'dec4', 'valor' => 'dec2', 'prox_validade' => 'data', 'lotes_ativos' => 'dec0',
    ];
    if (!$veCusto) {
        unset($csvCols['custo_medio'], $csvCols['valor'], $csvFmt['custo_medio'], $csvFmt['valor']);
    }
    estoque_csv_stream('produtos', $rowsCsv, $csvCols, $csvFmt);
}

$total = count(vero_rows($sqlBase, $params));
$rows  = vero_rows($sqlBase . " ORDER BY p.ativo DESC, p.nome LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

/* Resumo do catálogo (produtos ativos — independe dos filtros da lista). */
$resumo = vero_row(
    "SELECT COUNT(*) AS total, COALESCE(SUM(x.valor),0) AS valor_total,
            SUM(x.saldo < x.estoque_minimo) AS abaixo,
            SUM(x.vencido > 0) AS vencidos,
            SUM(x.controla_validade = 1 AND x.com_lote = 0) AS sem_lote
       FROM (SELECT p.id, p.estoque_minimo, p.controla_validade,
               COALESCE((SELECT SUM(s.quantidade) FROM estoque_saldos s WHERE s.tenant_id=p.tenant_id AND s.produto_id=p.id),0) AS saldo,
               COALESCE((SELECT SUM(s.valor_total) FROM estoque_saldos s WHERE s.tenant_id=p.tenant_id AND s.produto_id=p.id),0) AS valor,
               (SELECT COUNT(*) FROM estoque_lotes l WHERE l.tenant_id=p.tenant_id AND l.produto_id=p.id AND l.quantidade>0 AND l.validade IS NOT NULL AND l.validade < CURDATE()) AS vencido,
               (SELECT COUNT(*) FROM estoque_lotes l WHERE l.tenant_id=p.tenant_id AND l.produto_id=p.id AND l.quantidade>0) AS com_lote
             FROM " . T . " p WHERE p.tenant_id = :t AND p.ativo = 1) x", [':t' => vero_tenant()]);

$reservas = estoque_reservas_por_produto(); /* A2-F2-14: [produto_id => reservado] */

/* lotes ativos por produto p/ o modal "Ver lotes" (JSON embutido — sem requisição extra) */
$lotesPorProduto = [];
foreach (vero_rows(
    "SELECT l.produto_id, l.codigo_lote, l.quantidade, l.validade, a.nome AS almox
       FROM estoque_lotes l
       LEFT JOIN almoxarifados a ON a.id = l.almoxarifado_id
      WHERE l.tenant_id = :t AND l.quantidade > 0
      ORDER BY (l.validade IS NULL), l.validade, l.codigo_lote", [':t' => vero_tenant()]) as $l) {
    $lotesPorProduto[(int)$l['produto_id']][] = [
        'lote'  => (string)$l['codigo_lote'],
        'almox' => (string)($l['almox'] ?? '—'),
        'qtd'   => round((float)$l['quantidade'], 4),
        'val'   => $l['validade'] !== null ? date('d/m/Y', strtotime((string)$l['validade'])) : null,
        'dias'  => $l['validade'] !== null
            ? (int)floor((strtotime((string)$l['validade']) - strtotime(date('Y-m-d'))) / 86400) : null,
    ];
}

/* C-07 v2: mapa código → produto p/ o modal Saldo Inicial (lookup no cliente) */
$saldoIniProdutos = vero_rows(
    "SELECT p.id, p.codigo, p.nome, p.unidade, p.controla_validade, COALESCE(p.controla_lote,0) AS controla_lote
       FROM " . T . " p WHERE p.tenant_id = :t AND p.ativo = 1", [':t' => vero_tenant()]);

/* A-06/D-3: almoxarifados ativos + saldo/custo médio POR (produto, almoxarifado)
   para o modal Saldo Inicial exibir o saldo do almoxarifado escolhido (não o
   total do produto). Sem lookup extra no servidor — tudo no cliente. */
$saldoIniAlmoxes     = vero_options('almoxarifados', 'nome', 'ativo = 1');
/* default do seletor no RENDER: NÃO criar almoxarifado num GET (vero_srv_almox_padrao
   é get-OR-create → geraria "Almoxarifado Central" só por abrir a página). Usa o 1º
   ativo; 0 quando não houver — no POST o handler cai em vero_srv_almox_padrao(). */
$saldoIniAlmoxPadrao = (int)(vero_val(
    "SELECT id FROM almoxarifados WHERE tenant_id = :t AND ativo = 1 ORDER BY id LIMIT 1",
    [':t' => vero_tenant()]) ?? 0);
$saldoIniSaldos = []; /* [produto_id][almoxarifado_id] => ['saldo'=>float,'cm'=>float] */
foreach (vero_rows(
    "SELECT produto_id, almoxarifado_id, quantidade, custo_medio
       FROM estoque_saldos WHERE tenant_id = :t", [':t' => vero_tenant()]) as $s) {
    $saldoIniSaldos[(int)$s['produto_id']][(int)$s['almoxarifado_id']] = [
        'saldo' => (float)$s['quantidade'], 'cm' => (float)$s['custo_medio'],
    ];
}

$grupos = vero_options('estoque_grupos', 'nome', 'ativo = 1');
/* subgrupos rotulados "Grupo — Sub" (validação de pertencimento é server-side) */
$subgrupos = [];
foreach (vero_rows(
    "SELECT sg.id, CONCAT(g.nome, ' — ', sg.nome) AS rotulo
       FROM estoque_subgrupos sg JOIN estoque_grupos g ON g.id = sg.grupo_id
      WHERE sg.tenant_id = :t ORDER BY g.nome, sg.nome", [':t' => vero_tenant()]) as $sg) {
    $subgrupos[(int)$sg['id']] = $sg['rotulo'];
}
$fornecedoresOpt = vero_options('fornecedores', 'nome', 'ativo = 1');
$planoContasOpt = [];
foreach (vero_rows(
    "SELECT id, CONCAT(codigo, ' ', nome) AS rotulo FROM plano_contas
      WHERE tenant_id = :t AND ativo = 1 AND aceita_lancamento = 1
      ORDER BY codigo", [':t' => vero_tenant()]) as $pc) {
    $planoContasOpt[(int)$pc['id']] = $pc['rotulo'];
}

/* A2-F2-22: valores distintos já cadastrados no tenant p/ os selects de
   fabricante e ingrediente ativo (lista curta, combinável com os demais).
   A busca livre (:qN) continua cobrindo trechos; o select filtra por exato. */
$fabricantesOpt = [];
foreach (vero_rows("SELECT DISTINCT fabricante FROM " . T . "
      WHERE tenant_id = :t AND fabricante IS NOT NULL AND fabricante <> ''
      ORDER BY fabricante", [':t' => vero_tenant()]) as $r) {
    $fabricantesOpt[] = (string)$r['fabricante'];
}
$ingredientesOpt = [];
foreach (vero_rows("SELECT DISTINCT ingrediente_ativo FROM " . T . "
      WHERE tenant_id = :t AND ingrediente_ativo IS NOT NULL AND ingrediente_ativo <> ''
      ORDER BY ingrediente_ativo", [':t' => vero_tenant()]) as $r) {
    $ingredientesOpt[] = (string)$r['ingrediente_ativo'];
}

/* A2-F2-22: chips do filtro ativo — cada um aponta p/ a URL SEM aquele
   parâmetro (remoção individual); "Limpar tudo" cai na rota sem query. */
$basePath = strtok((string)$_SERVER['REQUEST_URI'], '?');
$chipDefs = [
    ['q',           $q !== ''      ? 'Busca: "' . $q . '"' : null],
    ['tipo',        $fTipo !== ''  ? 'Tipo: ' . TIPOS_INSUMO[$fTipo] : null],
    ['grupo',       $fGrupo        ? 'Grupo: ' . ($grupos[$fGrupo] ?? ('#' . $fGrupo)) : null],
    ['subgrupo',    $fSub          ? 'Subgrupo: ' . ($subgrupos[$fSub] ?? ('#' . $fSub)) : null],
    ['status',      $fStatus === 'inativo' ? 'Status: Inativos' : ($fStatus === 'todos' ? 'Status: Ativos e inativos' : null)],
    ['saldo',       $fSaldo !== ''  ? 'Saldo: ' . $SALDO_OPC[$fSaldo] : null],
    ['validade',    $fVal !== ''    ? 'Validade: ' . $VAL_OPC[$fVal] : null],
    ['lote',        $fLote !== ''   ? $LOTE_OPC[$fLote] : null],
    ['fabricante',  $fFab !== ''    ? 'Fabricante: ' . $fFab : null],
    ['ingrediente', $fIng !== ''    ? 'Ing. ativo: ' . $fIng : null],
    ['registro',    $fReg !== ''    ? $REG_OPC[$fReg] : null],
    ['alerta',      $fAlerta === '1' ? 'Abaixo do mínimo' : null],
];
$chips = [];
foreach ($chipDefs as [$key, $label]) {
    if ($label === null) continue;
    $qs = $_GET;
    unset($qs[$key], $qs['pg']);
    if ($key === 'grupo') unset($qs['grupo_id']); /* remove também o alias legado */
    $chips[] = ['label' => $label, 'url' => $basePath . ($qs ? '?' . http_build_query($qs) : '')];
}

/* A2-F2-23: URL do "Exportar CSV" = filtro atual + csv=1 (sem a paginação) */
$qsExport = $_GET;
unset($qsExport['pg']);
$qsExport['csv'] = '1';
$exportUrl = $basePath . '?' . http_build_query($qsExport);

$edit = null;
$editNutr = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        foreach (vero_rows("SELECT nutriente, percentual FROM estoque_produto_nutrientes
                             WHERE tenant_id=:t AND produto_id=:p", [':t' => vero_tenant(), ':p' => (int)$edit['id']]) as $n) {
            $editNutr[(string)$n['nutriente']] = (float)$n['percentual'];
        }
    }
}

$GUARD      = ['macro' => 'estoque', 'micro' => 'produtos_insumos'];
$PAGE_VIEW  = 'estoque_produtos_insumos';
$PAGE_TITLE = 'Produtos e Insumos';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('estoque.produtos_insumos.editar');
/* P-15: excluir só para quem tem o slug E a alçada de dono da fazenda */
$podeExcluir = vero_can('estoque.produtos_insumos.excluir')
    && in_array((string)($_SESSION['user_role'] ?? ''), PROD_ROLES_EXCLUIR, true);
?>
<style>
.pr-filtros{display:grid;gap:10px;flex:1}
.pr-filtros__busca{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.pr-filtros__busca input[type=text]{flex:1 1 260px;min-width:0}
.pr-filtros__selects{display:grid;grid-template-columns:repeat(auto-fit,minmax(148px,1fr));gap:8px}
.pr-filtros select{min-width:0;width:100%}
.pr-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:11px 0 2px}
.pr-chips .lbl{font:600 10.5px 'IBM Plex Sans';text-transform:uppercase;letter-spacing:.05em;color:#8A7D6E}
.pr-chip{display:inline-flex;align-items:center;gap:6px;background:#F0EBDF;border:1px solid #E1D9C7;border-radius:20px;padding:3px 10px;font-size:12px;color:#4A3F33;text-decoration:none;white-space:nowrap;line-height:1.6}
.pr-chip:hover{background:#E7E0D2;color:#241B14}
.pr-chip .x{font-size:14px;color:#9A3B2A;font-weight:600}
.pr-chip--clear{background:transparent;border-style:dashed;color:#8A7D6E}
.pr-chip--clear:hover{color:#9A3B2A;border-color:#C9A99A}
.pr-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.pr-table{width:100%;border-collapse:collapse;min-width:960px}
.pr-table thead th{background:#F5F1E8;font:600 11px 'IBM Plex Sans';text-transform:uppercase;letter-spacing:.03em;color:#6B5F53;border-bottom:2px solid #E1D9C7;padding:10px 11px;text-align:left;white-space:nowrap}
.pr-table tbody td{padding:9px 11px;border-bottom:1px solid #F0EBDF;vertical-align:top}
.pr-table tbody tr:nth-child(even){background:#FBFAF6}
.pr-table tbody tr:hover{background:#F4F1E8}
.pr-table tbody tr.pr-crit{box-shadow:inset 3px 0 0 #C2410C}
.pr-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
@media(max-width:820px){.pr-filtros{width:100%}}
</style>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <header class="vero-topbar">
    <h1 class="vero-topbar__title">Produtos e Insumos</h1>
    <div class="vero-topbar__actions">
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= rtrim(BIOS_BASE, '/') ?>/estoque/agrofit" title="Importar produtos do catálogo oficial do MAPA (Agrofit)">Importar do Agrofit</a>
      <?php if ($podeEditar): /* X-10: import em massa por planilha CSV */ ?>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= rtrim(BIOS_BASE, '/') ?>/estoque/importar_produtos" title="Carga em massa de produtos por planilha CSV (template + pré-visualização)">Importar planilha</a>
      <?php endif; ?>
      <?php if ($podeEditar): /* C-07 v2: modal Saldo Inicial (data + código → saldo → corrigir) */ ?>
      <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="vModalOpen('vm-saldo')" title="Informe a data e o código do produto; o sistema mostra o saldo atual e você corrige (a diferença entra/sai pelo estoque oficial).">Saldo Inicial</button>
      <?php endif; ?>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h($exportUrl) ?>" title="Baixar a lista filtrada em CSV (abre no Excel)">Exportar CSV</a>
      <?php /* C-43/A-07 (QA 19/07): vModalNovo — pós-?editar=N o form está renderizado
               em modo edição; recarrega com ?novo=1 (sem editar) p/ abrir VAZIO */ ?>
      <?php if ($podeEditar): ?><button class="vbtn vbtn-primary" type="button" onclick="vModalNovo('vm-form')">+ Novo produto</button><?php endif; ?>
    </div>
  </header>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" class="pr-filtros">
        <?php /* preserva ?alerta=1 (link externo) ao mexer nos selects — removível pelo chip */ ?>
        <?php if ($fAlerta === '1'): ?><input type="hidden" name="alerta" value="1"><?php endif; ?>
        <div class="pr-filtros__busca">
          <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por código, nome, ingrediente ativo, fabricante ou registro…">
          <button class="vbtn vbtn-primary vbtn-sm" type="submit">Buscar</button>
          <?php if ($filtroAtivo): ?><a class="vbtn vbtn-ghost vbtn-sm" href="<?= h($basePath) ?>" data-vero-clear>Limpar filtros</a><?php endif; ?>
          <span class="vsub" style="margin-left:auto"><?= $total ?> registro(s)</span>
        </div>
        <div class="pr-filtros__selects">
        <select name="tipo" onchange="this.form.submit()" aria-label="Tipo">
          <option value="">Todos os tipos</option>
          <?php foreach (TIPOS_INSUMO as $k => $lbl): ?><option value="<?= h($k) ?>"<?= $fTipo === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select>
        <select name="grupo" onchange="this.form.submit()" aria-label="Grupo">
          <option value="">Todos os grupos</option>
          <?php foreach ($grupos as $gid => $gnome): ?><option value="<?= (int)$gid ?>"<?= $fGrupo === (int)$gid ? ' selected' : '' ?>><?= h($gnome) ?></option><?php endforeach; ?>
        </select>
        <select name="subgrupo" onchange="this.form.submit()" aria-label="Subgrupo">
          <option value="">Todos os subgrupos</option>
          <?php foreach ($subgrupos as $sid => $slabel): ?><option value="<?= (int)$sid ?>"<?= $fSub === (int)$sid ? ' selected' : '' ?>><?= h($slabel) ?></option><?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()" aria-label="Status">
          <option value="">Ativos</option>
          <option value="inativo"<?= $fStatus === 'inativo' ? ' selected' : '' ?>>Inativos</option>
          <option value="todos"<?= $fStatus === 'todos' ? ' selected' : '' ?>>Ativos e inativos</option>
        </select>
        <select name="saldo" onchange="this.form.submit()" aria-label="Saldo">
          <option value="">Qualquer saldo</option>
          <?php foreach ($SALDO_OPC as $k => $lbl): ?><option value="<?= h($k) ?>"<?= $fSaldo === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select>
        <select name="validade" onchange="this.form.submit()" aria-label="Validade">
          <option value="">Qualquer validade</option>
          <?php foreach ($VAL_OPC as $k => $lbl): ?><option value="<?= h($k) ?>"<?= $fVal === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select>
        <select name="lote" onchange="this.form.submit()" aria-label="Lote">
          <option value="">Com e sem lote</option>
          <?php foreach ($LOTE_OPC as $k => $lbl): ?><option value="<?= h($k) ?>"<?= $fLote === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select>
        <select name="registro" onchange="this.form.submit()" aria-label="Registro MAPA">
          <option value="">Registro MAPA (todos)</option>
          <?php foreach ($REG_OPC as $k => $lbl): ?><option value="<?= h($k) ?>"<?= $fReg === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select>
        <?php if ($fabricantesOpt): ?>
        <select name="fabricante" onchange="this.form.submit()" aria-label="Fabricante">
          <option value="">Todos os fabricantes</option>
          <?php foreach ($fabricantesOpt as $fv): ?><option value="<?= h($fv) ?>"<?= $fFab === $fv ? ' selected' : '' ?>><?= h($fv) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($ingredientesOpt): ?>
        <select name="ingrediente" onchange="this.form.submit()" aria-label="Ingrediente ativo">
          <option value="">Todos os ingredientes ativos</option>
          <?php foreach ($ingredientesOpt as $iv): ?><option value="<?= h($iv) ?>"<?= $fIng === $iv ? ' selected' : '' ?>><?= h($iv) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        </div><!-- /pr-filtros__selects -->
      </form>
    </div>
    <?php if ($chips): ?>
    <div class="pr-chips">
      <span class="lbl">Filtros ativos:</span>
      <?php foreach ($chips as $c): ?>
        <a class="pr-chip" href="<?= h($c['url']) ?>" title="Remover este filtro"><?= h($c['label']) ?> <span class="x" aria-hidden="true">×</span></a>
      <?php endforeach; ?>
      <a class="pr-chip pr-chip--clear" href="<?= h($basePath) ?>" data-vero-clear>Limpar tudo</a>
    </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum produto encontrado para os filtros selecionados.</div>
    <?php else: ?>
    <div class="pr-wrap">
    <table class="pr-table vacoes-fixa" style="--vacoes-bg-even:#FBFAF6;--vacoes-bg-hover:#F4F1E8;--vacoes-bg-head:#F5F1E8">
      <thead><tr>
        <th>Código</th><th>Produto</th><th>Tipo</th><th>Grupo</th><th>Unidade</th>
        <th class="pr-num">Saldo</th>
        <th class="pr-num">Reservado / Disponível</th>
        <th class="pr-num">Custo médio (R$)</th>
        <th class="pr-num">Valor (R$)</th>
        <th class="pr-num">Mínimo</th>
        <th>Status</th><th class="pr-num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $saldo = (float)$r['saldo'];
          $custoMedio = $saldo > 0 ? (float)$r['valor'] / $saldo : 0.0;
          $abaixo = $saldo < (float)$r['estoque_minimo'];
          $proxVal = $r['prox_validade'] ?? null;
          $diasVal = $proxVal !== null
              ? (int)floor((strtotime((string)$proxVal) - strtotime(date('Y-m-d'))) / 86400) : null;
          $vencido = $diasVal !== null && $diasVal < 0;
          $semSaldo = $saldo <= 0.0001;
      ?>
        <tr<?= ($abaixo || $vencido) ? ' class="pr-crit"' : '' ?>>
          <td><strong class="vnum"><?= h($r['codigo']) ?></strong></td>
          <td><strong><?= h($r['nome']) ?></strong>
            <?php if (!empty($r['nao_registrado'])): ?> <span class="vbadge vb-warn" title="Produto não registrado (exigência de certificação)">não registrado</span><?php endif; ?>
            <?php if ($r['registro_mapa']): ?><div class="vhint">MAPA <?= h($r['registro_mapa']) ?></div><?php endif; ?></td>
          <td><?= $r['tipo_insumo'] !== null && isset(TIPOS_INSUMO[$r['tipo_insumo']])
                ? h(TIPOS_INSUMO[$r['tipo_insumo']]) : '<span class="vhint">—</span>' ?></td>
          <td><?= h($r['grupo_nome'] ?? '') ?: '—' ?></td>
          <td><?= h($r['unidade']) ?></td>
          <?php $acima = (float)$r['estoque_maximo'] > 0 && $saldo > (float)$r['estoque_maximo']; ?>
          <td class="pr-num">
            <strong<?= $abaixo ? ' style="color:#b3261e"' : ($acima ? ' style="color:#8A6D1A"' : '') ?>><?= numFmt($saldo, 2) ?></strong>
          </td>
          <td class="pr-num"><?php
            $resv = $reservas[(int)$r['id']] ?? 0.0;
            if ($resv > 0.0001) {
                $disp = max(0.0, $saldo - $resv);
                echo '<span title="reservado p/ atividades planejadas (orientativo)">'
                    . numFmt($resv, 2) . '</span> / <strong'
                    . ($disp <= 0.0001 ? ' style="color:#b3261e"' : '') . '>'
                    . numFmt($disp, 2) . '</strong>';
            } else {
                echo '<span class="vhint">—</span>';
            }
          ?></td>
          <td class="pr-num"><?= $veCusto ? numFmt($custoMedio, 2) : '<span class="vhint" title="sem permissão financeira">•••</span>' ?></td>
          <td class="pr-num"><?= $veCusto ? numFmt((float)$r['valor'], 2) : '<span class="vhint">•••</span>' ?></td>
          <td class="pr-num"><?= numFmt((float)$r['estoque_minimo'], 2) ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td class="pr-num"><div class="vactions" style="justify-content:flex-end">
            <?php /* lotes do produto em modal (um produto pode ter vários lotes);
                     ícone destaca em vermelho/âmbar quando há lote vencido/vencendo */ ?>
            <?= vero_btn_icone(vero_ico_olho(),
                  $vencido ? 'Ver lotes (HÁ LOTE VENCIDO)' : ($diasVal !== null && $diasVal <= 30 ? 'Ver lotes (vence em breve)' : 'Ver lotes'),
                  "lotesAbrir(" . (int)$r['id'] . ", '" . h(addslashes($r['codigo'] . ' — ' . $r['nome'])) . "', '" . h($r['unidade']) . "')") ?>
            <?php if ($podeEditar): ?>
              <?= vero_btn_icone(vero_ico_mover(), 'Movimentar', "movAbrir(" . (int)$r['id'] . ", '" . h(addslashes($r['nome'])) . "', '" . h($r['unidade']) . "', " . json_encode($saldo) . ")") ?>
              <?= vero_btn_editar((int)$r['id']) ?>
            <?php endif; ?>
            <?php if ($podeExcluir && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este produto?') ?>
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
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar produto' : 'Novo produto' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?php if (produtos_codigo6_ativo()): /* A2-F2-15: 6 dígitos, pré-preenchido */ ?>
          <?= vero_f_text('codigo', 'Código (6 dígitos)',
                $edit['codigo'] ?? vero_srv_produto_proximo_codigo(), true,
                'Gerado automaticamente — sobrescreva se precisar (6 números, único)') ?>
        <?php else: ?>
          <?= vero_f_text('codigo', 'Código', $edit['codigo'] ?? '', true, 'Único no estoque, ex.: FERT-001') ?>
        <?php endif; ?>
        <?= vero_f_text('nome', 'Nome do produto', $edit['nome'] ?? '', true) ?>
        <div class="vfield">
          <label>Tipo de insumo</label>
          <select name="tipo_insumo" id="f-tipo-insumo" onchange="tipoInsumoUI()">
            <option value="">— Sem tipo —</option>
            <?php foreach (TIPOS_INSUMO as $k => $lbl): ?>
              <option value="<?= h($k) ?>"<?= ($edit['tipo_insumo'] ?? '') === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= vero_f_select('grupo_id', 'Grupo', $grupos, $edit['grupo_id'] ?? null, false, '— Padrão (Insumos Agrícolas) —') ?>
        <?= vero_f_select('subgrupo_id', 'Subgrupo', $subgrupos, $edit['subgrupo_id'] ?? null, false, '— Nenhum —') ?>
        <?= vero_f_text('unidade', 'Unidade de uso', $edit['unidade'] ?? 'kg', true, 'kg, L, un, sc…') ?>
        <?= vero_f_text('ingrediente_ativo', 'Ingrediente/princípio ativo', $edit['ingrediente_ativo'] ?? '') ?>
        <?= vero_f_text('fabricante', 'Fabricante', $edit['fabricante'] ?? '') ?>
      </div>

      <?php /* achado frota 25/08: fertilizante/corretivo também têm registro MAPA
               (Lei 6.894/1980, regime próprio) — o campo era exclusivo do bloco de
               defensivo e não havia onde digitar. Bloco partido em dois: REGISTRO
               (defensivo + fertilizante + corretivo) × regulatório só-defensivo
               (classe toxicológica e carência não se aplicam a adubo). */ ?>
      <div id="bloco-registro" style="margin-top:10px;padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">Registro no MAPA</strong>
        <div class="vhint" style="margin-bottom:6px">Nº do rótulo/bula — defensivos (Lei 7.802/1989) e fertilizantes/corretivos (Lei 6.894/1980) têm registros próprios. Informativo, sai na DF/IF.</div>
        <div class="vgrid">
          <?= vero_f_text('registro_mapa', 'Registro MAPA', $edit['registro_mapa'] ?? '', false, 'nº do registro no Ministério da Agricultura') ?>
          <?= vero_f_select('nao_registrado', 'Produto não registrado', [0 => 'Não', 1 => 'Sim'], (int)($edit['nao_registrado'] ?? 0), false, '') ?>
        </div>
      </div>

      <div id="bloco-defensivo" style="margin-top:10px;padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">Dados regulatórios (defensivo)</strong>
        <div class="vhint" style="margin-bottom:6px">Registro informativo do rótulo/bula — o sistema NUNCA recomenda produto, dose ou carência (validação nominal do RT).</div>
        <div class="vgrid">
          <?= vero_f_text('classe_toxicologica', 'Classe toxicológica', $edit['classe_toxicologica'] ?? '', false, 'ex.: Categoria 4') ?>
          <?= vero_f_text('carencia_dias', 'Carência (dias)', $edit && $edit['carencia_dias'] !== null ? (string)(int)$edit['carencia_dias'] : '', false, 'do rótulo — informativa') ?>
        </div>
      </div>

      <div style="margin-top:10px;padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">Dados de bula (registro do RT)</strong>
        <div class="vhint" style="margin-bottom:6px">Copiados como referência EDITÁVEL na emissão da DF/IF.</div>
        <?php if (!produtos_pode_editar_bula()): ?>
          <div class="vflash vflash-aviso" style="margin-bottom:8px">Edição restrita a gestor/RT (decisão do cliente — P-49): os valores abaixo são preservados ao salvar.</div>
        <?php endif; ?>
        <fieldset<?= produtos_pode_editar_bula() ? '' : ' disabled' ?> style="border:0;padding:0;margin:0">
        <div class="vgrid">
          <?= vero_f_text('dose_referencia', 'Dose de referência',
                $edit && $edit['dose_referencia'] !== null ? numFmt((float)$edit['dose_referencia'], 4) : '', false, 'da bula') ?>
          <?= vero_f_text('dose_referencia_unidade', 'Unidade da dose',
                $edit['dose_referencia_unidade'] ?? '', false, 'ex.: kg/ha, L/ha, mL/100L') ?>
          <?= vero_f_text('lmr_dias', 'LMR (dias)',
                $edit && $edit['lmr_dias'] !== null ? (string)(int)$edit['lmr_dias'] : '', false, 'limite de dias p/ aplicação (conceito do cliente — P-49)') ?>
          <?= vero_f_text('intervalo_aplicacoes_dias', 'Intervalo entre aplicações (dias)',
                $edit && $edit['intervalo_aplicacoes_dias'] !== null ? (string)(int)$edit['intervalo_aplicacoes_dias'] : '') ?>
          <?= vero_f_text('num_max_aplicacoes', 'Nº máximo de aplicações',
                $edit && $edit['num_max_aplicacoes'] !== null ? (string)(int)$edit['num_max_aplicacoes'] : '', false, 'por safra (granularidade em validação — P-49)') ?>
          <?= vero_f_text('estoque_ideal', 'Estoque ideal',
                $edit && (float)$edit['estoque_ideal'] > 0 ? numFmt((float)$edit['estoque_ideal'], 2) : '', false, 'entre o mínimo e o máximo — apoio à compra') ?>
        </div>
        <div style="margin-top:8px">
          <strong style="font-size:12.5px">Concentrações nutricionais (%)</strong>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(86px,1fr));gap:6px;margin-top:6px">
            <?php foreach (NUTRIENTES_BULA as $simb): ?>
              <div class="vfield" style="margin:0">
                <label style="font-size:11px"><?= h($simb) ?></label>
                <input type="text" name="nutr[<?= h($simb) ?>]" inputmode="decimal" placeholder="—"
                       style="text-align:right;padding:4px 6px"
                       value="<?= isset($editNutr[$simb]) ? numFmt($editNutr[$simb], 2) : '' ?>">
              </div>
            <?php endforeach; ?>
          </div>
          <div class="vhint" style="margin-top:4px">Preencha só os presentes no rótulo — a DF imprime apenas os informados.</div>
        </div>
        </fieldset>
      </div>

      <div style="margin-top:10px;padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">Compras</strong>
        <div class="vgrid" style="margin-top:6px">
          <?= vero_f_select('fornecedor_padrao_id', 'Fornecedor padrão', $fornecedoresOpt, $edit['fornecedor_padrao_id'] ?? null, false, '— Nenhum —') ?>
          <?= vero_f_text('unidade_compra', 'Unidade de compra', $edit['unidade_compra'] ?? '', false, 'ex.: galão, sc, cx') ?>
          <?= vero_f_text('fator_conversao', 'Fator de conversão', $edit && $edit['fator_conversao'] !== null ? numFmt((float)$edit['fator_conversao'], 4) : '', false, '1 unidade de compra = X unidades de uso (ex.: galão 20 L → 20)') ?>
        </div>
      </div>

      <div class="vgrid" style="margin-top:10px">
        <?= vero_f_text('estoque_minimo', 'Estoque mínimo', $edit ? numFmt((float)$edit['estoque_minimo'], 2) : '', false, 'Alerta automático quando o saldo ficar abaixo') ?>
        <?= vero_f_text('estoque_maximo', 'Estoque máximo', $edit && (float)$edit['estoque_maximo'] > 0 ? numFmt((float)$edit['estoque_maximo'], 2) : '', false, 'Sinaliza excesso na listagem (0 = sem limite)') ?>
        <?= vero_f_select('plano_conta_id', 'Conta do plano de contas', $planoContasOpt, $edit['plano_conta_id'] ?? null, false, '— Nenhuma —') ?>
        <div class="vfield full">
          <label style="display:inline-flex;align-items:center;gap:8px;font-weight:600">
            <input type="checkbox" name="controla_validade" id="f-controla-validade" value="1" style="width:auto"
                   <?= $edit && (int)$edit['controla_validade'] === 1 ? 'checked' : '' ?>>
            Produto perecível (controla validade por lote)
          </label>
          <div class="vhint">Entradas exigem validade; as aplicações consomem o lote de prazo mais próximo (FEFO) e o sistema alerta vencimentos em até 30 dias. Defensivos controlam validade automaticamente.</div>
        </div>
        <div class="vfield full">
          <label style="display:inline-flex;align-items:center;gap:8px;font-weight:600">
            <input type="checkbox" name="controla_lote" value="1" style="width:auto"
                   <?= $edit && (int)$edit['controla_lote'] === 1 ? 'checked' : '' ?>>
            Controla lote (rastreabilidade mesmo sem validade)
          </label>
          <div class="vhint">O inventário conta por lote quando houver lotes ativos.</div>
        </div>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativo', 0 => 'Inativo'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vhint" style="margin-top:8px">O saldo é criado/ajustado pelas movimentações (entrada com custo médio ponderado).</div>
      <script>
      function tipoInsumoUI() {
        const t = document.getElementById('f-tipo-insumo').value;
        document.getElementById('bloco-registro').style.display =
          ['defensivo', 'fertilizante', 'corretivo'].includes(t) ? '' : 'none';
        document.getElementById('bloco-defensivo').style.display = (t === 'defensivo') ? '' : 'none';
        if (t === 'defensivo') {
          const cv = document.getElementById('f-controla-validade');
          cv.checked = true;
        }
      }
      tipoInsumoUI();
      </script>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>

<?php if ($podeEditar): /* C-07 v2 — modal Saldo Inicial */ ?>
<div class="vmodal" id="vm-saldo">
  <div class="vbox">
    <header>
      <h2>Saldo Inicial</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-saldo')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="saldo_inicial">
      <div class="vgrid">
        <div class="vfield">
          <label>Data da implantação *</label>
          <input type="date" name="si_data" value="<?= h(date('Y-m-d')) ?>" required>
        </div>
        <?php if (count($saldoIniAlmoxes) > 1): ?>
        <div class="vfield">
          <label>Almoxarifado *</label>
          <select name="si_almox" id="si-almox">
            <?php foreach ($saldoIniAlmoxes as $aid => $anome): ?>
              <option value="<?= (int)$aid ?>"<?= (int)$aid === (int)$saldoIniAlmoxPadrao ? ' selected' : '' ?>><?= h($anome) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="vhint">O saldo atual e a diferença são medidos neste almoxarifado.</div>
        </div>
        <?php else: ?>
        <input type="hidden" name="si_almox" id="si-almox" value="<?= (int)$saldoIniAlmoxPadrao ?>">
        <?php endif; ?>
        <div class="vfield">
          <label>Produto *</label>
          <?php /* 11/08: um campo so. Digita-se nome OU codigo e a lista filtra dentro
                   do proprio seletor. O si_codigo virou hidden — o servidor continua
                   lendo exatamente o mesmo campo de antes. */ ?>
          <div class="vcb">
            <input type="text" id="si-busca" class="vcb-inp" autocomplete="off" role="combobox"
                   aria-expanded="false" placeholder="digite o nome ou o código do produto…">
            <div class="vcb-lista" role="listbox"></div>
          </div>
          <input type="hidden" name="si_codigo" id="si-codigo">
          <div class="vhint" id="si-info">Digite o nome ou o código do produto.</div>
        </div>
        <div class="vfield">
          <label>Saldo correto *</label>
          <input type="text" name="si_qtd" id="si-qtd" placeholder="0,00" style="text-align:right" required>
          <div class="vhint">O ajuste é feito pela DIFERENÇA (entrada ou saída oficial, origem "implantação").</div>
        </div>
        <div class="vfield">
          <label>Custo unitário (R$)</label>
          <input type="text" name="si_custo" id="si-custo" placeholder="0,00" style="text-align:right">
          <div class="vhint">Obrigatório quando o ajuste for ENTRADA (saldo correto maior que o atual).</div>
        </div>
        <div class="vfield" id="si-validade-wrap" style="display:none">
          <label>Validade do lote *</label>
          <input type="date" name="si_validade" id="si-validade">
          <div class="vhint">Produto controla validade/lote — obrigatória na entrada.</div>
        </div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-saldo')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit" id="si-submit" disabled>Corrigir saldo</button>
      </div>
    </form>
  </div>
</div>
<style>
/* Combobox de produto (11/08). A lista é position:FIXED (19/08): com absolute
   ela era CORTADA pelo overflow do card/modal e o usuário tinha que rolar
   para enxergar; fixed escapa do clipping e o JS cola a posição no input. */
.vcb { position: relative; }
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
/* C-07 v2: lookup do produto pelo código, ao vivo (dados do servidor, escapados via textContent) */
const SI_PRODUTOS = <?= jsvar(array_map(static fn($p) => [
    'id' => (int)$p['id'],
    'codigo' => strtoupper((string)$p['codigo']), 'nome' => (string)$p['nome'],
    'un' => (string)$p['unidade'],
    'val' => ((int)$p['controla_validade'] === 1 || (int)$p['controla_lote'] === 1),
], $saldoIniProdutos)) ?>;
const SI_SALDOS = <?= jsvar($saldoIniSaldos) ?>; /* [produto_id][almox_id] => {saldo, cm} */
/* rota antiga /estoque/implantacao_saldo.php redireciona com ?saldo=1 → abre o modal */
(function(){
  var sp = new URLSearchParams(location.search);
  if (sp.has('editar')) return;                 // editando: NÃO auto-abre o Saldo Inicial
  if (sp.get('saldo') === '1') {
    vModalOpen('vm-saldo');
    if (history.replaceState) { sp.delete('saldo'); history.replaceState(null, '', location.pathname + (sp.toString() ? '?' + sp : '')); }
  }
})();
(function () {
  const cod = document.getElementById('si-codigo');
  const info = document.getElementById('si-info');
  const qtd = document.getElementById('si-qtd');
  const valWrap = document.getElementById('si-validade-wrap');
  const btn = document.getElementById('si-submit');
  const almox = document.getElementById('si-almox');
  if (!cod) return;
  function fmt(n) { return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function refresh() {
    const p = SI_PRODUTOS.find(x => x.codigo === cod.value.trim().toUpperCase());
    if (!p) {
      info.textContent = cod.value.trim() === '' ? 'Digite o nome ou o código do produto.'
                                                 : 'Produto não encontrado.';
      info.style.color = ''; valWrap.style.display = 'none'; btn.disabled = true;
      return;
    }
    const aid = almox ? String(almox.value) : '';
    const sd = (SI_SALDOS[p.id] && SI_SALDOS[p.id][aid]) ? SI_SALDOS[p.id][aid] : { saldo: 0, cm: 0 };
    info.textContent = p.nome + ' — saldo atual: ' + fmt(sd.saldo) + ' ' + p.un
                     + ' · custo médio R$ ' + fmt(sd.cm);
    info.style.color = '#2F5D33';
    valWrap.style.display = p.val ? '' : 'none';
    btn.disabled = false;
    qtd.value = fmt(sd.saldo);   /* ponto de partida = saldo do almoxarifado (editável) */
  }
  cod.addEventListener('input', refresh);
  if (almox) almox.addEventListener('change', refresh);

  /* ── Busca dentro do seletor ──────────────────────────────
     Um campo so: digita-se nome ou codigo, a lista filtra abaixo e a escolha
     grava no hidden si_codigo, delegando ao refresh() que ja existia. O POST
     enviado ao servidor e identico ao de antes. */
  const busca = document.getElementById('si-busca');
  const lista = busca ? busca.parentNode.querySelector('.vcb-lista') : null;
  if (busca && lista) {
    const rotulo = p => p.codigo + ' — ' + p.nome;
    function fecha() { lista.style.display = 'none'; busca.setAttribute('aria-expanded', 'false'); }
    /* Cola a lista (fixed) no input; abre para CIMA quando falta espaço embaixo. */
    function posiciona() {
      if (lista.style.display === 'none') return;
      const r = busca.getBoundingClientRect();
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
    /* rolagem/resize com a lista aberta (o input se move) → recola; capture
       pega também o scroll interno do .vbox do modal */
    ['scroll', 'resize'].forEach(function (ev) {
      window.addEventListener(ev, posiciona, true);
    });
    function escolhe(p) {
      cod.value = p.codigo;
      busca.value = rotulo(p);
      fecha();
      refresh();
    }
    function abre(termo) {
      const q = (termo || '').trim().toLowerCase();
      const itens = SI_PRODUTOS.filter(p =>
        q === '' || p.nome.toLowerCase().includes(q) || p.codigo.toLowerCase().includes(q));
      lista.innerHTML = '';
      if (!itens.length) {
        const v = document.createElement('div');
        v.className = 'vcb-vazio';
        v.textContent = 'nenhum produto encontrado';
        lista.appendChild(v);
      }
      itens.slice(0, 300).forEach(p => {
        const it = document.createElement('div');
        it.className = 'vcb-item' + (p.codigo === cod.value ? ' vcb-sel' : '');
        it.setAttribute('role', 'option');
        it.textContent = rotulo(p);          /* textContent: nao interpreta HTML */
        /* mousedown, nao click: roda ANTES do blur fechar a lista */
        it.addEventListener('mousedown', function (ev) { ev.preventDefault(); escolhe(p); });
        lista.appendChild(it);
      });
      lista.style.display = 'block';
      posiciona(); /* fixed: posição calculada a cada abertura */
      busca.setAttribute('aria-expanded', 'true');
    }
    busca.addEventListener('focus', function () { busca.select(); abre(''); });
    busca.addEventListener('input', function () {
      cod.value = '';        /* texto alterado sem escolher = sem produto valido */
      refresh();
      abre(busca.value);
    });
    busca.addEventListener('blur', function () {
      setTimeout(function () {
        fecha();
        const p = SI_PRODUTOS.find(x => x.codigo === cod.value);
        busca.value = p ? rotulo(p) : '';   /* restaura o rotulo ou limpa */
      }, 150);
    });
    busca.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { fecha(); busca.blur(); return; }
      if (ev.key === 'Enter') {
        const primeiro = lista.querySelector('.vcb-item');
        if (primeiro && lista.style.display !== 'none') {
          ev.preventDefault();
          primeiro.dispatchEvent(new MouseEvent('mousedown'));
        }
      }
    });
  }
  refresh();   /* estado inicial: botao desabilitado ate escolher o produto */
})();
</script>
<?php endif; ?>

<div class="vmodal" id="vm-mov">
  <div class="vbox">
    <header>
      <h2 id="mov-titulo">Movimentar estoque</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-mov')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="movimentar">
      <input type="hidden" name="produto_id" id="mov-produto">
      <div class="vgrid">
        <div class="vfield">
          <label>Tipo *</label>
          <select name="tipo_mov" id="mov-tipo" required onchange="movTipo()">
            <option value="entrada">Entrada</option>
            <option value="saida">Saída</option>
          </select>
        </div>
        <div class="vfield">
          <label>Data *</label>
          <input type="date" name="data_mov" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="vfield">
          <label>Quantidade *</label>
          <input type="text" name="quantidade" required inputmode="decimal">
          <div class="vhint" id="mov-saldo"></div>
        </div>
        <div class="vfield" id="mov-custo-box">
          <label>Custo unitário (R$) *</label>
          <input type="text" name="custo_unitario" inputmode="decimal">
          <div class="vhint">Compõe o custo médio ponderado</div>
        </div>
        <div class="vfield" id="mov-validade-box">
          <label>Validade do lote</label>
          <input type="date" name="validade">
          <div class="vhint">Obrigatória para produtos perecíveis — cria o lote (FEFO)</div>
        </div>
        <div class="full"><?= vero_f_text('observacao_mov', 'Observação (opcional)', '') ?></div>
        <div class="vfield full" id="mov-vencido-box">
          <label style="display:inline-flex;align-items:center;gap:8px;font-weight:600">
            <input type="checkbox" name="permitir_vencido" value="1" style="width:auto">
            Confirmo a saída de lote VENCIDO (decisão do RT)
          </label>
          <div class="vhint">Exigido só quando o FEFO consumiria lote vencido — o consumo confirmado fica na trilha.</div>
        </div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-mov')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Registrar movimentação</button>
      </div>
    </form>
  </div>
</div>

<div class="vmodal" id="vm-lotes">
  <div class="vbox">
    <header><h2 id="lot-titulo">Lotes do produto</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-lotes')">×</button></header>
    <div id="lot-corpo" style="max-height:60vh;overflow-y:auto"></div>
  </div>
</div>
<script>
var LOTES = <?= jsvar($lotesPorProduto) ?>;
function lotesAbrir(id, titulo, unidade) {
  document.getElementById('lot-titulo').textContent = 'Lotes — ' + titulo;
  var ls = LOTES[id] || [], corpo = document.getElementById('lot-corpo');
  if (!ls.length) {
    corpo.innerHTML = '<div class="vempty">Nenhum lote com saldo para este produto (saldo sem rastreabilidade de lote ou zerado).</div>';
  } else {
    var linhas = ls.map(function (l) {
      var cor = '', status = '—';
      if (l.dias !== null) {
        if (l.dias < 0) { cor = 'color:#b3261e;font-weight:600'; status = 'VENCIDO há ' + Math.abs(l.dias) + ' dia(s)'; }
        else if (l.dias <= 30) { cor = 'color:#8A6D1A;font-weight:600'; status = 'vence em ' + l.dias + ' dia(s)'; }
        else { status = 'ok'; }
      }
      return '<tr><td class="vnum"><strong>' + l.lote + '</strong></td><td>' + l.almox + '</td>'
        + '<td class="vnum" style="text-align:right"><strong>' + l.qtd.toLocaleString('pt-BR', {minimumFractionDigits: 2}) + '</strong> ' + unidade + '</td>'
        + '<td class="vnum" style="' + cor + '">' + (l.val || 'sem validade') + '</td>'
        + '<td style="' + cor + '">' + status + '</td></tr>';
    }).join('');
    corpo.innerHTML = '<table class="vtable"><thead><tr><th>Lote</th><th>Almoxarifado</th>'
      + '<th style="text-align:right">Quantidade</th><th>Validade</th><th>Situação</th></tr></thead><tbody>'
      + linhas + '</tbody></table>';
  }
  vModalOpen('vm-lotes');
}
function movAbrir(id, nome, unidade, saldo) {
  document.getElementById('mov-produto').value = id;
  document.getElementById('mov-titulo').textContent = 'Movimentar — ' + nome;
  document.getElementById('mov-saldo').textContent = 'Saldo atual: ' + saldo.toLocaleString('pt-BR', {minimumFractionDigits: 2}) + ' ' + unidade;
  movTipo();
  vModalOpen('vm-mov');
}
function movTipo() {
  const entrada = document.getElementById('mov-tipo').value === 'entrada';
  document.getElementById('mov-custo-box').style.display = entrada ? '' : 'none';
  document.getElementById('mov-validade-box').style.display = entrada ? '' : 'none';
  document.getElementById('mov-vencido-box').style.display = entrada ? 'none' : '';
  document.querySelector('[name=custo_unitario]').required = entrada;
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
