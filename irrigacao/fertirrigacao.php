<?php
/* ============================================================
   VERO — Irrigação / Fertirrigação  (tela real, leitura)
   Substitui o mock. Rota: /irrigacao/fertirrigacao.php
   Guard: irrigacao.fertirrigacao
   Recorte das aplicações tipo fertirrigação (agro_aplicacoes) com
   itens/produtos. Regra 1: o sistema não recomenda produto nem
   dose — tudo definido e validado pelo responsável técnico.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$fIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : '';
$fFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : '';

$where  = "ap.tenant_id = :t AND ap.tipo = 'fertirrigacao'";
$params = [':t' => $t];
if ($fIni !== '') { $where .= " AND COALESCE(ap.data, ap.data_prevista) >= :i"; $params[':i'] = $fIni; }
if ($fFim !== '') { $where .= " AND COALESCE(ap.data, ap.data_prevista) <= :f"; $params[':f'] = $fFim; }

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
         WHERE i.tenant_id = :t AND i.aplicacao_id = :a ORDER BY i.id",
        [':t' => $t, ':a' => (int)$r['id']]);
}
unset($r);

$badgeStatus = static fn(string $s): string => match ($s) {
    'validada'   => '<span class="vbadge vb-ok">Validada</span>',
    'registrada' => '<span class="vbadge vb-info">Registrada</span>',
    'planejada'  => '<span class="vbadge vb-warn">Planejada</span>',
    'cancelada'  => '<span class="vbadge vb-off">Cancelada</span>',
    default      => '<span class="vbadge vb-warn">Rascunho</span>',
};

$GUARD      = ['macro' => 'irrigacao', 'micro' => 'fertirrigacao'];
$PAGE_VIEW  = 'irrigacao_fertirrigacao';
$PAGE_TITLE = 'Fertirrigação';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Fertirrigação', 'Aplicações via água de irrigação — produto e dose definidos pelo responsável técnico', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub"><?= count($rows) ?> aplicação(ões)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma fertirrigação registrada — as aplicações nascem no módulo de aplicações
        (MIP → Aplicações de Defensivos / Nutrição → Aplicações) com tipo fertirrigação.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <?php $podeImprimir = vero_can('mip.aplicacoes_defensivos.ver'); /* IF imprime pelo impresso DF/IF canônico */ ?>
      <thead><tr>
        <th>Data</th><th>Válvula</th><th>Safra</th><th>Produtos (dose informada pelo RT)</th>
        <th class="num">Custo (R$)</th><th>Status</th><?php if ($podeImprimir): ?><th class="num">Ações</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'cancelada' ? ' style="opacity:.55"' : '' ?>>
          <td class="vnum"><strong><?= ($d = $r['data'] ?? $r['data_prevista']) ? date('d/m/Y', strtotime((string)$d)) : '—' ?></strong></td>
          <td><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td><?= h($r['safra'] ?? '—') ?></td>
          <td>
            <?php if (!$r['itens']): ?><span class="vhint">sem itens</span>
            <?php else: foreach ($r['itens'] as $i): ?>
              <div><strong><?= h($i['produto'] ?? $i['ingrediente_ativo'] ?? 'Produto') ?></strong>
                <span class="vhint"><?= $i['dose_valor'] !== null ? numFmt((float)$i['dose_valor'], 2) . ' ' . h((string)($i['dose_unidade'] ?? '')) : '' ?>
                  <?= $i['quantidade_consumida'] !== null ? '· ' . numFmt((float)$i['quantidade_consumida'], 2) . ' ' . h((string)($i['quantidade_unidade'] ?? '')) : '' ?></span></div>
            <?php endforeach; endif; ?>
          </td>
          <td class="num"><?= $r['custo_total'] !== null ? numFmt((float)$r['custo_total'], 2) : '—' ?></td>
          <td><?= $badgeStatus((string)$r['status']) ?></td>
          <?php if ($podeImprimir): ?>
          <td class="num"><div class="vactions" style="justify-content:flex-end"><?= vero_btn_icone(vero_ico_imprimir(), 'Imprimir IF — documento de fertirrigação', "window.open('" . BIOS_BASE . "/mip/aplicacao_impressao?id=" . (int)$r['id'] . "','_blank')") ?></div></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
