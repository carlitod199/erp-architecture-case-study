<?php
declare(strict_types=1);
/* ============================================================
   VERO — Packing House / Etiquetas de QR (caixa do produto)
   Rota: /packing/etiquetas.php?tipo=colaborador&id=2 · Guard: packing.crachas
   Gera uma folha/rolo de ETIQUETAS SÓ COM O QR de UM colaborador (N cópias),
   para colar na caixa do produto — sem nome/código na etiqueta (o scanner lê
   o QR, que codifica o código do crachá; texto na caixa seria ruído).
   Formatos: térmica 50×50 e 100×50 mm (1 etiqueta por página — casa com
   impressora térmica de rolo, ex.: Zebra ZD220/ZD230) e A4 adesiva (grade,
   para recortar). Página standalone (sem o menu) para impressão limpa.
   QR: includes/vero_qr.php.
   ============================================================ */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_qr.php';
require_once __DIR__ . '/../includes/vero_gs1.php'; // Code 128 (código de barras)

if (!vero_can('packing.crachas.ver')) { http_response_code(403); exit('Sem permissão.'); }

const ETQ_ORIGENS = ['colaborador' => 'agro_operadores', 'terceirizado' => 'rh_terceirizados'];

$tipo = (string)($_GET['tipo'] ?? '');
$id   = (int)($_GET['id'] ?? 0);
$qtd  = max(1, min(200, (int)($_GET['qtd'] ?? 24)));
$fmt  = (string)($_GET['fmt'] ?? 'term50');
$sym  = (string)($_GET['sym'] ?? 'barras'); // padrão: código de barras (Code 128)
if (!in_array($sym, ['qr', 'barras'], true)) $sym = 'barras';

$FMTS = [
    'term50'  => ['rot' => 'Térmica 50×50 mm',  'page' => '50mm 50mm',  'qr' => '38mm', 'bar_w' => '44mm', 'bar_h' => '13mm', 'perPage' => true],
    'term100' => ['rot' => 'Térmica 100×50 mm', 'page' => '100mm 50mm', 'qr' => '38mm', 'bar_w' => '82mm', 'bar_h' => '16mm', 'perPage' => true],
    'a4'      => ['rot' => 'A4 adesiva (recortar)', 'page' => 'A4',      'qr' => '34mm', 'bar_w' => '46mm', 'bar_h' => '13mm', 'perPage' => false],
];
if (!isset($FMTS[$fmt])) $fmt = 'term50';
$F = $FMTS[$fmt];
$embed = (int)($_GET['embed'] ?? 0) === 1; // aberta dentro do modal (iframe) → sem "Voltar"

if (!isset(ETQ_ORIGENS[$tipo]) || !$id) { vero_redirect(BIOS_BASE . '/packing/crachas'); }
$tab = ETQ_ORIGENS[$tipo];
$p = vero_row("SELECT nome, cracha FROM {$tab} WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => vero_tenant()]);
if (!$p || (string)($p['cracha'] ?? '') === '') {
    vero_flash('erro', 'Colaborador sem QR Code — gere o código antes de imprimir etiquetas.');
    vero_redirect(BIOS_BASE . '/packing/crachas');
}
$nome   = (string)$p['nome'];
$codigo = (string)$p['cracha'];
$svg    = $sym === 'barras' ? vero_code128_svg($codigo, 40) : vero_qr_svg($codigo); // mesmo símbolo em todas as cópias
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Etiquetas QR — <?= h($nome) ?></title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,Segoe UI,Arial,sans-serif;color:#101828;background:#f4f2ec}
  .bar{position:sticky;top:0;display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;
       padding:12px 18px;background:#fff;border-bottom:1px solid #e5e7eb}
  .bar .fld{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#475467}
  .bar input,.bar select{padding:7px 9px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px;color:#101828}
  .bar .grow{flex:1}
  .btn{padding:8px 16px;border-radius:7px;border:1px solid #101828;background:#101828;color:#fff;
       font-size:14px;cursor:pointer;text-decoration:none;display:inline-block}
  .btn.ghost{background:#fff;color:#101828}
  .meta{font-size:13px;color:#475467}
  .meta b{color:#101828}
  /* pré-visualização em tela */
  .labels{display:flex;flex-wrap:wrap;gap:10px;padding:18px}
  .etq{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1.5mm}
  .sym{display:block}
  .sym svg{display:block;width:100%;height:auto}
  .cod{font-family:ui-monospace,"Cascadia Code",Consolas,monospace;font-weight:700;letter-spacing:.5px;line-height:1}
  @media screen{
    .etq{width:<?= $sym === 'barras' ? '164' : '132' ?>px;min-height:132px;border:1px dashed #cbd5e1;border-radius:6px;background:#fff;padding:8px 6px}
    .sym{width:<?= $sym === 'barras' ? '144' : '90' ?>px}
<?php if ($sym === 'barras'): ?>    .sym svg{height:44px}
<?php endif; ?>    .cod{font-size:12px;color:#101828}
  }
  @media print{
    .bar{display:none !important}
    body{background:#fff}
    .labels{gap:0;padding:0}
    .cod{color:#000}
<?php if ($F['perPage']): ?>
    @page{size:<?= $F['page'] ?>;margin:0}
    .etq{width:100%;height:100vh;break-after:page}
    .etq:last-child{break-after:auto}
    .sym{width:<?= $sym === 'barras' ? $F['bar_w'] : $F['qr'] ?>}
<?php if ($sym === 'barras'): ?>    .sym svg{height:<?= $F['bar_h'] ?>}
<?php endif; ?>    .cod{font-size:10pt}
<?php else: ?>
    @page{size:A4;margin:0}
    .labels{display:grid;grid-template-columns:repeat(<?= $sym === 'barras' ? 3 : 4 ?>,1fr);gap:6mm;padding:8mm}
    .etq{break-inside:avoid;padding:2mm}
    .sym{width:<?= $sym === 'barras' ? $F['bar_w'] : $F['qr'] ?>}
<?php if ($sym === 'barras'): ?>    .sym svg{height:<?= $F['bar_h'] ?>}
<?php endif; ?>    .cod{font-size:8pt}
<?php endif; ?>
  }
</style>
</head>
<body>
  <form class="bar" method="get" action="">
    <input type="hidden" name="tipo" value="<?= h($tipo) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
    <div class="fld"><label>Colaborador</label>
      <div class="meta"><b><?= h($nome) ?></b> · código <b><?= h($codigo) ?></b></div></div>
    <div class="fld"><label for="sym">Símbolo</label>
      <select id="sym" name="sym" onchange="this.form.submit()">
        <option value="qr"<?= $sym === 'qr' ? ' selected' : '' ?>>QR Code</option>
        <option value="barras"<?= $sym === 'barras' ? ' selected' : '' ?>>Código de barras</option>
      </select></div>
    <div class="fld"><label for="fmt">Formato da etiqueta</label>
      <select id="fmt" name="fmt" onchange="this.form.submit()">
        <?php foreach ($FMTS as $k => $v): ?>
          <option value="<?= h($k) ?>"<?= $k === $fmt ? ' selected' : '' ?>><?= h($v['rot']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fld"><label for="qtd">Quantidade</label>
      <input id="qtd" type="number" name="qtd" min="1" max="200" value="<?= $qtd ?>" style="width:90px"></div>
    <div class="fld"><label>&nbsp;</label><button class="btn ghost" type="submit">Atualizar</button></div>
    <div class="grow"></div>
    <?php if (!$embed): ?><div class="fld"><label>&nbsp;</label>
      <a class="btn ghost" href="<?= h(BIOS_BASE) ?>/packing/crachas">Voltar</a></div><?php endif; ?>
    <div class="fld"><label>&nbsp;</label>
      <button class="btn" type="button" onclick="window.print()">Imprimir <?= $qtd ?> etiqueta(s)</button></div>
  </form>

  <div class="labels">
    <?php for ($i = 0; $i < $qtd; $i++): ?>
      <div class="etq"><span class="sym"><?= $svg ?></span><span class="cod"><?= h($codigo) ?></span></div>
    <?php endfor; ?>
  </div>
</body>
</html>
