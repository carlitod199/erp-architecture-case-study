<?php
/* ============================================================
   VERO — Packing House / Recepção de cargas  (Onda 1 · tarefa 3)
   Rota: /packing/recepcao.php · Guard: packing.recepcao (view: packing_recepcao)
   Recebe cargas da colheita (colheita_cargas destino='packing') na UNIDADE
   ATIVA do contexto de packing (ph_ctx). O operador seleciona as cargas que
   chegaram, informa pesagem (bruto/tara) + placa/motorista e o método de
   rastreabilidade de cada item; "Avaliar" mostra os 5 gates de aceitação
   (carência, certificação, rastreabilidade, licença, SO2) com cor; "Aceitar"
   cria a recepção (ph_recepcao_criar) e devolve número + status.
   Toda a regra vive no serviço packing/_ph_recepcao.php (fonte da verdade);
   esta tela só coleta, valida contra o tenant e apresenta.
   Tabelas: ph_recepcoes + ph_recepcao_itens (migration 201).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_ph_services.php';
require_once __DIR__ . '/_ph_recepcao.php';

/* Método de rastreabilidade por item — VARCHAR + whitelist (convenção). */
const REC_METODOS = [
    'segregacao'             => 'Segregação',
    'identidade_preservada'  => 'Identidade preservada',
];

/* Parsers de valores INDEXADOS (vero_* leem $_POST por chave; aqui os campos
   das linhas vêm em arrays it_*[carga_id], então parseamos o valor recebido). */
function rec_str_v($v, int $max): ?string
{
    $v = trim((string)$v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function rec_int_v($v): ?int
{
    $v = trim((string)$v);
    return ($v === '' || !is_numeric($v)) ? null : (int)$v;
}
function rec_dec_v($v): ?float
{
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace(' ', '', $v);
    if (str_contains($v, ',')) {
        $v = str_replace(['.', ','], ['', '.'], $v);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
        $v = str_replace('.', '', $v);
    }
    return is_numeric($v) ? (float)$v : null;
}

/**
 * Monta os itens da seleção a partir do POST, restrito às cargas PENDENTES
 * (que já vêm escopadas por tenant + unidade em ph_recepcao_cargas_pendentes:
 * só entra o que está em $cargasById → valida a FK contra o tenant). Devolve
 * dois arrays: o enxuto p/ os gates (5 chaves do contrato) e o rico p/ o insert.
 * $produtorId (referência solta — sem tabela de produtor no MVP) é aplicado a
 * todos os itens da recepção.
 * @return array{0: array<int,array>, 1: array<int,array>}
 */
function rec_coletar(array $cargasById, ?int $produtorId): array
{
    $selIds = array_map('intval', (array)($_POST['sel'] ?? []));
    $mMet   = (array)($_POST['it_metodo'] ?? []);
    $mPeso  = (array)($_POST['it_peso'] ?? []);
    $mCont  = (array)($_POST['it_contentores'] ?? []);
    $mTemp  = (array)($_POST['it_temp'] ?? []);
    $mTurma = (array)($_POST['it_turma'] ?? []);

    $gates = [];
    $criar = [];
    foreach ($selIds as $cid) {
        if (!isset($cargasById[$cid])) continue; // fora das pendentes do tenant/unidade → descarta
        $c = $cargasById[$cid];

        $met = (string)($mMet[$cid] ?? '');
        if (!isset(REC_METODOS[$met])) $met = 'segregacao';

        $peso = rec_dec_v($mPeso[$cid] ?? '');
        if ($peso === null) $peso = $c['peso_kg'] !== null ? (float)$c['peso_kg'] : null;

        $talhaoId    = $c['talhao_id'] !== null ? (int)$c['talhao_id'] : null;
        $variedadeId = $c['variedade_id'] !== null ? (int)$c['variedade_id'] : null;
        $safraTId    = $c['safra_talhao_id'] !== null ? (int)$c['safra_talhao_id'] : null;
        $loteId      = $c['lote_estoque_id'] !== null ? (int)$c['lote_estoque_id'] : null;
        $colhido     = $c['colhido_em'] !== null ? (string)$c['colhido_em'] : null;

        $gates[] = [
            'talhao_id'              => $talhaoId,
            'variedade_id'           => $variedadeId,
            'produtor_id'            => $produtorId,
            'colhido_em'             => $colhido,
            'metodo_rastreabilidade' => $met,
        ];
        $criar[] = [
            'colheita_carga_id'      => $cid,
            'lote_estoque_id'        => $loteId,
            'talhao_id'              => $talhaoId,
            'safra_talhao_id'        => $safraTId,
            'variedade_id'           => $variedadeId,
            'produtor_id'            => $produtorId,
            'colhido_em'             => $colhido,
            'peso_kg'                => $peso,
            'metodo_rastreabilidade' => $met,
            'n_contentores'          => rec_int_v($mCont[$cid] ?? ''),
            'temperatura_chegada_c'  => rec_dec_v($mTemp[$cid] ?? ''),
            'turma_colheita'         => rec_str_v($mTurma[$cid] ?? '', 60),
        ];
    }
    return [$gates, $criar];
}

/* ── Contexto ativo + cargas pendentes (base do POST e do render) ── */
$ctx        = ph_ctx_get();
$unidadeId  = (int)($ctx['unidade_id'] ?? 0);
$cargasPend = $unidadeId ? ph_recepcao_cargas_pendentes($unidadeId) : [];
$cargasById = [];
foreach ($cargasPend as $c) { $cargasById[(int)$c['carga_id']] = $c; }

$avaliacao = null; // resultado de ph_recepcao_gates a renderizar após "Avaliar"

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    /* Painel embutido: define o contexto ativo (unidade + turno) aqui mesmo. */
    if ($acao === 'set_ctx') {
        ph_ctx_set(vero_int('unidade_id') ?: null, vero_str('turno', 20));
        vero_flash('ok', 'Contexto de packing atualizado.');
        vero_redirect();
    }

    if (!$unidadeId) {
        vero_flash('erro', 'Defina a unidade de packing no contexto antes de receber cargas.');
        vero_redirect();
    }

    /* Mercado de destino (opcional) — pauta o gate de licença varietal/SO2.
       FK sempre validada contra o tenant. */
    $mercadoId = vero_int('h_mercado');
    if ($mercadoId) {
        $okMerc = vero_val("SELECT id FROM ph_mercados WHERE id=:i AND tenant_id=:t",
            [':i' => $mercadoId, ':t' => vero_tenant()]);
        $mercadoId = $okMerc ? (int)$okMerc : null;
    }
    $produtorId = vero_int('h_produtor'); // referência solta (sem tabela no MVP)

    /* ── acao='avaliar' (somente leitura): calcula os 5 gates sobre a seleção
       e re-renderiza a página com o painel de resultado (sem PRG). ── */
    if ($acao === 'avaliar') {
        [$gates, ] = rec_coletar($cargasById, $produtorId);
        if (!$gates) {
            vero_flash('aviso', 'Selecione ao menos uma carga para avaliar os gates.');
            vero_redirect();
        }
        $avaliacao = ph_recepcao_gates($gates, $mercadoId);
        /* cai no render abaixo (o form repovoa a partir do $_POST) */
    }

    /* ── acao='aceitar' (escrita): cria a recepção + itens. O serviço grava os
       gates e define status='aceita' (ou 'rejeitada' se algum gate bloqueia). ── */
    if ($acao === 'aceitar') {
        vero_require('packing.recepcao.editar');
        [, $criarItens] = rec_coletar($cargasById, $produtorId);
        if (!$criarItens) {
            vero_flash('erro', 'Selecione ao menos uma carga para criar a recepção.');
            vero_redirect();
        }
        $header = [
            'produtor_id'    => $produtorId,
            'contrato_id'    => null,
            'veiculo_placa'  => vero_str('h_placa', 10),
            'motorista'      => vero_str('h_motorista', 120),
            'transportadora' => vero_str('h_transportadora', 120),
            'chegou_em'      => date('Y-m-d H:i:s'),
            'peso_bruto_kg'  => vero_dec('h_peso_bruto'),
            'peso_tara_kg'   => vero_dec('h_peso_tara'),
            'observacao'     => vero_str('h_obs', 255),
            'mercado_id'     => $mercadoId, // p/ os gates — NÃO é coluna de ph_recepcoes
        ];
        try {
            $recId = ph_recepcao_criar($unidadeId, $header, $criarItens);
        } catch (Throwable $e) {
            vero_flash('erro', 'Não foi possível criar a recepção: ' . h($e->getMessage()));
            vero_redirect();
        }
        $rec = vero_row("SELECT numero, status FROM ph_recepcoes WHERE id=:i AND tenant_id=:t",
            [':i' => $recId, ':t' => vero_tenant()]);
        $numero = (string)($rec['numero'] ?? ('#' . $recId));
        $status = (string)($rec['status'] ?? '');
        if ($status === 'rejeitada') {
            vero_flash('aviso', 'Recepção ' . h($numero) . ' registrada como REJEITADA — um ou mais gates bloquearam a aceitação. Verifique os detalhes antes de liberar a carga.');
        } else {
            vero_flash('ok', 'Recepção ' . h($numero) . ' ACEITA — ' . count($criarItens) . ' carga(s) recebida(s) na unidade.');
        }
        vero_redirect();
    }
}

/* ── Dados de apoio do render (só quando há unidade) ───────────── */
$uniAtual  = ph_ctx_unidade_atual();
$unidades  = ph_ctx_unidades();
$proxNum   = $unidadeId ? ph_recepcao_numero($unidadeId) : '';
$mercados  = $unidadeId ? vero_options('ph_mercados', 'nome') : [];

/* Repovoamento do form após "Avaliar" (lê direto do $_POST — vazio em GET). */
$pSel   = array_flip(array_map('intval', (array)($_POST['sel'] ?? [])));
$pMet   = (array)($_POST['it_metodo'] ?? []);
$pPeso  = (array)($_POST['it_peso'] ?? []);
$pCont  = (array)($_POST['it_contentores'] ?? []);
$pTemp  = (array)($_POST['it_temp'] ?? []);
$pTurma = (array)($_POST['it_turma'] ?? []);

/** Ponto de status do DS (vbadge) pelo status do gate. */
function rec_gate_classe(string $st): string
{
    return match ($st) {
        'ok'       => 'vb-ok',
        'aviso'    => 'vb-warn',
        'bloqueio' => 'vb-off',
        default    => '',
    };
}
/** Ponto de status do DS (vbadge) pela cor do relógio de frio. */
function rec_relogio_classe(string $cor): string
{
    return match ($cor) {
        'verde'    => 'vb-ok',
        'amarelo'  => 'vb-warn',
        'vermelho' => 'vb-off',
        default    => '',
    };
}

$GUARD      = ['macro' => 'packing', 'micro' => 'recepcao'];
$PAGE_VIEW  = 'packing_recepcao';
$PAGE_TITLE = 'Recepção de Cargas';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('packing.recepcao.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <header class="vero-topbar">
    <h1 class="vero-topbar__title">Recepção de Cargas</h1>
    <div class="vero-topbar__actions">
      <?php if ($unidades): ?>
        <button type="button" class="vbtn vbtn-ghost" onclick="vModalOpen('vm-ctx')" title="Unidade de trabalho">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="M3 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M15 21V9h4a2 2 0 0 1 2 2v10M7 7h2M7 11h2M7 15h2"/></svg>
          <?= $unidadeId && $uniAtual
                ? h((string)$uniAtual['nome']) . (!empty($ctx['turno']) && isset(PH_TURNOS[$ctx['turno']]) ? ' · ' . h(PH_TURNOS[$ctx['turno']]) : '')
                : 'Definir unidade de trabalho' ?>
        </button>
      <?php endif; ?>
    </div>
  </header>

  <div class="vmodal" id="vm-ctx">
    <div class="vbox">
      <header>
        <h2>Unidade de trabalho</h2>
        <button class="vclose" type="button" onclick="vModalClose('vm-ctx')">×</button>
      </header>
      <?php if (!$unidades): ?>
        <div class="vform"><div class="vempty">Nenhuma unidade tipo "packing".
          <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h(BIOS_BASE) ?>/estoque/almoxarifados.php">Cadastrar almoxarifado</a></div></div>
      <?php else: ?>
        <form class="vform" method="post">
          <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
          <input type="hidden" name="acao" value="set_ctx">
          <div class="vgrid">
            <?= vero_f_select('unidade_id', 'Unidade de packing', $unidades, $ctx['unidade_id'], true, '— Selecione —') ?>
            <?= vero_f_select('turno', 'Turno', PH_TURNOS, $ctx['turno'], false, '— Sem turno —') ?>
          </div>
          <div class="vform-actions"><button type="submit" class="vbtn vbtn-primary">Definir</button></div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$unidadeId): ?>
    <div class="vempty" style="margin-top:14px">Defina a unidade de trabalho (botão no topo) para receber cargas.</div>
  <?php else: ?>
    <div class="rec-ctx vhint" style="margin-top:12px">Próxima recepção <strong><?= h($proxNum) ?></strong></div>

    <?php if ($avaliacao !== null): ?>
      <div class="vcard" style="margin-top:12px">
        <div class="vtoolbar"><strong>Avaliação dos gates de aceitação</strong></div>
        <?php $ordem = [
            'carencia'       => 'Carência (LMR / intervalo de segurança)',
            'certificacao'   => 'Certificação (GlobalG.A.P. / orgânico)',
            'rastreabilidade'=> 'Rastreabilidade (segregação / IP)',
            'licenca'        => 'Licença varietal (mercado)',
            'so2'            => 'Janela SO2 / relógio de frio',
        ]; ?>
        <table class="vtable">
          <tbody>
          <?php foreach ($ordem as $k => $rot):
            $g   = $avaliacao[$k] ?? ['status' => 'sem_dado', 'detalhe' => ''];
            $st  = (string)($g['status'] ?? 'sem_dado');
            $det = (string)($g['detalhe'] ?? '');
          ?>
            <tr>
              <td><span class="vbadge <?= rec_gate_classe($st) ?>"><?= h($rot) ?></span></td>
              <td class="vhint"><?= h($det) !== '' ? h($det) : h(ucfirst($st)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (!empty($avaliacao['bloqueia'])): ?>
          <div class="vhint" style="margin-top:10px"><span class="vbadge vb-off"><strong>Há gate(s) em bloqueio</strong></span> — se você aceitar, a recepção será registrada como REJEITADA.</div>
        <?php else: ?>
          <div class="vhint" style="margin-top:10px"><span class="vbadge vb-ok"><strong>Sem bloqueio</strong></span> — a recepção pode ser aceita.</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$cargasPend): ?>
      <div class="vempty">Nenhuma carga da colheita pendente de recepção nesta unidade.
        As cargas com destino "packing" aparecem aqui assim que forem apontadas na colheita.</div>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <div class="vcard" style="margin-top:12px">
        <div class="vtoolbar"><strong>Dados do recebimento</strong></div>
        <div class="vgrid" style="padding:14px 16px">
          <?= vero_f_text('h_placa', 'Placa do veículo', (string)($_POST['h_placa'] ?? ''), false, 'Ex.: ABC1D23') ?>
          <?= vero_f_text('h_motorista', 'Motorista', (string)($_POST['h_motorista'] ?? '')) ?>
          <?= vero_f_text('h_transportadora', 'Transportadora', (string)($_POST['h_transportadora'] ?? '')) ?>
          <?= vero_f_text('h_produtor', 'Produtor (id)', (string)($_POST['h_produtor'] ?? ''), false, 'Referência do produtor da carga') ?>
          <?= vero_f_text('h_peso_bruto', 'Peso bruto (kg)', (string)($_POST['h_peso_bruto'] ?? '')) ?>
          <?= vero_f_text('h_peso_tara', 'Tara (kg)', (string)($_POST['h_peso_tara'] ?? '')) ?>
          <div class="full"><?= vero_f_select('h_mercado', 'Mercado de destino', $mercados, $_POST['h_mercado'] ?? null, false, '— Não definido —') ?></div>
          <div class="full"><?= vero_f_text('h_obs', 'Observação', (string)($_POST['h_obs'] ?? '')) ?></div>
        </div>
        </div>

        <div class="vcard" style="margin-top:14px">
        <div class="vtoolbar"><strong>Cargas pendentes</strong>
          <span class="vhint">Marque as cargas que chegaram e confira peso, método e frio.</span></div>

        <div style="overflow-x:auto;padding:0 16px">
        <table class="vtable">
          <thead><tr>
            <th></th><th>Romaneio</th><th>Data</th><th>Válvula</th><th>Variedade</th>
            <th style="text-align:right">Peso (kg)</th><th>Colheita / frio</th>
            <th>Método rastreab.</th><th>Contentores</th><th>Temp. °C</th><th>Turma</th>
          </tr></thead>
          <tbody>
          <?php foreach ($cargasPend as $c):
            $cid = (int)$c['carga_id'];
            $rel = ph_relogio_status($c['colhido_em'] ?? null);
            $cor = (string)($rel['cor'] ?? 'sem_dado');
            $hrs = $rel['horas'] ?? null;
            $metSel = (string)($pMet[$cid] ?? 'segregacao');
          ?>
            <tr>
              <td><input type="checkbox" name="sel[]" value="<?= $cid ?>"<?= isset($pSel[$cid]) ? ' checked' : '' ?>></td>
              <td><strong><?= h((string)($c['romaneio'] ?? '')) ?: '—' ?></strong></td>
              <td class="vhint"><?= h(dateBR($c['data_carga'] ?? null)) ?></td>
              <td><?= h((string)($c['talhao_nome'] ?? '')) ?: '—' ?></td>
              <td><?= h((string)($c['variedade_nome'] ?? '')) ?: '—' ?></td>
              <td class="vnum" style="text-align:right"><?= $c['peso_kg'] !== null ? numFmt((float)$c['peso_kg'], 1) : '—' ?></td>
              <td>
                <div class="vhint"><?= h($c['colhido_em'] !== null ? substr((string)$c['colhido_em'], 0, 16) : '—') ?></div>
                <span class="vbadge <?= rec_relogio_classe($cor) ?>"><?= $hrs !== null ? numFmt((float)$hrs, 1) . ' h' : 'sem dado' ?></span>
              </td>
              <td>
                <select name="it_metodo[<?= $cid ?>]">
                  <?php foreach (REC_METODOS as $mk => $mv): ?>
                    <option value="<?= h($mk) ?>"<?= $mk === $metSel ? ' selected' : '' ?>><?= h($mv) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="text" style="width:5rem" name="it_contentores[<?= $cid ?>]" value="<?= h((string)($pCont[$cid] ?? '')) ?>"></td>
              <td><input type="text" style="width:5rem" name="it_temp[<?= $cid ?>]" value="<?= h((string)($pTemp[$cid] ?? '')) ?>"></td>
              <td><input type="text" name="it_turma[<?= $cid ?>]" value="<?= h((string)($pTurma[$cid] ?? '')) ?>"></td>
              <input type="hidden" name="it_peso[<?= $cid ?>]" value="<?= h((string)($pPeso[$cid] ?? '')) ?>">
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>

        <div class="vform-actions" style="margin-top:12px;padding-right:16px;padding-bottom:16px">
          <button class="vbtn vbtn-ghost" type="submit" name="acao" value="avaliar">Avaliar gates</button>
          <?php if ($podeEditar): ?>
            <button class="vbtn vbtn-primary" type="submit" name="acao" value="aceitar"
              data-confirm="Criar a recepção com as cargas selecionadas?">Aceitar recepção</button>
          <?php endif; ?>
        </div>
        </div>
      </form>
    <?php endif; ?>

  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php'; ?>
