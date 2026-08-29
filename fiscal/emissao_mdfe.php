<?php
/* ============================================================
   VERO — Fiscal / Emissão de MDF-e  (tela real — registro externo)
   Substitui o mock. Rota: /fiscal/emissao_mdfe.php
   Guard: fiscal.emissao_mdfe
   Mesmo limite da NF-e: emissão integrada exige certificado e
   credenciamento (fora, D7). Registra manifestos emitidos
   externamente — tipo 'outro' com prefixo MDFe (o ENUM de
   fiscal_documentos não tem mdfe), vinculável ao frete da venda.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'registrar') {
        vero_require('fiscal.emissao_mdfe.editar');
        $numero = vero_str('numero', 25);
        if ($numero === null) {
            vero_flash('erro', 'Número do MDF-e é obrigatório.');
            vero_redirect();
        }
        $chave = preg_replace('/\D/', '', (string)(vero_str('chave', 60) ?? ''));
        if ($chave !== '' && !preg_match('/^\d{44}$/', $chave)) {
            vero_flash('erro', 'Chave deve ter 44 dígitos (ou ficar em branco).');
            vero_redirect();
        }
        $valorMdfe = vero_dec('valor_total') ?? 0;
        if ($valorMdfe < 0) { /* A11: valor do MDF-e nunca é negativo */
            vero_flash('erro', 'O valor não pode ser negativo.');
            vero_redirect();
        }
        vero_insert('fiscal_documentos', [
            'tipo'         => 'outro',
            'numero'       => 'MDFe ' . $numero,
            'chave'        => $chave ?: null,
            'valor_total'  => $valorMdfe,
            'data_emissao' => vero_date('data_emissao') ?? date('Y-m-d'),
            'status'       => 'importado',
        ]);
        vero_flash('ok', "MDF-e {$numero} registrado.");
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT * FROM fiscal_documentos
      WHERE tenant_id = :t AND tipo = 'outro' AND numero LIKE 'MDFe %'
      ORDER BY id DESC LIMIT 30", [':t' => $t]);

/* fretes recentes para referência do manifesto */
$fretes = vero_rows(
    "SELECT l.*, v.numero AS venda FROM comercial_logistica l
       JOIN comercial_vendas v ON v.id = l.venda_id
      WHERE l.tenant_id = :t AND l.status IN ('previsto','em_transito')
      ORDER BY l.id DESC LIMIT 10", [':t' => $t]);

$GUARD      = ['macro' => 'fiscal', 'micro' => 'emissao_mdfe'];
$PAGE_VIEW  = 'fiscal_emissao_mdfe';
$PAGE_TITLE = 'Emissão de MDF-e';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('fiscal.emissao_mdfe.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Emissão de MDF-e', 'Registro dos manifestos de carga emitidos externamente — emissão integrada exige certificado', null) ?>

  <div class="vcard" style="margin-bottom:14px;border-left:4px solid #8A6D1A">
    <div style="padding:12px 14px" class="vhint">
      MDF-e é obrigatório no transporte interestadual de carga própria. A emissão integrada está fora do
      go-live (D7) — emita no portal/emissor externo e registre aqui para manter a trilha junto aos fretes.
    </div>
  </div>

  <?php if ($podeEditar): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Registrar MDF-e emitido</strong></div>
    <form class="vform" method="post" style="padding:0 14px 14px">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="registrar">
      <div class="vgrid">
        <?= vero_f_text('numero', 'Número', '', true) ?>
        <div class="full"><?= vero_f_text('chave', 'Chave (44 dígitos, opcional)', '') ?></div>
        <?= vero_f_text('valor_total', 'Valor da carga (R$, opcional)', '') ?>
        <div class="vfield">
          <label>Data de emissão</label>
          <input type="date" name="data_emissao" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-primary" type="submit">Registrar MDF-e</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>MDF-e registrados</strong>
        <span class="vsub"><?= count($rows) ?> manifesto(s)</span></div>
      <?php if (!$rows): ?>
        <div class="vempty">Nenhum MDF-e registrado.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Número</th><th>Emissão</th><th style="text-align:right">Carga (R$)</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><strong class="vnum"><?= h($r['numero']) ?></strong></td>
            <td class="vnum"><?= $r['data_emissao'] ? date('d/m/Y', strtotime((string)$r['data_emissao'])) : '—' ?></td>
            <td class="vnum" style="text-align:right"><?= (float)$r['valor_total'] > 0 ? numFmt((float)$r['valor_total'], 2) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong>Fretes aguardando manifesto</strong>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/comercial/logistica_frete.php">Logística</a></div>
      <?php if (!$fretes): ?>
        <div class="vempty">Nenhum frete previsto/em trânsito.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Venda</th><th>Transportadora</th><th>Placa</th><th>Situação</th></tr></thead>
        <tbody>
        <?php foreach ($fretes as $fRow): ?>
          <tr>
            <td><strong class="vnum"><?= h($fRow['venda']) ?></strong></td>
            <td><?= h($fRow['transportadora'] ?? '—') ?></td>
            <td class="vnum"><?= h($fRow['placa'] ?? '—') ?></td>
            <td><?= $fRow['status'] === 'em_transito'
                  ? '<span class="vbadge vb-warn">Em trânsito</span>' : '<span class="vbadge vb-info">Previsto</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
