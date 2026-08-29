<?php
/* ============================================================
   VERO — Estoque / Auditoria de Estoque (A2-F2-17 / EST-024)
   Rota: /estoque/auditoria.php — tela de LEITURA (vigilância
   permanente de consistência, pré e pós-homologação).
   Guard: estoque.auditoria (slug criado pelo A0-16).
   12 checagens com severidade (crítico / atenção / baixa); cada
   checagem lista os achados e a tela abre com o placar geral.
   Nada é gravado — correções acontecem nas telas próprias
   (ajuste tipado, estorno, telas de origem).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_reserva.php';

$t = vero_tenant();
$checks = [];

/* C1 — Saldo consolidado ≠ Σ movimentações ativas (produto×almox) — CRÍTICO */
$checks[] = ['id' => 'C1', 'sev' => 'critico',
    'titulo' => 'Saldo ≠ soma das movimentações ativas',
    'desc' => 'estoque_saldos difere da soma de entradas − saídas ± ajustes não estornados no produto×almoxarifado (tolerância 0,001).',
    'cols' => ['Produto', 'Almoxarifado', 'Saldo consolidado', 'Σ movimentações', 'Diferença'],
    'rows' => vero_rows(
        "SELECT CONCAT(p.codigo, ' — ', p.nome) c1, COALESCE(a.nome,'—') c2,
                ROUND(s.quantidade,4) c3, ROUND(m.soma,4) c4, ROUND(s.quantidade - m.soma,4) c5
           FROM estoque_saldos s
           JOIN estoque_produtos p ON p.id = s.produto_id
           LEFT JOIN almoxarifados a ON a.id = s.almoxarifado_id
           JOIN (SELECT produto_id, almoxarifado_id,
                        SUM(CASE WHEN tipo='entrada' THEN quantidade
                                 WHEN tipo='saida' THEN -quantidade
                                 ELSE quantidade END) soma
                   FROM estoque_movimentacoes
                  WHERE tenant_id = :t1 AND estornado_em IS NULL
                  GROUP BY produto_id, almoxarifado_id) m
             ON m.produto_id = s.produto_id AND m.almoxarifado_id = s.almoxarifado_id
          WHERE s.tenant_id = :t2 AND ABS(s.quantidade - m.soma) > 0.001", [':t1' => $t, ':t2' => $t])];

/* C2 — Σ lotes ≠ saldo consolidado — CRÍTICO */
$checks[] = ['id' => 'C2', 'sev' => 'critico',
    'titulo' => 'Soma dos lotes ≠ saldo consolidado',
    'desc' => 'A soma dos lotes com saldo excede o saldo do produto×almoxarifado (lote fantasma) — a parcela "sem lote" (saldo > lotes) é normal em produtos sem rastreio.',
    'cols' => ['Produto', 'Almoxarifado', 'Σ lotes', 'Saldo', 'Excesso'],
    'rows' => vero_rows(
        "SELECT CONCAT(p.codigo, ' — ', p.nome) c1, COALESCE(a.nome,'—') c2,
                ROUND(l.soma,4) c3, ROUND(COALESCE(s.quantidade,0),4) c4,
                ROUND(l.soma - COALESCE(s.quantidade,0),4) c5
           FROM (SELECT produto_id, almoxarifado_id, SUM(quantidade) soma
                   FROM estoque_lotes WHERE tenant_id = :t1 AND quantidade > 0
                  GROUP BY produto_id, almoxarifado_id) l
           JOIN estoque_produtos p ON p.id = l.produto_id
           LEFT JOIN almoxarifados a ON a.id = l.almoxarifado_id
           LEFT JOIN estoque_saldos s ON s.tenant_id = :t2
                AND s.produto_id = l.produto_id AND s.almoxarifado_id = l.almoxarifado_id
          WHERE l.soma - COALESCE(s.quantidade,0) > 0.001", [':t1' => $t, ':t2' => $t])];

/* C3 — Saldo ou lote NEGATIVO — CRÍTICO */
$checks[] = ['id' => 'C3', 'sev' => 'critico',
    'titulo' => 'Saldo ou lote negativo',
    'desc' => 'Quantidade negativa em estoque_saldos ou estoque_lotes — nunca deveria acontecer (services validam).',
    'cols' => ['Onde', 'Produto', 'Almoxarifado/Lote', 'Quantidade'],
    'rows' => vero_rows(
        "SELECT 'saldo' c1, CONCAT(p.codigo, ' — ', p.nome) c2, COALESCE(a.nome,'—') c3, ROUND(s.quantidade,4) c4
           FROM estoque_saldos s JOIN estoque_produtos p ON p.id = s.produto_id
           LEFT JOIN almoxarifados a ON a.id = s.almoxarifado_id
          WHERE s.tenant_id = :t1 AND s.quantidade < -0.0001
          UNION ALL
         SELECT 'lote', CONCAT(p.codigo, ' — ', p.nome), l.codigo_lote, ROUND(l.quantidade,4)
           FROM estoque_lotes l JOIN estoque_produtos p ON p.id = l.produto_id
          WHERE l.tenant_id = :t2 AND l.quantidade < -0.0001", [':t1' => $t, ':t2' => $t])];

/* C4 — Transferência com par quebrado — CRÍTICO */
$checks[] = ['id' => 'C4', 'sev' => 'critico',
    'titulo' => 'Transferência com par quebrado',
    'desc' => 'Saída de transferência ativa sem entrada vinculada (mov_ref_id), ou com par estornado de um lado só.',
    'cols' => ['Mov (saída)', 'Data', 'Produto', 'Problema'],
    'rows' => vero_rows(
        "SELECT mv.id c1, DATE_FORMAT(mv.data_movimento,'%d/%m/%Y') c2,
                CONCAT(p.codigo, ' — ', p.nome) c3,
                CASE WHEN mv.mov_ref_id IS NULL THEN 'sem par vinculado'
                     WHEN e.id IS NULL THEN 'par não encontrado'
                     WHEN (mv.estornado_em IS NULL) <> (e.estornado_em IS NULL) THEN 'estornada de um lado só'
                END c4
           FROM estoque_movimentacoes mv
           JOIN estoque_produtos p ON p.id = mv.produto_id
           LEFT JOIN estoque_movimentacoes e ON e.tenant_id = mv.tenant_id AND e.id = mv.mov_ref_id
          WHERE mv.tenant_id = :t AND mv.origem_tipo = 'transferencia' AND mv.tipo = 'saida'
            AND (mv.mov_ref_id IS NULL OR e.id IS NULL
                 OR (mv.estornado_em IS NULL) <> (e.estornado_em IS NULL))", [':t' => $t])];

/* C5 — Duplicidade por origem — CRÍTICO */
$checks[] = ['id' => 'C5', 'sev' => 'critico',
    'titulo' => 'Possível dupla emissão pela mesma origem',
    'desc' => 'Duas ou mais movimentações ativas do mesmo tipo, produto e origem (origem_tipo+origem_id) — a reemissão pode ter falhado. Devoluções de campo ficam fora (parciais múltiplas da mesma saída são legítimas).',
    'cols' => ['Origem', 'Produto', 'Tipo', 'Movimentações', 'Σ Qtd'],
    'rows' => vero_rows(
        "SELECT CONCAT(mv.origem_tipo, ' #', mv.origem_id) c1,
                CONCAT(p.codigo, ' — ', p.nome) c2, mv.tipo c3,
                GROUP_CONCAT(mv.id ORDER BY mv.id) c4, ROUND(SUM(mv.quantidade),4) c5
           FROM estoque_movimentacoes mv
           JOIN estoque_produtos p ON p.id = mv.produto_id
          WHERE mv.tenant_id = :t AND mv.estornado_em IS NULL
            AND mv.origem_tipo IS NOT NULL AND mv.origem_id IS NOT NULL
            AND mv.origem_tipo NOT IN ('transferencia', 'devolucao_campo')
          GROUP BY mv.origem_tipo, mv.origem_id, mv.produto_id, mv.tipo, p.codigo, p.nome
         HAVING COUNT(*) > 1", [':t' => $t])];

/* C6 — Custeio órfão de movimentação estornada — CRÍTICO */
$checks[] = ['id' => 'C6', 'sev' => 'critico',
    'titulo' => 'Custeio órfão (movimentação estornada, custo mantido)',
    'desc' => 'Lançamento de custeio de origem apontamento_insumo/aplicacao/maquina_manutencao cuja movimentação de estoque correspondente foi estornada — o custo ficou sem o insumo.',
    'cols' => ['Custeio', 'Origem', 'Valor (R$)', 'Mov estornada'],
    'cols_rs' => [2], /* índice da coluna monetária (gating P-75) */
    'rows' => vero_rows(
        "SELECT cl.id c1, CONCAT(cl.origem_tipo, ' #', cl.origem_id) c2,
                ROUND(cl.valor,2) c3, mv.id c4
           FROM custeio_lancamentos cl
           JOIN estoque_movimentacoes mv ON mv.tenant_id = cl.tenant_id
                AND mv.origem_tipo = cl.origem_tipo AND mv.origem_id = cl.origem_id
          WHERE cl.tenant_id = :t AND cl.origem_tipo IN ('apontamento_insumo','aplicacao','maquina_manutencao')
            AND mv.estornado_em IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM estoque_movimentacoes m2
                             WHERE m2.tenant_id = cl.tenant_id AND m2.origem_tipo = cl.origem_tipo
                               AND m2.origem_id = cl.origem_id AND m2.estornado_em IS NULL)", [':t' => $t])];

/* C7 — Movimentação ativa sem origem — ATENÇÃO */
$checks[] = ['id' => 'C7', 'sev' => 'atencao',
    'titulo' => 'Movimentação ativa sem origem',
    'desc' => 'origem_tipo nulo em movimentação que não é contra-movimento de estorno — sem rastreabilidade do porquê.',
    'cols' => ['Mov', 'Data', 'Tipo', 'Produto', 'Qtd'],
    'rows' => vero_rows(
        "SELECT mv.id c1, DATE_FORMAT(mv.data_movimento,'%d/%m/%Y') c2, mv.tipo c3,
                CONCAT(p.codigo, ' — ', p.nome) c4, ROUND(mv.quantidade,4) c5
           FROM estoque_movimentacoes mv
           JOIN estoque_produtos p ON p.id = mv.produto_id
          WHERE mv.tenant_id = :t AND mv.origem_tipo IS NULL
            AND mv.mov_ref_id IS NULL AND mv.estornado_em IS NULL", [':t' => $t])];

/* C8 — Lote VENCIDO com saldo — ATENÇÃO */
$checks[] = ['id' => 'C8', 'sev' => 'atencao',
    'titulo' => 'Lote vencido com saldo disponível',
    'desc' => 'FEFO vai puxar esse lote primeiro — a saída exigirá a confirmação de vencido; avalie descarte via ajuste tipado.',
    'cols' => ['Produto', 'Lote', 'Almoxarifado', 'Qtd', 'Venceu em'],
    'rows' => vero_rows(
        "SELECT CONCAT(p.codigo, ' — ', p.nome) c1, l.codigo_lote c2, COALESCE(a.nome,'—') c3,
                ROUND(l.quantidade,4) c4, DATE_FORMAT(l.validade,'%d/%m/%Y') c5
           FROM estoque_lotes l
           JOIN estoque_produtos p ON p.id = l.produto_id
           LEFT JOIN almoxarifados a ON a.id = l.almoxarifado_id
          WHERE l.tenant_id = :t AND l.quantidade > 0
            AND l.validade IS NOT NULL AND l.validade < CURDATE()", [':t' => $t])];

/* C9 — Entrada ativa com custo zero — ATENÇÃO */
$checks[] = ['id' => 'C9', 'sev' => 'atencao',
    'titulo' => 'Entrada com custo unitário zero',
    'desc' => 'Entrada ativa a custo 0 dilui o custo médio ponderado — confirme se é doação/bonificação intencional.',
    'cols' => ['Mov', 'Data', 'Produto', 'Qtd', 'Origem'],
    'rows' => vero_rows(
        "SELECT mv.id c1, DATE_FORMAT(mv.data_movimento,'%d/%m/%Y') c2,
                CONCAT(p.codigo, ' — ', p.nome) c3, ROUND(mv.quantidade,4) c4,
                COALESCE(mv.origem_tipo,'—') c5
           FROM estoque_movimentacoes mv
           JOIN estoque_produtos p ON p.id = mv.produto_id
          WHERE mv.tenant_id = :t AND mv.tipo = 'entrada' AND mv.estornado_em IS NULL
            AND mv.quantidade > 0 AND COALESCE(mv.custo_unitario,0) <= 0", [':t' => $t])];

/* C10 — valor_total do saldo ≠ qtd × custo implícito — ATENÇÃO */
$checks[] = ['id' => 'C10', 'sev' => 'atencao',
    'titulo' => 'Valor do saldo inconsistente com quantidade',
    'desc' => 'Saldo com quantidade zero e valor residual (ou vice-versa) — resíduo de arredondamento acima de R$ 0,05 distorce o custo médio da próxima entrada.',
    'cols' => ['Produto', 'Almoxarifado', 'Qtd', 'Valor residual (R$)'],
    'cols_rs' => [3], /* índice da coluna monetária (gating P-75) */
    'rows' => vero_rows(
        "SELECT CONCAT(p.codigo, ' — ', p.nome) c1, COALESCE(a.nome,'—') c2,
                ROUND(s.quantidade,4) c3, ROUND(s.valor_total,2) c4
           FROM estoque_saldos s
           JOIN estoque_produtos p ON p.id = s.produto_id
           LEFT JOIN almoxarifados a ON a.id = s.almoxarifado_id
          WHERE s.tenant_id = :t
            AND ((ABS(s.quantidade) <= 0.0001 AND ABS(s.valor_total) > 0.05)
              OR (s.quantidade > 0.0001 AND s.valor_total < -0.0001))", [':t' => $t])];

/* C11 — Reserva planejada > saldo físico — ATENÇÃO (usa a derivação da F2-14) */
$reservas = estoque_reservas_por_produto();
$c11 = [];
if ($reservas) {
    $marks = [];
    $pR = [':t' => $t];
    $i = 0;
    foreach ($reservas as $pid => $q) { $marks[] = ":r{$i}"; $pR[":r{$i}"] = $pid; $i++; }
    $saldosR = [];
    foreach (vero_rows("SELECT produto_id, SUM(quantidade) q FROM estoque_saldos
                         WHERE tenant_id = :t AND produto_id IN (" . implode(',', $marks) . ")
                         GROUP BY produto_id", $pR) as $s) {
        $saldosR[(int)$s['produto_id']] = (float)$s['q'];
    }
    $nomes = [];
    foreach (vero_rows("SELECT id, codigo, nome, unidade FROM estoque_produtos
                         WHERE tenant_id = :t AND id IN (" . implode(',', $marks) . ")", $pR) as $n) {
        $nomes[(int)$n['id']] = $n;
    }
    foreach ($reservas as $pid => $resv) {
        $fisico = $saldosR[$pid] ?? 0.0;
        if ($resv > $fisico + 0.0001) {
            $nm = $nomes[$pid] ?? null;
            $c11[] = ['c1' => $nm ? $nm['codigo'] . ' — ' . $nm['nome'] : "#{$pid}",
                'c2' => numFmt($resv, 2) . ' ' . ($nm['unidade'] ?? ''),
                'c3' => numFmt($fisico, 2), 'c4' => numFmt($resv - $fisico, 2)];
        }
    }
}
$checks[] = ['id' => 'C11', 'sev' => 'atencao',
    'titulo' => 'Reserva planejada maior que o saldo físico',
    'desc' => 'Atividades planejadas/em execução preveem mais insumo do que existe — indicativo de compra necessária (reserva é orientativa, P-60).',
    'cols' => ['Produto', 'Reservado', 'Saldo físico', 'Falta'],
    'rows' => $c11];

/* C12 — Produto com saldo e cadastro incompleto p/ o fluxo — BAIXA */
$checks[] = ['id' => 'C12', 'sev' => 'baixa',
    'titulo' => 'Cadastro incompleto para o fluxo do produto',
    'desc' => 'Produto com saldo e: defensivo sem registro MAPA, perecível sem nenhum lote com validade, ou grupo inativo — completa-se no cadastro.',
    'cols' => ['Produto', 'Tipo', 'Pendência'],
    'rows' => vero_rows(
        "SELECT CONCAT(p.codigo, ' — ', p.nome) c1, COALESCE(p.tipo_insumo,'—') c2,
                TRIM(BOTH '; ' FROM CONCAT(
                  CASE WHEN p.tipo_insumo = 'defensivo' AND COALESCE(p.registro_mapa,'') = '' THEN 'defensivo sem registro MAPA; ' ELSE '' END,
                  CASE WHEN p.controla_validade = 1 AND NOT EXISTS (SELECT 1 FROM estoque_lotes l
                        WHERE l.tenant_id = p.tenant_id AND l.produto_id = p.id AND l.quantidade > 0 AND l.validade IS NOT NULL)
                       THEN 'perecível sem lote com validade; ' ELSE '' END,
                  CASE WHEN g.id IS NOT NULL AND g.ativo = 0 THEN 'grupo inativo; ' ELSE '' END)) c3
           FROM estoque_produtos p
           LEFT JOIN estoque_grupos g ON g.id = p.grupo_id
          WHERE p.tenant_id = :t AND p.ativo = 1
            AND EXISTS (SELECT 1 FROM estoque_saldos s WHERE s.tenant_id = p.tenant_id
                         AND s.produto_id = p.id AND s.quantidade > 0.0001)
         HAVING c3 <> ''", [':t' => $t])];

/* Gating de custo (condição da auditoria A0 na F2-17, proxy P-75): valores em
   R$ só para quem enxerga o financeiro — sem o proxy, a coluna monetária é
   MASCARADA (o achado continua visível; só o valor some). */
$veCusto = vero_can('financeiro.dre_agro.ver');
if (!$veCusto) {
    foreach ($checks as &$c) {
        if (empty($c['cols_rs']) || !$c['rows']) continue;
        foreach ($c['rows'] as &$row) {
            $vals = array_values($row);
            foreach ($c['cols_rs'] as $ix) { if (isset($vals[$ix])) $vals[$ix] = '•••'; }
            $row = $vals;
        }
        unset($row);
    }
    unset($c);
}

/* placar */
$placar = ['critico' => 0, 'atencao' => 0, 'baixa' => 0];
foreach ($checks as $c) { $placar[$c['sev']] += count($c['rows']); }
$totalAchados = array_sum($placar);

$GUARD      = ['macro' => 'estoque', 'micro' => 'auditoria']; /* slug do A0-16 ✓ */
$PAGE_VIEW  = 'estoque_auditoria';
$PAGE_TITLE = 'Auditoria de Estoque';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$SEV = ['critico' => ['Crítico', '#b3261e', '#F8E7E4'],
        'atencao' => ['Atenção', '#8A6D1A', '#FBF3E4'],
        'baixa'   => ['Baixa', '#6B5F53', '#F1EDE2']];
?>
<style>
.au-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(170px,100%),1fr));gap:12px;margin-bottom:14px}
.au-kpi{background:#fff;border:1px solid #E7E0D2;border-radius:12px;padding:13px 15px}
.au-kpi .l{font:600 10.5px 'IBM Plex Sans';text-transform:uppercase;letter-spacing:.05em;color:#8A7D6E}
.au-kpi .v{font:700 1.5rem 'IBM Plex Mono',monospace;margin-top:3px}
.au-check{margin-bottom:14px}
.au-check .vtoolbar strong{font-size:13.5px}
.au-ok{color:#1E6B34;font-weight:600;font-size:12.5px}
.au-wrap{overflow-x:auto}
.au-tag{display:inline-block;border-radius:20px;padding:2px 10px;font:600 11px 'IBM Plex Sans'}
</style>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Auditoria de Estoque',
        '12 checagens de consistência — saldos, lotes, pares, custeio e cadastro. Somente leitura: corrija nas telas próprias.', null) ?>


  <?php foreach ($checks as $c): [$sevLbl, $sevCor, $sevBg] = $SEV[$c['sev']]; $n = count($c['rows']); ?>
  <div class="vcard au-check">
    <div class="vtoolbar">
      <span class="au-tag" style="color:<?= $sevCor ?>;background:<?= $sevBg ?>"><?= $sevLbl ?></span>
      <strong><?= h($c['id']) ?> — <?= h($c['titulo']) ?></strong>
      <div style="flex:1"></div>
      <?= $n === 0 ? '<span class="au-ok">✔ nada encontrado</span>'
          : '<span class="vbadge vb-warn">' . $n . ' achado(s)</span>' ?>
    </div>
    <div class="vhint" style="padding:0 14px 10px"><?= h($c['desc']) ?></div>
    <?php if ($n > 0): ?>
    <div class="au-wrap">
      <table class="vtable">
        <thead><tr><?php foreach ($c['cols'] as $col): ?><th><?= h($col) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($c['rows'] as $row): ?>
          <tr><?php foreach (array_values($row) as $v): ?><td><?= h((string)($v ?? '—')) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
