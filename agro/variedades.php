<?php
/* ============================================================
   VERO — Gestão Agrícola / Variedades  (CRUD real)
   Substitui a tela mock. Rota da matriz: /agro/variedades.php
   Guard: agricola.variedades | Escrita: agro.variedades.editar/excluir
   Tabela: agro_variedades (migration 120)
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'agro_variedades';

/* tipo_uso: lista da modelagem (mig. 120) — confirmar rótulos reais com o cliente */
const TIPOS_USO = ['mesa' => 'Mesa', 'vinho' => 'Vinho', 'suco' => 'Suco', 'mista' => 'Mista'];

/* unidades aceitas para produtividade esperada (VARCHAR + whitelist — DB-16) */
const UNIDADES_PRODUTIVIDADE = [
    'kg_ha' => 'kg/ha', 't_ha' => 't/ha', 'sacas_ha' => 'sacas/ha',
    'arroba_ha' => '@/ha', 'litros_ha' => 'L/ha',
];

/* cor da baga (item 3b, mig. 156) — categórico VARCHAR + whitelist PHP */
const CORES_BAGA = ['vermelha' => 'Vermelha', 'branca' => 'Branca', 'preta' => 'Preta'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.variedades.editar');

        $id        = vero_int('id');
        $culturaId = vero_int('cultura_id');
        $nome      = vero_str('nome', 120);

        if (!$culturaId || $nome === null) {
            vero_flash('erro', 'Cultura e nome da variedade são obrigatórios.');
            vero_redirect();
        }
        /* cultura precisa pertencer ao tenant */
        $okCult = vero_val("SELECT id FROM agro_culturas WHERE id=:c AND tenant_id=:t",
            [':c' => $culturaId, ':t' => vero_tenant()]);
        if (!$okCult) {
            vero_flash('erro', 'Cultura inválida.');
            vero_redirect();
        }
        /* nome único por cultura (constraint uq_variedade_tenant_cultura_nome inclui inativas) */
        $dup = vero_val(
            "SELECT id FROM " . T . "
              WHERE tenant_id=:t AND cultura_id=:c AND nome=:n AND id<>:id",
            [':t' => vero_tenant(), ':c' => $culturaId, ':n' => $nome, ':id' => (int)$id]
        );
        if ($dup) {
            vero_flash('erro', "Já existe a variedade \"{$nome}\" nesta cultura (mesmo que inativa).");
            vero_redirect();
        }

        $tipoUso = vero_str('tipo_uso', 20);
        if ($tipoUso !== null && !isset(TIPOS_USO[$tipoUso])) $tipoUso = null;

        $corBaga = vero_str('cor_baga', 20);
        if ($corBaga !== null && !isset(CORES_BAGA[$corBaga])) $corBaga = null;

        $apirenica = vero_int('apirenica');
        if ($apirenica !== null) $apirenica = $apirenica ? 1 : 0;

        /* produtividade esperada é REFERÊNCIA (RT/gestor) — pré-preenche a meta do
           vínculo safra×talhão, nunca é imposta. Unidade FIXA em kg/ha (17/07). */
        $prodEsperada = vero_dec('produtividade_esperada');
        if ($prodEsperada !== null && $prodEsperada < 0) { /* A11: produtividade nunca é negativa */
            vero_flash('erro', 'A produtividade esperada não pode ser negativa.');
            vero_redirect();
        }

        /* WP-CALC Z-06: cachos por planta — alimenta o raleio (unidade 'cacho')
           na calculadora de MO. Referência do RT; nunca negativo. */
        $cachosPlanta = vero_dec('cachos_por_planta');
        if ($cachosPlanta !== null && $cachosPlanta < 0) {
            vero_flash('erro', 'Cachos por planta não pode ser negativo.');
            vero_redirect();
        }

        $data = [
            'cultura_id'    => $culturaId,
            'nome'          => $nome,
            'codigo'        => vero_str('codigo', 40),
            'tipo_uso'      => $tipoUso,
            'cor_baga'      => $corBaga,
            'apirenica'     => $apirenica,
            'ciclo_dias'    => vero_int('ciclo_dias'),
            'produtividade_esperada'    => $prodEsperada,
            'unidade_produtividade'     => $prodEsperada !== null ? 'kg_ha' : null,
            'cachos_por_planta'         => $cachosPlanta,
            'observacao_tecnica'        => vero_str('observacao_tecnica', 1000),
            'ativo'         => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', "Variedade \"{$nome}\" atualizada.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Variedade \"{$nome}\" cadastrada.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('agro.variedades.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q        = trim((string)($_GET['q'] ?? ''));
$fCultura = (int)($_GET['cultura'] ?? 0);
$page     = max(1, (int)($_GET['pg'] ?? 1));
$perPage  = 15;

$where  = "v.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    /* QA-011: placeholder repetido quebra com prepares nativos (HY093) — :q1..:qN */
    $where .= " AND (v.nome LIKE :q1 OR v.codigo LIKE :q2)";
    $params[':q1'] = $params[':q2'] = "%{$q}%";
}
if ($fCultura > 0) {
    $where .= " AND v.cultura_id = :c";
    $params[':c'] = $fCultura;
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " v WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT v.*, c.nome AS cultura_nome
       FROM " . T . " v
       JOIN agro_culturas c ON c.id = v.cultura_id
      WHERE {$where}
      ORDER BY v.ativo DESC, c.nome, v.nome
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$culturas = vero_options('agro_culturas', 'nome', 'ativo = 1');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'agricola', 'micro' => 'variedades'];
$PAGE_VIEW  = 'agricola_variedades';
$PAGE_TITLE = 'Variedades';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.variedades.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Variedades', 'Catálogo de variedades por cultura — base para faixas nutricionais, colheita e premiação',
        $podeEditar ? '+ Nova variedade' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="cultura" onchange="this.form.submit()">
          <option value="">Todas as culturas</option>
          <?php foreach ($culturas as $cid => $cn): ?>
            <option value="<?= $cid ?>"<?= $fCultura === $cid ? ' selected' : '' ?>><?= h($cn) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nome ou código…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">
        Nenhuma variedade encontrada.
        <?php if (!$culturas): ?><br>Cadastre primeiro uma <a href="<?= BIOS_BASE ?>/agro/culturas">cultura</a>.<?php endif; ?>
      </div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Nome</th><th>Cultura</th><th>Uso</th><th>Cor da baga</th><th>Apirênica</th>
        <th style="text-align:right">Ciclo (dias)</th>
        <th style="text-align:right">Produtividade esperada</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong><?= $r['codigo'] !== null && $r['codigo'] !== '' ? ' <span class="vhint vnum">' . h($r['codigo']) . '</span>' : '' ?></td>
          <td><?= h($r['cultura_nome']) ?></td>
          <td><?= $r['tipo_uso'] !== null && isset(TIPOS_USO[$r['tipo_uso']])
                    ? '<span class="vbadge vb-info">' . h(TIPOS_USO[$r['tipo_uso']]) . '</span>' : '—' ?></td>
          <td><?= isset($r['cor_baga']) && isset(CORES_BAGA[$r['cor_baga']]) ? h(CORES_BAGA[$r['cor_baga']]) : '—' ?></td>
          <td><?= $r['apirenica'] === null ? '—' : ((int)$r['apirenica'] === 1 ? 'Sim' : 'Não') ?></td>
          <td class="vnum" style="text-align:right"><?= $r['ciclo_dias'] !== null ? (int)$r['ciclo_dias'] . ' d' : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $r['produtividade_esperada'] !== null
                ? numFmt((float)$r['produtividade_esperada'], 0) . ' ' . h(UNIDADES_PRODUTIVIDADE[$r['unidade_produtividade']] ?? '')
                : '—' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <a class="vicon vicon-acao" href="<?= BIOS_BASE ?>/agro/variedade_fenologia?variedade_id=<?= (int)$r['id'] ?>" title="Fenologia da variedade" aria-label="Fenologia da variedade">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/></svg>
            </a>
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('agro.variedades.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar esta variedade?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar variedade' : 'Nova variedade' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_select('cultura_id', 'Cultura', $culturas, $edit['cultura_id'] ?? ($fCultura ?: null), true) ?></div>
        <?= vero_f_text('nome', 'Nome da variedade', $edit['nome'] ?? '', true, 'Ex.: Thompson Seedless, Vitória, BRS Isis') ?>
        <?= vero_f_text('codigo', 'Código (opcional)', $edit['codigo'] ?? '') ?>
        <?= vero_f_select('tipo_uso', 'Uso', TIPOS_USO, $edit['tipo_uso'] ?? null, false, '— Não informado —') ?>
        <?= vero_f_select('cor_baga', 'Cor da baga', CORES_BAGA, $edit['cor_baga'] ?? null, false, '—') ?>
        <?= vero_f_select('apirenica', 'Apirênica (sem semente)', [1 => 'Sim', 0 => 'Não'], $edit['apirenica'] ?? null, false, '— Não informado —') ?>
        <?= vero_f_text('ciclo_dias', 'Ciclo (dias)', isset($edit['ciclo_dias']) && $edit['ciclo_dias'] !== null ? (string)(int)$edit['ciclo_dias'] : '', false, 'Estimativa — confirmar com o RT') ?>
        <?= vero_f_text('produtividade_esperada', 'Produtividade esperada (kg/ha)', $edit && $edit['produtividade_esperada'] !== null ? numFmt((float)$edit['produtividade_esperada'], 0) : '', false, 'Referência em kg/ha — pré-preenche a meta no vínculo safra×talhão (editável)') ?>
        <?= vero_f_text('cachos_por_planta', 'Cachos por planta', $edit && isset($edit['cachos_por_planta']) && $edit['cachos_por_planta'] !== null ? numFmt((float)$edit['cachos_por_planta'], 2) : '', false, 'Média de cachos por planta — usada no raleio (calculadora de mão de obra)') ?>
        <div class="full"><?= vero_f_text('observacao_tecnica', 'Observações técnicas', $edit['observacao_tecnica'] ?? '', false, 'Manejo, sensibilidades, janela de mercado…') ?></div>
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
