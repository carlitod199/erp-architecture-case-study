<?php
declare(strict_types=1);
/* ============================================================
   VERO — Packing House / Etiqueta de Caixa (GS1-128 · exportação/GlobalG.A.P.)
   Rota: /packing/etiqueta_caixa.php · Guard: packing.etiqueta_caixa.ver
   Etiqueta de CAIXA (product case label) para rastreabilidade fiscal/
   exportação: barra GS1-128 com (01)GTIN (3103)peso (10)lote + texto legível
   (lote, peso, GGN, variedade, categoria, produtor, origem, data). O LOTE é
   DERIVADO DA RECEPÇÃO (lote COLH- do item recebido) — amarra a caixa à carga
   colhida. O SKU (ph_skus) traz GTIN/peso/variedade/calibre/categoria/marca.
   GS1: includes/vero_gs1.php · Página standalone (impressão limpa).
   ============================================================ */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_gs1.php';

if (!vero_can('packing.etiqueta_caixa.ver') && !vero_can('packing.skus.ver')) {
    http_response_code(403); exit('Sem permissão.');
}

const ETQC_CATEGORIAS = [
    'extra' => 'Extra', 'cat1' => 'Categoria I', 'cat2' => 'Categoria II',
    'interno' => 'Mercado interno', 'industria' => 'Indústria',
];
const ETQC_FMTS = [
    'case' => ['rot' => 'Caixa 100×150 mm', 'page' => '100mm 150mm'],
    'a4'   => ['rot' => 'A4 (1 por folha)',  'page' => 'A4'],
];

/* ── entradas ── */
$itemId = (int)($_GET['item_id'] ?? 0);
$skuId  = (int)($_GET['sku_id'] ?? 0);
$qtd    = max(1, min(200, (int)($_GET['qtd'] ?? 4)));
$data   = (string)($_GET['data'] ?? date('Y-m-d'));
$pesoIn = isset($_GET['peso']) && $_GET['peso'] !== '' ? (float)str_replace(',', '.', (string)$_GET['peso']) : null;
$fmt    = (string)($_GET['fmt'] ?? 'case');
if (!isset(ETQC_FMTS[$fmt])) $fmt = 'case';
$embed  = (int)($_GET['embed'] ?? 0) === 1; // aberta dentro do modal (iframe) → sem "Voltar"

/* ── itens recebidos com lote COLH- (fonte do lote) ── */
$itens = vero_rows(
    "SELECT ri.id AS item_id, ri.colhido_em, ri.peso_kg, ri.produtor_id,
            ri.variedade_id, ri.talhao_id,
            r.numero AS recepcao_numero,
            el.codigo_lote AS lote,
            v.nome AS variedade_nome, t.nome AS talhao_nome, sa.identificacao AS safra_nome
       FROM ph_recepcao_itens ri
       JOIN ph_recepcoes r     ON r.id = ri.recepcao_id AND r.tenant_id = ri.tenant_id
       JOIN estoque_lotes el   ON el.id = ri.lote_estoque_id AND el.tenant_id = ri.tenant_id
       LEFT JOIN agro_variedades v ON v.id = ri.variedade_id AND v.tenant_id = ri.tenant_id
       LEFT JOIN agro_talhoes t    ON t.id = ri.talhao_id AND t.tenant_id = ri.tenant_id
       LEFT JOIN agro_safra_talhoes stt ON stt.id = ri.safra_talhao_id AND stt.tenant_id = ri.tenant_id
       LEFT JOIN agro_safras sa    ON sa.id = stt.safra_id AND sa.tenant_id = ri.tenant_id
      WHERE ri.tenant_id = :t AND el.codigo_lote LIKE 'COLH-%'
      ORDER BY ri.id DESC", [':t' => vero_tenant()]);
$itensById = [];
foreach ($itens as $it) { $itensById[(int)$it['item_id']] = $it; }

/* itens recebidos SEM lote amarrado — recepções aceitas antes da correção do
   aceite (ph_recepcao_lote_colh) ou carga órfã sem safra/talhão derivável;
   contados só para a mensagem do vazio explicar a causa real (gestor 19/08) */
$itensSemLote = (int)vero_val(
    "SELECT COUNT(*) FROM ph_recepcao_itens
      WHERE tenant_id = :t AND lote_estoque_id IS NULL", [':t' => vero_tenant()]);

/* ── SKUs ativos ── */
$skus = vero_rows(
    "SELECT s.id, s.codigo, s.descricao, s.gtin, s.peso_nominal_kg, s.calibre, s.categoria,
            s.marca_comercial, v.nome AS variedade_nome, c.nome AS cultura_nome
       FROM ph_skus s
       LEFT JOIN agro_variedades v ON v.id = s.variedade_id AND v.tenant_id = s.tenant_id
       LEFT JOIN agro_culturas   c ON c.id = s.cultura_id   AND c.tenant_id = s.tenant_id
      WHERE s.tenant_id = :t AND s.ativo = 1 ORDER BY s.codigo", [':t' => vero_tenant()]);
$skusById = [];
foreach ($skus as $s) { $skusById[(int)$s['id']] = $s; }

/* ── GGN (GlobalG.A.P.) da unidade ── */
$ggn = (string)(vero_val(
    "SELECT numero FROM ph_certificacoes
      WHERE tenant_id = :t AND ativo = 1 AND norma = 'GLOBALGAP' AND escopo = 'unidade'
        AND (validade IS NULL OR validade >= CURDATE()) ORDER BY id DESC LIMIT 1",
    [':t' => vero_tenant()]) ?? '');

/* ── monta a etiqueta quando item + SKU escolhidos ── */
$L = null; // dados resolvidos da etiqueta
if ($itemId && $skuId && isset($itensById[$itemId], $skusById[$skuId])) {
    $it = $itensById[$itemId];
    $sk = $skusById[$skuId];
    $gtin14 = vero_gs1_gtin14($sk['gtin'] !== null ? (string)$sk['gtin'] : null);
    $peso   = $pesoIn ?? ($sk['peso_nominal_kg'] !== null ? (float)$sk['peso_nominal_kg'] : null);
    $lote   = (string)$it['lote'];
    $es     = vero_gs1_element_string($gtin14, $peso, $lote);
    $L = [
        'svg'       => vero_gs1_128_svg($es['raw']),
        'hri'       => $es['hri'],
        'gtin14'    => $gtin14,
        'peso'      => $peso,
        'lote'      => $es['lote'],
        'variedade' => (string)($sk['variedade_nome'] ?? $it['variedade_nome'] ?? ''),
        'cultura'   => (string)($sk['cultura_nome'] ?? ''),
        'marca'     => (string)($sk['marca_comercial'] ?? ''),
        'descricao' => (string)($sk['descricao'] ?? ''),
        'calibre'   => (string)($sk['calibre'] ?? ''),
        'categoria' => ETQC_CATEGORIAS[$sk['categoria'] ?? ''] ?? '',
        'produtor'  => $it['produtor_id'] !== null ? ('Produtor #' . (int)$it['produtor_id']) : '—',
        'talhao'    => (string)($it['talhao_nome'] ?? ''),
        'safra'     => (string)($it['safra_nome'] ?? ''),
        'recepcao'  => (string)($it['recepcao_numero'] ?? ''),
        'data'      => $data,
    ];
}

$F = ETQC_FMTS[$fmt];
$semGtin = $L !== null && $L['gtin14'] === null;
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Etiqueta de Caixa — Packing</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,Segoe UI,Arial,sans-serif;color:#101828;background:#f4f2ec}
  .bar{position:sticky;top:0;z-index:5;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;
       padding:12px 18px;background:#fff;border-bottom:1px solid #e5e7eb}
  .bar .fld{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#475467}
  .bar select,.bar input{padding:7px 9px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px;color:#101828}
  .bar .grow{flex:1}
  .btn{padding:8px 16px;border-radius:7px;border:1px solid #101828;background:#101828;color:#fff;font-size:14px;cursor:pointer;text-decoration:none;display:inline-block}
  .btn.ghost{background:#fff;color:#101828}
  .note{padding:10px 18px;font-size:13px;color:#92400e;background:#fffbeb;border-bottom:1px solid #fde68a}
  .empty{padding:40px 18px;color:#475467}
  /* etiqueta */
  .labels{padding:18px;display:flex;flex-wrap:wrap;gap:14px}
  .etq{width:100mm;min-height:150mm;background:#fff;border:1px solid #cbd5e1;border-radius:6px;
       padding:5mm;display:flex;flex-direction:column;gap:3mm}
  .etq .brand{font-weight:800;font-size:15pt;line-height:1.1}
  .etq .desc{font-size:10pt;color:#334155}
  .etq .tags{display:flex;gap:8px;flex-wrap:wrap;font-size:9.5pt;color:#0f172a}
  .etq .tags span{background:#f1f5f9;border-radius:4px;padding:2px 7px}
  .etq .bc{margin:2mm 0}
  .etq .bc svg{width:100%;height:20mm;display:block}
  .etq .hri{font:600 8.5pt ui-monospace,Consolas,monospace;letter-spacing:.5px;text-align:center;word-break:break-all}
  .etq .fields{display:grid;grid-template-columns:1fr 1fr;gap:2mm 4mm;font-size:9.5pt;margin-top:auto}
  .etq .fields .k{color:#64748b;font-size:8pt;text-transform:uppercase;letter-spacing:.4px}
  .etq .fields b{font-size:10.5pt}
  .etq .foot{border-top:1px solid #e2e8f0;padding-top:2mm;font-size:8pt;color:#64748b;display:flex;justify-content:space-between}
  @media print{
    .bar,.note{display:none !important}
    body{background:#fff}
    .labels{padding:0;gap:0}
    @page{size:<?= $F['page'] ?>;margin:0}
    .labels{padding:<?= $fmt === 'a4' ? '10mm' : '0' ?>}
    .etq{border:none;border-radius:0;width:100mm;height:150mm;break-after:page}
    .etq:last-child{break-after:auto}
  }
</style>
</head>
<body>
  <form class="bar" method="get" action="">
    <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
    <div class="fld"><label>Item recebido (lote COLH-)</label>
      <select name="item_id" onchange="this.form.submit()" style="min-width:280px">
        <option value="">— Selecione a carga recebida —</option>
        <?php foreach ($itens as $it): $iid = (int)$it['item_id']; ?>
          <option value="<?= $iid ?>"<?= $iid === $itemId ? ' selected' : '' ?>>
            <?= h((string)$it['lote']) ?> · <?= h((string)($it['variedade_nome'] ?? '—')) ?>
            · rec <?= h((string)$it['recepcao_numero']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fld"><label>SKU (produto acabado)</label>
      <select name="sku_id" onchange="this.form.submit()" style="min-width:240px">
        <option value="">— Selecione o SKU —</option>
        <?php foreach ($skus as $s): $sid = (int)$s['id']; ?>
          <option value="<?= $sid ?>"<?= $sid === $skuId ? ' selected' : '' ?>>
            <?= h((string)$s['codigo']) ?> — <?= h((string)$s['descricao']) ?><?= $s['gtin'] ? '' : ' (sem GTIN)' ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fld"><label>Peso líq. (kg)</label>
      <input type="text" name="peso" value="<?= $pesoIn !== null ? h((string)$pesoIn) : '' ?>" placeholder="do SKU" style="width:90px"></div>
    <div class="fld"><label>Data</label>
      <input type="date" name="data" value="<?= h($data) ?>"></div>
    <div class="fld"><label>Quantidade</label>
      <input type="number" name="qtd" min="1" max="200" value="<?= $qtd ?>" style="width:80px"></div>
    <div class="fld"><label>Formato</label>
      <select name="fmt" onchange="this.form.submit()">
        <?php foreach (ETQC_FMTS as $k => $v): ?>
          <option value="<?= h($k) ?>"<?= $k === $fmt ? ' selected' : '' ?>><?= h($v['rot']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fld"><label>&nbsp;</label><button class="btn ghost" type="submit">Atualizar</button></div>
    <div class="grow"></div>
    <?php if (!$embed): ?><div class="fld"><label>&nbsp;</label><a class="btn ghost" href="<?= h(BIOS_BASE) ?>/packing/crachas">Voltar</a></div><?php endif; ?>
    <?php if ($L): ?><div class="fld"><label>&nbsp;</label>
      <button class="btn" type="button" onclick="window.print()">Imprimir <?= $qtd ?> etiqueta(s)</button></div><?php endif; ?>
  </form>

  <?php if ($semGtin): ?>
    <div class="note">Este SKU não tem <b>GTIN</b> válido — a barra sai só com <b>(3103)peso + (10)lote</b>.
      Para exportação/varejo, cadastre o GTIN do SKU em Packing → SKUs.</div>
  <?php endif; ?>

  <?php if (!$itens): ?>
    <div class="empty"><?php if ($itensSemLote > 0): ?>
      Há <?= $itensSemLote ?> item(ns) recebido(s) <b>sem lote COLH- amarrado</b> — recepção aceita antes da correção
      ou carga sem safra/registro de colheita vinculável. Novas recepções aceitas já geram o lote automaticamente;
      para os itens antigos, vincule a carga à colheita (ou confirme a entrada da colheita no estoque) e contate o suporte.
    <?php else: ?>
      Nenhum item recebido com lote COLH- ainda. Aceite uma recepção em Packing → Recepção para gerar o lote.
    <?php endif; ?></div>
  <?php elseif (!$skus): ?>
    <div class="empty">Nenhum SKU cadastrado. Cadastre o produto acabado em Packing → SKUs.</div>
  <?php elseif (!$L): ?>
    <div class="empty">Selecione a <b>carga recebida</b> (lote COLH-) e o <b>SKU</b> para gerar a etiqueta.</div>
  <?php else: ?>
    <div class="labels">
      <?php for ($i = 0; $i < $qtd; $i++): ?>
        <div class="etq">
          <div class="brand"><?= h($L['marca'] !== '' ? $L['marca'] : $L['descricao']) ?: 'PRODUTO' ?></div>
          <?php if ($L['marca'] !== '' && $L['descricao'] !== ''): ?><div class="desc"><?= h($L['descricao']) ?></div><?php endif; ?>
          <div class="tags">
            <?php if ($L['variedade'] !== ''): ?><span><?= h($L['variedade']) ?></span><?php endif; ?>
            <?php if ($L['categoria'] !== ''): ?><span><?= h($L['categoria']) ?></span><?php endif; ?>
            <?php if ($L['calibre'] !== ''): ?><span>Cal. <?= h($L['calibre']) ?></span><?php endif; ?>
          </div>

          <div class="bc"><?= $L['svg'] ?></div>
          <div class="hri"><?= h($L['hri']) ?></div>

          <div class="fields">
            <?php if ($L['gtin14'] !== null): ?><div><div class="k">GTIN</div><b><?= h($L['gtin14']) ?></b></div><?php endif; ?>
            <div><div class="k">Lote (batch)</div><b><?= h($L['lote']) ?></b></div>
            <div><div class="k">Peso líquido</div><b><?= $L['peso'] !== null ? numFmt((float)$L['peso'], 3) . ' kg' : '—' ?></b></div>
            <div><div class="k">GGN (GlobalG.A.P.)</div><b><?= $ggn !== '' ? h($ggn) : '—' ?></b></div>
            <div><div class="k">Produtor</div><b><?= h($L['produtor']) ?></b></div>
            <div><div class="k">Origem</div><b>Brasil</b></div>
            <?php if ($L['talhao'] !== ''): ?><div><div class="k">Talhão / Safra</div><b><?= h($L['talhao']) ?><?= $L['safra'] !== '' ? ' · ' . h($L['safra']) : '' ?></b></div><?php endif; ?>
            <div><div class="k">Data</div><b><?= h(dateBR($L['data'])) ?></b></div>
          </div>

          <div class="foot"><span>Recepção <?= h($L['recepcao']) ?></span><span>VERO Packing</span></div>
        </div>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</body>
</html>
