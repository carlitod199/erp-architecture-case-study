<?php
declare(strict_types=1);
/* ============================================================
   VERO — Packing House / QR Codes dos colaboradores
   Rota: /packing/crachas.php · Guard: packing.crachas (view: packing_crachas)
   Atribui o CÓDIGO (conteúdo do QR) a cada colaborador e gera o QR Code
   impriível, para apontar produção por LEITURA: QR do COLHEDOR na colheita
   e do EMBALADOR no embalamento (ambos → rh_producao_itens). O QR codifica
   o próprio código; o leitor digita esse texto no campo do apontamento.
   Resolver: includes/vero_cracha.php · QR: includes/vero_qr.php.
   Edita agro_operadores.cracha e rh_terceirizados.cracha (único por tenant).
   ============================================================ */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_cracha.php';
require_once __DIR__ . '/../includes/vero_qr.php';

const CRA_ORIGENS = ['colaborador' => 'agro_operadores', 'terceirizado' => 'rh_terceirizados'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['acao'] ?? '') === 'salvar') {
    csrfCheck();
    vero_require('packing.crachas.editar');
    $origem = vero_str('origem', 20);
    $pid    = vero_int('pessoa_id');
    $cracha = vero_str('cracha', 40);
    if (!isset(CRA_ORIGENS[$origem]) || !$pid) {
        vero_flash('erro', 'Colaborador inválido.'); vero_redirect();
    }
    $tab = CRA_ORIGENS[$origem];
    $ok  = vero_val("SELECT id FROM {$tab} WHERE id=:i AND tenant_id=:t", [':i' => $pid, ':t' => vero_tenant()]);
    if (!$ok) { vero_flash('erro', 'Colaborador não encontrado.'); vero_redirect(); }
    try {
        vero_pdo()->prepare("UPDATE {$tab} SET cracha = :c, updated_by = :u WHERE id = :i AND tenant_id = :t")
            ->execute([':c' => ($cracha !== '' ? $cracha : null), ':u' => vero_uid(), ':i' => $pid, ':t' => vero_tenant()]);
        vero_flash('ok', 'QR Code atualizado.');
    } catch (Throwable $e) {
        vero_flash('erro', 'Não foi possível salvar — o código "' . h($cracha) . '" já está em uso por outro colaborador.');
    }
    vero_redirect();
}

/* Lista unificada: colaboradores + terceirizados ativos, com o código atual. */
$pessoas = [];
foreach (vero_rows("SELECT id, nome, funcao, funcao_packing, cracha FROM agro_operadores
                     WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => vero_tenant()]) as $o) {
    $pessoas[] = ['origem' => 'colaborador', 'id' => (int)$o['id'], 'nome' => $o['nome'],
                  'funcao' => $o['funcao'] ?? '', 'papel' => $o['funcao_packing'] ?? '', 'cracha' => $o['cracha'] ?? ''];
}
foreach (vero_rows("SELECT id, nome, funcao_packing, cracha FROM rh_terceirizados
                     WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => vero_tenant()]) as $tc) {
    $pessoas[] = ['origem' => 'terceirizado', 'id' => (int)$tc['id'], 'nome' => $tc['nome'],
                  'funcao' => 'Terceirizado', 'papel' => $tc['funcao_packing'] ?? '', 'cracha' => $tc['cracha'] ?? ''];
}
$edit = null;
if (!empty($_GET['tipo']) && !empty($_GET['id']) && isset(CRA_ORIGENS[(string)$_GET['tipo']])) {
    foreach ($pessoas as $p) {
        if ($p['origem'] === $_GET['tipo'] && $p['id'] === (int)$_GET['id']) { $edit = $p; break; }
    }
}

$GUARD      = ['macro' => 'packing', 'micro' => 'crachas'];
$PAGE_VIEW  = 'packing_crachas';
$PAGE_TITLE = 'QR Codes dos Colaboradores';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<style>
  .vqr-thumb{width:56px;height:56px;display:inline-block}
  .vqr-thumb .vqr-svg{display:block;width:100%}
</style>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('QR Codes dos Colaboradores', 'O QR Code identifica a pessoa na leitura do apontamento — colhedor na colheita, embalador no embalamento. Gere e imprima o QR para colar no crachá físico.', null) ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Colaboradores</strong>
      <span class="vhint">Gere o QR e imprima etiquetas (só o QR) para colar nas caixas.</span></div>
  <?php if (!$pessoas): ?>
    <div class="vempty">Nenhum colaborador ativo. Cadastre em Pessoas e Equipes.</div>
  <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Colaborador</th><th>Função</th><th>Vínculo</th><th>QR Code</th><th>Código</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pessoas as $p): ?>
        <tr>
          <td><strong><?= h((string)$p['nome']) ?></strong></td>
          <td class="vhint"><?= h((string)$p['funcao']) ?: '—' ?><?php if (($p['papel'] ?? '') !== ''): ?> <span class="vbadge vb-info" style="margin-left:4px"><?= h(ucfirst((string)$p['papel'])) ?></span><?php endif; ?></td>
          <td><span class="vbadge <?= $p['origem'] === 'terceirizado' ? 'vb-warn' : 'vb-info' ?>"><?= $p['origem'] === 'terceirizado' ? 'Terceirizado' : 'Colaborador' ?></span></td>
          <td><?= $p['cracha'] !== '' ? '<span class="vqr-thumb">' . vero_qr_svg((string)$p['cracha']) . '</span>' : '<span class="vhint">— sem QR —</span>' ?></td>
          <td><?= $p['cracha'] !== '' ? '<code>' . h((string)$p['cracha']) . '</code>' : '<span class="vhint">—</span>' ?></td>
          <td><div class="vactions">
            <?php if ($p['cracha'] !== ''): ?>
              <?= vero_btn_icone(vero_ico_imprimir(), 'Imprimir etiquetas', '', BIOS_BASE . '/packing/etiquetas?tipo=' . $p['origem'] . '&id=' . $p['id']) ?>
            <?php endif; ?>
            <?= vero_btn_icone(vero_ico_lapis(), $p['cracha'] !== '' ? 'Editar QR Code' : 'Gerar QR Code', '', '?tipo=' . $p['origem'] . '&id=' . $p['id']) ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>

  <?php if ($edit): $sug = vero_srv_cracha_sugerir($edit['origem'], $edit['id']); ?>
  <div class="vmodal open" id="vm-form">
    <div class="vbox">
      <header>
        <h2>QR Code — <?= h((string)$edit['nome']) ?></h2>
        <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
      </header>
      <form class="vform" method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="origem" value="<?= h((string)$edit['origem']) ?>">
        <input type="hidden" name="pessoa_id" value="<?= (int)$edit['id'] ?>">
        <div class="vgrid">
          <?= vero_f_text('cracha', 'Código do QR Code', $edit['cracha'] !== '' ? (string)$edit['cracha'] : $sug, false, 'Sugerido: ' . $sug . ' — ou o código já impresso no crachá') ?>
        </div>
        <div class="vhint" style="margin:2px 2px 0">Função no packing: <strong><?= ($edit['papel'] ?? '') !== '' ? h(ucfirst((string)$edit['papel'])) : '— não definida —' ?></strong> · defina em <a href="<?= h(BIOS_BASE) ?>/pessoas/<?= $edit['origem'] === 'terceirizado' ? 'terceirizados' : 'colaboradores' ?>">Pessoas e Equipes</a> (colhedor conta colheita · embalador conta embalamento).</div>
        <?php if ($edit['cracha'] !== ''): ?>
          <div class="vgrid" style="padding-top:0"><div class="full" style="text-align:center"><span class="vqr-thumb" style="width:120px;height:120px;margin:0 auto"><?= vero_qr_svg((string)$edit['cracha']) ?></span></div></div>
        <?php endif; ?>
        <div class="vform-actions"><button type="submit" class="vbtn vbtn-primary">Salvar</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php'; ?>
