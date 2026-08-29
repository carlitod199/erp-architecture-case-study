<?php
/* ============================================================
   VERO — Custos / Metas da Safra  (A3-T16 — P-44 aprovada)
   Rota: /custeio/metas.php · Guard: custos.metas_safra (micro
   novo — rota no $rotasReais é 1 linha do A0). CRUD de
   gestao_metas (migration 136): indicadores VALIDADOS em PHP;
   o dashboard executivo confronta meta × realizado.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const INDICADORES_META = [
    'custo_total'  => 'Custo total (R$)',
    'custo_ha'     => 'Custo por hectare (R$/ha)',
    'kg_total'     => 'Colheita (kg)',
    'kg_ha'        => 'Produtividade (kg/ha)',
    'faturamento'  => 'Faturamento (R$)',
    'preco_kg'     => 'Preço médio (R$/kg)',
    'margem_pct'   => 'Margem (%)',
];

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');
    if ($acao === 'salvar') {
        vero_require('custeio.metas_safra.editar');
        $safraId = vero_int('safra_id');
        $indicador = (string)($_POST['indicador'] ?? '');
        $valor = vero_dec('valor_meta');
        if (!$safraId || !isset(INDICADORES_META[$indicador]) || $valor === null || $valor <= 0) {
            vero_flash('erro', 'Safra, indicador válido e valor da meta (> 0) são obrigatórios.');
            vero_redirect();
        }
        $dados = ['valor_meta' => $valor, 'observacao' => vero_str('observacao', 255)];
        $exist = vero_row("SELECT id FROM gestao_metas WHERE tenant_id=:t AND safra_id=:s AND indicador=:i",
            [':t' => $t, ':s' => $safraId, ':i' => $indicador]);
        if ($exist) vero_update('gestao_metas', (int)$exist['id'], $dados);
        else vero_insert('gestao_metas', $dados + ['safra_id' => $safraId, 'indicador' => $indicador]);
        vero_flash('ok', 'Meta salva.');
        vero_redirect('?safra=' . $safraId);
    }
    if ($acao === 'excluir') {
        vero_require('custeio.metas_safra.excluir');
        $id = vero_int('id');
        if ($id) vero_delete('gestao_metas', $id);
        vero_flash('ok', 'Meta removida.');
        vero_redirect();
    }
}

$safras = vero_options('agro_safras', 'identificacao');
$fSafra = (int)($_GET['safra'] ?? 0);
if (!$fSafra && $safras) $fSafra = (int)array_key_first($safras);
$metas = $fSafra ? vero_rows("SELECT * FROM gestao_metas WHERE tenant_id=:t AND safra_id=:s ORDER BY indicador",
    [':t' => $t, ':s' => $fSafra]) : [];

$GUARD      = ['macro' => 'custos', 'micro' => 'metas_safra'];
$PAGE_VIEW  = 'custos_metas_safra';
$PAGE_TITLE = 'Metas da Safra';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
$podeEditar = vero_can('custeio.metas_safra.editar');
/* formatação de apresentação por indicador (não altera dado nem cálculo) */
$metaPrefixo = ['custo_total' => 'R$ ', 'custo_ha' => 'R$ ', 'faturamento' => 'R$ ', 'preco_kg' => 'R$ '];
$metaSufixo  = ['kg_total' => ' kg', 'kg_ha' => ' kg/ha', 'margem_pct' => '%'];
$fmtMeta = static function (string $ind, $v) use ($metaPrefixo, $metaSufixo): string {
    $casas = ($ind === 'kg_total' || $ind === 'kg_ha') ? 0 : 2;
    return ($metaPrefixo[$ind] ?? '') . numFmt((float)$v, $casas) . ($metaSufixo[$ind] ?? '');
};
$safraNome = $fSafra && isset($safras[$fSafra]) ? (string)$safras[$fSafra] : '';

/* Design 25/07: categoria + cor + ícone por indicador para os cards de meta */
$INDINFO = [
    'custo_total' => ['Custos', '#B3402A'],
    'custo_ha'    => ['Custos', '#B3402A'],
    'kg_total'    => ['Produção', '#1A7F4B'],
    'kg_ha'       => ['Produção', '#1A7F4B'],
    'faturamento' => ['Financeiro', '#005059'],
    'preco_kg'    => ['Financeiro', '#005059'],
    'margem_pct'  => ['Financeiro', '#C9A961'],
];
$CATICO = [
    'Custos'     => '<rect x="2.5" y="6" width="19" height="13" rx="2.5"/><path d="M2.5 10.5h19M16 13.5h2.5"/>',
    'Produção'   => '<path d="M12 21v-8"/><path d="M12 13C7.6 13 5 10.2 5 6c4.2 0 7 2.6 7 7Z"/><path d="M12 15c0-3.4 2.6-6 6.8-6 0 3.4-2.6 6-6.8 6Z"/>',
    'Financeiro' => '<circle cx="9.5" cy="12" r="6.5"/><path d="M16 6.2a6.5 6.5 0 0 1 0 11.6"/>',
];
$hex2rgba = static fn(string $hex, float $a): string => sprintf('rgba(%d,%d,%d,%.2f)',
    hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)), $a);
/* ordena os cards pela ordem canônica (agrupa por categoria naturalmente) */
$ordemInd = array_flip(array_keys(INDICADORES_META));
usort($metas, static fn($a, $b) => ($ordemInd[$a['indicador']] ?? 99) <=> ($ordemInd[$b['indicador']] ?? 99));
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Metas da Safra', '', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <label style="font-size:12px;font-weight:600;color:#4A4034;margin:0">Safra</label>
      <form method="get" style="margin:0"><select name="safra" onchange="this.form.submit()">
        <?php foreach ($safras as $sid => $sn): ?>
          <option value="<?= $sid ?>"<?= $fSafra === (int)$sid ? ' selected' : '' ?>><?= h($sn) ?></option>
        <?php endforeach; ?>
      </select></form>
      <span style="flex:1"></span>
      <span class="vbadge vb-info"><?= count($metas) ?> meta(s)</span>
    </div>
    <div class="vhint" style="padding:12px 14px 0;font-size:12px;line-height:1.5">
      Defina o alvo de cada indicador para esta safra — o Dashboard Executivo confronta cada meta com o realizado.
      Cada indicador tem uma única meta por safra: salvar o mesmo indicador de novo atualiza o valor.
    </div>
    <?php if ($podeEditar): ?>
    <form method="post" class="vform" style="padding:12px 14px;display:flex;gap:12px;flex-wrap:wrap;align-items:end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="safra_id" value="<?= $fSafra ?>">
      <div class="vfield" style="flex:2 1 220px"><label>Indicador</label><select name="indicador">
        <?php foreach (INDICADORES_META as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?>
      </select></div>
      <div class="vfield" style="flex:0 1 150px"><label>Valor da meta</label><input type="text" name="valor_meta" style="text-align:right" placeholder="0,00"></div>
      <div class="vfield" style="flex:2 1 260px"><label>Observação</label><input type="text" name="observacao" placeholder="opcional"></div>
      <button class="vbtn vbtn-primary" type="submit">Salvar meta</button>
    </form>
    <?php endif; ?>
  </div>

  <style>
    .metas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(244px,1fr));gap:14px}
    .meta-card{position:relative;background:var(--card,#fff);border:1px solid #EFE8DA;border-radius:16px;
      padding:16px 18px 14px;display:flex;flex-direction:column;gap:9px;overflow:hidden;
      transition:transform .16s var(--ease,ease),box-shadow .16s,border-color .16s}
    .meta-card:hover{transform:translateY(-2px);box-shadow:0 12px 26px -14px rgba(0,0,0,.26);border-color:#E0D6C4}
    .meta-card::before{content:"";position:absolute;top:0;left:0;bottom:0;width:4px;background:var(--mc,#005059)}
    .meta-top{display:flex;align-items:center;gap:11px}
    .meta-ico{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;flex:0 0 auto}
    .meta-ico svg{width:21px;height:21px;color:var(--mc)}
    .meta-cat{font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--mc);font-weight:700}
    .meta-lbl{font-size:12px;color:var(--muted,#8A7C68);line-height:1.25;margin-top:1px}
    .meta-val{font-size:1.62rem;font-weight:800;color:#1E1610;letter-spacing:-.01em;line-height:1.08}
    .meta-obs{font-size:11.5px;color:var(--muted2,#9A8C78);line-height:1.4;
      border-top:1px dashed #EFE8DA;padding-top:8px}
    .meta-del{margin-top:auto;display:flex;justify-content:flex-end}
    .meta-del .vbtn{color:#9A3B2A}
  </style>

  <?php if (!$metas): ?>
    <div class="vcard"><div class="vempty" style="padding:30px 16px;text-align:center">
      <div style="font-size:34px;line-height:1;margin-bottom:8px">🎯</div>
      Nenhuma meta definida para <strong><?= $safraNome !== '' ? h($safraNome) : 'esta safra' ?></strong>.
      <?= $podeEditar ? '<br>Use o formulário acima para criar a primeira.' : '' ?>
    </div></div>
  <?php else: ?>
  <div class="metas-grid">
    <?php foreach ($metas as $m):
      $ind = (string)$m['indicador'];
      [$cat, $cor] = $INDINFO[$ind] ?? ['—', '#005059'];
      $ico = $CATICO[$cat] ?? ''; ?>
    <div class="meta-card" style="--mc:<?= h($cor) ?>">
      <div class="meta-top">
        <span class="meta-ico" style="background:<?= $hex2rgba($cor, .12) ?>;border:1px solid <?= $hex2rgba($cor, .24) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $ico ?></svg>
        </span>
        <div>
          <div class="meta-cat"><?= h($cat) ?></div>
          <div class="meta-lbl"><?= h(INDICADORES_META[$ind] ?? $ind) ?></div>
        </div>
      </div>
      <div class="meta-val"><?= h($fmtMeta($ind, $m['valor_meta'])) ?></div>
      <?php if (!empty($m['observacao'])): ?><div class="meta-obs"><?= h((string)$m['observacao']) ?></div><?php endif; ?>
      <?php if (vero_can('custeio.metas_safra.excluir')): ?>
      <div class="meta-del">
        <form method="post" data-confirm="Remover esta meta?" data-confirm-danger data-confirm-ok="Remover" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))" style="margin:0">
          <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
          <input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
          <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Remover</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
