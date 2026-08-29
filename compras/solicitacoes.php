<?php
/* ============================================================
   VERO — Compras / Solicitações de Compra  (tela real)
   Substitui o mock. Rota: /compras/solicitacoes.php
   Guard: compras.solicitacoes_compra
   Tabelas: compras_solicitacoes + compras_solicitacao_itens
   Fluxo: aberta → convertida (vira pedido) | cancelada.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_helpers.php';

const T = 'compras_solicitacoes';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('compras.solicitacoes_compra.editar');

        $id   = vero_int('id');
        $data = vero_date('data_solicitacao');
        if ($data === null) {
            vero_flash('erro', 'Informe a data da solicitação.');
            vero_redirect();
        }
        $iProduto = (array)($_POST['i_produto'] ?? []);
        $iDesc    = (array)($_POST['i_descricao'] ?? []);
        $iQtd     = (array)($_POST['i_qtd'] ?? []);
        $parseDec = static function ($v): float {
            $v = trim((string)$v);
            if ($v === '') return 0.0;
            if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
            elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
            return is_numeric($v) ? (float)$v : 0.0;
        };

        $itens = [];
        foreach ($iQtd as $ix => $qtdRaw) {
            $qtd = $parseDec($qtdRaw);
            $prodId = (int)($iProduto[$ix] ?? 0);
            $desc = trim((string)($iDesc[$ix] ?? ''));
            if ($qtd <= 0 || (!$prodId && $desc === '')) continue;
            if ($prodId) {
                $ok = vero_val("SELECT id FROM estoque_produtos WHERE id=:i AND tenant_id=:t",
                    [':i' => $prodId, ':t' => vero_tenant()]);
                if (!$ok) continue;
            }
            $itens[] = ['produto_id' => $prodId ?: null, 'descricao' => $desc !== '' ? mb_substr($desc, 0, 180) : null, 'quantidade' => $qtd];
        }
        if (!$itens) {
            vero_flash('erro', 'Inclua ao menos um item (produto do estoque ou descrição livre) com quantidade.');
            vero_redirect();
        }

        /* DB-08: vínculo de custo já na solicitação */
        $safraTalhaoId = vero_int('safra_talhao_id');
        if ($safraTalhaoId) {
            $ok = vero_val("SELECT id FROM agro_safra_talhoes WHERE id=:i AND tenant_id=:t",
                [':i' => $safraTalhaoId, ':t' => vero_tenant()]);
            if (!$ok) $safraTalhaoId = null;
        }
        $centroCustoId = vero_int('centro_custo_id');
        if ($centroCustoId) {
            $ok = vero_val("SELECT id FROM centros_custo WHERE id=:i AND tenant_id=:t",
                [':i' => $centroCustoId, ':t' => vero_tenant()]);
            if (!$ok) $centroCustoId = null;
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $sol = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
                    [':i' => $id, ':t' => vero_tenant()]);
                if (!$sol || $sol['status'] !== 'aberta') throw new RuntimeException('Só solicitações abertas podem ser editadas.');
                vero_update(T, $id, ['data_solicitacao' => $data, 'justificativa' => vero_str('justificativa', 255),
                    'safra_talhao_id' => $safraTalhaoId, 'centro_custo_id' => $centroCustoId]);
                $pdo->prepare("DELETE FROM compras_solicitacao_itens WHERE tenant_id=? AND solicitacao_id=?")
                    ->execute([vero_tenant(), $id]);
                $solId = $id;
            } else {
                $solId = vero_insert(T, [
                    'numero'           => compras_next_numero(T, 'SC'),
                    'solicitante_id'   => vero_uid(),
                    'status'           => 'aberta',
                    'justificativa'    => vero_str('justificativa', 255),
                    'data_solicitacao' => $data,
                    'safra_talhao_id'  => $safraTalhaoId,
                    'centro_custo_id'  => $centroCustoId,
                ]);
            }
            foreach ($itens as $item) {
                $pdo->prepare("INSERT INTO compras_solicitacao_itens (tenant_id, solicitacao_id, produto_id, descricao, quantidade)
                               VALUES (?,?,?,?,?)")
                    ->execute([vero_tenant(), $solId, $item['produto_id'], $item['descricao'], $item['quantidade']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao salvar: ' . h($e->getMessage()));
            vero_redirect();
        }
        vero_flash('ok', 'Solicitação salva com ' . count($itens) . ' item(ns).');
        vero_redirect(BIOS_BASE . '/compras/solicitacoes');
    }

    if ($acao === 'cancelar') {
        vero_require('compras.solicitacoes_compra.excluir');
        $id = vero_int('id');
        $sol = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if ($sol && $sol['status'] === 'aberta') {
            vero_update(T, (int)$id, ['status' => 'cancelada']);
            vero_flash('ok', "Solicitação {$sol['numero']} cancelada.");
        } else {
            vero_flash('erro', 'Só solicitações abertas podem ser canceladas.');
        }
        vero_redirect();
    }
}

/* ── Impressão (?imprimir=ID) — documento standalone, sem o shell ── */
if (!empty($_GET['imprimir'])) {
    $sid = (int)$_GET['imprimir'];
    $s = vero_row(
        "SELECT s.*, u.nome AS solicitante,
                CONCAT(sa.identificacao, ' · ', tl.codigo) AS safra_talhao,
                CONCAT(cc.codigo, ' — ', cc.nome) AS centro_custo
           FROM " . T . " s
           LEFT JOIN usuarios u ON u.id = s.solicitante_id
           LEFT JOIN agro_safra_talhoes st ON st.id = s.safra_talhao_id
           LEFT JOIN agro_safras sa ON sa.id = st.safra_id
           LEFT JOIN agro_talhoes tl ON tl.id = st.talhao_id
           LEFT JOIN centros_custo cc ON cc.id = s.centro_custo_id
          WHERE s.id = :i AND s.tenant_id = :t", [':i' => $sid, ':t' => vero_tenant()]);
    if (!$s) { http_response_code(404); exit('Solicitação não encontrada.'); }
    $itens = vero_rows(
        "SELECT i.*, p.codigo, p.nome, p.unidade
           FROM compras_solicitacao_itens i
           LEFT JOIN estoque_produtos p ON p.id = i.produto_id
          WHERE i.tenant_id = :t AND i.solicitacao_id = :s ORDER BY i.id", [':t' => vero_tenant(), ':s' => $sid]);
    $stLabel = ['aberta' => 'Aberta', 'convertida' => 'Convertida em pedido', 'cancelada' => 'Cancelada'][$s['status']] ?? $s['status'];
    ?><!doctype html><html lang="pt-br"><head><meta charset="utf-8">
    <title>Solicitação de Compra <?= h($s['numero']) ?></title>
    <style>
      *{box-sizing:border-box} body{font:13px/1.5 'IBM Plex Sans',Arial,sans-serif;color:#241B14;margin:0;padding:28px;background:#fff}
      .doc{max-width:760px;margin:0 auto}
      .doc h1{font-size:19px;margin:0 0 2px} .muted{color:#6B5F53}
      .top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #005059;padding-bottom:10px;margin-bottom:14px}
      .meta{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;margin-bottom:16px;font-size:12.5px}
      .meta b{color:#4A4034}
      table{width:100%;border-collapse:collapse;margin-top:6px} th,td{padding:7px 9px;border-bottom:1px solid #E1D9C7;text-align:left}
      th{background:#F5F1E8;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#6B5F53}
      td.num,th.num{text-align:right} .just{margin-top:14px;font-size:12.5px} .just b{display:block;color:#4A4034;margin-bottom:3px}
      .noprint{margin:18px auto 0;max-width:760px;text-align:right}
      .btn{display:inline-block;border:0;background:#005059;color:#fff;border-radius:8px;padding:8px 16px;font:600 13px 'IBM Plex Sans';cursor:pointer;text-decoration:none}
      @media print{.noprint{display:none}body{padding:0}}
    </style></head><body>
    <div class="doc">
      <div class="top">
        <div><h1>Solicitação de Compra</h1><div class="muted">Nº <?= h($s['numero']) ?> · <?= h($stLabel) ?></div></div>
        <div class="muted" style="text-align:right"><?= date('d/m/Y', strtotime((string)$s['data_solicitacao'])) ?></div>
      </div>
      <div class="meta">
        <div><b>Solicitante:</b> <?= h($s['solicitante'] ?? '—') ?></div>
        <div><b>Safra / Válvula:</b> <?= h($s['safra_talhao'] ?? '—') ?></div>
        <div><b>Centro de custo:</b> <?= h($s['centro_custo'] ?? '—') ?></div>
        <div><b>Itens:</b> <?= count($itens) ?></div>
      </div>
      <table>
        <thead><tr><th>#</th><th>Item</th><th class="num">Quantidade</th></tr></thead>
        <tbody>
        <?php foreach ($itens as $k => $it): ?>
          <tr><td class="num"><?= $k + 1 ?></td>
            <td><?= $it['codigo'] ? '<strong>' . h($it['codigo']) . '</strong> ' . h($it['nome']) : h($it['descricao'] ?? '—') ?></td>
            <td class="num"><?= numFmt((float)$it['quantidade'], 2) ?> <?= h($it['unidade'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$itens): ?><tr><td colspan="3" class="muted">Sem itens.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <?php if ($s['justificativa']): ?><div class="just"><b>Justificativa</b><?= h($s['justificativa']) ?></div><?php endif; ?>
    </div>
    <div class="noprint"><a class="btn" href="javascript:window.print()">Imprimir</a></div>
    <script>window.addEventListener('load',function(){setTimeout(function(){window.print();},200);});</script>
    </body></html><?php
    exit;
}

/* ── Dados ──────────────────────────────────────────────────── */
$modoForm = isset($_GET['novo']) || !empty($_GET['editar']);

$edit = null;
$editItens = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t AND status='aberta'",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        $editItens = vero_rows("SELECT * FROM compras_solicitacao_itens WHERE tenant_id=:t AND solicitacao_id=:s ORDER BY id",
            [':t' => vero_tenant(), ':s' => (int)$edit['id']]);
    } else {
        $modoForm = false;
    }
}

$produtos = vero_rows("SELECT id, codigo, nome, unidade FROM estoque_produtos
                        WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => vero_tenant()]);

/* selects de vínculo de custo (DB-08) */
$safraTalhoesOpt = [];
foreach (vero_rows(
    "SELECT st.id, CONCAT(sa.identificacao, ' · ', tl.codigo) AS rotulo
       FROM agro_safra_talhoes st
       JOIN agro_safras sa ON sa.id = st.safra_id
       JOIN agro_talhoes tl ON tl.id = st.talhao_id
      WHERE st.tenant_id = :t ORDER BY sa.identificacao DESC, tl.codigo", [':t' => vero_tenant()]) as $stx) {
    $safraTalhoesOpt[(int)$stx['id']] = $stx['rotulo'];
}
$centrosCustoOpt = [];
foreach (vero_rows("SELECT id, CONCAT(codigo, ' — ', nome) AS rotulo FROM centros_custo
                     WHERE tenant_id = :t AND ativo = 1 ORDER BY codigo", [':t' => vero_tenant()]) as $ccx) {
    $centrosCustoOpt[(int)$ccx['id']] = $ccx['rotulo'];
}

if (!$modoForm) {
    $page    = max(1, (int)($_GET['pg'] ?? 1));
    $perPage = 15;
    $fStatus = (string)($_GET['status'] ?? '');
    $fq      = trim((string)($_GET['q'] ?? ''));
    $wSol = "s.tenant_id = :t";
    $pSol = [':t' => vero_tenant()];
    if (in_array($fStatus, ['aberta', 'convertida', 'cancelada'], true)) { $wSol .= " AND s.status = :st"; $pSol[':st'] = $fStatus; }
    if ($fq !== '') { $wSol .= " AND (s.numero LIKE :q1 OR s.justificativa LIKE :q2)"; foreach ([1, 2] as $qi) $pSol[":q{$qi}"] = "%{$fq}%"; /* QA-011 */ }
    $total = (int)vero_val("SELECT COUNT(*) FROM " . T . " s WHERE {$wSol}", $pSol);
    $rows  = vero_rows(
        "SELECT s.*, u.nome AS solicitante,
                (SELECT COUNT(*) FROM compras_solicitacao_itens i
                  WHERE i.tenant_id = s.tenant_id AND i.solicitacao_id = s.id) AS itens
           FROM " . T . " s
           LEFT JOIN usuarios u ON u.id = s.solicitante_id
          WHERE {$wSol}
          ORDER BY s.data_solicitacao DESC, s.id DESC
          LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $pSol);
}

$GUARD      = ['macro' => 'compras', 'micro' => 'solicitacoes_compra'];
$PAGE_VIEW  = 'compras_solicitacoes_compra';
$PAGE_TITLE = 'Solicitações de Compra';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('compras.solicitacoes_compra.editar');

$badgeStatus = static fn(string $s): string => match ($s) {
    'aberta'     => '<span class="vbadge vb-warn">Aberta</span>',
    'convertida' => '<span class="vbadge vb-ok">Convertida em pedido</span>',
    'cancelada'  => '<span class="vbadge vb-off">Cancelada</span>',
    default      => '<span class="vbadge vb-info">' . h($s) . '</span>',
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>

<?php if (!$modoForm): ?>
  <div class="vhead">
    <div>
      <h1>Solicitações de Compra</h1>
      <div class="vsub">O campo pede, compras converte em pedido ao fornecedor — itens do estoque ou descrição livre</div>
    </div>
    <?php if ($podeEditar): ?>
      <a class="vbtn vbtn-primary" href="?novo=1">+ Nova solicitação</a>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" class="vfiltros">
        <input type="text" name="q" value="<?= h($fq) ?>" placeholder="Buscar por nº ou justificativa…">
        <select name="status" onchange="this.form.submit()" aria-label="Status">
          <option value="">Todos os status</option>
          <option value="aberta"<?= $fStatus === 'aberta' ? ' selected' : '' ?>>Aberta</option>
          <option value="convertida"<?= $fStatus === 'convertida' ? ' selected' : '' ?>>Convertida em pedido</option>
          <option value="cancelada"<?= $fStatus === 'cancelada' ? ' selected' : '' ?>>Cancelada</option>
        </select>
        <button class="vbtn vbtn-primary vbtn-sm" type="submit">Buscar</button>
        <?php if ($fStatus !== '' || $fq !== ''): ?><a class="vbtn vbtn-ghost vbtn-sm" href="<?= h(strtok((string)$_SERVER['REQUEST_URI'], '?')) ?>" data-vero-clear>Limpar filtros</a><?php endif; ?>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>
    <?php if (!$rows): ?>
      <div class="vempty"><?= ($fStatus !== '' || $fq !== '') ? 'Nenhuma solicitação para os filtros selecionados.' : 'Nenhuma solicitação registrada ainda.' ?></div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata" style="min-width:720px">
      <thead><tr>
        <th>Nº</th><th>Data</th><th>Solicitante</th><th>Justificativa</th>
        <th class="num">Itens</th>
        <th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'cancelada' ? ' class="is-off"' : '' ?>>
          <td><strong class="vnum"><?= h($r['numero']) ?></strong></td>
          <td class="vnum" style="white-space:nowrap"><?= date('d/m/Y', strtotime((string)$r['data_solicitacao'])) ?></td>
          <td><?= h($r['solicitante'] ?? '—') ?></td>
          <td class="vhint"><?= h(mb_substr((string)($r['justificativa'] ?? ''), 0, 60)) ?: '—' ?></td>
          <td class="num"><?= (int)$r['itens'] ?></td>
          <td class="num"><div class="vactions" style="justify-content:flex-end">
            <?= vero_btn_icone(vero_ico_imprimir(), 'Imprimir solicitação', '', '?imprimir=' . (int)$r['id']) ?>
            <?php if ($podeEditar && $r['status'] === 'aberta'): ?>
              <?= vero_btn_icone(vero_ico_seta(), 'Converter em pedido', '', BIOS_BASE . '/compras/pedidos?novo=1&solicitacao=' . (int)$r['id']) ?>
              <?= vero_btn_icone(vero_ico_lapis(), 'Editar', '', '?editar=' . (int)$r['id']) ?>
              <?= vero_btn_icone_post(vero_ico_x(), 'Cancelar', 'cancelar', (int)$r['id'], 'Cancelar esta solicitação?', true) ?>
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

<?php else: ?>
  <?php if (!$podeEditar): ?>
    <div class="vflash vflash-erro">Sem permissão para registrar solicitações.</div>
  <?php else: ?>
  <div class="vhead">
    <div>
      <h1><?= $edit ? 'Editar solicitação ' . h($edit['numero']) : 'Nova solicitação de compra' ?></h1>
      <div class="vsub">Informe os itens necessários; a conversão em pedido acontece na listagem</div>
    </div>
    <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/compras/solicitacoes">← Voltar à lista</a>
  </div>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">

    <div class="vcard" style="padding:18px 22px;margin-bottom:16px">
      <div class="vgrid">
        <div class="vfield">
          <label>Data *</label>
          <input type="date" name="data_solicitacao" required
                 value="<?= h($edit ? (string)$edit['data_solicitacao'] : date('Y-m-d')) ?>">
        </div>
        <?= vero_f_text('justificativa', 'Justificativa', $edit['justificativa'] ?? '', false, 'Ex.: adubação da safra 2026.1') ?>
        <?= vero_f_select('safra_talhao_id', 'Safra · Válvula (vínculo de custo)', $safraTalhoesOpt,
              $edit['safra_talhao_id'] ?? null, false, '— Nenhum —') ?>
        <?= vero_f_select('centro_custo_id', 'Centro de custo', $centrosCustoOpt,
              $edit['centro_custo_id'] ?? null, false, '— Nenhum —') ?>
      </div>
      <div class="vhint" style="margin-top:8px">O vínculo segue para o pedido e alimenta o painel "Compras fora do orçamento".</div>
    </div>

    <div class="vcard" style="margin-bottom:16px">
      <div class="vtoolbar"><strong style="font-size:14px">Itens solicitados</strong>
        <div style="flex:1"></div>
        <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="addItem()">+ Item</button>
      </div>
      <table class="vtable">
        <thead><tr>
          <th style="width:40%">Produto do estoque</th>
          <th>ou descrição livre</th>
          <th style="width:130px;text-align:right">Quantidade</th>
          <th style="width:40px"></th>
        </tr></thead>
        <tbody id="itens-body"></tbody>
      </table>
      <div class="vempty" id="itens-vazio">Nenhum item — clique em “+ Item”.</div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px">
      <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/compras/solicitacoes">Cancelar</a>
      <button class="vbtn vbtn-primary" type="submit">Salvar solicitação</button>
    </div>
  </form>

  <script>
  const PRODUTOS = <?= jsvar(array_map(static fn($p) => [
      'id' => (int)$p['id'], 'nome' => $p['codigo'] . ' — ' . $p['nome'] . ' (' . $p['unidade'] . ')',
  ], $produtos)) ?>;
  const EDIT_ITENS = <?= jsvar(array_map(static fn($i) => [
      'produto' => $i['produto_id'] !== null ? (int)$i['produto_id'] : null,
      'descricao' => $i['descricao'], 'qtd' => (float)$i['quantidade'],
  ], $editItens)) ?>;

  function addItem(preset) {
    const tb = document.getElementById('itens-body');
    const tr = document.createElement('tr');
    const opts = ['<option value="">— Nenhum (usar descrição) —</option>']
      .concat(PRODUTOS.map(p => `<option value="${p.id}">${esc(p.nome)}</option>`)).join('');
    tr.innerHTML = `
      <td><select name="i_produto[]">${opts}</select></td>
      <td><input type="text" name="i_descricao[]" placeholder="Descrição livre (quando não é produto do estoque)"></td>
      <td><input type="text" name="i_qtd[]" style="text-align:right" placeholder="0"></td>
      <td><button type="button" class="vclose" title="Remover" onclick="this.closest('tr').remove(); vazio()">×</button></td>`;
    tb.appendChild(tr);
    if (preset) {
      if (preset.produto) tr.querySelector('select').value = String(preset.produto);
      if (preset.descricao) tr.querySelector('input[name="i_descricao[]"]').value = preset.descricao;
      tr.querySelector('input[name="i_qtd[]"]').value = String(preset.qtd).replace('.', ',');
    }
    vazio();
  }
  function vazio() {
    document.getElementById('itens-vazio').style.display =
      document.querySelectorAll('#itens-body tr').length ? 'none' : '';
  }
  EDIT_ITENS.forEach(i => addItem(i));
  if (!EDIT_ITENS.length) addItem();
  </script>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
