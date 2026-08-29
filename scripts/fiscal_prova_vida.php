<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/fiscal_prova_vida.php  (SEFAZ — PROVA DE VIDA, read-only)
   ------------------------------------------------------------
   Consulta APENAS o "Status do Serviço" (sefazStatus) da SEFAZ em HOMOLOGAÇÃO,
   usando o adapter includes/fiscal/SefazTools.php + fiscal_config (tenant 1) do
   banco + certificado/senha do cofre config/fiscal_secrets.php. Sucesso = cStat 107.

   NÃO emite / NÃO cancela / NÃO inutiliza nada — só lê o status do serviço.
   Enquanto o certificado A1 não chegar, sai com mensagem clara e exit != 0
   (falha graciosa, sem stack trace).

   Uso: php scripts/fiscal_prova_vida.php
   Exit: 0 = cStat 107 (serviço em operação);  1 = falha/pendência.
   ============================================================ */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Somente CLI.\n"); exit(2); }

/* Escopo multiempresa p/ as funções vero_* (não há sessão HTTP na CLI).
   Emitente fiscal atual = tenant 1 (PRODUTOR EXEMPLO, homologação). */
$_SESSION['tenant_id'] = 1;

require_once __DIR__ . '/../includes/vero_crud.php';          // vero_pdo/vero_row/vero_tenant
require_once __DIR__ . '/../includes/fiscal/SefazTools.php';  // adapter isolado do sped-nfe

echo "== VERO — Prova de vida SEFAZ (Status do Serviço, HOMOLOGAÇÃO) ==\n";
echo "   tenant 1 — PRODUTOR EXEMPLO DA SILVA (Produtor Rural PF)\n\n";

/* 1) Config fiscal do banco. */
try {
    $cfg = vero_fiscal_config();
} catch (Throwable $e) {
    fwrite(STDERR, "FALHA ao ler fiscal_config no banco: " . $e->getMessage() . "\n");
    exit(1);
}
if (!$cfg) {
    fwrite(STDERR, "FALHA: fiscal_config (tenant 1) não encontrado. Rode a migration 154 e o seed do emitente.\n");
    exit(1);
}

$amb = (int)($cfg['ambiente'] ?? 2);
if ($amb !== 2) {
    // Trava de segurança: este utilitário é EXCLUSIVO de homologação.
    fwrite(STDERR, "ABORTADO: fiscal_config.ambiente={$amb} (não é HOMOLOGAÇÃO=2). Prova de vida roda só em homologação.\n");
    exit(1);
}

/* 2) Pendências de configuração (cert/senha/identidade) — falha graciosa. */
$falta = vero_sefaz_faltando($cfg);
$temCert  = vero_fiscal_cert_path($cfg) !== null && is_file((string)vero_fiscal_cert_path($cfg));
$temSenha = vero_fiscal_cert_senha((int)$cfg['tenant_id']) !== null;
if (!$temCert || !$temSenha) {
    echo "AGUARDANDO CERTIFICADO A1 DO CLIENTE.\n";
    echo "A infraestrutura está pronta; falta o cliente entregar o .pfx e a senha.\n\n";
    echo "Pendências detectadas:\n";
    foreach ($falta as $p) echo "  - {$p}\n";
    echo "\nQuando chegar: coloque o .pfx em storage/certs/ e preencha config/fiscal_secrets.php\n";
    echo "(cert_password e, se quiser, cert_path). Depois rode este script de novo.\n";
    exit(1);
}
if ($falta) {
    echo "CONFIGURAÇÃO INCOMPLETA (além de cert/senha):\n";
    foreach ($falta as $p) echo "  - {$p}\n";
    exit(1);
}

/* 3) Prova de vida propriamente dita (read-only): Status do Serviço. */
echo "Certificado + senha presentes. Consultando SEFAZ (sefazStatus)...\n\n";
$r = vero_sefaz_status_teste();

$cStat   = $r['cStat']   ?? '(sem cStat)';
$xMotivo = $r['xMotivo'] ?? '(sem xMotivo)';
echo "  cStat   : {$cStat}\n";
echo "  xMotivo : {$xMotivo}\n\n";

if (!empty($r['ok']) && (string)$cStat === '107') {
    echo "SUCESSO — cStat 107: Serviço em Operação. Prova de vida OK (homologação).\n";
    exit(0);
}

fwrite(STDERR, "FALHA — serviço não retornou cStat 107.\n");
if (!empty($r['faltando'])) {
    foreach ($r['faltando'] as $p) fwrite(STDERR, "  - {$p}\n");
}
exit(1);
