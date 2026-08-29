<?php
/* ============================================================
   VERO — Fiscal / Emissão de NF-e  (tela real — registro externo)
   Substitui o mock. Rota: /fiscal/emissao_nfe.php
   Guard: fiscal.emissao_nfe
   LIMITE HONESTO: emissão integrada à SEFAZ exige certificado
   digital A1, credenciamento e homologação — fora do escopo (D7).
   Esta tela REGISTRA as notas emitidas externamente (ex.: emissor
   gratuito da SEFAZ) e as vincula ao acervo fiscal.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_fiscal_services.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'registrar') {
        vero_require('fiscal.emissao_nfe.editar');
        $numero = vero_str('numero', 30);
        $valor  = vero_dec('valor_total');
        if ($numero === null || $valor === null || $valor <= 0) {
            vero_flash('erro', 'Número e valor da nota são obrigatórios.');
            vero_redirect();
        }
        $chave = preg_replace('/\D/', '', (string)(vero_str('chave', 60) ?? ''));
        if ($chave !== '' && !preg_match('/^\d{44}$/', $chave)) {
            vero_flash('erro', 'Chave de acesso deve ter 44 dígitos (ou ficar em branco).');
            vero_redirect();
        }
        if ($chave !== '') {
            $dup = vero_val("SELECT id FROM fiscal_documentos WHERE tenant_id=:t AND chave=:c", [':t' => $t, ':c' => $chave]);
            if ($dup) {
                vero_flash('erro', 'Já existe documento com esta chave.');
                vero_redirect();
            }
        }
        $tipo = (string)($_POST['tipo'] ?? 'nfe');
        if (!in_array($tipo, ['nfe', 'nfce'], true)) $tipo = 'nfe';
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $docId = vero_insert('fiscal_documentos', [
                'tipo'         => $tipo,
                'numero'       => $numero,
                'chave'        => $chave ?: null,
                'valor_total'  => $valor,
                'data_emissao' => vero_date('data_emissao') ?? date('Y-m-d'),
                'status'       => 'importado',
            ]);
            $file = $_FILES['xml'] ?? null;
            if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                fiscal_anexar_arquivo((int)$docId, $file, 'xml_nfe');
            }
            $pdo->commit();
            vero_flash('ok', strtoupper($tipo) . " {$numero} registrada no acervo fiscal.");
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }
}

$emitidas = vero_rows(
    "SELECT * FROM fiscal_documentos
      WHERE tenant_id = :t AND tipo IN ('nfe','nfce') AND fornecedor_id IS NULL
      ORDER BY id DESC LIMIT 30", [':t' => $t]);

$GUARD      = ['macro' => 'fiscal', 'micro' => 'emissao_nfe'];
$PAGE_VIEW  = 'fiscal_emissao_nfe';
$PAGE_TITLE = 'Emissão de NF-e';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('fiscal.emissao_nfe.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Emissão de NF-e', 'Registro das notas emitidas externamente — a emissão integrada exige certificado digital e contratação', null) ?>

  <div class="vcard" style="margin-bottom:14px;border-left:4px solid #8A6D1A">
    <div style="padding:12px 14px" class="vhint">
      <strong>Como funciona hoje:</strong> a emissão integrada à SEFAZ (certificado digital A1, credenciamento,
      homologação) está fora do go-live por decisão do projeto (D7). Emita a nota no
      <em>emissor gratuito da SEFAZ</em> ou no sistema do contador e <strong>registre aqui</strong> —
      o VERO guarda número, chave, valor e o XML, mantendo o acervo fiscal completo. Quando o cliente
      contratar a emissão integrada, esta tela evolui sem retrabalho.
    </div>
  </div>

  <?php if ($podeEditar): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Registrar nota emitida</strong></div>
    <form class="vform" method="post" enctype="multipart/form-data" style="padding:0 14px 14px">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="registrar">
      <div class="vgrid">
        <?= vero_f_select('tipo', 'Tipo', ['nfe' => 'NF-e', 'nfce' => 'NFC-e'], 'nfe', true, '') ?>
        <?= vero_f_text('numero', 'Número', '', true) ?>
        <div class="full"><?= vero_f_text('chave', 'Chave de acesso (44 dígitos, opcional)', '') ?></div>
        <?= vero_f_text('valor_total', 'Valor (R$)', '', true) ?>
        <div class="vfield">
          <label>Data de emissão</label>
          <input type="date" name="data_emissao" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="vfield full">
          <label>XML autorizado (opcional — recomendado)</label>
          <input type="file" name="xml" accept=".xml,text/xml">
        </div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-primary" type="submit">Registrar nota</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Notas próprias registradas</strong>
      <span class="vsub"><?= count($emitidas) ?> nota(s)</span></div>
    <?php if (!$emitidas): ?>
      <div class="vempty">Nenhuma nota própria registrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Tipo</th><th>Número</th><th>Emissão</th>
        <th style="text-align:right">Valor (R$)</th><th>Chave</th>
      </tr></thead>
      <tbody>
      <?php foreach ($emitidas as $r): ?>
        <tr>
          <td><span class="vbadge vb-info"><?= strtoupper((string)$r['tipo']) ?></span></td>
          <td><strong class="vnum"><?= h($r['numero'] ?? '—') ?></strong></td>
          <td class="vnum"><?= $r['data_emissao'] ? date('d/m/Y', strtotime((string)$r['data_emissao'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['valor_total'], 2) ?></strong></td>
          <td class="vnum vhint" style="font-size:.75rem"><?= $r['chave'] ? h(mb_substr((string)$r['chave'], 0, 22)) . '…' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
