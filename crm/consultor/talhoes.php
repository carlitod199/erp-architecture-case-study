<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Talhões & Ciclos (fenologia da carteira)
   Rota: /crm/consultor/talhoes
   Fonte: docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.talhoes)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* Dados locais fiéis ao mockup — Vale do São Francisco (uva e manga).
   TODO mover para _mock.php */
$FENO_UVA   = ['Poda', 'Brotação', 'Cresc. veget.', 'Floração', 'Chumbinho', 'Amolecimento', 'Maturação', 'Colheita'];
$FENO_MANGA = ['Poda pós-colheita', 'Fluxo vegetativo', 'Indução (PBZ)', 'Estresse hídrico', 'Floração', 'Pegamento', 'Cresc. fruto', 'Colheita'];
/* Abreviações dos estágios para a barra compacta (como no mockup) */
$FENO_ABBR = [
    'Poda' => 'Poda', 'Brotação' => 'Brot', 'Cresc. veget.' => 'Veg', 'Floração' => 'Flor',
    'Chumbinho' => 'Chumb', 'Amolecimento' => 'Amol', 'Maturação' => 'Mat', 'Colheita' => 'Colh',
    'Poda pós-colheita' => 'Poda', 'Fluxo vegetativo' => 'Fluxo', 'Indução (PBZ)' => 'PBZ',
    'Estresse hídrico' => 'Estr', 'Pegamento' => 'Peg', 'Cresc. fruto' => 'Fruto',
];

$TALHOES = [
    ['cod' => 'U-01', 'propId' => 'F01', 'propNome' => 'Fazenda Boa Vista',     'prodNome' => 'João Almeida',       'cultura' => 'Uva',   'vari' => 'BRS Vitória',      'ha' => 18, 'feno' => 'uva',   'estagio' => 5, 'dias' => 78,  'colheita' => '12/10/2026', 'risco' => 'ok'],
    ['cod' => 'U-02', 'propId' => 'F01', 'propNome' => 'Fazenda Boa Vista',     'prodNome' => 'João Almeida',       'cultura' => 'Uva',   'vari' => 'Arra 15',          'ha' => 22, 'feno' => 'uva',   'estagio' => 3, 'dias' => 36,  'colheita' => '22/11/2026', 'risco' => 'atencao'],
    ['cod' => 'U-03', 'propId' => 'F01', 'propNome' => 'Fazenda Boa Vista',     'prodNome' => 'João Almeida',       'cultura' => 'Uva',   'vari' => 'Sweet Globe',      'ha' => 14, 'feno' => 'uva',   'estagio' => 6, 'dias' => 98,  'colheita' => '21/09/2026', 'risco' => 'bloqueio'],
    ['cod' => 'M-01', 'propId' => 'F07', 'propNome' => 'Fazenda Boa Vista II',  'prodNome' => 'João Almeida',       'cultura' => 'Manga', 'vari' => 'Palmer',           'ha' => 30, 'feno' => 'manga', 'estagio' => 2, 'dias' => 41,  'colheita' => '—',          'risco' => 'ok'],
    ['cod' => 'U-04', 'propId' => 'F02', 'propNome' => 'Fazenda Santa Helena',  'prodNome' => 'Carlos Mendes',      'cultura' => 'Uva',   'vari' => 'Timpson',          'ha' => 32, 'feno' => 'uva',   'estagio' => 4, 'dias' => 52,  'colheita' => '01/11/2026', 'risco' => 'atencao'],
    ['cod' => 'U-05', 'propId' => 'F02', 'propNome' => 'Fazenda Santa Helena',  'prodNome' => 'Carlos Mendes',      'cultura' => 'Uva',   'vari' => 'Crimson Seedless', 'ha' => 26, 'feno' => 'uva',   'estagio' => 2, 'dias' => 22,  'colheita' => '04/12/2026', 'risco' => 'ok'],
    ['cod' => 'M-02', 'propId' => 'F03', 'propNome' => 'Fazenda Vale Verde',    'prodNome' => 'Maria Oliveira',     'cultura' => 'Manga', 'vari' => 'Palmer',           'ha' => 20, 'feno' => 'manga', 'estagio' => 4, 'dias' => 96,  'colheita' => '—',          'risco' => 'ok'],
    ['cod' => 'M-03', 'propId' => 'F03', 'propNome' => 'Fazenda Vale Verde',    'prodNome' => 'Maria Oliveira',     'cultura' => 'Manga', 'vari' => 'Keitt',            'ha' => 18, 'feno' => 'manga', 'estagio' => 1, 'dias' => 14,  'colheita' => '—',          'risco' => 'ok'],
    ['cod' => 'U-06', 'propId' => 'F04', 'propNome' => 'Fazenda São José',      'prodNome' => 'Antônio Ribeiro',    'cultura' => 'Uva',   'vari' => 'Itália',           'ha' => 16, 'feno' => 'uva',   'estagio' => 5, 'dias' => 81,  'colheita' => '08/10/2026', 'risco' => 'atencao'],
    ['cod' => 'U-07', 'propId' => 'F05', 'propNome' => 'Fazenda Nova Aliança',  'prodNome' => 'Fernanda Sá',        'cultura' => 'Uva',   'vari' => 'Arra 15',          'ha' => 38, 'feno' => 'uva',   'estagio' => 5, 'dias' => 74,  'colheita' => '15/10/2026', 'risco' => 'bloqueio'],
    ['cod' => 'U-08', 'propId' => 'F05', 'propNome' => 'Fazenda Nova Aliança',  'prodNome' => 'Fernanda Sá',        'cultura' => 'Uva',   'vari' => 'Sugar Crisp',      'ha' => 30, 'feno' => 'uva',   'estagio' => 3, 'dias' => 33,  'colheita' => '25/11/2026', 'risco' => 'ok'],
    ['cod' => 'M-04', 'propId' => 'F06', 'propNome' => 'Fazenda Riacho Grande', 'prodNome' => 'Roberto Nakamura',   'cultura' => 'Manga', 'vari' => 'Kent',             'ha' => 33, 'feno' => 'manga', 'estagio' => 3, 'dias' => 62,  'colheita' => '—',          'risco' => 'ok'],
    ['cod' => 'U-09', 'propId' => 'F08', 'propNome' => 'Fazenda Bom Jesus',     'prodNome' => 'Helena Vasconcelos', 'cultura' => 'Uva',   'vari' => 'Itália',           'ha' => 12, 'feno' => 'uva',   'estagio' => 6, 'dias' => 104, 'colheita' => '15/09/2026', 'risco' => 'atencao'],
    ['cod' => 'M-05', 'propId' => 'F09', 'propNome' => 'Fazenda Serra Branca',  'prodNome' => 'José Bezerra',       'cultura' => 'Manga', 'vari' => 'Tommy Atkins',     'ha' => 20, 'feno' => 'manga', 'estagio' => 0, 'dias' => 6,   'colheita' => '—',          'risco' => 'ok'],
];

/* Barra de fenologia compacta (o mockup usa .feno.mini — recriada com tokens claros) */
$fenoMini = function (array $estagios, int $atual) use ($FENO_ABBR): string {
    $html = '<div class="crm-feno crm-feno--mini">';
    foreach ($estagios as $i => $nome) {
        $cls = $i < $atual ? ' done' : ($i === $atual ? ' now' : '');
        $html .= '<span class="crm-feno__st' . $cls . '" title="' . h($nome) . '">' . h($FENO_ABBR[$nome] ?? $nome) . '</span>';
    }
    return $html . '</div>';
};
$riscoPill = fn(string $r): string => $r === 'bloqueio'
    ? crm_pill('Carência', 'red')
    : ($r === 'atencao' ? crm_pill('Atenção', 'amber') : crm_pill('OK', 'green'));

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'talhoes',
    'titulo' => 'Talhões & Ciclos',
    'acoes'  => '<button type="button" class="vbtn" data-toast="Exportação gerada · demonstrativo">Exportar</button>',
]);
?>

<style>
/* Fenologia (.feno do mockup) — recriada com os tokens claros do VERO */
.crm-app .crm-feno { display: flex; gap: 3px; margin: 8px 0 2px; }
.crm-app .crm-feno__st {
  flex: 1; min-width: 0; text-align: center;
  font: 600 9.5px var(--num, 'IBM Plex Mono'); text-transform: uppercase; letter-spacing: .4px;
  color: var(--crm-ink3); padding: 6px 2px 4px; border-top: 3px solid var(--crm-line2);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.crm-app .crm-feno__st.done { border-top-color: var(--crm-teal); color: var(--crm-ink2); }
.crm-app .crm-feno__st.now {
  border-top-color: var(--crm-amber); color: var(--crm-ink); font-weight: 700;
  background: var(--crm-bg2); border-radius: 0 0 6px 6px;
}
.crm-app .crm-feno--mini { gap: 2px; margin: 0 0 3px; min-width: 320px; }
.crm-app .crm-feno--mini .crm-feno__st { font-size: 8.5px; letter-spacing: .2px; padding: 4px 1px 2px; }
</style>

<div class="crm-g4">
  <?= crm_kpi('Talhões monitorados', '14', '337 ha em 9 propriedades', 'teal') ?>
  <?= crm_kpi('Em janela de gatilho', '5', 'oportunidade fenológica aberta', 'amber') ?>
  <?= crm_kpi('Bloqueados por carência', '2', 'U-03 · U-09', 'red') ?>
  <?= crm_kpi('Colheitas nos próximos 45 d', '4', 'set/out 2026', 'blue') ?>
</div>

<?php /* callout "o estágio avança sozinho" removido a pedido do gestor 25/08 */ ?>
<?= crm_callout(
    '<strong>2 análises vencidas:</strong> U-03 foliar · M-05 solo. Sem laudo não há como calibrar o programa do ciclo.'
    . '<div style="margin-top:8px"><a class="vbtn vbtn-sm" href="' . crm_url('consultor', 'analises') . '">Ver pendências</a></div>',
    'amber'
) ?>

<!-- Filtros (estado visual — protótipo) -->
<div class="crm-chips">
  <span class="crm-chip on">Todos (14)</span>
  <span class="crm-chip">Uva (9)</span>
  <span class="crm-chip">Manga (5)</span>
  <span class="crm-chip">Em gatilho (5)</span>
  <span class="crm-chip">Bloqueados (2)</span>
</div>

<div class="crm-card">
  <div class="crm-card__head">
    <span class="crm-card__title">Fenologia da carteira · 14 registros</span>
    <?= crm_demo('projeção fenológica') ?>
  </div>
  <div class="crm-tblwrap">
    <table class="crm-tbl">
      <thead>
        <tr>
          <th>Talhão</th>
          <th>Propriedade</th>
          <th>Variedade</th>
          <th class="num">Área</th>
          <th style="min-width:330px">Estágio fenológico</th>
          <th class="num">Dias</th>
          <th>Colheita prev.</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($TALHOES as $t): $est = $t['feno'] === 'manga' ? $FENO_MANGA : $FENO_UVA; ?>
        <tr class="tap" data-href="<?= crm_url('consultor', 'propriedade') ?>?id=<?= h($t['propId']) ?>">
          <td><?= crm_pill($t['cod'], 'grey') ?></td>
          <td style="white-space:nowrap"><strong><?= h($t['propNome']) ?></strong><div class="sub"><?= h($t['prodNome']) ?></div></td>
          <td style="white-space:nowrap"><?= crm_pill($t['cultura'], $t['cultura'] === 'Manga' ? 'amber' : 'teal') ?><div class="sub"><?= h($t['vari']) ?></div></td>
          <td class="num" style="white-space:nowrap"><?= crm_num((float)$t['ha']) ?> ha</td>
          <td>
            <?= $fenoMini($est, (int)$t['estagio']) ?>
            <div class="sub"><?= h($est[$t['estagio']]) ?></div>
          </td>
          <td class="num"><?= (int)$t['dias'] ?></td>
          <td style="white-space:nowrap"><?= h($t['colheita']) ?></td>
          <td style="white-space:nowrap"><?= $riscoPill($t['risco']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php crm_shell_end();
