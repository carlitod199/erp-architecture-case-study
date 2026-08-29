<?php
/* ============================================================
   VERO — Custos / Dashboard consolidado (A3, P-119)
   Rota: /custeio/dashboard_custos.php · Guard: custos.dashboard_custos
   Decisão 08/07 (P-119): consolida as telas de análise de custo
   (por válvula / fazenda / cultura / categoria / hectare) num ÚNICO
   painel com CORTE selecionável + filtros COMBINÁVEIS (safra,
   período, fazenda, cultura, válvula). Mesma fonte de todas as telas
   (custeio_lancamentos) — NÃO remove dados nem telas: consolida a
   APRESENTAÇÃO (as telas detalhadas seguem acessíveis, linkadas abaixo).
   Reusa a mecânica de matriz-de-categorias do _custo_dim_base.php.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

/* cortes disponíveis: rótulo SQL + se comporta R$/ha */
const CUSTO_CORTES = [
    'valvula'   => ['label' => 'Válvula (talhão)', 'sql' => "COALESCE(t.codigo,'(sem válvula)')",     'area' => true],
    'fazenda'   => ['label' => 'Fazenda',          'sql' => "COALESCE(f.nome,'(sem fazenda)')",        'area' => true],
    'safra'     => ['label' => 'Safra',            'sql' => "COALESCE(sf.identificacao,'(sem safra)')", 'area' => true],
    'cultura'   => ['label' => 'Cultura',          'sql' => "COALESCE(cu.nome,'(sem cultura)')",       'area' => true],
    'categoria' => ['label' => 'Categoria',        'sql' => "COALESCE(cl.categoria,'outros')",         'area' => false],
];
$corte = (string)($_GET['corte'] ?? 'valvula');
if (!isset(CUSTO_CORTES[$corte])) $corte = 'valvula';
$corteSql = CUSTO_CORTES[$corte]['sql'];

/* filtros combináveis */
$fSafra   = (int)($_GET['safra'] ?? 0);
$fFazenda = (int)($_GET['fazenda'] ?? 0);
$fCultura = (int)($_GET['cultura'] ?? 0);
$fTalhao  = (int)($_GET['talhao'] ?? 0);
$fProduto = (int)($_GET['produto'] ?? 0); /* P-17 */
$fIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';

$where  = "cl.tenant_id = :t";
$params = [':t' => $t];
if ($fSafra > 0)   { $where .= " AND cl.safra_id = :s";          $params[':s']  = $fSafra; }
if ($fFazenda > 0) { $where .= " AND f.id = :fz";                $params[':fz'] = $fFazenda; }
if ($fCultura > 0) { $where .= " AND cl.cultura_id = :cu";       $params[':cu'] = $fCultura; }
if ($fTalhao > 0)  { $where .= " AND cl.talhao_id = :tl";        $params[':tl'] = $fTalhao; }
if ($fIni !== '')  { $where .= " AND cl.data_competencia >= :i"; $params[':i']  = $fIni; }
if ($fFim !== '')  { $where .= " AND cl.data_competencia <= :f"; $params[':f']  = $fFim; }

$JOINS = "LEFT JOIN agro_talhoes t ON t.id = cl.talhao_id
          LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
          LEFT JOIN agro_culturas cu ON cu.id = cl.cultura_id
          LEFT JOIN agro_safras sf ON sf.id = cl.safra_id";

/* ── P-17: filtro por PRODUTO ─────────────────────────────────────────────
   custeio_lancamentos NÃO tem coluna de produto — o produto é derivado da
   origem do lançamento (mesma mecânica do dashboard_safra_valvula):
     • origem 'apontamento_insumo' → agro_apontamento_insumos.produto_id (1:1);
     • origem 'aplicacao'          → agro_aplicacao_itens.produto_id (N itens),
       com o valor do lançamento RATEADO ao produto por custo_total do item.
   O JOIN abaixo mantém só os lançamentos ligados ao produto e expõe prod_val
   (valor já rateado). As demais origens (mão de obra, máquinas, irrigação…)
   não têm produto e ficam de fora quando o filtro está ativo. R$/ha perde
   sentido nesse recorte e é ocultado (ver nota no rodapé). */
$valExpr  = 'cl.valor';
$prodJoin = '';
if ($fProduto > 0) {
    $prodJoin = "
      JOIN (
        SELECT cl2.id AS lanc_id,
               cl2.valor * CASE WHEN cl2.origem_tipo = 'apontamento_insumo' THEN 1
                                ELSE COALESCE(it.custo_total,0) / NULLIF(tot.soma,0) END AS prod_val
          FROM custeio_lancamentos cl2
          LEFT JOIN agro_apontamento_insumos ai
                 ON ai.id = cl2.origem_id AND ai.tenant_id = cl2.tenant_id
                AND cl2.origem_tipo = 'apontamento_insumo' AND ai.produto_id = :prodA
          /* multi-válvula: origem 'aplicacao_valvula' aponta a linha DB-29;
             av resolve a aplicação p/ amarrar os itens como na origem direta */
          LEFT JOIN agro_aplicacao_valvulas av2
                 ON av2.id = cl2.origem_id AND av2.tenant_id = cl2.tenant_id
                AND cl2.origem_tipo = 'aplicacao_valvula'
          LEFT JOIN agro_aplicacao_itens it
                 ON it.tenant_id = cl2.tenant_id AND it.produto_id = :prodB
                AND it.aplicacao_id = CASE cl2.origem_tipo
                                        WHEN 'aplicacao'         THEN cl2.origem_id
                                        WHEN 'aplicacao_valvula' THEN av2.aplicacao_id
                                      END
          LEFT JOIN (SELECT tenant_id, aplicacao_id, SUM(COALESCE(custo_total,0)) AS soma
                       FROM agro_aplicacao_itens GROUP BY tenant_id, aplicacao_id) tot
                 ON tot.tenant_id = cl2.tenant_id
                AND tot.aplicacao_id = CASE cl2.origem_tipo
                                         WHEN 'aplicacao'         THEN cl2.origem_id
                                         WHEN 'aplicacao_valvula' THEN av2.aplicacao_id
                                       END
         WHERE cl2.tenant_id = :prodT
           AND (ai.produto_id IS NOT NULL OR it.produto_id IS NOT NULL)
      ) pf ON pf.lanc_id = cl.id";
    $valExpr = 'pf.prod_val';
    $params[':prodA'] = $fProduto;
    $params[':prodB'] = $fProduto;
    $params[':prodT'] = $t;
}

/* categorias presentes (colunas dinâmicas da matriz) */
$categorias = array_map('strval', array_column(vero_rows(
    "SELECT DISTINCT COALESCE(cl.categoria,'outros') AS categoria
       FROM custeio_lancamentos cl {$JOINS}{$prodJoin} WHERE {$where} ORDER BY categoria", $params), 'categoria'));

/* matriz: dimensão × categoria */
$linhas = vero_rows(
    "SELECT {$corteSql} AS rotulo, COALESCE(cl.categoria,'outros') AS categoria, SUM({$valExpr}) AS total
       FROM custeio_lancamentos cl {$JOINS}{$prodJoin}
      WHERE {$where}
      GROUP BY rotulo, categoria", $params);

$porDim = [];
foreach ($linhas as $l) {
    $r = (string)$l['rotulo'];
    if (!isset($porDim[$r])) $porDim[$r] = ['cats' => [], 'total' => 0.0];
    $porDim[$r]['cats'][(string)$l['categoria']] = (float)$l['total'];
    $porDim[$r]['total'] += (float)$l['total'];
}
uasort($porDim, static fn($a, $b) => $b['total'] <=> $a['total']);
$totalGeral = array_sum(array_column($porDim, 'total'));
$nLanc = (int)vero_val("SELECT COUNT(*) FROM custeio_lancamentos cl {$JOINS}{$prodJoin} WHERE {$where}", $params);

/* ── Área por dimensão (só para cortes com área): de agro_safra_talhoes, a
   área plantada por válvula/fazenda/cultura. Sem safra filtrada, soma as
   inscrições de cada safra (área trabalhada por enrolamento) — mais fiel a
   R$/ha por safra; por isso R$/ha é mais preciso com uma safra selecionada. ── */
$areaPorDim = []; $areaTotal = 0.0;
if (CUSTO_CORTES[$corte]['area'] && $fProduto === 0) { /* P-17: R$/ha não se aplica ao recorte por produto */
    $corteArea = match ($corte) {
        'fazenda' => "COALESCE(f.nome,'(sem fazenda)')",
        'safra'   => "COALESCE(sf.identificacao,'(sem safra)')",
        'cultura' => "COALESCE(cu.nome,'(sem cultura)')",
        default   => "COALESCE(t.codigo,'(sem válvula)')",
    };
    $wArea = "st.tenant_id = :t";
    $pArea = [':t' => $t];
    if ($fSafra > 0)   { $wArea .= " AND st.safra_id = :s";    $pArea[':s']  = $fSafra; }
    if ($fFazenda > 0) { $wArea .= " AND f.id = :fz";          $pArea[':fz'] = $fFazenda; }
    if ($fCultura > 0) { $wArea .= " AND st.cultura_id = :cu"; $pArea[':cu'] = $fCultura; }
    if ($fTalhao > 0)  { $wArea .= " AND t.id = :tl";          $pArea[':tl'] = $fTalhao; }
    $rowsArea = vero_rows(
        "SELECT {$corteArea} AS rotulo, SUM(COALESCE(st.area_plantada_ha,0)) AS area
           FROM agro_safra_talhoes st
           JOIN agro_talhoes t ON t.id = st.talhao_id AND t.tenant_id = st.tenant_id
           LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
           LEFT JOIN agro_culturas cu ON cu.id = st.cultura_id
           LEFT JOIN agro_safras sf ON sf.id = st.safra_id
          WHERE {$wArea}
          GROUP BY rotulo", $pArea);
    foreach ($rowsArea as $ra) { $areaPorDim[(string)$ra['rotulo']] = (float)$ra['area']; $areaTotal += (float)$ra['area']; }
}

$safras    = vero_options('agro_safras', 'identificacao');
$safrasRot = vero_safra_rotulos($safras); /* P-04: id => rótulo curto (2026.2, sem "-NN") */
$fazendas  = vero_options('agro_fazendas', 'nome');
$culturas  = vero_options('agro_culturas', 'nome');
$talhoes   = vero_options('agro_talhoes', 'codigo');
$produtos  = vero_options('estoque_produtos', 'nome', 'ativo = 1'); /* P-17: filtro por produto */

/* Guard: reusa o slug existente 'custo_talhao' (Custo por Válvula) — quem já
   enxerga custo vê este consolidado; evita novo slug/re-seed (precedente
   verificador_razao/T32). Entrada de menu própria + slug dedicado ficam p/
   o A0/A4 na consolidação NAV-1 (esta tela É a consolidação pedida na P-119). */
$GUARD      = ['macro' => 'custos', 'micro' => 'custo_talhao'];
$PAGE_VIEW  = 'custos_dashboard';
$PAGE_TITLE = 'Dashboard de Custos';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$rotuloCat = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));
/* P-04: no corte por Safra, exibe o rótulo curto (2026.2) nas linhas da matriz */
$rotDim    = static fn(string $r): string => $corte === 'safra' ? vero_safra_rotulo($r) : $r;
$temArea   = CUSTO_CORTES[$corte]['area'] && $fProduto === 0;
$produtoNome = $fProduto > 0 ? ($produtos[$fProduto] ?? ('#' . $fProduto)) : '';
$mediaHa   = $areaTotal > 0 ? $totalGeral / $areaTotal : null;
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Dashboard de Custos', 'Custos por dimensão — escolha e combine os filtros.', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <form method="get" class="vtoolbar" style="flex-wrap:wrap;gap:8px;align-items:center">
      <label class="vhint">Corte:</label>
      <select name="corte" onchange="this.form.submit()">
        <?php foreach (CUSTO_CORTES as $k => $c): ?>
          <option value="<?= $k ?>"<?= $corte === $k ? ' selected' : '' ?>><?= h($c['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <span style="width:1px;height:22px;background:#E3D9C8"></span>
      <select name="safra" onchange="this.form.submit()">
        <option value="">Todas as safras</option>
        <?php foreach ($safras as $sid => $sn): ?><option value="<?= $sid ?>"<?= $fSafra === $sid ? ' selected' : '' ?>><?= h($safrasRot[$sid] ?? $sn) ?></option><?php endforeach; ?>
      </select>
      <select name="fazenda" onchange="this.form.submit()">
        <option value="">Todas as fazendas</option>
        <?php foreach ($fazendas as $id => $n): ?><option value="<?= $id ?>"<?= $fFazenda === $id ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?>
      </select>
      <select name="cultura" onchange="this.form.submit()">
        <option value="">Todas as culturas</option>
        <?php foreach ($culturas as $id => $n): ?><option value="<?= $id ?>"<?= $fCultura === $id ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?>
      </select>
      <select name="talhao" onchange="this.form.submit()">
        <option value="">Todas as válvulas</option>
        <?php foreach ($talhoes as $id => $n): ?><option value="<?= $id ?>"<?= $fTalhao === $id ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?>
      </select>
      <select name="produto" onchange="this.form.submit()" title="P-17: custo detalhado por produto (insumo/aplicação)">
        <option value="">Todos os produtos</option>
        <?php foreach ($produtos as $id => $n): ?><option value="<?= $id ?>"<?= $fProduto === $id ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?>
      </select>
      <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()" title="Competência de">
      <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()" title="Competência até">
      <?php if ($fSafra || $fFazenda || $fCultura || $fTalhao || $fProduto || $fIni || $fFim): ?>
        <a class="vbtn vbtn-ghost vbtn-sm" href="?corte=<?= h($corte) ?>">Limpar filtros</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="vkpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:14px">
    <div class="vkpi"><div class="vkpi-l">Custo total</div><div class="vkpi-v vnum">R$ <?= numFmt($totalGeral, 2) ?></div></div>
    <div class="vkpi"><div class="vkpi-l">Lançamentos</div><div class="vkpi-v vnum"><?= numFmt((float)$nLanc, 0) ?></div></div>
    <?php if ($temArea): ?>
    <div class="vkpi"><div class="vkpi-l">Área</div><div class="vkpi-v vnum"><?= numFmt($areaTotal, 2) ?> ha</div></div>
    <div class="vkpi"><div class="vkpi-l">Custo médio /ha</div><div class="vkpi-v vnum"><?= $mediaHa !== null ? 'R$ ' . numFmt($mediaHa, 2) : '—' ?></div></div>
    <?php endif; ?>
    <div class="vkpi"><div class="vkpi-l">Dimensões (<?= h(CUSTO_CORTES[$corte]['label']) ?>)</div><div class="vkpi-v vnum"><?= count($porDim) ?></div></div>
  </div>

  <div class="vcard">
    <?php if (!$porDim): ?>
      <div class="vempty">Nenhum lançamento de custeio no filtro.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th><?= h(CUSTO_CORTES[$corte]['label']) ?></th>
        <?php foreach ($categorias as $cat): ?><th style="text-align:right"><?= h($rotuloCat($cat)) ?></th><?php endforeach; ?>
        <th style="text-align:right">Total (R$)</th>
        <?php if ($temArea): ?><th style="text-align:right">Área (ha)</th><th style="text-align:right">R$/ha</th><?php endif; ?>
        <th style="width:18%">Participação</th>
      </tr></thead>
      <tbody>
      <?php foreach ($porDim as $rotulo => $d):
          $pct  = $totalGeral > 0 ? $d['total'] / $totalGeral * 100 : 0;
          $area = $areaPorDim[(string)$rotulo] ?? null;
          $porHa = ($area !== null && $area > 0) ? $d['total'] / $area : null; ?>
        <tr>
          <td><strong><?= h($rotDim((string)$rotulo)) ?></strong></td>
          <?php foreach ($categorias as $cat): ?>
            <td class="vnum" style="text-align:right"><?= isset($d['cats'][$cat]) ? numFmt($d['cats'][$cat], 2) : '—' ?></td>
          <?php endforeach; ?>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($d['total'], 2) ?></strong></td>
          <?php if ($temArea): ?>
            <td class="vnum" style="text-align:right"><?= $area !== null && $area > 0 ? numFmt($area, 2) : '—' ?></td>
            <td class="vnum" style="text-align:right"><?= $porHa !== null ? numFmt($porHa, 2) : '—' ?></td>
          <?php endif; ?>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:10px;background:#F2EDE2;border-radius:5px;overflow:hidden">
                <div style="height:100%;width:<?= number_format($pct, 1, '.', '') ?>%;background:#005059;border-radius:5px"></div>
              </div>
              <span class="vnum vhint"><?= numFmt($pct, 1) ?>%</span>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <tr style="border-top:2px solid #E3D9C8">
        <td><strong>Total</strong></td>
        <?php foreach ($categorias as $cat):
            $somaCat = array_sum(array_map(static fn($d) => $d['cats'][$cat] ?? 0.0, $porDim)); ?>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($somaCat, 2) ?></strong></td>
        <?php endforeach; ?>
        <td class="vnum" style="text-align:right"><strong><?= numFmt($totalGeral, 2) ?></strong></td>
        <?php if ($temArea): ?>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($areaTotal, 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><strong><?= $mediaHa !== null ? numFmt($mediaHa, 2) : '—' ?></strong></td>
        <?php endif; ?>
        <td></td>
      </tr>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php if ($fProduto > 0): ?>
    <div class="vhint" style="padding:10px 14px;color:#7A5410">
      Recorte pelo produto <strong><?= h($produtoNome) ?></strong>: mostra só os custos derivados de
      insumo/aplicação desse produto (o valor das aplicações com vários itens é rateado por
      <code>custo_total</code>). Mão de obra, máquinas e irrigação não têm produto e ficam de fora;
      por isso <strong>R$/ha não é exibido</strong> neste recorte.
    </div>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      Consolida a <strong>apresentação</strong> das telas de análise (por válvula, fazenda, cultura,
      categoria e hectare) — a mesma fonte (<code>custeio_lancamentos</code>), com o corte e os filtros
      escolhidos aqui. As telas detalhadas seguem disponíveis:
      <a href="dashboard_safra_valvula.php">Custo × margem por válvula (V-01/V-02)</a> ·
      <a href="custos.php">Por válvula</a> ·
      <a href="custo_categoria.php">Por categoria</a> ·
      <a href="custo_hectare.php">Por hectare</a> ·
      <a href="comparativo_safras.php">Comparativo entre safras</a> ·
      <a href="resultado_safra.php">Resultado da safra</a>.
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
