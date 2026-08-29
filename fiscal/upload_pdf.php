<?php
/* ============================================================
   VERO — Fiscal / Upload de PDF  (tela real)
   Substitui o mock. Rota: /fiscal/upload_pdf.php
   Guard: fiscal.upload_pdf
   Anexa DANFE/PDF a um documento fiscal existente (agro_anexos).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_fiscal_services.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'anexar') {
        vero_require('fiscal.upload_pdf.editar');
        $docId = vero_int('documento_id');
        $file = $_FILES['pdf'] ?? null;
        $doc = $docId ? vero_row("SELECT * FROM fiscal_documentos WHERE id=:i AND tenant_id=:t",
            [':i' => $docId, ':t' => $t]) : null;
        if (!$doc || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            vero_flash('erro', 'Selecione o documento e o arquivo PDF.');
            vero_redirect();
        }
        if (strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
            vero_flash('erro', 'Apenas arquivos PDF nesta tela — XML vai na Importação de NF-e.');
            vero_redirect();
        }
        if ((int)$file['size'] > 10 * 1024 * 1024) {
            vero_flash('erro', 'PDF acima de 10 MB.');
            vero_redirect();
        }
        try {
            fiscal_anexar_arquivo((int)$docId, $file, 'danfe_pdf');
            vero_flash('ok', 'PDF anexado ao documento ' . ($doc['numero'] ?? '#' . $docId) . '.');
        } catch (Throwable $e) {
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }
}

$documentos = vero_rows(
    "SELECT d.id, d.tipo, d.numero, f.nome AS fornecedor FROM fiscal_documentos d
       LEFT JOIN fornecedores f ON f.id = d.fornecedor_id
      WHERE d.tenant_id = :t AND d.status <> 'recusado' ORDER BY d.id DESC LIMIT 100", [':t' => $t]);

$anexos = vero_rows(
    "SELECT an.*, d.numero FROM agro_anexos an
       LEFT JOIN fiscal_documentos d ON d.id = an.origem_id AND d.tenant_id = an.tenant_id
      WHERE an.tenant_id = :t AND an.origem_tipo = 'fiscal_documento' AND an.tipo_arquivo IN ('danfe_pdf','nfse')
      ORDER BY an.id DESC LIMIT 50", [':t' => $t]);

$GUARD      = ['macro' => 'fiscal', 'micro' => 'upload_pdf'];
$PAGE_VIEW  = 'fiscal_upload_pdf';
$PAGE_TITLE = 'Upload de PDF';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('fiscal.upload_pdf.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Upload de PDF', 'Anexa o DANFE ou PDF ao documento fiscal correspondente', null) ?>

  <?php if ($podeEditar): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Anexar PDF</strong></div>
    <?php if (!$documentos): ?>
      <div class="vempty">Nenhum documento fiscal — importe ou registre um documento antes.</div>
    <?php else: ?>
    <form class="vform" method="post" enctype="multipart/form-data"
          style="padding:0 14px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="anexar">
      <div class="vfield" style="min-width:280px"><label>Documento *</label>
        <select name="documento_id" required>
          <option value="">Selecione…</option>
          <?php foreach ($documentos as $d): ?>
            <option value="<?= (int)$d['id'] ?>">
              <?= h(strtoupper((string)$d['tipo']) . ' ' . ($d['numero'] ?? '#' . $d['id']) . ' — ' . ($d['fornecedor'] ?? 'sem fornecedor')) ?>
            </option>
          <?php endforeach; ?>
        </select></div>
      <div class="vfield" style="flex:1;min-width:240px"><label>Arquivo PDF *</label>
        <input type="file" name="pdf" accept=".pdf,application/pdf" required></div>
      <button class="vbtn vbtn-primary" type="submit">Anexar</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>PDFs anexados</strong>
      <span class="vsub"><?= count($anexos) ?> arquivo(s)</span></div>
    <?php if (!$anexos): ?>
      <div class="vempty">Nenhum PDF anexado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Arquivo</th><th>Documento</th>
        <th style="text-align:right">Tamanho</th><th>Enviado em</th>
        <th style="text-align:right"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($anexos as $an): ?>
        <tr>
          <td><strong><?= h($an['nome_original'] ?? '—') ?></strong></td>
          <td class="vnum"><?= h($an['numero'] ?? '#' . $an['origem_id']) ?></td>
          <td class="vnum" style="text-align:right"><?= $an['tamanho_bytes'] !== null ? numFmt((float)$an['tamanho_bytes'] / 1024, 1) . ' KB' : '—' ?></td>
          <td class="vnum"><?= date('d/m/Y H:i', strtotime((string)$an['created_at'])) ?></td>
          <td style="text-align:right">
            <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base . h((string)$an['url']) ?>" target="_blank">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
