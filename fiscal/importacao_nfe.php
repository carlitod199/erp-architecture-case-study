<?php
/* ============================================================
   VERO — Fiscal / Importação de NF-e  (tela real)
   Substitui o mock. Rota: /fiscal/importacao_nfe.php
   Guard: fiscal.importacao_nfe
   Upload do XML da NF-e (layout 4.00, nfeProc ou NFe): extrai
   chave, número, emitente (get-or-create fornecedor por CNPJ),
   data, valor e itens. Idempotente por chave; o XML vira anexo.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_fiscal_services.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'importar') {
        vero_require('fiscal.importacao_nfe.editar');
        $file = $_FILES['xml'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            vero_flash('erro', 'Selecione o arquivo XML da NF-e.');
            vero_redirect();
        }
        if ((int)$file['size'] > 2 * 1024 * 1024) {
            vero_flash('erro', 'XML acima de 2 MB — confira o arquivo.');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $conteudo = (string)file_get_contents((string)$file['tmp_name']);
            $res = fiscal_importar_nfe_xml($conteudo);
            if ($res['ja_existia']) {
                $pdo->rollBack();
                vero_flash('erro', 'NF-e já importada (chave ' . $res['chave'] . ') — nada foi duplicado.');
                vero_redirect('?ver=' . $res['documento_id']);
            }
            fiscal_anexar_arquivo($res['documento_id'], $file, 'xml_nfe');
            $pdo->commit();
            vero_flash('ok', 'NF-e ' . $res['numero'] . ' importada — ' . $res['fornecedor'] .
                ', R$ ' . numFmt($res['valor'], 2) . ', ' . $res['itens'] . ' item(ns). XML anexado.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }
}

$importados = vero_rows(
    "SELECT d.*, f.nome AS fornecedor,
            (SELECT COUNT(*) FROM fiscal_documento_itens i WHERE i.tenant_id = d.tenant_id AND i.documento_id = d.id) AS itens
       FROM fiscal_documentos d
       LEFT JOIN fornecedores f ON f.id = d.fornecedor_id
      WHERE d.tenant_id = :t AND d.tipo = 'nfe' AND d.chave IS NOT NULL
      ORDER BY d.id DESC LIMIT 30", [':t' => $t]);

$GUARD      = ['macro' => 'fiscal', 'micro' => 'importacao_nfe'];
$PAGE_VIEW  = 'fiscal_importacao_nfe';
$PAGE_TITLE = 'Importação de NF-e';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('fiscal.importacao_nfe.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Importação de NF-e', 'Envie o XML da nota (layout 4.00) — chave, emitente, valores e itens são extraídos automaticamente', null) ?>

  <?php if ($podeEditar): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Enviar XML</strong></div>
    <form class="vform" method="post" enctype="multipart/form-data"
          style="padding:0 14px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="importar">
      <div class="vfield" style="flex:1;min-width:280px">
        <label>Arquivo XML da NF-e *</label>
        <input type="file" name="xml" accept=".xml,text/xml" required>
      </div>
      <button class="vbtn vbtn-primary" type="submit">Importar NF-e</button>
    </form>
    <div class="vhint" style="padding:0 14px 12px">
      Aceita o XML autorizado (nfeProc) ou o XML da NFe. Reimportar a mesma chave não duplica.
      Emitente sem cadastro vira fornecedor automaticamente (por CNPJ). O DANFE em PDF vai em Upload de PDF.
    </div>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>NF-e importadas</strong>
      <span class="vsub"><?= count($importados) ?> nota(s) ·
        <a href="<?= $base ?>/fiscal/documentos.php">todos os documentos</a></span></div>
    <?php if (!$importados): ?>
      <div class="vempty">Nenhuma NF-e importada ainda.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Número</th><th>Emitente</th><th>Emissão</th>
        <th>Chave</th>
        <th style="text-align:right">Valor (R$)</th>
        <th style="text-align:right">Itens</th>
        <th style="text-align:right"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($importados as $r): ?>
        <tr>
          <td><strong class="vnum"><?= h($r['numero'] ?? '—') ?></strong></td>
          <td><?= h($r['fornecedor'] ?? '—') ?></td>
          <td class="vnum"><?= $r['data_emissao'] ? date('d/m/Y', strtotime((string)$r['data_emissao'])) : '—' ?></td>
          <td class="vnum vhint" style="font-size:.75rem"><?= h(mb_substr((string)($r['chave'] ?? ''), 0, 22)) ?>…</td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['valor_total'], 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['itens'] ?></td>
          <td style="text-align:right">
            <a class="vicon vicon-acao" href="<?= $base ?>/fiscal/documentos.php?ver=<?= (int)$r['id'] ?>" title="Detalhe" aria-label="Detalhe"><?= vero_ico_olho() ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
