<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Calculadora de ROI Agrícola (protótipo)
   Rota: /crm/revenda/roi · cálculo client-side (crmRoiCalc em
   assets/js/crm.js — auto-liga pelos ids roiArea..roiProdSel e
   escreve em rInv..rFrase). Dados: crm/_mock.php.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'roi',
    'titulo' => 'Calculadora de ROI Agrícola',
    'sub'    => 'Demonstre o retorno da solução na frente do produtor · usável na visita',
    'papel'  => 'vendedor',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" data-toast="Simulação salva e enviada ao cliente">Salvar &amp; enviar</button>',
]);
?>

<div class="crm-g12">

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Dados de entrada</span>
    </div>

    <div class="vfield" style="margin-bottom:12px">
      <label>Cultura</label>
      <select>
        <option>Manga Palmer</option>
        <option>Uva Crimson</option>
      </select>
    </div>

    <div class="vfield" style="margin-bottom:12px">
      <label>Área · ha</label>
      <input type="number" id="roiArea" value="120" min="0">
    </div>

    <div class="vfield" style="margin-bottom:12px">
      <label>Produtividade atual · t/ha</label>
      <input type="number" id="roiProd" value="24" min="0">
    </div>

    <div class="vfield" style="margin-bottom:12px">
      <label>Preço de venda · R$/kg</label>
      <input type="number" id="roiPreco" value="2.10" step="0.01" min="0">
    </div>

    <div class="vfield" style="margin-bottom:12px">
      <label>Incremento esperado · %</label>
      <input type="number" id="roiIncr" value="3" min="0">
    </div>

    <div class="vfield" style="margin-bottom:12px">
      <label>Programa / produto</label>
      <select id="roiProdSel">
        <?php foreach ($M['roi_produtos'] as $p): ?>
          <option value="<?= h($p['preco'] . '|' . $p['dose']) ?>">
            <?= h($p['nome']) ?> · <?= crm_brl((float)$p['preco']) ?> · <?= crm_num((float)$p['dose'], 1) ?>/ha
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="vfield">
      <label>Custo de aplicação · R$/ha</label>
      <input type="number" id="roiApl" value="150" min="0">
    </div>
  </div>

  <div>
    <div class="crm-g3" style="margin-top:0">
      <?= crm_kpi('Investimento total', '<span id="rInv"></span>', 'Produto + aplicação na área', 'teal') ?>
      <?= crm_kpi('Receita adicional', '<span id="rRec"></span>', 'Incremento de produção vendido', 'green') ?>
      <?= crm_kpi('ROI', '<span id="rRoi"></span>', 'Retorno sobre o investimento', 'amber') ?>
    </div>

    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Memória de cálculo</span>
      </div>
      <?= crm_kv('Quantidade de produto', '<span id="rQtd"></span>') ?>
      <?= crm_kv('Custo por hectare', '<span id="rCustoHa"></span>') ?>
      <?= crm_kv('Investimento', '<span id="rInv2"></span>') ?>
      <?= crm_kv('Produção adicional', '<span id="rProdAd"></span>') ?>
      <?= crm_kv('Receita adicional', '<span id="rRec2"></span>') ?>
      <?= crm_kv('Retorno líquido', '<span id="rRet"></span>') ?>

      <div style="margin-top:14px;text-align:center">
        <div class="crm-card__title">ROI da simulação</div>
        <div style="font-size:34px;font-weight:700;color:var(--crm-teal)" id="rRoiBig"></div>
      </div>

    </div>
  </div>

</div>

<?php crm_shell_end();
