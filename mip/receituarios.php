<?php
/* ============================================================
   VERO — MIP / Receituários  (CRUD real)
   Rota da matriz: /mip/receituarios.php (rota real via A0 — $rotasReais)
   Guard: mip.receituarios | Escrita: mip.receituarios.editar/excluir
   Tabelas: agro_receituarios (mig. 120 — ganhou tela na A1-18) +
   agro_anexos com origem_tipo='receituario' (contrato DB-18).
   REGISTRO da prescrição emitida pelo responsável técnico — o
   sistema NUNCA recomenda produto/dose/carência (Regra 1); aqui
   só se arquiva o documento e o vínculo com a aplicação.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'agro_receituarios';
const ANEXO_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('mip.receituarios.editar');

        $id     = vero_int('id');
        $numero = vero_str('numero', 60);
        if ($numero === null) {
            vero_flash('erro', 'Informe o número do receituário.');
            vero_redirect();
        }
        $aplicacaoId = vero_int('aplicacao_id');
        if ($aplicacaoId) {
            $okAp = vero_val("SELECT id FROM agro_aplicacoes WHERE id=:i AND tenant_id=:t AND status <> 'cancelada'",
                [':i' => $aplicacaoId, ':t' => $t]);
            if (!$okAp) {
                vero_flash('erro', 'Aplicação inválida ou cancelada.');
                vero_redirect();
            }
        }
        $emitidoPor = vero_int('emitido_por');
        if ($emitidoPor && !vero_val("SELECT id FROM agro_operadores WHERE id=:i AND tenant_id=:t",
            [':i' => $emitidoPor, ':t' => $t])) $emitidoPor = null;

        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND numero=:n AND id<>:id",
            [':t' => $t, ':n' => $numero, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe o receituário \"{$numero}\".");
            vero_redirect();
        }

        $dados = [
            'numero'       => $numero,
            'aplicacao_id' => $aplicacaoId ?: null,
            'emitido_por'  => $emitidoPor,
            'emitido_em'   => vero_date('emitido_em') ?? date('Y-m-d'),
            'validade'     => vero_date('validade'),
            'observacao'   => vero_str('observacao', 2000),
        ];
        if ($id) {
            vero_update(T, $id, $dados);
            vero_flash('ok', "Receituário \"{$numero}\" atualizado.");
        } else {
            $id = vero_insert(T, $dados);
            vero_flash('ok', "Receituário \"{$numero}\" registrado. Anexe o documento (PDF).");
        }
        vero_redirect('?editar=' . (int)$id);
    }

    if ($acao === 'anexar') {
        vero_require('mip.receituarios.editar');
        $recId = vero_int('id');
        $rec = $recId ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $recId, ':t' => $t]) : null;
        $file = $_FILES['arquivo'] ?? null;

        if (!$rec || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            vero_flash('erro', 'Selecione um arquivo válido para anexar.');
            vero_redirect('?editar=' . (int)$recId);
        }
        $maxBytes = (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 5242880);
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ANEXO_EXT, true) || (int)$file['size'] > $maxBytes) {
            vero_flash('erro', 'Arquivo inválido: aceite apenas PDF/JPG/PNG até ' . round($maxBytes / 1048576, 1) . ' MB.');
            vero_redirect('?editar=' . (int)$recId);
        }
        if (!vero_upload_conteudo_ok((string)$file['tmp_name'], $ext)) {
            vero_flash('erro', 'O conteúdo do arquivo não corresponde a um PDF ou imagem válido. Envie o arquivo original.');
            vero_redirect('?editar=' . (int)$recId);
        }
        $dir = dirname(__DIR__) . '/storage/uploads/receituarios/' . $t;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nomeFisico = 'receituario' . $recId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destino = $dir . '/' . $nomeFisico;
        if (!move_uploaded_file((string)$file['tmp_name'], $destino)) {
            vero_flash('erro', 'Falha ao gravar o arquivo no servidor.');
            vero_redirect('?editar=' . (int)$recId);
        }
        vero_insert('agro_anexos', [
            'origem_tipo'   => 'receituario',
            'origem_id'     => (int)$recId,
            'tipo_arquivo'  => $ext,
            'nome_original' => mb_substr((string)$file['name'], 0, 255),
            'url'           => '/storage/uploads/receituarios/' . $t . '/' . $nomeFisico,
            'tamanho_bytes' => (int)$file['size'],
            'hash_sha256'   => hash_file('sha256', $destino),
        ]);
        vero_flash('ok', 'Documento "' . h((string)$file['name']) . '" anexado ao receituário.');
        vero_redirect('?editar=' . (int)$recId);
    }

    if ($acao === 'excluir_anexo') {
        vero_require('mip.receituarios.editar');
        $anexoId = vero_int('anexo_id');
        $recId   = vero_int('id');
        $anexo = $anexoId ? vero_row(
            "SELECT * FROM agro_anexos WHERE id=:i AND tenant_id=:t AND origem_tipo='receituario'",
            [':i' => $anexoId, ':t' => $t]) : null;
        if ($anexo) {
            $arquivo = dirname(__DIR__) . $anexo['url'];
            if (is_file($arquivo)) unlink($arquivo);
            vero_pdo()->prepare("DELETE FROM agro_anexos WHERE tenant_id=? AND id=?")->execute([$t, (int)$anexoId]);
            vero_flash('ok', 'Anexo removido.');
        }
        vero_redirect('?editar=' . (int)$recId);
    }

    if ($acao === 'excluir') {
        vero_require('mip.receituarios.excluir');
        $id = vero_int('id');
        if ($id) {
            /* remove anexos (arquivo + registro) e o receituário — sem coluna ativo */
            foreach (vero_rows(
                "SELECT * FROM agro_anexos WHERE tenant_id=:t AND origem_tipo='receituario' AND origem_id=:o",
                [':t' => $t, ':o' => $id]) as $ax) {
                $arq = dirname(__DIR__) . $ax['url'];
                if (is_file($arq)) unlink($arq);
            }
            vero_pdo()->prepare("DELETE FROM agro_anexos WHERE tenant_id=? AND origem_tipo='receituario' AND origem_id=?")
                ->execute([$t, (int)$id]);
            vero_pdo()->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=? LIMIT 1")->execute([$t, (int)$id]);
            vero_flash('ok', 'Receituário excluído (com anexos).');
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q          = trim((string)($_GET['q'] ?? ''));
$fAplicacao = (int)($_GET['aplicacao'] ?? 0);

$where  = "r.tenant_id = :t";
$params = [':t' => $t];
if ($q !== '') { $where .= " AND r.numero LIKE :q"; $params[':q'] = "%{$q}%"; }
if ($fAplicacao > 0) { $where .= " AND r.aplicacao_id = :a"; $params[':a'] = $fAplicacao; }

$rows = vero_rows(
    "SELECT r.*, op.nome AS rt_nome, ap.tipo AS aplic_tipo, ap.data AS aplic_data,
            tl.codigo AS talhao, fz.nome AS fazenda,
            (SELECT COUNT(*) FROM agro_anexos ax
              WHERE ax.tenant_id = r.tenant_id AND ax.origem_tipo = 'receituario' AND ax.origem_id = r.id) AS anexos
       FROM " . T . " r
       LEFT JOIN agro_operadores op ON op.id = r.emitido_por
       LEFT JOIN agro_aplicacoes ap ON ap.id = r.aplicacao_id
       LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = ap.fazenda_id
      WHERE {$where}
      ORDER BY r.emitido_em DESC, r.id DESC LIMIT 200",
    $params
);

/* aplicações para o vínculo (não canceladas, mais recentes) */
$aplicacoesOpt = [];
foreach (vero_rows(
    "SELECT ap.id, ap.tipo, ap.data, tl.codigo AS talhao, fz.nome AS fazenda
       FROM agro_aplicacoes ap
       LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = ap.fazenda_id
      WHERE ap.tenant_id = :t AND ap.status <> 'cancelada'
      ORDER BY ap.data DESC, ap.id DESC LIMIT 300", [':t' => $t]
) as $ap) {
    $aplicacoesOpt[(int)$ap['id']] = '#' . $ap['id'] . ' · ' . ($ap['data'] ? date('d/m/Y', strtotime((string)$ap['data'])) : 's/ data')
        . ' · ' . ucfirst((string)$ap['tipo']) . ' · ' . trim(($ap['fazenda'] ?? '') . ' — ' . ($ap['talhao'] ?? ''), ' —');
}
$operadores = vero_options('agro_operadores', 'nome');

$edit = null; $editAnexos = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => (int)$_GET['editar'], ':t' => $t]);
    if ($edit) {
        $editAnexos = vero_rows(
            "SELECT * FROM agro_anexos WHERE tenant_id=:t AND origem_tipo='receituario' AND origem_id=:o ORDER BY id",
            [':t' => $t, ':o' => (int)$edit['id']]);
    }
}
/* pré-seleção vinda do detalhe da aplicação (?nova_aplicacao=ID) */
$novaAplicacao = (int)($_GET['nova_aplicacao'] ?? 0);

$GUARD      = ['macro' => 'mip', 'micro' => 'receituarios'];
$PAGE_VIEW  = 'mip_receituarios';
$PAGE_TITLE = 'Receituários';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('mip.receituarios.editar');
$hoje = date('Y-m-d');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Receituários', 'Arquivo das prescrições emitidas pelo responsável técnico — registro e anexo',
        $podeEditar ? '+ Novo receituário' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por número…">
        <?php if ($fAplicacao): ?><input type="hidden" name="aplicacao" value="<?= $fAplicacao ?>"><?php endif; ?>
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
        <?php if ($fAplicacao): ?>
          <a class="vbtn vbtn-ghost vbtn-sm" href="?">Limpar filtro (aplicação #<?= $fAplicacao ?>)</a>
        <?php endif; ?>
      </form>
      <span class="vsub"><?= count($rows) ?> receituário(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum receituário registrado<?= $fAplicacao ? ' para esta aplicação' : '' ?>.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Número</th><th>Aplicação vinculada</th><th>Emitido por (RT)</th>
        <th>Emissão</th><th>Validade</th>
        <th class="num">Anexos</th>
        <th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $vencido = $r['validade'] !== null && (string)$r['validade'] < $hoje; ?>
        <tr>
          <td><strong class="vnum"><?= h($r['numero'] ?? ('#' . $r['id'])) ?></strong></td>
          <td><?= $r['aplicacao_id']
                ? '<a href="' . BIOS_BASE . '/mip/aplicacoes?ver=' . (int)$r['aplicacao_id'] . '">#' . (int)$r['aplicacao_id'] . '</a> · '
                  . ($r['aplic_data'] ? date('d/m/Y', strtotime((string)$r['aplic_data'])) : '') . ' · '
                  . h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —'))
                : '<span class="vhint">sem vínculo</span>' ?></td>
          <td><?= h($r['rt_nome'] ?? '') ?: '<span class="vhint">—</span>' ?></td>
          <td class="vnum"><?= $r['emitido_em'] ? dateBR((string)$r['emitido_em']) : '—' ?></td>
          <td class="vnum" style="<?= $vencido ? 'color:#b3261e' : '' ?>">
            <?= $r['validade'] ? dateBR((string)$r['validade']) . ($vencido ? ' (vencido)' : '') : '—' ?></td>
          <td class="num"><?= (int)$r['anexos'] ?: '<span style="color:#b3261e">0 ⚠</span>' ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('mip.receituarios.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir este receituário e seus anexos?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      O receituário é o documento do RT. O VERO arquiva número, vigência e o PDF assinado —
      produto, dose e carência continuam sendo decisão exclusiva do responsável técnico.
    </div>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= ($edit || $novaAplicacao) ? ' open' : '' ?>" id="vm-form">
  <div class="vbox" style="max-width:640px">
    <header>
      <h2><?= $edit ? 'Editar receituário' : 'Novo receituário' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('numero', 'Número do receituário', $edit['numero'] ?? '', true, 'Como consta no documento do RT') ?>
        <?= vero_f_select('emitido_por', 'Emitido por (RT)', $operadores, $edit['emitido_por'] ?? null, false, '— Não informado —') ?>
        <div class="full"><?= vero_f_select('aplicacao_id', 'Aplicação vinculada', $aplicacoesOpt,
              $edit['aplicacao_id'] ?? ($novaAplicacao ?: null), false, '— Sem vínculo (avulso) —') ?></div>
        <?= vero_f_text('emitido_em', 'Data de emissão', $edit['emitido_em'] ?? date('Y-m-d'), false, '', 'date') ?>
        <?= vero_f_text('validade', 'Validade', $edit['validade'] ?? '', false, '', 'date') ?>
        <div class="full"><?= vero_f_text('observacao', 'Observações', $edit['observacao'] ?? '') ?></div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>

    <?php if ($edit): ?>
    <div style="border-top:1px solid #EEE8DB;padding:14px 18px">
      <strong style="display:block;margin-bottom:8px">Documento anexado</strong>
      <?php if ($editAnexos): foreach ($editAnexos as $ax): ?>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
          <a href="<?= BIOS_BASE . h((string)$ax['url']) ?>" target="_blank"><?= h((string)$ax['nome_original']) ?></a>
          <span class="vhint"><?= round((int)$ax['tamanho_bytes'] / 1024) ?> KB</span>
          <form method="post" data-confirm="Remover este anexo?" data-confirm-danger data-confirm-ok="Remover" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
            <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
            <input type="hidden" name="acao" value="excluir_anexo">
            <input type="hidden" name="anexo_id" value="<?= (int)$ax['id'] ?>">
            <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
            <button class="vbtn vbtn-ghost vbtn-sm" type="submit">×</button>
          </form>
        </div>
      <?php endforeach; else: ?>
        <div class="vhint" style="margin-bottom:8px">Nenhum documento anexado ainda — anexe o PDF assinado do RT.</div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="anexar">
        <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <input type="file" name="arquivo" accept=".pdf,.jpg,.jpeg,.png" required>
        <button class="vbtn vbtn-primary vbtn-sm" type="submit">Anexar</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
