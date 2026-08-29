<?php
/* ============================================================
   VERO — Nutrição / Faixas Nutricionais  (CRUD real)
   Substitui o mock. Rota da matriz: /nutricao/faixas_nutricionais.php
   Guard: nutricao.faixas_nutricionais
   Tabela: analise_faixas (mig. 133)
   D5: as faixas vêm do RT/laboratório — o sistema nunca inventa
   faixa nem classifica análise sem faixa cadastrada aqui.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'analise_faixas';

const TIPOS = ['solo' => 'Solo', 'foliar' => 'Foliar'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('nutricao.faixas_nutricionais.editar');

        $id          = vero_int('id');
        $tipo        = vero_str('tipo', 10);
        $nutrienteId = vero_int('nutriente_id');
        $idealMin    = vero_dec('ideal_min');
        $idealMax    = vero_dec('ideal_max');

        if ($tipo === null || !isset(TIPOS[$tipo]) || !$nutrienteId || $idealMin === null || $idealMax === null) {
            vero_flash('erro', 'Tipo, nutriente e a faixa ideal (mín/máx) são obrigatórios.');
            vero_redirect();
        }
        $minimo = vero_dec('minimo');
        $maximo = vero_dec('maximo');
        if ($idealMin > $idealMax
            || ($minimo !== null && $minimo > $idealMin)
            || ($maximo !== null && $maximo < $idealMax)) {
            vero_flash('erro', 'Faixa inconsistente: exige mínimo ≤ ideal mín ≤ ideal máx ≤ máximo.');
            vero_redirect();
        }
        $okNutr = vero_val("SELECT id FROM analise_nutrientes WHERE id=:i AND tenant_id=:t",
            [':i' => $nutrienteId, ':t' => vero_tenant()]);
        if (!$okNutr) {
            vero_flash('erro', 'Nutriente inválido.');
            vero_redirect();
        }
        $variedadeId = vero_int('variedade_id');
        $fenologiaId = vero_int('fenologia_id');
        /* Opção B (mig 166): fase POR VARIEDADE (agro_variedade_fases) — prioritária.
           Precisa pertencer a uma fenologia aprovada/vigente (e, se há variedade
           escolhida, à variedade). fenologia_id (cultura) segue como fallback. */
        $variedadeFaseId = vero_int('variedade_fase_id');
        if ($variedadeFaseId) {
            $okVf = $variedadeId
                ? vero_val(
                    "SELECT fa.id FROM agro_variedade_fases fa
                       JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
                      WHERE fa.id = :i AND fa.tenant_id = :t
                        AND fe.status='aprovada' AND fe.ativo=1 AND fa.ativo=1
                        AND fe.variedade_id = :v",
                    [':i' => $variedadeFaseId, ':t' => vero_tenant(), ':v' => $variedadeId])
                : vero_val(
                    "SELECT fa.id FROM agro_variedade_fases fa
                       JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
                      WHERE fa.id = :i AND fa.tenant_id = :t
                        AND fe.status='aprovada' AND fe.ativo=1 AND fa.ativo=1",
                    [':i' => $variedadeFaseId, ':t' => vero_tenant()]);
            if (!$okVf) $variedadeFaseId = null;
        }

        /* evita faixa ativa duplicada para o mesmo contexto */
        $dup = vero_val(
            "SELECT id FROM " . T . "
              WHERE tenant_id=:t AND tipo=:tp AND nutriente_id=:n AND ativo=1 AND id<>:id
                AND ((:v1 IS NULL AND variedade_id IS NULL) OR variedade_id = :v2)
                AND ((:f1 IS NULL AND fenologia_id IS NULL) OR fenologia_id = :f2)
                AND ((:vf1 IS NULL AND variedade_fase_id IS NULL) OR variedade_fase_id = :vf2)",
            [':t' => vero_tenant(), ':tp' => $tipo, ':n' => $nutrienteId, ':id' => (int)$id,
             ':v1' => $variedadeId, ':v2' => $variedadeId, ':f1' => $fenologiaId, ':f2' => $fenologiaId,
             ':vf1' => $variedadeFaseId, ':vf2' => $variedadeFaseId]);
        if ($dup) {
            vero_flash('erro', 'Já existe uma faixa ativa para este nutriente neste contexto (variedade/fase). Inative a anterior.');
            vero_redirect();
        }

        $data = [
            'tipo'          => $tipo,
            'nutriente_id'  => $nutrienteId,
            'unidade'       => vero_str('unidade', 20),
            'variedade_id'     => $variedadeId,
            'porta_enxerto_id' => vero_int('porta_enxerto_id'),
            'fenologia_id'     => $fenologiaId,
            'variedade_fase_id' => $variedadeFaseId,
            'minimo'        => $minimo,
            'ideal_min'     => $idealMin,
            'ideal_max'     => $idealMax,
            'maximo'        => $maximo,
            'observacao'    => vero_str('observacao', 255),
            'ativo'         => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', 'Faixa atualizada.');
        } else {
            vero_insert(T, $data);
            vero_flash('ok', 'Faixa cadastrada.');
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('nutricao.faixas_nutricionais.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$fTipo   = (string)($_GET['tipo'] ?? '');
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 25;

$where  = "f.tenant_id = :t";
$params = [':t' => vero_tenant()];
if (isset(TIPOS[$fTipo])) {
    $where .= " AND f.tipo = :tp";
    $params[':tp'] = $fTipo;
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " f WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT f.*, n.nome AS nutriente_nome, n.simbolo, v.nome AS variedade_nome,
            fe.codigo AS fenologia_codigo, fe.nome AS fenologia_nome,
            vf.nome AS var_fase_nome
       FROM " . T . " f
       JOIN analise_nutrientes n ON n.id = f.nutriente_id
       LEFT JOIN agro_variedades v ON v.id = f.variedade_id
       LEFT JOIN agro_fenologia_estagios fe ON fe.id = f.fenologia_id
       LEFT JOIN agro_variedade_fases vf ON vf.id = f.variedade_fase_id AND vf.tenant_id = f.tenant_id
      WHERE {$where}
      ORDER BY f.ativo DESC, f.tipo, n.ordem, n.nome
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params);

$nutrientes = vero_rows("SELECT id, nome, simbolo, aplicacao, unidade_padrao FROM analise_nutrientes
                          WHERE tenant_id = :t AND ativo = 1 ORDER BY ordem, nome", [':t' => vero_tenant()]);
$variedades    = vero_options('agro_variedades', 'nome', 'ativo = 1');
$portaEnxertos = vero_options('agro_porta_enxertos', 'nome', 'ativo = 1');
$fenologias = vero_rows("SELECT id, codigo, nome FROM agro_fenologia_estagios
                          WHERE tenant_id = :t AND ativo = 1 ORDER BY ordem", [':t' => vero_tenant()]);
/* Opção B (mig 166): fases da fenologia POR VARIEDADE (versão aprovada vigente),
   agrupadas por variedade_id, p/ o select "Fase (por variedade)" (populado por JS ao
   escolher a variedade). { variedade_id: [{id, ini, fim, nome}] } */
$varFasesFaixaMap = [];
foreach (vero_rows(
    "SELECT fa.id, fe.variedade_id, fa.dia_inicio, fa.dia_fim, fa.nome
       FROM agro_variedade_fases fa
       JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
      WHERE fa.tenant_id = :t AND fe.status = 'aprovada' AND fe.ativo = 1 AND fa.ativo = 1
        AND fe.versao = (SELECT MAX(versao) FROM agro_variedade_fenologia fe2
                          WHERE fe2.tenant_id = fa.tenant_id AND fe2.variedade_id = fe.variedade_id
                            AND fe2.status = 'aprovada' AND fe2.ativo = 1)
      ORDER BY fe.variedade_id, fa.dia_inicio", [':t' => vero_tenant()]) as $vf) {
    $varFasesFaixaMap[(int)$vf['variedade_id']][] = [
        'id' => (int)$vf['id'], 'ini' => (int)$vf['dia_inicio'],
        'fim' => (int)$vf['dia_fim'], 'nome' => (string)$vf['nome']];
}

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}
/* P10 (auditoria Relatórios 20/07): deep-link vindo da análise ("sem faixa —
   cadastrar") já abre o modal de nova faixa com o nutriente e o tipo escolhidos. */
$abrirNova     = !empty($_GET['nova']) && !$edit;
$novaNutriente = (int)($_GET['nutriente'] ?? 0);
$novaTipo      = isset(TIPOS[(string)($_GET['tipo'] ?? '')]) ? (string)$_GET['tipo'] : 'solo';

$GUARD      = ['macro' => 'nutricao', 'micro' => 'faixas_nutricionais'];
$PAGE_VIEW  = 'nutricao_faixas_nutricionais';
$PAGE_TITLE = 'Faixas Nutricionais';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('nutricao.faixas_nutricionais.editar');

$fmtFaixa = static fn($v) => $v !== null ? numFmt((float)$v, 2) : '—';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Faixas Nutricionais', 'Referências do RT/laboratório por nutriente × variedade × fase — o sistema só classifica análises com faixa cadastrada',
        $podeEditar ? '+ Nova faixa' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="tipo" onchange="this.form.submit()">
          <option value="">Solo e foliar</option>
          <?php foreach (TIPOS as $tk => $tl): ?>
            <option value="<?= $tk ?>"<?= $fTipo === $tk ? ' selected' : '' ?>><?= $tl ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma faixa cadastrada. Sem faixa, as análises ficam “sem classificação” (o sistema nunca inventa referência).</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Tipo</th><th>Nutriente</th><th>Variedade</th><th>Fase</th><th>Unidade</th>
        <th class="num">Mínimo</th>
        <th class="num">Ideal mín</th>
        <th class="num">Ideal máx</th>
        <th class="num">Máximo</th>
        <th>Status</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="vbadge <?= $r['tipo'] === 'solo' ? 'vb-warn' : 'vb-info' ?>"><?= h(TIPOS[$r['tipo']]) ?></span></td>
          <td><strong><?= h($r['simbolo'] ?: $r['nutriente_nome']) ?></strong> <span class="vhint"><?= h($r['nutriente_nome']) ?></span></td>
          <td><?= h($r['variedade_nome'] ?? '') ?: '<span class="vhint">Todas</span>' ?></td>
          <td><?php if (($r['var_fase_nome'] ?? null) !== null): ?><?= h($r['var_fase_nome']) ?> <span class="vhint" title="Fase por variedade (dia 0 = poda)">var.</span><?php elseif ($r['fenologia_codigo']): ?><?= h($r['fenologia_codigo']) ?><?php else: ?><span class="vhint">Todas</span><?php endif; ?></td>
          <td><?= h($r['unidade'] ?? '') ?: '—' ?></td>
          <td class="num"><?= $fmtFaixa($r['minimo']) ?></td>
          <td class="num" style="color:#1E6B34"><strong><?= $fmtFaixa($r['ideal_min']) ?></strong></td>
          <td class="num" style="color:#1E6B34"><strong><?= $fmtFaixa($r['ideal_max']) ?></strong></td>
          <td class="num"><?= $fmtFaixa($r['maximo']) ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('nutricao.faixas_nutricionais.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar esta faixa?') ?>
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
<div class="vmodal<?= $edit || $abrirNova ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar faixa' : 'Nova faixa nutricional' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_select('tipo', 'Tipo de análise', TIPOS, $edit['tipo'] ?? ($abrirNova ? $novaTipo : 'solo'), true, '') ?>
        <div class="vfield">
          <label>Nutriente *</label>
          <select name="nutriente_id" required>
            <option value="">— Selecione —</option>
            <?php foreach ($nutrientes as $n): ?>
              <option value="<?= (int)$n['id'] ?>"<?= ($edit && (int)$edit['nutriente_id'] === (int)$n['id']) || ($abrirNova && $novaNutriente === (int)$n['id']) ? ' selected' : '' ?>>
                <?= h(($n['simbolo'] ? $n['simbolo'] . ' — ' : '') . $n['nome']) ?><?= $n['unidade_padrao'] ? ' (' . h($n['unidade_padrao']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= vero_f_select('variedade_id', 'Variedade', $variedades, $edit['variedade_id'] ?? null, false, '— Todas —') ?>
        <div class="vfield">
          <label>Fase (por variedade)</label>
          <select name="variedade_fase_id" id="faixa-varfase">
            <option value="">— Todas / usar fase por cultura —</option>
          </select>
          <div class="vhint" id="faixa-varfase-hint">Escolha a variedade acima: as fases da fenologia vigente dela aparecem aqui (dia 0 = poda). Preenchida, a faixa vale para variedade × fase.</div>
        </div>
        <div class="vfield">
          <label>Fase fenológica (cultura — fallback)</label>
          <select name="fenologia_id">
            <option value="">— Todas —</option>
            <?php foreach ($fenologias as $f): ?>
              <option value="<?= (int)$f['id'] ?>"<?= $edit && (int)($edit['fenologia_id'] ?? 0) === (int)$f['id'] ? ' selected' : '' ?>>
                <?= h($f['codigo'] . ' — ' . $f['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= vero_f_select('porta_enxerto_id', 'Porta-enxerto (opcional)', $portaEnxertos, $edit['porta_enxerto_id'] ?? null, false, '— Todos —') ?>
        <?= vero_f_text('unidade', 'Unidade', $edit['unidade'] ?? '', false, 'Ex.: mg/dm3, g/kg, %') ?>
        <?= vero_f_text('minimo', 'Mínimo', $edit && $edit['minimo'] !== null ? numFmt((float)$edit['minimo'], 2) : '') ?>
        <?= vero_f_text('ideal_min', 'Ideal mínimo', $edit && $edit['ideal_min'] !== null ? numFmt((float)$edit['ideal_min'], 2) : '', true) ?>
        <?= vero_f_text('ideal_max', 'Ideal máximo', $edit && $edit['ideal_max'] !== null ? numFmt((float)$edit['ideal_max'], 2) : '', true) ?>
        <?= vero_f_text('maximo', 'Máximo', $edit && $edit['maximo'] !== null ? numFmt((float)$edit['maximo'], 2) : '') ?>
        <div class="full"><?= vero_f_text('observacao', 'Observação / fonte (RT, laboratório)', $edit['observacao'] ?? '') ?></div>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
/* Opção B (mig 166): o select "Fase (por variedade)" mostra as fases da fenologia
   vigente da variedade escolhida (cadastro — sem auto por data). */
const FAIXA_VARFASES = <?= jsvar($varFasesFaixaMap) ?>;
const FAIXA_EDIT_VARFASE = <?= $edit && ($edit['variedade_fase_id'] ?? null) !== null ? (int)$edit['variedade_fase_id'] : 'null' ?>;
(function () {
  const varSel = document.querySelector('#vm-form select[name="variedade_id"]');
  const sel = document.getElementById('faixa-varfase');
  if (!sel) return;
  function refresh(keep) {
    const prev = keep || sel.value || '';
    const vid = parseInt((varSel && varSel.value) || '0', 10);
    sel.innerHTML = '<option value="">— Todas / usar fase por cultura —</option>';
    const fases = FAIXA_VARFASES[vid] || null;
    if (fases && fases.length) {
      fases.forEach(f => sel.add(new Option(f.nome + ' (' + f.ini + '–' + f.fim + 'd)', String(f.id))));
      if (prev && [...sel.options].some(o => o.value === prev)) sel.value = prev;
    }
  }
  if (varSel) varSel.addEventListener('change', () => refresh(null));
  refresh(FAIXA_EDIT_VARFASE ? String(FAIXA_EDIT_VARFASE) : '');
})();
</script>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
