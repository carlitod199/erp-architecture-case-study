<?php
/* ============================================================
   VERO — Nutrição / Aplicações Nutricionais  (tela real, leitura)
   Substitui o mock. Rota: /nutricao/aplicacoes_nutricionais.php
   Guard: nutricao.aplicacoes_nutricionais
   Recorte de agro_aplicacoes dos tipos nutricionais
   (fertirrigação, foliar, indutor) — o registro fica no núcleo
   de Aplicações (MIP → Aplicações).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const TIPOS_NUTRI = ['fertirrigacao' => 'Fertirrigação', 'foliar' => 'Adubação foliar', 'indutor_brotacao' => 'Indutor de brotação'];

$t = vero_tenant();
$fTipo = (string)($_GET['tipo'] ?? '');

$where  = "ap.tenant_id = :t AND ap.tipo IN ('fertirrigacao','foliar','indutor_brotacao')";
$params = [':t' => $t];
if (isset(TIPOS_NUTRI[$fTipo])) { $where .= " AND ap.tipo = :tp"; $params[':tp'] = $fTipo; }

$rows = vero_rows(
    "SELECT ap.*, tl.codigo AS talhao, fz.nome AS fazenda, sa.identificacao AS safra
       FROM agro_aplicacoes ap
       LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = COALESCE(ap.fazenda_id, tl.fazenda_id)
       LEFT JOIN agro_safras sa ON sa.id = ap.safra_id
      WHERE {$where}
      ORDER BY COALESCE(ap.data, ap.data_prevista) DESC, ap.id DESC LIMIT 100", $params);

foreach ($rows as &$r) {
    $r['itens'] = vero_rows(
        "SELECT i.*, p.nome AS produto FROM agro_aplicacao_itens i
          LEFT JOIN estoque_produtos p ON p.id = i.produto_id
         WHERE i.tenant_id = :t AND i.aplicacao_id = :a ORDER BY i.id", [':t' => $t, ':a' => (int)$r['id']]);
}
unset($r);
$totCusto = array_sum(array_map(static fn($r) => (float)($r['custo_total'] ?? 0), $rows));

$GUARD      = ['macro' => 'nutricao', 'micro' => 'aplicacoes_nutricionais'];
$PAGE_VIEW  = 'nutricao_aplicacoes_nutricionais';
$PAGE_TITLE = 'Aplicações Nutricionais';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$badgeStatus = static fn(string $s): string => match ($s) {
    'validada'   => '<span class="vbadge vb-ok">Validada</span>',
    'registrada' => '<span class="vbadge vb-warn">Aguardando RT</span>',
    'cancelada'  => '<span class="vbadge vb-off">Cancelada</span>',
    default      => '<span class="vbadge vb-info">' . h(ucfirst($s)) . '</span>',
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Aplicações Nutricionais', 'Fertirrigação, foliar e indutores — receita definida e validada pelo responsável técnico', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <select name="tipo" onchange="this.form.submit()">
          <option value="">Todos os tipos nutricionais</option>
          <?php foreach (TIPOS_NUTRI as $k => $rotulo): ?>
            <option value="<?= $k ?>"<?= $fTipo === $k ? ' selected' : '' ?>><?= h($rotulo) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= count($rows) ?> aplicação(ões) ·
        custo <strong class="vnum">R$ <?= numFmt($totCusto, 2) ?></strong> ·
        <a href="<?= $base ?>/mip/aplicacoes.php">registrar</a></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma aplicação nutricional — registre no núcleo de Aplicações escolhendo o tipo
        fertirrigação, foliar ou indutor.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Data</th><th>Tipo</th><th>Válvula</th><th>Safra</th>
        <th>Produtos (dose do RT)</th>
        <th class="num">Custo (R$)</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'cancelada' ? ' style="opacity:.55"' : '' ?>>
          <td class="vnum"><strong><?= ($d = $r['data'] ?? $r['data_prevista']) ? date('d/m/Y', strtotime((string)$d)) : '—' ?></strong></td>
          <td><span class="vbadge vb-info"><?= h(TIPOS_NUTRI[(string)$r['tipo']] ?? ucfirst((string)$r['tipo'])) ?></span></td>
          <td><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td><?= h($r['safra'] ?? '—') ?></td>
          <td>
            <?php if (!$r['itens']): ?><span class="vhint">sem itens</span>
            <?php else: foreach ($r['itens'] as $i): ?>
              <div><strong><?= h($i['produto'] ?? 'Produto') ?></strong>
                <span class="vhint"><?= $i['dose_valor'] !== null ? numFmt((float)$i['dose_valor'], 2) . ' ' . h((string)($i['dose_unidade'] ?? '')) : '' ?>
                  <?= $i['quantidade_consumida'] !== null ? '· ' . numFmt((float)$i['quantidade_consumida'], 2) . ' ' . h((string)($i['quantidade_unidade'] ?? '')) : '' ?></span></div>
            <?php endforeach; endif; ?>
          </td>
          <td class="num"><?= $r['custo_total'] !== null ? numFmt((float)$r['custo_total'], 2) : '—' ?></td>
          <td><?= $badgeStatus((string)$r['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
