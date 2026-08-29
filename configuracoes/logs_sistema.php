<?php
/* ============================================================
   VERO — Configurações / Logs do Sistema  (tela real, leitura)
   Substitui o mock. Rota: /configuracoes/logs_sistema.php
   Guard: configuracoes.logs_sistema
   Últimas exceções da aplicação lidas do error_log do PHP
   (entradas [VERO]); a trilha de autenticação fica em Auditoria.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

function logs_ler_ultimas(int $max = 60): array
{
    $arquivo = (string)ini_get('error_log');
    if ($arquivo === '' || !is_file($arquivo) || !is_readable($arquivo)) return ['arquivo' => $arquivo ?: null, 'linhas' => null];
    /* lê só o final do arquivo (até 256 KB) para não carregar logs gigantes */
    $tam = filesize($arquivo) ?: 0;
    $ler = min($tam, 262144);
    $fh = fopen($arquivo, 'rb');
    fseek($fh, $tam - $ler);
    $conteudo = (string)fread($fh, $ler);
    fclose($fh);
    $linhas = array_filter(array_map('trim', explode("\n", $conteudo)));
    /* agrupa por entrada datada [dd-Mon-YYYY ...] e filtra as da aplicação */
    $entradas = [];
    $atual = null;
    foreach ($linhas as $l) {
        if (preg_match('/^\[\d{2}-[A-Za-z]{3}-\d{4}/', $l)) {
            if ($atual !== null) $entradas[] = $atual;
            $atual = $l;
        } elseif ($atual !== null) {
            $atual .= "\n" . $l;
        }
    }
    if ($atual !== null) $entradas[] = $atual;
    $app = array_values(array_filter($entradas, static fn($e) => str_contains($e, '[VERO]')));
    return ['arquivo' => $arquivo, 'linhas' => array_slice(array_reverse($app), 0, $max)];
}

$logs = logs_ler_ultimas();

$GUARD      = ['macro' => 'configuracoes', 'micro' => 'logs_sistema'];
$PAGE_VIEW  = 'configuracoes_logs_sistema';
$PAGE_TITLE = 'Logs do Sistema';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Logs do Sistema', 'Últimas exceções da aplicação — a trilha de login/acesso fica em Auditoria', null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <span class="vsub">fonte: <code><?= h($logs['arquivo'] ?? 'error_log não configurado') ?></code></span>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/configuracoes/auditoria.php">Auditoria de acesso</a>
    </div>

    <?php if ($logs['linhas'] === null): ?>
      <div class="vempty">O arquivo de log do PHP não está acessível a partir da aplicação neste servidor —
        consulte-o diretamente no host (ini error_log).</div>
    <?php elseif (!$logs['linhas']): ?>
      <div class="vempty">Nenhuma exceção da aplicação no trecho recente do log. ✓</div>
    <?php else: ?>
      <div style="padding:12px 14px;display:flex;flex-direction:column;gap:8px">
        <?php foreach ($logs['linhas'] as $entrada): ?>
          <pre style="margin:0;padding:10px 12px;background:#20242a;color:#e8e6e3;border-radius:8px;
                      font-size:.78rem;overflow-x:auto;white-space:pre-wrap"><?= h($entrada) ?></pre>
        <?php endforeach; ?>
      </div>
      <div class="vhint" style="padding:0 14px 12px">Mostrando as últimas <?= count($logs['linhas']) ?> exceções (mais recentes primeiro), lidas dos últimos 256 KB do log.</div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
