<?php
/* ============================================================
   VERO — Configurações / Unidades de Medida  (tela real, leitura)
   Substitui o mock. Rota: /configuracoes/unidades_medida.php
   Guard: configuracoes.unidades_medida
   Não há tabela própria de unidades — elas vivem nos cadastros
   (produtos, nutrientes, consumos). Esta tela consolida as
   unidades em uso por fonte, para padronização visual.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

$fontes = [
    ['rotulo' => 'Produtos de estoque', 'rota' => '/estoque/produtos',
     'sql' => "SELECT unidade AS u, COUNT(*) AS n FROM estoque_produtos
                WHERE tenant_id = :t AND unidade IS NOT NULL AND unidade <> '' GROUP BY unidade"],
    ['rotulo' => 'Nutrientes (unidade padrão)', 'rota' => '/nutricao/nutrientes',
     'sql' => "SELECT unidade_padrao AS u, COUNT(*) AS n FROM analise_nutrientes
                WHERE tenant_id = :t AND unidade_padrao IS NOT NULL AND unidade_padrao <> '' GROUP BY unidade_padrao"],
    ['rotulo' => 'Faixas nutricionais', 'rota' => '/nutricao/faixas_nutricionais',
     'sql' => "SELECT unidade AS u, COUNT(*) AS n FROM analise_faixas
                WHERE tenant_id = :t AND unidade IS NOT NULL AND unidade <> '' GROUP BY unidade"],
    ['rotulo' => 'Consumos de irrigação', 'rota' => '/irrigacao/consumo_agua',
     'sql' => "SELECT unidade AS u, COUNT(*) AS n FROM irrigacao_consumos
                WHERE tenant_id = :t AND unidade IS NOT NULL AND unidade <> '' GROUP BY unidade"],
    ['rotulo' => 'Produção/premiação (RH)', 'rota' => '/pessoas/premiacao',
     'sql' => "SELECT unidade AS u, COUNT(*) AS n FROM rh_producao_itens
                WHERE tenant_id = :t AND unidade IS NOT NULL AND unidade <> '' GROUP BY unidade"],
    ['rotulo' => 'Monitoramentos MIP', 'rota' => '/mip/monitoramento',
     'sql' => "SELECT unidade AS u, COUNT(*) AS n FROM mip_monitoramentos
                WHERE tenant_id = :t AND unidade IS NOT NULL AND unidade <> '' GROUP BY unidade"],
];

/* consolida por unidade × fonte */
$mapa = [];
foreach ($fontes as $idx => $f) {
    foreach (vero_rows($f['sql'], [':t' => $t]) as $r) {
        $u = trim((string)$r['u']);
        if ($u === '') continue;
        $mapa[$u]['fontes'][$idx] = (int)$r['n'];
        $mapa[$u]['total'] = ($mapa[$u]['total'] ?? 0) + (int)$r['n'];
    }
}
uksort($mapa, 'strcasecmp');

$GUARD      = ['macro' => 'configuracoes', 'micro' => 'unidades_medida'];
$PAGE_VIEW  = 'configuracoes_unidades_medida';
$PAGE_TITLE = 'Unidades de Medida';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Unidades de Medida', 'Unidades em uso nos cadastros — cada unidade é definida na própria tela de origem', null) ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Unidades em uso</strong>
      <span class="vsub"><?= count($mapa) ?> unidade(s) distinta(s)</span></div>
    <?php if (!$mapa): ?>
      <div class="vempty">Nenhuma unidade em uso ainda — elas aparecem conforme os cadastros são preenchidos.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th>Unidade</th>
        <?php foreach ($fontes as $f): ?>
          <th style="text-align:right"><?= h($f['rotulo']) ?></th>
        <?php endforeach; ?>
        <th style="text-align:right">Total de registros</th>
      </tr></thead>
      <tbody>
      <?php foreach ($mapa as $u => $d): ?>
        <tr>
          <td><strong class="vnum"><?= h((string)$u) ?></strong></td>
          <?php foreach ($fontes as $idx => $f): ?>
            <td class="vnum" style="text-align:right">
              <?php if (isset($d['fontes'][$idx])): ?>
                <a href="<?= $base . $f['rota'] ?>" style="text-decoration:none"><?= (int)$d['fontes'][$idx] ?></a>
              <?php else: ?>—<?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td class="vnum" style="text-align:right"><strong><?= (int)$d['total'] ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      Unidades divergentes para a mesma grandeza (ex.: "kg" e "Kg") indicam cadastros a padronizar —
      corrija na tela de origem clicando na contagem.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
