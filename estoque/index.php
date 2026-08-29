<?php
/* ============================================================
   VERO — Estoque / Visão Geral  (tela real, leitura)
   Substitui o mock. Rota: /estoque/index.php  (destino do menu)
   Guard: estoque.produtos_insumos
   Landing do módulo: KPIs reconciliáveis com estoque_saldos,
   estoque por fazenda (via almoxarifados.fazenda_id), composição
   por grupo, lotes a vencer, alertas abertos e últimas
   movimentações. Filtros por fazenda e almoxarifado.
   Tarefa A2-F2-8 (rodada 1) — sem migração, somente leitura.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_reserva.php'; /* reserva derivada (A2-F2-14, P-60) */

$t = vero_tenant();

/* ---- filtros (GET) ---- */
$fFazenda = isset($_GET['fazenda']) ? (int)$_GET['fazenda'] : 0;
$fAlmox   = isset($_GET['almox']) ? (int)$_GET['almox'] : 0;

$fazendas = vero_rows("SELECT id, nome FROM agro_fazendas WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => $t]);
$almoxes  = vero_rows(
    "SELECT a.id, a.nome, a.fazenda_id FROM almoxarifados a
      WHERE a.tenant_id=:t AND a.ativo=1" . ($fFazenda ? " AND a.fazenda_id=:f" : "") . " ORDER BY a.nome",
    $fFazenda ? [':t' => $t, ':f' => $fFazenda] : [':t' => $t]);

/* almoxarifados que entram no recorte (filtro de fazenda e/ou almox) */
$almoxIds = array_map(static fn($a) => (int)$a['id'], $almoxes);
if ($fAlmox && in_array($fAlmox, $almoxIds, true)) $almoxIds = [$fAlmox];

$temRecorte = ($fFazenda || $fAlmox);
$inAlmox = '';
$pAlmox  = [':t' => $t];
if ($temRecorte) {
    if (!$almoxIds) $almoxIds = [0]; /* recorte vazio → nada */
    $marks = [];
    foreach ($almoxIds as $i => $id) { $marks[] = ":a$i"; $pAlmox[":a$i"] = $id; }
    $inAlmox = ' AND s.almoxarifado_id IN (' . implode(',', $marks) . ')';
}

/* ---- KPIs ---- */
$kpi = vero_row(
    "SELECT COALESCE(SUM(s.valor_total),0) AS valor,
            COUNT(DISTINCT CASE WHEN s.quantidade > 0 THEN s.produto_id END) AS skus
       FROM estoque_saldos s
       JOIN estoque_produtos p ON p.id = s.produto_id AND p.ativo = 1
      WHERE s.tenant_id = :t{$inAlmox}", $pAlmox);

$abaixoMin = (int)vero_val(
    "SELECT COUNT(*) FROM (
        SELECT p.id, COALESCE(SUM(s.quantidade),0) AS saldo, p.estoque_minimo
          FROM estoque_produtos p
          LEFT JOIN estoque_saldos s ON s.tenant_id = p.tenant_id AND s.produto_id = p.id{$inAlmox}
         WHERE p.tenant_id = :t AND p.ativo = 1 AND p.estoque_minimo > 0
         GROUP BY p.id, p.estoque_minimo
        HAVING COALESCE(SUM(s.quantidade),0) < p.estoque_minimo) x", $pAlmox);

$inAlmoxL = str_replace('s.almoxarifado_id', 'l.almoxarifado_id', $inAlmox);
$lotes3060 = vero_row(
    "SELECT SUM(l.validade <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS d30,
            SUM(l.validade <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)) AS d60,
            SUM(l.validade < CURDATE()) AS vencidos
       FROM estoque_lotes l
      WHERE l.tenant_id = :t AND l.quantidade > 0 AND l.validade IS NOT NULL{$inAlmoxL}", $pAlmox);

$alertasAbertos = (int)vero_val(
    "SELECT COUNT(*) FROM agro_alertas WHERE tenant_id = :t AND categoria = 'estoque' AND status = 'aberto'",
    [':t' => $t]);

$mov30 = (int)vero_val(
    "SELECT COUNT(*) FROM estoque_movimentacoes s
      WHERE s.tenant_id = :t AND s.data_movimento >= DATE_SUB(NOW(), INTERVAL 30 DAY){$inAlmox}", $pAlmox);

/* A2-F2-14: reserva ORIENTATIVA derivada (tenant inteiro) valorada ao custo médio */
$reservas = estoque_reservas_por_produto();
$reservaValor = 0.0;
if ($reservas) {
    $marks = [];
    $pR = [':t' => $t];
    $i = 0;
    foreach ($reservas as $pid => $q) { $marks[] = ":r{$i}"; $pR[":r{$i}"] = $pid; $i++; }
    foreach (vero_rows(
        "SELECT produto_id, COALESCE(SUM(valor_total),0) valor, COALESCE(SUM(quantidade),0) qtd
           FROM estoque_saldos WHERE tenant_id = :t AND produto_id IN (" . implode(',', $marks) . ")
          GROUP BY produto_id", $pR) as $s) {
        $cm = (float)$s['qtd'] > 0 ? (float)$s['valor'] / (float)$s['qtd'] : 0.0;
        $reservaValor += $reservas[(int)$s['produto_id']] * $cm;
    }
}

/* ---- estoque por fazenda (agregação via almoxarifados.fazenda_id) ---- */
$porFazenda = vero_rows(
    "SELECT COALESCE(f.nome, 'Sem fazenda vinculada') AS fazenda,
            COUNT(DISTINCT a.id) AS almoxarifados,
            COUNT(DISTINCT CASE WHEN s.quantidade > 0 THEN s.produto_id END) AS itens,
            COALESCE(SUM(s.valor_total),0) AS valor
       FROM almoxarifados a
       LEFT JOIN agro_fazendas f ON f.id = a.fazenda_id
       LEFT JOIN estoque_saldos s ON s.tenant_id = a.tenant_id AND s.almoxarifado_id = a.id
      WHERE a.tenant_id = :t AND a.ativo = 1
      GROUP BY f.id, f.nome
      ORDER BY valor DESC", [':t' => $t]);

/* ---- composição por grupo (no recorte) ---- */
$porGrupo = vero_rows(
    "SELECT g.nome AS grupo, COALESCE(SUM(s.valor_total),0) AS valor,
            COUNT(DISTINCT CASE WHEN s.quantidade > 0 THEN s.produto_id END) AS itens
       FROM estoque_saldos s
       JOIN estoque_produtos p ON p.id = s.produto_id AND p.ativo = 1
       JOIN estoque_grupos g ON g.id = p.grupo_id
      WHERE s.tenant_id = :t{$inAlmox}
      GROUP BY g.id, g.nome
     HAVING COALESCE(SUM(s.valor_total),0) > 0
         OR COUNT(DISTINCT CASE WHEN s.quantidade > 0 THEN s.produto_id END) > 0
      ORDER BY valor DESC LIMIT 10", $pAlmox);
$valorTotalGrupos = array_sum(array_map(static fn($g) => (float)$g['valor'], $porGrupo));

/* ---- lotes a vencer (60d, no recorte) ---- */
$lotesVencendo = vero_rows(
    "SELECT l.codigo_lote, l.validade, l.quantidade, p.codigo, p.nome, p.unidade, a.nome AS almox,
            DATEDIFF(l.validade, CURDATE()) AS dias
       FROM estoque_lotes l
       JOIN estoque_produtos p ON p.id = l.produto_id
       LEFT JOIN almoxarifados a ON a.id = l.almoxarifado_id
      WHERE l.tenant_id = :t AND l.quantidade > 0 AND l.validade IS NOT NULL
        AND l.validade <= DATE_ADD(CURDATE(), INTERVAL 60 DAY){$inAlmoxL}
      ORDER BY l.validade LIMIT 8", $pAlmox);

/* ---- itens abaixo do mínimo (no recorte) ---- */
$itensMin = vero_rows(
    "SELECT p.codigo, p.nome, p.unidade, p.estoque_minimo,
            COALESCE(SUM(s.quantidade),0) AS saldo
       FROM estoque_produtos p
       LEFT JOIN estoque_saldos s ON s.tenant_id = p.tenant_id AND s.produto_id = p.id{$inAlmox}
      WHERE p.tenant_id = :t AND p.ativo = 1 AND p.estoque_minimo > 0
      GROUP BY p.id, p.codigo, p.nome, p.unidade, p.estoque_minimo
     HAVING COALESCE(SUM(s.quantidade),0) < p.estoque_minimo
      ORDER BY COALESCE(SUM(s.quantidade),0) / p.estoque_minimo LIMIT 8", $pAlmox);

/* ---- últimas movimentações (no recorte) ---- */
$ultimasMov = vero_rows(
    "SELECT s.data_movimento, s.tipo, s.quantidade, s.valor_total, s.origem_tipo,
            s.estornado_em, s.mov_ref_id,
            p.codigo, p.nome, p.unidade, a.nome AS almox
       FROM estoque_movimentacoes s
       JOIN estoque_produtos p ON p.id = s.produto_id
       LEFT JOIN almoxarifados a ON a.id = s.almoxarifado_id
      WHERE s.tenant_id = :t{$inAlmox}
      ORDER BY s.data_movimento DESC, s.id DESC LIMIT 10", $pAlmox);

$badgeMov = static fn(string $tp): string => match ($tp) {
    'entrada' => '<span class="vbadge vb-ok">Entrada</span>',
    'saida'   => '<span class="vbadge vb-warn">Saída</span>',
    default   => '<span class="vbadge vb-info">' . h(ucfirst($tp)) . '</span>',
};

$GUARD      = ['macro' => 'estoque', 'micro' => 'produtos_insumos'];
$PAGE_VIEW  = 'estoque';
$PAGE_TITLE = 'Estoque — Visão Geral';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$qs = static function (array $extra) use ($fFazenda, $fAlmox): string {
    $q = array_filter(['fazenda' => $fFazenda, 'almox' => $fAlmox]);
    return '?' . http_build_query(array_merge($q, $extra));
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Estoque — Visão Geral',
        'Posição consolidada por fazenda e almoxarifado — valores reconciliáveis com os saldos', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:12px 14px">
      <div class="vfield"><label>Fazenda</label>
        <select name="fazenda" onchange="this.form.almox.value='';this.form.submit()">
          <option value="">Todas</option>
          <?php foreach ($fazendas as $f): ?>
            <option value="<?= (int)$f['id'] ?>"<?= $fFazenda === (int)$f['id'] ? ' selected' : '' ?>><?= h($f['nome']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="vfield"><label>Almoxarifado</label>
        <select name="almox" onchange="this.form.submit()">
          <option value="">Todos</option>
          <?php foreach ($almoxes as $a): ?>
            <option value="<?= (int)$a['id'] ?>"<?= $fAlmox === (int)$a['id'] ? ' selected' : '' ?>><?= h($a['nome']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <?php if ($temRecorte): ?><a class="vbtn vbtn-ghost vbtn-sm" href="index.php">Limpar filtros</a><?php endif; ?>
      <span style="flex:1"></span>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/produtos.php">Produtos</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/movimentacoes.php">Movimentações</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/lotes.php">Lotes</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/inventario.php">Inventário</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/transferencias.php">Transferências</a>
    </form>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Valor em estoque</span>
        <strong class="vnum" style="font-size:1.25rem">R$ <?= numFmt((float)$kpi['valor'], 2) ?></strong>
        <span class="vhint">custo médio ponderado</span></div>
      <div class="vkpi"><span class="vhint">Itens com saldo</span>
        <strong class="vnum" style="font-size:1.25rem"><?= (int)$kpi['skus'] ?></strong>
        <span class="vhint">produtos</span></div>
      <div class="vkpi"><span class="vhint">Abaixo do mínimo</span>
        <strong class="vnum" style="font-size:1.25rem;color:<?= $abaixoMin ? '#b3261e' : 'inherit' ?>"><?= $abaixoMin ?></strong>
        <span class="vhint">itens críticos</span></div>
      <div class="vkpi"><span class="vhint">Lotes vencendo</span>
        <strong class="vnum" style="font-size:1.25rem;color:<?= (int)($lotes3060['d30'] ?? 0) ? '#b3261e' : 'inherit' ?>">
          <?= (int)($lotes3060['d30'] ?? 0) ?> <span style="font-size:.8rem;font-weight:400">/30d</span></strong>
        <span class="vhint"><?= (int)($lotes3060['d60'] ?? 0) ?> em 60d · <?= (int)($lotes3060['vencidos'] ?? 0) ?> vencido(s)</span></div>
      <div class="vkpi"><span class="vhint">Alertas de estoque</span>
        <strong class="vnum" style="font-size:1.25rem;color:<?= $alertasAbertos ? '#b3261e' : 'inherit' ?>"><?= $alertasAbertos ?></strong>
        <span class="vhint"><a href="<?= $base ?>/estoque/alertas.php">ver fila</a></span></div>
      <div class="vkpi"><span class="vhint">Movimentações (30d)</span>
        <strong class="vnum" style="font-size:1.25rem"><?= $mov30 ?></strong></div>
      <div class="vkpi"><span class="vhint">Reservado p/ atividades</span>
        <strong class="vnum" style="font-size:1.25rem">R$ <?= numFmt($reservaValor, 2) ?></strong>
        <span class="vhint"><?= count($reservas) ?> produto(s) · orientativo</span></div>
      <div class="vkpi"><span class="vhint">Consistência</span>
        <span class="vhint" style="display:block;margin-top:6px"><a href="<?= $base ?>/estoque/auditoria.php">Auditoria de Estoque →</a></span></div>
    </div>
    <?php if ($temRecorte): ?>
      <div class="vhint" style="padding:0 14px 10px">KPIs no recorte selecionado (alertas de estoque são sempre do tenant inteiro).</div>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Estoque por fazenda</strong>
        <span class="vsub">via fazenda do almoxarifado — sempre o tenant inteiro</span></div>
      <?php if (!$porFazenda): ?>
        <div class="vempty">Nenhum almoxarifado cadastrado.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Fazenda</th><th style="text-align:right">Almox.</th>
          <th style="text-align:right">Itens</th><th style="text-align:right">Valor (R$)</th></tr></thead>
        <tbody>
        <?php foreach ($porFazenda as $r): ?>
          <tr>
            <td><strong><?= h($r['fazenda']) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= (int)$r['almoxarifados'] ?></td>
            <td class="vnum" style="text-align:right"><?= (int)$r['itens'] ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$r['valor'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong>Composição por grupo</strong>
        <span class="vsub">valor no recorte</span></div>
      <?php if (!$porGrupo): ?>
        <div class="vempty">Sem saldos no recorte.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Grupo</th><th style="text-align:right">Itens</th>
          <th style="text-align:right">Valor (R$)</th><th style="width:32%">%</th></tr></thead>
        <tbody>
        <?php foreach ($porGrupo as $g):
            $pct = $valorTotalGrupos > 0 ? (float)$g['valor'] / $valorTotalGrupos * 100 : 0; ?>
          <tr>
            <td><strong><?= h($g['grupo']) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= (int)$g['itens'] ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$g['valor'], 2) ?></td>
            <td><div style="background:var(--vero-soft,#eee);border-radius:4px;height:10px;overflow:hidden">
              <div style="width:<?= number_format(min(100, $pct), 1, '.', '') ?>%;height:10px;background:var(--vero-accent,#7a9b57)"></div></div>
              <span class="vhint"><?= numFmt($pct, 1) ?>%</span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Abaixo do estoque mínimo</strong>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/produtos.php?alerta=1">ver todos</a></div>
      <?php if (!$itensMin): ?>
        <div class="vempty">Nenhum item abaixo do mínimo no recorte.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Produto</th><th style="text-align:right">Saldo</th><th style="text-align:right">Mínimo</th></tr></thead>
        <tbody>
        <?php foreach ($itensMin as $r): ?>
          <tr>
            <td><strong class="vnum"><?= h($r['codigo']) ?></strong> <?= h($r['nome']) ?></td>
            <td class="vnum" style="text-align:right;color:#b3261e;font-weight:700">
              <?= numFmt((float)$r['saldo'], 2) ?> <span class="vhint"><?= h($r['unidade']) ?></span></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$r['estoque_minimo'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong>Lotes a vencer (60 dias)</strong>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/lotes.php?sit=vencendo">ver lotes</a></div>
      <?php if (!$lotesVencendo): ?>
        <div class="vempty">Nenhum lote com validade nos próximos 60 dias.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Produto</th><th>Lote</th><th style="text-align:right">Qtde</th><th>Validade</th></tr></thead>
        <tbody>
        <?php foreach ($lotesVencendo as $l): $dias = (int)$l['dias']; ?>
          <tr>
            <td><strong class="vnum"><?= h($l['codigo']) ?></strong> <?= h($l['nome']) ?>
              <span class="vhint"><?= h($l['almox'] ?? '') ?></span></td>
            <td class="vnum"><?= h($l['codigo_lote']) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$l['quantidade'], 2) ?>
              <span class="vhint"><?= h($l['unidade']) ?></span></td>
            <td><?php if ($dias < 0): ?><span class="vbadge vb-off">VENCIDO há <?= abs($dias) ?>d</span>
                <?php elseif ($dias <= 30): ?><span class="vbadge vb-warn">vence em <?= $dias ?>d</span>
                <?php else: ?><span class="vbadge vb-info"><?= date('d/m/Y', strtotime((string)$l['validade'])) ?></span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Últimas movimentações</strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/movimentacoes.php">histórico completo</a></div>
    <?php if (!$ultimasMov): ?>
      <div class="vempty">Nenhuma movimentação no recorte.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Data</th><th>Tipo</th><th>Produto</th><th>Almoxarifado</th>
        <th style="text-align:right">Qtde</th><th style="text-align:right">Valor (R$)</th><th>Origem</th></tr></thead>
      <tbody>
      <?php foreach ($ultimasMov as $m): ?>
        <tr<?= $m['estornado_em'] !== null ? ' style="opacity:.55"' : '' ?>>
          <td class="vnum"><?= date('d/m/Y H:i', strtotime((string)$m['data_movimento'])) ?></td>
          <td><?= $badgeMov((string)$m['tipo']) ?><?php
            if ($m['estornado_em'] !== null && $m['mov_ref_id'] !== null && $m['origem_tipo'] === null): ?>
              <span class="vbadge vb-off">estorno</span>
            <?php elseif ($m['estornado_em'] !== null): ?>
              <span class="vbadge vb-off">estornada</span>
            <?php endif; ?></td>
          <td><strong class="vnum"><?= h($m['codigo']) ?></strong> <?= h($m['nome']) ?></td>
          <td><?= h($m['almox'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$m['quantidade'], 2) ?>
            <span class="vhint"><?= h($m['unidade']) ?></span></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$m['valor_total'], 2) ?></td>
          <td><span class="vhint"><?= h((string)($m['origem_tipo'] ?? 'manual')) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
