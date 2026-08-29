<?php
/* ============================================================
   VERO — Estoque / Almoxarifados  (CRUD real)
   Substitui o mock. Rota: /estoque/almoxarifados.php
   Guard: estoque.almoxarifados
   Tabela: almoxarifados ("Almoxarifado Central" é criado
   automaticamente pelo sistema quando necessário).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'almoxarifados';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('estoque.almoxarifados.editar');
        $id   = vero_int('id');
        $nome = vero_str('nome', 120);
        if ($nome === null) {
            vero_flash('erro', 'Nome do almoxarifado é obrigatório.');
            vero_redirect();
        }
        $fazendaId = vero_int('fazenda_id');
        if ($fazendaId) {
            $ok = vero_val("SELECT id FROM agro_fazendas WHERE id=:i AND tenant_id=:t",
                [':i' => $fazendaId, ':t' => vero_tenant()]);
            if (!$ok) $fazendaId = null;
        }
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND nome=:n AND ativo=1 AND id<>:id",
            [':t' => vero_tenant(), ':n' => $nome, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe um almoxarifado ativo chamado \"{$nome}\".");
            vero_redirect();
        }
        $data = [
            'nome' => $nome, 'fazenda_id' => $fazendaId,
            'tipo' => vero_str('tipo', 40), 'ativo' => vero_int('ativo') ?? 1,
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Almoxarifado \"{$nome}\" atualizado."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Almoxarifado \"{$nome}\" criado."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('estoque.almoxarifados.excluir');
        $id = vero_int('id');
        if ($id) {
            $comSaldo = (float)vero_val("SELECT COALESCE(SUM(quantidade),0) FROM estoque_saldos
                                          WHERE tenant_id=:t AND almoxarifado_id=:a",
                [':t' => vero_tenant(), ':a' => $id]);
            if ($comSaldo > 0) {
                vero_flash('erro', 'Almoxarifado com saldo em estoque (' . numFmt($comSaldo, 2) . ') — transfira antes de inativar.');
            } else {
                vero_delete(T, $id); // soft delete (tem `ativo`)
            }
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$rows = vero_rows(
    "SELECT a.*, f.nome AS fazenda,
            (SELECT COUNT(DISTINCT s.produto_id) FROM estoque_saldos s
              WHERE s.tenant_id = a.tenant_id AND s.almoxarifado_id = a.id AND s.quantidade > 0) AS produtos,
            (SELECT COALESCE(SUM(s.valor_total),0) FROM estoque_saldos s
              WHERE s.tenant_id = a.tenant_id AND s.almoxarifado_id = a.id) AS valor
       FROM " . T . " a
       LEFT JOIN agro_fazendas f ON f.id = a.fazenda_id
      WHERE a.tenant_id = :t ORDER BY a.ativo DESC, a.nome", [':t' => vero_tenant()]);

$fazendas = vero_options('agro_fazendas', 'nome', 'ativo = 1');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'estoque', 'micro' => 'almoxarifados'];
$PAGE_VIEW  = 'estoque_almoxarifados';
$PAGE_TITLE = 'Almoxarifados';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('estoque.almoxarifados.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <header class="vero-topbar">
    <h1 class="vero-topbar__title">Almoxarifados</h1>
    <div class="vero-topbar__actions">
      <?php /* C-43/A-07 (QA 19/07): vModalNovo — pós-?editar=N o form está renderizado
               em modo edição; recarrega com ?novo=1 (sem editar) p/ abrir VAZIO */ ?>
      <?php if ($podeEditar): ?><?= vero_btn_icone('<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>', 'Novo almoxarifado', "vModalNovo('vm-form')") ?><?php endif; ?>
    </div>
  </header>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhum almoxarifado — o "Central" é criado automaticamente na primeira movimentação.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Almoxarifado</th><th>Fazenda</th><th>Tipo</th>
        <th style="text-align:right">Produtos com saldo</th>
        <th style="text-align:right">Valor em estoque (R$)</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= h($r['fazenda'] ?? '') ?: '—' ?></td>
          <td class="vhint"><?= h($r['tipo'] ?? '') ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['produtos'] ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['valor'], 2) ?></strong></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('estoque.almoxarifados.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este almoxarifado?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar almoxarifado' : 'Novo almoxarifado' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <?php
        $tipoOpcoes = [
          'central' => 'Central',
          'campo'   => 'Campo',
          'frio'    => 'Câmara fria',
          'packing' => 'Packing (unidade de embalamento)',
        ];
        $tipoAtual = (string)($edit['tipo'] ?? '');
        if ($tipoAtual !== '' && !array_key_exists($tipoAtual, $tipoOpcoes)) {
          $tipoOpcoes[$tipoAtual] = $tipoAtual;
        }
      ?>
      <div class="vgrid">
        <div class="full"><?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', true, 'Ex.: Galpão Sede, Depósito Campo 2') ?></div>
        <?= vero_f_select('fazenda_id', 'Fazenda', $fazendas, $edit['fazenda_id'] ?? null, false, '— Não vinculado —') ?>
        <?= vero_f_select('tipo', 'Tipo', $tipoOpcoes, $edit['tipo'] ?? '', false, '— Sem tipo —') ?>
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
