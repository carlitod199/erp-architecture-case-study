<?php
/* ============================================================
   VERO — Financeiro / Verificador do Razão (A3-T32 — FIN-01)
   Rota: /financeiro/verificador_razao.php (botão em Relatórios
   Financeiros; slug próprio = decisão da NAV — por ora herda o
   guard financeiro.relatorios_financeiros, que o CONTADOR tem).
   READ-ONLY: interface da MESMA verificação do CLI
   scripts/verificar_hash_razao.php (P-01: "verificador ao
   contador"): (1) ELO — hash_anterior de N == hash_atual de N−1;
   (2) CONTEÚDO — hash recomputado dos campos SELADOS == gravado.
   Baixa/estorno/cancelamento e campos DB-21 NÃO afetam o hash.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';

$t = vero_tenant();
$rows = vero_rows(
    "SELECT id, tipo, status, valor, data_competencia, data_vencimento, descricao,
            origem_tipo, origem_id, substituida_por_id, hash_anterior, hash_atual, created_at
       FROM movimentacoes_financeiras
      WHERE tenant_id = :t ORDER BY id", [':t' => $t]);

$anterior = null;
$okN = 0;
$problemas = [];
$selo = []; /* id => ok|elo|conteudo */
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $falha = null;
    if ((string)($r['hash_anterior'] ?? '') !== (string)($anterior ?? '')) {
        $falha = 'elo';
        $problemas[] = ['id' => $id, 'tipo' => 'ELO QUEBRADO',
            'detalhe' => 'hash_anterior=' . substr((string)($r['hash_anterior'] ?? 'NULL'), 0, 12)
                . '… ≠ esperado=' . substr((string)($anterior ?? 'NULL'), 0, 12) . '… (remoção física ou inserção fora de ordem)'];
    }
    $recalc = vero_srv_fin_hash([
        'tipo' => $r['tipo'], 'valor' => $r['valor'],
        'data_competencia' => $r['data_competencia'], 'data_vencimento' => $r['data_vencimento'],
        'descricao' => $r['descricao'], 'origem_tipo' => $r['origem_tipo'], 'origem_id' => $r['origem_id'],
    ], $r['hash_anterior'] !== null ? (string)$r['hash_anterior'] : null);
    if ($recalc !== (string)$r['hash_atual']) {
        $falha = $falha ?? 'conteudo';
        $problemas[] = ['id' => $id, 'tipo' => 'CONTEÚDO DIVERGE',
            'detalhe' => 'campo selado alterado após a criação (status=' . $r['status']
                . ', origem=' . ($r['origem_tipo'] ?? 'manual') . ')'];
    }
    if ($falha === null) $okN++;
    $selo[$id] = $falha ?? 'ok';
    $anterior = (string)$r['hash_atual'];
}
$integra = !$problemas;
$ultimas = array_slice($rows, -15);

$GUARD      = ['macro' => 'financeiro', 'micro' => 'relatorios_financeiros'];
$PAGE_VIEW  = 'financeiro_verificador_razao';
$PAGE_TITLE = 'Verificador do Razão';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Verificador do Razão (cadeia de integridade)',
      'Prova de integridade para o contador: cada lançamento é selado na criação e encadeado ao anterior — read-only', null) ?>

  <div class="vcard" style="margin-bottom:14px;<?= $integra ? '' : 'border-left:4px solid #b3261e' ?>">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;padding:14px">
      <div class="vkpi"><span class="vhint">Veredito</span>
        <strong style="font-size:1.2rem;color:<?= $integra ? '#1E6B34' : '#b3261e' ?>">
          <?= $integra ? '✓ CADEIA ÍNTEGRA' : '✗ ' . count($problemas) . ' PROBLEMA(S)' ?></strong>
        <span class="vhint">verificado agora, <?= date('d/m/Y H:i:s') ?></span></div>
      <div class="vkpi"><span class="vhint">Movimentações</span>
        <strong class="vnum" style="font-size:1.2rem"><?= count($rows) ?></strong></div>
      <div class="vkpi"><span class="vhint">Íntegras</span>
        <strong class="vnum" style="font-size:1.2rem;color:#1E6B34"><?= $okN ?></strong></div>
      <div class="vkpi"><span class="vhint">Elos / conteúdo</span>
        <strong class="vnum" style="font-size:1.2rem"><?= count(array_filter($problemas, fn($p) => $p['tipo'] === 'ELO QUEBRADO')) ?> / <?= count(array_filter($problemas, fn($p) => $p['tipo'] === 'CONTEÚDO DIVERGE')) ?></strong></div>
    </div>
    <div class="vhint" style="padding:0 14px 12px">
      O que o selo cobre: tipo, valor, competência, vencimento, descrição e origem — gravados no INSERT
      com SHA-256 encadeado por lançamento. Baixa, estorno e cancelamento lógico NÃO alteram o selo
      (ficam fora da fórmula por desenho); reemissão cancela e cria linha nova (DB-23).
      Mesma verificação do CLI <code>scripts/verificar_hash_razao.php</code> (exit 0/1 — agendável).
      <a class="vbtn vbtn-ghost vbtn-sm" href="">Reexecutar</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="javascript:window.print()">Imprimir</a>
    </div>
  </div>

  <?php if ($problemas): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Problemas encontrados</strong></div>
    <table class="vtable">
      <thead><tr><th>Lançamento</th><th>Tipo</th><th>Detalhe</th></tr></thead>
      <tbody>
      <?php foreach ($problemas as $p): ?>
        <tr><td class="vnum">#<?= (int)$p['id'] ?></td>
          <td><span class="vbadge vb-off"><?= h($p['tipo']) ?></span></td>
          <td><?= h($p['detalhe']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">Investigar antes de validar — divergência de conteúdo indica UPDATE em campo selado; elo quebrado indica remoção física ou inserção fora de ordem.</div>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Últimos lançamentos e seus selos</strong>
      <span class="vhint">amostra das <?= count($ultimas) ?> mais recentes; a verificação acima cobre TODAS</span></div>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>#</th><th>Criado em</th><th>Tipo</th><th>Descrição</th>
        <th style="text-align:right">Valor (R$)</th><th>Hash (início)</th><th>Selo</th></tr></thead>
      <tbody>
      <?php foreach (array_reverse($ultimas) as $r): ?>
        <tr>
          <td class="vnum"><?= (int)$r['id'] ?></td>
          <td class="vnum"><?= date('d/m/Y H:i', strtotime((string)$r['created_at'])) ?></td>
          <td><?= h((string)$r['tipo']) ?> <span class="vhint"><?= h((string)$r['status']) ?></span></td>
          <td><?= h(mb_substr((string)$r['descricao'], 0, 45)) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['valor'], 2) ?></td>
          <td class="vnum" style="font-size:.78rem"><?= h(substr((string)$r['hash_atual'], 0, 16)) ?>…</td>
          <td><?= $selo[(int)$r['id']] === 'ok'
                ? '<span class="vbadge vb-ok">✓ íntegra</span>'
                : '<span class="vbadge vb-off">✗ ' . h($selo[(int)$r['id']]) . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
