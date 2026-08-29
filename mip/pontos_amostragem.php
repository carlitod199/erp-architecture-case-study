<?php
/* ============================================================
   VERO — MIP / Pontos de Amostragem  (CRUD real)
   Substitui o mock. Rota: /mip/pontos_amostragem.php
   Guard: mip.pontos_amostragem
   Pontos fixos de amostragem por válvula (mip_pontos_amostragem),
   referenciados pelos monitoramentos (ponto_id). Sem coluna
   `ativo` — exclusão bloqueada quando há monitoramentos.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'mip_pontos_amostragem';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('mip.pontos_amostragem.editar');
        $id     = vero_int('id');
        $nome   = vero_str('nome', 80);
        $talhao = vero_int('talhao_id');
        if ($nome === null || !$talhao) {
            vero_flash('erro', 'Válvula e nome do ponto são obrigatórios.');
            vero_redirect();
        }
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND talhao_id=:tal AND nome=:n AND id<>:id",
            [':t' => vero_tenant(), ':tal' => $talhao, ':n' => $nome, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe o ponto \"{$nome}\" nesta válvula.");
            vero_redirect();
        }
        $data = [
            'talhao_id' => $talhao,
            'nome'      => $nome,
            'latitude'  => vero_dec('latitude'),
            'longitude' => vero_dec('longitude'),
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Ponto \"{$nome}\" atualizado."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Ponto \"{$nome}\" criado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('mip.pontos_amostragem.excluir');
        $id = vero_int('id');
        if ($id) {
            $uso = (int)vero_val("SELECT COUNT(*) FROM mip_monitoramentos WHERE tenant_id=:t AND ponto_id=:p",
                [':t' => vero_tenant(), ':p' => $id]);
            if ($uso > 0) {
                vero_flash('erro', "Ponto usado por {$uso} monitoramento(s) — não pode ser excluído.");
            } else {
                vero_delete(T, $id);
                vero_flash('ok', 'Ponto excluído.');
            }
        }
        vero_redirect();
    }
}

$fTalhao = (int)($_GET['talhao'] ?? 0);
$where   = "p.tenant_id = :t";
$params  = [':t' => vero_tenant()];
if ($fTalhao > 0) { $where .= " AND p.talhao_id = :tal"; $params[':tal'] = $fTalhao; }

$rows = vero_rows(
    "SELECT p.*, tl.codigo AS talhao, fz.nome AS fazenda,
            (SELECT COUNT(*) FROM mip_monitoramentos m WHERE m.tenant_id = p.tenant_id AND m.ponto_id = p.id) AS monitoramentos,
            (SELECT MAX(m2.data_monitoramento) FROM mip_monitoramentos m2 WHERE m2.tenant_id = p.tenant_id AND m2.ponto_id = p.id) AS ultimo
       FROM " . T . " p
       LEFT JOIN agro_talhoes tl ON tl.id = p.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
      WHERE {$where}
      ORDER BY fz.nome, tl.codigo, p.nome", $params);

$talhoes = vero_rows(
    "SELECT t.id, t.codigo, f.nome AS fazenda FROM agro_talhoes t
      LEFT JOIN agro_fazendas f ON f.id = t.fazenda_id
     WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo", [':t' => vero_tenant()]);
$talhaoOpts = [];
foreach ($talhoes as $tl) $talhaoOpts[(int)$tl['id']] = ($tl['fazenda'] ? $tl['fazenda'] . ' — ' : '') . $tl['codigo'];

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'mip', 'micro' => 'pontos_amostragem'];
$PAGE_VIEW  = 'mip_pontos_amostragem';
$PAGE_TITLE = 'Pontos de Amostragem';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('mip.pontos_amostragem.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Pontos de Amostragem', 'Pontos fixos de coleta por válvula — referenciados pelos monitoramentos MIP',
        $podeEditar ? '+ Novo ponto' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="talhao" onchange="this.form.submit()">
          <option value="">Todas as válvulas</option>
          <?php foreach ($talhaoOpts as $tid => $tn): ?>
            <option value="<?= $tid ?>"<?= $fTalhao === $tid ? ' selected' : '' ?>><?= h($tn) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= count($rows) ?> ponto(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum ponto de amostragem no filtro.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Válvula</th><th>Ponto</th><th>Coordenadas</th>
        <th class="num">Monitoramentos</th>
        <th>Última coleta</th>
        <th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></strong></td>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td class="vnum"><?= $r['latitude'] !== null && $r['longitude'] !== null
                ? numFmt((float)$r['latitude'], 6) . ', ' . numFmt((float)$r['longitude'], 6)
                : '<span class="vhint">sem GPS</span>' ?></td>
          <td class="num"><?= (int)$r['monitoramentos'] ?></td>
          <td class="vnum"><?= $r['ultimo'] ? date('d/m/Y', strtotime((string)$r['ultimo'])) : '—' ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('mip.pontos_amostragem.excluir') && (int)$r['monitoramentos'] === 0): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este ponto de amostragem?') ?>
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
      <h2><?= $edit ? 'Editar ponto' : 'Novo ponto de amostragem' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_select('talhao_id', 'Válvula', $talhaoOpts, $edit['talhao_id'] ?? '', true, 'Selecione…') ?>
        <?= vero_f_text('nome', 'Nome do ponto', $edit['nome'] ?? '', true, 'Ex.: P1, Bordadura Norte') ?>
        <?= vero_f_text('latitude', 'Latitude', $edit && $edit['latitude'] !== null ? (string)$edit['latitude'] : '', false, 'Ex.: -9,389') ?>
        <?= vero_f_text('longitude', 'Longitude', $edit && $edit['longitude'] !== null ? (string)$edit['longitude'] : '', false, 'Ex.: -40,503') ?>
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
