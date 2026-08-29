<?php
/* ============================================================
   VERO — MIP / Monitoramento  (tela real)
   Substitui o mock. Rota da matriz: /mip/monitoramento.php
   Guard: mip.monitoramento | Escrita: mip.monitoramentos.editar/excluir
   Tabela: mip_monitoramentos (por válvula × alvo × data, índice %).
   Nota: o schema da mig. 120 registra por VÁLVULA (não por válvula) —
   granularidade por válvula fica para a fase 2 se o cliente pedir.
   Índice ≥ nível de ação do alvo → alerta em agro_alertas (categoria
   mip, validação técnica obrigatória; ≥ 2× nível = crítico).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/../agro/_setor_espelho.php'; /* A1-36: rótulo P-57 (válvula = válvula) */

const T = 'mip_monitoramentos';
const FOTO_EXT = ['jpg', 'jpeg', 'png'];
const SEVERIDADES = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'];
/* 8.x: local onde o alvo foi encontrado (categórico, whitelist em PHP — mig. 163)
   C-44 (Wallace 19/07): + ponteiros | casca | mato */
const LOCAIS = ['folha' => 'Folha', 'ramo' => 'Ramo', 'cacho' => 'Cacho',
                'ponteiros' => 'Ponteiros', 'casca' => 'Casca', 'mato' => 'Mato'];

/** Reemite os alertas do monitoramento — UM POR ALVO que atingiu o nível de
    ação (8.x: multi-alvo). Cada alvo vira/atualiza um alerta próprio em
    agro_alertas. Como agro_alertas não tem coluna de alvo, a identidade do
    alerta por alvo é o TÍTULO ("<alvo> atingiu o nível de ação") dentro do
    mesmo (origem_tipo, origem_id=monitoramento). As ações do RT
    (mip_alerta_acoes, FK RESTRICT) são REAPONTADAS por título para o alerta
    reemitido do mesmo alvo — a trilha de decisão não se perde na reedição; se
    o índice do alvo cair abaixo do nível (ou o alvo sair), o alerta e sua
    trilha somem. Retorna a quantidade de alertas ativos após a reemissão.
    DEVE rodar dentro da transação do save (cabeçalho + junção já gravados). */
function mip_reemitir_alertas(int $monId): int
{
    $t = vero_tenant();
    $pdo = vero_pdo();

    /* alertas atuais deste monitoramento, indexados pelo título (= por alvo) */
    $existentes = [];
    foreach (vero_rows(
        "SELECT id, titulo FROM agro_alertas
          WHERE tenant_id=:t AND categoria='mip'
            AND origem_tipo='mip_monitoramento' AND origem_id=:o",
        [':t' => $t, ':o' => $monId]) as $ax) {
        $existentes[(string)$ax['titulo']] = (int)$ax['id'];
    }
    $apagarAlerta = static function (int $alertaId) use ($pdo, $t): void {
        $pdo->prepare("DELETE FROM mip_alerta_acoes WHERE tenant_id=:t AND alerta_id=:a")
            ->execute([':t' => $t, ':a' => $alertaId]);
        $pdo->prepare("DELETE FROM agro_alertas WHERE tenant_id=:t2 AND id=:a")
            ->execute([':t2' => $t, ':a' => $alertaId]);
    };

    $m = vero_row(
        "SELECT m.*, tt.fazenda_id
           FROM " . T . " m
           JOIN agro_talhoes tt ON tt.id = m.talhao_id
          WHERE m.id = :i AND m.tenant_id = :t", [':i' => $monId, ':t' => $t]);

    /* A1-47: RASCUNHO não alerta / cabeçalho sumiu → remove todos os alertas */
    if (!$m || (string)($m['status'] ?? 'enviado') === 'rascunho') {
        foreach ($existentes as $aid) $apagarAlerta($aid);
        return 0;
    }

    $st = vero_row("SELECT safra_id FROM agro_safra_talhoes WHERE id = :i AND tenant_id = :t",
        [':i' => (int)($m['safra_talhao_id'] ?? 0), ':t' => $t]);
    $safraId = $st ? (int)$st['safra_id'] : null;

    /* alvos do monitoramento (junção) + nível de ação de cada alvo */
    $alvos = vero_rows(
        "SELECT ma.nivel_infestacao, a.nome AS alvo_nome, a.nivel_acao
           FROM mip_monitoramento_alvos ma
           JOIN mip_alvos a ON a.id = ma.alvo_id
          WHERE ma.tenant_id = :t AND ma.monitoramento_id = :o
          ORDER BY ma.id", [':t' => $t, ':o' => $monId]);

    $usados = [];   /* títulos de alertas antigos reaproveitados */
    $ativos = 0;
    foreach ($alvos as $av) {
        if ($av['nivel_acao'] === null) continue;
        $nivel = (float)$av['nivel_infestacao'];
        $acao  = (float)$av['nivel_acao'];
        if ($nivel < $acao) continue;

        $titulo = $av['alvo_nome'] . ' atingiu o nível de ação';
        $antigo = $existentes[$titulo] ?? null;

        $novoAlertaId = vero_insert('agro_alertas', [
            'categoria'    => 'mip',
            'origem_tipo'  => 'mip_monitoramento',
            'origem_id'    => $monId,
            'fazenda_id'   => (int)$m['fazenda_id'],
            'talhao_id'    => (int)$m['talhao_id'],
            'safra_id'     => $safraId,
            'severidade'   => $nivel >= 2 * $acao ? 'critico' : 'atencao',
            'titulo'       => $titulo,
            'mensagem'     => 'Índice ' . numFmt($nivel, 2) . ($m['unidade'] ? ' ' . $m['unidade'] : '%')
                              . ' (nível de ação: ' . numFmt($acao, 2) . '%). Manejo pendente de validação do responsável técnico.',
            'requer_validacao_tecnica' => 1,
            'status'       => 'aberto',
            'data'         => (string)$m['data_monitoramento'],
        ]);
        if ($antigo) {
            /* reaponta a trilha do RT do MESMO alvo (por título) e remove o antigo */
            $pdo->prepare("UPDATE mip_alerta_acoes SET alerta_id=:n WHERE tenant_id=:t AND alerta_id=:a")
                ->execute([':n' => $novoAlertaId, ':t' => $t, ':a' => (int)$antigo]);
            $pdo->prepare("DELETE FROM agro_alertas WHERE tenant_id=:t AND id=:a")
                ->execute([':t' => $t, ':a' => (int)$antigo]);
            $usados[$titulo] = true;
        }
        $ativos++;
    }
    /* alertas antigos de alvos que caíram abaixo do nível (ou saíram) → removem */
    foreach ($existentes as $tit => $aid) {
        if (!isset($usados[$tit])) $apagarAlerta($aid);
    }
    return $ativos;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('mip.monitoramentos.editar');

        $id       = vero_int('id');
        $data     = vero_date('data_monitoramento');
        $talhaoId = vero_int('talhao_id');

        /* 8.x: MULTI-ALVO — cada alvo tem suas próprias métricas (nível, quantidade,
           local, severidade), enviadas como arrays paralelos. Parser decimal local
           espelha vero_dec() (que só lê $_POST escalar). */
        $parseDec = static function ($v): ?float {
            $v = trim((string)$v);
            if ($v === '') return null;
            $v = str_replace(' ', '', $v);
            if (str_contains($v, ',')) {
                $v = str_replace(['.', ','], ['', '.'], $v);
            } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
                $v = str_replace('.', '', $v);
            }
            return is_numeric($v) ? (float)$v : null;
        };
        $alvoIds = $_POST['alvo_id'] ?? [];
        if (!is_array($alvoIds)) $alvoIds = [];
        $nivRaw = (array)($_POST['nivel_infestacao'] ?? []);
        $qtdRaw = (array)($_POST['quantidade_encontrada'] ?? []);
        $locRaw = (array)($_POST['local_infestacao'] ?? []);
        $sevRaw = (array)($_POST['severidade_qualitativa'] ?? []);

        /* C-28: consolidação por ÁREA — nº de plantas amostradas
           (flexível, sem N fixo). Com ele, quantidade bruta vira ÍNDICE pela
           regra de 3 (encontradas ÷ amostradas × 100); sem ele, mantém o
           comportamento A1-47 (qtd = índice). Índice digitado sempre vence. */
        $amostradas = vero_int('plantas_amostradas') ?: null;
        /* X-02: metodologia parametrizável. Espaço amostral =
           unidades_por_planta × plantas_amostradas (ex.: 15 folhas × 20 plantas
           = 300). Índice = encontradas ÷ espaço amostral × 100. */
        $metodologia = vero_str('metodologia', 16);
        if (!in_array($metodologia, ['planta', 'folha', 'caixa', 'severidade'], true)) $metodologia = 'planta';
        $unidPlanta = vero_int('unidades_por_planta') ?: null;
        $espacoAmostral = ($amostradas !== null && $amostradas > 0)
            ? $amostradas * ($unidPlanta !== null && $unidPlanta > 0 ? $unidPlanta : 1)
            : null;

        $alvos = [];       /* linhas válidas do repeater */
        $vistos = [];      /* dedupe por alvo+LOCAL (C-27: mesmo alvo em locais ≠ é permitido; UNIQUE uq_mon_alvo_local, mig 173) */
        $erroAlvo = null;
        foreach ($alvoIds as $ix => $aidRaw) {
            $aid = (int)$aidRaw;
            if ($aid <= 0) continue;               /* linha em branco → ignora */
            /* A1-47 (DB-54): quantidade é o dado bruto; vira índice se o índice
               não for digitado (mesma unidade do nível de ação).
               C-28: com plantas amostradas, o índice consolidado é a regra de 3. */
            $qtd   = $parseDec($qtdRaw[$ix] ?? '');
            $nivel = $parseDec($nivRaw[$ix] ?? '');
            if ($nivel === null && $qtd !== null) {
                $nivel = ($espacoAmostral !== null && $espacoAmostral > 0)
                    ? round($qtd / $espacoAmostral * 100, 2)
                    : $qtd;
            }
            if ($nivel === null || $nivel < 0) { $erroAlvo = 'Informe a quantidade ou o índice de cada alvo.'; break; }
            $loc = (string)($locRaw[$ix] ?? '');
            if (!isset(LOCAIS[$loc])) $loc = null;              /* whitelist */
            $sev = (string)($sevRaw[$ix] ?? '');
            if (!isset(SEVERIDADES[$sev])) $sev = null;         /* whitelist */
            $chave = $aid . '|' . ($loc ?? '');
            if (isset($vistos[$chave])) { $erroAlvo = 'Alvo repetido no mesmo local — para repetir o alvo, escolha um local diferente.'; break; }
            $vistos[$chave] = true;
            $alvos[] = ['alvo_id' => $aid, 'nivel' => $nivel, 'qtd' => $qtd, 'local' => $loc, 'sev' => $sev];
        }

        if ($data === null || !$talhaoId || !$alvos || $erroAlvo !== null) {
            vero_flash('erro', $erroAlvo ?? 'Data, válvula e pelo menos um alvo com quantidade/índice são obrigatórios.');
            vero_redirect();
        }
        $okTalhao = vero_val("SELECT id FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
            [':i' => $talhaoId, ':t' => vero_tenant()]);
        if (!$okTalhao) {
            vero_flash('erro', 'Válvula inválida.');
            vero_redirect();
        }
        /* valida cada alvo (tenant + ativo) e captura o nível de ação p/ o redirect */
        foreach ($alvos as &$a) {
            $av = vero_row("SELECT id, nivel_acao FROM mip_alvos WHERE id=:i AND tenant_id=:t AND ativo=1",
                [':i' => $a['alvo_id'], ':t' => vero_tenant()]);
            if (!$av) { $erroAlvo = 'Alvo inválido ou inativo.'; break; }
            $a['nivel_acao'] = $av['nivel_acao'] !== null ? (float)$av['nivel_acao'] : null;
        }
        unset($a);
        if ($erroAlvo !== null) { vero_flash('erro', $erroAlvo); vero_redirect(); }

        $stId = vero_int('safra_talhao_id');
        if ($stId) {
            $okSt = vero_val("SELECT id FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t AND talhao_id=:ta",
                [':i' => $stId, ':t' => vero_tenant(), ':ta' => $talhaoId]);
            if (!$okSt) $stId = null;
        }
        /* A1-20: ponto de amostragem do PRÓPRIO válvula (coluna ponto_id existia sem tela) */
        $pontoId = vero_int('ponto_id');
        if ($pontoId) {
            $okPt = vero_val("SELECT id FROM mip_pontos_amostragem WHERE id=:i AND tenant_id=:t AND talhao_id=:ta",
                [':i' => $pontoId, ':t' => vero_tenant(), ':ta' => $talhaoId]);
            if (!$okPt) $pontoId = null;
        }
        /* A1-47: fluxo enviar-para-o-líder — rascunho NÃO emite alerta (coleta
           em andamento); "Salvar e ENVIAR" ou a ação enviar mudam o status */
        $enviar = (string)($_POST['enviar'] ?? '') === '1';
        $statusAtual = $id ? (string)(vero_val("SELECT status FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) ?? 'enviado') : null;

        /* COMPAT: cabeçalho mip_monitoramentos mantém as colunas do 1º alvo
           (alvo_id / nível / quantidade / local / severidade) — vários leitores
           (alertas legados, apontamento ULT_MON, auto-safra das aplicações,
           api/v1 sync) leem essas colunas diretamente. */
        $primeiro = $alvos[0];
        $dados = [
            'talhao_id'          => $talhaoId,
            'safra_talhao_id'    => $stId,
            'ponto_id'           => $pontoId,
            'alvo_id'            => $primeiro['alvo_id'],
            'data_monitoramento' => $data,
            'quantidade_encontrada' => $primeiro['qtd'],
            'nivel_infestacao'   => $primeiro['nivel'],
            'local_infestacao'   => $primeiro['local'],
            'severidade_qualitativa' => $primeiro['sev'],
            'unidade'            => vero_str('unidade', 30) ?? '%',
            'plantas_amostradas' => $amostradas,   /* C-28 (mig 175) */
            'metodologia'        => $metodologia,       /* X-02 (mig 184) */
            'unidades_por_planta' => $unidPlanta,
            'observacao'         => vero_str('observacao', 255),
            'status'             => $enviar ? 'enviado' : ($statusAtual ?? 'rascunho'),
        ];

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $ok = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t",
                    [':i' => $id, ':t' => vero_tenant()]);
                if (!$ok) throw new RuntimeException('Monitoramento inválido.');
                vero_update(T, $id, $dados);
                $monId = $id;
            } else {
                $dados['monitor_id'] = vero_uid(); /* A1-47: quem coletou */
                $monId = vero_insert(T, $dados);
            }
            /* junção: substitui os alvos (delete + reinsert, tenant-safe) */
            $pdo->prepare("DELETE FROM mip_monitoramento_alvos WHERE tenant_id=:t AND monitoramento_id=:o")
                ->execute([':t' => vero_tenant(), ':o' => $monId]);
            foreach ($alvos as $a) {
                vero_insert('mip_monitoramento_alvos', [
                    'monitoramento_id'       => $monId,
                    'alvo_id'                => $a['alvo_id'],
                    'nivel_infestacao'       => $a['nivel'],
                    'quantidade_encontrada'  => $a['qtd'],
                    'local_infestacao'       => $a['local'],
                    'severidade_qualitativa' => $a['sev'],
                ]);
            }
            $nAlertas = mip_reemitir_alertas($monId);
            $alertou  = $nAlertas > 0;
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao salvar: ' . h($e->getMessage()));
            vero_redirect();
        }

        /* A1-20: fotos do monitoramento → agro_anexos (origem_tipo='mip_monitoramento') */
        $fotos = $_FILES['fotos'] ?? null;
        $fotosOk = 0; $fotosErro = 0;
        if ($fotos && is_array($fotos['name'] ?? null)) {
            $maxBytes = (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 5242880);
            $dir = dirname(__DIR__) . '/storage/uploads/mip/' . vero_tenant();
            foreach ($fotos['name'] as $ix => $nomeArq) {
                if (($fotos['error'][$ix] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo((string)$nomeArq, PATHINFO_EXTENSION));
                if (!in_array($ext, FOTO_EXT, true) || (int)$fotos['size'][$ix] > $maxBytes) { $fotosErro++; continue; }
                if (!vero_upload_conteudo_ok((string)$fotos['tmp_name'][$ix], $ext)) { $fotosErro++; continue; }
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $nomeFisico = 'mon' . $monId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (!move_uploaded_file((string)$fotos['tmp_name'][$ix], $dir . '/' . $nomeFisico)) { $fotosErro++; continue; }
                vero_insert('agro_anexos', [
                    'origem_tipo'   => 'mip_monitoramento',
                    'origem_id'     => (int)$monId,
                    'tipo_arquivo'  => $ext,
                    'nome_original' => mb_substr((string)$nomeArq, 0, 255),
                    'url'           => '/storage/uploads/mip/' . vero_tenant() . '/' . $nomeFisico,
                    'tamanho_bytes' => (int)$fotos['size'][$ix],
                    'hash_sha256'   => hash_file('sha256', $dir . '/' . $nomeFisico),
                ]);
                $fotosOk++;
            }
        }
        if ($fotosErro > 0) vero_flash('aviso', $fotosErro . ' foto(s) recusada(s) — só JPG/PNG até o limite de tamanho.');

        $ehRascunho = !$enviar && ($statusAtual === null || $statusAtual === 'rascunho');
        vero_flash('ok', 'Monitoramento ' . ($ehRascunho ? 'salvo como RASCUNHO (não gera alerta até enviar)' : 'registrado e ENVIADO')
            . ($fotosOk ? " com {$fotosOk} foto(s)" : '') . '.'
            . ($alertou ? ' Nível de ação atingido — ' . $nAlertas . ' alerta(s) gerado(s) por alvo (validação do RT pendente).' : ''));
        /* A1-47/48e: acima do nível → a tela oferece iniciar a pulverização
           contextualizada (banner via query — flash escapa HTML). Multi-alvo:
           usa o 1º alvo que atingiu o nível de ação. */
        if ($alertou) {
            $alvoAlerta = 0;
            foreach ($alvos as $a) {
                if ($a['nivel_acao'] !== null && $a['nivel'] >= $a['nivel_acao']) { $alvoAlerta = $a['alvo_id']; break; }
            }
            $safraLink = $stId ? (int)(vero_val("SELECT safra_id FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t",
                [':i' => $stId, ':t' => vero_tenant()]) ?? 0) : 0;
            vero_redirect(BIOS_BASE . '/mip/monitoramento?pulverizar=' . (int)$talhaoId
                . ',' . $safraLink . ',' . (int)$alvoAlerta);
        }
        vero_redirect();
    }

    /* A1-47: enviar um rascunho ao líder (o alerta dispara aqui, se acima do nível) */
    if ($acao === 'enviar') {
        vero_require('mip.monitoramentos.editar');
        $id = vero_int('id');
        $mon = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => vero_tenant()]) : null;
        if (!$mon) { vero_flash('erro', 'Monitoramento inválido.'); vero_redirect(); }
        if ((string)$mon['status'] === 'enviado') { vero_flash('aviso', 'Este monitoramento já foi enviado.'); vero_redirect(); }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            vero_update(T, (int)$id, ['status' => 'enviado']);
            $nAlertas = mip_reemitir_alertas((int)$id);
            $pdo->commit();
            vero_flash('ok', 'Monitoramento ENVIADO ao líder.' . ($nAlertas > 0 ? ' Nível de ação atingido — ' . $nAlertas . ' alerta(s) gerado(s).' : ''));
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'excluir_foto') {
        vero_require('mip.monitoramentos.editar');
        $anexoId = vero_int('anexo_id');
        $monId   = vero_int('id');
        $anexo = $anexoId ? vero_row(
            "SELECT * FROM agro_anexos WHERE id=:i AND tenant_id=:t AND origem_tipo='mip_monitoramento'",
            [':i' => $anexoId, ':t' => vero_tenant()]) : null;
        if ($anexo) {
            $arq = dirname(__DIR__) . $anexo['url'];
            if (is_file($arq)) unlink($arq);
            vero_pdo()->prepare("DELETE FROM agro_anexos WHERE tenant_id=? AND id=?")->execute([vero_tenant(), (int)$anexoId]);
            vero_flash('ok', 'Foto removida.');
        }
        vero_redirect('?editar=' . (int)$monId);
    }

    if ($acao === 'excluir') {
        vero_require('mip.monitoramentos.excluir');
        $id = vero_int('id');
        if ($id) {
            $ok = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => vero_tenant()]);
            if ($ok) {
                $pdo = vero_pdo();
                /* fotos acompanham o registro (arquivo + agro_anexos) */
                foreach (vero_rows(
                    "SELECT * FROM agro_anexos WHERE tenant_id=:t AND origem_tipo='mip_monitoramento' AND origem_id=:o",
                    [':t' => vero_tenant(), ':o' => $id]) as $ax) {
                    $arq = dirname(__DIR__) . $ax['url'];
                    if (is_file($arq)) unlink($arq);
                }
                $pdo->prepare("DELETE FROM agro_anexos WHERE tenant_id=? AND origem_tipo='mip_monitoramento' AND origem_id=?")
                    ->execute([vero_tenant(), $id]);
                /* trilha de ações sai junto com o registro (FK RESTRICT) */
                $pdo->prepare(
                    "DELETE aa FROM mip_alerta_acoes aa
                      JOIN agro_alertas al ON al.id = aa.alerta_id
                     WHERE aa.tenant_id=? AND al.tenant_id=? AND al.categoria='mip'
                       AND al.origem_tipo='mip_monitoramento' AND al.origem_id=?")
                    ->execute([vero_tenant(), vero_tenant(), $id]);
                $pdo->prepare("DELETE FROM agro_alertas WHERE tenant_id=? AND categoria='mip' AND origem_tipo='mip_monitoramento' AND origem_id=?")
                    ->execute([vero_tenant(), $id]);
                /* 8.x: alvos da junção acompanham o registro (sem FK cascade) */
                $pdo->prepare("DELETE FROM mip_monitoramento_alvos WHERE tenant_id=? AND monitoramento_id=?")
                    ->execute([vero_tenant(), $id]);
                $pdo->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")
                    ->execute([vero_tenant(), $id]);
                vero_flash('ok', 'Monitoramento excluído (alerta e fotos removidos).');
            }
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$fTalhao = (int)($_GET['talhao'] ?? 0);
$fAlvo   = (int)($_GET['alvo'] ?? 0);
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

$where  = "m.tenant_id = :t";
$params = [':t' => vero_tenant()];
/* X-02/Y-03: monitoramentos arquivados (legado >100%) ficam ocultos por padrão;
   ?arquivados=1 mostra-os (revisão do histórico). */
$verArquivados = (string)($_GET['arquivados'] ?? '') === '1';
if (!$verArquivados) $where .= " AND m.arquivado = 0";
if ($fTalhao > 0) { $where .= " AND m.talhao_id = :ta"; $params[':ta'] = $fTalhao; }
/* 8.x multi-alvo: filtra por QUALQUER alvo do monitoramento (junção), não só o 1º */
if ($fAlvo > 0)   {
    $where .= " AND EXISTS (SELECT 1 FROM mip_monitoramento_alvos ma
                             WHERE ma.tenant_id = m.tenant_id AND ma.monitoramento_id = m.id AND ma.alvo_id = :al)";
    $params[':al'] = $fAlvo;
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " m WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT m.*,
            tt.codigo AS talhao, f.nome AS fazenda, s.identificacao AS safra,
            st.safra_id AS safra_id, pt.nome AS ponto_nome,
            (SELECT COUNT(*) FROM agro_anexos ax
              WHERE ax.tenant_id = m.tenant_id AND ax.origem_tipo = 'mip_monitoramento'
                AND ax.origem_id = m.id) AS fotos,
            (SELECT COUNT(*) FROM agro_alertas al
              WHERE al.tenant_id = m.tenant_id AND al.categoria = 'mip'
                AND al.origem_tipo = 'mip_monitoramento' AND al.origem_id = m.id) AS alertas_qtd
       FROM " . T . " m
       JOIN agro_talhoes tt ON tt.id = m.talhao_id
       JOIN agro_fazendas f ON f.id = tt.fazenda_id
       LEFT JOIN agro_safra_talhoes st ON st.id = m.safra_talhao_id
       LEFT JOIN agro_safras s ON s.id = st.safra_id
       LEFT JOIN mip_pontos_amostragem pt ON pt.id = m.ponto_id
      WHERE {$where}
      ORDER BY m.data_monitoramento DESC, m.id DESC
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params);

/* alvos de cada monitoramento listado (junção) — uma query só */
$alvosPorMon = [];
$monIds = array_map(static fn($r) => (int)$r['id'], $rows);
if ($monIds) {
    $inList = implode(',', $monIds); /* ints vindos do banco → seguro inline */
    foreach (vero_rows(
        "SELECT ma.monitoramento_id, ma.nivel_infestacao, ma.local_infestacao,
                a.nome AS alvo_nome, a.nivel_acao
           FROM mip_monitoramento_alvos ma
           JOIN mip_alvos a ON a.id = ma.alvo_id
          WHERE ma.tenant_id = :t AND ma.monitoramento_id IN ({$inList})
          ORDER BY ma.id", [':t' => vero_tenant()]) as $x) {
        $alvosPorMon[(int)$x['monitoramento_id']][] = $x;
    }
}

$talhoes = vero_rows(
    "SELECT t.id, CONCAT(f.nome, ' — ', t.codigo) AS label,
            f.mip_metodologia, f.mip_unidades_por_planta
       FROM agro_talhoes t JOIN agro_fazendas f ON f.id = t.fazenda_id
      WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo", [':t' => vero_tenant()]);
/* X-02: default de metodologia por fazenda, por válvula (para o form herdar). */
$mipCfgValvula = [];
foreach ($talhoes as $tt) {
    $mipCfgValvula[(int)$tt['id']] = [
        'metodologia' => (string)($tt['mip_metodologia'] ?? 'planta'),
        'unidades'    => $tt['mip_unidades_por_planta'] !== null ? (int)$tt['mip_unidades_por_planta'] : null,
    ];
}
$alvos = vero_rows(
    "SELECT id, nome, nivel_acao FROM mip_alvos WHERE tenant_id = :t AND ativo = 1 ORDER BY nome",
    [':t' => vero_tenant()]);
$vinculos = vero_rows(
    "SELECT st.id, st.talhao_id, CONCAT(s.identificacao, ' · ', c.nome) AS label
       FROM agro_safra_talhoes st
       JOIN agro_safras s ON s.id = st.safra_id
       JOIN agro_culturas c ON c.id = st.cultura_id
      WHERE st.tenant_id = :t ORDER BY s.identificacao DESC", [':t' => vero_tenant()]);
$pontos = vero_rows(
    "SELECT p.id, p.talhao_id, p.nome FROM mip_pontos_amostragem p
      WHERE p.tenant_id = :t ORDER BY p.nome", [':t' => vero_tenant()]);

$edit = null;
$editFotos = [];
$editAlvos = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        $editFotos = vero_rows(
            "SELECT * FROM agro_anexos WHERE tenant_id=:t AND origem_tipo='mip_monitoramento' AND origem_id=:o ORDER BY id",
            [':t' => vero_tenant(), ':o' => (int)$edit['id']]);
        $editAlvos = vero_rows(
            "SELECT * FROM mip_monitoramento_alvos WHERE tenant_id=:t AND monitoramento_id=:o ORDER BY id",
            [':t' => vero_tenant(), ':o' => (int)$edit['id']]);
    }
}

/** Renderiza uma linha do repeater de alvos (para o template e as linhas de edição). */
function mon_alvo_row(array $alvos, ?array $row): string
{
    $selAlvo = $row ? (int)$row['alvo_id'] : 0;
    $nivel   = $row && $row['nivel_infestacao'] !== null ? numFmt((float)$row['nivel_infestacao'], 2) : '';
    $qtd     = $row && $row['quantidade_encontrada'] !== null ? numFmt((float)$row['quantidade_encontrada'], 2) : '';
    $selLoc  = $row ? (string)($row['local_infestacao'] ?? '') : '';
    $selSev  = $row ? (string)($row['severidade_qualitativa'] ?? '') : '';
    ob_start(); ?>
    <div class="mon-alvo-row">
      <div class="vfield">
        <select name="alvo_id[]" onchange="monRowNivel(this)">
          <option value="">— Alvo —</option>
          <?php foreach ($alvos as $al): ?>
            <option value="<?= (int)$al['id'] ?>" data-nivel="<?= h((string)($al['nivel_acao'] ?? '')) ?>"<?= $selAlvo === (int)$al['id'] ? ' selected' : '' ?>><?= h($al['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="vhint mon-row-hint"></div>
      </div>
      <div class="vfield"><input type="text" name="nivel_infestacao[]" placeholder="Índice" value="<?= h($nivel) ?>"></div>
      <div class="vfield"><input type="text" name="quantidade_encontrada[]" placeholder="Qtd" value="<?= h($qtd) ?>"></div>
      <div class="vfield">
        <select name="local_infestacao[]">
          <option value="">— Local —</option>
          <?php foreach (LOCAIS as $k => $v): ?>
            <option value="<?= h($k) ?>"<?= $selLoc === $k ? ' selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vfield">
        <select name="severidade_qualitativa[]">
          <option value="">— Sev. —</option>
          <?php foreach (SEVERIDADES as $k => $v): ?>
            <option value="<?= h($k) ?>"<?= $selSev === $k ? ' selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="button" class="vbtn vbtn-ghost vbtn-sm mon-row-del" onclick="monDelAlvo(this)" title="Remover alvo">×</button>
      <!-- W-05: aviso reativo (não bloqueia o save) — Qtd > plantas amostradas -->
      <div class="mon-row-warn" role="alert" style="display:none"></div>
    </div>
    <?php return (string)ob_get_clean();
}

$GUARD      = ['macro' => 'mip', 'micro' => 'monitoramentos'];
$PAGE_VIEW  = 'mip_monitoramentos';
$PAGE_TITLE = 'Monitoramento MIP';
$EXTRA_HEAD = vero_assets() . <<<'HTML'
<style>
.mon-alvos-head{display:grid;grid-template-columns:2fr 1fr 1fr 1.2fr 1.2fr auto;gap:8px;font-size:11px;font-weight:600;color:#8A7D6E;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.mon-alvo-row{display:grid;grid-template-columns:2fr 1fr 1fr 1.2fr 1.2fr auto;gap:8px;align-items:start;margin-bottom:8px}
.mon-alvo-row .vfield{margin:0}
.mon-alvo-row .mon-row-del{align-self:center;padding:6px 10px}
/* W-05: aviso (NÃO bloqueio) quando a Qtd digitada > plantas amostradas */
.mon-alvo-row .mon-row-warn{grid-column:1/-1;margin:2px 0 0;padding:7px 10px;border-radius:8px;background:#F7EFD9;color:#8A6D1A;border:1px solid #E8D9A8;font-size:12px;font-weight:600;line-height:1.45;display:flex;gap:7px;align-items:flex-start}
.mon-alvo-row .mon-row-warn::before{content:"!";flex:none;width:16px;height:16px;margin-top:1px;border-radius:50%;background:#B57C1A;color:#fff;font-size:11px;line-height:16px;text-align:center;font-weight:700}
/* N-02: ícone (i) com a metodologia — popover simples, sem dependência externa */
.mon-info-wrap{position:relative;display:inline-block}
.mon-info-btn{width:16px;height:16px;border-radius:50%;border:1px solid #B57C1A;background:none;color:#B57C1A;font:italic 700 11px/14px 'IBM Plex Sans',sans-serif;cursor:pointer;padding:0;margin-left:6px;vertical-align:middle}
.mon-info-btn:hover{background:#B57C1A;color:#fff}
.mon-info-btn:focus-visible{outline:2px solid #005059;outline-offset:2px}
.mon-info-pop{position:absolute;left:0;top:calc(100% + 6px);z-index:5;width:310px;max-width:78vw;background:#FDFCF9;border:1px solid #D5CEBF;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.18);padding:11px 13px;font:400 12px/1.5 'IBM Plex Sans',sans-serif;color:#4A4034;text-transform:none;letter-spacing:normal}
.mon-info-pop strong{color:#2B2018}
@media(max-width:820px){.mon-alvos-head{display:none}.mon-alvo-row{grid-template-columns:1fr 1fr;border:1px solid #EEE8DB;border-radius:9px;padding:8px}}
</style>
HTML;
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('mip.monitoramentos.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?php /* A1-47/48e: banner pós-alerta — pulverização com o contexto do monitoramento */
  if (!empty($_GET['pulverizar']) && preg_match('/^\d+,\d+,\d+$/', (string)$_GET['pulverizar'])) {
      [$pTal, $pSaf, $pAlvo] = array_map('intval', explode(',', (string)$_GET['pulverizar'])); ?>
    <div class="vflash vflash-aviso" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <span><strong>Nível de ação atingido.</strong> Iniciar a OS de pulverização com este contexto (área, safra e alvo)? A decisão do manejo e a validação são do RT.</span>
      <a class="vbtn vbtn-primary vbtn-sm"
         href="<?= BIOS_BASE ?>/mip/aplicacoes?novo=1&pre_talhao=<?= $pTal ?><?= $pSaf ? '&pre_safra=' . $pSaf : '' ?>&pre_alvo=<?= $pAlvo ?>">Emitir DF pré-preenchida</a>
    </div>
  <?php } ?>
  <?= vero_page_header('Monitoramento MIP', 'Índice de infestação por válvula × alvo × data — atingiu o nível de ação, gera alerta (manejo validado pelo RT)',
        $podeEditar ? '+ Novo monitoramento' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="talhao" onchange="this.form.submit()">
          <option value="">Todas as válvulas</option>
          <?php foreach ($talhoes as $tt): ?>
            <option value="<?= (int)$tt['id'] ?>"<?= $fTalhao === (int)$tt['id'] ? ' selected' : '' ?>><?= h($tt['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="alvo" onchange="this.form.submit()">
          <option value="">Todos os alvos</option>
          <?php foreach ($alvos as $al): ?>
            <option value="<?= (int)$al['id'] ?>"<?= $fAlvo === (int)$al['id'] ? ' selected' : '' ?>><?= h($al['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum monitoramento registrado<?= !$alvos ? ' — cadastre primeiro os alvos em MIP → Alvos de Controle' : '' ?>.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Data</th><th>Fazenda / Válvula</th><th>Safra</th>
        <th>Alvos (índice × nível de ação)</th>
        <th>Situação</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $alvosMon = $alvosPorMon[(int)$r['id']] ?? [];
          $acima = false;
          foreach ($alvosMon as $am) {
              if ($am['nivel_acao'] !== null && (float)$am['nivel_infestacao'] >= (float)$am['nivel_acao']) { $acima = true; break; }
          }
          $unid = h($r['unidade'] ?? '%');
      ?>
        <tr>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_monitoramento'])) ?></td>
          <td><strong><?= h($r['fazenda']) ?> — <?= h($r['talhao']) ?></strong>
              <?php
                $extra = [];
                if ($r['ponto_nome'])              $extra[] = 'Ponto: ' . h((string)$r['ponto_nome']);
                if ((int)$r['fotos'] > 0)          $extra[] = (int)$r['fotos'] . ' foto(s)';
              ?>
              <?= $extra ? '<div class="vhint">' . implode(' · ', $extra) . '</div>' : '' ?></td>
          <td><?= h($r['safra'] ?? '') ?: '—' ?></td>
          <td><?php if (!$alvosMon): ?>—<?php else: foreach ($alvosMon as $am):
                  $aAcima = $am['nivel_acao'] !== null && (float)$am['nivel_infestacao'] >= (float)$am['nivel_acao']; ?>
                <div style="display:flex;gap:6px;align-items:baseline;flex-wrap:wrap;margin-bottom:2px">
                  <span><?= h($am['alvo_nome']) ?></span>
                  <span class="vnum"><strong><?= numFmt((float)$am['nivel_infestacao'], 2) ?></strong> <span class="vhint"><?= $unid ?></span></span>
                  <span class="vhint"><?= $am['nivel_acao'] !== null ? 'nível ' . numFmt((float)$am['nivel_acao'], 2) . '%' : 'sem nível' ?></span>
                  <?= $am['nivel_acao'] !== null
                        ? ($aAcima ? '<span class="vbadge vb-off"></span>' : '<span class="vbadge vb-ok"></span>')
                        : '' ?>
                  <?php if ($am['local_infestacao'] && isset(LOCAIS[$am['local_infestacao']])): ?>
                    <span class="vhint">· <?= h(LOCAIS[$am['local_infestacao']]) ?></span>
                  <?php endif; ?>
                </div>
              <?php endforeach; endif; ?></td>
          <td><?php
                $temNivel = false;
                foreach ($alvosMon as $am) { if ($am['nivel_acao'] !== null) { $temNivel = true; break; } }
                $ehRascunhoRow = (string)($r['status'] ?? 'enviado') === 'rascunho';
                echo !$temNivel
                    ? '<span class="vbadge vb-info">sem nível de ação</span>'
                    : ($acima ? '<span class="vbadge vb-off">Acima do nível</span>' : '<span class="vbadge vb-ok">Abaixo do nível</span>');
              ?>
              <?php /* A1-47: fluxo enviar-p/-líder */ ?>
              <?= $ehRascunhoRow
                ? '<br><span class="vbadge vb-warn" title="Coleta em andamento — sem alerta até enviar">Rascunho</span>'
                : '' ?>
              <?php /* P09 (auditoria 20/07) — TRANSPARÊNCIA: por que há/não há alerta, para
                       o índice acima do nível nunca parecer "validado sem alerta". */
                $motivoAlerta = null;
                if ($acima && $temNivel) {
                    if ($ehRascunhoRow)              $motivoAlerta = 'Sem alerta — rascunho (envie ao líder para gerar)';
                    elseif ((int)($r['alertas_qtd'] ?? 0) > 0) $motivoAlerta = (int)$r['alertas_qtd'] . ' alerta(s) gerado(s) — ver Alertas Fitossanitários';
                    else                             $motivoAlerta = 'Sem alerta ativo (resolvido/removido)';
                } elseif ($acima && !$temNivel) {
                    $motivoAlerta = 'Sem alerta — alvo sem nível de ação cadastrado';
                }
                if ($motivoAlerta): ?>
                <div class="vhint" style="margin-top:2px"><?= h($motivoAlerta) ?></div>
              <?php endif; ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar && (string)($r['status'] ?? '') === 'rascunho'): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="enviar">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="vbtn vbtn-primary vbtn-sm" type="submit" title="O líder recebe e o alerta dispara se estiver acima do nível">Enviar</button>
              </form>
            <?php endif; ?>
            <?php if ($podeEditar && $acima): ?>
              <?= vero_btn_icone('<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11h6a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2Z"/><path d="M9 11V7h4V4M17 4h.01M20 6h.01M20 9h.01M17 9h.01"/></svg>', 'Pulverizar (nova OS com o contexto desta linha)', '', BIOS_BASE . '/mip/aplicacoes?novo=1&pre_talhao=' . (int)$r['talhao_id'] . ($r['safra_id'] ? '&pre_safra=' . (int)$r['safra_id'] : '') . '&pre_alvo=' . (int)$r['alvo_id']) ?>
            <?php endif; ?>
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('mip.monitoramentos.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este monitoramento? O alerta vinculado será removido.') ?>
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
      <h2><?= $edit ? 'Editar monitoramento' : 'Novo monitoramento' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="vfield">
          <label>Data *</label>
          <input type="date" name="data_monitoramento" required
                 value="<?= h($edit ? (string)$edit['data_monitoramento'] : date('Y-m-d')) ?>">
        </div>
        <div class="vfield">
          <label><?= h(vero_a1_rotulo_area()) ?> *</label>
          <select name="talhao_id" id="mon-talhao" required>
            <option value="">— Selecione —</option>
            <?php foreach ($talhoes as $tt): ?>
              <option value="<?= (int)$tt['id'] ?>"<?= $edit && (int)$edit['talhao_id'] === (int)$tt['id'] ? ' selected' : '' ?>><?= h($tt['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Ponto de amostragem</label>
          <select name="ponto_id" id="mon-ponto">
            <option value="">— Sem ponto —</option>
          </select>
          <div class="vhint">Pontos georreferenciados da válvula (MIP → Pontos de Amostragem)</div>
        </div>
        <div class="vfield">
          <label>Safra (vínculo)</label>
          <select name="safra_talhao_id" id="mon-safra">
            <option value="">— Sem safra —</option>
          </select>
        </div>
        <?= vero_f_text('unidade', 'Unidade do índice', $edit['unidade'] ?? '%', false, 'Aplica a todos os alvos. Default: %') ?>
        <?php
          /* X-02: metodologia parametrizável. Edição usa o método
             gravado; nova coleta herda o default da fazenda (JS pela válvula). */
          $metEdit = (string)($edit['metodologia'] ?? '');
          $METS = ['planta' => 'Por planta (presença)', 'folha' => 'Por folha', 'caixa' => 'Por caixa', 'severidade' => 'Severidade'];
        ?>
        <div class="vfield">
          <label>Metodologia de amostragem</label>
          <select name="metodologia" id="mon-metodologia">
            <?php foreach ($METS as $val => $lbl): ?>
              <option value="<?= $val ?>"<?= $metEdit === $val ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
          <div class="vhint">Define a unidade contada com a praga. Herda o padrão da fazenda ao escolher a válvula.</div>
        </div>
        <div class="vfield" id="mon-unid-wrap">
          <label>Unidades por planta <span class="vhint" id="mon-unid-lbl">(folhas/planta)</span></label>
          <input type="number" name="unidades_por_planta" id="mon-unidades" min="1" step="1"
                 value="<?= $edit && ($edit['unidades_por_planta'] ?? null) !== null ? (int)$edit['unidades_por_planta'] : '' ?>">
          <div class="vhint">Ex.: 15 folhas/planta. Espaço amostral = unidades/planta × plantas amostradas.</div>
        </div>
        <div class="vfield">
          <label>Plantas amostradas</label>
          <input type="number" name="plantas_amostradas" id="mon-amostradas" min="1" step="1"
                 value="<?= $edit && ($edit['plantas_amostradas'] ?? null) !== null ? (int)$edit['plantas_amostradas'] : '' ?>">
          <div class="vhint">nº de plantas lidas na válvula. O índice consolidado = encontradas ÷
            (unidades/planta × plantas amostradas) × 100 — cálculo ao vivo em cada linha.
            Índice digitado manualmente prevalece. <strong id="mon-espaco"></strong></div>
        </div>
        <div class="full">
          <label style="display:block;font-size:12px;font-weight:600;color:#4A4034;margin-bottom:5px">Alvos monitorados *<span class="mon-info-wrap"><button type="button" class="mon-info-btn" onclick="monInfoToggle(event)" aria-label="Metodologia do preenchimento da quantidade" title="Como preencher a Qtd">i</button><span class="mon-info-pop" id="mon-info-pop" role="tooltip" style="display:none"><strong>Como preencher a Qtd</strong><br>Qtd = número de <strong>unidades da metodologia escolhida</strong> (folhas, plantas ou caixas) <strong>com</strong> a praga — não o nº de insetos. O índice consolidado da área = encontradas ÷ <strong>espaço amostral</strong> × 100, onde espaço amostral = unidades/planta × plantas amostradas (ex.: 15 folhas × 20 plantas = 300).<br><br>A metodologia é parametrizável por fazenda (planta / folha / caixa / severidade) — padrão do Vale: por folha.</span></span></label>
          <div class="vhint" style="margin-bottom:6px">Um ou mais alvos — cada um com seu índice/quantidade, local e severidade. Índice vazio = usa a quantidade.</div>
          <div class="mon-alvos-head">
            <span>Alvo</span><span>Índice</span><span>Qtd</span><span>Local</span><span>Severidade</span><span></span>
          </div>
          <div id="mon-alvos">
            <?php if ($editAlvos): foreach ($editAlvos as $ea): ?>
              <?= mon_alvo_row($alvos, $ea) ?>
            <?php endforeach; else: ?>
              <?= mon_alvo_row($alvos, null) ?>
            <?php endif; ?>
          </div>
          <button type="button" class="vbtn vbtn-ghost vbtn-sm" onclick="monAddAlvo()">+ Alvo</button>
          <!-- A-01: erro de validação dos alvos aparece AQUI, inline — o modal não
               fecha e nada do que foi digitado se perde -->
          <div id="mon-alvos-erro" role="alert" style="display:none;margin-top:6px;padding:8px 10px;border-radius:8px;background:#FBEDE9;color:#B3402A;font-size:12.5px;font-weight:600"></div>
        </div>
        <div class="vfield">
          <label>Fotos (JPG/PNG)</label>
          <input type="file" name="fotos[]" accept=".jpg,.jpeg,.png" multiple>
          <div class="vhint">Evidência de campo — várias fotos permitidas</div>
        </div>
        <div class="full"><?= vero_f_text('observacao', 'Observação', $edit['observacao'] ?? '') ?></div>
      </div>
      <template id="mon-alvo-tmpl"><?= mon_alvo_row($alvos, null) ?></template>
      <?php if ($edit && $editFotos): ?>
      <div class="vfield" style="margin-top:8px">
        <label>Fotos já anexadas</label>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <?php foreach ($editFotos as $fx): ?>
            <span style="display:inline-flex;gap:6px;align-items:center">
              <a href="<?= BIOS_BASE . h((string)$fx['url']) ?>" target="_blank"><?= h((string)$fx['nome_original']) ?></a>
              <button class="vbtn vbtn-ghost vbtn-sm" type="submit" form="mon-foto-del-<?= (int)$fx['id'] ?>"
                      data-confirm="Remover esta foto?" data-confirm-danger data-confirm-ok="Remover"
                      onclick="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">×</button>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <!-- A1-47: rascunho = coleta em andamento (SEM alerta); enviar = líder recebe e o alerta dispara -->
        <button class="vbtn vbtn-ghost" type="submit" title="Coleta em andamento — não gera alerta">Salvar rascunho</button>
        <button class="vbtn vbtn-primary" type="submit"
                onclick="this.form.querySelector('input[name=enviar]').value='1'">Salvar e ENVIAR ao líder</button>
        <input type="hidden" name="enviar" value="">
      </div>
    </form>
  </div>
</div>
<?php if ($edit && $editFotos): foreach ($editFotos as $fx): ?>
  <form method="post" id="mon-foto-del-<?= (int)$fx['id'] ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
    <input type="hidden" name="acao" value="excluir_foto">
    <input type="hidden" name="anexo_id" value="<?= (int)$fx['id'] ?>">
    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  </form>
<?php endforeach; endif; ?>
<script>
const MON_VINCULOS = <?= jsvar(array_map(static fn($v) => [
    'id' => (int)$v['id'], 'talhao' => (int)$v['talhao_id'], 'label' => $v['label'],
], $vinculos)) ?>;
const MON_PONTOS = <?= jsvar(array_map(static fn($p) => [
    'id' => (int)$p['id'], 'talhao' => (int)$p['talhao_id'], 'label' => $p['nome'],
], $pontos)) ?>;
const MIP_CFG_VALVULA = <?= jsvar($mipCfgValvula) ?>;  /* X-02: default de metodologia por válvula/fazenda */
const MON_EDIT_ST = <?= $edit && $edit['safra_talhao_id'] !== null ? (int)$edit['safra_talhao_id'] : 'null' ?>;
const MON_EDIT_PT = <?= $edit && $edit['ponto_id'] !== null ? (int)$edit['ponto_id'] : 'null' ?>;
function monSafras() {
  const talhao = parseInt(document.getElementById('mon-talhao').value || '0', 10);
  const sel = document.getElementById('mon-safra');
  sel.innerHTML = '<option value="">— Sem safra —</option>';
  const lista = MON_VINCULOS.filter(v => v.talhao === talhao); /* já vem da mais recente p/ a mais antiga */
  lista.forEach(v => sel.add(new Option(v.label, v.id)));
  if (MON_EDIT_ST) sel.value = String(MON_EDIT_ST);            /* edição: mantém o vínculo salvo */
  else if (lista.length) sel.value = String(lista[0].id);      /* 8.2: auto-seleciona a safra do vínculo (mais recente) */
  const selP = document.getElementById('mon-ponto');
  selP.innerHTML = '<option value="">— Sem ponto —</option>';
  MON_PONTOS.filter(p => p.talhao === talhao).forEach(p => selP.add(new Option(p.label, p.id)));
  if (MON_EDIT_PT) selP.value = String(MON_EDIT_PT);
}
/* 8.x multi-alvo: repeater. Clona a <template> renderizada no servidor (opções
   já escapadas com h()) — sem innerHTML de dado do servidor. */
/* C-28: hint da linha = nível de ação + ÍNDICE CONSOLIDADO DA ÁREA ao vivo
   (regra de 3: encontradas ÷ plantas amostradas × 100; índice digitado vence) */
function monConsolidaRow(row) {
  const hint = row.querySelector('.mon-row-hint');
  if (!hint) return;
  const partes = [];
  const opt = row.querySelector('select[name="alvo_id[]"]').selectedOptions[0];
  if (opt && opt.dataset.nivel) partes.push('Nível de ação: ' + opt.dataset.nivel + '%');
  /* X-02: espaço amostral = unidades/planta × plantas amostradas (15×20=300). */
  const espaco = monEspacoAmostral();
  const unidNome = monUnidadeNome();
  const nivVazio = row.querySelector('input[name="nivel_infestacao[]"]').value.trim() === '';
  const qtd = parseFloat(row.querySelector('input[name="quantidade_encontrada[]"]').value.trim().replace(/\./g, '').replace(',', '.'));
  if (nivVazio && espaco > 0 && !isNaN(qtd)) {
    const idx = Math.round(qtd / espaco * 10000) / 100;
    partes.push('Índice da área: ' + String(idx).replace('.', ',') + '% (' + qtd + ' em ' + espaco + ' ' + unidNome + ')');
  }
  hint.textContent = partes.join(' · ');
  /* W-05: AVISA (não bloqueia) quando a Qtd passa do ESPAÇO AMOSTRAL — sinal de
     que contou errado (insetos em vez da unidade). Origem dos índices >100%. */
  const warn = row.querySelector('.mon-row-warn');
  if (warn) {
    if (espaco > 0 && !isNaN(qtd) && qtd > espaco) {
      warn.textContent = 'Você digitou ' + qtd + ' em ' + espaco + ' ' + unidNome + ' (espaço amostral) — a Qtd é o nº de ' + unidNome + ' COM o alvo. Confira antes de enviar.';
      warn.style.display = 'flex';
    } else {
      warn.style.display = 'none';
    }
  }
}
/* X-02: helpers de metodologia parametrizável */
function monUnidadeNome() {
  var m = (document.getElementById('mon-metodologia') || {}).value || 'planta';
  return m === 'folha' ? 'folhas' : (m === 'caixa' ? 'caixas' : (m === 'severidade' ? 'unid.' : 'plantas'));
}
function monEspacoAmostral() {
  var p = parseInt((document.getElementById('mon-amostradas') || {}).value || '0', 10);
  var u = parseInt((document.getElementById('mon-unidades') || {}).value || '0', 10) || 1;
  return p > 0 ? p * u : 0;
}
/* atualiza rótulos (folhas/planta…), o total do espaço amostral e recalcula as linhas */
function monMetodologiaSync() {
  var m = (document.getElementById('mon-metodologia') || {}).value || 'planta';
  var wrap = document.getElementById('mon-unid-wrap');
  var lbl = document.getElementById('mon-unid-lbl');
  var esp = document.getElementById('mon-espaco');
  var un = monUnidadeNome();
  if (lbl) lbl.textContent = m === 'severidade' ? '(unidades/planta)' : '(' + un + '/planta)';
  /* por planta: multiplicador é 1 — esconde o campo de unidades p/ não confundir */
  if (wrap) wrap.style.display = (m === 'planta') ? 'none' : '';
  var e = monEspacoAmostral();
  if (esp) esp.textContent = e > 0 ? ('Espaço amostral: ' + e + ' ' + un + '.') : '';
  document.querySelectorAll('.mon-alvo-row').forEach(monConsolidaRow);
}
/* N-02: abre/fecha o popover da metodologia (sem dependência externa) */
function monInfoToggle(ev) {
  ev.preventDefault();
  ev.stopPropagation();
  const pop = document.getElementById('mon-info-pop');
  if (pop) pop.style.display = (pop.style.display === 'block') ? 'none' : 'block';
}
document.addEventListener('click', function (e) {
  const pop = document.getElementById('mon-info-pop');
  if (pop && pop.style.display === 'block' && !e.target.closest('.mon-info-wrap')) pop.style.display = 'none';
});
function monRowNivel(sel) { monConsolidaRow(sel.closest('.mon-alvo-row')); }
function monAddAlvo() {
  const tmpl = document.getElementById('mon-alvo-tmpl');
  const node = tmpl.content.firstElementChild.cloneNode(true);
  document.getElementById('mon-alvos').appendChild(node);
}
function monDelAlvo(btn) {
  const rows = document.querySelectorAll('#mon-alvos .mon-alvo-row');
  if (rows.length <= 1) { /* mantém ao menos 1 alvo */
    const row = btn.closest('.mon-alvo-row');
    row.querySelectorAll('select,input').forEach(el => { el.value = ''; });
    monRowNivel(row.querySelector('select[name="alvo_id[]"]'));
    return;
  }
  btn.closest('.mon-alvo-row').remove();
}
document.getElementById('mon-talhao').addEventListener('change', monSafras);
monSafras();
document.querySelectorAll('#mon-alvos select[name="alvo_id[]"]').forEach(monRowNivel);
/* A-01: validação dos alvos ANTES do submit — erro aparece
   inline (#mon-alvos-erro), o modal fica aberto e os dados digitados são
   preservados. Espelha as 2 regras do servidor (que seguem como rede final):
   (1) cada alvo precisa de índice OU quantidade; (2) alvo+local não repetem
   (C-27: mesmo alvo em locais diferentes é permitido). */
document.querySelector('#vm-form form').addEventListener('submit', function (e) {
  const box = document.getElementById('mon-alvos-erro');
  const vistos = new Set();
  let erro = '';
  for (const row of document.querySelectorAll('#mon-alvos .mon-alvo-row')) {
    const aid = row.querySelector('select[name="alvo_id[]"]').value;
    if (!aid) continue;                                   /* linha em branco → servidor ignora */
    const niv = row.querySelector('input[name="nivel_infestacao[]"]').value.trim();
    const qtd = row.querySelector('input[name="quantidade_encontrada[]"]').value.trim();
    if (niv === '' && qtd === '') { erro = 'Informe a quantidade ou o índice de cada alvo.'; break; }
    const chave = aid + '|' + row.querySelector('select[name="local_infestacao[]"]').value;
    if (vistos.has(chave)) { erro = 'Alvo repetido no mesmo local — para repetir o alvo, escolha um local diferente.'; break; }
    vistos.add(chave);
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
/* C-28: cálculo incremental — qualquer digitação no repeater ou nas plantas
   amostradas atualiza o índice consolidado das linhas ao vivo */
document.getElementById('mon-alvos').addEventListener('input', function (e) {
  const row = e.target.closest('.mon-alvo-row');
  if (row) monConsolidaRow(row);
});
(function () {
  const nEl = document.getElementById('mon-amostradas');
  if (nEl) nEl.addEventListener('input', monMetodologiaSync);
  const uEl = document.getElementById('mon-unidades');
  if (uEl) uEl.addEventListener('input', monMetodologiaSync);
  const mEl = document.getElementById('mon-metodologia');
  if (mEl) mEl.addEventListener('change', monMetodologiaSync);
  /* X-02: default por fazenda — ao escolher a válvula, herda metodologia +
     unidades/planta da fazenda (só quando o campo está vazio / é coleta nova). */
  const tEl = document.getElementById('mon-talhao');
  const ehNovo = (document.querySelector('#vm-form input[name="id"]') || {}).value === '';
  function aplicaDefaultFazenda() {
    if (!ehNovo) return;
    const cfg = MIP_CFG_VALVULA[(tEl && tEl.value) || ''] || null;
    if (!cfg) return;
    if (mEl && cfg.metodologia) mEl.value = cfg.metodologia;
    if (uEl && (uEl.value === '' || uEl.value === '0') && cfg.unidades) uEl.value = cfg.unidades;
    monMetodologiaSync();
  }
  if (tEl) tEl.addEventListener('change', aplicaDefaultFazenda);
  monMetodologiaSync(); /* estado inicial (rótulos + espaço amostral) */
  if (ehNovo) aplicaDefaultFazenda();
})();

/* C-28: RASCUNHO AUTOMÁTICO local (localStorage) — o monitor não perde a
   digitação se a página fechar/cair. Só no NOVO (edição já tem o banco);
   enviado com sucesso = rascunho local limpo. Nada vai ao servidor aqui. */
(function () {
  const form = document.querySelector('#vm-form form');
  if (!form || form.querySelector('input[name="id"]').value !== '') return;
  const LS = 'vero_mon_rascunho';
  const CAMPOS_ALVO = ['alvo_id[]', 'nivel_infestacao[]', 'quantidade_encontrada[]', 'local_infestacao[]', 'severidade_qualitativa[]'];
  function salva() {
    const d = { campos: {}, alvos: [] };
    form.querySelectorAll('input[name], select[name]').forEach(el => {
      if (el.name.endsWith('[]') || el.type === 'file' || el.type === 'hidden') return;
      d.campos[el.name] = el.value;
    });
    ['talhao_id', 'safra_talhao_id', 'ponto_id'].forEach(n => {
      const el = form.querySelector('[name="' + n + '"]'); if (el) d.campos[n] = el.value;
    });
    document.querySelectorAll('#mon-alvos .mon-alvo-row').forEach(row => {
      d.alvos.push(CAMPOS_ALVO.map(n => { const el = row.querySelector('[name="' + n + '"]'); return el ? el.value : ''; }));
    });
    try { localStorage.setItem(LS, JSON.stringify(d)); } catch (err) {}
  }
  form.addEventListener('input', salva);
  form.addEventListener('submit', function () { try { localStorage.removeItem(LS); } catch (err) {} });
  /* restauração: aviso discreto com ação explícita (não sobrescreve nada sozinho) */
  let d = null;
  try { d = JSON.parse(localStorage.getItem(LS) || 'null'); } catch (err) {}
  if (!d || !Array.isArray(d.alvos) || !d.alvos.some(a => a[0] !== '')) return;
  const aviso = document.createElement('div');
  aviso.style.cssText = 'margin:0 0 10px;padding:8px 10px;border-radius:8px;background:#EDF5EC;color:#2F5D33;font-size:12.5px;font-weight:600';
  aviso.textContent = 'Há uma digitação não enviada desta tela (rascunho automático). ';
  const a = document.createElement('a');
  a.href = '#'; a.textContent = 'Restaurar'; a.style.fontWeight = '700';
  a.onclick = function (ev) {
    ev.preventDefault();
    Object.entries(d.campos || {}).forEach(([n, v]) => {
      const el = form.querySelector('[name="' + n + '"]'); if (el) el.value = v;
    });
    const tal = form.querySelector('[name="talhao_id"]');
    tal.dispatchEvent(new Event('change'));                      /* repopula safra/ponto… */
    ['safra_talhao_id', 'ponto_id'].forEach(n => {               /* …e re-aplica a escolha */
      const el = form.querySelector('[name="' + n + '"]'); if (el && d.campos[n]) el.value = d.campos[n];
    });
    while (document.querySelectorAll('#mon-alvos .mon-alvo-row').length < d.alvos.length) monAddAlvo();
    const rows = document.querySelectorAll('#mon-alvos .mon-alvo-row');
    d.alvos.forEach((vals, i) => {
      CAMPOS_ALVO.forEach((n, j) => { const el = rows[i].querySelector('[name="' + n + '"]'); if (el) el.value = vals[j]; });
      monConsolidaRow(rows[i]);
    });
    aviso.remove();
    vModalOpen('vm-form');
  };
  aviso.appendChild(a);
  form.insertBefore(aviso, form.firstChild);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
