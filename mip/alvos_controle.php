<?php
/* ============================================================
   VERO — MIP / Alvos de Controle  (CRUD real)
   Substitui o mock. Rota da matriz: /mip/alvos_controle.php
   Guard: mip.alvos_controle | Escrita: mip.alvos_controle.editar/excluir
   Tabela: mip_alvos — catálogo de pragas/doenças/daninhas com nível
   de ação (limiar que dispara alerta no monitoramento).
   Produtos recomendados por alvo: registro do RT na fase 2 — o
   sistema nunca recomenda produto/dose automaticamente (regra 1).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'mip_alvos';

const TIPOS_ALVO = ['praga' => 'Praga', 'doenca' => 'Doença', 'planta_daninha' => 'Planta daninha'];

/* DB-51: PRODUTOS que controlam o alvo (mip_alvo_produtos). A amarração antes
   ficava só no cadastro de produto; agora é gerida também a partir do ALVO
   (registro do RT — Regra 1: o sistema LISTA o que o RT cadastrou, nunca
   recomenda produto/dose). Feature-detection pela migration 146. */
function alvos_prod_ativo(): bool
{
    static $a = null;
    if ($a === null) $a = vero_row("SHOW TABLES LIKE 'mip_alvo_produtos'") !== null;
    return $a;
}

/* DB-51: grava os produtos vinculados NO PRÓPRIO form do alvo (arrays prod_*),
   já na criação. Upsert por UNIQUE(tenant,alvo,produto); ignora linhas inválidas.
   Retorna quantos vínculos foram gravados/atualizados. */
function alvos_salvar_produtos_staged(int $alvoId): int
{
    if (!$alvoId || !alvos_prod_ativo()) return 0;
    $ids    = (array)($_POST['prod_id'] ?? []);
    $doses  = (array)($_POST['prod_dose'] ?? []);
    $uns    = (array)($_POST['prod_un'] ?? []);
    $caldas = (array)($_POST['prod_calda'] ?? []);
    $obss   = (array)($_POST['prod_obs'] ?? []);
    $parse = static function ($v): ?float {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
        return is_numeric($v) ? (float)$v : null;
    };
    $n = 0;
    foreach ($ids as $k => $raw) {
        $pid = (int)$raw;
        if ($pid <= 0) continue;
        $okP = vero_val("SELECT id FROM estoque_produtos WHERE id=:p AND tenant_id=:t AND ativo=1",
            [':p' => $pid, ':t' => vero_tenant()]);
        if (!$okP) continue;
        $dose = $parse($doses[$k] ?? '');
        $un   = mb_substr(trim((string)($uns[$k] ?? '')), 0, 20);
        if ($dose === null || $dose <= 0 || $un === '') continue;
        $calda = $parse($caldas[$k] ?? '');
        $obs   = mb_substr(trim((string)($obss[$k] ?? '')), 0, 255);
        $ex = vero_row("SELECT id FROM mip_alvo_produtos WHERE tenant_id=:t AND alvo_id=:a AND produto_id=:p",
            [':t' => vero_tenant(), ':a' => $alvoId, ':p' => $pid]);
        if ($ex) {
            vero_pdo()->prepare("UPDATE mip_alvo_produtos
                                    SET dose=?, dose_unidade=?, volume_calda_ha=?, observacao=?,
                                        cadastrado_por=?, ativo=1, updated_at=NOW(), updated_by=?
                                  WHERE tenant_id=? AND id=?")
                ->execute([$dose, $un, $calda, $obs, vero_uid(), vero_uid(), vero_tenant(), (int)$ex['id']]);
        } else {
            vero_pdo()->prepare("INSERT INTO mip_alvo_produtos
                    (tenant_id, alvo_id, produto_id, dose, dose_unidade, volume_calda_ha,
                     observacao, cadastrado_por, ativo, created_at, updated_at, created_by, updated_by)
                  VALUES (?,?,?,?,?,?,?,?,1,NOW(),NOW(),?,?)")
                ->execute([vero_tenant(), $alvoId, $pid, $dose, $un, $calda, $obs, vero_uid(), vero_uid(), vero_uid()]);
        }
        $n++;
    }
    return $n;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('mip.alvos_controle.editar');

        $id   = vero_int('id');
        $nome = vero_str('nome', 120);
        $tipo = vero_str('tipo', 20);

        if ($nome === null || $tipo === null || !isset(TIPOS_ALVO[$tipo])) {
            vero_flash('erro', 'Nome e tipo do alvo são obrigatórios.');
            vero_redirect();
        }
        $culturaId = vero_int('cultura_id');
        if ($culturaId) {
            $okCult = vero_val("SELECT id FROM agro_culturas WHERE id=:c AND tenant_id=:t",
                [':c' => $culturaId, ':t' => vero_tenant()]);
            if (!$okCult) $culturaId = null;
        }
        $dup = vero_val(
            "SELECT id FROM " . T . "
              WHERE tenant_id=:t AND nome=:n AND ativo=1 AND id<>:id
                AND ((:c1 IS NULL AND cultura_id IS NULL) OR cultura_id = :c2)",
            [':t' => vero_tenant(), ':n' => $nome, ':id' => (int)$id, ':c1' => $culturaId, ':c2' => $culturaId]);
        if ($dup) {
            vero_flash('erro', "Já existe o alvo \"{$nome}\" para esta cultura.");
            vero_redirect();
        }

        $data = [
            'nome'       => $nome,
            'tipo'       => $tipo,
            'cultura_id' => $culturaId,
            'nivel_acao' => vero_dec('nivel_acao'),
            'ativo'      => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            $msgAlvo = "Alvo \"{$nome}\" atualizado.";
        } else {
            $id = vero_insert(T, $data);
            $msgAlvo = "Alvo \"{$nome}\" cadastrado.";
        }
        /* DB-51: produtos vinculados no próprio form (já na criação) */
        $nProd = alvos_salvar_produtos_staged((int)$id);
        vero_flash('ok', $msgAlvo . ($nProd > 0 ? " {$nProd} produto(s) vinculado(s)." : ''));
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('mip.alvos_controle.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }

    /* DB-51: remover um produto já vinculado (soft delete, preserva trilha do RT).
       A adição é feita no próprio form do alvo (staged → alvos_salvar_produtos_staged). */
    if ($acao === 'prod_del' && alvos_prod_ativo()) {
        vero_require('mip.alvos_controle.editar');
        $alvoId = vero_int('alvo_id');
        $alvo = $alvoId ? vero_row("SELECT id FROM " . T . " WHERE tenant_id=:t AND id=:a",
            [':t' => vero_tenant(), ':a' => $alvoId]) : null;
        if (!$alvo) { vero_flash('erro', 'Alvo inválido.'); vero_redirect(); }
        $vinculoId = vero_int('vinculo_id');
        vero_pdo()->prepare("UPDATE mip_alvo_produtos SET ativo=0, updated_at=NOW(), updated_by=?
                              WHERE tenant_id=? AND id=? AND alvo_id=?")
            ->execute([vero_uid(), vero_tenant(), (int)$vinculoId, $alvoId]);
        vero_flash('ok', 'Vínculo produto×alvo removido (trilha do RT preservada).');
        vero_redirect('?editar=' . $alvoId);
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$fTipo   = (string)($_GET['tipo'] ?? '');
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

$where  = "a.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    $where .= " AND a.nome LIKE :q";
    $params[':q'] = "%{$q}%";
}
if (isset(TIPOS_ALVO[$fTipo])) {
    $where .= " AND a.tipo = :tp";
    $params[':tp'] = $fTipo;
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " a WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT a.*, c.nome AS cultura_nome,
            (SELECT COUNT(*) FROM mip_monitoramentos m
              WHERE m.tenant_id = a.tenant_id AND m.alvo_id = a.id) AS monitoramentos
       FROM " . T . " a
       LEFT JOIN agro_culturas c ON c.id = a.cultura_id
      WHERE {$where}
      ORDER BY a.ativo DESC, a.tipo, a.nome
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params);

$culturas = vero_options('agro_culturas', 'nome', 'ativo = 1');

$edit = null;
$editProdutos = [];
$produtosDisponiveis = [];
if (alvos_prod_ativo()) {
    /* defensivos ativos para escolher — carregado SEMPRE (criação e edição) */
    $produtosDisponiveis = vero_rows(
        "SELECT id, codigo, nome FROM estoque_produtos
          WHERE tenant_id = :t AND ativo = 1 AND tipo_insumo = 'defensivo'
          ORDER BY nome", [':t' => vero_tenant()]);
}
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit && alvos_prod_ativo()) {
        /* DB-51: produtos JÁ vinculados a ESTE alvo (registro do RT) */
        $editProdutos = vero_rows(
            "SELECT ap.*, p.nome AS produto_nome, p.codigo AS produto_codigo, u.nome AS rt_nome
               FROM mip_alvo_produtos ap
               JOIN estoque_produtos p ON p.id = ap.produto_id
               LEFT JOIN usuarios u ON u.id = ap.cadastrado_por
              WHERE ap.tenant_id = :t AND ap.alvo_id = :a AND ap.ativo = 1
              ORDER BY p.nome", [':t' => vero_tenant(), ':a' => (int)$edit['id']]);
    }
}
$podeEditarAlvoProd = vero_can('mip.alvos_controle.editar');

$GUARD      = ['macro' => 'mip', 'micro' => 'alvos_controle'];
$PAGE_VIEW  = 'mip_alvos_controle';
$PAGE_TITLE = 'Alvos de Controle';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('mip.alvos_controle.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Alvos de Controle', 'Catálogo de pragas, doenças e daninhas com nível de ação — o monitoramento alerta quando o índice atinge o nível',
        $podeEditar ? '+ Novo alvo' : null) ?>

  <div class="vcard">
    <div class="vtoolbar" style="gap:10px;flex-wrap:wrap">
      <?php /* NAV-1 Cluster 5: tela ÚNICA de alvos — filtro segmentado por tipo (substitui Pragas/Doenças separadas) */ ?>
      <div class="vseg" role="group" aria-label="Tipo de alvo">
        <a class="vseg__opt<?= $fTipo === '' ? ' is-on' : '' ?>" href="?<?= h(http_build_query(array_filter(['q' => $q]))) ?>">Todos</a>
        <?php $segLabels = ['praga' => 'Pragas', 'doenca' => 'Doenças', 'planta_daninha' => 'Daninhas']; ?>
        <?php foreach (TIPOS_ALVO as $tk => $tl): ?>
          <a class="vseg__opt<?= $fTipo === $tk ? ' is-on' : '' ?>" href="?<?= h(http_build_query(array_filter(['tipo' => $tk, 'q' => $q]))) ?>"><?= h($segLabels[$tk] ?? $tl) ?></a>
        <?php endforeach; ?>
      </div>
      <form method="get" style="display:flex;gap:8px;flex:1;min-width:200px;flex-wrap:wrap">
        <?php if ($fTipo !== ''): ?><input type="hidden" name="tipo" value="<?= h($fTipo) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nome…" style="flex:1;min-width:150px">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum alvo cadastrado. Ex.: Tripes, Míldio, Mosca-branca…</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Alvo</th><th>Tipo</th><th>Cultura</th>
        <th class="num">Nível de ação</th>
        <th class="num">Monitoramentos</th>
        <th>Status</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><span class="vbadge <?= $r['tipo'] === 'doenca' ? 'vb-warn' : 'vb-info' ?>"><?= h(TIPOS_ALVO[$r['tipo']] ?? $r['tipo']) ?></span></td>
          <td><?= h($r['cultura_nome'] ?? '') ?: '<span class="vhint">Todas</span>' ?></td>
          <td class="num"><?= $r['nivel_acao'] !== null ? numFmt((float)$r['nivel_acao'], 2) . '%' : '—' ?></td>
          <td class="num"><?= (int)$r['monitoramentos'] ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('mip.alvos_controle.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este alvo?') ?>
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
      <h2><?= $edit ? 'Editar alvo' : 'Novo alvo de controle' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('nome', 'Nome do alvo', $edit['nome'] ?? '', true, 'Ex.: Tripes, Míldio, Oídio') ?></div>
        <?= vero_f_select('tipo', 'Tipo', TIPOS_ALVO, $edit['tipo'] ?? 'praga', true, '') ?>
        <?= vero_f_select('cultura_id', 'Cultura', $culturas, $edit['cultura_id'] ?? null, false, '— Todas —') ?>
        <?= vero_f_text('nivel_acao', 'Nível de ação (%)', $edit && $edit['nivel_acao'] !== null ? numFmt((float)$edit['nivel_acao'], 2) : '', false, 'Índice que dispara alerta no monitoramento') ?>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativo', 0 => 'Inativo'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>

      <?php if (alvos_prod_ativo()): /* DB-51: produtos que controlam o alvo — disponível JÁ na criação */ ?>
      <div style="margin-top:14px;padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <strong style="font-size:13px">Produtos que controlam este alvo (registro do RT — DB-51)</strong>
        <div class="vhint" style="margin-bottom:6px">Vincule os produtos que controlam este alvo, com a dose da bula — a tela de pulverização LISTA estes registros ao selecionar o alvo.</div>
        <?php if ($edit && $editProdutos): /* já vinculados (persistidos) */ ?>
          <table class="vtable" style="margin-bottom:8px">
            <thead><tr><th>Produto</th><th style="text-align:right">Dose</th><th style="text-align:right">Calda (L/ha)</th>
              <th>Registro (RT)</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($editProdutos as $ep): ?>
              <tr>
                <td><strong><?= h($ep['produto_nome']) ?></strong> <span class="vhint"><?= h($ep['produto_codigo']) ?></span>
                  <?php if ($ep['observacao']): ?><div class="vhint"><?= h(mb_substr((string)$ep['observacao'], 0, 70)) ?></div><?php endif; ?></td>
                <td class="vnum" style="text-align:right"><?= numFmt((float)$ep['dose'], 4) ?> <span class="vhint"><?= h($ep['dose_unidade']) ?></span></td>
                <td class="vnum" style="text-align:right"><?= $ep['volume_calda_ha'] !== null ? numFmt((float)$ep['volume_calda_ha'], 0) : '—' ?></td>
                <td class="vhint"><?= h($ep['rt_nome'] ?? ('usuário #' . (int)$ep['cadastrado_por'])) ?></td>
                <td style="text-align:right">
                  <?php if ($podeEditarAlvoProd): ?>
                    <button class="vbtn vbtn-ghost vbtn-sm" type="button"
                            onclick="alvoProdDel(<?= (int)$ep['id'] ?>, '<?= h(addslashes((string)$ep['produto_nome'])) ?>')">Remover</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <?php if ($podeEditarAlvoProd): ?>
        <!-- staged: produtos a vincular ao SALVAR (arrays no próprio form do alvo) -->
        <table class="vtable" id="alvoprod-staged-tbl" style="margin-bottom:8px;display:none">
          <thead><tr><th>A vincular ao salvar</th><th style="text-align:right">Dose</th><th style="text-align:right">Calda</th><th>Obs.</th><th></th></tr></thead>
          <tbody id="alvoprod-staged"></tbody>
        </table>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;align-items:end">
          <div class="vfield" style="margin:0;grid-column:span 2"><label style="font-size:11px">Produto (defensivo)</label>
            <select id="alvoprod-id">
              <option value="">Selecione…</option>
              <?php foreach ($produtosDisponiveis as $pd): ?>
                <option value="<?= (int)$pd['id'] ?>"><?= h($pd['nome'] . ' (' . $pd['codigo'] . ')') ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="vfield" style="margin:0"><label style="font-size:11px">Dose</label>
            <input type="text" id="alvoprod-dose" inputmode="decimal" placeholder="ex.: 2,00"></div>
          <div class="vfield" style="margin:0"><label style="font-size:11px">Unidade</label>
            <input type="text" id="alvoprod-un" placeholder="mL/100L, kg/ha…"></div>
          <div class="vfield" style="margin:0"><label style="font-size:11px">Calda (L/ha)</label>
            <input type="text" id="alvoprod-calda" inputmode="decimal" placeholder="—"></div>
          <div class="vfield" style="margin:0;grid-column:span 2"><label style="font-size:11px">Observação</label>
            <input type="text" id="alvoprod-obs" maxlength="255"></div>
          <button class="vbtn vbtn-ghost" type="button" onclick="alvoProdStage()">+ Adicionar</button>
        </div>
        <div class="vhint" style="margin-top:4px">Os produtos adicionados são gravados ao clicar em <strong>Salvar</strong>.</div>
        <?php else: ?>
          <div class="vhint">Manutenção restrita a quem edita alvos (registro do RT).</div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (alvos_prod_ativo() && $podeEditarAlvoProd): /* DB-51: staged (criação+edição) + del (edição) */ ?>
<?php if ($edit): /* form oculto só p/ REMOVER um vínculo persistido */ ?>
<form method="post" id="alvoprod-form" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
  <input type="hidden" name="acao" value="prod_del">
  <input type="hidden" name="alvo_id" value="<?= (int)$edit['id'] ?>">
  <input type="hidden" name="vinculo_id" id="alvoprod-vinculo">
</form>
<?php endif; ?>
<script>
/* adiciona um produto à lista "staged" — grava inputs (arrays) DENTRO do form do
   alvo; persiste ao clicar em Salvar. Sem innerHTML com dados → sem XSS. */
function alvoProdStage() {
  var sel = document.getElementById('alvoprod-id');
  var pid = sel.value;
  if (!pid) { alert('Selecione o produto.'); return; }
  var dose = document.getElementById('alvoprod-dose').value.trim();
  var un   = document.getElementById('alvoprod-un').value.trim();
  if (!dose || !un) { alert('Informe dose e unidade.'); return; }
  var calda = document.getElementById('alvoprod-calda').value.trim();
  var obs   = document.getElementById('alvoprod-obs').value.trim();
  var pnome = sel.options[sel.selectedIndex].text;

  var tr = document.createElement('tr');
  var mk = function (nome, val) { var i = document.createElement('input'); i.type = 'hidden'; i.name = nome; i.value = val; return i; };
  var tdP = document.createElement('td');
  tdP.appendChild(document.createElement('strong')).textContent = pnome;
  tdP.appendChild(mk('prod_id[]', pid));
  tdP.appendChild(mk('prod_un[]', un));
  tdP.appendChild(mk('prod_obs[]', obs));
  var tdD = document.createElement('td'); tdD.style.textAlign = 'right'; tdD.textContent = dose + ' ' + un;
  tdD.appendChild(mk('prod_dose[]', dose));
  var tdC = document.createElement('td'); tdC.style.textAlign = 'right'; tdC.textContent = calda || '—';
  tdC.appendChild(mk('prod_calda[]', calda));
  var tdO = document.createElement('td'); tdO.className = 'vhint'; tdO.textContent = obs || '—';
  var tdX = document.createElement('td'); tdX.style.textAlign = 'right';
  var bx = document.createElement('button'); bx.type = 'button'; bx.className = 'vbtn vbtn-ghost vbtn-sm';
  bx.textContent = '×'; bx.onclick = function () { tr.remove(); toggleStagedTbl(); };
  tdX.appendChild(bx);
  tr.appendChild(tdP); tr.appendChild(tdD); tr.appendChild(tdC); tr.appendChild(tdO); tr.appendChild(tdX);
  document.getElementById('alvoprod-staged').appendChild(tr);
  toggleStagedTbl();

  sel.value = ''; document.getElementById('alvoprod-dose').value = '';
  document.getElementById('alvoprod-un').value = ''; document.getElementById('alvoprod-calda').value = '';
  document.getElementById('alvoprod-obs').value = '';
}
function toggleStagedTbl() {
  var tb = document.getElementById('alvoprod-staged');
  document.getElementById('alvoprod-staged-tbl').style.display = tb.children.length ? '' : 'none';
}
function alvoProdDel(vinculoId, nome) {
  if (!confirm('Remover o vínculo com "' + nome + '"? A trilha do RT é preservada.')) return;
  document.getElementById('alvoprod-vinculo').value = vinculoId;
  document.getElementById('alvoprod-form').submit();
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
