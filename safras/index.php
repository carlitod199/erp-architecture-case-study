<?php
/* ============================================================
   VERO — Gestão Agrícola / Safras (Ciclos)  (CRUD real)
   Substitui a tela mock. Rota da matriz: /safras/index.php
   Inclui o vínculo Safra × Válvula (agro_safra_talhoes): válvula,
   cultura, área plantada e produtividade planejada — a amarração
   central do sistema (custeio, colheita e dashboards dependem dela).
   Guard: agricola.safras | Escrita: agro.safras.editar/excluir
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../agro/_calc_mo_gaps.php'; /* A1-48c: gap de produção → MO p/ fechar */

const T  = 'agro_safras';
const TV = 'agro_safra_talhoes';

/* ── V-06 / D-2: cultura de UVA (condiciona os campos caixas/cachos/peso) ──
   Não há flag estruturada de "uva" no schema (agro_culturas só tem nome). O
   projeto já identifica uva por NOME em outros pontos (dashboard operacional:
   uva/videira/parreira). Mantemos a MESMA heurística para não poluir manga/
   outras culturas com os campos de raleio/embalamento da uva. */
$ehUva = static fn(?string $n): bool => $n !== null && (bool)preg_match('/uva|videira|parreira/i', $n);

/* ── Fim previsto sugerido (item 2a/2b) ──────────────────────────
   data_inicio + MAIOR ciclo entre as variedades vinculadas à safra.
   Ciclo = COALESCE(ciclo_dias, ciclo_poda_colheita_dias). Decisão do
   gestor: o maior ciclo manda (safra ativa enquanto ≥1 variedade em
   andamento). Retorna null se não houver válvula/variedade com ciclo
   — nesse caso não sugere nada e não trava o salvamento. HY093-safe. */
function safra_fim_sugerido(int $safraId): ?string
{
    if (!$safraId) return null;
    $ini = vero_val("SELECT data_inicio FROM " . T . " WHERE id=:s AND tenant_id=:t",
        [':s' => $safraId, ':t' => vero_tenant()]);
    if (!$ini) return null;
    /* MAX(ciclo) entre agro_safra_talhoes → agro_talhoes.variedade_id → agro_variedades */
    $max = vero_val(
        "SELECT MAX(COALESCE(vr.ciclo_dias, vr.ciclo_poda_colheita_dias))
           FROM " . TV . " st
           JOIN agro_talhoes    tl ON tl.id = st.talhao_id   AND tl.tenant_id = st.tenant_id
           JOIN agro_variedades vr ON vr.id = tl.variedade_id AND vr.tenant_id = st.tenant_id
          WHERE st.safra_id = :s AND st.tenant_id = :t",
        [':s' => $safraId, ':t' => vero_tenant()]);
    if ($max === null || (int)$max <= 0) return null;
    $dt = date_create((string)$ini);
    if (!$dt) return null;
    $dt->modify('+' . (int)$max . ' days');
    return $dt->format('Y-m-d');
}

/* ── D-09: conflito de safras ATIVAS sobrepostas ─────────────────
   Dada a safra recém-salva, lista outras safras ATIVAS que:
     (a) se sobrepõem no período  [data_inicio .. COALESCE(data_fim,
         data_fim_prevista, aberto)]  E
     (b) concorrem no mesmo escopo — mesma fazenda, uma delas de escopo
         "Todas" (fazenda_id IS NULL), OU compartilham ao menos uma
         válvula (agro_safra_talhoes).
   Risco alertado: duplo cômputo de custos/produção. Não bloqueia — só
   avisa (padrão do projeto p/ regras de negócio, ver encerramento). */
function safra_conflitos_ativas(int $safraId): array
{
    if (!$safraId) return [];
    return vero_rows(
        "SELECT s2.id, s2.identificacao,
                (s1.fazenda_id IS NULL) AS s1_todas,
                (s2.fazenda_id IS NULL) AS s2_todas,
                EXISTS(SELECT 1 FROM " . TV . " a
                        JOIN " . TV . " b ON b.talhao_id = a.talhao_id AND b.tenant_id = a.tenant_id
                       WHERE a.tenant_id = s1.tenant_id AND a.safra_id = s1.id AND b.safra_id = s2.id) AS compartilha_valvula
           FROM " . T . " s1
           JOIN " . T . " s2 ON s2.tenant_id = s1.tenant_id AND s2.id <> s1.id AND s2.status = 'ativa'
          WHERE s1.id = :s AND s1.tenant_id = :t
            AND s1.data_inicio <= COALESCE(s2.data_fim, s2.data_fim_prevista, '9999-12-31')
            AND s2.data_inicio <= COALESCE(s1.data_fim, s1.data_fim_prevista, '9999-12-31')
            AND (s1.fazenda_id IS NULL OR s2.fazenda_id IS NULL OR s1.fazenda_id = s2.fazenda_id
                 OR EXISTS(SELECT 1 FROM " . TV . " a
                            JOIN " . TV . " b ON b.talhao_id = a.talhao_id AND b.tenant_id = a.tenant_id
                           WHERE a.tenant_id = s1.tenant_id AND a.safra_id = s1.id AND b.safra_id = s2.id))
          ORDER BY s2.data_inicio",
        [':s' => $safraId, ':t' => vero_tenant()]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    /* ── Safra: criar/editar ── */
    if ($acao === 'salvar') {
        vero_require('agro.safras.editar');

        $id    = vero_int('id');
        $ident = vero_str('identificacao', 20);
        $ini   = vero_date('data_inicio');
        if ($ident === null || $ini === null) {
            vero_flash('erro', 'Identificação e data de início são obrigatórias.');
            vero_redirect();
        }
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND identificacao=:i AND id<>:id",
            [':t' => vero_tenant(), ':i' => $ident, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe a safra \"{$ident}\".");
            vero_redirect();
        }

        $status = in_array($_POST['status'] ?? '', ['planejada', 'ativa', 'encerrada'], true)
            ? $_POST['status'] : 'planejada';

        /* status anterior — para detectar a transição para "encerrada" */
        $statusAntes = $id
            ? (string)(vero_val("SELECT status FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => (int)$id, ':t' => vero_tenant()]) ?? '')
            : '';

        $data = [
            'identificacao'     => $ident,
            'fazenda_id'        => vero_int('fazenda_id'),
            'data_inicio'       => $ini,
            'data_fim_prevista' => vero_date('data_fim_prevista'),
            'data_fim'          => $status === 'encerrada' ? (vero_date('data_fim') ?? date('Y-m-d')) : vero_date('data_fim'),
            'status'            => $status,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', "Safra \"{$ident}\" atualizada.");
        } else {
            $id = vero_insert(T, $data);
            /* INVERSÃO DO FLUXO (gestor 17/07): as válvulas são escolhidas JÁ na
               criação da safra — cria os vínculos aqui. A cultura vem da variedade
               da válvula (cultura_id é NOT NULL no vínculo); válvula sem variedade
               é ignorada com aviso. Painel de vincular/desvincular segue para ajuste. */
            $sel = array_values(array_unique(array_map('intval', (array)($_POST['talhoes'] ?? []))));
            $vinc = 0; $semCult = [];
            foreach ($sel as $tid) {
                if ($tid <= 0) continue;
                $tl = vero_row(
                    "SELECT t.id, t.codigo, t.area_ha,
                            v.cultura_id, v.produtividade_esperada, v.unidade_produtividade
                       FROM agro_talhoes t
                       LEFT JOIN agro_variedades v ON v.id = t.variedade_id AND v.tenant_id = t.tenant_id
                      WHERE t.id = :i AND t.tenant_id = :t AND t.ativo = 1",
                    [':i' => $tid, ':t' => vero_tenant()]);
                if (!$tl) continue;
                if ($tl['cultura_id'] === null) { $semCult[] = (string)$tl['codigo']; continue; }
                $ja = vero_val("SELECT id FROM " . TV . " WHERE tenant_id=:t AND safra_id=:s AND talhao_id=:tl",
                    [':t' => vero_tenant(), ':s' => (int)$id, ':tl' => $tid]);
                if ($ja) continue;
                $unid = in_array($tl['unidade_produtividade'] ?? '', ['sacas_ha','t_ha','arroba_ha','kg_ha','litros_ha'], true)
                    ? $tl['unidade_produtividade'] : 'kg_ha';
                vero_insert(TV, [
                    'safra_id'                => (int)$id,
                    'talhao_id'               => $tid,
                    'cultura_id'              => (int)$tl['cultura_id'],
                    'area_plantada_ha'        => (float)$tl['area_ha'],
                    'produtividade_planejada' => $tl['produtividade_esperada'] !== null ? (float)$tl['produtividade_esperada'] : null,
                    'unidade_produtividade'   => $unid,
                ]);
                $vinc++;
            }
            /* fim previsto sugerido com base nas variedades vinculadas (item 2b) */
            $fimSug = safra_fim_sugerido((int)$id);
            if ($fimSug !== null) vero_update(T, (int)$id, ['data_fim_prevista' => $fimSug]);

            $msg = "Safra \"{$ident}\" criada" . ($vinc > 0
                ? " com {$vinc} válvula(s) vinculada(s)."
                : '. Vincule as válvulas no painel abaixo.');
            if ($semCult) $msg .= ' Ignoradas por não ter variedade/cultura: ' . implode(', ', $semCult) . '.';
            vero_flash('ok', $msg);
        }

        /* Aviso NÃO-bloqueante ao encerrar (pendências operacionais).
           A trava formal de lançamento retroativo é decisão P-06 (A3). */
        if ($status === 'encerrada' && $statusAntes !== 'encerrada') {
            $ativAbertas = (int)vero_val(
                "SELECT COUNT(*) FROM agro_atividades
                  WHERE tenant_id=:t AND safra_id=:s AND status IN ('planejada','em_execucao')",
                [':t' => vero_tenant(), ':s' => (int)$id]);
            $semColheita = (int)vero_val(
                "SELECT COUNT(*) FROM " . TV . " v
                  WHERE v.tenant_id=:t AND v.safra_id=:s
                    AND NOT EXISTS (SELECT 1 FROM colheita_registros cr
                                     WHERE cr.tenant_id = v.tenant_id AND cr.safra_talhao_id = v.id)",
                [':t' => vero_tenant(), ':s' => (int)$id]);
            $avisos = [];
            if ($ativAbertas > 0) $avisos[] = "{$ativAbertas} atividade(s) planejada(s)/em execução";
            if ($semColheita > 0) $avisos[] = "{$semColheita} válvula(ões) vinculado(s) sem colheita registrada";
            if ($avisos) {
                vero_flash('aviso', 'Safra encerrada com pendências: ' . implode(' e ', $avisos)
                    . '. O encerramento não bloqueia lançamentos (trava formal pendente de validação — P-06).');
            }
        }

        /* D-09: safra ATIVA sobreposta a outra(s) ativa(s) no mesmo escopo
           → alerta não-bloqueante (risco de duplo cômputo). */
        if ($status === 'ativa') {
            $conf = safra_conflitos_ativas((int)$id);
            if ($conf) {
                $temTodas = false; $nomes = [];
                foreach ($conf as $c) {
                    $nomes[] = (string)$c['identificacao'] . ((int)$c['compartilha_valvula'] ? ' (válvula em comum)' : '');
                    if ((int)$c['s1_todas'] || (int)$c['s2_todas']) $temTodas = true;
                }
                $msg = 'Atenção: esta safra ativa se sobrepõe no período a ' . count($conf)
                    . ' outra(s) safra(s) ativa(s): ' . implode(', ', $nomes) . '.';
                if ($temTodas) {
                    $msg .= ' Uma delas tem escopo "Todas as fazendas", concorrendo com safras específicas'
                        . ' no mesmo período — risco de duplo cômputo de custos/produção.';
                }
                $msg .= ' Revise períodos/escopo (ou encerre a anterior) para evitar duplicidade.';
                vero_flash('aviso', $msg);
            }
        }
        vero_redirect('?safra=' . $id);
    }

    /* ── Rolar safra: copiar vínculos de outra safra ── */
    if ($acao === 'rolar') {
        vero_require('agro.safras.editar');

        $safraId  = vero_int('safra_id');
        $origemId = vero_int('origem_safra_id');
        if (!$safraId || !$origemId || $safraId === $origemId) {
            vero_flash('erro', 'Escolha uma safra de origem diferente da atual.');
            vero_redirect('?safra=' . (int)$safraId);
        }
        $okDest = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $safraId,  ':t' => vero_tenant()]);
        $okOrig = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $origemId, ':t' => vero_tenant()]);
        if (!$okDest || !$okOrig) {
            vero_flash('erro', 'Safra inválida.');
            vero_redirect('?safra=' . (int)$safraId);
        }

        $origemVinculos = vero_rows(
            "SELECT v.talhao_id, v.cultura_id, v.area_plantada_ha, v.produtividade_planejada, v.unidade_produtividade
               FROM " . TV . " v
               JOIN agro_talhoes tl ON tl.id = v.talhao_id
              WHERE v.tenant_id = :t AND v.safra_id = :s AND tl.ativo = 1",
            [':t' => vero_tenant(), ':s' => $origemId]);

        $copiados = 0; $pulados = 0;
        foreach ($origemVinculos as $v) {
            $ja = vero_val("SELECT id FROM " . TV . " WHERE tenant_id=:t AND safra_id=:s AND talhao_id=:tl",
                [':t' => vero_tenant(), ':s' => $safraId, ':tl' => (int)$v['talhao_id']]);
            if ($ja) { $pulados++; continue; }
            vero_insert(TV, [
                'safra_id'                => $safraId,
                'talhao_id'               => (int)$v['talhao_id'],
                'cultura_id'              => (int)$v['cultura_id'],
                'area_plantada_ha'        => $v['area_plantada_ha'],
                'produtividade_planejada' => $v['produtividade_planejada'],
                'unidade_produtividade'   => $v['unidade_produtividade'],
            ]);
            $copiados++;
        }
        if ($copiados === 0 && $pulados === 0) {
            vero_flash('aviso', 'A safra de origem não tem vínculos com válvulas ativas para copiar.');
        } else {
            vero_flash('ok', "Rolagem concluída: {$copiados} vínculo(s) copiado(s)"
                . ($pulados > 0 ? " ({$pulados} já existia(m) e foi(ram) mantido(s))" : '')
                . '. Revise áreas e metas de produtividade.');
        }
        vero_redirect('?safra=' . $safraId);
    }

    /* ── Safra: excluir (bloqueia se houver vínculos) ── */
    if ($acao === 'excluir') {
        vero_require('agro.safras.excluir');
        $id = vero_int('id');
        if ($id) {
            $vinc = (int)vero_val("SELECT COUNT(*) FROM " . TV . " WHERE tenant_id=:t AND safra_id=:s",
                [':t' => vero_tenant(), ':s' => $id]);
            if ($vinc > 0) {
                vero_flash('erro', "A safra possui {$vinc} válvula(ões) vinculado(s). Remova os vínculos antes de excluir.");
            } else {
                vero_delete(T, $id); // sem coluna ativo → DELETE protegido por FK
            }
        }
        vero_redirect();
    }

    /* ── Vínculo Safra × Válvula: adicionar ── */
    if ($acao === 'vincular') {
        vero_require('agro.safras.editar');

        $safraId   = vero_int('safra_id');
        $talhaoId  = vero_int('talhao_id');
        $culturaId = vero_int('cultura_id');

        if (!$safraId || !$talhaoId || !$culturaId) {
            vero_flash('erro', 'Válvula e cultura são obrigatórios no vínculo.');
            vero_redirect('?safra=' . (int)$safraId);
        }
        /* tudo do tenant */
        $okS = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $safraId, ':t' => vero_tenant()]);
        $okT = vero_row("SELECT id, area_ha FROM agro_talhoes WHERE id=:i AND tenant_id=:t", [':i' => $talhaoId, ':t' => vero_tenant()]);
        $okC = vero_val("SELECT id FROM agro_culturas WHERE id=:i AND tenant_id=:t", [':i' => $culturaId, ':t' => vero_tenant()]);
        if (!$okS || !$okT || !$okC) {
            vero_flash('erro', 'Vínculo inválido.');
            vero_redirect('?safra=' . (int)$safraId);
        }
        $dup = vero_val("SELECT id FROM " . TV . " WHERE tenant_id=:t AND safra_id=:s AND talhao_id=:tl",
            [':t' => vero_tenant(), ':s' => $safraId, ':tl' => $talhaoId]);
        if ($dup) {
            vero_flash('erro', 'Esta válvula já está vinculada a esta safra.');
            vero_redirect('?safra=' . $safraId);
        }

        $unid = in_array($_POST['unidade_produtividade'] ?? '', ['sacas_ha','t_ha','arroba_ha','kg_ha','litros_ha'], true)
            ? $_POST['unidade_produtividade'] : 'kg_ha';

        /* A11: área plantada e produtividade planejada nunca são negativas */
        $areaPlant = vero_dec('area_plantada_ha') ?? (float)$okT['area_ha'];
        $prodPlan  = vero_dec('produtividade_planejada');
        if ($areaPlant < 0 || ($prodPlan !== null && $prodPlan < 0)) {
            vero_flash('erro', 'Área plantada e produtividade planejada não podem ser negativas.');
            vero_redirect();
        }

        /* V-06 / D-2: parâmetros de colheita/raleio da SAFRA (só fazem sentido
           na uva; para outras culturas os campos ficam ocultos → chegam vazios →
           NULL → fallback na variedade/cultura). Nunca negativos. */
        $cachosPl = vero_dec('cachos_por_planta');
        $caixasPl = vero_dec('caixas_por_planta');
        $pesoCx   = vero_dec('peso_caixa_kg');
        if (($cachosPl !== null && $cachosPl < 0) || ($caixasPl !== null && $caixasPl < 0) || ($pesoCx !== null && $pesoCx < 0)) {
            vero_flash('erro', 'Cachos/caixas por planta e peso da caixa não podem ser negativos.');
            vero_redirect('?safra=' . $safraId);
        }

        vero_insert(TV, [
            'safra_id'                => $safraId,
            'talhao_id'               => $talhaoId,
            'cultura_id'              => $culturaId,
            'area_plantada_ha'        => $areaPlant,
            'produtividade_planejada' => $prodPlan,
            'unidade_produtividade'   => $unid,
            'cachos_por_planta'       => $cachosPl,
            'caixas_por_planta'       => $caixasPl,
            'peso_caixa_kg'           => $pesoCx,
        ]);
        /* item 2b: recalcular o fim previsto sugerido ao mudar as válvulas
           (sugestão editável — sobrescreve enquanto houver ciclo calculável) */
        $fimSug = safra_fim_sugerido($safraId);
        if ($fimSug !== null) vero_update(T, $safraId, ['data_fim_prevista' => $fimSug]);

        vero_flash('ok', 'Válvula vinculado à safra.');
        vero_redirect('?safra=' . $safraId);
    }

    /* ── Vínculo Safra × Válvula: remover ── */
    if ($acao === 'desvincular') {
        vero_require('agro.safras.editar');
        $vid     = vero_int('id');
        $safraId = vero_int('safra_id');
        if ($vid) {
            try {
                $st = vero_pdo()->prepare("DELETE FROM " . TV . " WHERE id=:i AND tenant_id=:t LIMIT 1");
                $st->execute([':i' => $vid, ':t' => vero_tenant()]);
                /* item 2b: recalcular o fim previsto após remover válvula.
                   Se não sobrar variedade com ciclo, mantém o valor atual
                   (pode ser manual) — não apaga. */
                $fimSug = safra_fim_sugerido($safraId);
                if ($fimSug !== null) vero_update(T, $safraId, ['data_fim_prevista' => $fimSug]);
                vero_flash('ok', 'Vínculo removido.');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    vero_flash('erro', 'Não é possível remover: já existem apontamentos/colheitas nesta válvula-safra.');
                } else { throw $e; }
            }
        }
        vero_redirect('?safra=' . (int)$safraId);
    }

    /* ── V-06 / D-2: editar parâmetros de colheita/raleio de UMA válvula-safra ──
       Grava caixas/cachos por planta + peso da caixa no vínculo (agro_safra_talhoes).
       Vazio = NULL → volta a usar o fallback da variedade/cultura. */
    if ($acao === 'params_valvula') {
        vero_require('agro.safras.editar');
        $vid     = vero_int('id');
        $safraId = vero_int('safra_id');
        $vinc = $vid ? vero_row("SELECT id FROM " . TV . " WHERE id=:i AND tenant_id=:t",
            [':i' => $vid, ':t' => vero_tenant()]) : null;
        if (!$vinc) {
            vero_flash('erro', 'Vínculo inválido.');
            vero_redirect('?safra=' . (int)$safraId);
        }
        $cachosPl = vero_dec('cachos_por_planta');
        $caixasPl = vero_dec('caixas_por_planta');
        $pesoCx   = vero_dec('peso_caixa_kg');
        if (($cachosPl !== null && $cachosPl < 0) || ($caixasPl !== null && $caixasPl < 0) || ($pesoCx !== null && $pesoCx < 0)) {
            vero_flash('erro', 'Cachos/caixas por planta e peso da caixa não podem ser negativos.');
            vero_redirect('?safra=' . (int)$safraId);
        }
        vero_update(TV, (int)$vinc['id'], [
            'cachos_por_planta' => $cachosPl,
            'caixas_por_planta' => $caixasPl,
            'peso_caixa_kg'     => $pesoCx,
        ]);
        vero_flash('ok', 'Parâmetros de colheita/raleio da válvula atualizados.');
        vero_redirect('?safra=' . (int)$safraId);
    }
}

/* ── Listagem de safras ─────────────────────────────────────── */
$q    = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 15;

$where  = "s.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    $where .= " AND s.identificacao LIKE :q";
    $params[':q'] = "%{$q}%";
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " s WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT s.*, f.nome AS fazenda_nome,
            (SELECT COUNT(*) FROM " . TV . " v WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id)          AS talhoes,
            /* X-03: na uva cada válvula é uma safra — mostrar o
               CÓDIGO da válvula, não só a contagem. */
            (SELECT GROUP_CONCAT(tl.codigo ORDER BY tl.codigo SEPARATOR ', ')
               FROM " . TV . " v JOIN agro_talhoes tl ON tl.id = v.talhao_id AND tl.tenant_id = v.tenant_id
              WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id)                                            AS valvulas_cods,
            (SELECT COALESCE(SUM(v.area_plantada_ha),0) FROM " . TV . " v
              WHERE v.tenant_id = s.tenant_id AND v.safra_id = s.id)                                            AS area_plantada
       FROM " . T . " s
  LEFT JOIN agro_fazendas f ON f.id = s.fazenda_id
      WHERE {$where}
      ORDER BY s.data_inicio DESC
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$fazendas = vero_options('agro_fazendas', 'nome', 'ativo = 1');
$culturas = vero_options('agro_culturas', 'nome', 'ativo = 1');
/* V-06 / D-2: mapa cultura_id => é uva? (condiciona os campos no cliente) */
$culturaUva = [];
foreach ($culturas as $cid => $cn) $culturaUva[(int)$cid] = $ehUva((string)$cn) ? 1 : 0;
$talhoesOpt = [];
$talhaoRef  = []; // referência da variedade da válvula p/ pré-preencher o vínculo (A1-14)
foreach (vero_rows(
    "SELECT t.id, CONCAT(f.nome, ' — ', t.codigo) AS label,
            v.cultura_id, v.produtividade_esperada, v.unidade_produtividade
       FROM agro_talhoes t
       JOIN agro_fazendas f ON f.id = t.fazenda_id
       LEFT JOIN agro_variedades v ON v.id = t.variedade_id
      WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo",
    [':t' => vero_tenant()]
) as $r) {
    $talhoesOpt[(int)$r['id']] = (string)$r['label'];
    if ($r['cultura_id'] !== null || $r['produtividade_esperada'] !== null) {
        $talhaoRef[(int)$r['id']] = [
            'cultura' => $r['cultura_id'] !== null ? (int)$r['cultura_id'] : null,
            'meta'    => $r['produtividade_esperada'] !== null ? (float)$r['produtividade_esperada'] : null,
            'unidade' => $r['unidade_produtividade'],
        ];
    }
}

/* Safra em edição / painel de válvulas (?safra=ID) */
$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
        [':i' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}
$panel = null;
$panelVinculos = [];
$panelResumo = null;
$panelTemUva = false;
$safrasOrigemOpt = [];
if (!empty($_GET['safra'])) {
    $panel = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
        [':i' => (int)$_GET['safra'], ':t' => vero_tenant()]);
    if ($panel) {
        $panelVinculos = vero_rows(
            "SELECT v.*, tl.codigo AS talhao_codigo, tl.num_plantas AS talhao_num_plantas,
                    f.nome AS fazenda_nome, c.nome AS cultura_nome
               FROM " . TV . " v
               JOIN agro_talhoes  tl ON tl.id = v.talhao_id
               JOIN agro_fazendas f  ON f.id = tl.fazenda_id
               JOIN agro_culturas c  ON c.id = v.cultura_id
              WHERE v.tenant_id = :t AND v.safra_id = :s
              ORDER BY f.nome, tl.codigo",
            [':t' => vero_tenant(), ':s' => (int)$panel['id']]
        );
        /* V-06 / D-2: a safra tem alguma válvula de uva? → mostra a coluna de
           parâmetros de colheita/raleio (senão a tabela não é poluída). */
        $panelTemUva = false;
        foreach ($panelVinculos as $pv) { if ($ehUva((string)($pv['cultura_nome'] ?? ''))) { $panelTemUva = true; break; } }

        /* ── Resumo executivo (somente leitura) ──
           Estimado convertido para kg apenas quando a meta está em kg/ha
           ou t/ha (conversão inequívoca); demais unidades são contadas à
           parte para não inventar fatores. Custo = custeio_lancamentos da
           safra; faturamento/vendas = leitura (detalhe no Resultado da Safra). */
        $pid = (int)$panel['id'];
        $pt  = [':t' => vero_tenant(), ':s' => $pid];
        $panelResumo = [
            'area'      => 0.0, 'estimado_kg' => 0.0, 'metas_nao_convertidas' => 0, 'sem_meta' => 0, 'plantas' => 0.0,
            'kg_realizado' => (float)(vero_val(
                "SELECT COALESCE(SUM(kg_total_realizado),0) FROM colheita_registros
                  WHERE tenant_id=:t AND safra_id=:s", $pt) ?? 0),
            'custo' => (float)(vero_val(
                "SELECT COALESCE(SUM(valor),0) FROM custeio_lancamentos
                  WHERE tenant_id=:t AND safra_id=:s", $pt) ?? 0),
            'vendas' => (float)(vero_val(
                "SELECT COALESCE(SUM(valor_total),0) FROM comercial_vendas
                  WHERE tenant_id=:t AND safra_id=:s AND status <> 'cancelada'", $pt) ?? 0),
        ];
        foreach ($panelVinculos as $v) {
            $panelResumo['area'] += (float)$v['area_plantada_ha'];
            $panelResumo['plantas'] += (float)($v['talhao_num_plantas'] ?? 0); // item 2.2: total de plantas da safra (Σ das válvulas)
            if ($v['produtividade_planejada'] === null) { $panelResumo['sem_meta']++; continue; }
            $meta = (float)$v['produtividade_planejada'];
            if ($v['unidade_produtividade'] === 'kg_ha') {
                $panelResumo['estimado_kg'] += (float)$v['area_plantada_ha'] * $meta;
            } elseif ($v['unidade_produtividade'] === 't_ha') {
                $panelResumo['estimado_kg'] += (float)$v['area_plantada_ha'] * $meta * 1000;
            } else {
                $panelResumo['metas_nao_convertidas']++;
            }
        }

        /* safras de origem para "rolar" (todas menos a atual) */
        foreach (vero_rows(
            "SELECT id, identificacao FROM " . T . "
              WHERE tenant_id = :t AND id <> :s ORDER BY data_inicio DESC",
            $pt) as $so) { $safrasOrigemOpt[(int)$so['id']] = (string)$so['identificacao']; }
    }
}

/* ── Identificação sugerida (item 2c) — AAAA.S-NN ────────────────
   Sequencial NN por tenant dentro de cada prefixo AAAA.S (ano+semestre
   pela data_inicio). Levanta o MAX(NN) já existente por prefixo; a
   sugestão é editável e a unicidade real segue garantida pelo NN + a
   checagem de duplicidade no salvamento. */
$identSeq = []; // 'AAAA.S' => maior NN já usado
foreach (vero_rows("SELECT identificacao FROM " . T . " WHERE tenant_id=:t", [':t' => vero_tenant()]) as $r) {
    if (preg_match('/^(\d{4}\.[12])-(\d{2,})$/', (string)$r['identificacao'], $m)) {
        $p = $m[1]; $n = (int)$m[2];
        if (!isset($identSeq[$p]) || $n > $identSeq[$p]) $identSeq[$p] = $n;
    }
}
/* sugestão default para NOVA safra (baseada em hoje; o JS reajusta o
   prefixo quando o usuário informa a data de início). */
$hojeD = date_create('today');
$prefHoje = $hojeD->format('Y') . '.' . ((int)$hojeD->format('n') <= 6 ? '1' : '2');
$identSugestao = $prefHoje . '-' . str_pad((string)(($identSeq[$prefHoje] ?? 0) + 1), 2, '0', STR_PAD_LEFT);

/* Valores do modal (edição): identificação mantém o existente; fim
   previsto pré-preenche a sugestão só quando estiver em branco. */
$identVal = $edit['identificacao'] ?? $identSugestao;
$fimVal   = $edit['data_fim_prevista'] ?? '';
if ($edit && ($edit['data_fim_prevista'] === null || $edit['data_fim_prevista'] === '')) {
    $s = safra_fim_sugerido((int)$edit['id']);
    if ($s !== null) $fimVal = $s;
}

$statusBadge = function (string $s): string {
    return match ($s) {
        'ativa'     => '<span class="vbadge vb-ok">Ativa</span>',
        'encerrada' => '<span class="vbadge vb-off">Encerrada</span>',
        default     => '<span class="vbadge vb-warn">Planejada</span>',
    };
};

$GUARD      = ['macro' => 'agricola', 'micro' => 'safras'];
$PAGE_VIEW  = 'safras';
$PAGE_TITLE = 'Safras';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.safras.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Safras / Ciclos', 'Ciclos produtivos e vínculo com válvulas e culturas (suporta 1–2 safras por ano)',
        $podeEditar ? '+ Nova safra' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por identificação (ex.: 2026.1)…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma safra cadastrada. <?= $podeEditar ? 'Crie a primeira, ex.: “2026.1”.' : '' ?></div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Identificação</th><th>Fazenda</th><th>Início</th><th>Fim previsto</th>
        <th>Válvulas</th><th style="text-align:right">Área plantada (ha)</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong class="vnum"><?= h($r['identificacao']) ?></strong></td>
          <td><?= h($r['fazenda_nome'] ?? 'Todas') ?></td>
          <td class="vnum"><?= dateBR($r['data_inicio']) ?></td>
          <td class="vnum"><?= $r['data_fim_prevista'] ? dateBR($r['data_fim_prevista']) : '—' ?></td>
          <td><?= $r['valvulas_cods']
                ? '<strong>' . h((string)$r['valvulas_cods']) . '</strong>'
                : '<span class="vhint">— sem válvula —</span>' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['area_plantada'], 2) ?></td>
          <td><?= $statusBadge((string)$r['status']) ?></td>
          <td><div class="vactions">
            <?= vero_btn_icone(vero_ico_olho(), 'Válvulas da safra', '', '?safra=' . (int)$r['id']) ?>
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('agro.safras.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir esta safra? Só é possível sem válvulas vinculados.') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>

  <?php if ($panel): ?>
  <!-- Painel: válvulas da safra selecionada -->
  <div class="vcard" style="margin-top:20px">
    <div class="vtoolbar" style="justify-content:space-between">
      <strong>Válvulas da safra <?= h($panel['identificacao']) ?> <?= $statusBadge((string)$panel['status']) ?></strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h(strtok((string)$_SERVER['REQUEST_URI'], '?')) ?>">Fechar painel</a>
    </div>
    <?php /* Onda 2 GATE (decisão gestor 15/07): bloco estrutural depende de fenologia aprovada (variedade > cultura > bloqueia) */
      if (!function_exists('vero_a1_safra_fenologia_ok')) require_once __DIR__ . '/../agro/_fenologia_helper.php';
      $fenGate = vero_a1_safra_fenologia_ok((int)$panel['id']); ?>
    <div style="margin:8px 0;padding:9px 12px;border-radius:9px;font-size:13px;border:1px solid <?= $fenGate['ok'] ? '#BFE3C0;background:#F1F9F1;color:#2E6B33' : '#E8C6A0;background:#FBF3E7;color:#8A5A22' ?>">
      <?= $fenGate['ok']
          ? '✅ Bloco estrutural <strong>liberado</strong> — fenologia OK.'
          : '🔒 Bloco estrutural <strong>bloqueado</strong> — ' . h($fenGate['motivo']) . ' <span class="vhint">(cronograma, atividades, orçamento e monitoramentos exigem a fenologia aprovada da variedade — ou o fallback da cultura).</span>' ?>
    </div>

    <?php if ($panelResumo && $panelVinculos):
        $pctAting = $panelResumo['estimado_kg'] > 0
            ? $panelResumo['kg_realizado'] / $panelResumo['estimado_kg'] * 100 : null;
        $resultado = $panelResumo['vendas'] - $panelResumo['custo']; ?>
    <!-- Resumo executivo (leitura) -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0;border-bottom:1px solid #EEE8DB">
      <div style="padding:12px 16px">
        <div class="vhint">Área plantada</div>
        <strong class="vnum"><?= numFmt($panelResumo['area'], 2) ?> ha</strong>
        <div class="vhint"><?= count($panelVinculos) ?> válvula(ões)<?= $panelResumo['plantas'] > 0 ? ' · ' . numFmt($panelResumo['plantas'], 0) . ' plantas' : '' ?></div>
      </div>
      <div style="padding:12px 16px">
        <div class="vhint">Produção estimada</div>
        <strong class="vnum"><?= $panelResumo['estimado_kg'] > 0 ? numFmt($panelResumo['estimado_kg'], 0) . ' kg' : '—' ?></strong>
        <?php if ($panelResumo['metas_nao_convertidas'] > 0 || $panelResumo['sem_meta'] > 0): ?>
          <div class="vhint"><?= $panelResumo['sem_meta'] > 0 ? $panelResumo['sem_meta'] . ' sem meta' : '' ?><?=
            $panelResumo['sem_meta'] > 0 && $panelResumo['metas_nao_convertidas'] > 0 ? ' · ' : '' ?><?=
            $panelResumo['metas_nao_convertidas'] > 0 ? $panelResumo['metas_nao_convertidas'] . ' meta(s) fora de kg/t' : '' ?></div>
        <?php endif; ?>
      </div>
      <div style="padding:12px 16px">
        <div class="vhint">Produção realizada</div>
        <strong class="vnum"><?= numFmt($panelResumo['kg_realizado'], 0) ?> kg</strong>
        <?php if ($pctAting !== null): ?>
          <div class="vhint" style="<?= $pctAting >= 100 ? 'color:#1a7f4b' : ($pctAting < 80 ? 'color:#b3261e' : '') ?>">
            <?= numFmt($pctAting, 1) ?>% do estimado</div>
        <?php endif; ?>
      </div>
      <div style="padding:12px 16px">
        <div class="vhint">Custo realizado</div>
        <strong class="vnum" style="color:#005059">R$ <?= numFmt($panelResumo['custo'], 2) ?></strong>
      </div>
      <div style="padding:12px 16px">
        <div class="vhint">Vendas</div>
        <strong class="vnum">R$ <?= numFmt($panelResumo['vendas'], 2) ?></strong>
      </div>
      <div style="padding:12px 16px">
        <div class="vhint">Resultado bruto (vendas − custo)</div>
        <strong class="vnum" style="color:<?= $resultado >= 0 ? '#1a7f4b' : '#b3261e' ?>">R$ <?= numFmt($resultado, 2) ?></strong>
        <div class="vhint">detalhe em <a href="<?= BIOS_BASE ?>/custeio/resultado_safra">Resultado da Safra</a></div>
      </div>
    </div>
    <?= vero_calc_mo_gaps_painel_html((int)$panel['id'], (float)$panelResumo['area'], (float)$panelResumo['estimado_kg'], (float)$panelResumo['kg_realizado']) ?>
    <?php endif; ?>

    <?php if ($podeEditar && $safrasOrigemOpt): ?>
    <form method="post" class="vtoolbar" style="border-bottom:1px solid #EEE8DB"
          data-confirm="Copiar os vínculos (válvula, cultura, área e meta) da safra de origem para esta? Válvulas já vinculados são mantidos." data-confirm-ok="Copiar"
          onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="rolar">
      <input type="hidden" name="safra_id" value="<?= (int)$panel['id'] ?>">
      <span class="vhint">Rolar safra:</span>
      <select name="origem_safra_id" required>
        <option value="">Copiar vínculos da safra…</option>
        <?php foreach ($safrasOrigemOpt as $sid => $sn): ?><option value="<?= $sid ?>"><?= h($sn) ?></option><?php endforeach; ?>
      </select>
      <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Copiar vínculos</button>
      <span class="vhint">Copia válvulas ativas com cultura, área e meta</span>
    </form>
    <?php endif; ?>

    <?php if ($podeEditar): ?>
    <form method="post" class="vtoolbar" style="border-bottom:1px solid #EEE8DB">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="vincular">
      <input type="hidden" name="safra_id" value="<?= (int)$panel['id'] ?>">
      <select name="talhao_id" id="vinc-talhao" required>
        <option value="">Válvula…</option>
        <?php foreach ($talhoesOpt as $tid => $tl): ?><option value="<?= $tid ?>"><?= h($tl) ?></option><?php endforeach; ?>
      </select>
      <select name="cultura_id" id="vinc-cultura" required>
        <option value="">Cultura…</option>
        <?php foreach ($culturas as $cid => $cn): ?><option value="<?= $cid ?>"><?= h($cn) ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="area_plantada_ha" placeholder="Área (ha) — vazio = área da válvula" style="min-width:230px">
      <input type="text" name="produtividade_planejada" id="vinc-meta" placeholder="Meta produtiv." style="min-width:120px">
      <select name="unidade_produtividade" id="vinc-unidade">
        <option value="kg_ha">kg/ha</option><option value="t_ha">t/ha</option>
        <option value="sacas_ha">sacas/ha</option><option value="arroba_ha">@/ha</option><option value="litros_ha">L/ha</option>
      </select>
      <!-- V-06 / D-2: parâmetros de colheita/raleio da SAFRA — só aparecem quando
           a cultura é uva (JS). Vazios = fallback na variedade/cultura. -->
      <span id="vinc-uva" style="display:none;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="text" name="cachos_por_planta" id="vinc-cachos" placeholder="Cachos/planta" style="min-width:120px" title="Cachos por planta nesta safra (raleio) — vazio usa a variedade">
        <input type="text" name="caixas_por_planta" id="vinc-caixas" placeholder="Caixas/planta" style="min-width:120px" title="Caixas por planta nesta safra (colheita/embalamento)">
        <input type="text" name="peso_caixa_kg" id="vinc-pesocx" placeholder="Peso caixa (kg)" style="min-width:120px" title="Peso da caixa (kg) nesta safra — vazio usa a cultura">
      </span>
      <button class="vbtn vbtn-primary vbtn-sm" type="submit">Vincular</button>
      <?php if (!$culturas): ?>
        <span class="vhint">Nenhuma cultura ativa — cadastre em <a href="<?= BIOS_BASE ?>/agro/culturas">Culturas</a>.</span>
      <?php endif; ?>
    </form>
    <script>
    /* Pré-preenche cultura/meta/unidade pela variedade principal da válvula
       (referência EDITÁVEL — nunca imposta). Só preenche campo vazio.
       V-06/D-2: mostra os campos caixas/cachos/peso só quando a cultura é uva. */
    (function () {
      var REF = <?= jsvar($talhaoRef) ?>;
      var UVA = <?= jsvar($culturaUva) ?>; /* cultura_id => 1|0 (é uva?) */
      var sel  = document.getElementById('vinc-talhao');
      var cult = document.getElementById('vinc-cultura');
      var uvaBox = document.getElementById('vinc-uva');
      function toggleUva() {
        if (!uvaBox || !cult) return;
        var ehUva = cult.value && UVA[cult.value] === 1;
        uvaBox.style.display = ehUva ? 'inline-flex' : 'none';
      }
      if (cult) cult.addEventListener('change', toggleUva);
      if (sel) sel.addEventListener('change', function () {
        var r = REF[this.value];
        if (r) {
          var meta = document.getElementById('vinc-meta');
          var unid = document.getElementById('vinc-unidade');
          if (r.cultura && cult && !cult.value) cult.value = String(r.cultura);
          if (r.meta !== null && meta && meta.value === '') {
            meta.value = String(r.meta).replace('.', ',');
            if (r.unidade && unid) unid.value = r.unidade;
          }
        }
        toggleUva();
      });
      toggleUva();
    })();
    </script>
    <?php endif; ?>

    <?php if (!$panelVinculos): ?>
      <div class="vempty">Nenhum válvula vinculada a esta safra ainda.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Fazenda</th><th>Válvula</th><th>Cultura</th>
        <th style="text-align:right">Nº de plantas</th>
        <th style="text-align:right">Área plantada (ha)</th>
        <th style="text-align:right">Meta produtividade</th>
        <?php if ($panelTemUva): ?><th style="text-align:right" title="Parâmetros da SAFRA para colheita/raleio (uva) — vazio usa a variedade/cultura">Colheita/raleio (uva)</th><?php endif; ?>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($panelVinculos as $v):
          $vUva = $ehUva((string)($v['cultura_nome'] ?? '')); ?>
        <tr>
          <td><?= h($v['fazenda_nome']) ?></td>
          <td><strong class="vnum"><?= h($v['talhao_codigo']) ?></strong></td>
          <td><?= h($v['cultura_nome']) ?></td>
          <td class="vnum" style="text-align:right"><?= $v['talhao_num_plantas'] !== null ? numFmt((float)$v['talhao_num_plantas'], 0) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$v['area_plantada_ha'], 2) ?></td>
          <td class="vnum" style="text-align:right">
            <?= $v['produtividade_planejada'] !== null
                ? numFmt((float)$v['produtividade_planejada'], 0) . ' ' . h(str_replace('_', '/', (string)$v['unidade_produtividade']))
                : '—' ?>
          </td>
          <?php if ($panelTemUva): ?>
          <td class="vnum" style="text-align:right;font-size:12px">
            <?php if ($vUva):
                $cxP = $v['cachos_por_planta'] ?? null; $caP = $v['caixas_por_planta'] ?? null; $peC = $v['peso_caixa_kg'] ?? null;
                $bits = [];
                $bits[] = 'cachos/pl.: ' . ($cxP !== null ? numFmt((float)$cxP, 2) : '<span class="vhint">—</span>');
                $bits[] = 'caixas/pl.: ' . ($caP !== null ? numFmt((float)$caP, 2) : '<span class="vhint">—</span>');
                $bits[] = 'caixa: ' . ($peC !== null ? numFmt((float)$peC, 3) . ' kg' : '<span class="vhint">—</span>');
                echo implode('<br>', $bits);
            else: ?><span class="vhint">—</span><?php endif; ?>
          </td>
          <?php endif; ?>
          <td><div class="vactions">
            <?php if ($podeEditar && $vUva): ?>
            <button type="button" class="vbtn vbtn-ghost vbtn-sm"
                    onclick="vParamsOpen(this)"
                    data-id="<?= (int)$v['id'] ?>"
                    data-cachos="<?= $v['cachos_por_planta'] !== null ? h(numFmt((float)$v['cachos_por_planta'], 2)) : '' ?>"
                    data-caixas="<?= $v['caixas_por_planta'] !== null ? h(numFmt((float)$v['caixas_por_planta'], 2)) : '' ?>"
                    data-peso="<?= $v['peso_caixa_kg'] !== null ? h(numFmt((float)$v['peso_caixa_kg'], 3)) : '' ?>"
                    data-valvula="<?= h((string)$v['talhao_codigo']) ?>">Colheita/raleio</button>
            <?php endif; ?>
            <?php if ($podeEditar): ?>
            <form method="post" data-confirm="Remover o vínculo desta válvula com a safra?" data-confirm-danger data-confirm-ok="Remover" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="desvincular">
              <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
              <input type="hidden" name="safra_id" value="<?= (int)$panel['id'] ?>">
              <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Remover</button>
            </form>
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

<?php if ($podeEditar && $panel): ?>
<!-- V-06 / D-2: modal p/ editar os parâmetros de colheita/raleio (uva) de uma
     válvula-safra. Reusado por todas as linhas (preenchido via JS por data-*). -->
<div class="vmodal" id="vm-params">
  <div class="vbox">
    <header>
      <h2>Colheita/raleio — <span id="vp-valvula">válvula</span></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-params')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="params_valvula">
      <input type="hidden" name="id" id="vp-id" value="">
      <input type="hidden" name="safra_id" value="<?= (int)$panel['id'] ?>">
      <p class="vhint" style="margin:0 0 8px">Parâmetros desta safra para a válvula. Deixe em branco para usar o padrão da variedade (cachos por planta) ou da cultura (peso da caixa).</p>
      <div class="vgrid">
        <?= vero_f_text('cachos_por_planta', 'Cachos por planta', '', false, 'Raleio — cachos por planta nesta safra (mercado interno = mais cachos)') ?>
        <?= vero_f_text('caixas_por_planta', 'Caixas por planta', '', false, 'Colheita/embalamento — caixas por planta nesta safra') ?>
        <?= vero_f_text('peso_caixa_kg', 'Peso da caixa (kg)', '', false, 'Peso da caixa nesta safra — vazio usa a cultura') ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-params')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<script>
/* abre o modal de parâmetros preenchendo pelos data-* do botão da linha */
function vParamsOpen(btn) {
  var set = function (name, val) {
    var el = document.querySelector('#vm-params [name="' + name + '"]');
    if (el) el.value = val || '';
  };
  set('id', btn.getAttribute('data-id'));
  set('cachos_por_planta', btn.getAttribute('data-cachos'));
  set('caixas_por_planta', btn.getAttribute('data-caixas'));
  set('peso_caixa_kg', btn.getAttribute('data-peso'));
  var lbl = document.getElementById('vp-valvula');
  if (lbl) lbl.textContent = btn.getAttribute('data-valvula') || 'válvula';
  if (window.vModalOpen) vModalOpen('vm-params');
  else document.getElementById('vm-params').classList.add('open');
}
</script>
<?php endif; ?>

<?php if ($podeEditar):
  /* Chegada de "Poda finalizada → criar safra" (agro/apontamentos.php):
     ?nova=1 abre o modal de Nova safra; ?pre_talhao=<id> marca a válvula. */
  $abrirNova = isset($_GET['nova']) && !$edit;
?>
<div class="vmodal<?= ($edit || $abrirNova) ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar safra' : 'Nova safra' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('identificacao', 'Identificação', $identVal, true, 'Sugestão AAAA.S-NN (ex.: 2026.1-01) — editável') ?>
        <?= vero_f_select('fazenda_id', 'Fazenda (opcional)', $fazendas, $edit['fazenda_id'] ?? null, false, 'Todas as fazendas') ?>
        <?= vero_f_text('data_inicio', 'Data de início', $edit['data_inicio'] ?? '', true, '', 'date') ?>
        <?= vero_f_text('data_fim_prevista', 'Fim previsto', $fimVal, false, 'Sugerido = início + maior ciclo das variedades — editável', 'date') ?>
        <?= vero_f_select('status', 'Status',
              ['planejada' => 'Planejada', 'ativa' => 'Ativa', 'encerrada' => 'Encerrada'],
              $edit['status'] ?? 'planejada', true, '') ?>
        <?= vero_f_text('data_fim', 'Data de encerramento', $edit['data_fim'] ?? '', false, 'Preenchida ao encerrar', 'date') ?>
      </div>

      <?php if (!$edit): ?>
      <!-- INVERSÃO DO FLUXO (17/07): escolher as válvulas JÁ na criação da safra -->
      <div style="margin-top:10px">
        <label style="font-weight:600;display:block;margin-bottom:4px">Válvulas da safra
          <span class="vhint" style="font-weight:400">— vincule já na criação; a cultura vem da variedade da válvula</span></label>
        <?php if (!$talhoesOpt): ?>
          <div class="vhint">Nenhuma válvula ativa cadastrada.</div>
        <?php else: ?>
        <div id="vv-selector" data-count="<?= count($talhoesOpt) ?>">
          <div class="vhint" id="vv-counter" style="margin-bottom:6px" aria-live="polite">0 selecionada(s)</div>
          <div id="vv-list" style="max-height:240px;overflow:auto;border:1px solid #EEE8DB;border-radius:8px;padding:6px 12px">
            <?php
              $currFaz = null;
              foreach ($talhoesOpt as $tid => $label):
                  $temCultura = isset($talhaoRef[(int)$tid]) && $talhaoRef[(int)$tid]['cultura'] !== null;
                  $parts = explode(' — ', (string)$label, 2);
                  $faz   = $parts[0];
                  $cod   = isset($parts[1]) ? $parts[1] : $label;
                  $search = mb_strtolower((string)$label, 'UTF-8');
                  if ($faz !== $currFaz):
                      if ($currFaz !== null): ?></div></div><?php endif; // fecha grid + grupo anterior
                      $currFaz = $faz; ?>
                  <div class="vv-group">
                    <div class="vv-group-hd" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#8a8578;padding:8px 0 3px;position:sticky;top:-6px;background:#fff"><?= h($faz) ?></div>
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 12px">
              <?php endif; ?>
                  <label class="vv-item" data-search="<?= h($search) ?>" style="display:flex;gap:8px;align-items:center;padding:2px 0<?= $temCultura ? '' : ';opacity:.5' ?>">
                    <input type="checkbox" name="talhoes[]" value="<?= (int)$tid ?>"<?= $temCultura ? '' : ' disabled' ?>>
                    <span><?= h($cod) ?><?= $temCultura ? '' : ' <span class="vhint">(defina a variedade)</span>' ?></span>
                  </label>
              <?php endforeach;
              if ($currFaz !== null): ?></div></div><?php endif; // fecha último grupo ?>
          </div>
        </div>
        <div class="vhint" style="margin-top:4px">Dá para ajustar (adicionar/remover) depois, no painel da safra.</div>
        <script>
        (function () {
          var list = document.getElementById('vv-list');
          var counter = document.getElementById('vv-counter');
          if (!list || !counter) return;
          function updateCounter() {
            var n = list.querySelectorAll('input[type=checkbox]:checked').length;
            counter.textContent = n + ' selecionada(s)';
          }
          list.addEventListener('change', updateCounter);
          updateCounter();
        })();
        </script>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<script>
/* item 2c: em NOVA safra, reajusta o prefixo AAAA.S-NN da identificação
   sugerida quando o usuário informa a data de início. Só age se o campo
   não foi editado manualmente e se é criação (id vazio). Editável sempre. */
(function () {
  var seq = <?= jsvar($identSeq) ?>;
  var di = document.querySelector('#vm-form input[name="data_inicio"]');
  var id = document.querySelector('#vm-form input[name="identificacao"]');
  var idHidden = document.querySelector('#vm-form input[name="id"]');
  if (!di || !id) return;
  var isNew = !(idHidden && idHidden.value);
  function pad2(n) { return (n < 10 ? '0' : '') + n; }
  id.addEventListener('input', function () { id.dataset.touched = '1'; });
  di.addEventListener('change', function () {
    if (!isNew || id.dataset.touched === '1') return;
    var v = di.value; if (!v) return;
    var p = v.split('-'); if (p.length < 2) return;
    var y = parseInt(p[0], 10), mo = parseInt(p[1], 10);
    if (!y || !mo) return;
    var pref = y + '.' + (mo <= 6 ? '1' : '2');
    id.value = pref + '-' + pad2((seq[pref] || 0) + 1);
  });
})();
/* Poda → criar safra: ?pre_talhao=<id> marca a válvula no seletor da Nova safra
   (habilita se estiver desabilitada por falta de cultura — o POST avisa se faltar
   variedade). Não interfere no fluxo normal. */
(function () {
  var p = new URLSearchParams(location.search);
  var pre = parseInt(p.get('pre_talhao') || '0', 10);
  if (!pre) return;
  var cb = document.querySelector('#vm-form input[name="talhoes[]"][value="' + pre + '"]');
  if (!cb) return;
  cb.disabled = false;
  cb.checked = true;
  cb.dispatchEvent(new Event('change', { bubbles: true }));
  if (cb.scrollIntoView) cb.scrollIntoView({ block: 'center' });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
