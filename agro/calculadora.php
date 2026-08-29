<?php
/* ============================================================
   VERO — Gestão Agrícola / Calculadora de Diárias  (tela real)
   Tela nova, sem tabela no banco. Rota: /agro/calculadora.php
   Guard: agricola.calculadora (somente leitura — não grava nada)

   Fórmula por PLANTA (poda etc. — vero_services): plantas ÷ dias =
   plantas/dia; diárias/dia = ⌈plantas/dia ÷ meta⌉ (arredonda p/ CIMA).

   ERP-CALC 22/07 (áudio do gestor): COLHEITA não se calcula por nº de
   plantas — colhe-se por QUILO/CAIXA. Ao escolher uma atividade cuja
   unidade de rendimento é caixa ou kg, a tela pede a PRODUÇÃO PREVISTA
   TOTAL da área (kg, digitada) e converte a meta (caixas/dia) em kg
   pelo PESO DA CAIXA (default do tenant, editável):
     diárias/dia = ⌈ (produção ÷ dias) ÷ (meta × peso_caixa) ⌉
   Motor puro: vero_calc_mo_colheita() em agro/_calc_mo.php.
   Atividades por planta/ha/cacho: NADA MUDA (cálculo por quantidade).

   A3 (autorizado por A0, tela do A1 / design do A4 — edição
   cross-module registrada): seletor Própria/Terceirizada + custo
   da diária (opcional) → custo total ROTULADO pelo tipo. Mantém
   "orienta, não decide": o custo é INFORMADO, nunca inventado.
   Design: todos os inputs numa linha só, sem quebra.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_calc_mo.php'; /* motor puro: vero_calc_mo_colheita() */

$num = static fn($v) => (float)str_replace(['.', ','], ['', '.'], (string)$v);

$tipo      = in_array(($_GET['tipo'] ?? ''), ['propria', 'terceirizada'], true) ? (string)$_GET['tipo'] : 'propria';
$atividade = isset($_GET['atividade']) ? max(0, (int)$_GET['atividade']) : 0;
$plantas   = isset($_GET['plantas']) && $_GET['plantas'] !== '' ? $num($_GET['plantas']) : null;
$dias      = isset($_GET['dias']) ? max(0, (int)$_GET['dias']) : null;
$meta      = isset($_GET['meta']) && $_GET['meta'] !== '' ? $num($_GET['meta']) : null;
/* V-03/D-1: média de produtividade por pessoa — opcional. Se >0,
   dimensiona a equipe pela MÉDIA; a META segue como o número que vai na OS. */
$media     = isset($_GET['media']) && $_GET['media'] !== '' ? $num($_GET['media']) : null;
$custo     = isset($_GET['custo']) && $_GET['custo'] !== '' ? max(0.0, $num($_GET['custo'])) : null;
$producao  = isset($_GET['producao']) && $_GET['producao'] !== '' ? max(0.0, $num($_GET['producao'])) : null;
$peso      = isset($_GET['peso']) && $_GET['peso'] !== '' ? max(0.0, $num($_GET['peso'])) : null;

/* ── atividades do tenant (unidade define o modo) + parâmetros vigentes
      (rendimento = default da meta; custos = default do custo da diária) ── */
$tiposAtv = vero_rows(
    "SELECT id, nome, COALESCE(unidade_padrao,'') AS unid
       FROM agro_tipos_atividade WHERE tenant_id = :t AND ativo = 1 ORDER BY nome",
    [':t' => vero_tenant()]);
$hoje = date('Y-m-d');
$parms = [];   /* [tipo_id][chave] => valor (vigentes hoje) */
foreach (vero_rows(
    "SELECT tipo_atividade_id, chave, valor FROM agro_calc_parametros
      WHERE tenant_id = :t AND ativo = 1
        AND vigencia_inicio <= :d1 AND (vigencia_fim IS NULL OR vigencia_fim >= :d2)
      ORDER BY vigencia_inicio", [':t' => vero_tenant(), ':d1' => $hoje, ':d2' => $hoje]) as $r) {
    $parms[(int)$r['tipo_atividade_id']][(string)$r['chave']] = (float)$r['valor'];
}
$unidPorTipo = [];
foreach ($tiposAtv as $tp) $unidPorTipo[(int)$tp['id']] = (string)$tp['unid'];
$unidAtv      = $unidPorTipo[$atividade] ?? '';
$modoColheita = in_array($unidAtv, ['caixa', 'kg', 'contentor'], true); /* WP-CALC Z-05: contentor é colheita por peso */
$usaPeso      = in_array($unidAtv, ['caixa', 'contentor'], true);        /* caixa e contentor convertem meta→kg pelo peso */
const PESO_CONTENTOR_PADRAO = 20.0; /* WP-CALC Z-05: default do contentor (sem cultura no contexto desta tela) */

/* peso da caixa: default do tenant (colheita.peso_caixa_kg) — editável na tela */
$pesoTenant = function_exists('vero_srv_param')
    ? (float)vero_srv_param('colheita.peso_caixa_kg', '0')
    : (float)(vero_val("SELECT valor FROM tenant_parametros WHERE tenant_id = :t AND chave = 'colheita.peso_caixa_kg'",
        [':t' => vero_tenant()]) ?: 0);

/* ── cálculo server-side (a tela também recalcula ao vivo via JS) ── */
/* V-03/D-1: base de dimensionamento = MÉDIA (se informada) ou META (default).
   A meta permanece como o número impresso na OS do encarregado. */
$usaMedia = $media !== null && $media > 0;
$baseDim  = $usaMedia ? (float)$media : ($meta !== null ? (float)$meta : 0.0);
$baseWord = $usaMedia ? 'média' : 'meta';
$baseVal  = $usaMedia ? (float)$media : ($meta ?? 0.0);
$resultado  = null;   /* modo planta: vero_srv_diarias_necessarias · modo colheita: vero_calc_mo_colheita */
$custoTotal = null;
$pesoCalc   = null;
if ($modoColheita) {
    $pesoDefault = $unidAtv === 'contentor' ? PESO_CONTENTOR_PADRAO : ($pesoTenant > 0 ? $pesoTenant : null);
    $pesoCalc = $usaPeso ? (($peso !== null && $peso > 0) ? $peso : $pesoDefault) : null;
    if ($producao !== null && $dias !== null && $meta !== null && $producao > 0 && $dias > 0 && $baseDim > 0
        && (!$usaPeso || $pesoCalc !== null)) {
        $r = vero_calc_mo_colheita($producao, $baseDim, $usaPeso ? (float)$pesoCalc : 0.0, $dias);
        if (($r['estado'] ?? '') === 'ok') {
            $resultado = $r;
            if ($custo !== null && $custo > 0) $custoTotal = $r['diarias_total'] * $custo;
        }
    }
} elseif ($plantas !== null && $dias !== null && $meta !== null && $plantas > 0 && $dias > 0 && $baseDim > 0) {
    $resultado = vero_srv_diarias_necessarias($plantas, $dias, $baseDim);
    if ($custo !== null && $custo > 0) {
        $custoTotal = $resultado['diarias_total'] * $custo;
    }
}
$tipoRotulo = $tipo === 'terceirizada' ? 'Terceirizada' : 'Própria';

/* rótulos por modo (o JS espelha estas regras ao vivo) */
$lblQtd     = (!$modoColheita && $unidAtv !== '' && $unidAtv !== 'planta') ? 'Quantidade (' . $unidAtv . ') *' : 'Número de plantas *';
$lblMetaUn  = $modoColheita ? ($unidAtv === 'caixa' ? 'caixas/pessoa/dia' : ($unidAtv === 'contentor' ? 'contentores/pessoa/dia' : 'kg/pessoa/dia')) : '';
$lblPeso    = $unidAtv === 'contentor' ? 'Peso do contentor (kg) *' : 'Peso da caixa (kg) *';
$lblTile1   = $modoColheita ? 'Kg / dia' : (($unidAtv === '' || $unidAtv === 'planta') ? 'Plantas / dia' : 'Qtd / dia');

$GUARD      = ['macro' => 'agricola', 'micro' => 'calculadora'];
$PAGE_VIEW  = 'agricola_calculadora';
$PAGE_TITLE = 'Calculadora de Diárias';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Calculadora de Diárias', 'Planejamento de poda/colheita: quantas diárias são necessárias para vencer o talhão no prazo — e o custo estimado, próprio ou terceirizado. Colheita usa a produção prevista (kg), não o nº de plantas', null) ?>

  <div class="vcard" style="padding:18px 22px">
    <form method="get" id="calc-form">
      <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:nowrap;overflow-x:auto;padding-bottom:2px">
        <div class="vfield" style="flex:0 0 168px">
          <label>Tipo de mão de obra</label>
          <select name="tipo" id="c-tipo">
            <option value="propria"<?= $tipo === 'propria' ? ' selected' : '' ?>>Própria</option>
            <option value="terceirizada"<?= $tipo === 'terceirizada' ? ' selected' : '' ?>>Terceirizada</option>
          </select>
        </div>
        <div class="vfield" style="flex:0 0 170px">
          <label>Atividade</label>
          <select name="atividade" id="c-atividade">
            <option value="">— por plantas —</option>
            <?php foreach ($tiposAtv as $tp): $tid = (int)$tp['id']; $pp = $parms[$tid] ?? []; ?>
              <option value="<?= $tid ?>"<?= $tid === $atividade ? ' selected' : '' ?>
                      data-unid="<?= h($tp['unid']) ?>"
                      data-meta="<?= isset($pp['rendimento_por_diaria']) ? h((string)$pp['rendimento_por_diaria']) : '' ?>"
                      data-cp="<?= isset($pp['custo_diaria_propria']) ? h((string)$pp['custo_diaria_propria']) : '' ?>"
                      data-ct="<?= isset($pp['custo_diaria_terceirizada']) ? h((string)$pp['custo_diaria_terceirizada']) : '' ?>">
                <?= h($tp['nome']) ?><?= $tp['unid'] !== '' ? ' (' . h($tp['unid']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield" id="wrap-plantas" style="flex:1 1 150px;min-width:130px<?= $modoColheita ? ';display:none' : '' ?>">
          <label id="lbl-qtd"><?= h($lblQtd) ?></label>
          <input type="text" name="plantas" id="c-plantas" inputmode="numeric"<?= $modoColheita ? '' : ' required' ?>
                 value="<?= $plantas !== null ? h(number_format($plantas, 0, ',', '.')) : '' ?>" placeholder="Ex.: 5.714">
        </div>
        <div class="vfield" id="wrap-producao" style="flex:1 1 170px;min-width:150px<?= $modoColheita ? '' : ';display:none' ?>">
          <label>Produção prevista (kg) *</label>
          <input type="text" name="producao" id="c-producao" inputmode="numeric"<?= $modoColheita ? ' required' : '' ?>
                 value="<?= $producao !== null && $producao > 0 ? h(number_format($producao, 0, ',', '.')) : '' ?>" placeholder="Ex.: 40.000">
        </div>
        <div class="vfield" id="wrap-peso" style="flex:0 0 140px<?= $modoColheita && $usaPeso ? '' : ';display:none' ?>">
          <label id="lbl-peso"><?= h($lblPeso) ?></label>
          <input type="text" name="peso" id="c-peso" inputmode="decimal"
                 value="<?= $peso !== null && $peso > 0 ? h(number_format($peso, 2, ',', '.'))
                        : ($usaPeso && $pesoCalc !== null ? h(number_format((float)$pesoCalc, 2, ',', '.')) : '') ?>" placeholder="Ex.: 22,00">
        </div>
        <div class="vfield" style="flex:0 0 120px">
          <label>Dias disponíveis *</label>
          <input type="text" name="dias" id="c-dias" required inputmode="numeric"
                 value="<?= $dias !== null && $dias > 0 ? (int)$dias : '' ?>" placeholder="Ex.: 3">
        </div>
        <div class="vfield" style="flex:0 0 160px">
          <label>Meta por diária * <span class="vhint" id="lbl-meta-unid" style="display:inline"><?= h($lblMetaUn) ?></span></label>
          <input type="text" name="meta" id="c-meta" required inputmode="numeric"
                 value="<?= $meta !== null && $meta > 0 ? h(number_format($meta, 0, ',', '.')) : '' ?>" placeholder="Ex.: 100">
        </div>
        <!-- V-03/D-1: média de produtividade por pessoa (opcional). Preenchida →
             dimensiona a equipe pela MÉDIA; a Meta acima segue impressa na OS. -->
        <div class="vfield" style="flex:0 0 175px">
          <label>Média de produtividade <span class="vhint" style="display:inline">por pessoa/dia</span></label>
          <input type="text" name="media" id="c-media" inputmode="numeric"
                 value="<?= $media !== null && $media > 0 ? h(number_format($media, 0, ',', '.')) : '' ?>" placeholder="Opcional">
        </div>
        <div class="vfield" style="flex:0 0 150px">
          <label>Custo da diária (R$)</label>
          <input type="text" name="custo" id="c-custo" inputmode="decimal"
                 value="<?= $custo !== null && $custo > 0 ? h(number_format($custo, 2, ',', '.')) : '' ?>" placeholder="Opcional">
        </div>
        <div class="vfield" style="flex:0 0 auto">
          <button class="vbtn vbtn-primary" type="submit" style="height:40px;white-space:nowrap">Calcular</button>
        </div>
      </div>
      <div class="vhint" style="margin-top:8px" id="c-hint">
        <?php if ($modoColheita): ?>
          Colheita: informe a <strong>produção prevista da área (kg)</strong> — quem manda é o quilo, não o nº de plantas.
          A meta vem do parâmetro de rendimento (<?= $unidAtv === 'caixa' ? 'caixas' : 'kg' ?>/pessoa/dia) e o peso da caixa é o padrão do tenant — ambos editáveis.
        <?php else: ?>
          Meta = mesma usada nas regras de premiação. O custo é informado por você (própria ou terceirizada) — o sistema não arbitra valor de diária.
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="vcard" style="padding:20px 22px;margin-top:16px">
    <div id="res-vazio" class="vempty"<?= $resultado ? ' style="display:none"' : '' ?>>
      Preencha os campos — o resultado aparece aqui em tempo real.
    </div>
    <div id="res-box"<?= $resultado ? '' : ' style="display:none"' ?>>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;text-align:center">
        <div style="background:#FAF8F1;border:1px solid #EEE8DB;border-radius:12px;padding:16px">
          <div class="vhint" style="text-transform:uppercase;letter-spacing:.05em" id="r-t1-label"><?= h($lblTile1) ?></div>
          <div class="vnum" id="r-t1" style="font-size:26px;font-weight:700;color:#005059">
            <?php if ($resultado): ?>
              <?= $modoColheita ? h(number_format($resultado['kg_dia'], 0, ',', '.')) : h(number_format($resultado['plantas_dia'], 0, ',', '.')) ?>
            <?php endif; ?>
          </div>
        </div>
        <div style="background:#FAF8F1;border:1px solid #EEE8DB;border-radius:12px;padding:16px">
          <div class="vhint" style="text-transform:uppercase;letter-spacing:.05em">Diárias / dia</div>
          <div class="vnum" id="r-diarias-dia" style="font-size:26px;font-weight:700;color:#005059">
            <?= $resultado ? (int)$resultado['diarias_dia'] : '' ?>
          </div>
        </div>
        <div style="background:#E4EEF0;border:1px solid #C7DCDF;border-radius:12px;padding:16px">
          <div class="vhint" style="text-transform:uppercase;letter-spacing:.05em">Total de diárias</div>
          <div class="vnum" id="r-diarias-total" style="font-size:26px;font-weight:700;color:#00363D">
            <?= $resultado ? (int)$resultado['diarias_total'] : '' ?>
          </div>
        </div>
        <div id="r-custo-box" style="background:#EAF3EC;border:1px solid #CFE4D4;border-radius:12px;padding:16px"<?= $custoTotal !== null ? '' : ' data-off="1"' ?>>
          <div class="vhint" style="text-transform:uppercase;letter-spacing:.05em">
            Custo total (<span id="r-custo-tipo"><?= h($tipoRotulo) ?></span>)
          </div>
          <div class="vnum" id="r-custo-total" style="font-size:26px;font-weight:700;color:#1E5631">
            <?= $custoTotal !== null ? 'R$ ' . h(number_format($custoTotal, 2, ',', '.')) : '—' ?>
          </div>
        </div>
      </div>
      <!-- V-03/D-1: explícito na tela qual número dimensionou a equipe (média × meta). -->
      <div class="vhint" id="r-basenote" style="margin-top:14px;font-weight:600;color:#00363D">
        <?php if ($resultado && $usaMedia): ?>
          Dimensionado pela <strong>média</strong> de <?= h(number_format((float)$media, 0, ',', '.')) ?>/pessoa/dia — a <strong>meta <?= h(number_format((float)$meta, 0, ',', '.')) ?></strong> vai na OS do encarregado.
        <?php elseif ($resultado): ?>
          Dimensionado pela <strong>meta</strong> de <?= h(number_format((float)$meta, 0, ',', '.')) ?>/pessoa/dia.
        <?php endif; ?>
      </div>
      <div class="vhint" style="margin-top:8px" id="r-memoria">
        <?php if ($resultado && $modoColheita): ?>
          <?= h(number_format($producao, 0, ',', '.')) ?> kg previstos ÷ <?= (int)$dias ?> dia(s) =
          <?= h(number_format($resultado['kg_dia'], 2, ',', '.')) ?> kg/dia ·
          <?= h($baseWord) ?> <?= h(number_format($baseVal, 0, ',', '.')) ?> <?= $usaPeso
              ? ($unidAtv === 'contentor' ? 'contentor(es)' : 'caixa(s)') . ' × ' . h(number_format((float)$pesoCalc, 2, ',', '.')) . ' kg/' . ($unidAtv === 'contentor' ? 'contentor' : 'caixa') . ' = ' . h(number_format($resultado['meta_kg_dia'], 2, ',', '.')) . ' kg'
              : 'kg' ?>/pessoa/dia ·
          ÷ <?= h($baseWord) ?> = <?= (int)$resultado['diarias_dia'] ?> diária(s)/dia (arredondado para cima) ·
          × <?= (int)$dias ?> dia(s) = <?= (int)$resultado['diarias_total'] ?> diárias no total
          <?php if ($usaMedia): ?> · dimensionado pela <strong>média</strong>; meta <?= h(number_format((float)$meta, 0, ',', '.')) ?> vai na OS<?php endif; ?>
          <?php if ($custoTotal !== null): ?>
            · × R$ <?= h(number_format($custo, 2, ',', '.')) ?>/diária (<?= h($tipoRotulo) ?>) =
            <strong>R$ <?= h(number_format($custoTotal, 2, ',', '.')) ?></strong>
          <?php endif; ?>
        <?php elseif ($resultado): ?>
          <?= h(number_format($plantas, 0, ',', '.')) ?> plantas ÷ <?= (int)$dias ?> dia(s) =
          <?= h(number_format($resultado['plantas_dia'], 2, ',', '.')) ?> plantas/dia ·
          ÷ <?= h($baseWord) ?> <?= h(number_format($baseVal, 0, ',', '.')) ?> = <?= (int)$resultado['diarias_dia'] ?> diária(s)/dia
          (arredondado para cima) · × <?= (int)$dias ?> dia(s) = <?= (int)$resultado['diarias_total'] ?> diárias no total
          <?php if ($usaMedia): ?> · dimensionado pela <strong>média</strong>; meta <?= h(number_format((float)$meta, 0, ',', '.')) ?> vai na OS<?php endif; ?>
          <?php if ($custoTotal !== null): ?>
            · × R$ <?= h(number_format($custo, 2, ',', '.')) ?>/diária (<?= h($tipoRotulo) ?>) =
            <strong>R$ <?= h(number_format($custoTotal, 2, ',', '.')) ?></strong>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
  const PESO_TENANT = <?= jsvar($pesoTenant > 0 ? $pesoTenant : 0) ?>; /* colheita.peso_caixa_kg */
  const PESO_CONTENTOR = <?= jsvar(PESO_CONTENTOR_PADRAO) ?>; /* WP-CALC Z-05: default do contentor */
  const num = v => {
    v = String(v || '').trim().replaceAll('.', '').replace(',', '.');
    const n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  };
  const fmtInt = n => n.toLocaleString('pt-BR', {maximumFractionDigits: 0});
  const fmtDin = n => n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const fmtNum = n => n.toLocaleString('pt-BR', {maximumFractionDigits: 2});
  const $id = id => document.getElementById(id);

  /* modo atual pela unidade da atividade escolhida (caixa/kg = colheita) */
  function ctxAtv() {
    const opt = $id('c-atividade').selectedOptions[0];
    const unid = opt ? (opt.dataset.unid || '') : '';
    /* WP-CALC Z-05: contentor é colheita por peso; usaPeso = converte meta→kg pelo peso */
    return {opt, unid, colheita: unid === 'caixa' || unid === 'kg' || unid === 'contentor',
            usaPeso: unid === 'caixa' || unid === 'contentor'};
  }

  /* mostra/esconde campos + rótulos conforme o modo (espelha o server-side) */
  function toggleModo() {
    const c = ctxAtv();
    const mu = c.unid === 'caixa' ? 'caixas' : (c.unid === 'contentor' ? 'contentores' : 'kg');
    $id('wrap-plantas').style.display  = c.colheita ? 'none' : '';
    $id('wrap-producao').style.display = c.colheita ? '' : 'none';
    $id('wrap-peso').style.display     = c.usaPeso ? '' : 'none';
    $id('lbl-peso').textContent = c.unid === 'contentor' ? 'Peso do contentor (kg) *' : 'Peso da caixa (kg) *';
    $id('c-plantas').required  = !c.colheita;
    $id('c-producao').required = c.colheita;
    $id('lbl-qtd').textContent = (!c.colheita && c.unid && c.unid !== 'planta') ? 'Quantidade (' + c.unid + ') *' : 'Número de plantas *';
    $id('lbl-meta-unid').textContent = c.colheita ? mu + '/pessoa/dia' : '';
    $id('r-t1-label').textContent = c.colheita ? 'Kg / dia' : ((!c.unid || c.unid === 'planta') ? 'Plantas / dia' : 'Qtd / dia');
    $id('c-hint').textContent = c.colheita
      ? 'Colheita: informe a produção prevista da área (kg) — quem manda é o quilo, não o nº de plantas. '
        + 'A meta vem do parâmetro de rendimento (' + mu + '/pessoa/dia)'
        + (c.usaPeso ? ' e o peso do ' + (c.unid === 'contentor' ? 'contentor' : 'caixa') + ' — ambos editáveis.' : ' — editável.')
      : 'Meta = mesma usada nas regras de premiação. O custo é informado por você (própria ou terceirizada) — o sistema não arbitra valor de diária.';
  }

  /* defaults ao trocar a atividade: meta = rendimento por diária do parâmetro;
     peso da caixa = padrão do tenant (se vazio); custo = diária do parâmetro
     (só se o campo estiver vazio — nunca sobrescreve o que o usuário digitou) */
  function seedDefaults() {
    const c = ctxAtv();
    const d = c.opt ? c.opt.dataset : {};
    const m = parseFloat(d.meta || '0');
    if (m > 0) $id('c-meta').value = fmtNum(m);
    if (c.unid === 'caixa' && num($id('c-peso').value) <= 0 && PESO_TENANT > 0) {
      $id('c-peso').value = fmtDin(PESO_TENANT);
    }
    if (c.unid === 'contentor' && num($id('c-peso').value) <= 0 && PESO_CONTENTOR > 0) {
      $id('c-peso').value = fmtDin(PESO_CONTENTOR);
    }
    seedCusto();
  }
  function seedCusto() {
    if ($id('c-custo').value.trim() !== '') return; /* não sobrescreve */
    const c = ctxAtv();
    const d = c.opt ? c.opt.dataset : {};
    const v = parseFloat(($id('c-tipo').value === 'terceirizada' ? d.ct : d.cp) || '0');
    if (v > 0) $id('c-custo').value = fmtDin(v);
  }

  function calc() {
    const c = ctxAtv();
    const dDias = Math.floor(num($id('c-dias').value));
    const m = num($id('c-meta').value);
    /* V-03/D-1: média preenchida (>0) dimensiona a equipe; senão usa a meta.
       A meta segue como o número impresso na OS do encarregado. */
    const med = num($id('c-media').value);
    const usaMedia = med > 0;
    const base = usaMedia ? med : m;
    const bw = usaMedia ? 'média' : 'meta';
    const cst = num($id('c-custo').value);
    const tipo = $id('c-tipo').value === 'terceirizada' ? 'Terceirizada' : 'Própria';
    const box = $id('res-box'), vazio = $id('res-vazio');
    let t1 = 0, diariasDia = 0, diariasTotal = 0, mem = '';

    if (c.colheita) {
      /* COLHEITA: produção prevista (kg) ÷ dias ÷ base em kg/pessoa/dia (média|meta) */
      const kg = num($id('c-producao').value);
      const peso = c.usaPeso ? num($id('c-peso').value) : 1;
      if (kg <= 0 || dDias <= 0 || base <= 0 || peso <= 0) { box.style.display = 'none'; vazio.style.display = ''; return; }
      const baseKg = c.usaPeso ? base * peso : base;
      const kgDia = kg / dDias;
      diariasDia = Math.ceil(kgDia / baseKg);
      diariasTotal = diariasDia * dDias;
      t1 = Math.round(kgDia);
      const unWord = c.unid === 'contentor' ? 'contentor(es)' : 'caixa(s)';
      const unSing = c.unid === 'contentor' ? 'contentor' : 'caixa';
      mem = `${fmtInt(kg)} kg previstos ÷ ${dDias} dia(s) = ${fmtNum(kgDia)} kg/dia · ` +
        `${bw} ${fmtNum(base)} ` + (c.usaPeso ? `${unWord} × ${fmtDin(peso)} kg/${unSing} = ${fmtNum(baseKg)} kg` : 'kg') + `/pessoa/dia · ` +
        `÷ ${bw} = ${diariasDia} diária(s)/dia (arredondado para cima) · × ${dDias} dia(s) = ${diariasTotal} diárias no total` +
        (usaMedia ? ` · dimensionado pela média; meta ${fmtNum(m)} vai na OS` : '');
    } else {
      /* POR PLANTA (poda etc.) — dimensiona pela base (média|meta) */
      const p = num($id('c-plantas').value);
      if (p <= 0 || dDias <= 0 || base <= 0) { box.style.display = 'none'; vazio.style.display = ''; return; }
      const plantasDia = p / dDias;
      diariasDia = Math.ceil(plantasDia / base);
      diariasTotal = diariasDia * dDias;
      t1 = Math.round(plantasDia);
      mem = `${fmtInt(p)} plantas ÷ ${dDias} dia(s) = ${plantasDia.toLocaleString('pt-BR', {maximumFractionDigits: 2})} plantas/dia · ` +
        `÷ ${bw} ${fmtInt(base)} = ${diariasDia} diária(s)/dia (arredondado para cima) · × ${dDias} dia(s) = ${diariasTotal} diárias no total` +
        (usaMedia ? ` · dimensionado pela média; meta ${fmtInt(m)} vai na OS` : '');
    }

    $id('r-t1').textContent = fmtInt(t1);
    $id('r-diarias-dia').textContent = diariasDia;
    $id('r-diarias-total').textContent = diariasTotal;

    const custoBox = $id('r-custo-box');
    $id('r-custo-tipo').textContent = tipo;
    if (cst > 0) {
      $id('r-custo-total').textContent = 'R$ ' + fmtDin(diariasTotal * cst);
      custoBox.removeAttribute('data-off');
      mem += ` · × R$ ${fmtDin(cst)}/diária (${tipo}) = R$ ${fmtDin(diariasTotal * cst)}`;
    } else {
      $id('r-custo-total').textContent = '—';
      custoBox.setAttribute('data-off', '1');
    }
    $id('r-memoria').textContent = mem;
    const bn = $id('r-basenote');
    if (bn) bn.innerHTML = usaMedia
      ? 'Dimensionado pela <strong>média</strong> de ' + fmtNum(med) + '/pessoa/dia — a <strong>meta ' + fmtNum(m) + '</strong> vai na OS do encarregado.'
      : 'Dimensionado pela <strong>meta</strong> de ' + fmtNum(m) + '/pessoa/dia.';
    box.style.display = ''; vazio.style.display = 'none';
  }

  ['c-tipo', 'c-plantas', 'c-producao', 'c-peso', 'c-dias', 'c-meta', 'c-media', 'c-custo'].forEach(id => {
    const el = $id(id);
    el.addEventListener('input', calc);
    el.addEventListener('change', calc);
  });
  $id('c-atividade').addEventListener('change', () => { toggleModo(); seedDefaults(); calc(); });
  $id('c-tipo').addEventListener('change', seedCusto);
  </script>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
