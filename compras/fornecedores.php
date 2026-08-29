<?php
/* ============================================================
   VERO — Compras / Fornecedores  (CRUD real)
   Substitui o mock. Rota da matriz: /compras/fornecedores.php
   Guard: compras.fornecedores | Escrita: compras.fornecedores.editar/excluir
   Tabela: fornecedores
   A2-F2-6 (DB-09): categoria/cidade/UF/condição de pagamento,
   CNPJ/CPF NORMALIZADO no salvar (mesma regra do get-or-create do
   fiscal — fecha o furo de duplicatas por pontuação) e ficha
   consolidada (?ficha=ID): volume, ticket, lead time, % no prazo,
   produtos fornecidos e últimas compras.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';  /* CONDICOES_PAGAMENTO (fonte única) */
require_once __DIR__ . '/_export.php';  /* sweep A2/A4: export CSV do filtro ativo */

const T = 'fornecedores';

const CATEGORIAS_FORN = ['defensivos' => 'Defensivos', 'fertilizantes' => 'Fertilizantes',
    'sementes_mudas' => 'Sementes/Mudas', 'pecas' => 'Peças', 'combustivel' => 'Combustível',
    'servicos' => 'Serviços', 'embalagens' => 'Embalagens', 'geral' => 'Geral'];

/* CONDICOES_PAGAMENTO agora é fonte única em includes/vero_services.php
   (compartilhada com Pedidos de compra). */

/** Normaliza CNPJ/CPF removendo pontuação (mesma regra do fiscal). */
function forn_normalizar_doc(?string $doc): ?string
{
    if ($doc === null) return null;
    $limpo = preg_replace('/\D+/', '', $doc);
    return $limpo !== '' ? $limpo : null;
}

/** Formata CNPJ (14) / CPF (11) para exibição; devolve como veio se não bater. */
function forn_fmt_doc(?string $doc): string
{
    $d = preg_replace('/\D+/', '', (string)$doc);
    if (strlen($d) === 14) return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d);
    if (strlen($d) === 11) return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d);
    return (string)$doc;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('compras.fornecedores.editar');

        $id   = vero_int('id');
        $nome = vero_str('nome', 150);
        if ($nome === null) {
            vero_flash('erro', 'Nome do fornecedor é obrigatório.');
            vero_redirect();
        }
        $cnpj = forn_normalizar_doc(vero_str('cnpj_cpf', 20));
        if ($cnpj !== null) {
            /* dedup pelo documento NORMALIZADO, inclusive inativos (UNIQUE do banco é global) */
            $dup = vero_row("SELECT id, nome, ativo FROM " . T . " WHERE tenant_id=:t AND cnpj_cpf=:c AND id<>:id",
                [':t' => vero_tenant(), ':c' => $cnpj, ':id' => (int)$id]);
            if ($dup) {
                vero_flash('erro', "O documento {$cnpj} já pertence a \"{$dup['nome']}\""
                    . ((int)$dup['ativo'] === 1 ? '.' : ' (inativo — reative-o em vez de duplicar).'));
                vero_redirect();
            }
        }

        $uf = strtoupper((string)(vero_str('uf', 2) ?? ''));
        $categoria = vero_str('categoria', 60);
        if ($categoria !== null && !isset(CATEGORIAS_FORN[$categoria])) $categoria = null;

        $data = [
            'nome'      => $nome,
            'cnpj_cpf'  => $cnpj,
            'contato'   => vero_str('contato', 120),
            'email'     => vero_str('email', 150),
            'telefone'  => vero_str('telefone', 40),
            'categoria' => $categoria,
            'cidade'    => vero_str('cidade', 80),
            'uf'        => preg_match('/^[A-Z]{2}$/', $uf) ? $uf : null,
            'condicao_pagamento' => vero_str('condicao_pagamento', 60),
            'observacoes' => vero_str('observacoes', 255),
            'ativo'     => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', "Fornecedor \"{$nome}\" atualizado.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Fornecedor \"{$nome}\" cadastrado.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('compras.fornecedores.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));      /* sweep A2/A4: Ativos/Inativos */
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

$where  = "f.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    $where .= " AND (f.nome LIKE :q1 OR f.cnpj_cpf LIKE :q2 OR f.contato LIKE :q3)";
    foreach ([1, 2, 3] as $qi) $params[":q{$qi}"] = "%{$q}%"; /* QA-011: placeholders distintos */
}
if ($fStatus === 'ativo')   $where .= " AND f.ativo = 1";
elseif ($fStatus === 'inativo') $where .= " AND f.ativo = 0";

/* sweep A2/A4: exportação CSV — MESMO filtro ativo, todos os registros (sem paginação) */
if (($_GET['csv'] ?? '') !== '') {
    vero_require('compras.fornecedores.ver'); /* guard REAL pré-header (bios_guard ainda não carregou aqui) */
    $rowsCsv = vero_rows(
        "SELECT f.*,
                (SELECT COUNT(*) FROM compras_pedidos p
                  WHERE p.tenant_id = f.tenant_id AND p.fornecedor_id = f.id) AS pedidos
           FROM " . T . " f WHERE {$where} ORDER BY f.ativo DESC, f.nome", $params);
    foreach ($rowsCsv as &$rc) {
        $rc['doc_fmt']     = $rc['cnpj_cpf'] ? forn_fmt_doc($rc['cnpj_cpf']) : '';
        $rc['cat_label']   = ($rc['categoria'] !== null && isset(CATEGORIAS_FORN[$rc['categoria']])) ? CATEGORIAS_FORN[$rc['categoria']] : '';
        $rc['local']       = trim(((string)($rc['cidade'] ?? '')) . ($rc['uf'] ? '/' . $rc['uf'] : ''));
        $rc['ativo_label'] = (int)$rc['ativo'] === 1 ? 'Ativo' : 'Inativo';
    }
    unset($rc);
    vero_csv_stream('compras', 'fornecedores', $rowsCsv, [
        'nome'               => 'Fornecedor',
        'doc_fmt'            => 'CNPJ/CPF',
        'cat_label'          => 'Categoria',
        'contato'            => 'Contato',
        'telefone'           => 'Telefone',
        'email'              => 'E-mail',
        'local'              => 'Cidade/UF',
        'condicao_pagamento' => 'Condição de pagamento',
        'pedidos'            => 'Pedidos',
        'ativo_label'        => 'Status',
    ], ['pedidos' => 'dec0']);
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " f WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT f.*,
            (SELECT COUNT(*) FROM compras_pedidos p
              WHERE p.tenant_id = f.tenant_id AND p.fornecedor_id = f.id) AS pedidos
       FROM " . T . " f
      WHERE {$where}
      ORDER BY f.ativo DESC, f.nome
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}
/* opções da condição: inclui o valor atual do fornecedor se estiver fora do padrão
   (preserva cadastros antigos de texto livre) */
$condOpts = CONDICOES_PAGAMENTO;
if ($edit && ($cpAtual = trim((string)($edit['condicao_pagamento'] ?? ''))) !== '' && !isset($condOpts[$cpAtual])) {
    $condOpts[$cpAtual] = $cpAtual . ' (atual)';
}

/* ── Ficha consolidada (?ficha=ID) — leitura ────────────────── */
$ficha = null;
if (!empty($_GET['ficha'])) {
    $t = vero_tenant();
    $fid = (int)$_GET['ficha'];
    $ficha = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $fid, ':t' => $t]);
    if ($ficha) {
        $ficha['resumo'] = vero_row(
            "SELECT COUNT(DISTINCT p.id) AS pedidos,
                    COALESCE(SUM(CASE WHEN p.status NOT IN ('cancelado','rascunho') THEN p.valor_total END),0) AS volume,
                    COALESCE(AVG(CASE WHEN p.status NOT IN ('cancelado','rascunho') THEN p.valor_total END),0) AS ticket,
                    AVG(CASE WHEN r.data_recebimento IS NOT NULL
                             THEN DATEDIFF(r.data_recebimento, p.data_pedido) END) AS lead_dias,
                    SUM(CASE WHEN p.data_entrega_prevista IS NOT NULL AND r.data_recebimento IS NOT NULL
                             THEN (DATE(r.data_recebimento) <= p.data_entrega_prevista) END) AS no_prazo,
                    SUM(CASE WHEN p.data_entrega_prevista IS NOT NULL AND r.data_recebimento IS NOT NULL THEN 1 END) AS com_prazo
               FROM compras_pedidos p
               LEFT JOIN compras_recebimentos r ON r.tenant_id = p.tenant_id AND r.pedido_id = p.id AND r.status = 'confirmado'
              WHERE p.tenant_id = :t AND p.fornecedor_id = :f", [':t' => $t, ':f' => $fid]);
        $ficha['produtos'] = vero_rows(
            "SELECT ep.codigo, ep.nome, ep.unidade,
                    SUM(ri.quantidade) AS qtd, AVG(ri.custo_unitario) AS preco_medio,
                    MAX(r.data_recebimento) AS ultima
               FROM compras_recebimento_itens ri
               JOIN compras_recebimentos r ON r.id = ri.recebimento_id AND r.status = 'confirmado'
               JOIN compras_pedidos p ON p.id = r.pedido_id AND p.fornecedor_id = :f
               LEFT JOIN estoque_produtos ep ON ep.id = ri.produto_id
              WHERE ri.tenant_id = :t
              GROUP BY ep.id, ep.codigo, ep.nome, ep.unidade
              ORDER BY qtd DESC LIMIT 10", [':t' => $t, ':f' => $fid]);
        $ficha['ultimas'] = vero_rows(
            "SELECT r.numero, r.data_recebimento, r.tipo, p.numero AS pedido,
                    (SELECT COALESCE(SUM(ri.quantidade * ri.custo_unitario),0) FROM compras_recebimento_itens ri
                      WHERE ri.tenant_id = r.tenant_id AND ri.recebimento_id = r.id) AS valor
               FROM compras_recebimentos r
               JOIN compras_pedidos p ON p.id = r.pedido_id AND p.fornecedor_id = :f
              WHERE r.tenant_id = :t AND r.status = 'confirmado'
              ORDER BY r.id DESC LIMIT 8", [':t' => $t, ':f' => $fid]);
    }
}

$GUARD      = ['macro' => 'compras', 'micro' => 'fornecedores'];
$PAGE_VIEW  = 'compras_fornecedores';
$PAGE_TITLE = 'Fornecedores';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('compras.fornecedores.editar');
/* sweep A2/A4: URL do "Exportar CSV" = filtro atual + csv=1 (sem a paginação) */
$qsExport = $_GET; unset($qsExport['pg']); $qsExport['csv'] = '1';
$exportUrl = strtok((string)$_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($qsExport);
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Fornecedores', 'Base do fluxo de compras — pedidos, recebimentos e contas a pagar',
        $podeEditar ? '+ Novo fornecedor' : null) ?>

  <?php if ($ficha): $rs = $ficha['resumo']; ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Ficha — <?= h($ficha['nome']) ?></strong>
      <span class="vsub"><?= $ficha['cnpj_cpf'] ? h(forn_fmt_doc($ficha['cnpj_cpf'])) : 'sem documento' ?>
        <?= $ficha['categoria'] !== null && isset(CATEGORIAS_FORN[$ficha['categoria']]) ? ' · ' . CATEGORIAS_FORN[$ficha['categoria']] : '' ?>
        <?= $ficha['cidade'] ? ' · ' . h($ficha['cidade'] . ($ficha['uf'] ? '/' . $ficha['uf'] : '')) : '' ?></span>
      <div style="flex:1"></div>
      <a class="vbtn vbtn-ghost vbtn-sm" href="fornecedores.php">← Fechar ficha</a></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Pedidos</span>
        <strong class="vnum" style="font-size:1.2rem"><?= (int)$rs['pedidos'] ?></strong></div>
      <div class="vkpi"><span class="vhint">Volume comprado</span>
        <strong class="vnum" style="font-size:1.2rem">R$ <?= numFmt((float)$rs['volume'], 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Ticket médio</span>
        <strong class="vnum" style="font-size:1.2rem">R$ <?= numFmt((float)$rs['ticket'], 2) ?></strong></div>
      <div class="vkpi"><span class="vhint">Lead time médio</span>
        <strong class="vnum" style="font-size:1.2rem"><?= $rs['lead_dias'] !== null ? numFmt((float)$rs['lead_dias'], 1) . ' d' : '—' ?></strong></div>
      <div class="vkpi"><span class="vhint">Entregas no prazo</span>
        <strong class="vnum" style="font-size:1.2rem"><?= (int)$rs['com_prazo'] > 0
            ? numFmt((float)$rs['no_prazo'] / (float)$rs['com_prazo'] * 100, 0) . '%' : '—' ?></strong>
        <span class="vhint"><?= (int)$rs['com_prazo'] ?> com prazo informado</span></div>
      <div class="vkpi"><span class="vhint">Condição padrão</span>
        <strong class="vnum" style="font-size:1.2rem"><?= h($ficha['condicao_pagamento'] ?? '—') ?></strong></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;padding:0 14px 14px">
      <div>
        <strong style="font-size:13px">Produtos fornecidos (do histórico)</strong>
        <?php if (!$ficha['produtos']): ?><div class="vempty">Sem recebimentos confirmados.</div>
        <?php else: ?>
        <table class="vtable">
          <thead><tr><th>Produto</th><th style="text-align:right">Qtd</th><th style="text-align:right">Preço médio</th><th>Última</th></tr></thead>
          <tbody>
          <?php foreach ($ficha['produtos'] as $pf): ?>
            <tr><td><?= $pf['codigo'] ? '<strong class="vnum">' . h($pf['codigo']) . '</strong> ' . h($pf['nome']) : '<span class="vhint">sem produto</span>' ?></td>
              <td class="vnum" style="text-align:right"><?= numFmt((float)$pf['qtd'], 2) ?> <span class="vhint"><?= h($pf['unidade'] ?? '') ?></span></td>
              <td class="vnum" style="text-align:right"><?= numFmt((float)$pf['preco_medio'], 2) ?></td>
              <td class="vnum"><?= $pf['ultima'] ? date('d/m/Y', strtotime((string)$pf['ultima'])) : '—' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <div>
        <strong style="font-size:13px">Últimas compras</strong>
        <?php if (!$ficha['ultimas']): ?><div class="vempty">Sem recebimentos confirmados.</div>
        <?php else: ?>
        <table class="vtable">
          <thead><tr><th>Recebimento</th><th>Pedido</th><th>Data</th><th style="text-align:right">Valor (R$)</th></tr></thead>
          <tbody>
          <?php foreach ($ficha['ultimas'] as $uc): ?>
            <tr><td class="vnum"><?= h($uc['numero'] ?? '—') ?>
                <?= $uc['tipo'] === 'total' ? '<span class="vbadge vb-ok">total</span>' : '<span class="vbadge vb-warn">parcial</span>' ?></td>
              <td class="vnum"><?= h($uc['pedido']) ?></td>
              <td class="vnum"><?= date('d/m/Y', strtotime((string)$uc['data_recebimento'])) ?></td>
              <td class="vnum" style="text-align:right"><strong><?= numFmt((float)$uc['valor'], 2) ?></strong></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <style>
  .fo-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .fo-table{width:100%;border-collapse:collapse;min-width:780px}
  .fo-table thead th{background:#F5F1E8;font:600 11px 'IBM Plex Sans';text-transform:uppercase;letter-spacing:.03em;color:#6B5F53;border-bottom:2px solid #E1D9C7;padding:10px 12px;text-align:left;white-space:nowrap}
  .fo-table tbody td{padding:9px 12px;border-bottom:1px solid #F0EBDF;vertical-align:top}
  .fo-table tbody tr:nth-child(even){background:#FBFAF6}
  .fo-table tbody tr:hover{background:#F4F1E8}
  .fo-table tbody tr.fo-off{opacity:.55}
  .fo-cat{font-size:11.5px;color:#6B5F53}
  </style>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nome, documento ou contato…" style="flex:1;min-width:200px">
        <select name="status" onchange="this.form.submit()" aria-label="Status">
          <option value="">Ativos e inativos</option>
          <option value="ativo"<?= $fStatus === 'ativo' ? ' selected' : '' ?>>Ativos</option>
          <option value="inativo"<?= $fStatus === 'inativo' ? ' selected' : '' ?>>Inativos</option>
        </select>
        <button class="vbtn vbtn-primary vbtn-sm" type="submit">Buscar</button>
        <?php if ($q !== '' || $fStatus !== ''): ?><a class="vbtn vbtn-ghost vbtn-sm" href="<?= h(strtok((string)$_SERVER['REQUEST_URI'], '?')) ?>" data-vero-clear>Limpar filtros</a><?php endif; ?>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h($exportUrl) ?>" title="Baixar a lista filtrada em CSV (abre no Excel)">Exportar CSV</a>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty"><?= $q !== '' ? 'Nenhum fornecedor encontrado para a busca.' : 'Nenhum fornecedor cadastrado ainda.' ?></div>
    <?php else: ?>
    <div class="fo-wrap">
    <table class="fo-table">
      <thead><tr>
        <th>Fornecedor</th><th>CNPJ/CPF</th><th>Contato</th><th>Telefone / E-mail</th>
        <th class="pr-num" style="text-align:right">Pedidos</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= (int)$r['ativo'] === 0 ? ' class="fo-off"' : '' ?>>
          <td><strong><?= h($r['nome']) ?></strong>
            <?= $r['categoria'] !== null && isset(CATEGORIAS_FORN[$r['categoria']])
                ? '<div class="fo-cat">' . h(CATEGORIAS_FORN[$r['categoria']]) . '</div>' : '' ?></td>
          <td class="vnum"><?= $r['cnpj_cpf'] ? h(forn_fmt_doc($r['cnpj_cpf'])) : '—' ?>
            <?= $r['cidade'] ? '<div class="vhint">' . h($r['cidade'] . ($r['uf'] ? '/' . $r['uf'] : '')) . '</div>' : '' ?></td>
          <td><?= h($r['contato'] ?? '') ?: '—' ?></td>
          <td class="vhint"><?= h(trim(($r['telefone'] ?? '') . ' ' . ($r['email'] ?? ''))) ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['pedidos'] ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions" style="justify-content:flex-end">
            <?= vero_btn_icone(vero_ico_olho(), 'Ver ficha', '', '?ficha=' . (int)$r['id']) ?>
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('compras.fornecedores.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este fornecedor?') ?>
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
      <h2><?= $edit ? 'Editar fornecedor' : 'Novo fornecedor' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('nome', 'Nome / razão social', $edit['nome'] ?? '', true) ?></div>
        <?= vero_f_text('cnpj_cpf', 'CNPJ / CPF', $edit['cnpj_cpf'] ?? '', false, 'salvo sem pontuação (normalizado)') ?>
        <?= vero_f_select('categoria', 'Categoria', CATEGORIAS_FORN, $edit['categoria'] ?? null, false, '— Nenhuma —') ?>
        <?= vero_f_text('contato', 'Pessoa de contato', $edit['contato'] ?? '') ?>
        <?= vero_f_text('telefone', 'Telefone', $edit['telefone'] ?? '') ?>
        <?= vero_f_text('email', 'E-mail', $edit['email'] ?? '', false, '', 'email') ?>
        <?= vero_f_text('cidade', 'Cidade', $edit['cidade'] ?? '') ?>
        <?= vero_f_text('uf', 'UF', $edit['uf'] ?? '', false, 'ex.: PE') ?>
        <?= vero_f_select('condicao_pagamento', 'Condição de pagamento padrão', $condOpts, $edit['condicao_pagamento'] ?? null, false, '— Não definida —') ?>
        <div class="full"><?= vero_f_text('observacoes', 'Observações', $edit['observacoes'] ?? '') ?></div>
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
