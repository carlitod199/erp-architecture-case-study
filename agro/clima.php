<?php
/* ============================================================
   VERO — Agrícola / Clima e Chuvas  (CRUD real)
   Rota da matriz: /agro/clima.php (rota real via A0 — $rotasReais)
   Guard: agricola.clima | Escrita: agro.clima.editar/excluir
   Tabela: clima_registros (DB-20260704-04 / migration 134).
   Registro MANUAL de pluviômetro por fazenda (válvula opcional):
   chuva do dia, temperaturas mín/máx. Nenhuma recomendação é
   derivada (P-14: escopo mínimo; estação automática/API = fase 3).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_setor_espelho.php'; /* A1-36: rótulo P-57 (válvula = válvula) */

const T = 'clima_registros';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.clima.editar');

        $id        = vero_int('id');
        $fazendaId = vero_int('fazenda_id');
        $data      = vero_date('data');
        $chuva     = vero_dec('chuva_mm');
        if (!$fazendaId || $data === null) {
            vero_flash('erro', 'Fazenda e data são obrigatórias.');
            vero_redirect();
        }
        $okFaz = vero_val("SELECT id FROM agro_fazendas WHERE id=:f AND tenant_id=:t",
            [':f' => $fazendaId, ':t' => vero_tenant()]);
        if (!$okFaz) {
            vero_flash('erro', 'Fazenda inválida.');
            vero_redirect();
        }
        $talhaoId = vero_int('talhao_id');
        if ($talhaoId) {
            $okTal = vero_val("SELECT id FROM agro_talhoes WHERE id=:i AND tenant_id=:t AND fazenda_id=:f",
                [':i' => $talhaoId, ':t' => vero_tenant(), ':f' => $fazendaId]);
            if (!$okTal) {
                vero_flash('erro', 'A válvula selecionada não pertence à fazenda escolhida.');
                vero_redirect();
            }
        }
        /* 1 lançamento por fazenda(+válvula)×data — validação na tela
           (UNIQUE com NULL não é confiável no MySQL 5.7) */
        $dup = vero_val(
            "SELECT id FROM " . T . "
              WHERE tenant_id = :t AND fazenda_id = :f AND data = :d
                AND (talhao_id <=> :tl) AND id <> :id",
            [':t' => vero_tenant(), ':f' => $fazendaId, ':d' => $data,
             ':tl' => $talhaoId ?: null, ':id' => (int)$id]
        );
        if ($dup) {
            vero_flash('erro', 'Já existe lançamento para esta fazenda' . ($talhaoId ? '/válvula' : '') . " em " . dateBR($data) . '. Edite o registro existente.');
            vero_redirect();
        }
        $tMin = vero_dec('temp_min');
        $tMax = vero_dec('temp_max');
        if ($tMin !== null && $tMax !== null && $tMin > $tMax) {
            vero_flash('erro', 'Temperatura mínima maior que a máxima — confira os valores.');
            vero_redirect();
        }

        $dados = [
            'fazenda_id' => $fazendaId,
            'talhao_id'  => $talhaoId ?: null,
            'data'       => $data,
            'chuva_mm'   => $chuva,
            'temp_min'   => $tMin,
            'temp_max'   => $tMax,
            'observacao' => vero_str('observacao', 255),
        ];
        if ($id) {
            vero_update(T, $id, $dados);
            vero_flash('ok', 'Registro de ' . dateBR($data) . ' atualizado.');
        } else {
            vero_insert(T, $dados);
            vero_flash('ok', 'Registro de ' . dateBR($data) . ' lançado.');
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('agro.clima.excluir');
        $id = vero_int('id');
        if ($id) {
            /* sem coluna ativo e sem dependências → DELETE físico */
            vero_pdo()->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=? LIMIT 1")
                ->execute([vero_tenant(), (int)$id]);
            vero_flash('ok', 'Registro excluído.');
        }
        vero_redirect();
    }

    /* A1-34 (P-32): faixas climáticas de referência REGISTRADAS pelo RT —
       usadas só para AVISO visual na aplicação (Regra 1: sinaliza, nunca
       recomenda; a decisão de aplicar é sempre do RT). Vazio = sem faixa. */
    if ($acao === 'faixas_rt') {
        vero_require('agro.clima.editar');
        foreach (['aplic_vento_max_kmh' => 'faixa_vento',
                  'aplic_temp_max_c'    => 'faixa_temp',
                  'aplic_ur_min_pct'    => 'faixa_ur'] as $chave => $campo) {
            $v = vero_dec($campo);
            vero_srv_param_set('agro.' . $chave, $v !== null ? (string)$v : '');
        }
        vero_flash('ok', 'Faixas de referência do RT atualizadas — a aplicação passa a AVISAR quando o clima registrado sair delas (sem travar).');
        vero_redirect();
    }
}

/* ── Filtros / listagem ─────────────────────────────────────── */
$fFazenda = (int)($_GET['fazenda'] ?? 0);
$fMes     = (string)($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $fMes)) $fMes = date('Y-m');
$mesIni = $fMes . '-01';
$mesFim = date('Y-m-t', strtotime($mesIni));

$where  = "c.tenant_id = :t AND c.data BETWEEN :ini AND :fim";
$params = [':t' => vero_tenant(), ':ini' => $mesIni, ':fim' => $mesFim];
if ($fFazenda > 0) {
    $where .= " AND c.fazenda_id = :f";
    $params[':f'] = $fFazenda;
}

$rows = vero_rows(
    "SELECT c.*, f.nome AS fazenda_nome, tl.codigo AS talhao_codigo
       FROM " . T . " c
       JOIN agro_fazendas f ON f.id = c.fazenda_id
       LEFT JOIN agro_talhoes tl ON tl.id = c.talhao_id
      WHERE {$where}
      ORDER BY c.data DESC, f.nome, tl.codigo",
    $params
);

/* KPIs do mês filtrado + acumulado do ano */
$kpiMes = ['mm' => 0.0, 'dias' => 0, 'max' => 0.0];
$diasComChuva = [];
foreach ($rows as $r) {
    $mm = (float)($r['chuva_mm'] ?? 0);
    $kpiMes['mm'] += $mm;
    if ($mm > 0) $diasComChuva[(string)$r['data']] = true;
    if ($mm > $kpiMes['max']) $kpiMes['max'] = $mm;
}
$kpiMes['dias'] = count($diasComChuva);

$anoIni = substr($fMes, 0, 4) . '-01-01';
$pAno = [':t' => vero_tenant(), ':ini' => $anoIni, ':fim' => $mesFim];
$sqlAno = "SELECT COALESCE(SUM(c.chuva_mm),0) FROM " . T . " c
            WHERE c.tenant_id = :t AND c.data BETWEEN :ini AND :fim";
if ($fFazenda > 0) { $sqlAno .= " AND c.fazenda_id = :f"; $pAno[':f'] = $fFazenda; }
$acumAno = (float)(vero_val($sqlAno, $pAno) ?? 0);

$fazendas = vero_options('agro_fazendas', 'nome', 'ativo = 1');
$talhoesOpt = [];
foreach (vero_rows(
    "SELECT t.id, t.fazenda_id, CONCAT(f.nome, ' — ', t.codigo) AS label
       FROM agro_talhoes t JOIN agro_fazendas f ON f.id = t.fazenda_id
      WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo",
    [':t' => vero_tenant()]
) as $r) { $talhoesOpt[(int)$r['id']] = ['label' => (string)$r['label'], 'fazenda' => (int)$r['fazenda_id']]; }

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'agricola', 'micro' => 'clima'];
$PAGE_VIEW  = 'agricola_clima';
$PAGE_TITLE = 'Clima e Chuvas';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.clima.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Clima e Chuvas', 'Registro manual do pluviômetro por fazenda (válvula opcional) — o sistema não deriva recomendações',
        $podeEditar ? '+ Novo lançamento' : null) ?>

  <div class="vcard" style="padding:11px 16px;margin-bottom:14px;display:flex;gap:28px;flex-wrap:wrap;align-items:baseline">
    <div><span class="vhint">Chuva no mês&nbsp;</span><strong class="vnum" style="font-size:16px"><?= numFmt($kpiMes['mm'], 1) ?> mm</strong></div>
    <div><span class="vhint">Dias com chuva&nbsp;</span><strong class="vnum" style="font-size:16px"><?= $kpiMes['dias'] ?></strong></div>
    <div><span class="vhint">Maior chuva&nbsp;</span><strong class="vnum" style="font-size:16px"><?= numFmt($kpiMes['max'], 1) ?> mm</strong></div>
    <div style="margin-left:auto"><span class="vhint">Acumulado no ano&nbsp;</span><strong class="vnum" style="font-size:16px;color:#005059"><?= numFmt($acumAno, 1) ?> mm</strong></div>
  </div>

  <?php if (vero_can('agro.clima.editar')): /* A1-34 (P-32) */ ?>
  <details class="vcard" style="margin-bottom:16px;padding:14px 16px">
    <summary style="cursor:pointer"><strong>Faixas de referência do RT para aplicação</strong>
      <span class="vhint">— REGISTRO do RT: fora delas a aplicação mostra AVISO, nunca trava</span></summary>
    <?php
      $fVento = vero_srv_param('agro.aplic_vento_max_kmh', '');
      $fTemp  = vero_srv_param('agro.aplic_temp_max_c', '');
      $fUr    = vero_srv_param('agro.aplic_ur_min_pct', '');
    ?>
    <form method="post" style="display:flex;gap:12px;margin-top:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="faixas_rt">
      <div class="vfield"><label>Vento máx. (km/h)</label>
        <input type="text" name="faixa_vento" value="<?= $fVento !== '' ? h(numFmt((float)$fVento, 1)) : '' ?>" placeholder="ex.: 10"></div>
      <div class="vfield"><label>Temperatura máx. (°C)</label>
        <input type="text" name="faixa_temp" value="<?= $fTemp !== '' ? h(numFmt((float)$fTemp, 1)) : '' ?>" placeholder="ex.: 30"></div>
      <div class="vfield"><label>Umidade relativa mín. (%)</label>
        <input type="text" name="faixa_ur" value="<?= $fUr !== '' ? h(numFmt((float)$fUr, 0)) : '' ?>" placeholder="ex.: 55"></div>
      <button class="vbtn vbtn-primary" type="submit">Salvar faixas</button>
      <div class="vhint" style="width:100%">Vazio = sem faixa (sem aviso). O sistema NÃO recomenda condição de aplicação — as faixas são o registro da orientação do RT.</div>
    </form>
  </details>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="fazenda" onchange="this.form.submit()">
          <option value="">Todas as fazendas</option>
          <?php foreach ($fazendas as $fid => $fn): ?>
            <option value="<?= $fid ?>"<?= $fFazenda === $fid ? ' selected' : '' ?>><?= h($fn) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="month" name="mes" value="<?= h($fMes) ?>" onchange="this.form.submit()">
        <button class="vbtn vbtn-ghost" type="submit">Filtrar</button>
      </form>
      <span class="vsub"><?= count($rows) ?> lançamento(s) no mês</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum lançamento em <?= h(date('m/Y', strtotime($mesIni))) ?>.
        <?= $podeEditar ? 'Lance a leitura diária do pluviômetro.' : '' ?></div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Data</th><th>Fazenda</th><th><?= h(vero_a1_rotulo_area()) ?></th>
        <th class="num">Chuva (mm)</th>
        <th class="num">Temp. mín (°C)</th>
        <th class="num">Temp. máx (°C)</th>
        <th>Observação</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $mm = $r['chuva_mm'] !== null ? (float)$r['chuva_mm'] : null; ?>
        <tr>
          <td class="vnum"><?= dateBR((string)$r['data']) ?></td>
          <td><?= h($r['fazenda_nome']) ?></td>
          <td><?= $r['talhao_codigo'] ? '<strong class="vnum">' . h((string)$r['talhao_codigo']) . '</strong>' : '<span class="vhint">fazenda toda</span>' ?></td>
          <td class="num"><strong<?= $mm !== null && $mm > 0 ? ' style="color:#1D6FA5"' : '' ?>><?= $mm !== null ? numFmt($mm, 1) : '—' ?></strong></td>
          <td class="num"><?= $r['temp_min'] !== null ? numFmt((float)$r['temp_min'], 1) : '—' ?></td>
          <td class="num"><?= $r['temp_max'] !== null ? numFmt((float)$r['temp_max'], 1) : '—' ?></td>
          <td class="vhint"><?= h($r['observacao'] ?? '') ?: '—' ?></td>
          <td class="num"><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('agro.clima.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este lançamento de chuva?') ?>
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
      <h2><?= $edit ? 'Editar lançamento' : 'Novo lançamento de clima' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_select('fazenda_id', 'Fazenda', $fazendas, $edit['fazenda_id'] ?? ($fFazenda ?: null), true) ?></div>
        <div class="full">
          <div class="vfield">
            <label><?= h(vero_a1_rotulo_area()) ?> (opcional — vazio = fazenda toda)</label>
            <select name="talhao_id" id="clima-talhao">
              <option value="">— Fazenda toda —</option>
              <?php foreach ($talhoesOpt as $tid => $tinfo): ?>
                <option value="<?= $tid ?>" data-fazenda="<?= $tinfo['fazenda'] ?>"
                  <?= $edit && (int)($edit['talhao_id'] ?? 0) === $tid ? ' selected' : '' ?>><?= h($tinfo['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?= vero_f_text('data', 'Data', $edit['data'] ?? date('Y-m-d'), true, '', 'date') ?>
        <?= vero_f_text('chuva_mm', 'Chuva (mm)', $edit && $edit['chuva_mm'] !== null ? numFmt((float)$edit['chuva_mm'], 1) : '', false, '0 = sem chuva no dia') ?>
        <?= vero_f_text('temp_min', 'Temp. mínima (°C)', $edit && $edit['temp_min'] !== null ? numFmt((float)$edit['temp_min'], 1) : '') ?>
        <?= vero_f_text('temp_max', 'Temp. máxima (°C)', $edit && $edit['temp_max'] !== null ? numFmt((float)$edit['temp_max'], 1) : '') ?>
        <div class="full"><?= vero_f_text('observacao', 'Observação', $edit['observacao'] ?? '', false, 'Ex.: chuva à tarde, granizo…') ?></div>
      </div>
      <div class="vhint" style="margin-top:8px">Um lançamento por fazenda (ou válvula) por dia. Chuva registrada aparece na Ficha da Válvula e no detalhe das aplicações.</div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
