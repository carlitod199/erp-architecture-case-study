<?php
/* ============================================================
   VERO — Gestão Agrícola / Fases Fenológicas  (CRUD real)
   Substitui a tela mock. Rota da matriz: /agro/fenologia.php
   Guard: agricola.fenologia | Escrita: agro.fenologia.editar/excluir
   Tabela: agro_fenologia_estagios (migration 120)
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'agro_fenologia_estagios';

/* Escalas da modelagem (mig. 120) — confirmar com o RT */
const ESCALAS = ['BBCH' => 'BBCH', 'E-L' => 'Eichhorn-Lorenz (E-L)', 'propria' => 'Própria'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.fenologia.editar');

        $id        = vero_int('id');
        $culturaId = vero_int('cultura_id');
        $escala    = vero_str('escala', 30);
        $codigo    = vero_str('codigo', 20);
        $nome      = vero_str('nome', 120);

        if (!$culturaId || $escala === null || $codigo === null || $nome === null) {
            vero_flash('erro', 'Cultura, escala, código e nome são obrigatórios.');
            vero_redirect();
        }
        if (!isset(ESCALAS[$escala])) {
            vero_flash('erro', 'Escala inválida.');
            vero_redirect();
        }
        $okCult = vero_val("SELECT id FROM agro_culturas WHERE id=:c AND tenant_id=:t",
            [':c' => $culturaId, ':t' => vero_tenant()]);
        if (!$okCult) {
            vero_flash('erro', 'Cultura inválida.');
            vero_redirect();
        }
        /* código único por cultura × escala (constraint inclui inativos) */
        $dup = vero_val(
            "SELECT id FROM " . T . "
              WHERE tenant_id=:t AND cultura_id=:c AND escala=:e AND codigo=:cod AND id<>:id",
            [':t' => vero_tenant(), ':c' => $culturaId, ':e' => $escala, ':cod' => $codigo, ':id' => (int)$id]
        );
        if ($dup) {
            vero_flash('erro', "Já existe o estágio \"{$codigo}\" nesta cultura e escala.");
            vero_redirect();
        }

        $data = [
            'cultura_id' => $culturaId,
            'escala'     => $escala,
            'codigo'     => $codigo,
            'ordem'      => vero_int('ordem') ?? 0,
            'nome'       => $nome,
            'descricao'  => vero_str('descricao', 2000),
            'ativo'      => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', "Estágio \"{$codigo} — {$nome}\" atualizado.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Estágio \"{$codigo} — {$nome}\" cadastrado.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('agro.fenologia.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }

    /* ── A1-29: períodos fenológicos por safra (agro_fenologia_periodos) ── */
    if ($acao === 'periodo_salvar') {
        vero_require('agro.fenologia.editar');

        $safraId = vero_int('p_safra_id');
        $estagio = vero_int('p_estagio_id');
        $ini     = vero_date('p_data_inicio');
        $fim     = vero_date('p_data_fim');
        if (!$safraId || !$estagio || $ini === null || $fim === null) {
            vero_flash('erro', 'Safra, estágio e as duas datas do período são obrigatórios.');
            vero_redirect('?periodos=' . (int)$safraId);
        }
        if ($ini > $fim) {
            vero_flash('erro', 'Data inicial do período maior que a final.');
            vero_redirect('?periodos=' . $safraId);
        }
        $okSafra = vero_val("SELECT id FROM agro_safras WHERE id=:i AND tenant_id=:t",
            [':i' => $safraId, ':t' => vero_tenant()]);
        $okEst = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t AND ativo=1",
            [':i' => $estagio, ':t' => vero_tenant()]);
        if (!$okSafra || !$okEst) {
            vero_flash('erro', 'Safra ou estágio inválido.');
            vero_redirect('?periodos=' . $safraId);
        }
        $stId = vero_int('p_safra_talhao_id') ?: null; // NULL = safra inteira
        if ($stId) {
            $okSt = vero_val("SELECT id FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t AND safra_id=:s",
                [':i' => $stId, ':t' => vero_tenant(), ':s' => $safraId]);
            if (!$okSt) {
                vero_flash('erro', 'O talhão selecionado não é desta safra.');
                vero_redirect('?periodos=' . $safraId);
            }
        }
        /* sobreposição no MESMO escopo = aviso (não trava — RT decide) */
        $sobrepoe = (int)vero_val(
            "SELECT COUNT(*) FROM agro_fenologia_periodos p
              WHERE p.tenant_id=:t AND p.safra_id=:s AND (p.safra_talhao_id <=> :st)
                AND p.data_inicio <= :fim AND p.data_fim >= :ini",
            [':t' => vero_tenant(), ':s' => $safraId, ':st' => $stId, ':ini' => $ini, ':fim' => $fim]);

        vero_insert('agro_fenologia_periodos', [
            'safra_id'            => $safraId,
            'safra_talhao_id'     => $stId,
            'fenologia_estagio_id'=> $estagio,
            'data_inicio'         => $ini,
            'data_fim'            => $fim,
        ]);
        vero_flash('ok', 'Período cadastrado — a fase passa a ser resolvida automaticamente pela data.');
        if ($sobrepoe > 0) {
            vero_flash('aviso', "O período se sobrepõe a {$sobrepoe} período(s) já cadastrado(s) no mesmo escopo — na resolução automática vale o mais específico/mais recente.");
        }
        vero_redirect('?periodos=' . $safraId);
    }

    if ($acao === 'periodo_excluir') {
        vero_require('agro.fenologia.excluir');
        $pid = vero_int('id');
        $safraId = vero_int('p_safra_id');
        if ($pid) {
            vero_pdo()->prepare("DELETE FROM agro_fenologia_periodos WHERE tenant_id=? AND id=? LIMIT 1")
                ->execute([vero_tenant(), $pid]);
            vero_flash('ok', 'Período removido.');
        }
        vero_redirect('?periodos=' . (int)$safraId);
    }

    /* A1-49 (DB-53): parâmetros de irrigação por FASE — registro do RT
       (volume/tempo ideais por cultura×estágio×tipo); usados só como
       SUGESTÃO-AVISO no apontamento de irrigação (nunca travam) */
    if ($acao === 'irrig_salvar') {
        vero_require('agro.fenologia.editar');
        $culturaId = vero_int('ip_cultura_id');
        $estagioId = vero_int('ip_estagio_id');
        $tipoIrr   = vero_str('ip_tipo', 20);
        if (!$culturaId || !$estagioId || $tipoIrr === null
            || !in_array($tipoIrr, ['gotejo', 'microaspersao', 'pivo', 'outro'], true)) {
            vero_flash('erro', 'Cultura, estágio e tipo de irrigação (gotejo/microaspersão/pivô/outro) são obrigatórios.');
            vero_redirect('?irrigacao=1');
        }
        $okC = vero_val("SELECT id FROM agro_culturas WHERE id=:i AND tenant_id=:t", [':i' => $culturaId, ':t' => vero_tenant()]);
        $okE = vero_val("SELECT id FROM agro_fenologia_estagios WHERE id=:i AND tenant_id=:t", [':i' => $estagioId, ':t' => vero_tenant()]);
        if (!$okC || !$okE) { vero_flash('erro', 'Cultura ou estágio inválido.'); vero_redirect('?irrigacao=1'); }
        $dup = vero_val(
            "SELECT id FROM irrigacao_fase_parametros
              WHERE tenant_id=:t AND cultura_id=:c AND estagio_id=:e AND tipo_irrigacao=:ti AND ativo=1 AND id<>:id",
            [':t' => vero_tenant(), ':c' => $culturaId, ':e' => $estagioId, ':ti' => $tipoIrr, ':id' => (int)vero_int('ip_id')]);
        if ($dup) { vero_flash('erro', 'Já existe parâmetro para esta cultura×estágio×tipo — edite o existente.'); vero_redirect('?irrigacao=1'); }
        $dados = [
            'cultura_id'        => $culturaId,
            'estagio_id'        => $estagioId,
            'tipo_irrigacao'    => $tipoIrr,
            'volume_ideal_m3_ha' => vero_dec('ip_volume'),
            'tempo_ideal_h'     => vero_dec('ip_tempo'),
            'observacao'        => vero_str('ip_obs', 255),
            'ativo'             => 1,
        ];
        $ipId = vero_int('ip_id');
        if ($ipId) { vero_update('irrigacao_fase_parametros', $ipId, $dados); vero_flash('ok', 'Parâmetro de irrigação atualizado (registro do RT — sugere, não trava).'); }
        else       { vero_insert('irrigacao_fase_parametros', $dados);        vero_flash('ok', 'Parâmetro de irrigação registrado (RT — sugere, não trava).'); }
        vero_redirect('?irrigacao=1');
    }

    if ($acao === 'irrig_excluir') {
        vero_require('agro.fenologia.excluir');
        $ipId = vero_int('id');
        if ($ipId) {
            vero_update('irrigacao_fase_parametros', $ipId, ['ativo' => 0]);
            vero_flash('ok', 'Parâmetro de irrigação inativado.');
        }
        vero_redirect('?irrigacao=1');
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q        = trim((string)($_GET['q'] ?? ''));
$fCultura = (int)($_GET['cultura'] ?? 0);
$page     = max(1, (int)($_GET['pg'] ?? 1));
$perPage  = 20;

$where  = "e.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    /* QA-011: placeholder repetido quebra com prepares nativos (HY093) — :q1..:qN */
    $where .= " AND (e.codigo LIKE :q1 OR e.nome LIKE :q2)";
    $params[':q1'] = $params[':q2'] = "%{$q}%";
}
if ($fCultura > 0) {
    $where .= " AND e.cultura_id = :c";
    $params[':c'] = $fCultura;
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " e WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT e.*, c.nome AS cultura_nome
       FROM " . T . " e
       JOIN agro_culturas c ON c.id = e.cultura_id
      WHERE {$where}
      ORDER BY e.ativo DESC, c.nome, e.escala, e.ordem, e.codigo
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$culturas = vero_options('agro_culturas', 'nome', 'ativo = 1');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

/* ── Painel de períodos por safra (?periodos=SAFRA_ID) — A1-29 ── */
$safrasOpt = vero_options('agro_safras', 'identificacao');
$painelSafra = (int)($_GET['periodos'] ?? 0);
$periodos = [];
$vinculosSafra = [];
$estagiosOpt = [];
if ($painelSafra > 0 && vero_val("SELECT id FROM agro_safras WHERE id=:i AND tenant_id=:t",
        [':i' => $painelSafra, ':t' => vero_tenant()])) {
    $periodos = vero_rows(
        "SELECT p.*, e.codigo AS est_codigo, e.nome AS est_nome, e.escala,
                tl.codigo AS talhao_codigo
           FROM agro_fenologia_periodos p
           JOIN " . T . " e ON e.id = p.fenologia_estagio_id
           LEFT JOIN agro_safra_talhoes st ON st.id = p.safra_talhao_id
           LEFT JOIN agro_talhoes tl ON tl.id = st.talhao_id
          WHERE p.tenant_id = :t AND p.safra_id = :s
          ORDER BY p.data_inicio, p.id",
        [':t' => vero_tenant(), ':s' => $painelSafra]);
    foreach (vero_rows(
        "SELECT st.id, CONCAT(f.nome, ' — ', tl.codigo, ' (', c.nome, ')') AS label
           FROM agro_safra_talhoes st
           JOIN agro_talhoes tl ON tl.id = st.talhao_id
           JOIN agro_fazendas f ON f.id = tl.fazenda_id
           JOIN agro_culturas c ON c.id = st.cultura_id
          WHERE st.tenant_id = :t AND st.safra_id = :s ORDER BY f.nome, tl.codigo",
        [':t' => vero_tenant(), ':s' => $painelSafra]) as $v) {
        $vinculosSafra[(int)$v['id']] = (string)$v['label'];
    }
    foreach (vero_rows(
        "SELECT e.id, CONCAT(c.nome, ' — ', e.codigo, ' ', e.nome) AS label
           FROM " . T . " e JOIN agro_culturas c ON c.id = e.cultura_id
          WHERE e.tenant_id = :t AND e.ativo = 1 ORDER BY c.nome, e.ordem",
        [':t' => vero_tenant()]) as $es) {
        $estagiosOpt[(int)$es['id']] = (string)$es['label'];
    }
} else {
    $painelSafra = 0;
}

$GUARD      = ['macro' => 'agricola', 'micro' => 'fenologia'];
$PAGE_VIEW  = 'agricola_fenologia';
$PAGE_TITLE = 'Fases Fenológicas';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.fenologia.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Fases Fenológicas', 'Estágios por cultura e escala (BBCH, E-L ou própria) — usados no apontamento e nas faixas nutricionais',
        $podeEditar ? '+ Novo estágio' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="cultura" onchange="this.form.submit()">
          <option value="">Todas as culturas</option>
          <?php foreach ($culturas as $cid => $cn): ?>
            <option value="<?= $cid ?>"<?= $fCultura === $cid ? ' selected' : '' ?>><?= h($cn) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por código ou nome…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">
        Nenhum estágio fenológico encontrado.
        <?php if (!$culturas): ?><br>Cadastre primeiro uma <a href="<?= BIOS_BASE ?>/agro/culturas">cultura</a>.<?php endif; ?>
      </div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th style="text-align:right">Ordem</th><th>Código</th><th>Nome</th>
        <th>Escala</th><th>Cultura</th><th>Descrição</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="vnum" style="text-align:right"><?= (int)$r['ordem'] ?></td>
          <td><strong class="vnum"><?= h($r['codigo']) ?></strong></td>
          <td><?= h($r['nome']) ?></td>
          <td><span class="vbadge vb-info"><?= h(ESCALAS[$r['escala']] ?? $r['escala']) ?></span></td>
          <td><?= h($r['cultura_nome']) ?></td>
          <td class="vhint"><?= h(mb_substr((string)($r['descricao'] ?? ''), 0, 60)) ?: '—' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('agro.fenologia.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este estágio?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>

  <!-- Períodos fenológicos por safra (A1-29 / DB-32): resolvem a fase AUTOMATICAMENTE pela data -->
  <div class="vcard" style="margin-top:20px">
    <div class="vtoolbar" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
      <strong>Períodos por safra <span class="vhint">(fase automática pela data — DF/IF e apontamentos)</span></strong>
      <form method="get" style="display:flex;gap:8px">
        <select name="periodos" onchange="this.form.submit()">
          <option value="">Escolha a safra…</option>
          <?php foreach ($safrasOpt as $sid => $sn): ?>
            <option value="<?= $sid ?>"<?= $painelSafra === $sid ? ' selected' : '' ?>><?= h($sn) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <?php if (!$painelSafra): ?>
      <div class="vempty">Selecione uma safra para cadastrar os períodos (ex.: poda 01–10/07, brotação 11–25/07…).</div>
    <?php else: ?>
      <?php if ($podeEditar): ?>
      <form method="post" class="vtoolbar" style="border-bottom:1px solid #EEE8DB;flex-wrap:wrap">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="periodo_salvar">
        <input type="hidden" name="p_safra_id" value="<?= $painelSafra ?>">
        <select name="p_estagio_id" required style="min-width:220px">
          <option value="">Estágio…</option>
          <?php foreach ($estagiosOpt as $eid => $el): ?><option value="<?= $eid ?>"><?= h($el) ?></option><?php endforeach; ?>
        </select>
        <select name="p_safra_talhao_id">
          <option value="">Safra inteira</option>
          <?php foreach ($vinculosSafra as $vid => $vl): ?><option value="<?= $vid ?>"><?= h($vl) ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="p_data_inicio" required>
        <input type="date" name="p_data_fim" required>
        <button class="vbtn vbtn-primary vbtn-sm" type="submit">Adicionar período</button>
      </form>
      <?php endif; ?>

      <?php if (!$periodos): ?>
        <div class="vempty">Nenhum período nesta safra — sem períodos a fase continua manual.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr>
          <th>Estágio</th><th>Escopo</th><th>Início</th><th>Fim</th><th style="text-align:right">Ações</th>
        </tr></thead>
        <tbody>
        <?php foreach ($periodos as $p): ?>
          <tr>
            <td><strong class="vnum"><?= h($p['est_codigo']) ?></strong> <?= h($p['est_nome']) ?>
                <span class="vhint">(<?= h($p['escala']) ?>)</span></td>
            <td><?= $p['talhao_codigo']
                  ? '<span class="vbadge vb-info">talhão ' . h((string)$p['talhao_codigo']) . '</span>'
                  : '<span class="vhint">safra inteira</span>' ?></td>
            <td class="vnum"><?= dateBR((string)$p['data_inicio']) ?></td>
            <td class="vnum"><?= dateBR((string)$p['data_fim']) ?></td>
            <td><div class="vactions">
              <?php if (vero_can('agro.fenologia.excluir')): ?>
              <form method="post" data-confirm="Remover este período?" data-confirm-danger data-confirm-ok="Remover" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="periodo_excluir">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="p_safra_id" value="<?= $painelSafra ?>">
                <button class="vicon vicon-del" type="submit" title="Remover" aria-label="Remover"><?= vero_ico_lixeira() ?></button>
              </form>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="vhint" style="padding:10px 14px">
        Resolução automática: período do TALHÃO vence o da safra inteira; sobreposição vale o mais recente.
        Sem período para a data, a fase fica manual.
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php /* A1-49 (DB-53): parâmetros de irrigação por fase — registro do RT */
    $irrigParams = vero_rows(
        "SELECT ip.*, c.nome AS cultura, CONCAT(e.codigo, ' — ', e.nome) AS estagio
           FROM irrigacao_fase_parametros ip
           JOIN agro_culturas c ON c.id = ip.cultura_id
           JOIN agro_fenologia_estagios e ON e.id = ip.estagio_id
          WHERE ip.tenant_id = :t AND ip.ativo = 1
          ORDER BY c.nome, e.ordem, e.codigo", [':t' => vero_tenant()]);
    $culturasIrr = vero_options('agro_culturas', 'nome', 'ativo = 1');
    $estagiosIrr = [];
    foreach (vero_rows("SELECT id, CONCAT(codigo, ' — ', nome) AS label FROM agro_fenologia_estagios WHERE tenant_id=:t ORDER BY ordem, codigo",
        [':t' => vero_tenant()]) as $ei) { $estagiosIrr[(int)$ei['id']] = (string)$ei['label']; }
    $TIPOS_IRR = ['gotejo' => 'Gotejo', 'microaspersao' => 'Microaspersão', 'pivo' => 'Pivô', 'outro' => 'Outro'];
  ?>
  <details class="vcard" style="margin-top:16px;padding:14px 16px"<?= !empty($_GET['irrigacao']) ? ' open' : '' ?>>
    <summary style="cursor:pointer"><strong>Irrigação por fase fenológica</strong>
      <span class="vhint">— volume/tempo ideais registrados pelo RT por cultura×estágio×tipo; SUGEREM no apontamento, nunca travam</span></summary>
    <?php if ($irrigParams): ?>
    <table class="vtable" style="margin-top:8px">
      <thead><tr><th>Cultura</th><th>Estágio</th><th>Tipo</th>
        <th style="text-align:right">Volume ideal (m³/ha)</th><th style="text-align:right">Tempo ideal (h)</th><th>Obs.</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($irrigParams as $ip): ?>
        <tr>
          <td><?= h((string)$ip['cultura']) ?></td>
          <td><?= h((string)$ip['estagio']) ?></td>
          <td><span class="vbadge vb-info"><?= h($TIPOS_IRR[(string)$ip['tipo_irrigacao']] ?? (string)$ip['tipo_irrigacao']) ?></span></td>
          <td class="vnum" style="text-align:right"><?= $ip['volume_ideal_m3_ha'] !== null ? numFmt((float)$ip['volume_ideal_m3_ha'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $ip['tempo_ideal_h'] !== null ? numFmt((float)$ip['tempo_ideal_h'], 1) : '—' ?></td>
          <td class="vhint"><?= h((string)($ip['observacao'] ?? '')) ?: '—' ?></td>
          <td><?php if ($podeEditar): ?>
            <form method="post" style="display:inline" data-confirm="Inativar este parâmetro?" data-confirm-danger data-confirm-ok="Inativar" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="irrig_excluir">
              <input type="hidden" name="id" value="<?= (int)$ip['id'] ?>">
              <button class="vicon vicon-del" type="submit" title="Inativar" aria-label="Inativar"><?= vero_ico_lixeira() ?></button>
            </form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <div class="vempty">Nenhum parâmetro registrado — o apontamento de irrigação funciona normalmente sem sugestão.</div>
    <?php endif; ?>
    <?php if ($podeEditar): ?>
    <form method="post" style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="irrig_salvar">
      <div class="vfield"><label>Cultura *</label>
        <select name="ip_cultura_id" required><option value="">—</option>
          <?php foreach ($culturasIrr as $cid => $cn): ?><option value="<?= $cid ?>"><?= h($cn) ?></option><?php endforeach; ?>
        </select></div>
      <div class="vfield"><label>Estágio *</label>
        <select name="ip_estagio_id" required><option value="">—</option>
          <?php foreach ($estagiosIrr as $eid => $en): ?><option value="<?= $eid ?>"><?= h($en) ?></option><?php endforeach; ?>
        </select></div>
      <div class="vfield"><label>Tipo *</label>
        <select name="ip_tipo" required>
          <?php foreach ($TIPOS_IRR as $tk => $tv): ?><option value="<?= $tk ?>"><?= h($tv) ?></option><?php endforeach; ?>
        </select></div>
      <div class="vfield"><label>Volume (m³/ha)</label><input type="text" name="ip_volume" style="width:110px;text-align:right"></div>
      <div class="vfield"><label>Tempo (h)</label><input type="text" name="ip_tempo" style="width:90px;text-align:right"></div>
      <div class="vfield"><label>Obs.</label><input type="text" name="ip_obs" style="width:160px"></div>
      <button class="vbtn vbtn-primary" type="submit">Registrar</button>
    </form>
    <?php endif; ?>
  </details>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar estágio' : 'Novo estágio fenológico' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_select('cultura_id', 'Cultura', $culturas, $edit['cultura_id'] ?? ($fCultura ?: null), true) ?>
        <?= vero_f_select('escala', 'Escala', ESCALAS, $edit['escala'] ?? null, true) ?>
        <?= vero_f_text('codigo', 'Código', $edit['codigo'] ?? '', true, 'Ex.: EL-12, BBCH-65') ?>
        <?= vero_f_text('ordem', 'Ordem de exibição', $edit ? (string)(int)$edit['ordem'] : '', false, 'Número para ordenar os estágios') ?>
        <div class="full"><?= vero_f_text('nome', 'Nome do estágio', $edit['nome'] ?? '', true, 'Ex.: Floração plena, Baga ervilha') ?></div>
        <div class="full"><?= vero_f_text('descricao', 'Descrição (opcional)', $edit['descricao'] ?? '') ?></div>
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
