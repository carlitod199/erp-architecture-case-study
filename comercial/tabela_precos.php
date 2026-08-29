<?php
/* ============================================================
   VERO — Comercial / Tabela de Preços (F2, P-113 — ONDA 3)
   Rota: /comercial/tabela_precos.php · Guard: comercial.vendas (reuso —
   quem opera venda gerencia preço; menu/slug próprio = follow-up NAV).
   CRUD das regras de preço multidimensionais (migration 151). A venda
   puxa o preço da regra vigente MAIS ESPECÍFICA (comercial/_precos.php),
   como SUGESTÃO editável. Cadastro (fora do razão).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_precos.php';

const MOEDAS = ['BRL' => 'R$ (BRL)', 'USD' => 'US$ (USD)', 'EUR' => '€ (EUR)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('comercial.vendas.editar');
        $id    = vero_int('id');
        $preco = vero_dec('preco');
        $vIni  = vero_date('vigencia_inicio');
        if ($preco === null || $preco < 0 || $vIni === null) {
            vero_flash('erro', 'Preço (≥ 0) e início de vigência são obrigatórios.');
            vero_redirect();
        }
        $moeda = (string)($_POST['moeda'] ?? 'BRL');
        if (!isset(MOEDAS[$moeda])) $moeda = 'BRL';
        // A-4: cada FK do POST validada contra o tenant (id de outro tenant → null).
        $data = [
            'cultura_id'      => vero_fk_tenant('agro_culturas', vero_int('cultura_id')),
            'variedade_id'    => vero_fk_tenant('agro_variedades', vero_int('variedade_id')),
            'calibre'         => vero_str('calibre', 40) ?: null,
            'embalagem'       => vero_str('embalagem', 40) ?: null,
            'comprador_id'    => vero_fk_tenant('comercial_compradores', vero_int('comprador_id')),
            'canal_id'        => vero_fk_tenant('comercial_canais', vero_int('canal_id')),
            'safra_id'        => vero_fk_tenant('agro_safras', vero_int('safra_id')),
            'preco'           => $preco,
            'moeda'           => $moeda,
            'vigencia_inicio' => $vIni,
            'vigencia_fim'    => vero_date('vigencia_fim'),
            'ativo'           => vero_int('ativo') ?? 1,
        ];
        preco_persist($data, $id ?: null);
        vero_flash('ok', $id ? 'Regra de preço atualizada.' : 'Regra de preço criada.');
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('comercial.vendas.editar');
        $id = vero_int('id');
        if ($id) vero_pdo()->prepare("UPDATE comercial_tabela_precos SET ativo=0 WHERE id=:i AND tenant_id=:t")
            ->execute([':i' => $id, ':t' => vero_tenant()]); /* inativa (mantém histórico/trilha) */
        vero_redirect();
    }
}

$rows = vero_rows(
    "SELECT tp.*, cu.nome AS cultura, va.nome AS variedade, co.razao_social AS comprador,
            ca.nome AS canal, sa.identificacao AS safra
       FROM comercial_tabela_precos tp
       LEFT JOIN agro_culturas cu ON cu.id = tp.cultura_id
       LEFT JOIN agro_variedades va ON va.id = tp.variedade_id
       LEFT JOIN comercial_compradores co ON co.id = tp.comprador_id
       LEFT JOIN comercial_canais ca ON ca.id = tp.canal_id
       LEFT JOIN agro_safras sa ON sa.id = tp.safra_id
      WHERE tp.tenant_id = :t
      ORDER BY tp.ativo DESC, tp.vigencia_inicio DESC, tp.id DESC",
    [':t' => vero_tenant()]);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM comercial_tabela_precos WHERE id=:i AND tenant_id=:t",
        [':i' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$culturas  = vero_options('agro_culturas', 'nome');
$variedades = vero_options('agro_variedades', 'nome');
$compradores = vero_rows("SELECT id, razao_social FROM comercial_compradores WHERE tenant_id=:t AND ativo=1 ORDER BY razao_social", [':t' => vero_tenant()]);
$canais    = vero_rows("SELECT id, nome FROM comercial_canais WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => vero_tenant()]);
$safras    = vero_options('agro_safras', 'identificacao');

$GUARD      = ['macro' => 'comercial', 'micro' => 'vendas'];
$PAGE_VIEW  = 'comercial_tabela_precos';
$PAGE_TITLE = 'Tabela de Preços';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('comercial.vendas.editar');
$hoje = date('Y-m-d');
$simbolo = static fn(string $m): string => ['BRL' => 'R$', 'USD' => 'US$', 'EUR' => '€'][$m] ?? $m;
$vigenteHoje = static fn(array $r): bool => (int)$r['ativo'] === 1
    && (string)$r['vigencia_inicio'] <= date('Y-m-d')
    && ($r['vigencia_fim'] === null || (string)$r['vigencia_fim'] >= date('Y-m-d'));
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Tabela de Preços',
        'Regras multidimensionais (F2) — a venda puxa o preço vigente mais específico como sugestão editável',
        $podeEditar ? '+ Nova regra de preço' : null) ?>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma regra de preço cadastrada. A venda continua com preço digitado até haver regras.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <th>Cultura</th><th>Variedade</th><th>Calibre</th><th>Embalagem</th>
        <th>Cliente</th><th>Canal</th><th>Safra</th>
        <th style="text-align:right">Preço</th><th>Vigência</th><th>Status</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $vig = $vigenteHoje($r); ?>
        <tr<?= $vig ? ' style="background:#FAF8F1"' : '' ?>>
          <td><?= $r['cultura'] ? h($r['cultura']) : '<span class="vhint">— todas</span>' ?></td>
          <td><?= $r['variedade'] ? h($r['variedade']) : '<span class="vhint">—</span>' ?></td>
          <td><?= $r['calibre'] ? h($r['calibre']) : '<span class="vhint">—</span>' ?></td>
          <td><?= $r['embalagem'] ? h($r['embalagem']) : '<span class="vhint">—</span>' ?></td>
          <td><?= $r['comprador'] ? h($r['comprador']) : '<span class="vhint">—</span>' ?></td>
          <td><?= $r['canal'] ? h($r['canal']) : '<span class="vhint">—</span>' ?></td>
          <td><?= $r['safra'] ? h($r['safra']) : '<span class="vhint">—</span>' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= $simbolo((string)$r['moeda']) ?> <?= numFmt((float)$r['preco'], 4) ?></strong></td>
          <td class="vhint"><?= date('d/m/Y', strtotime((string)$r['vigencia_inicio'])) ?><?= $r['vigencia_fim'] ? ' – ' . date('d/m/Y', strtotime((string)$r['vigencia_fim'])) : '+' ?></td>
          <td><?= $vig ? '<span class="vbadge vb-ok">Vigente</span>' : ((int)$r['ativo'] === 1 ? '<span class="vbadge vb-info">Ativa</span>' : '<span class="vbadge vb-muted">Inativa</span>') ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?>
              <?php if ((int)$r['ativo'] === 1): ?><?= vero_btn_excluir((int)$r['id'], 'Inativar esta regra de preço?') ?><?php endif; ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      Especificidade: a venda usa a regra vigente que casa com MAIS dimensões (ex.: regra por
      cultura×variedade×cliente vence a regra só por cultura). Dimensão em branco = curinga (vale para todas).
    </div>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar regra de preço' : 'Nova regra de preço' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_select('cultura_id', 'Cultura (branco = todas)', $culturas, (int)($edit['cultura_id'] ?? 0), false, '') ?>
        <?= vero_f_select('variedade_id', 'Variedade', $variedades, (int)($edit['variedade_id'] ?? 0), false, '') ?>
        <?= vero_f_text('calibre', 'Calibre', $edit['calibre'] ?? '', false, 'Ex.: 16–18 mm') ?>
        <?= vero_f_text('embalagem', 'Embalagem', $edit['embalagem'] ?? '', false, 'Ex.: caixa 8,2 kg') ?>
        <div class="vfield">
          <label>Cliente (branco = todos)</label>
          <select name="comprador_id">
            <option value="">— todos</option>
            <?php foreach ($compradores as $c): ?>
              <option value="<?= (int)$c['id'] ?>"<?= (int)($edit['comprador_id'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['razao_social']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Canal (branco = todos)</label>
          <select name="canal_id">
            <option value="">— todos</option>
            <?php foreach ($canais as $c): ?>
              <option value="<?= (int)$c['id'] ?>"<?= (int)($edit['canal_id'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= vero_f_select('safra_id', 'Safra (branco = todas)', $safras, (int)($edit['safra_id'] ?? 0), false, '') ?>
        <?= vero_f_text('preco', 'Preço *', $edit ? numFmt((float)$edit['preco'], 4) : '', true, 'por kg/unidade') ?>
        <?= vero_f_select('moeda', 'Moeda', MOEDAS, $edit['moeda'] ?? 'BRL', true, '') ?>
        <div class="vfield">
          <label>Vigência início *</label>
          <input type="date" name="vigencia_inicio" required value="<?= h($edit['vigencia_inicio'] ?? $hoje) ?>">
        </div>
        <div class="vfield">
          <label>Vigência fim (branco = aberta)</label>
          <input type="date" name="vigencia_fim" value="<?= h($edit['vigencia_fim'] ?? '') ?>">
        </div>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
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
