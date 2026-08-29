<?php
/* ============================================================
   VERO — Nutrição / Histórico Nutricional  (tela real, leitura)
   Substitui o mock. Rota: /nutricao/historico_nutricional.php
   Guard: nutricao.historico_nutricional
   Evolução das análises (solo + foliar) de um talhão no tempo;
   com nutriente selecionado vira série do nutriente com a
   classificação de cada coleta (D5: sem faixa = sem classificação).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const CLASSIF = [
    'muito_baixo' => ['Muito baixo', 'vb-off'],
    'baixo'       => ['Baixo', 'vb-warn'],
    'adequado'    => ['Adequado', 'vb-ok'],
    'alto'        => ['Alto', 'vb-warn'],
    'excessivo'   => ['Excessivo', 'vb-off'],
];

$fTalhao    = (int)($_GET['talhao'] ?? 0);
$fTipo      = in_array((string)($_GET['tipo'] ?? ''), ['solo', 'foliar'], true) ? (string)$_GET['tipo'] : '';
$fNutriente = (int)($_GET['nutriente'] ?? 0);

$t = vero_tenant();
$talhoes = vero_rows(
    "SELECT t.id, t.codigo, f.nome AS fazenda FROM agro_talhoes t
      LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
     WHERE t.tenant_id = :t ORDER BY f.nome, t.codigo", [':t' => $t]);
$nutrientes = vero_rows(
    "SELECT id, nome, simbolo FROM analise_nutrientes WHERE tenant_id = :t ORDER BY ordem, nome", [':t' => $t]);

/* União solo+foliar dos resultados do talhão */
$series = [];
if ($fTalhao > 0) {
    $sqlBase = static function (string $tipo) {
        $tabA = $tipo === 'solo' ? 'analise_solo' : 'analise_foliar';
        $tabR = $tabA . '_resultados';
        $extra = $tipo === 'solo' ? "a.profundidade AS contexto" : "a.parte_folha AS contexto";
        return "SELECT '{$tipo}' AS tipo, a.data_amostra, {$extra},
                       r.nutriente_id, r.valor, r.unidade, r.classificacao,
                       n.nome AS nutriente, n.simbolo
                  FROM {$tabR} r
                  JOIN {$tabA} a ON a.id = r.analise_id
                  LEFT JOIN analise_nutrientes n ON n.id = r.nutriente_id
                 WHERE r.tenant_id = :t AND a.talhao_id = :tal";
    };
    $params = [':t' => $t, ':tal' => $fTalhao];
    $filtroN = $fNutriente > 0 ? " AND r.nutriente_id = :n" : '';
    if ($fNutriente > 0) $params[':n'] = $fNutriente;

    $partes = [];
    if ($fTipo === '' || $fTipo === 'solo')   $partes[] = $sqlBase('solo') . $filtroN;
    if ($fTipo === '' || $fTipo === 'foliar') $partes[] = $sqlBase('foliar') . $filtroN;
    /* mesmo placeholder nas duas metades da UNION exige emulação — roda cada uma e junta em PHP */
    foreach ($partes as $sql) {
        foreach (vero_rows($sql . " ORDER BY a.data_amostra DESC", $params) as $r) $series[] = $r;
    }
    usort($series, static fn($a, $b) => strcmp((string)$b['data_amostra'], (string)$a['data_amostra']));
}

/* Para a série de um nutriente: máximo para a barra comparativa */
$maxValor = 0.0;
if ($fNutriente > 0) {
    foreach ($series as $s) if ((float)$s['valor'] > $maxValor) $maxValor = (float)$s['valor'];
}

$GUARD      = ['macro' => 'nutricao', 'micro' => 'historico_nutricional'];
$PAGE_VIEW  = 'nutricao_historico_nutricional';
$PAGE_TITLE = 'Histórico Nutricional';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Histórico Nutricional', 'Evolução das análises de solo e foliares por talhão — classificação conforme as faixas do RT', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <select name="talhao" onchange="this.form.submit()">
          <option value="">Selecione o talhão…</option>
          <?php foreach ($talhoes as $tl): ?>
            <option value="<?= (int)$tl['id'] ?>"<?= $fTalhao === (int)$tl['id'] ? ' selected' : '' ?>>
              <?= h(($tl['fazenda'] ? $tl['fazenda'] . ' — ' : '') . $tl['codigo']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <select name="tipo" onchange="this.form.submit()">
          <option value="">Solo + Foliar</option>
          <option value="solo"<?= $fTipo === 'solo' ? ' selected' : '' ?>>Só solo</option>
          <option value="foliar"<?= $fTipo === 'foliar' ? ' selected' : '' ?>>Só foliar</option>
        </select>
        <select name="nutriente" onchange="this.form.submit()">
          <option value="">Todos os nutrientes</option>
          <?php foreach ($nutrientes as $n): ?>
            <option value="<?= (int)$n['id'] ?>"<?= $fNutriente === (int)$n['id'] ? ' selected' : '' ?>>
              <?= h($n['simbolo'] ? $n['simbolo'] . ' — ' . $n['nome'] : $n['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= count($series) ?> resultado(s)</span>
    </div>

    <?php if ($fTalhao === 0): ?>
      <div class="vempty">Selecione um talhão para ver a evolução das análises.</div>
    <?php elseif (!$series): ?>
      <div class="vempty">Nenhum resultado de análise para este talhão no filtro.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Data da amostra</th><th>Tipo</th><th>Contexto</th><th>Nutriente</th>
        <th class="num">Valor</th>
        <th>Classificação</th>
        <?php if ($fNutriente > 0): ?><th style="width:22%">Comparativo</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($series as $s):
          $cl = $s['classificacao'] !== null ? (CLASSIF[(string)$s['classificacao']] ?? [ucfirst((string)$s['classificacao']), 'vb-info']) : null; ?>
        <tr>
          <td class="vnum"><strong><?= date('d/m/Y', strtotime((string)$s['data_amostra'])) ?></strong></td>
          <td><span class="vbadge <?= $s['tipo'] === 'solo' ? 'vb-info' : 'vb-ok' ?>"><?= $s['tipo'] === 'solo' ? 'Solo' : 'Foliar' ?></span></td>
          <td class="vhint"><?= h($s['contexto'] ?? '—') ?></td>
          <td><strong class="vnum"><?= h($s['simbolo'] ?? '') ?></strong> <?= h($s['nutriente'] ?? '—') ?></td>
          <td class="num"><strong><?= numFmt((float)$s['valor'], 2) ?></strong>
            <span class="vhint"><?= h($s['unidade'] ?? '') ?></span></td>
          <td><?= $cl ? '<span class="vbadge ' . $cl[1] . '">' . h($cl[0]) . '</span>'
                      : '<span class="vhint">sem faixa cadastrada</span>' ?></td>
          <?php if ($fNutriente > 0): $pct = $maxValor > 0 ? (float)$s['valor'] / $maxValor * 100 : 0; ?>
          <td><div style="height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
            <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
          </div></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      Classificações seguem as faixas cadastradas pelo responsável técnico (Nutrição → Faixas Nutricionais) —
      resultados sem faixa aplicável não são classificados pelo sistema.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
