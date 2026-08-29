<?php
/* ============================================================
   VERO — Irrigação / Apontamentos  (tela real)
   Substitui o mock. Rota: /irrigacao/apontamentos_irrigacao.php
   Guard: irrigacao.apontamentos_irrigacao
   Tabelas: irrigacao_apontamentos + irrigacao_consumos.
   Apontamento por válvula × data (horas, lâmina) com consumos de
   ÁGUA (m³) e ENERGIA (kWh) — cada consumo com custo emite
   custeio_lancamentos (origem irrigacao_consumo, categoria
   irrigacao) amarrado a válvula/safra. Reedição/exclusão reemitem
   e removem tudo (idempotente).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/../custeio/_plano_map.php'; /* A3-T10: plano de contas no custeio */
require_once __DIR__ . '/../agro/_fenologia_helper.php'; /* A1-48d: fase pela data (sugestão DB-53) */
require_once __DIR__ . '/../agro/_setor_espelho.php';    /* tipo de irrigação do espelho */

const T = 'irrigacao_apontamentos';

/** Remove consumos + custeio de um apontamento (idempotência). */
function irr_limpar_consumos(int $apontId): void
{
    $t = vero_tenant();
    $pdo = vero_pdo();
    $pdo->prepare("DELETE cl FROM custeio_lancamentos cl
                    WHERE cl.tenant_id = ? AND cl.origem_tipo = 'irrigacao_consumo'
                      AND cl.origem_id IN (SELECT id FROM irrigacao_consumos WHERE tenant_id = ? AND apontamento_id = ?)")
        ->execute([$t, $t, $apontId]);
    $pdo->prepare("DELETE FROM irrigacao_consumos WHERE tenant_id = ? AND apontamento_id = ?")
        ->execute([$t, $apontId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    /* C-21: tarifas do tenant (R$/m³ e R$/kWh) — chave irrigacao.tarifas em
       tenant_parametros (JSON; precedente T30/FUNRURAL — sem migration). */
    if ($acao === 'salvar_tarifas') {
        vero_require('irrigacao.apontamentos_irrigacao.editar');
        $tAgua = vero_dec('tarifa_agua_m3');
        $tEner = vero_dec('tarifa_energia_kwh');
        if (($tAgua !== null && $tAgua < 0) || ($tEner !== null && $tEner < 0)) {
            vero_flash('erro', 'Tarifa não pode ser negativa.');
            vero_redirect();
        }
        vero_srv_param_set('irrigacao.tarifas', json_encode([
            'agua_m3'     => $tAgua !== null ? round($tAgua, 4) : null,
            'energia_kwh' => $tEner !== null ? round($tEner, 4) : null,
        ], JSON_UNESCAPED_UNICODE), 'Tarifas de irrigação: R$/m³ de água e R$/kWh de energia — custo automático dos consumos');
        vero_flash('ok', 'Tarifas gravadas. Novos apontamentos calculam o custo automaticamente (qtd × tarifa); custo digitado prevalece.');
        vero_redirect();
    }

    if ($acao === 'salvar') {
        vero_require('irrigacao.apontamentos_irrigacao.editar');

        $id       = vero_int('id');
        $talhaoId = vero_int('talhao_id');
        $data     = vero_date('data_apontamento');
        $horas    = vero_dec('horas') ?? 0;
        $lamina   = vero_dec('lamina_mm') ?? 0;

        $talhao = $talhaoId ? vero_row("SELECT * FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
            [':i' => $talhaoId, ':t' => vero_tenant()]) : null;
        if (!$talhao || $data === null) {
            vero_flash('erro', 'Válvula e data são obrigatórios.');
            vero_redirect();
        }
        $stId = vero_int('safra_talhao_id');
        $vinculo = null;
        if ($stId) {
            $vinculo = vero_row("SELECT * FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t AND talhao_id=:ta",
                [':i' => $stId, ':t' => vero_tenant(), ':ta' => $talhaoId]);
            if (!$vinculo) $stId = null;
        }
        if ($vinculo) { /* A3-T6 (P-06): safra fechada não recebe custeio */
            $guardSafra = vero_srv_custeio_pode_lancar((int)$vinculo['safra_id']);
            if (!$guardSafra['pode']) {
                vero_flash('erro', $guardSafra['motivo']);
                vero_redirect();
            }
        }

        /* C-21: tarifas do tenant → CUSTO AUTOMÁTICO quando o
           campo vier vazio (qtd × R$/m³ ou R$/kWh). Custo digitado prevalece. */
        $tarifasIrr = json_decode((string)(vero_srv_param('irrigacao.tarifas') ?? ''), true) ?: [];
        $tarifaDe = ['agua' => (float)($tarifasIrr['agua_m3'] ?? 0), 'energia' => (float)($tarifasIrr['energia_kwh'] ?? 0)];

        $consumos = [];
        foreach (['agua' => ['qtd' => 'agua_qtd', 'custo' => 'agua_custo', 'unidade' => 'm3'],
                  'energia' => ['qtd' => 'energia_qtd', 'custo' => 'energia_custo', 'unidade' => 'kWh']] as $tipo => $campos) {
            $qtd   = vero_dec($campos['qtd']);
            $custo = vero_dec($campos['custo']);
            if ($custo === null && $qtd !== null && $qtd > 0 && $tarifaDe[$tipo] > 0) {
                $custo = round($qtd * $tarifaDe[$tipo], 2);   /* C-21: rede do servidor (funciona sem JS) */
            }
            if (($qtd !== null && $qtd > 0) || ($custo !== null && $custo > 0)) {
                $consumos[] = ['tipo' => $tipo, 'quantidade' => $qtd ?? 0,
                               'unidade' => $campos['unidade'], 'custo' => round($custo ?? 0, 2)];
            }
        }

        $cab = [
            'talhao_id'        => $talhaoId,
            'safra_talhao_id'  => $stId,
            'horas'            => $horas,
            'lamina_mm'        => $lamina,
            'data_apontamento' => $data . ' 00:00:00',
        ];

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $ok = vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t",
                    [':i' => $id, ':t' => vero_tenant()]);
                if (!$ok) throw new RuntimeException('Apontamento inválido.');
                vero_update(T, $id, $cab);
                irr_limpar_consumos($id);
                $apontId = $id;
            } else {
                $apontId = vero_insert(T, $cab);
            }
            $centro = vero_srv_centro_custo('IRR', 'Irrigação');
            $custoTotal = 0.0;
            foreach ($consumos as $c) {
                $pdo->prepare("INSERT INTO irrigacao_consumos (tenant_id, apontamento_id, tipo, quantidade, unidade, custo)
                               VALUES (?,?,?,?,?,?)")
                    ->execute([vero_tenant(), $apontId, $c['tipo'], $c['quantidade'], $c['unidade'], $c['custo']]);
                $consumoId = (int)$pdo->lastInsertId();
                if ($c['custo'] > 0) {
                    $custoTotal += $c['custo'];
                    vero_insert('custeio_lancamentos', [
                        'safra_id'        => $vinculo ? (int)$vinculo['safra_id'] : null,
                        'safra_talhao_id' => $stId,
                        'talhao_id'       => $talhaoId,
                        'cultura_id'      => $vinculo ? (int)$vinculo['cultura_id'] : null,
                        'centro_custo_id' => $centro,
                        'plano_conta_id'  => custeio_plano_conta_id('irrigacao_consumo'),
                        'categoria'       => 'irrigacao',
                        'origem_tipo'     => 'irrigacao_consumo',
                        'origem_id'       => $consumoId,
                        'valor'           => $c['custo'],
                        'quantidade'      => $c['quantidade'],
                        'data_competencia'=> $data,
                    ]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao salvar: ' . h($e->getMessage()));
            vero_redirect();
        }
        vero_flash('ok', 'Apontamento de irrigação salvo'
            . ($custoTotal > 0 ? ' — R$ ' . numFmt($custoTotal, 2) . ' lançado(s) no custeio (categoria irrigação).' : '.'));
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('irrigacao.apontamentos_irrigacao.excluir');
        $id = vero_int('id');
        $ok = $id ? vero_val("SELECT id FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if ($ok) {
            $pdo = vero_pdo();
            $pdo->beginTransaction();
            try {
                irr_limpar_consumos((int)$id);
                $pdo->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")
                    ->execute([vero_tenant(), (int)$id]);
                $pdo->commit();
                vero_flash('ok', 'Apontamento excluído (consumos e custeio removidos).');
            } catch (Throwable $e) {
                $pdo->rollBack();
                vero_flash('erro', 'Erro ao excluir: ' . h($e->getMessage()));
            }
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$fTalhao = (int)($_GET['talhao'] ?? 0);
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

$where  = "a.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($fTalhao > 0) { $where .= " AND a.talhao_id = :ta"; $params[':ta'] = $fTalhao; }

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " a WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT a.*, t.codigo AS talhao, f.nome AS fazenda, s.identificacao AS safra,
            (SELECT COALESCE(SUM(c.quantidade),0) FROM irrigacao_consumos c
              WHERE c.tenant_id = a.tenant_id AND c.apontamento_id = a.id AND c.tipo = 'agua') AS agua,
            (SELECT COALESCE(SUM(c.quantidade),0) FROM irrigacao_consumos c
              WHERE c.tenant_id = a.tenant_id AND c.apontamento_id = a.id AND c.tipo = 'energia') AS energia,
            (SELECT COALESCE(SUM(c.custo),0) FROM irrigacao_consumos c
              WHERE c.tenant_id = a.tenant_id AND c.apontamento_id = a.id) AS custo
       FROM " . T . " a
       JOIN agro_talhoes t ON t.id = a.talhao_id
       JOIN agro_fazendas f ON f.id = t.fazenda_id
       LEFT JOIN agro_safra_talhoes st ON st.id = a.safra_talhao_id
       LEFT JOIN agro_safras s ON s.id = st.safra_id
      WHERE {$where}
      ORDER BY a.data_apontamento DESC, a.id DESC
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

$talhoes = vero_rows(
    "SELECT t.id, CONCAT(f.nome, ' — ', t.codigo) AS label
       FROM agro_talhoes t JOIN agro_fazendas f ON f.id = t.fazenda_id
      WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo", [':t' => vero_tenant()]);
$vinculos = vero_rows(
    "SELECT st.id, st.talhao_id, CONCAT(s.identificacao, ' · ', c.nome) AS label
       FROM agro_safra_talhoes st
       JOIN agro_safras s ON s.id = st.safra_id
       JOIN agro_culturas c ON c.id = st.cultura_id
      WHERE st.tenant_id = :t ORDER BY s.identificacao DESC", [':t' => vero_tenant()]);

/* Auto-preenchimento dos consumos a partir da BOMBA vinculada à válvula.
   Cadeia: válvula (talhao_id) → setor(es) (agro_setores.talhao_id)
           → bomba(s) (agro_bomba_valvulas.setor_id) → vazão/potência.
   Mapa por válvula { talhao_id: {vazao: Σvazao_m3h, potencia: Σpotencia_kw} }
   somando as bombas ATIVAS distintas (o subselect DISTINCT evita contar a
   mesma bomba duas vezes quando ela atende vários setores da mesma válvula). */
$bombaMap = [];
foreach (vero_rows(
    "SELECT sub.talhao_id,
            SUM(b.vazao_m3h)   AS vazao,
            SUM(b.potencia_kw) AS potencia
       FROM (SELECT DISTINCT s.talhao_id AS talhao_id, bv.bomba_id AS bomba_id
               FROM agro_setores s
               JOIN agro_bomba_valvulas bv
                 ON bv.setor_id = s.id AND bv.tenant_id = s.tenant_id
              WHERE s.tenant_id = :t AND s.ativo = 1 AND s.talhao_id IS NOT NULL) sub
       JOIN agro_bombas b
         ON b.id = sub.bomba_id AND b.tenant_id = :tb AND b.ativo = 1
      GROUP BY sub.talhao_id",
    [':t' => vero_tenant(), ':tb' => vero_tenant()]) as $bm) {
    $bombaMap[(int)$bm['talhao_id']] = [
        'vazao'    => $bm['vazao'] !== null ? (float)$bm['vazao'] : 0.0,
        'potencia' => $bm['potencia'] !== null ? (float)$bm['potencia'] : 0.0,
    ];
}

$edit = null;
$editConsumos = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        foreach (vero_rows("SELECT * FROM irrigacao_consumos WHERE tenant_id=:t AND apontamento_id=:a",
            [':t' => vero_tenant(), ':a' => (int)$edit['id']]) as $c) {
            $editConsumos[(string)$c['tipo']] = $c;
        }
    }
}

/* C-21: tarifas vigentes p/ o card e o cálculo JS */
$tarifasIrrUI = json_decode((string)(vero_srv_param('irrigacao.tarifas') ?? ''), true) ?: [];

$GUARD      = ['macro' => 'irrigacao', 'micro' => 'apontamentos_irrigacao'];
$PAGE_VIEW  = 'irrigacao_apontamentos_irrigacao';
$PAGE_TITLE = 'Apontamentos de Irrigação';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('irrigacao.apontamentos_irrigacao.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Apontamentos de Irrigação', 'Horas e lâmina por válvula × data, com consumos de água e energia lançados no custeio',
        $podeEditar ? '+ Novo apontamento' : null) ?>

  <?php if ($podeEditar): /* C-21: tarifas → custo automático */ ?>
  <div class="vcard" style="margin-bottom:14px">
    <form method="post" class="vtoolbar" style="gap:14px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar_tarifas">
      <div class="vfield" style="margin:0">
        <label>Tarifa da água (R$/m³)</label>
        <input type="text" name="tarifa_agua_m3" style="text-align:right;max-width:130px"
               value="<?= isset($tarifasIrrUI['agua_m3']) && $tarifasIrrUI['agua_m3'] !== null ? numFmt((float)$tarifasIrrUI['agua_m3'], 4) : '' ?>">
      </div>
      <div class="vfield" style="margin:0">
        <label>Tarifa da energia (R$/kWh)</label>
        <input type="text" name="tarifa_energia_kwh" style="text-align:right;max-width:130px"
               value="<?= isset($tarifasIrrUI['energia_kwh']) && $tarifasIrrUI['energia_kwh'] !== null ? numFmt((float)$tarifasIrrUI['energia_kwh'], 4) : '' ?>">
      </div>
      <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Gravar tarifas</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="talhao" onchange="this.form.submit()">
          <option value="">Todas as válvulas</option>
          <?php foreach ($talhoes as $t): ?>
            <option value="<?= (int)$t['id'] ?>"<?= $fTalhao === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum apontamento de irrigação registrado.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Data</th><th>Fazenda / Válvula</th><th>Safra</th>
        <th class="num">Horas</th>
        <th class="num">Lâmina (mm)</th>
        <th class="num">Água (m³)</th>
        <th class="num">Energia (kWh)</th>
        <th class="num">Custo (R$)</th>
        <th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_apontamento'])) ?></td>
          <td><strong><?= h($r['fazenda']) ?> — <?= h($r['talhao']) ?></strong></td>
          <td><?= h($r['safra'] ?? '') ?: '—' ?></td>
          <td class="num"><?= numFmt((float)$r['horas'], 1) ?></td>
          <td class="num"><?= numFmt((float)$r['lamina_mm'], 1) ?></td>
          <td class="num"><?= numFmt((float)$r['agua'], 1) ?></td>
          <td class="num"><?= numFmt((float)$r['energia'], 1) ?></td>
          <td class="num"><strong><?= numFmt((float)$r['custo'], 2) ?></strong></td>
          <td><div class="vactions">
            <?= vero_btn_icone(vero_ico_imprimir(), 'Imprimir — ordem de irrigação (papel) p/ preencher offline', "window.open('" . BIOS_BASE . "/irrigacao/apontamento_impressao?id=" . (int)$r['id'] . "','_blank')") ?>
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('irrigacao.apontamentos_irrigacao.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este apontamento? Consumos e custeio serão removidos.') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar apontamento' : 'Novo apontamento de irrigação' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="vfield">
          <label>Válvula *</label>
          <select name="talhao_id" id="irr-talhao" required>
            <option value="">— Selecione —</option>
            <?php foreach ($talhoes as $t): ?>
              <option value="<?= (int)$t['id'] ?>"<?= $edit && (int)$edit['talhao_id'] === (int)$t['id'] ? ' selected' : '' ?>>
                <?= h($t['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Safra (vínculo)</label>
          <select name="safra_talhao_id" id="irr-safra">
            <option value="">— Sem safra —</option>
          </select>
        </div>
        <div class="vfield">
          <label>Data *</label>
          <input type="date" name="data_apontamento" required
                 value="<?= h($edit ? substr((string)$edit['data_apontamento'], 0, 10) : date('Y-m-d')) ?>">
        </div>
        <?= vero_f_text('horas', 'Horas de irrigação', $edit ? numFmt((float)$edit['horas'], 1) : '') ?>
        <?= vero_f_text('lamina_mm', 'Lâmina (mm)', $edit ? numFmt((float)$edit['lamina_mm'], 1) : '') ?>
        <!-- A1-48d (DB-53): parâmetro do RT p/ a fase — SUGESTÃO, nunca trava -->
        <div class="full vhint" id="irr-sugestao" style="display:none;border-left:3px solid #005059;padding-left:8px"></div>
        <div class="full" style="border-top:1px solid #EEE8DB;padding-top:10px;font-weight:600;font-size:13px">Consumos do período</div>
        <div class="full vhint" id="irr-bomba-dica" style="display:none"></div>
        <?= vero_f_text('agua_qtd', 'Água (m³)',
              isset($editConsumos['agua']) ? numFmt((float)$editConsumos['agua']['quantidade'], 1) : '') ?>
        <?= vero_f_text('agua_custo', 'Custo da água (R$)',
              isset($editConsumos['agua']) ? numFmt((float)$editConsumos['agua']['custo'], 2) : '', false, 'Lançado no custeio') ?>
        <?= vero_f_text('energia_qtd', 'Energia (kWh)',
              isset($editConsumos['energia']) ? numFmt((float)$editConsumos['energia']['quantidade'], 1) : '') ?>
        <?= vero_f_text('energia_custo', 'Custo da energia (R$)',
              isset($editConsumos['energia']) ? numFmt((float)$editConsumos['energia']['custo'], 2) : '', false, 'Lançado no custeio') ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<script>
const IRR_VINCULOS = <?= jsvar(array_map(static fn($v) => [
    'id' => (int)$v['id'], 'talhao' => (int)$v['talhao_id'], 'label' => $v['label'],
], $vinculos)) ?>;
const IRR_EDIT_ST = <?= $edit && $edit['safra_talhao_id'] !== null ? (int)$edit['safra_talhao_id'] : 'null' ?>;
function irrSafras() {
  const talhao = parseInt(document.getElementById('irr-talhao').value || '0', 10);
  const sel = document.getElementById('irr-safra');
  sel.innerHTML = '<option value="">— Sem safra —</option>';
  const lista = IRR_VINCULOS.filter(v => v.talhao === talhao); /* já vem da MAIS RECENTE p/ a mais antiga */
  lista.forEach(v => sel.add(new Option(v.label, v.id)));
  if (IRR_EDIT_ST && [...sel.options].some(o => +o.value === IRR_EDIT_ST)) {
    sel.value = String(IRR_EDIT_ST);   /* edição preserva o vínculo salvo */
  } else if (lista.length) {
    sel.value = String(lista[0].id);   /* auto-seleciona ao escolher a válvula: único ou o vigente */
  }
  sel.dispatchEvent(new Event('change')); /* propaga p/ sugestão de fase/consumo */
}
document.getElementById('irr-talhao').addEventListener('change', irrSafras);
irrSafras();

/* A1-48d (DB-53): sugestão do RT por fase — cruzamento cultura(vínculo) ×
   estágio(fase da data) × tipo de irrigação (válvula-espelho da válvula).
   AVISO informativo; o registro real é sempre o digitado. */
const IRR_FENO = <?= jsvar(vero_a1_fenologia_periodos_js()) ?>;
const IRR_PARAMS = <?= jsvar(array_map(static fn($p) => [
    'cultura' => (int)$p['cultura_id'], 'estagio' => (int)$p['estagio_id'],
    'tipo' => (string)$p['tipo_irrigacao'],
    'vol' => $p['volume_ideal_m3_ha'] !== null ? (float)$p['volume_ideal_m3_ha'] : null,
    'tempo' => $p['tempo_ideal_h'] !== null ? (float)$p['tempo_ideal_h'] : null,
], vero_rows("SELECT * FROM irrigacao_fase_parametros WHERE tenant_id = :t AND ativo = 1", [':t' => vero_tenant()]))) ?>;
const IRR_VINC_CULT = <?= jsvar(array_map(static fn($v) => [
    'id' => (int)$v['id'], 'talhao' => (int)$v['talhao_id'], 'safra' => (int)$v['safra_id'],
    'cultura' => (int)$v['cultura_id'],
], vero_rows("SELECT id, talhao_id, safra_id, cultura_id FROM agro_safra_talhoes WHERE tenant_id=:t", [':t' => vero_tenant()]))) ?>;
const IRR_TIPO_SETOR = <?= jsvar(array_column(vero_rows(
    "SELECT talhao_id, tipo_irrigacao FROM agro_setores
      WHERE tenant_id = :t AND ativo = 1 AND talhao_id IS NOT NULL AND tipo_irrigacao IS NOT NULL",
    [':t' => vero_tenant()]), 'tipo_irrigacao', 'talhao_id')) ?>;
const IRR_TIPOS_NOME = {gotejo:'gotejo', microaspersao:'microaspersão', pivo:'pivô', outro:'outro'};

<?php
/* Onda 2 (fenologia por variedade / mig 157): camada PREFERIDA sobre a cultura.
   Fases da versão APROVADA vigente por variedade + talhão→variedade + safra→poda(dia 0).
   Hierarquia do gestor: variedade > cultura (fallback abaixo). */
$irrVarFasesRows = vero_rows(
    "SELECT fe.variedade_id, fa.nome, fa.dia_inicio, fa.dia_fim, fa.volume_mm_dia
       FROM agro_variedade_fases fa
       JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
      WHERE fa.tenant_id = :t AND fa.ativo = 1 AND fe.status = 'aprovada' AND fe.ativo = 1
        AND fe.versao = (SELECT MAX(versao) FROM agro_variedade_fenologia x
                          WHERE x.tenant_id = fe.tenant_id AND x.variedade_id = fe.variedade_id
                            AND x.status = 'aprovada' AND x.ativo = 1)
      ORDER BY fe.variedade_id, fa.dia_inicio", [':t' => vero_tenant()]);
$irrVarFases = [];
foreach ($irrVarFasesRows as $f) {
    $irrVarFases[(int)$f['variedade_id']][] = [
        'nome'   => (string)$f['nome'], 'ini' => (int)$f['dia_inicio'], 'fim' => (int)$f['dia_fim'],
        'vol_mm' => $f['volume_mm_dia'] !== null ? (float)$f['volume_mm_dia'] : null,
    ];
}
$irrTalVar    = array_column(vero_rows("SELECT id, variedade_id FROM agro_talhoes WHERE tenant_id = :t AND variedade_id IS NOT NULL", [':t' => vero_tenant()]), 'variedade_id', 'id');
$irrSafraPoda = array_column(vero_rows("SELECT id, data_inicio FROM agro_safras WHERE tenant_id = :t", [':t' => vero_tenant()]), 'data_inicio', 'id');
?>
const IRR_VAR_FASES  = <?= jsvar($irrVarFases) ?>;
const IRR_TAL_VAR    = <?= jsvar(array_map('intval', $irrTalVar)) ?>;
const IRR_SAFRA_PODA = <?= jsvar(array_map('strval', $irrSafraPoda)) ?>;

/* fase da fenologia APROVADA da variedade do talhão em (data − poda) dias */
function irrFaseVariedade(vinc, data) {
  const varId = IRR_TAL_VAR[vinc.talhao];
  const poda  = IRR_SAFRA_PODA[vinc.safra];
  if (!varId || !poda) return null;
  const fases = IRR_VAR_FASES[varId];
  if (!fases) return null;
  const dias = Math.floor((Date.parse(data) - Date.parse(poda)) / 86400000);
  if (isNaN(dias) || dias < 0) return null;
  return fases.find(f => f.ini <= dias && dias < f.fim) || null;
}

function irrSugestao() {
  const box = document.getElementById('irr-sugestao');
  const stId = parseInt(document.getElementById('irr-safra').value || '0', 10);
  const data = document.querySelector('input[name="data_apontamento"]').value;
  const talhao = parseInt(document.getElementById('irr-talhao').value || '0', 10);
  box.style.display = 'none';
  if (!stId || !data) return;
  const vinc = IRR_VINC_CULT.find(v => v.id === stId);
  if (!vinc) return;
  /* Onda 2: PREFERE a fenologia aprovada da VARIEDADE (mm/dia); senão cai na cultura */
  const fv = irrFaseVariedade(vinc, data);
  if (fv && fv.vol_mm !== null) {
    box.style.display = '';
    const m3 = fv.vol_mm * 10; /* m³/ha/dia = mm/dia × 10 */
    box.innerHTML = '<strong>Fenologia da variedade — fase ' + esc(fv.nome) + '</strong>: '
      + String(fv.vol_mm).replace('.', ',') + ' mm/dia (' + String(m3).replace('.', ',') + ' m³/ha/dia)'
      + ' — sugestão da fenologia aprovada da variedade; o apontamento vale o que você digitar.';
    return;
  }
  const fase = IRR_FENO.find(f => f.st === stId && f.safra === vinc.safra && f.ini <= data && data <= f.fim)
            || IRR_FENO.find(f => f.st === null && f.safra === vinc.safra && f.ini <= data && data <= f.fim);
  if (!fase) return;
  const tipoSetor = IRR_TIPO_SETOR[talhao] || null;
  const par = IRR_PARAMS.find(p => p.cultura === vinc.cultura && p.estagio === fase.feno && (tipoSetor === null || p.tipo === tipoSetor))
           || IRR_PARAMS.find(p => p.cultura === vinc.cultura && p.estagio === fase.feno);
  if (!par) return;
  const partes = [];
  if (par.vol !== null) partes.push(String(par.vol).replace('.', ',') + ' m³/ha');
  if (par.tempo !== null) partes.push(String(par.tempo).replace('.', ',') + ' h');
  if (!partes.length) return;
  box.style.display = '';
  box.innerHTML = '<strong>Parâmetro do RT para a fase atual</strong> (' + IRR_TIPOS_NOME[par.tipo] + '): '
    + partes.join(' · ') + ' — sugestão registrada em Fases Fenológicas; o apontamento vale o que você digitar.';
}
['irr-talhao', 'irr-safra'].forEach(i => document.getElementById(i).addEventListener('change', irrSugestao));
document.querySelector('input[name="data_apontamento"]').addEventListener('change', irrSugestao);
irrSugestao();

/* Auto-preenchimento dos consumos a partir da bomba da válvula (mig 160).
   água (m³) = Σvazao_m3h × horas · energia (kWh) = Σpotencia_kw × horas.
   Sempre editável: sobrescreve só quando o operador muda válvula ou horas;
   no load apenas mostra a dica de origem (não mexe nos valores gravados). */
const IRR_BOMBA = <?= jsvar($bombaMap) ?>;
const irrVirg = n => String(n).replace('.', ',');
function irrBombaDica() {
  const talhao = parseInt(document.getElementById('irr-talhao').value || '0', 10);
  const dica = document.getElementById('irr-bomba-dica');
  const b = IRR_BOMBA[talhao] || null;
  dica.style.display = '';
  if (!b || (!b.vazao && !b.potencia)) {
    dica.textContent = 'Sem bomba vinculada — informe água e energia manualmente.';
    return null;
  }
  dica.innerHTML = 'Da bomba: vazão <strong>' + irrVirg(b.vazao) + '</strong> m³/h, potência <strong>'
    + irrVirg(b.potencia) + '</strong> kW — água e energia calculadas por hora (editáveis).';
  return b;
}
/* C-21: tarifas do tenant → custo automático = qtd × tarifa (campo editável;
   digitação manual marca o campo e o auto não sobrescreve mais) */
const IRR_TARIFAS = <?= jsvar([
    'agua'    => isset($tarifasIrrUI['agua_m3']) ? (float)$tarifasIrrUI['agua_m3'] : null,
    'energia' => isset($tarifasIrrUI['energia_kwh']) ? (float)$tarifasIrrUI['energia_kwh'] : null,
]) ?>;
function irrDec(v) { v = String(v || '').trim(); if (!v) return null; if (v.includes(',')) v = v.replace(/\./g, '').replace(',', '.'); const n = parseFloat(v); return isNaN(n) ? null : n; }
function irrAutoCusto(tipo) {
  const qtdEl = document.querySelector('input[name="' + tipo + '_qtd"]');
  const cEl = document.querySelector('input[name="' + tipo + '_custo"]');
  if (!qtdEl || !cEl) return;
  if (cEl.value !== '' && cEl.dataset.auto !== '1') return;   /* manual prevalece */
  const qtd = irrDec(qtdEl.value), tarifa = IRR_TARIFAS[tipo];
  if (qtd === null || !tarifa) return;
  cEl.value = irrVirg((Math.round(qtd * tarifa * 100) / 100).toFixed(2));
  cEl.dataset.auto = '1';
}
function irrAutoConsumo() {
  const b = irrBombaDica();
  const horas = parseFloat((document.querySelector('input[name="horas"]').value || '0').replace(',', '.')) || 0;
  const agua = document.querySelector('input[name="agua_qtd"]');
  const energia = document.querySelector('input[name="energia_qtd"]');
  if (b && b.vazao)    agua.value    = irrVirg((b.vazao * horas).toFixed(1));
  if (b && b.potencia) energia.value = irrVirg((b.potencia * horas).toFixed(1));
  irrAutoCusto('agua'); irrAutoCusto('energia');   /* C-21 */
}
document.getElementById('irr-talhao').addEventListener('change', irrAutoConsumo);
document.querySelector('input[name="horas"]').addEventListener('input', irrAutoConsumo);
['agua', 'energia'].forEach(tipo => {
  const qtdEl = document.querySelector('input[name="' + tipo + '_qtd"]');
  const cEl = document.querySelector('input[name="' + tipo + '_custo"]');
  if (qtdEl) qtdEl.addEventListener('input', () => irrAutoCusto(tipo));  /* qtd manual também calcula */
  if (cEl) cEl.addEventListener('input', function () { this.dataset.auto = '0'; });
});
irrBombaDica(); /* load: só a dica, preserva valores já digitados/gravados */
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
