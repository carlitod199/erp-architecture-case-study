<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Rentabilidade por Cliente (DRE)
   Rota: /crm/consultor/dre
   Ranking de resultado por cliente do ciclo 2026 + onde a
   margem se perde; linha clica para o DRE do cliente.
   Dados fiéis ao mockup docs/VERO_CRM_Consultor_Frutas_Mockup.html.
   TODO mover os dados para _mock.php.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* produtor => [nome, grupo, classe] */
$PRODUTORES = [
    'P01' => ['João Almeida',       'Grupo Almeida Agrícola',       'A'],
    'P02' => ['Carlos Mendes',      'Fazenda Santa Helena Ltda.',   'A'],
    'P03' => ['Maria Oliveira',     'Agrícola Vale Verde',          'B'],
    'P04' => ['Antônio Ribeiro',    'Fazenda São José',             'B'],
    'P06' => ['Roberto Nakamura',   'Agropecuária Riacho Grande',   'B'],
    'P07' => ['Helena Vasconcelos', 'Fazenda Bom Jesus',            'C'],
    'P08' => ['José Bezerra',       'Fazenda Serra Branca',         'B'],
];

/* DRE por cliente · ciclo 2026 (rl=receita líq., mb=margem bruta,
   mc=margem de contribuição, fix=rateio fixo, res=resultado) */
$DRE = [
    ['id' => 'P01', 'rl' => 676704, 'mb' => 146168, 'mbp' => 21.6, 'mc' => 90574,  'mcp' => 13.4,  'fix' => 51666, 'res' => 38908,  'resp' => 5.7,   'roi' => 15.0, 'prazo' => 42, 'vis' => 14, 'cvis' => 430, 'ate' => 6020, 'rpv' => 48336],
    ['id' => 'P02', 'rl' => 449694, 'mb' => 84542,  'mbp' => 18.8, 'mc' => 25229,  'mcp' => 5.6,   'fix' => 34334, 'res' => -9105,  'resp' => -2.0,  'roi' => 6.0,  'prazo' => 68, 'vis' => 9,  'cvis' => 470, 'ate' => 4230, 'rpv' => 49966],
    ['id' => 'P03', 'rl' => 285168, 'mb' => 69011,  'mbp' => 24.2, 'mc' => 44617,  'mcp' => 15.6,  'fix' => 21772, 'res' => 22845,  'resp' => 8.0,   'roi' => 7.8,  'prazo' => 35, 'vis' => 11, 'cvis' => 520, 'ate' => 5720, 'rpv' => 25924],
    ['id' => 'P04', 'rl' => 86016,  'mb' => 14107,  'mbp' => 16.4, 'mc' => -18355, 'mcp' => -21.3, 'fix' => 6567,  'res' => -24922, 'resp' => -29.0, 'roi' => -7.5, 'prazo' => 84, 'vis' => 4,  'cvis' => 610, 'ate' => 2440, 'rpv' => 21504],
    ['id' => 'P06', 'rl' => 244684, 'mb' => 55788,  'mbp' => 22.8, 'mc' => 34417,  'mcp' => 14.1,  'fix' => 18682, 'res' => 15735,  'resp' => 6.4,   'roi' => 8.8,  'prazo' => 38, 'vis' => 7,  'cvis' => 560, 'ate' => 3920, 'rpv' => 34955],
    ['id' => 'P07', 'rl' => 107970, 'mb' => 29584,  'mbp' => 27.4, 'mc' => 20503,  'mcp' => 19.0,  'fix' => 8243,  'res' => 12260,  'resp' => 11.4,  'roi' => 10.8, 'prazo' => 28, 'vis' => 5,  'cvis' => 380, 'ate' => 1900, 'rpv' => 21594],
    ['id' => 'P08', 'rl' => 48924,  'mb' => 8415,   'mbp' => 17.2, 'mc' => 908,    'mcp' => 1.9,   'fix' => 3735,  'res' => -2827,  'resp' => -5.8,  'roi' => 0.5,  'prazo' => 72, 'vis' => 3,  'cvis' => 640, 'ate' => 1920, 'rpv' => 16308],
];

/* Carteira consolidada */
$TOT = ['rl' => 1899160, 'mb' => 407615, 'mc' => 197893, 'fix' => 144999, 'res' => 52894,
        'cmv' => 1491545, 'imp' => 171216, 'bon' => 49982, 'fin' => 43570, 'fre' => 43132,
        'com' => 28488, 'ate' => 26150, 'ina' => 18400, 'dev' => 17624];

/* Onde a margem se perde (linha, valor, cor) */
$SANGRIAS = [
    ['CMV — custo da mercadoria vendida', $TOT['cmv'], 'red'],
    ['Impostos sobre venda',              $TOT['imp'], 'amber'],
    ['Bonificação e desconto',            $TOT['bon'], 'amber'],
    ['Custo financeiro do prazo',         $TOT['fin'], 'amber'],
    ['Frete e entrega',                   $TOT['fre'], 'teal'],
    ['Comissão',                          $TOT['com'], 'teal'],
    ['Custo de atendimento',              $TOT['ate'], 'teal'],
    ['Inadimplência',                     $TOT['ina'], 'red'],
    ['Devoluções',                        $TOT['dev'], 'teal'],
];

/* Alavancas simuladas da carteira */
$ALAVANCAS = [
    ['Teto de desconto em 2,5% para classe A e B',       'Hoje Carlos Mendes recebe 4,6% e Antônio Ribeiro 3,8%.',            21400],
    ['Prazo médio de 51 para 42 dias',                   'Três clientes acima de 65 dias puxam a média da carteira.',          7700],
    ['Bloquear venda a prazo com título vencido',        'Evita a repetição do caso Antônio Ribeiro no próximo ciclo.',       18400],
    ['Migrar 2 clientes classe C para atendimento remoto', 'Mantém a frequência com metade do custo por contato.',             3100],
];

$rank = $DRE;
usort($rank, fn($a, $b) => $b['res'] <=> $a['res']);

$rankResp = $DRE;
usort($rankResp, fn($a, $b) => $b['resp'] <=> $a['resp']);

$rankRoi = $DRE;
usort($rankRoi, fn($a, $b) => $b['roi'] <=> $a['roi']);

$pc = fn(float $v, int $d = 1): string => crm_num($v, $d) . '%';

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'dre',
    'titulo' => 'Rentabilidade por Cliente',
    'demo'   => 'Custos e impostos do ERP',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" data-toast="DRE da carteira exportado em XLSX">Exportar XLSX</button>',
]);
?>

<style>
/* Linha consolidada da carteira e barras de rótulo largo (só desta tela) */
.crm-app .crm-tbl tr.dre-total td { background: var(--crm-bg2); border-top: 2px solid var(--crm-line2); font-weight: 700; }
.crm-app .crm-hbars--w .crm-hbar { grid-template-columns: 200px 1fr 78px; }
</style>

<div class="crm-g4">
  <?= crm_kpi('Receita líquida da carteira', crm_brl((float)$TOT['rl']), '7 clientes com faturamento no ciclo', 'teal') ?>
  <?= crm_kpi('Margem de contribuição', crm_brl((float)$TOT['mc']), '10,4% da receita líquida', 'blue') ?>
  <?= crm_kpi('Resultado da carteira', crm_brl((float)$TOT['res']), '2,8% depois do rateio', 'green') ?>
  <?= crm_kpi('Clientes no vermelho', '3', crm_brl(36854) . ' de resultado negativo', 'red') ?>
</div>

<?php /* callout dos clientes no vermelho removido a pedido do gestor 25/08 —
         o detalhe vive no ranking abaixo e no DRE por cliente */ ?>
<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title">Resultado por cliente · ordenado por resultado</span>
    <span class="crm-tabs" style="margin:0">
      <span class="crm-tab on">Ciclo 2026</span>
      <span class="crm-tab" data-toast="Ciclo 2025 · demonstrativo">Ciclo 2025</span>
      <span class="crm-tab" data-toast="Comparativo · demonstrativo">Comparativo</span>
    </span>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Cliente</th>
          <th class="num">Receita líquida</th>
          <th class="num">Margem bruta</th>
          <th class="num">Marg. contrib.</th>
          <th class="num">Rateio fixo</th>
          <th class="num">Resultado</th>
          <th style="min-width:130px">Resultado %</th>
          <th class="num">ROI atend.</th>
          <th class="num">Prazo</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rank as $d):
            [$nome, $grupo, $classe] = $PRODUTORES[$d['id']];
            $neg = $d['res'] < 0;
            $barCor = $neg ? 'red' : ($d['resp'] > 6 ? 'green' : 'amber'); ?>
        <tr class="tap" data-href="<?= crm_url('consultor', 'dre-cliente') ?>?id=<?= h($d['id']) ?>">
          <td>
            <?= crm_avatar($nome, $neg ? 'red' : 'teal') ?>
            <strong><?= h($nome) ?></strong> <?= crm_pill('Classe ' . $classe, 'grey') ?>
            <div class="sub" style="margin-left:39px"><?= h($grupo) ?></div>
          </td>
          <td class="num"><?= crm_brl((float)$d['rl']) ?></td>
          <td class="num"><?= crm_brl((float)$d['mb']) ?><div class="sub"><?= $pc((float)$d['mbp']) ?></div></td>
          <td class="num" <?= $d['mc'] < 0 ? 'style="color:var(--crm-red)"' : '' ?>><?= crm_brl((float)$d['mc']) ?><div class="sub"><?= $pc((float)$d['mcp']) ?></div></td>
          <td class="num" style="color:var(--crm-ink3)">(<?= crm_brl((float)$d['fix']) ?>)</td>
          <td class="num"><strong style="color:<?= $neg ? 'var(--crm-red)' : 'var(--crm-green)' ?>"><?= $neg ? '(' . crm_brl((float)-$d['res']) . ')' : crm_brl((float)$d['res']) ?></strong></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <?= crm_bar(min(100.0, abs((float)$d['resp']) * 7), $barCor) ?>
              <span class="num" style="min-width:46px;<?= $neg ? 'color:var(--crm-red)' : '' ?>"><?= $pc((float)$d['resp']) ?></span>
            </div>
          </td>
          <td class="num" <?= $d['roi'] < 3 ? 'style="color:var(--crm-red)"' : '' ?>><?= crm_num((float)$d['roi'], 1) ?>×</td>
          <td class="num" <?= $d['prazo'] > 60 ? 'style="color:var(--crm-red)"' : ($d['prazo'] > 45 ? 'style="color:var(--crm-amber)"' : '') ?>><?= (int)$d['prazo'] ?> d</td>
        </tr>
        <?php endforeach; ?>
        <tr class="dre-total">
          <td>Carteira consolidada</td>
          <td class="num"><?= crm_brl((float)$TOT['rl']) ?></td>
          <td class="num"><?= crm_brl((float)$TOT['mb']) ?><div class="sub">21,5%</div></td>
          <td class="num"><?= crm_brl((float)$TOT['mc']) ?><div class="sub">10,4%</div></td>
          <td class="num">(<?= crm_brl((float)$TOT['fix']) ?>)</td>
          <td class="num" style="color:var(--crm-green)"><?= crm_brl((float)$TOT['res']) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <?= crm_bar(20.0, 'green') ?>
              <span class="num" style="min-width:46px">2,8%</span>
            </div>
          </td>
          <td class="num">7,6×</td>
          <td class="num">51 d</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<div class="crm-g2">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Onde a margem se perde · carteira consolidada</span>
      <?= crm_demo('Custos do ERP') ?>
    </div>
    <div class="crm-hbars crm-hbars--w">
      <?php $maxS = 1491545.0; foreach ($SANGRIAS as [$rot, $v, $cor]): ?>
      <div class="crm-hbar">
        <span><?= h($rot) ?></span>
        <?= crm_bar((float)$v / $maxS * 100, $cor) ?>
        <span class="num"><?= crm_brl((float)$v) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?= crm_callout(
        'Depois do CMV, as duas maiores sangrias são comerciais e não operacionais: <strong>desconto</strong> ('
        . crm_brl(49982) . ') e <strong>custo financeiro do prazo</strong> (' . crm_brl(43570)
        . '). Somadas, valem 1,8× o custo de atendimento de toda a carteira.',
        'amber'
    ) ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Rentabilidade × tamanho · não é o mesmo ranking</span>
    </div>
    <div class="crm-hbars crm-hbars--w">
      <?php foreach ($rankResp as $d):
          [$nome] = $PRODUTORES[$d['id']];
          $cor = $d['res'] < 0 ? 'red' : ($d['resp'] > 6 ? 'green' : 'amber'); ?>
      <div class="crm-hbar">
        <span><?= h($nome) ?></span>
        <?= crm_bar(abs((float)$d['resp']) / 29.0 * 100, $cor) ?>
        <span class="num"><?= $pc((float)$d['resp']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?= crm_callout(
        'Helena Vasconcelos é o <strong>menor</strong> cliente da carteira em receita e o <strong>mais rentável</strong> '
        . 'em percentual: 11,4%. Carlos Mendes é o segundo maior em receita e fecha negativo. '
        . 'Faturamento não é rentabilidade.',
        'teal'
    ) ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Custo de atendimento · margem de contribuição ÷ custo</span>
    </div>
    <div class="crm-tblwrap">
      <table class="crm-tbl">
        <thead>
          <tr>
            <th>Cliente</th>
            <th class="num">Visitas</th>
            <th class="num">Custo/visita</th>
            <th class="num">Custo total</th>
            <th class="num">Receita/visita</th>
            <th class="num">ROI</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rankRoi as $d): [$nome] = $PRODUTORES[$d['id']]; ?>
          <tr>
            <td><?= h($nome) ?></td>
            <td class="num"><?= (int)$d['vis'] ?></td>
            <td class="num"><?= crm_brl((float)$d['cvis']) ?></td>
            <td class="num"><?= crm_brl((float)$d['ate']) ?></td>
            <td class="num"><?= crm_brl((float)$d['rpv']) ?></td>
            <td class="num"><strong style="color:<?= $d['roi'] < 3 ? 'var(--crm-red)' : 'var(--crm-green)' ?>"><?= crm_num((float)$d['roi'], 1) ?>×</strong></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Alavancas da carteira · simulação</span>
      <?= crm_demo('Simulação') ?>
    </div>
    <?php foreach ($ALAVANCAS as [$t, $x, $ganho]): ?>
    <div class="crm-kv">
      <span><strong style="color:var(--crm-ink)"><?= h($t) ?></strong><br><?= h($x) ?></span>
      <strong><?= crm_pill('+' . crm_brl((float)$ganho), 'green') ?></strong>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php crm_shell_end();
