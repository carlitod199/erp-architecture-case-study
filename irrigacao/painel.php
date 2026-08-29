<?php
/* ============================================================
   VERO — Irrigação / Pivôs (painel + cadastro)  (tela real)
   Substitui o mock. Rota: /irrigacao/painel.php (micro 'pivos')
   Guard: irrigacao.pivos
   CRUD de pivôs/sistemas de irrigação (agro_pivos) com KPIs dos
   últimos 30 dias (horas, lâmina, consumos).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('irrigacao.pivos.editar');
        $id   = vero_int('id');
        $nome = vero_str('nome', 120);
        if ($nome === null) {
            vero_flash('erro', 'Nome do pivô/sistema é obrigatório.');
            vero_redirect();
        }
        $data = [
            'nome'       => $nome,
            'fazenda_id' => vero_int('fazenda_id') ?: null,
            'area_ha'    => vero_dec('area_ha'),
        ];
        if ($id) { vero_update('agro_pivos', $id, $data); vero_flash('ok', "Pivô \"{$nome}\" atualizado."); }
        else     { vero_insert('agro_pivos', $data);      vero_flash('ok', "Pivô \"{$nome}\" cadastrado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('irrigacao.pivos.excluir');
        $id = vero_int('id');
        if ($id) {
            $uso = (int)vero_val(
                "SELECT (SELECT COUNT(*) FROM irrigacao_apontamentos WHERE tenant_id=:t1 AND pivo_id=:p1)
                      + (SELECT COUNT(*) FROM irrigacao_planejamentos WHERE tenant_id=:t2 AND pivo_id=:p2)",
                [':t1' => $t, ':p1' => $id, ':t2' => $t, ':p2' => $id]);
            if ($uso > 0) {
                vero_flash('erro', "Pivô usado por {$uso} apontamento(s)/planejamento(s) — não pode ser excluído.");
            } else {
                vero_pdo()->prepare("DELETE FROM agro_pivos WHERE tenant_id=? AND id=?")->execute([$t, (int)$id]);
                vero_flash('ok', 'Pivô excluído.');
            }
        }
        vero_redirect();
    }
}

$d30 = date('Y-m-d', strtotime('-30 days'));
$kpi = vero_row(
    "SELECT COUNT(*) AS apontamentos, COALESCE(SUM(horas),0) AS horas, COALESCE(SUM(lamina_mm),0) AS lamina
       FROM irrigacao_apontamentos WHERE tenant_id = :t AND data_apontamento >= :d", [':t' => $t, ':d' => $d30]);
$consumo = vero_row(
    "SELECT COALESCE(SUM(CASE WHEN c.tipo='agua' THEN c.quantidade END),0) AS agua,
            COALESCE(SUM(CASE WHEN c.tipo='energia' THEN c.quantidade END),0) AS energia,
            COALESCE(SUM(c.custo),0) AS custo
       FROM irrigacao_consumos c
       JOIN irrigacao_apontamentos a ON a.id = c.apontamento_id
      WHERE c.tenant_id = :t AND a.data_apontamento >= :d", [':t' => $t, ':d' => $d30]);

$rows = vero_rows(
    "SELECT p.*, f.nome AS fazenda,
            (SELECT COUNT(*) FROM irrigacao_apontamentos a WHERE a.tenant_id = p.tenant_id AND a.pivo_id = p.id) AS apontamentos,
            (SELECT COUNT(*) FROM irrigacao_planejamentos pl WHERE pl.tenant_id = p.tenant_id AND pl.pivo_id = p.id) AS planejamentos
       FROM agro_pivos p
       LEFT JOIN agro_fazendas f ON f.id = p.fazenda_id
      WHERE p.tenant_id = :t ORDER BY f.nome, p.nome", [':t' => $t]);

$fazendas = vero_options('agro_fazendas', 'nome');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM agro_pivos WHERE id=:id AND tenant_id=:t", [':id' => (int)$_GET['editar'], ':t' => $t]);
}

$GUARD      = ['macro' => 'irrigacao', 'micro' => 'pivos'];
$PAGE_VIEW  = 'irrigacao_pivos';
$PAGE_TITLE = 'Pivôs e Sistemas de Irrigação';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('irrigacao.pivos.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Pivôs e Sistemas de Irrigação', 'Cadastro dos sistemas + pulso dos últimos 30 dias',
        $podeEditar ? '+ Novo pivô' : null) ?>

  <div class="vcard" style="padding:11px 16px;margin-bottom:14px;display:flex;gap:26px;flex-wrap:wrap;align-items:baseline">
    <div><span class="vhint">Apontamentos (30d)&nbsp;</span><strong class="vnum" style="font-size:16px"><?= (int)$kpi['apontamentos'] ?></strong></div>
    <div><span class="vhint">Horas irrigadas&nbsp;</span><strong class="vnum" style="font-size:16px"><?= numFmt((float)$kpi['horas'], 1) ?> h</strong></div>
    <div><span class="vhint">Lâmina&nbsp;</span><strong class="vnum" style="font-size:16px"><?= numFmt((float)$kpi['lamina'], 1) ?> mm</strong></div>
    <div><span class="vhint">Água / Energia&nbsp;</span><strong class="vnum" style="font-size:16px"><?= numFmt((float)$consumo['agua'], 0) ?> m³ · <?= numFmt((float)$consumo['energia'], 0) ?> kWh</strong></div>
    <div style="margin-left:auto"><span class="vhint">Custo (30d)&nbsp;</span><strong class="vnum" style="font-size:16px;color:#005059">R$ <?= numFmt((float)$consumo['custo'], 2) ?></strong></div>
  </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Sistemas cadastrados</strong>
      <span class="vsub"><?= count($rows) ?> pivô(s)/sistema(s)</span></div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum pivô cadastrado — os apontamentos podem referenciá-los para leitura por sistema.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Sistema</th><th>Fazenda</th>
        <th class="num">Área (ha)</th>
        <th class="num">Apontamentos</th>
        <th class="num">Planejamentos</th>
        <th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= h($r['fazenda'] ?? '—') ?></td>
          <td class="num"><?= $r['area_ha'] !== null ? numFmt((float)$r['area_ha'], 2) : '—' ?></td>
          <td class="num"><?= (int)$r['apontamentos'] ?></td>
          <td class="num"><?= (int)$r['planejamentos'] ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('irrigacao.pivos.excluir') && (int)$r['apontamentos'] === 0 && (int)$r['planejamentos'] === 0): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este pivô?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar pivô' : 'Novo pivô / sistema' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', true, 'Ex.: Pivô 1, Gotejo Setor Norte') ?>
        <?= vero_f_select('fazenda_id', 'Fazenda', $fazendas, $edit['fazenda_id'] ?? '', false, 'Selecione…') ?>
        <?= vero_f_text('area_ha', 'Área coberta (ha)', $edit && $edit['area_ha'] !== null ? numFmt((float)$edit['area_ha'], 2) : '') ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
