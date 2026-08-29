<?php
/* ============================================================
   VERO — Fiscal / Importação de NFS-e  (tela real)
   Substitui o mock. Rota: /fiscal/importacao_nfse.php
   Guard: fiscal.importacao_nfse
   NFS-e não tem layout XML único nacional (varia por município) —
   registro guiado dos dados + anexo do PDF/XML. Parse automático
   por município fica para fase futura, conforme a prefeitura.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_fiscal_services.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'registrar') {
        vero_require('fiscal.importacao_nfse.editar');
        $numero = vero_str('numero', 30);
        $valor  = vero_dec('valor_total');
        if ($numero === null || $valor === null || $valor <= 0) {
            vero_flash('erro', 'Número e valor da NFS-e são obrigatórios.');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $docId = vero_insert('fiscal_documentos', [
                'tipo'          => 'outro', /* ENUM não tem nfse — registrado como outro, número prefixado */
                'numero'        => 'NFSe ' . $numero,
                'fornecedor_id' => vero_int('fornecedor_id') ?: null,
                'valor_total'   => $valor,
                'data_emissao'  => vero_date('data_emissao'),
                'status'        => 'importado',
            ]);
            $file = $_FILES['arquivo'] ?? null;
            if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                fiscal_anexar_arquivo((int)$docId, $file, 'nfse');
            }
            $pdo->commit();
            vero_flash('ok', "NFS-e {$numero} registrada" . (isset($file['name']) && $file['error'] === UPLOAD_ERR_OK ? ' com anexo.' : '.'));
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT d.*, f.nome AS fornecedor FROM fiscal_documentos d
       LEFT JOIN fornecedores f ON f.id = d.fornecedor_id
      WHERE d.tenant_id = :t AND d.tipo = 'outro' AND d.numero LIKE 'NFSe %'
      ORDER BY d.id DESC LIMIT 50", [':t' => $t]);

$fornecedores = vero_options('fornecedores', 'nome');

$GUARD      = ['macro' => 'fiscal', 'micro' => 'importacao_nfse'];
$PAGE_VIEW  = 'fiscal_importacao_nfse';
$PAGE_TITLE = 'Importação de NFS-e';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('fiscal.importacao_nfse.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Importação de NFS-e', 'Notas de serviço — registro guiado com anexo (o layout varia por município)', null) ?>

  <?php if ($podeEditar): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Registrar NFS-e</strong></div>
    <form class="vform" method="post" enctype="multipart/form-data" style="padding:0 14px 14px">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="registrar">
      <div class="vgrid">
        <?= vero_f_text('numero', 'Número da NFS-e', '', true) ?>
        <?= vero_f_select('fornecedor_id', 'Prestador', ['' => 'Sem vínculo'] + $fornecedores, '', false, '') ?>
        <?= vero_f_text('valor_total', 'Valor (R$)', '', true) ?>
        <div class="vfield">
          <label>Data de emissão</label>
          <input type="date" name="data_emissao" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="vfield full">
          <label>Arquivo (PDF ou XML da prefeitura)</label>
          <input type="file" name="arquivo" accept=".pdf,.xml">
        </div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-primary" type="submit">Registrar NFS-e</button>
      </div>
    </form>
    <div class="vhint" style="padding:0 14px 12px">
      NFS-e não tem layout XML único nacional — cada prefeitura usa o seu. O parse automático pode ser
      adicionado por município na fase futura, conforme a demanda do cliente.
    </div>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>NFS-e registradas</strong>
      <span class="vsub"><?= count($rows) ?> nota(s)</span></div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma NFS-e registrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Número</th><th>Prestador</th><th>Emissão</th>
        <th style="text-align:right">Valor (R$)</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong class="vnum"><?= h($r['numero']) ?></strong></td>
          <td><?= h($r['fornecedor'] ?? '—') ?></td>
          <td class="vnum"><?= $r['data_emissao'] ? date('d/m/Y', strtotime((string)$r['data_emissao'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['valor_total'], 2) ?></strong></td>
          <td><?= $r['status'] === 'conciliado' ? '<span class="vbadge vb-ok">Conciliado</span>'
                : ($r['status'] === 'recusado' ? '<span class="vbadge vb-off">Recusado</span>'
                : '<span class="vbadge vb-info">Registrado</span>') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
