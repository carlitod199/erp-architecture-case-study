<?php
/* ============================================================
   VERO — Relatórios / Exportações  (casca de fallback)
   Rota: /relatorios/exportacoes.php   Guard: relatorios.exportacoes
   Reestruturação 19/07: o hub virou MODAL (relatorios/_exportacoes_modal.php),
   aberto pelo botão "Exportações" de toda tela de relatório (_rel_base.php).
   Esta página fica como fallback p/ bookmarks/rota: renderiza o header
   padrão + o modal JÁ ABERTO — sem a grade antiga.
   A lista canônica de datasets vive no parcial (vero_exportacoes_grupos);
   cada CSV faz GET ao endpoint de stream existente
   (relatorios_*.php?csv=<chave>&ini=&fim=, guard antes de streamar).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_exportacoes_modal.php';

$ini = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : date('Y-01-01');
$fim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : date('Y-m-d');

$GUARD      = ['macro' => 'relatorios', 'micro' => 'exportacoes'];
$PAGE_VIEW  = 'relatorios_exportacoes';
$PAGE_TITLE = 'Exportações';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Exportações', 'Todos os exports CSV em um lugar — arquivos com BOM UTF-8, separados por ponto e vírgula (Excel)', null) ?>

  <div class="vcard">
    <div class="vempty" style="text-align:center;padding:28px 16px">
      <div style="font-size:15px;font-weight:600;margin-bottom:4px">As exportações agora abrem em um modal</div>
      <div class="vhint" style="margin-bottom:12px">Use o botão <em>Exportações</em> em qualquer tela de relatório — ou reabra aqui.</div>
      <button class="vbtn vbtn-primary" type="button" onclick="vModalOpen('vm-exportacoes')">Abrir exportações</button>
    </div>
  </div>
</div>

<?= vero_exportacoes_modal_html($ini, $fim, true) ?>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
