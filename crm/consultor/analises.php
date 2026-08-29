<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Análises de Solo & Foliar (protótipo)
   Rota: /crm/consultor/analises · dados fiéis ao mockup
   docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.analises)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* TODO mover para _mock.php */
$ANALISES = [
    ['id' => 'AN-0424', 'tipo' => 'Foliar', 'talhao' => 'U-09', 'vari' => 'Itália',       'propn' => 'Fazenda Bom Jesus',     'prod' => 'Helena Vasconcelos',
     'lab' => 'NutriFolha Juazeiro', 'prot' => 'NF-2026/1180', 'coleta' => '12/08/2026', 'origem' => 'Import PDF',
     'desvios' => 1, 'rec' => 'R-0918', 'opor' => '',      'status' => 'Interpretada'],
    ['id' => 'AN-0421', 'tipo' => 'Foliar', 'talhao' => 'U-07', 'vari' => 'Arra 15',      'propn' => 'Fazenda Nova Aliança',  'prod' => 'Fernanda Sá',
     'lab' => 'NutriFolha Juazeiro', 'prot' => 'NF-2026/1174', 'coleta' => '08/08/2026', 'origem' => 'Import PDF',
     'desvios' => 5, 'rec' => 'R-0905', 'opor' => 'O-118', 'status' => 'Interpretada'],
    ['id' => 'AN-0418', 'tipo' => 'Foliar', 'talhao' => 'U-02', 'vari' => 'Arra 15',      'propn' => 'Fazenda Boa Vista',     'prod' => 'João Almeida',
     'lab' => 'NutriFolha Juazeiro', 'prot' => 'NF-2026/1169', 'coleta' => '04/08/2026', 'origem' => 'Import PDF',
     'desvios' => 1, 'rec' => 'R-0916', 'opor' => 'O-126', 'status' => 'Interpretada'],
    ['id' => 'AN-0415', 'tipo' => 'Foliar', 'talhao' => 'M-02', 'vari' => 'Palmer',       'propn' => 'Fazenda Vale Verde',    'prod' => 'Maria Oliveira',
     'lab' => 'Agrolab Vale',        'prot' => 'AV-2026/4478', 'coleta' => '30/07/2026', 'origem' => 'Digitação manual',
     'desvios' => 1, 'rec' => 'R-0914', 'opor' => '',      'status' => 'Interpretada'],
    ['id' => 'AN-0412', 'tipo' => 'Solo',   'talhao' => 'M-04', 'vari' => 'Kent',         'propn' => 'Fazenda Riacho Grande', 'prod' => 'Roberto Nakamura',
     'lab' => 'Agrolab Vale',        'prot' => 'AV-2026/4412', 'coleta' => '28/07/2026', 'origem' => 'Import PDF',
     'desvios' => 4, 'rec' => 'R-0907', 'opor' => 'O-119', 'status' => 'Interpretada'],
    ['id' => 'AN-0409', 'tipo' => 'Solo',   'talhao' => 'U-04', 'vari' => 'Timpson',      'propn' => 'Fazenda Santa Helena',  'prod' => 'Carlos Mendes',
     'lab' => 'Agrolab Vale',        'prot' => 'AV-2026/4390', 'coleta' => '21/07/2026', 'origem' => 'Import PDF',
     'desvios' => 8, 'rec' => 'R-0911', 'opor' => 'O-127', 'status' => 'Interpretada'],
    ['id' => 'AN-0405', 'tipo' => 'Solo',   'talhao' => 'U-01', 'vari' => 'BRS Vitória',  'propn' => 'Fazenda Boa Vista',     'prod' => 'João Almeida',
     'lab' => 'Agrolab Vale',        'prot' => 'AV-2026/4356', 'coleta' => '09/07/2026', 'origem' => 'Import PDF',
     'desvios' => 0, 'rec' => '',       'opor' => '',      'status' => 'Interpretada'],
    ['id' => 'AN-0428', 'tipo' => 'Foliar', 'talhao' => 'M-01', 'vari' => 'Palmer',       'propn' => 'Fazenda Boa Vista II',  'prod' => 'João Almeida',
     'lab' => 'Agrolab Vale',        'prot' => 'AV-2026/4520', 'coleta' => '20/08/2026', 'origem' => 'Aguardando lab.',
     'desvios' => null, 'rec' => '',    'opor' => '',      'status' => 'Em análise'],
];

$interpretadas = array_filter($ANALISES, fn($a) => $a['desvios'] !== null);
$totDesvios    = array_sum(array_map(fn($a) => (int)$a['desvios'], $interpretadas));
$comDesvio     = count(array_filter($interpretadas, fn($a) => (int)$a['desvios'] > 0));

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'analises',
    'titulo' => 'Análises de Solo & Foliar',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-import-laudo\')">Importar laudo</button>',
]);
?>

<?php /* KPIs e callout-manifesto removidos a pedido do gestor 25/08 —
         a tela vai direto aos filtros e à lista de laudos */ ?>
<div class="crm-chips">
  <span class="crm-chip on">Todos (8)</span>
  <span class="crm-chip">Solo (3)</span>
  <span class="crm-chip">Foliar (5)</span>
  <span class="crm-chip">Com desvio (5)</span>
</div>

<div class="crm-card" style="padding:0;overflow:hidden">
  <div class="crm-card__head" style="padding:14px 18px 0;margin-bottom:10px">
    <span class="crm-card__title">Laudos do ciclo</span>
    <?= crm_pill(count($ANALISES) . ' registros', 'teal') ?>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Laudo</th>
          <th>Tipo</th>
          <th>Talhão / propriedade</th>
          <th>Coleta</th>
          <th>Laboratório</th>
          <th>Origem</th>
          <th class="num">Desvios</th>
          <th>Gerou</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ANALISES as $a): ?>
        <tr class="tap" data-href="<?= crm_url('consultor', 'analise') ?>?id=<?= h($a['id']) ?>">
          <td><strong><?= h($a['id']) ?></strong><div class="sub"><?= h($a['prot']) ?></div></td>
          <td><?= crm_pill($a['tipo'], $a['tipo'] === 'Solo' ? 'grey' : 'violet') ?></td>
          <td>
            <strong><?= crm_pill($a['talhao'], 'grey') ?> <?= h($a['vari']) ?></strong>
            <div class="sub"><?= h($a['propn']) ?> · <?= h($a['prod']) ?></div>
          </td>
          <td><?= h($a['coleta']) ?></td>
          <td><?= h($a['lab']) ?></td>
          <td><?= crm_pill($a['origem'], 'grey') ?></td>
          <td class="num">
            <?php if ($a['desvios'] === null): ?>
              <?= crm_pill('—', 'grey') ?>
            <?php elseif ((int)$a['desvios'] > 0): ?>
              <?= crm_pill((string)$a['desvios'], (int)$a['desvios'] > 2 ? 'red' : 'amber') ?>
            <?php else: ?>
              <?= crm_pill('0', 'green') ?>
            <?php endif; ?>
          </td>
          <td>
            <?= $a['rec'] !== ''  ? crm_pill($a['rec'], 'blue')   : '' ?>
            <?= $a['opor'] !== '' ? crm_pill($a['opor'], 'green') : '' ?>
            <?= ($a['rec'] === '' && $a['opor'] === '') ? crm_pill('—', 'grey') : '' ?>
          </td>
          <td><?= crm_pill($a['status'], $a['status'] === 'Interpretada' ? 'green' : 'amber') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="crm-g2">
  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Pendências de amostragem</span>
      <span class="crm-sub">Periodicidade</span>
    </div>
    <?php
    $pend = [
        ['red',   'U-03', 'Sweet Globe · sem análise foliar há 2 ciclos',
         'Fazenda Boa Vista · a referência é 1 laudo por ciclo, em pleno florescimento. O talhão entra em colheita em 21/09.', '2 ciclos'],
        ['red',   'M-05', 'Tommy Atkins · solo vencido há 14 meses',
         'Fazenda Serra Branca · manga pede 1 análise de solo por ciclo, no repouso pós-colheita. O talhão acaba de ser podado.', '14 meses'],
        ['amber', 'M-01', 'Palmer · laudo no laboratório',
         'Coletada em 20/08 para calibrar a nutrição da indução. Prazo de 5 dias úteis — resultado esperado até 27/08.', 'Em análise'],
    ];
    foreach ($pend as $i => [$cor, $cod, $t, $sub, $tag]): ?>
      <div style="display:flex;gap:10px;align-items:flex-start;padding:10px 0;<?= $i > 0 ? 'border-top:1px solid var(--crm-line)' : '' ?>">
        <span style="flex:0 0 8px;width:8px;height:8px;border-radius:50%;margin-top:5px;background:var(--crm-<?= h($cor) ?>)"></span>
        <span style="flex:1;min-width:0">
          <div style="font-size:12.5px;font-weight:600"><?= crm_pill($cod, 'grey') ?> <?= h($t) ?></div>
          <div class="crm-sub"><?= h($sub) ?></div>
        </span>
        <?= crm_pill($tag, $cor) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Como o laudo entra</span>
      <span class="crm-sub">Origem dos dados</span>
    </div>
    <?php
    $origens = [
        ['Import do PDF ou CSV do laboratório',
         'O sistema lê os parâmetros, associa ao talhão pelo protocolo e classifica contra a faixa da cultura. Caminho principal.',
         crm_pill('6 de 8', 'green')],
        ['Digitação manual',
         'Formulário com as mesmas faixas de referência, para laboratório sem layout mapeado.',
         crm_pill('1 de 8', 'grey')],
        ['Integração direta com o laboratório',
         'O laudo cai no talhão sozinho quando fica pronto. Depende de acordo com cada laboratório.',
         crm_demo('Fase 2')],
    ];
    foreach ($origens as $i => [$t, $sub, $tag]): ?>
      <div style="display:flex;gap:10px;align-items:flex-start;padding:10px 0;<?= $i > 0 ? 'border-top:1px solid var(--crm-line)' : '' ?>">
        <span style="flex:1;min-width:0">
          <div style="font-size:12.5px;font-weight:600"><?= h($t) ?></div>
          <div class="crm-sub"><?= h($sub) ?></div>
        </span>
        <?= $tag ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Modal: importar laudo (mock — sem POST) -->
<div class="vmodal" id="vm-import-laudo">
  <div class="vbox">
    <header>
      <h2>Importar laudo do laboratório</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-import-laudo')">×</button>
    </header>
    <div class="vform">
      <div style="font-size:12px;color:var(--crm-ink2);margin-bottom:12px">
        PDF ou CSV · layouts mapeados: Agrolab Vale, NutriFolha Juazeiro, Solocria, Fullin
      </div>
      <div data-toast="Seleção de arquivo · demonstrativo"
           style="border:1.5px dashed var(--crm-line2);border-radius:12px;padding:24px;text-align:center;background:var(--crm-bg2);cursor:pointer">
        <div style="font-size:13px;font-weight:600">Arraste o laudo ou clique para selecionar</div>
        <div class="crm-sub" style="margin-top:4px">Um laudo pode conter vários talhões — cada bloco vira um registro.</div>
      </div>
      <?= crm_callout(
          '<strong>AV-2026/4412.pdf lido:</strong> 22 parâmetros reconhecidos, talhão M-04 identificado pelo protocolo, '
          . 'método Mehlich-1 detectado. ' . crm_demo('leitura automática'),
          'green'
      ) ?>
      <div class="vgrid">
        <div class="vfield">
          <label>Talhão</label>
          <select>
            <option>M-04 · Kent · 33 ha · Riacho Grande</option>
            <option>Outro talhão…</option>
          </select>
        </div>
        <div class="vfield">
          <label>Tipo</label>
          <select>
            <option>Solo</option>
            <option>Foliar</option>
          </select>
        </div>
        <div class="vfield">
          <label>Data da coleta</label>
          <input type="text" value="28/07/2026">
        </div>
        <div class="vfield">
          <label>Profundidade / tecido</label>
          <input type="text" value="0–20 cm · projeção da copa">
        </div>
        <div class="vfield">
          <label>Método de extração</label>
          <select>
            <option>Mehlich-1</option>
            <option>Resina</option>
            <option>DTPA</option>
          </select>
        </div>
        <div class="vfield">
          <label>Referência de interpretação</label>
          <select>
            <option>Embrapa CT-100 · videira</option>
            <option>Embrapa CT-88 · mangueira</option>
            <option>IAC Boletim 100</option>
          </select>
        </div>
      </div>
      <div style="font-size:11px;color:var(--crm-ink3);margin-top:6px">
        O método de extração define a tabela de faixas usada na interpretação. Ao salvar, o sistema classifica cada
        parâmetro, monta o diagnóstico dos desvios e sugere a recomendação correspondente.
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-import-laudo')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Laudo importado · 22 parâmetros classificados · 1 desvio encontrado">Importar e interpretar</button>
      </div>
    </div>
  </div>
</div>

<?php crm_shell_end();
