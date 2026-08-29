<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Revenda / Clientes (carteira do vendedor)
   Rota: /crm/revenda/clientes · dados: crm/_mock.php
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M   = crm_mock();
$cli = $M['clientes'];

$fatTotal = array_sum(array_column($cli, 'fat12'));   /* R$ 4.920.000 */

crm_shell_start([
    'modulo' => 'revenda',
    'micro'  => 'clientes',
    'titulo' => 'Clientes',
    'sub'    => 'Sua carteira · ' . count($cli) . ' clientes · ' . crm_brl((float)$fatTotal) . ' em 12 meses',
    'papel'  => 'vendedor',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-novo-cliente\')">＋ Novo cliente</button>',
]);
?>

<!-- Filtros da carteira (estado visual — protótipo) -->
<div class="crm-chips">
  <span class="crm-chip on">Todos</span>
  <span class="crm-chip">Ativos</span>
  <span class="crm-chip">Em risco</span>
  <span class="crm-chip">Prospects</span>
  <span class="crm-chip">Uva</span>
  <span class="crm-chip">Manga</span>
  <span class="crm-chip">Alto valor (A)</span>
</div>

<div class="crm-card">
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Cliente</th>
          <th>Cultura</th>
          <th class="num">Área (ha)</th>
          <th>Segmento</th>
          <th class="num">Fat. 12m</th>
          <th>Consumo</th>
          <th>Risco</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cli as $c): $prospect = $c['status'] === 'Prospect'; ?>
        <tr class="tap" data-href="<?= crm_url('revenda', 'cliente') ?>?id=<?= h($c['id']) ?>">
          <td>
            <?= crm_avatar($c['nome'], $c['cor']) ?>
            <span style="display:inline-block;vertical-align:middle">
              <strong><?= h($c['nome']) ?></strong>
              <div class="sub"><?= h($c['cidade']) ?></div>
            </span>
          </td>
          <td><?= h($c['cultura']) ?></td>
          <td class="num"><?= crm_num((float)$c['area']) ?></td>
          <td><?= crm_pill($c['seg'], 'grey') ?></td>
          <td class="num"><?= $c['fat12'] > 0 ? crm_brl((float)$c['fat12']) : '—' ?></td>
          <td><?= $prospect ? '—' : crm_trend((float)$c['var_consumo']) ?></td>
          <td><?= crm_risco_pill($c['risco']) ?></td>
          <td><?= crm_status_pill($c['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal "Novo cliente" — demo: o documento puxa o cadastro do ERP -->
<div class="vmodal" id="vm-novo-cliente">
  <div class="vbox">
    <header>
      <h2>Novo cliente</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-novo-cliente')">×</button>
    </header>
    <form class="vform" onsubmit="return false">
      <?= vero_f_text('documento', 'Documento (CNPJ/CPF)', '12.345.678/0001-90', true) ?>
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome', 'Sítio Riacho Fundo', true) ?>
        <?= vero_f_select('tipo', 'Tipo', [
              'produtor_pj' => 'Produtor · PJ',
              'revenda'     => 'Revenda',
              'cooperativa' => 'Cooperativa',
            ], 'produtor_pj', true) ?>
        <?= vero_f_select('cultura', 'Cultura principal', [
              'manga' => 'Manga',
              'uva'   => 'Uva',
            ], 'manga', true) ?>
        <?= vero_f_select('vendedor', 'Vendedor', [
              'carlos' => 'Carlos Andrade',
            ], 'carlos', true) ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-novo-cliente')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Cliente criado e sincronizado ao ERP">Salvar</button>
      </div>
    </form>
  </div>
</div>

<?php crm_shell_end();
