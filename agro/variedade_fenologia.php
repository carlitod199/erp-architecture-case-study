<?php
/* ============================================================
   VERO — Gestão Agrícola / Fenologia da variedade  (CRUD direto)
   Rota: /agro/variedade_fenologia.php?variedade_id=X
   Guard/escrita: REUSA a permissão de variedades
     VER  → guard de página agricola.variedades
     EDIT → agro.variedades.editar
   Tabelas (migration 157):
     agro_variedade_fenologia  (1 linha por variedade — sempre vigente)
     agro_variedade_fases      (fases; dia 0 = poda; volume mm/dia)
   Regras do gestor (17/07 — SIMPLIFICAÇÃO): sem versão/rascunho/aprovação.
   As fases são gravadas DIRETO conforme vão sendo adicionadas. Mantém-se
   UMA fenologia por variedade (status 'aprovada' por compat com os consumidores
   — resolver/gate/apontamento — que a tratam como a vigente). A contiguidade
   desde a poda (dia 0) vira apenas um AVISO orientativo, não bloqueia nada.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_fenologia_helper.php';   /* mig 213: fases da SAFRA (manga) */

const T_FEN   = 'agro_variedade_fenologia';
const T_FASES = 'agro_variedade_fases';

/* ── helpers de contexto ────────────────────────────────────── */

/** Carrega a variedade dentro do tenant (ou null). */
function fen_variedade(int $id): ?array
{
    if ($id <= 0) return null;
    return vero_row(
        "SELECT v.*, c.nome AS cultura_nome
           FROM agro_variedades v
           JOIN agro_culturas c ON c.id = v.cultura_id
          WHERE v.id = :id AND v.tenant_id = :t",
        [':id' => $id, ':t' => vero_tenant()]
    );
}

/** A (única) fenologia da variedade, ou null se ainda não existir. */
function fen_atual(int $variedadeId): ?array
{
    return vero_row(
        "SELECT * FROM " . T_FEN . "
          WHERE tenant_id = :t AND variedade_id = :v AND ativo = 1
          ORDER BY versao DESC, id DESC LIMIT 1",
        [':t' => vero_tenant(), ':v' => $variedadeId]
    );
}

/** Obtém-ou-cria a fenologia da variedade e devolve o id. Mantém 'aprovada'
    (vigente) — os consumidores filtram por esse status. */
function fen_atual_criar(int $variedadeId): int
{
    $f = fen_atual($variedadeId);
    if ($f) {
        if ((string)$f['status'] !== 'aprovada') {
            vero_update(T_FEN, (int)$f['id'], [
                'status'       => 'aprovada',
                'aprovado_por' => vero_uid(),
                'aprovado_em'  => date('Y-m-d H:i:s'),
            ]);
        }
        return (int)$f['id'];
    }
    return vero_insert(T_FEN, [
        'variedade_id' => $variedadeId,
        'versao'       => 1,
        'status'       => 'aprovada',
        'aprovado_por' => vero_uid(),
        'aprovado_em'  => date('Y-m-d H:i:s'),
        'ativo'        => 1,
    ]);
}

/** Fases da fenologia, ordenadas por dia_inicio. */
function fen_fases(int $fenologiaId): array
{
    return vero_rows(
        "SELECT * FROM " . T_FASES . "
          WHERE tenant_id = :t AND fenologia_id = :f AND ativo = 1
          ORDER BY dia_inicio, id",
        [':t' => vero_tenant(), ':f' => $fenologiaId]
    );
}

/**
 * Aviso de CONTIGUIDADE (orientativo — não bloqueia). Ordena por dia_inicio;
 * sinaliza: 1ª fase deve começar no dia 0 (poda); dia_fim > dia_inicio;
 * dia_inicio de cada fase = dia_fim da anterior (sem lacuna/sobreposição).
 * Retorna string do 1º problema, ou null se OK.
 */
function fen_validar_contiguidade(array $fases): ?string
{
    if (!$fases) return 'Cadastre ao menos a fase da poda (dia 0).';
    usort($fases, static fn($a, $b) => (int)$a['dia_inicio'] <=> (int)$b['dia_inicio']);

    if ((int)$fases[0]['dia_inicio'] !== 0) {
        return 'A primeira fase deve começar no dia 0 (poda). Hoje começa no dia '
            . (int)$fases[0]['dia_inicio'] . '.';
    }
    $prevFim = null;
    foreach ($fases as $f) {
        $ini  = (int)$f['dia_inicio'];
        $fim  = (int)$f['dia_fim'];
        $nome = (string)$f['nome'];
        if ($fim <= $ini) {
            return "Fase \"{$nome}\": o dia final ({$fim}) deve ser maior que o dia inicial ({$ini}).";
        }
        if ($prevFim !== null && $ini !== $prevFim) {
            if ($ini > $prevFim) {
                return "Há uma LACUNA entre o dia {$prevFim} e o dia {$ini} (fase \"{$nome}\").";
            }
            return "Há SOBREPOSIÇÃO: a fase \"{$nome}\" começa no dia {$ini}, antes do fim da anterior (dia {$prevFim}).";
        }
        $prevFim = $fim;
    }
    return null;
}

/* variedade_id no querystring é o eixo da tela inteira */
$variedadeId = (int)($_GET['variedade_id'] ?? ($_POST['variedade_id'] ?? 0));
$variedade   = fen_variedade($variedadeId);

/* ── POST ───────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!$variedade) {
        vero_flash('erro', 'Variedade inválida.');
        vero_redirect('?');
    }
    $acao   = (string)($_POST['acao'] ?? '');
    $selfQS = '?variedade_id=' . $variedadeId;

    /* Adiciona/edita uma fase — grava DIRETO (cria a fenologia na 1ª fase). */
    if ($acao === 'fase_salvar') {
        vero_require('agro.variedades.editar');

        $nome      = vero_str('nome', 80);
        $diaInicio = vero_int('dia_inicio');
        $diaFim    = vero_int('dia_fim');
        $volume    = vero_dec('volume_mm_dia');
        $calda     = vero_dec('volume_calda_ha_l');
        $obs       = vero_str('observacao', 255);
        $faseId    = vero_int('fase_id');

        if ($nome === null || $diaInicio === null || $diaFim === null) {
            vero_flash('erro', 'Nome, dia inicial e dia final são obrigatórios.');
            vero_redirect($selfQS);
        }
        if ($diaInicio < 0 || $diaFim < 0) {
            vero_flash('erro', 'Os dias são contados desde a poda (dia 0) — não podem ser negativos.');
            vero_redirect($selfQS);
        }
        if ($diaFim <= $diaInicio) {
            vero_flash('erro', "O dia final ({$diaFim}) deve ser maior que o dia inicial ({$diaInicio}).");
            vero_redirect($selfQS);
        }
        if ($volume !== null && $volume < 0) {
            vero_flash('erro', 'O volume (mm/dia) não pode ser negativo.');
            vero_redirect($selfQS);
        }
        if ($calda !== null && $calda < 0) {
            vero_flash('erro', 'O volume de calda (L/ha) não pode ser negativo.');
            vero_redirect($selfQS);
        }

        $fenId = fen_atual_criar($variedadeId);
        $dados = [
            'nome'              => $nome,
            'dia_inicio'        => $diaInicio,
            'dia_fim'           => $diaFim,
            'volume_mm_dia'     => $volume,
            'volume_calda_ha_l' => $calda,
            'observacao'        => $obs,
        ];

        if ($faseId) {
            /* a fase precisa pertencer a ESTA fenologia (blindagem tenant) */
            $ok = vero_val(
                "SELECT id FROM " . T_FASES . "
                  WHERE id = :id AND tenant_id = :t AND fenologia_id = :f",
                [':id' => $faseId, ':t' => vero_tenant(), ':f' => $fenId]
            );
            if (!$ok) {
                vero_flash('erro', 'Fase inválida.');
                vero_redirect($selfQS);
            }
            vero_update(T_FASES, $faseId, $dados);
            vero_flash('ok', "Fase \"{$nome}\" atualizada.");
        } else {
            $ordem = (int)vero_val(
                "SELECT COALESCE(MAX(ordem),0)+1 FROM " . T_FASES . "
                  WHERE tenant_id = :t AND fenologia_id = :f",
                [':t' => vero_tenant(), ':f' => $fenId]
            );
            $dados['fenologia_id'] = $fenId;
            $dados['ordem']        = $ordem;
            $dados['ativo']        = 1;
            vero_insert(T_FASES, $dados);
            vero_flash('ok', "Fase \"{$nome}\" adicionada.");
        }

        /* aviso (não trava) se ainda não está contíguo desde a poda */
        $erro = fen_validar_contiguidade(fen_fases($fenId));
        if ($erro !== null) {
            vero_flash('aviso', 'Atenção — a fenologia ainda não está contígua: ' . $erro);
        }
        vero_redirect($selfQS);
    }

    /* ── Fases da SAFRA (mig 213 — manga): ajuste por válvula, na seção
       "Nas safras ativas" desta tela (decisão do gestor 25/08: tudo de
       fenologia AQUI, sem tela separada). O 1º ajuste grava a instância
       por baixo (invisível); reverter volta ao template. ── */
    if ($acao === 'safra_ajustar') {
        vero_require('agro.safras.editar');
        $stId    = (int)($_POST['st_id'] ?? 0);
        $ordem   = (int)($_POST['ordem'] ?? 0);
        $dataIni = vero_date('data_inicio');
        $motivo  = trim((string)($_POST['motivo'] ?? ''));
        if (!$stId || !$ordem || $dataIni === null) {
            vero_flash('erro', 'Escolha a válvula, a fase e a nova data de início.');
            vero_redirect($selfQS . '#safras');
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $m = vero_a1_safra_fases_materializar($stId);
            if (!$m['ok']) throw new RuntimeException($m['motivo']);
            $fase = vero_row(
                "SELECT id FROM agro_safra_fases
                  WHERE tenant_id = :t AND safra_talhao_id = :st AND ativo = 1 AND ordem = :o",
                [':t' => vero_tenant(), ':st' => $stId, ':o' => $ordem]);
            if (!$fase) throw new RuntimeException('Fase não encontrada para esta válvula.');
            $r = vero_a1_safra_fase_ajustar($stId, (int)$fase['id'], $dataIni, $motivo);
            if (!$r['ok']) throw new RuntimeException($r['motivo']);
            $pdo->commit();
            vero_flash('ok', $r['motivo']);
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect($selfQS . '#safras');
    }
    if ($acao === 'safra_reverter') {
        vero_require('agro.safras.editar');
        $r = vero_a1_safra_fases_reverter((int)($_POST['st_id'] ?? 0));
        vero_flash($r['ok'] ? 'ok' : 'aviso', $r['motivo']);
        vero_redirect($selfQS . '#safras');
    }

    /* Remove uma fase (hard delete). */
    if ($acao === 'fase_excluir') {
        vero_require('agro.variedades.editar');
        $fen    = fen_atual($variedadeId);
        $faseId = vero_int('fase_id');
        if ($fen && $faseId) {
            vero_pdo()->prepare(
                "DELETE FROM " . T_FASES . " WHERE tenant_id=? AND id=? AND fenologia_id=? LIMIT 1"
            )->execute([vero_tenant(), $faseId, (int)$fen['id']]);
            vero_flash('ok', 'Fase removida.');
        }
        vero_redirect($selfQS);
    }

    vero_redirect($selfQS);
}

/* ── GET / render ───────────────────────────────────────────── */
if (!$variedade) {
    $GUARD      = ['macro' => 'agricola', 'micro' => 'variedades'];
    $PAGE_VIEW  = 'agricola_variedades';
    $PAGE_TITLE = 'Fenologia da variedade';
    require __DIR__ . '/../includes/agro_header.php';
    echo '<div class="vwrap"><div class="vcard"><div class="vempty">'
        . 'Variedade não encontrada. Volte para o <a href="' . BIOS_BASE . '/agro/variedades">catálogo de variedades</a>.'
        . '</div></div></div>';
    require __DIR__ . '/../includes/agro_footer_simple.php';
    return;
}

$fen        = fen_atual($variedadeId);
$fases      = $fen ? fen_fases((int)$fen['id']) : [];
$contigErro = $fases ? fen_validar_contiguidade($fases) : null;

/* fase em edição (modal) */
$faseEdit = null;
if ($fen && !empty($_GET['editar_fase'])) {
    $faseEdit = vero_row(
        "SELECT * FROM " . T_FASES . " WHERE id=:id AND tenant_id=:t AND fenologia_id=:f",
        [':id' => (int)$_GET['editar_fase'], ':t' => vero_tenant(), ':f' => (int)$fen['id']]
    );
}
/* próximo dia inicial sugerido = maior dia_fim (contiguidade fácil) */
$proxInicio = 0;
foreach ($fases as $f) { $proxInicio = max($proxInicio, (int)$f['dia_fim']); }

/* ── Fases nas SAFRAS ativas (mig 213 — manga): vínculos safra×válvula das
   válvulas DESTA variedade, com a fase corrente e o estado (template/ajustada).
   O detalhe expande na própria linha (ação "Ajustar"). ── */
$hoje = date('Y-m-d');
$safraVinculos = vero_rows(
    "SELECT st.id AS st_id, st.data_poda, sa.identificacao AS safra,
            tl.codigo AS valvula, fz.nome AS fazenda,
            COALESCE(st.data_poda, sa.data_inicio) AS dia0
       FROM agro_safra_talhoes st
       JOIN agro_safras  sa ON sa.id = st.safra_id AND sa.tenant_id = st.tenant_id
       JOIN agro_talhoes tl ON tl.id = st.talhao_id AND tl.tenant_id = st.tenant_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
      WHERE st.tenant_id = :t AND tl.variedade_id = :v AND sa.status = 'ativa'
      ORDER BY sa.id DESC, fz.nome, tl.codigo",
    [':t' => vero_tenant(), ':v' => $variedadeId]);
foreach ($safraVinculos as $k => $sv) {
    $box = vero_a1_safra_fases_do_vinculo((int)$sv['st_id']);
    $safraVinculos[$k]['fases']  = $box['fases'];
    $safraVinculos[$k]['estado'] = $box['origem'];        /* instancia|template|nenhuma */
    $atual = null;
    foreach ($box['fases'] as $f) {
        if ($f['data_inicio'] <= $hoje && $f['data_fim'] >= $hoje) { $atual = $f; break; }
    }
    $safraVinculos[$k]['fase_atual'] = $atual;
}
$podeAjustarSafra = vero_can('agro.safras.editar');

$GUARD      = ['macro' => 'agricola', 'micro' => 'variedades'];
$PAGE_VIEW  = 'agricola_variedades';
$PAGE_TITLE = 'Fenologia — ' . $variedade['nome'];
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.variedades.editar');
$selfQS     = '?variedade_id=' . $variedadeId;
?>
<div class="vwrap">
  <?= vero_flash_html() ?>

  <header class="vero-topbar">
    <h1 class="vero-topbar__title">Fenologia — <?= h($variedade['nome']) ?>
      <span class="vhint" style="font-weight:400">· <?= h($variedade['cultura_nome']) ?></span></h1>
    <div class="vero-topbar__actions">
      <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/agro/variedades">← Variedades</a>
    </div>
  </header>

  <!-- Fases -->
  <div class="vcard">
    <div class="vtoolbar" style="justify-content:space-between">
      <strong>Fases fenológicas
        <span class="vhint">— dias contados desde a PODA (dia 0); volume em mm/dia</span></strong>
      <?php if ($podeEditar): ?>
        <button class="vbtn vbtn-primary vbtn-sm" type="button" onclick="vModalOpen('vm-form')">+ Adicionar fase</button>
      <?php endif; ?>
    </div>

    <?php if ($contigErro !== null && $fases): ?>
      <div class="vflash vflash-aviso" style="margin:12px 16px">⚠ <?= h($contigErro) ?></div>
    <?php elseif ($fases): ?>
      <div class="vflash vflash-ok" style="margin:12px 16px">✓ Fases contíguas desde a poda (dia 0).</div>
    <?php endif; ?>

    <?php if (!$fases): ?>
      <div class="vempty">
        Esta variedade ainda não tem fases cadastradas.
        <?php if ($podeEditar): ?>Clique em <strong>“+ Adicionar fase”</strong> e comece pela <strong>poda (dia 0)</strong> — cada fase já fica gravada.<?php endif; ?>
      </div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th style="text-align:right">#</th>
        <th>Fase</th>
        <th style="text-align:right">Dia início</th>
        <th style="text-align:right">Dia fim</th>
        <th style="text-align:right">Duração</th>
        <th style="text-align:right">Volume (mm/dia)</th>
        <th style="text-align:right">m³/ha/dia</th>
        <th>Observação</th>
        <?php if ($podeEditar): ?><th style="text-align:right">Ações</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php $i = 0; foreach ($fases as $f): $i++; ?>
        <tr>
          <td class="vnum" style="text-align:right"><?= $i ?></td>
          <td><strong><?= h($f['nome']) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= (int)$f['dia_inicio'] ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$f['dia_fim'] ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$f['dia_fim'] - (int)$f['dia_inicio'] ?> d</td>
          <td class="vnum" style="text-align:right"><?= $f['volume_mm_dia'] !== null ? numFmt((float)$f['volume_mm_dia'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $f['volume_mm_dia'] !== null ? numFmt((float)$f['volume_mm_dia'] * 10, 1) : '—' ?></td>
          <td class="vhint"><?= h((string)($f['observacao'] ?? '')) ?: '—' ?></td>
          <?php if ($podeEditar): ?>
          <td><div class="vactions">
            <?php $qs = $_GET; $qs['editar_fase'] = (int)$f['id']; $qs['variedade_id'] = $variedadeId; ?>
            <a class="vicon vicon-edit" href="?<?= h(http_build_query($qs)) ?>" title="Editar fase" aria-label="Editar fase"><?= vero_ico_lapis() ?></a>
            <form method="post" data-confirm="Remover esta fase?" data-confirm-danger data-confirm-ok="Remover" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="fase_excluir">
              <input type="hidden" name="variedade_id" value="<?= $variedadeId ?>">
              <input type="hidden" name="fase_id" value="<?= (int)$f['id'] ?>">
              <button class="vicon vicon-del" type="submit" title="Remover fase" aria-label="Remover fase"><?= vero_ico_lixeira() ?></button>
            </form>
          </div></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 16px">
      Conversão informativa: <strong>m³/ha/dia = mm/dia × 10</strong> (independe da densidade).
      L/planta/dia depende da densidade do talhão — calculado no uso (safra×talhão), não aqui.
    </div>
    <?php endif; ?>
  </div>

  <?php /* ── Nas SAFRAS ativas (mig 213 — manga): fase corrente por válvula e
           ação de AJUSTE/ADIANTAMENTO na própria linha. A manga muda a cada
           florada; o ajuste vale só para a safra — o template acima não muda. */ ?>
  <?php if ($safraVinculos): ?>
  <div class="vcard" id="safras" style="margin-top:14px">
    <div class="vtoolbar">
      <strong>Nas safras ativas
        <span class="vhint">— fase corrente por válvula; ajuste/adiantamento vale só para a safra</span></strong>
    </div>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th>Safra</th><th>Fazenda / válvula</th><th>Dia 0</th>
        <th>Fase atual</th><th>Situação</th>
        <?php if ($podeAjustarSafra): ?><th style="text-align:right">Ações</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($safraVinculos as $sv): $stId = (int)$sv['st_id']; ?>
        <tr>
          <td class="vnum"><?= h((string)$sv['safra']) ?></td>
          <td><strong><?= h((string)$sv['fazenda']) ?></strong> · <?= h((string)$sv['valvula']) ?></td>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$sv['dia0'])) ?><?= $sv['data_poda'] ? '' : ' <span class="vhint">(início da safra)</span>' ?></td>
          <td><?= $sv['fase_atual']
              ? '<strong>' . h((string)$sv['fase_atual']['nome']) . '</strong> <span class="vhint">até '
                . date('d/m', strtotime((string)$sv['fase_atual']['data_fim'])) . '</span>'
              : '<span class="vhint">fora do ciclo</span>' ?></td>
          <td><?= $sv['estado'] === 'instancia'
              ? '<span class="vbadge vb-warn">ajustada</span>'
              : ($sv['estado'] === 'template' ? '<span class="vhint">template</span>' : '<span class="vhint">—</span>') ?></td>
          <?php if ($podeAjustarSafra): ?>
          <td><div class="vactions">
            <?php if ($sv['fases']): ?>
              <button class="vbtn vbtn-ghost vbtn-sm" type="button"
                      onclick="var d=document.getElementById('sfx-<?= $stId ?>');d.style.display=d.style.display==='none'?'':'none'">Ajustar</button>
              <?php if ($sv['estado'] === 'instancia'): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Descartar os ajustes desta safra e voltar às datas do template?')">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="safra_reverter">
                <input type="hidden" name="variedade_id" value="<?= $variedadeId ?>">
                <input type="hidden" name="st_id" value="<?= $stId ?>">
                <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Voltar ao template</button>
              </form>
              <?php endif; ?>
            <?php else: ?>
              <span class="vhint">cadastre as fases acima</span>
            <?php endif; ?>
          </div></td>
          <?php endif; ?>
        </tr>
        <?php if ($sv['fases'] && $podeAjustarSafra): ?>
        <tr id="sfx-<?= $stId ?>" style="display:none">
          <td colspan="6" style="background:#FBF8F2;padding:12px 16px">
            <table class="vtable" style="margin-bottom:10px">
              <thead><tr><th>#</th><th>Fase</th><th>Início</th><th>Fim</th><th>Situação</th><th>Motivo</th></tr></thead>
              <tbody>
              <?php foreach ($sv['fases'] as $f):
                  $ehAtual = $sv['fase_atual'] && (int)$f['ordem'] === (int)$sv['fase_atual']['ordem']; ?>
                <tr<?= $ehAtual ? ' style="background:#EEF4F3"' : '' ?>>
                  <td class="vnum"><?= (int)$f['ordem'] ?></td>
                  <td><?= h((string)$f['nome']) ?><?= $ehAtual ? ' <span class="vbadge vb-ok">atual</span>' : '' ?></td>
                  <td class="vnum"><?= date('d/m/Y', strtotime((string)$f['data_inicio'])) ?></td>
                  <td class="vnum"><?= date('d/m/Y', strtotime((string)$f['data_fim'])) ?></td>
                  <td><?= $f['origem'] === 'ajuste' ? '<span class="vbadge vb-warn">ajustada</span>' : '<span class="vhint">template</span>' ?></td>
                  <td class="vhint"><?= h((string)($f['motivo'] ?? '')) ?: '—' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="safra_ajustar">
              <input type="hidden" name="variedade_id" value="<?= $variedadeId ?>">
              <input type="hidden" name="st_id" value="<?= $stId ?>">
              <div class="vfield" style="min-width:200px">
                <label>Adiantar/alterar a fase</label>
                <select name="ordem" required>
                  <option value="">— Fase —</option>
                  <?php foreach ($sv['fases'] as $f): ?>
                    <option value="<?= (int)$f['ordem'] ?>"><?= (int)$f['ordem'] ?> · <?= h((string)$f['nome']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="vfield">
                <label>Novo início</label>
                <input type="date" name="data_inicio" value="<?= $hoje ?>" required>
              </div>
              <div class="vfield" style="flex:1;min-width:200px">
                <label>Motivo (trilha do RT)</label>
                <input type="text" name="motivo" maxlength="255" placeholder="Ex.: florada antecipada após indução">
              </div>
              <button class="vbtn vbtn-primary" type="submit">Aplicar</button>
            </form>
            <div class="vhint" style="margin-top:6px">A fase anterior encerra na véspera; as seguintes deslocam mantendo as durações do template.</div>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $faseEdit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $faseEdit ? 'Editar fase' : 'Adicionar fase' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="fase_salvar">
      <input type="hidden" name="variedade_id" value="<?= $variedadeId ?>">
      <input type="hidden" name="fase_id" value="<?= $faseEdit ? (int)$faseEdit['id'] : '' ?>">
      <div class="vgrid" style="grid-template-columns:repeat(2,1fr)">
        <div class="full"><?= vero_f_text('nome', 'Nome da fase', $faseEdit['nome'] ?? '', true, 'Ex.: Poda, Brotação, Pré-flor, Florada') ?></div>
        <?= vero_f_text('dia_inicio', 'Dia inicial (desde a poda)', $faseEdit ? (string)(int)$faseEdit['dia_inicio'] : (string)$proxInicio, true, 'A 1ª fase começa em 0 (poda); as demais no dia final da anterior') ?>
        <?= vero_f_text('dia_fim', 'Dia final (desde a poda)', $faseEdit ? (string)(int)$faseEdit['dia_fim'] : '', true, 'Deve ser maior que o dia inicial') ?>
        <?= vero_f_text('volume_mm_dia', 'Volume de irrigação (mm/dia)', $faseEdit && $faseEdit['volume_mm_dia'] !== null ? numFmt((float)$faseEdit['volume_mm_dia'], 2) : '', false, 'Lâmina diária em mm/dia — m³/ha/dia = mm × 10') ?>
        <?= vero_f_text('volume_calda_ha_l', 'Volume de calda (L/ha)', $faseEdit && ($faseEdit['volume_calda_ha_l'] ?? null) !== null ? numFmt((float)$faseEdit['volume_calda_ha_l'], 0) : '', false, 'Calda de pulverização — a DF puxa por esta fase (cresce com o dossel)') ?>
        <div class="full"><?= vero_f_text('observacao', 'Observação', $faseEdit['observacao'] ?? '', false, 'Opcional — manejo, sensibilidade, marco') ?></div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar fase</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
