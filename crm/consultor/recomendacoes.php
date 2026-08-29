<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Recomendações Técnicas (protótipo demo)
   Rota: /crm/consultor/recomendacoes · dados fiéis ao mockup
   docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.recomendacoes)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* TODO mover para _mock.php */
$RECS = [
    ['id' => 'R-0912', 'data' => '19/08/2026', 'prod' => 'João Almeida',       'talhao' => 'U-02', 'vari' => 'Arra 15',
     'prob' => 'Míldio (Plasmopara viticola) · incidência 4%',
     'rec' => 'Fungicida sistêmico em até 48h + reforço protetor em 7 dias',
     'art' => 'ART 2026-004112', 'receita' => 'Emitida', 'caderno' => 'Registrado', 'destino' => 'UE',
     'status' => 'Aplicada', 'carencia' => '21 dias · colheita 22/11 · folga 74 d'],
    ['id' => 'R-0913', 'data' => '19/08/2026', 'prod' => 'João Almeida',       'talhao' => 'U-03', 'vari' => 'Sweet Globe',
     'prob' => 'Programa de pré-colheita',
     'rec' => 'Bloqueado — talhão em janela de carência até 01/09',
     'art' => '—', 'receita' => 'Bloqueada', 'caderno' => '—', 'destino' => 'UE / EUA',
     'status' => 'Bloqueada', 'carencia' => '14 dias · colheita 21/09 · conflito'],
    ['id' => 'R-0910', 'data' => '14/08/2026', 'prod' => 'Maria Oliveira',     'talhao' => 'M-02', 'vari' => 'Palmer',
     'prob' => 'Programa de floração — proteção antracnose/oídio',
     'rec' => 'Substituir 2 ativos por alternativas compatíveis com a lista do importador',
     'art' => 'ART 2026-004098', 'receita' => 'Em revisão', 'caderno' => 'Pendente', 'destino' => 'UE',
     'status' => 'Em revisão', 'carencia' => '—'],
    ['id' => 'R-0907', 'data' => '06/08/2026', 'prod' => 'Roberto Nakamura',   'talhao' => 'M-04', 'vari' => 'Kent',
     'prob' => 'K abaixo do ideal (análise de solo 28/07)',
     'rec' => 'Adubação de reposição · 180 kg/ha de K2O parcelado',
     'art' => '—', 'receita' => 'N/A', 'caderno' => 'Registrado', 'destino' => 'UE / Interno',
     'status' => 'Proposta', 'carencia' => '—'],
    ['id' => 'R-0905', 'data' => '22/08/2026', 'prod' => 'Fernanda Sá',        'talhao' => 'U-07', 'vari' => 'Arra 15',
     'prob' => 'Rachadura de baga · 12% em Arra 15',
     'rec' => 'Programa de cálcio + boro do chumbinho ao amolecimento; revisão de lâmina',
     'art' => '—', 'receita' => 'N/A', 'caderno' => '—', 'destino' => 'UE / RU',
     'status' => 'Proposta', 'carencia' => '—'],
    ['id' => 'R-0918', 'data' => '19/08/2026', 'prod' => 'Helena Vasconcelos', 'talhao' => 'U-09', 'vari' => 'Itália',
     'prob' => 'Zinco foliar 18 mg/kg (deficiente) · sintoma de folha pequena',
     'rec' => 'Sulfato de zinco foliar 0,3% em 2–3 aplicações a partir de 4–5 folhas expandidas',
     'art' => '—', 'receita' => 'N/A', 'caderno' => 'Registrado', 'destino' => 'Interno',
     'status' => 'Proposta', 'carencia' => '—'],
    ['id' => 'R-0916', 'data' => '11/08/2026', 'prod' => 'João Almeida',       'talhao' => 'U-02', 'vari' => 'Arra 15',
     'prob' => 'Boro foliar 24 mg/kg (deficiente) em plena floração',
     'rec' => 'Ácido bórico 0,1–0,2% em duas aplicações antes da antese + B via solo no ciclo seguinte',
     'art' => 'ART 2026-004102', 'receita' => 'Emitida', 'caderno' => 'Registrado', 'destino' => 'UE / EUA',
     'status' => 'Aplicada', 'carencia' => '—'],
    ['id' => 'R-0914', 'data' => '07/08/2026', 'prod' => 'Maria Oliveira',     'talhao' => 'M-02', 'vari' => 'Palmer',
     'prob' => 'Nitrogênio foliar 17,5 g/kg (excessivo) às vésperas da indução',
     'rec' => 'Suspender N até a florada; revisar ureia pós-colheita e reforçar o estresse hídrico',
     'art' => '—', 'receita' => 'N/A', 'caderno' => 'Registrado', 'destino' => 'UE',
     'status' => 'Aplicada', 'carencia' => '—'],
    ['id' => 'R-0911', 'data' => '29/07/2026', 'prod' => 'Carlos Mendes',      'talhao' => 'U-04', 'vari' => 'Timpson',
     'prob' => 'CE 1,68 dS/m e PST 11,4% — acúmulo de sais no bulbo molhado',
     'rec' => 'Fração de lixiviação, troca de KCl por K2SO4/KNO3, gesso agrícola e monitoramento mensal de CE',
     'art' => '—', 'receita' => 'N/A', 'caderno' => 'Pendente', 'destino' => 'UE / Interno',
     'status' => 'Proposta', 'carencia' => '—'],
    ['id' => 'R-0901', 'data' => '02/08/2026', 'prod' => 'João Almeida',       'talhao' => 'M-01', 'vari' => 'Palmer',
     'prob' => 'Indução floral 2026/27',
     'rec' => 'Paclobutrazol em solo úmido, 0,30 g i.a./m de copa (Palmer); janela 01–07/09',
     'art' => 'ART 2026-004077', 'receita' => 'A emitir', 'caderno' => 'Pendente', 'destino' => 'UE',
     'status' => 'Programada', 'carencia' => '—'],
];

$corReceita = ['Emitida' => 'green', 'Bloqueada' => 'red', 'Em revisão' => 'amber', 'A emitir' => 'amber', 'N/A' => 'grey'];
$corCaderno = ['Registrado' => 'green', 'Pendente' => 'amber', '—' => 'grey'];
$corStatus  = ['Aplicada' => 'green', 'Bloqueada' => 'red', 'Em revisão' => 'amber', 'Proposta' => 'blue', 'Programada' => 'teal'];

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'recomendacoes',
    'titulo' => 'Recomendações Técnicas',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" data-toast="Nova recomendação · demonstrativo">＋ Nova recomendação</button>',
]);
?>

<?php /* KPIs e callout-manifesto removidos a pedido do gestor 25/08 —
         a tela vai direto aos filtros e à lista de recomendações */ ?>
<div class="crm-chips">
  <span class="crm-chip on">Todos os status</span>
  <span class="crm-chip">Aplicada</span>
  <span class="crm-chip">Proposta</span>
  <span class="crm-chip">Bloqueada</span>
  <span class="crm-chip">Destino UE</span>
  <span class="crm-chip">Destino EUA</span>
  <span class="crm-chip">Mercado interno</span>
</div>

<div class="crm-card" style="padding:0;overflow:hidden">
  <div class="crm-card__head" style="padding:14px 18px 0;margin-bottom:10px">
    <span class="crm-card__title">Recomendações da carteira</span>
    <?= crm_pill(count($RECS) . ' registros', 'teal') ?>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>ID</th>
          <th>Produtor / talhão</th>
          <th>Problema identificado</th>
          <th>Recomendação</th>
          <th>Carência × colheita</th>
          <th>Receituário / ART</th>
          <th>Caderno</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($RECS as $r): ?>
        <tr>
          <td><strong><?= h($r['id']) ?></strong><div class="sub"><?= h($r['data']) ?></div></td>
          <td><strong><?= h($r['prod']) ?></strong><div class="sub"><?= crm_pill($r['talhao'], 'grey') ?> <?= h($r['vari']) ?></div></td>
          <td style="max-width:230px"><?= h($r['prob']) ?></td>
          <td style="max-width:250px"><?= h($r['rec']) ?></td>
          <td>
            <?php if ($r['carencia'] === '—'): ?>
              <?= crm_pill('n/a', 'grey') ?>
            <?php else: ?>
              <?= crm_pill($r['carencia'], $r['status'] === 'Bloqueada' ? 'red' : 'green') ?>
            <?php endif; ?>
          </td>
          <td><?= crm_pill($r['receita'], $corReceita[$r['receita']] ?? 'grey') ?><div class="sub"><?= h($r['art']) ?></div></td>
          <td><?= crm_pill($r['caderno'], $corCaderno[$r['caderno']] ?? 'grey') ?></td>
          <td><?= crm_pill($r['status'], $corStatus[$r['status']] ?? 'grey') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php /* cards "Bloqueios de conformidade" e "Do laudo à recomendação" removidos
         a pedido do gestor 25/08 — a tela é a lista de recomendações */ ?>
<?php crm_shell_end();
