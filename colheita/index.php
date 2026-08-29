<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php'; 

const T = 'colheita_registros';

const CATEGORIAS = ['premium' => 'Premium', 'cat1' => 'CAT 1', 'cat2' => 'CAT 2', 'cat3' => 'CAT 3', 'perdidos' => 'Perdidos'];


function colheita_msg_entrada(string $msg): string
{
    if (str_starts_with($msg, 'PERIODO_FECHADO:')) {
        return '⚠ ' . trim(substr($msg, strlen('PERIODO_FECHADO:')))
             . ' — o período está FECHADO no custeio; para lançar/estornar, reabra a safra em Custos → Fechamento de Safra (reabertura formal) e tente de novo.';
    }
    return $msg; 
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.colheita.editar');


        $redirOk = (($_POST['origem'] ?? '') === 'agro')
            ? BIOS_BASE . '/agro/colheita'
            : BIOS_BASE . '/colheita/index';

        $id      = vero_int('id');
        $data    = vero_date('data_colheita');
        $setorId = vero_int('setor_id');
        $stId    = vero_int('safra_talhao_id');

        if ($data === null || !$setorId || !$stId) {
            vero_flash('erro', 'Data, válvula e safra são obrigatórios.');
            vero_redirect($redirOk);
        }


        $tinhaEntrada = false;
        if ($id) {
            $pdoE = vero_pdo();
            $pdoE->beginTransaction();
            try {
                $tinhaEntrada = vero_srv_colheita_estornar_entrada((int)$id);
                $pdoE->commit();
            } catch (Throwable $e) {
                $pdoE->rollBack();
                vero_flash('erro', colheita_msg_entrada($e->getMessage())
                    . ' A colheita NÃO foi alterada (a entrada ativa não pôde ser estornada).');
                vero_redirect($redirOk);
            }
        }

        $setor = vero_row("SELECT * FROM agro_setores WHERE id=:i AND tenant_id=:t",
            [':i' => $setorId, ':t' => vero_tenant()]);
        if (!$setor || $setor['talhao_id'] === null) {
            vero_flash('erro', 'Válvula inválida ou sem válvula vinculada.');
            vero_redirect($redirOk);
        }
        $vinculo = vero_row(
            "SELECT * FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t AND talhao_id=:ta",
            [':i' => $stId, ':t' => vero_tenant(), ':ta' => (int)$setor['talhao_id']]);
        if (!$vinculo) {
            vero_flash('erro', 'A safra selecionada não está vinculada à válvula desta válvula.');
            vero_redirect($redirOk);
        }
        $variedadeId = vero_int('variedade_id');
        if ($variedadeId) {
            $okVar = vero_val("SELECT id FROM agro_variedades WHERE id=:i AND tenant_id=:t AND cultura_id=:c",
                [':i' => $variedadeId, ':t' => vero_tenant(), ':c' => (int)$vinculo['cultura_id']]);
            if (!$okVar) $variedadeId = null;
        }

        $area = (float)($setor['area_ha'] ?? 0);
        if ($area <= 0) $area = (float)$vinculo['area_plantada_ha'];


        $uniEntrada = (string)($_POST['colheita_unidade_entrada'] ?? 'kg');
        $uniTenant  = (string)vero_srv_param('colheita.unidade', 'kg');
        if ($uniEntrada === 'caixa' && in_array($uniTenant, ['caixa', 'ambos'], true) && $area > 0) {
            $pesoCaixa = (float)vero_srv_param('colheita.peso_caixa_kg', '0');
            if ($pesoCaixa > 0) {
                $cxPrev = vero_dec('cx_prev');
                $cxReal = vero_dec('cx_real');
                if ($cxPrev !== null) $_POST['producao_prevista_kg_ha']  = (string)($cxPrev * $pesoCaixa / $area);
                if ($cxReal !== null) $_POST['producao_realizada_kg_ha'] = (string)($cxReal * $pesoCaixa / $area);
            }
        }

        $prevKgHa = vero_dec('producao_prevista_kg_ha');
        $realKgHa = vero_dec('producao_realizada_kg_ha');

        if (($prevKgHa !== null && $prevKgHa < 0) || ($realKgHa !== null && $realKgHa < 0)) {
            vero_flash('erro', 'A produção prevista/realizada não pode ser negativa.');
            vero_redirect();
        }
        $kgPrev   = $prevKgHa !== null ? round($prevKgHa * $area, 3) : 0.0;
        $kgReal   = $realKgHa !== null ? round($realKgHa * $area, 3) : 0.0;

        $cKg    = (array)($_POST['c_kg'] ?? []);
        $cPct   = (array)($_POST['c_pct'] ?? []);
        $cPreco = (array)($_POST['c_preco'] ?? []);
        $cCausa = (array)($_POST['c_causa'] ?? []); 
        $parseDec = static function ($v): float {
            $v = trim((string)$v);
            if ($v === '') return 0.0;
            if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
            elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
            return is_numeric($v) ? (float)$v : 0.0;
        };

        $classifs = [];
        $fatur = ['previsto' => 0.0, 'realizado' => 0.0];
        foreach (['previsto' => $kgPrev, 'realizado' => $kgReal] as $momento => $kgTotal) {
            $modoKg  = array_key_exists($momento, $cKg); 
            $somaPct = 0.0;
            $somaKg  = 0.0;
            foreach (CATEGORIAS as $cat => $rotulo) {
                $preco = $cat === 'perdidos' ? 0.0 : $parseDec($cPreco[$momento][$cat] ?? '');
                if ($modoKg) {
                    $kg = round($parseDec($cKg[$momento][$cat] ?? ''), 3);
                    if ($kg <= 0) continue;

                    $pct = $kgTotal > 0 ? round($kg / $kgTotal * 100, 2) : 0.0;
                } else {
                    $pct = $parseDec($cPct[$momento][$cat] ?? '');
                    if ($pct <= 0) continue;
                    $kg = round($kgTotal * $pct / 100, 3);
                }
                $somaPct += $pct;
                $somaKg  += $kg;
                $fat = $cat === 'perdidos' ? 0.0 : round($kg * $preco, 2);
                $fatur[$momento] += $fat;
                $classifs[] = [
                    'momento' => $momento, 'categoria' => $cat, 'percentual' => $pct,
                    'preco_kg' => $preco, 'kg_calculado' => $kg, 'faturamento' => $fat,

                    'causa_perda' => $cat === 'perdidos'
                        ? (trim((string)($cCausa[$momento][$cat] ?? '')) ?: 'não informada')
                        : null,
                ];
            }
            if ($modoKg) {

                if ($somaKg > 0 && $kgTotal <= 0) {
                    vero_flash('erro', 'Você classificou ' . numFmt($somaKg, 0) . ' kg no momento "' . $momento
                        . '", mas a produção ' . ($momento === 'previsto' ? 'prevista' : 'realizada')
                        . ' está vazia — informe a produção total antes de classificar.');
                    vero_redirect($redirOk);
                }
                if ($somaKg > $kgTotal + 0.0005) {
                    vero_flash('erro', 'A soma dos kg do momento "' . $momento . '" (' . numFmt($somaKg, 0)
                        . ' kg) passa do kg total ' . ($momento === 'previsto' ? 'previsto' : 'realizado')
                        . ' (' . numFmt($kgTotal, 0) . ' kg). Ajuste os kg por categoria.');
                    vero_redirect($redirOk);
                }
            } elseif ($somaPct > 100.0001) {
                vero_flash('erro', 'A soma dos percentuais do momento "' . $momento . '" passa de 100% (' . numFmt($somaPct, 2) . '%).');
                vero_redirect($redirOk);
            }
        }

        $cab = [
            'safra_id'                 => (int)$vinculo['safra_id'],
            'safra_talhao_id'          => $stId,
            'talhao_id'                => (int)$setor['talhao_id'],
            'setor_id'                 => $setorId,
            'cultura_id'               => (int)$vinculo['cultura_id'],
            'variedade_id'             => $variedadeId,
            'data_colheita'            => $data,
            'producao_prevista_kg_ha'  => $prevKgHa,
            'producao_realizada_kg_ha' => $realKgHa,
            'kg_total_previsto'        => $kgPrev,
            'kg_total_realizado'       => $kgReal,
            'faturamento_previsto'     => round($fatur['previsto'], 2),
            'faturamento_realizado'    => round($fatur['realizado'], 2),
            'observacao'               => vero_str('observacao', 255),

            'status'                   => 'finalizada',
        ];

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $ok = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t",
                    [':i' => $id, ':t' => vero_tenant()]);
                if (!$ok) throw new RuntimeException('Registro de colheita inválido.');
                vero_update(T, $id, $cab);
                $pdo->prepare("DELETE FROM colheita_classificacoes WHERE tenant_id=? AND registro_id=?")
                    ->execute([vero_tenant(), $id]);
                $regId = $id;
            } else {
                $cab['origem'] = 'web';
                $regId = vero_insert(T, $cab);
            }
            foreach ($classifs as $c) {
                $c['registro_id'] = $regId;
                vero_insert('colheita_classificacoes', $c);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao salvar a colheita: ' . h($e->getMessage()));
            vero_redirect($redirOk);
        }


        $carencias = vero_srv_talhao_carencias((int)$setor['talhao_id'], $data);
        $pdo->prepare("DELETE FROM agro_alertas WHERE tenant_id=? AND origem_tipo='colheita_carencia' AND origem_id=?")
            ->execute([vero_tenant(), (int)$regId]);
        if ($carencias) {
            $itens = array_map(static fn($c) =>
                $c['produto'] . ' (aplicação #' . $c['aplicacao_id'] . ', liberação ' . date('d/m/Y', strtotime((string)$c['liberado_em'])) . ')',
                array_slice($carencias, 0, 5));
            $fazendaId = (int)(vero_val("SELECT fazenda_id FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
                [':i' => (int)$setor['talhao_id'], ':t' => vero_tenant()]) ?? 0);
            vero_insert('agro_alertas', [
                'categoria'    => 'residuo',
                'origem_tipo'  => 'colheita_carencia',
                'origem_id'    => (int)$regId,
                'fazenda_id'   => $fazendaId ?: null,
                'talhao_id'    => (int)$setor['talhao_id'],
                'safra_id'     => (int)$vinculo['safra_id'],
                'severidade'   => 'critico',
                'titulo'       => 'Colheita dentro do período de carência',
                'mensagem'     => 'Colheita de ' . date('d/m/Y', strtotime($data)) . ' com carência ativa: '
                                  . implode('; ', $itens)
                                  . (count($carencias) > 5 ? ' e mais ' . (count($carencias) - 5) . ' item(ns)' : '')
                                  . '. Avaliação do responsável técnico pendente.',
                'requer_validacao_tecnica' => 1,
                'status'       => 'aberto',
                'data'         => $data,
            ]);
            vero_flash('aviso', '⚠ Colheita registrada DENTRO do período de carência de '
                . count($carencias) . ' aplicação(ões) — alerta crítico emitido para avaliação do RT (categoria resíduo).');
        }

        vero_flash('ok', ($id ? 'Colheita atualizada' : 'Colheita registrada')
            . ' — realizado ' . numFmt($kgReal, 0) . ' kg, faturamento estimado R$ ' . numFmt($fatur['realizado'], 2) . '.');


        if ($tinhaEntrada) {
            $pdoE = vero_pdo();
            $pdoE->beginTransaction();
            try {
                $r = vero_srv_colheita_confirmar_entrada((int)$regId);
                $pdoE->commit();
                vero_flash('ok', 'Entrada no estoque RECONFIRMADA com os novos números: lote '
                    . $r['lote_codigo'] . ', ' . numFmt($r['kg'], 0) . ' kg (custo provisório R$ '
                    . numFmt($r['custo_unitario'], 4) . '/kg).');
            } catch (Throwable $e) {
                $pdoE->rollBack();
                vero_flash('aviso', '⚠ A colheita foi salva, mas a entrada no estoque NÃO pôde ser reconfirmada: '
                    . colheita_msg_entrada($e->getMessage())
                    . ' Use "Confirmar entrada" na listagem quando resolver.');
            }
        }
        vero_redirect($redirOk);
    }


    if ($acao === 'entrada_confirmar') {
        vero_require('agro.colheita.editar');
        $id = vero_int('id');
        if ($id) {
            $pdo = vero_pdo();
            $pdo->beginTransaction();
            try {
                $r = vero_srv_colheita_confirmar_entrada((int)$id);
                $pdo->commit();
                if ($r['ja_existia']) {
                    vero_flash('aviso', 'Esta colheita JÁ tem entrada ativa no estoque: lote '
                        . $r['lote_codigo'] . ' (' . numFmt($r['kg'], 0) . ' kg). Nada foi duplicado.');
                } else {
                    vero_flash('ok', 'Entrada CONFIRMADA no estoque — lote ' . $r['lote_codigo'] . ': '
                        . numFmt($r['kg'], 0) . ' kg a custo PROVISÓRIO R$ ' . numFmt($r['custo_unitario'], 4)
                        . '/kg (média do custeio acumulado ÷ kg colhido — revalorização no fechamento da safra).');
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                vero_flash('erro', colheita_msg_entrada($e->getMessage()));
            }
        }
        vero_redirect(BIOS_BASE . '/colheita/index');
    }


    if ($acao === 'entrada_estornar') {
        vero_require('agro.colheita.editar');
        $id = vero_int('id');
        if ($id) {
            $pdo = vero_pdo();
            $pdo->beginTransaction();
            try {
                $ok = vero_srv_colheita_estornar_entrada((int)$id);
                $pdo->commit();
                vero_flash($ok ? 'ok' : 'aviso', $ok
                    ? 'Entrada no estoque ESTORNADA — saldo devolvido e lote marcado como estornado; o registro de colheita permanece.'
                    : 'Esta colheita não tem entrada ativa no estoque.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                vero_flash('erro', colheita_msg_entrada($e->getMessage()));
            }
        }
        vero_redirect(BIOS_BASE . '/colheita/index');
    }

    if ($acao === 'excluir') {
        vero_require('agro.colheita.excluir');
        $id = vero_int('id');
        if ($id) {
            $ok = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => vero_tenant()]);
            if ($ok) {
                $pdo = vero_pdo();
                $pdo->beginTransaction();
                try {

                    $estornouEntrada = vero_srv_colheita_estornar_entrada((int)$id);
                    $pdo->prepare("UPDATE estoque_lotes SET colheita_registro_id = NULL
                                    WHERE tenant_id = ? AND colheita_registro_id = ? AND status = 'estornado'")
                        ->execute([vero_tenant(), $id]);
                    $pdo->prepare("DELETE FROM colheita_classificacoes WHERE tenant_id=? AND registro_id=?")
                        ->execute([vero_tenant(), $id]);
                    $pdo->prepare("DELETE FROM agro_alertas WHERE tenant_id=? AND origem_tipo='colheita_carencia' AND origem_id=?")
                        ->execute([vero_tenant(), $id]); 
                    $pdo->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")
                        ->execute([vero_tenant(), $id]);
                    $pdo->commit();
                    vero_flash('ok', 'Registro de colheita excluído.');
                    if ($estornouEntrada) {
                        vero_flash('aviso', 'A entrada desta colheita no estoque foi ESTORNADA junto com a exclusão (saldo devolvido, lote estornado).');
                    }
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    if ($e instanceof PDOException && $e->getCode() === '23000') {
                        vero_flash('erro', 'Não é possível excluir: existem vendas vinculadas a esta colheita.');
                    } else {

                        vero_flash('erro', colheita_msg_entrada($e->getMessage())
                            . ' A colheita NÃO foi excluída.');
                    }
                }
            }
        }
        vero_redirect(BIOS_BASE . '/colheita/index');
    }
}


$modoForm = isset($_GET['novo']) || !empty($_GET['editar']);

$edit = null;
$editClassifs = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        $editClassifs = vero_rows(
            "SELECT * FROM colheita_classificacoes WHERE tenant_id=:t AND registro_id=:r",
            [':t' => vero_tenant(), ':r' => (int)$edit['id']]);
    } else {
        $modoForm = false;
    }
}

if ($modoForm) {
    $setores = vero_rows(
        "SELECT s.id, s.codigo, s.area_ha, s.talhao_id, t.codigo AS talhao, f.nome AS fazenda
           FROM agro_setores s
           JOIN agro_talhoes t ON t.id = s.talhao_id
           JOIN agro_fazendas f ON f.id = t.fazenda_id
          WHERE s.tenant_id = :t AND s.ativo = 1 AND s.talhao_id IS NOT NULL
          ORDER BY f.nome, t.codigo, s.codigo",
        [':t' => vero_tenant()]);
    $vinculos = vero_rows(
        "SELECT st.id, st.talhao_id, st.cultura_id, st.area_plantada_ha,
                s.identificacao AS safra, c.nome AS cultura
           FROM agro_safra_talhoes st
           JOIN agro_safras s ON s.id = st.safra_id
           JOIN agro_culturas c ON c.id = st.cultura_id
          WHERE st.tenant_id = :t ORDER BY s.identificacao DESC",
        [':t' => vero_tenant()]);
    $variedades = vero_rows(
        "SELECT id, cultura_id, nome FROM agro_variedades
          WHERE tenant_id = :t AND ativo = 1 ORDER BY nome",
        [':t' => vero_tenant()]);
} else {
    $fSetor  = (int)($_GET['valvula'] ?? 0);
    $page    = max(1, (int)($_GET['pg'] ?? 1));
    $perPage = 15;

    $where  = "r.tenant_id = :t";
    $params = [':t' => vero_tenant()];
    if ($fSetor > 0) {
        $where .= " AND r.setor_id = :s";
        $params[':s'] = $fSetor;
    }
    $total = (int)vero_val("SELECT COUNT(*) FROM " . T . " r WHERE {$where}", $params);
    $rows  = vero_rows(
        "SELECT r.*, se.codigo AS valvula, t.codigo AS talhao, f.nome AS fazenda,
                s.identificacao AS safra, v.nome AS variedade
           FROM " . T . " r
           LEFT JOIN agro_setores se ON se.id = r.setor_id
           JOIN agro_talhoes t ON t.id = r.talhao_id
           JOIN agro_fazendas f ON f.id = t.fazenda_id
           JOIN agro_safras s ON s.id = r.safra_id
           LEFT JOIN agro_variedades v ON v.id = r.variedade_id
          WHERE {$where}
          ORDER BY r.data_colheita DESC, r.id DESC
          LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
        $params);
    $setoresFiltro = vero_rows(
        "SELECT s.id, CONCAT(f.nome, ' — ', s.codigo) AS label
           FROM agro_setores s
           LEFT JOIN agro_fazendas f ON f.id = s.fazenda_id
          WHERE s.tenant_id = :t ORDER BY f.nome, s.codigo",
        [':t' => vero_tenant()]);


    $custoColheita = (float)(vero_val(
        "SELECT COALESCE(SUM(cl.valor),0)
           FROM custeio_lancamentos cl
          WHERE cl.tenant_id = :t AND (
            (cl.origem_tipo = 'rh_producao_item' AND cl.origem_id IN (
                SELECT rpi.id FROM rh_producao_itens rpi
                  JOIN agro_apontamentos ap ON ap.id = rpi.apontamento_id
                  JOIN agro_tipos_atividade ta ON ta.id = ap.tipo_atividade_id
                 WHERE rpi.tenant_id = cl.tenant_id AND ta.categoria = 'colheita'))
            OR (cl.origem_tipo = 'apontamento_insumo' AND cl.origem_id IN (
                SELECT ai.id FROM agro_apontamento_insumos ai
                  JOIN agro_apontamentos ap2 ON ap2.id = ai.apontamento_id
                  JOIN agro_tipos_atividade ta2 ON ta2.id = ap2.tipo_atividade_id
                 WHERE ai.tenant_id = cl.tenant_id AND ta2.categoria = 'colheita'))
            OR (cl.origem_tipo = 'apontamento_maquina' AND cl.origem_id IN (
                SELECT am.id FROM agro_apontamento_maquinas am
                  JOIN agro_apontamentos ap3 ON ap3.id = am.apontamento_id
                  JOIN agro_tipos_atividade ta3 ON ta3.id = ap3.tipo_atividade_id
                 WHERE am.tenant_id = cl.tenant_id AND ta3.categoria = 'colheita'))
          )", [':t' => vero_tenant()]) ?? 0);
    $kgColhidoTotal = (float)(vero_val(
        "SELECT COALESCE(SUM(kg_total_realizado),0) FROM " . T . " WHERE tenant_id = :t",
        [':t' => vero_tenant()]) ?? 0);
}


$entradaMap = [];   
foreach (vero_rows(
    "SELECT m.origem_id, m.quantidade, m.custo_unitario, l.codigo_lote
       FROM estoque_movimentacoes m
       LEFT JOIN estoque_lotes l ON l.id = m.lote_id
      WHERE m.tenant_id = :t AND m.origem_tipo = 'colheita' AND m.tipo = 'entrada'
        AND m.estornado_em IS NULL", [':t' => vero_tenant()]
) as $em) { $entradaMap[(int)$em['origem_id']] = $em; }

$cfgMap = [];       
foreach (vero_rows(
    "SELECT cr.id, c.produto_estoque_colheita_id AS prod, c.exige_classificacao,
            (SELECT SUM(CASE WHEN COALESCE(cc.causa_perda,'') = '' THEN cc.kg_calculado ELSE 0 END)
               FROM colheita_classificacoes cc
              WHERE cc.tenant_id = cr.tenant_id AND cc.registro_id = cr.id AND cc.momento = 'realizado') AS kg_aprovado
       FROM colheita_registros cr
       JOIN agro_culturas c ON c.id = cr.cultura_id
      WHERE cr.tenant_id = :t", [':t' => vero_tenant()]
) as $cm) { $cfgMap[(int)$cm['id']] = $cm; }

$GUARD      = ['macro' => 'agricola', 'micro' => 'colheita'];
$PAGE_VIEW  = 'agricola_colheita'; 
$PAGE_TITLE = 'Colheita';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.colheita.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>

<?php if (!$modoForm): ?>
  <div class="vhead">
    <div>
      <h1>Colheita</h1>
      <div class="vsub">Registro por válvula × safra: previsto × realizado com classificação de qualidade — base da comercialização</div>
    </div>
    <?php if ($podeEditar): ?>
      <a class="vbtn vbtn-primary" href="?novo=1">+ Nova colheita</a>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="valvula" onchange="this.form.submit()">
          <option value="">Todas as válvulas</option>
          <?php foreach ($setoresFiltro as $sf): ?>
            <option value="<?= (int)$sf['id'] ?>"<?= $fSetor === (int)$sf['id'] ? ' selected' : '' ?>><?= h($sf['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub">Custo de colheita (operações categoria colheita):
        <strong class="vnum">R$ <?= numFmt($custoColheita, 2) ?></strong>
        <?= $kgColhidoTotal > 0 && $custoColheita > 0
              ? '· <strong class="vnum">R$ ' . numFmt($custoColheita / $kgColhidoTotal, 4) . '/kg</strong>' : '' ?></span>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma colheita registrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Válvula</th><th>Fazenda / Válvula</th><th>Safra</th><th>Variedade</th>
        <th style="text-align:right">Previsto (kg)</th>
        <th style="text-align:right">Realizado (kg)</th>
        <th style="text-align:right">Faturamento real. (R$)</th>
        <th>Estoque</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_colheita'])) ?>
            <?php if (($r['status'] ?? 'finalizada') === 'pendente'): ?>
              <br><span class="vbadge vb-warn" title="Lançada no app — revise e finalize no escritório">Pendente<?= ($r['origem'] ?? '') === 'app' ? ' (app)' : '' ?></span>
            <?php endif; ?></td>
          <td><strong class="vnum"><?= h($r['valvula'] ?? '—') ?></strong></td>
          <td><?= h($r['fazenda']) ?> — <?= h($r['talhao']) ?></td>
          <td><?= h($r['safra']) ?></td>
          <td><?= h($r['variedade'] ?? '') ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['kg_total_previsto'], 0) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['kg_total_realizado'], 0) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['faturamento_realizado'], 2) ?></td>
          <td><?php 
            $ent = $entradaMap[(int)$r['id']] ?? null;
            $cfg = $cfgMap[(int)$r['id']] ?? null;
            if ($ent): ?>
              <span class="vbadge vb-ok" title="Custo unitário PROVISÓRIO — revalorização no fechamento da safra">
                <?= h((string)($ent['codigo_lote'] ?? 'lote')) ?> · <?= numFmt((float)$ent['quantidade'], 0) ?> kg</span>
              <br><span class="vhint">custo provisório R$ <?= numFmt((float)$ent['custo_unitario'], 4) ?>/kg</span>
            <?php elseif ($cfg && $cfg['prod']): ?>
              <?php $kgEntrada = (int)$cfg['exige_classificacao'] === 1
                  ? ($cfg['kg_aprovado'] !== null ? (float)$cfg['kg_aprovado'] : null)
                  : (float)$r['kg_total_realizado']; ?>
              <span class="vhint"><?= $kgEntrada !== null
                  ? 'a estocar: ' . numFmt($kgEntrada, 0) . ' kg' . ((int)$cfg['exige_classificacao'] === 1 ? ' (aprovado)' : '')
                  : 'aguarda CLASSIFICAÇÃO realizada' ?></span>
            <?php else: ?>
              <span class="vhint" title="Configure o produto gerado pela colheita em Cadastros → Culturas">sem produto configurado</span>
            <?php endif; ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar && !$ent && $cfg && $cfg['prod']): ?>
              <?= vero_btn_icone_post(vero_ico_receber(), 'Confirmar entrada no estoque', 'entrada_confirmar', (int)$r['id']) ?>
            <?php endif; ?>
            <?php if ($podeEditar && $ent): ?>
              <?= vero_btn_icone_post(vero_ico_voltar(), 'Estornar entrada (devolve o lote, mantém o registro)', 'entrada_estornar', (int)$r['id'], 'Estornar a entrada no estoque desta colheita?') ?>
            <?php endif; ?>
            <?php if ($podeEditar && ($r['status'] ?? 'finalizada') === 'pendente'): ?>
              <?= vero_btn_icone(vero_ico_check(), 'Finalizar — revisar receita/classificação e gravar o realizado', '', '?editar=' . (int)$r['id']) ?>
            <?php elseif ($podeEditar): ?>
              <?= vero_btn_editar((int)$r['id']) ?>
            <?php endif; ?>
            <?php if (vero_can('agro.colheita.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este registro de colheita?') ?>
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
    <div class="vflash vflash-erro">Sem permissão para registrar colheitas.</div>
  <?php else: ?>
  <div class="vhead">
    <div>
      <h1><?= $edit ? 'Editar colheita' : 'Nova colheita' ?></h1>
      <div class="vsub">kg total = kg/ha × área da válvula · digite o kg por categoria e a % é calculada · faturamento = kg × preço (perdidos sem preço)</div>
    </div>
    <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/colheita/index">← Voltar à lista</a>
  </div>

  <?php

  $FORM_ACTION = '';
  $FORM_ORIGEM = '';
  $FORM_CANCEL = BIOS_BASE . '/colheita/index';
  require __DIR__ . '/_form.php';
  ?>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
