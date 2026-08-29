<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Painel do Consultor (protótipo demo)
   Rota: /crm/consultor/dashboard
   Fonte: docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.painel)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* Dados locais fiéis ao mockup — Vale do São Francisco (uva e manga).
   TODO mover para _mock.php */

$ETAPAS = ['Prospecção', 'Diagnóstico técnico', 'Recomendação', 'Proposta', 'Conformidade & crédito', 'Negociação'];

/* Oportunidades ABERTAS (etapa < Ganha) — id, etapa, valor, prob % */
$OPPS = [
    ['id' => 'O-115', 'etapa' => 4, 'valor' => 186000, 'prob' => 70],
    ['id' => 'O-118', 'etapa' => 2, 'valor' => 242000, 'prob' => 45],
    ['id' => 'O-121', 'etapa' => 3, 'valor' => 98000,  'prob' => 60],
    ['id' => 'O-119', 'etapa' => 3, 'valor' => 74000,  'prob' => 55],
    ['id' => 'O-112', 'etapa' => 5, 'valor' => 315000, 'prob' => 40],
    ['id' => 'O-124', 'etapa' => 1, 'valor' => 58000,  'prob' => 80],
    ['id' => 'O-108', 'etapa' => 0, 'valor' => 132000, 'prob' => 20],
    ['id' => 'O-126', 'etapa' => 2, 'valor' => 18400,  'prob' => 75],
    ['id' => 'O-127', 'etapa' => 2, 'valor' => 126000, 'prob' => 50],
];

/* Roteiro de hoje (visitas agendadas de 25/08) */
$ROTEIRO = [
    ['id' => 'V214', 'h' => '07:30', 'faz' => 'Fazenda Boa Vista',    'tipo' => 'Captação', 'prod' => 'João Almeida',   'mun' => 'Petrolina · PE',
     'obj' => 'Avaliar míldio no U-02 e fechar programa de pré-colheita', 'km' => 12, 'cult' => 'Uva/Manga'],
    ['id' => 'V215', 'h' => '09:45', 'faz' => 'Fazenda Nova Aliança', 'tipo' => 'Prospecção',          'prod' => 'Fernanda Sá',    'mun' => 'Santa Maria da Boa Vista · PE',
     'obj' => '2ª visita — apresentar diagnóstico de rachadura em Arra 15', 'km' => 54, 'cult' => 'Uva'],
    ['id' => 'V216', 'h' => '13:30', 'faz' => 'Fazenda Santa Helena', 'tipo' => 'Follow-up',           'prod' => 'Carlos Mendes',  'mun' => 'Lagoa Grande · PE',
     'obj' => 'Retomar contato — 32 dias sem visita; oportunidade parada', 'km' => 38, 'cult' => 'Uva'],
    ['id' => 'V217', 'h' => '16:00', 'faz' => 'Fazenda Bom Jesus',    'tipo' => 'Pós-venda',           'prod' => 'Helena Vasconcelos', 'mun' => 'Petrolina · PE',
     'obj' => 'Conferir resultado do bioinsumo aplicado em 04/08', 'km' => 24, 'cult' => 'Uva'],
];

/* Radar saiu do painel — os itens completos, com as
   ações secundárias, vivem na tela Radar & Automações (radar.php). */

/* Ações atrasadas (card Atenção) */
$ATRASADAS = [
    ['t' => 'Retomar O-112 · Santa Helena', 'x' => 'Sem movimentação há 12 dias na etapa Negociação. R$ 315.000 em risco.',
     'dias' => 12, 'href' => crm_url('consultor', 'oportunidade') . '?id=O-112'],
    ['t' => 'Ligar para Antônio Ribeiro',   'x' => '47 dias sem contato. Compra caiu 55% vs. 2025 e há R$ 18.400 vencidos.',
     'dias' => 47, 'href' => crm_url('consultor', 'produtor') . '?id=P04'],
    ['t' => 'Reativar José Bezerra',        'x' => '61 dias sem contato, sem oportunidade aberta e potencial de R$ 380 mil/ano.',
     'dias' => 61, 'href' => crm_url('consultor', 'produtor') . '?id=P08'],
];

/* Barras de cobertura por classe saíram do painel (gestor 25/08) —
   a leitura de cobertura ficou na pizza + rodapé do card Carteira. */

/* Agregados do pipeline */
$totalPipe = 0.0; $pond = 0.0;
$funil = [];
foreach ($ETAPAS as $i => $nome) $funil[$i] = ['nome' => $nome, 'n' => 0, 'valor' => 0.0];
foreach ($OPPS as $o) {
    $totalPipe += $o['valor'];
    $pond      += $o['valor'] * $o['prob'] / 100;
    $funil[$o['etapa']]['n']++;
    $funil[$o['etapa']]['valor'] += $o['valor'];
}
$maxEtapa = max(array_column($funil, 'valor'));

/* R$ compacto do mockup: >= 1 mi vira "R$ 1,25 mi", senão "R$ 614 mil" */
$brlk = function (float $v): string {
    if ($v >= 1000000) return 'R$ ' . number_format($v / 1000000, 2, ',', '.') . ' mi';
    return 'R$ ' . crm_num(round($v / 1000)) . ' mil';
};

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'dashboard',
    'titulo' => 'Painel do Consultor',
    'acoes'  => '<a class="vbtn vbtn-primary" href="' . crm_url('consultor', 'meu-dia') . '">Abrir Meu Dia</a>',
]);
?>

<div class="crm-g4">
  <div data-href="<?= crm_url('consultor', 'meu-dia') ?>" style="cursor:pointer">
    <?= crm_kpi('Visitas do dia', '4', '07:30 até 17:10 · 128 km', 'teal') ?>
  </div>
  <div data-href="<?= crm_url('consultor', 'acoes') ?>" style="cursor:pointer">
    <?= crm_kpi('Follow-ups hoje', '5', '1 vence hoje', 'amber') ?>
  </div>
  <div data-href="<?= crm_url('consultor', 'acoes') ?>" style="cursor:pointer">
    <?= crm_kpi('Ações atrasadas', '3', '<strong style="color:var(--crm-red)">R$ 315 mil em risco</strong>', 'red') ?>
  </div>
  <div data-href="<?= crm_url('consultor', 'indicadores') ?>" style="cursor:pointer">
    <?= crm_kpi('Meta do mês', '62%', 'R$ 388 mil de R$ 620 mil', 'green') ?>
  </div>
</div>

<?php /* Reorganização 25/08 (pedidos do gestor: "muita bagunça visual" e depois
         "tire o Radar, troque pelo Roteiro") — linhas PAREADAS, sem coluna sobrando:
         [Roteiro de hoje] | [Atenção]
         [Comercial · números + funil] | [Carteira · cobertura]
         O radar completo vive na tela Radar & Automações (menu Inteligência). */ ?>
<div class="crm-g23" style="align-items:start">
  <!-- Roteiro de hoje -->
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Roteiro de hoje · terça, 25 de agosto</span>
      <a class="vbtn vbtn-sm vbtn-ghost" href="<?= crm_url('consultor', 'rota') ?>">Ver rota</a>
    </div>
    <?php foreach ($ROTEIRO as $v): ?>
      <div class="crm-ag" data-href="<?= crm_url('consultor', 'visita') ?>?id=<?= h($v['id']) ?>" style="cursor:pointer">
        <span class="crm-ag__h"><?= h($v['h']) ?></span>
        <span class="crm-ag__bar"></span>
        <span class="crm-ag__body">
          <div class="crm-ag__t"><?= h($v['faz']) ?></div>
          <div class="crm-ag__sub"><?= h($v['prod']) ?> · <?= h($v['obj']) ?></div>
        </span>
        <?= crm_tag($v['km'] . ' km', 'grey') ?>
        <?= crm_tag($v['cult'], $v['cult'] === 'Uva' ? 'violet' : 'amber') ?>
        <?= crm_tag($v['tipo'], 'teal') ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Atenção -->
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Atenção · precisa de você</span>
      <?= crm_pill('3 atrasadas', 'red') ?>
    </div>
    <?php foreach ($ATRASADAS as $a): ?>
      <div class="crm-ag" data-href="<?= $a['href'] ?>" style="cursor:pointer">
        <span class="crm-ag__bar b-red"></span>
        <span class="crm-ag__body">
          <div class="crm-ag__t"><?= h($a['t']) ?></div>
          <div class="crm-ag__sub"><?= h($a['x']) ?></div>
        </span>
        <?= crm_pill($a['dias'] . ' d', 'red') ?>
      </div>
    <?php endforeach; ?>
    <div class="crm-ag" data-href="<?= crm_url('consultor', 'visitas') ?>" style="cursor:pointer">
      <span class="crm-ag__bar b-amber"></span>
      <span class="crm-ag__body">
        <div class="crm-ag__t">2 visitas sem próxima ação</div>
        <div class="crm-ag__sub">Visitas de 19/08 e 22/08 encerradas sem próximo passo definido.</div>
      </span>
      <?= crm_pill('2', 'amber') ?>
    </div>
  </div>
</div>

<?php /* 25/08 (gestor): cards da linha com a MESMA altura — grid estica os dois;
         a Carteira centraliza o miolo para não sobrar vazio embaixo */ ?>
<div class="crm-g23">
  <!-- Comercial: números + funil, rentabilidade no rodapé -->
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Comercial · ciclo 2026.2</span>
      <a class="vbtn vbtn-sm vbtn-ghost" href="<?= crm_url('consultor', 'pipeline') ?>">Pipeline</a>
    </div>
    <?php /* 25/08 (gestor): números/barras saíram; pizza do pipeline por etapa
             (mesmo donut SVG da Carteira), total aberto no centro. */
    $CORES_ETAPA = ['var(--crm-teal)', 'var(--crm-green)', 'var(--crm-amber)',
                    'var(--crm-blue)', 'var(--crm-violet)', 'var(--crm-red)']; ?>
    <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;margin:6px 0 2px">
      <svg viewBox="0 0 42 42" style="width:220px;height:220px;flex:none" role="img" aria-label="Pipeline por etapa">
        <circle cx="21" cy="21" r="15.915" fill="none" stroke="var(--crm-line)" stroke-width="5"></circle>
        <?php $acum = 0.0; foreach ($funil as $i => $f):
            if ($f['valor'] <= 0) continue;
            $pct = $f['valor'] / $totalPipe * 100; ?>
          <circle cx="21" cy="21" r="15.915" fill="none" stroke="<?= $CORES_ETAPA[$i % 6] ?>" stroke-width="5"
                  stroke-dasharray="<?= number_format($pct, 2, '.', '') ?> <?= number_format(100 - $pct, 2, '.', '') ?>"
                  stroke-dashoffset="<?= number_format(25 - $acum, 2, '.', '') ?>"></circle>
          <?php $acum += $pct; endforeach; ?>
        <text x="21" y="20.2" text-anchor="middle"
              style="font:600 3.7px var(--num,'IBM Plex Mono');fill:var(--crm-ink)"><?= h($brlk($totalPipe)) ?></text>
        <text x="21" y="25.2" text-anchor="middle"
              style="font:600 2.2px var(--num,'IBM Plex Mono');fill:var(--crm-ink3);letter-spacing:.2px"><?= count($OPPS) ?> OPORTUNIDADES</text>
      </svg>
      <div style="flex:1 1 240px;min-width:0;display:flex;flex-direction:column;gap:6px;font-size:12px">
        <?php foreach ($funil as $i => $f): ?>
          <div style="display:flex;align-items:center;gap:8px;color:var(--crm-ink2)">
            <span style="width:9px;height:9px;border-radius:3px;background:<?= $CORES_ETAPA[$i % 6] ?>;flex:none"></span>
            <?= h($f['nome']) ?> (<?= (int)$f['n'] ?>)
            <strong class="mono" style="margin-left:auto;font-family:var(--num,'IBM Plex Mono')"><?= $brlk((float)$f['valor']) ?></strong>
          </div>
        <?php endforeach; ?>
        <div style="font-size:11px;color:var(--crm-ink3);margin-top:2px">
          Ponderado pela probabilidade: <strong style="color:var(--crm-green)"><?= $brlk($pond) ?></strong>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:28px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid var(--crm-line)">
      <div>
        <div class="crm-card__title">Resultado da carteira <?= crm_demo('DRE · ERP') ?></div>
        <div style="font:600 19px var(--num,'IBM Plex Mono');color:var(--crm-green)">R$ 53 mil</div>
        <div style="font-size:11px;color:var(--crm-ink3)">2,8% da receita líq.</div>
      </div>
      <div>
        <div class="crm-card__title">Clientes no vermelho</div>
        <div style="font:600 19px var(--num,'IBM Plex Mono');color:var(--crm-red)">3</div>
        <div style="font-size:11px;color:var(--crm-ink3)">−R$ 36,9 mil · <a href="<?= crm_url('consultor', 'dre') ?>" style="color:var(--crm-teal);font-weight:600">abrir DRE</a></div>
      </div>
    </div>
  </div>

  <!-- Carteira -->
  <div class="crm-card" style="display:flex;flex-direction:column">
    <div class="crm-card__head">
      <span class="crm-card__title">Carteira · cobertura</span>
      <a class="vbtn vbtn-sm vbtn-ghost" href="<?= crm_url('consultor', 'produtores') ?>">Produtores</a>
    </div>
    <?php /* 25/08 (gestor): KPIs saíram; pizza da carteira por classe no lugar.
             Donut SVG puro (r=15.915 → circunferência 100: dasharray = %). */
    $PIZZA = [
        ['Classe A', 3, 'var(--crm-teal)'],
        ['Classe B', 4, 'var(--crm-amber)'],
        ['Classe C', 1, 'var(--crm-violet)'],
    ];
    $pTot = array_sum(array_column($PIZZA, 1));
    ?>
    <div style="display:flex;gap:20px;align-items:center;margin:6px 0 14px;flex-wrap:wrap;flex:1">
      <svg viewBox="0 0 42 42" style="width:190px;height:190px;flex:none" role="img" aria-label="Carteira por classe">
        <circle cx="21" cy="21" r="15.915" fill="none" stroke="var(--crm-line)" stroke-width="5"></circle>
        <?php $acum = 0.0; foreach ($PIZZA as [$rot, $n, $cor]):
            $pct = $n / $pTot * 100; ?>
          <circle cx="21" cy="21" r="15.915" fill="none" stroke="<?= $cor ?>" stroke-width="5"
                  stroke-dasharray="<?= number_format($pct, 2, '.', '') ?> <?= number_format(100 - $pct, 2, '.', '') ?>"
                  stroke-dashoffset="<?= number_format(25 - $acum, 2, '.', '') ?>"></circle>
          <?php $acum += $pct; endforeach; ?>
        <text x="21" y="20.2" text-anchor="middle"
              style="font:600 8px var(--num,'IBM Plex Mono');fill:var(--crm-ink)"><?= (int)$pTot ?></text>
        <text x="21" y="26.6" text-anchor="middle"
              style="font:600 2.9px var(--num,'IBM Plex Mono');fill:var(--crm-ink3);letter-spacing:.3px">PRODUTORES</text>
      </svg>
      <div style="display:flex;flex-direction:column;gap:7px;font-size:12px;min-width:150px">
        <?php foreach ($PIZZA as [$rot, $n, $cor]): ?>
          <div style="display:flex;align-items:center;gap:8px;color:var(--crm-ink2)">
            <span style="width:9px;height:9px;border-radius:3px;background:<?= $cor ?>;flex:none"></span>
            <?= h($rot) ?>
            <strong class="mono" style="margin-left:auto;font-family:var(--num,'IBM Plex Mono')"><?= (int)$n ?></strong>
          </div>
        <?php endforeach; ?>
        <div style="display:flex;align-items:center;gap:8px;color:var(--crm-red);font-weight:600">
          <span style="width:9px;height:9px;border-radius:3px;background:var(--crm-red);flex:none"></span>
          Em risco · sem contato &gt; 45 d
          <strong class="mono" style="margin-left:auto;font-family:var(--num,'IBM Plex Mono')">2</strong>
        </div>
      </div>
    </div>
    <?php /* barras de cobertura por classe retiradas a pedido do gestor 25/08 */ ?>
    <div style="font-size:11px;color:var(--crm-ink3);margin-top:8px">
      9 propriedades · 374 ha produtivos · potencial R$ 5,6 mi/ano · frequência-alvo A 15 d · B 30 d · C 45 d.
    </div>
  </div>
</div>

<?php crm_shell_end();
