<?php
/* ============================================================
   VERO — Pessoas / Treinamentos NR-31  (A3-T19 — análise A3-06)
   Rota: /pessoas/treinamentos.php · Guard: pessoas.treinamentos
   (slug/rota no menu = A0, como custos.metas_safra)
   Temas (validade em meses — P-61: NR-31=24 semeado) + turmas com
   instrutor (RT com registro ATIVO ou externo identificado — P-64)
   e presenças N:N; habilitação DERIVADA; matriz pessoa×tema p/ o
   auditor; certificado via agro_anexos origem `treinamento_turma`;
   alertas categoria `treinamento` (preservação de status).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_ifa_helper.php';

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'tema') {
        vero_require('pessoas.treinamentos.editar');
        $nome = vero_str('nome', 120);
        if ($nome === null) { vero_flash('erro', 'Nome do tema é obrigatório.'); vero_redirect(); }
        $vm = vero_int('validade_meses') ?: null;
        vero_insert('rh_treinamento_temas', [
            'nome' => $nome, 'norma' => vero_str('norma', 20) ?? 'NR-31',
            'validade_meses' => $vm, 'ativo' => 1,
        ]);
        vero_flash('ok', 'Tema criado' . ($vm ? " (validade {$vm} meses)" : ' (sem vencimento)') . '.');
        vero_redirect();
    }

    if ($acao === 'turma') {
        vero_require('pessoas.treinamentos.editar');
        $temaId = vero_int('tema_id');
        $data = vero_date('data');
        $instrutorOp = vero_int('instrutor_operador_id') ?: null;
        $instrutorExt = vero_str('instrutor_externo', 120);
        $presencas = array_filter(array_map('intval', (array)($_POST['presenca'] ?? [])));
        if (!$temaId || $data === null || !$presencas) {
            vero_flash('erro', 'Tema, data e ao menos um presente são obrigatórios.');
            vero_redirect();
        }
        if ($instrutorOp === null && $instrutorExt === null) {
            vero_flash('erro', 'Informe o instrutor: RT interno OU externo identificado.');
            vero_redirect();
        }
        if ($instrutorOp !== null) { /* P-64: interno precisa ser RT com registro ATIVO */
            $rt = ifa_rt_status($instrutorOp);
            if (!in_array($rt['status'], ['ativo', 'vencendo'], true)) {
                vero_flash('erro', 'Instrutor interno precisa ter registro de RT ATIVO — cadastre em Responsáveis Técnicos ou informe instrutor externo.');
                vero_redirect();
            }
        }
        $cargaHoras = vero_dec('carga_horas');
        if ($cargaHoras !== null && $cargaHoras < 0) { /* A11: carga horária nunca é negativa */
            vero_flash('erro', 'A carga horária não pode ser negativa.');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $turmaId = vero_insert('rh_treinamento_turmas', [
                'tema_id' => $temaId, 'data' => $data,
                'instrutor_operador_id' => $instrutorOp, 'instrutor_externo' => $instrutorExt,
                'carga_horas' => $cargaHoras, 'observacao' => vero_str('observacao', 255),
            ]);
            $ins = $pdo->prepare("INSERT INTO rh_treinamento_presencas (tenant_id, turma_id, operador_id) VALUES (?,?,?)");
            $n = 0;
            foreach (array_unique($presencas) as $opId) {
                if (!vero_val("SELECT id FROM agro_operadores WHERE id=:i AND tenant_id=:t AND ativo=1",
                    [':i' => $opId, ':t' => $t])) continue;
                $ins->execute([$t, $turmaId, $opId]); /* sem auditoria → PDO direto */
                $n++;
            }
            if ($n === 0) throw new RuntimeException('Nenhum presente válido.');
            $pdo->commit();
            vero_flash('ok', "Turma registrada com {$n} presente(s). Anexe o certificado/lista assinada.");
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'anexar') {
        vero_require('pessoas.treinamentos.editar');
        $turmaId = vero_int('id');
        $file = $_FILES['arquivo'] ?? null;
        $ok = $turmaId ? vero_val("SELECT id FROM rh_treinamento_turmas WHERE id=:i AND tenant_id=:t",
            [':i' => $turmaId, ':t' => $t]) : null;
        if (!$ok || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            vero_flash('erro', 'Turma/arquivo inválido.');
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
        $dir = dirname(__DIR__) . '/storage/uploads/treinamentos/' . $t;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nomeF = 'turma' . $turmaId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $nomeF)) {
            vero_flash('erro', 'Falha ao gravar arquivo.');
            vero_redirect();
        }
        vero_insert('agro_anexos', [
            'origem_tipo' => 'treinamento_turma', 'origem_id' => $turmaId,
            'tipo_arquivo' => 'certificado', 'nome_original' => mb_substr((string)$file['name'], 0, 255),
            'url' => '/storage/uploads/treinamentos/' . $t . '/' . $nomeF,
            'tamanho_bytes' => (int)$file['size'], 'hash_sha256' => hash_file('sha256', $dir . '/' . $nomeF),
        ]);
        vero_flash('ok', 'Certificado anexado.');
        vero_redirect();
    }
}

ifa_reemitir_alertas_pessoas(); /* derivado do tempo → reemite na carga */

$temas = vero_rows("SELECT * FROM rh_treinamento_temas WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => $t]);
$operadores = vero_rows("SELECT id, nome, funcao FROM agro_operadores WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => $t]);
$turmas = vero_rows(
    "SELECT tu.*, te.nome AS tema, te.norma, io.nome AS instrutor_interno,
            (SELECT COUNT(*) FROM rh_treinamento_presencas p WHERE p.tenant_id=tu.tenant_id AND p.turma_id=tu.id) AS presentes,
            (SELECT COUNT(*) FROM agro_anexos ax WHERE ax.tenant_id=tu.tenant_id AND ax.origem_tipo='treinamento_turma' AND ax.origem_id=tu.id) AS anexos
       FROM rh_treinamento_turmas tu
       JOIN rh_treinamento_temas te ON te.id = tu.tema_id
       LEFT JOIN agro_operadores io ON io.id = tu.instrutor_operador_id
      WHERE tu.tenant_id = :t ORDER BY tu.data DESC, tu.id DESC LIMIT 30", [':t' => $t]);

/* matriz pessoa × tema (status derivado: última presença + validade do tema) */
$matriz = [];
foreach (vero_rows(
    "SELECT p.operador_id, tu.tema_id, MAX(tu.data) AS ultima
       FROM rh_treinamento_presencas p JOIN rh_treinamento_turmas tu ON tu.id = p.turma_id
      WHERE p.tenant_id = :t GROUP BY p.operador_id, tu.tema_id", [':t' => $t]) as $m) {
    $matriz[(int)$m['operador_id']][(int)$m['tema_id']] = (string)$m['ultima'];
}
$temaValidade = array_column($temas, 'validade_meses', 'id');

$GUARD      = ['macro' => 'pessoas', 'micro' => 'treinamentos'];
$PAGE_VIEW  = 'pessoas_treinamentos';
$PAGE_TITLE = 'Treinamentos NR-31';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
$podeEditar = vero_can('pessoas.treinamentos.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Treinamentos (NR-31 / IFA v6)',
      'Turmas com presença e certificado — a habilitação de cada pessoa é DERIVADA (data + validade do tema)', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Matriz do auditor — pessoa × tema</strong>
      <span class="vhint">verde vigente · amarelo vence ≤30d · vermelho vencido · cinza nunca treinou</span></div>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>Colaborador</th>
        <?php foreach ($temas as $te): ?><th><?= h($te['nome']) ?><br>
          <span class="vhint"><?= $te['validade_meses'] ? (int)$te['validade_meses'] . ' meses' : 'sem vencimento' ?></span></th>
        <?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($operadores as $op): ?>
        <tr><td><strong><?= h($op['nome']) ?></strong> <span class="vhint"><?= h($op['funcao'] ?? '') ?></span></td>
        <?php foreach ($temas as $te):
            $ultima = $matriz[(int)$op['id']][(int)$te['id']] ?? null;
            if ($ultima === null) { $cor = '#e8e4da'; $rot = 'nunca'; }
            else {
                $vm = $temaValidade[(int)$te['id']] ?? null;
                if ($vm === null) { $cor = '#c9e8d4'; $rot = date('d/m/Y', strtotime($ultima)); }
                else {
                    $vence = date('Y-m-d', strtotime($ultima . ' +' . (int)$vm . ' months'));
                    $cor = $vence < date('Y-m-d') ? '#f3c6c2'
                         : ($vence <= date('Y-m-d', strtotime('+30 days')) ? '#f7e3b0' : '#c9e8d4');
                    $rot = 'até ' . date('d/m/Y', strtotime($vence));
                }
            } ?>
          <td style="background:<?= $cor ?>" class="vnum"><?= h($rot) ?></td>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <?php if ($podeEditar): ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Nova turma</strong></div>
      <form class="vform" method="post" style="padding:10px 14px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="turma">
        <div class="vgrid">
          <div class="vfield"><label>Tema *</label><select name="tema_id" required>
            <?php foreach ($temas as $te): ?><option value="<?= (int)$te['id'] ?>"><?= h($te['nome']) ?></option><?php endforeach; ?>
          </select></div>
          <div class="vfield"><label>Data *</label><input type="date" name="data" value="<?= date('Y-m-d') ?>" required></div>
          <div class="vfield"><label>Instrutor interno (RT ativo — P-64)</label><select name="instrutor_operador_id">
            <option value="">—</option>
            <?php foreach ($operadores as $op): ?><option value="<?= (int)$op['id'] ?>"><?= h($op['nome']) ?></option><?php endforeach; ?>
          </select></div>
          <?= vero_f_text('instrutor_externo', 'OU instrutor externo (SENAR/consultoria)', '') ?>
          <?= vero_f_text('carga_horas', 'Carga (h)', '') ?>
          <?= vero_f_text('observacao', 'Observação', '') ?>
        </div>
        <div class="vfield" style="margin-top:8px"><label>Presenças *</label>
          <div style="display:flex;gap:12px;flex-wrap:wrap">
          <?php foreach ($operadores as $op): ?>
            <label style="display:inline-flex;gap:5px;align-items:center;font-size:.88rem">
              <input type="checkbox" name="presenca[]" value="<?= (int)$op['id'] ?>"> <?= h($op['nome']) ?></label>
          <?php endforeach; ?>
          </div></div>
        <div class="vform-actions"><button class="vbtn vbtn-primary" type="submit">Registrar turma</button></div>
      </form>
    </div>
    <div class="vcard">
      <div class="vtoolbar"><strong>Novo tema</strong></div>
      <form class="vform" method="post" style="padding:10px 14px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="tema">
        <div class="vgrid">
          <?= vero_f_text('nome', 'Nome do tema *', '', true) ?>
          <?= vero_f_text('norma', 'Norma', 'NR-31') ?>
          <?= vero_f_text('validade_meses', 'Validade (meses — vazio = não vence)', '') ?>
        </div>
        <div class="vhint" style="margin-top:6px">A validade é definição sua/do RT — o sistema não inventa.</div>
        <div class="vform-actions"><button class="vbtn vbtn-primary" type="submit">Criar tema</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Turmas registradas</strong></div>
    <?php if (!$turmas): ?><div class="vempty">Nenhuma turma registrada.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>Data</th><th>Tema</th><th>Instrutor</th>
        <th style="text-align:right">Carga (h)</th><th style="text-align:right">Presentes</th>
        <th>Certificado</th></tr></thead>
      <tbody>
      <?php foreach ($turmas as $tu): ?>
        <tr>
          <td class="vnum"><strong><?= date('d/m/Y', strtotime((string)$tu['data'])) ?></strong></td>
          <td><?= h($tu['tema']) ?> <span class="vhint"><?= h((string)$tu['norma']) ?></span></td>
          <td><?= h($tu['instrutor_interno'] ?? $tu['instrutor_externo'] ?? '—') ?>
            <?= $tu['instrutor_interno'] ? '<span class="vbadge vb-info">RT</span>' : '<span class="vhint">externo</span>' ?></td>
          <td class="vnum" style="text-align:right"><?= $tu['carga_horas'] !== null ? numFmt((float)$tu['carga_horas'], 1) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$tu['presentes'] ?></td>
          <td>
            <?= (int)$tu['anexos'] > 0 ? '<span class="vbadge vb-ok">📎 ' . (int)$tu['anexos'] . '</span>' : '<span class="vhint">pendente</span>' ?>
            <?php if ($podeEditar): ?>
            <form method="post" enctype="multipart/form-data" style="display:inline-flex;gap:4px;align-items:center">
              <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
              <input type="hidden" name="acao" value="anexar">
              <input type="hidden" name="id" value="<?= (int)$tu['id'] ?>">
              <input type="file" name="arquivo" accept=".pdf,.jpg,.jpeg,.png" style="max-width:170px">
              <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Anexar</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      Registro do treinamento: turma + lista de presença/certificado anexado. Presenças nascem
      preparadas para assinatura digital no app. NR-31 vencendo gera alerta na fila unificada.
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
