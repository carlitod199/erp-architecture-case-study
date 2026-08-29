<?php
/* ============================================================
   VERO — Custos / Parâmetros de Cultura (A3-T25)
   Rota: /custeio/parametros_cultura.php · Guard: custeio.parametros_cultura
   SPEC §3 (DB-44): cultura × safra × metodologia + produtividade,
   preço e área PREVISTOS (UNIQUE tenant×cultura×safra = upsert).
   Unidades (unidade_comercial, fator_conversao, peso_unidade_kg)
   editam INLINE aqui mas GRAVAM em `agro_culturas` (DB-41 —
   DECISIONS 05/07: duplicar unidade no parâmetro dessincroniza).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('custeio.parametros_cultura.editar');
        $culturaId = vero_int('cultura_id');
        $safraId = vero_int('safra_id');
        $metId = vero_int('metodologia_id');
        $cultura = $culturaId ? vero_row("SELECT * FROM agro_culturas WHERE id=:i AND tenant_id=:t", [':i' => $culturaId, ':t' => $t]) : null;
        $okSafra = $safraId ? vero_val("SELECT id FROM agro_safras WHERE id=:i AND tenant_id=:t", [':i' => $safraId, ':t' => $t]) : null;
        $okMet = $metId ? vero_val("SELECT id FROM agro_custo_metodologias WHERE id=:i AND tenant_id=:t AND ativo=1", [':i' => $metId, ':t' => $t]) : null;
        if (!$cultura || !$okSafra || !$okMet) {
            vero_flash('erro', 'Cultura, safra e metodologia ATIVA são obrigatórias.');
            vero_redirect();
        }
        $prod = vero_dec('produtividade_prevista_ha');
        $preco = vero_dec('preco_previsto_unidade');
        $area = vero_dec('area_prevista_ha');
        if ($prod !== null && $prod < 0 || $preco !== null && $preco < 0 || $area !== null && $area < 0) {
            vero_flash('erro', 'Produtividade, preço e área não podem ser negativos.');
            vero_redirect();
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            /* parâmetro: upsert pela UNIQUE (tenant, cultura, safra) */
            $dados = [
                'metodologia_id' => $metId,
                'produtividade_prevista_ha' => $prod, 'preco_previsto_unidade' => $preco,
                'area_prevista_ha' => $area, 'observacoes' => vero_str('observacoes', 255), 'ativo' => 1,
            ];
            $exist = vero_row("SELECT id FROM agro_custo_parametros_cultura
                                WHERE tenant_id=:t AND cultura_id=:c AND safra_id=:s",
                [':t' => $t, ':c' => $culturaId, ':s' => $safraId]);
            if ($exist) vero_update('agro_custo_parametros_cultura', (int)$exist['id'], $dados);
            else vero_insert('agro_custo_parametros_cultura', $dados + ['cultura_id' => $culturaId, 'safra_id' => $safraId]);

            /* unidades: INLINE aqui, gravadas na CULTURA (DB-41 — fonte única).
               Correção da auditoria (grave): campo VAZIO NÃO apaga o valor já
               gravado na cultura — só grava o que veio preenchido. */
            $un = vero_str('unidade_comercial', 12);
            $fator = vero_dec('fator_conversao');
            $peso = vero_dec('peso_unidade_kg');
            $pesoCont = vero_dec('peso_contentor_kg'); /* WP-CALC Z-05: peso do contentor (colheita a granel) */
            if ($fator !== null && $fator <= 0) throw new RuntimeException('Fator de conversão deve ser > 0.');
            if ($peso !== null && $peso <= 0) throw new RuntimeException('Peso por unidade deve ser > 0.');
            if ($pesoCont !== null && $pesoCont <= 0) throw new RuntimeException('Peso do contentor deve ser > 0.');
            $unidades = [];
            if ($un !== null) $unidades['unidade_comercial'] = $un;
            if ($fator !== null) $unidades['fator_conversao'] = $fator;
            if ($peso !== null) $unidades['peso_unidade_kg'] = $peso;
            if ($pesoCont !== null) $unidades['peso_contentor_kg'] = $pesoCont;
            if ($unidades) vero_update('agro_culturas', $culturaId, $unidades);
            $pdo->commit();
            vero_flash('ok', 'Parâmetros salvos'
                . ($unidades ? ' — unidades gravadas na CULTURA (valem para todas as safras).' : ' (unidades da cultura mantidas — campos vazios não apagam).'));
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect('?safra=' . $safraId);
    }

    if ($acao === 'inativar') {
        vero_require('custeio.parametros_cultura.excluir');
        $id = vero_int('id');
        $p = $id ? vero_row("SELECT * FROM agro_custo_parametros_cultura WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if ($p) {
            vero_update('agro_custo_parametros_cultura', (int)$id, ['ativo' => 0]);
            vero_flash('ok', 'Parâmetro inativado.');
        }
        vero_redirect();
    }
}

$safras = vero_options('agro_safras', 'identificacao');
$fSafra = (int)($_GET['safra'] ?? 0);
if (!$fSafra && $safras) $fSafra = (int)array_key_first($safras);

$parametros = $fSafra ? vero_rows(
    "SELECT p.*, c.nome AS cultura, c.unidade_comercial, c.fator_conversao, c.peso_unidade_kg,
            c.unidade_produtividade, m.nome AS metodologia, m.tipo_ciclo
       FROM agro_custo_parametros_cultura p
       JOIN agro_culturas c ON c.id = p.cultura_id
       JOIN agro_custo_metodologias m ON m.id = p.metodologia_id
      WHERE p.tenant_id = :t AND p.safra_id = :s
      ORDER BY p.ativo DESC, c.nome", [':t' => $t, ':s' => $fSafra]) : [];

$culturas = vero_rows("SELECT * FROM agro_culturas WHERE tenant_id=:t ORDER BY nome", [':t' => $t]);
$metodologias = vero_rows("SELECT id, nome, tipo_ciclo FROM agro_custo_metodologias WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => $t]);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row(
        "SELECT p.*, c.unidade_comercial, c.fator_conversao, c.peso_unidade_kg, c.peso_contentor_kg
           FROM agro_custo_parametros_cultura p JOIN agro_culturas c ON c.id = p.cultura_id
          WHERE p.id=:i AND p.tenant_id=:t", [':i' => (int)$_GET['editar'], ':t' => $t]);
}

$GUARD      = ['macro' => 'custos', 'micro' => 'parametros_cultura'];
$PAGE_VIEW  = 'custos_parametros_cultura';
$PAGE_TITLE = 'Parâmetros de Cultura';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
$podeEditar = vero_can('custeio.parametros_cultura.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Parâmetros de Cultura (custo de produção)',
      'Produtividade, preço e área PREVISTOS por cultura × safra + unidades comerciais da cultura', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get"><select name="safra" onchange="this.form.submit()">
        <?php foreach ($safras as $sid => $sn): ?>
          <option value="<?= $sid ?>"<?= $fSafra === (int)$sid ? ' selected' : '' ?>><?= h($sn) ?></option>
        <?php endforeach; ?>
      </select></form>
      <span class="vhint">1 parâmetro por cultura×safra (novo salvar da mesma dupla ATUALIZA — sem duplicar)</span>
    </div>
    <?php if (!$parametros): ?><div class="vempty">Nenhum parâmetro para esta safra.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>Cultura</th><th>Metodologia</th>
        <th style="text-align:right">Produtividade prevista (/ha)</th>
        <th style="text-align:right">Preço previsto (R$/un.)</th>
        <th style="text-align:right">Área prevista (ha)</th>
        <th>Unidade comercial (da cultura)</th><th>Situação</th><th style="text-align:right">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($parametros as $p): ?>
        <tr<?= (int)$p['ativo'] ? '' : ' style="opacity:.55"' ?>>
          <td><strong><?= h($p['cultura']) ?></strong></td>
          <td><?= h($p['metodologia']) ?> <span class="vhint">(<?= h((string)$p['tipo_ciclo']) ?>)</span></td>
          <td class="vnum" style="text-align:right"><?= $p['produtividade_prevista_ha'] !== null ? numFmt((float)$p['produtividade_prevista_ha'], 0) : '—' ?>
            <span class="vhint"><?= h(str_replace('_', '/', (string)($p['unidade_produtividade'] ?? ''))) ?></span></td>
          <td class="vnum" style="text-align:right"><?= $p['preco_previsto_unidade'] !== null ? numFmt((float)$p['preco_previsto_unidade'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $p['area_prevista_ha'] !== null ? numFmt((float)$p['area_prevista_ha'], 2) : '—' ?></td>
          <td><?= h($p['unidade_comercial'] ?? '—') ?>
            <?php if ($p['fator_conversao'] !== null): ?><span class="vhint">× <?= numFmt((float)$p['fator_conversao'], 3) ?></span><?php endif; ?>
            <?php if ($p['peso_unidade_kg'] !== null): ?><span class="vhint"><?= numFmt((float)$p['peso_unidade_kg'], 2) ?> kg/un.</span><?php endif; ?></td>
          <td><?= (int)$p['ativo'] ? '<span class="vbadge vb-ok">Ativo</span>' : '<span class="vbadge vb-off">Inativo</span>' ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar && (int)$p['ativo']): ?>
              <?= vero_btn_icone(vero_ico_lapis(), 'Editar', '', '?safra=' . rawurlencode((string)$fSafra) . '&editar=' . (int)$p['id']) ?>
              <?php if (vero_can('custeio.parametros_cultura.excluir')): ?>
              <form method="post" data-confirm="Inativar este parâmetro?" data-confirm-danger data-confirm-ok="Inativar" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="inativar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="vicon vicon-del" type="submit" title="Inativar" aria-label="Inativar"><?= vero_ico_lixeira() ?></button>
              </form>
              <?php endif; ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($podeEditar): ?>
  <div class="vcard">
    <div class="vtoolbar"><strong><?= $edit ? 'Editar parâmetro' : 'Novo parâmetro' ?></strong>
      <?php if ($edit): ?><a class="vbtn vbtn-ghost vbtn-sm" href="?safra=<?= $fSafra ?>">cancelar</a><?php endif; ?></div>
    <form class="vform" method="post" style="padding:10px 14px">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <div class="vgrid">
        <div class="vfield"><label>Cultura *</label><select name="cultura_id" required>
          <option value="">—</option>
          <?php foreach ($culturas as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= $edit && (int)$edit['cultura_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['nome']) ?></option>
          <?php endforeach; ?>
        </select></div>
        <div class="vfield"><label>Safra *</label><select name="safra_id" required>
          <?php foreach ($safras as $sid => $sn): ?>
            <option value="<?= $sid ?>"<?= ($edit ? (int)$edit['safra_id'] : $fSafra) === (int)$sid ? ' selected' : '' ?>><?= h($sn) ?></option>
          <?php endforeach; ?>
        </select></div>
        <div class="vfield"><label>Metodologia *</label><select name="metodologia_id" required>
          <?php foreach ($metodologias as $m): ?>
            <option value="<?= (int)$m['id'] ?>"<?= $edit && (int)$edit['metodologia_id'] === (int)$m['id'] ? ' selected' : '' ?>>
              <?= h($m['nome']) ?> (<?= h((string)$m['tipo_ciclo']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
        <?= vero_f_text('produtividade_prevista_ha', 'Produtividade prevista (/ha)', $edit && $edit['produtividade_prevista_ha'] !== null ? numFmt((float)$edit['produtividade_prevista_ha'], 0) : '') ?>
        <?= vero_f_text('preco_previsto_unidade', 'Preço previsto (R$/unidade)', $edit && $edit['preco_previsto_unidade'] !== null ? numFmt((float)$edit['preco_previsto_unidade'], 2) : '') ?>
        <?= vero_f_text('area_prevista_ha', 'Área prevista (ha)', $edit && $edit['area_prevista_ha'] !== null ? numFmt((float)$edit['area_prevista_ha'], 2) : '') ?>
        <div class="full"><?= vero_f_text('observacoes', 'Observações', $edit['observacoes'] ?? '') ?></div>
      </div>
      <div class="vtoolbar" style="margin-top:10px"><strong>Unidades da CULTURA</strong>
        <span class="vhint">editam aqui, gravam em agro_culturas — valem para TODAS as safras; campo vazio NÃO apaga o valor atual</span></div>
      <div class="vgrid">
        <?= vero_f_text('unidade_comercial', 'Unidade comercial (cx, sc, t…)', $edit['unidade_comercial'] ?? '') ?>
        <?= vero_f_text('fator_conversao', 'Fator produção→comercial', $edit && $edit['fator_conversao'] !== null ? numFmt((float)$edit['fator_conversao'], 3) : '') ?>
        <?= vero_f_text('peso_unidade_kg', 'Peso por unidade / caixa (kg)', $edit && $edit['peso_unidade_kg'] !== null ? numFmt((float)$edit['peso_unidade_kg'], 2) : '') ?>
        <?= vero_f_text('peso_contentor_kg', 'Peso do contentor (kg)', $edit && isset($edit['peso_contentor_kg']) && $edit['peso_contentor_kg'] !== null ? numFmt((float)$edit['peso_contentor_kg'], 2) : '', false, 'Colheita a granel — contentores = produção (kg) ÷ este peso (padrão 20)') ?>
      </div>
      <div class="vform-actions"><button class="vbtn vbtn-primary" type="submit">Salvar</button></div>
    </form>
    <script>
    /* correção da auditoria: pré-preenche as unidades da CULTURA selecionada */
    const CULT_UN = <?= jsvar(array_map(static fn($c) => [
        'id' => (int)$c['id'],
        'un' => $c['unidade_comercial'],
        'fator' => $c['fator_conversao'] !== null ? numFmt((float)$c['fator_conversao'], 3) : null,
        'peso' => $c['peso_unidade_kg'] !== null ? numFmt((float)$c['peso_unidade_kg'], 2) : null,
        'pesoCont' => isset($c['peso_contentor_kg']) && $c['peso_contentor_kg'] !== null ? numFmt((float)$c['peso_contentor_kg'], 2) : null,
    ], $culturas)) ?>;
    const selCult = document.querySelector('[name="cultura_id"]');
    if (selCult) selCult.addEventListener('change', () => {
      const c = CULT_UN.find(x => String(x.id) === selCult.value);
      const set = (n, v) => { const el = document.querySelector('[name="' + n + '"]'); if (el) el.value = v ?? ''; };
      set('unidade_comercial', c ? c.un : '');
      set('fator_conversao', c ? c.fator : '');
      set('peso_unidade_kg', c ? c.peso : '');
      set('peso_contentor_kg', c ? c.pesoCont : '');
    });
    </script>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
