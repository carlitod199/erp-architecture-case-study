<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Propriedades (fazendas da carteira)
   Rota: /crm/consultor/propriedades
   Fonte: docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.propriedades)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* Dados locais fiéis ao mockup — Vale do São Francisco (uva e manga).
   TODO mover para _mock.php */
$PROPRIEDADES = [
    ['id' => 'F01', 'nome' => 'Fazenda Boa Vista',     'prod' => 'João Almeida',       'mun' => 'Petrolina · PE',              'loc' => '-9,3891 / -40,5030', 'cultura' => 'Uva',         'vars' => 'BRS Vitória · Arra 15 · Sweet Globe',  'haProd' => 54, 'irrig' => 'Gotejamento',   'destino' => 'Exportação UE / EUA',             'cert' => ['GLOBALG.A.P.', 'PIF'],       'ultVisita' => '19/08/2026'],
    ['id' => 'F07', 'nome' => 'Fazenda Boa Vista II',  'prod' => 'João Almeida',       'mun' => 'Lagoa Grande · PE',           'loc' => '-8,9941 / -40,2760', 'cultura' => 'Manga',       'vars' => 'Palmer · Kent',                         'haProd' => 30, 'irrig' => 'Microaspersão', 'destino' => 'Exportação UE',                   'cert' => ['GLOBALG.A.P.'],              'ultVisita' => '02/08/2026'],
    ['id' => 'F02', 'nome' => 'Fazenda Santa Helena',  'prod' => 'Carlos Mendes',      'mun' => 'Lagoa Grande · PE',           'loc' => '-8,9905 / -40,2712', 'cultura' => 'Uva',         'vars' => 'Timpson · Crimson Seedless · Itália',   'haProd' => 58, 'irrig' => 'Gotejamento',   'destino' => 'Exportação UE / Mercado interno', 'cert' => ['GLOBALG.A.P.'],              'ultVisita' => '24/07/2026'],
    ['id' => 'F03', 'nome' => 'Fazenda Vale Verde',    'prod' => 'Maria Oliveira',     'mun' => 'Juazeiro · BA',               'loc' => '-9,4112 / -40,4988', 'cultura' => 'Manga',       'vars' => 'Palmer · Keitt · Tommy Atkins',         'haProd' => 38, 'irrig' => 'Microaspersão', 'destino' => 'Exportação UE',                   'cert' => ['PIF', 'Rainforest'],         'ultVisita' => '14/08/2026'],
    ['id' => 'F04', 'nome' => 'Fazenda São José',      'prod' => 'Antônio Ribeiro',    'mun' => 'Casa Nova · BA',              'loc' => '-9,1620 / -40,9704', 'cultura' => 'Uva / Manga', 'vars' => 'Itália · Benitaka · Rosa',              'haProd' => 24, 'irrig' => 'Microaspersão', 'destino' => 'Mercado interno',                 'cert' => [],                            'ultVisita' => '09/07/2026'],
    ['id' => 'F05', 'nome' => 'Fazenda Nova Aliança',  'prod' => 'Fernanda Sá',        'mun' => 'Santa Maria da Boa Vista · PE', 'loc' => '-8,8061 / -39,8266', 'cultura' => 'Uva',       'vars' => 'Arra 15 · Sugar Crisp · BRS Isis',      'haProd' => 68, 'irrig' => 'Gotejamento',   'destino' => 'Exportação UE / RU',              'cert' => ['GLOBALG.A.P.'],              'ultVisita' => '22/08/2026'],
    ['id' => 'F06', 'nome' => 'Fazenda Riacho Grande', 'prod' => 'Roberto Nakamura',   'mun' => 'Curaçá · BA',                 'loc' => '-9,0281 / -39,9098', 'cultura' => 'Manga',       'vars' => 'Kent · Palmer',                         'haProd' => 33, 'irrig' => 'Gotejamento',   'destino' => 'Exportação UE / Mercado interno', 'cert' => ['PIF'],                       'ultVisita' => '06/08/2026'],
    ['id' => 'F08', 'nome' => 'Fazenda Bom Jesus',     'prod' => 'Helena Vasconcelos', 'mun' => 'Petrolina · PE',              'loc' => '-9,3455 / -40,5610', 'cultura' => 'Uva',         'vars' => 'Itália · BRS Vitória',                  'haProd' => 12, 'irrig' => 'Gotejamento',   'destino' => 'Mercado interno',                 'cert' => [],                            'ultVisita' => '17/08/2026'],
    ['id' => 'F09', 'nome' => 'Fazenda Serra Branca',  'prod' => 'José Bezerra',       'mun' => 'Orocó · PE',                  'loc' => '-8,6103 / -39,6011', 'cultura' => 'Manga',       'vars' => 'Tommy Atkins · Espada',                 'haProd' => 20, 'irrig' => 'Microaspersão', 'destino' => 'Mercado interno',                 'cert' => [],                            'ultVisita' => '25/06/2026'],
];

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'propriedades',
    'titulo' => 'Propriedades',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-nova-prop\')">＋ Nova propriedade</button>',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Propriedades', '9', 'em 6 municípios', 'teal') ?>
  <?= crm_kpi('Área produtiva', '337 ha', '374 ha totais', 'teal') ?>
  <?= crm_kpi('Com certificação', '6', 'GLOBALG.A.P. · PIF · Rainforest', 'green') ?>
  <?= crm_kpi('Destino exportação', '6', 'UE · EUA · RU', 'blue') ?>
</div>

<!-- Filtros (estado visual — protótipo) -->
<div class="crm-chips">
  <span class="crm-chip on">Todas</span>
  <span class="crm-chip">Petrolina · PE</span>
  <span class="crm-chip">Juazeiro · BA</span>
  <span class="crm-chip">Lagoa Grande · PE</span>
  <span class="crm-chip">Uva</span>
  <span class="crm-chip">Manga</span>
  <span class="crm-chip">Exportação</span>
  <span class="crm-chip">Mercado interno</span>
</div>

<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title">Propriedades · 9 registros</span>
    <a class="vbtn vbtn-sm" href="<?= crm_url('consultor', 'rota') ?>">Ver no mapa</a>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Propriedade</th>
          <th>Produtor</th>
          <th>Cultura / variedades</th>
          <th class="num">Área prod.</th>
          <th>Irrigação</th>
          <th>Destino</th>
          <th>Certificações</th>
          <th>Última visita</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($PROPRIEDADES as $f): ?>
        <tr class="tap" data-href="<?= crm_url('consultor', 'propriedade') ?>?id=<?= h($f['id']) ?>">
          <td><strong><?= h($f['nome']) ?></strong><div class="sub"><?= h($f['mun']) ?> · <?= h($f['loc']) ?></div></td>
          <td><?= h($f['prod']) ?></td>
          <td>
            <?php if ($f['cultura'] === 'Uva / Manga'): ?>
              <?= crm_pill('Uva', 'teal') ?> <?= crm_pill('Manga', 'amber') ?>
            <?php else: ?>
              <?= crm_pill($f['cultura'], $f['cultura'] === 'Manga' ? 'amber' : 'teal') ?>
            <?php endif; ?>
            <div class="sub"><?= h($f['vars']) ?></div>
          </td>
          <td class="num"><?= crm_num((float)$f['haProd']) ?> ha</td>
          <td><?= h($f['irrig']) ?></td>
          <td><?= h($f['destino']) ?></td>
          <td>
            <?php if ($f['cert']): ?>
              <?php foreach ($f['cert'] as $c): ?><?= crm_pill($c, 'green') ?> <?php endforeach; ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td><?= h($f['ultVisita']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal "Nova propriedade" — demo -->
<div class="vmodal" id="vm-nova-prop">
  <div class="vbox">
    <header>
      <h2>Nova propriedade</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-nova-prop')">×</button>
    </header>
    <form class="vform" onsubmit="return false">
      <?= vero_f_text('nome', 'Nome da propriedade', 'Fazenda Boa Vista', true) ?>
      <div class="vgrid">
        <?= vero_f_select('produtor', 'Produtor', [
              'P01' => 'João Almeida',
              'P02' => 'Carlos Mendes',
              'P03' => 'Maria Oliveira',
              'P04' => 'Antônio Ribeiro',
              'P05' => 'Fernanda Sá',
              'P06' => 'Roberto Nakamura',
              'P07' => 'Helena Vasconcelos',
              'P08' => 'José Bezerra',
            ], 'P01', true) ?>
        <?= vero_f_text('municipio', 'Município', 'Petrolina · PE', true) ?>
        <?= vero_f_select('cultura', 'Cultura', [
              'uva'   => 'Uva',
              'manga' => 'Manga',
              'ambas' => 'Uva e manga',
            ], 'uva', true) ?>
        <?= vero_f_text('area', 'Área produtiva (ha)', '54') ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-nova-prop')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Propriedade criada · demonstrativo">Salvar</button>
      </div>
    </form>
  </div>
</div>

<?php crm_shell_end();
