<?php
/* ============================================================
   VERO — Pessoas / Encargos CLT  (tela real)
   Tela nova. Rota da matriz: /pessoas/encargos.php
   Guard: pessoas.encargos | Escrita: pessoas.encargos.editar/excluir
   Tabelas: rh_encargos_config (percentuais por tenant com vigência — D4)
   Demonstrativo: cada encargo = salário bruto × pct da config vigente.
   Aceite (prints do sistema legado): bruto 1.664,00 → total encargos 919,19.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_funrural.php'; /* A3 — regime FUNRURAL por safra (P-117) */

const T = 'rh_encargos_config';

const ENCARGOS_CAMPOS = [
    'fgts_pct'          => 'FGTS',
    'inss_patronal_pct' => 'INSS patronal',
    'rat_pct'           => 'RAT',
    'terceiros_pct'     => 'Terceiros',
    'ferias_pct'        => 'Férias + 1/3',
    'decimo_pct'        => '13º salário',
    'outros_pct'        => 'Outros',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('pessoas.encargos.editar');

        $id  = vero_int('id');
        $vig = vero_date('vigencia_inicio');
        if ($vig === null) {
            vero_flash('erro', 'Informe o início da vigência.');
            vero_redirect();
        }
        $data = ['vigencia_inicio' => $vig, 'ativo' => vero_int('ativo') ?? 1];
        foreach (array_keys(ENCARGOS_CAMPOS) as $campo) {
            $pct = vero_dec($campo);
            if ($pct === null || $pct < 0 || $pct > 100) {
                vero_flash('erro', 'Percentuais devem estar entre 0 e 100.');
                vero_redirect();
            }
            $data[$campo] = $pct;
        }
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND vigencia_inicio=:v AND id<>:id",
            [':t' => vero_tenant(), ':v' => $vig, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', 'Já existe uma configuração com esta vigência.');
            vero_redirect();
        }

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', 'Configuração de encargos atualizada.');
        } else {
            vero_insert(T, $data);
            vero_flash('ok', 'Nova vigência de encargos cadastrada.');
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('pessoas.encargos.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }

    if ($acao === 'salvar_funrural') { /* A3 — P-117: regime FUNRURAL por safra */
        vero_require('pessoas.encargos.editar');
        $default = (string)($_POST['funrural_default'] ?? FUNRURAL_REGIME_DEFAULT);
        $porSafra = [];
        foreach ((array)($_POST['funrural_safra'] ?? []) as $sid => $reg) $porSafra[(int)$sid] = (string)$reg;
        funrural_salvar($default, $porSafra);
        vero_flash('ok', 'Regime de FUNRURAL por safra atualizado.');
        vero_redirect();
    }
}

/* ── Dados ──────────────────────────────────────────────────── */
$configs = vero_rows(
    "SELECT * FROM " . T . " WHERE tenant_id = :t ORDER BY ativo DESC, vigencia_inicio DESC",
    [':t' => vero_tenant()]);
$vigente = vero_srv_encargos_vigente();

/* A3 P-117 — safras + regime FUNRURAL efetivo de cada uma */
$safrasFun = vero_rows(
    "SELECT id, identificacao, status FROM agro_safras WHERE tenant_id = :t ORDER BY identificacao DESC",
    [':t' => vero_tenant()]);
$funDefault = funrural_default();

$colaboradores = vero_rows(
    "SELECT * FROM agro_operadores
      WHERE tenant_id = :t AND ativo = 1 AND tipo_vinculo = 'clt' AND salario_mensal IS NOT NULL
      ORDER BY nome",
    [':t' => vero_tenant()]);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'pessoas', 'micro' => 'encargos'];
$PAGE_VIEW  = 'pessoas_encargos';
$PAGE_TITLE = 'Encargos CLT';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('pessoas.encargos.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Encargos CLT', 'Percentuais parametrizáveis por vigência (D4) e demonstrativo do custo total por colaborador',
        $podeEditar ? '+ Nova vigência' : null) ?>

  <div class="vcard" style="margin-bottom:16px">
    <div class="vtoolbar"><strong style="font-size:14px">Configurações por vigência</strong>
      <?php if ($vigente): ?>
        <span class="vsub">Vigente hoje: <strong><?= date('d/m/Y', strtotime((string)$vigente['vigencia_inicio'])) ?></strong></span>
      <?php endif; ?>
    </div>
    <?php if (!$configs): ?>
      <div class="vempty">Nenhuma configuração — cadastre a primeira vigência.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Vigência a partir de</th>
        <?php foreach (ENCARGOS_CAMPOS as $rotulo): ?>
          <th style="text-align:right"><?= h($rotulo) ?> (%)</th>
        <?php endforeach; ?>
        <th style="text-align:right">Total (%)</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($configs as $c):
          $somaPct = 0;
          foreach (array_keys(ENCARGOS_CAMPOS) as $campo) $somaPct += (float)$c[$campo];
      ?>
        <tr<?= $vigente && (int)$vigente['id'] === (int)$c['id'] ? ' style="background:#FAF8F1"' : '' ?>>
          <td><strong class="vnum"><?= date('d/m/Y', strtotime((string)$c['vigencia_inicio'])) ?></strong></td>
          <?php foreach (array_keys(ENCARGOS_CAMPOS) as $campo): ?>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$c[$campo], 2) ?></td>
          <?php endforeach; ?>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($somaPct, 2) ?></strong></td>
          <td><?= vero_b_ativo($c['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$c['id']) ?><?php endif; ?>
            <?php if (vero_can('pessoas.encargos.excluir') && (int)$c['ativo'] === 1 && count($configs) > 1): ?>
              <?= vero_btn_excluir((int)$c['id'], 'Inativar esta vigência?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="vcard" style="margin-bottom:16px">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar_funrural">
      <div class="vtoolbar"><strong style="font-size:14px">FUNRURAL — regime por safra</strong>
        <div class="vhint">Opção fiscal do produtor (Lei 13.606), pode mudar entre safras.
          <strong>Folha</strong> = 20% patronal + RAT + SENAR sobre a folha (encargo no custo de MDO).
          <strong>Receita</strong> = substitutiva ~<?= numFmt(FUNRURAL_ALIQUOTA_RECEITA, 1) ?>% sobre a comercialização (despesa de venda).</div>
        <?php if ($podeEditar): ?>
          <span style="flex:1"></span>
          <div class="vfield" style="min-width:230px">
            <label>Regime padrão do tenant</label>
            <select name="funrural_default">
              <?php foreach (FUNRURAL_REGIMES as $k => $lab): ?>
                <option value="<?= $k ?>"<?= $funDefault === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>
      <?php if (!$safrasFun): ?>
        <div class="vempty">Nenhuma safra cadastrada — o regime padrão vale para toda a folha.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Safra</th><th>Status</th><th style="width:46%">Regime FUNRURAL</th><th>Efetivo</th></tr></thead>
        <tbody>
        <?php
        $funOverrides = (array)(funrural_config()['safras'] ?? []); /* safra_id => regime (só overrides) */
        foreach ($safrasFun as $s):
            $sid = (int)$s['id'];
            $override = (string)($funOverrides[(string)$sid] ?? ''); /* '' = usa padrão */
            $efet = funrural_regime_safra($sid); ?>
          <tr>
            <td><strong><?= h((string)$s['identificacao']) ?></strong></td>
            <td><span class="vhint"><?= h((string)$s['status']) ?></span></td>
            <td>
              <?php if ($podeEditar): ?>
              <select name="funrural_safra[<?= $sid ?>]">
                <option value=""<?= $override === '' ? ' selected' : '' ?>>— usar padrão (<?= h(FUNRURAL_REGIMES[$funDefault]) ?>)</option>
                <?php foreach (FUNRURAL_REGIMES as $k => $lab): ?>
                  <option value="<?= $k ?>"<?= $override === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
                <span class="vhint"><?= h(FUNRURAL_REGIMES[$efet]) ?></span>
              <?php endif; ?>
            </td>
            <td><span class="vbadge <?= $efet === 'receita' ? 'vb-warn' : 'vb-info' ?>"><?= h(ucfirst($efet)) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($podeEditar): ?>
      <div class="vform-actions" style="padding:10px 14px">
        <button class="vbtn vbtn-primary" type="submit">Salvar regime por safra</button>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </form>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong style="font-size:14px">Demonstrativo por colaborador (CLT ativos com salário)</strong>
      <div class="vhint">Cada encargo = salário bruto × percentual da configuração vigente</div>
    </div>
    <?php if (!$vigente): ?>
      <div class="vempty">Sem configuração vigente — cadastre uma vigência acima.</div>
    <?php elseif (!$colaboradores): ?>
      <div class="vempty">Nenhum colaborador CLT ativo com salário cadastrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Colaborador</th>
        <th style="text-align:right">Bruto (R$)</th>
        <th style="text-align:right">FGTS</th>
        <th style="text-align:right">INSS patr.</th>
        <th style="text-align:right">RAT</th>
        <th style="text-align:right">Terceiros</th>
        <th style="text-align:right">Férias</th>
        <th style="text-align:right">13º</th>
        <th style="text-align:right">Outros</th>
        <th style="text-align:right">Total encargos</th>
        <th style="text-align:right">Custo total</th>
      </tr></thead>
      <tbody>
      <?php
      $tot = array_fill_keys(['bruto', 'fgts', 'inss_patronal', 'rat', 'terceiros', 'ferias', 'decimo', 'outros', 'total', 'custo_total'], 0.0);
      foreach ($colaboradores as $col):
          $bruto = (float)$col['salario_mensal'];
          $e = vero_srv_encargos_calc($bruto, $vigente);
          $tot['bruto'] += $bruto;
          foreach (['fgts', 'inss_patronal', 'rat', 'terceiros', 'ferias', 'decimo', 'outros', 'total', 'custo_total'] as $k) $tot[$k] += $e[$k];
      ?>
        <tr>
          <td><strong><?= h($col['nome']) ?></strong>
            <?= $col['funcao'] ? '<span class="vhint">' . h($col['funcao']) . '</span>' : '' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($bruto, 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($e['fgts'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($e['inss_patronal'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($e['rat'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($e['terceiros'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($e['ferias'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($e['decimo'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt($e['outros'], 2) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt($e['total'], 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><strong style="color:#005059"><?= numFmt($e['custo_total'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td style="font-weight:700">Total</td>
        <td class="vnum" style="text-align:right;font-weight:700"><?= numFmt($tot['bruto'], 2) ?></td>
        <td class="vnum" style="text-align:right"><?= numFmt($tot['fgts'], 2) ?></td>
        <td class="vnum" style="text-align:right"><?= numFmt($tot['inss_patronal'], 2) ?></td>
        <td class="vnum" style="text-align:right"><?= numFmt($tot['rat'], 2) ?></td>
        <td class="vnum" style="text-align:right"><?= numFmt($tot['terceiros'], 2) ?></td>
        <td class="vnum" style="text-align:right"><?= numFmt($tot['ferias'], 2) ?></td>
        <td class="vnum" style="text-align:right"><?= numFmt($tot['decimo'], 2) ?></td>
        <td class="vnum" style="text-align:right"><?= numFmt($tot['outros'], 2) ?></td>
        <td class="vnum" style="text-align:right;font-weight:700"><?= numFmt($tot['total'], 2) ?></td>
        <td class="vnum" style="text-align:right;font-weight:700;color:#005059"><?= numFmt($tot['custo_total'], 2) ?></td>
      </tr></tfoot>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar vigência' : 'Nova vigência de encargos' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="vfield">
          <label>Início da vigência *</label>
          <input type="date" name="vigencia_inicio" required value="<?= h($edit['vigencia_inicio'] ?? date('Y-m-d')) ?>">
        </div>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
        <?php foreach (ENCARGOS_CAMPOS as $campo => $rotulo): ?>
          <?= vero_f_text($campo, $rotulo . ' (%)',
                $edit ? numFmt((float)$edit[$campo], 3) : numFmt((float)($vigente[$campo] ?? 0), 3), true) ?>
        <?php endforeach; ?>
      </div>
      <div class="vhint" style="margin-top:8px">Seeds padrão: FGTS 8 · INSS 20 · RAT 2 · Terceiros 5,8 · Férias 11,11 · 13º 8,33 (≈ 55,24%).</div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
