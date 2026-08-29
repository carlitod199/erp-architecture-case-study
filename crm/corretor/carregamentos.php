<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Corretor / Carregamentos do dia (protótipo demo)
   Rota: /crm/corretor/carregamentos · dados: crm/_mock.php
   Um card por caminhão: itens, composição e margem da carga
   (margem = venda − frete − comissão; R$/kg sobre o peso total).
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();

/* Destinos disponíveis p/ nova carga — TODO mover para _mock.php */
$destinos = ['CEAGESP · São Paulo', 'CEASA · Recife', 'CEASA · Belo Horizonte', 'Atacadista Silva'];

crm_shell_start([
    'modulo' => 'corretor',
    'micro'  => 'carregamentos',
    'titulo' => 'Carregamentos',
    'sub'    => 'Controle operacional · 13/08 · 3 caminhões · 7,2 t',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-nova-carga\')">＋ Novo carregamento</button>',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Total carregado', '7.200 kg', '3 caminhões', 'teal') ?>
  <?= crm_kpi('Primeira/Extra', '5.531 kg', '77% do volume', 'green') ?>
  <?= crm_kpi('Segunda/Terceira', '1.669 kg', '23%', 'amber') ?>
  <?= crm_kpi('Caixas', '2.470', 'expedidas hoje', 'blue') ?>
</div>

<?php foreach ($M['cargas'] as $c):
    $pesoTot = array_sum(array_column($c['itens'], 'peso'));
    $cxTot   = array_sum(array_column($c['itens'], 'cx'));
    $margem  = $c['venda'] - $c['frete'] - $c['com'];
    $mkg     = $pesoTot > 0 ? $margem / $pesoTot : 0.0;
?>
  <div class="crm-card" style="margin-bottom:14px">
    <div class="crm-card__head" style="align-items:flex-start">
      <div>
        <div style="font-size:14px;font-weight:700">Caminhão #<?= h($c['id']) ?> <?= crm_pill($c['status'], $c['cor']) ?></div>
        <div class="crm-sub"><?= h($c['mot']) ?> · <?= h($c['cam']) ?> · <?= h($c['dest']) ?></div>
      </div>
      <div style="text-align:right">
        <div style="font:700 13px var(--num,'IBM Plex Mono')"><?= crm_num((float)$pesoTot) ?> kg · <?= crm_num((float)$cxTot) ?> caixas</div>
        <div class="crm-sub">Margem <strong style="color:var(--crm-green)"><?= crm_brl($mkg, 2) ?>/kg</strong></div>
      </div>
    </div>

    <div class="crm-tblwrap">
      <table class="crm-tbl">
        <thead>
          <tr>
            <th>Variedade</th><th>Classificação</th><th>Calibre</th>
            <th class="num">Caixas</th><th class="num">Peso (kg)</th><th style="width:26%">Composição</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($c['itens'] as $i):
              $premium = in_array($i['cl'], ['Extra', 'Exportação'], true);
              $pct     = $pesoTot > 0 ? $i['peso'] / $pesoTot * 100 : 0.0;
          ?>
            <tr>
              <td><strong><?= h($i['v']) ?></strong></td>
              <td><?= crm_pill($i['cl'], $premium ? 'teal' : 'grey') ?></td>
              <td style="font:12px var(--num,'IBM Plex Mono')"><?= h($i['cal']) ?></td>
              <td class="num"><?= crm_num((float)$i['cx']) ?></td>
              <td class="num"><?= crm_num((float)$i['peso']) ?></td>
              <td><span style="display:flex;align-items:center;gap:8px"><?= crm_bar($pct) ?>
                  <span class="sub" style="min-width:34px;text-align:right"><?= crm_num($pct) ?>%</span></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;margin-top:10px">
      <div style="flex:1;min-width:130px"><?= crm_kv('Venda', crm_brl((float)$c['venda'])) ?></div>
      <div style="flex:1;min-width:130px"><?= crm_kv('Frete', crm_brl((float)$c['frete'])) ?></div>
      <div style="flex:1;min-width:130px"><?= crm_kv('Comissão', crm_brl((float)$c['com'])) ?></div>
      <div style="flex:1;min-width:130px"><?= crm_kv('Margem', '<span style="color:var(--crm-green)">' . crm_brl((float)$margem) . '</span>') ?></div>
      <?php if ($c['status'] === 'Programado'): ?>
        <button type="button" class="vbtn vbtn-primary" data-toast="Carga #0813-09 despachada">Despachar</button>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<!-- Modal: novo carregamento (demo — sem persistência) -->
<div class="vmodal" id="vm-nova-carga">
  <div class="vbox">
    <header>
      <h2>Novo carregamento</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-nova-carga')">×</button>
    </header>
    <div class="vform">
      <div class="vfield">
        <label>Caminhão / placa</label>
        <input type="text" placeholder="PEX-4B21">
      </div>
      <div class="vfield">
        <label>Motorista</label>
        <input type="text" placeholder="José Aparecido">
      </div>
      <div class="vfield">
        <label>Destino</label>
        <select>
          <?php foreach ($destinos as $d): ?>
            <option><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vfield">
        <label>Itens</label>
        <div class="crm-chips" style="margin:6px 0 0">
          <span class="crm-chip on">Palmer · 1ª · 420 cx</span>
          <span class="crm-chip">＋ Adicionar item</span>
        </div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-nova-carga')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Carregamento criado">Criar</button>
      </div>
    </div>
  </div>
</div>

<?php crm_shell_end();
