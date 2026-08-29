<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Produtores (carteira do consultor)
   Rota: /crm/consultor/produtores
   Fonte: docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.produtores)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* Dados locais fiéis ao mockup — Vale do São Francisco (uva e manga).
   TODO mover para _mock.php */
$PRODUTORES = [
    'P01' => ['nome' => 'João Almeida',      'grupo' => 'Grupo Almeida Agrícola',      'mun' => 'Petrolina · PE',              'culturas' => ['Uva', 'Manga'], 'ha' => 96, 'pot' => 'R$ 1,4 mi/ano',  'ult' => 'há 6 dias',  'ultDias' => 6,  'ultDesc' => 'Visita técnica — talhão U-03',   'status' => 'Ativo',    'classe' => 'A', 'cor' => 'teal'],
    'P02' => ['nome' => 'Carlos Mendes',     'grupo' => 'Fazenda Santa Helena Ltda.',  'mun' => 'Lagoa Grande · PE',           'culturas' => ['Uva'],          'ha' => 64, 'pot' => 'R$ 980 mil/ano', 'ult' => 'há 32 dias', 'ultDias' => 32, 'ultDesc' => 'Ligação — cotação de fungicida', 'status' => 'Ativo',    'classe' => 'A', 'cor' => 'blue'],
    'P03' => ['nome' => 'Maria Oliveira',    'grupo' => 'Agrícola Vale Verde',         'mun' => 'Juazeiro · BA',               'culturas' => ['Manga'],        'ha' => 41, 'pot' => 'R$ 610 mil/ano', 'ult' => 'há 11 dias', 'ultDias' => 11, 'ultDesc' => 'Visita técnica — indução floral', 'status' => 'Ativo',    'classe' => 'B', 'cor' => 'green'],
    'P04' => ['nome' => 'Antônio Ribeiro',   'grupo' => 'Fazenda São José',            'mun' => 'Casa Nova · BA',              'culturas' => ['Uva', 'Manga'], 'ha' => 28, 'pot' => 'R$ 430 mil/ano', 'ult' => 'há 47 dias', 'ultDias' => 47, 'ultDesc' => 'WhatsApp — sem retorno',          'status' => 'Em risco', 'classe' => 'B', 'cor' => 'red'],
    'P05' => ['nome' => 'Fernanda Sá',       'grupo' => 'Frutas Nova Aliança',         'mun' => 'Santa Maria da Boa Vista · PE', 'culturas' => ['Uva'],        'ha' => 74, 'pot' => 'R$ 1,1 mi/ano',  'ult' => 'há 3 dias',  'ultDias' => 3,  'ultDesc' => 'Primeira visita — diagnóstico',   'status' => 'Prospect', 'classe' => 'A', 'cor' => 'violet'],
    'P06' => ['nome' => 'Roberto Nakamura',  'grupo' => 'Agropecuária Riacho Grande',  'mun' => 'Curaçá · BA',                 'culturas' => ['Manga'],        'ha' => 35, 'pot' => 'R$ 520 mil/ano', 'ult' => 'há 19 dias', 'ultDias' => 19, 'ultDesc' => 'Visita técnica — pós-colheita',   'status' => 'Ativo',    'classe' => 'B', 'cor' => 'teal'],
    'P07' => ['nome' => 'Helena Vasconcelos', 'grupo' => 'Fazenda Bom Jesus',          'mun' => 'Petrolina · PE',              'culturas' => ['Uva'],          'ha' => 14, 'pot' => 'R$ 190 mil/ano', 'ult' => 'há 8 dias',  'ultDias' => 8,  'ultDesc' => 'Pedido entregue',                 'status' => 'Ativo',    'classe' => 'C', 'cor' => 'green'],
    'P08' => ['nome' => 'José Bezerra',      'grupo' => 'Fazenda Serra Branca',        'mun' => 'Orocó · PE',                  'culturas' => ['Manga'],        'ha' => 22, 'pot' => 'R$ 380 mil/ano', 'ult' => 'há 61 dias', 'ultDias' => 61, 'ultDesc' => 'Visita técnica',                  'status' => 'Em risco', 'classe' => 'B', 'cor' => 'amber'],
];

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'produtores',
    'titulo' => 'Produtores',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-novo-produtor\')">＋ Novo produtor</button>',
]);
?>

<div class="crm-g4">
  <?= crm_kpi('Produtores na carteira', '8', '6 ativos · 1 prospect · 2 em risco', 'teal') ?>
  <?= crm_kpi('Potencial anual', 'R$ 5,6 mi', crm_trend(12) . ' vs. 2025', 'green') ?>
  <?= crm_kpi('Positivação 2026', '75%', '6 de 8 compraram no período', 'green') ?>
  <?= crm_kpi('Fora da frequência-alvo', '2', 'Antônio Ribeiro · José Bezerra', 'red') ?>
</div>

<!-- Filtros da carteira (estado visual — protótipo) -->
<div class="crm-chips">
  <span class="crm-chip on">Todos</span>
  <span class="crm-chip">Classe A</span>
  <span class="crm-chip">Classe B</span>
  <span class="crm-chip">Classe C</span>
  <span class="crm-chip">Ativos</span>
  <span class="crm-chip">Prospects</span>
  <span class="crm-chip">Em risco</span>
  <span class="crm-chip">Uva</span>
  <span class="crm-chip">Manga</span>
</div>

<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title">Carteira · 8 registros</span>
    <?= crm_pill('337 ha produtivos', 'grey') ?>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Produtor</th>
          <th>Município</th>
          <th>Culturas</th>
          <th class="num">Área (ha)</th>
          <th class="num">Potencial/ano</th>
          <th>Último contato</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($PRODUTORES as $id => $p): $late = $p['ultDias'] > 30; ?>
        <tr class="tap" data-href="<?= crm_url('consultor', 'produtor') ?>?id=<?= h($id) ?>">
          <td>
            <?= crm_avatar($p['nome'], $p['cor']) ?>
            <span style="display:inline-block;vertical-align:middle">
              <strong><?= h($p['nome']) ?></strong> <?= crm_pill('Classe ' . $p['classe'], 'grey') ?>
              <div class="sub"><?= h($p['grupo']) ?></div>
            </span>
          </td>
          <td><?= h($p['mun']) ?></td>
          <td>
            <?php foreach ($p['culturas'] as $cu): ?>
              <?= crm_pill($cu, $cu === 'Manga' ? 'amber' : 'teal') ?>
            <?php endforeach; ?>
          </td>
          <td class="num"><?= crm_num((float)$p['ha']) ?></td>
          <td class="num"><strong><?= h($p['pot']) ?></strong></td>
          <td>
            <?= crm_pill($p['ult'], $late ? 'red' : 'green') ?>
            <div class="sub"><?= h($p['ultDesc']) ?></div>
          </td>
          <td><?= $p['status'] === 'Em risco' ? crm_pill('Em risco', 'red') : crm_status_pill($p['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal "Novo produtor" — demo -->
<div class="vmodal" id="vm-novo-produtor">
  <div class="vbox">
    <header>
      <h2>Novo produtor</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-novo-produtor')">×</button>
    </header>
    <form class="vform" onsubmit="return false">
      <?= vero_f_text('documento', 'Documento (CNPJ/CPF)', '11.234.567/0001-09', true) ?>
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome', 'João Almeida', true) ?>
        <?= vero_f_text('grupo', 'Grupo / razão social', 'Grupo Almeida Agrícola') ?>
        <?= vero_f_select('classe', 'Classe', [
              'A' => 'Classe A · visita a cada 15 dias',
              'B' => 'Classe B · visita a cada 30 dias',
              'C' => 'Classe C · visita a cada 45 dias',
            ], 'B', true) ?>
        <?= vero_f_select('cultura', 'Cultura principal', [
              'uva'   => 'Uva',
              'manga' => 'Manga',
              'ambas' => 'Uva e manga',
            ], 'uva', true) ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-novo-produtor')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Produtor criado na carteira · demonstrativo">Salvar</button>
      </div>
    </form>
  </div>
</div>

<?php crm_shell_end();
