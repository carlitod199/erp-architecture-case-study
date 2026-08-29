<?php
/* ============================================================
   VERO — Colheita · formulário compartilhado (parcial)
   Reusado por:
     • /colheita/index.php  (tela completa, POST para si mesma)
     • /agro/colheita.php   (apontamento inline, POST para o handler
                             de /colheita/index.php via ?acao=salvar)

   NÃO contém regra crítica: a validação (Σ kg ≤ kg total), a gravação e a
   entrada/estorno de estoque continuam APENAS no handler POST de
   /colheita/index.php. Aqui é só a UI + cálculo VIVO (espelho do
   servidor, sem valor legal). Cada tela que inclui já checou permissão
   (agro.colheita.editar) antes de dar require neste parcial.

   Variáveis esperadas do escopo que inclui (todas opcionais):
     $edit         array|null  — registro em edição (null = novo)
     $editClassifs array       — classificações do registro em edição
     $FORM_ACTION  string      — action do <form> ('' = posta para a própria tela)
     $FORM_ORIGEM  string      — hidden "origem" (ex.: 'agro') p/ o handler
                                 decidir o redirect pós-gravação
     $FORM_CANCEL  string      — URL dos botões Cancelar/Voltar
   ============================================================ */
declare(strict_types=1);

/* CATEGORIAS já é const em /colheita/index.php; para uso standalone
   (agro/colheita.php) define aqui uma única vez. */
if (!defined('CATEGORIAS')) {
    define('CATEGORIAS', ['premium' => 'Premium', 'cat1' => 'CAT 1', 'cat2' => 'CAT 2', 'cat3' => 'CAT 3', 'perdidos' => 'Perdidos']);
}

$edit         = $edit         ?? null;
$editClassifs = $editClassifs ?? [];
$FORM_ACTION  = $FORM_ACTION  ?? '';
$FORM_ORIGEM  = $FORM_ORIGEM  ?? '';
$FORM_CANCEL  = $FORM_CANCEL  ?? (BIOS_BASE . '/colheita/index');

/* ── T-13: unidade de entrada da colheita (config por tenant, tenant_parametros) ──
   kg é o backbone interno; "caixa" é apenas unidade de ENTRADA convertida em kg.
   Se a config pede caixa mas não há peso padrão, cai para kg (evita ÷ por zero). */
require_once __DIR__ . '/../includes/vero_services.php';
$COLHEITA_UNI  = (string)vero_srv_param('colheita.unidade', 'kg');
if (!in_array($COLHEITA_UNI, ['kg', 'caixa', 'ambos'], true)) $COLHEITA_UNI = 'kg';
$COLHEITA_PESO = (float)vero_srv_param('colheita.peso_caixa_kg', '0');
if (($COLHEITA_UNI === 'caixa' || $COLHEITA_UNI === 'ambos') && $COLHEITA_PESO <= 0) $COLHEITA_UNI = 'kg';

$vPrev = $edit && $edit['producao_prevista_kg_ha']  !== null ? (float)$edit['producao_prevista_kg_ha']  : null;
$vReal = $edit && $edit['producao_realizada_kg_ha'] !== null ? (float)$edit['producao_realizada_kg_ha'] : null;
/* em kg-only mantém o formato BR de hoje; com caixa carrega kg/ha em ponto-decimal cheio
   (o campo kg/ha vira portador canônico e é preenchido/lido via nº de caixas). */
$fmtProd = static function (?float $v) use ($COLHEITA_UNI): string {
    if ($v === null) return '';
    return $COLHEITA_UNI === 'kg' ? numFmt($v, 0) : rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
};

/* Dados dos selects — carrega só se o escopo que inclui ainda não trouxe
   (a tela completa já popula; o agro inline deixa o parcial popular). */
if (!isset($setores)) {
    $setores = vero_rows(
        "SELECT s.id, s.codigo, s.area_ha, s.talhao_id, t.codigo AS talhao, f.nome AS fazenda
           FROM agro_setores s
           JOIN agro_talhoes t ON t.id = s.talhao_id
           JOIN agro_fazendas f ON f.id = t.fazenda_id
          WHERE s.tenant_id = :t AND s.ativo = 1 AND s.talhao_id IS NOT NULL
          ORDER BY f.nome, t.codigo, s.codigo",
        [':t' => vero_tenant()]);
}
if (!isset($vinculos)) {
    $vinculos = vero_rows(
        "SELECT st.id, st.talhao_id, st.cultura_id, st.area_plantada_ha,
                s.identificacao AS safra, c.nome AS cultura
           FROM agro_safra_talhoes st
           JOIN agro_safras s ON s.id = st.safra_id
           JOIN agro_culturas c ON c.id = st.cultura_id
          WHERE st.tenant_id = :t ORDER BY s.identificacao DESC",
        [':t' => vero_tenant()]);
}
if (!isset($variedades)) {
    $variedades = vero_rows(
        "SELECT id, cultura_id, nome FROM agro_variedades
          WHERE tenant_id = :t AND ativo = 1 ORDER BY nome",
        [':t' => vero_tenant()]);
}
?>
  <?php if (!$setores): ?>
    <div class="vflash vflash-aviso">Nenhuma válvula com válvula vinculada. Cadastre em Gestão Agrícola → Válvulas / Setores.</div>
  <?php endif; ?>

  <form method="post" action="<?= h($FORM_ACTION) ?>" id="f-colheita">
    <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
    <?php if ($FORM_ORIGEM !== ''): ?>
    <input type="hidden" name="origem" value="<?= h($FORM_ORIGEM) ?>">
    <?php endif; ?>

    <div class="vcard" style="padding:18px 22px;margin-bottom:16px">
      <div class="vgrid" style="grid-template-columns:repeat(3,1fr)">
        <div class="vfield">
          <label>Data da colheita *</label>
          <input type="date" name="data_colheita" required
                 value="<?= h($edit ? (string)$edit['data_colheita'] : date('Y-m-d')) ?>">
        </div>
        <div class="vfield">
          <label>Válvula *</label>
          <select name="setor_id" id="f-setor" required>
            <option value="">— Selecione —</option>
            <?php foreach ($setores as $s): ?>
              <option value="<?= (int)$s['id'] ?>" data-talhao="<?= (int)$s['talhao_id'] ?>" data-area="<?= h((string)$s['area_ha']) ?>"
                <?= $edit && (int)$edit['setor_id'] === (int)$s['id'] ? ' selected' : '' ?>>
                <?= h($s['fazenda']) ?> — válvula <?= h($s['talhao']) ?> — válvula <?= h($s['codigo']) ?> (<?= numFmt((float)$s['area_ha'], 2) ?> ha)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Safra *</label>
          <select name="safra_talhao_id" id="f-safra" required>
            <option value="">— Selecione a válvula primeiro —</option>
          </select>
        </div>
        <div class="vfield">
          <label>Variedade</label>
          <select name="variedade_id" id="f-variedade">
            <option value="">— Não informada —</option>
          </select>
        </div>
        <?php if ($COLHEITA_UNI === 'ambos'): ?>
        <div class="vfield">
          <label>Unidade de entrada</label>
          <select id="f-unidade" name="colheita_unidade_entrada">
            <option value="kg">Quilograma (kg/ha)</option>
            <option value="caixa">Caixa (× <?= numFmt($COLHEITA_PESO, 2) ?> kg)</option>
          </select>
          <div class="vhint">Em caixa, o kg = nº de caixas × <?= numFmt($COLHEITA_PESO, 2) ?> kg.</div>
        </div>
        <?php else: ?>
          <input type="hidden" id="f-unidade" name="colheita_unidade_entrada" value="<?= h($COLHEITA_UNI) ?>">
        <?php endif; ?>
        <div class="vfield">
          <label>Produção prevista<?= $COLHEITA_UNI === 'kg' ? ' (kg/ha)' : '' ?></label>
          <input type="text" name="producao_prevista_kg_ha" id="f-prev" class="u-kg" style="text-align:right"
                 value="<?= h($fmtProd($vPrev)) ?>">
          <input type="text" id="cx-prev" name="cx_prev" class="u-caixa" style="text-align:right;display:none"
                 placeholder="nº de caixas">
          <div class="vhint" id="h-prev">kg total previsto: —</div>
        </div>
        <div class="vfield">
          <label>Produção realizada<?= $COLHEITA_UNI === 'kg' ? ' (kg/ha)' : '' ?></label>
          <input type="text" name="producao_realizada_kg_ha" id="f-real" class="u-kg" style="text-align:right"
                 value="<?= h($fmtProd($vReal)) ?>">
          <input type="text" id="cx-real" name="cx_real" class="u-caixa" style="text-align:right;display:none"
                 placeholder="nº de caixas">
          <div class="vhint" id="h-real">kg total realizado: —</div>
        </div>
        <div class="vfield" style="grid-column:1/-1">
          <label>Observação</label>
          <input type="text" name="observacao" value="<?= h($edit['observacao'] ?? '') ?>">
        </div>
      </div>
    </div>

    <?php
    /* ── Pedido do gestor (08/2026): classificação por KG, % derivada ──
       O usuário digita o KG por categoria e a % vira leitura (kg ÷ kg total).
       Prefill em edição: usa kg_calculado gravado; registros antigos só-% (kg
       zerado) derivam kg = % × kg total do momento — mesmo número que a tela
       antiga exibia. Formato BR compatível com o parser dec() do JS. */
    $kgTotMomento = [
        'previsto'  => $edit ? (float)$edit['kg_total_previsto']  : 0.0,
        'realizado' => $edit ? (float)$edit['kg_total_realizado'] : 0.0,
    ];
    $fmtKgIn = static fn(float $v): string => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
      <?php foreach (['previsto' => 'Classificação PREVISTA', 'realizado' => 'Classificação REALIZADA'] as $momento => $titulo): ?>
      <div class="vcard">
        <div class="vtoolbar"><strong style="font-size:14px"><?= $titulo ?></strong>
          <div style="flex:1"></div>
          <span class="vsub">Σ = <span id="soma-<?= $momento ?>">—</span></span>
        </div>
        <table class="vtable">
          <thead><tr>
            <th>Categoria</th>
            <th style="width:110px;text-align:right">kg</th>
            <th style="width:110px;text-align:right">Preço/kg (R$)</th>
            <th style="width:70px;text-align:right">%</th>
            <th style="width:120px;text-align:right">R$</th>
          </tr></thead>
          <tbody>
          <?php foreach (CATEGORIAS as $cat => $rotulo):
              $cVal = null;
              foreach ($editClassifs as $ec) {
                  if ($ec['momento'] === $momento && $ec['categoria'] === $cat) { $cVal = $ec; break; }
              }
              $kgIni = $cVal ? (float)$cVal['kg_calculado'] : 0.0;
              if ($kgIni <= 0 && $cVal && (float)$cVal['percentual'] > 0) {
                  $kgIni = round($kgTotMomento[$momento] * (float)$cVal['percentual'] / 100, 3);
              }
          ?>
            <tr>
              <td><?= $cat === 'perdidos' ? '<span class="vbadge vb-off">Perdidos</span>' : '<strong>' . $rotulo . '</strong>' ?></td>
              <td><input type="text" name="c_kg[<?= $momento ?>][<?= $cat ?>]" class="cl-kg-in" data-m="<?= $momento ?>"
                         style="text-align:right" placeholder="0"
                         value="<?= $kgIni > 0 ? h($fmtKgIn($kgIni)) : '' ?>"></td>
              <td><?php if ($cat === 'perdidos'): ?>
                    <input type="text" name="c_causa[<?= $momento ?>][<?= $cat ?>]" placeholder="causa da perda"
                           value="<?= h((string)($cVal['causa_perda'] ?? '')) ?>" title="Obrigatória para a categoria Perdidos — mantém o kg FORA da entrada no estoque">
                  <?php else: ?><input type="text" name="c_preco[<?= $momento ?>][<?= $cat ?>]" class="cl-preco" data-m="<?= $momento ?>"
                         style="text-align:right" placeholder="0,00"
                         value="<?= $cVal ? numFmt((float)$cVal['preco_kg'], 2) : '' ?>"><?php endif; ?></td>
              <td class="vnum cl-pct-out" style="text-align:right" title="Calculada: kg da categoria ÷ kg total do momento">—</td>
              <td class="vnum cl-fat" style="text-align:right">—</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr>
            <td style="font-weight:600">Total</td>
            <td class="vnum" style="text-align:right" id="kg-<?= $momento ?>">—</td>
            <td style="text-align:right;font-weight:600">Faturamento <?= $momento ?></td>
            <td colspan="2" class="vnum" style="text-align:right;font-weight:700" id="fat-<?= $momento ?>">—</td>
          </tr></tfoot>
        </table>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- A-01 (G-16): erro de validação aparece AQUI, inline — o form não
         recarrega e a digitação é preservada; o handler POST de
         /colheita/index.php segue como rede final (Σ % ≤ 100 por momento). -->
    <div id="colheita-erro" role="alert" style="display:none;margin-bottom:12px;padding:10px 12px;border-radius:8px;background:#FBEDE9;color:#B3402A;font-size:12.5px;font-weight:600"></div>

    <div style="display:flex;justify-content:flex-end;gap:10px">
      <a class="vbtn vbtn-ghost" href="<?= h($FORM_CANCEL) ?>">Cancelar</a>
      <button class="vbtn vbtn-primary" type="submit">Salvar colheita</button>
    </div>
  </form>

  <script>
  const VINCULOS   = <?= jsvar(array_map(static fn($v) => [
      'id' => (int)$v['id'], 'talhao' => (int)$v['talhao_id'], 'cultura' => (int)$v['cultura_id'],
      'label' => $v['safra'] . ' · ' . $v['cultura'], 'area' => (float)$v['area_plantada_ha'],
  ], $vinculos)) ?>;
  const VARIEDADES = <?= jsvar(array_map(static fn($v) => [
      'id' => (int)$v['id'], 'cultura' => (int)$v['cultura_id'], 'nome' => $v['nome'],
  ], $variedades)) ?>;
  const EDIT_SAFRA = <?= $edit && $edit['safra_talhao_id'] !== null ? (int)$edit['safra_talhao_id'] : 'null' ?>;
  const EDIT_VAR   = <?= $edit && $edit['variedade_id'] !== null ? (int)$edit['variedade_id'] : 'null' ?>;
  /* T-13: unidade de entrada (config do tenant) e peso padrão da caixa */
  const COL_UNI  = <?= jsvar($COLHEITA_UNI) ?>;
  const COL_PESO = <?= jsvar($COLHEITA_PESO) ?>;

  const $id = s => document.getElementById(s);
  const fmt = (n, d = 2) => n.toLocaleString('pt-BR', {minimumFractionDigits: d, maximumFractionDigits: d});
  const dec = v => {
    v = String(v || '').trim();
    if (!v) return 0;
    if (v.includes(',')) v = v.replaceAll('.', '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(v)) v = v.replaceAll('.', '');
    const n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  };

  function areaAtual() {
    const opt = $id('f-setor').selectedOptions[0];
    if (!opt || !opt.value) return 0;
    let a = parseFloat(opt.dataset.area || '0');
    if (a <= 0) {
      const st = VINCULOS.find(v => v.id === parseInt($id('f-safra').value || '0', 10));
      if (st) a = st.area;
    }
    return a;
  }
  function refreshSafras(keep) {
    const opt = $id('f-setor').selectedOptions[0];
    const talhao = opt && opt.value ? parseInt(opt.dataset.talhao, 10) : 0;
    const sel = $id('f-safra');
    sel.innerHTML = '<option value="">— Selecione —</option>';
    VINCULOS.filter(v => v.talhao === talhao).forEach(v => sel.add(new Option(v.label, v.id)));
    if (keep) sel.value = String(keep);
    refreshVariedades(EDIT_VAR);
  }
  function refreshVariedades(keep) {
    const st = VINCULOS.find(v => v.id === parseInt($id('f-safra').value || '0', 10));
    const sel = $id('f-variedade');
    const atual = keep || parseInt(sel.value || '0', 10);
    sel.innerHTML = '<option value="">— Não informada —</option>';
    VARIEDADES.filter(v => !st || v.cultura === st.cultura).forEach(v => sel.add(new Option(v.nome, v.id)));
    if (atual && [...sel.options].some(o => +o.value === atual)) sel.value = String(atual);
  }

  function kgTotais() {
    const a = areaAtual();
    return {
      previsto: dec($id('f-prev').value) * a,
      realizado: dec($id('f-real').value) * a,
    };
  }
  function recalc() {
    const tot = kgTotais();
    $id('h-prev').textContent = 'kg total previsto: ' + (tot.previsto > 0 ? fmt(tot.previsto, 0) + ' kg' : '—');
    $id('h-real').textContent = 'kg total realizado: ' + (tot.realizado > 0 ? fmt(tot.realizado, 0) + ' kg' : '—');
    ['previsto', 'realizado'].forEach(m => {
      /* Fluxo invertido (08/2026): o usuário digita o KG; a % é DERIVADA
         (kg ÷ kg total do momento) — espelho do handler PHP, sem valor legal. */
      let somaPct = 0, somaKg = 0, somaFat = 0;
      document.querySelectorAll(`.cl-kg-in[data-m=${m}]`).forEach(inp => {
        const tr = inp.closest('tr');
        const kg = dec(inp.value);
        const precoEl = tr.querySelector('.cl-preco');
        const preco = precoEl ? dec(precoEl.value) : 0;
        const pct = tot[m] > 0 ? kg / tot[m] * 100 : 0;
        const fat = precoEl ? kg * preco : 0;
        somaPct += pct; somaKg += kg; somaFat += fat;
        tr.querySelector('.cl-pct-out').textContent = kg > 0 ? (tot[m] > 0 ? fmt(pct, 1) + '%' : '?') : '—';
        tr.querySelector('.cl-fat').textContent = kg > 0 && precoEl ? fmt(fat) : (kg > 0 ? '0,00' : '—');
      });
      const somaEl = $id('soma-' + m);
      /* A1-51: aviso VIVO além da cor — o server também bloqueia (Σ kg ≤ kg total) */
      const excede = somaKg > tot[m] + 0.001;
      somaEl.textContent = somaKg > 0
        ? fmt(somaKg, 0) + ' kg' + (tot[m] > 0 ? ' (' + fmt(somaPct, 1) + '%)' : '')
          + (excede ? ' — PASSA DO KG TOTAL, o salvamento será recusado' : '')
        : '—';
      somaEl.style.color = excede ? '#9A3B2A' : '';
      somaEl.style.fontWeight = excede ? '700' : '';
      $id('kg-' + m).textContent = somaKg > 0 ? fmt(somaKg, 0) : '—';
      $id('fat-' + m).textContent = fmt(somaFat);
    });
  }

  /* ── T-13: conversão nº de caixas → kg/ha (o campo kg/ha é o portador canônico) ──
     kg total = caixas × peso; kg/ha = kg total ÷ área. É espelho VIVO; o handler
     PHP em /colheita/index.php reconverte no submit (backbone kg intacto). */
  function unidadeAtual() { const e = $id('f-unidade'); return e ? e.value : 'kg'; }
  function initCaixaFromKg() {
    if (COL_PESO <= 0) return;
    const a = areaAtual();
    if (a <= 0) return;
    [['cx-prev', 'f-prev'], ['cx-real', 'f-real']].forEach(([cx, kg]) => {
      const cEl = $id(cx); if (!cEl || cEl.value) return;
      const kgHa = dec($id(kg).value);
      if (kgHa > 0) { const n = kgHa * a / COL_PESO; cEl.value = fmt(n, n % 1 === 0 ? 0 : 2); }
    });
  }
  function syncCaixaToKg() {
    const a = areaAtual();
    [['cx-prev', 'f-prev'], ['cx-real', 'f-real']].forEach(([cx, kg]) => {
      const cEl = $id(cx); if (!cEl) return;
      const kgHa = (a > 0 && COL_PESO > 0) ? (dec(cEl.value) * COL_PESO / a) : 0;
      $id(kg).value = kgHa > 0 ? String(kgHa) : '';
    });
  }
  function aplicarUnidade() {
    const caixa = unidadeAtual() === 'caixa';
    if (caixa) initCaixaFromKg();
    document.querySelectorAll('.u-kg').forEach(e => e.style.display = caixa ? 'none' : '');
    document.querySelectorAll('.u-caixa').forEach(e => e.style.display = caixa ? '' : 'none');
    if (caixa) syncCaixaToKg();
    recalc();
  }

  $id('f-setor').addEventListener('change', () => { refreshSafras(null); if (unidadeAtual() === 'caixa') syncCaixaToKg(); recalc(); });
  $id('f-safra').addEventListener('change', () => { refreshVariedades(null); recalc(); });
  document.querySelectorAll('#f-prev, #f-real, .cl-kg-in, .cl-preco').forEach(el => el.addEventListener('input', recalc));
  document.querySelectorAll('#cx-prev, #cx-real').forEach(el => el.addEventListener('input', () => { syncCaixaToKg(); recalc(); }));
  const uniEl = $id('f-unidade');
  if (uniEl && uniEl.tagName === 'SELECT') uniEl.addEventListener('change', aplicarUnidade);

  refreshSafras(EDIT_SAFRA);
  recalc();
  aplicarUnidade();

  /* A-01 (G-16): bloqueio ANTES do submit — Σ kg ≤ kg total em cada momento
     (previsto/realizado); erro inline em #colheita-erro com a digitação
     preservada. Espelho do servidor (/colheita/index.php, rede final). */
  document.getElementById('f-colheita').addEventListener('submit', function (e) {
    const box = document.getElementById('colheita-erro');
    const tot = kgTotais();
    let erro = '';
    for (const m of ['previsto', 'realizado']) {
      let soma = 0;
      document.querySelectorAll(`.cl-kg-in[data-m=${m}]`).forEach(inp => { soma += dec(inp.value); });
      if (soma <= 0) continue;
      if (tot[m] <= 0) {
        erro = 'Você classificou ' + fmt(soma, 0) + ' kg na classificação ' + m.toUpperCase()
             + ', mas a produção ' + (m === 'previsto' ? 'prevista' : 'realizada')
             + ' está vazia — informe a produção total antes de classificar.';
        break;
      }
      if (soma > tot[m] + 0.001) {
        erro = 'A soma dos kg da classificação ' + m.toUpperCase() + ' (' + fmt(soma, 0)
             + ' kg) passa do kg total ' + (m === 'previsto' ? 'previsto' : 'realizado')
             + ' (' + fmt(tot[m], 0) + ' kg). Ajuste os kg antes de salvar.';
        break;
      }
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
  </script>
