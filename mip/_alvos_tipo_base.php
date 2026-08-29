<?php
/* ============================================================
   VERO — MIP / base compartilhada de alvos por tipo
   Incluída por pragas.php e doencas.php, que definem:
     $ALV_TIPO ('praga'|'doenca'), $ALV_MICRO, $ALV_VIEW,
     $ALV_TITULO, $ALV_SUB
   Recorte de mip_alvos com CRUD dentro do tipo — a visão completa
   (inclui plantas daninhas) fica em Alvos de Controle.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$permBase = 'mip.' . $ALV_MICRO;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require($permBase . '.editar');
        $id   = vero_int('id');
        $nome = vero_str('nome', 120);
        $nivel = vero_dec('nivel_acao');
        if ($nome === null || $nivel === null || $nivel <= 0) {
            vero_flash('erro', 'Nome e nível de ação (maior que zero) são obrigatórios.');
            vero_redirect();
        }
        $dup = vero_val("SELECT id FROM mip_alvos WHERE tenant_id=:t AND nome=:n AND tipo=:tp AND id<>:id",
            [':t' => vero_tenant(), ':n' => $nome, ':tp' => $ALV_TIPO, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe o alvo \"{$nome}\" neste tipo.");
            vero_redirect();
        }
        $data = [
            'nome'       => $nome,
            'tipo'       => $ALV_TIPO,
            'cultura_id' => vero_fk_tenant('agro_culturas', vero_int('cultura_id')), // A-5
            'nivel_acao' => $nivel,
            'ativo'      => vero_int('ativo') ?? 1,
        ];
        if ($id) { vero_update('mip_alvos', $id, $data); vero_flash('ok', "Alvo \"{$nome}\" atualizado."); }
        else     { vero_insert('mip_alvos', $data);      vero_flash('ok', "Alvo \"{$nome}\" criado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require($permBase . '.excluir');
        $id = vero_int('id');
        if ($id) {
            $uso = (int)vero_val("SELECT COUNT(*) FROM mip_monitoramentos WHERE tenant_id=:t AND alvo_id=:a",
                [':t' => vero_tenant(), ':a' => $id]);
            if ($uso > 0) {
                vero_update('mip_alvos', (int)$id, ['ativo' => 0]);
                vero_flash('erro', "Alvo com {$uso} monitoramento(s) — inativado em vez de excluído.");
            } else {
                vero_delete('mip_alvos', $id);
            }
        }
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT a.*, c.nome AS cultura,
            (SELECT COUNT(*) FROM mip_monitoramentos m WHERE m.tenant_id = a.tenant_id AND m.alvo_id = a.id) AS monitoramentos,
            (SELECT MAX(m2.nivel_infestacao) FROM mip_monitoramentos m2
              WHERE m2.tenant_id = a.tenant_id AND m2.alvo_id = a.id
                AND m2.data_monitoramento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS pico_30d
       FROM mip_alvos a
       LEFT JOIN agro_culturas c ON c.id = a.cultura_id
      WHERE a.tenant_id = :t AND a.tipo = :tp
      ORDER BY a.ativo DESC, a.nome", [':t' => vero_tenant(), ':tp' => $ALV_TIPO]);

$culturas = vero_options('agro_culturas', 'nome');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM mip_alvos WHERE id=:id AND tenant_id=:t AND tipo=:tp",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant(), ':tp' => $ALV_TIPO]);
}

$GUARD      = ['macro' => 'mip', 'micro' => $ALV_MICRO];
$PAGE_VIEW  = $ALV_VIEW;
$PAGE_TITLE = $ALV_TITULO;
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can($permBase . '.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header($ALV_TITULO, $ALV_SUB, $podeEditar ? '+ Novo alvo' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum alvo deste tipo cadastrado.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Alvo</th><th>Cultura</th>
        <th class="num">Nível de ação</th>
        <th class="num">Monitoramentos</th>
        <th class="num">Pico 30d</th>
        <th>Status</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $pico = $r['pico_30d'] !== null ? (float)$r['pico_30d'] : null;
          $acima = $pico !== null && $pico >= (float)$r['nivel_acao']; ?>
        <tr<?= (int)$r['ativo'] !== 1 ? ' style="opacity:.55"' : '' ?>>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= h($r['cultura'] ?? 'Todas') ?></td>
          <td class="num"><?= numFmt((float)$r['nivel_acao'], 1) ?>%</td>
          <td class="num"><?= (int)$r['monitoramentos'] ?></td>
          <td class="vnum" style="text-align:right;<?= $acima ? 'color:#b3261e;font-weight:700' : '' ?>">
            <?= $pico !== null ? numFmt($pico, 1) . '%' : '—' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td class="num"><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can($permBase . '.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este alvo? (com monitoramentos será inativado)') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">Nível de ação definido pelo responsável técnico. A visão completa (incluindo plantas daninhas) fica em MIP → Alvos de Controle.</div>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar alvo' : 'Novo alvo — ' . h($ALV_TITULO) ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome do alvo', $edit['nome'] ?? '', true) ?>
        <?= vero_f_select('cultura_id', 'Cultura', ['' => 'Todas'] + $culturas, $edit['cultura_id'] ?? '', false, '') ?>
        <?= vero_f_text('nivel_acao', 'Nível de ação (%)', $edit ? numFmt((float)$edit['nivel_acao'], 1) : '', true,
            'Índice que dispara alerta — definido pelo RT') ?>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativo', 0 => 'Inativo'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
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
