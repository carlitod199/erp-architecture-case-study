<?php
/* ============================================================
   VERO — Fiscal / Acesso do Contador  (tela real)
   Substitui o mock. Rota: /fiscal/acesso_contador.php
   Guard: fiscal.acesso_contador
   Pacote do contador: exports CSV centralizados do período +
   orientação para criar usuário com perfil restrito ao Fiscal.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$ini = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : date('Y-01-01');
$fim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : date('Y-m-d');

$kpi = vero_row(
    "SELECT COUNT(*) AS docs,
            SUM(CASE WHEN status <> 'recusado' THEN valor_total ELSE 0 END) AS valor
       FROM fiscal_documentos
      WHERE tenant_id = :t AND COALESCE(data_emissao, created_at) BETWEEN :i AND :f",
    [':t' => $t, ':i' => $ini, ':f' => $fim]);
$xmls = (int)vero_val(
    "SELECT COUNT(*) FROM agro_anexos
      WHERE tenant_id = :t AND origem_tipo = 'fiscal_documento' AND tipo_arquivo = 'xml_nfe'", [':t' => $t]);
$livroN = (int)vero_val(
    "SELECT COUNT(*) FROM fiscal_livro_caixa WHERE tenant_id = :t AND data_lancamento BETWEEN :i AND :f",
    [':t' => $t, ':i' => $ini, ':f' => $fim]);

$exports = [
    ['Documentos fiscais do período', '/fiscal/relatorios_fiscais', 'documentos'],
    ['Livro caixa do período', '/fiscal/relatorios_fiscais', 'livro'],
    ['Razão financeiro completo', '/relatorios/relatorios_financeiros', 'razao'],
    ['Custeio por lançamento', '/relatorios/relatorios_financeiros', 'custeio'],
];

$GUARD      = ['macro' => 'fiscal', 'micro' => 'acesso_contador'];
$PAGE_VIEW  = 'fiscal_acesso_contador';
$PAGE_TITLE = 'Acesso do Contador';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Acesso do Contador', 'Pacote de exportações do período e orientação de acesso restrito ao módulo Fiscal', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center">
        <label class="vhint">Período do pacote</label>
        <input type="date" name="ini" value="<?= h($ini) ?>" onchange="this.form.submit()">
        <input type="date" name="fim" value="<?= h($fim) ?>" onchange="this.form.submit()">
      </form>
      <span class="vsub"><?= (int)($kpi['docs'] ?? 0) ?> documento(s) ·
        R$ <?= numFmt((float)($kpi['valor'] ?? 0), 2) ?> ·
        <?= $xmls ?> XML(s) arquivado(s) · <?= $livroN ?> lançamento(s) no livro</span>
    </div>
    <table class="vtable">
      <tbody>
      <?php foreach ($exports as [$rotulo, $rota, $chave]): ?>
        <tr>
          <td><strong><?= h($rotulo) ?></strong></td>
          <td style="text-align:right;width:120px">
            <a class="vbtn vbtn-primary vbtn-sm"
               href="<?= $base . $rota ?>?ini=<?= h($ini) ?>&fim=<?= h($fim) ?>&csv=<?= h($chave) ?>">Baixar CSV</a></td>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td><strong>XMLs das NF-e</strong> <span class="vhint">— arquivos individuais com hash de integridade</span></td>
        <td style="text-align:right">
          <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/fiscal/upload_xml.php">Abrir fila de XMLs</a></td>
      </tr>
      </tbody>
    </table>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Acesso restrito para o contador</strong></div>
    <div style="padding:12px 14px" class="vhint">
      <p style="margin:0 0 8px">Para dar acesso direto ao contador sem expor a operação:</p>
      <ol style="margin:0 0 8px 18px;line-height:1.7">
        <li>Crie um perfil em <a href="<?= $base ?>/configuracoes/perfis_acesso.php">Configurações → Perfis de Acesso</a>
          (ex.: "Contador") e marque em <a href="<?= $base ?>/configuracoes/permissoes.php">Permissões</a> apenas os
          micros do módulo Fiscal (e Relatórios Financeiros, se desejar).</li>
        <li>Crie o usuário em <a href="<?= $base ?>/configuracoes/usuarios.php">Configurações → Usuários</a> com esse perfil.</li>
        <li>O contador enxergará somente as telas fiscais — o guard bloqueia o restante automaticamente.</li>
      </ol>
      <p style="margin:0">Alternativa sem acesso: baixe o pacote de CSVs acima e envie por e-mail — os XMLs ficam
        arquivados aqui com hash SHA-256 para conferência.</p>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
