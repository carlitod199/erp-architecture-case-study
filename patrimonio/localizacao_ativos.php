<?php
/* ============================================================
   VERO — Patrimônio / Localização de Ativos  (tela real, leitura)
   Substitui o mock. Rota: /patrimonio/localizacao_ativos.php
   Guard: patrimonio.localizacao_ativos
   Onde está cada bem: máquinas, veículos e implementos por
   fazenda + ativos patrimoniais (via máquina vinculada ou sem
   localização definida).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

$itens = [];

foreach (vero_rows(
    "SELECT m.nome, m.tipo, m.status, f.nome AS fazenda FROM maquinas m
      LEFT JOIN agro_fazendas f ON f.id = m.fazenda_id
     WHERE m.tenant_id = :t AND m.ativo = 1", [':t' => $t]) as $r) {
    $itens[] = ['fazenda' => $r['fazenda'], 'classe' => 'Máquina',
        'nome' => $r['nome'], 'detalhe' => trim(($r['tipo'] ?? '') . ($r['status'] ? ' · ' . $r['status'] : ''), ' ·')];
}
foreach (vero_rows(
    "SELECT v.placa, v.modelo, f.nome AS fazenda FROM veiculos v
      LEFT JOIN agro_fazendas f ON f.id = v.fazenda_id
     WHERE v.tenant_id = :t AND v.ativo = 1", [':t' => $t]) as $r) {
    $itens[] = ['fazenda' => $r['fazenda'], 'classe' => 'Veículo',
        'nome' => (string)($r['modelo'] ?? 'Veículo'), 'detalhe' => (string)($r['placa'] ?? '')];
}
foreach (vero_rows(
    "SELECT i.nome, i.tipo, f.nome AS fazenda FROM implementos i
      LEFT JOIN agro_fazendas f ON f.id = i.fazenda_id
     WHERE i.tenant_id = :t AND i.ativo = 1", [':t' => $t]) as $r) {
    $itens[] = ['fazenda' => $r['fazenda'], 'classe' => 'Implemento',
        'nome' => $r['nome'], 'detalhe' => (string)($r['tipo'] ?? '')];
}
foreach (vero_rows(
    "SELECT a.descricao, c.nome AS categoria, f.nome AS fazenda
       FROM patrimonio_ativos a
       LEFT JOIN patrimonio_categorias c ON c.id = a.categoria_id
       LEFT JOIN maquinas m ON m.id = a.maquina_id
       LEFT JOIN agro_fazendas f ON f.id = m.fazenda_id
      WHERE a.tenant_id = :t AND a.ativo = 1", [':t' => $t]) as $r) {
    $itens[] = ['fazenda' => $r['fazenda'], 'classe' => 'Ativo patrimonial',
        'nome' => $r['descricao'], 'detalhe' => (string)($r['categoria'] ?? '')];
}

/* agrupa por fazenda */
$porFazenda = [];
foreach ($itens as $i) {
    $fz = $i['fazenda'] !== null ? (string)$i['fazenda'] : 'Sem localização definida';
    $porFazenda[$fz][] = $i;
}
ksort($porFazenda);
/* "Sem localização" por último */
if (isset($porFazenda['Sem localização definida'])) {
    $sem = $porFazenda['Sem localização definida'];
    unset($porFazenda['Sem localização definida']);
    $porFazenda['Sem localização definida'] = $sem;
}

$badgeClasse = static fn(string $c): string => match ($c) {
    'Máquina'    => '<span class="vbadge vb-info">Máquina</span>',
    'Veículo'    => '<span class="vbadge vb-ok">Veículo</span>',
    'Implemento' => '<span class="vbadge vb-warn">Implemento</span>',
    default      => '<span class="vbadge vb-off">Ativo</span>',
};

$GUARD      = ['macro' => 'patrimonio', 'micro' => 'localizacao_ativos'];
$PAGE_VIEW  = 'patrimonio_localizacao_ativos';
$PAGE_TITLE = 'Localização de Ativos';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Localização de Ativos', 'Bens por fazenda — máquinas, veículos, implementos e ativos patrimoniais vinculados', null) ?>

  <?php if (!$porFazenda): ?>
    <div class="vcard"><div class="vempty">Nenhum bem cadastrado ainda.</div></div>
  <?php else: ?>
    <?php foreach ($porFazenda as $fz => $lista): ?>
    <div class="vcard" style="margin-bottom:14px">
      <div class="vtoolbar"><strong><?= h((string)$fz) ?></strong>
        <span class="vsub"><?= count($lista) ?> bem(ns)</span></div>
      <table class="vtable">
        <thead><tr><th>Classe</th><th>Bem</th><th>Detalhe</th></tr></thead>
        <tbody>
        <?php foreach ($lista as $i): ?>
          <tr>
            <td><?= $badgeClasse((string)$i['classe']) ?></td>
            <td><strong><?= h((string)$i['nome']) ?></strong></td>
            <td class="vhint"><?= h((string)$i['detalhe']) ?: '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
    <div class="vhint">Ativos patrimoniais herdam a fazenda da máquina vinculada — vincule em Patrimônio → Ativos
      para tirá-los de "Sem localização definida".</div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
