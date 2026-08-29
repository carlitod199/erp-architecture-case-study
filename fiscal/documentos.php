<?php
/* ============================================================
   VERO — Fiscal / Documentos Fiscais  (tela real)
   Substitui o mock. Rota: /fiscal/documentos.php
   Guard: fiscal.documentos_fiscais
   Central de fiscal_documentos: filtros, detalhe com itens e
   anexos, registro manual, recusar/reativar. Importação de XML
   fica em Importação de NF-e; conciliação na tela própria.
   Módulo fora do go-live (D7) — telas prontas para quando o
   cliente ativar.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'fiscal_documentos';
const TIPOS_DOC = ['nfe' => 'NF-e', 'nfce' => 'NFC-e', 'cte' => 'CT-e', 'outro' => 'Outro'];

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('fiscal.documentos_fiscais.editar');
        $id     = vero_int('id');
        $numero = vero_str('numero', 30);
        $valor  = vero_dec('valor_total');
        if ($numero === null || $valor === null || $valor < 0) {
            vero_flash('erro', 'Número e valor são obrigatórios.');
            vero_redirect();
        }
        $tipo = (string)($_POST['tipo'] ?? 'nfe');
        if (!isset(TIPOS_DOC[$tipo])) $tipo = 'outro';
        $chave = vero_str('chave', 60);
        if ($chave !== null) {
            $chave = preg_replace('/\D/', '', $chave);
            if ($chave !== '' && !preg_match('/^\d{44}$/', $chave)) {
                vero_flash('erro', 'Chave de acesso deve ter 44 dígitos (ou ficar em branco).');
                vero_redirect();
            }
            $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND chave=:c AND id<>:id",
                [':t' => $t, ':c' => $chave, ':id' => (int)$id]);
            if ($chave !== '' && $dup) {
                vero_flash('erro', 'Já existe documento com esta chave de acesso.');
                vero_redirect();
            }
        }
        $data = [
            'tipo'          => $tipo,
            'numero'        => $numero,
            'chave'         => $chave ?: null,
            'fornecedor_id' => vero_int('fornecedor_id') ?: null,
            'valor_total'   => $valor,
            'data_emissao'  => vero_date('data_emissao'),
        ];
        if ($id) { vero_update(T, $id, $data); vero_flash('ok', "Documento {$numero} atualizado."); }
        else     { vero_insert(T, $data);      vero_flash('ok', "Documento {$numero} registrado."); }
        vero_redirect();
    }

    if (in_array($acao, ['recusar', 'reativar'], true)) {
        vero_require('fiscal.documentos_fiscais.editar');
        $id = vero_int('id');
        $doc = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if ($doc) {
            vero_update(T, (int)$id, ['status' => $acao === 'recusar' ? 'recusado' : 'importado']);
            vero_flash('ok', 'Documento ' . ($acao === 'recusar' ? 'recusado (linha preservada)' : 'reativado') . '.');
        }
        vero_redirect();
    }
}

$fTipo   = (string)($_GET['tipo'] ?? '');
$fStatus = (string)($_GET['status'] ?? '');
$fFila   = (string)($_GET['fila'] ?? '');
if (!in_array($fFila, ['a_conciliar', 'conciliadas', 'registro'], true)) $fFila = '';
$q       = trim((string)($_GET['q'] ?? ''));

$where  = "d.tenant_id = :t";
$params = [':t' => $t];
if (isset(TIPOS_DOC[$fTipo]))                                { $where .= " AND d.tipo = :tp";   $params[':tp'] = $fTipo; }
if (in_array($fStatus, ['importado', 'conciliado', 'recusado'], true)) { $where .= " AND d.status = :st"; $params[':st'] = $fStatus; }
if ($q !== '') { $where .= " AND (d.numero LIKE :q OR d.chave LIKE :q2)"; $params[':q'] = "%{$q}%"; $params[':q2'] = "%{$q}%"; }

/* Inbox lite (auditoria UX 19/07): filas com a MESMA regra dos contadores.
   Vínculo real de conciliação: fiscal_conciliacoes (documento_id → pedido_id),
   linha com status <> 'pendente' = documento já casado (conciliado OU divergente). */
const FILA_SEM_CONC = "NOT EXISTS (SELECT 1 FROM fiscal_conciliacoes c
        WHERE c.tenant_id = d.tenant_id AND c.documento_id = d.id AND c.status <> 'pendente')";
if ($fFila === 'a_conciliar') $where .= " AND d.status = 'importado' AND d.fornecedor_id IS NOT NULL AND " . FILA_SEM_CONC;
if ($fFila === 'conciliadas') $where .= " AND d.status = 'conciliado'";
if ($fFila === 'registro')    $where .= " AND d.fornecedor_id IS NULL AND d.status <> 'recusado'";

/* contadores das filas — acervo inteiro do tenant, independentes dos filtros */
$filas = vero_row(
    "SELECT COALESCE(SUM(d.status = 'importado' AND d.fornecedor_id IS NOT NULL AND " . FILA_SEM_CONC . "),0) AS a_conciliar,
            COALESCE(SUM(d.status = 'conciliado'),0) AS conciliadas,
            COALESCE(SUM(d.fornecedor_id IS NULL AND d.status <> 'recusado'),0) AS registro
       FROM " . T . " d WHERE d.tenant_id = :t", [':t' => $t]) ?: ['a_conciliar' => 0, 'conciliadas' => 0, 'registro' => 0];

$rows = vero_rows(
    "SELECT d.*, f.nome AS fornecedor,
            fc.status AS conc_status, fc.pedido_id AS conc_pedido_id, cp.numero AS pedido_numero,
            (SELECT COUNT(*) FROM fiscal_documento_itens i WHERE i.tenant_id = d.tenant_id AND i.documento_id = d.id) AS itens,
            (SELECT COUNT(*) FROM agro_anexos an WHERE an.tenant_id = d.tenant_id
              AND an.origem_tipo = 'fiscal_documento' AND an.origem_id = d.id) AS anexos
       FROM " . T . " d
       LEFT JOIN fornecedores f ON f.id = d.fornecedor_id
       LEFT JOIN fiscal_conciliacoes fc ON fc.tenant_id = d.tenant_id AND fc.documento_id = d.id AND fc.status <> 'pendente'
       LEFT JOIN compras_pedidos cp ON cp.id = fc.pedido_id
      WHERE {$where}
      ORDER BY d.data_emissao DESC, d.id DESC LIMIT 100", $params);
$totValor = 0.0;
foreach ($rows as $r) if ($r['status'] !== 'recusado') $totValor += (float)$r['valor_total'];

$fornecedores = vero_options('fornecedores', 'nome');

$edit = null;
$detalhe = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => (int)$_GET['editar'], ':t' => $t]);
}
if (!empty($_GET['ver'])) {
    $detalhe = vero_row("SELECT d.*, f.nome AS fornecedor FROM " . T . " d
        LEFT JOIN fornecedores f ON f.id = d.fornecedor_id
        WHERE d.id=:i AND d.tenant_id=:t", [':i' => (int)$_GET['ver'], ':t' => $t]);
    if ($detalhe) {
        $detalhe['itens_rows'] = vero_rows("SELECT * FROM fiscal_documento_itens
            WHERE tenant_id=:t AND documento_id=:d ORDER BY id", [':t' => $t, ':d' => (int)$detalhe['id']]);
        $detalhe['anexos_rows'] = vero_rows("SELECT * FROM agro_anexos
            WHERE tenant_id=:t AND origem_tipo='fiscal_documento' AND origem_id=:d ORDER BY id",
            [':t' => $t, ':d' => (int)$detalhe['id']]);
        /* amarração real: conciliação → pedido → recebimento(s) → título(s) do financeiro
           (movimentacoes_financeiras.origem_tipo = 'compras_recebimento') */
        $detalhe['conc'] = vero_row(
            "SELECT fc.*, cp.numero AS pedido_numero, cp.status AS pedido_status
               FROM fiscal_conciliacoes fc
               LEFT JOIN compras_pedidos cp ON cp.id = fc.pedido_id
              WHERE fc.tenant_id = :t AND fc.documento_id = :d AND fc.status <> 'pendente'
              ORDER BY fc.id DESC LIMIT 1", [':t' => $t, ':d' => (int)$detalhe['id']]);
        $detalhe['recebs'] = [];
        $detalhe['titulos'] = [];
        if ($detalhe['conc'] && $detalhe['conc']['pedido_id'] !== null) {
            $detalhe['recebs'] = vero_rows(
                "SELECT id, numero, tipo, data_recebimento FROM compras_recebimentos
                  WHERE tenant_id = :t AND pedido_id = :p AND status = 'confirmado' ORDER BY id",
                [':t' => $t, ':p' => (int)$detalhe['conc']['pedido_id']]);
            if ($detalhe['recebs']) {
                $recebIds = implode(',', array_map(static fn($r) => (int)$r['id'], $detalhe['recebs']));
                $detalhe['titulos'] = vero_rows(
                    "SELECT m.id, m.descricao, m.valor, m.status, m.data_vencimento, m.origem_id
                       FROM movimentacoes_financeiras m
                      WHERE m.tenant_id = :t AND m.tipo = 'pagar'
                        AND m.origem_tipo = 'compras_recebimento' AND m.origem_id IN ({$recebIds})
                        AND m.status <> 'cancelado'
                      ORDER BY m.id", [':t' => $t]);
            }
        }
    }
}

$badgeStatus = static fn(string $s): string => match ($s) {
    'conciliado' => '<span class="vbadge vb-ok">Conciliado</span>',
    'recusado'   => '<span class="vbadge vb-off">Recusado</span>',
    default      => '<span class="vbadge vb-info">Importado</span>',
};

/* chip de ESTADO do documento (inbox lite) — mesma régua das filas */
$chipEstado = static function (array $r): string {
    if ($r['status'] === 'recusado')            return '<span class="vbadge vb-off">Recusado</span>';
    if ($r['status'] === 'conciliado')          return '<span class="vbadge vb-ok">Conciliada</span>';
    if (($r['conc_status'] ?? null) === 'divergente') return '<span class="vbadge vb-warn">Divergente</span>';
    if ($r['fornecedor_id'] === null)           return '<span class="vbadge vb-info">Registro</span>';
    return '<span class="vbadge vb-warn">A conciliar</span>';
};

$GUARD      = ['macro' => 'fiscal', 'micro' => 'documentos_fiscais'];
$PAGE_VIEW  = 'fiscal_documentos_fiscais';
$PAGE_TITLE = 'Documentos Fiscais';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('fiscal.documentos_fiscais.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Documentos Fiscais', 'Central de NF-e e demais documentos — módulo pronto, fora do go-live por decisão (D7)',
        $podeEditar ? '+ Registro manual' : null) ?>

  <?php
  /* filas do inbox — clicar filtra a lista; clicar de novo desliga o filtro */
  $filaCards = [
      'a_conciliar' => ['A conciliar', 'NF de entrada sem pedido casado', (int)$filas['a_conciliar'], '#B57C1A'],
      'conciliadas' => ['Conciliadas', 'casadas com pedido de compra',    (int)$filas['conciliadas'], '#0E7E72'],
      'registro'    => ['Notas próprias (registro)', 'sem emitente vinculado', (int)$filas['registro'], '#005059'],
  ];
  ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:14px">
    <?php foreach ($filaCards as $fk => [$rotulo, $sub, $n, $cor]): $ativa = $fFila === $fk; ?>
    <a class="vcard" href="<?= $base ?>/fiscal/documentos.php<?= $ativa ? '' : '?fila=' . $fk ?>"
       style="display:flex;align-items:center;gap:12px;padding:12px 14px;text-decoration:none;<?= $ativa ? 'outline:2px solid ' . $cor . ';' : '' ?>">
      <strong class="vnum" style="font-size:1.4rem;color:<?= $cor ?>"><?= $n ?></strong>
      <span style="display:flex;flex-direction:column;min-width:0">
        <strong><?= h($rotulo) ?></strong>
        <span class="vhint"><?= h($sub) ?><?= $ativa ? ' · filtrando — clique p/ limpar' : '' ?></span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($detalhe): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong><?= h(TIPOS_DOC[(string)$detalhe['tipo']] ?? 'Documento') ?>
        <?= h($detalhe['numero'] ?? '') ?> — <?= h($detalhe['fornecedor'] ?? 'sem fornecedor') ?></strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/fiscal/documentos.php">← Voltar</a></div>
    <div style="padding:12px 14px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
      <div class="vkpi"><span class="vhint">Chave de acesso</span>
        <strong class="vnum" style="font-size:.82rem;word-break:break-all"><?= h($detalhe['chave'] ?? '—') ?></strong></div>
      <div class="vkpi"><span class="vhint">Emissão</span>
        <strong class="vnum"><?= $detalhe['data_emissao'] ? date('d/m/Y', strtotime((string)$detalhe['data_emissao'])) : '—' ?></strong></div>
      <div class="vkpi"><span class="vhint">Valor total</span>
        <strong class="vnum">R$ <?= numFmt((float)$detalhe['valor_total'], 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Status</span><?= $badgeStatus((string)$detalhe['status']) ?></div>
    </div>
    <?php $conc = $detalhe['conc'] ?? null; ?>
    <div class="vtoolbar" style="border-top:1px solid var(--vero-border,#eee)"><strong>Amarração com Compras e Financeiro</strong></div>
    <div style="padding:12px 14px">
      <?php if ($conc): ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <?= $conc['status'] === 'divergente'
              ? '<span class="vbadge vb-warn">Divergente</span>' : '<span class="vbadge vb-ok">Conciliada</span>' ?>
          <?php if ($conc['pedido_numero'] !== null): ?>
            <?php if (vero_can('compras.pedidos_compra.ver')): ?>
              <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/compras/pedidos.php">Pedido <?= h((string)$conc['pedido_numero']) ?></a>
            <?php else: ?>
              <span class="vhint">Pedido <?= h((string)$conc['pedido_numero']) ?></span>
            <?php endif; ?>
          <?php endif; ?>
          <?php foreach ($detalhe['recebs'] as $rc): ?>
            <?php if (vero_can('compras.recebimentos.ver')): ?>
              <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/compras/recebimentos.php">Recebimento <?= h((string)$rc['numero']) ?></a>
            <?php else: ?>
              <span class="vhint">Recebimento <?= h((string)$rc['numero']) ?></span>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php foreach ($detalhe['titulos'] as $tt): ?>
            <?php $rotTit = 'Título R$ ' . numFmt((float)$tt['valor'], 2) . ' (' . h((string)$tt['status']) . ')';
                  $rcNum = '';
                  foreach ($detalhe['recebs'] as $rc2) if ((int)$rc2['id'] === (int)$tt['origem_id']) $rcNum = (string)$rc2['numero']; ?>
            <?php if (vero_can('financeiro.contas_pagar.ver')): ?>
              <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/financeiro/contas_pagar.php?q=<?= urlencode($rcNum) ?>"><?= $rotTit ?></a>
            <?php else: ?>
              <span class="vhint"><?= $rotTit ?></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php if ($conc['pedido_id'] !== null && !$detalhe['recebs']): ?>
          <div class="vhint" style="margin-top:6px">Pedido ainda sem recebimento confirmado no sistema.</div>
        <?php elseif ($detalhe['recebs'] && !$detalhe['titulos']): ?>
          <div class="vhint" style="margin-top:6px">Recebimento sem título vinculado no Financeiro.</div>
        <?php endif; ?>
        <?php if ($conc['observacao']): ?><div class="vhint" style="margin-top:6px"><?= h((string)$conc['observacao']) ?></div><?php endif; ?>
      <?php elseif ($detalhe['status'] === 'recusado'): ?>
        <span class="vhint">Documento recusado — fora do fluxo de conciliação.</span>
      <?php elseif ($detalhe['fornecedor_id'] === null): ?>
        <span class="vhint">Nota própria (registro) — sem emitente vinculado, não passa pela conciliação de compras.</span>
      <?php else: ?>
        <span class="vhint">Sem vínculo com pedido de compra —</span>
        <a href="<?= $base ?>/fiscal/conciliacao_fiscal.php">conciliar agora</a>
      <?php endif; ?>
    </div>
    <?php if ($detalhe['itens_rows']): ?>
    <div class="vtoolbar" style="border-top:1px solid var(--vero-border,#eee)"><strong>Itens do documento</strong></div>
    <table class="vtable">
      <thead><tr><th>Descrição</th><th style="text-align:right">Qtd</th>
        <th style="text-align:right">Vlr. unit. (R$)</th><th style="text-align:right">Total (R$)</th></tr></thead>
      <tbody>
      <?php foreach ($detalhe['itens_rows'] as $i): ?>
        <tr>
          <td><?= h($i['descricao'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$i['quantidade'], 2) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$i['valor_unitario'], 4) ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$i['valor_total'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <?php if ($detalhe['anexos_rows']): ?>
    <div class="vtoolbar" style="border-top:1px solid var(--vero-border,#eee)"><strong>Anexos</strong></div>
    <table class="vtable">
      <tbody>
      <?php foreach ($detalhe['anexos_rows'] as $an): ?>
        <tr>
          <td><span class="vbadge vb-info"><?= h($an['tipo_arquivo'] ?? 'arquivo') ?></span>
            <?= h($an['nome_original'] ?? '—') ?></td>
          <td style="text-align:right"><a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base . h((string)$an['url']) ?>" target="_blank">Baixar</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="tipo" onchange="this.form.submit()">
          <option value="">Todos os tipos</option>
          <?php foreach (TIPOS_DOC as $k => $rotulo): ?>
            <option value="<?= $k ?>"<?= $fTipo === $k ? ' selected' : '' ?>><?= h($rotulo) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
          <option value="">Todos os status</option>
          <option value="importado"<?= $fStatus === 'importado' ? ' selected' : '' ?>>Importados</option>
          <option value="conciliado"<?= $fStatus === 'conciliado' ? ' selected' : '' ?>>Conciliados</option>
          <option value="recusado"<?= $fStatus === 'recusado' ? ' selected' : '' ?>>Recusados</option>
        </select>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Número ou chave…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= count($rows) ?> documento(s) ·
        <strong class="vnum">R$ <?= numFmt($totValor, 2) ?></strong> ·
        <a href="<?= $base ?>/fiscal/importacao_nfe.php">importar XML</a></span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum documento fiscal — importe um XML de NF-e ou faça um registro manual.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Tipo</th><th>Número</th><th>Fornecedor</th><th>Emissão</th>
        <th style="text-align:right">Valor (R$)</th>
        <th style="text-align:right">Itens</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'recusado' ? ' style="opacity:.55"' : '' ?>>
          <td><span class="vbadge vb-info"><?= h(TIPOS_DOC[(string)$r['tipo']] ?? ucfirst((string)$r['tipo'])) ?></span></td>
          <td><strong class="vnum"><?= h($r['numero'] ?? '—') ?></strong>
            <?= (int)$r['anexos'] > 0 ? '<span class="vhint">📎' . (int)$r['anexos'] . '</span>' : '' ?></td>
          <td><?= h($r['fornecedor'] ?? '—') ?></td>
          <td class="vnum"><?= $r['data_emissao'] ? date('d/m/Y', strtotime((string)$r['data_emissao'])) : '—' ?></td>
          <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$r['valor_total'], 2) ?></strong></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['itens'] ?></td>
          <td><?= $chipEstado($r) ?>
            <?php if ($r['status'] === 'conciliado' && $r['pedido_numero'] !== null): ?>
              <a class="vhint" href="?ver=<?= (int)$r['id'] ?>" title="Ver amarração com o pedido e o título">→ <?= h((string)$r['pedido_numero']) ?></a>
            <?php endif; ?></td>
          <td><div class="vactions">
            <a class="vicon vicon-acao" href="?ver=<?= (int)$r['id'] ?>" title="Detalhe" aria-label="Detalhe"><?= vero_ico_olho() ?></a>
            <?php if ($podeEditar && $r['status'] !== 'recusado'): ?>
              <?= vero_btn_editar((int)$r['id']) ?>
              <form method="post" onsubmit="return confirm('Recusar este documento?')">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="recusar">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="vicon vicon-del" type="submit" title="Recusar" aria-label="Recusar"><?= vero_ico_x() ?></button>
              </form>
            <?php elseif ($podeEditar): ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="reativar">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="vicon vicon-acao" type="submit" title="Reativar" aria-label="Reativar"><?= vero_ico_check() ?></button>
              </form>
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
      <h2><?= $edit ? 'Editar documento' : 'Registro manual de documento' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_select('tipo', 'Tipo', TIPOS_DOC, $edit['tipo'] ?? 'nfe', true, '') ?>
        <?= vero_f_text('numero', 'Número', $edit['numero'] ?? '', true) ?>
        <div class="full"><?= vero_f_text('chave', 'Chave de acesso (44 dígitos, opcional)', $edit['chave'] ?? '') ?></div>
        <?= vero_f_select('fornecedor_id', 'Fornecedor/emitente', ['' => 'Sem vínculo'] + $fornecedores, $edit['fornecedor_id'] ?? '', false, '') ?>
        <?= vero_f_text('valor_total', 'Valor total (R$)', $edit ? numFmt((float)$edit['valor_total'], 2) : '', true) ?>
        <div class="vfield">
          <label>Data de emissão</label>
          <input type="date" name="data_emissao" value="<?= h($edit['data_emissao'] ?? '') ?>">
        </div>
      </div>
      <div class="vhint" style="margin-top:8px">Para NF-e com XML em mãos, prefira a Importação de NF-e — ela extrai chave, emitente e itens automaticamente.</div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
