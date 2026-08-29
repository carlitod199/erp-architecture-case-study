<?php
/* ============================================================
   VERO — Packing House / Licenças de Variedade  (CRUD real)
   Rota: /packing/licencas_varietais.php
   Guard: packing.licencas_varietais
   Tabela: ph_licencas_varietais (migration 199).
   Registra a proteção varietal (marca registrada e/ou obtentor/
   cultivar) usada no embalamento: vigência, mercados autorizados,
   alíquota de royalty e base de cálculo.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'ph_licencas_varietais';

/* Categóricos = VARCHAR + whitelist em PHP (convenção do projeto). */
const LIC_TIPOS_PROTECAO = ['marca' => 'Marca registrada', 'obtentor' => 'Obtentor (cultivar)'];
const LIC_BASES_CALCULO  = [
    'kg_exportado'   => 'Kg exportado',
    'kg_embalado'    => 'Kg embalado',
    'caixa'          => 'Caixa embalada',
    'valor_fob'      => 'Valor FOB',
    'receita_liquida'=> 'Receita líquida',
];
const LIC_STATUS = ['ativo' => 'Ativo', 'suspenso' => 'Suspenso', 'expirado' => 'Expirado'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('packing.licencas_varietais.editar');
        $id = vero_int('id');

        $denominacao = vero_str('denominacao_varietal', 120);
        $marca       = vero_str('marca_comercial', 120);
        if ($denominacao === null && $marca === null) {
            vero_flash('erro', 'Informe ao menos a denominação varietal ou a marca comercial.');
            vero_redirect();
        }

        /* FK sempre validada contra o tenant (auditoria seg. 23/07). */
        $variedadeId = vero_fk_tenant('agro_variedades', vero_int('variedade_id'));

        /* Categóricos: só aceita valor da whitelist, senão null. */
        $tipoProtecao = vero_str('tipo_protecao', 30);
        if ($tipoProtecao !== null && !isset(LIC_TIPOS_PROTECAO[$tipoProtecao])) $tipoProtecao = null;
        $baseCalculo = vero_str('base_calculo', 40);
        if ($baseCalculo !== null && !isset(LIC_BASES_CALCULO[$baseCalculo])) $baseCalculo = null;
        $status = vero_str('status', 20);
        if ($status === null || !isset(LIC_STATUS[$status])) $status = 'ativo';

        /* Mercados autorizados: campo texto separado por vírgula -> JSON array. */
        $mercadosRaw = (string)($_POST['mercados_autorizados'] ?? '');
        $mercados = [];
        foreach (preg_split('/[,\n;]+/', $mercadosRaw) as $m) {
            $m = trim($m);
            if ($m !== '') $mercados[] = mb_substr($m, 0, 60);
        }
        $mercadosJson = $mercados ? json_encode(array_values(array_unique($mercados)), JSON_UNESCAPED_UNICODE) : null;

        /* Vigência: fim não pode ser anterior ao início. */
        $vigInicio = vero_date('vigencia_inicio');
        $vigFim    = vero_date('vigencia_fim');
        if ($vigInicio !== null && $vigFim !== null && $vigFim < $vigInicio) {
            vero_flash('erro', 'A vigência final não pode ser anterior à inicial.');
            vero_redirect();
        }

        $data = [
            'variedade_id'         => $variedadeId,
            'denominacao_varietal' => $denominacao,
            'marca_comercial'      => $marca,
            'obtentor'             => vero_str('obtentor', 120),
            'licenciante'          => vero_str('licenciante', 120),
            'tipo_protecao'        => $tipoProtecao,
            'vigencia_inicio'      => $vigInicio,
            'vigencia_fim'         => $vigFim,
            'mercados_autorizados' => $mercadosJson,
            'aliquota_pct'         => vero_dec('aliquota_pct'),
            'base_calculo'         => $baseCalculo,
            'status'               => $status,
        ];

        $rotulo = $marca ?? $denominacao;
        if ($id) {
            /* garante que a linha é do tenant antes de atualizar */
            $ok = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => vero_tenant()]);
            if (!$ok) { vero_flash('erro', 'Licença não encontrada.'); vero_redirect(); }
            vero_update(T, $id, $data);
            vero_flash('ok', "Licença \"{$rotulo}\" atualizada.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Licença \"{$rotulo}\" cadastrada.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('packing.licencas_varietais.excluir');
        $id = vero_int('id');
        if ($id) {
            $ok = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => vero_tenant()]);
            if ($ok) vero_delete(T, $id);
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$rows = vero_rows(
    "SELECT l.*, v.nome AS variedade_nome
       FROM " . T . " l
       LEFT JOIN agro_variedades v ON v.id = l.variedade_id AND v.tenant_id = l.tenant_id
      WHERE l.tenant_id = :t
      ORDER BY (l.status = 'ativo') DESC, l.marca_comercial, l.denominacao_varietal",
    [':t' => vero_tenant()]);

$variedades = vero_options('agro_variedades', 'nome', 'ativo = 1');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t2",
        [':id' => (int)$_GET['editar'], ':t2' => vero_tenant()]);
}
$editMercados = '';
if ($edit && $edit['mercados_autorizados'] !== null && $edit['mercados_autorizados'] !== '') {
    $dec = json_decode((string)$edit['mercados_autorizados'], true);
    if (is_array($dec)) $editMercados = implode(', ', $dec);
}

$GUARD      = ['macro' => 'packing', 'micro' => 'licencas_varietais'];
$PAGE_VIEW  = 'packing_licencas_varietais';
$PAGE_TITLE = 'Licenças de Variedade';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('packing.licencas_varietais.editar');

/** Badge de status conforme o valor textual. */
function lic_badge_status(string $status): string
{
    $label = LIC_STATUS[$status] ?? $status;
    $cls = $status === 'ativo' ? 'vb-ok' : ($status === 'suspenso' ? 'vb-warn' : 'vb-off');
    return '<span class="vbadge ' . $cls . '">' . h($label) . '</span>';
}
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <header class="vero-topbar">
    <h1 class="vero-topbar__title">Licenças de Variedade</h1>
    <div class="vero-topbar__actions">
      <?php if ($podeEditar): ?><?= vero_btn_icone('<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>', 'Nova licença', "vModalNovo('vm-form')") ?><?php endif; ?>
    </div>
  </header>

  <div class="vcard">
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma licença de variedade cadastrada. Registre marcas e cultivares protegidas usadas no embalamento.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Marca comercial</th><th>Denominação varietal</th><th>Variedade</th>
        <th>Proteção</th><th>Vigência</th>
        <th style="text-align:right">Alíquota</th>
        <th>Mercados</th><th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $merc = '';
        if ($r['mercados_autorizados'] !== null && $r['mercados_autorizados'] !== '') {
            $d = json_decode((string)$r['mercados_autorizados'], true);
            if (is_array($d)) $merc = implode(', ', $d);
        }
        $vig = '—';
        if ($r['vigencia_inicio'] || $r['vigencia_fim']) {
            $vig = dateBR($r['vigencia_inicio'] ?? null) . ' → ' . dateBR($r['vigencia_fim'] ?? null);
        }
      ?>
        <tr>
          <td><strong><?= h($r['marca_comercial'] ?? '') ?: '—' ?></strong></td>
          <td class="vhint"><em><?= h($r['denominacao_varietal'] ?? '') ?: '—' ?></em></td>
          <td><?= h($r['variedade_nome'] ?? '') ?: '—' ?></td>
          <td><?= isset(LIC_TIPOS_PROTECAO[(string)$r['tipo_protecao']]) ? h(LIC_TIPOS_PROTECAO[(string)$r['tipo_protecao']]) : '—' ?></td>
          <td class="vhint"><?= h($vig) ?></td>
          <td class="vnum" style="text-align:right"><?= $r['aliquota_pct'] !== null ? numFmt((float)$r['aliquota_pct'], 3) . '%' : '—' ?></td>
          <td class="vhint"><?= h($merc) ?: '—' ?></td>
          <td><?= lic_badge_status((string)$r['status']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('packing.licencas_varietais.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir esta licença de variedade?') ?>
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
      <h2><?= $edit ? 'Editar licença' : 'Nova licença de variedade' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('marca_comercial', 'Marca comercial (registrada)', $edit['marca_comercial'] ?? '', false, 'Ex.: Sugraone, Autumn Crisp') ?>
        <?= vero_f_text('denominacao_varietal', 'Denominação varietal (botânica)', $edit['denominacao_varietal'] ?? '', false, 'Ex.: Superior Seedless') ?>
        <?= vero_f_select('variedade_id', 'Variedade vinculada', $variedades, $edit['variedade_id'] ?? null, false, '— Não vinculada —') ?>
        <?= vero_f_select('tipo_protecao', 'Tipo de proteção', LIC_TIPOS_PROTECAO, $edit['tipo_protecao'] ?? null, false, '— Selecione —') ?>
        <?= vero_f_text('obtentor', 'Obtentor', $edit['obtentor'] ?? '', false, 'Detentor do direito de cultivar') ?>
        <?= vero_f_text('licenciante', 'Licenciante', $edit['licenciante'] ?? '', false, 'Quem licencia o uso') ?>
        <?= vero_f_text('vigencia_inicio', 'Vigência — início', $edit['vigencia_inicio'] ?? '', false, '', 'date') ?>
        <?= vero_f_text('vigencia_fim', 'Vigência — fim', $edit['vigencia_fim'] ?? '', false, '', 'date') ?>
        <?= vero_f_text('aliquota_pct', 'Alíquota (%)', $edit['aliquota_pct'] ?? '', false, 'Royalty — ex.: 3,5') ?>
        <?= vero_f_select('base_calculo', 'Base de cálculo', LIC_BASES_CALCULO, $edit['base_calculo'] ?? null, false, '— Selecione —') ?>
        <div class="full"><?= vero_f_text('mercados_autorizados', 'Mercados autorizados', $editMercados, false, 'Separe por vírgula — ex.: EUA, União Europeia, Reino Unido') ?></div>
        <?= vero_f_select('status', 'Status', LIC_STATUS, $edit['status'] ?? 'ativo', true, '') ?>
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
