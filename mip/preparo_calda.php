<?php
/* ============================================================
   VERO — MIP / Preparo de Calda  (V-08, go-live 10-11/08)
   Rota: /mip/preparo_calda.php
   Guard: mip.preparo_calda  (perm reusada: mip.aplicacoes_defensivos.*)

   A "visão do preparador de calda". Processo real do cliente:
     1) o RT emite a OS de pulverização/fertirrigação (DF/IF) com
        produtos/doses/bico, MAS sem operador nem máquina — a OS
        nasce em status 'planejada' (mip/aplicacoes.php, acao=salvar
        modo=emitir);
     2) na hora do preparo, o PREPARADOR abre esta fila, escolhe a
        OS do dia, atribui o APLICADOR (tratorista) + o TRATOR/
        PULVERIZADOR numerado e CONFIRMA — cada um na sua OS.

   Esta tela NÃO reimplementa regra fiscal/estoque: ela apenas
   orquestra a atribuição e dispara a CONFIRMAÇÃO já existente
   (mip/aplicacoes.php, acao=confirmar), que baixa o estoque por
   FEFO e lança o custeio. O POST leva maquina_ids[] (trator/
   pulverizador) + op_operador[] (aplicador) + _retorno (esta fila).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

/* Fila do dia: mostra as OS emitidas (planejada) previstas ATÉ a data
   escolhida (default hoje) — inclui atrasadas e as sem data prevista. */
$fData = (string)($_GET['data'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fData)) $fData = date('Y-m-d');

$rows = vero_rows(
    "SELECT ap.*, tl.codigo AS talhao, fz.nome AS fazenda, sa.identificacao AS safra,
            (SELECT COUNT(*) FROM agro_aplicacao_itens i     WHERE i.tenant_id = ap.tenant_id AND i.aplicacao_id = ap.id) AS itens,
            (SELECT COUNT(*) FROM agro_aplicacao_operadores o WHERE o.tenant_id = ap.tenant_id AND o.aplicacao_id = ap.id) AS ops,
            (SELECT COUNT(*) FROM agro_aplicacao_maquinas  mq WHERE mq.tenant_id = ap.tenant_id AND mq.aplicacao_id = ap.id) AS maqs
       FROM agro_aplicacoes ap
       LEFT JOIN agro_talhoes  tl ON tl.id = ap.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = COALESCE(ap.fazenda_id, tl.fazenda_id)
       LEFT JOIN agro_safras   sa ON sa.id = ap.safra_id
      WHERE ap.tenant_id = :t AND ap.status = 'planejada'
        AND (ap.data_prevista IS NULL OR ap.data_prevista <= :d)
      ORDER BY ap.data_prevista IS NULL, ap.data_prevista ASC, ap.id ASC
      LIMIT 200",
    [':t' => $t, ':d' => $fData]);

$TIPOS = [
    'pulverizacao'     => 'Pulverização',
    'fertirrigacao'    => 'Fertirrigação',
    'foliar'           => 'Adubação foliar',
    'indutor_brotacao' => 'Indutor de brotação',
    'tratamento'       => 'Tratamento',
    'outro'            => 'Outro',
];

/* Máquinas NUMERADAS (código TR-01 etc.) + aplicadores (operadores) */
$maquinas = [];
foreach (vero_rows(
    "SELECT id, codigo, nome FROM maquinas
      WHERE tenant_id = :t AND ativo = 1 AND status <> 'inativa'
      ORDER BY codigo, nome", [':t' => $t]) as $mq) {
    $maquinas[(int)$mq['id']] = trim((($mq['codigo'] ?? '') !== '' ? $mq['codigo'] . ' — ' : '') . (string)$mq['nome']);
}
$operadores = vero_options('agro_operadores', 'nome');

/* OS selecionada para o painel de atribuição (?os=ID) */
$os = null; $osItens = [];
if (!empty($_GET['os'])) {
    $os = vero_row(
        "SELECT ap.*, tl.codigo AS talhao, fz.nome AS fazenda, sa.identificacao AS safra
           FROM agro_aplicacoes ap
           LEFT JOIN agro_talhoes  tl ON tl.id = ap.talhao_id
           LEFT JOIN agro_fazendas fz ON fz.id = COALESCE(ap.fazenda_id, tl.fazenda_id)
           LEFT JOIN agro_safras   sa ON sa.id = ap.safra_id
          WHERE ap.id = :i AND ap.tenant_id = :t AND ap.status = 'planejada'",
        [':i' => (int)$_GET['os'], ':t' => $t]);
    if ($os) {
        $osItens = vero_rows(
            "SELECT i.*, p.nome AS produto_nome, p.codigo AS produto_codigo
               FROM agro_aplicacao_itens i
               LEFT JOIN estoque_produtos p ON p.id = i.produto_id
              WHERE i.tenant_id = :t AND i.aplicacao_id = :a ORDER BY i.id",
            [':t' => $t, ':a' => (int)$os['id']]);
    }
}

$fecharUrl = h(strtok((string)$_SERVER['REQUEST_URI'], '?')) . '?data=' . h($fData);

$GUARD      = ['macro' => 'mip', 'micro' => 'preparo_calda'];
$PAGE_VIEW  = 'mip_preparo_calda';
$PAGE_TITLE = 'Preparo de Calda';
$EXTRA_HEAD = vero_assets(); /* fix 21/07: sem isto os componentes .vcard/.vtoolbar/.vbtn nao estilizavam */
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('mip.aplicacoes_defensivos.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Preparo de Calda',
        'Fila das OS emitidas (DF/IF) sem operador/máquina — atribua o aplicador e o trator/pulverizador e confirme a execução', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <label class="vhint" style="align-self:center">OS previstas até</label>
        <input type="date" name="data" value="<?= h($fData) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub"><?= count($rows) ?> OS aguardando preparo</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma OS emitida aguardando preparo até <?= dateBR($fData) ?>.
        As OS são emitidas em <a href="<?= BIOS_BASE ?>/mip/aplicacoes">Pulverização</a> (modo “Emitir OS”).</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Documento</th><th>Prevista</th><th>Tipo</th><th>Válvula</th><th>Safra</th>
        <th class="num">Produtos</th><th>Atribuição</th><th class="num">Ação</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $atrasada = $r['data_prevista'] && (string)$r['data_prevista'] < date('Y-m-d'); ?>
        <tr>
          <td><?= $r['doc_serie']
                ? '<strong class="vnum">' . h((string)$r['doc_serie']) . (int)$r['doc_numero'] . '</strong>'
                : '<span class="vhint">#' . (int)$r['id'] . '</span>' ?></td>
          <td class="vnum"><strong><?= $r['data_prevista'] ? dateBR((string)$r['data_prevista']) : '—' ?></strong>
            <?= $atrasada ? '<div class="vhint" style="color:#b3261e">atrasada</div>' : '' ?></td>
          <td><span class="vbadge vb-info"><?= h($TIPOS[(string)$r['tipo']] ?? ucfirst((string)$r['tipo'])) ?></span></td>
          <td><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?></td>
          <td><?= h($r['safra'] ?? '—') ?></td>
          <td class="num"><?= (int)$r['itens'] ?></td>
          <td>
            <?php if ((int)$r['ops'] > 0 || (int)$r['maqs'] > 0): ?>
              <span class="vbadge vb-warn"><?= (int)$r['maqs'] ?> máq. · <?= (int)$r['ops'] ?> aplic.</span>
            <?php else: ?>
              <span class="vhint">sem operador/máquina</span>
            <?php endif; ?>
          </td>
          <td class="num">
            <?php if ($podeEditar): ?>
              <div class="vactions" style="justify-content:flex-end"><?= vero_btn_icone(vero_ico_seta(), 'Preparar', '', '?data=' . rawurlencode((string)$fData) . '&os=' . (int)$r['id']) ?></div>
            <?php else: ?>
              <span class="vhint">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      O preparador define o aplicador e o trator/pulverizador POR OS e confirma o preparo — a confirmação baixa o
      estoque por FEFO pelas quantidades reais e lança o custeio. A validação nominal do RT continua sendo a etapa seguinte.
    </div>
  </div>
</div>

<?php if ($podeEditar && $os):
    $docTxt = $os['doc_serie'] ? h((string)$os['doc_serie']) . (int)$os['doc_numero'] : '#' . (int)$os['id']; ?>
<div class="vmodal open" id="vm-preparo">
  <div class="vbox" style="max-width:720px">
    <header>
      <h2>Preparo de calda — <?= $docTxt ?>
        <span class="vhint"><?= h(trim(($os['fazenda'] ?? '') . ' — ' . ($os['talhao'] ?? ''), ' —')) ?>
          · <?= h($TIPOS[(string)$os['tipo']] ?? ucfirst((string)$os['tipo'])) ?></span></h2>
      <a class="vclose" href="<?= $fecharUrl ?>">×</a>
    </header>
    <form class="vform" method="post" action="<?= BIOS_BASE ?>/mip/aplicacoes">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="confirmar">
      <input type="hidden" name="id" value="<?= (int)$os['id'] ?>">
      <input type="hidden" name="_retorno" value="<?= h(BIOS_BASE . '/mip/preparo_calda?data=' . urlencode($fData)) ?>">

      <div class="vgrid">
        <div class="vfield">
          <label>Data do preparo / execução *</label>
          <input type="date" name="data" value="<?= h($os['data_prevista'] ? (string)$os['data_prevista'] : date('Y-m-d')) ?>" required>
        </div>
      </div>

      <!-- Trator / pulverizador NUMERADOS (uva usa trator + pulverizador juntos):
           os dois selects postam em maquina_ids[]; o endpoint valida cada id no tenant. -->
      <div class="vfield" style="margin-top:10px">
        <label>Trator / pulverizador (numerados) *</label>
        <div class="vgrid">
          <div class="vfield">
            <label class="vhint">Trator</label>
            <select name="maquina_ids[]" required>
              <option value="">— Selecione o trator —</option>
              <?php foreach ($maquinas as $mid => $mlabel): ?>
                <option value="<?= (int)$mid ?>"><?= h($mlabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="vfield">
            <label class="vhint">Pulverizador / implemento (opcional)</label>
            <select name="maquina_ids[]">
              <option value="">— Nenhum —</option>
              <?php foreach ($maquinas as $mid => $mlabel): ?>
                <option value="<?= (int)$mid ?>"><?= h($mlabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <!-- Aplicador (tratorista): a confirmação exige pelo menos 1 operador -->
      <div class="vfield" style="margin-top:10px">
        <label>Aplicador (tratorista) * <span class="vhint">— pelo menos 1 (exigência de certificação)</span></label>
        <div class="vgrid">
          <div class="vfield">
            <label class="vhint">Aplicador principal</label>
            <select name="op_operador[]" required>
              <option value="">— Selecione o aplicador —</option>
              <?php foreach ($operadores as $oid => $onome): ?>
                <option value="<?= (int)$oid ?>"><?= h($onome) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="vfield">
            <label class="vhint">Auxiliar (opcional)</label>
            <select name="op_operador[]">
              <option value="">— Nenhum —</option>
              <?php foreach ($operadores as $oid => $onome): ?>
                <option value="<?= (int)$oid ?>"><?= h($onome) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <?php if ($osItens): ?>
      <div class="vfield" style="margin-top:10px">
        <label>Quantidades da calda (pré-preenchidas com as previstas da emissão — ajuste se o preparo consumiu outra quantidade)</label>
        <div class="vdata-wrap">
        <table class="vdata">
          <thead><tr><th>Produto</th><th class="num">Prevista</th><th class="num" style="width:32%">Real do preparo</th></tr></thead>
          <tbody>
          <?php foreach ($osItens as $ci): ?>
            <tr>
              <td><strong><?= h(trim(($ci['produto_codigo'] ? $ci['produto_codigo'] . ' — ' : '') . ($ci['produto_nome'] ?? '—'))) ?></strong></td>
              <td class="num"><?= numFmt((float)$ci['quantidade_consumida'], 2) ?> <?= h((string)($ci['quantidade_unidade'] ?? '')) ?></td>
              <td><input type="text" name="c_qtd[<?= (int)$ci['id'] ?>]"
                         value="<?= numFmt((float)$ci['quantidade_consumida'], 2) ?>" style="text-align:right"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
      <?php endif; ?>

      <div class="vhint" style="margin-top:8px">
        Ao confirmar: a OS <?= $docTxt ?> sai da fila, o estoque é baixado por FEFO pelas quantidades reais e o custeio é lançado.
        Clima, tríplice lavagem e EPI podem ser detalhados depois em Pulverização → Confirmar execução.
      </div>
      <div class="vform-actions">
        <a class="vbtn vbtn-ghost" href="<?= $fecharUrl ?>">Cancelar</a>
        <button class="vbtn vbtn-primary" type="submit">Atribuir e confirmar preparo</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
