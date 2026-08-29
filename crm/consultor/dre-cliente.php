<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / DRE do Cliente (detalhe)
   Rota: /crm/consultor/dre-cliente?id=P01 (fallback: 1º)
   Demonstrativo de resultado em cascata por cliente do ciclo
   2026 + alavancas que mudam o resultado.
   Dados fiéis ao mockup docs/VERO_CRM_Consultor_Frutas_Mockup.html.
   TODO mover os dados para _mock.php.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* produtor => [nome, grupo, classe, status] */
$PRODUTORES = [
    'P01' => ['João Almeida',       'Grupo Almeida Agrícola',     'A', 'Ativo'],
    'P02' => ['Carlos Mendes',      'Fazenda Santa Helena Ltda.', 'A', 'Ativo'],
    'P03' => ['Maria Oliveira',     'Agrícola Vale Verde',        'B', 'Ativo'],
    'P04' => ['Antônio Ribeiro',    'Fazenda São José',           'B', 'Em risco'],
    'P06' => ['Roberto Nakamura',   'Agropecuária Riacho Grande', 'B', 'Ativo'],
    'P07' => ['Helena Vasconcelos', 'Fazenda Bom Jesus',          'C', 'Ativo'],
    'P08' => ['José Bezerra',       'Fazenda Serra Branca',       'B', 'Em risco'],
];

/* DRE completo por cliente · ciclo 2026 */
$DRE = [
    'P01' => ['rb' => 742000, 'dev' => 4452, 'imp' => 60844, 'rl' => 676704, 'cmv' => 530536, 'mb' => 146168, 'mbp' => 21.6, 'bon' => 13356, 'fre' => 12614, 'ate' => 6020, 'vis' => 14, 'cvis' => 430, 'com' => 10151, 'fin' => 13453, 'ina' => 0,     'mc' => 90574,  'mcp' => 13.4,  'fix' => 51666, 'res' => 38908,  'resp' => 5.7,   'roi' => 15.0, 'rpv' => 48336, 'prazo' => 42],
    'P02' => ['rb' => 498000, 'dev' => 7470, 'imp' => 40836, 'rl' => 449694, 'cmv' => 365152, 'mb' => 84542,  'mbp' => 18.8, 'bon' => 22908, 'fre' => 10956, 'ate' => 4230, 'vis' => 9,  'cvis' => 470, 'com' => 6745,  'fin' => 14474, 'ina' => 0,     'mc' => 25229,  'mcp' => 5.6,   'fix' => 34334, 'res' => -9105,  'resp' => -2.0,  'roi' => 6.0,  'rpv' => 49966, 'prazo' => 68],
    'P03' => ['rb' => 312000, 'dev' => 1248, 'imp' => 25584, 'rl' => 285168, 'cmv' => 216157, 'mb' => 69011,  'mbp' => 24.2, 'bon' => 3744,  'fre' => 5928,  'ate' => 5720, 'vis' => 11, 'cvis' => 520, 'com' => 4278,  'fin' => 4724,  'ina' => 0,     'mc' => 44617,  'mcp' => 15.6,  'fix' => 21772, 'res' => 22845,  'resp' => 8.0,   'roi' => 7.8,  'rpv' => 25924, 'prazo' => 35],
    'P04' => ['rb' => 96000,  'dev' => 2112, 'imp' => 7872,  'rl' => 86016,  'cmv' => 71909,  'mb' => 14107,  'mbp' => 16.4, 'bon' => 3648,  'fre' => 3264,  'ate' => 2440, 'vis' => 4,  'cvis' => 610, 'com' => 1290,  'fin' => 3420,  'ina' => 18400, 'mc' => -18355, 'mcp' => -21.3, 'fix' => 6567,  'res' => -24922, 'resp' => -29.0, 'roi' => -7.5, 'rpv' => 21504, 'prazo' => 84],
    'P06' => ['rb' => 268000, 'dev' => 1340, 'imp' => 21976, 'rl' => 244684, 'cmv' => 188896, 'mb' => 55788,  'mbp' => 22.8, 'bon' => 3752,  'fre' => 5628,  'ate' => 3920, 'vis' => 7,  'cvis' => 560, 'com' => 3670,  'fin' => 4401,  'ina' => 0,     'mc' => 34417,  'mcp' => 14.1,  'fix' => 18682, 'res' => 15735,  'resp' => 6.4,   'roi' => 8.8,  'rpv' => 34955, 'prazo' => 38],
    'P07' => ['rb' => 118000, 'dev' => 354,  'imp' => 9676,  'rl' => 107970, 'cmv' => 78386,  'mb' => 29584,  'mbp' => 27.4, 'bon' => 1062,  'fre' => 3068,  'ate' => 1900, 'vis' => 5,  'cvis' => 380, 'com' => 1620,  'fin' => 1431,  'ina' => 0,     'mc' => 20503,  'mcp' => 19.0,  'fix' => 8243,  'res' => 12260,  'resp' => 11.4,  'roi' => 10.8, 'rpv' => 21594, 'prazo' => 28],
    'P08' => ['rb' => 54000,  'dev' => 648,  'imp' => 4428,  'rl' => 48924,  'cmv' => 40509,  'mb' => 8415,   'mbp' => 17.2, 'bon' => 1512,  'fre' => 1674,  'ate' => 1920, 'vis' => 3,  'cvis' => 640, 'com' => 734,   'fin' => 1667,  'ina' => 0,     'mc' => 908,    'mcp' => 1.9,   'fix' => 3735,  'res' => -2827,  'resp' => -5.8,  'roi' => 0.5,  'rpv' => 16308, 'prazo' => 72],
];

/* Linhas do DRE em cascata: [rótulo, chave, tipo]
   tipo: 'h' cabeça · '-' dedução · '=' / '==' / '===' subtotais */
$LINHAS = [
    ['Receita bruta',                                       'rb',  'h'],
    ['(−) Devoluções e abatimentos',                        'dev', '-'],
    ['(−) Impostos sobre venda',                            'imp', '-'],
    ['(=) Receita líquida',                                 'rl',  '='],
    ['(−) CMV — custo da mercadoria vendida',               'cmv', '-'],
    ['(=) Margem bruta',                                    'mb',  '=='],
    ['(−) Bonificações e descontos comerciais',             'bon', '-'],
    ['(−) Frete e entrega',                                 'fre', '-'],
    ['(−) Custo de atendimento (visitas e deslocamento)',   'ate', '-'],
    ['(−) Comissão do consultor',                           'com', '-'],
    ['(−) Custo financeiro do prazo',                       'fin', '-'],
    ['(−) Perdas e provisão para inadimplência',            'ina', '-'],
    ['(=) Margem de contribuição',                          'mc',  '=='],
    ['(−) Rateio de despesas fixas',                        'fix', '-'],
    ['(=) Resultado do cliente',                            'res', '==='],
];

/* Médias da carteira consolidada */
$MEDIA = ['mbp' => 21.5, 'mcp' => 10.4, 'resp' => 2.8, 'prazo' => 51, 'roi' => 7.6];

$id = (string)($_GET['id'] ?? '');
if (!isset($DRE[$id])) $id = array_key_first($DRE);
$d = $DRE[$id];
[$nome, $grupo, $classe, $status] = $PRODUTORES[$id];

$neg  = $d['res'] < 0;
$desc = $d['bon'] / $d['rb'] * 100;                 /* % de desconto sobre a bruta */
$pc   = fn(float $v, int $dec = 1): string => crm_num($v, $dec) . '%';

/* Chip comparativo com a média da carteira (dif em p.p.) */
$cmp = function (float $v, float $m) use ($pc): string {
    $dif = $v - $m;
    return crm_pill(($dif > 0 ? '+' : ($dif < 0 ? '−' : '')) . crm_num(abs($dif), 1) . ' p.p.', $dif >= 0 ? 'green' : 'red');
};

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'dre_cliente',
    'titulo' => 'DRE do Cliente',
    'demo'   => 'Custos e impostos do ERP',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" data-toast="DRE de ' . h($nome) . ' exportado em XLSX">Exportar XLSX</button>',
]);
?>

<style>
/* Cascata do DRE (só desta tela) */
.crm-app .dre-t tr.lh td { font-weight: 700; }
.crm-app .dre-t tr.l1 td, .crm-app .dre-t tr.l2 td { font-weight: 700; background: var(--crm-bg2); border-top: 1px solid var(--crm-line2); }
.crm-app .dre-t tr.l3 td { font-weight: 700; font-size: 13px; background: #EAF1F0; border-top: 2px solid var(--crm-line2); }
</style>

<a class="crm-crumb" href="<?= crm_url('consultor', 'dre') ?>">‹ Rentabilidade por Cliente</a>

<?php if ($neg):
    if ($d['ina'] > 0) {
        $detalhe = 'A inadimplência de <strong>' . crm_brl((float)$d['ina']) . '</strong>'
            . ($d['ina'] > $d['mb']
                ? ' sozinha supera toda a margem bruta do cliente (' . crm_brl((float)$d['mb']) . ').'
                : ' sozinha consome ' . crm_num($d['ina'] / $d['mb'] * 100) . '% da margem bruta.');
    } else {
        $detalhe = 'Desconto de <strong>' . $pc($desc) . '</strong> somado a <strong>' . (int)$d['prazo']
            . ' dias de prazo</strong> consome ' . crm_num(($d['bon'] + $d['fin']) / $d['mb'] * 100) . '% da margem bruta.';
    }
    echo crm_callout(
        '<strong>Resultado negativo de ' . crm_brl((float)-$d['res']) . '</strong> ('
        . $pc((float)$d['resp']) . ' da receita líquida). ' . $detalhe,
        'red'
    );
endif; ?>

<div class="crm-g4">
  <?= crm_kpi('Receita líquida', crm_brl((float)$d['rl']), 'bruta ' . crm_brl((float)$d['rb']), 'teal') ?>
  <?= crm_kpi('Margem bruta', $pc((float)$d['mbp']), 'carteira 21,5% ' . $cmp((float)$d['mbp'], $MEDIA['mbp']), $d['mbp'] >= $MEDIA['mbp'] ? 'green' : 'amber') ?>
  <?= crm_kpi('Margem de contribuição', $pc((float)$d['mcp']), 'carteira 10,4% ' . $cmp((float)$d['mcp'], $MEDIA['mcp']), $d['mcp'] < 0 ? 'red' : ($d['mcp'] >= $MEDIA['mcp'] ? 'green' : 'amber')) ?>
  <?= crm_kpi('Resultado', ($neg ? '−' : '') . crm_brl(abs((float)$d['res'])), $pc((float)$d['resp']) . ' da receita líquida', $neg ? 'red' : 'green') ?>
</div>

<div class="crm-g23">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Demonstrativo de resultado · ciclo 2026 · <?= h($nome) ?></span>
      <?= crm_demo('Custos do ERP') ?>
    </div>
    <div class="crm-tblwrap">
      <table class="crm-tbl dre-t">
        <thead>
          <tr>
            <th>Linha</th>
            <th class="num">Valor</th>
            <th class="num">% receita líq.</th>
            <th style="min-width:140px">Peso</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($LINHAS as [$rot, $k, $tp]):
              $v   = (float)$d[$k];
              $pct = $v / $d['rl'] * 100;
              $ded = $tp === '-';
              $cls = $tp === '===' ? 'l3' : ($tp === '==' ? 'l2' : ($tp === '=' ? 'l1' : ($tp === 'h' ? 'lh' : '')));
              $cor = $tp === '===' ? ($neg ? 'var(--crm-red)' : 'var(--crm-green)')
                   : (($tp === '==' && $v < 0) ? 'var(--crm-red)' : 'inherit');
              $sub = $k === 'ate' ? ' · ' . (int)$d['vis'] . ' visitas × ' . crm_brl((float)$d['cvis'])
                   : ($k === 'fin' ? ' · prazo médio ' . (int)$d['prazo'] . ' d' : '');
              $barCor = ($ded || $v < 0) ? 'red' : 'green'; ?>
          <tr class="<?= $cls ?>">
            <td><?= h($rot) ?><?php if ($sub !== ''): ?><span class="sub"><?= h($sub) ?></span><?php endif; ?></td>
            <td class="num" style="color:<?= $cor ?>">
              <strong><?= $ded ? '(' . crm_brl(abs($v)) . ')' : ($v < 0 ? '−' . crm_brl(-$v) : crm_brl($v)) ?></strong>
            </td>
            <td class="num" style="color:<?= ($tp === '===' || $tp === '==') ? $cor : 'var(--crm-ink3)' ?>"><?= crm_num(abs($pct), 1) ?>%</td>
            <td><?= crm_bar(min(100.0, abs($pct)), $barCor) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Ficha do cliente</span>
      </div>
      <div style="display:flex;align-items:center;gap:4px;margin-bottom:12px">
        <?= crm_avatar($nome, $neg ? 'red' : 'teal', 'g') ?>
        <div>
          <div style="font-weight:700;font-size:14px"><?= h($nome) ?></div>
          <div class="sub" style="font-size:11px;color:var(--crm-ink3)"><?= h($grupo) ?></div>
          <div style="margin-top:5px">
            <?= crm_pill('Classe ' . $classe, 'grey') ?>
            <?= $status === 'Em risco' ? crm_pill('Em risco', 'red') : crm_status_pill($status) ?>
          </div>
        </div>
      </div>
      <?= crm_kv('Visitas no ciclo', (string)(int)$d['vis']) ?>
      <?= crm_kv('Custo por visita', crm_brl((float)$d['cvis'])) ?>
      <?= crm_kv('Receita por visita', crm_brl((float)$d['rpv'])) ?>
      <?= crm_kv('ROI de atendimento', '<span style="color:' . ($d['roi'] < 3 ? 'var(--crm-red)' : 'var(--crm-green)') . '">' . crm_num((float)$d['roi'], 1) . '×</span>') ?>
      <?= crm_kv('Prazo médio', '<span style="color:' . ($d['prazo'] > 60 ? 'var(--crm-red)' : 'inherit') . '">' . (int)$d['prazo'] . ' dias</span>') ?>
      <?= crm_kv('Desconto concedido', '<span style="color:' . ($desc > 3 ? 'var(--crm-red)' : 'inherit') . '">' . $pc($desc) . '</span>') ?>
      <button type="button" class="vbtn" style="margin-top:12px" data-toast="Ficha do produtor · demonstrativo">Abrir ficha completa</button>
    </div>

    <?php /* card "alavancas deste cliente" removido a pedido do gestor 25/08 */ ?>

    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Comparativo com a carteira · posição relativa</span>
      </div>
      <div class="crm-hbars">
        <?php
        $comparativo = [
            ['Margem bruta %',       (float)$d['mbp'],  $d['mbp'] >= $MEDIA['mbp'] ? 'green' : 'amber'],
            ['Média da carteira',    $MEDIA['mbp'],     'grey'],
            ['Marg. contribuição %', (float)$d['mcp'],  $d['mcp'] < 0 ? 'red' : ($d['mcp'] >= $MEDIA['mcp'] ? 'green' : 'amber')],
            ['Média da carteira',    $MEDIA['mcp'],     'grey'],
            ['Resultado %',          (float)$d['resp'], $neg ? 'red' : ($d['resp'] >= $MEDIA['resp'] ? 'green' : 'amber')],
            ['Média da carteira',    $MEDIA['resp'],    'grey'],
        ];
        $maxC = 29.0;
        foreach ($comparativo as [$rot, $v, $cor]): ?>
        <div class="crm-hbar">
          <span><?= h($rot) ?></span>
          <?= crm_bar(abs($v) / $maxC * 100, $cor) ?>
          <span class="num"><?= $pc($v) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php crm_shell_end();
