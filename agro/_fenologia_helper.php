<?php
/* ============================================================
   VERO — Helper de fenologia por período (domínio A1 — A1-29)
   Resolve a fase fenológica AUTOMATICAMENTE pela data usando
   agro_fenologia_periodos (DB-32 / migration 135).
   Regra de resolução: período específico do vínculo safra×talhão
   vence o período geral da safra (safra_talhao_id IS NULL);
   empate = o de data_inicio mais recente. Retorna NULL se não
   houver período — o usuário informa manualmente (nada é inventado).
   ============================================================ */
declare(strict_types=1);

/**
 * @param int|null $safraTalhaoId vínculo safra×talhão (preferencial)
 * @param int|null $safraId       fallback quando só a safra é conhecida
 * @param string   $data          Y-m-d
 * @return int|null agro_fenologia_estagios.id
 */
function vero_a1_fenologia_por_data(?int $safraTalhaoId, ?int $safraId, string $data): ?int
{
    if ($safraTalhaoId === null && $safraId === null) return null;
    if ($safraTalhaoId !== null && $safraId === null) {
        $safraId = (int)(vero_val(
            "SELECT safra_id FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t",
            [':i' => $safraTalhaoId, ':t' => vero_tenant()]) ?? 0) ?: null;
        if ($safraId === null) return null;
    }
    /* placeholders todos ÚNICOS — prepares nativos não aceitam repetição */
    $row = vero_row(
        "SELECT p.fenologia_estagio_id
           FROM agro_fenologia_periodos p
          WHERE p.tenant_id = :t AND p.safra_id = :s
            AND (p.safra_talhao_id <=> :st OR p.safra_talhao_id IS NULL)
            AND p.data_inicio <= :d1 AND p.data_fim >= :d2
          ORDER BY (p.safra_talhao_id <=> :st2) DESC, p.data_inicio DESC
          LIMIT 1",
        [':t' => vero_tenant(), ':s' => $safraId, ':d1' => $data, ':d2' => $data,
         ':st' => $safraTalhaoId, ':st2' => $safraTalhaoId]);
    return $row ? (int)$row['fenologia_estagio_id'] : null;
}

/* ============================================================
   FENOLOGIA POR VARIEDADE (Onda 2 / migration 157) — resolução por
   DIAS DESDE A PODA (dia 0). Hierarquia do gestor (15/07):
   VARIEDADE (versão aprovada vigente) > CULTURA (fallback antigo) > bloqueia.
   Estas funções são a camada "variedade"; retornam NULL quando não há
   fenologia aprovada p/ a variedade — o chamador então usa o fallback.
   ============================================================ */

/**
 * Fase da fenologia VIGENTE (maior versão 'aprovada') da variedade em N dias
 * desde a poda (dia 0). Retorna a linha de agro_variedade_fases (inclui
 * volume_mm_dia) ou NULL. Intervalo: dia_inicio <= dias < dia_fim (contíguo).
 */
function vero_a1_variedade_fase_por_dias(int $variedadeId, int $dias): ?array
{
    if ($variedadeId <= 0 || $dias < 0) return null;
    /* placeholders todos ÚNICOS (QA-011): :t/:t2, :v/:v2, :d/:d2 */
    return vero_row(
        "SELECT fa.*
           FROM agro_variedade_fases fa
           JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
          WHERE fa.tenant_id = :t AND fe.variedade_id = :v
            AND fe.status = 'aprovada' AND fe.ativo = 1 AND fa.ativo = 1
            AND fe.versao = (SELECT MAX(versao) FROM agro_variedade_fenologia
                              WHERE tenant_id = :t2 AND variedade_id = :v2
                                AND status = 'aprovada' AND ativo = 1)
            AND fa.dia_inicio <= :d AND fa.dia_fim > :d2
          ORDER BY fa.dia_inicio DESC LIMIT 1",
        [':t' => vero_tenant(), ':v' => $variedadeId, ':t2' => vero_tenant(),
         ':v2' => $variedadeId, ':d' => $dias, ':d2' => $dias]
    );
}

/* ============================================================
   FENOLOGIA VIVA DA SAFRA (módulo manga · mig 213) — instância EDITÁVEL
   das fases por vínculo safra×válvula (agro_safra_fases), materializada
   do template e ajustável/adiantável pelo RT. Resolução por DATA real.
   Camada acima do template: SÓ age quando existem linhas para o vínculo —
   safra de uva sem instância segue byte a byte o fluxo antigo.
   ============================================================ */

/**
 * Fase da INSTÂNCIA da safra na data (se o vínculo tiver fases materializadas).
 * Retorna a linha do TEMPLATE correspondente (agro_variedade_fases — mesmo
 * shape que os consumidores gravam via ['id']) + dias/variedade_id/instancia,
 * ou NULL quando não há instância cobrindo a data.
 */
function vero_a1_safra_fase_instancia(int $safraTalhaoId, string $data): ?array
{
    if ($safraTalhaoId <= 0) return null;
    $inst = vero_row(
        "SELECT sf.variedade_fase_id, sf.data_inicio
           FROM agro_safra_fases sf
          WHERE sf.tenant_id = :t AND sf.safra_talhao_id = :st AND sf.ativo = 1
            AND sf.data_inicio <= :d1 AND sf.data_fim >= :d2
          ORDER BY sf.ordem DESC LIMIT 1",
        [':t' => vero_tenant(), ':st' => $safraTalhaoId, ':d1' => $data, ':d2' => $data]);
    if (!$inst) return null;
    $fase = vero_row(
        "SELECT fa.*, fe.variedade_id
           FROM agro_variedade_fases fa
           JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
          WHERE fa.id = :i AND fa.tenant_id = :t",
        [':i' => (int)$inst['variedade_fase_id'], ':t' => vero_tenant()]);
    if (!$fase) return null;
    /* dias desde a poda continuam vindo da âncora real (p/ LMR etc.) */
    $dataPoda = vero_val(
        "SELECT COALESCE(st.data_poda, sa.data_inicio)
           FROM agro_safra_talhoes st
           JOIN agro_safras sa ON sa.id = st.safra_id AND sa.tenant_id = st.tenant_id
          WHERE st.id = :i AND st.tenant_id = :t",
        [':i' => $safraTalhaoId, ':t' => vero_tenant()]);
    $fase['dias'] = $dataPoda
        ? max(0, (int)floor((strtotime($data) - strtotime((string)$dataPoda)) / 86400))
        : (int)$fase['dia_inicio'];
    $fase['variedade_id'] = (int)$fase['variedade_id'];
    $fase['instancia'] = true;
    return $fase;
}

/**
 * Resolve a fase POR VARIEDADE a partir do vínculo safra×talhão (ou safra) e da
 * data: descobre a variedade do talhão e a data de poda (referência = início da
 * safra, até a Onda 5 formalizar a poda) → dias = data − poda → fase.
 * Hierarquia (mig 213): INSTÂNCIA da safra (fases editadas/adiantadas — manga)
 * > template da variedade por dias > NULL (chamador cai no fallback).
 * Retorna a fase (agro_variedade_fases, com volume_mm_dia) + 'dias'/'variedade_id'
 * no array, ou NULL (então o chamador cai no fallback por cultura/estágio).
 */
function vero_a1_fenologia_variedade_resolver(?int $safraTalhaoId, ?int $safraId, string $data): ?array
{
    if ($safraTalhaoId === null && $safraId === null) return null;

    /* fenologia viva da safra vence o template — só existe quando o RT
       materializou/ajustou as fases (manga); uva segue intacta */
    if ($safraTalhaoId !== null) {
        $inst = vero_a1_safra_fase_instancia($safraTalhaoId, $data);
        if ($inst !== null) return $inst;
    }

    $talhaoId = null;
    if ($safraTalhaoId !== null) {
        $st = vero_row(
            "SELECT safra_id, talhao_id FROM agro_safra_talhoes WHERE id = :i AND tenant_id = :t",
            [':i' => $safraTalhaoId, ':t' => vero_tenant()]);
        if ($st) { $safraId = (int)$st['safra_id']; $talhaoId = (int)$st['talhao_id']; }
    }
    if ($safraId === null) return null;

    /* dia 0 = data da PODA da válvula (Onda 5: último apontamento de poda, gravado
       em agro_safra_talhoes.data_poda ao confirmar). Fallback: início da safra. */
    $dataPoda = null;
    if ($safraTalhaoId) {
        $dataPoda = vero_val("SELECT data_poda FROM agro_safra_talhoes WHERE id = :i AND tenant_id = :t",
            [':i' => $safraTalhaoId, ':t' => vero_tenant()]);
    }
    if (!$dataPoda) {
        $dataPoda = vero_val("SELECT data_inicio FROM agro_safras WHERE id = :j AND tenant_id = :u",
            [':j' => $safraId, ':u' => vero_tenant()]);
    }
    if (!$dataPoda) return null;

    /* variedade: do talhão do vínculo; sem vínculo específico não há como saber */
    if ($talhaoId === null) return null;
    $variedadeId = (int)(vero_val("SELECT variedade_id FROM agro_talhoes WHERE id = :i AND tenant_id = :t",
        [':i' => $talhaoId, ':t' => vero_tenant()]) ?? 0);
    if ($variedadeId <= 0) return null;

    $dias = (int)floor((strtotime($data) - strtotime((string)$dataPoda)) / 86400);
    if ($dias < 0) return null;

    $fase = vero_a1_variedade_fase_por_dias($variedadeId, $dias);
    if ($fase === null) return null;
    $fase['dias'] = $dias;
    $fase['variedade_id'] = $variedadeId;
    return $fase;
}

/**
 * Períodos da safra para pré-preenchimento em JS.
 * @return array<int, array{st: ?int, feno: int, ini: string, fim: string}>
 */
function vero_a1_fenologia_periodos_js(): array
{
    $out = [];
    foreach (vero_rows(
        "SELECT p.safra_id, p.safra_talhao_id, p.fenologia_estagio_id, p.data_inicio, p.data_fim
           FROM agro_fenologia_periodos p
          WHERE p.tenant_id = :t
          ORDER BY p.safra_id, p.data_inicio", [':t' => vero_tenant()]) as $p) {
        $out[] = [
            'safra' => (int)$p['safra_id'],
            'st'    => $p['safra_talhao_id'] !== null ? (int)$p['safra_talhao_id'] : null,
            'feno'  => (int)$p['fenologia_estagio_id'],
            'ini'   => (string)$p['data_inicio'],
            'fim'   => (string)$p['data_fim'],
        ];
    }
    return $out;
}

/**
 * GATE da safra (Onda 2, decisão gestor 15/07): o bloco estrutural (cronograma,
 * atividades, orçamento, monitoramentos) só é liberado se TODA variedade da safra
 * tiver fenologia utilizável — hierarquia: variedade (versão APROVADA) > cultura
 * (fallback agro_fenologia_estagios) > NENHUMA ⇒ bloqueia.
 * @return array{ok: bool, motivo: string, pendentes: array<int,string>}
 */
function vero_a1_safra_fenologia_ok(int $safraId): array
{
    if ($safraId <= 0) return ['ok' => false, 'motivo' => 'Safra inválida.', 'pendentes' => []];
    $rows = vero_rows(
        "SELECT DISTINCT vr.id AS variedade_id, vr.nome AS variedade,
                (SELECT COUNT(*) FROM agro_variedade_fenologia fe
                   WHERE fe.tenant_id = vr.tenant_id AND fe.variedade_id = vr.id
                     AND fe.status = 'aprovada' AND fe.ativo = 1) AS tem_var,
                (SELECT COUNT(*) FROM agro_fenologia_estagios es
                   WHERE es.tenant_id = vr.tenant_id AND es.cultura_id = vr.cultura_id
                     AND es.ativo = 1) AS tem_cult
           FROM agro_safra_talhoes st
           JOIN agro_talhoes tl    ON tl.id = st.talhao_id    AND tl.tenant_id = st.tenant_id
           JOIN agro_variedades vr ON vr.id = tl.variedade_id AND vr.tenant_id = st.tenant_id
          WHERE st.safra_id = :s AND st.tenant_id = :t",
        [':s' => $safraId, ':t' => vero_tenant()]);

    if (!$rows) {
        return ['ok' => false, 'motivo' => 'Vincule ao menos uma válvula com variedade definida.', 'pendentes' => []];
    }
    $pendentes = [];
    foreach ($rows as $r) {
        if ((int)$r['tem_var'] === 0 && (int)$r['tem_cult'] === 0) {
            $pendentes[(int)$r['variedade_id']] = (string)$r['variedade'];
        }
    }
    if ($pendentes) {
        return ['ok' => false,
                'motivo' => 'Fenologia não cadastrada/aprovada para: ' . implode(', ', $pendentes) . '.',
                'pendentes' => $pendentes];
    }
    return ['ok' => true, 'motivo' => 'Fenologia OK (variedade ou cultura).', 'pendentes' => []];
}

/**
 * MATERIALIZA o template aprovado da variedade em fases com DATA para o
 * vínculo safra×válvula (mig 213). Idempotente: com linhas ativas, no-op.
 * Âncora do dia 0 = data_poda do vínculo (fallback: início da safra).
 * @return array{ok: bool, motivo: string}
 */
function vero_a1_safra_fases_materializar(int $safraTalhaoId): array
{
    $t = vero_tenant();
    if ($safraTalhaoId <= 0) return ['ok' => false, 'motivo' => 'Vínculo inválido.'];
    $ja = (int)vero_val("SELECT COUNT(*) FROM agro_safra_fases WHERE tenant_id = :t AND safra_talhao_id = :st AND ativo = 1",
        [':t' => $t, ':st' => $safraTalhaoId]);
    if ($ja > 0) return ['ok' => true, 'motivo' => 'Fases já materializadas.'];

    $st = vero_row(
        "SELECT st.safra_id, st.talhao_id, COALESCE(st.data_poda, sa.data_inicio) AS dia0
           FROM agro_safra_talhoes st
           JOIN agro_safras sa ON sa.id = st.safra_id AND sa.tenant_id = st.tenant_id
          WHERE st.id = :i AND st.tenant_id = :t",
        [':i' => $safraTalhaoId, ':t' => $t]);
    if (!$st || !$st['dia0']) return ['ok' => false, 'motivo' => 'Vínculo sem data de poda nem início de safra.'];

    $variedadeId = (int)(vero_val("SELECT variedade_id FROM agro_talhoes WHERE id = :i AND tenant_id = :t",
        [':i' => (int)$st['talhao_id'], ':t' => $t]) ?? 0);
    if ($variedadeId <= 0) return ['ok' => false, 'motivo' => 'Válvula sem variedade definida.'];

    $fases = vero_rows(
        "SELECT fa.id, fa.ordem, fa.nome, fa.dia_inicio, fa.dia_fim
           FROM agro_variedade_fases fa
           JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
          WHERE fa.tenant_id = :t AND fe.variedade_id = :v
            AND fe.status = 'aprovada' AND fe.ativo = 1 AND fa.ativo = 1
            AND fe.versao = (SELECT MAX(versao) FROM agro_variedade_fenologia
                              WHERE tenant_id = :t2 AND variedade_id = :v2
                                AND status = 'aprovada' AND ativo = 1)
          ORDER BY fa.dia_inicio",
        [':t' => $t, ':v' => $variedadeId, ':t2' => $t, ':v2' => $variedadeId]);
    if (!$fases) return ['ok' => false, 'motivo' => 'Variedade sem fenologia APROVADA — cadastre/aprove o template primeiro.'];

    $dia0 = strtotime((string)$st['dia0']);
    foreach ($fases as $i => $f) {
        vero_insert('agro_safra_fases', [
            'safra_id'          => (int)$st['safra_id'],
            'safra_talhao_id'   => $safraTalhaoId,
            'variedade_fase_id' => (int)$f['id'],
            'ordem'             => $i + 1,
            'nome'              => (string)$f['nome'],
            /* intervalo do template é [dia_inicio, dia_fim) → em datas vira
               [dia0+ini, dia0+fim-1] (contíguo, sem sobreposição) */
            'data_inicio'       => date('Y-m-d', $dia0 + (int)$f['dia_inicio'] * 86400),
            'data_fim'          => date('Y-m-d', $dia0 + max((int)$f['dia_inicio'], (int)$f['dia_fim'] - 1) * 86400),
            'origem'            => 'template',
        ]);
    }
    return ['ok' => true, 'motivo' => count($fases) . ' fases materializadas a partir do template.'];
}

/**
 * Fases do vínculo para EXIBIÇÃO (mig 213): se há instância, retorna as linhas
 * reais; senão, calcula uma PRÉVIA com datas a partir do template (mesma conta
 * da materialização) — a tela mostra sempre datas, sem expor a mecânica.
 * @return array{origem: string, fases: array<int, array>}  origem: instancia|template|nenhuma
 */
function vero_a1_safra_fases_do_vinculo(int $safraTalhaoId): array
{
    $t = vero_tenant();
    $fases = vero_rows(
        "SELECT * FROM agro_safra_fases
          WHERE tenant_id = :t AND safra_talhao_id = :st AND ativo = 1 ORDER BY ordem",
        [':t' => $t, ':st' => $safraTalhaoId]);
    if ($fases) return ['origem' => 'instancia', 'fases' => $fases];

    $st = vero_row(
        "SELECT st.talhao_id, COALESCE(st.data_poda, sa.data_inicio) AS dia0
           FROM agro_safra_talhoes st
           JOIN agro_safras sa ON sa.id = st.safra_id AND sa.tenant_id = st.tenant_id
          WHERE st.id = :i AND st.tenant_id = :t",
        [':i' => $safraTalhaoId, ':t' => $t]);
    if (!$st || !$st['dia0']) return ['origem' => 'nenhuma', 'fases' => []];
    $variedadeId = (int)(vero_val("SELECT variedade_id FROM agro_talhoes WHERE id = :i AND tenant_id = :t",
        [':i' => (int)$st['talhao_id'], ':t' => $t]) ?? 0);
    if ($variedadeId <= 0) return ['origem' => 'nenhuma', 'fases' => []];

    $tpl = vero_rows(
        "SELECT fa.id, fa.nome, fa.dia_inicio, fa.dia_fim
           FROM agro_variedade_fases fa
           JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
          WHERE fa.tenant_id = :t AND fe.variedade_id = :v
            AND fe.status = 'aprovada' AND fe.ativo = 1 AND fa.ativo = 1
            AND fe.versao = (SELECT MAX(versao) FROM agro_variedade_fenologia
                              WHERE tenant_id = :t2 AND variedade_id = :v2
                                AND status = 'aprovada' AND ativo = 1)
          ORDER BY fa.dia_inicio",
        [':t' => $t, ':v' => $variedadeId, ':t2' => $t, ':v2' => $variedadeId]);
    if (!$tpl) return ['origem' => 'nenhuma', 'fases' => []];

    $dia0 = strtotime((string)$st['dia0']);
    $out = [];
    foreach ($tpl as $i => $f) {
        $out[] = [
            'id'                => null,               /* prévia — sem linha gravada */
            'ordem'             => $i + 1,
            'nome'              => (string)$f['nome'],
            'variedade_fase_id' => (int)$f['id'],
            'data_inicio'       => date('Y-m-d', $dia0 + (int)$f['dia_inicio'] * 86400),
            'data_fim'          => date('Y-m-d', $dia0 + max((int)$f['dia_inicio'], (int)$f['dia_fim'] - 1) * 86400),
            'origem'            => 'template',
            'motivo'            => null,
        ];
    }
    return ['origem' => 'template', 'fases' => $out];
}

/**
 * REVERTE o vínculo ao template (desfaz a materialização/ajustes). Delete
 * físico: a UNIQUE (tenant, st, ordem) impede re-materializar sobre linha
 * inativa — e a instância é dado derivado, recriável do template.
 * @return array{ok: bool, motivo: string}
 */
function vero_a1_safra_fases_reverter(int $safraTalhaoId): array
{
    $n = 0;
    if ($safraTalhaoId > 0) {
        $stmt = vero_pdo()->prepare("DELETE FROM agro_safra_fases WHERE tenant_id = ? AND safra_talhao_id = ?");
        $stmt->execute([vero_tenant(), $safraTalhaoId]);
        $n = $stmt->rowCount();
    }
    return $n > 0
        ? ['ok' => true, 'motivo' => 'Fases da safra revertidas ao template da variedade (' . $n . ' linha(s) removida(s)).']
        : ['ok' => false, 'motivo' => 'Este vínculo já segue o template.'];
}

/**
 * ADIANTA/ALTERA uma fase da instância (mig 213 — requisito da manga): a fase
 * alvo passa a começar em $novaDataInicio; a anterior encerra na véspera; as
 * SEGUINTES deslocam pelo mesmo delta (durações preservadas). Trilha motivo.
 * @return array{ok: bool, motivo: string}
 */
function vero_a1_safra_fase_ajustar(int $safraTalhaoId, int $faseId, string $novaDataInicio, string $motivo): array
{
    $t = vero_tenant();
    $alvo = vero_row(
        "SELECT * FROM agro_safra_fases WHERE id = :i AND tenant_id = :t AND safra_talhao_id = :st AND ativo = 1",
        [':i' => $faseId, ':t' => $t, ':st' => $safraTalhaoId]);
    if (!$alvo) return ['ok' => false, 'motivo' => 'Fase inválida para este vínculo.'];
    $ini = strtotime($novaDataInicio);
    if ($ini === false) return ['ok' => false, 'motivo' => 'Data inválida.'];

    $delta = (int)floor(($ini - strtotime((string)$alvo['data_inicio'])) / 86400);
    if ($delta === 0) return ['ok' => true, 'motivo' => 'Nada a ajustar — a fase já começa nessa data.'];

    /* anterior: encerra na véspera do novo início (não pode inverter) */
    $ant = vero_row(
        "SELECT * FROM agro_safra_fases
          WHERE tenant_id = :t AND safra_talhao_id = :st AND ativo = 1 AND ordem = :o",
        [':t' => $t, ':st' => $safraTalhaoId, ':o' => (int)$alvo['ordem'] - 1]);
    if ($ant && strtotime((string)$ant['data_inicio']) >= $ini) {
        return ['ok' => false, 'motivo' => 'A nova data invade a fase anterior (' . $ant['nome'] . ') — ajuste-a primeiro.'];
    }
    if ($ant) {
        vero_update('agro_safra_fases', (int)$ant['id'], [
            'data_fim' => date('Y-m-d', $ini - 86400),
            'origem'   => 'ajuste',
        ]);
    }
    /* alvo + seguintes deslocam pelo delta (durações preservadas) */
    $seq = vero_rows(
        "SELECT * FROM agro_safra_fases
          WHERE tenant_id = :t AND safra_talhao_id = :st AND ativo = 1 AND ordem >= :o
          ORDER BY ordem",
        [':t' => $t, ':st' => $safraTalhaoId, ':o' => (int)$alvo['ordem']]);
    foreach ($seq as $f) {
        vero_update('agro_safra_fases', (int)$f['id'], [
            'data_inicio' => date('Y-m-d', strtotime((string)$f['data_inicio']) + $delta * 86400),
            'data_fim'    => date('Y-m-d', strtotime((string)$f['data_fim']) + $delta * 86400),
            'origem'      => 'ajuste',
            'motivo'      => mb_substr($motivo, 0, 255) ?: null,
        ]);
    }
    return ['ok' => true, 'motivo' => 'Fase "' . $alvo['nome'] . '" ' . ($delta < 0 ? 'adiantada' : 'adiada')
        . ' em ' . abs($delta) . ' dia(s); ' . (count($seq) - 1) . ' fase(s) seguinte(s) deslocada(s).'];
}
