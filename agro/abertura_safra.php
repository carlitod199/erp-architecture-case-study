<?php
/* ============================================================
   VERO — Gestão Agrícola / Abertura de Safra  (Onda 5 · item 2d)
   Rota da matriz: /agro/abertura_safra.php
   Guard: agricola.abertura_safra (perm agro.safra.abrir)
   Fluxo formal e AUDITÁVEL (decisões do gestor 15/07):
     1. Abrir safra do semestre (AAAA.S-NN, status 'ativa') — agrupa válvulas.
     2. Listar válvulas candidatas e validar pré-condições EXPLÍCITAS por
        válvula (OS de poda concluídas + ≥1 apontamento de poda).
     3. Confirmar Poda Finalizada por válvula → grava no vínculo
        agro_safra_talhoes (data_poda = ÚLTIMO apontamento de poda, dia 0),
        poda_status='finalizada', trilha quem/quando. Idempotente.
   NÃO toca: migrations, fenologia (helper/CRUD), apontamentos, colheita.
   Reusa a identificação (T-05) e o fim previsto (T-04) do safras/index.php.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php'; // P-09: reemitir custeio ao re-vincular poda

const T  = 'agro_safras';
const TV = 'agro_safra_talhoes';

/* estado categórico da poda por válvula — validado em PHP (nunca inferido) */
const PODA_STATUS = ['pendente', 'finalizada'];

/* ── Poda: identifica o tipo de atividade "Poda" ─────────────────
   Decisão do gestor: poda é trato_cultural; identificamos pelo NOME.
   Retorna a lista de ids de tipos-poda do tenant (pode ser >1 se houver
   variações "Poda verde", "Poda seca"…). Ints → inline seguro nas queries. */
function abertura_poda_tipo_ids(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = array_map('intval', array_column(vero_rows(
        "SELECT id FROM agro_tipos_atividade
          WHERE tenant_id = :t AND categoria = 'trato_cultural' AND nome LIKE :nm",
        [':t' => vero_tenant(), ':nm' => '%poda%']), 'id'));
    return $cache;
}

/* ── Fim previsto (regra T-04, idêntica ao safras/index.php) ──────
   data_inicio + MAIOR ciclo entre as variedades vinculadas. Ciclo =
   COALESCE(ciclo_dias, ciclo_poda_colheita_dias). null se não calculável.
   HY093-safe (cada placeholder uma vez por query). */
function abertura_fim_previsto(int $safraId): ?string
{
    if (!$safraId) return null;
    $ini = vero_val("SELECT data_inicio FROM " . T . " WHERE id = :s AND tenant_id = :t",
        [':s' => $safraId, ':t' => vero_tenant()]);
    if (!$ini) return null;
    $max = vero_val(
        "SELECT MAX(COALESCE(vr.ciclo_dias, vr.ciclo_poda_colheita_dias))
           FROM " . TV . " st
           JOIN agro_talhoes    tl ON tl.id = st.talhao_id    AND tl.tenant_id = st.tenant_id
           JOIN agro_variedades vr ON vr.id = tl.variedade_id AND vr.tenant_id = st.tenant_id
          WHERE st.safra_id = :s AND st.tenant_id = :t",
        [':s' => $safraId, ':t' => vero_tenant()]);
    if ($max === null || (int)$max <= 0) return null;
    $dt = date_create((string)$ini);
    if (!$dt) return null;
    $dt->modify('+' . (int)$max . ' days');
    return $dt->format('Y-m-d');
}

/* ── Pré-condições de poda de UMA válvula (re-checagem no servidor) ──
   Retorna as métricas cruas; a decisão "pronta" é montada pelo chamador.
   HY093-safe: ids de tipo-poda vêm inline como inteiros (nada de placeholder
   repetido); :t e :tl distintos. */
function abertura_precond(int $talhaoId): array
{
    $ids = abertura_poda_tipo_ids();
    $zero = ['os_abertas' => 0, 'os_concluidas' => 0, 'apont_poda' => 0, 'ult_apont' => null];
    if (!$ids) return $zero;
    $in = implode(',', $ids); // apenas inteiros
    /* Uma OS conta como "de poda" se casa por QUALQUER das duas vias:
         (a) planejada    — os.atividade_id -> agro_atividades.tipo_atividade_id
         (b) apontamento  — a OS-espelho de um apontamento de poda
                            (agro_apontamentos.ordem_servico_id = os.id), cuja
                            OS nasce com atividade_id NULL. Sem (b), a poda feita
                            SÓ por apontamento (fluxo de campo comum) nunca satisfaz
                            o pré-req — embora a oferta ≥95% (por apontamento) apareça.
       HY093-safe: subconsultas correlatas, sem novos placeholders. */
    $podaOS = "(EXISTS (SELECT 1 FROM agro_atividades atv
                          WHERE atv.id = os.atividade_id AND atv.tenant_id = os.tenant_id
                            AND atv.tipo_atividade_id IN ($in))
                OR EXISTS (SELECT 1 FROM agro_apontamentos ap
                            WHERE ap.ordem_servico_id = os.id AND ap.tenant_id = os.tenant_id
                              AND ap.tipo_atividade_id IN ($in)))";
    $r = vero_row(
        "SELECT
            (SELECT COUNT(*) FROM agro_ordens_servico os
              WHERE os.tenant_id = :t1 AND os.talhao_id = :tl1
                AND os.status IN ('aberta','em_execucao') AND $podaOS) AS os_abertas,
            (SELECT COUNT(*) FROM agro_ordens_servico os
              WHERE os.tenant_id = :t2 AND os.talhao_id = :tl2
                AND os.status = 'concluida' AND $podaOS) AS os_concluidas,
            (SELECT COUNT(*) FROM agro_apontamentos ap
              WHERE ap.tenant_id = :t3 AND ap.talhao_id = :tl3
                AND ap.tipo_atividade_id IN ($in)) AS apont_poda,
            (SELECT MAX(DATE(ap.data_apontamento)) FROM agro_apontamentos ap
              WHERE ap.tenant_id = :t4 AND ap.talhao_id = :tl4
                AND ap.tipo_atividade_id IN ($in)) AS ult_apont",
        [':t1' => vero_tenant(), ':tl1' => $talhaoId, ':t2' => vero_tenant(), ':tl2' => $talhaoId,
         ':t3' => vero_tenant(), ':tl3' => $talhaoId, ':t4' => vero_tenant(), ':tl4' => $talhaoId]);
    return $r ?: $zero;
}

/* ── Identificação sequencial AAAA.S-NN (regra T-05, = safras/index.php) ──
   Semestre pela data de hoje. Extraído p/ ser reusado pelo "abrir" manual e
   pelo atalho W-13 "iniciar nova safra pela poda". */
function abertura_gerar_identificacao(): string
{
    $identSeq = [];
    foreach (vero_rows("SELECT identificacao FROM " . T . " WHERE tenant_id = :t", [':t' => vero_tenant()]) as $r) {
        if (preg_match('/^(\d{4}\.[12])-(\d{2,})$/', (string)$r['identificacao'], $m)) {
            $p = $m[1]; $n = (int)$m[2];
            if (!isset($identSeq[$p]) || $n > $identSeq[$p]) $identSeq[$p] = $n;
        }
    }
    $hoje = date_create('today');
    $pref = $hoje->format('Y') . '.' . ((int)$hoje->format('n') <= 6 ? '1' : '2');
    return $pref . '-' . str_pad((string)(($identSeq[$pref] ?? 0) + 1), 2, '0', STR_PAD_LEFT);
}

/* ── Grava o vínculo safra↔válvula com a poda FINALIZADA e ajusta a safra
   (1ª poda define o dia 0 = data_inicio; recalcula o fim previsto T-04).
   Idempotente por upsert. DEVE rodar DENTRO de transação. Retorna quantas
   válvulas já estavam finalizadas ANTES desta (0 = esta é a 1ª → definiu o dia 0).
   Reusado por confirmar_poda (manual) e nova_safra_poda (atalho da poda). */
function abertura_grava_vinculo_poda(int $safraId, int $talhaoId, int $culturaId, float $areaHa, string $dataPoda): int
{
    $t = vero_tenant();
    $finalizadasAntes = (int)vero_val(
        "SELECT COUNT(*) FROM " . TV . " WHERE tenant_id = :t AND safra_id = :s AND poda_status = 'finalizada'",
        [':t' => $t, ':s' => $safraId]);
    $vinc = vero_row("SELECT id FROM " . TV . " WHERE tenant_id = :t AND safra_id = :s AND talhao_id = :tl",
        [':t' => $t, ':s' => $safraId, ':tl' => $talhaoId]);
    $dadosPoda = [
        'data_poda'           => $dataPoda,
        'poda_status'         => 'finalizada',
        'poda_confirmada_em'  => date('Y-m-d H:i:s'),
        'poda_confirmada_por' => vero_uid(),
    ];
    if ($vinc) {
        vero_update(TV, (int)$vinc['id'], $dadosPoda);
    } else {
        vero_insert(TV, array_merge([
            'safra_id'              => $safraId,
            'talhao_id'             => $talhaoId,
            'cultura_id'            => $culturaId,
            'area_plantada_ha'      => $areaHa,
            'unidade_produtividade' => 'kg_ha',
        ], $dadosPoda));
    }
    if ($finalizadasAntes === 0) {
        vero_update(T, $safraId, ['data_inicio' => $dataPoda]);
    }
    $fim = abertura_fim_previsto($safraId);
    if ($fim !== null) vero_update(T, $safraId, ['data_fim_prevista' => $fim]);
    return $finalizadasAntes;
}

/* ── P-09: o custo da PODA conta na safra que se INICIA.
   Os apontamentos de poda foram lançados quando a nova safra ainda não existia,
   então ficaram vinculados à safra anterior (custeio na safra errada). Após
   finalizar o vínculo da poda na safra nova, re-vincula ao NOVO safra_talhao os
   apontamentos de poda desta válvula que pertencem a ESTE ciclo — data POSTERIOR
   ao dia-0 da safra a que estavam vinculados (ou sem vínculo/sem dia-0). Roda
   DENTRO da transação; devolve os ids p/ REEMITIR o custeio APÓS o commit (a
   reemissão lê o safra_talhao_id já corrigido e move o custo para a safra certa). */
function abertura_revincula_poda_apontamentos(int $safraId, int $talhaoId): array
{
    $ids = abertura_poda_tipo_ids();
    if (!$ids) return [];
    $in = implode(',', $ids); // apenas inteiros
    $t  = vero_tenant();
    $novoVinc = (int)(vero_val(
        "SELECT id FROM " . TV . " WHERE tenant_id = :t AND safra_id = :s AND talhao_id = :tl",
        [':t' => $t, ':s' => $safraId, ':tl' => $talhaoId]) ?? 0);
    if (!$novoVinc) return [];
    $apts = vero_rows(
        "SELECT ap.id
           FROM agro_apontamentos ap
           LEFT JOIN " . TV . " st ON st.id = ap.safra_talhao_id AND st.tenant_id = ap.tenant_id
          WHERE ap.tenant_id = :t AND ap.talhao_id = :tl
            AND ap.tipo_atividade_id IN ($in)
            AND (ap.safra_talhao_id IS NULL OR ap.safra_talhao_id <> :nv)
            AND (st.id IS NULL OR st.data_poda IS NULL OR DATE(ap.data_apontamento) > st.data_poda)",
        [':t' => $t, ':tl' => $talhaoId, ':nv' => $novoVinc]);
    if (!$apts) return [];
    $apIds = array_map(static fn($r) => (int)$r['id'], $apts);
    $ph = implode(',', array_fill(0, count($apIds), '?'));
    $stmt = vero_pdo()->prepare(
        "UPDATE agro_apontamentos SET safra_talhao_id = ? WHERE tenant_id = ? AND id IN ($ph)");
    $stmt->execute(array_merge([$novoVinc, $t], $apIds));
    return $apIds;
}

/* ── W-13: registra a ORIGEM "iniciada pela poda da válvula X" na safra.
   Só grava se a coluna agro_safras.origem existir (migration PROPOSTA — ver
   docstring do arquivo). Silencioso/reversível enquanto não migrado. */
function abertura_registrar_origem(int $safraId, int $talhaoId): void
{
    static $tem = null;
    if ($tem === null) {
        $tem = ((int)vero_val(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agro_safras' AND COLUMN_NAME = 'origem'") > 0);
    }
    if (!$tem) return;
    $cod = (string)(vero_val("SELECT codigo FROM agro_talhoes WHERE id = :i AND tenant_id = :t",
        [':i' => $talhaoId, ':t' => vero_tenant()]) ?? ('#' . $talhaoId));
    vero_update(T, $safraId, ['origem' => mb_substr('Iniciada pela poda da válvula ' . $cod, 0, 160)]);
}

/* ================================ POST ================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    /* ── 1) ABRIR SAFRA do semestre (AAAA.S-NN) ── */
    if ($acao === 'abrir') {
        vero_require('agro.safra.abrir');

        /* Identificação sequencial — MESMA regra do safras/index.php (T-05).
           Semestre pela data de hoje (a poda ainda não foi confirmada). */
        $ident = abertura_gerar_identificacao();
        $hoje  = date_create('today');

        /* unicidade (defensivo — o NN já garante, mas concorrência pode colidir) */
        if (vero_val("SELECT id FROM " . T . " WHERE tenant_id = :t AND identificacao = :i", [':t' => vero_tenant(), ':i' => $ident])) {
            vero_flash('erro', "A safra \"{$ident}\" já existe. Recarregue e tente novamente.");
            vero_redirect();
        }

        /* data_inicio: a coluna é NOT NULL no schema (a migration 159 não a
           relaxou e NÃO devemos tocar migrations). Gravamos PROVISÓRIA = hoje
           (referência do semestre); a 1ª poda confirmada SOBRESCREVE com o
           dia 0 real. A detecção de "1ª válvula" usa a contagem de finalizadas,
           não o NULL. Decisão registrada para o A0. */
        $novoId = vero_insert(T, [
            'identificacao'     => $ident,
            'fazenda_id'        => null,             // safra do semestre agrupa válvulas de qualquer fazenda
            'data_inicio'       => $hoje->format('Y-m-d'),
            'data_fim_prevista' => null,
            'data_fim'          => null,
            'status'            => 'ativa',
        ]);
        vero_flash('ok', "Safra \"{$ident}\" aberta. Agora confirme a poda finalizada de cada válvula — a 1ª confirmação define o dia 0 (data_inicio) da safra.");
        vero_redirect('?safra=' . $novoId);
    }

    /* ── 3) CONFIRMAR PODA FINALIZADA por válvula ── */
    if ($acao === 'confirmar_poda') {
        vero_require('agro.safra.confirmar_poda');

        $safraId  = vero_int('safra_id');
        $talhaoId = vero_int('talhao_id');
        if (!$safraId || !$talhaoId) {
            vero_flash('erro', 'Safra e válvula são obrigatórias.');
            vero_redirect();
        }

        /* safra existe, do tenant e ATIVA */
        $safra = vero_row("SELECT * FROM " . T . " WHERE id = :i AND tenant_id = :t", [':i' => $safraId, ':t' => vero_tenant()]);
        if (!$safra) { vero_flash('erro', 'Safra inválida.'); vero_redirect(); }
        if ((string)$safra['status'] !== 'ativa') {
            vero_flash('erro', 'Só é possível confirmar poda em uma safra ATIVA.');
            vero_redirect('?safra=' . $safraId);
        }

        /* válvula existe, do tenant e ativa */
        $talhao = vero_row("SELECT id, area_ha, variedade_id FROM agro_talhoes WHERE id = :i AND tenant_id = :t AND ativo = 1",
            [':i' => $talhaoId, ':t' => vero_tenant()]);
        if (!$talhao) { vero_flash('erro', 'Válvula inválida.'); vero_redirect('?safra=' . $safraId); }

        /* IDEMPOTÊNCIA — bloqueia se a válvula já está 'finalizada' em QUALQUER
           safra ATIVA (não reconfirma/duplica). Cobre a própria safra e outras. */
        $jaFinal = vero_row(
            "SELECT s.identificacao FROM " . TV . " st
               JOIN " . T . " s ON s.id = st.safra_id AND s.tenant_id = st.tenant_id
              WHERE st.tenant_id = :t AND st.talhao_id = :tl
                AND st.poda_status = 'finalizada' AND s.status = 'ativa' LIMIT 1",
            [':t' => vero_tenant(), ':tl' => $talhaoId]);
        if ($jaFinal) {
            vero_flash('erro', "Poda já finalizada para esta válvula na safra ativa \"{$jaFinal['identificacao']}\". Não é possível reconfirmar (evita duplicar safra).");
            vero_redirect('?safra=' . $safraId);
        }

        /* PRÉ-CONDIÇÕES EXPLÍCITAS (re-check server; nunca confia no cliente) */
        if (!abertura_poda_tipo_ids()) {
            vero_flash('erro', 'Não há tipo de atividade "Poda" cadastrado (trato cultural). Cadastre em Tipos de Atividade.');
            vero_redirect('?safra=' . $safraId);
        }
        $pc = abertura_precond($talhaoId);
        if ((int)$pc['os_abertas'] > 0) {
            vero_flash('erro', 'Há OS de poda aberta/em execução nesta válvula — conclua todas antes de confirmar.');
            vero_redirect('?safra=' . $safraId);
        }
        if ((int)$pc['os_concluidas'] < 1) {
            vero_flash('erro', 'Nenhuma OS de poda concluída nesta válvula.');
            vero_redirect('?safra=' . $safraId);
        }
        if ((int)$pc['apont_poda'] < 1 || $pc['ult_apont'] === null) {
            vero_flash('erro', 'Nenhum apontamento de poda registrado nesta válvula (execução obrigatória).');
            vero_redirect('?safra=' . $safraId);
        }

        /* dia 0 = data do ÚLTIMO apontamento de poda (execução real) */
        $dataPoda = (string)$pc['ult_apont']; // já é DATE (Y-m-d)

        /* cultura do vínculo: existente nesta safra, senão da variedade da válvula */
        $culturaId = (int)(vero_val(
            "SELECT cultura_id FROM " . TV . " WHERE tenant_id = :t AND safra_id = :s AND talhao_id = :tl",
            [':t' => vero_tenant(), ':s' => $safraId, ':tl' => $talhaoId]) ?? 0);
        if (!$culturaId) {
            $culturaId = (int)(vero_val(
                "SELECT vr.cultura_id FROM agro_talhoes tl
                   JOIN agro_variedades vr ON vr.id = tl.variedade_id AND vr.tenant_id = tl.tenant_id
                  WHERE tl.id = :tl AND tl.tenant_id = :t",
                [':tl' => $talhaoId, ':t' => vero_tenant()]) ?? 0);
        }
        if (!$culturaId) {
            vero_flash('erro', 'Válvula sem variedade/cultura definida — defina a variedade da válvula antes de confirmar a poda (a cultura é obrigatória no vínculo da safra).');
            vero_redirect('?safra=' . $safraId);
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        $revincPoda = [];
        try {
            /* upsert do vínculo + dia 0 na 1ª poda + fim previsto (helper compartilhado) */
            $finalizadasAntes = abertura_grava_vinculo_poda(
                $safraId, $talhaoId, $culturaId, (float)$talhao['area_ha'], $dataPoda);
            /* P-09: re-vincula os apontamentos de poda deste ciclo à safra que inicia */
            $revincPoda = abertura_revincula_poda_apontamentos($safraId, $talhaoId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao confirmar a poda: ' . h($e->getMessage()));
            vero_redirect('?safra=' . $safraId);
        }
        /* reemite o custeio dos apontamentos re-vinculados (fora da transação) */
        foreach ($revincPoda as $apId) vero_srv_apontamento_reemitir_custeio((int)$apId);

        vero_flash('ok', 'Poda confirmada — dia 0 = ' . dateBR($dataPoda)
            . ($finalizadasAntes === 0 ? ' (definiu o início da safra).' : '.')
            . ($revincPoda ? ' Custo da poda (' . count($revincPoda) . ' apont.) atribuído a esta safra.' : '')
            . ' Fenologia da válvula liberada.');
        vero_redirect('?safra=' . $safraId);
    }

    /* ── W-13) INICIAR NOVA SAFRA PELA PODA (atalho do modal de apontamentos) ──
       Abre a safra do semestre E confirma a poda da válvula numa única transação:
       dia 0 = data da última planta podada (recalculada no servidor). O corte de
       ≥95% já foi validado no apontamento; aqui revalidamos as pré-condições e a
       idempotência (não duplica safra de válvula já finalizada em safra ativa).
       Retorno ao apontamentos.php em erro (origem do atalho). */
    if ($acao === 'nova_safra_poda') {
        vero_require('agro.safra.abrir');
        vero_require('agro.safra.confirmar_poda');
        $apontUrl = BIOS_BASE . '/agro/apontamentos';

        $talhaoId = vero_int('talhao_id');
        if (!$talhaoId) { vero_flash('erro', 'Válvula inválida.'); vero_redirect($apontUrl); }

        $talhao = vero_row("SELECT id, area_ha, codigo FROM agro_talhoes WHERE id = :i AND tenant_id = :t AND ativo = 1",
            [':i' => $talhaoId, ':t' => vero_tenant()]);
        if (!$talhao) { vero_flash('erro', 'Válvula inválida.'); vero_redirect($apontUrl); }

        /* pré-condições de poda (re-check server) — mesmas do fluxo manual */
        if (!abertura_poda_tipo_ids()) {
            vero_flash('erro', 'Não há tipo de atividade "Poda" cadastrado.'); vero_redirect($apontUrl);
        }
        $pc = abertura_precond($talhaoId);
        if ((int)$pc['os_abertas'] > 0) {
            vero_flash('erro', 'Há OS de poda aberta/em execução nesta válvula — conclua antes de iniciar a safra.'); vero_redirect($apontUrl);
        }
        if ((int)$pc['os_concluidas'] < 1) {
            vero_flash('erro', 'Nenhuma OS de poda concluída nesta válvula.'); vero_redirect($apontUrl);
        }
        if ((int)$pc['apont_poda'] < 1 || $pc['ult_apont'] === null) {
            vero_flash('erro', 'Nenhum apontamento de poda registrado nesta válvula.'); vero_redirect($apontUrl);
        }

        /* idempotência: válvula já finalizada em QUALQUER safra ativa → não duplica */
        $jaFinal = vero_row(
            "SELECT s.identificacao FROM " . TV . " st
               JOIN " . T . " s ON s.id = st.safra_id AND s.tenant_id = st.tenant_id
              WHERE st.tenant_id = :t AND st.talhao_id = :tl
                AND st.poda_status = 'finalizada' AND s.status = 'ativa' LIMIT 1",
            [':t' => vero_tenant(), ':tl' => $talhaoId]);
        if ($jaFinal) {
            vero_flash('erro', "Poda já finalizada para esta válvula na safra ativa \"{$jaFinal['identificacao']}\". Não é possível iniciar outra (evita duplicar safra).");
            vero_redirect($apontUrl);
        }

        /* dia 0 = ÚLTIMO apontamento de poda (autoridade do servidor; o dia_zero
           do POST é só exibição no modal, não confiado). */
        $dataPoda = (string)$pc['ult_apont'];

        /* cultura pela variedade da válvula (safra nova, sem vínculo prévio) */
        $culturaId = (int)(vero_val(
            "SELECT vr.cultura_id FROM agro_talhoes tl
               JOIN agro_variedades vr ON vr.id = tl.variedade_id AND vr.tenant_id = tl.tenant_id
              WHERE tl.id = :tl AND tl.tenant_id = :t",
            [':tl' => $talhaoId, ':t' => vero_tenant()]) ?? 0);
        if (!$culturaId) {
            vero_flash('erro', 'Válvula sem variedade/cultura definida — defina a variedade antes de iniciar a safra.');
            vero_redirect($apontUrl);
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        $revincPoda = [];
        try {
            $ident = abertura_gerar_identificacao();
            if (vero_val("SELECT id FROM " . T . " WHERE tenant_id = :t AND identificacao = :i",
                    [':t' => vero_tenant(), ':i' => $ident])) {
                throw new RuntimeException("A safra \"{$ident}\" já existe. Tente novamente.");
            }
            $safraId = vero_insert(T, [
                'identificacao'     => $ident,
                'fazenda_id'        => null,
                'data_inicio'       => $dataPoda,   // dia 0 já conhecido (poda)
                'data_fim_prevista' => null,
                'data_fim'          => null,
                'status'            => 'ativa',
            ]);
            abertura_grava_vinculo_poda($safraId, $talhaoId, $culturaId, (float)$talhao['area_ha'], $dataPoda);
            abertura_registrar_origem($safraId, $talhaoId);
            /* P-09: o custo da poda conta na safra que acabou de iniciar */
            $revincPoda = abertura_revincula_poda_apontamentos($safraId, $talhaoId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao iniciar a safra pela poda: ' . h($e->getMessage()));
            vero_redirect($apontUrl);
        }
        foreach ($revincPoda as $apId) vero_srv_apontamento_reemitir_custeio((int)$apId);

        vero_flash('ok', "Nova safra \"{$ident}\" iniciada pela poda da válvula {$talhao['codigo']} — dia 0 = "
            . dateBR($dataPoda) . '.'
            . ($revincPoda ? ' Custo da poda (' . count($revincPoda) . ' apont.) atribuído a esta safra.' : '')
            . ' A válvula já entra com a poda confirmada.');
        vero_redirect(BIOS_BASE . '/safras/index');
    }
}

/* ================================ VIEW ================================ */

/* Safras ATIVAS (as que estão em abertura/confirmação de poda) */
$safrasAtivas = vero_rows(
    "SELECT s.*,
            (SELECT COUNT(*) FROM " . TV . " v WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id) AS valvulas,
            (SELECT COUNT(*) FROM " . TV . " v WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id AND v.poda_status = 'finalizada') AS podas_ok
       FROM " . T . " s
      WHERE s.tenant_id = :t AND s.status = 'ativa'
      ORDER BY s.identificacao DESC", [':t' => vero_tenant()]);

/* Safra em foco (?safra=ID) e suas válvulas candidatas */
$panel = null;
$candidatas = [];
$semPodaTipo = !abertura_poda_tipo_ids();
if (!empty($_GET['safra'])) {
    $panel = vero_row("SELECT * FROM " . T . " WHERE id = :i AND tenant_id = :t",
        [':i' => (int)$_GET['safra'], ':t' => vero_tenant()]);
    if ($panel && !$semPodaTipo) {
        $ids = abertura_poda_tipo_ids();
        $in  = implode(',', $ids); // inteiros
        $sid = (int)$panel['id'];
        /* Casa OS de poda por atividade planejada OU por apontamento-espelho
           (atividade_id NULL) — MESMA regra de abertura_precond, senão o painel
           mostraria "OS poda concluídas: 0" para uma poda feita só por apontamento
           mesmo já confirmável/confirmada. */
        $podaOScand = "(EXISTS (SELECT 1 FROM agro_atividades atv
                                 WHERE atv.id = os.atividade_id AND atv.tenant_id = os.tenant_id
                                   AND atv.tipo_atividade_id IN ($in))
                        OR EXISTS (SELECT 1 FROM agro_apontamentos ap
                                    WHERE ap.ordem_servico_id = os.id AND ap.tenant_id = os.tenant_id
                                      AND ap.tipo_atividade_id IN ($in)))";
        /* Candidatas = válvulas com QUALQUER envolvimento de poda (OS ou
           apontamento) OU já vinculadas a esta safra. Métricas por subquery
           correlata (tl.tenant_id) → HY093-safe: :t/:s/:s2/:s3 distintos. */
        $candidatas = vero_rows(
            "SELECT tl.id, tl.codigo, tl.area_ha, f.nome AS fazenda,
                    (SELECT COUNT(*) FROM agro_ordens_servico os
                      WHERE os.tenant_id = tl.tenant_id AND os.talhao_id = tl.id
                        AND os.status IN ('aberta','em_execucao') AND $podaOScand) AS os_abertas,
                    (SELECT COUNT(*) FROM agro_ordens_servico os
                      WHERE os.tenant_id = tl.tenant_id AND os.talhao_id = tl.id
                        AND os.status = 'concluida' AND $podaOScand) AS os_concluidas,
                    (SELECT COUNT(*) FROM agro_apontamentos ap
                      WHERE ap.tenant_id = tl.tenant_id AND ap.talhao_id = tl.id
                        AND ap.tipo_atividade_id IN ($in)) AS apont_poda,
                    (SELECT MAX(DATE(ap.data_apontamento)) FROM agro_apontamentos ap
                      WHERE ap.tenant_id = tl.tenant_id AND ap.talhao_id = tl.id
                        AND ap.tipo_atividade_id IN ($in)) AS ult_apont,
                    (SELECT st.poda_status FROM " . TV . " st
                      WHERE st.tenant_id = tl.tenant_id AND st.talhao_id = tl.id AND st.safra_id = :s) AS vinc_status,
                    (SELECT st.data_poda FROM " . TV . " st
                      WHERE st.tenant_id = tl.tenant_id AND st.talhao_id = tl.id AND st.safra_id = :s2) AS vinc_data_poda,
                    (SELECT COUNT(*) FROM " . TV . " st
                       JOIN " . T . " s2 ON s2.id = st.safra_id AND s2.tenant_id = st.tenant_id
                      WHERE st.tenant_id = tl.tenant_id AND st.talhao_id = tl.id
                        AND st.poda_status = 'finalizada' AND s2.status = 'ativa' AND st.safra_id <> :s3) AS final_outra
               FROM agro_talhoes tl
               JOIN agro_fazendas f ON f.id = tl.fazenda_id AND f.tenant_id = tl.tenant_id
              WHERE tl.tenant_id = :t AND tl.ativo = 1
             HAVING os_abertas > 0 OR os_concluidas > 0 OR apont_poda > 0 OR vinc_status IS NOT NULL
              ORDER BY f.nome, tl.codigo",
            [':s' => $sid, ':s2' => $sid, ':s3' => $sid, ':t' => vero_tenant()]);
    }
}

$statusBadge = static fn(string $s): string => match ($s) {
    'ativa'     => '<span class="vbadge vb-ok">Ativa</span>',
    'encerrada' => '<span class="vbadge vb-off">Encerrada</span>',
    default     => '<span class="vbadge vb-warn">Planejada</span>',
};

$GUARD      = ['macro' => 'agricola', 'micro' => 'abertura_safra'];
$PAGE_VIEW  = 'agricola_abertura_safra';
$PAGE_TITLE = 'Abertura de Safra';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeAbrir    = vero_can('agro.safra.abrir');
$podeConfirmar = vero_can('agro.safra.confirmar_poda');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Abertura de Safra', 'Abra a safra do semestre e confirme a poda finalizada por válvula (dia 0 = último apontamento de poda)', null) ?>

  <?php if ($semPodaTipo): ?>
    <div class="vflash vflash-aviso">
      Nenhum tipo de atividade <strong>"Poda"</strong> (trato cultural) cadastrado. As pré-condições de poda não podem ser
      avaliadas — cadastre em <a href="<?= BIOS_BASE ?>/agro/tipos_atividade">Tipos de Atividade</a>.
    </div>
  <?php endif; ?>

  <!-- Abrir safra -->
  <div class="vcard" style="margin-bottom:20px">
    <div class="vtoolbar" style="justify-content:space-between">
      <strong>Safras ativas (em abertura/confirmação de poda)</strong>
      <?php if ($podeAbrir): ?>
      <form method="post" data-confirm="Abrir uma nova safra do semestre? A identificação AAAA.S-NN é gerada automaticamente." data-confirm-ok="Abrir safra" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="abrir">
        <button class="vbtn vbtn-primary vbtn-sm" type="submit">+ Abrir safra do semestre</button>
      </form>
      <?php endif; ?>
    </div>

    <?php if (!$safrasAtivas): ?>
      <div class="vempty">Nenhuma safra ativa. <?= $podeAbrir ? 'Abra a safra do semestre para iniciar a confirmação de poda.' : '' ?></div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Identificação</th><th>Início (dia 0)</th><th>Fim previsto</th>
        <th style="text-align:right">Válvulas</th><th style="text-align:right">Podas confirmadas</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($safrasAtivas as $s): ?>
        <tr>
          <td><strong class="vnum"><?= h($s['identificacao']) ?></strong></td>
          <td class="vnum"><?= $s['data_inicio'] ? dateBR($s['data_inicio']) : '—' ?></td>
          <td class="vnum"><?= $s['data_fim_prevista'] ? dateBR($s['data_fim_prevista']) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$s['valvulas'] ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$s['podas_ok'] ?></td>
          <td><?= $statusBadge((string)$s['status']) ?></td>
          <td><div class="vactions">
            <?= vero_btn_icone(vero_ico_olho(), 'Válvulas / confirmar poda', '', '?safra=' . (int)$s['id']) ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($panel): ?>
  <!-- Válvulas candidatas da safra em foco -->
  <div class="vcard">
    <div class="vtoolbar" style="justify-content:space-between">
      <strong>Válvulas candidatas — safra <?= h($panel['identificacao']) ?> <?= $statusBadge((string)$panel['status']) ?></strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h(strtok((string)$_SERVER['REQUEST_URI'], '?')) ?>">Fechar</a>
    </div>

    <div class="vhint" style="padding:9px 16px;border-bottom:1px solid #EEE8DB">
      Pré-condições por válvula: <strong>todas as OS de poda concluídas</strong> (nenhuma aberta/em execução)
      + <strong>≥1 apontamento de poda</strong>. O botão só habilita quando batem. A confirmação grava
      <em>data_poda = último apontamento de poda</em> (dia 0) e libera a fenologia da válvula.
    </div>

    <?php if ((string)$panel['status'] !== 'ativa'): ?>
      <div class="vflash vflash-aviso" style="margin:14px 16px">Esta safra não está ativa — confirmação de poda indisponível.</div>
    <?php endif; ?>

    <?php if (!$candidatas): ?>
      <div class="vempty">Nenhuma válvula com processo de poda (OS ou apontamento) encontrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Fazenda / Válvula</th>
        <th style="text-align:right">OS poda abertas</th>
        <th style="text-align:right">OS poda concluídas</th>
        <th style="text-align:right">Apont. poda</th>
        <th>Último apont. (dia 0)</th>
        <th>Situação</th>
        <th style="text-align:right">Ação</th>
      </tr></thead>
      <tbody>
      <?php foreach ($candidatas as $c):
          $osAb = (int)$c['os_abertas']; $osOk = (int)$c['os_concluidas'];
          $apo  = (int)$c['apont_poda']; $ult  = $c['ult_apont'];
          $jaFinal    = (string)($c['vinc_status'] ?? '') === 'finalizada';
          $finalOutra = (int)$c['final_outra'] > 0;
          $pronta = !$jaFinal && !$finalOutra && $osAb === 0 && $osOk >= 1 && $apo >= 1 && $ult !== null;

          if ($jaFinal) {
              $sit = '<span class="vbadge vb-ok">Poda finalizada</span>'
                   . ($c['vinc_data_poda'] ? '<div class="vhint">dia 0: ' . dateBR($c['vinc_data_poda']) . '</div>' : '');
          } elseif ($finalOutra) {
              $sit = '<span class="vbadge vb-off">Bloqueada</span><div class="vhint">poda já finalizada em outra safra ativa</div>';
          } elseif ($pronta) {
              $sit = '<span class="vbadge vb-info">Pronta p/ confirmar</span>';
          } else {
              $faltas = [];
              if ($osAb > 0)  $faltas[] = "conclua {$osAb} OS de poda aberta(s)/em execução";
              if ($osOk < 1)  $faltas[] = 'nenhuma OS de poda concluída';
              if ($apo < 1)   $faltas[] = 'sem apontamento de poda';
              $sit = '<span class="vbadge vb-warn">Pendente</span><div class="vhint">' . h(implode(' · ', $faltas)) . '</div>';
          }
      ?>
        <tr>
          <td><strong><?= h($c['fazenda']) ?> — <?= h($c['codigo']) ?></strong></td>
          <td class="vnum" style="text-align:right<?= $osAb > 0 ? ';color:#B57C1A;font-weight:600' : '' ?>"><?= $osAb ?></td>
          <td class="vnum" style="text-align:right"><?= $osOk ?></td>
          <td class="vnum" style="text-align:right"><?= $apo ?></td>
          <td class="vnum"><?= $ult ? dateBR($ult) : '—' ?></td>
          <td><?= $sit ?></td>
          <td><div class="vactions">
            <?php if ($podeConfirmar && (string)$panel['status'] === 'ativa' && $pronta): ?>
              <form method="post" data-confirm="Confirmar poda finalizada desta válvula? O dia 0 será <?= h(dateBR($ult)) ?> (último apontamento de poda). Ação auditável e não reversível por aqui." data-confirm-ok="Confirmar poda" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="confirmar_poda">
                <input type="hidden" name="safra_id" value="<?= (int)$panel['id'] ?>">
                <input type="hidden" name="talhao_id" value="<?= (int)$c['id'] ?>">
                <button class="vbtn vbtn-primary vbtn-sm" type="submit">Confirmar poda</button>
              </form>
            <?php elseif ($jaFinal): ?>
              <span class="vhint">✓ confirmada</span>
            <?php else: ?>
              <button class="vbtn vbtn-ghost vbtn-sm" type="button" disabled title="Pré-condições não atendidas">Confirmar poda</button>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
