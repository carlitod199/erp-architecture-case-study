<?php
/* ============================================================
   VERO — Configurações / Categorias  (tela real, leitura)
   Substitui o mock. Rota: /configuracoes/categorias.php
   Guard: configuracoes.categorias
   As categorias do VERO são estruturais (custeio) ou vivem em
   cadastros próprios (grupos de estoque, centros de custo,
   alvos MIP). Esta tela é o mapa delas, com uso e atalho.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

$custeio = vero_rows(
    "SELECT COALESCE(categoria,'outros') AS nome, COUNT(*) AS n, SUM(valor) AS total
       FROM custeio_lancamentos WHERE tenant_id = :t GROUP BY categoria ORDER BY total DESC", [':t' => $t]);

$grupos = vero_rows(
    "SELECT g.nome, (SELECT COUNT(*) FROM estoque_produtos p
                      WHERE p.tenant_id = g.tenant_id AND p.grupo_id = g.id) AS n
       FROM estoque_grupos g WHERE g.tenant_id = :t AND g.ativo = 1 ORDER BY g.nome", [':t' => $t]);

$centros = vero_rows(
    "SELECT CONCAT(codigo, ' — ', nome) AS nome,
            (SELECT COUNT(*) FROM custeio_lancamentos cl
              WHERE cl.tenant_id = c.tenant_id AND cl.centro_custo_id = c.id) AS n
       FROM centros_custo c WHERE c.tenant_id = :t AND c.ativo = 1 ORDER BY c.codigo", [':t' => $t]);

$alvos = vero_rows(
    "SELECT tipo AS nome, COUNT(*) AS n FROM mip_alvos
      WHERE tenant_id = :t AND ativo = 1 GROUP BY tipo ORDER BY tipo", [':t' => $t]);

$alertas = vero_rows(
    "SELECT categoria AS nome, COUNT(*) AS n FROM agro_alertas
      WHERE tenant_id = :t GROUP BY categoria ORDER BY categoria", [':t' => $t]);

$GUARD      = ['macro' => 'configuracoes', 'micro' => 'categorias'];
$PAGE_VIEW  = 'configuracoes_categorias';
$PAGE_TITLE = 'Categorias';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
$rotulo = static fn(string $c): string => ucfirst(str_replace('_', ' ', $c));

$blocos = [
    ['titulo' => 'Categorias de custeio', 'sub' => 'Fixas do sistema — nascem dos módulos de origem',
     'rota' => '/custeio/custo_categoria', 'acao' => 'Ver custos', 'linhas' => $custeio, 'moeda' => true],
    ['titulo' => 'Grupos de estoque', 'sub' => 'Cadastro próprio em Estoque → Grupos e Subgrupos',
     'rota' => '/estoque/grupos_subgrupos', 'acao' => 'Gerenciar', 'linhas' => $grupos, 'moeda' => false],
    ['titulo' => 'Centros de custo', 'sub' => 'Cadastro próprio em Financeiro → Centros de Custo',
     'rota' => '/financeiro/centros_custo', 'acao' => 'Gerenciar', 'linhas' => $centros, 'moeda' => false],
    ['titulo' => 'Tipos de alvo MIP', 'sub' => 'Derivados dos alvos de controle cadastrados',
     'rota' => '/mip/alvos_controle', 'acao' => 'Gerenciar', 'linhas' => $alvos, 'moeda' => false],
    ['titulo' => 'Categorias de alerta', 'sub' => 'Fixas do sistema — estoque, nutrição e MIP',
     'rota' => '/dashboard/indicadores_alertas', 'acao' => 'Ver alertas', 'linhas' => $alertas, 'moeda' => false],
];
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Categorias', 'Mapa das categorias do sistema — as editáveis têm cadastro próprio, com atalho aqui', null) ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
    <?php foreach ($blocos as $b): ?>
    <div class="vcard">
      <div class="vtoolbar">
        <div><strong><?= h($b['titulo']) ?></strong>
          <div class="vhint"><?= h($b['sub']) ?></div></div>
        <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base . $b['rota'] ?>"><?= h($b['acao']) ?></a>
      </div>
      <?php if (!$b['linhas']): ?>
        <div class="vempty">Nenhum registro.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Categoria</th><th style="text-align:right"><?= $b['moeda'] ? 'Lançamentos' : 'Registros' ?></th>
          <?php if ($b['moeda']): ?><th style="text-align:right">Total (R$)</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($b['linhas'] as $l): ?>
          <tr>
            <td><strong><?= h($rotulo((string)$l['nome'])) ?></strong></td>
            <td class="vnum" style="text-align:right"><?= (int)$l['n'] ?></td>
            <?php if ($b['moeda']): ?>
              <td class="vnum" style="text-align:right"><?= numFmt((float)$l['total'], 2) ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
