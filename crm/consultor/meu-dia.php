<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Meu Dia (protótipo demo)
   Rota: /crm/consultor/meu-dia
   Fonte: docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.meudia)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* Dados locais fiéis ao mockup — TODO mover para _mock.php */

$FENO_UVA = ['Poda', 'Brotação', 'Cresc. veget.', 'Floração', 'Chumbinho', 'Amolecimento', 'Maturação', 'Colheita'];
$ABBR = ['Poda' => 'Poda', 'Brotação' => 'Brot', 'Cresc. veget.' => 'Veg', 'Floração' => 'Flor', 'Chumbinho' => 'Chumb',
         'Amolecimento' => 'Amol', 'Maturação' => 'Mat', 'Colheita' => 'Colh'];

/* Paradas do roteiro de hoje (25/08) — produtor, propriedade, talhões */
$PARADAS = [
    ['id' => 'V214', 'hora' => '07:30', 'km' => 12, 'tipo' => 'Captação', 'cor' => 'teal',
     'faz' => 'Fazenda Boa Vista', 'prod' => 'João Almeida', 'mun' => 'Petrolina · PE', 'loc' => '-9,3891 / -40,5030',
     'obj' => 'Avaliar míldio no U-02 e fechar programa de pré-colheita', 'objCor' => 'teal',
     'ult' => 'há 6 dias · Visita técnica — talhão U-03',
     'credito' => 'R$ 233.500 disponível', 'venc' => '',
     'opps' => ['O-115 · R$ 186 mil', 'O-124 · R$ 58 mil', 'O-126 · R$ 18 mil'],
     'cert' => ['GLOBALG.A.P.', 'PIF'],
     'talhoes' => [
         ['cod' => 'U-01', 'vari' => 'BRS Vitória', 'ha' => 18, 'status' => 'Em ciclo',      'risco' => 'ok',       'estagio' => 5, 'dias' => 78],
         ['cod' => 'U-02', 'vari' => 'Arra 15',     'ha' => 22, 'status' => 'Em ciclo',      'risco' => 'atencao',  'estagio' => 3, 'dias' => 36],
         ['cod' => 'U-03', 'vari' => 'Sweet Globe', 'ha' => 14, 'status' => 'Pré-colheita',  'risco' => 'bloqueio', 'estagio' => 6, 'dias' => 98],
     ],
     'alerta' => ''],
    ['id' => 'V215', 'hora' => '09:45', 'km' => 54, 'tipo' => 'Prospecção', 'cor' => 'teal',
     'faz' => 'Fazenda Nova Aliança', 'prod' => 'Fernanda Sá', 'mun' => 'Santa Maria da Boa Vista · PE', 'loc' => '-8,8061 / -39,8266',
     'obj' => '2ª visita — apresentar diagnóstico de rachadura em Arra 15', 'objCor' => 'teal',
     'ult' => 'há 3 dias · Primeira visita — diagnóstico',
     'credito' => 'Sem cadastro', 'venc' => '',
     'opps' => ['O-118 · R$ 242 mil'],
     'cert' => ['GLOBALG.A.P.'],
     'talhoes' => [
         ['cod' => 'U-07', 'vari' => 'Arra 15',     'ha' => 38, 'status' => 'Em ciclo', 'risco' => 'bloqueio', 'estagio' => 5, 'dias' => 74],
         ['cod' => 'U-08', 'vari' => 'Sugar Crisp', 'ha' => 30, 'status' => 'Em ciclo', 'risco' => 'ok',       'estagio' => 3, 'dias' => 33],
     ],
     'alerta' => ''],
    ['id' => 'V216', 'hora' => '13:30', 'km' => 38, 'tipo' => 'Follow-up', 'cor' => 'amber',
     'faz' => 'Fazenda Santa Helena', 'prod' => 'Carlos Mendes', 'mun' => 'Lagoa Grande · PE', 'loc' => '-8,9905 / -40,2712',
     'obj' => 'Retomar contato — 32 dias sem visita; oportunidade parada', 'objCor' => 'amber',
     'ult' => 'há 32 dias · Ligação — cotação de fungicida',
     'credito' => 'R$ 29.000 disponível', 'venc' => '',
     'opps' => ['O-112 · R$ 315 mil', 'O-127 · R$ 126 mil'],
     'cert' => ['GLOBALG.A.P.'],
     'talhoes' => [
         ['cod' => 'U-04', 'vari' => 'Timpson',          'ha' => 32, 'status' => 'Em ciclo', 'risco' => 'atencao', 'estagio' => 4, 'dias' => 52],
         ['cod' => 'U-05', 'vari' => 'Crimson Seedless', 'ha' => 26, 'status' => 'Em ciclo', 'risco' => 'ok',      'estagio' => 2, 'dias' => 22],
     ],
     'alerta' => ''],
    ['id' => 'V217', 'hora' => '16:00', 'km' => 24, 'tipo' => 'Pós-venda', 'cor' => 'teal',
     'faz' => 'Fazenda Bom Jesus', 'prod' => 'Helena Vasconcelos', 'mun' => 'Petrolina · PE', 'loc' => '-9,3455 / -40,5610',
     'obj' => 'Conferir resultado do bioinsumo aplicado em 04/08', 'objCor' => 'teal',
     'ult' => 'há 8 dias · Pedido entregue',
     'credito' => 'R$ 48.000 disponível', 'venc' => '',
     'opps' => [],
     'cert' => [],
     'talhoes' => [
         ['cod' => 'U-09', 'vari' => 'Itália', 'ha' => 12, 'status' => 'Pré-colheita', 'risco' => 'atencao', 'estagio' => 6, 'dias' => 104],
     ],
     'alerta' => ''],
];

/* Pins do mapa da rota (posições % fiéis ao mockup) */
$PINS = [
    ['x' => 24, 'y' => 52, 'n' => '1', 't' => 'Boa Vista',     'cor' => 'teal',  'id' => 'F01'],
    ['x' => 58, 'y' => 17, 'n' => '2', 't' => 'Nova Aliança',  'cor' => 'teal',  'id' => 'F05'],
    ['x' => 40, 'y' => 33, 'n' => '3', 't' => 'Santa Helena',  'cor' => 'teal',  'id' => 'F02'],
    ['x' => 17, 'y' => 70, 'n' => '4', 't' => 'Bom Jesus',     'cor' => 'teal',  'id' => 'F08'],
    ['x' => 64, 'y' => 44, 'n' => '',  't' => 'Boa Vista II',  'cor' => 'grey',  'id' => 'F07'],
    ['x' => 30, 'y' => 88, 'n' => '',  't' => 'Vale Verde',    'cor' => 'grey',  'id' => 'F03'],
    ['x' => 68, 'y' => 74, 'n' => '',  't' => 'São José',      'cor' => 'red',   'id' => 'F04'],
    ['x' => 80, 'y' => 31, 'n' => '',  't' => 'Serra Branca',  'cor' => 'red',   'id' => 'F09'],
    ['x' => 58, 'y' => 94, 'n' => '',  't' => 'Riacho Grande', 'cor' => 'amber', 'id' => 'F06'],
];

/* Ações do dia (follow-ups de hoje + atrasadas) */
$ACOES_DIA = [
    ['t' => 'Enviar cotação do programa de pré-colheita · U-03', 'x' => 'Prometido na visita V212 de 19/08. Vence hoje.',
     'pill' => 'hoje', 'cor' => 'amber', 'href' => crm_url('consultor', 'oportunidade') . '?id=O-115'],
    ['t' => 'Retomar O-112 · Santa Helena', 'x' => 'Sem movimentação há 12 dias na etapa Negociação. R$ 315.000 em risco.',
     'pill' => '12 d', 'cor' => 'red', 'href' => crm_url('consultor', 'oportunidade') . '?id=O-112'],
    ['t' => 'Ligar para Antônio Ribeiro', 'x' => '47 dias sem contato. Compra caiu 55% vs. 2025 e há R$ 18.400 vencidos.',
     'pill' => '47 d', 'cor' => 'red', 'href' => crm_url('consultor', 'produtor') . '?id=P04'],
    ['t' => 'Reativar José Bezerra', 'x' => '61 dias sem contato, sem oportunidade aberta e potencial de R$ 380 mil/ano.',
     'pill' => '61 d', 'cor' => 'red', 'href' => crm_url('consultor', 'produtor') . '?id=P08'],
];

$riscoPill = function (string $r): string {
    if ($r === 'bloqueio') return crm_pill('Carência', 'red');
    if ($r === 'atencao')  return crm_pill('Atenção', 'amber');
    return crm_pill('OK', 'green');
};

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'meu_dia',
    'titulo' => 'Meu Dia',
]);
?>

<style>
/* Barra de fenologia (sem equivalente no crm.css) — escopo .crm-app, cores var(--crm-*) */
.crm-app .crm-feno { display: flex; border: 1px solid var(--crm-line2); border-radius: 9px; overflow: hidden; background: var(--crm-bg2); }
.crm-app .crm-feno .st {
  flex: 1; min-width: 0; padding: 7px 4px; text-align: center; line-height: 1.25;
  font-size: 9.5px; font-weight: 600; color: var(--crm-ink3);
  border-right: 1px solid var(--crm-line2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.crm-app .crm-feno .st:last-child { border-right: 0; }
.crm-app .crm-feno .st.done { background: rgba(14,126,114,.10); color: var(--crm-green); }
.crm-app .crm-feno .st.now  { background: var(--crm-teal); color: #fff; font-weight: 700; }
.crm-app .crm-feno .st .dd  { display: block; font: 600 9px var(--num,'IBM Plex Mono'); opacity: .8; margin-top: 2px; }
</style>

<?= crm_callout(
    '<strong>Roteiro otimizado:</strong> 4 visitas · 128 km · saída 07:00, retorno estimado 17:40. '
    . 'Economia de 31 km sobre a ordem original. ' . crm_demo('roteirização')
    . '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">'
    . '<button type="button" class="vbtn vbtn-sm vbtn-ghost" data-toast="Roteiro recalculado">Recalcular</button>'
    . '<a class="vbtn vbtn-sm vbtn-ghost" href="' . crm_url('consultor', 'rota') . '">Ver no mapa</a></div>',
    'teal'
) ?>

<div class="crm-g23">
  <div>
    <?php foreach ($PARADAS as $i => $v): ?>
      <div class="crm-card" <?= $i > 0 ? 'style="margin-top:14px"' : '' ?>>
        <div class="crm-card__head" style="align-items:flex-start;flex-wrap:wrap">
          <div style="display:flex;gap:12px;align-items:center;min-width:0">
            <span class="crm-avatar crm-avatar--g av-<?= h($v['cor']) ?>" style="margin:0"><?= h(substr($v['hora'], 0, 2)) ?>h</span>
            <div style="min-width:0">
              <div class="crm-card__title">Parada <?= $i + 1 ?> de <?= count($PARADAS) ?> · <?= (int)$v['km'] ?> km · <?= h($v['tipo']) ?></div>
              <div style="font-size:15px;font-weight:700;margin-top:2px"><?= h($v['faz']) ?></div>
              <div style="font-size:11.5px;color:var(--crm-ink2)"><?= h($v['prod']) ?> · <?= h($v['mun']) ?> · <?= h($v['loc']) ?></div>
            </div>
          </div>
          <div style="display:flex;gap:7px;flex-wrap:wrap">
            <button type="button" class="vbtn vbtn-sm vbtn-ghost" data-toast="Abrindo navegação (demonstrativo)">Navegar</button>
            <a class="vbtn vbtn-sm" href="<?= crm_url('consultor', 'visita') ?>?id=<?= h($v['id']) ?>">Abrir briefing</a>
          </div>
        </div>

        <?= crm_callout('<strong>Objetivo:</strong> ' . h($v['obj']), $v['objCor']) ?>

        <?= crm_kv('Último contato', h($v['ult'])) ?>
        <?= crm_kv('Crédito', h($v['credito']) . ' ' . crm_demo('ERP')) ?>
        <?= crm_kv('Oportunidades abertas', $v['opps']
            ? implode(' ', array_map(fn($o) => crm_pill($o, 'blue'), $v['opps']))
            : crm_pill('nenhuma', 'grey')) ?>
        <?= crm_kv('Certificações', $v['cert']
            ? implode(' ', array_map(fn($c) => crm_pill($c, 'green'), $v['cert']))
            : '—') ?>

        <div style="margin-top:14px">
          <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:8px">
            <span class="crm-card__title">Talhões · estágio atual</span>
            <span style="font-size:10.5px;color:var(--crm-ink3)">Atualizado automaticamente pela data de poda</span>
          </div>
          <?php foreach ($v['talhoes'] as $t): ?>
            <div style="margin-bottom:11px">
              <div style="display:flex;align-items:center;gap:9px;margin-bottom:5px;flex-wrap:wrap">
                <?= crm_pill($t['cod'], 'grey') ?>
                <span style="font-size:12.5px;font-weight:600"><?= h($t['vari']) ?></span>
                <span style="font-size:11px;color:var(--crm-ink3)"><?= (int)$t['ha'] ?> ha · <?= h($t['status']) ?></span>
                <?= $riscoPill($t['risco']) ?>
              </div>
              <div class="crm-feno">
                <?php foreach ($FENO_UVA as $iF => $nome): ?>
                  <span class="st <?= $iF < $t['estagio'] ? 'done' : ($iF === $t['estagio'] ? 'now' : '') ?>" title="<?= h($nome) ?>">
                    <?= h($ABBR[$nome] ?? $nome) ?>
                    <?php if ($iF === $t['estagio']): ?><span class="dd"><?= (int)$t['dias'] ?> d</span><?php endif; ?>
                  </span>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($v['alerta'] !== ''): ?>
          <?= crm_callout('<strong>' . h($v['alerta']) . '</strong>', 'red') ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div>
    <!-- Rota do dia -->
    <div class="crm-card">
      <div class="crm-card__head">
        <span class="crm-card__title">Rota do dia · 128 km · 4 paradas <?= crm_demo('roteirização') ?></span>
        <a class="vbtn vbtn-sm vbtn-ghost" href="<?= crm_url('consultor', 'rota') ?>">Expandir</a>
      </div>
      <div class="crm-map" style="min-height:300px">
        <?php foreach ($PINS as $p): ?>
          <a class="crm-pin pin-<?= h($p['cor']) ?>" style="left:<?= (int)$p['x'] ?>%;top:<?= (int)$p['y'] ?>%"
             href="<?= crm_url('consultor', 'propriedade') ?>?id=<?= h($p['id']) ?>">
            <span class="crm-pin__dot"></span>
            <span class="crm-pin__lbl"><?= $p['n'] !== '' ? h($p['n']) . ' · ' : '' ?><?= h($p['t']) ?></span>
          </a>
        <?php endforeach; ?>
        <span class="crm-map__badge"><?= crm_pill('07:00 até 17:40', 'teal') ?></span>
      </div>
    </div>

    <!-- Ações do dia -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Ações do dia</span>
        <?= crm_pill(count($ACOES_DIA) . ' pendentes', 'amber') ?>
      </div>
      <?php foreach ($ACOES_DIA as $a): ?>
        <div class="crm-ag" data-href="<?= $a['href'] ?>" style="cursor:pointer">
          <span class="crm-ag__bar b-<?= h($a['cor']) ?>"></span>
          <span class="crm-ag__body">
            <div class="crm-ag__t"><?= h($a['t']) ?></div>
            <div class="crm-ag__sub"><?= h($a['x']) ?></div>
          </span>
          <?= crm_pill($a['pill'], $a['cor']) ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Encaixe sugerido -->
    <div class="crm-card" style="margin-top:14px">
      <div class="crm-card__head">
        <span class="crm-card__title">Encaixe sugerido · proximidade <?= crm_demo('rota') ?></span>
      </div>
      <div class="crm-ag">
        <span class="crm-ag__bar b-blue"></span>
        <span class="crm-ag__body">
          <div class="crm-ag__t">Fazenda Serra Branca está a 11 km do trecho das 13:30</div>
          <div class="crm-ag__sub">José Bezerra · 61 dias sem contato · potencial R$ 380 mil/ano · sem oportunidade aberta.</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:7px">
            <button type="button" class="vbtn vbtn-sm" data-toast="Visita encaixada às 15:00">Encaixar 15:00</button>
            <a class="vbtn vbtn-sm vbtn-ghost" href="<?= crm_url('consultor', 'produtor') ?>?id=P08">Ver produtor</a>
          </div>
        </span>
      </div>
    </div>
  </div>
</div>

<?php crm_shell_end();
