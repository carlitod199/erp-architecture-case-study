<?php
/* ============================================================
   VERO — Fiscal / Upload de XML  (tela real)
   Substitui o mock. Rota: /fiscal/upload_xml.php
   Guard: fiscal.upload_xml
   Visão técnica dos XMLs processados (anexos tipo xml_nfe) com
   integridade (SHA-256) — o envio usa a mesma engine da
   Importação de NF-e.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

$rows = vero_rows(
    "SELECT an.*, d.numero, d.chave, d.status AS doc_status, f.nome AS fornecedor
       FROM agro_anexos an
       LEFT JOIN fiscal_documentos d ON d.id = an.origem_id AND d.tenant_id = an.tenant_id
       LEFT JOIN fornecedores f ON f.id = d.fornecedor_id
      WHERE an.tenant_id = :t AND an.origem_tipo = 'fiscal_documento' AND an.tipo_arquivo = 'xml_nfe'
      ORDER BY an.id DESC LIMIT 60", [':t' => $t]);

$GUARD      = ['macro' => 'fiscal', 'micro' => 'upload_xml'];
$PAGE_VIEW  = 'fiscal_upload_xml';
$PAGE_TITLE = 'Upload de XML';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Upload de XML', 'Fila técnica dos XMLs processados, com hash de integridade — o envio fica na Importação de NF-e', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <span class="vsub"><?= count($rows) ?> XML(s) processado(s)</span>
      <a class="vbtn vbtn-primary vbtn-sm" href="<?= $base ?>/fiscal/importacao_nfe.php">Enviar XML de NF-e</a>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum XML processado — envie pela Importação de NF-e.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Arquivo</th><th>NF-e</th><th>Emitente</th>
        <th style="text-align:right">Tamanho</th>
        <th>SHA-256</th><th>Recebido em</th>
        <th style="text-align:right"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome_original'] ?? '—') ?></strong></td>
          <td class="vnum"><?= h($r['numero'] ?? '—') ?>
            <?= $r['doc_status'] === 'conciliado' ? '<span class="vbadge vb-ok">Conciliado</span>' : '' ?></td>
          <td><?= h($r['fornecedor'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= $r['tamanho_bytes'] !== null ? numFmt((float)$r['tamanho_bytes'] / 1024, 1) . ' KB' : '—' ?></td>
          <td class="vnum vhint" style="font-size:.72rem"><?= h(mb_substr((string)($r['hash_sha256'] ?? ''), 0, 16)) ?>…</td>
          <td class="vnum"><?= date('d/m/Y H:i', strtotime((string)$r['created_at'])) ?></td>
          <td style="text-align:right">
            <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base . h((string)$r['url']) ?>" target="_blank" download>Baixar</a>
            <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/fiscal/documentos.php?ver=<?= (int)$r['origem_id'] ?>">Documento</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">O hash SHA-256 garante que o XML arquivado é o mesmo que foi recebido — guarde os XMLs por 5 anos (obrigação do contribuinte).</div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
