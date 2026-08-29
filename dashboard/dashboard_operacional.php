<?php
/* ============================================================
   VERO — Dashboard Operacional  (tela real, leitura)
   Substitui o mock. Rota: /dashboard/dashboard_operacional.php
   Guard: dashboard.dashboard_operacional
   Pulso do campo: apontamentos, alertas abertos por categoria,
   estoque abaixo do mínimo/vencendo, colheita previsto × realizado
   e horas de irrigação dos últimos 30 dias.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$d30 = date('Y-m-d', strtotime('-30 days'));

/* ── P12 (auditoria 20/07): filtro FAZENDA por URL (mesmo padrão do executivo).
   Aplica ao pulso de campo (apontamentos, pessoas, MO, irrigação, MIP, alertas,
   colheita, últimos), pois o dado de campo é escopado por talhão→fazenda.
   Estoque fica tenant-wide (produto/lote não carregam fazenda) — rotulado no
   piloto. NÃO há filtro de SAFRA aqui: o pulso é uma janela de TEMPO (30d), não
   de safra; a colheita já aparece por safra no próprio card. O caminho SEM
   filtro ($fFaz=0) usa LEFT JOIN e mantém as contagens idênticas ao original. */
$fazendas = vero_rows(
    "SELECT id, nome FROM agro_fazendas WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => $t]);
$fFaz = (int)($_GET['fazenda'] ?? 0);
$pFaz = $fFaz ? [':fz' => $fFaz] : [];
/* condições reutilizáveis (vazias quando sem filtro → SQL idêntico ao anterior) */
$fjApont = $fFaz ? " LEFT JOIN agro_talhoes tl ON tl.id = a.talhao_id" : "";
$fcApont = $fFaz ? " AND tl.fazenda_id = :fz" : "";
$fjRh    = $fFaz ? " LEFT JOIN agro_apontamentos a ON a.id = pi.apontamento_id LEFT JOIN agro_talhoes tl ON tl.id = a.talhao_id" : "";
$fcRh    = $fFaz ? " AND tl.fazenda_id = :fz" : "";
$fjIrr   = $fFaz ? " LEFT JOIN agro_talhoes tl ON tl.id = ia.talhao_id" : "";
$fcIrr   = $fFaz ? " AND tl.fazenda_id = :fz" : "";
$fjMip   = $fFaz ? " LEFT JOIN agro_talhoes tl ON tl.id = mm.talhao_id" : "";
$fcMip   = $fFaz ? " AND tl.fazenda_id = :fz" : "";
$fcAlerta = $fFaz ? " AND fazenda_id = :fz" : "";

/* KPIs operacionais (últimos 30 dias) */
$apont30 = (int)vero_val(
    "SELECT COUNT(*) FROM agro_apontamentos a{$fjApont} WHERE a.tenant_id=:t AND a.data_apontamento >= :d{$fcApont}",
    [':t' => $t, ':d' => $d30] + $pFaz);
$pessoas30 = (int)vero_val(
    "SELECT COUNT(DISTINCT CONCAT(pi.origem_pessoa, ':', COALESCE(pi.operador_id, pi.terceirizado_id, 0))) FROM rh_producao_itens pi{$fjRh}
      WHERE pi.tenant_id=:t AND pi.data_trabalho >= :d{$fcRh}", [':t' => $t, ':d' => $d30] + $pFaz);
$irr30 = vero_row(
    "SELECT COALESCE(SUM(ia.horas),0) AS horas, COUNT(*) AS apontamentos
       FROM irrigacao_apontamentos ia{$fjIrr} WHERE ia.tenant_id=:t AND ia.data_apontamento >= :d{$fcIrr}",
    [':t' => $t, ':d' => $d30] + $pFaz);
$monit30 = (int)vero_val(
    "SELECT COUNT(*) FROM mip_monitoramentos mm{$fjMip} WHERE mm.tenant_id=:t AND mm.data_monitoramento >= :d{$fcMip}",
    [':t' => $t, ':d' => $d30] + $pFaz);

/* Alertas abertos por categoria */
$alertas = vero_rows(
    "SELECT categoria, COUNT(*) AS total, SUM(severidade='critico') AS criticos
       FROM agro_alertas WHERE tenant_id=:t AND status='aberto'{$fcAlerta}
      GROUP BY categoria ORDER BY criticos DESC, total DESC", [':t' => $t] + $pFaz);
$rotaAlerta = [
    'estoque'    => '/estoque/produtos',
    'mip'        => '/mip/alertas_fitossanitarios',
    'nutricao'   => '/nutricao/painel_nutrientes',
    'financeiro' => '/financeiro/index',
];

/* Estoque: abaixo do mínimo e lotes vencendo (30d) */
$estoqueMin = vero_rows(
    "SELECT p.codigo, p.nome, p.unidade, p.estoque_minimo,
            COALESCE((SELECT SUM(s.quantidade) FROM estoque_saldos s
                       WHERE s.tenant_id = p.tenant_id AND s.produto_id = p.id), 0) AS saldo
       FROM estoque_produtos p
      WHERE p.tenant_id = :t AND p.ativo = 1 AND p.estoque_minimo IS NOT NULL AND p.estoque_minimo > 0
     HAVING saldo < p.estoque_minimo
      ORDER BY saldo / p.estoque_minimo
      LIMIT 8", [':t' => $t]);
$lotesVencendo = vero_rows(
    "SELECT l.codigo_lote, l.validade, l.quantidade, p.codigo, p.nome, p.unidade
       FROM estoque_lotes l
       JOIN estoque_produtos p ON p.id = l.produto_id
      WHERE l.tenant_id = :t AND l.quantidade > 0 AND l.validade IS NOT NULL
        AND l.validade <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
      ORDER BY l.validade LIMIT 8", [':t' => $t]);

/* Colheita por safra ativa: previsto × realizado (P12: filtrável por fazenda
   via safra_talhão → talhão) */
$colheita = vero_rows(
    "SELECT sa.identificacao AS safra,
            COALESCE(SUM(cr.kg_total_previsto),0) AS previsto,
            COALESCE(SUM(cr.kg_total_realizado),0) AS realizado
       FROM colheita_registros cr
       JOIN agro_safras sa ON sa.id = cr.safra_id"
       . ($fFaz ? " LEFT JOIN agro_safra_talhoes st ON st.id = cr.safra_talhao_id LEFT JOIN agro_talhoes tl ON tl.id = st.talhao_id" : "") . "
      WHERE cr.tenant_id = :t" . ($fFaz ? " AND tl.fazenda_id = :fz" : "") . "
      GROUP BY sa.id, sa.identificacao ORDER BY sa.identificacao DESC LIMIT 5", [':t' => $t] + $pFaz);

/* Últimos apontamentos */
$ultimos = vero_rows(
    "SELECT a.data_apontamento, ta.nome AS atividade, tl.codigo AS talhao, fz.nome AS fazenda,
            (SELECT COUNT(*) FROM rh_producao_itens pi WHERE pi.tenant_id = a.tenant_id AND pi.apontamento_id = a.id) AS pessoas
       FROM agro_apontamentos a
       LEFT JOIN agro_tipos_atividade ta ON ta.id = a.tipo_atividade_id
       LEFT JOIN agro_talhoes tl ON tl.id = a.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
      WHERE a.tenant_id = :t" . ($fFaz ? " AND tl.fazenda_id = :fz" : "") . "
      ORDER BY a.data_apontamento DESC, a.id DESC LIMIT 8", [':t' => $t] + $pFaz);

$GUARD      = ['macro' => 'dashboard', 'micro' => 'dashboard_operacional'];
$PAGE_VIEW  = 'dashboard_dashboard_operacional';
$PAGE_TITLE = 'Dashboard Operacional';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');

/* ── Abas do Dashboard Operacional ──────────────────────────────────────────
   "Produtividade" (pulso do campo, default) · "Custo produtivo" (V-01/V-02,
   embutido de custeio/dashboard_safra_valvula.php). A aba de custo só aparece/
   renderiza para quem tem custos.custo_talhao — sem permissão, some e cai na
   Produtividade.
   IMPORTANTE: a página é o 2º filho do grid `.bios-shell` (320px 1fr). Se as abas
   fossem um <div> irmão do conteúdo, virariam um 3º item do grid (quebrava o
   layout) OU deslocavam o topbar. Por isso as abas viram a STRING $DOPS_TABS,
   renderizada DENTRO do container do próprio conteúdo (o piloto/embed dá echo
   em $DOPS_TABS no topo). Assim o conteúdo continua sendo o único filho do grid. */
$podeCusto = vero_can('custos.custo_talhao');
$aba = ($_GET['aba'] ?? '') === 'custo' ? 'custo' : 'pulso';
if ($aba === 'custo' && !$podeCusto) $aba = 'pulso';

ob_start(); ?>
<style>
.dops-tabbar{margin:0 0 16px}
.dops-tabs{display:inline-flex;gap:4px;border:1px solid var(--border,#E3D9C8);border-radius:12px;
  padding:4px;background:var(--warm,#FBF8F2)}
.dops-tab{padding:8px 16px;border-radius:9px;font-weight:600;font-size:13.5px;
  color:var(--muted,#8A7C68);text-decoration:none;white-space:nowrap;line-height:1.2}
.dops-tab.is-on{background:var(--accent-deep,#00363D);color:#fff}
.dops-tab:not(.is-on):hover{background:#fff;color:var(--accent-deep,#00363D)}
</style>
<div class="dops-tabbar">
  <nav class="dops-tabs" role="tablist" aria-label="Abas do dashboard operacional">
    <a class="dops-tab<?= $aba === 'pulso' ? ' is-on' : '' ?>" href="<?= $base ?>/dashboard/dashboard_operacional?aba=pulso"<?= $aba === 'pulso' ? ' aria-current="page"' : '' ?>>Produtividade</a>
    <?php if ($podeCusto): ?>
    <a class="dops-tab<?= $aba === 'custo' ? ' is-on' : '' ?>" href="<?= $base ?>/dashboard/dashboard_operacional?aba=custo"<?= $aba === 'custo' ? ' aria-current="page"' : '' ?>>Custo produtivo</a>
    <?php endif; ?>
  </nav>
</div>
<?php $DOPS_TABS = ob_get_clean();

/* Aba CUSTO: embute a tela de custo/margem por válvula (sem chrome próprio). */
if ($aba === 'custo') {
    $EMBED = true;
    require __DIR__ . '/../custeio/dashboard_safra_valvula.php';
    require __DIR__ . '/../includes/agro_footer_simple.php';
    return;
}

/* Aba PRODUTIVIDADE (pulso) — redesenho ECharts (A4-05, piloto aprovado) — DEFAULT.
   ?classico=1 = render antigo (escape reversível até auditoria A0). Reusa as
   variáveis acima; o redesenho não tem queries próprias. */
if (empty($_GET['classico'])) {
    require __DIR__ . '/_operacional_piloto.php';
    echo '</div><!-- /.dops-main -->';
    require __DIR__ . '/../includes/agro_footer_simple.php';
    return;
}
?>
<div class="vwrap">
  <?= $DOPS_TABS ?? '' ?>
  <?= vero_flash_html() ?>
  <?= vero_page_header('Dashboard Operacional', 'Pulso do campo nos últimos 30 dias — apontamentos, alertas, estoque e colheita', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Apontamentos (30d)</span>
        <strong class="vnum" style="font-size:1.25rem"><?= $apont30 ?></strong></div>
      <div class="vkpi"><span class="vhint">Pessoas em campo (30d)</span>
        <strong class="vnum" style="font-size:1.25rem"><?= $pessoas30 ?></strong></div>
      <div class="vkpi"><span class="vhint">Irrigação (30d)</span>
        <strong class="vnum" style="font-size:1.25rem"><?= numFmt((float)$irr30['horas'], 1) ?> h</strong>
        <span class="vhint"><?= (int)$irr30['apontamentos'] ?> apontamento(s)</span></div>
      <div class="vkpi"><span class="vhint">Monitoramentos MIP (30d)</span>
        <strong class="vnum" style="font-size:1.25rem"><?= $monit30 ?></strong></div>
      <div class="vkpi"><span class="vhint">Alertas abertos</span>
        <strong class="vnum" style="font-size:1.25rem;color:<?= $alertas ? '#b3261e' : 'var(--vero-ok,#1a7f4b)' ?>">
          <?= array_sum(array_map(static fn($a) => (int)$a['total'], $alertas)) ?></strong></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Alertas abertos por categoria</strong></div>
      <?php if (!$alertas): ?>
        <div class="vempty">Nenhum alerta aberto.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Categoria</th><th style="text-align:right">Abertos</th><th style="text-align:right">Críticos</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($alertas as $al): ?>
          <tr>
            <td><strong><?= h(ucfirst((string)$al['categoria'])) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= (int)$al['total'] ?></td>
            <td class="vnum" style="text-align:right;<?= (int)$al['criticos'] > 0 ? 'color:#b3261e;font-weight:700' : '' ?>"><?= (int)$al['criticos'] ?></td>
            <td style="text-align:right"><?php if (isset($rotaAlerta[(string)$al['categoria']])): ?>
              <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base . $rotaAlerta[(string)$al['categoria']] ?>">Abrir</a>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong>Colheita — previsto × realizado (kg)</strong></div>
      <?php if (!$colheita): ?>
        <div class="vempty">Nenhum registro de colheita.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Safra</th><th style="text-align:right">Previsto</th><th style="text-align:right">Realizado</th><th style="text-align:right">%</th></tr></thead>
        <tbody>
        <?php foreach ($colheita as $c):
            $pct = (float)$c['previsto'] > 0 ? (float)$c['realizado'] / (float)$c['previsto'] * 100 : null; ?>
          <tr>
            <td><strong><?= h(vero_safra_rotulo((string)$c['safra'])) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$c['previsto'], 0) ?></td>
            <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$c['realizado'], 0) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= $pct !== null ? numFmt($pct, 1) . '%' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Estoque abaixo do mínimo</strong>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/produtos.php">Produtos</a></div>
      <?php if (!$estoqueMin): ?>
        <div class="vempty">Nenhum produto abaixo do mínimo.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Produto</th><th style="text-align:right">Saldo</th><th style="text-align:right">Mínimo</th></tr></thead>
        <tbody>
        <?php foreach ($estoqueMin as $p): ?>
          <tr>
            <td><strong class="vnum"><?= h($p['codigo']) ?></strong> <?= h($p['nome']) ?></td>
            <td class="vnum" style="text-align:right;color:#b3261e"><strong><?= numFmt((float)$p['saldo'], 0) ?></strong>
              <span class="vhint"><?= h($p['unidade']) ?></span></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$p['estoque_minimo'], 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong>Lotes vencendo (30 dias)</strong>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/lotes.php">Lotes</a></div>
      <?php if (!$lotesVencendo): ?>
        <div class="vempty">Nenhum lote com vencimento próximo.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Lote</th><th>Produto</th><th style="text-align:right">Saldo</th><th>Validade</th></tr></thead>
        <tbody>
        <?php foreach ($lotesVencendo as $l):
            $vencido = $l['validade'] < date('Y-m-d'); ?>
          <tr>
            <td class="vnum"><strong><?= h($l['codigo_lote']) ?></strong></td>
            <td><?= h($l['nome']) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$l['quantidade'], 0) ?>
              <span class="vhint"><?= h($l['unidade']) ?></span></td>
            <td><span class="vbadge <?= $vencido ? 'vb-off' : 'vb-warn' ?>">
              <?= date('d/m/Y', strtotime((string)$l['validade'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Últimos apontamentos</strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/agro/apontamentos.php">Todos</a></div>
    <?php if (!$ultimos): ?>
      <div class="vempty">Nenhum apontamento registrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Data</th><th>Atividade</th><th>Válvula</th><th style="text-align:right">Pessoas</th></tr></thead>
      <tbody>
      <?php foreach ($ultimos as $u): ?>
        <tr>
          <td class="vnum"><strong><?= date('d/m/Y', strtotime((string)$u['data_apontamento'])) ?></strong></td>
          <td><?= h($u['atividade'] ?? '—') ?></td>
          <td><?= h(trim(($u['fazenda'] ?? '') . ' — ' . ($u['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$u['pessoas'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
