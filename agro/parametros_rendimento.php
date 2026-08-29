<?php
/* ============================================================
   VERO — Gestão Agrícola / Parâmetros de Rendimento (mão de obra)
   C-01 + A-06: a calculadora de MO (P-91) exigia
   parâmetros que NÃO tinham tela de cadastro. Esta tela é o caminho.

   Tabela: agro_calc_parametros (DB-52, chave→valor POR tipo de
   atividade, versionado por vigência; motor agro/_calc_mo.php).
   Salvar = desativa a linha vigente da chave (ativo=0, trilha fica)
   e INSERE a nova com vigência informada — o motor lê a vigente.
   Campo vazio no form = NÃO mexe na chave (preserva o vigente).

   Guard: reusa agricola.tipos_atividade (parâmetro é atributo do
   tipo de atividade — precedente porta_enxertos/P-37; sem re-seed).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php'; /* vero_srv_encargos_* p/ custo auto */

const T = 'agro_calc_parametros';

/* chaves editáveis (espelha VERO_CALC_MO_CHAVES do motor) — rótulo + hint */
const PR_CHAVES = [
    'rendimento_por_diaria'     => ['Rendimento por diária', 'quanto 1 pessoa faz por diária, na unidade da atividade (ex.: 250 plantas; colheita: caixas ou kg por pessoa/dia — é a meta da calculadora)'],
    'fator_ajuste'              => ['Fator de ajuste', 'multiplicador de dificuldade — 1,0 = normal'],
    'jornada_horas'             => ['Jornada (h/dia)', 'informativo'],
    'custo_diaria_propria'      => ['Custo da diária própria (R$)', 'CLT — usado na estimativa de custo'],
    'custo_diaria_terceirizada' => ['Custo da diária terceirizada (R$)', 'usado na estimativa de custo'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    vero_require('agro.tipos_atividade.editar');

    $tipoId = vero_int('tipo_atividade_id');
    $okTipo = $tipoId ? vero_val("SELECT id FROM agro_tipos_atividade WHERE id=:i AND tenant_id=:t",
        [':i' => $tipoId, ':t' => vero_tenant()]) : null;
    if (!$okTipo) {
        vero_flash('erro', 'Tipo de atividade inválido.');
        vero_redirect();
    }
    $vigencia = vero_date('vigencia_inicio') ?: date('Y-m-d');
    $obs = vero_str('observacao', 255);

    $pdo = vero_pdo();
    $pdo->beginTransaction();
    try {
        $gravadas = 0;
        foreach (PR_CHAVES as $chave => $meta) {
            $valor = vero_dec('p_' . $chave);
            if ($valor === null) continue;                    /* vazio = preserva o vigente */
            if ($valor < 0) throw new RuntimeException($meta[0] . ' não pode ser negativo.');
            /* versiona: desativa a(s) linha(s) ativa(s) da chave e insere a nova */
            $pdo->prepare("UPDATE " . T . " SET ativo = 0, updated_by = :u
                            WHERE tenant_id = :t AND tipo_atividade_id = :a AND chave = :c AND ativo = 1")
                ->execute([':u' => vero_uid(), ':t' => vero_tenant(), ':a' => $tipoId, ':c' => $chave]);
            vero_insert(T, [
                'tipo_atividade_id' => $tipoId,
                'chave'             => $chave,
                'valor'             => $valor,
                'vigencia_inicio'   => $vigencia,
                'observacao'        => $obs,
                'ativo'             => 1,
            ]);
            $gravadas++;
        }
        /* dias úteis/mês = valor fixo EDITÁVEL do tenant (base do custo auto da
           diária CLT); persistido em tenant_parametros p/ ser lembrado. */
        $diasU = vero_dec('dias_uteis_mes');
        if ($diasU !== null && $diasU > 0) {
            $pdo->prepare("INSERT INTO tenant_parametros (tenant_id, chave, valor)
                           VALUES (:t, 'mo.dias_uteis_mes', :v)
                           ON DUPLICATE KEY UPDATE valor = :v2")
                ->execute([':t' => vero_tenant(), ':v' => (string)$diasU, ':v2' => (string)$diasU]);
        }
        $pdo->commit();
        vero_flash($gravadas ? 'ok' : 'aviso', $gravadas
            ? "Parâmetros gravados ({$gravadas} chave(s), vigência " . date('d/m/Y', strtotime($vigencia)) . '). A calculadora já os usa.'
            : 'Nenhum valor informado — nada alterado.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        vero_flash('erro', 'Erro ao gravar: ' . h($e->getMessage()));
    }
    vero_redirect();
}

/* ── leitura: tipos + parâmetros vigentes hoje ── */
$tipos = vero_rows(
    "SELECT id, nome, categoria, unidade_padrao FROM agro_tipos_atividade
      WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => vero_tenant()]);
$hoje = date('Y-m-d');
$vigentes = [];   /* [tipo_id][chave] => ['valor'=>, 'vigencia'=>] */
foreach (vero_rows(
    "SELECT tipo_atividade_id, chave, valor, vigencia_inicio FROM " . T . "
      WHERE tenant_id = :t AND ativo = 1
        AND vigencia_inicio <= :d1 AND (vigencia_fim IS NULL OR vigencia_fim >= :d2)
      ORDER BY vigencia_inicio", [':t' => vero_tenant(), ':d1' => $hoje, ':d2' => $hoje]) as $r) {
    $vigentes[(int)$r['tipo_atividade_id']][(string)$r['chave']] =
        ['valor' => (float)$r['valor'], 'vigencia' => (string)$r['vigencia_inicio']];
}
$podeEditar = vero_can('agro.tipos_atividade.editar');

/* ── Custo automático da diária (A0 20/07) ──────────────────────────────
   Próprio (CLT): POR EQUIPE — média de (salário + encargos vigentes) dos
   membros CLT da equipe; a diária = essa média mensal ÷ dias úteis (o JS
   divide, então dias úteis fica editável ao vivo). Terceirizado: NÃO tem
   vínculo de equipe (agro_equipe_membros só liga operador) — usa a MÉDIA
   GERAL das diárias dos terceirizados na modalidade Diária. */
$encCfg = vero_srv_encargos_vigente();
$equipesMO = [];
foreach (vero_rows(
    "SELECT e.id, e.nome, o.tipo_vinculo, o.salario_mensal
       FROM agro_equipes e
       LEFT JOIN agro_equipe_membros m ON m.equipe_id = e.id AND m.tenant_id = e.tenant_id
       LEFT JOIN agro_operadores o ON o.id = m.operador_id AND o.tenant_id = e.tenant_id
      WHERE e.tenant_id = :t AND e.ativo = 1
      ORDER BY e.nome", [':t' => vero_tenant()]) as $r) {
    $eid = (int)$r['id'];
    if (!isset($equipesMO[$eid])) $equipesMO[$eid] = ['nome' => (string)$r['nome'], 'soma' => 0.0, 'n' => 0];
    if (($r['tipo_vinculo'] ?? '') === 'clt' && $r['salario_mensal'] !== null && (float)$r['salario_mensal'] > 0) {
        $bruto = (float)$r['salario_mensal'];
        $enc   = $encCfg ? (float)vero_srv_encargos_calc($bruto, $encCfg)['total'] : 0.0;
        $equipesMO[$eid]['soma'] += $bruto + $enc;
        $equipesMO[$eid]['n']++;
    }
}
$equipesJs = [];
foreach ($equipesMO as $eid => $e) {
    $equipesJs[$eid] = ['nome' => $e['nome'], 'nClt' => $e['n'],
        'mensal' => $e['n'] > 0 ? round($e['soma'] / $e['n'], 2) : null];
}
$terceiroDiaria = vero_val(
    "SELECT ROUND(AVG(valor_diaria), 2) FROM rh_terceirizados
      WHERE tenant_id = :t AND ativo = 1 AND modalidade_padrao = 'diaria' AND valor_diaria > 0",
    [':t' => vero_tenant()]);
$terceiroDiaria = $terceiroDiaria !== null ? (float)$terceiroDiaria : null;
$diasUteisDefault = (float)(vero_val(
    "SELECT valor FROM tenant_parametros WHERE tenant_id = :t AND chave = 'mo.dias_uteis_mes'",
    [':t' => vero_tenant()]) ?: 22);

$GUARD      = ['macro' => 'agricola', 'micro' => 'tipos_atividade'];
$PAGE_VIEW  = 'agricola_parametros_rendimento';
$PAGE_TITLE = 'Parâmetros de Rendimento (MO)';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Parâmetros de Rendimento — Mão de Obra', '', null) ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><div class="vhint">
      Rendimento por diária de cada atividade — é o que liga a <strong>Calculadora de Mão de Obra</strong>
      do apontamento (diárias = trabalho ÷ rendimento × fator). O sistema nunca inventa rendimento:
      sem parâmetro, a calculadora avisa e aguarda este cadastro. Valores versionados por
      vigência — corrigir cria uma nova vigência, a trilha fica.
      <strong>Colheita</strong>: o rendimento é em <strong>caixas (ou kg) por pessoa/dia</strong> — a
      calculadora parte da produção prevista da área (kg) e converte pelo peso da caixa
      (Configurações → Parâmetros do Sistema), nunca pelo nº de plantas.
    </div></div>
  </div>

  <div class="vcard">
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Atividade</th>
        <th>Unidade</th>
        <th class="num">Rendimento/diária</th>
        <th class="num">Fator</th>
        <th class="num">Diária própria (R$)</th>
        <th class="num">Diária terceirizada (R$)</th>
        <th>Vigência</th>
        <?php if ($podeEditar): ?><th style="width:90px"></th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($tipos as $tp): $v = $vigentes[(int)$tp['id']] ?? []; ?>
        <tr>
          <td><strong><?= h($tp['nome']) ?></strong></td>
          <td><?= h((string)($tp['unidade_padrao'] ?? '—')) ?></td>
          <td class="num"><?= isset($v['rendimento_por_diaria'])
              ? numFmt($v['rendimento_por_diaria']['valor'], 0)
              : '<span class="vbadge vb-warn">sem parâmetro</span>' ?></td>
          <td class="num"><?= isset($v['fator_ajuste']) ? numFmt($v['fator_ajuste']['valor'], 2) : '—' ?></td>
          <td class="num"><?= isset($v['custo_diaria_propria']) ? numFmt($v['custo_diaria_propria']['valor'], 2) : '—' ?></td>
          <td class="num"><?= isset($v['custo_diaria_terceirizada']) ? numFmt($v['custo_diaria_terceirizada']['valor'], 2) : '—' ?></td>
          <td><?= isset($v['rendimento_por_diaria'])
              ? date('d/m/Y', strtotime($v['rendimento_por_diaria']['vigencia']))
              : '—' ?></td>
          <?php if ($podeEditar): ?>
          <td><div class="vactions">
            <?= vero_btn_icone(vero_ico_lapis(), 'Editar', 'prEditar(' . (int)$tp['id'] . ', ' . h(json_encode($tp['nome'])) . ', ' . h(json_encode($v)) . ')') ?>
          </div></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal" id="vm-form">
  <div class="vbox">
    <header>
      <h2>Parâmetros — <span id="pr-nome"></span></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="tipo_atividade_id" id="pr-tipo" value="">

      <?php /* A0 20/07: puxa a diária própria (por equipe) e a terceirizada
               (média geral) da folha/encargos — preenche os campos abaixo. */ ?>
      <div class="vcard" style="background:var(--warm,#FBF8F2);border:1px solid var(--border,#E3D9C8);padding:12px 14px;margin-bottom:14px">
        <div class="vhint" style="margin-bottom:9px"><strong>Puxar custo automático</strong> —
          preenche as diárias própria/terceirizada abaixo a partir da folha; você ainda pode ajustar.</div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
          <div class="vfield" style="margin:0;min-width:190px">
            <label>Equipe (custo próprio CLT)</label>
            <select id="pr-equipe">
              <option value="">— escolha a equipe —</option>
              <?php foreach ($equipesJs as $eid => $e): ?>
                <option value="<?= (int)$eid ?>"<?= $e['mensal'] === null ? ' disabled' : '' ?>>
                  <?= h($e['nome']) ?><?= $e['mensal'] === null ? ' (sem CLT c/ salário)' : ' · ' . (int)$e['nClt'] . ' CLT' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="vfield" style="margin:0;max-width:120px">
            <label>Dias úteis/mês</label>
            <input type="text" name="dias_uteis_mes" id="pr-dias" value="<?= h(numFmt($diasUteisDefault, 0)) ?>" inputmode="numeric">
          </div>
          <button class="vbtn vbtn-ghost" type="button" onclick="prPuxar()">Preencher custos</button>
        </div>
        <div class="vhint" style="margin-top:8px" id="pr-puxar-msg">
          <?php if (!$equipesJs): ?>Nenhuma equipe cadastrada — cadastre em Pessoas → Equipes.
          <?php else: ?>Terceirizada usa a média geral das diárias<?= $terceiroDiaria !== null ? ' (R$ ' . numFmt($terceiroDiaria, 2) . ')' : ' — nenhum terceirizado por diária cadastrado' ?>.<?php endif; ?>
        </div>
      </div>

      <div class="vgrid">
        <?php foreach (PR_CHAVES as $chave => $meta): ?>
          <?= vero_f_text('p_' . $chave, $meta[0], '', false, $meta[1] . ' — vazio = mantém o valor atual') ?>
        <?php endforeach; ?>
        <div class="vfield">
          <label>Início da vigência</label>
          <input type="date" name="vigencia_inicio" value="<?= h($hoje) ?>">
          <div class="vhint">A partir de quando os valores valem (apontamentos usam a vigência da data)</div>
        </div>
        <div class="full"><?= vero_f_text('observacao', 'Observação', '', false, 'ex.: medição da fazenda, safra 2026.2') ?></div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Gravar parâmetros</button>
      </div>
    </form>
  </div>
</div>
<script>
var PR_MO = <?= jsvar(['equipes' => $equipesJs, 'terceiro' => $terceiroDiaria]) ?>;
function prFmt(n){ return Number(n).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}); }
function prSetField(chave, valor){
  var el = document.querySelector('#vm-form input[name="p_' + chave + '"]');
  if (el) el.value = prFmt(valor);
}
function prPuxar(){
  var msg = document.getElementById('pr-puxar-msg');
  var dias = parseFloat((document.getElementById('pr-dias').value || '').replace('.', '').replace(',', '.')) || 0;
  if (dias <= 0){ msg.textContent = 'Informe os dias úteis do mês (número > 0).'; return; }
  var eid = document.getElementById('pr-equipe').value, parts = [];
  if (eid && PR_MO.equipes[eid] && PR_MO.equipes[eid].mensal){
    var e = PR_MO.equipes[eid], propria = e.mensal / dias;
    prSetField('custo_diaria_propria', propria);
    parts.push('própria R$ ' + prFmt(propria) + ' (' + e.nClt + ' CLT · R$ ' + prFmt(e.mensal) + '/mês ÷ ' + dias + ' dias)');
  } else {
    parts.push('escolha uma equipe com colaborador CLT p/ o custo próprio');
  }
  if (PR_MO.terceiro){
    prSetField('custo_diaria_terceirizada', PR_MO.terceiro);
    parts.push('terceirizada R$ ' + prFmt(PR_MO.terceiro) + ' (média geral)');
  }
  msg.innerHTML = '✓ ' + parts.join(' · ') + ' — ajuste se precisar e grave.';
}
function prEditar(id, nome, vigentes) {
  document.getElementById('pr-tipo').value = id;
  document.getElementById('pr-nome').textContent = nome;
  /* pré-preenche com os vigentes (editável); vazio = mantém */
  document.querySelectorAll('#vm-form input[name^="p_"]').forEach(el => {
    const chave = el.name.slice(2);
    const v = vigentes && vigentes[chave] ? vigentes[chave].valor : null;
    el.value = v !== null && v !== undefined ? String(v).replace('.', ',') : '';
  });
  var eq = document.getElementById('pr-equipe'); if (eq) eq.value = '';
  var pm = document.getElementById('pr-puxar-msg'); /* reset da mensagem fica no reload; ok */
  vModalOpen('vm-form');
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
