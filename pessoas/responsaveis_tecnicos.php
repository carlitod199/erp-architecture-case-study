<?php
/* ============================================================
   VERO — Pessoas / Responsáveis Técnicos  (tela real)
   Rota: /pessoas/responsaveis_tecnicos.php
   Guard: pessoas.responsaveis_tecnicos
   A3-T21 (análise A3-06 — absorve P-10/DB-03): CADASTRO FORMAL do
   RT em `rt_registros` (conselho/nº/UF/validade/culturas + anexo
   `rt_registro`), status DERIVADO; alertas categoria `rt`. Mantém
   as leituras de atuação (validações nominais).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_ifa_helper.php';

const CONSELHOS_RT = ['crea' => 'CREA', 'cfta' => 'CFTA', 'outro' => 'Outro'];

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'registro') {
        vero_require('pessoas.responsaveis_tecnicos.editar');
        $opId = vero_int('operador_id');
        $conselho = (string)($_POST['conselho'] ?? '');
        $numero = vero_str('numero', 30);
        $uf = strtoupper((string)(vero_str('uf', 2) ?? ''));
        if (!$opId || !isset(CONSELHOS_RT[$conselho]) || $numero === null || strlen($uf) !== 2) {
            vero_flash('erro', 'Colaborador, conselho, número e UF são obrigatórios.');
            vero_redirect();
        }
        if (!vero_val("SELECT id FROM agro_operadores WHERE id=:i AND tenant_id=:t AND ativo=1",
            [':i' => $opId, ':t' => $t])) {
            vero_flash('erro', 'Colaborador inválido.');
            vero_redirect();
        }
        try {
            vero_insert('rt_registros', [
                'operador_id' => $opId, 'conselho' => $conselho, 'numero' => $numero, 'uf' => $uf,
                'validade' => vero_date('validade'), 'culturas' => vero_str('culturas', 255), 'ativo' => 1,
            ]);
            vero_flash('ok', 'Registro de RT cadastrado — anexe a carteira/ART como evidência.');
        } catch (Throwable $e) {
            vero_flash('erro', 'Registro duplicado para este colaborador/conselho/número.');
        }
        vero_redirect();
    }

    if ($acao === 'inativar') {
        vero_require('pessoas.responsaveis_tecnicos.excluir');
        $id = vero_int('id');
        if ($id && vero_val("SELECT id FROM rt_registros WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t])) {
            vero_update('rt_registros', (int)$id, ['ativo' => 0]);
            vero_flash('ok', 'Registro inativado (histórico preservado).');
        }
        vero_redirect();
    }

    if ($acao === 'anexar') {
        vero_require('pessoas.responsaveis_tecnicos.editar');
        $id = vero_int('id');
        $file = $_FILES['arquivo'] ?? null;
        $ok = $id ? vero_val("SELECT id FROM rt_registros WHERE id=:i AND tenant_id=:t", [':i' => $id, ':t' => $t]) : null;
        if (!$ok || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            vero_flash('erro', 'Registro/arquivo inválido.');
            vero_redirect();
        }
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) || (int)$file['size'] > 5242880) {
            vero_flash('erro', 'Só PDF/JPG/PNG até 5 MB.');
            vero_redirect();
        }
        if (!vero_upload_conteudo_ok((string)$file['tmp_name'], $ext)) {
            vero_flash('erro', 'O conteúdo do arquivo não corresponde a um PDF ou imagem válido. Envie o arquivo original.');
            vero_redirect();
        }
        $dir = dirname(__DIR__) . '/storage/uploads/rt/' . $t;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nomeF = 'rt' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $nomeF)) {
            vero_flash('erro', 'Falha ao gravar arquivo.');
            vero_redirect();
        }
        vero_insert('agro_anexos', [
            'origem_tipo' => 'rt_registro', 'origem_id' => (int)$id,
            'tipo_arquivo' => 'carteira_art', 'nome_original' => mb_substr((string)$file['name'], 0, 255),
            'url' => '/storage/uploads/rt/' . $t . '/' . $nomeF,
            'tamanho_bytes' => (int)$file['size'], 'hash_sha256' => hash_file('sha256', $dir . '/' . $nomeF),
        ]);
        vero_flash('ok', 'Evidência anexada.');
        vero_redirect();
    }
}

ifa_reemitir_alertas_pessoas();

$registros = vero_rows(
    "SELECT r.*, o.nome AS operador,
            (SELECT COUNT(*) FROM agro_anexos ax WHERE ax.tenant_id=r.tenant_id
              AND ax.origem_tipo='rt_registro' AND ax.origem_id=r.id) AS anexos
       FROM rt_registros r JOIN agro_operadores o ON o.id = r.operador_id
      WHERE r.tenant_id = :t
      ORDER BY r.ativo DESC, o.nome", [':t' => $t]);
$operadoresRT = vero_rows("SELECT id, nome FROM agro_operadores WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => $t]);

/* usuários com atuação técnica registrada */
$validadores = vero_rows(
    "SELECT u.nome, u.email,
            (SELECT COUNT(*) FROM agro_aplicacoes ap
              WHERE ap.tenant_id = :t1 AND ap.validado_por = u.id AND ap.status = 'validada') AS aplicacoes,
            (SELECT COUNT(*) FROM agro_alertas al
              WHERE al.tenant_id = :t2 AND al.reconhecido_por = u.id) AS alertas_tratados
       FROM usuarios u
      WHERE u.tenant_id = :t3 AND u.ativo = 1
     HAVING aplicacoes > 0 OR alertas_tratados > 0
      ORDER BY aplicacoes DESC, alertas_tratados DESC",
    [':t1' => $t, ':t2' => $t, ':t3' => $t]);

/* colaboradores com função técnica declarada */
$tecnicos = vero_rows(
    "SELECT nome, funcao, tipo_vinculo FROM agro_operadores
      WHERE tenant_id = :t AND ativo = 1
        AND (funcao LIKE '%tecnic%' OR funcao LIKE '%técnic%' OR funcao LIKE '%agronom%'
             OR funcao LIKE '%agrônom%' OR funcao LIKE '%engenheir%')
      ORDER BY nome", [':t' => $t]);

$GUARD      = ['macro' => 'pessoas', 'micro' => 'responsaveis_tecnicos'];
$PAGE_VIEW  = 'pessoas_responsaveis_tecnicos';
$PAGE_TITLE = 'Responsáveis Técnicos';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<?php $podeEditarRT = vero_can('pessoas.responsaveis_tecnicos.editar'); ?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Responsáveis Técnicos', 'Registro FORMAL (conselho/validade — IFA v6) + atuação técnica nominal no sistema', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Registros formais de RT</strong>
      <span class="vhint">status derivado da validade; aviso-não-trava na validação de aplicações</span></div>
    <?php if (!$registros): ?><div class="vempty">Nenhum registro formal — cadastre abaixo (Major Must IFA v6).</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>Colaborador</th><th>Conselho</th><th class="vnum">Número/UF</th>
        <th>Validade</th><th>Culturas</th><th>Situação</th><th>Evidência</th><th style="text-align:right">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($registros as $r):
          $st = $r['ativo'] ? ($r['validade'] === null ? 'ativo'
              : ($r['validade'] < date('Y-m-d') ? 'vencido'
              : ($r['validade'] <= date('Y-m-d', strtotime('+60 days')) ? 'vencendo' : 'ativo'))) : 'inativo'; ?>
        <tr<?= $r['ativo'] ? '' : ' style="opacity:.55"' ?>>
          <td><strong><?= h($r['operador']) ?></strong></td>
          <td><?= h(CONSELHOS_RT[$r['conselho']] ?? strtoupper((string)$r['conselho'])) ?></td>
          <td class="vnum"><?= h((string)$r['numero']) ?>/<?= h((string)$r['uf']) ?></td>
          <td class="vnum"><?= $r['validade'] ? date('d/m/Y', strtotime((string)$r['validade'])) : 'sem vencimento' ?></td>
          <td><?= h($r['culturas'] ?? '—') ?></td>
          <td><?= ['ativo' => '<span class="vbadge vb-ok">Ativo</span>',
                   'vencendo' => '<span class="vbadge vb-warn">Vence em breve</span>',
                   'vencido' => '<span class="vbadge vb-off">VENCIDO</span>',
                   'inativo' => '<span class="vbadge vb-off">Inativo</span>'][$st] ?></td>
          <td><?= (int)$r['anexos'] > 0 ? '<span class="vbadge vb-ok">📎 ' . (int)$r['anexos'] . '</span>' : '<span class="vhint">pendente</span>' ?>
            <?php if ($podeEditarRT && $r['ativo']): ?>
            <form method="post" enctype="multipart/form-data" style="display:inline-flex;gap:4px;align-items:center">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="anexar">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="file" name="arquivo" accept=".pdf,.jpg,.jpeg,.png" style="max-width:150px">
              <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Anexar</button>
            </form>
            <?php endif; ?></td>
          <td><div class="vactions">
            <?php if (vero_can('pessoas.responsaveis_tecnicos.excluir') && $r['ativo']): ?>
            <form method="post" data-confirm="Inativar este registro de RT?" data-confirm-danger data-confirm-ok="Inativar" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="inativar">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="vicon vicon-del" type="submit" title="Inativar" aria-label="Inativar"><?= vero_ico_lixeira() ?></button>
            </form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php if ($podeEditarRT): ?>
    <form class="vform" method="post" style="padding:10px 14px;display:flex;gap:8px;flex-wrap:wrap;align-items:end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="registro">
      <div class="vfield"><label>Colaborador *</label><select name="operador_id" required>
        <option value="">—</option>
        <?php foreach ($operadoresRT as $op): ?><option value="<?= (int)$op['id'] ?>"><?= h($op['nome']) ?></option><?php endforeach; ?>
      </select></div>
      <div class="vfield"><label>Conselho *</label><select name="conselho">
        <?php foreach (CONSELHOS_RT as $ck => $cl): ?><option value="<?= $ck ?>"><?= h($cl) ?></option><?php endforeach; ?>
      </select></div>
      <div class="vfield"><label>Número *</label><input type="text" name="numero" required></div>
      <div class="vfield"><label>UF *</label><input type="text" name="uf" maxlength="2" style="width:60px" required></div>
      <div class="vfield"><label>Validade</label><input type="date" name="validade"></div>
      <div class="vfield"><label>Culturas</label><input type="text" name="culturas" placeholder="uva, manga…"></div>
      <button class="vbtn vbtn-primary" type="submit">Cadastrar registro</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Usuários com validações técnicas</strong></div>
    <?php if (!$validadores): ?>
      <div class="vempty">Nenhuma validação técnica registrada ainda — aplicações e alertas guardam quem validou.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Usuário</th><th>E-mail</th>
        <th style="text-align:right">Aplicações validadas</th>
        <th style="text-align:right">Alertas tratados</th>
      </tr></thead>
      <tbody>
      <?php foreach ($validadores as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td class="vhint"><?= h($r['email'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['aplicacoes'] ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['alertas_tratados'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Colaboradores com função técnica</strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/pessoas/colaboradores.php">Colaboradores</a></div>
    <?php if (!$tecnicos): ?>
      <div class="vempty">Nenhum colaborador com função técnica declarada — informe a função no cadastro
        (ex.: "Técnico agrícola", "Engenheiro agrônomo").</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>Colaborador</th><th>Função</th><th>Vínculo</th></tr></thead>
      <tbody>
      <?php foreach ($tecnicos as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= h($r['funcao'] ?? '—') ?></td>
          <td><span class="vbadge vb-info"><?= h(str_replace('_', ' ', (string)$r['tipo_vinculo'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
